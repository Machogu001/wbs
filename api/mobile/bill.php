<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    $billId = (int)($_GET['id'] ?? 0);
    if ($billId <= 0) {
        mobileApiJson(422, 'error', 'A valid bill ID is required.');
    }

    $billService = new Bill($db);
    $paymentService = new Payment($db);
    $bill = $billService->getById($billId, (int)$user['id']);
    if (!$bill) {
        mobileApiJson(404, 'error', 'Bill not found.');
    }

    $paymentsStmt = $db->prepare('SELECT p.*, b.account_number, b.billing_month
        FROM payments p
        LEFT JOIN bills b ON b.id = p.bill_id
        WHERE p.user_id = :user_id AND p.bill_id = :bill_id
        ORDER BY p.created_at DESC, p.id DESC');
    $paymentsStmt->execute([
        ':user_id' => (int)$user['id'],
        ':bill_id' => $billId,
    ]);
    $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    mobileApiJson(200, 'success', 'Bill loaded.', [
        'bill' => mobileApiFormatBill($billService, $paymentService, $bill) + [
            'line_items' => $billService->getBillLineItems($billId),
            'payments' => array_map('mobileApiFormatPayment', $payments),
        ],
    ]);
} catch (Throwable $e) {
    error_log('Mobile API bill detail failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load the bill right now.');
}