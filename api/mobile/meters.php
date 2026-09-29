<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    $meterService = new ClientMeter($db);

    mobileApiJson(200, 'success', 'Meters loaded.', [
        'meters' => array_map('mobileApiFormatMeter', $meterService->listByUserId((int)$user['id'])),
    ]);
} catch (Throwable $e) {
    error_log('Mobile API meters failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load meters right now.');
}