<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Accounting.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
if (!$auth->isLoggedIn() || !$auth->hasRole(['admin', 'finance'])) {
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
		$message = 'Security validation failed. Please refresh and try again.';
		$messageType = 'danger';
	} else {
		$action = (string)($_POST['action'] ?? '');
		try {
			if ($action === 'save_account') {
				$accountId = (int)($_POST['account_id'] ?? 0);
				$code = trim((string)($_POST['code'] ?? ''));
				$name = trim((string)($_POST['name'] ?? ''));
				$type = trim((string)($_POST['account_type'] ?? ''));
				$normalBalance = trim((string)($_POST['normal_balance'] ?? 'debit'));
				$parentId = (int)($_POST['parent_id'] ?? 0);
				$description = trim((string)($_POST['description'] ?? ''));
				$isActive = isset($_POST['is_active']) ? 1 : 0;

				if ($code === '' || $name === '' || $type === '') {
					throw new InvalidArgumentException('Code, name, and type are required.');
				}

				if ($accountId > 0) {
					$accounting->updateAccount($accountId, $name, $type, $normalBalance, $parentId > 0 ? $parentId : null, $description !== '' ? $description : null, $isActive);
					$message = 'Account updated.';
				} else {
					$accounting->createAccount($code, $name, $type, $normalBalance, $parentId > 0 ? $parentId : null, $description !== '' ? $description : null, 0);
					$message = 'Account created.';
				}
			} elseif ($action === 'toggle_account') {
				$accountId = (int)($_POST['account_id'] ?? 0);
				$isActive = (int)($_POST['is_active'] ?? 0);
				if ($accountId <= 0) {
					throw new InvalidArgumentException('Invalid account.');
				}
				$accounting->setAccountStatus($accountId, $isActive ? 1 : 0);
				$message = $isActive ? 'Account activated.' : 'Account deactivated.';
			} elseif ($action === 'post_entry') {
				$entryDate = trim((string)($_POST['entry_date'] ?? date('Y-m-d')));
				$memo = trim((string)($_POST['memo'] ?? ''));
				$debitAccountId = (int)($_POST['debit_account_id'] ?? 0);
				$creditAccountId = (int)($_POST['credit_account_id'] ?? 0);
				$amount = (float)($_POST['amount'] ?? 0);
				$referenceType = trim((string)($_POST['reference_type'] ?? 'manual'));
				$referenceId = (int)($_POST['reference_id'] ?? 0);

				if ($debitAccountId <= 0 || $creditAccountId <= 0 || $amount <= 0) {
					throw new InvalidArgumentException('Debit account, credit account, and amount are required.');
				}

				$accounting->postJournalEntry($entryDate, $memo !== '' ? $memo : 'Manual journal entry', [
					['account_id' => $debitAccountId, 'debit' => $amount, 'credit' => 0, 'memo' => $memo],
					['account_id' => $creditAccountId, 'debit' => 0, 'credit' => $amount, 'memo' => $memo],
				], $referenceType !== '' ? $referenceType : 'manual', $referenceId > 0 ? $referenceId : null, (int)($_SESSION['user_id'] ?? 0));
				$message = 'Journal entry posted.';
			} elseif ($action === 'lock_period') {
				$periodKey = trim((string)($_POST['period_key'] ?? ''));
				$note = trim((string)($_POST['note'] ?? ''));
				$accounting->lockPeriod($periodKey, (int)($_SESSION['user_id'] ?? 0), $note !== '' ? $note : null);
				$message = 'Accounting period ' . $periodKey . ' locked.';
			} elseif ($action === 'unlock_period') {
				$periodKey = trim((string)($_POST['period_key'] ?? ''));
				$note = trim((string)($_POST['note'] ?? ''));
				$accounting->unlockPeriod($periodKey, (int)($_SESSION['user_id'] ?? 0), $note !== '' ? $note : null);
				$message = 'Accounting period ' . $periodKey . ' unlocked.';
			} elseif ($action === 'reverse_entry') {
				$entryId = (int)($_POST['entry_id'] ?? 0);
				$reversalDate = trim((string)($_POST['reversal_date'] ?? date('Y-m-d')));
				$memo = trim((string)($_POST['memo'] ?? ''));
				if ($entryId <= 0) {
					throw new InvalidArgumentException('Invalid journal entry for reversal.');
				}

				$reversalId = $accounting->reverseJournalEntry($entryId, $reversalDate, $memo !== '' ? $memo : null, (int)($_SESSION['user_id'] ?? 0));
				$message = 'Journal entry reversed (Reversal ID: ' . $reversalId . ').';
			} else {
				throw new InvalidArgumentException('Unsupported action.');
			}
			$messageType = 'success';
		} catch (Throwable $e) {
			$message = $e->getMessage();
			$messageType = 'danger';
		}
	}

	$_SESSION['accounting_flash'] = [
		'message' => $message,
		'type' => $messageType,
	];

	header('Location: /admin/accounting');
	exit;
}

$summary = $accounting->getSummary();
$selectedType = trim((string)($_GET['type'] ?? ''));
$selectedAccountId = (int)($_GET['account_id'] ?? 0);
$selectedEntryId = (int)($_GET['entry_id'] ?? 0);
$allowedTypes = ['asset', 'liability', 'equity', 'revenue', 'expense', 'cost_of_sales'];
if (!in_array($selectedType, $allowedTypes, true)) {
	$selectedType = '';
}

$accounts = $accounting->getAccounts(false);
if ($selectedType !== '') {
	$accounts = array_values(array_filter($accounts, static function (array $account) use ($selectedType): bool {
		return (string)($account['account_type'] ?? '') === $selectedType;
	}));
}

$selectedAccount = null;
if ($selectedAccountId > 0) {
	foreach ($accounts as $account) {
		if ((int)$account['id'] === $selectedAccountId) {
			$selectedAccount = $account;
			break;
		}
	}
}

$accountLedger = [];
if ($selectedAccount) {
	$accountLedger = $accounting->getLedgerByAccount((int)$selectedAccount['id'], null, null, 50);
}

$selectedEntry = null;
if ($selectedEntryId > 0) {
	$selectedEntry = $accounting->getJournalEntryById($selectedEntryId);
}

$selectedAccountTypeClass = 'accounting-box-type-asset';
$selectedBalanceClass = 'accounting-box-balance-debit';
$selectedStatusClass = 'accounting-box-status-inactive';
if ($selectedAccount) {
	$accountType = (string)($selectedAccount['account_type'] ?? 'asset');
	$selectedAccountTypeClass = 'accounting-box-type-' . preg_replace('/[^a-z_]/', '', $accountType);
	if (!in_array($selectedAccountTypeClass, ['accounting-box-type-asset', 'accounting-box-type-liability', 'accounting-box-type-equity', 'accounting-box-type-revenue', 'accounting-box-type-expense', 'accounting-box-type-cost_of_sales'], true)) {
		$selectedAccountTypeClass = 'accounting-box-type-asset';
	}
	$selectedBalanceClass = ((string)($selectedAccount['normal_balance'] ?? 'debit')) === 'credit' ? 'accounting-box-balance-credit' : 'accounting-box-balance-debit';
	$selectedStatusClass = !empty($selectedAccount['is_active']) ? 'accounting-box-status-active' : 'accounting-box-status-inactive';
}

$trialBalance = $accounting->getTrialBalance();
$reconFromDate = trim((string)($_GET['recon_from'] ?? date('Y-m-01')));
$reconToDate = trim((string)($_GET['recon_to'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reconFromDate)) {
	$reconFromDate = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reconToDate)) {
	$reconToDate = date('Y-m-d');
}
$reconciliation = $accounting->getReconciliationSummary($reconFromDate, $reconToDate);
$periodLocks = $accounting->getPeriodLocks(24);
$journalEntries = $accounting->getJournalEntries(20);
$journalEntryModalData = [];
foreach ($journalEntries as $entry) {
	$journalEntryModalData[] = [
		'id' => (int)$entry['id'],
		'entry_no' => (string)$entry['entry_no'],
		'entry_date' => (string)$entry['entry_date'],
		'memo' => (string)($entry['memo'] ?? ''),
		'reference_label' => (string)(($entry['reference_type'] ?? '-') . (($entry['reference_id'] ?? '') !== '' && $entry['reference_id'] !== null ? ' #' . (int)$entry['reference_id'] : '')),
		'lines' => array_map(static function (array $line): array {
			return [
				'code' => (string)$line['code'],
				'name' => (string)$line['name'],
				'line_memo' => (string)($line['line_memo'] ?? ''),
				'debit' => number_format((float)$line['debit'], 2, '.', ''),
				'credit' => number_format((float)$line['credit'], 2, '.', ''),
			];
		}, $entry['lines'] ?? []),
	];
}

$is_admin_page = true;
$page_title = 'Accounting';
include __DIR__ . '/../../templates/header.php';
?>
<style>
	.accounting-card-link {
		display: block;
		transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
		border-radius: 1rem;
	}

	.accounting-card-link:hover {
		transform: translateY(-2px);
		box-shadow: 0 0.75rem 1.75rem rgba(15, 23, 42, 0.12);
	}

	.accounting-card-link:focus-visible {
		outline: 3px solid rgba(59, 130, 246, 0.45);
		outline-offset: 3px;
	}

	.accounting-panel {
		border: 1px solid rgba(15, 23, 42, 0.08);
		box-shadow: 0 0.5rem 1.25rem rgba(15, 23, 42, 0.04);
		border-radius: 1rem;
		overflow: hidden;
	}

	.accounting-panel .card-header {
		background: linear-gradient(135deg, rgba(248, 250, 252, 1), rgba(241, 245, 249, 1));
		border-bottom: 1px solid rgba(15, 23, 42, 0.08);
	}

	.accounting-kpi {
		min-height: 110px;
		border: 0;
		border-radius: 1rem;
		box-shadow: 0 0.45rem 1.2rem rgba(15, 23, 42, 0.08);
	}

	.accounting-kpi h5 {
		font-size: 0.82rem;
		text-transform: uppercase;
		letter-spacing: 0.08em;
		opacity: 0.92;
	}

	.accounting-kpi h3 {
		font-size: 2rem;
		font-weight: 700;
		margin-bottom: 0.1rem;
	}

	.accounting-kpi small {
		opacity: 0.88;
	}

	.accounting-filter-pill {
		display: inline-flex;
		align-items: center;
		gap: 0.4rem;
		padding: 0.3rem 0.75rem;
		border-radius: 999px;
		background: #e2e8f0;
		color: #0f172a;
		font-size: 0.85rem;
		font-weight: 600;
	}

	.accounting-link-table a {
		color: inherit;
	}

	.accounting-link-table a:hover {
		text-decoration: underline;
	}

	.accounting-row-asset {
		background: rgba(219, 234, 254, 0.45);
	}

	.accounting-row-liability {
		background: rgba(254, 243, 199, 0.45);
	}

	.accounting-row-equity {
		background: rgba(237, 233, 254, 0.45);
	}

	.accounting-row-revenue {
		background: rgba(220, 252, 231, 0.45);
	}

	.accounting-row-expense {
		background: rgba(254, 226, 226, 0.45);
	}

	.accounting-row-cost_of_sales {
		background: rgba(226, 232, 240, 0.45);
	}

	.accounting-row-inactive {
		opacity: 0.72;
	}

	.accounting-entry-bill {
		background: rgba(219, 234, 254, 0.42);
	}

	.accounting-entry-payment {
		background: rgba(220, 252, 231, 0.42);
	}

	.accounting-entry-manual {
		background: rgba(241, 245, 249, 0.55);
	}

	.accounting-entry-other {
		background: rgba(254, 243, 199, 0.35);
	}

	.accounting-modal-bill .modal-header {
		background: linear-gradient(135deg, #1d4ed8, #2563eb) !important;
	}

	.accounting-modal-payment .modal-header {
		background: linear-gradient(135deg, #15803d, #16a34a) !important;
	}

	.accounting-modal-manual .modal-header {
		background: linear-gradient(135deg, #334155, #475569) !important;
	}

	.accounting-modal-other .modal-header {
		background: linear-gradient(135deg, #b45309, #d97706) !important;
	}

	.accounting-entry-line-bill {
		background: rgba(219, 234, 254, 0.35);
		border-color: rgba(59, 130, 246, 0.18);
	}

	.accounting-entry-line-payment {
		background: rgba(220, 252, 231, 0.35);
		border-color: rgba(34, 197, 94, 0.18);
	}

	.accounting-entry-line-manual {
		background: rgba(241, 245, 249, 0.7);
		border-color: rgba(100, 116, 139, 0.18);
	}

	.accounting-entry-line-other {
		background: rgba(254, 243, 199, 0.35);
		border-color: rgba(245, 158, 11, 0.18);
	}

	.accounting-muted-box {
		border: 1px solid rgba(15, 23, 42, 0.08);
		border-radius: 0.85rem;
		background: #f8fafc;
	}

	.accounting-box-code {
		background: linear-gradient(135deg, #dbeafe, #bfdbfe);
		border-color: #93c5fd;
	}

	.accounting-box-type-asset {
		background: linear-gradient(135deg, #dbeafe, #bfdbfe);
		border-color: #93c5fd;
	}

	.accounting-box-type-liability {
		background: linear-gradient(135deg, #fef3c7, #fde68a);
		border-color: #fbbf24;
	}

	.accounting-box-type-equity {
		background: linear-gradient(135deg, #ede9fe, #ddd6fe);
		border-color: #a78bfa;
	}

	.accounting-box-type-revenue {
		background: linear-gradient(135deg, #dcfce7, #bbf7d0);
		border-color: #86efac;
	}

	.accounting-box-type-expense {
		background: linear-gradient(135deg, #fee2e2, #fecaca);
		border-color: #fca5a5;
	}

	.accounting-box-type-cost_of_sales {
		background: linear-gradient(135deg, #e2e8f0, #cbd5e1);
		border-color: #94a3b8;
	}

	.accounting-box-balance-debit {
		background: linear-gradient(135deg, #ecfccb, #d9f99d);
		border-color: #a3e635;
	}

	.accounting-box-balance-credit {
		background: linear-gradient(135deg, #cffafe, #a5f3fc);
		border-color: #67e8f9;
	}

	.accounting-box-status-active {
		background: linear-gradient(135deg, #dcfce7, #bbf7d0);
		border-color: #86efac;
	}

	.accounting-box-status-inactive {
		background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
		border-color: #cbd5e1;
	}

	.accounting-entry-line {
		border: 1px solid rgba(15, 23, 42, 0.08);
		border-radius: 0.75rem;
		background: #ffffff;
		padding: 0.8rem;
		height: 100%;
	}

	.accounting-entry-line strong {
		font-size: 0.9rem;
	}

	.accounting-amount-positive {
		color: #166534;
		font-weight: 600;
	}

	.accounting-amount-negative {
		color: #b91c1c;
		font-weight: 600;
	}
</style>
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
				<p class="pb-subtitle">Manage the chart of accounts, post journal entries, and review trial balance output.</p>
			</div>
		</div>
	</div>
	<?php if ($message !== ''): ?>
		<div class="alert alert-<?php echo htmlspecialchars($messageType); ?> mb-4" role="alert">
			<?php echo htmlspecialchars($message); ?>
		</div>
	<?php endif; ?>

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
								<?php $accountUrl = '/accounting?type=' . urlencode((string)$account['account_type']) . '&account_id=' . (int)$account['id'] . '#ledger'; ?>
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

	<div class="card admin-table-card mb-4" id="ledger">
		<div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
			<h5 class="mb-0">Account Ledger</h5>
			<?php if ($selectedAccount): ?>
				<span class="badge bg-secondary"><?php echo htmlspecialchars($selectedAccount['code'] . ' - ' . $selectedAccount['name']); ?></span>
			<?php else: ?>
				<span class="badge bg-light text-dark">Select an account to view activity</span>
			<?php endif; ?>
		</div>
		<div class="card-body">
			<?php if (!$selectedAccount): ?>
				<div class="text-muted">Click any account code or name in the chart of accounts to view its journal activity.</div>
			<?php else: ?>
				<div class="row g-3 mb-3">
					<div class="col-md-3"><div class="accounting-muted-box accounting-box-code p-3"><div class="small text-muted">Code</div><div class="fw-semibold"><?php echo htmlspecialchars($selectedAccount['code']); ?></div></div></div>
					<div class="col-md-3"><div class="accounting-muted-box <?php echo htmlspecialchars($selectedAccountTypeClass); ?> p-3"><div class="small text-muted">Type</div><div class="fw-semibold"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$selectedAccount['account_type']))); ?></div></div></div>
					<div class="col-md-3"><div class="accounting-muted-box <?php echo htmlspecialchars($selectedBalanceClass); ?> p-3"><div class="small text-muted">Normal Balance</div><div class="fw-semibold"><?php echo htmlspecialchars(ucfirst((string)$selectedAccount['normal_balance'])); ?></div></div></div>
					<div class="col-md-3"><div class="accounting-muted-box <?php echo htmlspecialchars($selectedStatusClass); ?> p-3"><div class="small text-muted">Status</div><div class="fw-semibold"><?php echo !empty($selectedAccount['is_active']) ? 'Active' : 'Inactive'; ?></div></div></div>
				</div>
				<div class="table-responsive">
					<table class="table table-sm align-middle mb-0">
						<thead><tr><th>Date</th><th>Entry No</th><th>Memo</th><th class="text-end">Movement</th></tr></thead>
						<tbody>
						<?php if (empty($accountLedger)): ?>
							<tr><td colspan="4" class="text-center py-4 text-muted">No ledger entries found for this account.</td></tr>
						<?php else: ?>
							<?php foreach ($accountLedger as $ledgerRow): ?>
								<tr>
									<td><?php echo htmlspecialchars((string)$ledgerRow['entry_date']); ?></td>
									<td><?php echo htmlspecialchars((string)$ledgerRow['entry_no']); ?></td>
									<td><?php echo htmlspecialchars((string)($ledgerRow['line_memo'] ?? $ledgerRow['memo'] ?? '-')); ?></td>
									<td class="text-end <?php echo ((float)$ledgerRow['movement'] < 0) ? 'accounting-amount-negative' : 'accounting-amount-positive'; ?>"><?php echo number_format((float)$ledgerRow['movement'], 2); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<div class="card admin-table-card mb-4" id="entry-detail">
		<div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
			<h5 class="mb-0">Journal Entry Detail</h5>
			<?php if ($selectedEntry): ?>
				<span class="badge bg-secondary"><?php echo htmlspecialchars($selectedEntry['entry_no']); ?></span>
			<?php else: ?>
				<span class="badge bg-light text-dark">Select an entry to view full lines</span>
			<?php endif; ?>
		</div>
		<div class="card-body">
			<?php if (!$selectedEntry): ?>
				<div class="text-muted">Click any entry number below to inspect the double-entry lines.</div>
			<?php else: ?>
				<div class="row g-3 mb-3">
					<div class="col-md-3"><div class="accounting-muted-box p-3"><div class="small text-muted">Entry No</div><div class="fw-semibold"><?php echo htmlspecialchars($selectedEntry['entry_no']); ?></div></div></div>
					<div class="col-md-3"><div class="accounting-muted-box p-3"><div class="small text-muted">Date</div><div class="fw-semibold"><?php echo htmlspecialchars($selectedEntry['entry_date']); ?></div></div></div>
					<div class="col-md-3"><div class="accounting-muted-box p-3"><div class="small text-muted">Reference</div><div class="fw-semibold"><?php echo htmlspecialchars(($selectedEntry['reference_type'] ?? '-') . ($selectedEntry['reference_id'] ? ' #' . (int)$selectedEntry['reference_id'] : '')); ?></div></div></div>
					<div class="col-md-3"><div class="accounting-muted-box p-3"><div class="small text-muted">Status</div><div class="fw-semibold"><?php echo htmlspecialchars(ucfirst((string)$selectedEntry['status'])); ?></div></div></div>
				</div>
				<div class="table-responsive">
					<table class="table table-sm align-middle mb-0">
						<thead><tr><th>Account</th><th>Line Memo</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
						<tbody>
						<?php if (empty($selectedEntry['lines'])): ?>
							<tr><td colspan="4" class="text-center py-4 text-muted">No lines found for this entry.</td></tr>
						<?php else: ?>
							<?php foreach ($selectedEntry['lines'] as $line): ?>
								<tr>
									<td><?php echo htmlspecialchars($line['code'] . ' - ' . $line['name']); ?></td>
									<td><?php echo htmlspecialchars((string)($line['line_memo'] ?? '')); ?></td>
									<td class="text-end accounting-amount-positive"><?php echo number_format((float)$line['debit'], 2); ?></td>
									<td class="text-end accounting-amount-negative"><?php echo number_format((float)$line['credit'], 2); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<div class="card admin-table-card">
		<div class="card-header"><h5 class="mb-0">Recent Journal Entries</h5></div>
		<div class="card-body p-0">
			<div class="table-responsive">
				<table class="table table-striped table-sm mb-0 align-middle">
					<thead><tr><th>Entry No</th><th>Date</th><th>Memo</th><th>Reference</th><th class="text-end">Debits</th><th class="text-end">Credits</th><th>Actions</th></tr></thead>
					<tbody>
					<?php if (empty($journalEntries)): ?>
						<tr><td colspan="7" class="text-center py-4 text-muted">No journal entries posted yet.</td></tr>
					<?php else: ?>
						<?php foreach ($journalEntries as $entry): ?>
							<?php $debits = 0.0; $credits = 0.0; foreach ($entry['lines'] as $line) { $debits += (float)$line['debit']; $credits += (float)$line['credit']; } ?>
								<?php
									$entryType = strtolower((string)($entry['reference_type'] ?? 'manual'));
									$entryRowClass = 'accounting-entry-manual';
									if ($entryType === 'bill') {
										$entryRowClass = 'accounting-entry-bill';
									} elseif ($entryType === 'payment') {
										$entryRowClass = 'accounting-entry-payment';
									} elseif ($entryType !== '' && $entryType !== 'manual') {
										$entryRowClass = 'accounting-entry-other';
									}
								?>
								<tr class="<?php echo htmlspecialchars($entryRowClass); ?>">
								<td><a href="/accounting?entry_id=<?php echo (int)$entry['id']; ?>#entry-detail" class="text-decoration-none fw-semibold js-entry-modal-trigger" data-entry-id="<?php echo (int)$entry['id']; ?>"><?php echo htmlspecialchars($entry['entry_no']); ?></a></td>
								<td><?php echo htmlspecialchars($entry['entry_date']); ?></td>
								<td><?php echo htmlspecialchars($entry['memo'] ?? '-'); ?></td>
								<td><?php echo htmlspecialchars(($entry['reference_type'] ?? '-') . ($entry['reference_id'] ? ' #' . (int)$entry['reference_id'] : '')); ?></td>
									<td class="text-end accounting-amount-positive"><?php echo number_format($debits, 2); ?></td>
									<td class="text-end accounting-amount-negative"><?php echo number_format($credits, 2); ?></td>
									<td>
										<?php if ((string)($entry['status'] ?? '') === 'posted' && strtolower((string)($entry['reference_type'] ?? '')) !== 'reversal'): ?>
											<form method="post" class="d-flex gap-1 align-items-center">
												<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['accounting_csrf']); ?>">
												<input type="hidden" name="action" value="reverse_entry">
												<input type="hidden" name="entry_id" value="<?php echo (int)$entry['id']; ?>">
												<input type="hidden" name="reversal_date" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>">
												<button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Reverse this journal entry?');">Reverse</button>
											</form>
										<?php else: ?>
											<span class="text-muted small">-</span>
										<?php endif; ?>
									</td>
							</tr>
							<tr class="table-light <?php echo htmlspecialchars($entryRowClass); ?>">
								<td colspan="7">
									<div class="small text-muted mb-1">Lines</div>
									<div class="row g-2">
										<?php foreach ($entry['lines'] as $line): ?>
											<div class="col-md-4">
												<div class="accounting-entry-line">
													<div><strong><?php echo htmlspecialchars($line['code']); ?></strong> - <?php echo htmlspecialchars($line['name']); ?></div>
													<div class="small text-muted"><?php echo htmlspecialchars($line['line_memo'] ?? ''); ?></div>
														<div class="small">Debit: <span class="accounting-amount-positive"><?php echo number_format((float)$line['debit'], 2); ?></span> | Credit: <span class="accounting-amount-negative"><?php echo number_format((float)$line['credit'], 2); ?></span></div>
												</div>
											</div>
										<?php endforeach; ?>
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

<div class="modal fade" id="journalEntryModal" tabindex="-1" aria-labelledby="journalEntryModalLabel" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
		<div class="modal-content">
			<div class="modal-header bg-primary text-white">
				<div>
					<h5 class="modal-title mb-0" id="journalEntryModalLabel">Journal Entry Detail</h5>
					<div class="small opacity-75" id="journalEntryModalSubline"></div>
				</div>
				<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div class="row g-3 mb-3">
					<div class="col-md-4"><div class="accounting-muted-box p-3"><div class="small text-muted">Entry No</div><div class="fw-semibold" id="journalEntryModalNo">-</div></div></div>
					<div class="col-md-4"><div class="accounting-muted-box p-3"><div class="small text-muted">Date</div><div class="fw-semibold" id="journalEntryModalDate">-</div></div></div>
					<div class="col-md-4"><div class="accounting-muted-box p-3"><div class="small text-muted">Reference</div><div class="fw-semibold" id="journalEntryModalRef">-</div></div></div>
				</div>
				<div class="mb-3"><div class="small text-muted mb-1">Memo</div><div class="fw-semibold" id="journalEntryModalMemo">-</div></div>
				<div class="table-responsive">
					<table class="table table-sm align-middle mb-0">
						<thead><tr><th>Account</th><th>Line Memo</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
						<tbody id="journalEntryModalLines"></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
	var entryData = <?php echo json_encode($journalEntryModalData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
	var entryMap = {};
	entryData.forEach(function (entry) {
		entryMap[String(entry.id)] = entry;
	});

	var modalEl = document.getElementById('journalEntryModal');
	if (!modalEl || !window.bootstrap || !window.bootstrap.Modal) {
		return;
	}

	var modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
	var titleEl = document.getElementById('journalEntryModalLabel');
	var sublineEl = document.getElementById('journalEntryModalSubline');
	var modalContentEl = modalEl.querySelector('.modal-content');
	var noEl = document.getElementById('journalEntryModalNo');
	var dateEl = document.getElementById('journalEntryModalDate');
	var refEl = document.getElementById('journalEntryModalRef');
	var memoEl = document.getElementById('journalEntryModalMemo');
	var linesEl = document.getElementById('journalEntryModalLines');
	var entryTypeClasses = ['accounting-modal-bill', 'accounting-modal-payment', 'accounting-modal-manual', 'accounting-modal-other'];

	function renderEntry(entry) {
		if (!entry) {
			return;
		}

		modalContentEl.classList.remove.apply(modalContentEl.classList, entryTypeClasses);
		var entryType = String((entry.reference_type || 'manual')).toLowerCase();
		var modalClass = 'accounting-modal-manual';
		if (entryType === 'bill') {
			modalClass = 'accounting-modal-bill';
		} else if (entryType === 'payment') {
			modalClass = 'accounting-modal-payment';
		} else if (entryType !== '' && entryType !== 'manual') {
			modalClass = 'accounting-modal-other';
		}
		modalContentEl.classList.add(modalClass);

		titleEl.textContent = 'Journal Entry Detail';
		sublineEl.textContent = entry.entry_no;
		noEl.textContent = entry.entry_no;
		dateEl.textContent = entry.entry_date;
		refEl.textContent = entry.reference_label || '-';
		memoEl.textContent = entry.memo || '-';

		var rows = '';
		if (!entry.lines || !entry.lines.length) {
			rows = '<tr><td colspan="4" class="text-center py-4 text-muted">No lines found for this entry.</td></tr>';
		} else {
			entry.lines.forEach(function (line) {
				var lineClass = 'accounting-entry-line-manual';
				if (entryType === 'bill') {
					lineClass = 'accounting-entry-line-bill';
				} else if (entryType === 'payment') {
					lineClass = 'accounting-entry-line-payment';
				} else if (entryType !== '' && entryType !== 'manual') {
					lineClass = 'accounting-entry-line-other';
				}
				rows += '<tr>' +
					'<td><div class="accounting-entry-line ' + lineClass + '"><strong>' + escapeHtml(line.code + ' - ' + line.name) + '</strong></div></td>' +
					'<td>' + escapeHtml(line.line_memo || '') + '</td>' +
					'<td class="text-end accounting-amount-positive">' + escapeHtml(line.debit) + '</td>' +
					'<td class="text-end accounting-amount-negative">' + escapeHtml(line.credit) + '</td>' +
				'</tr>';
			});
		}
		linesEl.innerHTML = rows;
		modal.show();
	}

	function escapeHtml(value) {
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	document.querySelectorAll('.js-entry-modal-trigger').forEach(function (link) {
		link.addEventListener('click', function (event) {
			event.preventDefault();
			var entry = entryMap[String(link.getAttribute('data-entry-id') || '')];
			renderEntry(entry);
		});
	});

	if (window.location.search.indexOf('entry_id=') !== -1 && document.getElementById('entry-detail')) {
		window.requestAnimationFrame(function () {
			var params = new URLSearchParams(window.location.search);
			var entryId = params.get('entry_id');
			if (entryId && entryMap[entryId]) {
				renderEntry(entryMap[entryId]);
			}
		});
	}
});
</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>