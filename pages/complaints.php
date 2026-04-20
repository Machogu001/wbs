<?php
session_start();
if(!isset($_SESSION['user_id'])) {
    header("Location: /login");
    exit;
}

require_once __DIR__ . '/../config/database.php';require_once __DIR__ . '/../includes/Auth.php';require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Complaint.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
if (!$auth->isLoggedIn()) {
    header("Location: /login");
    exit;
}
$auth = new Auth($db);
if (!$auth->isLoggedIn()) {
    header("Location: /login");
    exit;
}

$page_title = "Complaints";
require_once __DIR__ . '/../templates/header.php';

$message = null;
$message_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {
    // CSRF validation
    if (!hash_equals($_SESSION['app_csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
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

if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
    // Use toast for flash notifications
    echo '<script>window.addEventListener("load",function(){ if(window.showToast){ showToast(' . json_encode($message) . ',' . json_encode($message_type) . '); } });</script>';
}

$complaints = [];
if ($db) {
    $complaintService = new Complaint($db);
    $complaints = $complaintService->listByUser($_SESSION['user_id']);
}
?>

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

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
