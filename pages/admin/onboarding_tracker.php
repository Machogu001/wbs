<?php
session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Payment.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';
require_once __DIR__ . '/../../includes/PaymentLink.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/ClientMeter.php';
require_once __DIR__ . '/../../includes/Mpesa.php';
require_once __DIR__ . '/../../includes/SMS.php';
require_once __DIR__ . '/../../includes/Email.php';

function onboardingTrackerFormatDate(?string $value, string $fallback = 'Not yet'): string
{
    if (!$value) {
        return $fallback;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $fallback;
    }

    return date('d M Y, H:i', $timestamp);
}

function onboardingTrackerBuildUrl(array $params = []): string
{
    $base = '/admin/onboarding-tracker';
    if (empty($params)) {
        return $base;
    }

    return $base . '?' . http_build_query($params);
}

function onboardingTrackerCurrentParams(): array
{
    $params = [];
    foreach (['q', 'type', 'status', 'from', 'to', 'assignee'] as $key) {
        $value = trim((string)($_REQUEST[$key] ?? ''));
        if ($value !== '') {
            $params[$key] = $value;
        }
    }

    return $params;
}

function onboardingTrackerBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host;
}

function onboardingTrackerRedirectWithFlash(string $type, string $message, array $params = []): void
{
    $_SESSION['onboarding_tracker_flash'] = [
        'type' => $type,
        'message' => $message,
    ];

    header('Location: ' . onboardingTrackerBuildUrl($params));
    exit;
}

function onboardingTrackerProformaStatus(array $row): array
{
    $outstandingAmount = (float)($row['outstanding_amount'] ?? 0);
    $userStatus = strtolower((string)($row['user_status'] ?? 'inactive'));
    $hasCompletedSetup = !empty($row['account_setup_completed_at']);
    $hasSetupToken = !empty($row['account_setup_token']);
    $expiresAt = !empty($row['account_setup_expires_at']) ? strtotime((string)$row['account_setup_expires_at']) : false;
    $tokenExpired = $hasSetupToken && $expiresAt !== false && $expiresAt < time();
    $tokenActive = $hasSetupToken && $expiresAt !== false && $expiresAt >= time();

    $payment = [
        'label' => $outstandingAmount <= 0.01 ? 'Fee Paid' : 'Balance ' . number_format($outstandingAmount, 2),
        'class' => $outstandingAmount <= 0.01 ? 'bg-success' : 'bg-warning text-dark',
    ];

    if ($hasCompletedSetup || $userStatus === 'active') {
        return [
            'key' => 'complete',
            'label' => 'Customer Activated',
            'class' => 'bg-success',
            'payment' => $payment,
            'setup' => ['label' => 'Password Set', 'class' => 'bg-success'],
            'note' => 'Portal access completed and the customer account is active.',
        ];
    }

    if ($outstandingAmount > 0.01) {
        return [
            'key' => 'pending_payment',
            'label' => 'Awaiting Fee Payment',
            'class' => 'bg-warning text-dark',
            'payment' => $payment,
            'setup' => ['label' => 'Waiting for Payment', 'class' => 'bg-secondary'],
            'note' => 'The registration fee must clear before onboarding can continue.',
        ];
    }

    if ($tokenExpired) {
        return [
            'key' => 'action_needed',
            'label' => 'Setup Link Expired',
            'class' => 'bg-danger',
            'payment' => $payment,
            'setup' => ['label' => 'Expired', 'class' => 'bg-danger'],
            'note' => 'Payment is cleared, but the account setup link expired before the customer finished password setup.',
        ];
    }

    if ($tokenActive || !empty($row['account_setup_sent_at'])) {
        return [
            'key' => 'pending_setup',
            'label' => 'Waiting for Password Setup',
            'class' => 'bg-info text-dark',
            'payment' => $payment,
            'setup' => ['label' => 'Link Sent', 'class' => 'bg-info text-dark'],
            'note' => 'Fee is paid and the customer still needs to finish portal password setup.',
        ];
    }

    return [
        'key' => 'action_needed',
        'label' => 'Setup Link Missing',
        'class' => 'bg-secondary',
        'payment' => $payment,
        'setup' => ['label' => 'Pending Issue', 'class' => 'bg-secondary'],
        'note' => 'Payment is cleared, but no setup link has been issued yet.',
    ];
}

function onboardingTrackerMeterStatus(array $row): array
{
    $registrationBillId = (int)($row['registration_bill_id'] ?? 0);
    $billAmount = (float)($row['bill_amount'] ?? 0);
    $outstandingAmount = (float)($row['outstanding_amount'] ?? 0);
    $meterStatus = strtolower((string)($row['meter_status'] ?? 'active'));
    $requiresFee = $registrationBillId > 0 && $billAmount > 0;

    if ($meterStatus !== 'active') {
        return [
            'key' => 'action_needed',
            'label' => 'Meter Inactive',
            'class' => 'bg-danger',
            'payment' => ['label' => $requiresFee ? 'Review Needed' : 'No Fee Bill', 'class' => 'bg-secondary'],
            'setup' => ['label' => 'Meter Inactive', 'class' => 'bg-danger'],
            'note' => 'This additional meter record is inactive and needs review.',
        ];
    }

    if (!$requiresFee) {
        return [
            'key' => 'complete',
            'label' => 'Meter Linked',
            'class' => 'bg-success',
            'payment' => ['label' => 'No Fee Required', 'class' => 'bg-secondary'],
            'setup' => ['label' => 'Meter Active', 'class' => 'bg-success'],
            'note' => 'The additional meter is already linked and does not have a separate onboarding fee bill.',
        ];
    }

    if ($outstandingAmount > 0.01) {
        return [
            'key' => 'pending_payment',
            'label' => 'Awaiting Meter Fee',
            'class' => 'bg-warning text-dark',
            'payment' => ['label' => 'Balance ' . number_format($outstandingAmount, 2), 'class' => 'bg-warning text-dark'],
            'setup' => ['label' => 'Meter Linked', 'class' => 'bg-success'],
            'note' => 'The meter is linked to the account, but the additional meter registration fee is still outstanding.',
        ];
    }

    return [
        'key' => 'complete',
        'label' => 'Meter Ready',
        'class' => 'bg-success',
        'payment' => ['label' => 'Fee Paid', 'class' => 'bg-success'],
        'setup' => ['label' => 'Meter Active', 'class' => 'bg-success'],
        'note' => 'The additional meter is linked and its onboarding fee has been settled.',
    ];
}

function onboardingTrackerSendStk(PDO $db, Payment $paymentService, string $sourceType, int $sourceId, string $currency): string
{
    if ($sourceType === 'proforma') {
        $stmt = $db->prepare('SELECT rp.id, rp.user_id, rp.bill_id, u.account_number, u.full_name, u.phone_number
            FROM registration_proformas rp
            INNER JOIN users u ON u.id = rp.user_id
            WHERE rp.id = :id LIMIT 1');
        $stmt->execute([':id' => $sourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            throw new Exception('Registration proforma not found.');
        }
    } else {
        $stmt = $db->prepare('SELECT um.id, um.user_id, um.registration_bill_id AS bill_id, u.account_number, u.full_name, u.phone_number
            FROM user_meters um
            INNER JOIN users u ON u.id = um.user_id
            WHERE um.id = :id LIMIT 1');
        $stmt->execute([':id' => $sourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            throw new Exception('Additional meter record not found.');
        }
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
    $mpesa = new Mpesa();
    $response = $mpesa->stkPush($formattedPhone, $amountToCharge, (string)$row['account_number'], 'Registration Fee');
    if (isset($response['error'])) {
        $details = '';
        if (isset($response['http_code'])) {
            $details .= ' (HTTP ' . $response['http_code'] . ')';
        }
        if (isset($response['details']) && is_array($response['details'])) {
            if (!empty($response['details']['errorMessage'])) {
                $details .= ': ' . $response['details']['errorMessage'];
            } elseif (!empty($response['details']['errorCode'])) {
                $details .= ' (Code ' . $response['details']['errorCode'] . ')';
            }
        }
        throw new Exception('Payment initiation failed: ' . $response['error'] . $details);
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

function onboardingTrackerResendSetupLink(PDO $db, Payment $paymentService, int $proformaId, array $settings): string
{
    $stmt = $db->prepare('SELECT rp.id, rp.user_id, rp.bill_id, u.account_number, u.full_name, u.phone_number, u.email
        FROM registration_proformas rp
        INNER JOIN users u ON u.id = rp.user_id
        WHERE rp.id = :id LIMIT 1');
    $stmt->execute([':id' => $proformaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        throw new Exception('Registration proforma not found.');
    }

    $outstandingAmount = $paymentService->getBillOutstandingAmount((int)$row['bill_id']);
    if ($outstandingAmount > 0.01) {
        throw new Exception('The registration fee must be fully paid before resending the setup link.');
    }

    $setupData = $paymentService->reissueRegistrationAccountSetupToken((int)$row['user_id'], (int)$row['bill_id']);
    if (!$setupData || empty($setupData['token'])) {
        throw new Exception('Unable to generate a new setup link.');
    }

    $setupLink = onboardingTrackerBaseUrl() . '/registration-account-setup?token=' . rawurlencode((string)$setupData['token']);
    $companyName = !empty($settings['company_name']) ? (string)$settings['company_name'] : 'Water Billing System';
    $supportPhone = !empty($settings['support_phone']) ? (string)$settings['support_phone'] : '254724400202';
    $messageText = "Dear {$row['full_name']}, your registration fee has been confirmed. Set your portal password here: {$setupLink}\n\nUse the link within 7 days to activate your online access. For assistance contact {$supportPhone}.";

    if (!empty($row['phone_number'])) {
        try {
            $sms = new SMS($db);
            $sms->sendWithFallback((string)$row['phone_number'], $messageText, 'registration_setup_link');
        } catch (Throwable $e) {
            error_log('Registration setup SMS failed: ' . $e->getMessage());
        }
    }

    if (!empty($row['email'])) {
        try {
            $emailService = new Email();
            $emailBody = "Hello {$row['full_name']},\n\nYour account setup link for {$companyName} is ready:\n{$setupLink}\n\nThis link expires in 7 days. If you need help, call {$supportPhone}.";
            $emailService->queue((string)$row['email'], 'Complete your account setup', $emailBody, 'registration_setup_link');
        } catch (Throwable $e) {
            error_log('Registration setup email failed: ' . $e->getMessage());
        }
    }

    return 'Account setup link resent for ' . (string)$row['full_name'] . '.';
}

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if (!$auth->isLoggedIn() || (!$auth->isAdmin() && !$auth->hasPermission('manage_registration_proformas') && !$auth->hasPermission('view_customers'))) {
    header('Location: /login');
    exit;
}

// Ensure the multi-meter schema is up to date before querying assignee metadata.
new ClientMeter($db);

$paymentService = new Payment($db);
$settingsService = new BillingSettings($db);
$settings = $settingsService->getSettings();
$currency = !empty($settings['currency_code']) ? (string)$settings['currency_code'] : 'KES';
$canViewCustomers = $auth->isAdmin() || $auth->hasPermission('view_customers');

$flashMessage = '';
$flashType = 'success';
if (isset($_SESSION['onboarding_tracker_flash'])) {
    $flash = $_SESSION['onboarding_tracker_flash'];
    $flashMessage = (string)($flash['message'] ?? '');
    $flashType = (string)($flash['type'] ?? 'success');
    unset($_SESSION['onboarding_tracker_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['app_csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }

    $redirectParams = onboardingTrackerCurrentParams();
    $action = trim((string)($_POST['tracker_action'] ?? ''));

    try {
        if ($action === 'send_stk') {
            $sourceType = trim((string)($_POST['source_type'] ?? ''));
            $sourceId = (int)($_POST['source_id'] ?? 0);
            if (!in_array($sourceType, ['proforma', 'meter'], true) || $sourceId <= 0) {
                throw new Exception('Invalid onboarding item selected.');
            }

            onboardingTrackerRedirectWithFlash('success', onboardingTrackerSendStk($db, $paymentService, $sourceType, $sourceId, $currency), $redirectParams);
        }

        if ($action === 'resend_setup_link') {
            $proformaId = (int)($_POST['proforma_id'] ?? 0);
            if ($proformaId <= 0) {
                throw new Exception('Invalid registration proforma selected.');
            }

            onboardingTrackerRedirectWithFlash('success', onboardingTrackerResendSetupLink($db, $paymentService, $proformaId, $settings), $redirectParams);
        }

        throw new Exception('Unsupported tracker action.');
    } catch (Exception $e) {
        onboardingTrackerRedirectWithFlash('danger', $e->getMessage(), $redirectParams);
    }
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

$proformaRows = [];
if ($typeFilter !== 'meter') {
    $proformaSql = "
        SELECT rp.id, rp.user_id, rp.bill_id, rp.notes, rp.account_setup_token, rp.account_setup_expires_at,
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

    $proformaParams = [];
    $proformaWhere = [];
    if ($searchTerm !== '') {
        $proformaWhere[] = "(u.full_name LIKE :search OR u.account_number LIKE :search OR u.phone_number LIKE :search OR u.email LIKE :search)";
        $proformaParams[':search'] = $searchLike;
    }
    if ($dateFromSql !== '') {
        $proformaWhere[] = 'rp.created_at >= :date_from';
        $proformaParams[':date_from'] = $dateFromSql;
    }
    if ($dateToSql !== '') {
        $proformaWhere[] = 'rp.created_at <= :date_to';
        $proformaParams[':date_to'] = $dateToSql;
    }
    if ($assigneeFilter === 'unassigned') {
        $proformaWhere[] = 'rp.created_by_user_id IS NULL';
    } elseif (ctype_digit($assigneeFilter) && (int)$assigneeFilter > 0) {
        $proformaWhere[] = 'rp.created_by_user_id = :assignee_id';
        $proformaParams[':assignee_id'] = (int)$assigneeFilter;
    }

    if (!empty($proformaWhere)) {
        $proformaSql .= ' WHERE ' . implode(' AND ', $proformaWhere);
    }

    $proformaSql .= ' ORDER BY rp.created_at DESC, rp.id DESC';
    $stmtProformas = $db->prepare($proformaSql);
    $stmtProformas->execute($proformaParams);
    $proformaRows = $stmtProformas->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$meterRows = [];
if ($typeFilter !== 'proforma') {
    $meterSql = "
        SELECT um.id, um.user_id, um.meter_number, um.meter_label, um.status AS meter_status,
               um.is_primary, um.registration_bill_id, um.created_by_user_id, um.created_at,
               u.account_number, u.full_name, u.phone_number, u.email,
               creator.full_name AS created_by_name,
               b.amount AS bill_amount, b.status AS bill_status, b.due_date,
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

    $meterParams = [];
    $meterWhere = [];
    if ($searchTerm !== '') {
        $meterWhere[] = "(u.full_name LIKE :search OR u.account_number LIKE :search OR u.phone_number LIKE :search OR u.email LIKE :search OR um.meter_number LIKE :search OR COALESCE(um.meter_label, '') LIKE :search)";
        $meterParams[':search'] = $searchLike;
    }
    if ($dateFromSql !== '') {
        $meterWhere[] = 'um.created_at >= :date_from';
        $meterParams[':date_from'] = $dateFromSql;
    }
    if ($dateToSql !== '') {
        $meterWhere[] = 'um.created_at <= :date_to';
        $meterParams[':date_to'] = $dateToSql;
    }
    if ($assigneeFilter === 'unassigned') {
        $meterWhere[] = 'um.created_by_user_id IS NULL';
    } elseif (ctype_digit($assigneeFilter) && (int)$assigneeFilter > 0) {
        $meterWhere[] = 'um.created_by_user_id = :assignee_id';
        $meterParams[':assignee_id'] = (int)$assigneeFilter;
    }

    if (!empty($meterWhere)) {
        $meterSql .= ' AND ' . implode(' AND ', $meterWhere);
    }

    $meterSql .= ' ORDER BY um.created_at DESC, um.id DESC';
    $stmtMeters = $db->prepare($meterSql);
    $stmtMeters->execute($meterParams);
    $meterRows = $stmtMeters->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$trackerRows = [];
$summary = [
    'proforma_pending_payment' => 0,
    'proforma_pending_setup' => 0,
    'meter_pending_payment' => 0,
    'completed' => 0,
    'attention' => 0,
];

foreach ($proformaRows as $row) {
    $status = onboardingTrackerProformaStatus($row);

    if ($status['key'] === 'pending_payment') {
        $summary['proforma_pending_payment']++;
    } elseif ($status['key'] === 'pending_setup') {
        $summary['proforma_pending_setup']++;
    } elseif ($status['key'] === 'complete') {
        $summary['completed']++;
    } else {
        $summary['attention']++;
    }

    $trackerRows[] = [
        'type' => 'proforma',
        'type_label' => 'New Connection',
        'icon' => 'bi-person-plus',
        'record_id' => (int)($row['id'] ?? 0),
        'subject_name' => (string)($row['full_name'] ?? ''),
        'contact_line' => trim((string)($row['phone_number'] ?? '') . ' • ' . (string)($row['email'] ?? '')),
        'account_number' => (string)($row['account_number'] ?? ''),
        'item_label' => strtoupper((string)($row['connection_type'] ?? 'domestic')),
        'item_meta' => 'Account ' . (string)($row['account_number'] ?? ''),
        'assignee_name' => !empty($row['created_by_name']) ? (string)$row['created_by_name'] : 'Unassigned',
        'created_at' => (string)($row['created_at'] ?? ''),
        'created_label' => onboardingTrackerFormatDate((string)($row['created_at'] ?? ''), 'Unknown'),
        'payment' => $status['payment'],
        'setup' => $status['setup'],
        'overall' => ['key' => $status['key'], 'label' => $status['label'], 'class' => $status['class']],
        'note' => $status['note'],
        'amount_label' => $currency . ' ' . number_format((float)($row['bill_amount'] ?? 0), 2),
        'balance_label' => $currency . ' ' . number_format((float)($row['outstanding_amount'] ?? 0), 2),
        'due_label' => !empty($row['due_date']) ? date('d M Y', strtotime((string)$row['due_date'])) : 'No due date',
        'last_paid_label' => onboardingTrackerFormatDate((string)($row['last_paid_at'] ?? ''), 'No confirmed payment'),
        'open_url' => PaymentLink::generateRegistrationProformaLink((int)$row['bill_id']),
        'document_url' => '/invoice?t=' . urlencode(PaymentLink::generateToken((int)$row['bill_id'])) . (((float)($row['outstanding_amount'] ?? 0) > 0.01) ? '&proforma=1' : ''),
        'payments_url' => '/admin/payments?account=' . urlencode((string)($row['account_number'] ?? '')),
        'customer_url' => '/admin/users?edit_id=' . (int)($row['user_id'] ?? 0),
        'setup_url' => !empty($row['account_setup_token']) ? '/registration-account-setup?token=' . urlencode((string)$row['account_setup_token']) : '',
    ];
}

foreach ($meterRows as $row) {
    $status = onboardingTrackerMeterStatus($row);

    if ($status['key'] === 'pending_payment') {
        $summary['meter_pending_payment']++;
    } elseif ($status['key'] === 'complete') {
        $summary['completed']++;
    } else {
        $summary['attention']++;
    }

    $meterLabel = trim((string)($row['meter_label'] ?? ''));
    $trackerRows[] = [
        'type' => 'meter',
        'type_label' => 'Additional Meter',
        'icon' => 'bi-diagram-3',
        'record_id' => (int)($row['id'] ?? 0),
        'subject_name' => (string)($row['full_name'] ?? ''),
        'contact_line' => trim((string)($row['phone_number'] ?? '') . ' • ' . (string)($row['email'] ?? '')),
        'account_number' => (string)($row['account_number'] ?? ''),
        'item_label' => (string)($row['meter_number'] ?? ''),
        'item_meta' => $meterLabel !== '' ? $meterLabel : 'No meter label',
        'assignee_name' => !empty($row['created_by_name']) ? (string)$row['created_by_name'] : 'Unassigned',
        'created_at' => (string)($row['created_at'] ?? ''),
        'created_label' => onboardingTrackerFormatDate((string)($row['created_at'] ?? ''), 'Unknown'),
        'payment' => $status['payment'],
        'setup' => $status['setup'],
        'overall' => ['key' => $status['key'], 'label' => $status['label'], 'class' => $status['class']],
        'note' => $status['note'],
        'amount_label' => !empty($row['registration_bill_id']) ? $currency . ' ' . number_format((float)($row['bill_amount'] ?? 0), 2) : 'No fee bill',
        'balance_label' => !empty($row['registration_bill_id']) ? $currency . ' ' . number_format((float)($row['outstanding_amount'] ?? 0), 2) : 'No balance',
        'due_label' => !empty($row['due_date']) ? date('d M Y', strtotime((string)$row['due_date'])) : 'No due date',
        'last_paid_label' => onboardingTrackerFormatDate((string)($row['last_paid_at'] ?? ''), 'No confirmed payment'),
        'open_url' => !empty($row['registration_bill_id']) ? PaymentLink::generateLink((int)$row['registration_bill_id']) : '',
        'document_url' => !empty($row['registration_bill_id']) ? '/invoice?t=' . urlencode(PaymentLink::generateToken((int)$row['registration_bill_id'])) : '',
        'payments_url' => '/admin/payments?account=' . urlencode((string)($row['account_number'] ?? '')),
        'customer_url' => '/admin/users?edit_id=' . (int)($row['user_id'] ?? 0),
        'setup_url' => '',
    ];
}

if ($statusFilter !== 'all') {
    $trackerRows = array_values(array_filter($trackerRows, static function (array $row) use ($statusFilter): bool {
        $statusKey = (string)($row['overall']['key'] ?? '');
        if ($statusFilter === 'attention') {
            return in_array($statusKey, ['pending_payment', 'pending_setup', 'action_needed'], true);
        }

        return $statusKey === $statusFilter;
    }));
}

$trackerSuggestions = [];
foreach ($trackerRows as $row) {
    $candidates = [
        (string)($row['account_number'] ?? ''),
        (string)($row['subject_name'] ?? ''),
        (string)($row['item_label'] ?? ''),
        (string)($row['item_meta'] ?? ''),
    ];

    $contactLine = (string)($row['contact_line'] ?? '');
    if ($contactLine !== '') {
        foreach (explode('•', $contactLine) as $contactPart) {
            $candidates[] = trim($contactPart);
        }
    }

    foreach ($candidates as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }
        $trackerSuggestions[mb_strtolower($candidate)] = $candidate;
    }
}

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
    if (($row['overall']['key'] ?? '') === 'complete') {
        $assigneeSummary[$assigneeName]['completed']++;
    } else {
        $assigneeSummary[$assigneeName]['pending']++;
    }
}

usort($assigneeSummary, static function (array $left, array $right): int {
    if ($left['pending'] !== $right['pending']) {
        return $right['pending'] <=> $left['pending'];
    }

    if ($left['total'] !== $right['total']) {
        return $right['total'] <=> $left['total'];
    }

    return strcmp($left['name'], $right['name']);
});

$statusOrder = [
    'action_needed' => 0,
    'pending_payment' => 1,
    'pending_setup' => 2,
    'complete' => 3,
];

usort($trackerRows, static function (array $left, array $right) use ($statusOrder): int {
    $leftRank = $statusOrder[$left['overall']['key'] ?? 'complete'] ?? 99;
    $rightRank = $statusOrder[$right['overall']['key'] ?? 'complete'] ?? 99;
    if ($leftRank !== $rightRank) {
        return $leftRank <=> $rightRank;
    }

    return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
});

$allFilteredRows = $trackerRows;

$perPage = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalRows = count($allFilteredRows);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

$trackerRows = array_slice($allFilteredRows, ($page - 1) * $perPage, $perPage);

$exportRequested = strtolower(trim((string)($_GET['export'] ?? '')));
if ($exportRequested === 'csv') {
    $exportRows = array_values(array_filter($allFilteredRows, static function (array $row): bool {
        return ($row['overall']['key'] ?? '') !== 'complete';
    }));

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="onboarding-tracker-' . date('Ymd-His') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Type', 'Client', 'Account Number', 'Item', 'Item Meta', 'Assignee', 'Created At', 'Payment Status', 'Setup Status', 'Overall Status', 'Amount', 'Balance', 'Due Date', 'Last Payment', 'Payments URL', 'Customer URL']);
    foreach ($exportRows as $row) {
        fputcsv($output, [
            $row['type_label'],
            $row['subject_name'],
            $row['account_number'],
            $row['item_label'],
            $row['item_meta'],
            $row['assignee_name'],
            $row['created_label'],
            $row['payment']['label'],
            $row['setup']['label'],
            $row['overall']['label'],
            $row['amount_label'],
            $row['balance_label'],
            $row['due_label'],
            $row['last_paid_label'],
            onboardingTrackerBaseUrl() . $row['payments_url'],
            onboardingTrackerBaseUrl() . $row['customer_url'],
        ]);
    }
    fclose($output);
    exit;
}

$page_title = 'Onboarding Tracker';
$is_admin_page = true;
require_once __DIR__ . '/../../templates/header.php';
?>

<style>
.onboarding-summary-card {
    border: 0;
    border-radius: 1rem;
    box-shadow: 0 0.85rem 2rem rgba(15, 23, 42, 0.08);
}

.onboarding-summary-card .card-body {
    padding: 1.25rem;
}

.onboarding-summary-kicker {
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #6b7280;
}

.onboarding-summary-value {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
    color: #0f172a;
}

.onboarding-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.45rem 0.75rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.16);
    color: #fff;
    font-size: 0.88rem;
}

.onboarding-filters .form-control,
.onboarding-filters .form-select {
    min-height: 2.8rem;
}

.onboarding-record-title {
    font-weight: 700;
    color: #0f172a;
}

.onboarding-record-subtle {
    color: #6b7280;
    font-size: 0.9rem;
}

.onboarding-stage-stack {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.onboarding-record-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
}

.onboarding-assignee-card {
    border: 1px solid rgba(15, 23, 42, 0.08);
    border-radius: 0.9rem;
    padding: 1rem;
    background: #fff;
    height: 100%;
}

.onboarding-assignee-name {
    font-weight: 700;
    color: #0f172a;
}

.onboarding-assignee-meta {
    color: #64748b;
    font-size: 0.9rem;
}

.onboarding-pagination {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.25rem;
    border-top: 1px solid rgba(15, 23, 42, 0.08);
}

.onboarding-pagination-links {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
}
</style>

<div class="container mt-4">
    <div class="row">
        <div class="col-12">
            <div class="pb-banner pb-banner--teal mb-4">
                <div class="pb-bg" aria-hidden="true">
                    <div class="pb-grid"></div>
                    <div class="pb-blob pb-blob--a"></div>
                    <div class="pb-blob pb-blob--b"></div>
                    <i class="bi bi-diagram-3-fill pb-watermark"></i>
                </div>
                <div class="pb-inner">
                    <div class="pb-left">
                        <div class="pb-eyebrow-row">
                            <span class="pb-eyebrow-chip"><i class="bi bi-diagram-3"></i> Follow-up Workspace</span>
                        </div>
                        <h2 class="pb-title">Onboarding Tracker</h2>
                        <p class="pb-subtitle">Track new registration proformas and additional meter onboarding from fee collection through account or meter readiness.</p>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <span class="onboarding-chip"><i class="bi bi-file-earmark-medical"></i> New connection follow-up</span>
                            <span class="onboarding-chip"><i class="bi bi-diagram-3"></i> Additional meter follow-up</span>
                        </div>
                    </div>
                    <div class="pb-right">
                        <div class="d-grid gap-2">
                            <a class="btn btn-light" href="/admin/registration-proformas"><i class="bi bi-file-earmark-medical me-1"></i> Registration Proformas</a>
                            <a class="btn btn-outline-light" href="/admin/users"><i class="bi bi-people me-1"></i> Customers</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($flashMessage !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($flashMessage); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-6 col-xl-3">
            <div class="card onboarding-summary-card h-100">
                <div class="card-body">
                    <div class="onboarding-summary-kicker">New Connections Awaiting Payment</div>
                    <div class="onboarding-summary-value mt-2"><?php echo (int)$summary['proforma_pending_payment']; ?></div>
                    <div class="text-muted small mt-2">Dormant accounts with unpaid registration fees.</div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card onboarding-summary-card h-100">
                <div class="card-body">
                    <div class="onboarding-summary-kicker">Paid, Waiting for Setup</div>
                    <div class="onboarding-summary-value mt-2"><?php echo (int)$summary['proforma_pending_setup']; ?></div>
                    <div class="text-muted small mt-2">Fees received, but portal password setup is not complete.</div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card onboarding-summary-card h-100">
                <div class="card-body">
                    <div class="onboarding-summary-kicker">Additional Meters Awaiting Fee</div>
                    <div class="onboarding-summary-value mt-2"><?php echo (int)$summary['meter_pending_payment']; ?></div>
                    <div class="text-muted small mt-2">Meters already linked to accounts but still awaiting payment.</div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card onboarding-summary-card h-100">
                <div class="card-body">
                    <div class="onboarding-summary-kicker">Completed Onboardings</div>
                    <div class="onboarding-summary-value mt-2"><?php echo (int)$summary['completed']; ?></div>
                    <div class="text-muted small mt-2">Activated customers and fully settled additional meters.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body onboarding-filters">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-5">
                    <label class="form-label" for="trackerSearch">Search</label>
                    <input type="text" class="form-control" id="trackerSearch" name="q" list="trackerSearchSuggestions" value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Account no, client name, phone, email or meter no" autocomplete="off">
                    <datalist id="trackerSearchSuggestions">
                        <?php foreach (array_values($trackerSuggestions) as $suggestion): ?>
                            <option value="<?php echo htmlspecialchars($suggestion, ENT_QUOTES, 'UTF-8'); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <div class="form-text">Suggestions appear as you type account number, name, phone, email, or meter details.</div>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label" for="trackerType">Type</label>
                    <select class="form-select" id="trackerType" name="type">
                        <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="proforma" <?php echo $typeFilter === 'proforma' ? 'selected' : ''; ?>>New connections</option>
                        <option value="meter" <?php echo $typeFilter === 'meter' ? 'selected' : ''; ?>>Additional meters</option>
                    </select>
                </div>
                <div class="col-md-4 col-lg-2">
                    <label class="form-label" for="trackerStatus">Status</label>
                    <select class="form-select" id="trackerStatus" name="status">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All stages</option>
                        <option value="attention" <?php echo $statusFilter === 'attention' ? 'selected' : ''; ?>>Needs follow-up</option>
                        <option value="pending_payment" <?php echo $statusFilter === 'pending_payment' ? 'selected' : ''; ?>>Awaiting payment</option>
                        <option value="pending_setup" <?php echo $statusFilter === 'pending_setup' ? 'selected' : ''; ?>>Awaiting setup</option>
                        <option value="complete" <?php echo $statusFilter === 'complete' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label" for="trackerFrom">From</label>
                    <input type="date" class="form-control" id="trackerFrom" name="from" value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3 col-lg-2">
                    <label class="form-label" for="trackerTo">To</label>
                    <input type="date" class="form-control" id="trackerTo" name="to" value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-6 col-lg-3">
                    <label class="form-label" for="trackerAssignee">Assignee</label>
                    <select class="form-select" id="trackerAssignee" name="assignee">
                        <option value="all" <?php echo $assigneeFilter === 'all' ? 'selected' : ''; ?>>All staff</option>
                        <option value="unassigned" <?php echo $assigneeFilter === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                        <?php foreach ($staffAssignees as $staffRow): ?>
                            <?php $staffId = (int)($staffRow['id'] ?? 0); ?>
                            <option value="<?php echo $staffId; ?>" <?php echo $assigneeFilter === (string)$staffId ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)($staffRow['full_name'] ?? 'Staff')); ?> (<?php echo htmlspecialchars(ucfirst((string)($staffRow['role'] ?? 'staff'))); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5 col-lg-3">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-funnel me-1"></i> Apply</button>
                        <a href="/admin/onboarding-tracker" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </div>
                <div class="col-12">
                    <a class="btn btn-outline-success" href="<?php echo htmlspecialchars(onboardingTrackerBuildUrl(array_merge(onboardingTrackerCurrentParams(), ['export' => 'csv'])), ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-download me-1"></i> Export Pending CSV</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-person-lines-fill me-2"></i>Assignee Summary</h5>
            <span class="badge bg-secondary"><?php echo count($assigneeSummary); ?> assignees</span>
        </div>
        <div class="card-body">
            <?php if (empty($assigneeSummary)): ?>
                <div class="text-muted">No assignee summary is available for the current filters.</div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($assigneeSummary as $assigneeRow): ?>
                        <div class="col-md-6 col-xl-3">
                            <div class="onboarding-assignee-card">
                                <div class="onboarding-assignee-name"><?php echo htmlspecialchars($assigneeRow['name']); ?></div>
                                <div class="onboarding-assignee-meta mt-2">Pending: <strong><?php echo (int)$assigneeRow['pending']; ?></strong></div>
                                <div class="onboarding-assignee-meta">Completed: <strong><?php echo (int)$assigneeRow['completed']; ?></strong></div>
                                <div class="onboarding-assignee-meta">Total records: <strong><?php echo (int)$assigneeRow['total']; ?></strong></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-list-task me-2"></i>Tracked Onboarding Records</h5>
            <span class="badge bg-secondary"><?php echo count($trackerRows); ?> shown of <?php echo (int)$totalRows; ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($trackerRows)): ?>
                <div class="p-4 text-muted">No onboarding records match the current filters.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Type</th>
                                <th>Client</th>
                                <th>Item</th>
                                <th>Payment</th>
                                <th>Setup</th>
                                <th>Overall</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trackerRows as $row): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><i class="bi <?php echo htmlspecialchars($row['icon']); ?> me-1"></i><?php echo htmlspecialchars($row['type_label']); ?></div>
                                        <div class="onboarding-record-subtle"><?php echo htmlspecialchars($row['created_label']); ?></div>
                                    </td>
                                    <td>
                                        <div class="onboarding-record-title"><?php echo htmlspecialchars($row['subject_name']); ?></div>
                                        <div class="onboarding-record-subtle"><?php echo htmlspecialchars($row['account_number']); ?></div>
                                        <div class="onboarding-record-subtle"><?php echo htmlspecialchars($row['contact_line']); ?></div>
                                        <div class="onboarding-record-subtle">Assignee: <?php echo htmlspecialchars($row['assignee_name']); ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($row['item_label']); ?></div>
                                        <div class="onboarding-record-subtle"><?php echo htmlspecialchars($row['item_meta']); ?></div>
                                        <div class="small text-muted mt-1">Amount: <?php echo htmlspecialchars($row['amount_label']); ?></div>
                                        <div class="small text-muted">Balance: <?php echo htmlspecialchars($row['balance_label']); ?></div>
                                        <div class="small text-muted">Due: <?php echo htmlspecialchars($row['due_label']); ?></div>
                                    </td>
                                    <td>
                                        <div class="onboarding-stage-stack">
                                            <span class="badge <?php echo htmlspecialchars($row['payment']['class']); ?>"><?php echo htmlspecialchars($row['payment']['label']); ?></span>
                                            <span class="small text-muted">Last payment: <?php echo htmlspecialchars($row['last_paid_label']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="onboarding-stage-stack">
                                            <span class="badge <?php echo htmlspecialchars($row['setup']['class']); ?>"><?php echo htmlspecialchars($row['setup']['label']); ?></span>
                                            <span class="small text-muted"><?php echo htmlspecialchars($row['note']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo htmlspecialchars($row['overall']['class']); ?>"><?php echo htmlspecialchars($row['overall']['label']); ?></span>
                                    </td>
                                    <td>
                                        <div class="onboarding-record-actions">
                                            <?php if ($row['open_url'] !== ''): ?>
                                                <a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars($row['open_url']); ?>" target="_blank" rel="noopener">Open</a>
                                            <?php endif; ?>
                                            <?php if ($row['document_url'] !== ''): ?>
                                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars($row['document_url']); ?>" target="_blank" rel="noopener">Document</a>
                                            <?php endif; ?>
                                            <?php if ($row['overall']['key'] === 'pending_payment'): ?>
                                                <form method="POST" class="d-inline" data-confirm-message="Send a new M-Pesa STK push to <?php echo htmlspecialchars($row['subject_name'], ENT_QUOTES, 'UTF-8'); ?> for this onboarding fee?">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['app_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="tracker_action" value="send_stk">
                                                    <input type="hidden" name="source_type" value="<?php echo htmlspecialchars($row['type'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="source_id" value="<?php echo (int)$row['record_id']; ?>">
                                                    <input type="hidden" name="q" value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="type" value="<?php echo htmlspecialchars($typeFilter, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="from" value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="to" value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="assignee" value="<?php echo htmlspecialchars($assigneeFilter, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-success">Send STK</button>
                                                </form>
                                            <?php endif; ?>
                                            <a class="btn btn-sm btn-outline-success" href="<?php echo htmlspecialchars($row['payments_url']); ?>">Payments</a>
                                            <?php if ($row['type'] === 'proforma' && in_array($row['overall']['key'], ['pending_setup', 'action_needed'], true)): ?>
                                                <form method="POST" class="d-inline" data-confirm-message="Resend the account setup link to <?php echo htmlspecialchars($row['subject_name'], ENT_QUOTES, 'UTF-8'); ?>?">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['app_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="tracker_action" value="resend_setup_link">
                                                    <input type="hidden" name="proforma_id" value="<?php echo (int)$row['record_id']; ?>">
                                                    <input type="hidden" name="q" value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="type" value="<?php echo htmlspecialchars($typeFilter, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="from" value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="to" value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="assignee" value="<?php echo htmlspecialchars($assigneeFilter, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-info">Resend Setup Link</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($row['setup_url'] !== ''): ?>
                                                <a class="btn btn-sm btn-outline-info" href="<?php echo htmlspecialchars($row['setup_url']); ?>" target="_blank" rel="noopener">Setup Link</a>
                                            <?php endif; ?>
                                            <?php if ($canViewCustomers): ?>
                                                <a class="btn btn-sm btn-outline-dark" href="<?php echo htmlspecialchars($row['customer_url']); ?>">Customer</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($totalRows > 0): ?>
            <div class="onboarding-pagination">
                <div class="text-muted small">Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?></div>
                <div class="onboarding-pagination-links">
                    <?php $basePageParams = onboardingTrackerCurrentParams(); ?>
                    <?php if ($page > 1): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars(onboardingTrackerBuildUrl(array_merge($basePageParams, ['page' => 1])), ENT_QUOTES, 'UTF-8'); ?>">First</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars(onboardingTrackerBuildUrl(array_merge($basePageParams, ['page' => $page - 1])), ENT_QUOTES, 'UTF-8'); ?>">Previous</a>
                    <?php endif; ?>
                    <?php $startPage = max(1, $page - 2); ?>
                    <?php $endPage = min($totalPages, $page + 2); ?>
                    <?php for ($pageNo = $startPage; $pageNo <= $endPage; $pageNo++): ?>
                        <a class="btn btn-sm <?php echo $pageNo === $page ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo htmlspecialchars(onboardingTrackerBuildUrl(array_merge($basePageParams, ['page' => $pageNo])), ENT_QUOTES, 'UTF-8'); ?>"><?php echo (int)$pageNo; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars(onboardingTrackerBuildUrl(array_merge($basePageParams, ['page' => $page + 1])), ENT_QUOTES, 'UTF-8'); ?>">Next</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars(onboardingTrackerBuildUrl(array_merge($basePageParams, ['page' => $totalPages])), ENT_QUOTES, 'UTF-8'); ?>">Last</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>