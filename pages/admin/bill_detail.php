<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';
require_once __DIR__ . '/../../includes/FinanceApproval.php';
require_once __DIR__ . '/../../includes/InstallmentPlan.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if (!$auth->isLoggedIn() || (!$auth->isAdmin() && !$auth->hasPermission('view_bill_detail'))) {
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

if (empty($_SESSION['bill_detail_csrf'])) {
    $_SESSION['bill_detail_csrf'] = bin2hex(random_bytes(32));
}

$flashMessage = '';
$flashType = 'success';
if (!empty($_SESSION['bill_detail_flash']) && is_array($_SESSION['bill_detail_flash'])) {
    $flashMessage = (string)($_SESSION['bill_detail_flash']['message'] ?? '');
    $flashType = (string)($_SESSION['bill_detail_flash']['type'] ?? 'success');
    unset($_SESSION['bill_detail_flash']);
}

$financeApproval = new FinanceApproval($db);
$installmentService = new InstallmentPlan($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['bill_detail_csrf'], $token)) {
        $_SESSION['bill_detail_flash'] = [
            'message' => 'Security validation failed. Please try again.',
            'type' => 'danger',
        ];
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        $submittedBy = (int)($_SESSION['user_id'] ?? 0);
        $result = false;

        if (in_array($action, ['request_writeoff', 'request_waiver'], true)) {
            $amount = (float)($_POST['request_amount'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            $result = $financeApproval->createBillWriteOffRequest(
                $billId,
                $amount,
                $submittedBy,
                $reason,
                $action === 'request_waiver' ? 'waiver' : 'writeoff'
            );
            $_SESSION['bill_detail_flash'] = [
                'message' => $result ? 'Approval request submitted successfully.' : 'Could not submit approval request. Ensure amount is valid and bill has outstanding balance.',
                'type' => $result ? 'success' : 'danger',
            ];
        } elseif ($action === 'request_installment') {
            $amount = (float)($_POST['plan_amount'] ?? 0);
            $count = (int)($_POST['installment_count'] ?? 3);
            $frequency = trim((string)($_POST['frequency'] ?? 'monthly'));
            $startDate = trim((string)($_POST['start_date'] ?? date('Y-m-d')));
            $reason = trim((string)($_POST['plan_reason'] ?? ''));

            $result = $financeApproval->createInstallmentPlanRequest(
                $billId,
                $submittedBy,
                $amount,
                $count,
                $frequency,
                $startDate,
                $reason
            );
            $_SESSION['bill_detail_flash'] = [
                'message' => $result ? 'Installment approval request submitted successfully.' : 'Could not submit installment request. Check amount, dates, and outstanding balance.',
                'type' => $result ? 'success' : 'danger',
            ];
        }
    }

    $redirect = '/admin/bill-detail?bill_id=' . $billId;
    header('Location: ' . $redirect);
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
$latestInstallmentPlan = $installmentService->getLatestPlanByBillId($billId, ['active', 'completed']);
$installmentAllocationLedger = [];
if ($latestInstallmentPlan) {
    $installmentAllocationLedger = $installmentService->getAllocationLedgerByPlanId((int)$latestInstallmentPlan['id']);
}

$is_admin_page = true;
$page_title = 'Bill Detail';
require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container-fluid mt-4 admin-shell">
    <?php if ($flashMessage !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($flashMessage); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="pb-banner pb-banner--amber mb-4">
        <div class="pb-bg" aria-hidden="true">
            <div class="pb-grid"></div>
            <div class="pb-blob pb-blob--a"></div>
            <div class="pb-blob pb-blob--b"></div>
            <i class="bi bi-file-text-fill pb-watermark"></i>
        </div>
        <div class="pb-inner">
            <div class="pb-left">
                <div class="pb-eyebrow-row">
                    <span class="pb-eyebrow-chip"><i class="bi bi-file-text-fill"></i> Billing Desk</span>
                </div>
                <h2 class="pb-title">Bill Detail #<?php echo (int)$billId; ?></h2>
                <p class="pb-subtitle">Detailed bill composition, payments, and adjustments.</p>
            </div>
            <div class="pb-right">
                <div class="pb-btn-row">
                    <a href="/admin/payments" class="pb-btn"><i class="bi bi-arrow-left"></i> Back to Payments</a>
                    <a href="/invoice?bill_id=<?php echo (int)$billId; ?>" class="pb-btn" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i> Download Invoice</a>
                    <a href="/admin/accounting?entry_id=<?php echo (int)$billId; ?>" class="pb-btn pb-btn--accent"><i class="bi bi-journal-text"></i> Accounting</a>
                </div>
            </div>
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

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h5 class="mb-0">Write-off / Waiver Request</h5></div>
                <div class="card-body">
                    <?php if ($outstanding <= 0): ?>
                        <p class="text-success mb-0">This bill has no outstanding amount.</p>
                    <?php else: ?>
                        <form method="post" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['bill_detail_csrf']); ?>">
                            <div class="col-md-6">
                                <label class="form-label">Request Amount (<?php echo htmlspecialchars($currency); ?>)</label>
                                <input type="number" name="request_amount" class="form-control" min="0.01" max="<?php echo htmlspecialchars((string)number_format($outstanding, 2, '.', '')); ?>" step="0.01" value="<?php echo htmlspecialchars((string)number_format($outstanding, 2, '.', '')); ?>" required>
                                <small class="text-muted">Outstanding: <?php echo htmlspecialchars($currency); ?> <?php echo number_format($outstanding, 2); ?></small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Reason</label>
                                <input type="text" name="reason" class="form-control" maxlength="255" placeholder="Brief justification">
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button type="submit" name="action" value="request_writeoff" class="btn btn-outline-danger btn-sm" data-confirm-message="Submit write-off request for approval?">Request Write-off</button>
                                <button type="submit" name="action" value="request_waiver" class="btn btn-outline-warning btn-sm" data-confirm-message="Submit waiver request for approval?">Request Waiver</button>
                                <a href="/admin/approvals?status=pending#finance-items" class="btn btn-outline-secondary btn-sm">Open Approvals</a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h5 class="mb-0">Installment Plan Request</h5></div>
                <div class="card-body">
                    <?php if ($outstanding <= 0): ?>
                        <p class="text-success mb-0">No installment plan is needed for a settled bill.</p>
                    <?php else: ?>
                        <form method="post" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['bill_detail_csrf']); ?>">
                            <div class="col-md-4">
                                <label class="form-label">Amount</label>
                                <input type="number" name="plan_amount" class="form-control" min="0.01" max="<?php echo htmlspecialchars((string)number_format($outstanding, 2, '.', '')); ?>" step="0.01" value="<?php echo htmlspecialchars((string)number_format($outstanding, 2, '.', '')); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Installments</label>
                                <input type="number" name="installment_count" class="form-control" min="2" max="36" value="3" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Frequency</label>
                                <select name="frequency" class="form-select">
                                    <option value="monthly" selected>Monthly</option>
                                    <option value="weekly">Weekly</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Reason</label>
                                <input type="text" name="plan_reason" class="form-control" maxlength="255" placeholder="Installment rationale">
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button type="submit" name="action" value="request_installment" class="btn btn-outline-primary btn-sm" data-confirm-message="Submit installment plan request for approval?">Request Installment Plan</button>
                                <a href="/admin/approvals?status=pending#finance-items" class="btn btn-outline-secondary btn-sm">Open Approvals</a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($latestInstallmentPlan): ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Installment Plan #<?php echo (int)$latestInstallmentPlan['id']; ?></h5>
                <div class="d-flex gap-2 align-items-center">
                    <span class="badge bg-info text-dark"><?php echo htmlspecialchars(ucfirst((string)$latestInstallmentPlan['frequency'])); ?></span>
                    <?php $planStatus = (string)($latestInstallmentPlan['status'] ?? 'active'); ?>
                    <span class="badge bg-<?php echo $planStatus === 'completed' ? 'success' : 'primary'; ?>"><?php echo htmlspecialchars(ucfirst($planStatus)); ?></span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Due Date</th>
                                <th class="text-end">Installment</th>
                                <th class="text-end">Allocated</th>
                                <th class="text-end">Balance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (($latestInstallmentPlan['items'] ?? []) as $planItem): ?>
                                <tr>
                                    <td><?php echo (int)$planItem['sequence_no']; ?></td>
                                    <td><?php echo htmlspecialchars(date('d-m-Y', strtotime((string)$planItem['due_date']))); ?></td>
                                    <td class="text-end"><?php echo number_format((float)$planItem['due_amount'], 2); ?></td>
                                    <td class="text-end"><?php echo number_format((float)($planItem['allocated_amount'] ?? 0), 2); ?></td>
                                    <td class="text-end"><?php echo number_format((float)($planItem['balance_amount'] ?? 0), 2); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst((string)($planItem['item_status'] ?? 'pending'))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($latestInstallmentPlan): ?>
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Installment Allocation Audit</h5></div>
            <div class="card-body p-0">
                <?php if (empty($installmentAllocationLedger)): ?>
                    <p class="text-muted p-3 mb-0">No payment allocations have been posted for this plan yet.</p>
                <?php else: ?>
                    <?php
                        $paymentTotals = [];
                        $itemTotals = [];
                        foreach ($installmentAllocationLedger as $allocRow) {
                            $paymentKey = (int)($allocRow['payment_id'] ?? 0);
                            $itemKey = (int)($allocRow['plan_item_id'] ?? 0);
                            $allocAmount = (float)($allocRow['allocated_amount'] ?? 0);
                            if (!isset($paymentTotals[$paymentKey])) {
                                $paymentTotals[$paymentKey] = 0.0;
                            }
                            if (!isset($itemTotals[$itemKey])) {
                                $itemTotals[$itemKey] = 0.0;
                            }
                            $paymentTotals[$paymentKey] += $allocAmount;
                            $itemTotals[$itemKey] += $allocAmount;
                        }
                    ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Payment</th>
                                    <th>Receipt</th>
                                    <th>Item #</th>
                                    <th>Due Date</th>
                                    <th class="text-end">Allocated</th>
                                    <th class="text-end">Payment Total Allocated</th>
                                    <th class="text-end">Item Total Allocated</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($installmentAllocationLedger as $row): ?>
                                <?php
                                    $payId = (int)($row['payment_id'] ?? 0);
                                    $itemId = (int)($row['plan_item_id'] ?? 0);
                                ?>
                                <tr>
                                    <td>#<?php echo $payId; ?> <span class="text-muted">(<?php echo htmlspecialchars(date('d-m-Y H:i', strtotime((string)($row['payment_date'] ?? 'now')))); ?>)</span></td>
                                    <td><?php echo htmlspecialchars((string)($row['mpesa_receipt'] ?? '-')); ?></td>
                                    <td><?php echo (int)($row['sequence_no'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars(date('d-m-Y', strtotime((string)($row['due_date'] ?? 'now')))); ?></td>
                                    <td class="text-end"><?php echo number_format((float)($row['allocated_amount'] ?? 0), 2); ?></td>
                                    <td class="text-end"><?php echo number_format((float)($paymentTotals[$payId] ?? 0), 2); ?></td>
                                    <td class="text-end"><?php echo number_format((float)($itemTotals[$itemId] ?? 0), 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

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
