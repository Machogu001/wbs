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
$fromPeriod = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
$toPeriod = isset($_GET['to']) ? trim((string)$_GET['to']) : '';

if (!preg_match('/^\d{4}-\d{2}$/', $fromPeriod)) {
    $fromPeriod = '';
}
if (!preg_match('/^\d{4}-\d{2}$/', $toPeriod)) {
    $toPeriod = '';
}

$fromDate = $fromPeriod !== '' ? ($fromPeriod . '-01') : null;
$toDate = $toPeriod !== '' ? date('Y-m-t', strtotime($toPeriod . '-01')) : null;

$bills = $billService->getBillsByUser($_SESSION['user_id']);
if ($fromDate !== null || $toDate !== null) {
    $bills = array_values(array_filter($bills, function (array $billRow) use ($fromDate, $toDate): bool {
        $month = isset($billRow['billing_month']) ? date('Y-m-01', strtotime((string)$billRow['billing_month'])) : null;
        if (!$month) {
            return false;
        }
        if ($fromDate !== null && $month < $fromDate) {
            return false;
        }
        if ($toDate !== null && $month > $toDate) {
            return false;
        }
        return true;
    }));
}

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
$periodToken = '';
if ($fromPeriod !== '' || $toPeriod !== '') {
    $periodToken = '_' . ($fromPeriod !== '' ? str_replace('-', '', $fromPeriod) : 'start')
        . '_to_'
        . ($toPeriod !== '' ? str_replace('-', '', $toPeriod) : 'now');
}
$filename = 'statement_' . ($user['account_number'] ?? 'account') . $periodToken . '_' . date('Ymd') . '.pdf';

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
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #1e293b; background: #ffffff; }
        .wrapper { padding: 24px 0; }
        .container { max-width: 960px; margin: 0 auto; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0 0 28px 0; overflow: hidden; }
        .header-band { background: #ffffff; color: #1e293b; padding: 22px 28px 16px 28px; display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 0; border-bottom: 3px solid #1e40af; }
        .header-band .muted { color: #64748b; font-size: 12px; }
        .header-band .label { color: #64748b; font-size: 12px; }
        .header-band .value { font-weight: 600; font-size: 13px; color: #1e293b; }
        .body-pad { padding: 0 28px; }
        .statement-headline { text-align: center; padding: 22px 28px 6px 28px; background: #ffffff; }
        .tag { font-size: 11px; letter-spacing: 0.10em; text-transform: uppercase; color: #1e40af; margin-bottom: 4px; }
        .title { font-size: 24px; font-weight: 700; color: #1e40af; }
        .muted { color: #64748b; font-size: 12px; }
        .section { margin-bottom: 18px; }
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
        .footer-note { margin-top: 22px; font-size: 10px; color: #64748b; text-align: center; border-top: 1px solid #cbd5e1; padding-top: 12px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .badge-green { background: #ffffff; color: #166534; border: 1px solid #166534; }
        .badge-red { background: #ffffff; color: #b91c1c; border: 1px solid #b91c1c; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <div class="header-band" style="display:flex; justify-content:space-between; align-items:flex-start;">
                <div style="text-align:center; flex:1;">
                    <div class="title" style="letter-spacing:0.06em;">
                        <span style="color:#e11d48;">A</span><span style="color:#ea580c;">c</span><span style="color:#ca8a04;">c</span><span style="color:#16a34a;">o</span><span style="color:#0284c7;">u</span><span style="color:#7c3aed;">n</span><span style="color:#db2777;">t</span><span style="color:#1e293b;"> </span><span style="color:#e11d48;">S</span><span style="color:#ea580c;">t</span><span style="color:#ca8a04;">a</span><span style="color:#16a34a;">t</span><span style="color:#0284c7;">e</span><span style="color:#7c3aed;">m</span><span style="color:#db2777;">e</span><span style="color:#e11d48;">n</span><span style="color:#ea580c;">t</span>
                    </div>
                    <div class="tag" style="margin-top:4px;">CUSTOMER STATEMENT</div>
                    <div class="muted">Account: <strong style="color:#1e293b;">' . htmlspecialchars($user['account_number'] ?? '') . '</strong></div>
                </div>
                <div style="text-align:right;">
                    <div class="muted">Generated: ' . date('Y-m-d') . '</div>
                    ' . (($fromPeriod !== '' || $toPeriod !== '')
                        ? '<div class="muted">Period: '
                            . htmlspecialchars($fromPeriod !== '' ? date('M Y', strtotime($fromPeriod . '-01')) : 'Beginning')
                            . ' - '
                            . htmlspecialchars($toPeriod !== '' ? date('M Y', strtotime($toPeriod . '-01')) : 'Present')
                            . '</div>'
                        : '') . '
                    <div style="margin-top:12px; text-align:right; color:#1e40af; border-left:none; padding-left:0; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.10em;">Company</div>
                    <div class="box" style="text-align:right; margin-top:6px;">
                        <div class="value">' . htmlspecialchars($companyName) . '</div>
                        ' . ($companyEmail ? '<div class="label">Email: <span class="value">' . htmlspecialchars($companyEmail) . '</span></div>' : '') . '
                        ' . ($companyPhone ? '<div class="label">Phone: <span class="value">' . htmlspecialchars($companyPhone) . '</span></div>' : '') . '
                        ' . ($companyPin ? '<div class="label">PIN/Tax ID: <span class="value">' . htmlspecialchars($companyPin) . '</span></div>' : '') . '
                    </div>
                </div><!-- /right side -->
            </div><!-- /header-band -->

            <div class="body-pad">
            <div class="section" style="display:flex; gap:18px; align-items:flex-start;">
                <div style="flex:1;">
                    <table style="margin-top:0;">
                        <thead>
                            <tr>
                                <th style="color:#e11d48; border-bottom-color:#e11d48;">Client</th>
                                <th style="color:#ea580c; border-bottom-color:#ea580c;">Account</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>' . htmlspecialchars($user['full_name'] ?? 'Customer') . '</strong></td>
                                <td>' . htmlspecialchars($user['account_number'] ?? '') . '</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="section">
                <div class="section-title">Billing History</div>
                <table>
                    <thead>
                        <tr>
                            <th style="color:#e11d48; border-bottom-color:#e11d48;">Billing Month</th>
                            <th class="text-right" style="color:#16a34a; border-bottom-color:#16a34a;">Amount (' . htmlspecialchars($currency) . ')</th>
                            <th style="color:#0284c7; border-bottom-color:#0284c7;">Status</th>
                            <th style="color:#7c3aed; border-bottom-color:#7c3aed;">Due Date</th>
                        </tr>
                    </thead>
                    <tbody>' . $rows . '</tbody>
                </table>
            </div>

            <div class="section" style="text-align:right;">
                <table class="summary-table" style="margin-top:0; margin-left:auto;">
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
