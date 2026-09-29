<?php

require_once __DIR__ . '/../_bootstrap.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $actor = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $actor, ['view_customers']);
    $selectedUserId = (int)($_GET['user_id'] ?? 0);
    $stmt = $db->query('SELECT id, account_number, full_name, phone_number, address, location_label, latitude, longitude, status FROM users WHERE latitude IS NOT NULL AND longitude IS NOT NULL');
    $pins = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    mobileApiJson(200, 'success', 'Customer locations loaded.', ['selected_user_id' => $selectedUserId, 'pins' => $pins]);
} catch (Throwable $e) {
    error_log('Mobile API admin customer locations failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load customer locations right now.');
}