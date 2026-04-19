<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    http_response_code(500);
    echo 'Database connection failed';
    exit;
}

$auth = new Auth($db);
if (!$auth->isLoggedIn() || (!$auth->isAdmin() && !$auth->hasPermission('view_invoicing'))) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$filename = 'meter_reading_template.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
if ($output === false) {
    http_response_code(500);
    echo 'Unable to generate CSV';
    exit;
}

fputcsv($output, ['account_or_meter', 'current_reading', 'billing_month', 'due_date']);
fputcsv($output, ['MTR0001', '1250.50', date('Y-m-01'), date('Y-m-d', strtotime('+14 days'))]);
fputcsv($output, ['MTR0002', '834.00', '', '']);

fclose($output);
exit;
