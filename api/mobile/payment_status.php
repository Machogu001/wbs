<?php

require_once __DIR__ . '/_bootstrap.php';

try {
	mobileApiRequireMethod('GET');
	$db = mobileApiGetDatabase();
	$user = mobileApiRequireUser($db);

	$paymentId = (int)($_GET['payment_id'] ?? $_GET['id'] ?? 0);
	$billId = (int)($_GET['bill_id'] ?? 0);
	$checkoutRequestId = trim((string)($_GET['checkout_request_id'] ?? ''));

	if ($paymentId <= 0 && $billId <= 0 && $checkoutRequestId === '') {
		mobileApiJson(422, 'error', 'Provide payment_id, checkout_request_id, or bill_id.');
	}

	$query = 'SELECT p.*, b.account_number, b.billing_month, b.amount AS bill_amount, b.status AS bill_status, b.due_date
		FROM payments p
		LEFT JOIN bills b ON b.id = p.bill_id
		WHERE p.user_id = :user_id';
	$params = [':user_id' => (int)$user['id']];

	if ($paymentId > 0) {
		$query .= ' AND p.id = :payment_id';
		$params[':payment_id'] = $paymentId;
	} elseif ($checkoutRequestId !== '') {
		$query .= ' AND p.checkout_request_id = :checkout_request_id';
		$params[':checkout_request_id'] = $checkoutRequestId;
	} else {
		$query .= ' AND p.bill_id = :bill_id';
		$params[':bill_id'] = $billId;
	}

	$query .= ' ORDER BY p.id DESC LIMIT 1';
	$stmt = $db->prepare($query);
	$stmt->execute($params);
	$payment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	if (!$payment) {
		mobileApiJson(404, 'error', 'Payment status not found.');
	}

	$paymentService = new Payment($db);
	$outstandingAmount = !empty($payment['bill_id']) ? $paymentService->getBillOutstandingAmount((int)$payment['bill_id']) : 0.0;
	$isFinal = strtolower((string)($payment['status'] ?? 'pending')) !== 'pending';
	$paymentData = mobileApiFormatPayment($payment) + [
		'bill_amount' => isset($payment['bill_amount']) ? (float)$payment['bill_amount'] : null,
		'bill_status' => (string)($payment['bill_status'] ?? ''),
		'due_date' => (string)($payment['due_date'] ?? ''),
		'outstanding_amount' => $outstandingAmount,
		'is_final' => $isFinal,
		'is_successful' => strtolower((string)($payment['status'] ?? '')) === 'completed',
		'receipt_url' => !empty($payment['bill_id']) ? mobileApiBuildAbsoluteUrl('/payment-receipt?t=' . urlencode(PaymentLink::generateToken((int)$payment['bill_id'])) . '&p=' . (int)$payment['id']) : '',
		'receipt_pdf_url' => !empty($payment['bill_id']) ? mobileApiBuildAbsoluteUrl('/payment-receipt-pdf?t=' . urlencode(PaymentLink::generateToken((int)$payment['bill_id'])) . '&p=' . (int)$payment['id']) : '',
	];

	mobileApiJson(200, 'success', 'Payment status loaded.', [
		'payment' => $paymentData,
		'polling' => [
			'recommended_interval_seconds' => $isFinal ? 0 : 5,
			'should_continue' => !$isFinal,
		],
	]);
} catch (Throwable $e) {
	error_log('Mobile API payment status failed: ' . $e->getMessage());
	mobileApiJson(500, 'error', 'Unable to load the payment status right now.');
}
