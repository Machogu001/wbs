<?php

require_once __DIR__ . '/../_bootstrap.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $user, ['view_invoicing', 'correct_bills', 'view_payments', 'view_customers']);

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') {
        mobileApiJson(200, 'success', 'Clients loaded.', ['clients' => []]);
    }

    $userService = new User($db);
    $results = $userService->searchByNameOrAccount($q, 10);
    $clients = array_map(static function (array $row) use ($q): array {
        return mobileApiFormatClientSearchResult($row, $q);
    }, $results);

    mobileApiJson(200, 'success', 'Clients loaded.', ['clients' => $clients]);
} catch (Throwable $e) {
    error_log('Mobile API admin search clients failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to search clients right now.');
}