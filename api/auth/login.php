<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Payment.php';

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
    
    // Validate required fields
    if(empty($data->identifier) || empty($data->password)) {
        throw new Exception("Account number, phone or email and password are required");
    }
    
    $user = new User($db);
    $auth = new Auth($db);
    
    // Attempt login with account number, phone number, or email (active users only)
    $user_data = $user->login($data->identifier, $data->password);
    
    if($user_data) {
        // Start session and login
        $auth->login($user_data['id'], $user_data);

        $requiresPasswordChange = !empty($user_data['must_change_password']);
        
        http_response_code(200);
        echo json_encode(array(
            "status" => "success",
            "message" => "Login successful",
            "data" => array(
                "user" => $user_data,
                "session_id" => session_id(),
                "requires_registration_payment" => false,
                "requires_password_change" => $requiresPasswordChange
            )
        ));
    } else {
        // If normal login fails, check if credentials match a non-active account
        $authRow = $user->getAuthRowByIdentifier($data->identifier);

        if ($authRow && password_verify($data->password, $authRow['password_hash'])) {
            // Credentials are correct but account is not active
            if (isset($authRow['status']) && $authRow['status'] !== 'active') {
                $paymentModel = new Payment($db);
                $pendingPayment = $paymentModel->getLatestPendingRegistrationByUserId($authRow['id']);

                // Log in the user but indicate that registration payment is required
                $userPayload = $authRow;
                unset($userPayload['password_hash']);
                $auth->login($userPayload['id'], $userPayload);

                $registrationPaymentData = null;
                if ($pendingPayment) {
                    $registrationPaymentData = array(
                        "amount" => $pendingPayment['amount'],
                        "checkout_request_id" => $pendingPayment['checkout_request_id']
                    );
                }

                http_response_code(200);
                echo json_encode(array(
                    "status" => "success",
                    "message" => "Login successful. Please complete your registration payment.",
                    "data" => array(
                        "user" => $userPayload,
                        "session_id" => session_id(),
                        "requires_registration_payment" => true,
                        "registration_payment" => $registrationPaymentData
                    )
                ));
                return;
            }
        }

        // Fallback: invalid credentials or non-activation with wrong password
        throw new Exception("Invalid account, phone/email or password");
    }
    
} catch(Exception $e) {
    http_response_code(401);
    echo json_encode(array(
        "status" => "error",
        "message" => $e->getMessage()
    ));
}
?>
