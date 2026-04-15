<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if (!$auth->isLoggedIn() || !$auth->hasRole(['admin', 'finance', 'support'])) {
    header('Location: /login');
    exit;
}

$billId = (int)($_GET['bill_id'] ?? 0);
if ($billId <= 0) {
    header('Location: /admin/payments');
    exit;
}

$billService = new Bill($db);
$bill = $billService->getById($billId, null);
if (!$bill) {
    header('Location: /admin/payments');
    exit;
}

$settingsService = new BillingSettings($db);
$settings = $settingsService->getSettings();
$currency = (string)($settings['currency_code'] ?? 'KES');

$lineItems = $billService->getBillLineItems((int)$billId);

$user = null;
$stmtUser = $db->prepare('SELECT id, account_number, full_name, phone_number, email, meter_number, connection_type FROM users WHERE id = :id LIMIT 1');
$stmtUser->bindValue(':id', (int)($bill['user_id'] ?? 0), PDO::PARAM_INT);
$stmtUser->execute();
$user = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: null;

$stmtPayments = $db->prepare("SELECT id, mpesa_receipt, amount, status, phone_number, result_code, result_desc, COALESCE(transaction_date, created_at) AS paid_at
    FROM payments
    WHERE bill_id = :bill_id
    ORDER BY id DESC");
$stmtPayments->bindValue(':bill_id', $billId, PDO::PARAM_INT);
$stmtPayments->execute();
$payments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stmtCredits = $db->prepare("SELECT id, units_credited, amount_credited, type, created_by, created_at, note
    FROM credit_notes
    WHERE bill_id = :bill_id
    ORDER BY id DESC");
$stmtCredits->bindValue(':bill_id', $billId, PDO::PARAM_INT);
$stmtCredits->execute();
$creditNotes = $stmtCredits->fetchAll(PDO::FETCH_ASSOC) ?: [];

$baseAmount = isset($bill['base_amount']) ? (float)$bill['base_amount'] : (float)($bill['amount'] ?? 0);
$taxRate = isset($bill['tax_rate']) ? (float)$bill['tax_rate'] : 0.0;
$taxAmount = isset($bill['tax_amount']) ? (float)$bill['tax_amount'] : 0.0;
$totalAmount = (float)($bill['amount'] ?? 0);

$totalPaid = 0.0;
foreach ($payments as $payment) {
    if (($payment['status'] ?? '') === 'completed') {
        $totalPaid += (float)($payment['amount'] ?? 0);
    }
}
$outstanding = max(0, $totalAmount - $totalPaid);

$is_admin_page = true;
$page_title = 'Bill Detail';
require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container-fluid mt-4 admin-shell">
    <div class="admin-page-header mb-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h2 class="mb-1">Bill Detail #<?php echo (int)$billId; ?></h2>
            <p class="admin-page-subtitle mb-0">Detailed bill composition, payments, and adjustments.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="/admin/payments" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Payments</a>
            <a href="/admin/accounting?entry_id=<?php echo (int)$billId; ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-journal-text me-1"></i>Accounting</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header"><h5 class="mb-0">Customer</h5></div>
                <div class="card-body">
                    <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars((string)($user['full_name'] ?? '-')); ?></p>
                    <p class="mb-1"><strong>Account:</strong> <?php echo htmlspecialchars((string)($user['account_number'] ?? '-')); ?></p>
                    <p class="mb-1"><strong>Meter:</strong> <?php echo htmlspecialchars((string)($user['meter_number'] ?? '-')); ?></p>
                    <p class="mb-1"><strong>Phone:</strong> <?php echo htmlspecialchars((string)($user['phone_number'] ?? '-')); ?></p>
                    <p class="mb-1"><strong>Email:</strong> <?php echo htmlspecialchars((string)($user['email'] ?? '-')); ?></p>
                    <p class="mb-0"><strong>Category:</strong> <?php echo htmlspecialchars(ucfirst((string)($user['connection_type'] ?? '-'))); ?></p>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header"><h5 class="mb-0">Bill Summary</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3"><div class="small text-muted">Billing Month</div><div class="fw-semibold"><?php echo htmlspecialchars(date('M Y', strtotime((string)$bill['billing_month']))); ?></div></div>
                        <div class="col-md-3"><div class="small text-muted">Due Date</div><div class="fw-semibold"><?php echo htmlspecialchars(date('d-m-Y', strtotime((string)$bill['due_date']))); ?></div></div>
                        <div class="col-md-3"><div class="small text-muted">Status</div><div class="fw-semibold"><?php echo htmlspecialchars(ucfirst((string)$bill['status'])); ?></div></div>
                        <div class="col-md-3"><div class="small text-muted">Consumption</div><div class="fw-semibold"><?php echo number_format((float)($bill['consumption'] ?? 0), 2); ?> m3</div></div>
                        <div class="col-md-3"><div class="small text-muted">Base</div><div class="fw-semibold"><?php echo htmlspecialchars($currency); ?> <?php echo number_format($baseAmount, 2); ?></div></div>
                        <div class="col-md-3"><div class="small text-muted">Tax (<?php echo number_format($taxRate, 2); ?>%)</div><div class="fw-semibold"><?php echo htmlspecialchars($currency); ?> <?php echo number_format($taxAmount, 2); ?></div></div>
                        <div class="col-md-3"><div class="small text-muted">Total</div><div class="fw-semibold"><?php echo htmlspecialchars($currency); ?> <?php echo number_format($totalAmount, 2); ?></div></div>
                        <div class="col-md-3"><div class="small text-muted">Outstanding</div><div class="fw-semibold <?php echo $outstanding > 0 ? 'text-danger' : 'text-success'; ?>"><?php echo htmlspecialchars($currency); ?> <?php echo number_format($outstanding, 2); ?></div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Bill Line Items</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Description</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($lineItems)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">No line items recorded for this bill.</td></tr>
                    <?php else: ?>
                        <?php foreach ($lineItems as $line): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($line['line_type'] ?? '')))); ?></td>
                                <td><?php echo htmlspecialchars((string)($line['description'] ?? '')); ?></td>
                                <td class="text-end"><?php echo number_format((float)($line['quantity'] ?? 0), 2); ?></td>
                                <td class="text-end"><?php echo number_format((float)($line['unit_rate'] ?? 0), 4); ?></td>
                                <td class="text-end"><?php echo number_format((float)($line['line_amount'] ?? 0), 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><h5 class="mb-0">Payments</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Date</th>
                                    <th>Receipt</th>
                                    <th>Status</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($payments)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">No payments recorded.</td></tr>
                            <?php else: ?>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td>#<?php echo (int)$payment['id']; ?></td>
                                        <td><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime((string)($payment['paid_at'] ?? 'now')))); ?></td>
                                        <td><?php echo htmlspecialchars((string)($payment['mpesa_receipt'] ?? '-')); ?></td>
                                        <td><?php echo htmlspecialchars(ucfirst((string)($payment['status'] ?? '-'))); ?></td>
                                        <td class="text-end"><?php echo number_format((float)($payment['amount'] ?? 0), 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><h5 class="mb-0">Credit Notes</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Type</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($creditNotes)): ?>
                                <tr><td colspan="3" class="text-center text-muted py-3">No credit notes recorded.</td></tr>
                            <?php else: ?>
                                <?php foreach ($creditNotes as $cn): ?>
                                    <tr>
                                        <td>#<?php echo (int)$cn['id']; ?></td>
                                        <td><?php echo htmlspecialchars(ucfirst((string)($cn['type'] ?? ''))); ?></td>
                                        <td class="text-end"><?php echo number_format((float)($cn['amount_credited'] ?? 0), 2); ?></td>
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

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
