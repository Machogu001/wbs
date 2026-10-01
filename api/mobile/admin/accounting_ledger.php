<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/Accounting.php';

try {
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $user, ['view_accounting']);
    $accounting = new Accounting($db);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $selectedType = preg_replace('/[^a-z_]/', '', strtolower((string)($_GET['type'] ?? '')));
        $selectedAccountId = (int)($_GET['account_id'] ?? 0);
        $selectedEntryId = (int)($_GET['entry_id'] ?? 0);
        $accounts = $accounting->getAccounts(false);
        if ($selectedType !== '') {
            $accounts = array_values(array_filter(
                $accounts,
                static fn(array $account): bool => (string)($account['account_type'] ?? '') === $selectedType
            ));
        }
        $selectedAccount = $selectedAccountId > 0 ? $accounting->getAccountById($selectedAccountId) : null;
        $accountLedger = $selectedAccountId > 0 ? $accounting->getLedgerByAccount($selectedAccountId) : [];
        $journalEntries = $accounting->getJournalEntries(50);
        $selectedEntry = $selectedEntryId > 0 ? $accounting->getJournalEntryById($selectedEntryId) : null;
        if ($selectedEntry === null && !empty($journalEntries)) {
            $selectedEntry = $accounting->getJournalEntryById((int)$journalEntries[0]['id']);
        }

        mobileApiJson(200, 'success', 'Accounting ledger loaded.', [
            'accounts' => $accounts,
            'selected_account' => $selectedAccount,
            'account_ledger' => $accountLedger,
            'journal_entries' => $journalEntries,
            'selected_entry' => $selectedEntry,
        ]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $action = trim((string)($data['action'] ?? ''));
        if ($action !== 'reverse_entry') {
            mobileApiJson(422, 'error', 'Unsupported ledger action.');
        }
        $entryId = (int)($data['entry_id'] ?? 0);
        $reversalDate = preg_replace('/[^0-9\-]/', '', (string)($data['reversal_date'] ?? date('Y-m-d')));
        if ($entryId <= 0) {
            mobileApiJson(422, 'error', 'A valid journal entry is required.');
        }
        $accounting->reverseJournalEntry($entryId, $reversalDate);
        mobileApiJson(200, 'success', 'Journal entry reversed.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin accounting ledger failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process accounting ledger right now.');
}