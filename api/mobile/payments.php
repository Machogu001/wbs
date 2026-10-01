<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);

    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $limit;
    $status = trim((string)($_GET['status'] ?? ''));
    $where = 'WHERE p.user_id = :user_id';
    if ($status !== '') {
        $where .= ' AND p.status = :status';
    }

    $stmt = $db->prepare('SELECT p.*, b.account_number, b.billing_month
        FROM payments p
        LEFT JOIN bills b ON b.id = p.bill_id
        ' . $where . '
        ORDER BY COALESCE(p.transaction_date, p.created_at) DESC, p.id DESC
        LIMIT :limit OFFSET :offset');
    $stmt->bindValue(':user_id', (int)$user['id'], PDO::PARAM_INT);
    if ($status !== '') {
        $stmt->bindValue(':status', $status);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $countStmt = $db->prepare('SELECT COUNT(*) FROM payments p ' . $where);
    $countParams = [':user_id' => (int)$user['id']];
    if ($status !== '') {
        $countParams[':status'] = $status;
    }
    $countStmt->execute($countParams);

    mobileApiJson(200, 'success', 'Payments loaded.', [
        'page' => $page,
        'limit' => $limit,
        'total' => (int)$countStmt->fetchColumn(),
        'payments' => array_map(static function (array $row): array {
            $payment = mobileApiFormatPayment($row);
            if (!empty($row['bill_id']) && (string)($row['status'] ?? '') === 'completed') {
                $payment['receipt_pdf_url'] = mobileApiDocumentUrl('receipt', (int)$row['bill_id'], (int)$row['id']);
                $payment['receipt_url'] = $payment['receipt_pdf_url'];
            }
            return $payment;
        }, $rows),
    ]);
} catch (Throwable $e) {
    error_log('Mobile API payments failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load payments right now.');
}