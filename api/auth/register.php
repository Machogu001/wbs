<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';
require_once __DIR__ . '/../../includes/Mpesa.php';
require_once __DIR__ . '/../../includes/Payment.php';
require_once __DIR__ . '/../../includes/SMS.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if(!$db) {
        throw new Exception("Database connection failed");
    }
    
    $data = json_decode(file_get_contents("php://input"));
    
    if(!$data) {
        throw new Exception("Invalid JSON input");
    }
    
    // Validate required fields (meter_number will be auto-generated)
    $required = ['full_name', 'phone_number', 'email', 'password', 'id_number', 'address'];
    foreach($required as $field) {
        if(empty($data->$field)) {
            throw new Exception("Missing required field: $field");
        }
    }

    if (empty($data->first_name) || empty($data->last_name)) {
        throw new Exception("First name and last name are required");
    }
    
    $user = new User($db);
    
    // Check if phone already exists
    if($user->phoneExists($data->phone_number)) {
        throw new Exception("Phone number already registered");
    }
    
    // Generate sequential account number in format MTR0001, MTR0002, ...
    // Find the maximum existing numeric suffix and increment it
    $stmt = $db->query("SELECT account_number FROM users WHERE account_number LIKE 'MTR%' ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetch(PDO::FETCH_ASSOC);
    $nextNumber = 1;
    if ($last && !empty($last['account_number']) && preg_match('/^MTR(\d+)$/', $last['account_number'], $m)) {
        $nextNumber = (int)$m[1] + 1;
    }
    $account_number = 'MTR' . str_pad((string)$nextNumber, 4, '0', STR_PAD_LEFT);
    
    // Set user properties
    $user->account_number = $account_number;
    $user->full_name = trim($data->full_name);
    $user->phone_number = trim($data->phone_number);
    $user->email = isset($data->email) ? trim($data->email) : '';
    $user->id_number = trim($data->id_number);
    $user->address = trim($data->address);
    $user->tax_pin = isset($data->tax_pin) ? trim($data->tax_pin) : null;
    // Meter number matches account number
    $user->meter_number = $account_number;
    $user->connection_type = isset($data->connection_type) ? $data->connection_type : 'domestic';
    $user->password = $data->password;
    $user->role = 'customer';

    // Read registration fee setting
    $settingsService = new BillingSettings($db);
    $settings = $settingsService->getSettings();
    $registrationFee = isset($settings['registration_fee']) ? (float)$settings['registration_fee'] : 0.00;

    // If a registration fee is configured, require STK push before activating account
    if ($registrationFee > 0) {
        // Validate and normalize phone similar to bill payments
        if(!preg_match('/^(?:254|\+254|0)?((?:7|1)\d{8})$/', $data->phone_number, $matches)) {
            throw new Exception("Invalid phone number format for M-Pesa payment");
        }
        $formatted_phone = '254' . $matches[1];

        $mpesa = new Mpesa();
        $response = $mpesa->stkPush(
            $formatted_phone,
            $registrationFee,
            $account_number,
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

        // Create user in an inactive state until registration fee is paid
        // Note: users.status enum allows only 'active', 'inactive', 'suspended'
        // so we use 'inactive' here to represent a pending registration.
        $user->status = 'inactive';
        if(!$user->create()) {
            throw new Exception("Unable to register user");
        }

        // Create registration fee bill
        $billService = new Bill($db);
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
        // Link this payment explicitly to registration via registration_id
        $payment->registration_id = $user->id;
        $payment->create();

        http_response_code(201);
        echo json_encode(array(
            "status" => "success",
            "message" => "Registration initiated. An M-Pesa prompt has been sent for the registration fee. Your account will be activated after payment is received.",
            "data" => array(
                "account_number" => $account_number,
                "full_name" => $user->full_name,
                "phone_number" => $user->phone_number,
                "requires_payment" => true,
                "amount" => $registrationFee,
                "checkout_request_id" => $payment->checkout_request_id
            )
        ));
    } else {
        // No registration fee configured: behave as before
        $user->status = 'active';
        if(!$user->create()) {
            throw new Exception("Unable to register user");
        }

        // Send SMS with account details on successful registration (no fee case)
        $settings = $settingsService->getSettings();
        $companyName = !empty($settings['company_name']) ? $settings['company_name'] : 'BreMac Consultant Ltd';
        $sms = new SMS();
        $loginUrl = 'https://wbs.bremac.co.ke/';
        $messageText = "Dear " . $user->full_name . ",\n" .
            "Your water account has been created successfully.\n" .
            "Account No: " . $user->account_number . "\n" .
            "Meter No: " . $user->meter_number . "\n" .
            "You can now log in at " . $loginUrl . " using your account number, phone or email to view your bills and make payments.\n" .
            $companyName;
        // Ignore SMS failures silently
        $sms->send($user->phone_number, $messageText);

        // Also send an email with the same content if email is provided
        if (!empty($user->email)) {
            require_once __DIR__ . '/../../includes/Email.php';
            $email = new Email();
            $email->send(
                $user->email,
                'Your new water account details',
                $messageText
            );
        }

        http_response_code(201);
        echo json_encode(array(
            "status" => "success",
            "message" => "User registered successfully",
            "data" => array(
                "account_number" => $account_number,
                "full_name" => $user->full_name,
                "phone_number" => $user->phone_number,
                "requires_payment" => false
            )
        ));
    }
    
} catch(Exception $e) {
    http_response_code(400);
    echo json_encode(array(
        "status" => "error",
        "message" => $e->getMessage()
    ));
}
?>
