<?php
session_start();
if(!isset($_SESSION['user_id'])) {
	header("Location: /login");
	exit;
}

require_once __DIR__ . '/../config/database.php';

$message = null;
$message_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$tax_pin = isset($_POST['tax_pin']) ? trim($_POST['tax_pin']) : '';
	$database = new Database();
	$db = $database->getConnection();
	if ($db) {
		$stmt = $db->prepare("UPDATE users SET tax_pin = :tax_pin WHERE id = :id");
		$stmt->bindParam(':tax_pin', $tax_pin);
		$stmt->bindParam(':id', $_SESSION['user_id'], PDO::PARAM_INT);
		if ($stmt->execute()) {
			$_SESSION['user_data']['tax_pin'] = $tax_pin;
			$message = 'PIN / Tax ID updated successfully.';
			$message_type = 'success';
		} else {
			$message = 'Failed to update PIN / Tax ID.';
			$message_type = 'danger';
		}
	} else {
		$message = 'Database connection error.';
		$message_type = 'danger';
	}
}

$page_title = "My Profile";
require_once __DIR__ . '/../templates/header.php';

$user = $_SESSION['user_data'] ?? [];
?>

<div class="container mt-4">
	<div class="row">
		<div class="col-md-12">
			<h2>My Profile</h2>
			<p class="text-muted">Your account details.</p>
		</div>
	</div>

	<div class="row mt-3">
		<div class="col-md-6">
			<div class="card">
				<div class="card-body">
					<?php if($message): ?>
						<script>
						window.addEventListener('load', function() {
							if (window.showToast) {
								showToast(<?php echo json_encode($message); ?>, <?php echo json_encode($message_type); ?>);
							}
						});
						</script>
					<?php endif; ?>
					<form method="POST">
					<div class="mb-3">
						<label class="form-label">Full Name</label>
						<div class="form-control bg-light"><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></div>
					</div>
					<div class="mb-3">
						<label class="form-label">Account Number</label>
						<div class="form-control bg-light"><?php echo htmlspecialchars($user['account_number'] ?? ''); ?></div>
					</div>
					<div class="mb-3">
						<label class="form-label">Phone Number</label>
						<div class="form-control bg-light"><?php echo htmlspecialchars($user['phone_number'] ?? ''); ?></div>
					</div>
					<div class="mb-3">
						<label class="form-label">Meter Number</label>
						<div class="form-control bg-light"><?php echo htmlspecialchars($user['meter_number'] ?? ''); ?></div>
					</div>
						<div class="mb-3">
							<label class="form-label">PIN / Tax ID (optional)</label>
							<input type="text" name="tax_pin" class="form-control" value="<?php echo htmlspecialchars($user['tax_pin'] ?? ''); ?>" placeholder="e.g. P012345678Z">
							<div class="form-text">If you have a PIN/Tax ID you want used on eTIMS submissions, you can add it here. This field is optional.</div>
						</div>
						<button type="submit" class="btn btn-primary">Save PIN</button>
					</form>
				</div>
			</div>
		</div>
	</div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
