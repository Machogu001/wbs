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
        $selectedType = trim((string)($_GET['type'] ?? ''));
        $allowedTypes = ['asset', 'liability', 'equity', 'revenue', 'expense', 'cost_of_sales'];
        if (!in_array($selectedType, $allowedTypes, true)) {
            $selectedType = '';
        }
        $reconFromDate = trim((string)($_GET['recon_from'] ?? date('Y-m-01')));
        $reconToDate = trim((string)($_GET['recon_to'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reconFromDate)) { $reconFromDate = date('Y-m-01'); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reconToDate)) { $reconToDate = date('Y-m-d'); }
        mobileApiJson(200, 'success', 'Accounting overview loaded.', [
            'selected_type' => $selectedType,
            'recon_from' => $reconFromDate,
            'recon_to' => $reconToDate,
            'summary' => $accounting->getSummary(),
            'accounts' => $selectedType !== '' ? array_values(array_filter($accounting->getAccounts(false), static fn(array $a): bool => (string)($a['account_type'] ?? '') === $selectedType)) : $accounting->getAccounts(false),
            'trial_balance' => $accounting->getTrialBalance(),
            'reconciliation' => $accounting->getReconciliationSummary($reconFromDate, $reconToDate),
            'period_locks' => $accounting->getPeriodLocks(24),
        ]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $action = trim((string)($data['action'] ?? ''));
        if ($action === 'save_account') {
            $accountId = (int)($data['account_id'] ?? 0);
            $code = trim((string)($data['code'] ?? ''));
            $name = trim((string)($data['name'] ?? ''));
            $type = trim((string)($data['account_type'] ?? ''));
            $normalBalance = trim((string)($data['normal_balance'] ?? 'debit'));
            $parentId = (int)($data['parent_id'] ?? 0);
            $description = trim((string)($data['description'] ?? ''));
            $isActive = !empty($data['is_active']) ? 1 : 0;
            if ($code === '' || $name === '' || $type === '') {
                mobileApiJson(422, 'error', 'Code, name, and type are required.');
            }
            if ($accountId > 0) {
                $accounting->updateAccount($accountId, $name, $type, $normalBalance, $parentId > 0 ? $parentId : null, $description !== '' ? $description : null, $isActive);
                mobileApiJson(200, 'success', 'Account updated.');
            }
            $accounting->createAccount($code, $name, $type, $normalBalance, $parentId > 0 ? $parentId : null, $description !== '' ? $description : null, 0);
            mobileApiJson(201, 'success', 'Account created.');
        }

        if ($action === 'toggle_account') {
            $accountId = (int)($data['account_id'] ?? 0);
            $isActive = !empty($data['is_active']) ? 1 : 0;
            if ($accountId <= 0) {
                mobileApiJson(422, 'error', 'Invalid account.');
            }
            $accounting->setAccountStatus($accountId, $isActive);
            mobileApiJson(200, 'success', $isActive ? 'Account activated.' : 'Account deactivated.');
        }

        if ($action === 'post_entry') {
            $entryDate = trim((string)($data['entry_date'] ?? date('Y-m-d')));
            $memo = trim((string)($data['memo'] ?? ''));
            $debitAccountId = (int)($data['debit_account_id'] ?? 0);
            $creditAccountId = (int)($data['credit_account_id'] ?? 0);
            $amount = (float)($data['amount'] ?? 0);
            $referenceType = trim((string)($data['reference_type'] ?? 'manual'));
            $referenceId = (int)($data['reference_id'] ?? 0);
            if ($debitAccountId <= 0 || $creditAccountId <= 0 || $amount <= 0) {
                mobileApiJson(422, 'error', 'Debit account, credit account, and amount are required.');
            }
            $entryId = $accounting->postJournalEntry($entryDate, $memo !== '' ? $memo : 'Manual journal entry', [
                ['account_id' => $debitAccountId, 'debit' => $amount, 'credit' => 0, 'memo' => $memo],
                ['account_id' => $creditAccountId, 'debit' => 0, 'credit' => $amount, 'memo' => $memo],
            ], $referenceType !== '' ? $referenceType : 'manual', $referenceId > 0 ? $referenceId : null, (int)$user['id']);
            mobileApiJson(201, 'success', 'Journal entry posted.', ['entry_id' => $entryId]);
        }

        if ($action === 'lock_period' || $action === 'unlock_period') {
            $periodKey = trim((string)($data['period_key'] ?? ''));
            $note = trim((string)($data['note'] ?? ''));
            if ($periodKey === '') {
                mobileApiJson(422, 'error', 'A period key is required.');
            }
            if ($action === 'lock_period') {
                $accounting->lockPeriod($periodKey, (int)$user['id'], $note !== '' ? $note : null);
                mobileApiJson(200, 'success', 'Accounting period locked.');
            }
            $accounting->unlockPeriod($periodKey, (int)$user['id'], $note !== '' ? $note : null);
            mobileApiJson(200, 'success', 'Accounting period unlocked.');
        }

        mobileApiJson(422, 'error', 'Unsupported accounting action.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin accounting overview failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process accounting overview right now.');
}