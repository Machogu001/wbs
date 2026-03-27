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

if ($isInstalled) {
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
                                This system has already been installed. Proceed to login or registration.
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
    <?php
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install Water Billing System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .step {
            margin-bottom: 2rem;
            padding: 1rem;
            border-left: 4px solid #0066cc;
            background-color: #f8f9fa;
        }
        .step.completed {
            border-left-color: #28a745;
        }
        .step.error {
            border-left-color: #dc3545;
        }
        .step-number {
            display: inline-block;
            width: 30px;
            height: 30px;
            line-height: 30px;
            text-align: center;
            background-color: #0066cc;
            color: white;
            border-radius: 50%;
            margin-right: 10px;
        }
        .step.completed .step-number {
            background-color: #28a745;
        }
        .step.error .step-number {
            background-color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h3 class="text-center mb-0">Water Billing System Installation</h3>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            This installer will set up the Water Billing System.
                        </div>
                        
                        <form method="POST" action="install_process.php">
                            <h4>Database Configuration</h4>
                            <div class="mb-3">
                                <label class="form-label">Database Host</label>
                                <input type="text" name="db_host" class="form-control" value="localhost" autocomplete="off" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Database Name</label>
                                <input type="text" name="db_name" class="form-control" value="water_billing" autocomplete="off" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Database Username</label>
                                <input type="text" name="db_user" class="form-control" value="root" autocomplete="username" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Database Password</label>
                                <input type="password" name="db_pass" class="form-control" autocomplete="current-password">
                            </div>
                            
                            <h4>Admin Account</h4>
                            <div class="mb-3">
                                <label class="form-label">Admin Full Name</label>
                                <input type="text" name="admin_name" class="form-control" value="System Administrator" autocomplete="name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Admin Email</label>
                                <input type="email" name="admin_email" class="form-control" value="admin@bremac.co.ke" autocomplete="email" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Admin Phone Number</label>
                                <input type="tel" name="admin_phone" class="form-control" value="254717996492" autocomplete="tel" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Admin Password</label>
                                <input type="password" name="admin_pass" class="form-control" value="admin123" autocomplete="new-password" required>
                            </div>

                            <h4>Company Settings</h4>
                            <div class="mb-3">
                                <label class="form-label">Company Name</label>
                                <input type="text" name="company_name" class="form-control" value="Water Billing System" autocomplete="organization" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Support Phone</label>
                                <input type="tel" name="support_phone" class="form-control" value="254724400202" autocomplete="tel">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Support Email</label>
                                <input type="email" name="support_email" class="form-control" value="support@bremac.co.ke" autocomplete="email">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Locale Code</label>
                                <input type="text" name="locale_code" class="form-control" value="en-KE" autocomplete="off">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Timezone</label>
                                <input type="text" name="timezone_name" class="form-control" value="Africa/Nairobi" autocomplete="off">
                            </div>
                            
                            <div class="alert alert-warning">
                                <strong>Important:</strong> Change the default admin password after installation.
                            </div>
                            
                            <div class="text-center">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    Install Now
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
