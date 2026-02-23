<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if(!$auth->isLoggedIn() || !$auth->isAdmin()) {
	header("Location: /login");
	exit;
}

$page_title = "Admin - Users";
require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container mt-4">
	<div class="row">
		<div class="col-md-12">
			<div class="admin-page-header d-flex justify-content-between align-items-center">
				<div>
					<h2 class="mb-1">Users</h2>
					<p class="text-muted mb-0">This page is protected for admins only.</p>
				</div>
			</div>
		</div>
	</div>
</div>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
