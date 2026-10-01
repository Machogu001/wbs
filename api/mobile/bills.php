<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    $billService = new Bill($db);
    $paymentService = new Payment($db);

    $statusFilter = trim((string)($_GET['status'] ?? ''));
    $legacyFilter = trim((string)($_GET['filter'] ?? ''));
    if ($statusFilter === '' && in_array($legacyFilter, ['paid', 'unpaid'], true)) {
        $statusFilter = $legacyFilter;
    }
    $fromPeriod = trim((string)($_GET['from'] ?? ''));
    $toPeriod = trim((string)($_GET['to'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $fromPeriod)) {
        $fromPeriod = '';
    }
    if (!preg_match('/^\d{4}-\d{2}$/', $toPeriod)) {
        $toPeriod = '';
    }
    $fromDate = $fromPeriod !== '' ? $fromPeriod . '-01' : '';
    $toDate = $toPeriod !== '' ? date('Y-m-t', strtotime($toPeriod . '-01')) : '';
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    $sql = 'SELECT * FROM bills WHERE user_id = :user_id';
    $params = [':user_id' => (int)$user['id']];
    if ($statusFilter === 'unpaid') {
        $sql .= " AND status != 'paid'";
    } elseif ($statusFilter !== '') {
        $sql .= ' AND status = :status';
        $params[':status'] = $statusFilter;
    }
    if ($fromDate !== '') {
        $sql .= ' AND DATE(billing_month) >= :from_date';
        $params[':from_date'] = $fromDate;
    }
    if ($toDate !== '') {
        $sql .= ' AND DATE(billing_month) <= :to_date';
        $params[':to_date'] = $toDate;
    }
    $sql .= ' ORDER BY billing_month DESC, id DESC LIMIT :limit OFFSET :offset';

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':user_id', (int)$user['id'], PDO::PARAM_INT);
    if ($statusFilter !== '' && $statusFilter !== 'unpaid') {
        $stmt->bindValue(':status', $statusFilter);
    }
    if ($fromDate !== '') {
        $stmt->bindValue(':from_date', $fromDate);
    }
    if ($toDate !== '') {
        $stmt->bindValue(':to_date', $toDate);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $countSql = 'SELECT COUNT(*) FROM bills WHERE user_id = :user_id';
    if ($statusFilter === 'unpaid') {
        $countSql .= " AND status != 'paid'";
    } elseif ($statusFilter !== '') {
        $countSql .= ' AND status = :status';
    }
    if ($fromDate !== '') {
        $countSql .= ' AND DATE(billing_month) >= :from_date';
    }
    if ($toDate !== '') {
        $countSql .= ' AND DATE(billing_month) <= :to_date';
    }
    $countStmt = $db->prepare($countSql);
    $countStmt->bindValue(':user_id', (int)$user['id'], PDO::PARAM_INT);
    if ($statusFilter !== '' && $statusFilter !== 'unpaid') {
        $countStmt->bindValue(':status', $statusFilter);
    }
    if ($fromDate !== '') {
        $countStmt->bindValue(':from_date', $fromDate);
    }
    if ($toDate !== '') {
        $countStmt->bindValue(':to_date', $toDate);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();
    $formattedBills = array_map(static function (array $billRow) use ($billService, $paymentService): array {
        return mobileApiFormatBill($billService, $paymentService, $billRow);
    }, $rows);
    $summary = [
        'paid_amount' => 0.0,
        'unpaid_amount' => 0.0,
        'bill_count' => $total,
        'overdue_count' => 0,
    ];
    // Totals cover every bill matching the filters (not just the current page), like the website KPIs.
    $summarySql = "SELECT b.status, b.amount, GREATEST(0, b.amount - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.bill_id = b.id AND p.status = 'completed'), 0)) AS outstanding
        FROM bills b WHERE b.user_id = :user_id"
        . str_replace([' status', ' DATE(billing_month)'], [' b.status', ' DATE(b.billing_month)'], substr($countSql, strlen('SELECT COUNT(*) FROM bills WHERE user_id = :user_id')));
    $summaryStmt = $db->prepare($summarySql);
    $summaryStmt->bindValue(':user_id', (int)$user['id'], PDO::PARAM_INT);
    if ($statusFilter !== '' && $statusFilter !== 'unpaid') {
        $summaryStmt->bindValue(':status', $statusFilter);
    }
    if ($fromDate !== '') {
        $summaryStmt->bindValue(':from_date', $fromDate);
    }
    if ($toDate !== '') {
        $summaryStmt->bindValue(':to_date', $toDate);
    }
    $summaryStmt->execute();
    foreach ($summaryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $bill) {
        $status = (string)($bill['status'] ?? '');
        if ($status === 'paid') {
            $summary['paid_amount'] += (float)$bill['amount'];
        } elseif ($status !== 'cancelled') {
            $summary['unpaid_amount'] += (float)$bill['outstanding'];
        }
        if ($status === 'overdue') {
            $summary['overdue_count']++;
        }
    }
    $summary['paid_amount'] = round($summary['paid_amount'], 2);
    $summary['unpaid_amount'] = round($summary['unpaid_amount'], 2);

    mobileApiJson(200, 'success', 'Bills loaded.', [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'summary' => $summary,
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
                        ['value' => 'unpaid', 'label' => 'Unpaid'],
                        ['value' => 'pending', 'label' => 'Pending'],
                        ['value' => 'paid', 'label' => 'Paid'],
                        ['value' => 'overdue', 'label' => 'Overdue'],
                        ['value' => 'cancelled', 'label' => 'Cancelled'],
                    ],
                    [
                        'key' => 'from',
                        'label' => 'From month',
                        'input_type' => 'month',
                        'default_value' => $fromPeriod,
                    ],
                    [
                        'key' => 'to',
                        'label' => 'To month',
                        'input_type' => 'month',
                        'default_value' => $toPeriod,
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