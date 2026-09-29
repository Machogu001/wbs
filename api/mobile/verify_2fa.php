<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('POST');
    $db = mobileApiGetDatabase();
    $payload = mobileApiReadJson();

    $challengeToken = trim((string)($payload['challenge_token'] ?? ''));
    $code = trim((string)($payload['code'] ?? ''));
    $deviceName = trim((string)($payload['device_name'] ?? ''));

    if ($challengeToken === '' || $code === '') {
        mobileApiJson(422, 'error', 'Challenge token and verification code are required.');
    }

    $authService = mobileApiGetAuthService($db);
    $challenge = $authService->verifyTwoFactorChallenge($challengeToken, $code);
    unset($challenge['password_hash']);

    $token = $authService->issueAccessToken((int)$challenge['user_id'], $deviceName !== '' ? $deviceName : null);
    $requiresRegistrationPayment = (string)($challenge['status'] ?? 'active') !== 'active';

    mobileApiJson(200, 'success', 'Login successful.', [
        'user' => mobileApiFormatUser($db, $challenge),
        'access' => $token,
        'requires_registration_payment' => $requiresRegistrationPayment,
        'registration_payment' => $requiresRegistrationPayment ? mobileApiGetRegistrationPaymentData($db, (int)$challenge['user_id']) : null,
    ]);
} catch (RuntimeException $e) {
    mobileApiJson(400, 'error', $e->getMessage());
} catch (Throwable $e) {
    error_log('Mobile API 2FA verification failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to verify the login code right now.');
}