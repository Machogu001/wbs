<?php

require_once __DIR__ . '/../_bootstrap.php';

function mobileApiOnboardingStatusKey(array $row): string
{
    return (string)($row['overall_key'] ?? '');
}

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $user, ['manage_registration_proformas', 'view_customers']);

    new ClientMeter($db);
    $paymentService = new Payment($db);
    $billService = new Bill($db);

    $typeFilter = strtolower(trim((string)($_GET['type'] ?? 'all')));
    if (!in_array($typeFilter, ['all', 'proforma', 'meter'], true)) {
        $typeFilter = 'all';
    }

    $statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
    if (!in_array($statusFilter, ['all', 'attention', 'pending_payment', 'pending_setup', 'complete'], true)) {
        $statusFilter = 'all';
    }

    $searchTerm = trim((string)($_GET['q'] ?? ''));
    $searchLike = '%' . $searchTerm . '%';
    $dateFrom = trim((string)($_GET['from'] ?? ''));
    $dateTo = trim((string)($_GET['to'] ?? ''));
    $assigneeFilter = trim((string)($_GET['assignee'] ?? 'all'));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $page = max(1, (int)($_GET['page'] ?? 1));

    $dateFromSql = '';
    if ($dateFrom !== '') {
        $parsedFrom = strtotime($dateFrom . ' 00:00:00');
        if ($parsedFrom !== false) {
            $dateFromSql = date('Y-m-d H:i:s', $parsedFrom);
        }
    }

    $dateToSql = '';
    if ($dateTo !== '') {
        $parsedTo = strtotime($dateTo . ' 23:59:59');
        if ($parsedTo !== false) {
            $dateToSql = date('Y-m-d H:i:s', $parsedTo);
        }
    }

    $basePaidSql = "
        SELECT bill_id,
               SUM(amount) AS total_paid,
               MAX(COALESCE(transaction_date, created_at)) AS last_paid_at
        FROM payments
        WHERE status = 'completed' AND bill_id IS NOT NULL
        GROUP BY bill_id
    ";

    $trackerRows = [];
    $summary = [
        'proforma_pending_payment' => 0,
        'proforma_pending_setup' => 0,
        'meter_pending_payment' => 0,
        'completed' => 0,
        'attention' => 0,
    ];

    if ($typeFilter !== 'meter') {
        $sql = "
            SELECT rp.id, rp.user_id, rp.bill_id, rp.account_setup_token, rp.account_setup_expires_at,
                   rp.account_setup_completed_at, rp.account_setup_sent_at, rp.created_at, rp.created_by_user_id,
                   u.account_number, u.full_name, u.phone_number, u.email, u.status AS user_status, u.connection_type,
                   creator.full_name AS created_by_name,
                   b.amount AS bill_amount, b.status AS bill_status, b.due_date,
                   COALESCE(pay.total_paid, 0) AS total_paid,
                   GREATEST(0, b.amount - COALESCE(pay.total_paid, 0)) AS outstanding_amount,
                   pay.last_paid_at
            FROM registration_proformas rp
            INNER JOIN users u ON u.id = rp.user_id
            INNER JOIN bills b ON b.id = rp.bill_id
            LEFT JOIN users creator ON creator.id = rp.created_by_user_id
            LEFT JOIN ({$basePaidSql}) pay ON pay.bill_id = b.id
        ";
        $params = [];
        $where = [];
        if ($searchTerm !== '') {
            $where[] = "(u.full_name LIKE :search OR u.account_number LIKE :search OR u.phone_number LIKE :search OR u.email LIKE :search)";
            $params[':search'] = $searchLike;
        }
        if ($dateFromSql !== '') {
            $where[] = 'rp.created_at >= :date_from';
            $params[':date_from'] = $dateFromSql;
        }
        if ($dateToSql !== '') {
            $where[] = 'rp.created_at <= :date_to';
            $params[':date_to'] = $dateToSql;
        }
        if ($assigneeFilter === 'unassigned') {
            $where[] = 'rp.created_by_user_id IS NULL';
        } elseif (ctype_digit($assigneeFilter) && (int)$assigneeFilter > 0) {
            $where[] = 'rp.created_by_user_id = :assignee_id';
            $params[':assignee_id'] = (int)$assigneeFilter;
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY rp.created_at DESC, rp.id DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $outstandingAmount = (float)($row['outstanding_amount'] ?? 0);
            $userStatus = strtolower((string)($row['user_status'] ?? 'inactive'));
            $hasCompletedSetup = !empty($row['account_setup_completed_at']);
            $hasSetupToken = !empty($row['account_setup_token']);
            $expiresAt = !empty($row['account_setup_expires_at']) ? strtotime((string)$row['account_setup_expires_at']) : false;
            $tokenExpired = $hasSetupToken && $expiresAt !== false && $expiresAt < time();
            $tokenActive = $hasSetupToken && $expiresAt !== false && $expiresAt >= time();
            $overallKey = 'action_needed';
            $overallLabel = 'Setup Link Missing';

            if ($hasCompletedSetup || $userStatus === 'active') {
                $overallKey = 'complete';
                $overallLabel = 'Customer Activated';
                $summary['completed']++;
            } elseif ($outstandingAmount > 0.01) {
                $overallKey = 'pending_payment';
                $overallLabel = 'Awaiting Fee Payment';
                $summary['proforma_pending_payment']++;
            } elseif ($tokenExpired) {
                $overallKey = 'action_needed';
                $overallLabel = 'Setup Link Expired';
                $summary['attention']++;
            } elseif ($tokenActive || !empty($row['account_setup_sent_at'])) {
                $overallKey = 'pending_setup';
                $overallLabel = 'Waiting for Password Setup';
                $summary['proforma_pending_setup']++;
            } else {
                $summary['attention']++;
            }

            $trackerRows[] = [
                'type' => 'proforma',
                'record_id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'account_number' => (string)$row['account_number'],
                'full_name' => (string)$row['full_name'],
                'phone_number' => (string)$row['phone_number'],
                'email' => (string)$row['email'],
                'item_label' => strtoupper((string)($row['connection_type'] ?? 'domestic')),
                'item_meta' => 'Account ' . (string)$row['account_number'],
                'assignee_name' => !empty($row['created_by_name']) ? (string)$row['created_by_name'] : 'Unassigned',
                'created_at' => (string)$row['created_at'],
                'bill_id' => (int)$row['bill_id'],
                'bill_amount' => (float)$row['bill_amount'],
                'outstanding_amount' => $outstandingAmount,
                'last_paid_at' => (string)($row['last_paid_at'] ?? ''),
                'due_date' => (string)($row['due_date'] ?? ''),
                'overall_key' => $overallKey,
                'overall_label' => $overallLabel,
                'open_url' => PaymentLink::generateRegistrationProformaLink((int)$row['bill_id']),
                'document_url' => mobileApiDocumentUrl($outstandingAmount > 0.01 ? 'proforma' : 'invoice', (int)$row['bill_id']),
                'payments_url' => '/admin/payments?account=' . urlencode((string)$row['account_number']),
                'customer_url' => '/admin/users?edit_id=' . (int)$row['user_id'],
            ];
        }
    }

    if ($typeFilter !== 'proforma') {
        $sql = "
            SELECT um.id, um.user_id, um.meter_number, um.meter_label, um.status AS meter_status,
                   um.registration_bill_id, um.created_by_user_id, um.created_at,
                   u.account_number, u.full_name, u.phone_number, u.email,
                   creator.full_name AS created_by_name,
                   b.amount AS bill_amount, b.due_date,
                   COALESCE(pay.total_paid, 0) AS total_paid,
                   GREATEST(0, COALESCE(b.amount, 0) - COALESCE(pay.total_paid, 0)) AS outstanding_amount,
                   pay.last_paid_at
            FROM user_meters um
            INNER JOIN users u ON u.id = um.user_id
            LEFT JOIN users creator ON creator.id = um.created_by_user_id
            LEFT JOIN bills b ON b.id = um.registration_bill_id
            LEFT JOIN ({$basePaidSql}) pay ON pay.bill_id = b.id
            WHERE um.is_primary = 0
        ";
        $params = [];
        $where = [];
        if ($searchTerm !== '') {
            $where[] = "(u.full_name LIKE :search OR u.account_number LIKE :search OR u.phone_number LIKE :search OR u.email LIKE :search OR um.meter_number LIKE :search OR COALESCE(um.meter_label, '') LIKE :search)";
            $params[':search'] = $searchLike;
        }
        if ($dateFromSql !== '') {
            $where[] = 'um.created_at >= :date_from';
            $params[':date_from'] = $dateFromSql;
        }
        if ($dateToSql !== '') {
            $where[] = 'um.created_at <= :date_to';
            $params[':date_to'] = $dateToSql;
        }
        if ($assigneeFilter === 'unassigned') {
            $where[] = 'um.created_by_user_id IS NULL';
        } elseif (ctype_digit($assigneeFilter) && (int)$assigneeFilter > 0) {
            $where[] = 'um.created_by_user_id = :assignee_id';
            $params[':assignee_id'] = (int)$assigneeFilter;
        }
        if (!empty($where)) {
            $sql .= ' AND ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY um.created_at DESC, um.id DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $registrationBillId = (int)($row['registration_bill_id'] ?? 0);
            $billAmount = (float)($row['bill_amount'] ?? 0);
            $outstandingAmount = (float)($row['outstanding_amount'] ?? 0);
            $meterStatus = strtolower((string)($row['meter_status'] ?? 'active'));
            $requiresFee = $registrationBillId > 0 && $billAmount > 0;

            if ($meterStatus !== 'active') {
                $overallKey = 'action_needed';
                $overallLabel = 'Meter Inactive';
                $summary['attention']++;
            } elseif (!$requiresFee) {
                $overallKey = 'complete';
                $overallLabel = 'Meter Linked';
                $summary['completed']++;
            } elseif ($outstandingAmount > 0.01) {
                $overallKey = 'pending_payment';
                $overallLabel = 'Awaiting Meter Fee';
                $summary['meter_pending_payment']++;
            } else {
                $overallKey = 'complete';
                $overallLabel = 'Meter Ready';
                $summary['completed']++;
            }

            $trackerRows[] = [
                'type' => 'meter',
                'record_id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'account_number' => (string)$row['account_number'],
                'full_name' => (string)$row['full_name'],
                'phone_number' => (string)$row['phone_number'],
                'email' => (string)$row['email'],
                'item_label' => (string)$row['meter_number'],
                'item_meta' => trim((string)($row['meter_label'] ?? '')) !== '' ? (string)$row['meter_label'] : 'No meter label',
                'assignee_name' => !empty($row['created_by_name']) ? (string)$row['created_by_name'] : 'Unassigned',
                'created_at' => (string)$row['created_at'],
                'bill_id' => $registrationBillId,
                'bill_amount' => $billAmount,
                'outstanding_amount' => $outstandingAmount,
                'last_paid_at' => (string)($row['last_paid_at'] ?? ''),
                'due_date' => (string)($row['due_date'] ?? ''),
                'overall_key' => $overallKey,
                'overall_label' => $overallLabel,
                'open_url' => $registrationBillId > 0 ? PaymentLink::generateLink($registrationBillId) : '',
                'document_url' => mobileApiDocumentUrl('invoice', $registrationBillId),
                'payments_url' => '/admin/payments?account=' . urlencode((string)$row['account_number']),
                'customer_url' => '/admin/users?edit_id=' . (int)$row['user_id'],
            ];
        }
    }

    if ($statusFilter !== 'all') {
        $trackerRows = array_values(array_filter($trackerRows, static function (array $row) use ($statusFilter): bool {
            $statusKey = mobileApiOnboardingStatusKey($row);
            if ($statusFilter === 'attention') {
                return in_array($statusKey, ['pending_payment', 'pending_setup', 'action_needed'], true);
            }
            return $statusKey === $statusFilter;
        }));
    }

    usort($trackerRows, static function (array $left, array $right): int {
        $order = ['action_needed' => 0, 'pending_payment' => 1, 'pending_setup' => 2, 'complete' => 3];
        $leftRank = $order[$left['overall_key'] ?? 'complete'] ?? 99;
        $rightRank = $order[$right['overall_key'] ?? 'complete'] ?? 99;
        if ($leftRank !== $rightRank) {
            return $leftRank <=> $rightRank;
        }
        return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
    });

    $total = count($trackerRows);
    $rows = array_slice($trackerRows, ($page - 1) * $limit, $limit);

    $assigneeSummary = [];
    foreach ($trackerRows as $row) {
        $assigneeName = (string)($row['assignee_name'] ?? 'Unassigned');
        if (!isset($assigneeSummary[$assigneeName])) {
            $assigneeSummary[$assigneeName] = [
                'name' => $assigneeName,
                'total' => 0,
                'pending' => 0,
                'completed' => 0,
            ];
        }
        $assigneeSummary[$assigneeName]['total']++;
        if (($row['overall_key'] ?? '') === 'complete') {
            $assigneeSummary[$assigneeName]['completed']++;
        } else {
            $assigneeSummary[$assigneeName]['pending']++;
        }
    }

    mobileApiJson(200, 'success', 'Onboarding tracker loaded.', [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'summary' => $summary,
        'assignee_summary' => array_values($assigneeSummary),
        'records' => array_map(static function (array $row) use ($paymentService, $billService): array {
            return [
                'type' => (string)$row['type'],
                'record_id' => (int)$row['record_id'],
                'user_id' => (int)$row['user_id'],
                'account_number' => (string)$row['account_number'],
                'full_name' => (string)$row['full_name'],
                'phone_number' => (string)$row['phone_number'],
                'email' => (string)$row['email'],
                'item_label' => (string)$row['item_label'],
                'item_meta' => (string)$row['item_meta'],
                'assignee_name' => (string)$row['assignee_name'],
                'created_at' => (string)$row['created_at'],
                'bill_id' => (int)$row['bill_id'],
                'bill_amount' => (float)$row['bill_amount'],
                'outstanding_amount' => (float)$row['outstanding_amount'],
                'last_paid_at' => (string)$row['last_paid_at'],
                'due_date' => (string)$row['due_date'],
                'overall_key' => (string)$row['overall_key'],
                'overall_label' => (string)$row['overall_label'],
                'open_url' => mobileApiBuildAbsoluteUrl((string)$row['open_url']),
                'document_url' => mobileApiBuildAbsoluteUrl((string)$row['document_url']),
                'payments_url' => mobileApiBuildAbsoluteUrl((string)$row['payments_url']),
                'customer_url' => mobileApiBuildAbsoluteUrl((string)$row['customer_url']),
            ];
        }, $rows),
    ]);
} catch (Throwable $e) {
    error_log('Mobile API onboarding tracker failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load onboarding tracker right now.');
}