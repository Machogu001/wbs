<?php
session_start();
if(!isset($_SESSION['user_id'])) {
    header("Location: /login");
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Auth.php';
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
$auth = new Auth($db);
if (!$auth->isLoggedIn()) {
    header("Location: /login");
    exit;
}
if (!$db) {
    header("Location: /bills");
    exit;
}

$canAdminDownloadInvoice = $auth->isAdmin()
    || $auth->hasPermission('view_invoicing')
    || $auth->hasPermission('view_bill_detail');

$billService = new Bill($db);
$bill = $canAdminDownloadInvoice
    ? $billService->getById($bill_id, null)
    : $billService->getById($bill_id, (int)$_SESSION['user_id']);
if (!$bill) {
    header("Location: " . ($canAdminDownloadInvoice ? "/admin/payments" : "/bills"));
    exit;
}
$billLineItems = $billService->getBillLineItems((int)$bill_id);

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

$baseAmount = isset($bill['base_amount']) ? (float)$bill['base_amount'] : (float)$bill['amount'];
$taxRate = isset($bill['tax_rate']) ? (float)$bill['tax_rate'] : 0.0;
$taxAmount = isset($bill['tax_amount']) ? (float)$bill['tax_amount'] : 0.0;
$totalAmount = (float)$bill['amount'];

$lineRowsHtml = '';
if (empty($billLineItems)) {
    $lineRowsHtml = '<tr>'
        . '<td>Water consumption for ' . htmlspecialchars(date('M Y', strtotime($bill['billing_month']))) . '</td>'
        . '<td class="text-right">' . number_format((float)$bill['consumption'], 2) . '</td>'
        . '<td class="text-right">' . number_format((float)$bill['rate_per_unit'], 2) . '</td>'
        . '<td class="text-right">' . number_format((float)$bill['amount'], 2) . '</td>'
        . '</tr>';
} else {
    foreach ($billLineItems as $lineItem) {
        $lineRowsHtml .= '<tr>'
            . '<td>' . htmlspecialchars((string)($lineItem['description'] ?? 'Line item')) . '</td>'
            . '<td class="text-right">' . number_format((float)($lineItem['quantity'] ?? 0), 2) . '</td>'
            . '<td class="text-right">' . number_format((float)($lineItem['unit_rate'] ?? 0), 2) . '</td>'
            . '<td class="text-right">' . number_format((float)($lineItem['line_amount'] ?? 0), 2) . '</td>'
            . '</tr>';
    }
}

$html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice #' . (int)$bill_id . '</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #1e293b; background: #ffffff; }
        .wrapper { padding: 24px 0; }
        .container { max-width: 860px; margin: 0 auto; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0 0 28px 0; overflow: hidden; }
        .header-band { background: #ffffff; color: #1e293b; padding: 22px 28px 16px 28px; display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 0; border-bottom: 3px solid #1e40af; }
        .header-band .muted { color: #64748b; font-size: 12px; }
        .header-band .label { color: #64748b; font-size: 12px; }
        .header-band .value { font-weight: 600; font-size: 13px; color: #1e293b; }
        .body-pad { padding: 0 28px; }
        .tag { font-size: 11px; letter-spacing: 0.10em; text-transform: uppercase; color: #1e40af; margin-bottom: 4px; }
        .title { font-size: 24px; font-weight: 700; letter-spacing: 0.05em; color: #1e40af; }
        .section { margin-bottom: 20px; }
        .section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.10em; color: #1e40af; margin-bottom: 6px; border-left: 3px solid #1e40af; padding-left: 7px; }
        .box { border: 1px solid #cbd5e1; border-radius: 4px; padding: 10px 12px; background: #ffffff; font-size: 13px; }
        .label { color: #64748b; font-size: 12px; }
        .value { font-weight: 600; font-size: 13px; color: #1e293b; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 12px; }
        th { background: #ffffff; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.06em; padding: 7px 8px; border-bottom: 2px solid #cbd5e1; border-top: 1px solid #cbd5e1; border-left: 1px solid #cbd5e1; border-right: 1px solid #cbd5e1; }
        td { border: 1px solid #cbd5e1; padding: 6px 8px; background: #ffffff; color: #1e293b; }
        tr:nth-child(even) td { background: #f8fafc; }
        .text-right { text-align: right; }
        .summary-table { width: 260px; font-size: 12px; }
        .summary-table td { border: none; padding: 3px 0; background: transparent; }
        .summary-label { color: #64748b; padding-right: 12px; }
        .summary-value { font-weight: 600; color: #1e293b; }
        .summary-total-label { font-weight: 700; color: #1e40af; border-top: 2px solid #1e40af; padding-top: 6px; }
        .summary-total-value { font-weight: 700; color: #1e40af; border-top: 2px solid #1e40af; padding-top: 6px; }
        .status-pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; color: #ffffff; }
        .footer-note { margin-top: 26px; font-size: 10px; color: #64748b; text-align: center; border-top: 1px solid #cbd5e1; padding-top: 12px; }
        .right-meta { text-align: right; font-size: 12px; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <div class="header-band">
                <div class="title-block">
                    <div style="font-size:28px; font-weight:700; letter-spacing:0.12em; margin-bottom:8px; line-height:1.2;">
                        <span style="color:#e11d48;">I</span><span style="color:#ea580c;">N</span><span style="color:#ca8a04;">V</span><span style="color:#16a34a;">O</span><span style="color:#0284c7;">I</span><span style="color:#7c3aed;">C</span><span style="color:#db2777;">E</span>
                    </div>
                    <div class="muted" style="margin-top:6px;">Invoice #: <strong style="color:#1e293b;">INV-' . (int)$bill_id . '</strong></div>
                    <div class="muted">Billing Month: ' . htmlspecialchars(date('M Y', strtotime($bill['billing_month']))) . '</div>
                    <div class="muted">Due Date: ' . htmlspecialchars(date('d-m-Y', strtotime($bill['due_date']))) . '</div>
                </div>
                <div class="right-meta">
                    <div class="label">Status</div>
                    <div class="status-pill" style="background:' . $statusColor . ';">' . htmlspecialchars(strtoupper($bill['status'])) . '</div>
                    <div style="margin-top:8px;" class="muted">Generated: ' . date('d-m-Y') . '</div>
                    <div style="margin-top:12px; text-align:right; color:#1e40af; border-left:none; padding-left:0; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.10em;">Company</div>
                    <div class="box" style="text-align:right; margin-top:6px;">
                        <div class="value">' . htmlspecialchars($companyName) . '</div>
                        ' . ($companyEmail ? '<div class="label">Email: <span class="value">' . htmlspecialchars($companyEmail) . '</span></div>' : '') . '
                        ' . ($companyPhone ? '<div class="label">Phone: <span class="value">' . htmlspecialchars($companyPhone) . '</span></div>' : '') . '
                        ' . ($companyPin ? '<div class="label">PIN/Tax ID: <span class="value">' . htmlspecialchars($companyPin) . '</span></div>' : '') . '
                    </div>
                </div><!-- /right-meta -->
            </div><!-- /header-band -->

            <div class="body-pad">
            <div class="section">
                <table style="margin-top:0;">
                    <thead>
                        <tr>
                            <th style="color:#e11d48; border-bottom-color:#e11d48;">Billed To</th>
                            <th style="color:#ea580c; border-bottom-color:#ea580c;">Account</th>
                            <th class="text-right" style="color:#16a34a; border-bottom-color:#16a34a;">Amount Due (' . htmlspecialchars($currency) . ')</th>
                            <th style="color:#0284c7; border-bottom-color:#0284c7;">Due Date</th>
                            <th class="text-right" style="color:#7c3aed; border-bottom-color:#7c3aed;">Prev (m³)</th>
                            <th class="text-right" style="color:#7c3aed; border-bottom-color:#7c3aed;">Current (m³)</th>
                            <th class="text-right" style="color:#db2777; border-bottom-color:#db2777;">Consumption (m³)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>' . htmlspecialchars($user['full_name'] ?? 'Customer') . '</strong></td>
                            <td>' . htmlspecialchars($user['account_number'] ?? '') . '</td>
                            <td class="text-right"><strong>' . number_format($bill['amount'], 2) . '</strong></td>
                            <td>' . htmlspecialchars(date('d-m-Y', strtotime($bill['due_date']))) . '</td>
                            <td class="text-right">' . number_format($bill['previous_reading'], 2) . '</td>
                            <td class="text-right">' . number_format($bill['current_reading'], 2) . '</td>
                            <td class="text-right">' . number_format($bill['consumption'], 2) . '</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="section">
                <div class="section-title">Invoice Details</div>
                <table>
                    <thead>
                        <tr>
                            <th style="color:#ca8a04; border-bottom-color:#ca8a04;">Description</th>
                            <th class="text-right" style="color:#db2777; border-bottom-color:#db2777;">Qty</th>
                            <th class="text-right" style="color:#0284c7; border-bottom-color:#0284c7;">Rate (' . htmlspecialchars($currency) . ')</th>
                            <th class="text-right" style="color:#e11d48; border-bottom-color:#e11d48;">Amount (' . htmlspecialchars($currency) . ')</th>
                        </tr>
                    </thead>
                    <tbody>' . $lineRowsHtml . '</tbody>
                </table>
            </div>

            <div class="section">
                <div style="text-align:right;">
                    <div class="section-title" style="text-align:right;">Summary</div>
                    <table class="summary-table" style="margin-left:auto;">
                        <tr>
                            <td class="summary-label">Subtotal:</td>
                            <td class="summary-value text-right">' . htmlspecialchars($currency) . ' ' . number_format($baseAmount, 2) . '</td>
                        </tr>
                        <tr>
                            <td class="summary-label">VAT (' . number_format($taxRate, 2) . '%):</td>
                            <td class="summary-value text-right">' . htmlspecialchars($currency) . ' ' . number_format($taxAmount, 2) . '</td>
                        </tr>
                        <tr>
                            <td class="summary-total-label">Total Due:</td>
                            <td class="summary-total-value text-right">' . htmlspecialchars($currency) . ' ' . number_format($totalAmount, 2) . '</td>
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
            </div><!-- /body-pad -->
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
