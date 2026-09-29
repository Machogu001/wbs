<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);

    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $meterNumber = strtoupper(trim((string)($_GET['meter_number'] ?? '')));

    $sql = 'SELECT id, user_id, account_number, meter_number, current_reading, billing_month, due_date, photo_path, status, created_at, approved_at, bill_id
        FROM meter_readings
        WHERE user_id = :user_id';
    $params = [':user_id' => (int)$user['id']];
    if ($meterNumber !== '') {
        $sql .= ' AND meter_number = :meter_number';
        $params[':meter_number'] = $meterNumber;
    }
    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT :limit';

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':user_id', (int)$user['id'], PDO::PARAM_INT);
    if ($meterNumber !== '') {
        $stmt->bindValue(':meter_number', $meterNumber);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    mobileApiJson(200, 'success', 'Meter readings loaded.', [
        'meter_readings' => array_map(static function (array $row): array {
            return [
                'id' => (int)($row['id'] ?? 0),
                'meter_number' => (string)($row['meter_number'] ?? ''),
                'current_reading' => isset($row['current_reading']) ? (float)$row['current_reading'] : 0.0,
                'billing_month' => (string)($row['billing_month'] ?? ''),
                'due_date' => (string)($row['due_date'] ?? ''),
                'status' => (string)($row['status'] ?? 'pending'),
                'photo_url' => !empty($row['photo_path']) ? mobileApiBuildAbsoluteUrl((string)$row['photo_path']) : '',
                'bill_id' => !empty($row['bill_id']) ? (int)$row['bill_id'] : null,
                'created_at' => (string)($row['created_at'] ?? ''),
                'approved_at' => (string)($row['approved_at'] ?? ''),
            ];
        }, $rows),
    ]);
} catch (Throwable $e) {
    error_log('Mobile API meter readings failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load meter readings right now.');
}