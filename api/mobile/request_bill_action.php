<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/FinanceApproval.php';

try {
    mobileApiRequireMethod('POST');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    $data = mobileApiReadJson();

    $billId = (int)($data['bill_id'] ?? 0);
    $action = trim((string)($data['action'] ?? ''));
    if ($billId <= 0 || $action === '') {
        mobileApiJson(422, 'error', 'Bill ID and action are required.');
    }

    $billService = new Bill($db);
    $bill = $billService->getById($billId, (int)$user['id']);
    if (!$bill) {
        mobileApiJson(404, 'error', 'Bill not found.');
    }

    $financeApproval = new FinanceApproval($db);
    $reason = trim((string)($data['reason'] ?? ''));
    $result = false;

    if (in_array($action, ['writeoff', 'waiver'], true)) {
        $amount = (float)($data['amount'] ?? 0);
        $result = $financeApproval->createBillWriteOffRequest(
            $billId,
            $amount,
            (int)$user['id'],
            $reason,
            $action === 'waiver' ? 'waiver' : 'writeoff'
        );
    } elseif ($action === 'installment') {
        $amount = (float)($data['amount'] ?? 0);
        $installmentCount = (int)($data['installment_count'] ?? 3);
        $frequency = trim((string)($data['frequency'] ?? 'monthly'));
        $startDate = trim((string)($data['start_date'] ?? date('Y-m-d')));
        $result = $financeApproval->createInstallmentPlanRequest(
            $billId,
            (int)$user['id'],
            $amount,
            $installmentCount,
            $frequency,
            $startDate,
            $reason
        );
    } else {
        mobileApiJson(422, 'error', 'Unsupported bill action.');
    }

    if (!$result) {
        mobileApiJson(422, 'error', 'The request could not be submitted. Check the amount, dates, and outstanding balance.');
    }

    mobileApiJson(200, 'success', 'Request submitted successfully.');
} catch (Throwable $e) {
    error_log('Mobile API bill action request failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to submit the request right now.');
}