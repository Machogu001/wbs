<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';
require_once __DIR__ . '/../../includes/CreditNote.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';
require_once __DIR__ . '/../../includes/Etims.php';
require_once __DIR__ . '/../../includes/Accounting.php';
require_once __DIR__ . '/../../includes/InstallmentPlan.php';
require_once __DIR__ . '/../../includes/Payment.php';
require_once __DIR__ . '/../../includes/FinanceApproval.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
if (!$auth->isLoggedIn() || (!$auth->isAdmin() && !$auth->hasPermission('view_payments'))) {
	header('Location: /login');
	exit;
}

$userService = new User($db);
$billService = new Bill($db);
$creditService = new CreditNote($db);
$paymentService = new Payment($db);
$financeApproval = new FinanceApproval($db);
$settingsService = new BillingSettings($db);
$settings = $settingsService->getSettings();

$message = null;
$message_type = 'success';
$currentUser = null;
$userBills = [];
$userPayments = [];
$paymentAdjustments = [];
$client_list = $userService->listAll();

// Handle account lookup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_account') {
	// CSRF validation
	if (!hash_equals($_SESSION['app_csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
		http_response_code(403);
		die('Invalid CSRF token.');
	}
	$account = trim($_POST['account_number'] ?? '');
	if ($account === '') {
		$message = 'Please enter an account number.';
		$message_type = 'danger';
	} else {
		$currentUser = $userService->getByAccountNumber($account);
		if (!$currentUser) {
			$message = 'Account not found.';
			$message_type = 'danger';
		} else {
			$userBills = $billService->getBillsByUser($currentUser['id']);
			$userPayments = $paymentService->getCompletedPaymentsByUserId((int)$currentUser['id']);
			$paymentAdjustments = $paymentService->getAdjustmentsByUserId((int)$currentUser['id']);
		}
	}
}

// Handle manual payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual_payment') {
	// CSRF validation
	if (!hash_equals($_SESSION['app_csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
		http_response_code(403);
		die('Invalid CSRF token.');
	}
	$account = trim($_POST['account_number'] ?? '');
	$billId = (int)($_POST['bill_id'] ?? 0);
	$txnCode = trim($_POST['transaction_code'] ?? '');
	$amount = (float)($_POST['amount'] ?? 0);
	$paidDate = trim($_POST['paid_date'] ?? '');
	$paidTime = trim($_POST['paid_time'] ?? '');
	$phone = trim($_POST['phone_number'] ?? '');

	$currentUser = $account !== '' ? $userService->getByAccountNumber($account) : null;
	if (!$currentUser) {
		$message = 'Account not found.';
		$message_type = 'danger';
	} elseif ($billId <= 0 || $txnCode === '' || $amount <= 0 || $paidDate === '') {
		$message = 'Please fill in all required fields (bill, transaction code, amount, date).';
		$message_type = 'danger';
	} else {
		$billRow = $billService->getById($billId, $currentUser['id']);
		if (!$billRow) {
			$message = 'Selected bill not found for this account.';
			$message_type = 'danger';
		} elseif (!in_array($billRow['status'], ['pending','overdue'], true)) {
			$message = 'Only pending or overdue bills can be receipted manually.';
			$message_type = 'danger';
		} else {
			$billAmount = (float)$billRow['amount'];
			if ($amount > $billAmount + 0.01) {
				$message = 'Amount paid cannot exceed the bill amount (' . number_format($billAmount, 2) . ').';
				$message_type = 'danger';
			} else {
				// Build transaction datetime
				$paidDateTime = $paidDate;
				if ($paidTime !== '') {
					$paidDateTime .= ' ' . $paidTime;
				}
				$paidDateTime = date('Y-m-d H:i:s', strtotime($paidDateTime));

				// Insert payment directly
				try {
					$stmt = $db->prepare("INSERT INTO payments (bill_id, user_id, phone_number, amount, mpesa_receipt, status, transaction_date, created_at) VALUES (:bill_id, :user_id, :phone, :amount, :receipt, 'completed', :tx_date, :created_at)");
					$stmt->bindParam(':bill_id', $billId, PDO::PARAM_INT);
					$stmt->bindParam(':user_id', $currentUser['id'], PDO::PARAM_INT);
					$stmt->bindParam(':phone', $phone);
					$stmt->bindParam(':amount', $amount);
					$stmt->bindParam(':receipt', $txnCode);
					$stmt->bindParam(':tx_date', $paidDateTime);
					$now = date('Y-m-d H:i:s');
					$stmt->bindParam(':created_at', $now);
					$stmt->execute();
					$paymentId = (int)$db->lastInsertId();

					try {
						$accounting = new Accounting($db);
						$paymentRow = [
							'id' => $paymentId,
							'amount' => $amount,
							'transaction_date' => $paidDateTime,
							'bill_id' => $billId,
						];
						$accounting->postPaymentReceived($paymentId, $paymentRow, $billRow, 'Manual payment received', (int)($_SESSION['user_id'] ?? 0));
					} catch (Throwable $e) {
						error_log('Manual payment accounting posting failed for payment #' . $paymentId . ': ' . $e->getMessage());
					}

					try {
						$planner = new InstallmentPlan($db);
						$planner->allocatePayment($paymentId);
					} catch (Throwable $e) {
						error_log('Manual payment installment allocation failed for payment #' . $paymentId . ': ' . $e->getMessage());
					}

					// Set bill paid when cumulative completed payments settle the bill.
					$stmtPaid = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE bill_id = :bill_id AND status = 'completed'");
					$stmtPaid->bindParam(':bill_id', $billId, PDO::PARAM_INT);
					$stmtPaid->execute();
					$totalPaid = (float)$stmtPaid->fetchColumn();
					if ($totalPaid + 0.01 >= $billAmount) {
						$billService->updateStatus($billId, 'paid');
					}

					// Log manual payment entry
					try {
						$logger = new ActivityLog($db);
						$logger->log(
							$_SESSION['user_id'] ?? null,
							'record_manual_payment',
							'payment',
							$paymentId,
							'Recorded manual payment for bill #' . $billId,
							array(
								'bill_id' => $billId,
								'amount' => $amount,
								'tx_code' => $txnCode,
								'paid_date' => $paidDateTime
							)
						);
					} catch (Exception $e) {
						// Ignore logging errors
					}

					// Submit to ETIMS if configured
					try {
						$etims = new Etims($db);
						if ($etims->isConfigured()) {
							// Reload payment row for ETIMS payload
							$stmtP = $db->prepare('SELECT * FROM payments WHERE id = :id LIMIT 1');
							$stmtP->bindParam(':id', $paymentId, PDO::PARAM_INT);
							$stmtP->execute();
							$paymentRow = $stmtP->fetch(PDO::FETCH_ASSOC) ?: null;
							if ($paymentRow) {
								$etims->submitSale($paymentRow, $billRow, $currentUser);
							}
						}
					} catch (Exception $e) {
						// Log but do not block manual receipt
						error_log('Manual ETIMS error: ' . $e->getMessage());
					}

					$message = 'Manual payment recorded successfully.';
					$message_type = 'success';
					// Refresh bills
					$userBills = $billService->getBillsByUser($currentUser['id']);
				} catch (Exception $e) {
					$message = 'Failed to record manual payment.';
					$message_type = 'danger';
				}
			}
		}
	}
}

// Handle credit note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'credit_note') {
	// CSRF validation
	if (!hash_equals($_SESSION['app_csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
		http_response_code(403);
		die('Invalid CSRF token.');
	}
	$account = trim($_POST['account_number'] ?? '');
	$billId = (int)($_POST['bill_id'] ?? 0);
	$type = $_POST['credit_type'] ?? 'full';
	$units = (float)($_POST['units'] ?? 0);
	$note = trim($_POST['note'] ?? '');

	$currentUser = $account !== '' ? $userService->getByAccountNumber($account) : null;
	if (!$currentUser) {
		$message = 'Account not found.';
		$message_type = 'danger';
	} elseif ($billId <= 0) {
		$message = 'Please select a bill to credit.';
		$message_type = 'danger';
	} else {
		$billRow = $billService->getById($billId, $currentUser['id']);
		if (!$billRow) {
			$message = 'Selected bill not found for this account.';
			$message_type = 'danger';
		} elseif (!in_array($billRow['status'], ['pending','overdue'], true)) {
			$message = 'Only pending or overdue bills can be adjusted by credit note.';
			$message_type = 'danger';
		} else {
			$prev = (float)$billRow['previous_reading'];
			$curr = (float)$billRow['current_reading'];
			$consumption = (float)$billRow['consumption'];
			$rate = (float)$billRow['rate_per_unit'];
			$serviceCharge = (float)$billRow['service_charge'];

			if ($type === 'full') {
				$unitsCredited = $consumption;
				$newConsumption = 0.0;
				$newCurrent = $prev;
				$newService = 0.0;
				$newAmount = 0.0;
			} else {
				if ($units <= 0 || $units > $consumption) {
					$message = 'Units to credit must be between 0 and current consumption.';
					$message_type = 'danger';
					goto after_credit;
				}
				$unitsCredited = $units;
				$newConsumption = max(0.0, $consumption - $unitsCredited);
				$newCurrent = $prev + $newConsumption;
				$newService = $serviceCharge;
				$newAmount = ($newConsumption * $rate) + $newService;
			}

			// Amount credited is old amount - new amount
			$oldAmount = (float)$billRow['amount'];
			$amountCredited = max(0.0, $oldAmount - $newAmount);

			try {
				// Update bill
				$stmtU = $db->prepare('UPDATE bills SET previous_reading = :prev, current_reading = :curr, consumption = :consumption, service_charge = :service_charge, amount = :amount, status = :status WHERE id = :id');
				$status = ($type === 'full') ? 'cancelled' : $billRow['status'];
				$stmtU->bindParam(':prev', $prev);
				$stmtU->bindParam(':curr', $newCurrent);
				$stmtU->bindParam(':consumption', $newConsumption);
				$stmtU->bindParam(':service_charge', $newService);
				$stmtU->bindParam(':amount', $newAmount);
				$stmtU->bindParam(':status', $status);
				$stmtU->bindParam(':id', $billId, PDO::PARAM_INT);
				$stmtU->execute();

				// Record credit note
				$creditService->create($billId, $currentUser['id'], $unitsCredited, $amountCredited, $type, $_SESSION['user_id'], $note);

				$message = 'Credit note applied successfully.';
				$message_type = 'success';
				// Refresh bills
				$userBills = $billService->getBillsByUser($currentUser['id']);
			} catch (Exception $e) {
				$message = 'Failed to apply credit note.';
				$message_type = 'danger';
			}
		}
	}
}
after_credit:

// Handle refund / chargeback request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'payment_adjustment_request') {
	// CSRF validation
	if (!hash_equals($_SESSION['app_csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
		http_response_code(403);
		die('Invalid CSRF token.');
	}
	$account = trim($_POST['account_number'] ?? '');
	$paymentId = (int)($_POST['payment_id'] ?? 0);
	$adjustmentType = trim((string)($_POST['adjustment_type'] ?? 'refund'));
	$amount = (float)($_POST['amount'] ?? 0);
	$reason = trim((string)($_POST['reason'] ?? ''));

	$currentUser = $account !== '' ? $userService->getByAccountNumber($account) : null;
	if (!$currentUser) {
		$message = 'Account not found.';
		$message_type = 'danger';
	} else {
		$paymentRow = $paymentService->getById($paymentId);
		if (!$paymentRow || (int)($paymentRow['user_id'] ?? 0) !== (int)$currentUser['id']) {
			$message = 'Selected payment does not belong to this account.';
			$message_type = 'danger';
		} else {
			try {
				$financeApproval->createPaymentAdjustmentRequest(
					$paymentId,
					$adjustmentType,
					$amount,
					(int)($_SESSION['user_id'] ?? 0),
					$reason
				);

				try {
					$logger = new ActivityLog($db);
					$logger->log(
						$_SESSION['user_id'] ?? null,
						'create_payment_adjustment_request',
						'payment',
						$paymentId,
						ucfirst($adjustmentType) . ' request created for payment #' . $paymentId,
						[
							'payment_id' => $paymentId,
							'adjustment_type' => $adjustmentType,
							'amount' => $amount,
							'reason' => $reason,
						]
					);
				} catch (Exception $e) {
					// Ignore logging errors
				}

				$message = ucfirst($adjustmentType) . ' request submitted for approval.';
				$message_type = 'success';
			} catch (Throwable $e) {
				$message = $e->getMessage();
				$message_type = 'danger';
			}
		}
	}
}

// If we already have a current user from search or actions, reload bills when not set
if ($currentUser && empty($userBills)) {
	$userBills = $billService->getBillsByUser($currentUser['id']);
}

if ($currentUser && empty($userPayments)) {
	$userPayments = $paymentService->getCompletedPaymentsByUserId((int)$currentUser['id']);
}

if ($currentUser && empty($paymentAdjustments)) {
	$paymentAdjustments = $paymentService->getAdjustmentsByUserId((int)$currentUser['id']);
}

// Only show unpaid (pending/overdue) bills in the UI lists
if (!empty($userBills)) {
	$userBills = array_values(array_filter($userBills, function ($b) {
		return isset($b['status']) && in_array($b['status'], ['pending', 'overdue'], true);
	}));
}

$currency = $settings['currency_code'] ?? 'KES';

$page_title = 'Admin - Payments';
$is_admin_page = true;

include __DIR__ . '/../../templates/header.php';
?>
<div class="container-fluid py-3 admin-shell admin-payments-page">
	<div class="pb-banner pb-banner--emerald mb-4">
		<div class="pb-bg" aria-hidden="true">
			<div class="pb-grid"></div>
			<div class="pb-blob pb-blob--a"></div>
			<div class="pb-blob pb-blob--b"></div>
			<i class="bi bi-wallet2 pb-watermark"></i>
		</div>
		<div class="pb-inner">
			<div class="pb-left">
				<div class="pb-eyebrow-row">
					<span class="pb-eyebrow-chip"><i class="bi bi-wallet2"></i> Revenue Operations</span>
				</div>
				<h2 class="pb-title">Payments &amp; Credit Notes</h2>
				<p class="pb-subtitle">Record manual receipts and apply bill adjustments with audit-ready controls.</p>
			</div>
			<div class="pb-right">
				<div class="pb-btn-row">
					<span class="pb-btn" style="cursor:default; background:rgba(52,211,153,0.15); border-color:rgba(52,211,153,0.35); color:#6ee7b7;"><i class="bi bi-shield-check"></i> Audit trail enabled</span>
					<a href="/reports?report_scope=payments" class="pb-btn pb-btn--accent"><i class="bi bi-graph-up"></i> Payment Reports</a>
				</div>
			</div>
		</div>
	</div>

	<?php if (!empty($message)): ?>
		<div class="alert alert-<?php echo htmlspecialchars($message_type); ?> mt-2"><?php echo htmlspecialchars($message); ?></div>
	<?php endif; ?>

	<div class="row mt-3">
		<div class="col-md-4">
			<div class="card mb-3">
				<div class="card-header admin-section-title">Account Lookup</div>
				<div class="card-body">
					<form method="POST">
						<input type="hidden" name="action" value="search_account">
						<div class="mb-3">
							<label class="form-label">Account / Meter / Name</label>
							<input type="text" name="account_number" id="account-search-input" class="form-control" list="clientList" placeholder="Start typing account, meter or name" value="<?php echo htmlspecialchars($_POST['account_number'] ?? ($currentUser['account_number'] ?? '')); ?>" required>
							<datalist id="clientList">
								<?php foreach ($client_list as $client): ?>
									<option value="<?php echo htmlspecialchars($client['account_number']); ?>">
										<?php echo htmlspecialchars($client['full_name'] . ' - ' . $client['account_number'] . ($client['meter_number'] ? ' (MTR: ' . $client['meter_number'] . ')' : '')); ?>
									</option>
									<?php if (!empty($client['full_name'])): ?>
										<option value="<?php echo htmlspecialchars($client['full_name']); ?>"></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</datalist>
							<small class="text-muted">Type to search; select from suggestions.</small>
						</div>
						<button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i> Load Account</button>
					</form>
					<?php if ($currentUser): ?>
						<hr>
						<p class="mb-1"><strong><?php echo htmlspecialchars($currentUser['full_name']); ?></strong></p>
						<p class="mb-0 text-muted">Meter: <?php echo htmlspecialchars($currentUser['meter_number'] ?? ''); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="col-md-8">
			<div class="card mb-3">
				<div class="card-header admin-section-title">Manual Payment Receipt</div>
				<div class="card-body">
					<?php if (!$currentUser): ?>
						<p class="text-muted mb-0">Search for an account first to record a manual payment.</p>
					<?php else: ?>
						<form method="POST" class="row g-3">
							<input type="hidden" name="action" value="manual_payment">
							<input type="hidden" name="account_number" value="<?php echo htmlspecialchars($currentUser['account_number']); ?>">
							<div class="col-md-6">
								<label class="form-label">Bill / Invoice</label>
								<select name="bill_id" class="form-select" required>
									<option value="">Select bill</option>
									<?php foreach ($userBills as $b): ?>
										<option value="<?php echo (int)$b['id']; ?>">
											#<?php echo (int)$b['id']; ?> - <?php echo htmlspecialchars(date('M Y', strtotime($b['billing_month']))); ?> - <?php echo htmlspecialchars($currency); ?> <?php echo number_format((float)$b['amount'], 2); ?> (<?php echo htmlspecialchars(ucfirst($b['status'])); ?>)
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-6">
								<label class="form-label">Transaction Code</label>
								<input type="text" name="transaction_code" class="form-control" required>
							</div>
							<div class="col-md-4">
								<label class="form-label">Amount Paid (<?php echo htmlspecialchars($currency); ?>)</label>
								<input type="number" step="0.01" name="amount" class="form-control" required>
							</div>
							<div class="col-md-4">
								<label class="form-label">Paid Date</label>
								<input type="date" name="paid_date" class="form-control" required>
							</div>
							<div class="col-md-4">
								<label class="form-label">Paid Time</label>
								<input type="time" name="paid_time" class="form-control">
							</div>
							<div class="col-md-6">
								<label class="form-label">Payer Phone (optional)</label>
								<input type="text" name="phone_number" class="form-control" value="<?php echo htmlspecialchars($currentUser['phone_number'] ?? ''); ?>">
							</div>
							<div class="col-md-6 d-flex align-items-end">
								<button type="submit" class="btn btn-success w-100"><i class="bi bi-receipt-cutoff me-1"></i> Record Manual Payment</button>
							</div>
						</form>
						<p class="text-muted small mt-2 mb-0">Note: Only full payments are supported here. For adjustments, use a credit note.</p>
					<?php endif; ?>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-header admin-section-title">Credit Note Adjustment</div>
				<div class="card-body">
					<?php if (!$currentUser): ?>
						<p class="text-muted mb-0">Search for an account first to apply a credit note.</p>
					<?php else: ?>
						<form method="POST" class="row g-3">
							<input type="hidden" name="action" value="credit_note">
							<input type="hidden" name="account_number" value="<?php echo htmlspecialchars($currentUser['account_number']); ?>">
							<div class="col-md-6">
								<label class="form-label">Bill / Invoice</label>
								<select name="bill_id" class="form-select" required>
									<option value="">Select bill</option>
									<?php foreach ($userBills as $b): ?>
										<option value="<?php echo (int)$b['id']; ?>">
											#<?php echo (int)$b['id']; ?> - <?php echo htmlspecialchars(date('M Y', strtotime($b['billing_month']))); ?> - <?php echo htmlspecialchars($currency); ?> <?php echo number_format((float)$b['amount'], 2); ?> (<?php echo htmlspecialchars(ucfirst($b['status'])); ?>)
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-6">
								<label class="form-label">Credit Type</label>
								<select name="credit_type" class="form-select">
									<option value="full">Full invoice</option>
									<option value="partial">Partial (some units)</option>
								</select>
							</div>
							<div class="col-md-4">
								<label class="form-label">Units to credit (for partial)</label>
								<input type="number" step="0.01" name="units" class="form-control">
							</div>
							<div class="col-md-8">
								<label class="form-label">Note (optional)</label>
								<input type="text" name="note" class="form-control">
							</div>
							<div class="col-12 d-flex justify-content-end">
								<button type="submit" class="btn btn-outline-warning" data-confirm-message="Apply this credit note to the selected invoice?"><i class="bi bi-journal-minus me-1"></i> Apply Credit Note</button>
							</div>
						</form>
						<p class="text-muted small mt-2 mb-0">Full credit will cancel the invoice. Partial credit reduces billed units and amount while keeping the bill open.</p>
					<?php endif; ?>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-header admin-section-title">Refund / Chargeback Request</div>
				<div class="card-body">
					<?php if (!$currentUser): ?>
						<p class="text-muted mb-0">Search for an account first to raise a refund or chargeback request.</p>
					<?php elseif (empty($userPayments)): ?>
						<p class="text-muted mb-0">This account has no completed payments available for adjustment.</p>
					<?php else: ?>
						<form method="POST" class="row g-3">
							<input type="hidden" name="action" value="payment_adjustment_request">
							<input type="hidden" name="account_number" value="<?php echo htmlspecialchars($currentUser['account_number']); ?>">
							<div class="col-md-6">
								<label class="form-label">Completed Payment</label>
								<select name="payment_id" class="form-select" required>
									<option value="">Select payment</option>
									<?php foreach ($userPayments as $paymentRow): ?>
										<option value="<?php echo (int)$paymentRow['id']; ?>">
											#<?php echo (int)$paymentRow['id']; ?> - <?php echo htmlspecialchars($paymentRow['mpesa_receipt'] ?: 'Manual payment'); ?> - <?php echo htmlspecialchars($currency); ?> <?php echo number_format((float)$paymentRow['amount'], 2); ?>
											<?php if (!empty($paymentRow['available_adjustment_amount'])): ?>
												(available <?php echo number_format((float)$paymentRow['available_adjustment_amount'], 2); ?>)
											<?php endif; ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-3">
								<label class="form-label">Request Type</label>
								<select name="adjustment_type" class="form-select">
									<option value="refund">Refund</option>
									<option value="chargeback">Chargeback</option>
								</select>
							</div>
							<div class="col-md-3">
								<label class="form-label">Amount (<?php echo htmlspecialchars($currency); ?>)</label>
								<input type="number" step="0.01" min="0.01" name="amount" class="form-control" required>
							</div>
							<div class="col-12">
								<label class="form-label">Reason</label>
								<input type="text" name="reason" class="form-control" placeholder="Explain why this payment needs reversal or refund" required>
							</div>
							<div class="col-12 d-flex justify-content-end">
								<button type="submit" class="btn btn-outline-danger" data-confirm-message="Submit this payment adjustment request for approval?"><i class="bi bi-arrow-counterclockwise me-1"></i> Submit Request</button>
							</div>
						</form>
						<p class="text-muted small mt-2 mb-0">Approved requests restore receivables and post accounting entries through the finance approvals workflow.</p>
					<?php endif; ?>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-header admin-section-title">Open Bills</div>
				<div class="card-body p-0">
					<?php if (!$currentUser || empty($userBills)): ?>
						<p class="text-muted mb-0 p-3">No open bills to display for this account.</p>
					<?php else: ?>
						<div class="table-responsive">
							<table class="table table-sm align-middle mb-0">
								<thead>
									<tr>
										<th>ID</th>
										<th>Billing Month</th>
										<th class="text-end">Amount (<?php echo htmlspecialchars($currency); ?>)</th>
										<th>Status</th>
										<th>Actions</th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ($userBills as $b): ?>
									<tr>
										<td>#<?php echo (int)$b['id']; ?></td>
										<td><?php echo htmlspecialchars(date('M Y', strtotime((string)$b['billing_month']))); ?></td>
										<td class="text-end"><?php echo number_format((float)$b['amount'], 2); ?></td>
										<td><?php echo htmlspecialchars(ucfirst((string)$b['status'])); ?></td>
										<td><a href="/admin/bill-detail?bill_id=<?php echo (int)$b['id']; ?>" class="btn btn-sm btn-outline-dark">View Detail</a></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-header admin-section-title">Completed Payments</div>
				<div class="card-body p-0">
					<?php if (!$currentUser || empty($userPayments)): ?>
						<p class="text-muted mb-0 p-3">No completed payments to display for this account.</p>
					<?php else: ?>
						<div class="table-responsive">
							<table class="table table-sm align-middle mb-0">
								<thead>
									<tr>
										<th>Payment</th>
										<th>Date</th>
										<th class="text-end">Amount</th>
										<th class="text-end">Available</th>
										<th>Status</th>
										<th>Bill</th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ($userPayments as $paymentRow): ?>
									<tr>
										<td>#<?php echo (int)$paymentRow['id']; ?> <?php echo htmlspecialchars($paymentRow['mpesa_receipt'] ?: 'Manual'); ?></td>
										<td><?php echo htmlspecialchars(date('d M Y', strtotime((string)($paymentRow['transaction_date'] ?? $paymentRow['created_at'] ?? 'now')))); ?></td>
										<td class="text-end"><?php echo number_format((float)$paymentRow['amount'], 2); ?></td>
										<td class="text-end"><?php echo number_format((float)($paymentRow['available_adjustment_amount'] ?? 0), 2); ?></td>
										<td><?php echo htmlspecialchars(ucfirst((string)$paymentRow['status'])); ?></td>
										<td>
											<?php if (!empty($paymentRow['bill_id'])): ?>
												<a href="/admin/bill-detail?bill_id=<?php echo (int)$paymentRow['bill_id']; ?>" class="btn btn-sm btn-outline-dark">Bill #<?php echo (int)$paymentRow['bill_id']; ?></a>
											<?php else: ?>
												<span class="text-muted small">None</span>
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

			<div class="card mb-3">
				<div class="card-header admin-section-title">Adjustment Requests</div>
				<div class="card-body p-0">
					<?php if (!$currentUser || empty($paymentAdjustments)): ?>
						<p class="text-muted mb-0 p-3">No refund or chargeback requests recorded for this account.</p>
					<?php else: ?>
						<div class="table-responsive">
							<table class="table table-sm align-middle mb-0">
								<thead>
									<tr>
										<th>Type</th>
										<th>Payment</th>
										<th class="text-end">Amount</th>
										<th>Status</th>
										<th>Reason</th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ($paymentAdjustments as $adjustmentRow): ?>
									<tr>
										<td><?php echo htmlspecialchars(ucfirst((string)$adjustmentRow['adjustment_type'])); ?></td>
										<td>#<?php echo (int)$adjustmentRow['payment_id']; ?> <?php echo htmlspecialchars($adjustmentRow['mpesa_receipt'] ?: 'Manual'); ?></td>
										<td class="text-end"><?php echo number_format((float)$adjustmentRow['amount'], 2); ?></td>
										<td><?php echo htmlspecialchars(ucfirst((string)$adjustmentRow['status'])); ?></td>
										<td><?php echo htmlspecialchars((string)($adjustmentRow['reason'] ?? '')); ?></td>
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
<?php include __DIR__ . '/../../templates/footer.php'; ?>
