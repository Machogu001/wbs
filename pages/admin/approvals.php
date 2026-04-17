<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/ApprovalWorkflow.php';
require_once __DIR__ . '/../../includes/FinanceApproval.php';
require_once __DIR__ . '/../../includes/InstallmentPlan.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
if (!$auth->isLoggedIn() || !$auth->hasRole(['admin', 'finance'])) {
    header('Location: /login');
    exit;
}

if (empty($_SESSION['approvals_csrf'])) {
    $_SESSION['approvals_csrf'] = bin2hex(random_bytes(32));
}

ApprovalWorkflow::ensureTables($db);
$finance = new FinanceApproval($db);
$installments = new InstallmentPlan($db);
$message = '';
$messageType = 'success';

if (!empty($_SESSION['approvals_flash']) && is_array($_SESSION['approvals_flash'])) {
    $message = (string)($_SESSION['approvals_flash']['message'] ?? '');
    $messageType = (string)($_SESSION['approvals_flash']['type'] ?? 'success');
    unset($_SESSION['approvals_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['approvals_csrf'], $token)) {
        $message = 'Security validation failed. Please refresh and try again.';
        $messageType = 'danger';
    } else {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $action = trim((string)($_POST['action'] ?? ''));
        if ($itemId > 0 && in_array($action, ['approve', 'reject'], true)) {
            if ($finance->decideItem($itemId, $action, (int)($_SESSION['user_id'] ?? 0), trim((string)($_POST['comments'] ?? '')))) {
                $message = 'Approval item updated.';
            } else {
                $message = 'Could not update approval item.';
                $messageType = 'danger';
            }
        } else {
            $message = 'Invalid approval action.';
            $messageType = 'warning';
        }
    }

    $_SESSION['approvals_flash'] = [
        'message' => $message,
        'type' => $messageType,
    ];

    $redirectUrl = (string)($_SERVER['REQUEST_URI'] ?? '/admin/approvals');
    if ($redirectUrl === '') {
        $redirectUrl = '/admin/approvals';
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$financeSummary = $finance->getSummary();
$selectedStatus = trim((string)($_GET['status'] ?? ''));
$allowedStatuses = ['pending', 'approved', 'rejected'];
if (!in_array($selectedStatus, $allowedStatuses, true)) {
    $selectedStatus = '';
}

$financeItems = $finance->getItems($selectedStatus, 200);
$financeItemsByBillId = [];
foreach ($financeItems as $item) {
    $type = (string)($item['entity_type'] ?? '');
    if (!in_array($type, ['bill_writeoff', 'bill_waiver', 'bill_installment', 'payment_refund', 'payment_chargeback'], true)) {
        continue;
    }
    $metadata = [];
    if (!empty($item['metadata_json']) && is_string($item['metadata_json'])) {
        $decoded = json_decode($item['metadata_json'], true);
        if (is_array($decoded)) {
            $metadata = $decoded;
        }
    }
    $billId = (int)($metadata['bill_id'] ?? $item['entity_id'] ?? 0);
    if ($billId <= 0) {
        continue;
    }
    $financeItemsByBillId[$billId] = true;
}

$billInstallmentPlans = [];
foreach (array_keys($financeItemsByBillId) as $billId) {
    $plan = $installments->getLatestPlanByBillId((int)$billId, ['active', 'completed']);
    if ($plan) {
        $billInstallmentPlans[(int)$billId] = $plan;
    }
}

$meterWorkflows = $db->query("SELECT aw.*, mr.account_number, mr.meter_number
    FROM approval_workflows aw
    LEFT JOIN meter_readings mr ON mr.id = aw.meter_reading_id
    ORDER BY aw.created_at DESC
    LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$page_title = 'Approvals';
$is_admin_page = true;
include __DIR__ . '/../../templates/header.php';
?>
<div class="container-fluid mt-4 admin-shell">
    <div class="pb-banner pb-banner--cobalt mb-4">
        <div class="pb-bg" aria-hidden="true">
            <div class="pb-grid"></div>
            <div class="pb-blob pb-blob--a"></div>
            <div class="pb-blob pb-blob--b"></div>
            <i class="bi bi-check2-circle pb-watermark"></i>
        </div>
        <div class="pb-inner">
            <div class="pb-left">
                <div class="pb-eyebrow-row">
                    <span class="pb-eyebrow-chip"><i class="bi bi-check2-circle"></i> Workflow Approvals</span>
                </div>
                <h2 class="pb-title">Approvals Dashboard</h2>
                <p class="pb-subtitle">Track financial approval items and existing workflow records.</p>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <a href="/admin/approvals?status=pending#finance-items" class="text-decoration-none">
                <div class="card admin-kpi-card bg-warning text-dark <?php echo $selectedStatus === 'pending' ? 'border border-dark border-3' : ''; ?>">
                    <div class="card-body"><h5>Pending Finance</h5><h3><?php echo (int)$financeSummary['pending']; ?></h3></div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="/admin/approvals?status=approved#finance-items" class="text-decoration-none">
                <div class="card admin-kpi-card bg-success text-white <?php echo $selectedStatus === 'approved' ? 'border border-light border-3' : ''; ?>">
                    <div class="card-body"><h5>Approved</h5><h3><?php echo (int)$financeSummary['approved']; ?></h3></div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="/admin/approvals?status=rejected#finance-items" class="text-decoration-none">
                <div class="card admin-kpi-card bg-danger text-white <?php echo $selectedStatus === 'rejected' ? 'border border-light border-3' : ''; ?>">
                    <div class="card-body"><h5>Rejected</h5><h3><?php echo (int)$financeSummary['rejected']; ?></h3></div>
                </div>
            </a>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-7">
            <div class="card admin-table-card" id="finance-items">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Finance Approval Items</h5>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-secondary"><?php echo $selectedStatus !== '' ? htmlspecialchars(ucfirst($selectedStatus)) : 'All'; ?></span>
                        <?php if ($selectedStatus !== ''): ?>
                            <a href="/admin/approvals#finance-items" class="btn btn-sm btn-outline-secondary">Clear Filter</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-sm mb-0 align-middle">
                            <thead><tr><th>Reference</th><th>Title</th><th>Amount</th><th>Status</th><th>Submitted By</th><th>Action</th></tr></thead>
                            <tbody>
                                <?php if (empty($financeItems)): ?>
                                    <tr><td colspan="6" class="text-center py-4 text-muted">No finance approval items.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($financeItems as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['reference_no'] ?? ('#' . (int)$item['entity_id'])); ?></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($item['title']); ?></div>
                                                <?php
                                                    $metadata = [];
                                                    if (!empty($item['metadata_json']) && is_string($item['metadata_json'])) {
                                                        $decoded = json_decode($item['metadata_json'], true);
                                                        if (is_array($decoded)) {
                                                            $metadata = $decoded;
                                                        }
                                                    }
                                                    $entityType = (string)($item['entity_type'] ?? '');
                                                    $billId = (int)($item['entity_id'] ?? 0);
                                                ?>
                                                <?php if ($billId > 0 && in_array($entityType, ['bill_writeoff', 'bill_waiver', 'bill_installment', 'payment_refund', 'payment_chargeback'], true)): ?>
                                                    <div class="small text-muted">
                                                        <a href="/admin/bill-detail?bill_id=<?php echo $billId; ?>">Bill #<?php echo $billId; ?></a>
                                                        <?php if (!empty($metadata['payment_id'])): ?>
                                                            | Payment #<?php echo (int)$metadata['payment_id']; ?>
                                                        <?php endif; ?>
                                                        <?php if (!empty($metadata['reason'])): ?>
                                                            | Reason: <?php echo htmlspecialchars((string)$metadata['reason']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($entityType === 'bill_installment' && !empty($metadata)): ?>
                                                    <div class="small text-muted">
                                                        Plan: <?php echo (int)($metadata['installment_count'] ?? 0); ?> installments, <?php echo htmlspecialchars((string)($metadata['frequency'] ?? 'monthly')); ?>, start <?php echo htmlspecialchars((string)($metadata['start_date'] ?? '-')); ?>
                                                    </div>
                                                    <?php if (($item['status'] ?? '') === 'approved' && isset($billInstallmentPlans[$billId])): ?>
                                                        <div class="small text-success">Plan #<?php echo (int)$billInstallmentPlans[$billId]['id']; ?> is <?php echo htmlspecialchars((string)$billInstallmentPlans[$billId]['status']); ?></div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <?php if (in_array($entityType, ['payment_refund', 'payment_chargeback'], true)): ?>
                                                    <div class="small text-muted">
                                                        Type: <?php echo htmlspecialchars($entityType === 'payment_refund' ? 'Refund' : 'Chargeback'); ?>
                                                        <?php if (!empty($metadata['requested_amount'])): ?>
                                                            | Requested: KES <?php echo number_format((float)$metadata['requested_amount'], 2); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>KES <?php echo number_format((float)$item['amount'], 2); ?></td>
                                            <td><span class="badge bg-<?php echo ($item['status'] === 'approved') ? 'success' : (($item['status'] === 'rejected') ? 'danger' : 'warning text-dark'); ?>"><?php echo htmlspecialchars(ucfirst((string)$item['status'])); ?></span></td>
                                            <td><?php echo htmlspecialchars($item['submitted_by_name'] ?? 'System'); ?></td>
                                            <td>
                                                <?php if (($item['status'] ?? '') === 'pending'): ?>
                                                    <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['approvals_csrf']); ?>">
                                                        <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>">
                                                        <input type="text" name="comments" class="form-control form-control-sm" placeholder="Decision note (optional)" style="min-width: 180px;">
                                                        <button type="submit" name="action" value="approve" class="btn btn-sm btn-outline-success" data-confirm-message="Approve this finance approval item?">Approve</button>
                                                        <button type="submit" name="action" value="reject" class="btn btn-sm btn-outline-danger" data-confirm-message="Reject this finance approval item?">Reject</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-muted small">Closed</span>
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
        <div class="col-xl-5">
            <div class="card admin-table-card">
                <div class="card-header"><h5 class="mb-0">Workflow Records</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-sm mb-0 align-middle">
                            <thead><tr><th>Meter</th><th>Account</th><th>Status</th><th>Stage</th></tr></thead>
                            <tbody>
                                <?php if (empty($meterWorkflows)): ?>
                                    <tr><td colspan="4" class="text-center py-4 text-muted">No workflow records found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($meterWorkflows as $workflow): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($workflow['meter_number'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($workflow['account_number'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars((string)$workflow['status']); ?></td>
                                            <td><?php echo (int)($workflow['current_stage'] ?? 0); ?></td>
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
