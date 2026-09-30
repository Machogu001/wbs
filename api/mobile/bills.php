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
    $formattedBills = array_map(static function (array $billRow) use ($billService, $paymentService): array {
        return mobileApiFormatBill($billService, $paymentService, $billRow);
    }, $rows);

    mobileApiJson(200, 'success', 'Bills loaded.', [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'bills' => $formattedBills,
        'screen' => [
            'title' => 'Bills',
            'layout' => 'filterable_list',
            'primary_action' => [
                'type' => 'navigate',
                'label' => 'Dashboard',
                'target' => '/api/mobile/dashboard.php',
            ],
            'filters' => [
                [
                    'key' => 'status',
                    'label' => 'Bill Status',
                    'input_type' => 'dropdown',
                    'default_value' => $statusFilter,
                    'options' => [
                        ['value' => '', 'label' => 'All Bills'],
                        ['value' => 'pending', 'label' => 'Pending'],
                        ['value' => 'paid', 'label' => 'Paid'],
                        ['value' => 'overdue', 'label' => 'Overdue'],
                        ['value' => 'cancelled', 'label' => 'Cancelled'],
                    ],
                ],
                [
                    'key' => 'limit',
                    'label' => 'Rows',
                    'input_type' => 'dropdown',
                    'default_value' => $limit,
                    'options' => [
                        ['value' => 10, 'label' => '10'],
                        ['value' => 20, 'label' => '20'],
                        ['value' => 50, 'label' => '50'],
                        ['value' => 100, 'label' => '100'],
                    ],
                ],
            ],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'has_next_page' => ($page * $limit) < $total,
                'has_previous_page' => $page > 1,
            ],
            'sections' => [
                [
                    'key' => 'bills',
                    'title' => 'Bill List',
                    'presentation' => 'list',
                    'empty_state' => 'No bills matched the selected filters.',
                    'item_actions' => [
                        [
                            'type' => 'navigate',
                            'label' => 'Open Bill',
                            'target_template' => '/api/mobile/bill.php?id={id}',
                        ],
                        [
                            'type' => 'link',
                            'label' => 'Payment Link',
                            'field' => 'public_payment_url',
                        ],
                    ],
                ],
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log('Mobile API bills failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load bills right now.');
}