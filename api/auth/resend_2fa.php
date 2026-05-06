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
    // WebOTP binding line — Android Chrome reads this line to auto-fill the code
    $otpDomain = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? (getenv('APP_DOMAIN') ?: ''));
    $otpSuffix = $otpDomain !== '' ? "\n\n@{$otpDomain} #{$code}" : '';
    $messageText = "{$code} is your {$appName} login verification code. It expires in 5 minutes.{$otpSuffix}";

    $sent = false;
    $lastError = '';
    $actualMethod = $requestedMethod;
    $attemptedMethods = [];

    $sendByMethod = function(string $m) use (&$lastError, $phone, $emailAddr, $messageText, $db): bool {
        if ($m === 'sms') {
            $sms = new SMS($db);
            $result = $sms->sendWithFallback($phone, $messageText, 'login_otp');
            $deliveryMode = (string)($result['delivery_mode'] ?? 'failed');
            if ($deliveryMode === 'immediate') {
                return true;
            }
            if ($deliveryMode === 'queued') {
                $lastError = 'SMS delivery was queued and may be delayed';
                return false;
            }
            $lastError = (string)($result['message'] ?? 'SMS send failed');
            return false;
        }

        $email = new Email();
        $result = $email->send($emailAddr, 'Your login verification code', $messageText);
        if (!empty($result['success'])) {
            return true;
        }
        $lastError = (string)($result['message'] ?? 'Email send failed');
        return false;
    };

    $attemptedMethods[] = $requestedMethod;
    $sent = $sendByMethod($requestedMethod);

    if (!$sent) {
        $alternate = $requestedMethod === 'sms' ? 'email' : 'sms';
        $canUseAlternate = ($alternate === 'sms' && $phone !== '') || ($alternate === 'email' && $emailAddr !== '' && filter_var($emailAddr, FILTER_VALIDATE_EMAIL));
        if ($canUseAlternate) {
            $attemptedMethods[] = $alternate;
            if ($sendByMethod($alternate)) {
                $sent = true;
                $actualMethod = $alternate;
            }
        }
    }

    if (!$sent) {
        throw new Exception('Failed to resend verification code via ' . implode(' then ', $attemptedMethods) . ': ' . $lastError);
    }

    $_SESSION['login_2fa']['code'] = $code;
    $_SESSION['login_2fa']['method'] = $actualMethod;
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
        'Resent 2FA verification code via ' . $actualMethod,
        [
            'requested_method' => $requestedMethod,
            'method' => $actualMethod,
            'fallback_used' => $actualMethod !== $requestedMethod,
        ]
    );

    $responseMessage = 'Verification code resent';
    if ($actualMethod !== $requestedMethod) {
        $responseMessage .= ' via ' . $actualMethod . ' (fallback)';
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => $responseMessage,
        'data' => [
            'method' => $actualMethod,
            'requested_method' => $requestedMethod,
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
