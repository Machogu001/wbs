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
        $budgetYear = preg_replace('/[^0-9]/', '', (string)($_GET['budget_year'] ?? date('Y')));
        $budgetAccounts = array_values(array_filter($accounting->getAccounts(), static function (array $acct): bool {
            return !empty($acct['is_active']);
        }));
        mobileApiJson(200, 'success', 'Accounting budget loaded.', [
            'budget_year' => $budgetYear,
            'budget_accounts' => $budgetAccounts,
            'budget_vs_actual' => $accounting->getBudgetVsActual($budgetYear),
        ]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        if ((string)($data['action'] ?? '') !== 'save_budget') {
            mobileApiJson(422, 'error', 'Unsupported budget action.');
        }
        $budgetAccountId = (int)($data['budget_account_id'] ?? 0);
        $financialYear = preg_replace('/[^0-9]/', '', (string)($data['financial_year'] ?? date('Y')));
        $budgetMode = (string)($data['budget_mode'] ?? 'monthly');
        $elimDecimals = !empty($data['eliminate_decimals']);
        $months = [];
        if ($budgetMode === 'monthly') {
            $months = (array)($data['months'] ?? []);
        } elseif ($budgetMode === 'quarterly') {
            $quarters = (array)($data['quarters'] ?? []);
            for ($q = 1; $q <= 4; $q++) {
                $qVal = (float)($quarters[$q] ?? $quarters[(string)$q] ?? 0);
                $perMonth = $elimDecimals ? floor($qVal / 3) : round($qVal / 3, 2);
                $startMonth = ($q - 1) * 3 + 1;
                for ($m = $startMonth; $m < $startMonth + 3; $m++) {
                    $months[$m] = $perMonth;
                }
            }
        } elseif ($budgetMode === 'yearly') {
            $yearlyBudget = (float)($data['yearly_budget'] ?? 0);
            $perMonth = $elimDecimals ? floor($yearlyBudget / 12) : round($yearlyBudget / 12, 2);
            for ($m = 1; $m <= 12; $m++) {
                $months[$m] = $perMonth;
            }
        }
        $normalizedMonths = [];
        for ($m = 1; $m <= 12; $m++) {
            $normalizedMonths[$m] = (float)($months[$m] ?? $months[(string)$m] ?? 0);
        }
        $accounting->saveBudget($budgetAccountId, $financialYear, $normalizedMonths);
        mobileApiJson(200, 'success', 'Budget saved.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin accounting budget failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process accounting budget right now.');
}