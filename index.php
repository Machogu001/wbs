<?php
// Main entry point for the application
session_start();

// Define base path
define('BASE_PATH', dirname(__FILE__));
define('APP_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]");

// Check if installation is required
$config_file = BASE_PATH . '/config/database.php';
$install_lock = BASE_PATH . '/config/installed.lock';
$installed = false;

if (file_exists($install_lock)) {
    $installed = true;
} elseif (file_exists($config_file)) {
    require_once $config_file;
    if (class_exists('Database') && method_exists('Database', 'testConnection')) {
        $installed = Database::testConnection();
    }
}

if(!$installed && !strpos($_SERVER['REQUEST_URI'], 'install')) {
    header('Location: /install/');
    exit;
}

// Simple router
$request_uri = strtok($_SERVER['REQUEST_URI'], '?');
$base_dir = rtrim(str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']), '/');
if ($base_dir !== '' && strpos($request_uri, $base_dir) === 0) {
    $request = substr($request_uri, strlen($base_dir));
} else {
    $request = $request_uri;
}
$request = trim($request, '/');

// Default route
if(empty($request)) {
    require_once 'pages/index.php';
    exit;
}

// Define routes
$routes = [
    '' => 'pages/index.php',
    'home' => 'pages/index.php',
    'register' => 'pages/register.php',
    'login' => 'pages/login.php',
    'registration-payment' => 'pages/registration_payment.php',
    'dashboard' => 'pages/dashboard.php',
    'bills' => 'pages/bills.php',
    'pay' => 'pages/pay_bill.php',
    'pay-link' => 'pages/pay_link.php',
    'payment-receipt' => 'pages/payment_receipt.php',
    'payment-receipt-pdf' => 'pages/payment_receipt_pdf.php',
    'profile' => 'pages/profile.php',
    'submit-reading' => 'pages/submit_reading.php',
    'invoice' => 'pages/invoice.php',
    'statement' => 'pages/statement.php',
    'complaints' => 'pages/complaints.php',
	'logout' => 'pages/logout.php',
    'admin' => 'pages/admin/index.php',
    'admin/payments' => 'pages/admin/payments.php',
    'admin/users' => 'pages/admin/users.php',
    'admin/invoicing' => 'pages/admin/invoicing.php',
    'admin/reports' => 'pages/admin/reports.php',
    'admin/complaints' => 'pages/admin/complaints.php'
];

// Check if route exists
if(isset($routes[$request])) {
    require_once $routes[$request];
    exit;
}

// Check if it's an API request
if(strpos($request, 'api/') === 0) {
    $api_file = BASE_PATH . '/' . $request . '.php';
    if(file_exists($api_file)) {
        require_once $api_file;
        exit;
    }
}

// 404 - Page not found
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6 text-center">
                <h1 class="display-1">404</h1>
                <h2>Page Not Found</h2>
                <p class="lead">The page you are looking for doesn't exist or has been moved.</p>
                <a href="index.php" class="btn btn-primary">
                    <i class="bi bi-house-door"></i> Go to Homepage
                </a>
            </div>
        </div>
    </div>
</body>
</html>
