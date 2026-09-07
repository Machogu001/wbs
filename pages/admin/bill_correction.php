<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/BillCorrection.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if (!$auth->isLoggedIn() || (!$auth->isAdmin() && !$auth->hasPermission('correct_bills'))) {
	header('Location: /login');
	exit;
}

$userService = new User($db);
$correctionService = new BillCorrection($db);
$settingsService = new BillingSettings($db);
$settings = $settingsService->getSettings();
$currency = (string)($settings['currency_code'] ?? 'KES');

if (empty($_SESSION['bill_correction_csrf'])) {
	$_SESSION['bill_correction_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = 'success';
$currentUser = null;
$userBills = [];
$selectedBill = null;

$resolveClient = static function (User $userService, string $identifier): ?array {
	$identifier = trim($identifier);
	if ($identifier === '') {
		return null;
	}

	$user = $userService->getByAccountNumber($identifier);
	if (!$user) {
		$user = $userService->getByMeterNumber($identifier);
	}
	if (!$user) {
		$matches = $userService->searchByNameOrAccount($identifier, 2);
		if (count($matches) === 1) {
			$user = $matches[0];
		}
	}
	return $user ?: null;
};

$identifier = trim((string)($_GET['q'] ?? $_POST['account_or_meter'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_correction') {
	if (!hash_equals($_SESSION['bill_correction_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
		$message = 'Security validation failed. Please refresh and try again.';
		$messageType = 'danger';
	} else {
		$billId = (int)($_POST['bill_id'] ?? 0);
		$newReading = (float)($_POST['new_reading'] ?? 0);
		$reason = trim((string)($_POST['reason'] ?? ''));

		try {
			$result = $correctionService->correctReading($billId, $newReading, $reason, (int)($_SESSION['user_id'] ?? 0));
			$message = 'Bill #' . $result['bill_id'] . ' corrected: ' . htmlspecialchars($currency) . ' '
				. number_format($result['old_amount'], 2) . ' -> ' . number_format($result['new_amount'], 2) . '.';
			if ($result['overpayment_credited'] > 0.01) {
				$message .= ' Overpayment of ' . htmlspecialchars($currency) . ' ' . number_format($result['overpayment_credited'], 2)
					. ' was credited to the client wallet for automatic use on the next bill.';
			}
			$messageType = 'success';
		} catch (Throwable $e) {
			$message = $e->getMessage();
			$messageType = 'danger';
		}

		$identifier = trim((string)($_POST['account_or_meter'] ?? ''));
	}
}

if ($identifier !== '') {
	$currentUser = $resolveClient($userService, $identifier);
	if (!$currentUser) {
		if ($message === '') {
			$message = 'Account, meter number, or name not found. If using a name, enter enough to identify one client.';
			$messageType = 'danger';
		}
	} else {
		$stmt = $db->prepare("SELECT id, billing_month, current_reading, amount, status, due_date, rate_per_unit
			FROM bills
			WHERE user_id = :user_id AND rate_per_unit > 0
			ORDER BY billing_month DESC, id DESC");
		$stmt->bindValue(':user_id', (int)$currentUser['id'], PDO::PARAM_INT);
		$stmt->execute();
		$userBills = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	}
}

$selectedBillId = (int)($_GET['bill_id'] ?? 0);
$history = [];
if ($selectedBillId > 0) {
	$selectedBill = $correctionService->getCorrectableBill($selectedBillId);
	if ($selectedBill) {
		$history = $correctionService->getHistoryForBill($selectedBillId);
	}
}

$page_title = 'Bill Reading Correction';
$is_admin_page = true;
include __DIR__ . '/../../templates/header.php';
?>
<div class="container-fluid mt-4 admin-shell">
	<div class="pb-banner pb-banner--cobalt mb-4">
		<div class="pb-inner">
			<div class="pb-left">
				<h2 class="pb-title">Bill Reading Correction</h2>
				<p class="pb-subtitle">Fix a wrongly entered meter reading. The bill, ledgers, and any resulting overpayment credit are updated together.</p>
			</div>
		</div>
	</div>

	<?php if ($message !== ''): ?>
		<div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
	<?php endif; ?>

	<div class="card mb-4">
		<div class="card-header admin-section-title">Find Client</div>
		<div class="card-body">
			<form method="GET" class="row g-3">
				<div class="col-md-8">
					<label class="form-label">Account / Meter / Name</label>
					<input type="text" name="q" class="form-control" placeholder="Start typing account, meter or name" value="<?php echo htmlspecialchars($identifier); ?>" required>
				</div>
				<div class="col-md-4 d-flex align-items-end">
					<button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i> Search</button>
				</div>
			</form>
		</div>
	</div>

	<?php if ($currentUser): ?>
	<div class="card mb-4">
		<div class="card-header admin-section-title">Bills for <?php echo htmlspecialchars($currentUser['full_name']); ?> (<?php echo htmlspecialchars($currentUser['account_number']); ?>)</div>
		<div class="card-body p-0">
			<?php if (empty($userBills)): ?>
				<p class="text-muted mb-0 p-3">No reading-based bills found for this account.</p>
			<?php else: ?>
				<div class="table-responsive">
					<table class="table table-sm align-middle mb-0">
						<thead>
							<tr>
								<th>ID</th>
								<th>Billing Month</th>
								<th class="text-end">Current Reading (m³)</th>
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
								<td class="text-end"><?php echo number_format((float)$b['current_reading'], 2); ?></td>
								<td class="text-end"><?php echo number_format((float)$b['amount'], 2); ?></td>
								<td><?php echo htmlspecialchars(ucfirst((string)$b['status'])); ?></td>
								<td>
									<a href="/admin/bill-correction?q=<?php echo urlencode($identifier); ?>&bill_id=<?php echo (int)$b['id']; ?>" class="btn btn-sm btn-outline-primary">Correct Reading</a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>

	<?php if ($selectedBillId > 0): ?>
	<div class="card mb-4">
		<div class="card-header admin-section-title">Correct Bill #<?php echo $selectedBillId; ?></div>
		<div class="card-body">
			<?php if (!$selectedBill): ?>
				<p class="text-danger mb-0">This bill cannot be corrected here (not found, or has no linked meter reading).</p>
			<?php else: ?>
				<div class="row mb-3">
					<div class="col-md-3"><div class="small text-muted">Previous Reading</div><div class="fw-semibold"><?php echo number_format((float)$selectedBill['previous_reading'], 2); ?> m³</div></div>
					<div class="col-md-3"><div class="small text-muted">Current Reading (on file)</div><div class="fw-semibold"><?php echo number_format((float)$selectedBill['current_reading'], 2); ?> m³</div></div>
					<div class="col-md-3"><div class="small text-muted">Current Amount</div><div class="fw-semibold"><?php echo htmlspecialchars($currency); ?> <?php echo number_format((float)$selectedBill['amount'], 2); ?></div></div>
					<div class="col-md-3"><div class="small text-muted">Total Paid</div><div class="fw-semibold"><?php echo htmlspecialchars($currency); ?> <?php echo number_format((float)$selectedBill['total_paid'], 2); ?></div></div>
				</div>
				<form method="POST" class="row g-3" data-confirm-message="Apply this reading correction? This updates the bill, ledgers, and client wallet immediately.">
					<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['bill_correction_csrf']); ?>">
					<input type="hidden" name="action" value="apply_correction">
					<input type="hidden" name="bill_id" value="<?php echo $selectedBillId; ?>">
					<input type="hidden" name="account_or_meter" value="<?php echo htmlspecialchars($identifier); ?>">
					<div class="col-md-4">
						<label class="form-label">Corrected Current Reading (m³)</label>
						<input type="number" step="0.01" min="0" name="new_reading" class="form-control" required>
					</div>
					<div class="col-md-8">
						<label class="form-label">Reason for correction</label>
						<input type="text" name="reason" class="form-control" placeholder="e.g. Digit entered wrongly during meter reading" required>
					</div>
					<div class="col-12">
						<button type="submit" class="btn btn-warning"><i class="bi bi-pencil-square me-1"></i> Apply Correction</button>
					</div>
				</form>

				<?php if (!empty($history)): ?>
					<hr>
					<h6 class="mb-2">Correction History</h6>
					<div class="table-responsive">
						<table class="table table-sm mb-0">
							<thead>
								<tr>
									<th>Date</th>
									<th>Reading</th>
									<th class="text-end">Old Amount</th>
									<th class="text-end">New Amount</th>
									<th class="text-end">Wallet Credit</th>
									<th>By</th>
									<th>Reason</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($history as $h): ?>
								<tr>
									<td><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime((string)$h['created_at']))); ?></td>
									<td><?php echo number_format((float)$h['old_current_reading'], 2); ?> &rarr; <?php echo number_format((float)$h['new_current_reading'], 2); ?></td>
									<td class="text-end"><?php echo number_format((float)$h['old_amount'], 2); ?></td>
									<td class="text-end"><?php echo number_format((float)$h['new_amount'], 2); ?></td>
									<td class="text-end"><?php echo number_format((float)$h['overpayment_credited'], 2); ?></td>
									<td><?php echo htmlspecialchars($h['corrected_by_name'] ?? '-'); ?></td>
									<td><?php echo htmlspecialchars((string)($h['reason'] ?? '')); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>
</div>
<?php include __DIR__ . '/../../templates/footer.php'; ?>
