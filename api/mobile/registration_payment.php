<?php

require_once __DIR__ . '/_bootstrap.php';

try {
	$db = mobileApiGetDatabase();
	$user = mobileApiRequireUser($db);
	$settingsService = new BillingSettings($db);
	$settings = $settingsService->getSettings();
	$registrationFee = isset($settings['registration_fee']) ? (float)$settings['registration_fee'] : 0.0;
	$paymentService = new Payment($db);
	$billService = new Bill($db);
	$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

	if ($method === 'GET') {
		$registrationPayment = mobileApiGetRegistrationPaymentData($db, (int)$user['id']);
		$currentUser = (new User($db))->getById((int)$user['id']);
		$latestPayment = $paymentService->getLatestRegistrationByUserId((int)$user['id']);
		$registrationBalance = $registrationFee;
		$registrationBillStatus = null;
		$registrationFullySettled = false;

		if ($latestPayment && !empty($latestPayment['bill_id'])) {
			$registrationBalance = $paymentService->getBillOutstandingAmount((int)$latestPayment['bill_id']);
			$billRow = $billService->getById((int)$latestPayment['bill_id']);
			$registrationBillStatus = (string)($billRow['status'] ?? 'pending');
		}

		if ($registrationBalance <= 0.01 && ((string)($currentUser['status'] ?? 'active') === 'active' || $registrationBillStatus === 'paid')) {
			$registrationBalance = 0.0;
			$registrationFullySettled = true;
		}

		mobileApiJson(200, 'success', 'Registration payment status loaded.', [
			'requires_registration_payment' => !$registrationFullySettled,
			'registration_fee' => $registrationFee,
			'registration_balance' => max(0, (float)$registrationBalance),
			'bill_status' => $registrationBillStatus ?: 'pending',
			'registration_fully_settled' => $registrationFullySettled,
			'latest_payment' => $latestPayment ? mobileApiFormatPayment($latestPayment) : null,
			'registration_payment' => $registrationPayment,
		]);
	}

	mobileApiRequireMethod('POST');
	$data = mobileApiReadJson();
	$action = trim((string)($data['action'] ?? 'initiate'));
	if (!in_array($action, ['initiate', 'resend_stk'], true)) {
		mobileApiJson(422, 'error', 'Unsupported registration payment action.');
	}

	if (isset($user['role']) && (string)$user['role'] === 'admin') {
		mobileApiJson(422, 'error', 'Registration fee does not apply to admin accounts.');
	}
	if ($registrationFee <= 0) {
		mobileApiJson(422, 'error', 'Registration fee is not configured.');
	}

	$latestRegistration = $paymentService->getLatestRegistrationByUserId((int)$user['id']);
	$billId = null;
	$amountToCharge = round($registrationFee, 2);
	if ($latestRegistration && !empty($latestRegistration['bill_id'])) {
		$existingBill = $billService->getById((int)$latestRegistration['bill_id']);
		if ($existingBill && isset($existingBill['status'])) {
			if ((string)$existingBill['status'] === 'paid') {
				mobileApiJson(422, 'error', 'Registration fee already paid. Your account should now be active.');
			}
			$billId = (int)$latestRegistration['bill_id'];
			$amountToCharge = $paymentService->getBillOutstandingAmount($billId);
		}
	}

	if (!$billId) {
		$dueDate = date('Y-m-d', strtotime('+14 days'));
		$billId = $billService->createRegistrationFeeBill((int)$user['id'], (string)$user['account_number'], $registrationFee, $dueDate, 'pending');
	}
	if ($amountToCharge <= 0.01) {
		mobileApiJson(422, 'error', 'Registration fee already paid. Your account should now be active.');
	}

	$phoneNumber = trim((string)($data['phone'] ?? ($user['phone_number'] ?? '')));
	if (!preg_match('/^(?:254|\+254|0)?((?:7|1)\d{8})$/', $phoneNumber, $matches)) {
		mobileApiJson(422, 'error', 'Invalid or missing phone number for M-Pesa payment.');
	}
	$formattedPhone = '254' . $matches[1];

	$response = (new Mpesa())->stkPush($formattedPhone, $amountToCharge, (string)$user['account_number'], 'Registration Fee');
	if (isset($response['error'])) {
		$details = '';
		if (isset($response['http_code'])) {
			$details .= ' (HTTP ' . $response['http_code'] . ')';
		}
		if (isset($response['details']) && is_array($response['details'])) {
			if (!empty($response['details']['errorMessage'])) {
				$details .= ': ' . (string)$response['details']['errorMessage'];
			} elseif (!empty($response['details']['errorCode'])) {
				$details .= ' (Code ' . (string)$response['details']['errorCode'] . ')';
			}
		}
		mobileApiJson(400, 'error', 'Payment initiation failed: ' . (string)$response['error'] . $details);
	}

	$payment = new Payment($db);
	$payment->bill_id = $billId;
	$payment->user_id = (int)$user['id'];
	$payment->phone_number = $formattedPhone;
	$payment->amount = $amountToCharge;
	$payment->merchant_request_id = $response['MerchantRequestID'] ?? null;
	$payment->checkout_request_id = $response['CheckoutRequestID'] ?? null;
	$payment->status = 'pending';
	$payment->registration_id = (int)$user['id'];
	if (!$payment->create()) {
		mobileApiJson(500, 'error', 'Failed to save registration payment request.');
	}

	mobileApiJson(200, 'success', 'Registration payment initiated. Please approve the M-Pesa prompt on your phone.', [
		'amount' => $amountToCharge,
		'payment' => [
			'payment_id' => (int)$payment->id,
			'checkout_request_id' => (string)($payment->checkout_request_id ?? ''),
			'merchant_request_id' => (string)($payment->merchant_request_id ?? ''),
			'phone_number' => $formattedPhone,
			'bill_id' => (int)$billId,
		],
	]);
} catch (InvalidArgumentException $e) {
	mobileApiJson(422, 'error', $e->getMessage());
} catch (Throwable $e) {
	error_log('Mobile API registration payment failed: ' . $e->getMessage());
	mobileApiJson(500, 'error', 'Unable to process registration payment right now.');
}
