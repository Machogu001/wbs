<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    $billService = new Bill($db);
    $paymentService = new Payment($db);

    $statusFilter = trim((string)($_GET['status'] ?? ''));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    $sql = 'SELECT * FROM bills WHERE user_id = :user_id';
    $params = [':user_id' => (int)$user['id']];
    if ($statusFilter !== '') {
        $sql .= ' AND status = :status';
        $params[':status'] = $statusFilter;
    }
    $sql .= ' ORDER BY billing_month DESC, id DESC LIMIT :limit OFFSET :offset';

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':user_id', (int)$user['id'], PDO::PARAM_INT);
    if ($statusFilter !== '') {
        $stmt->bindValue(':status', $statusFilter);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $countSql = 'SELECT COUNT(*) FROM bills WHERE user_id = :user_id';
    if ($statusFilter !== '') {
        $countSql .= ' AND status = :status';
    }
    $countStmt = $db->prepare($countSql);
    $countStmt->bindValue(':user_id', (int)$user['id'], PDO::PARAM_INT);
    if ($statusFilter !== '') {
        $countStmt->bindValue(':status', $statusFilter);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    mobileApiJson(200, 'success', 'Bills loaded.', [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'bills' => array_map(static function (array $billRow) use ($billService, $paymentService): array {
            return mobileApiFormatBill($billService, $paymentService, $billRow);
        }, $rows),
    ]);
} catch (Throwable $e) {
    error_log('Mobile API bills failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load bills right now.');
}