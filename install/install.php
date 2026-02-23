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
                                <input type="text" name="db_host" class="form-control" value="localhost" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Database Name</label>
                                <input type="text" name="db_name" class="form-control" value="water_billing" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Database Username</label>
                                <input type="text" name="db_user" class="form-control" value="root" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Database Password</label>
                                <input type="password" name="db_pass" class="form-control">
                            </div>
                            
                            <h4>Admin Account</h4>
                            <div class="mb-3">
                                <label class="form-label">Admin Phone Number</label>
                                <input type="tel" name="admin_phone" class="form-control" value="254700000001" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Admin Password</label>
                                <input type="password" name="admin_pass" class="form-control" value="admin123" required>
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
