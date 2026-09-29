<?php

require_once __DIR__ . '/_bootstrap.php';

mobileApiJson(200, 'success', 'Water Billing System mobile API is available.', [
    'version' => 'v1',
    'documentation' => mobileApiBuildAbsoluteUrl('/docs/mobile-api.md'),
    'authentication' => 'Bearer token',
    'endpoints' => [
        'POST /api/mobile/login.php',
        'POST /api/mobile/verify_2fa.php',
        'POST /api/mobile/resend_2fa.php',
        'POST /api/mobile/logout.php',
        'GET /api/mobile/me.php',
        'GET /api/mobile/dashboard.php',
        'GET /api/mobile/meters.php',
        'GET /api/mobile/bills.php',
        'GET /api/mobile/bill.php?id={bill_id}',
        'GET /api/mobile/payments.php',
        'POST /api/mobile/initiate_payment.php',
        'GET /api/mobile/meter_readings.php',
        'POST /api/mobile/submit_reading.php',
        'GET /api/mobile/admin/search_clients.php',
        'GET /api/mobile/admin/collections.php',
        'GET /api/mobile/admin/onboarding_tracker.php',
    ],
    'openapi' => mobileApiBuildAbsoluteUrl('/docs/mobile-api-openapi.json'),
]);