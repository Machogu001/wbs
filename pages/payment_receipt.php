<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/PaymentLink.php';
require_once __DIR__ . '/../includes/Payment.php';
require_once __DIR__ . '/../includes/Bill.php';
require_once __DIR__ . '/../includes/User.php';

$database = new Database();
$db = $database->getConnection();

$page_title = 'Payment Receipt';
require_once __DIR__ . '/../templates/header.php';

$message = null;
$message_type = 'danger';
$paymentRow = null;
$billRow = null;
$userRow = null;

$token = isset($_GET['t']) ? trim($_GET['t']) : '';
$paymentId = isset($_GET['p']) ? (int)$_GET['p'] : 0;

if (!$db) {
    $message = 'Unable to connect to the database.';
} elseif ($token === '' || $paymentId <= 0) {
    $message = 'Invalid receipt link.';
} else {
    $billId = PaymentLink::getBillIdFromToken($token);
    if (!$billId) {
        $message = 'Invalid or expired receipt link.';
    } else {
        $payment = new Payment($db);
        $paymentRow = $payment->getById($paymentId);
        if (!$paymentRow || (int)$paymentRow['bill_id'] !== (int)$billId) {
            $message = 'Payment not found for this receipt.';
        } else {
            $bill = new Bill($db);
            $billRow = $bill->getById($billId);
            if (!$billRow) {
                $message = 'Bill not found for this payment.';
            } else {
                $user = new User($db);
                $userRow = $user->getById($paymentRow['user_id']);
                if (!$userRow) {
                    $message = 'Customer account not found.';
                } elseif ($paymentRow['status'] !== 'completed') {
                    $message = 'Payment is not completed yet.';
                    $message_type = 'warning';
                } else {
                    $message = 'Payment successful.';
                    $message_type = 'success';
                }
            }
        }
    }
}

$paymentMethodMap = [
    'mpesa' => 'M-Pesa',
    'cash' => 'Cash',
    'bank' => 'Bank Transfer',
    'card' => 'Card',
    'cheque' => 'Cheque',
    'wallet' => 'Wallet',
    'other' => 'Other',
];
$paymentMethodKey = strtolower(trim((string)($paymentRow['payment_method'] ?? 'mpesa')));
$paymentMethodLabel = $paymentMethodMap[$paymentMethodKey] ?? ucfirst($paymentMethodKey ?: 'M-Pesa');
$referenceLabel = $paymentMethodKey === 'mpesa' ? 'M-Pesa Reference' : 'Reference Number';
$receiverLabel = 'System / Automatic';
if (!empty($paymentRow['received_by_user_id'])) {
    $receiverRow = $user->getById((int)$paymentRow['received_by_user_id']);
    if ($receiverRow && !empty($receiverRow['full_name'])) {
        $receiverLabel = $receiverRow['full_name'];
    }
}
?>

<div class="container mt-4 payment-receipt-page">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex align-items-center mb-2">
                <?php if($paymentRow && $paymentRow['status'] === 'completed' && $message_type === 'success'): ?>
                    <span class="badge bg-success me-2">
                        <i class="bi bi-check-circle-fill"></i> Payment Successful
                    </span>
                <?php elseif($message_type === 'warning'): ?>
                    <span class="badge bg-warning text-dark me-2">
                        <i class="bi bi-exclamation-triangle-fill"></i> Pending Confirmation
                    </span>
                <?php else: ?>
                    <span class="badge bg-danger me-2">
                        <i class="bi bi-x-circle-fill"></i> Payment Issue
                    </span>
                <?php endif; ?>
                <h2 class="mb-0">Payment Receipt</h2>
            </div>
            <p class="text-muted mb-0">
                <?php if($paymentRow && $paymentRow['status'] === 'completed' && $message_type === 'success'): ?>
                    Thank you. Your payment has been received and the bill has been updated.
                <?php elseif($message_type === 'warning'): ?>
                    This receipt will be available once the payment is fully confirmed.
                <?php else: ?>
                    We were unable to confirm this payment. Please review the details below.
                <?php endif; ?>
            </p>
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
        <div class="col-md-8">
            <div class="card shadow-sm receipt-primary-card">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Receipt Details</h5>
                </div>
                <div class="card-body">
                    <?php if(!$paymentRow || !$billRow || !$userRow || $paymentRow['status'] !== 'completed'): ?>
                        <p class="mb-0"><?php echo htmlspecialchars($message ?: 'Unable to load receipt details.'); ?></p>
                    <?php else: ?>
                        <dl class="row mb-0 receipt-detail-grid">
                            <dt class="col-sm-4">Receipt Number</dt>
                            <dd class="col-sm-8 fw-semibold text-break"><?php echo htmlspecialchars($paymentRow['mpesa_receipt'] ?? 'N/A'); ?></dd>

                            <dt class="col-sm-4">Payment Method</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($paymentMethodLabel); ?></dd>

                            <dt class="col-sm-4"><?php echo htmlspecialchars($referenceLabel); ?></dt>
                            <dd class="col-sm-8 text-break"><?php echo htmlspecialchars($paymentRow['mpesa_receipt'] ?? 'N/A'); ?></dd>

                            <dt class="col-sm-4">Account Number</dt>
                            <dd class="col-sm-8 text-break"><?php echo htmlspecialchars($billRow['account_number']); ?></dd>

                            <dt class="col-sm-4">Customer Name</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($userRow['full_name']); ?></dd>

                            <dt class="col-sm-4">Billing Month</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars(date('F Y', strtotime($billRow['billing_month']))); ?></dd>

                            <dt class="col-sm-4">Amount Paid</dt>
                            <dd class="col-sm-8">KES <?php echo number_format((float)$paymentRow['amount'], 2); ?></dd>

                            <dt class="col-sm-4">Payment Status</dt>
                            <dd class="col-sm-8"><span class="badge bg-success">Completed</span></dd>

                            <dt class="col-sm-4">Paid On</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($paymentRow['transaction_date'] ?? $paymentRow['created_at']))); ?></dd>

                            <dt class="col-sm-4">Received By</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($receiverLabel); ?></dd>
                        </dl>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm receipt-next-card">
                <div class="card-header bg-light">
                    <h5 class="mb-0">Next Steps</h5>
                </div>
                <div class="card-body d-grid gap-2 receipt-next-actions">
                    <?php if($paymentRow && $billRow && $userRow && $paymentRow['status'] === 'completed'): ?>
                    <a href="/payment-receipt-pdf?t=<?php echo urlencode($token); ?>&p=<?php echo (int)$paymentId; ?>" class="btn btn-outline-primary">
                        <i class="bi bi-file-earmark-arrow-down"></i> Download PDF Receipt
                    </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-secondary" onclick="(function(){ if(window.close){ window.close(); } window.location.href='/' })();">
                        <i class="bi bi-x-lg"></i> Close Window
                    </button>
                    <a href="/login" class="btn btn-primary">
                        <i class="bi bi-box-arrow-in-right"></i> Login to My Account
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
