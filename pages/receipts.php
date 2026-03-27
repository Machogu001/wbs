<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/PaymentLink.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
if (!$auth->isLoggedIn()) {
    header('Location: /login');
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$stmt = $db->prepare("SELECT p.*, b.billing_month
    FROM payments p
    LEFT JOIN bills b ON b.id = p.bill_id
    WHERE p.user_id = :user_id AND p.status = 'completed'
    ORDER BY COALESCE(p.transaction_date, p.created_at) DESC
    LIMIT 200");
$stmt->execute([':user_id' => $userId]);
$receipts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$page_title = 'My Receipts';
include __DIR__ . '/../templates/header.php';
?>
<div class="container py-4">
    <div class="admin-page-header mb-4">
        <h2 class="mb-1">Receipt History</h2>
        <p class="admin-page-subtitle">View and download your completed payment receipts.</p>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Receipt</th>
                            <th>Billing Month</th>
                            <th>Amount</th>
                            <th>Paid At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($receipts)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">No completed receipts found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($receipts as $receipt): ?>
                                <?php $receiptToken = !empty($receipt['bill_id']) ? PaymentLink::generateToken((int)$receipt['bill_id']) : ''; ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($receipt['mpesa_receipt'] ?? ('PAY-' . (int)$receipt['id'])); ?></td>
                                    <td><?php echo !empty($receipt['billing_month']) ? htmlspecialchars(date('M Y', strtotime((string)$receipt['billing_month']))) : '-'; ?></td>
                                    <td>KES <?php echo number_format((float)($receipt['amount'] ?? 0), 2); ?></td>
                                    <td><?php echo !empty($receipt['transaction_date']) ? htmlspecialchars(date('d-m-Y H:i', strtotime((string)$receipt['transaction_date']))) : '-'; ?></td>
                                    <td>
                                        <?php if ($receiptToken !== ''): ?>
                                            <a class="btn btn-sm btn-outline-primary" href="/payment-receipt?t=<?php echo urlencode($receiptToken); ?>&p=<?php echo (int)$receipt['id']; ?>">View</a>
                                            <a class="btn btn-sm btn-outline-secondary" href="/payment-receipt-pdf?t=<?php echo urlencode($receiptToken); ?>&p=<?php echo (int)$receipt['id']; ?>">PDF</a>
                                        <?php else: ?>
                                            <span class="text-muted small">Receipt link unavailable</span>
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
<?php include __DIR__ . '/../templates/footer.php'; ?>
