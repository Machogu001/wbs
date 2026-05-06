<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Accounting.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
if (!$auth->isLoggedIn() || (!$auth->isAdmin() && !$auth->hasPermission('view_accounting'))) {
	header('Location: /login');
	exit;
}

if (empty($_SESSION['accounting_csrf'])) {
	$_SESSION['accounting_csrf'] = bin2hex(random_bytes(32));
}

$accounting = new Accounting($db);
$message = '';
$messageType = 'success';

if (!empty($_SESSION['accounting_flash']) && is_array($_SESSION['accounting_flash'])) {
	$message = (string)($_SESSION['accounting_flash']['message'] ?? '');
	$messageType = (string)($_SESSION['accounting_flash']['type'] ?? 'success');
	unset($_SESSION['accounting_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$token = (string)($_POST['csrf_token'] ?? '');
	if (!hash_equals($_SESSION['accounting_csrf'], $token)) {
		$_SESSION['accounting_flash'] = ['message' => 'Security validation failed. Please refresh and try again.', 'type' => 'danger'];
		header('Location: /admin/accounting');
		exit;
	}
	$action = (string)($_POST['action'] ?? '');
	try {
		if ($action === 'save_account') {
			$accountId     = (int)($_POST['account_id'] ?? 0);
			$code          = trim((string)($_POST['code'] ?? ''));
			$name          = trim((string)($_POST['name'] ?? ''));
			$type          = trim((string)($_POST['account_type'] ?? ''));
			$normalBalance = trim((string)($_POST['normal_balance'] ?? 'debit'));
			$parentId      = (int)($_POST['parent_id'] ?? 0);
			$description   = trim((string)($_POST['description'] ?? ''));
			$isActive      = isset($_POST['is_active']) ? 1 : 0;

			if ($code === '' || $name === '' || $type === '') {
				throw new InvalidArgumentException('Code, name, and type are required.');
			}
			if ($accountId > 0) {
				$accounting->updateAccount($accountId, $name, $type, $normalBalance, $parentId > 0 ? $parentId : null, $description !== '' ? $description : null, $isActive);
				$_SESSION['accounting_flash'] = ['message' => 'Account updated.', 'type' => 'success'];
			} else {
				$accounting->createAccount($code, $name, $type, $normalBalance, $parentId > 0 ? $parentId : null, $description !== '' ? $description : null, 0);
				$_SESSION['accounting_flash'] = ['message' => 'Account created.', 'type' => 'success'];
			}
		} elseif ($action === 'toggle_account') {
			$accountId = (int)($_POST['account_id'] ?? 0);
			$isActive  = (int)($_POST['is_active'] ?? 0);
			if ($accountId <= 0) {
				throw new InvalidArgumentException('Invalid account.');
			}
			$accounting->setAccountStatus($accountId, $isActive ? 1 : 0);
			$_SESSION['accounting_flash'] = ['message' => $isActive ? 'Account activated.' : 'Account deactivated.', 'type' => 'success'];
		} elseif ($action === 'post_entry') {
			$entryDate       = trim((string)($_POST['entry_date'] ?? date('Y-m-d')));
			$memo            = trim((string)($_POST['memo'] ?? ''));
			$debitAccountId  = (int)($_POST['debit_account_id'] ?? 0);
			$creditAccountId = (int)($_POST['credit_account_id'] ?? 0);
			$amount          = (float)($_POST['amount'] ?? 0);
			$referenceType   = trim((string)($_POST['reference_type'] ?? 'manual'));
			$referenceId     = (int)($_POST['reference_id'] ?? 0);

			if ($debitAccountId <= 0 || $creditAccountId <= 0 || $amount <= 0) {
				throw new InvalidArgumentException('Debit account, credit account, and amount are required.');
			}
			$accounting->postJournalEntry($entryDate, $memo !== '' ? $memo : 'Manual journal entry', [
				['account_id' => $debitAccountId,  'debit' => $amount, 'credit' => 0,       'memo' => $memo],
				['account_id' => $creditAccountId, 'debit' => 0,       'credit' => $amount, 'memo' => $memo],
			], $referenceType !== '' ? $referenceType : 'manual', $referenceId > 0 ? $referenceId : null, (int)($_SESSION['user_id'] ?? 0));
			$_SESSION['accounting_flash'] = ['message' => 'Journal entry posted.', 'type' => 'success'];
		} elseif ($action === 'lock_period') {
			$periodKey = trim((string)($_POST['period_key'] ?? ''));
			$note      = trim((string)($_POST['note'] ?? ''));
			$accounting->lockPeriod($periodKey, (int)($_SESSION['user_id'] ?? 0), $note !== '' ? $note : null);
			$_SESSION['accounting_flash'] = ['message' => 'Accounting period ' . htmlspecialchars($periodKey) . ' locked.', 'type' => 'success'];
		} elseif ($action === 'unlock_period') {
			$periodKey = trim((string)($_POST['period_key'] ?? ''));
			$note      = trim((string)($_POST['note'] ?? ''));
			$accounting->unlockPeriod($periodKey, (int)($_SESSION['user_id'] ?? 0), $note !== '' ? $note : null);
			$_SESSION['accounting_flash'] = ['message' => 'Accounting period ' . htmlspecialchars($periodKey) . ' unlocked.', 'type' => 'success'];
		} else {
			throw new InvalidArgumentException('Unsupported action.');
		}
	} catch (Throwable $e) {
		$_SESSION['accounting_flash'] = ['message' => $e->getMessage(), 'type' => 'danger'];
	}
	header('Location: /admin/accounting');
	exit;
}

// GET data
$summary = $accounting->getSummary();
$selectedType = trim((string)($_GET['type'] ?? ''));
$allowedTypes = ['asset', 'liability', 'equity', 'revenue', 'expense', 'cost_of_sales'];
if (!in_array($selectedType, $allowedTypes, true)) {
	$selectedType = '';
}
$accounts = $accounting->getAccounts(false);
if ($selectedType !== '') {
	$accounts = array_values(array_filter($accounts, static fn(array $a): bool => (string)($a['account_type'] ?? '') === $selectedType));
}
$trialBalance = $accounting->getTrialBalance();

$reconFromDate = trim((string)($_GET['recon_from'] ?? date('Y-m-01')));
$reconToDate   = trim((string)($_GET['recon_to']   ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reconFromDate)) { $reconFromDate = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reconToDate))   { $reconToDate   = date('Y-m-d'); }
$reconciliation = $accounting->getReconciliationSummary($reconFromDate, $reconToDate);
$periodLocks = $accounting->getPeriodLocks(24);

$is_admin_page = true;
$page_title = 'Accounting';
include __DIR__ . '/../../templates/header.php';
?>
<?php include __DIR__ . '/../../templates/accounting_styles.php'; ?>
<div class="container-fluid mt-4 admin-shell">
	<div class="pb-banner pb-banner--teal mb-4">
		<div class="pb-bg" aria-hidden="true">
			<div class="pb-grid"></div>
			<div class="pb-blob pb-blob--a"></div>
			<div class="pb-blob pb-blob--b"></div>
			<i class="bi bi-journal-bookmark-fill pb-watermark"></i>
		</div>
		<div class="pb-inner">
			<div class="pb-left">
				<div class="pb-eyebrow-row">
					<span class="pb-eyebrow-chip"><i class="bi bi-journal-bookmark-fill"></i> Finance &amp; Accounting</span>
				</div>
				<h2 class="pb-title">Accounting Management</h2>
				<p class="pb-subtitle">Manage the chart of accounts, post journal entries, lock periods, and track reconciliation. Use the navigation below for financial reports, budgets, transfers, and the general ledger.</p>
			</div>
		</div>
	</div>

	<?php if ($message !== ''): ?>
		<div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show mb-4" role="alert">
			<?php echo htmlspecialchars($message); ?>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>
	<?php endif; ?>

	<?php include __DIR__ . '/../../templates/accounting_subnav.php'; ?>

	<!-- KPI tiles -->
	<div class="row g-3 mb-4">
		<div class="col-md-4">
			<a class="text-decoration-none accounting-card-link" href="/accounting?type=asset#accounts">
				<div class="card admin-kpi-card bg-primary text-white accounting-kpi">
					<div class="card-body"><h5>Assets</h5><h3><?php echo (int)$summary['asset']['count']; ?></h3><small>Balance: <?php echo number_format((float)$summary['asset']['balance'], 2); ?></small></div>
				</div>
			</a>
		</div>
		<div class="col-md-4">
			<a class="text-decoration-none accounting-card-link" href="/accounting?type=revenue#accounts">
				<div class="card admin-kpi-card bg-success text-white accounting-kpi">
					<div class="card-body"><h5>Revenue</h5><h3><?php echo (int)$summary['revenue']['count']; ?></h3><small>Balance: <?php echo number_format((float)$summary['revenue']['balance'], 2); ?></small></div>
				</div>
			</a>
		</div>
		<div class="col-md-4">
			<a class="text-decoration-none accounting-card-link" href="/accounting?type=expense#accounts">
				<div class="card admin-kpi-card bg-warning text-dark accounting-kpi">
					<div class="card-body"><h5>Expenses</h5><h3><?php echo (int)$summary['expense']['count']; ?></h3><small>Balance: <?php echo number_format((float)$summary['expense']['balance'], 2); ?></small></div>
				</div>
			</a>
		</div>
	</div>

	<!-- Chart of Accounts + Post Journal Entry -->
	<div class="row g-3 mb-4">
		<div class="col-lg-5" id="accounts">
			<div class="card admin-table-card h-100 accounting-panel">
				<div class="card-header"><h5 class="mb-0">Chart of Accounts</h5></div>
				<div class="card-body">
					<?php if ($selectedType !== ''): ?>
						<div class="mb-3"><span class="accounting-filter-pill">Showing <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $selectedType))); ?> accounts</span> <a href="/accounting#accounts" class="ms-2 small">Clear filter</a></div>
					<?php endif; ?>
					<form method="post" class="row g-2 mb-3">
						<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['accounting_csrf']); ?>">
						<input type="hidden" name="action" value="save_account">
						<input type="hidden" name="account_id" value="0">
						<div class="col-6"><label class="form-label">Code</label><input type="text" name="code" class="form-control form-control-sm" placeholder="e.g. 6000" required></div>
						<div class="col-6"><label class="form-label">Name</label><input type="text" name="name" class="form-control form-control-sm" placeholder="Account name" required></div>
						<div class="col-6"><label class="form-label">Type</label><select name="account_type" class="form-select form-select-sm" required><option value="asset">Asset</option><option value="liability">Liability</option><option value="equity">Equity</option><option value="revenue">Revenue</option><option value="expense">Expense</option><option value="cost_of_sales">Cost of Sales</option></select></div>
						<div class="col-6"><label class="form-label">Normal Balance</label><select name="normal_balance" class="form-select form-select-sm"><option value="debit">Debit</option><option value="credit">Credit</option></select></div>
						<div class="col-12"><label class="form-label">Description</label><input type="text" name="description" class="form-control form-control-sm" placeholder="Optional"></div>
						<div class="col-12 d-grid"><button type="submit" class="btn btn-primary btn-sm">Create Account</button></div>
					</form>
					<div class="table-responsive">
						<table class="table table-sm align-middle mb-0 accounting-link-table">
							<thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Status</th></tr></thead>
							<tbody>
							<?php foreach ($accounts as $account): ?>
								<?php $accountUrl = '/accounting/ledger?type=' . urlencode((string)$account['account_type']) . '&account_id=' . (int)$account['id'] . '#ledger'; ?>
								<tr class="accounting-row-<?php echo htmlspecialchars((string)$account['account_type']); ?><?php echo !empty($account['is_active']) ? '' : ' accounting-row-inactive'; ?>">
									<td><a href="<?php echo htmlspecialchars($accountUrl); ?>"><strong><?php echo htmlspecialchars($account['code']); ?></strong></a></td>
									<td><a href="<?php echo htmlspecialchars($accountUrl); ?>" class="text-decoration-none"><?php echo htmlspecialchars($account['name']); ?></a></td>
									<td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$account['account_type']))); ?></td>
									<td><?php echo !empty($account['is_active']) ? 'Active' : 'Inactive'; ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
		<div class="col-lg-7">
			<div class="card admin-table-card h-100">
				<div class="card-header"><h5 class="mb-0">Post Journal Entry</h5></div>
				<div class="card-body">
					<form method="post" class="row g-2 mb-4">
						<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['accounting_csrf']); ?>">
						<input type="hidden" name="action" value="post_entry">
						<div class="col-md-3"><label class="form-label">Date</label><input type="date" name="entry_date" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>" required></div>
						<div class="col-md-3"><label class="form-label">Reference Type</label><input type="text" name="reference_type" class="form-control form-control-sm" placeholder="manual"></div>
						<div class="col-md-3"><label class="form-label">Reference ID</label><input type="number" name="reference_id" class="form-control form-control-sm" placeholder="Optional"></div>
						<div class="col-md-3"><label class="form-label">Amount</label><input type="number" step="0.01" min="0" name="amount" class="form-control form-control-sm" required></div>
						<div class="col-md-12"><label class="form-label">Memo</label><input type="text" name="memo" class="form-control form-control-sm" placeholder="Journal memo"></div>
						<div class="col-md-6"><label class="form-label">Debit Account</label><select name="debit_account_id" class="form-select form-select-sm" required><?php foreach ($accounts as $account): ?><option value="<?php echo (int)$account['id']; ?>"><?php echo htmlspecialchars($account['code'] . ' - ' . $account['name']); ?></option><?php endforeach; ?></select></div>
						<div class="col-md-6"><label class="form-label">Credit Account</label><select name="credit_account_id" class="form-select form-select-sm" required><?php foreach ($accounts as $account): ?><option value="<?php echo (int)$account['id']; ?>"><?php echo htmlspecialchars($account['code'] . ' - ' . $account['name']); ?></option><?php endforeach; ?></select></div>
						<div class="col-12 d-grid"><button type="submit" class="btn btn-success btn-sm">Post Entry</button></div>
					</form>
					<h6 class="fw-semibold mb-2 small text-muted text-uppercase">Trial Balance</h6>
					<div class="table-responsive">
						<table class="table table-sm align-middle mb-0">
							<thead><tr><th>Code</th><th>Name</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr></thead>
							<tbody>
							<?php foreach ($trialBalance as $row): ?>
								<tr>
									<td><?php echo htmlspecialchars($row['code']); ?></td>
									<td><?php echo htmlspecialchars($row['name']); ?></td>
									<td class="text-end"><?php echo number_format((float)$row['total_debit'], 2); ?></td>
									<td class="text-end"><?php echo number_format((float)$row['total_credit'], 2); ?></td>
									<td class="text-end"><?php echo number_format((float)$row['balance'], 2); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- Period Close + Reconciliation Snapshot -->
	<div class="row g-3 mb-4">
		<div class="col-lg-5">
			<div class="card admin-table-card h-100 accounting-panel">
				<div class="card-header"><h5 class="mb-0">Period Close Controls</h5></div>
				<div class="card-body">
					<form method="post" class="row g-2 mb-3">
						<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['accounting_csrf']); ?>">
						<div class="col-md-6"><label class="form-label">Period (YYYY-MM)</label><input type="month" name="period_key" class="form-control form-control-sm" value="<?php echo htmlspecialchars(date('Y-m')); ?>" required></div>
						<div class="col-md-6"><label class="form-label">Note</label><input type="text" name="note" class="form-control form-control-sm" placeholder="Optional reason"></div>
						<div class="col-6 d-grid"><button type="submit" name="action" value="lock_period" class="btn btn-danger btn-sm">Lock Period</button></div>
						<div class="col-6 d-grid"><button type="submit" name="action" value="unlock_period" class="btn btn-outline-success btn-sm">Unlock Period</button></div>
					</form>
					<div class="table-responsive">
						<table class="table table-sm align-middle mb-0">
							<thead><tr><th>Period</th><th>Status</th><th>Note</th></tr></thead>
							<tbody>
							<?php if (empty($periodLocks)): ?>
								<tr><td colspan="3" class="text-center text-muted py-3">No period lock records yet.</td></tr>
							<?php else: ?>
								<?php foreach ($periodLocks as $periodLock): ?>
									<tr>
										<td><?php echo htmlspecialchars((string)$periodLock['period_key']); ?></td>
										<td><?php echo ((int)($periodLock['is_locked'] ?? 0) === 1) ? '<span class="badge bg-danger">Locked</span>' : '<span class="badge bg-success">Open</span>'; ?></td>
										<td><?php echo htmlspecialchars((string)($periodLock['note'] ?? '-')); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
		<div class="col-lg-7">
			<div class="card admin-table-card h-100 accounting-panel">
				<div class="card-header"><h5 class="mb-0">Reconciliation Snapshot</h5></div>
				<div class="card-body">
					<form method="get" class="row g-2 mb-3">
						<div class="col-md-4"><label class="form-label">From</label><input type="date" name="recon_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($reconFromDate); ?>" required></div>
						<div class="col-md-4"><label class="form-label">To</label><input type="date" name="recon_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($reconToDate); ?>" required></div>
						<div class="col-md-4 d-grid align-items-end"><button type="submit" class="btn btn-outline-primary btn-sm mt-4">Refresh</button></div>
					</form>
					<div class="row g-2">
						<div class="col-md-6"><div class="accounting-muted-box p-3"><div class="small text-muted">Bills (Period)</div><div class="fw-semibold"><?php echo number_format((float)$reconciliation['billed_total'], 2); ?></div></div></div>
						<div class="col-md-6"><div class="accounting-muted-box p-3"><div class="small text-muted">Bill Journals (Period)</div><div class="fw-semibold"><?php echo number_format((float)$reconciliation['journal_bill_total'], 2); ?></div></div></div>
						<div class="col-md-6"><div class="accounting-muted-box p-3"><div class="small text-muted">Payments (Period)</div><div class="fw-semibold"><?php echo number_format((float)$reconciliation['payments_total'], 2); ?></div></div></div>
						<div class="col-md-6"><div class="accounting-muted-box p-3"><div class="small text-muted">Payment Journals (Period)</div><div class="fw-semibold"><?php echo number_format((float)$reconciliation['journal_payment_total'], 2); ?></div></div></div>
						<div class="col-md-6"><div class="accounting-muted-box p-3"><div class="small text-muted">Billing Delta</div><div class="fw-semibold <?php echo ((float)$reconciliation['billing_to_journal_delta'] === 0.0) ? 'text-success' : 'text-danger'; ?>"><?php echo number_format((float)$reconciliation['billing_to_journal_delta'], 2); ?></div></div></div>
						<div class="col-md-6"><div class="accounting-muted-box p-3"><div class="small text-muted">Payment Delta</div><div class="fw-semibold <?php echo ((float)$reconciliation['payments_to_journal_delta'] === 0.0) ? 'text-success' : 'text-danger'; ?>"><?php echo number_format((float)$reconciliation['payments_to_journal_delta'], 2); ?></div></div></div>
						<div class="col-12"><div class="accounting-muted-box p-3"><div class="small text-muted">Open Accounts Receivable (as of end date)</div><div class="fw-semibold"><?php echo number_format((float)$reconciliation['open_ar_total'], 2); ?></div></div></div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
<?php include __DIR__ . '/../../templates/footer.php'; ?>
