<?php

require_once __DIR__ . '/../_bootstrap.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $user, ['view_customers']);

    $q = trim((string)($_GET['q'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));

    $userService = new User($db);
    $rows = $q !== '' ? $userService->searchByNameOrAccount($q, 100) : $userService->listAll();
    if ($status !== '') {
        $rows = array_values(array_filter($rows, static function (array $row) use ($status): bool {
            return (string)($row['status'] ?? '') === $status;
        }));
    }

    $total = count($rows);
    $offset = ($page - 1) * $limit;
    $pagedRows = array_slice($rows, $offset, $limit);
    $clients = array_map(static function (array $row) use ($q): array {
        return mobileApiFormatClientSearchResult($row, $q) + [
            'phone_number' => (string)($row['phone_number'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
        ];
    }, $pagedRows);

    mobileApiJson(200, 'success', 'Customers loaded.', [
        'customers' => $clients,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'has_more' => ($offset + count($pagedRows)) < $total,
        ],
    ]);
} catch (Throwable $e) {
    error_log('Mobile API admin customers failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load customers right now.');
}