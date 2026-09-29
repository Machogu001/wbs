<?php

require_once __DIR__ . '/../_bootstrap.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $user, ['view_customers', 'view_payments', 'view_bill_detail']);

    $userService = new User($db);
    $client = null;
    $clientId = (int)($_GET['id'] ?? 0);
    $accountNumber = trim((string)($_GET['account_number'] ?? ''));
    $meterNumber = trim((string)($_GET['meter_number'] ?? ''));

    if ($clientId > 0) {
        $client = $userService->getById($clientId);
    } elseif ($accountNumber !== '') {
        $client = $userService->getByAccountNumber($accountNumber);
    } elseif ($meterNumber !== '') {
        $client = $userService->getByMeterNumber($meterNumber);
    }

    if (!$client) {
        mobileApiJson(404, 'error', 'Customer not found.');
    }

    $billService = new Bill($db);
    $paymentService = new Payment($db);
    $bills = $billService->getBillsByUser((int)$client['id']);
    $payments = $paymentService->getCompletedPaymentsByUserId((int)$client['id'], 20);
    $summary = [
        'bill_count' => count($bills),
        'payment_count' => count($payments),
        'outstanding_amount' => 0.0,
    ];
    foreach ($bills as $bill) {
        $summary['outstanding_amount'] += $paymentService->getBillOutstandingAmount((int)($bill['id'] ?? 0));
    }

    mobileApiJson(200, 'success', 'Customer loaded.', [
        'customer' => mobileApiFormatUser($db, $client),
        'summary' => $summary,
        'recent_bills' => array_map(static function (array $bill) use ($billService, $paymentService): array {
            return mobileApiFormatBill($billService, $paymentService, $bill);
        }, array_slice($bills, 0, 10)),
        'recent_payments' => array_map('mobileApiFormatPayment', array_slice($payments, 0, 10)),
    ]);
} catch (Throwable $e) {
    error_log('Mobile API admin customer detail failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load customer details right now.');
}