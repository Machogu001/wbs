<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/mpesa_config.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/ClientMeter.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';
require_once __DIR__ . '/../../includes/MeterReading.php';
require_once __DIR__ . '/../../includes/SMS.php';
require_once __DIR__ . '/../../includes/PaymentLink.php';
require_once __DIR__ . '/../../includes/ShortUrl.php';
require_once __DIR__ . '/../../includes/ClientWallet.php';

$database = new Database();
$db = $database->getConnection();

$auth = new Auth($db);
if(!$auth->isLoggedIn() || (!$auth->isAdmin() && !$auth->hasPermission('view_invoicing'))) {
	header("Location: /login");
	exit;
}

$message = null;
$message_type = 'success';
$defaultBillingMonth = date('Y-m-01', strtotime('first day of last month'));
$defaultDueDate = date('Y-m-d', strtotime('+3 days'));

$settingsService = null;
if ($db) {
	$settingsService = new BillingSettings($db);
}
$settings = $settingsService ? $settingsService->getSettings() : [];

function resolveReadingClient(User $userService, string $identifier): array {
	$identifier = trim($identifier);
	if ($identifier === '') {
		return ['success' => false, 'message' => 'Client is required.'];
	}

	$user = $userService->getByAccountNumber($identifier);
	if (!$user) {
		$user = $userService->getByMeterNumber($identifier);
	}

	if (!$user) {
		$matches = $userService->searchByNameOrAccount($identifier, 2);
		if (count($matches) === 1) {
			$user = $matches[0];
		} elseif (count($matches) > 1) {
			return ['success' => false, 'message' => 'Multiple clients found. Please use account or meter number.'];
		}
	}

	if (!$user) {
		return ['success' => false, 'message' => 'Account, meter number, or name not found.'];
	}

	return ['success' => true, 'user' => $user];
}

function getMeterPhotoUploadAtIndex(array $files, int $index): ?array {
	if (!isset($files['name']) || !is_array($files['name']) || !array_key_exists($index, $files['name'])) {
		return null;
	}

	return [
		'name' => $files['name'][$index] ?? '',
		'type' => $files['type'][$index] ?? '',
		'tmp_name' => $files['tmp_name'][$index] ?? '',
		'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
		'size' => $files['size'][$index] ?? 0,
	];
}

function uploadReadingPhoto(?array $photo, string $accountNumber): array {
	if (!$photo || ($photo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
		return ['success' => true, 'path' => null];
	}

	if (($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
		return ['success' => false, 'message' => 'Failed to upload meter photo.'];
	}

	$imageInfo = getimagesize($photo['tmp_name']);
	$allowedTypes = ['image/jpeg', 'image/png'];
	if ($imageInfo === false || !in_array($imageInfo['mime'], $allowedTypes, true)) {
		return ['success' => false, 'message' => 'Please upload a valid JPG or PNG image.'];
	}

	$uploadDir = __DIR__ . '/../../uploads/meter_readings';
	if (!is_dir($uploadDir)) {
		mkdir($uploadDir, 0755, true);
	}

	$ext = $imageInfo['mime'] === 'image/png' ? 'png' : 'jpg';
	$filename = 'reading_' . preg_replace('/[^A-Za-z0-9_-]/', '', $accountNumber) . '_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
	$destination = $uploadDir . '/' . $filename;

	if (!move_uploaded_file($photo['tmp_name'], $destination)) {
		return ['success' => false, 'message' => 'Failed to save meter photo.'];
	}

	return ['success' => true, 'path' => '/uploads/meter_readings/' . $filename];
}

function processMeterReadingEntry(array $entry, ?array $photo, User $userService, MeterReading $readingService, Bill $billService, array $settings, int $actorId): array {
	$identifier = trim((string)($entry['account_or_meter'] ?? ''));
	$currentReading = (float)($entry['current_reading'] ?? 0);
	$billingMonth = (string)($entry['billing_month'] ?? '');
	$dueDate = (string)($entry['due_date'] ?? '');

	if ($currentReading <= 0) {
		return ['success' => false, 'message' => 'Current reading must be greater than 0.'];
	}

	$clientResult = resolveReadingClient($userService, $identifier);
	if (!$clientResult['success']) {
		return $clientResult;
	}

	$user = $clientResult['user'];
	$meterNumber = (string)($user['matched_meter_number'] ?? $user['meter_number'] ?? '');
	$photoResult = uploadReadingPhoto($photo, $user['account_number']);
	if (!$photoResult['success']) {
		return $photoResult;
	}

	$billResult = $billService->createBillForUser(
		$user['id'],
		$user['account_number'],
		$currentReading,
		$billingMonth,
		$dueDate,
		$settings['rate_per_unit'],
		$settings['service_charge'],
		'pending',
		$meterNumber
	);

	if (empty($billResult['success'])) {
		return ['success' => false, 'message' => $billResult['message'] ?? 'Failed to create pending bill.'];
	}

	// Auto-apply any existing wallet credit towards the new bill
	try {
		$walletService = new ClientWallet($db);
		$walletBalance = $walletService->getBalance((int)$user['id']);
		if ($walletBalance > 0.01) {
			$billOutstanding = $billResult['amount'];
			$autoApply = round(min($walletBalance, $billOutstanding), 2);
			if ($autoApply > 0) {
				// Insert a completed payment from wallet credit
				$walletRef = 'WALLET-' . date('YmdHis');
				$stmtWP = $db->prepare("INSERT INTO payments
					(bill_id, user_id, phone_number, payment_method, amount, mpesa_receipt, status, transaction_date, received_by_user_id, created_at)
					VALUES (?, ?, ?, 'wallet', ?, ?, 'completed', NOW(), ?, NOW())");
				$stmtWP->execute([
					(int)$billResult['bill_id'],
					(int)$user['id'],
					$user['phone_number'] ?? null,
					$autoApply,
					$walletRef,
					$actorId,
				]);
				$walletService->applyTowardsBill(
					(int)$user['id'],
					(int)$billResult['bill_id'],
					$autoApply,
					'Auto-applied to new bill #' . $billResult['bill_id'],
					(int)$actorId
				);
				// Mark bill paid if credit covers it fully
				if ($autoApply >= $billOutstanding - 0.01) {
					$stmtBU = $db->prepare("UPDATE bills SET status = 'paid' WHERE id = ?");
					$stmtBU->execute([(int)$billResult['bill_id']]);
				}
			}
		}
	} catch (\Throwable $e) {
		error_log('Wallet auto-apply on bill creation failed: ' . $e->getMessage());
	}

	$sms = new SMS();
	$previousReading = $billResult['previous_reading'];
	$currentReadingValue = $billResult['current_reading'];
	$units = $billResult['consumption'];
	$billAmount = $billResult['amount'];
	$previousBalance = 0;
	$totalToPay = $billAmount;
	$billDate = date('d-m-Y');
	$account = $user['account_number'];
	$paybill = MpesaConfig::getShortCode();
	$payUrl = PaymentLink::generateLink((int)$billResult['bill_id']);

	$messageText = Bill::buildBillNotificationMessage(
		$user,
		$billResult,
		$dueDate,
		(float)$previousBalance,
		(float)$totalToPay,
		$paybill,
		$payUrl,
		$billDate,
		$settings['bill_notification_template'] ?? null
	);

	$sms->sendWithFallback($user['phone_number'], $messageText, 'bill_notification');

	if (!empty($user['email'])) {
		require_once __DIR__ . '/../../includes/Email.php';
		$email = new Email();
		$email->queue($user['email'], 'New water bill generated', $messageText, 'bill_notification');
	}

	$readingId = $readingService->createReading(
		$user['id'],
		$user['account_number'],
		$meterNumber,
		$currentReading,
		$billingMonth,
		$dueDate,
		$photoResult['path'],
		$actorId,
		$billResult['bill_id'],
		'approved',
		$actorId
	);

	if (!$readingId) {
		return ['success' => false, 'message' => 'Failed to submit meter reading.'];
	}

	return ['success' => true, 'account_number' => $user['account_number']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db && $settingsService) {
	// CSRF validation
	if (!hash_equals($_SESSION['app_csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
		http_response_code(403);
		die('Invalid CSRF token.');
	}
	if (isset($_POST['action']) && $_POST['action'] === 'add_reading') {
		$identifiers = $_POST['account_or_meter'] ?? [];
		$currentReadings = $_POST['current_reading'] ?? [];
		$billingMonth = trim((string)($_POST['billing_month'] ?? '')) ?: $defaultBillingMonth;
		$dueDate = trim((string)($_POST['due_date'] ?? '')) ?: $defaultDueDate;

		if (!is_array($identifiers)) {
			$identifiers = [$identifiers];
		}
		if (!is_array($currentReadings)) {
			$currentReadings = [$currentReadings];
		}

		$userService = new User($db);
		$readingService = new MeterReading($db);
		$billService = new Bill($db);
		$settings = $settingsService->getSettings();

		$entries = [];
		$maxRows = max(count($identifiers), count($currentReadings));
		for ($i = 0; $i < $maxRows; $i++) {
			$identifier = trim((string)($identifiers[$i] ?? ''));
			$currentReading = trim((string)($currentReadings[$i] ?? ''));
			if ($identifier === '' && $currentReading === '') {
				continue;
			}
			$entries[] = [
				'row_number' => $i + 1,
				'account_or_meter' => $identifier,
				'current_reading' => $currentReading,
				'billing_month' => $billingMonth,
				'due_date' => $dueDate,
				'photo' => getMeterPhotoUploadAtIndex($_FILES['meter_photo'] ?? [], $i),
			];
		}

		if (empty($entries)) {
			$message = 'Add at least one client reading before submitting.';
			$message_type = 'danger';
		} else {
			$successCount = 0;
			$errors = [];
			foreach ($entries as $entry) {
				$result = processMeterReadingEntry($entry, $entry['photo'], $userService, $readingService, $billService, $settings, (int)$_SESSION['user_id']);
				if (!empty($result['success'])) {
					$successCount++;
				} else {
					$errors[] = 'Row ' . $entry['row_number'] . ': ' . ($result['message'] ?? 'Failed to process entry.');
				}
			}

			if ($successCount > 0) {
				$_SESSION['flash_message'] = $successCount === count($entries)
					? 'Submitted ' . $successCount . ' meter reading(s). Bills were created and marked pending payment.'
					: 'Submitted ' . $successCount . ' of ' . count($entries) . ' meter reading(s). ' . implode(' ', array_slice($errors, 0, 3));
				$_SESSION['flash_type'] = $successCount === count($entries) ? 'success' : 'warning';
				header('Location: /invoicing');
				exit;
			}

			$message = implode(' ', $errors);
			$message_type = 'danger';
		}
	} elseif (isset($_POST['action']) && $_POST['action'] === 'import_readings_csv') {
		$importBillingMonth = trim((string)($_POST['import_billing_month'] ?? '')) ?: $defaultBillingMonth;
		$importDueDate = trim((string)($_POST['import_due_date'] ?? '')) ?: $defaultDueDate;

		if (!isset($_FILES['readings_csv']) || ($_FILES['readings_csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			$message = 'Please upload a CSV file to import readings.';
			$message_type = 'danger';
		} else {
			$userService = new User($db);
			$readingService = new MeterReading($db);
			$billService = new Bill($db);
			$settings = $settingsService->getSettings();

			$csvFile = $_FILES['readings_csv']['tmp_name'];
			$handle = fopen($csvFile, 'r');
			if ($handle === false) {
				$message = 'Unable to open the uploaded CSV file.';
				$message_type = 'danger';
			} else {
				$entries = [];
				$rowNumber = 0;
				while (($row = fgetcsv($handle)) !== false) {
					$rowNumber++;
					if ($rowNumber === 1) {
						$firstCell = strtolower(trim((string)($row[0] ?? '')));
						if ($firstCell === 'account_or_meter' || $firstCell === 'account' || $firstCell === 'client') {
							continue;
						}
					}

					$identifier = trim((string)($row[0] ?? ''));
					$currentReading = trim((string)($row[1] ?? ''));
					$billingMonth = trim((string)($row[2] ?? ''));
					$dueDate = trim((string)($row[3] ?? ''));

					if ($identifier === '' && $currentReading === '') {
						continue;
					}

					$entries[] = [
						'row_number' => $rowNumber,
						'account_or_meter' => $identifier,
						'current_reading' => $currentReading,
						'billing_month' => $billingMonth !== '' ? $billingMonth : $importBillingMonth,
						'due_date' => $dueDate !== '' ? $dueDate : $importDueDate,
						'photo' => null,
					];
				}
				fclose($handle);

				if (empty($entries)) {
					$message = 'No valid reading rows were found in the CSV file.';
					$message_type = 'danger';
				} else {
					$successCount = 0;
					$errors = [];
					foreach ($entries as $entry) {
						$result = processMeterReadingEntry($entry, null, $userService, $readingService, $billService, $settings, (int)$_SESSION['user_id']);
						if (!empty($result['success'])) {
							$successCount++;
						} else {
							$errors[] = 'CSV row ' . $entry['row_number'] . ': ' . ($result['message'] ?? 'Failed to process entry.');
						}
					}

					if ($successCount > 0) {
						$_SESSION['flash_message'] = $successCount === count($entries)
							? 'Imported ' . $successCount . ' meter reading(s) from CSV. Bills were created and marked pending payment.'
							: 'Imported ' . $successCount . ' of ' . count($entries) . ' CSV reading(s). ' . implode(' ', array_slice($errors, 0, 3));
						$_SESSION['flash_type'] = $successCount === count($entries) ? 'success' : 'warning';
						header('Location: /invoicing');
						exit;
					}

					$message = implode(' ', $errors);
					$message_type = 'danger';
				}
			}
		}
	}
}

$settings = null;
$clients_summary = [];
$client_list = [];
if ($db && $settingsService) {
	$settings = $settingsService->getSettings();
	$billService = new Bill($db);
	$clients_summary = $billService->getUsersBillingSummary();
	$userService = new User($db);
	$client_list = $userService->listAll();
}

$totalClients = count($clients_summary);
$clientsWithUnpaid = 0;
$paidClients = 0;
$totalUnpaidAmount = 0.0;
foreach ($clients_summary as $clientSummary) {
	$totalUnpaidAmount += (float)($clientSummary['total_unpaid'] ?? 0);
	if ((float)($clientSummary['total_unpaid'] ?? 0) > 0) {
		$clientsWithUnpaid++;
	}
	if (($clientSummary['last_status'] ?? '') === 'paid') {
		$paidClients++;
	}
}


$is_admin_page = true;
$page_title = "Admin - Invoicing";
require_once __DIR__ . '/../../templates/header.php';

if (isset($_SESSION['flash_message'])) {
	$message = $_SESSION['flash_message'];
	$message_type = $_SESSION['flash_type'] ?? 'success';
	unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}
?>

<div class="container mt-4 invoicing-page">
	<div class="row">
		<div class="col-md-12">
			<div class="pb-banner pb-banner--amber mb-4">
				<div class="pb-bg" aria-hidden="true">
					<div class="pb-grid"></div>
					<div class="pb-blob pb-blob--a"></div>
					<div class="pb-blob pb-blob--b"></div>
					<i class="bi bi-receipt-cutoff pb-watermark"></i>
				</div>
				<div class="pb-inner">
					<div class="pb-left">
						<div class="pb-eyebrow-row">
							<span class="pb-eyebrow-chip"><i class="bi bi-receipt-cutoff"></i> Billing Desk</span>
						</div>
						<h2 class="pb-title">Invoicing</h2>
						<p class="pb-subtitle">Record client meter readings, generate invoices, and keep a quick eye on outstanding balances.</p>
					</div>
					<div class="pb-right">
						<div class="pb-kpi-row">
							<div class="pb-kpi">
								<span class="pb-kpi-label">Clients</span>
								<span class="pb-kpi-value"><?php echo number_format($totalClients); ?></span>
							</div>
							<div class="pb-kpi">
								<span class="pb-kpi-label">Unpaid Accounts</span>
								<span class="pb-kpi-value"><?php echo number_format($clientsWithUnpaid); ?></span>
							</div>
							<div class="pb-kpi">
								<span class="pb-kpi-label">Outstanding</span>
								<span class="pb-kpi-value"><?php echo htmlspecialchars($settings['currency_code'] ?? 'KES'); ?> <?php echo number_format($totalUnpaidAmount, 2); ?></span>
							</div>
							<div class="pb-kpi">
								<span class="pb-kpi-label">Paid Last Bill</span>
								<span class="pb-kpi-value"><?php echo number_format($paidClients); ?></span>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<?php if($message): ?>
		<script>
		window.addEventListener('load', function() {
			if (window.showToast) {
				showToast(<?php echo json_encode($message); ?>, <?php echo json_encode($message_type); ?>);
			}
		});
		</script>
	<?php endif; ?>

	<div class="row mt-4">
		<div class="col-12">
			<div class="card invoicing-card invoicing-entry-card">
				<div class="card-header invoicing-card-header">
					<div>
						<h5 class="mb-1 admin-section-title">Record Client Meter Readings</h5>
						<p class="mb-0 text-muted small">Add multiple clients in one batch. Entries submitted here are approved immediately and invoice notices are sent to each client.</p>
					</div>
				</div>
				<div class="card-body invoicing-card-body">
					<div class="invoicing-import-box mb-4">
						<div class="invoicing-import-head">
							<div>
								<h6 class="mb-1">Bulk Import via CSV</h6>
								<p class="mb-0 text-muted small">Upload many readings at once. You can leave billing month and due date empty in the CSV and use defaults below.</p>
							</div>
							<a href="/api/admin/download_meter_reading_template" class="btn btn-outline-secondary btn-sm">
								<i class="bi bi-download me-1"></i>Download Template
							</a>
						</div>
						<form method="POST" enctype="multipart/form-data" class="row g-3 mt-1">
							<input type="hidden" name="action" value="import_readings_csv">
							<div class="col-xl-5 col-lg-6">
								<label class="form-label fw-semibold">CSV File</label>
								<input type="file" name="readings_csv" accept=".csv,text/csv" class="form-control" required>
							</div>
							<div class="col-xl-3 col-lg-3 col-md-6">
								<label class="form-label fw-semibold">Default Billing Month</label>
								<input type="date" name="import_billing_month" class="form-control" value="<?php echo htmlspecialchars($defaultBillingMonth); ?>">
							</div>
							<div class="col-xl-3 col-lg-3 col-md-6">
								<label class="form-label fw-semibold">Default Due Date</label>
								<input type="date" name="import_due_date" class="form-control" value="<?php echo htmlspecialchars($defaultDueDate); ?>">
							</div>
							<div class="col-xl-1 col-lg-12 d-flex align-items-end">
								<button type="submit" class="btn btn-primary w-100">
									<i class="bi bi-upload me-1"></i>Import
								</button>
							</div>
						</form>
					</div>

					<form method="POST" enctype="multipart/form-data">
						<input type="hidden" name="action" value="add_reading">
						<div class="row g-3">
							<div class="col-md-6 col-xl-4">
								<label class="form-label fw-semibold">Billing Month</label>
								<input type="date" name="billing_month" class="form-control" value="<?php echo htmlspecialchars($defaultBillingMonth); ?>" required>
							</div>
							<div class="col-md-6 col-xl-4">
								<label class="form-label fw-semibold">Due Date</label>
								<input type="date" name="due_date" class="form-control" value="<?php echo htmlspecialchars($defaultDueDate); ?>" required>
							</div>
							<div class="col-xl-4 d-flex align-items-end">
								<div class="invoicing-shared-note w-100">
									<span class="invoicing-shared-note-label">Batch settings</span>
									<p class="mb-0 text-muted small">These dates apply to every client reading in this batch.</p>
								</div>
							</div>
							<div class="col-12">
								<div class="invoicing-reading-list" id="readingRows">
									<div class="invoicing-reading-row" data-row-index="0">
										<div class="invoicing-reading-row-head">
											<h6 class="mb-0">Client 1</h6>
											<button type="button" class="btn btn-sm btn-outline-danger js-remove-reading-row d-none">Remove</button>
										</div>
										<div class="row g-3">
											<div class="col-lg-5">
												<label class="form-label fw-semibold">Search Client (Account / Meter / Name)</label>
												<input type="text" name="account_or_meter[]" class="form-control client-search-input js-client-autocomplete" placeholder="Start typing account, meter or name" autocomplete="off" required>
												<small class="text-muted">Type to search; if the client has multiple properties, select the exact meter number.</small>
												<div class="small text-muted mt-1 js-client-selection-summary">Choose an account or meter to confirm the property being billed.</div>
											</div>
											<div class="col-lg-3 col-md-6">
												<label class="form-label fw-semibold">Current Reading (m³)</label>
												<input type="number" step="0.01" min="0" name="current_reading[]" class="form-control" required>
											</div>
											<div class="col-lg-4 col-md-6">
												<label class="form-label fw-semibold">Meter Photo (Optional)</label>
												<input type="file" name="meter_photo[]" accept="image/png,image/jpeg" class="form-control">
												<small class="text-muted">Optional for admin entries.</small>
											</div>
										</div>
									</div>
								</div>
								<div class="invoicing-reading-tools mt-3">
									<button type="button" class="btn btn-outline-primary" id="addReadingRowBtn">
										<i class="bi bi-plus-circle me-1"></i>Add Another Client
									</button>
								</div>
							</div>
						</div>
						<div class="invoicing-form-actions mt-4">
							<button type="submit" class="btn btn-success btn-lg px-4">Submit Readings</button>
						</div>
					</form>
				</div>
			</div>
		</div>
		<div class="col-12 mt-4">
			<div class="card invoicing-card invoicing-overview-card">
				<div class="card-header system-settings-overview-header">
					<div>
						<h5 class="mb-1 admin-section-title">Client Billing Overview</h5>
						<p class="mb-0 text-muted small">Quick reference for latest client bills, payment status, and the available invoice or payment actions.</p>
					</div>
					<div class="d-flex align-items-center gap-2 system-settings-filter-wrap">
						<label class="form-label mb-0" style="white-space:nowrap;">Filter:</label>
						<select id="billingFilter" class="form-select form-select-sm">
							<option value="all">All</option>
							<option value="paid">Paid</option>
							<option value="unpaid">Unpaid</option>
						</select>
					</div>
				</div>
				<div class="card-body invoicing-card-body p-0">
					<div class="table-responsive invoicing-table-wrap">
						<table class="table table-striped align-middle mb-0 invoicing-table">
							<thead>
								<tr>
									<th>Account</th>
									<th>Name</th>
									<th>Phone</th>
									<th>Last Bill</th>
									<th>Status</th>
									<th>Due Date</th>
									<th>Unpaid (KES)</th>
									<th>Actions</th>
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
											<td>
												<?php if (!empty($client['last_bill_id']) && (int)$client['last_bill_id'] > 0): ?>
													<?php $clientPayUrl = PaymentLink::generateLink((int)$client['last_bill_id']); ?>
													<div class="d-flex gap-2 flex-wrap">
														<a href="/invoice?bill_id=<?php echo (int)$client['last_bill_id']; ?>" class="btn btn-sm btn-outline-dark" target="_blank" rel="noopener">
															<i class="bi bi-file-earmark-pdf me-1"></i>Invoice
														</a>
														<button type="button"
															class="btn btn-sm btn-outline-primary js-copy-pay-link"
															data-pay-url="<?php echo htmlspecialchars($clientPayUrl, ENT_QUOTES, 'UTF-8'); ?>">
															<i class="bi bi-link-45deg me-1"></i>Copy Pay Link
														</button>
														<a href="<?php echo htmlspecialchars($clientPayUrl); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
															<i class="bi bi-box-arrow-up-right me-1"></i>Open Pay Page
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
</div>

<script src="/public/js/admin-client-autocomplete.js"></script>
<script>
(function() {
	const filterSelect = document.getElementById('billingFilter');
	const tableRows = document.querySelectorAll('table tbody tr[data-unpaid]');
	const readingRows = document.getElementById('readingRows');
	const addReadingRowBtn = document.getElementById('addReadingRowBtn');
	const clientLookup = new Map();

	function normalizeLookupKey(value) {
		return (value || '').trim().toUpperCase();
	}

	function summarizeMeters(meters) {
		return (meters || []).map(function(meter) {
			return meter.number + (meter.label ? ' (' + meter.label + ')' : '');
		}).join(', ');
	}

	function setLookupItem(item) {
		if (!item) return;
		const accountKey = normalizeLookupKey(item.account_number || '');
		if (accountKey) {
			clientLookup.set(accountKey, { item: item, selectedMeter: null });
		}
		(item.meters || []).forEach(function(meter) {
			const meterKey = normalizeLookupKey(meter.number || '');
			if (meterKey) {
				clientLookup.set(meterKey, { item: item, selectedMeter: meter });
			}
		});
	}

	function updateSelectionSummary(input) {
		const row = input ? input.closest('.invoicing-reading-row') : null;
		if (!row) return;
		const summaryEl = row.querySelector('.js-client-selection-summary');
		if (!summaryEl) return;

		const match = clientLookup.get(normalizeLookupKey(input.value || ''));
		if (!match) {
			summaryEl.textContent = 'Choose an account or meter to confirm the property being billed.';
			return;
		}

		if (match.selectedMeter) {
			summaryEl.textContent = 'Billing meter ' + match.selectedMeter.number + (match.selectedMeter.label ? ' for ' + match.selectedMeter.label : '') + ' under account ' + match.item.account_number + '.';
			return;
		}

		const meters = match.item.meters || [];
		if (meters.length > 1) {
			summaryEl.textContent = 'Account ' + match.item.account_number + ' has ' + meters.length + ' meters: ' + summarizeMeters(meters) + '. Choose a specific meter number for property-level billing.';
			return;
		}

		if (meters.length === 1) {
			summaryEl.textContent = 'Billing primary meter ' + meters[0].number + (meters[0].label ? ' for ' + meters[0].label : '') + ' under account ' + match.item.account_number + '.';
			return;
		}

		summaryEl.textContent = 'Billing account ' + match.item.account_number + '.';
	}

	function refreshReadingRowState() {
		if (!readingRows) return;
		const rows = readingRows.querySelectorAll('.invoicing-reading-row');
		rows.forEach((row, index) => {
			row.setAttribute('data-row-index', index);
			const heading = row.querySelector('.invoicing-reading-row-head h6');
			if (heading) heading.textContent = 'Client ' + (index + 1);
			const removeBtn = row.querySelector('.js-remove-reading-row');
			if (removeBtn) {
				removeBtn.classList.toggle('d-none', rows.length === 1);
			}
		});
	}

	if (readingRows && addReadingRowBtn) {
		addReadingRowBtn.addEventListener('click', function() {
			const firstRow = readingRows.querySelector('.invoicing-reading-row');
			if (!firstRow) return;
			const clone = firstRow.cloneNode(true);
			clone.querySelectorAll('input').forEach(input => {
				if (input.type === 'file') {
					input.value = '';
				} else {
					input.value = '';
				}
			});
			const summary = clone.querySelector('.js-client-selection-summary');
			if (summary) {
				summary.textContent = 'Choose an account or meter to confirm the property being billed.';
			}
			readingRows.appendChild(clone);
			if (window.WbsClientAutocomplete) {
				window.WbsClientAutocomplete.init('.js-client-autocomplete', {
					endpoint: '/api/admin/search_clients',
					minChars: 2,
					debounceMs: 250
				});
			}
			refreshReadingRowState();
		});

		readingRows.addEventListener('click', function(event) {
			const removeBtn = event.target.closest('.js-remove-reading-row');
			if (!removeBtn) return;
			const row = removeBtn.closest('.invoicing-reading-row');
			if (!row) return;
			row.remove();
			refreshReadingRowState();
		});

		refreshReadingRowState();
	}

	document.addEventListener('change', function(event) {
		const searchInput = event.target.closest('.client-search-input');
		if (!searchInput) return;
		updateSelectionSummary(searchInput);
	});

	document.addEventListener('input', function(event) {
		const searchInput = event.target.closest('.client-search-input');
		if (!searchInput) return;
		updateSelectionSummary(searchInput);
	});

	document.addEventListener('wbs:client-results', function(event) {
		const searchInput = event.target.closest('.client-search-input');
		if (!searchInput) return;
		clientLookup.clear();
		(event.detail.items || []).forEach(function(item) {
			setLookupItem(item);
		});
		updateSelectionSummary(searchInput);
	});

	document.addEventListener('wbs:client-selected', function(event) {
		const searchInput = event.target.closest('.client-search-input');
		if (!searchInput) return;
		setLookupItem(event.detail.item);
		updateSelectionSummary(searchInput);
	});

	if (window.WbsClientAutocomplete) {
		window.WbsClientAutocomplete.init('.js-client-autocomplete', {
			endpoint: '/api/admin/search_clients',
			minChars: 2,
			debounceMs: 250
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
})();
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
