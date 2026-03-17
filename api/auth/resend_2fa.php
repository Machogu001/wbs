<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/SMS.php';
require_once __DIR__ . '/../../includes/Email.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Database connection failed');
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new Exception('Invalid JSON input');
    }

    $requestedMethod = isset($payload['method']) ? strtolower(trim((string)$payload['method'])) : '';
    if ($requestedMethod !== 'sms' && $requestedMethod !== 'email') {
        throw new Exception('Invalid delivery method.');
    }

    $twofa = $_SESSION['login_2fa'] ?? null;
    if (!$twofa || empty($twofa['user_id']) || empty($twofa['identifier']) || empty($twofa['ip'])) {
        throw new Exception('No active verification session. Please log in again.');
    }

    $userId = (int)$twofa['user_id'];
    $userModel = new User($db);
    $row = $userModel->getById($userId);
    if (!$row) {
        unset($_SESSION['login_2fa']);
        throw new Exception('User not found. Please log in again.');
    }

    $phone = trim((string)($row['phone_number'] ?? ''));
    $emailAddr = trim((string)($row['email'] ?? ''));

    if ($requestedMethod === 'sms' && $phone === '') {
        throw new Exception('No phone number on file to send SMS.');
    }
    if ($requestedMethod === 'email' && ($emailAddr === '' || !filter_var($emailAddr, FILTER_VALIDATE_EMAIL))) {
        throw new Exception('No valid email address on file to send email.');
    }

    // Simple resend cooldown: e.g. 60 seconds between sends
    $now = time();
    $cooldownSeconds = 60;
    $lastSentAt = isset($twofa['last_sent_at']) ? (int)$twofa['last_sent_at'] : 0;
    if ($lastSentAt > 0 && ($now - $lastSentAt) < $cooldownSeconds) {
        $remaining = $cooldownSeconds - ($now - $lastSentAt);

        http_response_code(200);
        echo json_encode([
            'status' => 'cooldown',
            'message' => 'Please wait ' . $remaining . ' seconds before requesting another code.',
            'data' => [
                'remaining' => $remaining,
            ],
        ]);
        exit;
    }

    $code = (string)random_int(100000, 999999);
    $appName = getenv('APP_NAME') ?: 'Water Billing System';
    $messageText = "{$code} is your {$appName} login verification code. It expires in 5 minutes.";

    $sent = false;
    $lastError = '';

    if ($requestedMethod === 'sms') {
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
        throw new Exception('Failed to resend verification code: ' . $lastError);
    }

    $_SESSION['login_2fa']['code'] = $code;
    $_SESSION['login_2fa']['method'] = $requestedMethod;
    $_SESSION['login_2fa']['expires_at'] = time() + 300;
    $_SESSION['login_2fa']['attempts'] = 0;
    $_SESSION['login_2fa']['last_sent_at'] = $now;

    // Log resend action
    $activity = new ActivityLog($db);
    $activity->log(
        $userId,
        'login_2fa_resend',
        null,
        null,
        'Resent 2FA verification code via ' . $requestedMethod,
        [
            'method' => $requestedMethod,
        ]
    );

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Verification code resent',
        'data' => [
            'method' => $requestedMethod,
        ],
    ]);
} catch (Exception $e) {
    if (http_response_code() < 400) {
        http_response_code(400);
    }
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ]);
}
