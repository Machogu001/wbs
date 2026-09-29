<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/PaymentLink.php';
require_once __DIR__ . '/../includes/Bill.php';
require_once __DIR__ . '/../includes/Payment.php';
require_once __DIR__ . '/../includes/User.php';

$database = new Database();
$db = $database->getConnection();
$token = trim((string)($_GET['t'] ?? ''));
$page_title = 'Registration Proforma';
$hide_nav = true;
require_once __DIR__ . '/../templates/header.php';

$message = null;
$messageType = 'danger';
$billRow = null;
$userRow = null;
$paymentRow = null;
$amountDue = 0.0;
$createdPaymentId = 0;
$setupLink = '';
$isCompanyCustomer = false;
$displayClientName = '';
$contactPersonName = '';
$companyRegistrationNumber = '';
$isSettledInvoice = false;

if (!$db) {
	$message = 'Unable to connect to the database.';
} elseif ($token === '') {
	$message = 'Invalid registration proforma link.';
} else {
	$billId = (int)(PaymentLink::getBillIdFromToken($token) ?? 0);
	if ($billId <= 0) {
		$message = 'Invalid or expired registration proforma link.';
	} else {
		$billService = new Bill($db);
		$billRow = $billService->getById($billId);
		if (!$billRow || !$billService->isRegistrationFeeBill($billRow)) {
			$message = 'Registration proforma not found.';
			$billRow = null;
		} else {
			$userService = new User($db);
			$userRow = $userService->getById((int)$billRow['user_id']);
			$isCompanyCustomer = strtolower(trim((string)($userRow['customer_type'] ?? 'individual'))) === 'company';
			$displayClientName = trim((string)($userRow['company_name'] ?? ''));
			if ($displayClientName === '') {
				$displayClientName = trim((string)($userRow['full_name'] ?? ''));
			}
			$contactPersonName = trim((string)($userRow['contact_person_name'] ?? ''));
			if ($contactPersonName === '') {
				$contactPersonName = trim((string)($userRow['full_name'] ?? ''));
			}
			$companyRegistrationNumber = trim((string)($userRow['company_registration_number'] ?? ''));
			$paymentService = new Payment($db);
			$amountDue = $paymentService->getBillOutstandingAmount($billId);
			$isSettledInvoice = $amountDue <= 0.01;
			$paymentRow = $paymentService->getLatestCompletedByBillId($billId);

			if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userRow) {
				$payPhone = trim((string)($_POST['pay_phone'] ?? ''));
				$locationLabel = trim((string)($_POST['location_label'] ?? ''));
				$latitude = trim((string)($_POST['latitude'] ?? ''));
				$longitude = trim((string)($_POST['longitude'] ?? ''));
				$gpsAccuracy = trim((string)($_POST['gps_accuracy'] ?? ''));
				if ($locationLabel !== (string)($userRow['location_label'] ?? '')) {
					$stmtLocation = $db->prepare('UPDATE users SET location_label = :location_label WHERE id = :id');
					$stmtLocation->execute([
						':location_label' => $locationLabel !== '' ? $locationLabel : null,
						':id' => (int)$userRow['id'],
					]);
					$userRow['location_label'] = $locationLabel;
				}
				if ($latitude !== '' || $longitude !== '') {
					$stmtGps = $db->prepare('UPDATE users SET latitude = :latitude, longitude = :longitude WHERE id = :id');
					$stmtGps->execute([
						':latitude' => $latitude !== '' ? (float)$latitude : null,
						':longitude' => $longitude !== '' ? (float)$longitude : null,
						':id' => (int)$userRow['id'],
					]);
					$userRow['latitude'] = $latitude;
					$userRow['longitude'] = $longitude;
				}
				if ($payPhone === '') {
					$message = 'Enter the phone number to receive the M-Pesa STK prompt.';
				} elseif (!preg_match('/^(?:254|\+254|0)?((?:7|1)\d{8})$/', $payPhone, $matches)) {
					$message = 'Use a valid Kenyan phone number: 07..., 01..., or 254...';
				} elseif ($amountDue <= 0.01) {
					$message = 'This registration proforma is already fully paid.';
					$messageType = 'success';
				} else {
					$formattedPhone = '254' . $matches[1];
					try {
						require_once __DIR__ . '/../includes/Mpesa.php';
						$mpesa = new Mpesa();
						$response = $mpesa->stkPush($formattedPhone, $amountDue, (string)$billRow['account_number'], 'Registration Fee');
						if (isset($response['error'])) {
							$details = '';
							if (isset($response['http_code'])) {
								$details .= ' (HTTP ' . $response['http_code'] . ')';
							}
							if (isset($response['details']) && is_array($response['details'])) {
								if (!empty($response['details']['errorMessage'])) {
									$details .= ': ' . $response['details']['errorMessage'];
								} elseif (!empty($response['details']['errorCode'])) {
									$details .= ' (Code ' . $response['details']['errorCode'] . ')';
								}
							}
							$message = 'Payment initiation failed: ' . $response['error'] . $details;
						} else {
							$payment = new Payment($db);
							$payment->bill_id = (int)$billRow['id'];
							$payment->user_id = (int)$userRow['id'];
							$payment->phone_number = $formattedPhone;
							$payment->amount = $amountDue;
							$payment->merchant_request_id = $response['MerchantRequestID'] ?? null;
							$payment->checkout_request_id = $response['CheckoutRequestID'] ?? null;
							$payment->status = 'pending';
							$payment->registration_id = (int)$userRow['id'];
							if ($payment->create()) {
								$createdPaymentId = (int)$payment->id;
								$message = 'M-Pesa prompt sent. Keep this page open while we confirm payment.';
								$messageType = 'info';
							} else {
								$message = 'Failed to save the payment request.';
							}
						}
					} catch (Throwable $e) {
						$message = 'Unable to initiate payment: ' . $e->getMessage();
					}
				}
			}

			$amountDue = $paymentService->getBillOutstandingAmount($billId);
			$isSettledInvoice = $amountDue <= 0.01;
			if ($amountDue <= 0.01) {
				$stmt = $db->prepare("SELECT id, account_setup_token, account_setup_expires_at FROM registration_proformas WHERE bill_id = :bill_id LIMIT 1");
				$stmt->execute([':bill_id' => $billId]);
				$proformaRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
				if ($proformaRow && empty($proformaRow['account_setup_token'])) {
					$fallbackToken = bin2hex(random_bytes(24));
					$fallbackExpiry = date('Y-m-d H:i:s', strtotime('+7 days'));
					$stmtToken = $db->prepare('UPDATE registration_proformas SET account_setup_token = :token, account_setup_expires_at = :expires_at, account_setup_sent_at = COALESCE(account_setup_sent_at, NOW()) WHERE id = :id');
					$stmtToken->execute([
						':token' => $fallbackToken,
						':expires_at' => $fallbackExpiry,
						':id' => (int)$proformaRow['id'],
					]);
					$proformaRow['account_setup_token'] = $fallbackToken;
				}
				if ($proformaRow && !empty($proformaRow['account_setup_token'])) {
					$setupLink = '/registration-account-setup?token=' . urlencode((string)$proformaRow['account_setup_token']);
				}
			}
		}
	}
}
?>

<div class="container mt-4">
	<div class="row justify-content-center">
		<div class="col-lg-9">
			<div class="card shadow-sm">
				<div class="card-header d-flex justify-content-between align-items-center" style="background:linear-gradient(135deg,#0f172a 0%,#1d4ed8 100%);color:#f8fafc;">
					<h3 class="mb-0"><i class="bi bi-file-earmark-medical me-2"></i><?php echo $isSettledInvoice ? 'Registration Invoice' : 'Registration Proforma'; ?></h3>
					<?php if ($billRow): ?>
						<span class="badge bg-light text-dark"><?php echo $amountDue <= 0.01 ? 'Paid' : 'Awaiting Payment'; ?></span>
					<?php endif; ?>
				</div>
				<div class="card-body">
					<?php if ($isSettledInvoice): ?>
						<script>document.title = 'Registration Invoice - Water Billing System';</script>
					<?php endif; ?>
					<?php if ($message): ?>
						<div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
					<?php endif; ?>
					<?php if (!$billRow || !$userRow): ?>
						<p class="mb-0 text-muted">We could not load this registration proforma.</p>
					<?php else: ?>
						<div class="row g-4">
							<div class="col-md-7">
								<h5 class="mb-3">Client Details</h5>
								<dl class="row mb-0">
									<dt class="col-sm-4">Client Type</dt><dd class="col-sm-8"><?php echo $isCompanyCustomer ? 'Company / Organization' : 'Individual / Personal'; ?></dd>
									<dt class="col-sm-4"><?php echo $isCompanyCustomer ? 'Company' : 'Client'; ?></dt><dd class="col-sm-8"><?php echo htmlspecialchars($displayClientName); ?></dd>
									<?php if ($isCompanyCustomer): ?>
										<dt class="col-sm-4">Contact Person</dt><dd class="col-sm-8"><?php echo htmlspecialchars($contactPersonName); ?></dd>
										<?php if ($companyRegistrationNumber !== ''): ?>
											<dt class="col-sm-4">Reg. Number</dt><dd class="col-sm-8"><?php echo htmlspecialchars($companyRegistrationNumber); ?></dd>
										<?php endif; ?>
									<?php endif; ?>
									<dt class="col-sm-4">Account No.</dt><dd class="col-sm-8"><?php echo htmlspecialchars((string)$userRow['account_number']); ?></dd>
									<dt class="col-sm-4">Phone</dt><dd class="col-sm-8"><?php echo htmlspecialchars((string)$userRow['phone_number']); ?></dd>
									<dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?php echo htmlspecialchars((string)$userRow['email']); ?></dd>
									<dt class="col-sm-4">Address</dt><dd class="col-sm-8"><?php echo htmlspecialchars((string)$userRow['address']); ?></dd>
									<dt class="col-sm-4">Location / Landmark</dt><dd class="col-sm-8"><?php echo htmlspecialchars((string)($userRow['location_label'] ?? '')); ?></dd>
									<dt class="col-sm-4">Due Date</dt><dd class="col-sm-8"><?php echo htmlspecialchars(date('d M Y', strtotime((string)$billRow['due_date']))); ?></dd>
								</dl>
							</div>
							<div class="col-md-5">
								<div class="border rounded p-3 bg-light h-100">
									<div class="small text-muted">Registration Fee</div>
									<div class="display-6 fw-semibold mb-3">KES <?php echo number_format((float)$billRow['amount'], 2); ?></div>
									<div class="small text-muted">Outstanding Balance</div>
									<div class="fs-3 fw-semibold mb-3">KES <?php echo number_format($amountDue, 2); ?></div>
									<?php if (!$isSettledInvoice): ?>
									<form method="POST" id="registrationProformaPayForm" class="mt-2">
										<label for="location_label" class="form-label">Location / Landmark</label>
										<input type="text" class="form-control mb-2" id="location_label" name="location_label" value="<?php echo htmlspecialchars((string)($_POST['location_label'] ?? ($userRow['location_label'] ?? ''))); ?>" placeholder="e.g. P5PP+CJ, Nguluni">
										<div class="form-text mb-2">Optional. Add the nearest landmark or estate, the same way it appears on the normal registration form.</div>
										<div class="mb-2">
											<button type="button" class="btn btn-outline-primary btn-sm" id="useGpsBtn">
												<i class="bi bi-geo-alt"></i> Use my current GPS location
											</button>
										</div>
										<input type="hidden" id="latitude" name="latitude" value="<?php echo htmlspecialchars((string)($_POST['latitude'] ?? ($userRow['latitude'] ?? ''))); ?>">
										<input type="hidden" id="longitude" name="longitude" value="<?php echo htmlspecialchars((string)($_POST['longitude'] ?? ($userRow['longitude'] ?? ''))); ?>">
										<input type="hidden" id="gps_accuracy" name="gps_accuracy" value="<?php echo htmlspecialchars((string)($_POST['gps_accuracy'] ?? '')); ?>">
										<div id="gps_accuracy_feedback" class="form-text mb-2 <?php echo (!empty($_POST['gps_accuracy']) ? '' : 'd-none'); ?>">
											<?php if (!empty($_POST['gps_accuracy'])): ?>GPS accuracy: ~<?php echo (int)round((float)$_POST['gps_accuracy']); ?>m<?php endif; ?>
										</div>
										<input type="text" class="form-control form-control-sm mb-2" id="gps_dms_input" autocomplete="off" placeholder="e.g. 1°15'51.6&quot;S 37°11'15.2&quot;E">
										<div class="form-text mb-2">Advanced: paste GPS coordinates in DMS format or drag the pin on the map.</div>
										<div id="customerLocationMap" style="height:260px;border-radius:0.5rem;overflow:hidden;border:1px solid #dee2e6;"></div>
										<div class="form-text mb-3">Click anywhere on the map to place the pin exactly where the client lives.</div>
										<div class="d-grid gap-2">
										<a href="/invoice?t=<?php echo urlencode($token); ?><?php echo $isSettledInvoice ? '' : '&amp;proforma=1'; ?>" class="btn btn-outline-primary" target="_blank" rel="noopener">
											<i class="bi bi-download me-1"></i> <?php echo $isSettledInvoice ? 'Download Invoice' : 'Download Proforma Invoice'; ?>
										</a>
										<?php if ($amountDue > 0.01): ?>
												<label for="pay_phone" class="form-label">Pay using this phone number</label>
												<input type="text" class="form-control mb-2" id="pay_phone" name="pay_phone" value="<?php echo htmlspecialchars((string)($_POST['pay_phone'] ?? $userRow['phone_number'])); ?>" placeholder="07..., 01..., or 254..." required>
												<button type="submit" class="btn btn-success w-100" id="registrationProformaPayBtn">
													<span class="spinner-border spinner-border-sm d-none" id="registrationProformaPaySpinner"></span>
													<span id="registrationProformaPayText"><i class="bi bi-phone me-1"></i> Send M-Pesa STK Push</span>
												</button>
												<button type="button" class="btn btn-outline-secondary w-100" disabled>
													Save location and send payment from above
												</button>
										<?php else: ?>
											<button type="button" class="btn btn-outline-secondary" disabled>
												<i class="bi bi-geo-alt me-1"></i> Location captured above
											</button>
											<?php if ($setupLink !== ''): ?>
												<a href="<?php echo htmlspecialchars($setupLink); ?>" class="btn btn-primary">
													<i class="bi bi-key me-1"></i> Set Portal Password
												</a>
											<?php endif; ?>
										<?php endif; ?>
										</div>
									</form>
									<?php else: ?>
									<div class="d-grid gap-2">
										<a href="/invoice?t=<?php echo urlencode($token); ?>" class="btn btn-outline-primary" target="_blank" rel="noopener">
											<i class="bi bi-download me-1"></i> Download Invoice
										</a>
										<button type="button" class="btn btn-outline-secondary" disabled>
											<i class="bi bi-geo-alt me-1"></i> Location locked after payment
										</button>
										<?php if ($setupLink !== ''): ?>
											<a href="<?php echo htmlspecialchars($setupLink); ?>" class="btn btn-primary">
												<i class="bi bi-key me-1"></i> Set Portal Password
											</a>
										<?php endif; ?>
									</div>
									<?php endif; ?>
								</div>
							</div>
						</div>
						<div id="registrationProformaStatus" class="alert alert-info mt-3 d-none"></div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>

<?php
$googleMapsApiKey = Database::env('GOOGLE_MAPS_API_KEY', '');
$tokenJson = json_encode($token);
$paymentIdJson = json_encode($createdPaymentId > 0 ? $createdPaymentId : null);

if ($googleMapsApiKey) {
	$custom_scripts = <<<JS
<script>
function initRegistrationProformaLocationMap() {
	var form = document.getElementById('registrationProformaPayForm');
	var btn = document.getElementById('registrationProformaPayBtn');
	var spinner = document.getElementById('registrationProformaPaySpinner');
	var text = document.getElementById('registrationProformaPayText');
	var statusBox = document.getElementById('registrationProformaStatus');
	var gpsBtn = document.getElementById('useGpsBtn');
	var locationInput = document.getElementById('location_label');
	var latitudeInput = document.getElementById('latitude');
	var longitudeInput = document.getElementById('longitude');
	var accuracyInput = document.getElementById('gps_accuracy');
	var accuracyFeedback = document.getElementById('gps_accuracy_feedback');
	var dmsInput = document.getElementById('gps_dms_input');
	var mapEl = document.getElementById('customerLocationMap');
	var token = {$tokenJson};
	var paymentId = {$paymentIdJson};

	if (form && btn) {
		form.addEventListener('submit', function() {
			btn.disabled = true;
			if (spinner) spinner.classList.remove('d-none');
			if (text) text.textContent = 'Sending prompt...';
		});
	}

	if (!mapEl || typeof google === 'undefined' || !google.maps) {
		return;
	}

	var defaultLat = -1.292066;
	var defaultLng = 36.821945;
	var startLat = defaultLat;
	var startLng = defaultLng;
	var zoom = 13;
	var marker = null;

	if (latitudeInput && longitudeInput && latitudeInput.value && longitudeInput.value) {
		var parsedLat = parseFloat(latitudeInput.value);
		var parsedLng = parseFloat(longitudeInput.value);
		if (!isNaN(parsedLat) && !isNaN(parsedLng)) {
			startLat = parsedLat;
			startLng = parsedLng;
			zoom = 16;
		}
	}

	var map = new google.maps.Map(mapEl, {
		center: { lat: startLat, lng: startLng },
		zoom: zoom,
		mapTypeId: google.maps.MapTypeId.ROADMAP,
		mapTypeControl: true,
		streetViewControl: false
	});

	function updateInputsFromLatLng(lat, lng) {
		if (latitudeInput) latitudeInput.value = lat.toFixed(7);
		if (longitudeInput) longitudeInput.value = lng.toFixed(7);
	}

	function ensureMarker(lat, lng) {
		var pos = new google.maps.LatLng(lat, lng);
		if (!marker) {
			marker = new google.maps.Marker({
				position: pos,
				map: map,
				draggable: true
			});
			marker.addListener('dragend', function() {
				var ll = marker.getPosition();
				updateInputsFromLatLng(ll.lat(), ll.lng());
			});
		} else {
			marker.setPosition(pos);
		}
		updateInputsFromLatLng(lat, lng);
	}

	function parseDmsValue(input) {
		if (!input) return null;
		var str = input.trim();
		if (!str) return null;
		str = str.replace(/"/g, '"').replace(/'/g, "'");
		var latRegex = /(\d+)[°º]\s*(\d+)[']\s*([0-9.]+)["”]?\s*([NS])/i;
		var lonRegex = /(\d+)[°º]\s*(\d+)[']\s*([0-9.]+)["”]?\s*([EW])/i;
		var latMatch = str.match(latRegex);
		var lonMatch = str.match(lonRegex);
		if (!latMatch || !lonMatch) return null;
		function toDecimal(deg, min, sec, hemi) {
			var d = parseFloat(deg) + parseFloat(min) / 60 + parseFloat(sec) / 3600;
			return /[SW]/i.test(hemi) ? -d : d;
		}
		var lat = toDecimal(latMatch[1], latMatch[2], latMatch[3], latMatch[4]);
		var lng = toDecimal(lonMatch[1], lonMatch[2], lonMatch[3], lonMatch[4]);
		if (isNaN(lat) || isNaN(lng)) return null;
		return { lat: lat, lng: lng };
	}

	function geocodeAndZoomFromQuery(query, showToastOnFound) {
		if (!query) return;
		query = query.trim();
		if (query.length < 3) return;
		var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(query + ', Kenya');
		fetch(url, { headers: { 'Accept-Language': 'en' } })
			.then(function(resp) { return resp.json(); })
			.then(function(results) {
				if (!Array.isArray(results) || results.length === 0) return;
				var first = results[0];
				var lat = parseFloat(first.lat);
				var lng = parseFloat(first.lon);
				if (isNaN(lat) || isNaN(lng)) return;
				map.setCenter({ lat: lat, lng: lng });
				map.setZoom(16);
				ensureMarker(lat, lng);
				if (showToastOnFound && window.showToast) {
					showToast('Suggested location for "' + query + '". Adjust the pin if needed.', 'info');
				}
			})
			.catch(function() {});
	}

	if (latitudeInput && longitudeInput && latitudeInput.value && longitudeInput.value) {
		var currentLat = parseFloat(latitudeInput.value);
		var currentLng = parseFloat(longitudeInput.value);
		if (!isNaN(currentLat) && !isNaN(currentLng)) {
			ensureMarker(currentLat, currentLng);
		}
	}

	map.addListener('click', function(e) {
		ensureMarker(e.latLng.lat(), e.latLng.lng());
	});

	function detectCurrentRegistrationLocation() {
		if (!gpsBtn) return;
		if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost') {
			if (window.showToast) showToast('GPS detection requires HTTPS. Open this page using https:// and try again.', 'danger');
			return;
		}
		if (!navigator.geolocation) {
			if (window.showToast) showToast('Geolocation is not supported by this browser.', 'danger');
			return;
		}
		gpsBtn.disabled = true;
		gpsBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Detecting...';
		var targetAccuracy = 15;
		var bestPosition = null;
		var finished = false;
		var watchId = null;
		var finishTimer = null;

		function resetButton() {
			gpsBtn.disabled = false;
			gpsBtn.innerHTML = '<i class="bi bi-geo-alt"></i> Use my current GPS location';
		}

		function updateAccuracyFeedback(accuracy) {
			if (!accuracyFeedback) return;
			accuracyFeedback.classList.remove('d-none', 'text-success', 'text-warning', 'text-danger');
			if (accuracy <= targetAccuracy) {
				accuracyFeedback.textContent = 'GPS accuracy: ~' + Math.round(accuracy) + 'm Good';
				accuracyFeedback.classList.add('text-success');
			} else {
				accuracyFeedback.textContent = 'GPS accuracy: ~' + Math.round(accuracy) + 'm. Waiting for 15m or better; move outside and hold still.';
				accuracyFeedback.classList.add('text-warning');
			}
		}

		function applyPosition(position, metTarget) {
			var lat = position.coords.latitude;
			var lng = position.coords.longitude;
			var accuracy = typeof position.coords.accuracy === 'number' ? position.coords.accuracy : 999999;
			updateInputsFromLatLng(lat, lng);
			if (accuracyInput) accuracyInput.value = accuracy;
			updateAccuracyFeedback(accuracy);
			if (window.showToast) {
				if (metTarget) {
					showToast('GPS captured (accuracy ~' + Math.round(accuracy) + 'm).', 'success');
				} else {
					showToast('Best GPS fix reached ~' + Math.round(accuracy) + 'm. Retry for 15m or better, or drag the pin manually.', 'warning');
				}
			}
			map.setCenter({ lat: lat, lng: lng });
			map.setZoom(18);
			ensureMarker(lat, lng);
			if (locationInput && locationInput.value.trim() === '') {
				var url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng);
				fetch(url, { headers: { 'Accept-Language': 'en' } })
					.then(function(resp) { return resp.json(); })
					.then(function(data) {
						if (!data || !locationInput) return;
						var label = (data.address && (data.address.suburb || data.address.neighbourhood || data.address.village || data.address.town || data.address.city)) || data.display_name || '';
						if (label) locationInput.value = String(label).slice(0, 120);
					})
					.catch(function() {});
			}
		}

		function finishWithPosition(position, metTarget) {
			if (finished) return;
			finished = true;
			if (watchId !== null) navigator.geolocation.clearWatch(watchId);
			if (finishTimer) clearTimeout(finishTimer);
			applyPosition(position, metTarget);
			resetButton();
		}

		function finishWithError(error) {
			if (finished) return;
			finished = true;
			if (watchId !== null) navigator.geolocation.clearWatch(watchId);
			if (finishTimer) clearTimeout(finishTimer);
			var message = 'Unable to get location. Please allow location access in your browser.';
			if (error && typeof error.code !== 'undefined') {
				if (error.code === 1) message = 'Location access was denied. Allow permission and try again.';
				else if (error.code === 2) message = 'Location is unavailable. Check GPS/network and try again.';
				else if (error.code === 3) message = 'Location request timed out. Please try again.';
			}
			if (window.showToast) showToast(message, 'danger');
			resetButton();
		}

		watchId = navigator.geolocation.watchPosition(function(position) {
			var accuracy = typeof position.coords.accuracy === 'number' ? position.coords.accuracy : 999999;
			if (!bestPosition) {
				bestPosition = position;
			} else {
				var bestAccuracy = typeof bestPosition.coords.accuracy === 'number' ? bestPosition.coords.accuracy : 999999;
				if (accuracy < bestAccuracy) {
					bestPosition = position;
				}
			}
			updateAccuracyFeedback(accuracy);
			if (accuracy <= targetAccuracy) {
				finishWithPosition(position, true);
			}
		}, function(error) {
			if (error && error.code === 1) {
				finishWithError(error);
			}
		}, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });

		finishTimer = setTimeout(function() {
			if (bestPosition) {
				finishWithPosition(bestPosition, false);
			} else {
				finishWithError({ code: 3 });
			}
		}, 15000);
	}

	if (gpsBtn) {
		gpsBtn.addEventListener('click', detectCurrentRegistrationLocation);
	}

	if (dmsInput) {
		var applyDms = function() {
			var parsed = parseDmsValue(dmsInput.value || '');
			if (!parsed) {
				if (window.showToast && dmsInput.value.trim() !== '') {
					showToast('Could not understand the coordinates. Use a format like 1°15\'51.6"S 37°11\'15.2"E.', 'danger');
				}
				return;
			}
			map.setCenter({ lat: parsed.lat, lng: parsed.lng });
			map.setZoom(16);
			ensureMarker(parsed.lat, parsed.lng);
			if (window.showToast) {
				showToast('GPS coordinates applied from DMS input.', 'success');
			}
		};
		dmsInput.addEventListener('change', applyDms);
		dmsInput.addEventListener('blur', applyDms);
		dmsInput.addEventListener('keydown', function(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				applyDms();
			}
		});
	}

	if (locationInput) {
		var lastLocationQuery = '';
		locationInput.addEventListener('change', function() {
			var q = (locationInput.value || '').trim();
			if (q.length < 3 || q === lastLocationQuery) return;
			lastLocationQuery = q;
			geocodeAndZoomFromQuery(q, true);
		});
		locationInput.addEventListener('blur', function() {
			var q = (locationInput.value || '').trim();
			if (q.length < 3 || q === lastLocationQuery) return;
			lastLocationQuery = q;
			geocodeAndZoomFromQuery(q, true);
		});
	}

	if ((!latitudeInput || !latitudeInput.value || !longitudeInput || !longitudeInput.value) && locationInput && locationInput.value) {
		geocodeAndZoomFromQuery(locationInput.value, false);
	}

	if (!token || !paymentId || !statusBox) {
		return;
	}

	statusBox.classList.remove('d-none');
	var seconds = 180;
	var done = false;

	function setStatus(message, alertClass) {
		statusBox.className = 'alert mt-3 ' + alertClass;
		statusBox.textContent = message;
	}

	function finish(message, redirectNow) {
		done = true;
		setStatus(message, 'alert-success');
		if (redirectNow) {
			window.location.href = '/registration-proforma?t=' + encodeURIComponent(token) + '&p=' + encodeURIComponent(paymentId);
		}
	}

	setStatus('Waiting for payment confirmation... ' + seconds + 's remaining.', 'alert-info');

	var countdown = setInterval(function() {
		if (done) {
			clearInterval(countdown);
			return;
		}
		seconds--;
		if (seconds <= 0) {
			clearInterval(countdown);
			setStatus('Confirmation is taking longer than expected. Refresh this page if you already approved the prompt.', 'alert-warning');
			return;
		}
		setStatus('Waiting for payment confirmation... ' + seconds + 's remaining.', 'alert-info');
	}, 1000);

	var poller = setInterval(function() {
		if (done) {
			clearInterval(poller);
			return;
		}
		fetch('/api/payments/check_status_from_link?t=' + encodeURIComponent(token) + '&p=' + encodeURIComponent(paymentId) + '&_ts=' + Date.now())
			.then(function(res) { return res.json(); })
			.then(function(data) {
				if (!data || data.status !== 'success' || !data.data) {
					return;
				}
				if (data.data.payment_status === 'completed') {
					clearInterval(poller);
					clearInterval(countdown);
					finish('Payment confirmed. Reloading your proforma...', true);
				}
			})
			.catch(function() {});
	}, 3000);
}
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=$googleMapsApiKey&callback=initRegistrationProformaLocationMap" async defer></script>
JS;
} else {
	$custom_scripts = <<<JS
<script>
(function() {
	var form = document.getElementById('registrationProformaPayForm');
	var btn = document.getElementById('registrationProformaPayBtn');
	var spinner = document.getElementById('registrationProformaPaySpinner');
	var text = document.getElementById('registrationProformaPayText');
	var statusBox = document.getElementById('registrationProformaStatus');
	var gpsBtn = document.getElementById('useGpsBtn');
	var locationInput = document.getElementById('location_label');
	var latitudeInput = document.getElementById('latitude');
	var longitudeInput = document.getElementById('longitude');
	var accuracyInput = document.getElementById('gps_accuracy');
	var accuracyFeedback = document.getElementById('gps_accuracy_feedback');
	var dmsInput = document.getElementById('gps_dms_input');
	var mapEl = document.getElementById('customerLocationMap');
	var map = null;
	var marker = null;
	var token = {$tokenJson};
	var paymentId = {$paymentIdJson};

	if (form && btn) {
		form.addEventListener('submit', function() {
			btn.disabled = true;
			if (spinner) spinner.classList.remove('d-none');
			if (text) text.textContent = 'Sending prompt...';
		});
	}

	function updateInputsFromLatLng(lat, lng) {
		if (latitudeInput) {
			latitudeInput.value = lat.toFixed(7);
		}
		if (longitudeInput) {
			longitudeInput.value = lng.toFixed(7);
		}
	}

	function ensureMarker(lat, lng) {
		if (!map || typeof L === 'undefined') {
			return;
		}
		var pos = [lat, lng];
		if (!marker) {
			marker = L.marker(pos, { draggable: true }).addTo(map);
			marker.on('dragend', function(e) {
				var ll = e.target.getLatLng();
				updateInputsFromLatLng(ll.lat, ll.lng);
			});
		} else {
			marker.setLatLng(pos);
		}
		updateInputsFromLatLng(lat, lng);
	}

	function parseDmsValue(input) {
		if (!input) {
			return null;
		}
		var str = input.trim();
		if (!str) {
			return null;
		}

		str = str.replace(/"/g, '"').replace(/'/g, "'");

		var latRegex = /(\d+)[°º]\s*(\d+)[']\s*([0-9.]+)["”]?\s*([NS])/i;
		var lonRegex = /(\d+)[°º]\s*(\d+)[']\s*([0-9.]+)["”]?\s*([EW])/i;
		var latMatch = str.match(latRegex);
		var lonMatch = str.match(lonRegex);
		if (!latMatch || !lonMatch) {
			return null;
		}

		function toDecimal(deg, min, sec, hemi) {
			var d = parseFloat(deg) + parseFloat(min) / 60 + parseFloat(sec) / 3600;
			return /[SW]/i.test(hemi) ? -d : d;
		}

		var lat = toDecimal(latMatch[1], latMatch[2], latMatch[3], latMatch[4]);
		var lng = toDecimal(lonMatch[1], lonMatch[2], lonMatch[3], lonMatch[4]);
		if (isNaN(lat) || isNaN(lng)) {
			return null;
		}
		return { lat: lat, lng: lng };
	}

	function geocodeAndZoomFromQuery(query, showToastOnFound) {
		if (!map || !query) {
			return;
		}
		query = query.trim();
		if (query.length < 3) {
			return;
		}

		var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(query + ', Kenya');
		fetch(url, { headers: { 'Accept-Language': 'en' } })
			.then(function(resp) { return resp.json(); })
			.then(function(results) {
				if (!Array.isArray(results) || results.length === 0) {
					return;
				}
				var first = results[0];
				var lat = parseFloat(first.lat);
				var lng = parseFloat(first.lon);
				if (isNaN(lat) || isNaN(lng)) {
					return;
				}
				map.setView([lat, lng], 16);
				ensureMarker(lat, lng);
				if (showToastOnFound && window.showToast) {
					showToast('Suggested location for "' + query + '". Adjust the pin if needed.', 'info');
				}
			})
			.catch(function() {});
	}

	function initLocationMap() {
		if (!mapEl || typeof L === 'undefined') {
			return;
		}

		var defaultLat = -1.292066;
		var defaultLng = 36.821945;
		var startLat = defaultLat;
		var startLng = defaultLng;
		var zoom = 13;

		if (latitudeInput && longitudeInput && latitudeInput.value && longitudeInput.value) {
			var parsedLat = parseFloat(latitudeInput.value);
			var parsedLng = parseFloat(longitudeInput.value);
			if (!isNaN(parsedLat) && !isNaN(parsedLng)) {
				startLat = parsedLat;
				startLng = parsedLng;
				zoom = 16;
			}
		}

		map = L.map('customerLocationMap').setView([startLat, startLng], zoom);
		var streetsLayer = L.tileLayer('https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
		});
		var satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{x}/{y}', {
			maxZoom: 19,
			attribution: 'Imagery &copy; Esri'
		});

		streetsLayer.addTo(map);
		L.control.layers({
			'Streets': streetsLayer,
			'Satellite': satelliteLayer
		}, {}).addTo(map);

		if (latitudeInput && longitudeInput && latitudeInput.value && longitudeInput.value) {
			var currentLat = parseFloat(latitudeInput.value);
			var currentLng = parseFloat(longitudeInput.value);
			if (!isNaN(currentLat) && !isNaN(currentLng)) {
				ensureMarker(currentLat, currentLng);
			}
		}

		map.on('click', function(e) {
			ensureMarker(e.latlng.lat, e.latlng.lng);
		});

		if ((!latitudeInput || !latitudeInput.value || !longitudeInput || !longitudeInput.value) && locationInput && locationInput.value) {
			geocodeAndZoomFromQuery(locationInput.value, false);
		}
	}

	function detectCurrentRegistrationLocation() {
		if (!gpsBtn) {
			return;
		}

		if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost') {
			if (window.showToast) {
				showToast('GPS detection requires HTTPS. Open this page using https:// and try again.', 'danger');
			}
			return;
		}

		if (!navigator.geolocation) {
			if (window.showToast) {
				showToast('Geolocation is not supported by this browser.', 'danger');
			}
			return;
		}

		gpsBtn.disabled = true;
		gpsBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Detecting...';
		var targetAccuracy = 15;
		var bestPosition = null;
		var finished = false;
		var watchId = null;
		var finishTimer = null;

		function resetButton() {
			gpsBtn.disabled = false;
			gpsBtn.innerHTML = '<i class="bi bi-geo-alt"></i> Use my current GPS location';
		}

		function updateAccuracyFeedback(accuracy) {
			if (!accuracyFeedback) return;
			accuracyFeedback.classList.remove('d-none', 'text-success', 'text-warning', 'text-danger');
			if (accuracy <= targetAccuracy) {
				accuracyFeedback.textContent = 'GPS accuracy: ~' + Math.round(accuracy) + 'm Good';
				accuracyFeedback.classList.add('text-success');
			} else {
				accuracyFeedback.textContent = 'GPS accuracy: ~' + Math.round(accuracy) + 'm. Waiting for 15m or better; move outside and hold still.';
				accuracyFeedback.classList.add('text-warning');
			}
		}

		function applyPosition(position, metTarget) {
			var lat = position.coords.latitude;
			var lng = position.coords.longitude;
			var accuracy = typeof position.coords.accuracy === 'number' ? position.coords.accuracy : 999999;
			if (latitudeInput) latitudeInput.value = lat.toFixed(7);
			if (longitudeInput) longitudeInput.value = lng.toFixed(7);
			if (accuracyInput) accuracyInput.value = accuracy;
			updateAccuracyFeedback(accuracy);
			if (window.showToast) {
				if (metTarget) {
					showToast('GPS captured (accuracy ~' + Math.round(accuracy) + 'm).', 'success');
				} else {
					showToast('Best GPS fix reached ~' + Math.round(accuracy) + 'm. Retry for 15m or better, or drag the pin manually.', 'warning');
				}
			}
			if (map) {
				map.setView([lat, lng], 18);
				ensureMarker(lat, lng);
			}
			if (locationInput && locationInput.value.trim() === '') {
				var url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng);
				fetch(url, { headers: { 'Accept-Language': 'en' } })
					.then(function(resp) { return resp.json(); })
					.then(function(data) {
						if (!data || !locationInput) {
							return;
						}
						var label = (data.address && (data.address.suburb || data.address.neighbourhood || data.address.village || data.address.town || data.address.city)) || data.display_name || '';
						if (label) {
							locationInput.value = String(label).slice(0, 120);
						}
					})
					.catch(function() {});
			}
		}

		function finishWithPosition(position, metTarget) {
			if (finished) return;
			finished = true;
			if (watchId !== null) navigator.geolocation.clearWatch(watchId);
			if (finishTimer) clearTimeout(finishTimer);
			applyPosition(position, metTarget);
			resetButton();
		}

		function finishWithError(error) {
			if (finished) return;
			finished = true;
			if (watchId !== null) navigator.geolocation.clearWatch(watchId);
			if (finishTimer) clearTimeout(finishTimer);
			var message = 'Unable to get location. Please allow location access in your browser.';
			if (error && typeof error.code !== 'undefined') {
				if (error.code === 1) {
					message = 'Location access was denied. Allow permission and try again.';
				} else if (error.code === 2) {
					message = 'Location is unavailable. Check GPS/network and try again.';
				} else if (error.code === 3) {
					message = 'Location request timed out. Please try again.';
				}
			}
			if (window.showToast) {
				showToast(message, 'danger');
			}
			resetButton();
		}

		watchId = navigator.geolocation.watchPosition(function(position) {
			var accuracy = typeof position.coords.accuracy === 'number' ? position.coords.accuracy : 999999;
			if (!bestPosition) {
				bestPosition = position;
			} else {
				var bestAccuracy = typeof bestPosition.coords.accuracy === 'number' ? bestPosition.coords.accuracy : 999999;
				if (accuracy < bestAccuracy) {
					bestPosition = position;
				}
			}
			updateAccuracyFeedback(accuracy);
			if (accuracy <= targetAccuracy) {
				finishWithPosition(position, true);
			}
		}, function(error) {
			if (error && error.code === 1) {
				finishWithError(error);
			}
		}, {
			enableHighAccuracy: true,
			timeout: 10000,
			maximumAge: 0
		});

		finishTimer = setTimeout(function() {
			if (bestPosition) {
				finishWithPosition(bestPosition, false);
			} else {
				finishWithError({ code: 3 });
			}
		}, 15000);
	}

	if (gpsBtn) {
		gpsBtn.addEventListener('click', detectCurrentRegistrationLocation);
	}

	if (dmsInput) {
		var applyDms = function() {
			var parsed = parseDmsValue(dmsInput.value || '');
			if (!parsed) {
				if (window.showToast && dmsInput.value.trim() !== '') {
					showToast('Could not understand the coordinates. Use a format like 1°15\'51.6"S 37°11\'15.2"E.', 'danger');
				}
				return;
			}
			if (map) {
				map.setView([parsed.lat, parsed.lng], 16);
			}
			ensureMarker(parsed.lat, parsed.lng);
			if (window.showToast) {
				showToast('GPS coordinates applied from DMS input.', 'success');
			}
		};

		dmsInput.addEventListener('change', applyDms);
		dmsInput.addEventListener('blur', applyDms);
		dmsInput.addEventListener('keydown', function(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				applyDms();
			}
		});
	}

	if (locationInput) {
		var lastLocationQuery = '';
		locationInput.addEventListener('change', function() {
			var q = (locationInput.value || '').trim();
			if (q.length < 3 || q === lastLocationQuery) {
				return;
			}
			lastLocationQuery = q;
			geocodeAndZoomFromQuery(q, true);
		});
		locationInput.addEventListener('blur', function() {
			var q = (locationInput.value || '').trim();
			if (q.length < 3 || q === lastLocationQuery) {
				return;
			}
			lastLocationQuery = q;
			geocodeAndZoomFromQuery(q, true);
		});
	}

	initLocationMap();

	if (!token || !paymentId || !statusBox) {
		return;
	}

	statusBox.classList.remove('d-none');
	var seconds = 180;
	var done = false;

	function setStatus(message, alertClass) {
		statusBox.className = 'alert mt-3 ' + alertClass;
		statusBox.textContent = message;
	}

	function finish(message, redirectNow) {
		done = true;
		setStatus(message, 'alert-success');
		if (redirectNow) {
			window.location.href = '/registration-proforma?t=' + encodeURIComponent(token) + '&p=' + encodeURIComponent(paymentId);
		}
	}

	setStatus('Waiting for payment confirmation... ' + seconds + 's remaining.', 'alert-info');

	var countdown = setInterval(function() {
		if (done) {
			clearInterval(countdown);
			return;
		}
		seconds--;
		if (seconds <= 0) {
			clearInterval(countdown);
			setStatus('Confirmation is taking longer than expected. Refresh this page if you already approved the prompt.', 'alert-warning');
			return;
		}
		setStatus('Waiting for payment confirmation... ' + seconds + 's remaining.', 'alert-info');
	}, 1000);

	var poller = setInterval(function() {
		if (done) {
			clearInterval(poller);
			return;
		}
		fetch('/api/payments/check_status_from_link?t=' + encodeURIComponent(token) + '&p=' + encodeURIComponent(paymentId) + '&_ts=' + Date.now())
			.then(function(res) { return res.json(); })
			.then(function(data) {
				if (!data || data.status !== 'success' || !data.data) {
					return;
				}
				if (data.data.payment_status === 'completed') {
					clearInterval(poller);
					clearInterval(countdown);
					finish('Payment confirmed. Reloading your proforma...', true);
				}
			})
			.catch(function() {});
	}, 3000);
})();
</script>
JS;
}
?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>