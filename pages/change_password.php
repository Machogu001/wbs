<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/User.php';

$message = null;
$message_type = 'success';

$database = new Database();
$db = $database->getConnection();
// Ensure user-related schema (including must_change_password) is present
$userModel = $db ? new User($db) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$db) {
        $message = 'Database connection error.';
        $message_type = 'danger';
    } else {
        try {
            $userId = (int)($_SESSION['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new Exception('Invalid session user. Please log in again.');
            }

            $oldPassword = (string)($_POST['old_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_new_password'] ?? '');

            if ($oldPassword === '' || $newPassword === '' || $confirmPassword === '') {
                throw new Exception('Please fill in all password fields.');
            }
            if ($newPassword !== $confirmPassword) {
                throw new Exception('New password and confirmation do not match.');
            }
            if (strlen($newPassword) < 6) {
                throw new Exception('New password must be at least 6 characters long.');
            }

            $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
            $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['password_hash']) || !password_verify($oldPassword, $row['password_hash'])) {
                throw new Exception('Current password is incorrect.');
            }

            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmtUpdate = $db->prepare('UPDATE users SET password_hash = :hash, must_change_password = 0 WHERE id = :id');
            $stmtUpdate->bindParam(':hash', $newHash);
            $stmtUpdate->bindParam(':id', $userId, PDO::PARAM_INT);
            if ($stmtUpdate->execute()) {
                // Optional: mark in session that password has been changed
                $_SESSION['password_changed_recently'] = true;

                $message = 'Password updated successfully. Redirecting to dashboard...';
                $message_type = 'success';

                echo "<script>setTimeout(function(){ window.location.href = '/dashboard'; }, 1500);</script>";
            } else {
                throw new Exception('Failed to update password.');
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
            $message_type = 'danger';
        }
    }
}

$page_title = 'Change Password';
$hide_nav = false;
require_once __DIR__ . '/../templates/header.php';
?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h3 class="mb-0"><i class="bi bi-key"></i> Change Password</h3>
                </div>
                <div class="card-body">
                    <p class="text-muted">For your security, please update your password.</p>

                    <?php if ($message): ?>
                        <script>
                        window.addEventListener('load', function() {
                            if (window.showToast) {
                                showToast(<?php echo json_encode($message); ?>, <?php echo json_encode($message_type); ?>);
                            }
                        });
                        </script>
                        <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> mt-2">
                            <?php echo htmlspecialchars($message); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label for="old_password" class="form-label">Current Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="old_password" name="old_password" autocomplete="current-password" required>
                                <button class="btn btn-outline-secondary toggle-password" type="button" aria-label="Show or hide current password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="new_password" name="new_password" autocomplete="new-password" required>
                                <button class="btn btn-outline-secondary toggle-password" type="button" aria-label="Show or hide new password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_new_password" class="form-label">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="confirm_new_password" name="confirm_new_password" autocomplete="new-password" required>
                                <button class="btn btn-outline-secondary toggle-password" type="button" aria-label="Show or hide confirm password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-check2-circle"></i> Update Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
