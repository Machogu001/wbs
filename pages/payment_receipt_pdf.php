<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/PaymentLink.php';
require_once __DIR__ . '/../includes/Payment.php';
require_once __DIR__ . '/../includes/Bill.php';
require_once __DIR__ . '/../includes/BillingSettings.php';
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
$isCompanyCustomer = strtolower(trim((string)($userRow['customer_type'] ?? 'individual'))) === 'company';
$companyCustomerName = trim((string)($userRow['company_name'] ?? ''));
$contactPersonName = trim((string)($userRow['contact_person_name'] ?? ''));
$companyRegistrationNumber = trim((string)($userRow['company_registration_number'] ?? ''));
$customerName = $isCompanyCustomer && $companyCustomerName !== ''
    ? $companyCustomerName
    : trim((string)($userRow['full_name'] ?? 'Customer'));
$customerTypeLabel = $isCompanyCustomer ? 'Company / Organization' : 'Individual / Personal';
$billingMonth = date('F Y', strtotime($billRow['billing_month']));
$amountPaid = number_format((float)$paymentRow['amount'], 2);
$remainingBalanceValue = $payment->getBillOutstandingAmount((int)$billId);
$remainingBalance = number_format((float)$remainingBalanceValue, 2);
$paidOn = date('d-m-Y H:i', strtotime($paymentRow['transaction_date'] ?? $paymentRow['created_at']));
$paymentMethodMap = [
    'mpesa' => 'M-Pesa',
    'cash' => 'Cash',
    'bank' => 'Bank Transfer',
    'card' => 'Card',
    'cheque' => 'Cheque',
    'wallet' => 'Wallet',
    'other' => 'Other',
];
$paymentMethodKey = strtolower(trim((string)($paymentRow['payment_method'] ?? 'mpesa')));
$paymentMethodLabel = $paymentMethodMap[$paymentMethodKey] ?? ucfirst($paymentMethodKey ?: 'M-Pesa');
$referenceLabel = $paymentMethodKey === 'mpesa' ? 'M-Pesa Reference' : 'Reference Number';
$receiverLabel = 'System / Automatic';
if (!empty($paymentRow['received_by_user_id'])) {
    $receiverRow = $user->getById((int)$paymentRow['received_by_user_id']);
    if ($receiverRow && !empty($receiverRow['full_name'])) {
        $receiverLabel = $receiverRow['full_name'];
    }
}
$settingsService = new BillingSettings($db);
$settings = $settingsService->getSettings();
$companyName = !empty($settings['company_name']) ? $settings['company_name'] : 'BreMac Consultant Ltd';
$rainbowColors = ['#e53e3e','#dd6b20','#d69e2e','#38a169','#3182ce','#5a67d8','#805ad5'];
$rainbowName = '';
$colorIdx = 0;
foreach (mb_str_split($companyName) as $char) {
    if ($char === ' ') {
        $rainbowName .= ' ';
    } else {
        $rainbowName .= '<span style="color:' . $rainbowColors[$colorIdx % count($rainbowColors)] . '">' . htmlspecialchars($char) . '</span>';
        $colorIdx++;
    }
}

$html = "<!DOCTYPE html>
<html><head><meta charset='UTF-8'><style>
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color: #0f172a; margin: 0; padding: 10px 8px 12px; }
.receipt-shell { width: 100%; }
.hdr { text-align: center; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px dashed #94a3b8; }
.hdr h2 { margin: 0; color: #16a34a; font-size: 15px; }
.hdr p { margin: 4px 0 0; font-size: 10px; font-weight: bold; }
.section-title { color: #2563eb; font-size: 11px; font-weight: 700; margin: 0 0 6px; text-transform: uppercase; letter-spacing: 0.06em; text-align: center; }
.table { width: 100%; border-collapse: collapse; table-layout: fixed; }
.table td { padding: 5px 3px; border-bottom: 1px solid #dbe3ef; vertical-align: top; }
.label { width: 40%; font-weight: bold; font-size: 9px; color: #000000; }
.value { width: 60%; font-size: 10px; text-align: right; word-wrap: break-word; color: #000000; }
.etims-block { margin-top: 10px; padding-top: 8px; border-top: 1px dashed #94a3b8; text-align: center; }
.etims-block img { display: block; margin: 4px auto 0; }
.etims-label { font-size: 8px; color: #475569; margin-top: 3px; }
.footer-note { margin-top: 8px; padding-top: 8px; border-top: 1px dashed #94a3b8; text-align: center; color: #64748b; font-size: 8px; }
</style></head><body>
<div class='receipt-shell'>
    <div class='hdr'>
        <h2>Payment Receipt</h2>
        <p>" . $rainbowName . "</p>
    </div>
    <div class='section-title'>Receipt Details</div>
    <table class='table'>
        <tr><td class='label'>Receipt Number</td><td class='value'>" . htmlspecialchars($receiptNumber) . "</td></tr>
        <tr><td class='label'>Account Number</td><td class='value'>" . htmlspecialchars($accountNumber) . "</td></tr>
        <tr><td class='label'>Client Type</td><td class='value'>" . htmlspecialchars($customerTypeLabel) . "</td></tr>
        <tr><td class='label'>" . htmlspecialchars($isCompanyCustomer ? 'Company Name' : 'Customer Name') . "</td><td class='value'>" . htmlspecialchars($customerName) . "</td></tr>
        " . ($isCompanyCustomer && $contactPersonName !== '' ? "<tr><td class='label'>Contact Person</td><td class='value'>" . htmlspecialchars($contactPersonName) . "</td></tr>" : "") . "
        " . ($isCompanyCustomer && $companyRegistrationNumber !== '' ? "<tr><td class='label'>Reg. Number</td><td class='value'>" . htmlspecialchars($companyRegistrationNumber) . "</td></tr>" : "") . "
        <tr><td class='label'>Billing Month</td><td class='value'>" . htmlspecialchars($billingMonth) . "</td></tr>
        <tr><td class='label'>Amount Paid</td><td class='value'>KES " . htmlspecialchars($amountPaid) . "</td></tr>
        <tr><td class='label'>Remaining Balance</td><td class='value'>KES " . htmlspecialchars($remainingBalance) . "</td></tr>
        <tr><td class='label'>Payment Status</td><td class='value'>Completed</td></tr>
        <tr><td class='label'>Paid On</td><td class='value'>" . htmlspecialchars($paidOn) . "</td></tr>
            <tr><td class='label'>Payment Method</td><td class='value'>" . htmlspecialchars($paymentMethodLabel) . "</td></tr>
              <tr><td class='label'>" . htmlspecialchars($referenceLabel) . "</td><td class='value'>" . htmlspecialchars($receiptNumber) . "</td></tr>
            <tr><td class='label'>Received By</td><td class='value'>" . htmlspecialchars($receiverLabel) . "</td></tr>
    </table>
    " . (!empty($paymentRow['etims_qr_svg_url']) || !empty($paymentRow['etims_invoice_id']) ? "
    <div class='etims-block'>
        <div class='section-title'>eTIMS QR</div>
        <img src='" . htmlspecialchars($paymentRow['etims_qr_svg_url'] ?: ('https://etims.bremac.co.ke/qr/' . (int)$paymentRow['etims_invoice_id'] . '.svg')) . "' width='120' height='120' alt='eTIMS QR' />
        <div class='etims-label'>Scan to verify this receipt on KRA eTIMS</div>
    </div>" : "") . "
    <div class='footer-note'>Keep this receipt for your records.</div>
</div>
</body></html>";

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper([0, 0, 226.77, 600.00], 'portrait');
$dompdf->render();

$filename = 'receipt-' . preg_replace('/[^A-Za-z0-9]/', '', $accountNumber) . '-' . $paymentId . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');

echo $dompdf->output();
