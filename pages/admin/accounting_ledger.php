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

// POST: reverse_entry
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['accounting_csrf'], (string)$_POST['csrf_token'])) {
        $_SESSION['accounting_flash'] = ['message' => 'Invalid CSRF token.', 'type' => 'danger'];
        header('Location: /accounting/ledger');
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'reverse_entry') {
        $entryId      = (int)($_POST['entry_id'] ?? 0);
        $reversalDate = preg_replace('/[^0-9\-]/', '', (string)($_POST['reversal_date'] ?? date('Y-m-d')));
        try {
            $accounting->reverseJournalEntry($entryId, $reversalDate);
            $_SESSION['accounting_flash'] = ['message' => 'Journal entry reversed.', 'type' => 'success'];
        } catch (Throwable $e) {
            $_SESSION['accounting_flash'] = ['message' => 'Error: ' . $e->getMessage(), 'type' => 'danger'];
        }
        header('Location: /accounting/ledger');
        exit;
    }
    header('Location: /accounting/ledger');
    exit;
}

// GET data: account ledger
$selectedType      = isset($_GET['type']) ? preg_replace('/[^a-z_]/', '', strtolower((string)$_GET['type'])) : '';
$selectedAccountId = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
$selectedEntryId   = isset($_GET['entry_id'])   ? (int)$_GET['entry_id']   : 0;

$accounts      = [];
$selectedAccount = null;
$accountLedger = [];
$selectedAccountTypeClass  = 'accounting-muted-box';
$selectedBalanceClass      = 'accounting-muted-box';
$selectedStatusClass       = 'accounting-muted-box';
try { $accounts = $accounting->getAccounts($selectedType ?: null); } catch (Throwable $e) { $accounts = []; }
if ($selectedAccountId > 0) {
    try {
        $selectedAccount = $accounting->getAccountById($selectedAccountId);
        if ($selectedAccount) {
            $selectedAccountTypeClass = 'accounting-muted-box accounting-box-type-' . htmlspecialchars((string)$selectedAccount['account_type']);
            $selectedBalanceClass     = 'accounting-muted-box accounting-box-balance-' . htmlspecialchars((string)$selectedAccount['normal_balance']);
            $selectedStatusClass      = 'accounting-muted-box ' . (!empty($selectedAccount['is_active']) ? 'accounting-box-status-active' : 'accounting-box-status-inactive');
            $accountLedger = $accounting->getLedgerByAccount($selectedAccountId);
        }
    } catch (Throwable $e) { $selectedAccount = null; }
}

// Journal entries
$journalEntries       = [];
$journalEntryModalData = [];
$selectedEntry        = null;
try { $rawJournalEntries = $accounting->getJournalEntries(50); } catch (Throwable $e) { $rawJournalEntries = []; }
foreach ($rawJournalEntries as $je) {
    $journalEntries[] = $je;
    $refLabel = ($je['reference_type'] ?? '-');
    if (!empty($je['reference_id'])) {
        $refLabel .= ' #' . (int)$je['reference_id'];
    }
    $modalEntry = [
        'id'           => (int)$je['id'],
        'entry_no'     => $je['entry_no'],
        'entry_date'   => $je['entry_date'],
        'memo'         => $je['memo'] ?? '',
        'reference_type' => $je['reference_type'] ?? '',
        'reference_label' => $refLabel,
        'lines'        => [],
    ];
    foreach ($je['lines'] as $line) {
        $modalEntry['lines'][] = [
            'code'      => $line['code'],
            'name'      => $line['name'],
            'line_memo' => $line['line_memo'] ?? '',
            'debit'     => number_format((float)$line['debit'], 2),
            'credit'    => number_format((float)$line['credit'], 2),
        ];
    }
    $journalEntryModalData[] = $modalEntry;
}
if ($selectedEntryId > 0) {
    try { $selectedEntry = $accounting->getJournalEntryById($selectedEntryId); } catch (Throwable $e) { $selectedEntry = null; }
}

$is_admin_page = true;
$page_title = 'Ledger & Journals — Accounting';
include __DIR__ . '/../../templates/header.php';
?>
<?php include __DIR__ . '/../../templates/accounting_styles.php'; ?>
<div class="container-fluid px-4 py-4">

	<!-- Banner -->
	<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
		<div>
			<h2 class="fw-bold mb-1"><i class="bi bi-list-ul me-2 text-primary"></i>Ledger &amp; Journals</h2>
			<p class="text-muted mb-0">Account ledger activity · Journal entry detail · Recent postings</p>
		</div>
		<a href="/accounting" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Overview</a>
	</div>

	<?php include __DIR__ . '/../../templates/accounting_subnav.php'; ?>

	<?php if ($message): ?>
		<div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show mb-4" role="alert">
			<?php echo htmlspecialchars($message); ?>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>
	<?php endif; ?>

	<!-- Account filter pills -->
	<?php if ($selectedType || $selectedAccountId): ?>
		<div class="mb-3 d-flex gap-2 align-items-center flex-wrap">
			<?php if ($selectedType): ?>
				<span class="accounting-filter-pill"><i class="bi bi-funnel me-1"></i>Type: <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $selectedType))); ?> <a href="/accounting/ledger" class="ms-1 text-secondary text-decoration-none">×</a></span>
			<?php endif; ?>
			<?php if ($selectedAccount): ?>
				<span class="accounting-filter-pill"><i class="bi bi-bank me-1"></i><?php echo htmlspecialchars($selectedAccount['code'] . ' – ' . $selectedAccount['name']); ?> <a href="/accounting/ledger<?php echo $selectedType ? '?type=' . urlencode($selectedType) : ''; ?>" class="ms-1 text-secondary text-decoration-none">×</a></span>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<!-- Account Ledger -->
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
				<div class="text-muted">Click any account code or name in the table below to view its journal activity, or use the filter pills above.</div>
				<!-- Quick account picker -->
				<?php if (!empty($accounts)): ?>
					<div class="mt-3">
						<p class="small fw-semibold mb-2">Accounts</p>
						<div class="table-responsive">
							<table class="table table-sm table-hover align-middle accounting-link-table">
								<thead class="table-light"><tr><th>Code</th><th>Name</th><th>Type</th></tr></thead>
								<tbody>
								<?php foreach ($accounts as $acct): ?>
									<tr class="accounting-row-<?php echo htmlspecialchars((string)$acct['account_type']); ?> <?php echo empty($acct['is_active']) ? 'accounting-row-inactive' : ''; ?>">
										<td><a href="/accounting/ledger?account_id=<?php echo (int)$acct['id']; ?>" class="fw-semibold text-decoration-none"><?php echo htmlspecialchars($acct['code']); ?></a></td>
										<td><a href="/accounting/ledger?account_id=<?php echo (int)$acct['id']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($acct['name']); ?></a></td>
										<td><span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$acct['account_type']))); ?></span></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>
				<?php endif; ?>
			<?php else: ?>
				<div class="row g-3 mb-3">
					<div class="col-md-3"><div class="accounting-muted-box accounting-box-code p-3"><div class="small text-muted">Code</div><div class="fw-semibold"><?php echo htmlspecialchars($selectedAccount['code']); ?></div></div></div>
					<div class="col-md-3"><div class="<?php echo htmlspecialchars($selectedAccountTypeClass); ?> p-3"><div class="small text-muted">Type</div><div class="fw-semibold"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$selectedAccount['account_type']))); ?></div></div></div>
					<div class="col-md-3"><div class="<?php echo htmlspecialchars($selectedBalanceClass); ?> p-3"><div class="small text-muted">Normal Balance</div><div class="fw-semibold"><?php echo htmlspecialchars(ucfirst((string)$selectedAccount['normal_balance'])); ?></div></div></div>
					<div class="col-md-3"><div class="<?php echo htmlspecialchars($selectedStatusClass); ?> p-3"><div class="small text-muted">Status</div><div class="fw-semibold"><?php echo !empty($selectedAccount['is_active']) ? 'Active' : 'Inactive'; ?></div></div></div>
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
									<td><a href="/accounting/ledger?account_id=<?php echo $selectedAccountId; ?>&entry_id=<?php echo (int)($ledgerRow['journal_entry_id'] ?? 0); ?>#entry-detail" class="text-decoration-none fw-semibold js-entry-modal-trigger" data-entry-id="<?php echo (int)($ledgerRow['journal_entry_id'] ?? 0); ?>"><?php echo htmlspecialchars((string)$ledgerRow['entry_no']); ?></a></td>
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

	<!-- Journal Entry Detail -->
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
				<div class="text-muted">Click any entry number in the Recent Journal Entries table below to inspect the double-entry lines.</div>
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

	<!-- Recent Journal Entries -->
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
								<td><a href="/accounting/ledger?entry_id=<?php echo (int)$entry['id']; ?>#entry-detail" class="text-decoration-none fw-semibold js-entry-modal-trigger" data-entry-id="<?php echo (int)$entry['id']; ?>"><?php echo htmlspecialchars($entry['entry_no']); ?></a></td>
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

<!-- Journal Entry Modal -->
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
		if (!entry) { return; }

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
