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
$message_type = 'success';

$settingsService = null;
if ($db) {
	$settingsService = new BillingSettings($db);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db && $settingsService) {
	if (isset($_POST['action']) && $_POST['action'] === 'add_reading') {
		$identifier = trim($_POST['account_or_meter']);
		$current_reading = (float)$_POST['current_reading'];
		$billing_month = $_POST['billing_month'];
		$due_date = $_POST['due_date'];

		$userService = new User($db);
		$readingService = new MeterReading($db);
		$billService = new Bill($db);
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
						$previousBalance = 0;
						$totalToPay = $billAmount;
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

						// Also send an email bill notice if user has email
						if (!empty($user['email'])) {
							require_once __DIR__ . '/../../includes/Email.php';
							$email = new Email();
							$email->send(
								$user['email'],
								'New water bill generated',
								$messageText
							);
						}
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
							header("Location: /invoicing");
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


$is_admin_page = true;
$page_title = "Admin - Invoicing";
require_once __DIR__ . '/../../templates/header.php';

if (isset($_SESSION['flash_message'])) {
	$message = $_SESSION['flash_message'];
	$message_type = $_SESSION['flash_type'] ?? 'success';
	unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}
?>

<div class="container mt-4">
	<div class="row">
		<div class="col-md-12">
			<div class="admin-page-header d-flex justify-content-between align-items-center">
				<div>
					<h2 class="mb-1">Invoicing</h2>
					<p class="text-muted mb-0">Record client meter readings and generate invoices.</p>
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
		<div class="col-md-6">
			<div class="card">
				<div class="card-header">
					<h5 class="mb-0 admin-section-title">Record Client Meter Reading (Pending Approval)</h5>
				</div>
				<div class="card-body">
					<form method="POST" enctype="multipart/form-data">
						<input type="hidden" name="action" value="add_reading">
						<div class="mb-3">
							<label class="form-label">Search Client (Name or Account No.)</label>
							<input type="text" id="clientSearch" name="account_or_meter" class="form-control" list="clientList" placeholder="Start typing name or account" required>
							<datalist id="clientList">
								<?php foreach($client_list as $client): ?>
									<option value="<?php echo htmlspecialchars($client['account_number']); ?>">
										<?php echo htmlspecialchars($client['full_name'] . ' - ' . $client['account_number']); ?>
									</option>
									<option value="<?php echo htmlspecialchars($client['full_name']); ?>"></option>
								<?php endforeach; ?>
							</datalist>
							<small class="text-muted">Type to search; select from suggestions.</small>
						</div>
						<div class="mb-3">
							<label class="form-label">Current Reading (m³)</label>
							<input type="number" step="0.01" min="0" name="current_reading" class="form-control" required>
						</div>
						<div class="mb-3">
							<label class="form-label">Billing Month</label>
							<input type="date" name="billing_month" class="form-control" value="<?php echo date('Y-m-01'); ?>" required>
						</div>
						<div class="mb-3">
							<label class="form-label">Due Date</label>
							<input type="date" name="due_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>" required>
						</div>
						<div class="mb-3">
							<label class="form-label">Meter Photo (Optional)</label>
							<input type="file" name="meter_photo" accept="image/png,image/jpeg" class="form-control">
							<small class="text-muted">Optional for admin entries.</small>
						</div>
						<button type="submit" class="btn btn-success">Submit Reading</button>
					</form>
				</div>
			</div>
		</div>
		<div class="col-md-6">
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
									<th>Action</th>
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
