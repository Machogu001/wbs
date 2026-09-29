<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    $paymentId = (int)($_GET['id'] ?? 0);
    if ($paymentId <= 0) {
        mobileApiJson(422, 'error', 'A valid payment ID is required.');
    }

    $stmt = $db->prepare('SELECT p.*, b.account_number, b.billing_month, b.amount AS bill_amount
        FROM payments p
        LEFT JOIN bills b ON b.id = p.bill_id
        WHERE p.id = :id AND p.user_id = :user_id
        LIMIT 1');
    $stmt->execute([
        ':id' => $paymentId,
        ':user_id' => (int)$user['id'],
    ]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$payment) {
        mobileApiJson(404, 'error', 'Payment not found.');
    }

    $paymentData = mobileApiFormatPayment($payment) + [
        'bill_amount' => isset($payment['bill_amount']) ? (float)$payment['bill_amount'] : null,
        'receipt_url' => !empty($payment['bill_id']) ? mobileApiBuildAbsoluteUrl('/payment-receipt?t=' . urlencode(PaymentLink::generateToken((int)$payment['bill_id'])) . '&p=' . (int)$payment['id']) : '',
        'receipt_pdf_url' => !empty($payment['bill_id']) ? mobileApiBuildAbsoluteUrl('/payment-receipt-pdf?t=' . urlencode(PaymentLink::generateToken((int)$payment['bill_id'])) . '&p=' . (int)$payment['id']) : '',
    ];

    mobileApiJson(200, 'success', 'Payment loaded.', ['payment' => $paymentData]);
} catch (Throwable $e) {
    error_log('Mobile API payment detail failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load the payment right now.');
}