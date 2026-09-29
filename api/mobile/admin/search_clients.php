<?php

require_once __DIR__ . '/../_bootstrap.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $user, ['view_invoicing', 'correct_bills', 'view_payments', 'view_customers']);

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') {
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'data' => [],
            'clients' => [],
            'results' => [],
            'suggestions' => [],
        ]);
        exit;
    }

    $userService = new User($db);
    $results = $userService->searchByNameOrAccount($q, 10);
    $clients = array_map(static function (array $row) use ($q): array {
        return mobileApiFormatClientSearchResult($row, $q);
    }, $results);

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'data' => $clients,
        'clients' => $clients,
        'results' => $clients,
        'suggestions' => $clients,
    ]);
    exit;
} catch (Throwable $e) {
    error_log('Mobile API admin search clients failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to search clients right now.');
}