<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);

    mobileApiJson(200, 'success', 'Profile loaded.', [
        'user' => mobileApiFormatUser($db, $user),
        'requires_registration_payment' => (string)($user['status'] ?? 'active') !== 'active',
        'registration_payment' => (string)($user['status'] ?? 'active') !== 'active' ? mobileApiGetRegistrationPaymentData($db, (int)$user['id']) : null,
    ]);
} catch (Throwable $e) {
    error_log('Mobile API profile failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load the profile right now.');
}