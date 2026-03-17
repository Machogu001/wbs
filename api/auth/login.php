<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Payment.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';
require_once __DIR__ . '/../../includes/SMS.php';
require_once __DIR__ . '/../../includes/Email.php';

// Simple login rate limiting to protect against brute-force attacks
const LOGIN_MAX_ATTEMPTS = 5;          // maximum failed attempts
const LOGIN_WINDOW_SECONDS = 600;      // window size in seconds (10 minutes)

function getClientIp(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function ensureLoginAttemptsTable(PDO $db): void {
    $sql = "CREATE TABLE IF NOT EXISTS login_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                identifier VARCHAR(255) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                attempts INT NOT NULL DEFAULT 0,
                last_attempt_at DATETIME NOT NULL,
                INDEX idx_identifier_ip (identifier, ip_address),
                INDEX idx_last_attempt_at (last_attempt_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
}

function isRateLimited(PDO $db, string $identifier, string $ip): bool {
    $stmt = $db->prepare("SELECT attempts, last_attempt_at FROM login_attempts WHERE identifier = :identifier AND ip_address = :ip LIMIT 1");
    $stmt->bindParam(':identifier', $identifier);
    $stmt->bindParam(':ip', $ip);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return false;
    }

    $lastAttemptTs = strtotime($row['last_attempt_at']);
    if ($lastAttemptTs === false) {
        return false;
    }

    if ((time() - $lastAttemptTs) > LOGIN_WINDOW_SECONDS) {
        // Window has passed; not rate limited
        return false;
    }

    return ((int)$row['attempts'] >= LOGIN_MAX_ATTEMPTS);
}

function recordFailedAttempt(PDO $db, string $identifier, string $ip): void {
    $now = date('Y-m-d H:i:s');

    $stmt = $db->prepare("SELECT id, attempts, last_attempt_at FROM login_attempts WHERE identifier = :identifier AND ip_address = :ip LIMIT 1");
    $stmt->bindParam(':identifier', $identifier);
    $stmt->bindParam(':ip', $ip);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $lastAttemptTs = strtotime($row['last_attempt_at']);
        $attempts = (int)$row['attempts'];

        if ($lastAttemptTs === false || (time() - $lastAttemptTs) > LOGIN_WINDOW_SECONDS) {
            // Reset window
            $attempts = 1;
        } else {
            $attempts++;
        }

        $update = $db->prepare("UPDATE login_attempts SET attempts = :attempts, last_attempt_at = :last_attempt_at WHERE id = :id");
        $update->bindParam(':attempts', $attempts, PDO::PARAM_INT);
        $update->bindParam(':last_attempt_at', $now);
        $update->bindParam(':id', $row['id'], PDO::PARAM_INT);
        $update->execute();
    } else {
        $attempts = 1;
        $insert = $db->prepare("INSERT INTO login_attempts (identifier, ip_address, attempts, last_attempt_at) VALUES (:identifier, :ip, :attempts, :last_attempt_at)");
        $insert->bindParam(':identifier', $identifier);
        $insert->bindParam(':ip', $ip);
        $insert->bindParam(':attempts', $attempts, PDO::PARAM_INT);
        $insert->bindParam(':last_attempt_at', $now);
        $insert->execute();
    }
}

function clearLoginAttempts(PDO $db, string $identifier, string $ip): void {
    $stmt = $db->prepare("DELETE FROM login_attempts WHERE identifier = :identifier AND ip_address = :ip");
    $stmt->bindParam(':identifier', $identifier);
    $stmt->bindParam(':ip', $ip);
    $stmt->execute();
}

function sendTwoFactorCode(array $userRow, string $identifier, string $clientIp): array {
    $method = isset($userRow['two_factor_method']) ? strtolower((string)$userRow['two_factor_method']) : 'sms';
    if ($method !== 'sms' && $method !== 'email') {
        $method = 'sms';
    }

    $phone = trim((string)($userRow['phone_number'] ?? ''));
    $emailAddr = trim((string)($userRow['email'] ?? ''));

    $availableMethods = [];
    if ($phone !== '') {
        $availableMethods[] = 'sms';
    }
    if ($emailAddr !== '' && filter_var($emailAddr, FILTER_VALIDATE_EMAIL)) {
        $availableMethods[] = 'email';
    }

    if ($method === 'sms' && $phone === '' && $emailAddr !== '') {
        $method = 'email';
    } elseif ($method === 'email' && ($emailAddr === '' || !filter_var($emailAddr, FILTER_VALIDATE_EMAIL)) && $phone !== '') {
        $method = 'sms';
    }

    if ($method === 'sms' && $phone === '') {
        return [
            'success' => false,
            'message' => 'Two-step verification is enabled, but no phone number is set. Please contact support.',
        ];
    }
    if ($method === 'email' && ($emailAddr === '' || !filter_var($emailAddr, FILTER_VALIDATE_EMAIL))) {
        return [
            'success' => false,
            'message' => 'Two-step verification is enabled, but no valid email address is set. Please contact support.',
        ];
    }

    $code = (string)random_int(100000, 999999);
    $appName = getenv('APP_NAME') ?: 'Water Billing System';
    $messageText = "{$code} is your {$appName} login verification code. It expires in 5 minutes.";

    $sent = false;
    $lastError = '';

    if ($method === 'sms') {
        $sms = new SMS();
        $result = $sms->send($phone, $messageText);
        $sent = !empty($result['success']);
        if (!$sent) {
            $lastError = (string)($result['message'] ?? 'SMS send failed');
        }
    } else {
        $email = new Email();
        $result = $email->send($emailAddr, 'Your login verification code', $messageText);
        $sent = !empty($result['success']);
        if (!$sent) {
            $lastError = (string)($result['message'] ?? 'Email send failed');
        }
    }

    if (!$sent) {
        return [
            'success' => false,
            'message' => 'Failed to send verification code: ' . $lastError,
        ];
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['login_2fa'] = [
        'user_id' => (int)$userRow['id'],
        'user' => $userRow,
        'code' => $code,
        'method' => $method,
        'identifier' => $identifier,
        'ip' => $clientIp,
        'expires_at' => time() + 300,
        'attempts' => 0,
    ];

    return [
        'success' => true,
        'method' => $method,
        'available_methods' => $availableMethods,
    ];
}

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

    $identifier = (string)$data->identifier;
    $clientIp = getClientIp();

    // Ensure login attempts table exists and enforce rate limit before checking credentials
    ensureLoginAttemptsTable($db);
    if (isRateLimited($db, $identifier, $clientIp)) {
        http_response_code(429);
        echo json_encode(array(
            "status" => "error",
            "message" => "Too many login attempts. Please try again after a few minutes."
        ));
        return;
    }

    $user = new User($db);
    $auth = new Auth($db);
    $logger = new ActivityLog($db);

    // Attempt login with account number, phone number, or email (active users only)
    $user_data = $user->login($identifier, $data->password);
    
    if($user_data) {
        $twoFactorEnabled = !empty($user_data['two_factor_enabled']);

        if ($twoFactorEnabled) {
            $sendResult = sendTwoFactorCode($user_data, $identifier, $clientIp);
            if (!$sendResult['success']) {
                throw new Exception($sendResult['message']);
            }

            // Password is correct; clear failed attempts immediately
            clearLoginAttempts($db, $identifier, $clientIp);

            http_response_code(200);
            echo json_encode(array(
                "status" => "two_factor_required",
                "message" => "Verification code sent",
                "data" => array(
                    "method" => $sendResult['method'],
                    "available_methods" => $sendResult['available_methods'] ?? array(),
                    "session_id" => session_id()
                )
            ));
            return;
        }

        // Start session and login (no 2FA)
        $auth->login($user_data['id'], $user_data);

        // Log successful login
        $logger->log(
            $user_data['id'],
            'login',
            'user',
            $user_data['id'],
            'User logged in successfully',
            array('identifier' => $identifier)
        );

        // Successful login: clear failed attempts for this identifier + IP
        clearLoginAttempts($db, $identifier, $clientIp);

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
        $authRow = $user->getAuthRowByIdentifier($identifier);

        if ($authRow && password_verify($data->password, $authRow['password_hash'])) {
            // Credentials are correct but account is not active
            if (isset($authRow['status']) && $authRow['status'] !== 'active') {
                $paymentModel = new Payment($db);
                $pendingPayment = $paymentModel->getLatestPendingRegistrationByUserId($authRow['id']);

                // Log in the user but indicate that registration payment is required
                $userPayload = $authRow;
                unset($userPayload['password_hash']);
                $auth->login($userPayload['id'], $userPayload);

                // Log login for non-active account (registration payment flow)
                $logger->log(
                    $userPayload['id'],
                    'login',
                    'user',
                    $userPayload['id'],
                    'User logged in (registration payment required)',
                    array('identifier' => $identifier, 'status' => $authRow['status'])
                );

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
                // Treat this as a successful login from a rate-limiting perspective
                clearLoginAttempts($db, $identifier, $clientIp);
                return;
            }
        }

        // Fallback: invalid credentials or non-activation with wrong password
        recordFailedAttempt($db, $identifier, $clientIp);
        throw new Exception("Invalid account, phone/email or password");
    }
    
} catch(Exception $e) {
    // Preserve any specific status code that may have been set (e.g. 429)
    $currentCode = http_response_code();
    if ($currentCode < 400 || $currentCode === 200) {
        http_response_code(401);
    }
    echo json_encode(array(
        "status" => "error",
        "message" => $e->getMessage()
    ));
}
?>
