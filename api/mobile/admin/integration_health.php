<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/IntegrationHealth.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    if (!mobileApiUserHasRole($user, 'admin')) {
        mobileApiJson(403, 'error', 'Forbidden.');
    }

    $health = new IntegrationHealth($db);
    mobileApiJson(200, 'success', 'Integration health loaded.', [
        'summary' => $health->getLiveSummary(),
    ]);
} catch (Throwable $e) {
    error_log('Mobile API admin integration health failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load integration health right now.');
}