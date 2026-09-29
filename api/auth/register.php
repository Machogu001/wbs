<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';
require_once __DIR__ . '/../../includes/Mpesa.php';
require_once __DIR__ . '/../../includes/Payment.php';
require_once __DIR__ . '/../../includes/SMS.php';

function nextAccountNumber(PDO $db, string $prefix): string {
    $stmt = $db->prepare("SELECT account_number FROM users WHERE account_number LIKE :prefix ORDER BY id DESC LIMIT 1");
    $like = $prefix . '%';
    $stmt->bindParam(':prefix', $like);
    $stmt->execute();
    $last = $stmt->fetch(PDO::FETCH_ASSOC);

    $next = 1;
    if ($last && !empty($last['account_number']) && preg_match('/^' . preg_quote($prefix, '/') . '(\\d+)$/', $last['account_number'], $m)) {
        $next = (int)$m[1] + 1;
    }

    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function normalizePhoneFromPayload($data): string {
    $rawCountry = isset($data->phone_country_code) ? (string)$data->phone_country_code : '';
    $rawLocal = isset($data->phone_number_local) ? (string)$data->phone_number_local : '';

    $country = preg_replace('/\D+/', '', $rawCountry);
    $local = preg_replace('/\D+/', '', $rawLocal);

    if ($country !== '' && $local !== '') {
        // If user enters full international format in the local field, strip the repeated country code.
        if (strpos($local, $country) === 0 && strlen($local) > strlen($country)) {
            $local = substr($local, strlen($country));
        }

        $local = ltrim($local, '0');
        if ($local === '') {
            return '';
        }

        return $country . $local;
    }

    // Backward compatibility with old payloads using only phone_number.
    $legacy = preg_replace('/\D+/', '', (string)($data->phone_number ?? ''));
    if ($legacy === '') {
        return '';
    }

    if ($country !== '') {
        if (strpos($legacy, $country) === 0) {
            return $legacy;
        }
        $legacyLocal = $legacy;
        if (strpos($legacyLocal, $country) === 0 && strlen($legacyLocal) > strlen($country)) {
            $legacyLocal = substr($legacyLocal, strlen($country));
        }
        $legacyLocal = ltrim($legacyLocal, '0');
        if ($legacyLocal !== '') {
            return $country . $legacyLocal;
        }
    }

    return trim($legacy);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        throw new Exception("Database connection failed");
    }

    $data = json_decode(file_get_contents("php://input"));
    if (!$data) {
        throw new Exception("Invalid JSON input");
    }

    $registrationType = strtolower(trim((string)($data->registration_type ?? 'client')));
    if (!in_array($registrationType, ['client', 'customer', 'staff'], true)) {
        $registrationType = 'client';
    }
    $customerType = strtolower(trim((string)($data->customer_type ?? 'individual')));
    if (!in_array($customerType, ['individual', 'company'], true)) {
        $customerType = 'individual';
    }

    $firstName = trim((string)($data->first_name ?? ''));
    $middleName = trim((string)($data->middle_name ?? ''));
    $lastName = trim((string)($data->last_name ?? ''));
    $companyName = trim((string)($data->company_name ?? ''));
    $companyRegistrationNumber = trim((string)($data->company_registration_number ?? ''));
    $contactPersonName = trim(preg_replace('/\s+/', ' ', $firstName . ' ' . $middleName . ' ' . $lastName));

    $fullNameFromParts = trim(preg_replace('/\s+/', ' ', $firstName . ' ' . $middleName . ' ' . $lastName));
    $fullName = trim((string)($data->full_name ?? $fullNameFromParts));

    if ($fullName === '') {
        $fullName = $fullNameFromParts;
    }

    $phoneNumber = normalizePhoneFromPayload($data);
    $idNumber = trim((string)($data->id_number ?? ''));
    $staffUsername = trim((string)($data->username ?? ''));

    if ($firstName === '' || $lastName === '') {
        throw new Exception("First name and last name are required");
    }
    if ($phoneNumber === '') {
        throw new Exception("Phone number is required");
    }

    $user = new User($db);
    if ($user->phoneExists($phoneNumber)) {
        throw new Exception("Phone number already registered");
    }

    $settingsService = new BillingSettings($db);
    $settings = $settingsService->getSettings();
    $registrationFee = isset($settings['registration_fee']) ? (float)$settings['registration_fee'] : 0.00;
    $enforceLocationAccuracy = !empty($settings['enforce_location_accuracy']);

    // -------------------------------------------------------------
    // STAFF REGISTRATION: no meter number, no registration fee flow.
    // -------------------------------------------------------------
    if ($registrationType === 'staff') {
        if ($staffUsername === '') {
            throw new Exception("Username is required for office staff");
        }

        if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $staffUsername)) {
            throw new Exception("Username must be 3-30 characters and contain only letters, numbers, dot, underscore or hyphen");
        }

        if ($user->usernameExists($staffUsername)) {
            throw new Exception("Username already exists. Please choose another username");
        }

        $accountNumber = nextAccountNumber($db, 'STF');
        $tempPassword = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));

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
        // Default office staff role
        $user->role = 'reader';
        $user->status = 'active';

        if (!$user->create()) {
            throw new Exception("Unable to register staff user");
        }

        $sms = new SMS();
        $companyName = !empty($settings['company_name']) ? $settings['company_name'] : 'BreMac Consultant Ltd';
        $loginUrl = 'https://wbs.bremac.co.ke/';
        $messageText = "Dear " . $user->full_name . ",\n" .
            "Your office staff account has been created successfully.\n" .
            "Username: " . $user->username . "\n" .
            "Account No: " . $user->account_number . "\n" .
            "Temporary Password: " . $tempPassword . "\n" .
            "Please login and change your password at " . $loginUrl . "\n" .
            $companyName;
        $sms->send($user->phone_number, $messageText, 'account_creation');

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'Staff user registered successfully',
            'data' => [
                'registration_type' => 'staff',
                'account_number' => $accountNumber,
                'username' => $user->username,
                'full_name' => $user->full_name,
                'phone_number' => $user->phone_number,
                'requires_payment' => false,
                'temp_password' => $tempPassword,
            ]
        ]);
        exit;
    }

    if ($customerType === 'company') {
        if ($companyName === '' || $companyRegistrationNumber === '') {
            throw new Exception("Company name and company registration number are required for company registration");
        }
        $fullName = $companyName;
    } elseif ($idNumber === '') {
        throw new Exception("ID number is required for client registration");
    }

    // -------------------------------------------------------------
    // CLIENT REGISTRATION: requires customer details and meter number.
    // -------------------------------------------------------------
    $email = trim((string)($data->email ?? ''));
    $address = trim((string)($data->address ?? ''));
    $password = (string)($data->password ?? '');

    $requiredClient = [
        'email' => $email,
        'address' => $address,
        'password' => $password,
    ];
    foreach ($requiredClient as $field => $value) {
        if ($value === '') {
            throw new Exception("Missing required field for client registration: $field");
        }
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
        throw new Exception($customerType === 'company'
            ? 'A user with the same email address or company registration number already exists.'
            : 'A user with the same email address or ID number already exists.');
    }

    // Enforce GPS accuracy BEFORE any account is created or STK push is sent
    if ($enforceLocationAccuracy) {
        $submittedAccuracy = isset($data->gps_accuracy) && $data->gps_accuracy !== '' ? (float)$data->gps_accuracy : null;
        $hasLat = isset($data->latitude) && $data->latitude !== '';
        $hasLng = isset($data->longitude) && $data->longitude !== '';
        $locationLabel = isset($data->location_label) ? trim((string)$data->location_label) : '';
        if ($locationLabel === '') {
            http_response_code(422);
            echo json_encode([
                'status' => 'error',
                'message' => 'Location is required when GPS enforcement is enabled.'
            ]);
            exit;
        }
        if (!$hasLat || !$hasLng || $submittedAccuracy === null || $submittedAccuracy > 14) {
            http_response_code(422);
            echo json_encode([
                'status' => 'error',
                'message' => 'GPS location with accuracy ≤14m is required. Please use the "Use my current GPS location" button outdoors and try again.'
            ]);
            exit;
        }
    }

    $accountNumber = nextAccountNumber($db, 'MTR');

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
    $user->tax_pin = isset($data->tax_pin) ? trim((string)$data->tax_pin) : null;
    $user->meter_number = $accountNumber;
    $user->connection_type = isset($data->connection_type) ? (string)$data->connection_type : 'domestic';
    $user->location_label = isset($data->location_label) ? trim((string)$data->location_label) : null;
    $clientLatitude = null;
    $clientLongitude = null;
    if (isset($data->latitude) && $data->latitude !== '') {
        $clientLatitude = (float)$data->latitude;
        $user->latitude = $clientLatitude;
    }
    if (isset($data->longitude) && $data->longitude !== '') {
        $clientLongitude = (float)$data->longitude;
        $user->longitude = $clientLongitude;
    }

    $user->password = $password;
    $user->role = 'customer';

    if ($registrationFee > 0) {
        if (!preg_match('/^(?:254|\+254|0)?((?:7|1)\d{8})$/', $phoneNumber, $matches)) {
            throw new Exception("Invalid phone number format for M-Pesa payment");
        }
        $formattedPhone = '254' . $matches[1];

        $mpesa = new Mpesa();
        $response = $mpesa->stkPush(
            $formattedPhone,
            $registrationFee,
            $accountNumber,
            "Registration Fee"
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

        $user->status = 'inactive';
        if (!$user->create()) {
            throw new Exception("Unable to register user");
        }

        $billService = new Bill($db);
        $dueDate = date('Y-m-d', strtotime('+14 days'));
        $billId = $billService->createRegistrationFeeBill($user->id, $user->account_number, $registrationFee, $dueDate, 'pending');

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

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'Client registration initiated. Complete M-Pesa payment to activate account.',
            'data' => [
                'registration_type' => 'client',
                'account_number' => $accountNumber,
                'full_name' => $user->full_name,
                'phone_number' => $user->phone_number,
                'requires_payment' => true,
                'amount' => $registrationFee,
                'checkout_request_id' => $payment->checkout_request_id,
            ]
        ]);
    } else {
        $user->status = 'active';
        if (!$user->create()) {
            throw new Exception("Unable to register user");
        }

        $companyName = !empty($settings['company_name']) ? $settings['company_name'] : 'BreMac Consultant Ltd';
        $sms = new SMS();
        $loginUrl = 'https://wbs.bremac.co.ke/';
        $messageText = "Dear " . $user->full_name . ",\n" .
            "Your water account has been created successfully.\n" .
            "Account No: " . $user->account_number . "\n" .
            "Meter No: " . $user->meter_number . "\n" .
            "You can now log in at " . $loginUrl . " using your account number, phone, email or username to view your bills and make payments.\n" .
            $companyName;
        $sms->send($user->phone_number, $messageText, 'registration');

        if (!empty($user->email)) {
            require_once __DIR__ . '/../../includes/Email.php';
            $emailSvc = new Email();
            $emailSvc->send($user->email, 'Your new water account details', $messageText);
        }

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'Client registered successfully',
            'data' => [
                'registration_type' => 'client',
                'account_number' => $accountNumber,
                'full_name' => $user->full_name,
                'phone_number' => $user->phone_number,
                'requires_payment' => false,
            ]
        ]);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
