<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/Accounting.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $user, ['view_accounting']);
    $accounting = new Accounting($db);
    $from = trim((string)($_GET['recon_from'] ?? date('Y-01-01')));
    $to = trim((string)($_GET['recon_to'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-01-01'); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $to = date('Y-m-d'); }

    mobileApiJson(200, 'success', 'Accounting reports loaded.', [
        'recon_from' => $from,
        'recon_to' => $to,
        'balance_sheet' => $accounting->getBalanceSheet($to),
        'profit_and_loss' => $accounting->getProfitAndLoss($from, $to),
        'cash_flow' => $accounting->getCashFlow($from, $to),
        'accounts_receivable_aging' => $accounting->getAccountsReceivableAging(),
    ]);
} catch (Throwable $e) {
    error_log('Mobile API admin accounting reports failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load accounting reports right now.');
}