<?php
$install_lock = __DIR__ . '/../config/installed.lock';
$config_file = __DIR__ . '/../config/database.php';
$isInstalled = file_exists($install_lock);

if (!$isInstalled && file_exists($config_file)) {
	require_once $config_file;
	if (class_exists('Database') && method_exists('Database', 'testConnection')) {
		try {
			$isInstalled = Database::testConnection();
		} catch (Throwable $e) {
			$isInstalled = false;
		}
	}
}

if (!$isInstalled) {
	require_once __DIR__ . '/install.php';
	exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>System Already Installed</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
	<div class="container mt-5">
		<div class="row justify-content-center">
			<div class="col-md-8 col-lg-6">
				<div class="card shadow-sm">
					<div class="card-header bg-success text-white">
						<h4 class="mb-0 text-center">System Already Installed</h4>
					</div>
					<div class="card-body">
						<div class="alert alert-info mb-4">
							This system has already been installed. You can proceed to login or register a new account.
						</div>
						<div class="d-grid gap-2 d-md-flex justify-content-md-center">
							<a href="/login" class="btn btn-primary">Proceed to Login</a>
							<a href="/register" class="btn btn-outline-primary">Proceed to Registration</a>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</body>
</html>
