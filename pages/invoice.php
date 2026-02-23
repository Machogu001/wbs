<?php
session_start();
if(!isset($_SESSION['user_id'])) {
    header("Location: /login");
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Bill.php';
require_once __DIR__ . '/../includes/BillingSettings.php';
require_once __DIR__ . '/../vendor/autoload.php';

$bill_id = isset($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;
if ($bill_id <= 0) {
    header("Location: /bills");
    exit;
}

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    header("Location: /bills");
    exit;
}

$billService = new Bill($db);
$bill = $billService->getById($bill_id, $_SESSION['user_id']);
if (!$bill) {
    header("Location: /bills");
    exit;
}

$user = $_SESSION['user_data'] ?? [];

// Try to load the latest completed M-Pesa payment for this bill (including ETIMS metadata)
$payment = null;
try {
    $stmtPay = $db->prepare("SELECT id, amount, status, mpesa_receipt, phone_number, etims_invoice_id, etims_qr_svg_url, COALESCE(transaction_date, created_at) AS paid_at
        FROM payments
        WHERE bill_id = :bill_id AND status = 'completed'
        ORDER BY COALESCE(transaction_date, created_at) DESC
        LIMIT 1");
    $stmtPay->bindParam(':bill_id', $bill_id, PDO::PARAM_INT);
    $stmtPay->execute();
    $payment = $stmtPay->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {
    $payment = null;
}

// Load issuer/company settings to align invoice format with getssl implementation
$settingsService = new BillingSettings($db);
$settings = $settingsService->getSettings();
$companyName = $settings['company_name'] ?? 'BreMac Consultant Ltd';
$companyEmail = $settings['support_email'] ?? '';
$companyPhone = $settings['support_phone'] ?? '';
$companyPin = $settings['company_pin'] ?? '';
$currency = $settings['currency_code'] ?? 'KES';

use Dompdf\Dompdf;

$filename = 'invoice_' . ($user['account_number'] ?? 'account') . '_' . $bill_id . '.pdf';

$statusColor = '#dc2626'; // default red
if ($bill['status'] === 'paid') {
    $statusColor = '#16a34a';
} elseif (in_array($bill['status'], ['pending'], true)) {
    $statusColor = '#eab308';
}

$html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice #' . (int)$bill_id . '</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #111827; background: #f3f4f6; }
        .wrapper { padding: 24px 0; }
        .container { max-width: 860px; margin: 0 auto; background: #ffffff; border-radius: 6px; box-shadow: 0 1px 3px rgba(15,23,42,0.08); padding: 24px 28px; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 24px; }
        .title-block { }
        .tag { font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: #6b7280; margin-bottom: 4px; }
        .title { font-size: 24px; font-weight: 700; letter-spacing: 0.05em; }
        .muted { color: #6b7280; font-size: 12px; }
        .right-meta { text-align: right; font-size: 12px; color: #6b7280; }
        .section { margin-bottom: 20px; }
        .section-title { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: #6b7280; margin-bottom: 6px; }
        .box { border: 1px solid #e5e7eb; border-radius: 4px; padding: 10px 12px; background: #f9fafb; font-size: 13px; }
        .label { color: #6b7280; font-size: 12px; }
        .value { font-weight: 600; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 12px; }
        th, td { border: 1px solid #e5e7eb; padding: 6px 8px; }
        th { background: #f9fafb; color: #6b7280; text-transform: uppercase; font-size: 11px; letter-spacing: 0.06em; }
        .text-right { text-align: right; }
        .summary-table { width: 260px; font-size: 12px; }
        .summary-table td { border: none; padding: 3px 0; }
        .summary-label { color: #6b7280; padding-right: 12px; }
        .summary-value { font-weight: 600; }
        .summary-total-label { font-weight: 600; color: #111827; border-top: 1px solid #e5e7eb; padding-top: 6px; }
        .summary-total-value { font-weight: 700; border-top: 1px solid #e5e7eb; padding-top: 6px; }
        .status-pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; color: #ffffff; }
        .footer-note { margin-top: 26px; font-size: 10px; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <div class="header">
                <div class="title-block">
                    <div class="tag">INVOICE</div>
                    <div class="title">Water Billing</div>
                    <div class="muted" style="margin-top:6px;">Invoice #: <strong>INV-' . (int)$bill_id . '</strong></div>
                    <div class="muted">Billing Month: ' . htmlspecialchars(date('M Y', strtotime($bill['billing_month']))) . '</div>
                    <div class="muted">Due Date: ' . htmlspecialchars(date('d-m-Y', strtotime($bill['due_date']))) . '</div>
                </div>
                <div class="right-meta">
                    <div class="label">Status</div>
                    <div class="status-pill" style="background:' . $statusColor . ';">' . htmlspecialchars(strtoupper($bill['status'])) . '</div>
                    <div style="margin-top:8px;" class="muted">Generated: ' . date('d-m-Y') . '</div>
                </div>
            </div>

            <div class="section" style="display:flex; gap:18px;">
                <div style="flex:1;">
                    <div class="section-title">Issuer</div>
                    <div class="box">
                        <div class="value">' . htmlspecialchars($companyName) . '</div>
                        ' . ($companyEmail ? '<div class="label">Email: <span class="value">' . htmlspecialchars($companyEmail) . '</span></div>' : '') . '
                        ' . ($companyPhone ? '<div class="label">Phone: <span class="value">' . htmlspecialchars($companyPhone) . '</span></div>' : '') . '
                        ' . ($companyPin ? '<div class="label">PIN/Tax ID: <span class="value">' . htmlspecialchars($companyPin) . '</span></div>' : '') . '
                    </div>
                </div>
                <div style="flex:1;">
                    <div class="section-title">Billed To</div>
                    <div class="box">
                        <div class="value">' . htmlspecialchars($user['full_name'] ?? 'Customer') . '</div>
                        <div class="label">Account: <span class="value">' . htmlspecialchars($user['account_number'] ?? '') . '</span></div>
                    </div>
                </div>
            </div>

            <div class="section" style="display:flex; gap:18px;">
                <div style="flex:1;">
                    <div class="section-title">Meter Details</div>
                    <div class="box">
                        <div class="label">Previous Reading: <span class="value">' . number_format($bill['previous_reading'], 2) . ' m³</span></div>
                        <div class="label">Current Reading: <span class="value">' . number_format($bill['current_reading'], 2) . ' m³</span></div>
                        <div class="label">Consumption: <span class="value">' . number_format($bill['consumption'], 2) . ' m³</span></div>
                    </div>
                </div>
            </div>

            <div class="section">
                <div class="section-title">Invoice Details</div>
                <table>
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th class="text-right">Previous (m³)</th>
                            <th class="text-right">Current (m³)</th>
                            <th class="text-right">Consumption (m³)</th>
                            <th class="text-right">Rate (' . htmlspecialchars($currency) . '/m³)</th>
                            <th class="text-right">Service Charge (' . htmlspecialchars($currency) . ')</th>
                            <th class="text-right">Amount (' . htmlspecialchars($currency) . ')</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Water consumption for ' . htmlspecialchars(date('M Y', strtotime($bill['billing_month']))) . '</td>
                            <td class="text-right">' . number_format($bill['previous_reading'], 2) . '</td>
                            <td class="text-right">' . number_format($bill['current_reading'], 2) . '</td>
                            <td class="text-right">' . number_format($bill['consumption'], 2) . '</td>
                            <td class="text-right">' . number_format($bill['rate_per_unit'], 2) . '</td>
                            <td class="text-right">' . number_format($bill['service_charge'], 2) . '</td>
                            <td class="text-right">' . number_format($bill['amount'], 2) . '</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="section">
                <div style="text-align:right;">
                    <div class="section-title" style="text-align:right;">Summary</div>
                    <table class="summary-table" style="margin-left:auto;">
                        <tr>
                            <td class="summary-label">Subtotal:</td>
                            <td class="summary-value text-right">' . htmlspecialchars($currency) . ' ' . number_format($bill['amount'], 2) . '</td>
                        </tr>
                        <tr>
                            <td class="summary-total-label">Total Due:</td>
                            <td class="summary-total-value text-right">' . htmlspecialchars($currency) . ' ' . number_format($bill['amount'], 2) . '</td>
                        </tr>
                    </table>
                </div>
            </div>

            ' . ($payment ? '
            <div class="section">
                <div class="section-title">Payment Information</div>
                <table>
                    <thead>
                        <tr>
                            <th>Paid On</th>
                            <th>Method</th>
                            <th>MPesa Receipt</th>
                            <th class="text-right">Amount (' . htmlspecialchars($currency) . ')</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>' . htmlspecialchars(date('d-m-Y H:i', strtotime($payment['paid_at']))) . '</td>
                            <td>M-Pesa</td>
                            <td>' . htmlspecialchars($payment['mpesa_receipt'] ?: '-') . '</td>
                            <td class="text-right">' . number_format($payment['amount'], 2) . '</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            ' : '' ) . '

            ' . ($payment && (!empty($payment['etims_qr_svg_url']) || !empty($payment['etims_invoice_id'])) ? '
            <div class="section">
                <div class="section-title">eTIMS QR</div>
                <table style="width:100%; border:none; border-collapse:collapse;">
                    <tr>
                        <td style="width:210px; border:none; vertical-align:top;">
                            <img src="' . htmlspecialchars($payment['etims_qr_svg_url'] ?: ('https://etims.bremac.co.ke/qr/' . (int)$payment['etims_invoice_id'] . '.svg')) . '" width="160" height="160" alt="eTIMS QR" />
                        </td>
                        <td style="border:none; vertical-align:top; font-size:10px; color:#6b7280;">
                            <div>Scan to verify receipt on KRA portal.</div>
                        </td>
                    </tr>
                </table>
            </div>
            ' : '' ) . '

            <div class="footer-note">This is a system-generated invoice for water services and does not require a signature.</div>
        </div>
    </div>
</body>
</html>';

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream($filename, ["Attachment" => true]);
exit;
