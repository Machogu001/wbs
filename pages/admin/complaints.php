<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Complaint.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if(!$auth->isLoggedIn() || !$auth->isAdmin()) {
    header("Location: /login");
    exit;
}

$page_title = "Admin - Complaints";
require_once __DIR__ . '/../../templates/header.php';

$message = null;
$message_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {
    $id = (int)($_POST['complaint_id'] ?? 0);
    $status = $_POST['status'] ?? '';

    if ($id > 0 && in_array($status, ['open','in_progress','resolved','closed'], true)) {
        $complaintService = new Complaint($db);
        if ($complaintService->updateStatus($id, $status)) {
            try {
                $logger = new ActivityLog($db);
                $logger->log(
                    $_SESSION['user_id'] ?? null,
                    'update_complaint_status',
                    'complaint',
                    $id,
                    'Updated complaint status to ' . $status,
                    array('status' => $status)
                );
            } catch (Exception $e) {
                // Ignore logging errors
            }
            $_SESSION['flash_message'] = "Complaint updated.";
            $_SESSION['flash_type'] = "success";
            header("Location: /admin/complaints");
            exit;
        } else {
            $message = "Failed to update complaint.";
            $message_type = "danger";
        }
    } else {
        $message = "Invalid request.";
        $message_type = "danger";
    }
}

if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

$complaints = [];
if ($db) {
    $complaintService = new Complaint($db);
    $complaints = $complaintService->listAll();
}
?>

<div class="container mt-4">
    <div class="row">
        <div class="col-md-12">
            <div class="admin-page-header d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Complaints</h2>
                    <p class="text-muted mb-0">Manage user complaints.</p>
                </div>
            </div>
        </div>
    </div>

    <?php if($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> mt-3">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="row mt-3">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Account</th>
                                    <th>Name</th>
                                    <th>Phone</th>
                                    <th>Subject</th>
                                    <th>Status</th>
                                    <th>Submitted</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($complaints)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted">No complaints.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($complaints as $c): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($c['account_number']); ?></td>
                                            <td><?php echo htmlspecialchars($c['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($c['phone_number']); ?></td>
                                            <td><?php echo htmlspecialchars($c['subject']); ?></td>
                                            <td><?php echo htmlspecialchars(ucfirst(str_replace('_',' ', $c['status']))); ?></td>
                                            <td><?php echo htmlspecialchars(date('d-m-Y', strtotime($c['created_at']))); ?></td>
                                            <td>
                                                <form method="POST" class="d-flex gap-2">
                                                    <input type="hidden" name="complaint_id" value="<?php echo (int)$c['id']; ?>">
                                                    <select name="status" class="form-select form-select-sm">
                                                        <?php foreach(['open','in_progress','resolved','closed'] as $st): ?>
                                                            <option value="<?php echo $st; ?>" <?php echo $c['status'] === $st ? 'selected' : ''; ?>><?php echo ucfirst(str_replace('_',' ', $st)); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button class="btn btn-sm btn-primary">Update</button>
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
    </div>
</div>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
