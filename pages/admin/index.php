<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/mpesa_config.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';
require_once __DIR__ . '/../../includes/MeterReading.php';
require_once __DIR__ . '/../../includes/SMS.php';
require_once __DIR__ . '/../../includes/PaymentLink.php';

$database = new Database();
$db = $database->getConnection();

$auth = new Auth($db);
if(!$auth->isLoggedIn() || !$auth->hasPermission('manage_settings')) {
	header("Location: /login");
	exit;
}

$message = null;
$message_type = "success";
$is_flash_message = false;

$settingsService = null;
if ($db) {
	$settingsService = new BillingSettings($db);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db && $settingsService) {
	if (isset($_POST['action']) && $_POST['action'] === 'update_settings') {
		$rate = (float)$_POST['rate_per_unit'];
		$service = (float)$_POST['service_charge'];
		$company_pin = isset($_POST['company_pin']) ? trim($_POST['company_pin']) : null;
		$etims_integration_url = isset($_POST['etims_integration_url']) ? trim($_POST['etims_integration_url']) : null;
		$etims_api_key = isset($_POST['etims_api_key']) ? trim($_POST['etims_api_key']) : null;
		$company_name = isset($_POST['company_name']) ? trim($_POST['company_name']) : null;
		$support_phone = isset($_POST['support_phone']) ? trim($_POST['support_phone']) : null;
		$support_email = isset($_POST['support_email']) ? trim($_POST['support_email']) : null;
		$currency_code = isset($_POST['currency_code']) ? strtoupper(trim($_POST['currency_code'])) : null;
		$locale_code = isset($_POST['locale_code']) ? trim($_POST['locale_code']) : null;
		$timezone_name = isset($_POST['timezone_name']) ? trim($_POST['timezone_name']) : null;
		$financial_year_start_month = isset($_POST['financial_year_start_month']) ? (int)$_POST['financial_year_start_month'] : null;
		$vat_rate = isset($_POST['vat_rate']) ? $_POST['vat_rate'] : null;
		$etims_taxation_type_code = isset($_POST['etims_taxation_type_code']) ? $_POST['etims_taxation_type_code'] : null;
		$registration_fee = isset($_POST['registration_fee']) ? $_POST['registration_fee'] : null;
		$enforce_location_accuracy = isset($_POST['enforce_location_accuracy']) ? 1 : 0;

		if ($rate <= 0) {
			$message = "Rate per m³ must be greater than 0.";
			$message_type = "danger";
		} else {
			if ($settingsService->updateSettings($rate, $service, $company_pin, $etims_integration_url, $etims_api_key, $company_name, $support_phone, $support_email, $currency_code, $financial_year_start_month, $vat_rate, $etims_taxation_type_code, $registration_fee, $locale_code, $timezone_name, $enforce_location_accuracy)) {
				// Log activity
				try {
					$logger = new ActivityLog($db);
					$logger->log(
						$_SESSION['user_id'] ?? null,
						'update_settings',
						'billing_settings',
						1,
						'Updated billing and company settings',
						array(
							'rate_per_unit' => $rate,
							'service_charge' => $service,
							'company_name' => $company_name,
							'support_phone' => $support_phone,
							'support_email' => $support_email,
							'currency_code' => $currency_code,
							'locale_code' => $locale_code,
							'timezone_name' => $timezone_name
						)
					);
				} catch (Exception $e) {
					// Ignore logging errors
				}
				$_SESSION['flash_message'] = "Billing settings updated successfully.";
				$_SESSION['flash_type'] = "success";
				header("Location: /settings");
				exit;
			} else {
				$message = "Failed to update billing settings.";
				$message_type = "danger";
			}
		}
	}

	if (isset($_POST['action']) && $_POST['action'] === 'save_tariff_plan') {
		$planId = (int)($_POST['tariff_plan_id'] ?? 0);
		$name = trim((string)($_POST['tariff_name'] ?? ''));
		$category = trim((string)($_POST['tariff_category'] ?? 'all'));
		$effectiveFrom = trim((string)($_POST['effective_from'] ?? ''));
		$effectiveTo = trim((string)($_POST['effective_to'] ?? ''));
		$baseRate = (float)($_POST['base_rate_per_unit'] ?? 0);
		$serviceCharge = (float)($_POST['tariff_service_charge'] ?? 0);
		$vatRate = (float)($_POST['tariff_vat_rate'] ?? 0);
		$isActive = isset($_POST['tariff_is_active']) ? 1 : 0;

		$blockFromUnits = $_POST['block_from_unit'] ?? [];
		$blockToUnits = $_POST['block_to_unit'] ?? [];
		$blockRates = $_POST['block_rate'] ?? [];
		$blocks = [];
		$blockCount = max(count((array)$blockFromUnits), count((array)$blockRates));
		for ($i = 0; $i < $blockCount; $i++) {
			$from = trim((string)($blockFromUnits[$i] ?? ''));
			$to = trim((string)($blockToUnits[$i] ?? ''));
			$rate = trim((string)($blockRates[$i] ?? ''));
			if ($from === '' && $to === '' && $rate === '') {
				continue;
			}
			$blocks[] = [
				'from_unit' => ($from === '' ? 0 : (float)$from),
				'to_unit' => ($to === '' ? null : (float)$to),
				'rate_per_unit' => ($rate === '' ? 0 : (float)$rate),
			];
		}

		try {
			$savedId = $settingsService->saveTariffPlan([
				'id' => $planId,
				'name' => $name,
				'category' => $category,
				'effective_from' => $effectiveFrom,
				'effective_to' => $effectiveTo,
				'base_rate_per_unit' => $baseRate,
				'service_charge' => $serviceCharge,
				'vat_rate' => $vatRate,
				'is_active' => $isActive,
			], $blocks);

			$_SESSION['flash_message'] = $planId > 0
				? 'Tariff plan updated successfully.'
				: 'Tariff plan created successfully.';
			$_SESSION['flash_type'] = 'success';
			header('Location: /settings?edit_tariff_id=' . (int)$savedId);
			exit;
		} catch (Throwable $e) {
			$message = 'Failed to save tariff plan: ' . $e->getMessage();
			$message_type = 'danger';
		}
	}

	if (isset($_POST['action']) && $_POST['action'] === 'toggle_tariff_plan') {
		$planId = (int)($_POST['tariff_plan_id'] ?? 0);
		$isActive = (int)($_POST['is_active'] ?? 0);
		if ($settingsService->setTariffPlanStatus($planId, $isActive)) {
			$_SESSION['flash_message'] = $isActive ? 'Tariff plan activated.' : 'Tariff plan deactivated.';
			$_SESSION['flash_type'] = 'success';
			header('Location: /settings');
			exit;
		}
		$message = 'Failed to update tariff status.';
		$message_type = 'danger';
	}

	if (isset($_POST['action']) && $_POST['action'] === 'add_reading') {
		$identifier = trim($_POST['account_or_meter']);
		$current_reading = (float)$_POST['current_reading'];
		$billing_month = $_POST['billing_month'];
		$due_date = $_POST['due_date'];

		$userService = new User($db);
		$readingService = new MeterReading($db);
		$billService = new Bill($db);
		$settingsService = new BillingSettings($db);
		$settings = $settingsService->getSettings();

		if ($current_reading <= 0) {
			$message = "Current reading must be greater than 0.";
			$message_type = "danger";
		} else {
			$user = $userService->getByAccountNumber($identifier);
			if (!$user) {
				$user = $userService->getByMeterNumber($identifier);
			}

			if (!$user) {
				$matches = $userService->searchByNameOrAccount($identifier, 2);
				if (count($matches) === 1) {
					$user = $matches[0];
				} elseif (count($matches) > 1) {
					$message = "Multiple clients found. Please use account or meter number.";
					$message_type = "danger";
				}
			}

			if (!$user) {
				$message = $message ?? "Account, meter number, or name not found.";
				$message_type = "danger";
			} else {
				$photo_path = null;
				if (isset($_FILES['meter_photo']) && $_FILES['meter_photo']['error'] === UPLOAD_ERR_OK) {
					$photo = $_FILES['meter_photo'];
					$imageInfo = getimagesize($photo['tmp_name']);
					$allowedTypes = ['image/jpeg', 'image/png'];
					if ($imageInfo === false || !in_array($imageInfo['mime'], $allowedTypes, true)) {
						$message = "Please upload a valid JPG or PNG image.";
						$message_type = "danger";
					} else {
						$upload_dir = __DIR__ . '/../../uploads/meter_readings';
						if (!is_dir($upload_dir)) {
							mkdir($upload_dir, 0755, true);
						}
						$ext = $imageInfo['mime'] === 'image/png' ? 'png' : 'jpg';
						$filename = 'reading_' . $user['account_number'] . '_' . time() . '.' . $ext;
						$destination = $upload_dir . '/' . $filename;

						if (move_uploaded_file($photo['tmp_name'], $destination)) {
							$photo_path = '/uploads/meter_readings/' . $filename;
						} else {
							$message = "Failed to upload meter photo.";
							$message_type = "danger";
						}
					}
				}

				if (!$message) {
					$billResult = $billService->createBillForUser(
						$user['id'],
						$user['account_number'],
						$current_reading,
						$billing_month,
						$due_date,
						$settings['rate_per_unit'],
						$settings['service_charge'],
						'pending'
					);

					if (!$billResult) {
						$message = "Failed to create pending bill.";
						$message_type = "danger";
					} else {
						$sms = new SMS();
						$previousReading = $billResult['previous_reading'];
						$currentReading = $billResult['current_reading'];
						$units = $billResult['consumption'];
						$billAmount = $billResult['amount'];
						// Compute account balance so any overpayment reduces the new amount to pay
						$previousBalance = 0;
						$totalToPay = $billAmount;
						try {
							// Sum of all bills for this user (including this new one)
							$stmtBills = $db->prepare('SELECT COALESCE(SUM(amount),0) AS total_billed FROM bills WHERE user_id = :uid');
							$stmtBills->bindParam(':uid', $user['id'], PDO::PARAM_INT);
							$stmtBills->execute();
							$rowBills = $stmtBills->fetch(PDO::FETCH_ASSOC) ?: ['total_billed' => 0];
							$totalBilled = (float)$rowBills['total_billed'];

							// Sum of all completed payments for this user
							$stmtPay = $db->prepare("SELECT COALESCE(SUM(amount),0) AS total_paid FROM payments WHERE user_id = :uid AND status = 'completed'");
							$stmtPay->bindParam(':uid', $user['id'], PDO::PARAM_INT);
							$stmtPay->execute();
							$rowPay = $stmtPay->fetch(PDO::FETCH_ASSOC) ?: ['total_paid' => 0];
							$totalPaid = (float)$rowPay['total_paid'];

							// Outstanding after adding this new bill
							$outstandingAfter = $totalBilled - $totalPaid;
							// Previous balance is what was outstanding before this bill
							$previousBalance = $outstandingAfter - $billAmount;
							// Total to pay is the outstanding after this bill; cannot be negative
							$totalToPay = max(0, $outstandingAfter);
						} catch (Exception $e) {
							// If anything fails, fall back to simple behaviour
							$previousBalance = 0;
							$totalToPay = $billAmount;
						}
						$billDate = date('d-m-Y');
						$account = $user['account_number'];
						$paybill = MpesaConfig::getShortCode();
						$payUrl = PaymentLink::generateLink((int)$billResult['bill_id']);

						$messageText = "AC: {$account}\n" .
							"BillDate: {$billDate}\n" .
							"CurRead: " . number_format($currentReading, 2) . "\n" .
							"PrevRead: " . number_format($previousReading, 2) . "\n" .
							"Units: " . number_format($units, 2) . "\n" .
							"Bill: KES " . number_format($billAmount, 2) . "\n" .
							"PrevBal: KES " . number_format($previousBalance, 2) . "\n" .
							"Total to Pay: KES " . number_format($totalToPay, 2) . "\n" .
							"DueDate: " . date('d-m-Y', strtotime($due_date)) . "\n" .
							"Paybill: {$paybill}\n" .
							"Acc: {$account}\n" .
							"Pay online: {$payUrl}";

						$sms->send($user['phone_number'], $messageText);
						$reading_id = $readingService->createReading(
							$user['id'],
							$user['account_number'],
							$user['meter_number'],
							$current_reading,
							$billing_month,
							$due_date,
							$photo_path,
							$_SESSION['user_id'],
							$billResult['bill_id'],
							'approved',
							$_SESSION['user_id']
						);

						if ($reading_id) {
							$_SESSION['flash_message'] = "Meter reading submitted and approved. Bill created and pending payment.";
							$_SESSION['flash_type'] = "success";
							header("Location: /settings");
							exit;
						} else {
							$message = "Failed to submit meter reading.";
							$message_type = "danger";
						}
					}
				}
			}
		}
	}

	if (isset($_POST['action']) && $_POST['action'] === 'approve_reading') {
		$reading_id = (int)$_POST['reading_id'];
		$readingService = new MeterReading($db);
		$billService = new Bill($db);
		$settingsService = new BillingSettings($db);
		$settings = $settingsService->getSettings();

		$reading = $readingService->getById($reading_id);
		if (!$reading) {
			$message = "Reading not found.";
			$message_type = "danger";
		} else {
			if (!empty($reading['bill_id'])) {
				$readingService->markApproved($reading_id, $_SESSION['user_id']);
				$_SESSION['flash_message'] = "Reading approved. Pending bill remains for payment.";
				$_SESSION['flash_type'] = "success";
				header("Location: /settings");
				exit;
			} else {
				$result = $billService->createBillForUser(
					$reading['user_id'],
					$reading['account_number'],
					$reading['current_reading'],
					$reading['billing_month'],
					$reading['due_date'],
					$settings['rate_per_unit'],
					$settings['service_charge'],
					'pending'
				);

				if ($result) {
					$readingService->attachBill($reading_id, $result['bill_id']);
					$readingService->markApproved($reading_id, $_SESSION['user_id']);
					$_SESSION['flash_message'] = "Reading approved and bill created. Amount: KES " . number_format($result['amount'], 2) . ".";
					$_SESSION['flash_type'] = "success";
					header("Location: /settings");
					exit;
				} else {
					$message = "Failed to create bill from reading.";
					$message_type = "danger";
				}
			}
		}
	}

	if (isset($_POST['action']) && $_POST['action'] === 'reject_reading') {
		$reading_id = (int)$_POST['reading_id'];
		$readingService = new MeterReading($db);
		$reading = $readingService->getById($reading_id);
		if ($readingService->markRejected($reading_id, $_SESSION['user_id'])) {
			if (!empty($reading['bill_id'])) {
				$billService = new Bill($db);
				$billService->updateStatus($reading['bill_id'], 'cancelled');
			}
			$_SESSION['flash_message'] = "Reading rejected. Pending bill cancelled.";
			$_SESSION['flash_type'] = "success";
			header("Location: /settings");
			exit;
		} else {
			$message = "Failed to reject reading.";
			$message_type = "danger";
		}
	}
}

$settings = null;
$pending_readings = [];
$clients_summary = [];
$client_list = [];
$tariff_plans = [];
$editing_tariff = null;
if ($db && $settingsService) {
	$settings = $settingsService->getSettings();
	$tariff_plans = $settingsService->listTariffPlans(false);
	$editingTariffId = (int)($_GET['edit_tariff_id'] ?? 0);
	if ($editingTariffId > 0) {
		$editing_tariff = $settingsService->getTariffPlanById($editingTariffId);
	}
	$readingService = new MeterReading($db);
	$pending_readings = $readingService->listPending();
	$billService = new Bill($db);
	$clients_summary = $billService->getUsersBillingSummary();
	$userService = new User($db);
	$client_list = $userService->listAll();
}

$is_admin_page = true;
$page_title = "System Setting";
require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container-fluid mt-4 admin-shell system-settings-page">
	<div class="row">
		<div class="col-md-12">
			<div class="admin-page-header admin-hero-header system-settings-hero">
				<div class="admin-hero-main">
					<p class="admin-hero-eyebrow mb-2"><i class="bi bi-sliders"></i> Billing Control Center</p>
					<h2 class="mb-1">System Setting</h2>
					<p class="text-muted mb-0">Manage billing settings and record meter readings.</p>
				</div>
				<div class="admin-hero-actions system-settings-hero-actions">
					<div class="admin-hero-chip">
						<i class="bi bi-cash-stack"></i>
						Rate: <strong>KES <?php echo number_format((float)($settings['rate_per_unit'] ?? 50), 2); ?></strong>
					</div>
					<div class="admin-hero-chip">
						<i class="bi bi-person-plus"></i>
						Registration: <strong>KES <?php echo number_format((float)($settings['registration_fee'] ?? 0), 2); ?></strong>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div id="adminToast" class="admin-toast" style="position:fixed; top:4.5rem; right:1rem; z-index:2000; display:none; min-width:260px;">
		<div id="adminToastBody" class="alert alert-success mb-0 shadow"></div>
	</div>

	<?php
	if (isset($_SESSION['flash_message'])) {
		$message = $_SESSION['flash_message'];
		$message_type = $_SESSION['flash_type'] ?? 'success';
		unset($_SESSION['flash_message'], $_SESSION['flash_type']);
		$is_flash_message = true;
	}
	?>

	<?php if($is_flash_message && $message): ?>
		<script>
		window.addEventListener('load', function() {
			var msg = <?php echo json_encode($message); ?>;
			var type = <?php echo json_encode($message_type); ?>;
			if (window.WbsAdminUi && typeof window.WbsAdminUi.showFlashToast === 'function') {
				window.WbsAdminUi.showFlashToast(msg, type);
				return;
			}
			if (window.showToast) {
				window.showToast(msg, type);
				return;
			}
			var box = document.getElementById('adminToast');
			var body = document.getElementById('adminToastBody');
			if (!box || !body) return;
			body.classList.remove('alert-success','alert-danger','alert-warning','alert-info');
			if (type === 'danger' || type === 'error') {
				body.classList.add('alert-danger');
			} else if (type === 'warning') {
				body.classList.add('alert-warning');
			} else if (type === 'info') {
				body.classList.add('alert-info');
			} else {
				body.classList.add('alert-success');
			}
			body.textContent = msg;
			box.style.display = 'block';
			setTimeout(function(){ box.style.display = 'none'; }, 4000);
		});
		</script>
	<?php elseif($message): ?>
		<div class="alert alert-<?php echo htmlspecialchars($message_type); ?> mt-3">
			<?php echo htmlspecialchars($message); ?>
		</div>
	<?php endif; ?>

	<div class="row mt-4 g-4 align-items-stretch">
		<div class="col-lg-8">
			<div class="card system-settings-card system-settings-primary-card h-100">
				<div class="card-header">
					<h5 class="mb-0 admin-section-title">Billing Settings</h5>
				</div>
				<div class="card-body">
					<form method="POST">
						<input type="hidden" name="action" value="update_settings">
						<div class="row g-3">
							<div class="col-md-6">
								<label class="form-label">Company Name</label>
								<input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars($settings['company_name'] ?? 'BreMac Consultant Ltd'); ?>" placeholder="e.g. BreMac Consultant Ltd" required>
								<div class="form-text">Shown in customer SMS messages and receipts. Defaults to BreMac Consultant Ltd.</div>
							</div>
							<div class="col-md-6">
								<label class="form-label">Support Phone</label>
								<input type="text" name="support_phone" class="form-control" value="<?php echo htmlspecialchars($settings['support_phone'] ?? '+254 700 000 000'); ?>" placeholder="e.g. +254 700 000 000">
								<div class="form-text">Shown in the footer as the main contact phone.</div>
							</div>
							<div class="col-md-6">
								<label class="form-label">Support Email</label>
								<input type="email" name="support_email" class="form-control" value="<?php echo htmlspecialchars($settings['support_email'] ?? 'support@waterbilling.com'); ?>" placeholder="e.g. support@waterbilling.com">
								<div class="form-text">Shown in the footer as the main support email.</div>
							</div>
							<div class="col-md-3">
								<label class="form-label">Rate per m³ (<?php echo htmlspecialchars($settings['currency_code'] ?? 'KES'); ?>)</label>
								<input type="number" step="0.01" min="0" name="rate_per_unit" class="form-control" value="<?php echo htmlspecialchars($settings['rate_per_unit'] ?? '50.00'); ?>" required>
							</div>
							<div class="col-md-3">
								<label class="form-label">Service Charge (<?php echo htmlspecialchars($settings['currency_code'] ?? 'KES'); ?>)</label>
								<input type="number" step="0.01" min="0" name="service_charge" class="form-control" value="<?php echo htmlspecialchars($settings['service_charge'] ?? '0.00'); ?>" required>
							</div>
							<div class="col-md-3">
								<label class="form-label">Registration Fee (<?php echo htmlspecialchars($settings['currency_code'] ?? 'KES'); ?>)</label>
								<input type="number" step="0.01" min="0" name="registration_fee" class="form-control" value="<?php echo htmlspecialchars($settings['registration_fee'] ?? '0.00'); ?>">
								<div class="form-text">One-time fee charged on new registrations.</div>
							</div>
							<div class="col-md-3">
								<label class="form-label">Enforce GPS Location Accuracy</label>
								<div class="form-check form-switch mt-1">
									<input class="form-check-input" type="checkbox" role="switch" id="enforce_location_accuracy_switch" name="enforce_location_accuracy" value="1" <?php echo !empty($settings['enforce_location_accuracy']) ? 'checked' : ''; ?>>
									<label class="form-check-label" for="enforce_location_accuracy_switch">Require accurate GPS (&le;14m)</label>
								</div>
								<div class="form-text">When enabled, clients must capture GPS with accuracy &le;14m before submitting the registration form.</div>
							</div>
							<div class="col-md-3">
								<label class="form-label">Currency Code</label>
								<input type="text" name="currency_code" class="form-control" value="<?php echo htmlspecialchars($settings['currency_code'] ?? 'KES'); ?>" maxlength="10" placeholder="e.g. KES, USD">
								<div class="form-text">ISO currency code used in reports and invoices.</div>
							</div>
							<div class="col-md-3">
								<label class="form-label">Locale</label>
								<input type="text" name="locale_code" class="form-control" value="<?php echo htmlspecialchars($settings['locale_code'] ?? 'en-KE'); ?>" maxlength="20" placeholder="e.g. en-KE, en-US">
								<div class="form-text">Used for localized formatting and messaging.</div>
							</div>
							<div class="col-md-3">
								<label class="form-label">Timezone</label>
								<input type="text" name="timezone_name" class="form-control" value="<?php echo htmlspecialchars($settings['timezone_name'] ?? 'Africa/Nairobi'); ?>" maxlength="100" placeholder="e.g. Africa/Nairobi">
								<div class="form-text">IANA timezone for billing and reporting.</div>
							</div>
							<div class="col-md-3">
								<label class="form-label">Financial Year Starts</label>
								<select name="financial_year_start_month" class="form-select">
									<?php
									$fyStart = (int)($settings['financial_year_start_month'] ?? 1);
									$months = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
									foreach ($months as $num => $label): ?>
										<option value="<?php echo $num; ?>" <?php echo $fyStart === $num ? 'selected' : ''; ?>><?php echo $label; ?></option>
									<?php endforeach; ?>
								</select>
								<div class="form-text">Used for annual financial reporting.</div>
							</div>
						</div>
						<hr class="my-4">
						<h6 class="mb-3">eTIMS Integration (Optional)</h6>
						<div class="row g-3">
							<div class="col-md-4">
								<label class="form-label">Company PIN (KRA PIN/TIN)</label>
								<input type="text" name="company_pin" class="form-control" value="<?php echo htmlspecialchars($settings['company_pin'] ?? ''); ?>" placeholder="e.g. P012345678Z">
								<div class="form-text">Used when submitting invoices to eTIMS. Leave blank if not integrated.</div>
							</div>
							<div class="col-md-5">
								<label class="form-label">eTIMS Integration URL</label>
								<input type="url" name="etims_integration_url" class="form-control" value="<?php echo htmlspecialchars($settings['etims_integration_url'] ?? ''); ?>" placeholder="https://etims.example.com/api/saveSales">
								<div class="form-text">Endpoint for your eTIMS gateway.</div>
							</div>
							<div class="col-md-3">
								<label class="form-label">eTIMS API Key</label>
								<input type="text" name="etims_api_key" class="form-control" value="<?php echo htmlspecialchars($settings['etims_api_key'] ?? ''); ?>" placeholder="Optional API key/token">
								<div class="form-text">Only if your eTIMS gateway requires it.</div>
							</div>
						</div>
						<div class="row g-3 mt-2">
							<div class="col-md-3">
								<label class="form-label">VAT Rate %</label>
								<input type="number" step="0.01" min="0" max="100" name="vat_rate" class="form-control" value="<?php echo htmlspecialchars(isset($settings['vat_rate']) ? $settings['vat_rate'] : '0.00'); ?>" placeholder="e.g. 16.00">
								<div class="form-text">VAT percentage used for ETIMS tax calculations.</div>
							</div>
							<div class="col-md-4">
								<label class="form-label">Taxation Type Code</label>
								<?php $taxCode = strtoupper(trim((string)($settings['etims_taxation_type_code'] ?? ''))); ?>
								<select name="etims_taxation_type_code" class="form-select">
									<option value="" <?php echo $taxCode === '' ? 'selected' : ''; ?>>Auto (based on VAT rate)</option>
									<option value="A" <?php echo $taxCode === 'A' ? 'selected' : ''; ?>>A - Zero-rated / Exempt</option>
									<option value="B" <?php echo $taxCode === 'B' ? 'selected' : ''; ?>>B - Standard VAT</option>
								</select>
								<div class="form-text">If left as Auto, the system will choose A for 0% and B when VAT &gt; 0.</div>
							</div>
							<div class="col-md-5">
								<label class="form-label">Payment Link Secret (.env)</label>
								<div class="input-group mb-2">
									<input type="text" id="paymentLinkSecretValue" class="form-control" value="<?php echo htmlspecialchars(MpesaConfig::getPaymentLinkSecret()); ?>" readonly>
									<button type="button" class="btn btn-outline-primary" id="btnGeneratePaymentLinkSecret">Generate</button>
									<button type="button" class="btn btn-outline-secondary" id="btnCopyPaymentLinkSecret">Copy</button>
								</div>
								<div class="form-text">
									Use <strong>Generate</strong> to create a new 64-char secret, then <strong>Copy</strong> and paste into your .env file.
									Changing this secret invalidates previously generated payment links.
								</div>
							</div>
						</div>
						<div class="mt-4">
							<button type="submit" class="btn btn-primary">Save Settings</button>
						</div>
					</form>
				</div>
			</div>
			<div class="card system-settings-card mt-4">
				<div class="card-header">
					<h5 class="mb-0 admin-section-title">Tariff Plan Management</h5>
				</div>
				<div class="card-body">
					<form method="POST" class="row g-3">
						<input type="hidden" name="action" value="save_tariff_plan">
						<input type="hidden" name="tariff_plan_id" value="<?php echo (int)($editing_tariff['id'] ?? 0); ?>">
						<div class="col-md-4">
							<label class="form-label">Tariff Name</label>
							<input type="text" class="form-control" name="tariff_name" value="<?php echo htmlspecialchars((string)($editing_tariff['name'] ?? '')); ?>" placeholder="e.g. Domestic 2026 Q2" required>
						</div>
						<div class="col-md-2">
							<label class="form-label">Category</label>
							<select name="tariff_category" class="form-select" required>
								<?php $selectedCategory = (string)($editing_tariff['category'] ?? 'all'); ?>
								<?php foreach (['all' => 'All', 'domestic' => 'Domestic', 'commercial' => 'Commercial', 'industrial' => 'Industrial'] as $catValue => $catLabel): ?>
									<option value="<?php echo htmlspecialchars($catValue); ?>" <?php echo $selectedCategory === $catValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($catLabel); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="col-md-2">
							<label class="form-label">Effective From</label>
							<input type="date" class="form-control" name="effective_from" value="<?php echo htmlspecialchars((string)($editing_tariff['effective_from'] ?? date('Y-m-01'))); ?>" required>
						</div>
						<div class="col-md-2">
							<label class="form-label">Effective To</label>
							<input type="date" class="form-control" name="effective_to" value="<?php echo htmlspecialchars((string)($editing_tariff['effective_to'] ?? '')); ?>">
						</div>
						<div class="col-md-2 d-flex align-items-end">
							<div class="form-check form-switch">
								<input class="form-check-input" type="checkbox" name="tariff_is_active" id="tariff_is_active" <?php echo !isset($editing_tariff['is_active']) || !empty($editing_tariff['is_active']) ? 'checked' : ''; ?>>
								<label class="form-check-label" for="tariff_is_active">Active</label>
							</div>
						</div>

						<div class="col-md-4">
							<label class="form-label">Default Rate (KES/m3)</label>
							<input type="number" step="0.0001" min="0" class="form-control" name="base_rate_per_unit" value="<?php echo htmlspecialchars((string)($editing_tariff['base_rate_per_unit'] ?? ($settings['rate_per_unit'] ?? '50.0000'))); ?>" required>
						</div>
						<div class="col-md-4">
							<label class="form-label">Service Charge (KES)</label>
							<input type="number" step="0.01" min="0" class="form-control" name="tariff_service_charge" value="<?php echo htmlspecialchars((string)($editing_tariff['service_charge'] ?? ($settings['service_charge'] ?? '0.00'))); ?>" required>
						</div>
						<div class="col-md-4">
							<label class="form-label">VAT Rate (%)</label>
							<input type="number" step="0.01" min="0" max="100" class="form-control" name="tariff_vat_rate" value="<?php echo htmlspecialchars((string)($editing_tariff['vat_rate'] ?? ($settings['vat_rate'] ?? '0.00'))); ?>" required>
						</div>

						<?php
						$tariffBlocks = $editing_tariff['blocks'] ?? [
							['from_unit' => 0, 'to_unit' => 10, 'rate_per_unit' => $settings['rate_per_unit'] ?? 50],
							['from_unit' => 10, 'to_unit' => 20, 'rate_per_unit' => $settings['rate_per_unit'] ?? 50],
							['from_unit' => 20, 'to_unit' => '', 'rate_per_unit' => $settings['rate_per_unit'] ?? 50],
						];
						if (empty($tariffBlocks)) {
							$tariffBlocks = [['from_unit' => 0, 'to_unit' => '', 'rate_per_unit' => $settings['rate_per_unit'] ?? 50]];
						}
						?>
						<div class="col-12">
							<div class="d-flex justify-content-between align-items-center mb-2">
								<h6 class="mb-0">Tariff Blocks</h6>
								<button type="button" class="btn btn-sm btn-outline-primary" id="addTariffBlockBtn"><i class="bi bi-plus-circle me-1"></i>Add Block</button>
							</div>
							<div id="tariffBlocksContainer">
								<?php foreach ($tariffBlocks as $idx => $block): ?>
									<div class="row g-2 align-items-end tariff-block-row mb-2" data-index="<?php echo (int)$idx; ?>">
										<div class="col-md-3">
											<label class="form-label">From Unit</label>
											<input type="number" step="0.01" min="0" class="form-control" name="block_from_unit[]" value="<?php echo htmlspecialchars((string)($block['from_unit'] ?? '')); ?>">
										</div>
										<div class="col-md-3">
											<label class="form-label">To Unit (blank = open)</label>
											<input type="number" step="0.01" min="0" class="form-control" name="block_to_unit[]" value="<?php echo htmlspecialchars((string)($block['to_unit'] ?? '')); ?>">
										</div>
										<div class="col-md-3">
											<label class="form-label">Rate</label>
											<input type="number" step="0.0001" min="0" class="form-control" name="block_rate[]" value="<?php echo htmlspecialchars((string)($block['rate_per_unit'] ?? '')); ?>">
										</div>
										<div class="col-md-3">
											<button type="button" class="btn btn-outline-danger w-100 js-remove-tariff-block"><i class="bi bi-trash"></i> Remove</button>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
							<template id="tariffBlockTemplate">
								<div class="row g-2 align-items-end tariff-block-row mb-2">
									<div class="col-md-3">
										<label class="form-label">From Unit</label>
										<input type="number" step="0.01" min="0" class="form-control" name="block_from_unit[]" value="0">
									</div>
									<div class="col-md-3">
										<label class="form-label">To Unit (blank = open)</label>
										<input type="number" step="0.01" min="0" class="form-control" name="block_to_unit[]" value="">
									</div>
									<div class="col-md-3">
										<label class="form-label">Rate</label>
										<input type="number" step="0.0001" min="0" class="form-control" name="block_rate[]" value="0">
									</div>
									<div class="col-md-3">
										<button type="button" class="btn btn-outline-danger w-100 js-remove-tariff-block"><i class="bi bi-trash"></i> Remove</button>
									</div>
								</div>
							</template>
						</div>

						<div class="col-12 d-flex gap-2">
							<button type="submit" class="btn btn-primary"><?php echo !empty($editing_tariff) ? 'Update Tariff Plan' : 'Create Tariff Plan'; ?></button>
							<a href="/settings" class="btn btn-outline-secondary">Reset</a>
						</div>
					</form>

					<hr class="my-4">
					<div class="table-responsive">
						<table class="table table-sm align-middle mb-0">
							<thead>
								<tr>
									<th>Name</th>
									<th>Category</th>
									<th>Effective</th>
									<th>VAT %</th>
									<th>Status</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
							<?php if (empty($tariff_plans)): ?>
								<tr><td colspan="6" class="text-center text-muted py-3">No tariff plans found.</td></tr>
							<?php else: ?>
								<?php foreach ($tariff_plans as $plan): ?>
									<tr>
										<td><?php echo htmlspecialchars((string)$plan['name']); ?></td>
										<td><?php echo htmlspecialchars(ucfirst((string)$plan['category'])); ?></td>
										<td><?php echo htmlspecialchars((string)$plan['effective_from']); ?><?php echo !empty($plan['effective_to']) ? ' to ' . htmlspecialchars((string)$plan['effective_to']) : ' onward'; ?></td>
										<td><?php echo number_format((float)$plan['vat_rate'], 2); ?></td>
										<td><?php echo !empty($plan['is_active']) ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
										<td class="d-flex gap-2 flex-wrap">
											<a href="/settings?edit_tariff_id=<?php echo (int)$plan['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
											<form method="POST" class="d-inline">
												<input type="hidden" name="action" value="toggle_tariff_plan">
												<input type="hidden" name="tariff_plan_id" value="<?php echo (int)$plan['id']; ?>">
												<input type="hidden" name="is_active" value="<?php echo !empty($plan['is_active']) ? 0 : 1; ?>">
												<button type="submit" class="btn btn-sm btn-outline-<?php echo !empty($plan['is_active']) ? 'danger' : 'success'; ?>"><?php echo !empty($plan['is_active']) ? 'Deactivate' : 'Activate'; ?></button>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
		<div class="col-lg-4 mt-4 mt-lg-0">
			<div class="card h-100 admin-aside-card system-settings-card system-settings-summary-card">
				<div class="card-header bg-light">
					<h6 class="mb-0 admin-section-title small">At a Glance</h6>
				</div>
				<div class="card-body">
					<p class="mb-2"><strong>Company:</strong> <?php echo htmlspecialchars($settings['company_name'] ?? 'BreMac Consultant Ltd'); ?></p>
					<p class="mb-2"><strong>Support:</strong> <?php echo htmlspecialchars($settings['support_phone'] ?? '+254 700 000 000'); ?> &middot; <?php echo htmlspecialchars($settings['support_email'] ?? 'support@waterbilling.com'); ?></p>
					<p class="mb-2"><strong>Rate per m³:</strong> KES <?php echo number_format((float)($settings['rate_per_unit'] ?? 50), 2); ?></p>
					<p class="mb-2"><strong>Service Charge:</strong> KES <?php echo number_format((float)($settings['service_charge'] ?? 0), 2); ?></p>
					<p class="mb-2"><strong>Locale / Timezone:</strong> <?php echo htmlspecialchars($settings['locale_code'] ?? 'en-KE'); ?> &middot; <?php echo htmlspecialchars($settings['timezone_name'] ?? 'Africa/Nairobi'); ?></p>
					<p class="mb-2"><strong>Registration Fee:</strong> <?php echo htmlspecialchars($settings['currency_code'] ?? 'KES'); ?> <?php echo number_format((float)($settings['registration_fee'] ?? 0), 2); ?></p>
					<p class="mb-0"><strong>GPS Enforcement:</strong> <span class="badge bg-<?php echo !empty($settings['enforce_location_accuracy']) ? 'success' : 'secondary'; ?>"><?php echo !empty($settings['enforce_location_accuracy']) ? 'On' : 'Off'; ?></span></p>
				</div>
			</div>
		</div>
	</div>

	<div class="row mt-4">
		<div class="col-md-12">
			<div class="card system-settings-card system-settings-overview-card">
				<div class="card-header system-settings-overview-header">
					<h5 class="mb-0 admin-section-title">Client Billing Overview</h5>
					<div class="d-flex align-items-center gap-2 system-settings-filter-wrap">
						<label class="form-label mb-0" style="white-space:nowrap;">Filter:</label>
						<select id="billingFilter" class="form-select form-select-sm">
							<option value="all">All</option>
							<option value="paid">Paid</option>
							<option value="unpaid">Unpaid</option>
						</select>
					</div>
				</div>
				<div class="card-body p-0">
					<div class="table-responsive system-settings-table-wrap">
						<table class="table table-striped align-middle mb-0 system-settings-table">
							<thead>
								<tr>
									<th class="billing-col-account">Account</th>
									<th class="billing-col-name">Name</th>
									<th class="billing-col-phone">Phone</th>
									<th class="billing-col-bill">Last Bill</th>
									<th class="billing-col-status">Status</th>
									<th class="billing-col-date">Due Date</th>
									<th class="billing-col-unpaid">Unpaid (KES)</th>
									<th class="billing-col-action">Action</th>
								</tr>
							</thead>
							<tbody>
								<?php if(empty($clients_summary)): ?>
									<tr>
										<td colspan="8" class="text-center text-muted">No clients found.</td>
									</tr>
								<?php else: ?>
									<?php foreach($clients_summary as $client): ?>
										<tr data-unpaid="<?php echo ((float)$client['total_unpaid']) > 0 ? '1' : '0'; ?>">
											<td class="billing-col-account"><?php echo htmlspecialchars($client['account_number']); ?></td>
											<td class="billing-col-name"><?php echo htmlspecialchars($client['full_name']); ?></td>
											<td class="billing-col-phone"><?php echo htmlspecialchars($client['phone_number']); ?></td>
											<td class="billing-col-bill"><?php echo $client['last_amount'] !== null ? number_format($client['last_amount'], 2) : 'N/A'; ?></td>
											<td class="billing-col-status">
												<?php if($client['last_status']): ?>
													<span class="badge bg-<?php echo $client['last_status'] === 'paid' ? 'success' : 'warning'; ?>">
														<?php echo htmlspecialchars(ucfirst($client['last_status'])); ?>
													</span>
												<?php else: ?>
													<span class="text-muted">N/A</span>
												<?php endif; ?>
											</td>
											<td class="billing-col-date">
												<?php echo $client['last_due_date'] ? htmlspecialchars(date('d-m-Y', strtotime($client['last_due_date']))) : 'N/A'; ?>
											</td>
											<td class="billing-col-unpaid"><?php echo number_format($client['total_unpaid'], 2); ?></td>
											<td class="billing-col-action">
												<?php if (!empty($client['last_bill_id']) && (int)$client['last_bill_id'] > 0): ?>
													<?php $clientPayUrl = PaymentLink::generateLink((int)$client['last_bill_id']); ?>
													<div class="d-flex gap-2 flex-wrap">
														<a href="/admin/bill-detail?bill_id=<?php echo (int)$client['last_bill_id']; ?>" class="btn btn-sm btn-outline-dark">
															<i class="bi bi-receipt me-1"></i>Bill
														</a>
														<button type="button"
															class="btn btn-sm btn-outline-primary js-copy-pay-link"
															data-pay-url="<?php echo htmlspecialchars($clientPayUrl, ENT_QUOTES, 'UTF-8'); ?>">
															<i class="bi bi-link-45deg me-1"></i>Copy Link
														</button>
														<a href="<?php echo htmlspecialchars($clientPayUrl); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
															<i class="bi bi-box-arrow-up-right me-1"></i>Open
														</a>
													</div>
												<?php else: ?>
													<span class="text-muted small">—</span>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row mt-4">
		<div class="col-12">
			<div class="card system-settings-card system-settings-data-card">
				<div class="card-header">
					<h5 class="mb-0 admin-section-title">Pending Meter Readings</h5>
				</div>
				<div class="card-body p-0">
					<div class="table-responsive system-settings-table-wrap">
						<table class="table table-striped align-middle mb-0 system-settings-table">
							<thead>
								<tr>
									<th>Account</th>
									<th>Meter</th>
									<th>Current Reading</th>
									<th>Billing Month</th>
									<th>Due Date</th>
									<th>Photo</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php if(empty($pending_readings)): ?>
									<tr>
										<td colspan="7" class="text-center text-muted py-3">No pending readings.</td>
									</tr>
								<?php else: ?>
									<?php foreach($pending_readings as $reading): ?>
										<tr>
											<td><?php echo htmlspecialchars($reading['account_number']); ?></td>
											<td><?php echo htmlspecialchars($reading['meter_number']); ?></td>
											<td><?php echo number_format($reading['current_reading'], 2); ?></td>
											<td><?php echo htmlspecialchars(date('M Y', strtotime($reading['billing_month']))); ?></td>
											<td><?php echo htmlspecialchars(date('d-m-Y', strtotime($reading['due_date']))); ?></td>
											<td>
												<?php if(!empty($reading['photo_path'])): ?>
													<a href="<?php echo htmlspecialchars($reading['photo_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">View</a>
												<?php else: ?>
													<span class="text-muted">N/A</span>
												<?php endif; ?>
											</td>
											<td>
												<div class="reading-action-stack">
												<form method="POST" class="d-inline">
													<input type="hidden" name="action" value="approve_reading">
													<input type="hidden" name="reading_id" value="<?php echo (int)$reading['id']; ?>">
													<button type="submit" class="btn btn-sm btn-success">Approve</button>
												</form>
												<form method="POST" class="d-inline ms-1">
													<input type="hidden" name="action" value="reject_reading">
													<input type="hidden" name="reading_id" value="<?php echo (int)$reading['id']; ?>">
													<button type="submit" class="btn btn-sm btn-danger">Reject</button>
												</form>
												</div>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row mt-4">
		<div class="col-12">
			<div class="card system-settings-card system-settings-data-card">
				<div class="card-header d-flex justify-content-between align-items-center system-settings-header-stack">
					<h5 class="mb-0 admin-section-title">Recent Payments &amp; ETIMS</h5>
					<a href="/admin/payments" class="btn btn-sm btn-outline-secondary system-settings-manual-link"><i class="bi bi-plus-circle me-1"></i>Record Manual / Credit</a>
				</div>
				<div class="card-body">
					<?php
					if ($db) {
						$stmt = $db->query("SELECT p.id, p.bill_id, p.user_id, p.mpesa_receipt, p.phone_number, p.amount, p.status, p.created_at,
							p.etims_status, p.etims_sent_at, p.etims_last_status_code
						FROM payments p ORDER BY p.id DESC LIMIT 10");
						$recent_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
					} else {
						$recent_payments = [];
					}
					?>
					<p class="text-muted small mb-3">Only completed M-Pesa payments can be submitted to eTIMS. When available, a <strong>Send</strong> or <strong>Retry</strong> button will appear in the Action column.</p>
					<?php if (empty($recent_payments)): ?>
						<p class="text-muted mb-0">No payments recorded yet. Once a bill is paid, it will appear here for eTIMS tracking.</p>
					<?php else: ?>
						<div class="table-responsive system-settings-table-wrap">
							<table class="table table-sm align-middle mb-0 system-settings-table">
								<thead>
									<tr>
										<th>ID</th>
										<th>Bill</th>
										<th>Phone</th>
										<th>M-Pesa Receipt</th>
										<th>Amount (KES)</th>
										<th>Date</th>
										<th>Status</th>
										<th>ETIMS</th>
										<th>Action</th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ($recent_payments as $p): ?>
									<tr data-payment-id="<?php echo (int)$p['id']; ?>">
										<td><?php echo (int)$p['id']; ?></td>
										<td>#<?php echo (int)$p['bill_id']; ?></td>
										<td><?php echo htmlspecialchars($p['phone_number'] ?? '—'); ?></td>
										<td><code><?php echo htmlspecialchars($p['mpesa_receipt'] ?? '—'); ?></code></td>
										<td><?php echo number_format((float)$p['amount'], 2); ?></td>
										<td><?php echo htmlspecialchars($p['created_at'] ? date('d-m-Y H:i', strtotime($p['created_at'])) : '—'); ?></td>
										<td><span class="badge bg-<?php echo $p['status'] === 'completed' ? 'success' : ($p['status'] === 'failed' ? 'danger' : 'secondary'); ?>"><?php echo htmlspecialchars(ucfirst($p['status'])); ?></span></td>
										<td>
											<?php if (empty($p['etims_status'])): ?>
												<span class="badge bg-secondary etims-status-badge">Not sent</span>
											<?php elseif ($p['etims_status'] === 'sent'): ?>
												<span class="badge bg-success etims-status-badge">Sent</span>
											<?php else: ?>
												<span class="badge bg-danger etims-status-badge"><?php echo htmlspecialchars(ucfirst($p['etims_status'])); ?></span>
											<?php endif; ?>
										</td>
										<td>
											<?php if ($p['status'] === 'completed' && (empty($p['etims_status']) || $p['etims_status'] !== 'sent')): ?>
												<button type="button" class="btn btn-sm btn-outline-primary js-etims-resend-btn">
													<?php echo empty($p['etims_status']) ? 'Send' : 'Retry'; ?>
												</button>
											<?php else: ?>
												<a href="/admin/bill-detail?bill_id=<?php echo (int)$p['bill_id']; ?>" class="btn btn-sm btn-outline-dark">Bill</a>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>

<script>
(function() {
	const searchInput = document.getElementById('clientSearch');
	const dataList = document.getElementById('clientList');
	const filterSelect = document.getElementById('billingFilter');
	const tableRows = document.querySelectorAll('table tbody tr[data-unpaid]');

	if (searchInput && dataList) {
		let debounceTimer = null;
		searchInput.addEventListener('input', function() {
			clearTimeout(debounceTimer);
			const q = this.value.trim();
			if (q.length < 2) {
				return;
			}
			debounceTimer = setTimeout(() => {
				fetch('/api/admin/search_clients?q=' + encodeURIComponent(q))
					.then(res => res.json())
					.then(data => {
						if (!data || data.status !== 'success') return;
						while (dataList.firstChild) dataList.removeChild(dataList.firstChild);
						data.data.forEach(item => {
							const opt1 = document.createElement('option');
							opt1.value = item.account_number;
							opt1.textContent = item.full_name + ' - ' + item.account_number;
							dataList.appendChild(opt1);
							const opt2 = document.createElement('option');
							opt2.value = item.full_name;
							dataList.appendChild(opt2);
						});
					});
			}, 300);
		});
	}

	if (filterSelect) {
		filterSelect.addEventListener('change', function() {
			const val = this.value;
			tableRows.forEach(row => {
				const unpaid = row.getAttribute('data-unpaid') === '1';
				if (val === 'all') {
					row.style.display = '';
				} else if (val === 'paid') {
					row.style.display = unpaid ? 'none' : '';
				} else if (val === 'unpaid') {
					row.style.display = unpaid ? '' : 'none';
				}
			});
		});
	}

	const secretInput = document.getElementById('paymentLinkSecretValue');
	const btnGenerateSecret = document.getElementById('btnGeneratePaymentLinkSecret');
	const btnCopySecret = document.getElementById('btnCopyPaymentLinkSecret');

	function copySecretText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}

		return new Promise(function(resolve, reject) {
			try {
				const tmp = document.createElement('textarea');
				tmp.value = text;
				tmp.setAttribute('readonly', '');
				tmp.style.position = 'absolute';
				tmp.style.left = '-9999px';
				document.body.appendChild(tmp);
				tmp.select();
				const ok = document.execCommand('copy');
				document.body.removeChild(tmp);
				if (ok) resolve(); else reject(new Error('copy command failed'));
			} catch (err) {
				reject(err);
			}
		});
	}

	function postSecret(payload) {
		return fetch('/api/admin/generate_payment_link_secret', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json',
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: JSON.stringify(payload || {})
		}).then(async function(res) {
			const raw = await res.text();
			let data = null;

			try {
				data = raw ? JSON.parse(raw) : null;
			} catch (e) {
				throw new Error('Server returned HTML instead of JSON. Your session may have expired. Refresh and log in again.');
			}

			if (!res.ok && data && data.message) {
				throw new Error(data.message);
			}

			if (!res.ok) {
				throw new Error('Request failed with HTTP ' + res.status + '.');
			}

			return data;
		});
	}

	if (btnGenerateSecret && secretInput) {
		btnGenerateSecret.addEventListener('click', function() {
			btnGenerateSecret.disabled = true;
			const oldText = btnGenerateSecret.textContent;
			btnGenerateSecret.textContent = 'Generating...';

			postSecret({ save_to_env: false })
				.then(function(data) {
					if (!data || data.status !== 'success' || !data.data || !data.data.secret) {
						throw new Error((data && data.message) ? data.message : 'Could not generate secret');
					}
					secretInput.value = data.data.secret;
					if (window.showToast) {
						showToast('New payment link secret generated. Copy or push it to .env.', 'success');
					}
				})
				.catch(function(err) {
					if (window.showToast) {
						showToast(err.message || 'Failed to generate secret.', 'danger');
					}
				})
				.finally(function() {
					btnGenerateSecret.disabled = false;
					btnGenerateSecret.textContent = oldText;
				});
		});
	}

	if (btnCopySecret && secretInput) {
		btnCopySecret.addEventListener('click', function() {
			const value = String(secretInput.value || '').trim();
			if (!value) {
				if (window.showToast) showToast('Generate a secret first.', 'warning');
				return;
			}

			copySecretText(value)
				.then(function() {
					if (window.showToast) showToast('Secret copied to clipboard.', 'success');
				})
				.catch(function() {
					if (window.showToast) showToast('Unable to copy automatically. Select and copy manually.', 'warning');
				});
		});
	}

	const copyButtons = document.querySelectorAll('.js-copy-pay-link');
	if (copyButtons.length) {
		const copyText = function(text) {
			if (navigator.clipboard && window.isSecureContext) {
				return navigator.clipboard.writeText(text);
			}

			return new Promise(function(resolve, reject) {
				try {
					const tmp = document.createElement('textarea');
					tmp.value = text;
					tmp.setAttribute('readonly', '');
					tmp.style.position = 'absolute';
					tmp.style.left = '-9999px';
					document.body.appendChild(tmp);
					tmp.select();
					const ok = document.execCommand('copy');
					document.body.removeChild(tmp);
					if (ok) {
						resolve();
					} else {
						reject(new Error('copy command failed'));
					}
				} catch (err) {
					reject(err);
				}
			});
		};

		copyButtons.forEach(btn => {
			btn.addEventListener('click', function() {
				const url = this.getAttribute('data-pay-url');
				if (!url) return;

				copyText(url)
					.then(() => {
						if (window.showToast) {
							showToast('Payment link copied to clipboard.', 'success');
						}
					})
					.catch(() => {
						if (window.showToast) {
							showToast('Unable to copy link automatically. Please use Open and copy from the browser address bar.', 'warning');
						}
					});
			});
		});
	}

	// ETIMS resend / send buttons
	const etimsButtons = document.querySelectorAll('.js-etims-resend-btn');
	if (etimsButtons.length) {
		etimsButtons.forEach(btn => {
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				const row = this.closest('tr');
				if (!row) return;
				const paymentId = row.getAttribute('data-payment-id');
				if (!paymentId) return;

				const originalText = this.textContent;
				this.disabled = true;
				this.textContent = 'Sending...';

				fetch('/api/admin/etims_resubmit.php', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json'
					},
					body: JSON.stringify({ payment_id: paymentId })
				})
				.then(res => res.json())
				.then(data => {
					if (!data || data.status !== 'success') {
						var failMsg = (data && data.message) ? data.message : 'Failed to submit to ETIMS';
						if (window.showToast) {
							showToast(failMsg, 'danger');
						} else {
							alert(failMsg);
						}
						return;
					}
					const info = data.data || {};
					const badge = row.querySelector('.etims-status-badge');
					if (badge) {
						badge.classList.remove('bg-secondary', 'bg-success', 'bg-danger');
						if (info.etims_status === 'sent') {
							badge.classList.add('bg-success');
							badge.textContent = 'Sent';
							// Hide button once successfully sent
							btn.style.display = 'none';
						} else if (!info.etims_status) {
							badge.classList.add('bg-secondary');
							badge.textContent = 'Not sent';
						} else {
							badge.classList.add('bg-danger');
							badge.textContent = info.etims_status.charAt(0).toUpperCase() + info.etims_status.slice(1);
						}
					}
				})
				.catch(() => {
					var errMsg = 'Error calling ETIMS resubmit API';
					if (window.showToast) {
						showToast(errMsg, 'danger');
					} else {
						alert(errMsg);
					}
				})
				.finally(() => {
					this.disabled = false;
					this.textContent = originalText;
				});
			});
		});
	}

	const addTariffBlockBtn = document.getElementById('addTariffBlockBtn');
	const tariffBlocksContainer = document.getElementById('tariffBlocksContainer');
	const tariffBlockTemplate = document.getElementById('tariffBlockTemplate');

	function bindTariffBlockRemoveHandlers() {
		if (!tariffBlocksContainer) return;
		tariffBlocksContainer.querySelectorAll('.js-remove-tariff-block').forEach(function(btn) {
			btn.onclick = function() {
				const rows = tariffBlocksContainer.querySelectorAll('.tariff-block-row');
				if (rows.length <= 1) {
					if (window.showToast) {
						showToast('At least one tariff block is required.', 'warning');
					}
					return;
				}
				const row = this.closest('.tariff-block-row');
				if (row) row.remove();
			};
		});
	}

	if (addTariffBlockBtn && tariffBlocksContainer && tariffBlockTemplate) {
		addTariffBlockBtn.addEventListener('click', function() {
			const clone = tariffBlockTemplate.content.cloneNode(true);
			tariffBlocksContainer.appendChild(clone);
			bindTariffBlockRemoveHandlers();
		});
		bindTariffBlockRemoveHandlers();
	}
})();
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
