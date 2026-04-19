<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Complaint.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if(!$auth->isLoggedIn()) {
    header("Location: /login");
    exit;
}

$isAdmin = $auth->isAdmin();

// Set page title and admin flag for layout
if ($isAdmin) {
    $is_admin_page = true;
    $page_title = "Admin - Complaints";
} else {
    $page_title = "Complaints";
}

require_once __DIR__ . '/../../templates/header.php';

$message = null;
$message_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {
    // CSRF validation
    if (!hash_equals($_SESSION['app_csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
    if ($isAdmin) {
        // Admin can update complaint status
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
                header("Location: /complaints");
                exit;
            } else {
                $message = "Failed to update complaint.";
                $message_type = "danger";
            }
        } else {
            $message = "Invalid request.";
            $message_type = "danger";
        }
    } else {
        // Regular user can create a new complaint
        $subject = trim($_POST['subject'] ?? '');
        $details = trim($_POST['message'] ?? '');

        if ($subject === '' || $details === '') {
            $message = "Subject and message are required.";
            $message_type = "danger";
        } else {
            $complaintService = new Complaint($db);
            if ($complaintService->create($_SESSION['user_id'], $subject, $details)) {
                $_SESSION['flash_message'] = "Complaint submitted successfully.";
                $_SESSION['flash_type'] = "success";
                header("Location: /complaints");
                exit;
            } else {
                $message = "Failed to submit complaint.";
                $message_type = "danger";
            }
        }
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
    if ($isAdmin) {
        $complaints = $complaintService->listAll();
    } else {
        $complaints = $complaintService->listByUser($_SESSION['user_id']);
    }
}
?>

<?php if ($isAdmin): ?>
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-12">
                <div class="pb-banner pb-banner--rose mb-4">
                    <div class="pb-bg" aria-hidden="true">
                        <div class="pb-grid"></div>
                        <div class="pb-blob pb-blob--a"></div>
                        <div class="pb-blob pb-blob--b"></div>
                        <i class="bi bi-chat-left-text-fill pb-watermark"></i>
                    </div>
                    <div class="pb-inner">
                        <div class="pb-left">
                            <div class="pb-eyebrow-row">
                                <span class="pb-eyebrow-chip"><i class="bi bi-chat-left-text-fill"></i> Customer Relations</span>
                            </div>
                            <h2 class="pb-title">Complaints</h2>
                            <p class="pb-subtitle">Manage user complaints and track resolution status.</p>
                        </div>
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
<?php else: ?>
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-12">
                <h2>Complaints</h2>
                <p class="text-muted">Submit and track your complaints.</p>
            </div>
        </div>

        <?php if($message && !isset($_SESSION['flash_message'])): ?>
            <div class="alert alert-<?php echo $message_type; ?> mt-3">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="row mt-3">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">New Complaint</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Subject</label>
                                <input type="text" name="subject" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Message</label>
                                <textarea name="message" class="form-control" rows="4" required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">Submit</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">My Complaints</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($complaints)): ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted">No complaints found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach($complaints as $c): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($c['subject']); ?></td>
                                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst(str_replace('_',' ', $c['status']))); ?></span></td>
                                                <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($c['created_at']))); ?></td>
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
<?php endif; ?>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
