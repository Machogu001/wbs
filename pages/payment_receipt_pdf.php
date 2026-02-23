<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/PaymentLink.php';
require_once __DIR__ . '/../includes/Payment.php';
require_once __DIR__ . '/../includes/Bill.php';
require_once __DIR__ . '/../includes/User.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;

$database = new Database();
$db = $database->getConnection();

$token = isset($_GET['t']) ? trim($_GET['t']) : '';
$paymentId = isset($_GET['p']) ? (int)$_GET['p'] : 0;

if (!$db || $token === '' || $paymentId <= 0) {
    http_response_code(400);
    echo 'Invalid receipt request.';
    exit;
}

$billId = PaymentLink::getBillIdFromToken($token);
if (!$billId) {
    http_response_code(400);
    echo 'Invalid or expired receipt link.';
    exit;
}

$payment = new Payment($db);
$paymentRow = $payment->getById($paymentId);
if (!$paymentRow || (int)$paymentRow['bill_id'] !== (int)$billId || $paymentRow['status'] !== 'completed') {
    http_response_code(400);
    echo 'Receipt not available for this payment.';
    exit;
}

$bill = new Bill($db);
$billRow = $bill->getById($billId);
if (!$billRow) {
    http_response_code(400);
    echo 'Bill not found for this payment.';
    exit;
}

$user = new User($db);
$userRow = $user->getById($paymentRow['user_id']);
if (!$userRow) {
    http_response_code(400);
    echo 'Customer account not found.';
    exit;
}

$receiptNumber = $paymentRow['mpesa_receipt'] ?? 'N/A';
$accountNumber = $billRow['account_number'];
$customerName = $userRow['full_name'];
$billingMonth = date('F Y', strtotime($billRow['billing_month']));
$amountPaid = number_format((float)$paymentRow['amount'], 2);
$paidOn = date('d-m-Y H:i', strtotime($paymentRow['transaction_date'] ?? $paymentRow['created_at']));

$html = "<!DOCTYPE html>
<html><head><meta charset='UTF-8'><style>
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; }
.hdr { text-align: center; margin-bottom: 20px; }
.hdr h2 { margin: 0; }
.table { width: 100%; border-collapse: collapse; }
.table td { padding: 4px 6px; border-bottom: 1px solid #ddd; }
.label { width: 35%; font-weight: bold; }
.value { width: 65%; }
</style></head><body>
<div class='hdr'>
  <h2>Payment Receipt</h2>
  <p>Water Billing System</p>
</div>
<table class='table'>
  <tr><td class='label'>Receipt Number</td><td class='value'>" . htmlspecialchars($receiptNumber) . "</td></tr>
  <tr><td class='label'>Account Number</td><td class='value'>" . htmlspecialchars($accountNumber) . "</td></tr>
  <tr><td class='label'>Customer Name</td><td class='value'>" . htmlspecialchars($customerName) . "</td></tr>
  <tr><td class='label'>Billing Month</td><td class='value'>" . htmlspecialchars($billingMonth) . "</td></tr>
  <tr><td class='label'>Amount Paid</td><td class='value'>KES " . htmlspecialchars($amountPaid) . "</td></tr>
  <tr><td class='label'>Payment Status</td><td class='value'>Completed</td></tr>
  <tr><td class='label'>Paid On</td><td class='value'>" . htmlspecialchars($paidOn) . "</td></tr>
</table>
</body></html>";

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'receipt-' . preg_replace('/[^A-Za-z0-9]/', '', $accountNumber) . '-' . $paymentId . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');

echo $dompdf->output();
