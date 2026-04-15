<?php
session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';

$database = new Database();
$db = $database->getConnection();

$auth = new Auth($db);
if (!$auth->isLoggedIn() || !($auth->isAdmin() || $auth->hasRole('support'))) {
    header('Location: /login');
    exit;
}

$page_title = 'Support Inquiries';
$is_admin_page = true;
$isAdminUser = $auth->isAdmin();

$flashMessage = '';
$flashType = 'success';

if (!empty($_SESSION['support_inquiries_flash']) && is_array($_SESSION['support_inquiries_flash'])) {
    $flashMessage = (string)($_SESSION['support_inquiries_flash']['message'] ?? '');
    $flashType = (string)($_SESSION['support_inquiries_flash']['type'] ?? 'success');
    unset($_SESSION['support_inquiries_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {
    $action = (string)($_POST['action'] ?? '');
    $inquiryId = isset($_POST['inquiry_id']) ? (int)$_POST['inquiry_id'] : 0;

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS support_inquiries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(60) DEFAULT NULL,
            message TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            email_sent TINYINT(1) NOT NULL DEFAULT 0,
            email_error TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_support_inquiries_status (status),
            INDEX idx_support_inquiries_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        $_SESSION['support_inquiries_flash'] = [
            'message' => 'Could not prepare support inquiries storage.',
            'type' => 'danger',
        ];
        header('Location: /admin/support-inquiries');
        exit;
    }

    if ($inquiryId > 0 && in_array($action, ['mark_handled', 'reopen'], true)) {
        $nextStatus = $action === 'mark_handled' ? 'handled' : 'queued';
        $stmt = $db->prepare('UPDATE support_inquiries SET status = :status WHERE id = :id');
        $stmt->execute([
            ':status' => $nextStatus,
            ':id' => $inquiryId,
        ]);

        $_SESSION['support_inquiries_flash'] = [
            'message' => $action === 'mark_handled' ? 'Inquiry marked as handled.' : 'Inquiry reopened.',
            'type' => 'success',
        ];
        header('Location: /admin/support-inquiries');
        exit;
    }

    if ($isAdminUser && $inquiryId > 0 && $action === 'delete') {
        $stmt = $db->prepare('DELETE FROM support_inquiries WHERE id = :id');
        $stmt->execute([':id' => $inquiryId]);

        $_SESSION['support_inquiries_flash'] = [
            'message' => 'Inquiry deleted.',
            'type' => 'success',
        ];
        header('Location: /admin/support-inquiries');
        exit;
    }
}

$statusFilter = trim((string)($_GET['status'] ?? 'open'));
$allowedStatuses = ['open', 'handled', 'all'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'open';
}

$inquiries = [];
$summary = [
    'open' => 0,
    'handled' => 0,
    'all' => 0,
];

if ($db) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS support_inquiries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(60) DEFAULT NULL,
            message TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            email_sent TINYINT(1) NOT NULL DEFAULT 0,
            email_error TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_support_inquiries_status (status),
            INDEX idx_support_inquiries_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $summaryQuery = $db->query("SELECT
            SUM(CASE WHEN status IN ('pending', 'queued', 'sent') THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN status = 'handled' THEN 1 ELSE 0 END) AS handled_count,
            COUNT(*) AS total_count
            FROM support_inquiries");
        $summaryRow = $summaryQuery ? ($summaryQuery->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $summary['open'] = (int)($summaryRow['open_count'] ?? 0);
        $summary['handled'] = (int)($summaryRow['handled_count'] ?? 0);
        $summary['all'] = (int)($summaryRow['total_count'] ?? 0);

        $sql = 'SELECT * FROM support_inquiries';
        if ($statusFilter === 'open') {
            $sql .= " WHERE status IN ('pending', 'queued', 'sent')";
        } elseif ($statusFilter === 'handled') {
            $sql .= " WHERE status = 'handled'";
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 200';

        $stmt = $db->query($sql);
        $inquiries = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        $flashMessage = 'Could not load support inquiries.';
        $flashType = 'danger';
    }
}

require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container-fluid mt-4 admin-shell">
    <div class="row mb-3">
        <div class="col-12 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h2 class="mb-1"><i class="bi bi-inbox"></i> Support Inquiries</h2>
                <p class="text-muted mb-0">Offline visitor inquiries are stored here for follow-up.</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a class="btn btn-outline-primary btn-sm <?php echo $statusFilter === 'open' ? 'active' : ''; ?>" href="/admin/support-inquiries?status=open">Open (<?php echo (int)$summary['open']; ?>)</a>
                <a class="btn btn-outline-secondary btn-sm <?php echo $statusFilter === 'handled' ? 'active' : ''; ?>" href="/admin/support-inquiries?status=handled">Handled (<?php echo (int)$summary['handled']; ?>)</a>
                <a class="btn btn-outline-dark btn-sm <?php echo $statusFilter === 'all' ? 'active' : ''; ?>" href="/admin/support-inquiries?status=all">All (<?php echo (int)$summary['all']; ?>)</a>
            </div>
        </div>
    </div>

    <?php if ($flashMessage !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>">
            <?php echo htmlspecialchars($flashMessage); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($inquiries)): ?>
                <div class="p-4 text-muted">No support inquiries found for this filter.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Visitor</th>
                                <th>Message</th>
                                <th>Status</th>
                                <th>Received</th>
                                <th>Email</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inquiries as $inquiry): ?>
                                <?php
                                    $status = (string)($inquiry['status'] ?? 'pending');
                                    $isHandled = $status === 'handled';
                                    $badgeClass = $isHandled ? 'success' : 'warning';
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars((string)$inquiry['name']); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars((string)($inquiry['phone'] ?: 'No phone provided')); ?></div>
                                    </td>
                                    <td style="min-width:280px; white-space:pre-wrap;"><?php echo htmlspecialchars((string)$inquiry['message']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $badgeClass; ?>">
                                            <?php echo htmlspecialchars(ucfirst($status)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars((string)$inquiry['created_at']); ?></div>
                                        <div class="text-muted small">Updated: <?php echo htmlspecialchars((string)$inquiry['updated_at']); ?></div>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars((string)$inquiry['email']); ?></div>
                                        <div class="text-muted small">
                                            <?php echo !empty($inquiry['email_sent']) ? 'Notification sent' : 'Stored in inbox'; ?>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-2 flex-wrap">
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                                <?php if ($isHandled): ?>
                                                    <input type="hidden" name="action" value="reopen">
                                                    <button type="submit" class="btn btn-outline-secondary btn-sm">Reopen</button>
                                                <?php else: ?>
                                                    <input type="hidden" name="action" value="mark_handled">
                                                    <button type="submit" class="btn btn-outline-success btn-sm">Mark handled</button>
                                                <?php endif; ?>
                                            </form>
                                            <?php if ($isAdminUser): ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this inquiry?');">
                                                    <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>