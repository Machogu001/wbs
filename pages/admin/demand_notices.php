<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/DemandNotice.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
if (!$auth->isLoggedIn() || !$auth->hasRole(['admin', 'finance'])) {
    header('Location: /login');
    exit;
}

if (empty($_SESSION['demand_notices_csrf'])) {
    $_SESSION['demand_notices_csrf'] = bin2hex(random_bytes(32));
}

$service = new DemandNotice($db);
$message = '';
$messageType = 'success';

if (!empty($_SESSION['demand_notices_flash']) && is_array($_SESSION['demand_notices_flash'])) {
    $message = (string)($_SESSION['demand_notices_flash']['message'] ?? '');
    $messageType = (string)($_SESSION['demand_notices_flash']['type'] ?? 'success');
    unset($_SESSION['demand_notices_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['demand_notices_csrf'], $token)) {
        $message = 'Security validation failed. Please refresh and try again.';
        $messageType = 'danger';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'generate') {
            $count = $service->generateForOverdueBills(200);
            $message = $count . ' demand notice(s) generated.';
        } elseif ($action === 'mark_status') {
            $noticeId = (int)($_POST['notice_id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));
            if ($noticeId > 0 && $service->updateStatus($noticeId, $status, $note)) {
                $message = 'Demand notice updated.';
            } else {
                $message = 'Could not update demand notice.';
                $messageType = 'danger';
            }
        } else {
            $message = 'Invalid demand notice action.';
            $messageType = 'warning';
        }
    }

    $_SESSION['demand_notices_flash'] = [
        'message' => $message,
        'type' => $messageType,
    ];

    $redirectUrl = (string)($_SERVER['REQUEST_URI'] ?? '/admin/demand-notices');
    if ($redirectUrl === '') {
        $redirectUrl = '/admin/demand-notices';
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$summary = $service->getSummary();
$selectedStatus = trim((string)($_GET['status'] ?? ''));
$allowedStatuses = ['draft', 'sent', 'acknowledged', 'resolved', 'cancelled'];
if (!in_array($selectedStatus, $allowedStatuses, true)) {
    $selectedStatus = '';
}

$notices = $service->getRecent(200, $selectedStatus);

$page_title = 'Demand Notices';
$is_admin_page = true;
include __DIR__ . '/../../templates/header.php';
?>
<div class="container-fluid mt-4 admin-shell">
    <div class="admin-page-header mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h2 class="mb-1">Demand Notices</h2>
            <p class="admin-page-subtitle">Generate and track overdue customer demand notices.</p>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="generate">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['demand_notices_csrf']); ?>">
            <button type="submit" class="btn btn-primary" data-confirm-message="Generate demand notices for all currently overdue bills?">Generate Overdue Notices</button>
        </form>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <a href="/admin/demand-notices?status=draft" class="text-decoration-none">
                <div class="card admin-kpi-card <?php echo $selectedStatus === 'draft' ? 'border border-dark border-3' : ''; ?>">
                    <div class="card-body"><h5>Draft</h5><h3><?php echo (int)$summary['draft']; ?></h3></div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="/admin/demand-notices?status=sent" class="text-decoration-none">
                <div class="card admin-kpi-card bg-warning text-dark <?php echo $selectedStatus === 'sent' ? 'border border-dark border-3' : ''; ?>">
                    <div class="card-body"><h5>Sent</h5><h3><?php echo (int)$summary['sent']; ?></h3></div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="/admin/demand-notices?status=resolved" class="text-decoration-none">
                <div class="card admin-kpi-card bg-success text-white <?php echo $selectedStatus === 'resolved' ? 'border border-light border-3' : ''; ?>">
                    <div class="card-body"><h5>Resolved</h5><h3><?php echo (int)$summary['resolved']; ?></h3></div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="/admin/demand-notices?status=acknowledged" class="text-decoration-none">
                <div class="card admin-kpi-card bg-secondary text-white <?php echo $selectedStatus === 'acknowledged' ? 'border border-light border-3' : ''; ?>">
                    <div class="card-body"><h5>Acknowledged</h5><h3><?php echo (int)$summary['acknowledged']; ?></h3></div>
                </div>
            </a>
        </div>
    </div>

    <div class="card admin-table-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Recent Demand Notices</h5>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-secondary"><?php echo $selectedStatus !== '' ? htmlspecialchars(ucfirst($selectedStatus)) : 'All'; ?></span>
                <?php if ($selectedStatus !== ''): ?>
                    <a href="/admin/demand-notices" class="btn btn-sm btn-outline-secondary">Clear Filter</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-sm mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Notice No</th>
                            <th>Customer</th>
                            <th>Account</th>
                            <th>Amount Due</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Generated</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($notices)): ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">No demand notices yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($notices as $notice): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($notice['notice_number']); ?></td>
                                    <td><?php echo htmlspecialchars($notice['full_name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($notice['account_number'] ?? ''); ?></td>
                                    <td>KES <?php echo number_format((float)($notice['amount_due'] ?? 0), 2); ?></td>
                                    <td>KES <?php echo number_format((float)($notice['balance_due'] ?? 0), 2); ?></td>
                                    <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars(ucfirst((string)$notice['status'])); ?></span></td>
                                    <td><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime((string)$notice['generated_at']))); ?></td>
                                    <td>
                                        <form method="post" class="d-flex gap-2 flex-wrap">
                                            <input type="hidden" name="action" value="mark_status">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['demand_notices_csrf']); ?>">
                                            <input type="hidden" name="notice_id" value="<?php echo (int)$notice['id']; ?>">
                                            <select name="status" class="form-select form-select-sm" style="min-width: 120px;">
                                                <option value="draft">Draft</option>
                                                <option value="sent">Sent</option>
                                                <option value="acknowledged">Acknowledged</option>
                                                <option value="resolved">Resolved</option>
                                                <option value="cancelled">Cancelled</option>
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-outline-primary" data-confirm-template="Update this demand notice to {status}?">Update</button>
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
