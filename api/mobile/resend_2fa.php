<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('POST');
    $db = mobileApiGetDatabase();
    $payload = mobileApiReadJson();

    $challengeToken = trim((string)($payload['challenge_token'] ?? ''));
    $method = trim((string)($payload['method'] ?? ''));

    if ($challengeToken === '') {
        mobileApiJson(422, 'error', 'Challenge token is required.');
    }

    $authService = mobileApiGetAuthService($db);
    $challenge = $authService->resendTwoFactorChallenge($challengeToken, $method !== '' ? $method : null);
    mobileApiJson(200, 'success', 'Verification code sent.', $challenge);
} catch (RuntimeException $e) {
    mobileApiJson(400, 'error', $e->getMessage());
} catch (Throwable $e) {
    error_log('Mobile API 2FA resend failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to resend the verification code right now.');
}