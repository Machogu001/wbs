<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/ClientMeter.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';
require_once __DIR__ . '/../../includes/Mpesa.php';
require_once __DIR__ . '/../../includes/Payment.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/SMS.php';
require_once __DIR__ . '/../../includes/Email.php';
require_once __DIR__ . '/../../includes/PaymentLink.php';
require_once __DIR__ . '/../../includes/CountryDialCode.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if(!$auth->isLoggedIn() || (!$auth->isAdmin() && !$auth->hasPermission('view_customers'))) {
	header("Location: /login");
	exit;
}

$isAdminUser = $auth->isAdmin();
$usersCsrfToken = (string)($_SESSION['app_csrf_token'] ?? '');

// Load registration fee setting for display and logic
$settingsService = new BillingSettings($db);
$settings = $settingsService->getSettings();
$registrationFee = isset($settings['registration_fee']) ? (float)$settings['registration_fee'] : 0.00;

$successMessage = '';
$errorMessage = '';

function splitNameParts($fullName)
{
	$normalized = trim(preg_replace('/\s+/', ' ', (string)$fullName));
	if ($normalized === '') {
		return ['first_name' => '', 'middle_name' => '', 'last_name' => ''];
	}

	$parts = preg_split('/\s+/', $normalized);
	if (!$parts) {
		return ['first_name' => '', 'middle_name' => '', 'last_name' => ''];
	}

	if (count($parts) === 1) {
		return ['first_name' => $parts[0], 'middle_name' => '', 'last_name' => ''];
	}

	$firstName = array_shift($parts);
	$lastName = array_pop($parts);
	$middleName = implode(' ', $parts);

	return [
		'first_name' => $firstName,
		'middle_name' => $middleName,
		'last_name' => $lastName
	];
}

function splitPhoneForForm($rawPhone, $countryOptions)
{
	$phone = preg_replace('/\D+/', '', (string)$rawPhone);
	if ($phone === '') {
		return ['country_code' => '254', 'local_number' => ''];
	}

	$codes = [];
	foreach ($countryOptions as $option) {
		$code = preg_replace('/\D+/', '', (string)($option['value'] ?? ''));
		if ($code !== '') {
			$codes[$code] = $code;
		}
	}
	$codes = array_values($codes);
	usort($codes, function ($a, $b) {
		return strlen($b) <=> strlen($a);
	});

	foreach ($codes as $code) {
		if (strpos($phone, $code) === 0 && strlen($phone) > strlen($code)) {
			return [
				'country_code' => $code,
				'local_number' => substr($phone, strlen($code))
			];
		}
	}

	if (strpos($phone, '0') === 0) {
		return ['country_code' => '254', 'local_number' => $phone];
	}

	return ['country_code' => '254', 'local_number' => $phone];
}

function hasCountryCodeOption(array $countryOptions, string $countryCode): bool
{
	$normalized = preg_replace('/\D+/', '', $countryCode);
	if ($normalized === '') {
		return false;
	}

	foreach ($countryOptions as $option) {
		$code = preg_replace('/\D+/', '', (string)($option['value'] ?? ''));
		if ($code === $normalized) {
			return true;
		}
	}

	return false;
}

function normalizePhoneFromForm($countryCode, $localNumber)
{
	$code = preg_replace('/\D+/', '', (string)$countryCode);
	$local = preg_replace('/\D+/', '', (string)$localNumber);
	$local = ltrim($local, '0');
	if ($code === '' || $local === '') {
		return '';
	}
	return $code . $local;
}

function normalizeMeterNumberInput($meterNumber)
{
	$meter = strtoupper(trim((string)$meterNumber));
	return preg_replace('/\s+/', '', $meter);
}

function normalizeCurrencyAmountInput($amount)
{
	$normalized = preg_replace('/[^0-9.\-]/', '', (string)$amount);
	if ($normalized === '' || $normalized === '-' || $normalized === '.') {
		return 0.0;
	}
	return round((float)$normalized, 2);
}

function parseMeterDetails(?string $meterDetailsRaw, ?string $fallbackMeterNumber = null): array
{
	$meters = [];
	$segments = $meterDetailsRaw !== null && $meterDetailsRaw !== ''
		? array_values(array_filter(explode('||', $meterDetailsRaw)))
		: [];

	foreach ($segments as $index => $segment) {
		$parts = explode('::', (string)$segment, 2);
		$meterNumber = trim((string)($parts[0] ?? ''));
		$meterLabel = trim((string)($parts[1] ?? ''));
		if ($meterNumber === '') {
			continue;
		}
		$meters[] = [
			'number' => $meterNumber,
			'label' => $meterLabel,
			'is_primary' => $index === 0,
		];
	}

	if (empty($meters) && $fallbackMeterNumber !== null && trim($fallbackMeterNumber) !== '') {
		$meters[] = [
			'number' => trim($fallbackMeterNumber),
			'label' => '',
			'is_primary' => true,
		];
	}

	return $meters;
}

function buildUsersPageUrl(array $params = [])
{
	$query = [];
	if (isset($params['page']) && (int)$params['page'] > 1) {
		$query['page'] = (int)$params['page'];
	}
	if (isset($params['customer_search']) && trim((string)$params['customer_search']) !== '') {
		$query['customer_search'] = trim((string)$params['customer_search']);
	}
	if (isset($params['edit_id']) && (int)$params['edit_id'] > 0) {
		$query['edit_id'] = (int)$params['edit_id'];
	}

	$queryString = http_build_query($query);
	return '/admin/users' . ($queryString !== '' ? '?' . $queryString : '');
}

$countryCodeOptions = [
	['value' => '254', 'label' => 'Kenya (+254)'],
	['value' => '256', 'label' => 'Uganda (+256)'],
	['value' => '255', 'label' => 'Tanzania (+255)'],
	['value' => '1', 'label' => 'United States (+1)'],
	['value' => '1', 'label' => 'Canada (+1)'],
	['value' => '44', 'label' => 'United Kingdom (+44)']
];
try {
	if ($db) {
		$countryDialCodeService = new CountryDialCode($db);
		$dbCountryCodeOptions = $countryDialCodeService->listActive();
		if (!empty($dbCountryCodeOptions)) {
			$countryCodeOptions = $dbCountryCodeOptions;
		}
	}
} catch (Exception $e) {
	// Keep fallback options if table creation/loading fails.
}

// Load flash messages (for redirect-after-POST) if set
if (isset($_SESSION['flash_message'])) {
	if (!empty($_SESSION['flash_type']) && $_SESSION['flash_type'] === 'error') {
		$errorMessage = (string)$_SESSION['flash_message'];
	} else {
		$successMessage = (string)$_SESSION['flash_message'];
	}
	unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

$currentPage = isset($_REQUEST['page']) ? (int)$_REQUEST['page'] : 1;
if ($currentPage < 1) {
	$currentPage = 1;
}
$customerSearch = trim((string)($_REQUEST['customer_search'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	// CSRF validation
	$_csrfToken = (string)($_POST['csrf_token'] ?? '');
	if (!hash_equals($_SESSION['app_csrf_token'] ?? '', $_csrfToken)) {
		http_response_code(403);
		die('Invalid CSRF token.');
	}
	// Finance users have read-only access; block all write operations
	if (!$isAdminUser) {
		$errorMessage = 'You do not have permission to modify customer records.';
	} else {

	$formType = trim((string)($_POST['form_type'] ?? ''));

	if ($formType === 'update_status') {
		try {
			$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
			$newStatus = $_POST['new_status'] ?? '';
			$allowed = ['active', 'inactive', 'suspended'];
			if ($userId <= 0 || !in_array($newStatus, $allowed, true)) {
				throw new Exception('Invalid user or status.');
			}
			$stmt = $db->prepare('UPDATE users SET status = :status WHERE id = :id');
			$stmt->bindParam(':status', $newStatus);
			$stmt->bindParam(':id', $userId, PDO::PARAM_INT);
			if ($stmt->execute()) {
				$_SESSION['flash_message'] = 'User status updated.';
				$_SESSION['flash_type'] = 'success';
				header('Location: ' . buildUsersPageUrl([
					'page' => $currentPage,
					'customer_search' => $customerSearch
				]));
				exit;
			} else {
				throw new Exception('Failed to update user status.');
			}
		} catch (Exception $e) {
			$errorMessage = $e->getMessage();
		}
	} elseif ($formType === 'delete_user') {
		try {
			$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
			if ($userId <= 0) {
				throw new Exception('Invalid user.');
			}
			if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $userId) {
				throw new Exception('You cannot delete your own account.');
			}
			$stmt = $db->prepare('DELETE FROM users WHERE id = :id');
			$stmt->bindParam(':id', $userId, PDO::PARAM_INT);
			if ($stmt->execute()) {
				$_SESSION['flash_message'] = 'User deleted successfully.';
				$_SESSION['flash_type'] = 'success';
				header('Location: ' . buildUsersPageUrl([
					'page' => $currentPage,
					'customer_search' => $customerSearch
				]));
				exit;
			} else {
				throw new Exception('Failed to delete user.');
			}
		} catch (Exception $e) {
			$errorMessage = $e->getMessage();
		}
	} elseif ($formType === 'add_client_meter') {
		try {
			$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
			if ($userId <= 0) {
				throw new Exception('Invalid customer selected.');
			}

			$meterNumber = normalizeMeterNumberInput($_POST['additional_meter_number'] ?? '');
			$meterLabel = trim((string)($_POST['additional_meter_label'] ?? ''));
			if ($meterNumber === '') {
				throw new Exception('Meter number is required.');
			}

			$userModel = new User($db);
			$targetUser = $userModel->getById($userId);
			if (!$targetUser || strtolower((string)($targetUser['role'] ?? 'customer')) !== 'customer') {
				throw new Exception('Customer not found.');
			}

			$primaryMeterNumber = normalizeMeterNumberInput((string)($targetUser['meter_number'] ?? ''));
			if ($primaryMeterNumber !== '' && $primaryMeterNumber === $meterNumber) {
				throw new Exception('That meter number is already the primary meter on this account.');
			}

			$clientMeterService = new ClientMeter($db);
			if ($clientMeterService->meterExists($meterNumber)) {
				throw new Exception('That meter number is already assigned to another account or meter record.');
			}

			$billId = null;
			$db->beginTransaction();

			if ($registrationFee > 0) {
				$billService = new Bill($db);
				$dueDate = date('Y-m-d', strtotime('+14 days'));
				$billId = $billService->createRegistrationFeeBill(
					(int)$targetUser['id'],
					(string)$targetUser['account_number'],
					$registrationFee,
					$dueDate,
					'pending'
				);
				if (!$billId) {
					throw new Exception('Failed to create the additional meter registration bill.');
				}
			}

			$clientMeterService->addMeter((int)$targetUser['id'], $meterNumber, $meterLabel !== '' ? $meterLabel : null, $billId ? (int)$billId : null, (int)($_SESSION['user_id'] ?? 0) ?: null);
			$db->commit();

			$paymentLink = $billId ? PaymentLink::generateLink((int)$billId) : '';
			$amountText = $registrationFee > 0 ? ' A registration fee of KES ' . number_format($registrationFee, 2) . ' has been billed.' : '';
			$labelText = $meterLabel !== '' ? ' (' . $meterLabel . ')' : '';
			$messageText = 'Dear ' . (string)$targetUser['full_name'] . ', additional meter ' . $meterNumber
				. $labelText . ' has been linked to Account ' . (string)$targetUser['account_number'] . '.' . $amountText
				. ($paymentLink !== '' ? ' Pay here: ' . $paymentLink : '');

			if (!empty($targetUser['phone_number'])) {
				try {
					$sms = new SMS($db);
					$sms->sendWithFallback((string)$targetUser['phone_number'], $messageText, 'additional_meter');
				} catch (Throwable $e) {
					error_log('Additional meter SMS failed: ' . $e->getMessage());
				}
			}

			if (!empty($targetUser['email'])) {
				try {
					$emailService = new Email();
					$emailService->queue((string)$targetUser['email'], 'Additional meter added to your account', $messageText, 'additional_meter');
				} catch (Throwable $e) {
					error_log('Additional meter email failed: ' . $e->getMessage());
				}
			}

			$_SESSION['flash_message'] = $billId
				? 'Additional meter added and registration fee bill created on the same account.'
				: 'Additional meter added successfully.';
			$_SESSION['flash_type'] = 'success';
			header('Location: ' . buildUsersPageUrl([
				'page' => $currentPage,
				'customer_search' => $customerSearch,
				'edit_id' => $userId,
			]));
			exit;
		} catch (Exception $e) {
			if ($db && $db->inTransaction()) {
				$db->rollBack();
			}
			$errorMessage = $e->getMessage();
		}
	} elseif ($formType === 'replace_client_meter') {
		try {
			$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
			$oldMeterId = isset($_POST['old_meter_id']) ? (int)$_POST['old_meter_id'] : 0;
			$newMeterNumber = normalizeMeterNumberInput($_POST['replacement_meter_number'] ?? '');
			$newMeterLabel = trim((string)($_POST['replacement_meter_label'] ?? ''));
			$oldFinalReading = isset($_POST['old_final_reading']) ? (float)$_POST['old_final_reading'] : 0.0;
			$newOpeningReading = isset($_POST['new_opening_reading']) ? (float)$_POST['new_opening_reading'] : 0.0;
			$replacementReason = trim((string)($_POST['replacement_reason'] ?? ''));

			if ($userId <= 0 || $oldMeterId <= 0) {
				throw new Exception('Select the faulty meter to replace.');
			}
			if ($newMeterNumber === '') {
				throw new Exception('New meter number is required.');
			}

			$userModel = new User($db);
			$targetUser = $userModel->getById($userId);
			if (!$targetUser || strtolower((string)($targetUser['role'] ?? 'customer')) !== 'customer') {
				throw new Exception('Customer not found.');
			}

			$clientMeterService = new ClientMeter($db);
			$replacement = $clientMeterService->replaceMeter(
				(int)$targetUser['id'],
				$oldMeterId,
				$newMeterNumber,
				$newMeterLabel !== '' ? $newMeterLabel : null,
				$oldFinalReading,
				$newOpeningReading,
				$replacementReason !== '' ? $replacementReason : null,
				(int)($_SESSION['user_id'] ?? 0) ?: null
			);

			try {
				$logger = new ActivityLog($db);
				$logger->log(
					$_SESSION['user_id'] ?? null,
					'replace_meter',
					'user_meter',
					$replacement['new_meter_id'] ?? null,
					'Replaced customer meter',
					[
						'user_id' => (int)$targetUser['id'],
						'account_number' => (string)$targetUser['account_number'],
						'old_meter_number' => (string)($replacement['old_meter']['meter_number'] ?? ''),
						'new_meter_number' => (string)$replacement['new_meter_number'],
						'old_final_reading' => (float)$replacement['old_final_reading'],
						'new_opening_reading' => (float)$replacement['new_opening_reading'],
						'is_primary' => !empty($replacement['is_primary']),
						'reason' => $replacementReason,
					]
				);
			} catch (Throwable $e) {
				error_log('Meter replacement log failed: ' . $e->getMessage());
			}

			$reasonText = $replacementReason !== '' ? ' Reason: ' . $replacementReason . '.' : '';
			$messageText = 'Dear ' . (string)$targetUser['full_name'] . ', faulty meter '
				. (string)($replacement['old_meter']['meter_number'] ?? '') . ' has been replaced with '
				. (string)$replacement['new_meter_number'] . ' on Account ' . (string)$targetUser['account_number']
				. '. Closing reading: ' . number_format((float)$replacement['old_final_reading'], 2)
				. '. New opening reading: ' . number_format((float)$replacement['new_opening_reading'], 2) . '.' . $reasonText;

			if (!empty($targetUser['phone_number'])) {
				try {
					$sms = new SMS($db);
					$sms->sendWithFallback((string)$targetUser['phone_number'], $messageText, 'meter_replacement');
				} catch (Throwable $e) {
					error_log('Meter replacement SMS failed: ' . $e->getMessage());
				}
			}

			if (!empty($targetUser['email'])) {
				try {
					$emailService = new Email();
					$emailService->queue((string)$targetUser['email'], 'Meter replacement completed', $messageText, 'meter_replacement');
				} catch (Throwable $e) {
					error_log('Meter replacement email failed: ' . $e->getMessage());
				}
			}

			$_SESSION['flash_message'] = 'Meter replaced successfully. Future billing will use the new meter history.';
			$_SESSION['flash_type'] = 'success';
			header('Location: ' . buildUsersPageUrl([
				'page' => $currentPage,
				'customer_search' => $customerSearch,
				'edit_id' => $userId,
			]));
			exit;
		} catch (Exception $e) {
			$errorMessage = $e->getMessage();
		}
	} elseif ($formType === 'edit_user_save') {
		try {
			$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
			if ($userId <= 0) {
				throw new Exception('Invalid user.');
			}

			$first_name = trim($_POST['first_name'] ?? '');
			$middle_name = trim($_POST['middle_name'] ?? '');
			$last_name = trim($_POST['last_name'] ?? '');
			$full_name = trim(preg_replace('/\s+/', ' ', $first_name . ' ' . $middle_name . ' ' . $last_name));
			$phone_country_code = trim((string)($_POST['phone_country_code'] ?? '254'));
			$phone_number_local = trim((string)($_POST['phone_number_local'] ?? ''));
			$phone_number = normalizePhoneFromForm($phone_country_code, $phone_number_local);
			$email = trim($_POST['email'] ?? '');
			$id_number = trim($_POST['id_number'] ?? '');
			$address = trim($_POST['address'] ?? '');
			$tax_pin = trim($_POST['tax_pin'] ?? '');
			$meter_number = normalizeMeterNumberInput($_POST['meter_number'] ?? '');
			$connection_type = trim($_POST['connection_type'] ?? 'domestic');
			$unit_rate_input = trim($_POST['unit_rate'] ?? '');
			$unit_rate = $unit_rate_input !== '' ? (float)$unit_rate_input : null;
			$location_label = trim($_POST['location_label'] ?? '');
			$latitude = trim($_POST['latitude'] ?? '');
			$longitude = trim($_POST['longitude'] ?? '');
			$role = 'customer';
			$password = (string)($_POST['password'] ?? '');

			if ($first_name === '' || $last_name === '' || $phone_number === '' || $id_number === '' || $address === '') {
				throw new Exception('Please fill in all required fields.');
			}
			if ($unit_rate !== null && $unit_rate < 0) {
				throw new Exception('Client unit rate cannot be negative.');
			}

			$existingUser = (new User($db))->getById($userId);
			if ($meter_number === '') {
				$meter_number = normalizeMeterNumberInput((string)($existingUser['meter_number'] ?? $existingUser['account_number'] ?? ''));
			}

			// Ensure phone is unique to this user
			$stmtCheck = $db->prepare('SELECT id FROM users WHERE phone_number = :phone AND id <> :id LIMIT 1');
			$stmtCheck->bindParam(':phone', $phone_number);
			$stmtCheck->bindParam(':id', $userId, PDO::PARAM_INT);
			$stmtCheck->execute();
			if ($stmtCheck->fetch(PDO::FETCH_ASSOC)) {
				throw new Exception('The phone number is already registered to another user.');
			}

			$editUserModel = new User($db);
			$clientMeterService = new ClientMeter($db);
			$existingPrimaryMeter = normalizeMeterNumberInput((string)($existingUser['meter_number'] ?? ''));
			if ($meter_number !== $existingPrimaryMeter && $clientMeterService->meterExists($meter_number)) {
				throw new Exception('The meter number is already assigned to another user.');
			}

			// Build update query
			// Customers page always stores customer role
			$role = 'customer';

			$sql = 'UPDATE users SET full_name = :full_name, phone_number = :phone_number, email = :email, id_number = :id_number, address = :address, tax_pin = :tax_pin, meter_number = :meter_number, connection_type = :connection_type, unit_rate = :unit_rate, location_label = :location_label, latitude = :latitude, longitude = :longitude, role = :role';
			$updatePassword = ($password !== '');
			if ($updatePassword) {
				$sql .= ', password_hash = :password_hash';
			}
			$sql .= ' WHERE id = :id';

			$stmt = $db->prepare($sql);
			$stmt->bindParam(':full_name', $full_name);
			$stmt->bindParam(':phone_number', $phone_number);
			$stmt->bindParam(':email', $email);
			$stmt->bindParam(':id_number', $id_number);
			$stmt->bindParam(':address', $address);
			$taxPinValue = $tax_pin !== '' ? $tax_pin : null;
			$stmt->bindParam(':tax_pin', $taxPinValue);
			$stmt->bindParam(':meter_number', $meter_number);
			$stmt->bindParam(':connection_type', $connection_type);
			$stmt->bindValue(':unit_rate', $unit_rate, $unit_rate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
			$locValue = $location_label !== '' ? $location_label : null;
			$latValue = $latitude !== '' ? (float)$latitude : null;
			$lngValue = $longitude !== '' ? (float)$longitude : null;
			$stmt->bindParam(':location_label', $locValue);
			$stmt->bindParam(':latitude', $latValue);
			$stmt->bindParam(':longitude', $lngValue);
			$stmt->bindParam(':role', $role);
			if ($updatePassword) {
				$password_hash = password_hash($password, PASSWORD_BCRYPT);
				$stmt->bindParam(':password_hash', $password_hash);
			}
			$stmt->bindParam(':id', $userId, PDO::PARAM_INT);

			if ($stmt->execute()) {
				$clientMeterService->syncPrimaryMeter($userId, $meter_number);
				$_SESSION['flash_message'] = 'User updated successfully.';
				$_SESSION['flash_type'] = 'success';
				header('Location: ' . buildUsersPageUrl([
					'page' => $currentPage,
					'customer_search' => $customerSearch
				]));
				exit;
			} else {
				throw new Exception('Failed to update user.');
			}
		} catch (Exception $e) {
			$errorMessage = $e->getMessage();
		}
	} elseif ($formType === 'resend_registration_stk') {
		// Retry a failed/expired registration payment by resending the M-Pesa STK push
		try {
			$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
			if ($userId <= 0) {
				throw new Exception('Invalid user.');
			}

			$userModel = new User($db);
			$targetUser = $userModel->getById($userId);
			if (!$targetUser) {
				throw new Exception('User not found.');
			}
			if (($targetUser['status'] ?? '') === 'active') {
				throw new Exception('This account is already active.');
			}
			if ($registrationFee <= 0) {
				throw new Exception('No registration fee is configured.');
			}

			$paymentModel = new Payment($db);
			$lastRegistrationPayment = $paymentModel->getLatestRegistrationByUserId($userId);
			$billId = !empty($lastRegistrationPayment['bill_id']) ? (int)$lastRegistrationPayment['bill_id'] : null;

			$amountToCharge = $billId ? $paymentModel->getBillOutstandingAmount($billId) : round($registrationFee, 2);
			if ($billId && $amountToCharge <= 0.01) {
				throw new Exception('Registration fee already paid. Activate the account instead.');
			}

			if (empty($targetUser['phone_number']) || !preg_match('/^(?:254|\+254|0)?((?:7|1)\d{8})$/', $targetUser['phone_number'], $matches)) {
				throw new Exception('Invalid or missing phone number for M-Pesa payment.');
			}
			$formattedPhone = '254' . $matches[1];

			$mpesa = new Mpesa();
			$response = $mpesa->stkPush(
				$formattedPhone,
				$amountToCharge,
				$targetUser['account_number'] ?? 'REG',
				'Registration Fee'
			);

			if (isset($response['error'])) {
				$details = '';
				if (isset($response['http_code'])) {
					$details .= ' (HTTP ' . $response['http_code'] . ')';
				}
				if (!empty($response['details']['errorMessage'])) {
					$details .= ': ' . $response['details']['errorMessage'];
				}
				throw new Exception('Payment initiation failed: ' . $response['error'] . $details);
			}

			if (!$billId) {
				$billService = new Bill($db);
				$dueDate = date('Y-m-d', strtotime('+14 days'));
				$billId = $billService->createRegistrationFeeBill(
					$userId,
					$targetUser['account_number'] ?? 'REG',
					$registrationFee,
					$dueDate,
					'pending'
				);
			}

			$newPayment = new Payment($db);
			$newPayment->bill_id = $billId;
			$newPayment->user_id = $userId;
			$newPayment->phone_number = $formattedPhone;
			$newPayment->amount = $amountToCharge;
			$newPayment->merchant_request_id = $response['MerchantRequestID'] ?? null;
			$newPayment->checkout_request_id = $response['CheckoutRequestID'] ?? null;
			$newPayment->status = 'pending';
			$newPayment->registration_id = $userId;

			if (!$newPayment->create()) {
				throw new Exception('Failed to save the new payment record.');
			}

			$_SESSION['flash_message'] = 'A new M-Pesa registration payment prompt (KES ' . number_format($amountToCharge, 2) . ') was sent to ' . $formattedPhone . '.';
			$_SESSION['flash_type'] = 'success';
			header('Location: ' . buildUsersPageUrl([
				'page' => $currentPage,
				'customer_search' => $customerSearch
			]));
			exit;
		} catch (Exception $e) {
			$errorMessage = $e->getMessage();
		}
		} else {
		// Default: create new user
		try {
			$first_name = trim($_POST['first_name'] ?? '');
			$middle_name = trim($_POST['middle_name'] ?? '');
			$last_name = trim($_POST['last_name'] ?? '');
			$full_name = trim(preg_replace('/\s+/', ' ', $first_name . ' ' . $middle_name . ' ' . $last_name));
			$phone_country_code = trim((string)($_POST['phone_country_code'] ?? '254'));
			$phone_number_local = trim((string)($_POST['phone_number_local'] ?? ''));
			$phone_number = normalizePhoneFromForm($phone_country_code, $phone_number_local);
			$email = trim($_POST['email'] ?? '');
			$id_number = trim($_POST['id_number'] ?? '');
			$address = trim($_POST['address'] ?? '');
			$tax_pin = trim($_POST['tax_pin'] ?? '');
			$connection_type = trim($_POST['connection_type'] ?? 'domestic');
			$unit_rate_input = trim($_POST['unit_rate'] ?? '');
			$unit_rate = $unit_rate_input !== '' ? (float)$unit_rate_input : null;
				$location_label = trim($_POST['location_label'] ?? '');
				$latitude = trim($_POST['latitude'] ?? '');
				$longitude = trim($_POST['longitude'] ?? '');
			$password = (string)($_POST['password'] ?? '');
			$role = 'customer';
			$registration_already_paid = isset($_POST['registration_already_paid']);
			$send_stk = isset($_POST['send_stk']);
			$registration_mpesa_code = trim($_POST['registration_mpesa_code'] ?? '');
			$registration_paid_amount = normalizeCurrencyAmountInput($_POST['registration_paid_amount'] ?? '');

			// Basic validation
			if ($first_name === '' || $last_name === '' || $phone_number === '' || $email === '' || $id_number === '' || $address === '' || $password === '') {
				throw new Exception('Please fill in all required fields.');
			}
			if ($unit_rate !== null && $unit_rate < 0) {
				throw new Exception('Client unit rate cannot be negative.');
			}

			$user = new User($db);
			if ($user->phoneExists($phone_number)) {
				throw new Exception('The phone number is already registered to another user.');
			}

			if ($registrationFee > 0 && $registration_already_paid) {
				if ($registration_paid_amount <= 0) {
					$registration_paid_amount = round($registrationFee, 2);
				}
				if ($registration_paid_amount <= 0) {
					throw new Exception('Enter a valid amount already paid toward the registration fee.');
				}
				if ($registration_paid_amount - round($registrationFee, 2) > 0.01) {
					throw new Exception('Amount already paid cannot exceed the configured registration fee.');
				}
			}

			// Generate sequential account number like public registration
			$stmt = $db->query("SELECT account_number FROM users WHERE account_number LIKE 'MTR%' ORDER BY id DESC LIMIT 1");
			$last = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
			$nextNumber = 1;
			if ($last && !empty($last['account_number']) && preg_match('/^MTR(\d+)$/', $last['account_number'], $m)) {
				$nextNumber = (int)$m[1] + 1;
			}
			$account_number = 'MTR' . str_pad((string)$nextNumber, 4, '0', STR_PAD_LEFT);
			$meter_number = $account_number;

			// Ensure auto-generated meter number is unique (edge case: concurrent inserts)
			if ($user->meterNumberExists($meter_number)) {
				throw new Exception('Generated meter number is already in use. Please try again.');
			}

			// Populate user model
			$user->account_number = $account_number;
			$user->full_name = $full_name;
			$user->phone_number = $phone_number;
			$user->email = $email;
			$user->id_number = $id_number;
			$user->address = $address;
			$user->tax_pin = $tax_pin !== '' ? $tax_pin : null;
			$user->meter_number = $meter_number;
			$user->connection_type = $connection_type !== '' ? $connection_type : 'domestic';
			$user->unit_rate = $unit_rate;
			$user->location_label = $location_label !== '' ? $location_label : null;
			$user->latitude = $latitude !== '' ? (float)$latitude : null;
			$user->longitude = $longitude !== '' ? (float)$longitude : null;
			$user->password = $password;
			$user->role = 'customer';

			$billService = new Bill($db);
			$paymentModel = new Payment($db);

			// Case 1: No registration fee configured OR admin records an existing payment
			if ($registrationFee <= 0 || $registration_already_paid) {
				$user->status = $registrationFee > 0 ? 'inactive' : 'active';
				if (!$user->create()) {
					throw new Exception('Failed to create user account.');
				}

				$registrationBalance = 0.0;

				// If a registration fee exists and is marked as already paid, record the amount already received
				if ($registrationFee > 0 && $registration_already_paid) {
					$dueDate = date('Y-m-d');
					$billId = $billService->createRegistrationFeeBill($user->id, $user->account_number, $registrationFee, $dueDate, 'pending');
					if ($billId) {
						$payment = new Payment($db);
						$payment->bill_id = $billId;
						$payment->user_id = $user->id;
						$payment->phone_number = $phone_number;
						$payment->amount = $registration_paid_amount;
						$payment->merchant_request_id = null;
						$payment->checkout_request_id = null;
						$payment->mpesa_receipt = $registration_mpesa_code !== '' ? $registration_mpesa_code : null;
						$payment->status = 'completed';
						$payment->registration_id = $user->id;
						if (!$payment->create()) {
							throw new Exception('Failed to record the completed registration payment for the new user.');
						}
						$registrationBalance = $paymentModel->getBillOutstandingAmount((int)$billId);
						$billRow = $billService->getById((int)$billId);
						if ($registrationBalance <= 0.01 && (($billRow['status'] ?? '') === 'paid')) {
							$stmtActivate = $db->prepare('UPDATE users SET status = "active" WHERE id = :id');
							$stmtActivate->execute([':id' => (int)$user->id]);
							$user->status = 'active';
						} else {
							$user->status = 'inactive';
						}
					}
				}

				// Registration payment already recorded above triggers its own SMS/email
				// via Payment::sendCompletedPaymentNotification(); only send the generic
				// "account created" notice when there was no registration fee to collect.
				if ($registrationFee <= 0 || !$registration_already_paid) {
					$sms = new SMS();
					$companyName = !empty($settings['company_name']) ? $settings['company_name'] : 'BreMac Consultant Ltd';
					$loginUrl = 'https://wbs.bremac.co.ke/';
					$messageText = "Dear " . $user->full_name . ",\n" .
						"Your water account has been created successfully.\n" .
						"Account No: " . $user->account_number . "\n" .
						"Meter No: " . $user->meter_number . "\n" .
						"You can now log in at " . $loginUrl . " using your account number, phone or email to view your bills and make payments.\n" .
						$companyName;
					$sms->send($user->phone_number, $messageText, 'registration');

					// Also send an email if available
					if (!empty($user->email)) {
						require_once __DIR__ . '/../../includes/Email.php';
						$email = new Email();
						$email->send(
							$user->email,
							'Your new water account details',
							$messageText
						);
					}
				}

				$_SESSION['flash_message'] = 'User created successfully.';
				$_SESSION['flash_type'] = 'success';
				header('Location: ' . buildUsersPageUrl([
					'page' => $currentPage,
					'customer_search' => $customerSearch
				]));
				exit;
			} else {
				// Case 2: Registration fee configured and not marked as already paid
				if (!$send_stk) {
					throw new Exception('Please either mark the registration fee as already paid or choose to send an M-Pesa STK push.');
				}

				// Validate and normalize phone for M-Pesa
				if (!preg_match('/^(?:254|\+254|0)?((?:7|1)\d{8})$/', $phone_number, $matches)) {
					throw new Exception('Invalid phone number format for M-Pesa payment.');
				}
				$formatted_phone = '254' . $matches[1];

				$mpesa = new Mpesa();
				$response = $mpesa->stkPush(
					$formatted_phone,
					$registrationFee,
					$account_number,
					'Registration Fee'
				);

				if (isset($response['error'])) {
					$details = '';
					if (isset($response['http_code'])) {
						$details .= ' (HTTP ' . $response['http_code'] . ')';
					}
					if (isset($response['details']) && is_array($response['details'])) {
						if (!empty($response['details']['errorMessage'])) {
							$details .= ': ' . $response['details']['errorMessage'];
						} elseif (!empty($response['details']['errorCode'])) {
							$details .= ' (Code ' . $response['details']['errorCode'] . ')';
						}
					}
					throw new Exception('Payment initiation failed: ' . $response['error'] . $details);
				}

				// Create user in inactive state awaiting registration fee
				$user->status = 'inactive';
				if (!$user->create()) {
					throw new Exception('Failed to create user account after initiating payment.');
				}

				// Create registration fee bill
				$dueDate = date('Y-m-d', strtotime('+14 days'));
				$billId = $billService->createRegistrationFeeBill($user->id, $user->account_number, $registrationFee, $dueDate, 'pending');

				// Save payment record tied to the registration fee bill
				$payment = new Payment($db);
				$payment->bill_id = $billId;
				$payment->user_id = $user->id;
				$payment->phone_number = $formatted_phone;
				$payment->amount = $registrationFee;
				$payment->merchant_request_id = $response['MerchantRequestID'] ?? null;
				$payment->checkout_request_id = $response['CheckoutRequestID'] ?? null;
				$payment->status = 'pending';
				$payment->registration_id = $user->id;
				$payment->create();

				$_SESSION['flash_message'] = 'User created and registration payment initiated via M-Pesa STK. The account will be activated automatically after payment is received.';
				$_SESSION['flash_type'] = 'success';
				header('Location: ' . buildUsersPageUrl([
					'page' => $currentPage,
					'customer_search' => $customerSearch
				]));
				exit;
			}
		} catch (Exception $e) {
			$errorMessage = $e->getMessage();
		}
	}
	} // end isAdminUser write gate
}

// Pagination for existing users list
$usersList = [];
$totalUsers = 0;
$perPage = 10;

if ($db) {
	try {
		$whereClause = "role = 'customer'";
		$queryParams = [];

		if ($customerSearch !== '') {
			$whereClause .= " AND (account_number LIKE :customer_search OR full_name LIKE :customer_search OR meter_number LIKE :customer_search OR phone_number LIKE :customer_search OR EXISTS (SELECT 1 FROM user_meters um WHERE um.user_id = users.id AND (um.meter_number LIKE :customer_search_meter OR COALESCE(um.meter_label, '') LIKE :customer_search_label)))";
			$queryParams[':customer_search'] = '%' . $customerSearch . '%';
			$queryParams[':customer_search_meter'] = '%' . $customerSearch . '%';
			$queryParams[':customer_search_label'] = '%' . $customerSearch . '%';
		}

		$stmtCount = $db->prepare("SELECT COUNT(*) AS total FROM users WHERE $whereClause");
		foreach ($queryParams as $param => $value) {
			$stmtCount->bindValue($param, $value, PDO::PARAM_STR);
		}
		$stmtCount->execute();
		$rowCount = $stmtCount->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0];
		$totalUsers = (int)($rowCount['total'] ?? 0);
		$totalPages = max(1, (int)ceil($totalUsers / $perPage));
		if ($currentPage > $totalPages) {
			$currentPage = $totalPages;
		}
		$offset = ($currentPage - 1) * $perPage;

		$stmtUsers = $db->prepare("SELECT id, account_number, full_name, phone_number, meter_number, status, role,
			COALESCE((SELECT GROUP_CONCAT(DISTINCT um.meter_number ORDER BY um.is_primary DESC, um.created_at ASC, um.id ASC SEPARATOR ',') FROM user_meters um WHERE um.user_id = users.id AND um.status = 'active'), meter_number) AS meter_numbers,
			COALESCE((SELECT GROUP_CONCAT(CONCAT(um.meter_number, '::', COALESCE(um.meter_label, '')) ORDER BY um.is_primary DESC, um.created_at ASC, um.id ASC SEPARATOR '||') FROM user_meters um WHERE um.user_id = users.id AND um.status = 'active'), CONCAT(COALESCE(meter_number, ''), '::')) AS meter_details
			FROM users WHERE $whereClause ORDER BY full_name ASC LIMIT :limit OFFSET :offset");
		foreach ($queryParams as $param => $value) {
			$stmtUsers->bindValue($param, $value, PDO::PARAM_STR);
		}
		$stmtUsers->bindValue(':limit', $perPage, PDO::PARAM_INT);
		$stmtUsers->bindValue(':offset', $offset, PDO::PARAM_INT);
		$stmtUsers->execute();
		$usersList = $stmtUsers->fetchAll(PDO::FETCH_ASSOC) ?: [];
	} catch (Exception $e) {
		$usersList = [];
		$totalUsers = 0;
		$totalPages = 1;
	}
} else {
	$totalPages = 1;
}

$editUser = null;
$isEditMode = false;
$editUserMeters = [];
$editUserMeterReplacements = [];
if ($db && isset($_GET['edit_id'])) {
	$editId = (int)$_GET['edit_id'];
	if ($editId > 0) {
		try {
			$userRepo = new User($db);
			$clientMeterRepo = new ClientMeter($db);
			$editUser = $userRepo->getById($editId);
			if ($editUser && strtolower((string)($editUser['role'] ?? '')) !== 'customer') {
				$editUser = null;
			}
			$isEditMode = (bool)$editUser;
			if ($isEditMode) {
				$editUserMeters = $clientMeterRepo->listByUserId((int)$editUser['id']);
				$editUserMeterReplacements = $clientMeterRepo->listReplacementsByUserId((int)$editUser['id']);
			}
		} catch (Exception $e) {
			$editUser = null;
			$isEditMode = false;
			$editUserMeters = [];
			$editUserMeterReplacements = [];
		}
	}
}

$editNameParts = splitNameParts($editUser['full_name'] ?? '');
$formFirstName = isset($_POST['first_name']) ? trim((string)$_POST['first_name']) : ($editNameParts['first_name'] ?? '');
$formMiddleName = isset($_POST['middle_name']) ? trim((string)$_POST['middle_name']) : ($editNameParts['middle_name'] ?? '');
$formLastName = isset($_POST['last_name']) ? trim((string)$_POST['last_name']) : ($editNameParts['last_name'] ?? '');
$editPhoneParts = splitPhoneForForm((string)($editUser['phone_number'] ?? ''), $countryCodeOptions);
$formPhoneCountryCode = isset($_POST['phone_country_code']) ? preg_replace('/\D+/', '', (string)$_POST['phone_country_code']) : ($editPhoneParts['country_code'] ?? '254');
if (!hasCountryCodeOption($countryCodeOptions, $formPhoneCountryCode)) {
	$formPhoneCountryCode = '254';
}
$formPhoneLocalNumber = isset($_POST['phone_number_local']) ? preg_replace('/\D+/', '', (string)$_POST['phone_number_local']) : ($editPhoneParts['local_number'] ?? '');

$page_title = "Admin - Users";
$is_admin_page = true;
require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container mt-4 admin-shell admin-users-page" id="usersPageDensityTarget">
	<div class="row">
		<div class="col-md-12">
			<div class="pb-banner pb-banner--emerald mb-4">
				<div class="pb-bg" aria-hidden="true">
					<div class="pb-grid"></div>
					<div class="pb-blob pb-blob--a"></div>
					<div class="pb-blob pb-blob--b"></div>
					<i class="bi bi-people-fill pb-watermark"></i>
				</div>
				<div class="pb-inner">
					<div class="pb-left">
						<div class="pb-eyebrow-row">
							<span class="pb-eyebrow-chip"><i class="bi bi-people-fill"></i> Customer Administration</span>
						</div>
						<h2 class="pb-title">Customers</h2>
						<p class="pb-subtitle">Create and manage customer accounts.</p>
					</div>
					<div class="pb-right">
						<div class="pb-btn-row">
							<?php if ($isAdminUser): ?>
							<a href="/admin/staff-users" class="pb-btn"><i class="bi bi-person-badge"></i> Staff Users</a>
							<?php endif; ?>
							<a href="/admin/customer-locations" class="pb-btn pb-btn--accent"><i class="bi bi-geo-alt"></i> Customer Map</a>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row mt-3">
		<div class="col-12">
			<div class="card">
				<div class="card-header d-flex justify-content-between align-items-center">
					<h5 class="mb-0"><i class="bi bi-people me-1"></i> Existing Customers</h5>
					<div class="d-flex align-items-center gap-2">
						<small class="text-muted">Total: <?php echo (int)$totalUsers; ?></small>
						<button type="button" class="btn btn-sm btn-outline-secondary" data-density-toggle data-density-target="#usersPageDensityTarget" data-density-key="users-table" data-density-compact-text="Compact View" data-density-comfy-text="Comfortable View">
							<i class="bi bi-arrows-collapse"></i> <span class="js-density-label">Compact View</span>
						</button>
					</div>
				</div>
				<div class="card-body border-bottom bg-light-subtle">
					<form method="get" action="/admin/users" class="row g-2 align-items-end">
						<div class="col-md-8 col-lg-9">
							<label for="customer_search" class="form-label mb-1">Search customers</label>
							<input type="text" class="form-control" id="customer_search" name="customer_search" value="<?php echo htmlspecialchars($customerSearch); ?>" placeholder="Search by Account No, Name, Meter No or Phone">
						</div>
						<div class="col-md-4 col-lg-3 d-flex gap-2">
							<button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-search"></i> Search</button>
							<?php if ($customerSearch !== ''): ?>
								<a href="/admin/users" class="btn btn-outline-secondary">Clear</a>
							<?php endif; ?>
						</div>
					</form>
				</div>
				<div class="card-body p-0">
					<?php if (empty($usersList)): ?>
						<p class="p-3 mb-0 text-muted"><?php echo $customerSearch !== '' ? 'No customers matched that search.' : 'No customers found.'; ?></p>
					<?php else: ?>
						<div class="table-responsive">
							<table class="table table-striped mb-0 table-density-target">
								<thead>
									<tr>
										<th scope="col">Account No</th>
										<th scope="col">Client Name</th>
										<th scope="col">Phone No</th>
										<th scope="col">Meter No</th>
										<th scope="col">Status</th>
										<th scope="col">Role</th>
										<th scope="col">Actions</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($usersList as $u): ?>
										<?php $meterItems = parseMeterDetails((string)($u['meter_details'] ?? ''), (string)($u['meter_number'] ?? '')); ?>
										<?php $primaryMeterItem = $meterItems[0] ?? ['number' => (string)($u['meter_number'] ?? ''), 'label' => '']; ?>
										<tr>
											<td><?php echo htmlspecialchars($u['account_number'] ?? ''); ?></td>
											<td><?php echo htmlspecialchars($u['full_name'] ?? ''); ?></td>
											<td><?php echo htmlspecialchars($u['phone_number'] ?? ''); ?></td>
											<td>
												<div class="fw-semibold"><?php echo htmlspecialchars((string)($primaryMeterItem['number'] ?? '')); ?></div>
												<?php if (!empty($primaryMeterItem['label'])): ?>
													<div class="small text-muted"><?php echo htmlspecialchars((string)$primaryMeterItem['label']); ?></div>
												<?php endif; ?>
												<?php if (count($meterItems) > 1): ?>
													<div class="small mt-1"><span class="badge bg-info-subtle text-info border border-info-subtle"><?php echo count($meterItems); ?> meters</span></div>
												<?php endif; ?>
											</td>
											<td>
												<span class="badge bg-<?php echo ($u['status'] === 'active') ? 'success' : (($u['status'] === 'inactive') ? 'secondary' : 'warning'); ?>">
													<?php echo htmlspecialchars(ucfirst($u['status'] ?? '')); ?>
												</span>
											</td>
											<td>
												<?php
													$roleLabel = $u['role'] ?? 'customer';
													switch (strtolower((string)$roleLabel)) {
														case 'admin':
															$badgeClass = 'danger';
															$roleText = 'Admin';
															break;
														case 'reader':
															$badgeClass = 'info';
															$roleText = 'Reader';
															break;
														case 'finance':
															$badgeClass = 'primary';
															$roleText = 'Finance';
															break;
														case 'support':
															$badgeClass = 'secondary';
															$roleText = 'Support';
															break;
														default:
															$badgeClass = 'light text-dark';
															$roleText = 'Customer';
													}
												?>
												<span class="badge bg-<?php echo $badgeClass; ?>"><?php echo htmlspecialchars($roleText); ?></span>
											</td>
											<td>
				<div class="d-flex flex-wrap gap-1">
												<?php if ($isAdminUser): ?>
												<a href="<?php echo htmlspecialchars(buildUsersPageUrl(['page' => $currentPage, 'customer_search' => $customerSearch, 'edit_id' => (int)$u['id']])); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i> Edit</a>
												<?php endif; ?>
												<a href="<?php echo htmlspecialchars('/admin/customer-locations?user_id=' . (int)$u['id']); ?>" class="btn btn-sm btn-outline-info" title="View on map">
													<i class="bi bi-geo-alt"></i>
												</a>
												<?php if ($isAdminUser): ?>
												<?php if (($u['status'] ?? '') !== 'active' && $registrationFee > 0): ?>
														<form method="post" action="" class="d-inline">
															<input type="hidden" name="form_type" value="resend_registration_stk">
															<input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
															<input type="hidden" name="page" value="<?php echo (int)$currentPage; ?>">
															<input type="hidden" name="customer_search" value="<?php echo htmlspecialchars($customerSearch); ?>">
															<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($usersCsrfToken); ?>">
															<button type="submit" class="btn btn-sm btn-outline-primary" title="Resend M-Pesa registration payment prompt">
																<i class="bi bi-arrow-repeat"></i> Retry Payment
															</button>
														</form>
													<?php endif; ?>
												<?php if (($u['status'] ?? '') !== 'active'): ?>
														<form method="post" action="" class="d-inline">
															<input type="hidden" name="form_type" value="update_status">
															<input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
															<input type="hidden" name="page" value="<?php echo (int)$currentPage; ?>">
															<input type="hidden" name="customer_search" value="<?php echo htmlspecialchars($customerSearch); ?>">
															<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($usersCsrfToken); ?>">
															<input type="hidden" name="new_status" value="active">
															<button type="submit" class="btn btn-sm btn-outline-success">Activate</button>
														</form>
													<?php endif; ?>
													<?php if (($u['status'] ?? '') !== 'inactive'): ?>
														<form method="post" action="" class="d-inline">
															<input type="hidden" name="form_type" value="update_status">
															<input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
															<input type="hidden" name="page" value="<?php echo (int)$currentPage; ?>">
															<input type="hidden" name="customer_search" value="<?php echo htmlspecialchars($customerSearch); ?>">
															<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($usersCsrfToken); ?>">
															<input type="hidden" name="new_status" value="inactive">
															<button type="submit" class="btn btn-sm btn-outline-secondary">Deactivate</button>
														</form>
													<?php endif; ?>
														<?php if (($u['status'] ?? '') !== 'suspended'): ?>
														<form method="post" action="" class="d-inline">
															<input type="hidden" name="form_type" value="update_status">
															<input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
															<input type="hidden" name="page" value="<?php echo (int)$currentPage; ?>">
															<input type="hidden" name="customer_search" value="<?php echo htmlspecialchars($customerSearch); ?>">
															<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($usersCsrfToken); ?>">
															<input type="hidden" name="new_status" value="suspended">
															<button type="submit" class="btn btn-sm btn-outline-warning">Suspend</button>
														</form>
													<?php endif; ?>
															<form method="post" action="" class="d-inline" data-confirm-message="Are you sure you want to delete this user? This action cannot be undone.">
														<input type="hidden" name="form_type" value="delete_user">
														<input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
																<input type="hidden" name="page" value="<?php echo (int)$currentPage; ?>">
																<input type="hidden" name="customer_search" value="<?php echo htmlspecialchars($customerSearch); ?>">
																<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($usersCsrfToken); ?>">
														<button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Delete</button>
													</form>
												<?php endif; // isAdminUser ?>
												</div>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
				<?php if (!empty($totalPages) && $totalPages > 1): ?>
					<div class="card-footer">
						<nav aria-label="User pagination">
							<ul class="pagination mb-0">
								<li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
									<a class="page-link" href="<?php echo $currentPage <= 1 ? '#' : htmlspecialchars(buildUsersPageUrl(['page' => ($currentPage - 1), 'customer_search' => $customerSearch])); ?>" tabindex="-1">Previous</a>
								</li>
								<?php for ($p = 1; $p <= $totalPages; $p++): ?>
									<li class="page-item <?php echo $p === $currentPage ? 'active' : ''; ?>">
										<a class="page-link" href="<?php echo htmlspecialchars(buildUsersPageUrl(['page' => $p, 'customer_search' => $customerSearch])); ?>"><?php echo $p; ?></a>
									</li>
								<?php endfor; ?>
								<li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
									<a class="page-link" href="<?php echo $currentPage >= $totalPages ? '#' : htmlspecialchars(buildUsersPageUrl(['page' => ($currentPage + 1), 'customer_search' => $customerSearch])); ?>">Next</a>
								</li>
							</ul>
						</nav>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<div class="row mt-4">
		<div class="col-12">
			<?php if ($successMessage): ?>
				<script>
				(function(){
					var msg = <?php echo json_encode($successMessage); ?>;
					var show = function(){ if (window.showToast) { showToast(msg, 'success'); } };
					if (document.readyState === 'loading') {
						document.addEventListener('DOMContentLoaded', show);
					} else {
						show();
					}
				})();
				</script>
			<?php endif; ?>
			<?php if ($errorMessage): ?>
				<script>
				(function(){
					var msg = <?php echo json_encode($errorMessage); ?>;
					var show = function(){ if (window.showToast) { showToast(msg, 'danger'); } };
					if (document.readyState === 'loading') {
						document.addEventListener('DOMContentLoaded', show);
					} else {
						show();
					}
				})();
				</script>
			<?php endif; ?>

			<div class="card">
				<div class="card-header">
					<h5 class="mb-0"><?php echo $isEditMode ? 'Edit Customer' : 'Create New Customer'; ?></h5>
				</div>
				<div class="card-body">
				<?php if ($isAdminUser): ?>
					<form method="post" action="">
						<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($usersCsrfToken); ?>">
						<input type="hidden" name="form_type" value="<?php echo $isEditMode ? 'edit_user_save' : 'create_user'; ?>">
						<input type="hidden" name="page" value="<?php echo (int)$currentPage; ?>">
						<input type="hidden" name="customer_search" value="<?php echo htmlspecialchars($customerSearch); ?>">
						<?php if ($isEditMode && !empty($editUser['id'])): ?>
							<input type="hidden" name="user_id" value="<?php echo (int)$editUser['id']; ?>">
						<?php endif; ?>
						<div class="row">
							<div class="col-md-4 mb-3">
								<label for="first_name" class="form-label">First Name *</label>
								<input type="text" class="form-control" id="first_name" name="first_name" autocomplete="given-name" required value="<?php echo htmlspecialchars($formFirstName); ?>">
							</div>
							<div class="col-md-4 mb-3">
								<label for="middle_name" class="form-label">Middle Name (optional)</label>
								<input type="text" class="form-control" id="middle_name" name="middle_name" autocomplete="additional-name" value="<?php echo htmlspecialchars($formMiddleName); ?>">
							</div>
							<div class="col-md-4 mb-3">
								<label for="last_name" class="form-label">Last Name *</label>
								<input type="text" class="form-control" id="last_name" name="last_name" autocomplete="family-name" required value="<?php echo htmlspecialchars($formLastName); ?>">
							</div>
							<div class="col-md-6 mb-3">
								<label for="phone_number_local" class="form-label">Phone Number *</label>
								<div class="input-group">
									<span class="input-group-text">+</span>
									<select class="form-select" id="phone_country_code" name="phone_country_code" style="max-width: 190px;" required>
										<?php foreach ($countryCodeOptions as $option): ?>
											<?php $code = (string)($option['value'] ?? ''); ?>
											<?php $label = (string)($option['label'] ?? ''); ?>
											<option value="<?php echo htmlspecialchars($code); ?>" <?php echo $formPhoneCountryCode === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
										<?php endforeach; ?>
									</select>
									<input type="text" class="form-control" id="phone_number_local" name="phone_number_local" autocomplete="tel-national" inputmode="numeric" placeholder="e.g. 712345678" required value="<?php echo htmlspecialchars($formPhoneLocalNumber); ?>">
								</div>
								<div class="form-text">Choose country code, then enter the phone number without spaces or symbols.</div>
							</div>
						</div>
						<div class="row">
							<div class="col-md-6 mb-3">
								<label for="email" class="form-label">Email *</label>
								<input type="email" class="form-control" id="email" name="email" autocomplete="email" required value="<?php echo htmlspecialchars(isset($_POST['email']) ? $_POST['email'] : ($editUser['email'] ?? '')); ?>">
							</div>
							<div class="col-md-6 mb-3">
								<label for="id_number" class="form-label">ID Number *</label>
								<input type="text" class="form-control" id="id_number" name="id_number" autocomplete="off" required value="<?php echo htmlspecialchars(isset($_POST['id_number']) ? $_POST['id_number'] : ($editUser['id_number'] ?? '')); ?>">
							</div>
						</div>
						<div class="row">
							<div class="col-md-6 mb-3">
								<label for="address" class="form-label">Address *</label>
								<input type="text" class="form-control" id="address" name="address" autocomplete="street-address" required value="<?php echo htmlspecialchars(isset($_POST['address']) ? $_POST['address'] : ($editUser['address'] ?? '')); ?>">
							</div>
							<div class="col-md-6 mb-3">
								<?php if ($isEditMode): ?>
									<label for="meter_number" class="form-label">Meter No *</label>
									<input type="text" class="form-control" id="meter_number" name="meter_number" autocomplete="off" required value="<?php echo htmlspecialchars(isset($_POST['meter_number']) ? $_POST['meter_number'] : ($editUser['meter_number'] ?? '')); ?>">
									<div class="form-text">Only authorised staff can change the meter number after installation at the customer premises.</div>
								<?php else: ?>
									<label for="meter_number_preview" class="form-label">Meter No</label>
									<input type="text" class="form-control" id="meter_number_preview" value="Auto: same as generated Account No" readonly>
									<div class="form-text">On registration, Meter No will automatically start as the generated Account No. Staff can update it later to the actual installed meter number.</div>
								<?php endif; ?>
							</div>
						</div>
						<div class="row">
							<div class="col-md-6 mb-3">
								<label for="tax_pin" class="form-label">KRA PIN (optional)</label>
								<input type="text" class="form-control" id="tax_pin" name="tax_pin" autocomplete="off" value="<?php echo htmlspecialchars(isset($_POST['tax_pin']) ? $_POST['tax_pin'] : ($editUser['tax_pin'] ?? '')); ?>">
							</div>
							<div class="col-md-6 mb-3">
								<label for="location_label" class="form-label">Location (optional)</label>
								<input type="text" class="form-control location-autocomplete" id="location_label" name="location_label" value="<?php echo htmlspecialchars(isset($_POST['location_label']) ? $_POST['location_label'] : ($editUser['location_label'] ?? '')); ?>" placeholder="e.g. P5PP+CJ, Nguluni" autocomplete="off">
								<div class="form-text">Type a nearby landmark, estate, village name or Plus Code (e.g. "P5PP+CJ, Nguluni"); use the map below to fine-tune the exact pin.</div>
							</div>
							<div class="col-md-6 mb-3">
								<label class="form-label d-block">GPS (internal only)</label>
								<input type="hidden" id="latitude" name="latitude" value="<?php echo htmlspecialchars(isset($_POST['latitude']) ? $_POST['latitude'] : ($editUser['latitude'] ?? '')); ?>">
								<input type="hidden" id="longitude" name="longitude" value="<?php echo htmlspecialchars(isset($_POST['longitude']) ? $_POST['longitude'] : ($editUser['longitude'] ?? '')); ?>">
								<small class="text-muted d-block mb-1">Exact GPS coordinates are used internally for maps and reports; they are never shown to customers.</small>
								<input type="text" class="form-control form-control-sm" id="gps_dms_input" autocomplete="off" placeholder="e.g. 1°15'51.6&quot;S 37°11'15.2&quot;E">
								<div class="form-text">Advanced: paste latitude/longitude in DMS format and the map plus GPS fields will update automatically.</div>
							</div>
						</div>
						<div class="row">
							<div class="col-md-12 mb-2">
								<button type="button" class="btn btn-outline-secondary btn-sm" id="adminDetectLocationBtn">
									<i class="bi bi-geo-alt"></i> Use my current GPS location
								</button>
								<div class="form-text">Optional. Use this if you are physically at the property and want to pin it using your current device location.</div>
							</div>
						</div>
						<div class="row">
							<div class="col-12 mb-3">
								<label class="form-label">Pinned Location</label>
								<div id="customerLocationMap" style="height:260px;border-radius:0.5rem;overflow:hidden;border:1px solid #dee2e6;"></div>
								<div class="form-text">Zoom, drag and click on the map to place the pin exactly where the customer lives.</div>
							</div>
						</div>
						<div class="row">
							<div class="col-md-6 mb-3">
								<label for="connection_type" class="form-label">Connection Type</label>
								<select class="form-select" id="connection_type" name="connection_type">
									<?php $selType = isset($_POST['connection_type']) ? $_POST['connection_type'] : ($editUser['connection_type'] ?? 'domestic'); ?>
									<option value="domestic" <?php echo $selType === 'domestic' ? 'selected' : ''; ?>>Domestic</option>
									<option value="commercial" <?php echo $selType === 'commercial' ? 'selected' : ''; ?>>Commercial</option>
								</select>
							</div>
								<div class="col-md-6 mb-3">
									<label for="unit_rate" class="form-label">Client Unit Rate (KES)</label>
									<input type="number" class="form-control" id="unit_rate" name="unit_rate" min="0" step="0.0001" value="<?php echo htmlspecialchars(isset($_POST['unit_rate']) ? $_POST['unit_rate'] : ($editUser['unit_rate'] ?? '')); ?>">
									<div class="form-text">Leave blank to use the global or tariff-plan rate.</div>
								</div>
							<div class="col-md-6 mb-3">
								<label for="password" class="form-label"><?php echo $isEditMode ? 'Password (leave blank to keep current)' : 'Password *'; ?></label>
								<input type="password" class="form-control" id="password" name="password" autocomplete="new-password" <?php echo $isEditMode ? '' : 'required'; ?>>
								<div class="form-text"><?php echo $isEditMode ? 'Only set a value if you want to change the password.' : 'The customer can change this password after logging in.'; ?></div>
							</div>
						</div>
						<input type="hidden" name="role" value="customer">

						<?php if ($registrationFee > 0 && !$isEditMode): ?>
							<div class="mb-3">
								<div class="form-text mb-1">Registration fee configured: KES <?php echo number_format($registrationFee, 2); ?></div>
								<div class="form-check">
									<input class="form-check-input" type="checkbox" value="1" id="registration_already_paid" name="registration_already_paid" <?php echo isset($_POST['registration_already_paid']) ? 'checked' : ''; ?>>
									<label class="form-check-label" for="registration_already_paid">
										Registration fee already paid (cash/M-Pesa/other).
									</label>
								</div>
								<div class="form-check mt-1">
									<input class="form-check-input" type="checkbox" value="1" id="send_stk" name="send_stk" <?php echo isset($_POST['send_stk']) ? 'checked' : ''; ?>>
									<label class="form-check-label" for="send_stk">
										Send M-Pesa STK push for registration fee now.
									</label>
								</div>
								<div class="mt-2">
									<label for="registration_mpesa_code" class="form-label">M-Pesa Transaction Code (if paid via M-Pesa)</label>
									<input type="text" class="form-control" id="registration_mpesa_code" name="registration_mpesa_code" autocomplete="off" value="<?php echo htmlspecialchars($_POST['registration_mpesa_code'] ?? ''); ?>" placeholder="e.g. QEU1XYZ123">
									<div class="form-text">Optional. Enter the M-Pesa transaction code for reconciliation when the registration fee was paid via M-Pesa.</div>
								</div>
								<div class="mt-2">
									<label for="registration_paid_amount" class="form-label">Amount Already Paid</label>
									<input type="number" class="form-control" id="registration_paid_amount" name="registration_paid_amount" min="0" step="0.01" value="<?php echo htmlspecialchars($_POST['registration_paid_amount'] ?? ''); ?>" placeholder="Leave blank to treat as fully paid">
									<div class="form-text">Use this when the client has paid only part of the registration fee. The system will keep the remaining balance outstanding and notify the client to clear it.</div>
								</div>
								<div class="form-text mt-1">If neither option is selected, you will be asked to choose one.</div>
							</div>
						<?php endif; ?>

						<div class="mt-3">
								<button type="submit" class="btn btn-primary"><?php echo $isEditMode ? 'Save Changes' : 'Create Customer'; ?></button>
						</div>
					</form>
				<?php else: ?>
					<div class="alert alert-info mb-0">
						<i class="bi bi-lock"></i> Finance accounts have read-only access to customer records. Contact an administrator to create or edit customers.
					</div>
				<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<?php if ($isEditMode && $isAdminUser && !empty($editUser['id'])): ?>
		<div class="row mt-4">
			<div class="col-12">
				<div class="card">
					<div class="card-header d-flex justify-content-between align-items-center">
						<h5 class="mb-0">Account Meters</h5>
						<span class="text-muted small">Account No: <?php echo htmlspecialchars((string)$editUser['account_number']); ?></span>
					</div>
					<div class="card-body">
						<p class="text-muted mb-3">Add more meters to this customer without changing the payment account number.<?php echo $registrationFee > 0 ? ' Each additional meter will create a registration-fee bill of KES ' . number_format($registrationFee, 2) . '.' : ''; ?></p>
						<form method="post" action="" class="row g-3 align-items-end">
							<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($usersCsrfToken); ?>">
							<input type="hidden" name="form_type" value="add_client_meter">
							<input type="hidden" name="user_id" value="<?php echo (int)$editUser['id']; ?>">
							<input type="hidden" name="page" value="<?php echo (int)$currentPage; ?>">
							<input type="hidden" name="customer_search" value="<?php echo htmlspecialchars($customerSearch); ?>">
							<input type="hidden" name="edit_id" value="<?php echo (int)$editUser['id']; ?>">
							<div class="col-md-6 col-lg-5">
								<label for="additional_meter_number" class="form-label">New Meter No</label>
								<input type="text" class="form-control" id="additional_meter_number" name="additional_meter_number" autocomplete="off" required>
							</div>
							<div class="col-md-6 col-lg-4">
								<label for="additional_meter_label" class="form-label">Property / Meter Label</label>
								<input type="text" class="form-control" id="additional_meter_label" name="additional_meter_label" autocomplete="off" placeholder="e.g. Plot B rental house">
							</div>
							<div class="col-md-6 col-lg-4">
								<button type="submit" class="btn btn-outline-primary w-100"><?php echo $registrationFee > 0 ? 'Add Meter & Charge Fee' : 'Add Meter'; ?></button>
							</div>
						</form>

						<div class="table-responsive mt-4">
							<table class="table table-sm align-middle mb-0">
								<thead>
									<tr>
										<th>Meter No</th>
										<th>Property / Label</th>
										<th>Type</th>
										<th>Status</th>
										<th>Fee Bill</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($editUserMeters as $meterRow): ?>
										<tr>
											<td><?php echo htmlspecialchars((string)$meterRow['meter_number']); ?></td>
											<td><?php echo htmlspecialchars((string)($meterRow['meter_label'] ?? '')); ?></td>
											<td>
												<span class="badge bg-<?php echo !empty($meterRow['is_primary']) ? 'dark' : 'info'; ?>">
													<?php echo !empty($meterRow['is_primary']) ? 'Primary' : 'Additional'; ?>
												</span>
											</td>
											<td><?php echo htmlspecialchars(ucfirst((string)($meterRow['status'] ?? 'active'))); ?></td>
											<td>
												<?php if (!empty($meterRow['registration_bill_id'])): ?>
													<a class="btn btn-sm btn-outline-secondary" href="/admin/bill-detail?bill_id=<?php echo (int)$meterRow['registration_bill_id']; ?>">Bill #<?php echo (int)$meterRow['registration_bill_id']; ?></a>
												<?php else: ?>
													<span class="text-muted">None</span>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>

						<div class="border rounded p-3 mt-4 bg-light-subtle">
							<h6 class="mb-3">Replace Faulty Meter</h6>
							<p class="text-muted small mb-3">Use this when an installed meter is faulty and the replacement meter should start with its own reading history.</p>
							<form method="post" action="" class="row g-3 align-items-end">
								<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($usersCsrfToken); ?>">
								<input type="hidden" name="form_type" value="replace_client_meter">
								<input type="hidden" name="user_id" value="<?php echo (int)$editUser['id']; ?>">
								<input type="hidden" name="page" value="<?php echo (int)$currentPage; ?>">
								<input type="hidden" name="customer_search" value="<?php echo htmlspecialchars($customerSearch); ?>">
								<input type="hidden" name="edit_id" value="<?php echo (int)$editUser['id']; ?>">
								<div class="col-md-6 col-lg-4">
									<label for="old_meter_id" class="form-label">Faulty Meter</label>
									<select class="form-select" id="old_meter_id" name="old_meter_id" required>
										<option value="">Select meter</option>
										<?php foreach ($editUserMeters as $meterRow): ?>
											<?php if (($meterRow['status'] ?? 'active') !== 'active') { continue; } ?>
											<option value="<?php echo (int)$meterRow['id']; ?>" <?php echo isset($_POST['old_meter_id']) && (int)$_POST['old_meter_id'] === (int)$meterRow['id'] ? 'selected' : ''; ?>>
												<?php echo htmlspecialchars((string)$meterRow['meter_number']); ?><?php echo !empty($meterRow['meter_label']) ? ' - ' . htmlspecialchars((string)$meterRow['meter_label']) : ''; ?><?php echo !empty($meterRow['is_primary']) ? ' (Primary)' : ''; ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="col-md-6 col-lg-4">
									<label for="replacement_meter_number" class="form-label">Replacement Meter No</label>
									<input type="text" class="form-control" id="replacement_meter_number" name="replacement_meter_number" value="<?php echo htmlspecialchars((string)($_POST['replacement_meter_number'] ?? '')); ?>" autocomplete="off" required>
								</div>
								<div class="col-md-6 col-lg-4">
									<label for="replacement_meter_label" class="form-label">New Meter Label</label>
									<input type="text" class="form-control" id="replacement_meter_label" name="replacement_meter_label" value="<?php echo htmlspecialchars((string)($_POST['replacement_meter_label'] ?? '')); ?>" autocomplete="off" placeholder="Optional label">
								</div>
								<div class="col-md-6 col-lg-3">
									<label for="old_final_reading" class="form-label">Old Meter Closing Reading</label>
									<input type="number" step="0.01" min="0" class="form-control" id="old_final_reading" name="old_final_reading" value="<?php echo htmlspecialchars((string)($_POST['old_final_reading'] ?? '0')); ?>">
								</div>
								<div class="col-md-6 col-lg-3">
									<label for="new_opening_reading" class="form-label">New Meter Opening Reading</label>
									<input type="number" step="0.01" min="0" class="form-control" id="new_opening_reading" name="new_opening_reading" value="<?php echo htmlspecialchars((string)($_POST['new_opening_reading'] ?? '0')); ?>">
								</div>
								<div class="col-md-12 col-lg-4">
									<label for="replacement_reason" class="form-label">Reason</label>
									<input type="text" class="form-control" id="replacement_reason" name="replacement_reason" value="<?php echo htmlspecialchars((string)($_POST['replacement_reason'] ?? '')); ?>" maxlength="255" placeholder="e.g. Faulty meter stopped counting">
								</div>
								<div class="col-md-12 col-lg-2">
									<button type="submit" class="btn btn-warning w-100">Replace Meter</button>
								</div>
							</form>
						</div>

						<?php if (!empty($editUserMeterReplacements)): ?>
							<div class="mt-4">
								<h6 class="mb-3">Replacement History</h6>
								<div class="table-responsive">
									<table class="table table-sm align-middle mb-0">
										<thead>
											<tr>
												<th>Date</th>
												<th>Old Meter</th>
												<th>New Meter</th>
												<th>Closing</th>
												<th>Opening</th>
												<th>Reason</th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ($editUserMeterReplacements as $replacementRow): ?>
												<tr>
													<td><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime((string)$replacementRow['replaced_at']))); ?></td>
													<td><?php echo htmlspecialchars((string)$replacementRow['old_meter_number']); ?></td>
													<td><?php echo htmlspecialchars((string)$replacementRow['new_meter_number']); ?></td>
													<td><?php echo number_format((float)$replacementRow['old_final_reading'], 2); ?></td>
													<td><?php echo number_format((float)$replacementRow['new_opening_reading'], 2); ?></td>
													<td><?php echo htmlspecialchars((string)($replacementRow['reason'] ?? '')); ?></td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
	<?php endif; ?>
</div>

<?php
$googleMapsApiKey = Database::env('GOOGLE_MAPS_API_KEY', '');

if ($googleMapsApiKey) {
	$custom_scripts = <<<JS
<script>
function initAdminCustomerLocationMap() {
	var btn = document.getElementById('adminDetectLocationBtn');
	var latInput = document.getElementById('latitude');
	var lngInput = document.getElementById('longitude');
	var locationInput = document.getElementById('location_label');
	var mapEl = document.getElementById('customerLocationMap');
	var dmsInput = document.getElementById('gps_dms_input');

	if (!mapEl || typeof google === 'undefined' || !google.maps) {
		return;
	}

	var defaultLat = -1.292066; // Nairobi fallback
	var defaultLng = 36.821945;
	var startLat = defaultLat;
	var startLng = defaultLng;
	var zoom = 13;

	if (latInput && lngInput && latInput.value && lngInput.value) {
		var parsedLat = parseFloat(latInput.value);
		var parsedLng = parseFloat(lngInput.value);
		if (!isNaN(parsedLat) && !isNaN(parsedLng)) {
			startLat = parsedLat;
			startLng = parsedLng;
			zoom = 16;
		}
	}

	var map = new google.maps.Map(mapEl, {
		center: { lat: startLat, lng: startLng },
		zoom: zoom,
		mapTypeId: google.maps.MapTypeId.ROADMAP,
		mapTypeControl: true,
		streetViewControl: false
	});

	var marker = null;

	function updateInputsFromLatLng(lat, lng) {
		if (!latInput || !lngInput) return;
		latInput.value = lat.toFixed(7);
		lngInput.value = lng.toFixed(7);
	}

	function ensureMarker(lat, lng) {
		if (!map) return;
		var pos = new google.maps.LatLng(lat, lng);
		if (!marker) {
			marker = new google.maps.Marker({
				position: pos,
				map: map,
				draggable: true
			});
			marker.addListener('dragend', function() {
				var ll = marker.getPosition();
				updateInputsFromLatLng(ll.lat(), ll.lng());
			});
		} else {
			marker.setPosition(pos);
		}
		updateInputsFromLatLng(lat, lng);
	}

	function parseDMSValue(input) {
		if (!input) return null;
		var str = input.trim();
		if (!str) return null;

		// Normalize quotes
		str = str.replace(/"/g, '"').replace(/'/g, "'");

		var latRegex = /(\d+)[°º]\s*(\d+)[']\s*([0-9.]+)["”]?\s*([NS])/i;
		var lonRegex = /(\d+)[°º]\s*(\d+)[']\s*([0-9.]+)["”]?\s*([EW])/i;

		var latMatch = str.match(latRegex);
		var lonMatch = str.match(lonRegex);

		if (!latMatch || !lonMatch) {
			return null;
		}

		function toDecimal(deg, min, sec, hemi) {
			var d = parseFloat(deg) + parseFloat(min) / 60 + parseFloat(sec) / 3600;
			if (/[SW]/i.test(hemi)) {
				return -d;
			}
			return d;
		}

		var lat = toDecimal(latMatch[1], latMatch[2], latMatch[3], latMatch[4]);
		var lng = toDecimal(lonMatch[1], lonMatch[2], lonMatch[3], lonMatch[4]);

		if (isNaN(lat) || isNaN(lng)) {
			return null;
		}
		return { lat: lat, lng: lng };
	}

	function geocodeAndZoomFromQuery(query, showToastOnFound) {
		if (!map) return;
		if (!query) return;
		query = query.trim();
		if (query.length < 3) return;

		var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(query + ', Kenya');
		fetch(url, { headers: { 'Accept-Language': 'en' } })
			.then(function(resp) { return resp.json(); })
			.then(function(results) {
				if (!Array.isArray(results) || results.length === 0) {
					return;
				}
				var r = results[0];
				var lat = parseFloat(r.lat);
				var lng = parseFloat(r.lon);
				if (isNaN(lat) || isNaN(lng)) return;
				map.setCenter({ lat: lat, lng: lng });
				map.setZoom(16);
				ensureMarker(lat, lng);
				if (showToastOnFound && window.showToast) {
					showToast('Suggested location for "' + query + '". Adjust on the map if needed.', 'info');
				}
			})
			.catch(function() {
				// Fail silently; admin can still place pin manually.
			});
	}

	// Initialize marker if existing GPS values are present
	if (latInput && lngInput && latInput.value && lngInput.value && !isNaN(parseFloat(latInput.value)) && !isNaN(parseFloat(lngInput.value))) {
		ensureMarker(parseFloat(latInput.value), parseFloat(lngInput.value));
	}

	// Click on map to place/move pin
	map.addListener('click', function(e) {
		var lat = e.latLng.lat();
		var lng = e.latLng.lng();
		ensureMarker(lat, lng);
	});

	// Geolocation button
	if (btn) {
		btn.addEventListener('click', function() {
			if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost') {
				var insecureMsg = 'GPS detection requires HTTPS. Open this page using https:// and try again, or pin location manually on the map.';
				if (window.showToast) {
					showToast(insecureMsg, 'danger');
				} else {
					alert(insecureMsg);
				}
				return;
			}

			if (!navigator.geolocation) {
				var msg = 'Geolocation is not supported by this browser.';
				if (window.showToast) {
					showToast(msg, 'danger');
				} else {
					alert(msg);
				}
				return;
			}
			btn.disabled = true;
			btn.textContent = 'Detecting location...';

			var bestPosition = null;
			var finished = false;
			var watchId = null;
			var finishTimer = null;

			function resetButton() {
				btn.disabled = false;
				btn.innerHTML = '<i class="bi bi-geo-alt"></i> Use my current GPS location';
			}

			function finishWithPosition(position) {
				if (finished) return;
				finished = true;
				if (watchId !== null) {
					navigator.geolocation.clearWatch(watchId);
				}
				if (finishTimer) {
					clearTimeout(finishTimer);
				}

				var lat = position.coords.latitude;
				var lng = position.coords.longitude;
				var accuracy = typeof position.coords.accuracy === 'number' ? position.coords.accuracy : 999999;

				if (accuracy > 5000) {
					var isLikelyMobile = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent || '');
					if (isLikelyMobile) {
						if (window.showToast) {
							showToast('Detected location is too coarse (~' + Math.round(accuracy) + 'm). Enable precise location/GPS on your phone and try again, or pin manually on the map.', 'danger');
						}
						resetButton();
						return;
					}

					// Desktop/laptop fallback: allow approximate location and let admin refine pin manually.
					if (window.showToast) {
						showToast('Using approximate location (~' + Math.round(accuracy) + 'm). Please drag the pin to the exact spot if needed.', 'warning');
					}
				}

				map.setCenter({ lat: lat, lng: lng });
				map.setZoom(18);
				ensureMarker(lat, lng);

				if (window.showToast) {
					if (accuracy > 100) {
						showToast('Location detected, but accuracy is low (~' + Math.round(accuracy) + 'm). Move to open sky and tap again for a better fix.', 'warning');
					} else {
						showToast('Location detected (accuracy ~' + Math.round(accuracy) + 'm).', 'success');
					}
				}

				resetButton();
			}

			function finishWithError(error) {
				if (finished) return;
				finished = true;
				if (watchId !== null) {
					navigator.geolocation.clearWatch(watchId);
				}
				if (finishTimer) {
					clearTimeout(finishTimer);
				}

				var msg = 'Unable to get location. Please allow location access in your browser.';
				if (error && typeof error.code !== 'undefined') {
					if (error.code === 1) {
						msg = 'Location access was denied. Allow location permission in your browser, then try again.';
					} else if (error.code === 2) {
						msg = 'Location is currently unavailable. Please check GPS/network and try again, or place the pin manually.';
					} else if (error.code === 3) {
						msg = 'Location request timed out. Move to an area with better signal and try again.';
					}
				}
				if (window.showToast) {
					showToast(msg, 'danger');
				} else {
					alert(msg);
				}
				resetButton();
			}

			watchId = navigator.geolocation.watchPosition(function(position) {
				if (!bestPosition) {
					bestPosition = position;
				} else {
					var oldAcc = typeof bestPosition.coords.accuracy === 'number' ? bestPosition.coords.accuracy : 999999;
					var newAcc = typeof position.coords.accuracy === 'number' ? position.coords.accuracy : 999999;
					if (newAcc < oldAcc) {
						bestPosition = position;
					}
				}

				var currentAcc = typeof position.coords.accuracy === 'number' ? position.coords.accuracy : 999999;
				if (currentAcc <= 60) {
					finishWithPosition(position);
				}
			}, function(error) {
				if (error && error.code === 1) {
					finishWithError(error);
				}
			}, {
				enableHighAccuracy: true,
				timeout: 10000,
				maximumAge: 0
			});

			finishTimer = setTimeout(function() {
				if (bestPosition) {
					finishWithPosition(bestPosition);
				} else {
					finishWithError({ code: 3 });
				}
			}, 12000);
		});
	}

	// Allow admin to paste DMS coordinates like 1°15'51.6"S 37°11'15.2"E
	if (dmsInput) {
		var applyDms = function() {
			var parsed = parseDMSValue(dmsInput.value || '');
			if (!parsed) {
				if (window.showToast && dmsInput.value.trim() !== '') {
					showToast('Could not understand the coordinates. Please use a format like 1°15\'51.6"S 37°11\'15.2"E.', 'danger');
				}
				return;
			}
			map.setCenter({ lat: parsed.lat, lng: parsed.lng });
			map.setZoom(16);
			ensureMarker(parsed.lat, parsed.lng);
			if (window.showToast) {
				showToast('GPS coordinates applied from DMS input.', 'success');
			}
		};

		dmsInput.addEventListener('change', applyDms);
		dmsInput.addEventListener('blur', applyDms);
		dmsInput.addEventListener('keydown', function(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				applyDms();
			}
		});
	}

	// When admin types or changes the textual location, try to zoom map
	if (locationInput) {
		var lastLocationQuery = '';
		locationInput.addEventListener('change', function() {
			var q = locationInput.value || '';
			if (q.trim() === '' || q.trim() === lastLocationQuery) return;
			lastLocationQuery = q.trim();
			geocodeAndZoomFromQuery(lastLocationQuery, true);
		});
		locationInput.addEventListener('blur', function() {
			var q = locationInput.value || '';
			q = q.trim();
			if (q.length < 3 || q === lastLocationQuery) return;
			lastLocationQuery = q;
			geocodeAndZoomFromQuery(lastLocationQuery, true);
		});
	}

	// If we don't have GPS yet but we do have a textual location, try to suggest a pin
	if ((!latInput || !latInput.value || !lngInput || !lngInput.value) && locationInput && locationInput.value) {
		geocodeAndZoomFromQuery(locationInput.value, true);
	}
}
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=$googleMapsApiKey&callback=initAdminCustomerLocationMap" async defer></script>
JS;
} else {
	// Fallback to Leaflet map if Google Maps API key is not configured
	$custom_scripts = <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function() {
	var btn = document.getElementById('adminDetectLocationBtn');
	var latInput = document.getElementById('latitude');
	var lngInput = document.getElementById('longitude');
	var locationInput = document.getElementById('location_label');
	var mapEl = document.getElementById('customerLocationMap');
	var map = null;
	var marker = null;
	var dmsInput = document.getElementById('gps_dms_input');

	function updateInputsFromLatLng(lat, lng) {
		if (!latInput || !lngInput) return;
		latInput.value = lat.toFixed(7);
		lngInput.value = lng.toFixed(7);
	}

	function ensureMarker(lat, lng) {
		if (!map) return;
		var pos = [lat, lng];
		if (!marker) {
			marker = L.marker(pos, { draggable: true }).addTo(map);
			marker.on('dragend', function(e) {
				var ll = e.target.getLatLng();
				updateInputsFromLatLng(ll.lat, ll.lng);
			});
		} else {
			marker.setLatLng(pos);
		}
		updateInputsFromLatLng(lat, lng);
	}

	function parseDMSValue(input) {
		if (!input) return null;
		var str = input.trim();
		if (!str) return null;

		// Normalize quotes
		str = str.replace(/"/g, '"').replace(/'/g, "'");

		var latRegex = /(\d+)[°º]\s*(\d+)[']\s*([0-9.]+)["”]?\s*([NS])/i;
		var lonRegex = /(\d+)[°º]\s*(\d+)[']\s*([0-9.]+)["”]?\s*([EW])/i;

		var latMatch = str.match(latRegex);
		var lonMatch = str.match(lonRegex);

		if (!latMatch || !lonMatch) {
			return null;
		}

		function toDecimal(deg, min, sec, hemi) {
			var d = parseFloat(deg) + parseFloat(min) / 60 + parseFloat(sec) / 3600;
			if (/[SW]/i.test(hemi)) {
				return -d;
			}
			return d;
		}

		var lat = toDecimal(latMatch[1], latMatch[2], latMatch[3], latMatch[4]);
		var lng = toDecimal(lonMatch[1], lonMatch[2], lonMatch[3], lonMatch[4]);

		if (isNaN(lat) || isNaN(lng)) {
			return null;
		}
		return { lat: lat, lng: lng };
	}

	function geocodeAndZoomFromQuery(query, showToastOnFound) {
		if (!map) return;
		if (!query) return;
		query = query.trim();
		if (query.length < 3) return;

		var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(query + ', Kenya');
		fetch(url, { headers: { 'Accept-Language': 'en' } })
			.then(function(resp) { return resp.json(); })
			.then(function(results) {
				if (!Array.isArray(results) || results.length === 0) {
					return;
				}
				var r = results[0];
				var lat = parseFloat(r.lat);
				var lng = parseFloat(r.lon);
				if (isNaN(lat) || isNaN(lng)) return;
				map.setView([lat, lng], 16);
				ensureMarker(lat, lng);
				if (showToastOnFound && window.showToast) {
					showToast('Suggested location for "' + query + '". Adjust on the map if needed.', 'info');
				}
			})
			.catch(function() {
				// Fail silently; admin can still place pin manually.
			});
	}

	function initMap() {
		if (!mapEl || typeof L === 'undefined') {
			return;
		}
		var defaultLat = -1.292066;
		var defaultLng = 36.821945;
		var startLat = defaultLat;
		var startLng = defaultLng;
		var zoom = 13;

		if (latInput && lngInput && latInput.value && lngInput.value) {
			var parsedLat = parseFloat(latInput.value);
			var parsedLng = parseFloat(lngInput.value);
			if (!isNaN(parsedLat) && !isNaN(parsedLng)) {
				startLat = parsedLat;
				startLng = parsedLng;
				zoom = 16;
			}
		}

		map = L.map('customerLocationMap').setView([startLat, startLng], zoom);

		var streetsLayer = L.tileLayer('https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, Tiles style by Humanitarian OpenStreetMap Team hosted by OpenStreetMap France'
		});
		var satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{x}/{y}', {
			maxZoom: 19,
			attribution: 'Imagery &copy; <a href="https://www.esri.com/">Esri</a> &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community'
		});

		streetsLayer.addTo(map);
		L.control.layers({
			'Streets (OSM HOT)': streetsLayer,
			'Satellite (Esri)': satelliteLayer
		}, {}).addTo(map);

		if (latInput && lngInput && latInput.value && lngInput.value && !isNaN(parseFloat(latInput.value)) && !isNaN(parseFloat(lngInput.value))) {
			ensureMarker(parseFloat(latInput.value), parseFloat(lngInput.value));
		}

		map.on('click', function(e) {
			ensureMarker(e.latlng.lat, e.latlng.lng);
		});

		// Keep view focused on the current customer area when switching to satellite
		map.on('baselayerchange', function(e) {
			if (e && e.name === 'Satellite (Esri)') {
				if (latInput && lngInput && latInput.value && lngInput.value && !isNaN(parseFloat(latInput.value)) && !isNaN(parseFloat(lngInput.value))) {
					var clat = parseFloat(latInput.value);
					var clng = parseFloat(lngInput.value);
					map.setView([clat, clng], 16);
				} else {
					map.setView([startLat, startLng], zoom);
				}
			}
		});

		// If we don't have GPS yet but we do have a textual location,
		// try to suggest a pin using a geocoding service.
		if ((!latInput || !latInput.value || !lngInput || !lngInput.value) && locationInput && locationInput.value) {
			geocodeAndZoomFromQuery(locationInput.value, true);
		}
	}

	if (btn) {
		btn.addEventListener('click', function() {
			if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost') {
				var insecureMsg = 'GPS detection requires HTTPS. Open this page using https:// and try again, or pin location manually on the map.';
				if (window.showToast) {
					showToast(insecureMsg, 'danger');
				} else {
					alert(insecureMsg);
				}
				return;
			}

			if (!navigator.geolocation) {
				var msg = 'Geolocation is not supported by this browser.';
				if (window.showToast) {
					showToast(msg, 'danger');
				} else {
					alert(msg);
				}
				return;
			}
			btn.disabled = true;
			btn.textContent = 'Detecting location...';

			var bestPosition = null;
			var finished = false;
			var watchId = null;
			var finishTimer = null;

			function resetButton() {
				btn.disabled = false;
				btn.innerHTML = '<i class="bi bi-geo-alt"></i> Use my current GPS location';
			}

			function finishWithPosition(position) {
				if (finished) return;
				finished = true;
				if (watchId !== null) {
					navigator.geolocation.clearWatch(watchId);
				}
				if (finishTimer) {
					clearTimeout(finishTimer);
				}

				var lat = position.coords.latitude;
				var lng = position.coords.longitude;
				var accuracy = typeof position.coords.accuracy === 'number' ? position.coords.accuracy : 999999;

				if (accuracy > 5000) {
					var isLikelyMobile = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent || '');
					if (isLikelyMobile) {
						if (window.showToast) {
							showToast('Detected location is too coarse (~' + Math.round(accuracy) + 'm). Enable precise location/GPS on your phone and try again, or pin manually on the map.', 'danger');
						}
						resetButton();
						return;
					}

					// Desktop/laptop fallback: allow approximate location and let admin refine pin manually.
					if (window.showToast) {
						showToast('Using approximate location (~' + Math.round(accuracy) + 'm). Please drag the pin to the exact spot if needed.', 'warning');
					}
				}

				if (map) {
					map.setView([lat, lng], 18);
					ensureMarker(lat, lng);
				} else {
					updateInputsFromLatLng(lat, lng);
				}

				if (window.showToast) {
					if (accuracy > 100) {
						showToast('Location detected, but accuracy is low (~' + Math.round(accuracy) + 'm). Move to open sky and tap again for a better fix.', 'warning');
					} else {
						showToast('Location detected (accuracy ~' + Math.round(accuracy) + 'm).', 'success');
					}
				}

				resetButton();
			}

			function finishWithError(error) {
				if (finished) return;
				finished = true;
				if (watchId !== null) {
					navigator.geolocation.clearWatch(watchId);
				}
				if (finishTimer) {
					clearTimeout(finishTimer);
				}

				var msg = 'Unable to get location. Please allow location access in your browser.';
				if (error && typeof error.code !== 'undefined') {
					if (error.code === 1) {
						msg = 'Location access was denied. Allow location permission in your browser, then try again.';
					} else if (error.code === 2) {
						msg = 'Location is currently unavailable. Please check GPS/network and try again, or place the pin manually.';
					} else if (error.code === 3) {
						msg = 'Location request timed out. Move to an area with better signal and try again.';
					}
				}
				if (window.showToast) {
					showToast(msg, 'danger');
				} else {
					alert(msg);
				}
				resetButton();
			}

			watchId = navigator.geolocation.watchPosition(function(position) {
				if (!bestPosition) {
					bestPosition = position;
				} else {
					var oldAcc = typeof bestPosition.coords.accuracy === 'number' ? bestPosition.coords.accuracy : 999999;
					var newAcc = typeof position.coords.accuracy === 'number' ? position.coords.accuracy : 999999;
					if (newAcc < oldAcc) {
						bestPosition = position;
					}
				}

				var currentAcc = typeof position.coords.accuracy === 'number' ? position.coords.accuracy : 999999;
				if (currentAcc <= 60) {
					finishWithPosition(position);
				}
			}, function(error) {
				if (error && error.code === 1) {
					finishWithError(error);
				}
			}, {
				enableHighAccuracy: true,
				timeout: 10000,
				maximumAge: 0
			});

			finishTimer = setTimeout(function() {
				if (bestPosition) {
					finishWithPosition(bestPosition);
				} else {
					finishWithError({ code: 3 });
				}
			}, 12000);
		});
	}

	// Allow admin to paste DMS coordinates like 1°15'51.6"S 37°11'15.2"E
	if (dmsInput) {
		var applyDms = function() {
			var parsed = parseDMSValue(dmsInput.value || '');
			if (!parsed) {
				if (window.showToast && dmsInput.value.trim() !== '') {
					showToast('Could not understand the coordinates. Please use a format like 1°15\'51.6"S 37°11\'15.2"E.', 'danger');
				}
				return;
			}
			if (map) {
				map.setView([parsed.lat, parsed.lng], 16);
				ensureMarker(parsed.lat, parsed.lng);
			} else {
				updateInputsFromLatLng(parsed.lat, parsed.lng);
			}
			if (window.showToast) {
				showToast('GPS coordinates applied from DMS input.', 'success');
			}
		};

		dmsInput.addEventListener('change', applyDms);
		dmsInput.addEventListener('blur', applyDms);
		dmsInput.addEventListener('keydown', function(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				applyDms();
			}
		});
	}

	// When admin types or changes the textual location, try to zoom map
	if (locationInput) {
		var lastLocationQuery = '';
		locationInput.addEventListener('change', function() {
			var q = locationInput.value || '';
			if (q.trim() === '' || q.trim() === lastLocationQuery) return;
			lastLocationQuery = q.trim();
			geocodeAndZoomFromQuery(lastLocationQuery, true);
		});
		locationInput.addEventListener('blur', function() {
			var q = locationInput.value || '';
			q = q.trim();
			if (q.length < 3 || q === lastLocationQuery) return;
			lastLocationQuery = q;
			geocodeAndZoomFromQuery(lastLocationQuery, true);
		});
	}

	if (mapEl && typeof L !== 'undefined') {
		initMap();
	}
});
</script>
JS;
}

require_once __DIR__ . '/../../templates/footer.php';
?>
