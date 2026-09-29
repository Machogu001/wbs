<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('POST');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    $payload = mobileApiReadJson();

    $billId = (int)($payload['bill_id'] ?? 0);
    $phone = trim((string)($payload['phone'] ?? ($user['phone_number'] ?? '')));
    if ($billId <= 0 || $phone === '') {
        mobileApiJson(422, 'error', 'Bill ID and phone number are required.');
    }

    $billService = new Bill($db);
    $paymentService = new Payment($db);
    $bill = $billService->getById($billId, (int)$user['id']);
    if (!$bill) {
        mobileApiJson(404, 'error', 'Bill not found.');
    }

    $amountDue = $paymentService->getBillOutstandingAmount($billId);
    if ($amountDue <= 0.01) {
        mobileApiJson(400, 'error', 'This bill is already fully paid.');
    }

    if (!preg_match('/^(?:254|\+254|0)?((?:7|1)\d{8})$/', $phone, $matches)) {
        mobileApiJson(422, 'error', 'Invalid phone number format.');
    }

    $formattedPhone = '254' . $matches[1];
    $reference = $billService->isRegistrationFeeBill($bill)
        ? 'Registration Fee'
        : 'Water Bill - ' . date('F Y', strtotime((string)$bill['billing_month']));

    $mpesa = new Mpesa();
    $response = $mpesa->stkPush($formattedPhone, $amountDue, (string)$bill['account_number'], $reference);
    if (isset($response['error'])) {
        $details = (string)$response['error'];
        if (!empty($response['details']['errorMessage'])) {
            $details .= ': ' . (string)$response['details']['errorMessage'];
        }
        mobileApiJson(400, 'error', 'Payment initiation failed: ' . $details);
    }

    $payment = $paymentService;
    $payment->bill_id = $billId;
    $payment->user_id = (int)$user['id'];
    $payment->phone_number = $formattedPhone;
    $payment->amount = $amountDue;
    $payment->merchant_request_id = $response['MerchantRequestID'] ?? null;
    $payment->checkout_request_id = $response['CheckoutRequestID'] ?? null;
    $payment->status = 'pending';
    $payment->registration_id = $billService->isRegistrationFeeBill($bill) ? (int)$user['id'] : null;

    if (!$payment->create()) {
        mobileApiJson(500, 'error', 'Failed to save the payment request.');
    }

    mobileApiJson(200, 'success', 'Payment initiated successfully.', [
        'payment' => [
            'payment_id' => (int)$payment->id,
            'bill_id' => $billId,
            'amount' => $amountDue,
            'checkout_request_id' => (string)($response['CheckoutRequestID'] ?? ''),
            'merchant_request_id' => (string)($response['MerchantRequestID'] ?? ''),
            'phone_number' => $formattedPhone,
        ],
    ]);
} catch (Throwable $e) {
    error_log('Mobile API payment initiation failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to initiate payment right now.');
}