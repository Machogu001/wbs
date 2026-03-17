<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/Auth.php';
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

    $code = isset($payload['code']) ? trim((string)$payload['code']) : '';
    if ($code === '') {
        throw new Exception('Verification code is required');
    }

    $twofa = $_SESSION['login_2fa'] ?? null;
    if (!$twofa || empty($twofa['user_id']) || empty($twofa['code']) || empty($twofa['expires_at'])) {
        throw new Exception('No active verification session. Please log in again.');
    }

    if (time() > (int)$twofa['expires_at']) {
        // Do not destroy the verification session when the code expires so that
        // the user can request a new code via the resend endpoint.
        throw new Exception('The verification code has expired. Please request a new code.');
    }

    $attempts = isset($twofa['attempts']) ? (int)$twofa['attempts'] : 0;
    if ($attempts >= 5) {
        unset($_SESSION['login_2fa']);
        throw new Exception('Too many incorrect codes. Please log in again.');
    }

    if ($code !== (string)$twofa['code']) {
        $_SESSION['login_2fa']['attempts'] = $attempts + 1;
        throw new Exception('Invalid verification code');
    }

    $userId = (int)$twofa['user_id'];
    $userModel = new User($db);
    $row = $userModel->getById($userId);
    if (!$row) {
        unset($_SESSION['login_2fa']);
        throw new Exception('User not found. Please log in again.');
    }

    // Rebuild the payload used for the normal login response
    if (isset($row['password_hash'])) {
        unset($row['password_hash']);
    }

    $auth = new Auth($db);
    $logger = new ActivityLog($db);

    $auth->login($row['id'], $row);

    $logger->log(
        $row['id'],
        'login',
        'user',
        $row['id'],
        'User logged in successfully (2FA)',
        array('identifier' => $twofa['identifier'] ?? null, 'two_factor_method' => $twofa['method'] ?? null)
    );

    unset($_SESSION['login_2fa']);

    $requiresPasswordChange = !empty($row['must_change_password']);

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Login successful',
        'data' => [
            'user' => $row,
            'session_id' => session_id(),
            'requires_registration_payment' => false,
            'requires_password_change' => $requiresPasswordChange,
        ],
    ]);
} catch (Exception $e) {
    if (http_response_code() < 400) {
        http_response_code(401);
    }
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ]);
}
