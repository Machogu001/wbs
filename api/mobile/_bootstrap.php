<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-API-Key');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/mpesa_config.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/Payment.php';
require_once __DIR__ . '/../../includes/MeterReading.php';
require_once __DIR__ . '/../../includes/ClientMeter.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';
require_once __DIR__ . '/../../includes/CountryDialCode.php';
require_once __DIR__ . '/../../includes/MobileApiAuth.php';
require_once __DIR__ . '/../../includes/Mpesa.php';
require_once __DIR__ . '/../../includes/PaymentLink.php';
require_once __DIR__ . '/../../includes/SMS.php';
require_once __DIR__ . '/../../includes/Email.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

function mobileApiGetUiMeta(): array
{
    static $uiMeta = null;
    if (is_array($uiMeta)) {
        return $uiMeta;
    }

    $uiMeta = [
        'company_name' => 'Water Billing System',
        'currency_code' => 'KES',
        'locale_code' => 'en-KE',
        'timezone_name' => 'Africa/Nairobi',
        'preferred_terms_format' => 'sections',
        'preferred_autocomplete_keys' => [
            'label',
            'value',
        ],
        'preferred_card_style' => 'summary_first',
        'preferred_date_format' => 'Y-m-d',
        'preferred_datetime_format' => 'Y-m-d H:i:s',
        'preferred_time_format' => 'H:i',
    ];

    try {
        $database = new Database();
        $db = $database->getConnection();
        if ($db instanceof PDO) {
            $settings = (new BillingSettings($db))->getSettings();
            $uiMeta['company_name'] = (string)($settings['company_name'] ?? $uiMeta['company_name']);
            $uiMeta['currency_code'] = (string)($settings['currency_code'] ?? $uiMeta['currency_code']);
            $uiMeta['locale_code'] = (string)($settings['locale_code'] ?? $uiMeta['locale_code']);
            $uiMeta['timezone_name'] = (string)($settings['timezone_name'] ?? $uiMeta['timezone_name']);
        }
    } catch (Throwable $e) {
    }

    return $uiMeta;
}

// ---------------------------------------------------------------------------
// Activity log: app requests are recorded in the same activity_log table as the
// website, with the gadget (app version, Android version, device model), IP,
// location and network owner so staff see app users exactly like web users.
// ---------------------------------------------------------------------------
function mobileApiActivityMetadata(array $extra = []): array
{
    $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $metadata = [
        'channel' => 'mobile_app',
        'device_type' => 'Mobile App',
        'browser' => 'My Water Bill app',
    ];
    if (preg_match('#MyWaterBillApp/([^\s(]+)\s*\(Android\s*([^;)]*);?\s*([^)]*)\)#i', $userAgent, $match)) {
        $metadata['app_version'] = $match[1];
        $metadata['browser'] = 'My Water Bill app ' . $match[1];
        $device = trim($match[3]);
        $metadata['os'] = 'Android ' . trim($match[2]) . ($device !== '' ? ' (' . $device . ')' : '');
        if ($device !== '') {
            $metadata['device_model'] = $device;
        }
    } elseif (stripos($userAgent, 'android') !== false) {
        $metadata['os'] = 'Android';
    }

    return array_merge($metadata, $extra);
}

function mobileApiLogActivity(PDO $db, ?int $userId, string $action, ?string $entityType = null, $entityId = null, string $description = '', array $metadata = []): void
{
    $GLOBALS['mobileApiActivityLogged'] = true;
    try {
        (new ActivityLog($db))->log(
            $userId,
            $action,
            $entityType,
            $entityId !== null && $entityId !== '' ? (int)$entityId : null,
            $description,
            mobileApiActivityMetadata($metadata)
        );
    } catch (Throwable $e) {
        error_log('Mobile API activity log failed: ' . $e->getMessage());
    }
}

// Every successful state-changing request made by a signed-in app user is logged
// automatically, so new endpoints are covered without extra code. Endpoints that
// log a more specific entry themselves set mobileApiActivityLogged first.
function mobileApiAutoLogActivity(int $statusCode, string $message, array $data): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $actor = $GLOBALS['mobileApiActor'] ?? null;
    if ($method === 'GET' || $method === 'OPTIONS' || !is_array($actor) || $statusCode >= 400
        || !empty($GLOBALS['mobileApiActivityLogged'])) {
        return;
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $endpoint = preg_match('#/api/mobile/(.+)\.php$#', $script, $match) ? $match[1] : basename($script, '.php');
    $payload = [];
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        $payload = is_array($decoded) ? $decoded : [];
    }
    $payload += $_POST;

    $requested = strtolower(trim((string)($payload['action'] ?? '')));
    $endpointKey = str_replace(['/', '-'], '_', $endpoint);
    $action = preg_match('/^[a-z0-9_]{2,60}$/', $requested) ? $requested : $endpointKey;
    if (strpos($action, 'admin_') !== 0 && strpos($endpointKey, 'admin_') === 0 && $action !== $endpointKey) {
        $action = $action . '_' . substr($endpointKey, 6);
    }

    $entityId = null;
    foreach ([$data, $payload] as $source) {
        foreach (['id', 'bill_id', 'payment_id', 'user_id', 'customer_id', 'complaint_id', 'reading_id'] as $key) {
            if (isset($source[$key]) && is_scalar($source[$key]) && (int)$source[$key] > 0) {
                $entityId = (int)$source[$key];
                break 2;
            }
        }
    }

    $fields = array_values(array_diff(array_keys($payload), ['password', 'current_password', 'new_password', 'confirm_password', 'code', 'pin', 'otp']));
    mobileApiLogActivity(
        mobileApiGetDatabase(),
        (int)($actor['id'] ?? 0) ?: null,
        substr($action, 0, 100),
        $endpointKey,
        $entityId,
        trim($message) !== '' ? $message . ' (via mobile app)' : 'Mobile app action: ' . $endpoint,
        [
            'endpoint' => 'api/mobile/' . $endpoint . '.php',
            'method' => $method,
            'fields' => array_slice($fields, 0, 30),
        ]
    );
}

function mobileApiJson(int $statusCode, string $status, string $message, array $data = []): void
{
    mobileApiAutoLogActivity($statusCode, $message, $data);
    http_response_code($statusCode);
    $payload = [
        'status' => $status,
        'message' => $message,
        'data' => $data,
        'meta' => [
            'api_version' => 'v1',
            'generated_at' => gmdate('c'),
            'ui' => mobileApiGetUiMeta(),
        ],
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = json_encode([
            'status' => 'error',
            'message' => 'Unable to encode API response.',
            'data' => [],
        ]);
        if ($json === false) {
            $json = '{"status":"error","message":"Unable to encode API response.","data":{}}';
        }
    }

    echo $json;
    exit;
}

function mobileApiReadJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('Invalid JSON input.');
    }

    return $data;
}

function mobileApiRequireMethod(string $method): void
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== strtoupper($method)) {
        mobileApiJson(405, 'error', 'Method not allowed.');
    }
}

function mobileApiGetDatabase(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }

    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new RuntimeException('Database connection failed.');
    }

    return $db;
}

function mobileApiGetAuthService(PDO $db): MobileApiAuth
{
    static $authService = null;
    if ($authService instanceof MobileApiAuth) {
        return $authService;
    }

    $authService = new MobileApiAuth($db);
    return $authService;
}

function mobileApiGetBearerToken(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
    }

    if (!is_string($header) || trim($header) === '') {
        return null;
    }

    if (preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

function mobileApiGetAppKey(): ?string
{
    $header = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['X_API_KEY'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            $header = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? '';
        }
    }

    $header = is_string($header) ? trim($header) : '';
    return $header !== '' ? $header : null;
}

function mobileApiGetConfiguredAppKey(PDO $db): string
{
    $databaseKey = MpesaConfig::getMobileApiKeyFromDatabase($db);
    if ($databaseKey !== '') {
        return $databaseKey;
    }

    $configuredKey = MpesaConfig::getMobileApiKey();
    if ($configuredKey !== '') {
        return $configuredKey;
    }

    return '';
}

function mobileApiIsAppKeyRequired(PDO $db): bool
{
    try {
        $settings = (new BillingSettings($db))->getSettings();
        return !empty($settings['mobile_api_key_required']);
    } catch (Throwable $e) {
        return true;
    }
}

function mobileApiRequireAppKey(): void
{
    $db = mobileApiGetDatabase();
    if (!mobileApiIsAppKeyRequired($db)) {
        return;
    }

    $configuredKey = mobileApiGetConfiguredAppKey($db);
    if ($configuredKey === '') {
        mobileApiJson(503, 'error', 'Mobile API access key is not configured. Ask an administrator to generate MOBILE_API_KEY in system settings or save it in billing settings.');
    }

    $providedKey = mobileApiGetAppKey();
    if ($providedKey === null) {
        mobileApiJson(401, 'error', 'X-API-Key header is required.');
    }

    if (!hash_equals($configuredKey, $providedKey)) {
        mobileApiJson(403, 'error', 'Invalid mobile API key.');
    }
}

function mobileApiRequireUser(PDO $db): array
{
    $token = mobileApiGetBearerToken();
    if ($token === null) {
        mobileApiJson(401, 'error', 'Authorization bearer token is required.');
    }

    $auth = mobileApiGetAuthService($db);
    $user = $auth->authenticate($token);
    if (!$user) {
        mobileApiJson(401, 'error', 'Invalid or expired access token.');
    }
    $GLOBALS['mobileApiActor'] = $user;

    if (isset($user['password_hash'])) {
        unset($user['password_hash']);
    }

    return $user;
}

function mobileApiUserHasRole(array $user, $roles): bool
{
    $currentRole = strtolower((string)($user['role'] ?? 'customer'));
    if (is_array($roles)) {
        foreach ($roles as $role) {
            if ($currentRole === strtolower((string)$role)) {
                return true;
            }
        }
        return false;
    }

    return $currentRole === strtolower((string)$roles);
}

function mobileApiRolePermissions(PDO $db, string $role): array
{
    static $permissionCache = [];

    $normalizedRole = strtolower(trim($role));
    if ($normalizedRole === '') {
        $normalizedRole = 'customer';
    }

    if (!array_key_exists($normalizedRole, $permissionCache)) {
        try {
            $stmt = $db->prepare('SELECT permission FROM role_permissions WHERE role = :role');
            $stmt->execute([':role' => $normalizedRole]);
            $permissionCache[$normalizedRole] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $fallback = [
                'customer' => ['view_own_bills', 'submit_own_reading'],
                'reader' => ['view_invoicing', 'send_messages'],
                'finance' => ['view_customers', 'view_accounting', 'view_reports', 'view_payments', 'view_invoicing', 'view_bill_detail', 'manage_demand_notices', 'manage_approvals', 'manage_registration_proformas', 'send_messages', 'receive_payments'],
                'support' => ['handle_support', 'view_customers', 'view_bill_detail', 'send_messages'],
            ];
            $permissionCache[$normalizedRole] = $fallback[$normalizedRole] ?? [];
        }
    }

    return $permissionCache[$normalizedRole];
}

function mobileApiUserHasPermission(PDO $db, array $user, string $permission): bool
{
    if (mobileApiUserHasRole($user, 'admin')) {
        return true;
    }

    return in_array($permission, mobileApiRolePermissions($db, (string)($user['role'] ?? 'customer')), true);
}

// Every staff permission the website and mobile API check. Admins implicitly hold all of them.
function mobileApiStaffPermissionKeys(): array
{
    return [
        'view_customers', 'view_payments', 'receive_payments', 'view_invoicing', 'view_bill_detail',
        'correct_bills', 'view_accounting', 'view_reports', 'manage_approvals', 'manage_demand_notices',
        'manage_registration_proformas', 'handle_support', 'send_messages', 'manage_settings',
    ];
}

// Effective permissions sent to the app so it shows the same features the website
// grants this user. The API still enforces every permission server-side.
function mobileApiEffectivePermissions(PDO $db, array $user): array
{
    if (mobileApiUserHasRole($user, 'customer')) {
        return [];
    }

    return array_values(array_filter(
        mobileApiStaffPermissionKeys(),
        static fn(string $permission): bool => mobileApiUserHasPermission($db, $user, $permission)
    ));
}

function mobileApiUserHasAnyPermission(PDO $db, array $user, array $permissions): bool
{
    foreach ($permissions as $permission) {
        if (mobileApiUserHasPermission($db, $user, (string)$permission)) {
            return true;
        }
    }

    return false;
}

function mobileApiNormalizePhone(string $countryCode, string $localNumber): string
{
    $code = preg_replace('/\D+/', '', $countryCode);
    $local = preg_replace('/\D+/', '', $localNumber);
    $local = ltrim($local, '0');
    if ($code === '' || $local === '') {
        return '';
    }
    return $code . $local;
}

function mobileApiNormalizeMeter(string $meterNumber): string
{
    return preg_replace('/\s+/', '', strtoupper(trim($meterNumber)));
}

function mobileApiNormalizeCurrencyAmount($amount): float
{
    $normalized = preg_replace('/[^0-9.\-]/', '', (string)$amount);
    if ($normalized === '' || $normalized === '-' || $normalized === '.') {
        return 0.0;
    }
    return round((float)$normalized, 2);
}

function mobileApiCountryCodeOptions(PDO $db): array
{
    $fallback = [
        ['value' => '254', 'label' => 'Kenya (+254)'],
        ['value' => '256', 'label' => 'Uganda (+256)'],
        ['value' => '255', 'label' => 'Tanzania (+255)'],
        ['value' => '1', 'label' => 'United States (+1)'],
        ['value' => '44', 'label' => 'United Kingdom (+44)'],
    ];

    try {
        $rows = (new CountryDialCode($db))->listActive();
        return !empty($rows) ? $rows : $fallback;
    } catch (Throwable $e) {
        return $fallback;
    }
}

function mobileApiConnectionTypeOptions(): array
{
    return [
        ['value' => 'domestic', 'label' => 'Domestic'],
        ['value' => 'commercial', 'label' => 'Commercial'],
        ['value' => 'industrial', 'label' => 'Industrial'],
    ];
}

function mobileApiCustomerTypeOptions(): array
{
    return [
        ['value' => 'individual', 'label' => 'Individual'],
        ['value' => 'company', 'label' => 'Company'],
    ];
}

function mobileApiStaffRoleOptions(): array
{
    return [
        ['value' => 'admin', 'label' => 'Admin'],
        ['value' => 'reader', 'label' => 'Reader'],
        ['value' => 'finance', 'label' => 'Finance'],
        ['value' => 'support', 'label' => 'Support'],
    ];
}

function mobileApiStatusOptions(): array
{
    return [
        ['value' => 'active', 'label' => 'Active'],
        ['value' => 'inactive', 'label' => 'Inactive'],
        ['value' => 'suspended', 'label' => 'Suspended'],
    ];
}

function mobileApiResolveClient(User $userService, string $identifier): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    $user = $userService->getByAccountNumber($identifier);
    if (!$user) {
        $user = $userService->getByMeterNumber($identifier);
    }
    if (!$user) {
        $matches = $userService->searchByNameOrAccount($identifier, 2);
        if (count($matches) === 1) {
            $user = $matches[0];
        }
    }

    return $user ?: null;
}

function mobileApiRequireStaffPermission(PDO $db, array $user, array $permissions = [], array $roles = []): void
{
    if (mobileApiUserHasRole($user, 'admin')) {
        return;
    }

    if (!empty($roles) && mobileApiUserHasRole($user, $roles)) {
        return;
    }

    foreach ($permissions as $permission) {
        if (mobileApiUserHasPermission($db, $user, (string)$permission)) {
            return;
        }
    }

    mobileApiJson(403, 'error', 'Forbidden.');
}

function mobileApiGetClientIp(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim((string)$parts[0]);
    }

    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function mobileApiEnsureLoginAttemptsTable(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(255) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        last_attempt_at DATETIME NOT NULL,
        INDEX idx_identifier_ip (identifier, ip_address),
        INDEX idx_last_attempt_at (last_attempt_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function mobileApiIsRateLimited(PDO $db, string $identifier, string $ip): bool
{
    $stmt = $db->prepare('SELECT attempts, last_attempt_at FROM login_attempts WHERE identifier = :identifier AND ip_address = :ip LIMIT 1');
    $stmt->execute([':identifier' => $identifier, ':ip' => $ip]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return false;
    }

    $lastAttempt = strtotime((string)$row['last_attempt_at']);
    if ($lastAttempt === false || (time() - $lastAttempt) > 600) {
        return false;
    }

    return (int)$row['attempts'] >= 5;
}

function mobileApiRecordFailedLogin(PDO $db, string $identifier, string $ip): void
{
    $stmt = $db->prepare('SELECT id, attempts, last_attempt_at FROM login_attempts WHERE identifier = :identifier AND ip_address = :ip LIMIT 1');
    $stmt->execute([':identifier' => $identifier, ':ip' => $ip]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $now = date('Y-m-d H:i:s');

    if ($row) {
        $lastAttempt = strtotime((string)$row['last_attempt_at']);
        $attempts = (int)$row['attempts'];
        $attempts = ($lastAttempt === false || (time() - $lastAttempt) > 600) ? 1 : $attempts + 1;

        $update = $db->prepare('UPDATE login_attempts SET attempts = :attempts, last_attempt_at = :last_attempt_at WHERE id = :id');
        $update->execute([
            ':attempts' => $attempts,
            ':last_attempt_at' => $now,
            ':id' => (int)$row['id'],
        ]);
        return;
    }

    $insert = $db->prepare('INSERT INTO login_attempts (identifier, ip_address, attempts, last_attempt_at) VALUES (:identifier, :ip, 1, :last_attempt_at)');
    $insert->execute([
        ':identifier' => $identifier,
        ':ip' => $ip,
        ':last_attempt_at' => $now,
    ]);
}

function mobileApiClearFailedLogins(PDO $db, string $identifier, string $ip): void
{
    $stmt = $db->prepare('DELETE FROM login_attempts WHERE identifier = :identifier AND ip_address = :ip');
    $stmt->execute([':identifier' => $identifier, ':ip' => $ip]);
}

function mobileApiBuildAbsoluteUrl(string $path): string
{
    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . $path;
}

function mobileApiDocumentUrl(string $type, int $billId, int $paymentId = 0): string
{
    if ($billId <= 0 && $paymentId <= 0) {
        return '';
    }

    $query = ['type' => $type];
    if ($type === 'receipt') {
        $query['payment_id'] = $paymentId;
    } else {
        $query['bill_id'] = $billId;
    }

    return mobileApiBuildAbsoluteUrl('/api/mobile/document.php?' . http_build_query($query));
}

function mobileApiGetRegistrationPaymentData(PDO $db, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $paymentService = new Payment($db);
    $billService = new Bill($db);
    $registrationPayment = $paymentService->getLatestRegistrationByUserId($userId);
    if (!$registrationPayment || empty($registrationPayment['bill_id'])) {
        return null;
    }

    $billId = (int)$registrationPayment['bill_id'];
    $bill = $billService->getById($billId);
    $outstanding = $paymentService->getBillOutstandingAmount($billId);

    return [
        'bill_id' => $billId,
        'amount_due' => $outstanding,
        'bill_status' => (string)($bill['status'] ?? 'pending'),
        'checkout_request_id' => (string)($registrationPayment['checkout_request_id'] ?? ''),
        'public_payment_url' => mobileApiBuildAbsoluteUrl(PaymentLink::generateRegistrationProformaLink($billId)),
        'document_url' => mobileApiDocumentUrl('proforma', $billId),
    ];
}

function mobileApiNormalizeThemePreference($value): string
{
    $themePreference = strtolower(trim((string)$value));
    if (!in_array($themePreference, ['system', 'light', 'dark'], true)) {
        return 'system';
    }

    return $themePreference;
}

function mobileApiFormatMeter(array $meter): array
{
    return [
        'id' => (int)($meter['id'] ?? 0),
        'meter_number' => (string)($meter['meter_number'] ?? ''),
        'meter_label' => (string)($meter['meter_label'] ?? ''),
        'status' => (string)($meter['status'] ?? 'active'),
        'is_primary' => !empty($meter['is_primary']),
        'registration_bill_id' => !empty($meter['registration_bill_id']) ? (int)$meter['registration_bill_id'] : null,
        'created_at' => (string)($meter['created_at'] ?? ''),
    ];
}

function mobileApiFormatUser(PDO $db, array $user): array
{
    $meterService = new ClientMeter($db);
    $meters = array_map('mobileApiFormatMeter', $meterService->listByUserId((int)$user['id']));
    $role = (string)($user['role'] ?? 'customer');
    $isAdmin = mobileApiUserHasRole($user, 'admin');

    return [
        'id' => (int)($user['id'] ?? 0),
        'account_number' => (string)($user['account_number'] ?? ''),
        'username' => (string)($user['username'] ?? ''),
        'full_name' => (string)($user['full_name'] ?? ''),
        'customer_type' => (string)($user['customer_type'] ?? 'individual'),
        'company_name' => (string)($user['company_name'] ?? ''),
        'contact_person_name' => (string)($user['contact_person_name'] ?? ''),
        'phone_number' => (string)($user['phone_number'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'address' => (string)($user['address'] ?? ''),
        'connection_type' => (string)($user['connection_type'] ?? ''),
        'status' => (string)($user['status'] ?? ''),
        'role' => (string)($user['role'] ?? 'customer'),
        'is_admin' => mobileApiUserHasRole($user, 'admin'),
        'is_staff' => !mobileApiUserHasRole($user, 'customer'),
        'permissions' => mobileApiEffectivePermissions($db, $user),
        'must_change_password' => !empty($user['must_change_password']),
        'two_factor_enabled' => !empty($user['two_factor_enabled']),
        'two_factor_method' => strtolower((string)($user['two_factor_method'] ?? 'sms')) === 'email' ? 'email' : 'sms',
        'tax_pin' => (string)($user['tax_pin'] ?? ''),
        'theme_preference' => mobileApiNormalizeThemePreference($user['theme_preference'] ?? 'system'),
        'meters' => $meters,
    ];
}

function mobileApiFormatBill(Bill $billService, Payment $paymentService, array $bill): array
{
    $billId = (int)($bill['id'] ?? 0);
    $isRegistrationFee = $billService->isRegistrationFeeBill($bill);
    $outstanding = $billId > 0 ? $paymentService->getBillOutstandingAmount($billId) : 0.0;
    $documentSuffix = $isRegistrationFee && $outstanding > 0.01 ? '&proforma=1' : '';

    return [
        'id' => $billId,
        'account_number' => (string)($bill['account_number'] ?? ''),
        'type' => $isRegistrationFee ? 'registration_fee' : 'water_bill',
        'type_label' => $billService->getBillTypeLabel($bill),
        'billing_month' => (string)($bill['billing_month'] ?? ''),
        'previous_reading' => isset($bill['previous_reading']) ? (float)$bill['previous_reading'] : null,
        'current_reading' => isset($bill['current_reading']) ? (float)$bill['current_reading'] : null,
        'consumption' => isset($bill['consumption']) ? (float)$bill['consumption'] : null,
        'rate_per_unit' => isset($bill['rate_per_unit']) ? (float)$bill['rate_per_unit'] : null,
        'service_charge' => isset($bill['service_charge']) ? (float)$bill['service_charge'] : null,
        'base_amount' => isset($bill['base_amount']) ? (float)$bill['base_amount'] : null,
        'tax_rate' => isset($bill['tax_rate']) ? (float)$bill['tax_rate'] : null,
        'tax_amount' => isset($bill['tax_amount']) ? (float)$bill['tax_amount'] : null,
        'amount' => isset($bill['amount']) ? (float)$bill['amount'] : 0.0,
        'outstanding_amount' => $outstanding,
        'paid_amount' => max(0, (float)($bill['amount'] ?? 0) - $outstanding),
        'status' => (string)($bill['status'] ?? 'pending'),
        'due_date' => (string)($bill['due_date'] ?? ''),
        'public_payment_url' => $billId > 0 ? mobileApiBuildAbsoluteUrl($isRegistrationFee ? PaymentLink::generateRegistrationProformaLink($billId) : PaymentLink::generateLink($billId)) : '',
        'document_url' => mobileApiDocumentUrl($documentSuffix !== '' ? 'proforma' : 'invoice', $billId),
    ];
}

function mobileApiFormatPayment(array $payment): array
{
    return [
        'id' => (int)($payment['id'] ?? 0),
        'bill_id' => !empty($payment['bill_id']) ? (int)$payment['bill_id'] : null,
        'amount' => isset($payment['amount']) ? (float)$payment['amount'] : 0.0,
        'status' => (string)($payment['status'] ?? 'pending'),
        'phone_number' => (string)($payment['phone_number'] ?? ''),
        'merchant_request_id' => (string)($payment['merchant_request_id'] ?? ''),
        'checkout_request_id' => (string)($payment['checkout_request_id'] ?? ''),
        'mpesa_receipt' => (string)($payment['mpesa_receipt'] ?? ''),
        'created_at' => (string)($payment['created_at'] ?? ''),
        'billing_month' => (string)($payment['billing_month'] ?? ''),
        'account_number' => (string)($payment['account_number'] ?? ''),
        'payment_method' => (string)($payment['payment_method'] ?? 'mpesa'),
        'transaction_date' => (string)($payment['transaction_date'] ?? ''),
    ];
}

mobileApiRequireAppKey();

function mobileApiFormatClientSearchResult(array $row, string $query = ''): array
{
    $queryLower = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
    $meters = [];
    $meterNumbers = [];

    if (!empty($row['meter_details'])) {
        $segments = array_values(array_filter(explode('||', (string)$row['meter_details'])));
        foreach ($segments as $segment) {
            $parts = explode('::', $segment, 2);
            $meterNumber = trim((string)($parts[0] ?? ''));
            $meterLabel = trim((string)($parts[1] ?? ''));
            if ($meterNumber === '') {
                continue;
            }

            $meters[] = [
                'number' => $meterNumber,
                'label' => $meterLabel,
                'is_primary' => empty($meterNumbers),
            ];
            $meterNumbers[] = $meterNumber;
        }
    } elseif (!empty($row['meter_number'])) {
        $meterNumbers = [(string)$row['meter_number']];
        $meters[] = [
            'number' => (string)$row['meter_number'],
            'label' => '',
            'is_primary' => true,
        ];
    }

    $selectionValue = (string)($row['account_number'] ?? '');
    $matchedMeter = null;
    foreach ($meters as $meter) {
        $numberLower = function_exists('mb_strtolower') ? mb_strtolower((string)$meter['number'], 'UTF-8') : strtolower((string)$meter['number']);
        $labelLower = function_exists('mb_strtolower') ? mb_strtolower((string)($meter['label'] ?? ''), 'UTF-8') : strtolower((string)($meter['label'] ?? ''));
        if (($queryLower !== '' && strpos($numberLower, $queryLower) !== false) || ($queryLower !== '' && $labelLower !== '' && strpos($labelLower, $queryLower) !== false)) {
            $selectionValue = (string)$meter['number'];
            $matchedMeter = $meter;
            break;
        }
    }

    $meterSummary = array_map(static function (array $meter): string {
        return $meter['number'] . (!empty($meter['label']) ? ' (' . $meter['label'] . ')' : '');
    }, $meters);

    $suggestionText = (string)($row['full_name'] ?? '') . ' - ' . (string)($row['account_number'] ?? '');
    if ($matchedMeter) {
        $suggestionText .= ' (Matched meter: ' . $matchedMeter['number'] . (!empty($matchedMeter['label']) ? ' - ' . $matchedMeter['label'] : '') . ')';
    } elseif (!empty($meterSummary)) {
        $suggestionText .= ' (Meters: ' . implode(', ', $meterSummary) . ')';
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'account_number' => (string)($row['account_number'] ?? ''),
        'full_name' => (string)($row['full_name'] ?? ''),
        'label' => $suggestionText,
        'value' => $selectionValue,
        'text' => $suggestionText,
        'meter_number' => (string)($row['meter_number'] ?? ''),
        'meter_numbers' => $meterNumbers,
        'meters' => $meters,
        'selection_value' => $selectionValue,
        'suggestion_text' => $suggestionText,
        'matched_meter_number' => $matchedMeter['number'] ?? null,
        'phone_number' => (string)($row['phone_number'] ?? ''),
        'email' => (string)($row['email'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
    ];
}