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

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    header("Location: /bills");
    exit;
}

$billService = new Bill($db);
$bills = $billService->getBillsByUser($_SESSION['user_id']);

$total_paid = 0.0;
$total_unpaid = 0.0;
foreach ($bills as $bill) {
    if ($bill['status'] === 'paid') {
        $total_paid += (float)$bill['amount'];
    } elseif (in_array($bill['status'], ['pending', 'overdue'], true)) {
        $total_unpaid += (float)$bill['amount'];
    }
}

use Dompdf\Dompdf;

$user = $_SESSION['user_data'] ?? [];
$settingsService = new BillingSettings($db);
$settings = $settingsService->getSettings();
$companyName = $settings['company_name'] ?? 'BreMac Consultant Ltd';
$companyEmail = $settings['support_email'] ?? '';
$companyPhone = $settings['support_phone'] ?? '';
$companyPin = $settings['company_pin'] ?? '';
$currency = $settings['currency_code'] ?? 'KES';
$filename = 'statement_' . ($user['account_number'] ?? 'account') . '_' . date('Ymd') . '.pdf';

$rows = '';
if (empty($bills)) {
    $rows = '<tr><td colspan="4" style="text-align:center;">No bills found.</td></tr>';
} else {
    foreach ($bills as $bill) {
        $rows .= '<tr>'
            . '<td>' . htmlspecialchars(date('M Y', strtotime($bill['billing_month']))) . '</td>'
            . '<td>' . number_format($bill['amount'], 2) . '</td>'
            . '<td>' . htmlspecialchars(ucfirst($bill['status'])) . '</td>'
            . '<td>' . htmlspecialchars(date('d-m-Y', strtotime($bill['due_date']))) . '</td>'
            . '</tr>';
    }
}

$html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Account Statement</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #111827; background: #f3f4f6; }
        .wrapper { padding: 24px 0; }
        .container { max-width: 960px; margin: 0 auto; background: #ffffff; border-radius: 6px; box-shadow: 0 1px 3px rgba(15,23,42,0.08); padding: 24px 28px; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 24px; }
        .title-block { }
        .tag { font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: #6b7280; margin-bottom: 4px; }
        .title { font-size: 22px; font-weight: 700; }
        .muted { color: #6b7280; font-size: 12px; }
        .section { margin-bottom: 18px; }
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
        .footer-note { margin-top: 22px; font-size: 10px; color: #6b7280; text-align: center; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .badge-green { background: #dcfce7; color: #166534; }
        .badge-red { background: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <div class="header">
                <div class="title-block">
                    <div class="tag">Customer Statement</div>
                    <div class="title">' . htmlspecialchars($companyName) . '</div>
                    <div class="muted" style="margin-top:6px;">Account: <strong>' . htmlspecialchars($user['account_number'] ?? '') . '</strong></div>
                </div>
                <div style="text-align:right;">
                    <div class="muted">Generated: ' . date('Y-m-d') . '</div>
                </div>
            </div>

            <div class="section">
                <div class="section-title">Issuer</div>
                <div class="box">
                    <div class="value">' . htmlspecialchars($companyName) . '</div>
                    ' . ($companyEmail ? '<div class="label">Email: <span class="value">' . htmlspecialchars($companyEmail) . '</span></div>' : '') . '
                    ' . ($companyPhone ? '<div class="label">Phone: <span class="value">' . htmlspecialchars($companyPhone) . '</span></div>' : '') . '
                    ' . ($companyPin ? '<div class="label">PIN/Tax ID: <span class="value">' . htmlspecialchars($companyPin) . '</span></div>' : '') . '
                </div>
            </div>

            <div class="section" style="display:flex; gap:18px;">
                <div style="flex:1;">
                    <div class="section-title">Customer</div>
                    <div class="box">
                        <div class="value">' . htmlspecialchars($user['full_name'] ?? 'Customer') . '</div>
                        <div class="label">Account: <span class="value">' . htmlspecialchars($user['account_number'] ?? '') . '</span></div>
                    </div>
                </div>
                <div style="flex:1;">
                    <div class="section-title">Summary</div>
                    <div class="box">
                        <div class="label">Total Paid: <span class="value">' . htmlspecialchars($currency) . ' ' . number_format($total_paid, 2) . '</span></div>
                        <div class="label">Total Unpaid: <span class="value">' . htmlspecialchars($currency) . ' ' . number_format($total_unpaid, 2) . '</span></div>
                    </div>
                </div>
            </div>

            <div class="section">
                <div class="section-title">Billing History</div>
                <table>
                    <thead>
                        <tr>
                            <th>Billing Month</th>
                            <th class="text-right">Amount (' . htmlspecialchars($currency) . ')</th>
                            <th>Status</th>
                            <th>Due Date</th>
                        </tr>
                    </thead>
                    <tbody>' . $rows . '</tbody>
                </table>
            </div>

            <div class="section" style="display:flex; justify-content:flex-end;">
                <table class="summary-table">
                    <tr>
                        <td class="summary-label">Total Paid:</td>
                        <td class="summary-value text-right">' . htmlspecialchars($currency) . ' ' . number_format($total_paid, 2) . '</td>
                    </tr>
                    <tr>
                        <td class="summary-label">Total Unpaid:</td>
                        <td class="summary-value text-right">' . htmlspecialchars($currency) . ' ' . number_format($total_unpaid, 2) . '</td>
                    </tr>
                </table>
            </div>

            <div class="footer-note">This statement shows your water billing history for this account.</div>
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
