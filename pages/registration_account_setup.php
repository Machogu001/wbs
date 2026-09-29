<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/User.php';
require_once __DIR__ . '/../includes/Payment.php';

$database = new Database();
$db = $database->getConnection();
$page_title = 'Set Account Password';
$hide_nav = true;
require_once __DIR__ . '/../templates/header.php';

$token = trim((string)($_GET['token'] ?? ''));
$message = null;
$messageType = 'danger';
$setupRow = null;
$userRow = null;

if (!$db) {
	$message = 'Unable to connect to the database.';
} elseif ($token === '') {
	$message = 'Invalid account setup link.';
} else {
	$paymentService = new Payment($db);
	$stmt = $db->prepare("SELECT rp.*, u.full_name, u.account_number, u.email, b.status AS bill_status, b.id AS bill_id
		FROM registration_proformas rp
		INNER JOIN users u ON u.id = rp.user_id
		INNER JOIN bills b ON b.id = rp.bill_id
		WHERE rp.account_setup_token = :token LIMIT 1");
	$stmt->execute([':token' => $token]);
	$setupRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	if (!$setupRow) {
		$message = 'This account setup link is invalid.';
	} elseif (!empty($setupRow['account_setup_completed_at'])) {
		$message = 'This account setup link has already been used. You can log in now.';
		$messageType = 'success';
	} elseif (empty($setupRow['account_setup_expires_at']) || strtotime((string)$setupRow['account_setup_expires_at']) < time()) {
		$message = 'This account setup link has expired. Please contact support or use Forgot Password.';
	} elseif ($paymentService->getBillOutstandingAmount((int)$setupRow['bill_id']) > 0.01) {
		$message = 'Your registration fee is not fully paid yet. Complete payment before setting up portal access.';
	} else {
		$userService = new User($db);
		$userRow = $userService->getById((int)$setupRow['user_id']);
		if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userRow) {
			$password = (string)($_POST['password'] ?? '');
			$confirmPassword = (string)($_POST['confirm_password'] ?? '');
			if ($password === '' || strlen($password) < 6) {
				$message = 'Choose a password with at least 6 characters.';
			} elseif (!hash_equals($password, $confirmPassword)) {
				$message = 'Password and confirmation do not match.';
			} else {
				$hash = password_hash($password, PASSWORD_BCRYPT);
				$db->beginTransaction();
				try {
					$stmtUser = $db->prepare('UPDATE users SET password_hash = :password_hash, must_change_password = 0, status = "active" WHERE id = :id');
					$stmtUser->execute([
						':password_hash' => $hash,
						':id' => (int)$userRow['id'],
					]);
					$stmtSetup = $db->prepare('UPDATE registration_proformas SET account_setup_completed_at = NOW(), account_setup_token = NULL, account_setup_expires_at = NULL WHERE id = :id');
					$stmtSetup->execute([':id' => (int)$setupRow['id']]);
					$db->commit();
					$message = 'Password saved successfully. You can now log in to your account.';
					$messageType = 'success';
				} catch (Throwable $e) {
					if ($db->inTransaction()) {
						$db->rollBack();
					}
					$message = 'Failed to save your password. Please try again.';
				}
			}
		}
	}
}
?>

<div class="container mt-5">
	<div class="row justify-content-center">
		<div class="col-md-6">
			<div class="card shadow-sm">
				<div class="card-header bg-primary text-white">
					<h3 class="mb-0"><i class="bi bi-key me-2"></i>Set Your Account Password</h3>
				</div>
				<div class="card-body">
					<?php if ($message): ?>
						<div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
					<?php endif; ?>
					<?php if ($userRow && $messageType !== 'success'): ?>
						<p class="text-muted">Account: <strong><?php echo htmlspecialchars((string)$userRow['account_number']); ?></strong><br>Name: <strong><?php echo htmlspecialchars((string)$userRow['full_name']); ?></strong></p>
						<form method="POST">
							<div class="mb-3">
								<label class="form-label" for="password">New Password</label>
								<input type="password" class="form-control" id="password" name="password" autocomplete="new-password" required>
							</div>
							<div class="mb-3">
								<label class="form-label" for="confirm_password">Confirm Password</label>
								<input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
							</div>
							<button type="submit" class="btn btn-primary w-100"><i class="bi bi-check2-circle me-1"></i> Save Password</button>
						</form>
					<?php else: ?>
						<div class="d-grid gap-2">
							<a href="/login" class="btn btn-primary"><i class="bi bi-box-arrow-in-right me-1"></i> Go to Login</a>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>