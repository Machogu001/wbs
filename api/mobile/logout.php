<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('POST');
    $db = mobileApiGetDatabase();
    $token = mobileApiGetBearerToken();
    if ($token === null) {
        mobileApiJson(401, 'error', 'Authorization bearer token is required.');
    }

    $authService = mobileApiGetAuthService($db);
    $authService->revokeAccessToken($token);
    mobileApiJson(200, 'success', 'Logged out successfully.');
} catch (Throwable $e) {
    error_log('Mobile API logout failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to log out right now.');
}