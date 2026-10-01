<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('POST');
    $db = mobileApiGetDatabase();
    $payload = mobileApiReadJson();

    $identifier = trim((string)($payload['identifier'] ?? ''));
    $password = (string)($payload['password'] ?? '');
    $deviceName = trim((string)($payload['device_name'] ?? ''));

    if ($identifier === '' || $password === '') {
        mobileApiJson(422, 'error', 'Identifier and password are required.');
    }

    $clientIp = mobileApiGetClientIp();
    mobileApiEnsureLoginAttemptsTable($db);
    if (mobileApiIsRateLimited($db, $identifier, $clientIp)) {
        mobileApiJson(429, 'error', 'Too many login attempts. Please try again after a few minutes.');
    }

    $userModel = new User($db);
    $authService = mobileApiGetAuthService($db);
    $authRow = $userModel->getAuthRowByIdentifier($identifier);

    if (!$authRow || !password_verify($password, (string)($authRow['password_hash'] ?? ''))) {
        mobileApiRecordFailedLogin($db, $identifier, $clientIp);
        if ($authRow) {
            mobileApiLogActivity($db, (int)$authRow['id'], 'login_failed', 'user', (int)$authRow['id'], 'Failed login attempt from the mobile app (wrong password)', ['identifier' => $identifier]);
        }
        mobileApiJson(401, 'error', 'Invalid account, phone/email or password.');
    }

    mobileApiClearFailedLogins($db, $identifier, $clientIp);

    $twoFactorEnabled = !empty($authRow['two_factor_enabled']);
    if ($twoFactorEnabled) {
        $challenge = $authService->startTwoFactorChallenge($authRow, $identifier, $clientIp);
        mobileApiLogActivity($db, (int)$authRow['id'], 'two_factor_challenge', 'user', (int)$authRow['id'], 'Two-step verification code sent for mobile app login', ['identifier' => $identifier, 'device_name' => $deviceName]);
        mobileApiJson(200, 'two_factor_required', 'Verification code sent.', $challenge + [
            'device_name' => $deviceName,
        ]);
    }

    unset($authRow['password_hash']);
    $token = $authService->issueAccessToken((int)$authRow['id'], $deviceName !== '' ? $deviceName : null);
    $requiresRegistrationPayment = (string)($authRow['status'] ?? 'active') !== 'active';
    mobileApiLogActivity($db, (int)$authRow['id'], 'login', 'user', (int)$authRow['id'],
        $requiresRegistrationPayment ? 'User logged in via mobile app (registration payment required)' : 'User logged in via mobile app',
        ['identifier' => $identifier, 'device_name' => $deviceName, 'status' => (string)($authRow['status'] ?? 'active')]);

    mobileApiJson(200, 'success', 'Login successful.', [
        'user' => mobileApiFormatUser($db, $authRow),
        'access' => $token,
        'requires_registration_payment' => $requiresRegistrationPayment,
        'registration_payment' => $requiresRegistrationPayment ? mobileApiGetRegistrationPaymentData($db, (int)$authRow['id']) : null,
    ]);
} catch (InvalidArgumentException $e) {
    mobileApiJson(422, 'error', $e->getMessage());
} catch (RuntimeException $e) {
    mobileApiJson(400, 'error', $e->getMessage());
} catch (Throwable $e) {
    error_log('Mobile API login failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to complete login right now.');
}