<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/CountryDialCode.php';

function mobileApiNextAccountNumber(PDO $db, string $prefix): string
{
	$stmt = $db->prepare('SELECT account_number FROM users WHERE account_number LIKE :prefix ORDER BY id DESC LIMIT 1');
	$stmt->execute([':prefix' => $prefix . '%']);
	$last = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

	$next = 1;
	if ($last && !empty($last['account_number']) && preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', (string)$last['account_number'], $matches)) {
		$next = (int)$matches[1] + 1;
	}

	return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function mobileApiRegistrationCountryCodes(PDO $db): array
{
	$fallback = [
		['value' => '254', 'label' => 'Kenya (+254)'],
		['value' => '256', 'label' => 'Uganda (+256)'],
		['value' => '255', 'label' => 'Tanzania (+255)'],
		['value' => '1', 'label' => 'United States (+1)'],
		['value' => '1', 'label' => 'Canada (+1)'],
		['value' => '44', 'label' => 'United Kingdom (+44)'],
	];

	try {
		$service = new CountryDialCode($db);
		$rows = $service->listActive();
		return !empty($rows) ? $rows : $fallback;
	} catch (Throwable $e) {
		return $fallback;
	}
}

try {
	$db = mobileApiGetDatabase();
	$settingsService = new BillingSettings($db);
	$settings = $settingsService->getSettings();
	$registrationFee = isset($settings['registration_fee']) ? (float)$settings['registration_fee'] : 0.0;
	$enforceLocationAccuracy = !empty($settings['enforce_location_accuracy']);
	$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

	if ($method === 'GET') {
		mobileApiJson(200, 'success', 'Registration form metadata loaded.', [
			'registration_fee' => $registrationFee,
			'enforce_location_accuracy' => $enforceLocationAccuracy,
			'gps_accuracy_max_meters' => 14,
			'country_code_options' => mobileApiRegistrationCountryCodes($db),
			'registration_type_options' => [
				['value' => 'client', 'label' => 'Client'],
				['value' => 'staff', 'label' => 'Office Staff'],
			],
			'customer_type_options' => [
				['value' => 'individual', 'label' => 'Individual / Personal'],
				['value' => 'company', 'label' => 'Company / Organization'],
			],
			'connection_type_options' => [
				['value' => 'domestic', 'label' => 'Domestic'],
				['value' => 'commercial', 'label' => 'Commercial'],
				['value' => 'industrial', 'label' => 'Industrial'],
			],
			'company_name' => (string)($settings['company_name'] ?? ''),
		]);
	}

	mobileApiRequireMethod('POST');
	$data = mobileApiReadJson();

	$registrationType = strtolower(trim((string)($data['registration_type'] ?? 'client')));
	if (!in_array($registrationType, ['client', 'customer', 'staff'], true)) {
		$registrationType = 'client';
	}
	$customerType = strtolower(trim((string)($data['customer_type'] ?? 'individual')));
	if (!in_array($customerType, ['individual', 'company'], true)) {
		$customerType = 'individual';
	}

	$firstName = trim((string)($data['first_name'] ?? ''));
	$middleName = trim((string)($data['middle_name'] ?? ''));
	$lastName = trim((string)($data['last_name'] ?? ''));
	$companyName = trim((string)($data['company_name'] ?? ''));
	$companyRegistrationNumber = trim((string)($data['company_registration_number'] ?? ''));
	$contactPersonName = trim(preg_replace('/\s+/', ' ', $firstName . ' ' . $middleName . ' ' . $lastName));
	$fullNameFromParts = trim(preg_replace('/\s+/', ' ', $firstName . ' ' . $middleName . ' ' . $lastName));
	$fullName = trim((string)($data['full_name'] ?? $fullNameFromParts));
	if ($fullName === '') {
		$fullName = $fullNameFromParts;
	}

	$phoneNumber = mobileApiNormalizePhone((string)($data['phone_country_code'] ?? ''), (string)($data['phone_number_local'] ?? ''));
	if ($phoneNumber === '') {
		$legacyPhone = preg_replace('/\D+/', '', (string)($data['phone_number'] ?? ''));
		$phoneNumber = $legacyPhone !== '' ? $legacyPhone : '';
	}
	$idNumber = trim((string)($data['id_number'] ?? ''));
	$staffUsername = trim((string)($data['username'] ?? ''));

	if ($firstName === '' || $lastName === '') {
		mobileApiJson(422, 'error', 'First name and last name are required.');
	}
	if ($phoneNumber === '') {
		mobileApiJson(422, 'error', 'Phone number is required.');
	}

	$userService = new User($db);
	if ($userService->phoneExists($phoneNumber)) {
		mobileApiJson(422, 'error', 'Phone number already registered.');
	}

	if ($registrationType === 'staff') {
		if ($staffUsername === '') {
			mobileApiJson(422, 'error', 'Username is required for office staff.');
		}
		if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $staffUsername)) {
			mobileApiJson(422, 'error', 'Username must be 3-30 characters and contain only letters, numbers, dot, underscore or hyphen.');
		}
		if ($userService->usernameExists($staffUsername)) {
			mobileApiJson(422, 'error', 'Username already exists. Please choose another username.');
		}

		$accountNumber = mobileApiNextAccountNumber($db, 'STF');
		$tempPassword = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
		$user = new User($db);
		$user->account_number = $accountNumber;
		$user->username = $staffUsername;
		$user->full_name = $fullName;
		$user->phone_number = $phoneNumber;
		$user->email = null;
		$user->id_number = $idNumber !== '' ? $idNumber : null;
		$user->address = null;
		$user->tax_pin = null;
		$user->meter_number = null;
		$user->connection_type = 'domestic';
		$user->location_label = null;
		$user->latitude = null;
		$user->longitude = null;
		$user->password = $tempPassword;
		$user->role = 'reader';
		$user->status = 'active';

		if (!$user->create()) {
			mobileApiJson(500, 'error', 'Unable to register staff user.');
		}

		$companyDisplay = !empty($settings['company_name']) ? (string)$settings['company_name'] : 'BreMac Consultant Ltd';
		$loginUrl = mobileApiBuildAbsoluteUrl('/');
		try {
			(new SMS())->send($user->phone_number, "Dear {$user->full_name},\nYour office staff account has been created successfully.\nUsername: {$user->username}\nAccount No: {$user->account_number}\nTemporary Password: {$tempPassword}\nPlease login and change your password at {$loginUrl}\n{$companyDisplay}", 'account_creation');
		} catch (Throwable $e) {
		}

		mobileApiJson(201, 'success', 'Staff user registered successfully.', [
			'registration_type' => 'staff',
			'account_number' => $accountNumber,
			'username' => $user->username,
			'full_name' => $user->full_name,
			'phone_number' => $user->phone_number,
			'requires_payment' => false,
			'temp_password' => $tempPassword,
		]);
	}

	if ($customerType === 'company') {
		if ($companyName === '' || $companyRegistrationNumber === '') {
			mobileApiJson(422, 'error', 'Company name and company registration number are required for company registration.');
		}
		$fullName = $companyName;
	} elseif ($idNumber === '') {
		mobileApiJson(422, 'error', 'ID number is required for client registration.');
	}

	$email = trim((string)($data['email'] ?? ''));
	$address = trim((string)($data['address'] ?? ''));
	$password = (string)($data['password'] ?? '');
	if ($email === '' || $address === '' || $password === '') {
		mobileApiJson(422, 'error', 'Email, address, and password are required for client registration.');
	}
	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
		mobileApiJson(422, 'error', 'Please enter a valid email address.');
	}

	$conflictSql = 'SELECT id FROM users WHERE email = :email';
	$conflictParams = [':email' => $email];
	if ($customerType === 'company') {
		$conflictSql .= ' OR company_registration_number = :company_registration_number';
		$conflictParams[':company_registration_number'] = $companyRegistrationNumber;
	} else {
		$conflictSql .= ' OR id_number = :id_number';
		$conflictParams[':id_number'] = $idNumber;
	}
	$stmtConflict = $db->prepare($conflictSql . ' LIMIT 1');
	$stmtConflict->execute($conflictParams);
	if ($stmtConflict->fetch(PDO::FETCH_ASSOC)) {
		mobileApiJson(422, 'error', $customerType === 'company' ? 'A user with the same email address or company registration number already exists.' : 'A user with the same email address or ID number already exists.');
	}

	if ($enforceLocationAccuracy) {
		$submittedAccuracy = isset($data['gps_accuracy']) && $data['gps_accuracy'] !== '' ? (float)$data['gps_accuracy'] : null;
		$hasLat = isset($data['latitude']) && $data['latitude'] !== '';
		$hasLng = isset($data['longitude']) && $data['longitude'] !== '';
		$locationLabel = trim((string)($data['location_label'] ?? ''));
		if ($locationLabel === '') {
			mobileApiJson(422, 'error', 'Location is required when GPS enforcement is enabled.');
		}
		if (!$hasLat || !$hasLng || $submittedAccuracy === null || $submittedAccuracy > 14) {
			mobileApiJson(422, 'error', 'GPS location with accuracy <=14m is required. Please capture your current GPS location outdoors and try again.');
		}
	}

	$accountNumber = mobileApiNextAccountNumber($db, 'MTR');
	$user = new User($db);
	$user->account_number = $accountNumber;
	$user->username = null;
	$user->full_name = $fullName;
	$user->customer_type = $customerType;
	$user->company_name = $customerType === 'company' ? $companyName : null;
	$user->contact_person_name = $customerType === 'company' ? $contactPersonName : null;
	$user->company_registration_number = $customerType === 'company' ? $companyRegistrationNumber : null;
	$user->phone_number = $phoneNumber;
	$user->email = $email;
	$user->id_number = $customerType === 'company' ? ($idNumber !== '' ? $idNumber : null) : $idNumber;
	$user->address = $address;
	$user->tax_pin = trim((string)($data['tax_pin'] ?? '')) ?: null;
	$user->meter_number = $accountNumber;
	$user->connection_type = (string)($data['connection_type'] ?? 'domestic');
	$user->location_label = trim((string)($data['location_label'] ?? '')) ?: null;
	$user->latitude = isset($data['latitude']) && $data['latitude'] !== '' ? (float)$data['latitude'] : null;
	$user->longitude = isset($data['longitude']) && $data['longitude'] !== '' ? (float)$data['longitude'] : null;
	$user->password = $password;
	$user->role = 'customer';

	if ($registrationFee > 0) {
		if (!preg_match('/^(?:254|\+254|0)?((?:7|1)\d{8})$/', $phoneNumber, $matches)) {
			mobileApiJson(422, 'error', 'Invalid phone number format for M-Pesa payment.');
		}
		$formattedPhone = '254' . $matches[1];
		$response = (new Mpesa())->stkPush($formattedPhone, $registrationFee, $accountNumber, 'Registration Fee');
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

		$user->status = 'inactive';
		if (!$user->create()) {
			mobileApiJson(500, 'error', 'Unable to register user.');
		}

		$billService = new Bill($db);
		$dueDate = date('Y-m-d', strtotime('+14 days'));
		$billId = $billService->createRegistrationFeeBill((int)$user->id, $user->account_number, $registrationFee, $dueDate, 'pending');

		$payment = new Payment($db);
		$payment->bill_id = $billId;
		$payment->user_id = $user->id;
		$payment->phone_number = $formattedPhone;
		$payment->amount = $registrationFee;
		$payment->merchant_request_id = $response['MerchantRequestID'] ?? null;
		$payment->checkout_request_id = $response['CheckoutRequestID'] ?? null;
		$payment->status = 'pending';
		$payment->registration_id = $user->id;
		$payment->create();

		mobileApiJson(201, 'success', 'Client registration initiated. Complete M-Pesa payment to activate account.', [
			'registration_type' => 'client',
			'account_number' => $accountNumber,
			'full_name' => $user->full_name,
			'phone_number' => $user->phone_number,
			'requires_payment' => true,
			'amount' => $registrationFee,
			'payment' => [
				'payment_id' => (int)$payment->id,
				'checkout_request_id' => (string)($payment->checkout_request_id ?? ''),
				'merchant_request_id' => (string)($payment->merchant_request_id ?? ''),
			],
		]);
	}

	$user->status = 'active';
	if (!$user->create()) {
		mobileApiJson(500, 'error', 'Unable to register user.');
	}

	$companyDisplay = !empty($settings['company_name']) ? (string)$settings['company_name'] : 'BreMac Consultant Ltd';
	$loginUrl = mobileApiBuildAbsoluteUrl('/');
	$messageText = "Dear {$user->full_name},\nYour water account has been created successfully.\nAccount No: {$user->account_number}\nMeter No: {$user->meter_number}\nYou can now log in at {$loginUrl} using your account number, phone, email or username to view your bills and make payments.\n{$companyDisplay}";
	try {
		(new SMS())->send($user->phone_number, $messageText, 'registration');
	} catch (Throwable $e) {
	}
	if (!empty($user->email)) {
		try {
			(new Email())->send($user->email, 'Your new water account details', $messageText);
		} catch (Throwable $e) {
		}
	}

	mobileApiJson(201, 'success', 'Client registered successfully.', [
		'registration_type' => 'client',
		'account_number' => $accountNumber,
		'full_name' => $user->full_name,
		'phone_number' => $user->phone_number,
		'requires_payment' => false,
	]);
} catch (InvalidArgumentException $e) {
	mobileApiJson(422, 'error', $e->getMessage());
} catch (Throwable $e) {
	error_log('Mobile API registration failed: ' . $e->getMessage());
	mobileApiJson(500, 'error', 'Unable to process registration right now.');
}
