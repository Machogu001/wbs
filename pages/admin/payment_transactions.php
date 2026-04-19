<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/FinanceApproval.php';
require_once __DIR__ . '/../../includes/PaymentLink.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if (!$auth->isLoggedIn() || (!$auth->isAdmin() && !$auth->hasPermission('view_payments'))) {
    header('Location: /login');
    exit;
}

if (empty($_SESSION['payment_transactions_csrf'])) {
    $_SESSION['payment_transactions_csrf'] = bin2hex(random_bytes(32));
}

$approvals = new FinanceApproval($db);

$message = '';
$messageType = 'success';

if (!empty($_SESSION['payment_transactions_flash']) && is_array($_SESSION['payment_transactions_flash'])) {
    $message = (string)($_SESSION['payment_transactions_flash']['message'] ?? '');
    $messageType = (string)($_SESSION['payment_transactions_flash']['type'] ?? 'success');
    unset($_SESSION['payment_transactions_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_approval') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['payment_transactions_csrf'], $token)) {
        $message = 'Security validation failed. Please refresh and try again.';
        $messageType = 'danger';
    } else {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $receipt = trim((string)($_POST['reference_no'] ?? ''));
        if ($paymentId > 0 && $approvals->createFromPayment($paymentId, $amount, (int)($_SESSION['user_id'] ?? 0), $receipt)) {
            $message = 'Finance approval item created.';
        } else {
            $message = 'Could not create finance approval item.';
            $messageType = 'danger';
        }
    }

    $_SESSION['payment_transactions_flash'] = [
        'message' => $message,
        'type' => $messageType,
    ];

    $redirectUrl = (string)($_SERVER['REQUEST_URI'] ?? '/admin/payment-transactions');
    if ($redirectUrl === '') {
        $redirectUrl = '/admin/payment-transactions';
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$status = trim((string)($_GET['status'] ?? ''));
$allowedStatuses = ['pending', 'completed', 'failed', 'cancelled'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$counts = [
    'pending' => 0,
    'completed' => 0,
    'failed' => 0,
    'cancelled' => 0,
];
$stmtCounts = $db->query("SELECT status, COUNT(*) AS total FROM payments GROUP BY status");
foreach (($stmtCounts ? $stmtCounts->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
    $key = (string)($row['status'] ?? '');
    if (isset($counts[$key])) {
        $counts[$key] = (int)($row['total'] ?? 0);
    }
}

$sql = "SELECT p.*, u.full_name, u.account_number, b.billing_month
    FROM payments p
    LEFT JOIN users u ON u.id = p.user_id
    LEFT JOIN bills b ON b.id = p.bill_id";
$params = [];
if ($status !== '') {
    $sql .= " WHERE p.status = :status";
    $params[':status'] = $status;
}
$sql .= " ORDER BY COALESCE(p.transaction_date, p.created_at) DESC LIMIT 200";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$page_title = 'Payment Transactions';
$is_admin_page = true;
include __DIR__ . '/../../templates/header.php';
?>
<div class="container-fluid mt-4 admin-shell">
    <div class="pb-banner pb-banner--cobalt mb-4">
        <div class="pb-bg" aria-hidden="true">
            <div class="pb-grid"></div>
            <div class="pb-blob pb-blob--a"></div>
            <div class="pb-blob pb-blob--b"></div>
            <i class="bi bi-credit-card-2-front-fill pb-watermark"></i>
        </div>
        <div class="pb-inner">
            <div class="pb-left">
                <div class="pb-eyebrow-row">
                    <span class="pb-eyebrow-chip"><i class="bi bi-credit-card-2-front-fill"></i> Finance Records</span>
                </div>
                <h2 class="pb-title">Payment Transactions</h2>
                <p class="pb-subtitle">Review payment statuses, receipts, and request finance approvals where needed.</p>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><a class="text-decoration-none" href="/admin/payment-transactions"><div class="card admin-kpi-card"><div class="card-body"><h5>All</h5><h3><?php echo array_sum($counts); ?></h3></div></div></a></div>
        <div class="col-md-3"><a class="text-decoration-none" href="/admin/payment-transactions?status=pending"><div class="card admin-kpi-card bg-warning text-dark"><div class="card-body"><h5>Pending</h5><h3><?php echo $counts['pending']; ?></h3></div></div></a></div>
        <div class="col-md-3"><a class="text-decoration-none" href="/admin/payment-transactions?status=completed"><div class="card admin-kpi-card bg-success text-white"><div class="card-body"><h5>Completed</h5><h3><?php echo $counts['completed']; ?></h3></div></div></a></div>
        <div class="col-md-3"><a class="text-decoration-none" href="/admin/payment-transactions?status=failed"><div class="card admin-kpi-card bg-danger text-white"><div class="card-body"><h5>Failed</h5><h3><?php echo $counts['failed']; ?></h3></div></div></a></div>
    </div>

    <div class="card admin-table-card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0">Latest Transactions</h5>
            <span class="badge bg-secondary"><?php echo $status !== '' ? htmlspecialchars(ucfirst($status)) : 'All statuses'; ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-sm mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Customer</th>
                            <th>Account</th>
                            <th>Receipt</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Transaction Date</th>
                            <th>ETIMS</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payments)): ?>
                            <tr><td colspan="9" class="text-center py-4 text-muted">No payment transactions found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($payments as $payment): ?>
                                <?php $receiptToken = !empty($payment['bill_id']) ? PaymentLink::generateToken((int)$payment['bill_id']) : ''; ?>
                                <tr>
                                    <td><?php echo (int)$payment['id']; ?></td>
                                    <td><?php echo htmlspecialchars($payment['full_name'] ?? 'Unknown'); ?></td>
                                    <td><?php echo htmlspecialchars($payment['account_number'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($payment['mpesa_receipt'] ?? '-'); ?></td>
                                    <td>KES <?php echo number_format((float)($payment['amount'] ?? 0), 2); ?></td>
                                    <td><span class="badge bg-<?php echo ($payment['status'] === 'completed') ? 'success' : (($payment['status'] === 'failed') ? 'danger' : (($payment['status'] === 'pending') ? 'warning text-dark' : 'secondary')); ?>"><?php echo htmlspecialchars(ucfirst((string)$payment['status'])); ?></span></td>
                                    <td><?php echo !empty($payment['transaction_date']) ? htmlspecialchars(date('d-m-Y H:i', strtotime((string)$payment['transaction_date']))) : '-'; ?></td>
                                    <td><?php echo htmlspecialchars((string)($payment['etims_status'] ?? '-')); ?></td>
                                    <td>
                                        <?php if (($payment['status'] ?? '') === 'completed' && $receiptToken !== ''): ?>
                                            <a class="btn btn-sm btn-outline-primary" href="/payment-receipt?t=<?php echo urlencode($receiptToken); ?>&p=<?php echo (int)$payment['id']; ?>">Receipt</a>
                                        <?php endif; ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="request_approval">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['payment_transactions_csrf']); ?>">
                                            <input type="hidden" name="payment_id" value="<?php echo (int)$payment['id']; ?>">
                                            <input type="hidden" name="amount" value="<?php echo htmlspecialchars((string)$payment['amount']); ?>">
                                            <input type="hidden" name="reference_no" value="<?php echo htmlspecialchars((string)($payment['mpesa_receipt'] ?? '')); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary" data-confirm-message="Create a finance approval item for this payment?">Queue Approval</button>
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
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.WbsAdminUi) {
        window.WbsAdminUi.init({
            flashMessage: <?php echo json_encode($message); ?>,
            flashType: <?php echo json_encode($messageType); ?>
        });
    }
});
</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>
