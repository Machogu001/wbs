<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);

    $from = trim((string)($_GET['from'] ?? ''));
    $to = trim((string)($_GET['to'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $allowedStatuses = ['pending', 'overdue', 'paid'];
    if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
        mobileApiJson(422, 'error', 'Invalid bill status filter.');
    }

    $billService = new Bill($db);
    $paymentService = new Payment($db);
    $bills = $billService->getBillsByUser((int)$user['id']);
    $payments = $paymentService->getCompletedPaymentsByUserId((int)$user['id'], 200);

    $billRows = [];
    $totals = [
        'billed_amount' => 0.0,
        'paid_amount' => 0.0,
        'outstanding_amount' => 0.0,
        'bill_count' => 0,
        'payment_count' => 0,
    ];

    foreach ($bills as $bill) {
        $billMonth = (string)($bill['billing_month'] ?? '');
        $billStatus = (string)($bill['status'] ?? '');
        if ($status !== '' && $billStatus !== $status) {
            continue;
        }
        if ($from !== '' && $billMonth !== '' && $billMonth < $from) {
            continue;
        }
        if ($to !== '' && $billMonth !== '' && $billMonth > $to) {
            continue;
        }

        $formattedBill = mobileApiFormatBill($billService, $paymentService, $bill);
        $billRows[] = $formattedBill;
        $totals['billed_amount'] += (float)$formattedBill['amount'];
        $totals['paid_amount'] += (float)$formattedBill['paid_amount'];
        $totals['outstanding_amount'] += (float)$formattedBill['outstanding_amount'];
        $totals['bill_count']++;
    }

    $paymentRows = [];
    foreach ($payments as $payment) {
        $paymentDate = substr((string)($payment['transaction_date'] ?? $payment['created_at'] ?? ''), 0, 10);
        if ($from !== '' && $paymentDate !== '' && $paymentDate < $from) {
            continue;
        }
        if ($to !== '' && $paymentDate !== '' && $paymentDate > $to) {
            continue;
        }

        $paymentRows[] = mobileApiFormatPayment($payment);
        $totals['payment_count']++;
    }

    mobileApiJson(200, 'success', 'Statement loaded.', [
        'filters' => [
            'from' => $from,
            'to' => $to,
            'status' => $status,
        ],
        'summary' => $totals,
        'bills' => $billRows,
        'payments' => $paymentRows,
    ]);
} catch (Throwable $e) {
    error_log('Mobile API statement failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load the statement right now.');
}