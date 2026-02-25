<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/mpesa_config.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';
require_once __DIR__ . '/../../includes/MeterReading.php';
require_once __DIR__ . '/../../includes/SMS.php';
require_once __DIR__ . '/../../includes/PaymentLink.php';

$database = new Database();
$db = $database->getConnection();

$auth = new Auth($db);
if(!$auth->isLoggedIn() || !$auth->isAdmin()) {
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
		$financial_year_start_month = isset($_POST['financial_year_start_month']) ? (int)$_POST['financial_year_start_month'] : null;
		$vat_rate = isset($_POST['vat_rate']) ? $_POST['vat_rate'] : null;
		$etims_taxation_type_code = isset($_POST['etims_taxation_type_code']) ? $_POST['etims_taxation_type_code'] : null;
		$registration_fee = isset($_POST['registration_fee']) ? $_POST['registration_fee'] : null;

		if ($rate <= 0) {
			$message = "Rate per m³ must be greater than 0.";
			$message_type = "danger";
		} else {
			if ($settingsService->updateSettings($rate, $service, $company_pin, $etims_integration_url, $etims_api_key, $company_name, $support_phone, $support_email, $currency_code, $financial_year_start_month, $vat_rate, $etims_taxation_type_code, $registration_fee)) {
				$_SESSION['flash_message'] = "Billing settings updated successfully.";
				$_SESSION['flash_type'] = "success";
				header("Location: /admin");
				exit;
			} else {
				$message = "Failed to update billing settings.";
				$message_type = "danger";
			}
		}
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
						$paybill = MpesaConfig::SHORTCODE;
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
							header("Location: /admin");
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
				header("Location: /admin");
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
					header("Location: /admin");
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
			header("Location: /admin");
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
if ($db && $settingsService) {
	$settings = $settingsService->getSettings();
	$readingService = new MeterReading($db);
	$pending_readings = $readingService->listPending();
	$billService = new Bill($db);
	$clients_summary = $billService->getUsersBillingSummary();
	$userService = new User($db);
	$client_list = $userService->listAll();
}

$is_admin_page = true;
$page_title = "Admin Dashboard";
require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container mt-4">
	<div class="row">
		<div class="col-md-12">
			<div class="admin-page-header d-flex justify-content-between align-items-center">
				<div>
					<h2 class="mb-1">Admin Dashboard</h2>
					<p class="text-muted mb-0">Manage billing settings and record meter readings.</p>
				</div>
				<button type="button" class="btn btn-sm btn-outline-light border" onclick="if(window.showToast){showToast('Sample admin toast working','info');}">
					Test Toast
				</button>
			</div>
		</div>
	</div>

	<div id="adminToast" style="position:fixed; top:4.5rem; right:1rem; z-index:2000; display:none; min-width:260px;">
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

	<div class="row mt-4">
		<div class="col-lg-8">
			<div class="card">
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
								<label class="form-label">Currency Code</label>
								<input type="text" name="currency_code" class="form-control" value="<?php echo htmlspecialchars($settings['currency_code'] ?? 'KES'); ?>" maxlength="10" placeholder="e.g. KES, USD">
								<div class="form-text">ISO currency code used in reports and invoices.</div>
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
						</div>
						<div class="mt-4">
							<button type="submit" class="btn btn-primary">Save Settings</button>
						</div>
					</form>
				</div>
			</div>
		</div>
		<div class="col-lg-4 mt-4 mt-lg-0">
			<div class="card h-100 admin-aside-card">
				<div class="card-header bg-light">
					<h6 class="mb-0 admin-section-title small">At a Glance</h6>
				</div>
				<div class="card-body">
					<p class="mb-2"><strong>Company:</strong> <?php echo htmlspecialchars($settings['company_name'] ?? 'BreMac Consultant Ltd'); ?></p>
					<p class="mb-2"><strong>Support:</strong> <?php echo htmlspecialchars($settings['support_phone'] ?? '+254 700 000 000'); ?> &middot; <?php echo htmlspecialchars($settings['support_email'] ?? 'support@waterbilling.com'); ?></p>
					<p class="mb-2"><strong>Rate per m³:</strong> KES <?php echo number_format((float)($settings['rate_per_unit'] ?? 50), 2); ?></p>
					<p class="mb-2"><strong>Service Charge:</strong> KES <?php echo number_format((float)($settings['service_charge'] ?? 0), 2); ?></p>
					<p class="mb-0"><strong>Registration Fee:</strong> KES <?php echo number_format((float)($settings['registration_fee'] ?? 0), 2); ?></p>
				</div>
			</div>
		</div>
	</div>

	<div class="row mt-4">
		<div class="col-md-12">
			<div class="card">
				<div class="card-header d-flex justify-content-between align-items-center">
					<h5 class="mb-0 admin-section-title">Client Billing Overview</h5>
					<div class="d-flex align-items-center gap-2">
						<label class="form-label mb-0">Filter:</label>
						<select id="billingFilter" class="form-select form-select-sm">
							<option value="all">All</option>
							<option value="paid">Paid</option>
							<option value="unpaid">Unpaid</option>
						</select>
					</div>
				</div>
				<div class="card-body">
					<div class="table-responsive">
						<table class="table table-striped align-middle">
							<thead>
								<tr>
									<th>Account</th>
									<th>Name</th>
									<th>Phone</th>
									<th>Last Bill</th>
									<th>Last Status</th>
									<th>Due Date</th>
									<th>Total Unpaid (KES)</th>
								</tr>
							</thead>
							<tbody>
								<?php if(empty($clients_summary)): ?>
									<tr>
										<td colspan="7" class="text-center text-muted">No clients found.</td>
									</tr>
								<?php else: ?>
									<?php foreach($clients_summary as $client): ?>
										<tr data-unpaid="<?php echo ((float)$client['total_unpaid']) > 0 ? '1' : '0'; ?>">
											<td><?php echo htmlspecialchars($client['account_number']); ?></td>
											<td><?php echo htmlspecialchars($client['full_name']); ?></td>
											<td><?php echo htmlspecialchars($client['phone_number']); ?></td>
											<td><?php echo $client['last_amount'] !== null ? number_format($client['last_amount'], 2) : 'N/A'; ?></td>
											<td>
												<?php if($client['last_status']): ?>
													<span class="badge bg-<?php echo $client['last_status'] === 'paid' ? 'success' : 'warning'; ?>">
														<?php echo htmlspecialchars(ucfirst($client['last_status'])); ?>
													</span>
												<?php else: ?>
													<span class="text-muted">N/A</span>
												<?php endif; ?>
											</td>
											<td>
												<?php echo $client['last_due_date'] ? htmlspecialchars(date('d-m-Y', strtotime($client['last_due_date']))) : 'N/A'; ?>
											</td>
											<td><?php echo number_format($client['total_unpaid'], 2); ?></td>
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
		<div class="col-md-8">
			<div class="card">
				<div class="card-header">
					<h5 class="mb-0 admin-section-title">Pending Meter Readings</h5>
				</div>
				<div class="card-body">
					<div class="table-responsive">
						<table class="table table-striped align-middle">
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
										<td colspan="7" class="text-center text-muted">No pending readings.</td>
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
		<div class="col-md-4">
			<div class="card h-100">
				<div class="card-header d-flex justify-content-between align-items-center">
					<h5 class="mb-0 admin-section-title">Recent Payments &amp; ETIMS</h5>
					<a href="/admin/payments" class="btn btn-sm btn-outline-secondary">Record Manual / Credit</a>
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
					<p class="text-muted small mb-2">Only completed M-Pesa payments can be submitted to eTIMS. When available, a <strong>Send</strong> or <strong>Retry</strong> button will appear in the Action column.</p>
					<?php if (empty($recent_payments)): ?>
						<p class="text-muted mb-0">No payments recorded yet. Once a bill is paid, it will appear here for eTIMS tracking.</p>
					<?php else: ?>
						<div class="table-responsive">
							<table class="table table-sm align-middle mb-0">
								<thead>
									<tr>
										<th>ID</th>
										<th>Bill</th>
										<th>Amount (KES)</th>
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
										<td><?php echo number_format((float)$p['amount'], 2); ?></td>
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
												<span class="text-muted small">-</span>
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

	<div class="row mt-4">
		<div class="col-md-12">
			<div class="card">
				<div class="card-header">
					<h5 class="mb-0 admin-section-title">Pending Meter Readings</h5>
				</div>
				<div class="card-body">
					<div class="table-responsive">
						<table class="table table-striped align-middle">
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
										<td colspan="7" class="text-center text-muted">No pending readings.</td>
									</tr>
								<?php else: ?>
									<?php foreach($pending_readings as $reading): ?>
										<tr>
											<td><?php echo htmlspecialchars($reading['account_number']); ?></td>
											<td><?php echo htmlspecialchars($reading['meter_number']); ?></td>
											<td><?php echo number_format($reading['current_reading'], 2); ?></td>
											<td><?php echo htmlspecialchars(date('M Y', strtotime($reading['billing_month']))); ?></td>
											<td><?php echo htmlspecialchars($reading['due_date']); ?></td>
											<td>
												<?php if(!empty($reading['photo_path'])): ?>
													<a href="<?php echo htmlspecialchars($reading['photo_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">View</a>
												<?php else: ?>
													<span class="text-muted">N/A</span>
												<?php endif; ?>
											</td>
											<td>
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
})();
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
