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

// POST: post_transfer
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['accounting_csrf'], (string)$_POST['csrf_token'])) {
        $_SESSION['accounting_flash'] = ['message' => 'Invalid CSRF token.', 'type' => 'danger'];
        header('Location: /accounting/transfers');
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'post_transfer') {
        $transferDate   = preg_replace('/[^0-9\-]/', '', (string)($_POST['transfer_date'] ?? date('Y-m-d')));
        $fromAccountId  = (int)($_POST['from_account_id'] ?? 0);
        $toAccountId    = (int)($_POST['to_account_id'] ?? 0);
        $amount         = (float)($_POST['amount'] ?? 0);
        $memo           = htmlspecialchars_decode(strip_tags((string)($_POST['memo'] ?? '')));
        if ($fromAccountId <= 0 || $toAccountId <= 0 || $amount <= 0 || $fromAccountId === $toAccountId) {
            $_SESSION['accounting_flash'] = ['message' => 'Invalid transfer: check accounts and amount.', 'type' => 'danger'];
        } else {
            try {
                $userId = (int)($_SESSION['user_id'] ?? 0);
                $accounting->postTransfer($transferDate, $fromAccountId, $toAccountId, $amount, $memo, $userId ?: null);
                $_SESSION['accounting_flash'] = ['message' => 'Transfer posted successfully.', 'type' => 'success'];
            } catch (Throwable $e) {
                $_SESSION['accounting_flash'] = ['message' => 'Error: ' . $e->getMessage(), 'type' => 'danger'];
            }
        }
        header('Location: /accounting/transfers');
        exit;
    }
    header('Location: /accounting/transfers');
    exit;
}

// GET data
$transfers = [];
try { $transfers = $accounting->getTransfers(50); } catch (Throwable $e) { $transfers = []; }
$allAccounts = [];
try { $allAccounts = $accounting->getAccounts(); } catch (Throwable $e) { $allAccounts = []; }

$is_admin_page = true;
$page_title = 'Fund Transfers — Accounting';
include __DIR__ . '/../../templates/header.php';
?>
<?php include __DIR__ . '/../../templates/accounting_styles.php'; ?>
<div class="container-fluid mt-4 admin-shell">

	<div class="pb-banner pb-banner--teal mb-4">
		<div class="pb-bg" aria-hidden="true">
			<div class="pb-grid"></div>
			<div class="pb-blob pb-blob--a"></div>
			<div class="pb-blob pb-blob--b"></div>
			<i class="bi bi-arrow-left-right pb-watermark"></i>
		</div>
		<div class="pb-inner">
			<div class="pb-left">
				<div class="pb-eyebrow-row">
					<span class="pb-eyebrow-chip"><i class="bi bi-arrow-left-right"></i> Finance &amp; Accounting</span>
				</div>
				<h2 class="pb-title">Fund Transfers</h2>
				<p class="pb-subtitle">Move funds between accounts with automatic double-entry posting</p>
			</div>
			<div class="pb-right">
				<div class="pb-btn-row">
					<a href="/accounting" class="pb-btn"><i class="bi bi-arrow-left me-1"></i> Back to Overview</a>
				</div>
			</div>
		</div>
	</div>

	<?php include __DIR__ . '/../../templates/accounting_subnav.php'; ?>

	<?php if ($message): ?>
		<div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show mb-4" role="alert">
			<?php echo htmlspecialchars($message); ?>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>
	<?php endif; ?>

	<div class="card admin-table-card mb-4" id="transfers">
		<div class="card-header">
			<h5 class="mb-0"><i class="bi bi-arrow-left-right me-2"></i>Fund Transfers</h5>
		</div>
		<div class="card-body">
			<div class="row g-4">
				<!-- Transfer form -->
				<div class="col-md-5">
					<h6 class="fw-semibold mb-3">New Transfer</h6>
					<form method="post" action="/accounting/transfers">
						<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['accounting_csrf']); ?>">
						<input type="hidden" name="action" value="post_transfer">
						<div class="mb-2">
							<label class="form-label mb-1 small fw-semibold">Date</label>
							<input type="date" name="transfer_date" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>" required>
						</div>
						<div class="mb-2">
							<label class="form-label mb-1 small fw-semibold">From Account</label>
							<select name="from_account_id" class="form-select form-select-sm" required>
								<option value="">— select —</option>
								<?php foreach ($allAccounts as $acct): ?>
									<option value="<?php echo (int)$acct['id']; ?>"><?php echo htmlspecialchars($acct['code'] . ' – ' . $acct['name']); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="mb-2">
							<label class="form-label mb-1 small fw-semibold">To Account</label>
							<select name="to_account_id" class="form-select form-select-sm" required>
								<option value="">— select —</option>
								<?php foreach ($allAccounts as $acct): ?>
									<option value="<?php echo (int)$acct['id']; ?>"><?php echo htmlspecialchars($acct['code'] . ' – ' . $acct['name']); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="mb-2">
							<label class="form-label mb-1 small fw-semibold">Amount</label>
							<input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" required>
						</div>
						<div class="mb-3">
							<label class="form-label mb-1 small fw-semibold">Memo <span class="text-muted">(optional)</span></label>
							<input type="text" name="memo" class="form-control form-control-sm" maxlength="255">
						</div>
						<button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-arrow-left-right me-1"></i>Post Transfer</button>
					</form>
				</div>
				<!-- Transfer history -->
				<div class="col-md-7">
					<h6 class="fw-semibold mb-3">Recent Transfers</h6>
					<?php if (empty($transfers)): ?>
						<p class="text-muted small">No transfers recorded yet.</p>
					<?php else: ?>
						<div class="table-responsive">
							<table class="table table-sm align-middle">
								<thead class="table-light">
									<tr>
										<th>Ref</th>
										<th>Date</th>
										<th>From</th>
										<th>To</th>
										<th class="text-end">Amount</th>
										<th>Memo</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($transfers as $tr): ?>
										<tr>
											<td class="font-monospace small"><?php echo htmlspecialchars($tr['transfer_no']); ?></td>
											<td><?php echo htmlspecialchars($tr['transfer_date']); ?></td>
											<td><?php echo htmlspecialchars($tr['from_code'] . ' ' . $tr['from_name']); ?></td>
											<td><?php echo htmlspecialchars($tr['to_code'] . ' ' . $tr['to_name']); ?></td>
											<td class="text-end"><?php echo number_format((float)$tr['amount'], 2); ?></td>
											<td class="text-muted small"><?php echo htmlspecialchars($tr['memo'] ?? ''); ?></td>
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
