<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);

    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    $stmt = $db->prepare('SELECT p.*, b.account_number, b.billing_month
        FROM payments p
        LEFT JOIN bills b ON b.id = p.bill_id
        WHERE p.user_id = :user_id
        ORDER BY p.created_at DESC, p.id DESC
        LIMIT :limit OFFSET :offset');
    $stmt->bindValue(':user_id', (int)$user['id'], PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $countStmt = $db->prepare('SELECT COUNT(*) FROM payments WHERE user_id = :user_id');
    $countStmt->execute([':user_id' => (int)$user['id']]);

    mobileApiJson(200, 'success', 'Payments loaded.', [
        'page' => $page,
        'limit' => $limit,
        'total' => (int)$countStmt->fetchColumn(),
        'payments' => array_map('mobileApiFormatPayment', $rows),
    ]);
} catch (Throwable $e) {
    error_log('Mobile API payments failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load payments right now.');
}