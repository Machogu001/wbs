<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/Accounting.php';

try {
    $db = mobileApiGetDatabase();
    $actor = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $actor, ['view_accounting']);
    $accounting = new Accounting($db);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        mobileApiJson(200, 'success', 'Accounting transfers loaded.', [
            'accounts' => $accounting->getAccounts(),
            'transfers' => $accounting->getTransfers(50),
        ]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        if ((string)($data['action'] ?? '') !== 'post_transfer') {
            mobileApiJson(422, 'error', 'Unsupported transfer action.');
        }
        $transferDate = preg_replace('/[^0-9\-]/', '', (string)($data['transfer_date'] ?? date('Y-m-d')));
        $fromAccountId = (int)($data['from_account_id'] ?? 0);
        $toAccountId = (int)($data['to_account_id'] ?? 0);
        $amount = (float)($data['amount'] ?? 0);
        $memo = htmlspecialchars_decode(strip_tags((string)($data['memo'] ?? '')));
        if ($fromAccountId <= 0 || $toAccountId <= 0 || $amount <= 0 || $fromAccountId === $toAccountId) {
            mobileApiJson(422, 'error', 'Invalid transfer: check accounts and amount.');
        }
        $accounting->postTransfer($transferDate, $fromAccountId, $toAccountId, $amount, $memo, (int)$actor['id']);
        mobileApiJson(200, 'success', 'Transfer posted successfully.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin accounting transfers failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process accounting transfers right now.');
}