<?php

require_once __DIR__ . '/../_bootstrap.php';

function mobileApiOnboardingStatusKey(array $row): string
{
    return (string)($row['overall_key'] ?? '');
}

function mobileApiOnboardingSendStk(PDO $db, Payment $paymentService, string $sourceType, int $sourceId, string $currency): string
{
    if ($sourceType === 'proforma') {
        $stmt = $db->prepare('SELECT rp.id, rp.user_id, rp.bill_id, u.account_number, u.full_name, u.phone_number
            FROM registration_proformas rp
            INNER JOIN users u ON u.id = rp.user_id
            WHERE rp.id = :id LIMIT 1');
    } else {
        $stmt = $db->prepare('SELECT um.id, um.user_id, um.registration_bill_id AS bill_id, u.account_number, u.full_name, u.phone_number
            FROM user_meters um
            INNER JOIN users u ON u.id = um.user_id
            WHERE um.id = :id LIMIT 1');
    }
    $stmt->execute([':id' => $sourceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        throw new Exception($sourceType === 'proforma' ? 'Registration proforma not found.' : 'Additional meter record not found.');
    }

    $billId = (int)($row['bill_id'] ?? 0);
    if ($billId <= 0) {
        throw new Exception('No registration bill is linked to this onboarding item.');
    }

    $billService = new Bill($db);
    $billRow = $billService->getById($billId);
    if (!$billRow || !$billService->isRegistrationFeeBill($billRow)) {
        throw new Exception('Registration fee bill not found for this onboarding item.');
    }

    $amountToCharge = $paymentService->getBillOutstandingAmount($billId);
    if ($amountToCharge <= 0.01) {
        throw new Exception('This onboarding fee is already fully settled.');
    }
    if (!preg_match('/^(?:254|\+254|0)?((?:7|1)\d{8})$/', (string)($row['phone_number'] ?? ''), $matches)) {
        throw new Exception('A valid Kenyan M-Pesa phone number is required to send an STK push.');
    }

    $formattedPhone = '254' . $matches[1];
    $response = (new Mpesa())->stkPush($formattedPhone, $amountToCharge, (string)$row['account_number'], 'Registration Fee');
    if (isset($response['error'])) {
        $details = '';
        if (isset($response['http_code'])) {
            $details .= ' (HTTP ' . $response['http_code'] . ')';
        }
        if (!empty($response['details']['errorMessage'])) {
            $details .= ': ' . (string)$response['details']['errorMessage'];
        }
        throw new Exception('Payment initiation failed: ' . (string)$response['error'] . $details);
    }

    $payment = new Payment($db);
    $payment->bill_id = $billId;
    $payment->user_id = (int)$row['user_id'];
    $payment->phone_number = $formattedPhone;
    $payment->amount = $amountToCharge;
    $payment->merchant_request_id = $response['MerchantRequestID'] ?? null;
    $payment->checkout_request_id = $response['CheckoutRequestID'] ?? null;
    $payment->status = 'pending';
    $payment->registration_id = (int)$row['user_id'];
    if (!$payment->create()) {
        throw new Exception('Failed to save the pending payment request.');
    }

    return 'M-Pesa STK push sent to ' . (string)$row['full_name'] . ' for ' . $currency . ' ' . number_format($amountToCharge, 2) . '.';
}

function mobileApiOnboardingResendSetupLink(PDO $db, Payment $paymentService, int $proformaId, array $settings): string
{
    $stmt = $db->prepare('SELECT rp.id, rp.user_id, rp.bill_id, u.full_name, u.phone_number, u.email
        FROM registration_proformas rp
        INNER JOIN users u ON u.id = rp.user_id
        WHERE rp.id = :id LIMIT 1');
    $stmt->execute([':id' => $proformaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        throw new Exception('Registration proforma not found.');
    }

    if ($paymentService->getBillOutstandingAmount((int)$row['bill_id']) > 0.01) {
        throw new Exception('The registration fee must be fully paid before resending the setup link.');
    }

    $setupData = $paymentService->reissueRegistrationAccountSetupToken((int)$row['user_id'], (int)$row['bill_id']);
    if (!$setupData || empty($setupData['token'])) {
        throw new Exception('Unable to generate a new setup link.');
    }

    $setupLink = mobileApiBuildAbsoluteUrl('/registration-account-setup?token=' . rawurlencode((string)$setupData['token']));
    $companyName = !empty($settings['company_name']) ? (string)$settings['company_name'] : 'Water Billing System';
    $supportPhone = !empty($settings['support_phone']) ? (string)$settings['support_phone'] : '254724400202';
    $messageText = "Dear {$row['full_name']}, your registration fee has been confirmed. Set your portal password here: {$setupLink}\n\nUse the link within 7 days to activate your online access. For assistance contact {$supportPhone}.";

    if (!empty($row['phone_number'])) {
        try {
            (new SMS($db))->sendWithFallback((string)$row['phone_number'], $messageText, 'registration_setup_link');
        } catch (Throwable $e) {
            error_log('Registration setup SMS failed: ' . $e->getMessage());
        }
    }
    if (!empty($row['email'])) {
        try {
            $emailBody = "Hello {$row['full_name']},\n\nYour account setup link for {$companyName} is ready:\n{$setupLink}\n\nThis link expires in 7 days. If you need help, call {$supportPhone}.";
            (new Email())->queue((string)$row['email'], 'Complete your account setup', $emailBody, 'registration_setup_link');
        } catch (Throwable $e) {
            error_log('Registration setup email failed: ' . $e->getMessage());
        }
    }

    return 'Account setup link resent for ' . (string)$row['full_name'] . '.';
}

try {
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $user, ['manage_registration_proformas', 'view_customers']);

    new ClientMeter($db);
    $paymentService = new Payment($db);
    $billService = new Bill($db);
    $settings = (new BillingSettings($db))->getSettings();
    $currency = !empty($settings['currency_code']) ? (string)$settings['currency_code'] : 'KES';
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $action = trim((string)($data['tracker_action'] ?? $data['action'] ?? ''));
        if ($action === 'send_stk') {
            $sourceType = trim((string)($data['source_type'] ?? ''));
            $sourceId = (int)($data['source_id'] ?? $data['record_id'] ?? 0);
            if (!in_array($sourceType, ['proforma', 'meter'], true) || $sourceId <= 0) {
                mobileApiJson(422, 'error', 'Invalid onboarding item selected.');
            }
            $message = mobileApiOnboardingSendStk($db, $paymentService, $sourceType, $sourceId, $currency);
            mobileApiLogActivity($db, (int)($user['id'] ?? 0), 'onboarding_send_stk', $sourceType, $sourceId, $message);
            mobileApiJson(200, 'success', $message);
        }
        if ($action === 'resend_setup_link') {
            $proformaId = (int)($data['proforma_id'] ?? $data['record_id'] ?? 0);
            if ($proformaId <= 0) {
                mobileApiJson(422, 'error', 'Invalid registration proforma selected.');
            }
            $message = mobileApiOnboardingResendSetupLink($db, $paymentService, $proformaId, $settings);
            mobileApiLogActivity($db, (int)($user['id'] ?? 0), 'onboarding_resend_setup_link', 'registration_proforma', $proformaId, $message);
            mobileApiJson(200, 'success', $message);
        }
        mobileApiJson(422, 'error', 'Unsupported tracker action.');
    }

    if ($method !== 'GET') {
        mobileApiJson(405, 'error', 'Method not allowed.');
    }

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

    $staffAssignees = [];
    try {
        $stmtStaff = $db->query("SELECT id, full_name, role FROM users WHERE role IN ('admin', 'reader', 'finance', 'support') ORDER BY full_name ASC");
        $staffAssignees = $stmtStaff ? ($stmtStaff->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        $staffAssignees = [];
    }

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
        'staff_assignees' => $staffAssignees,
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