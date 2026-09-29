<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';

function mobileApiJsonError(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

function mobileApiSaveEnvValue(string $envPath, string $key, string $value): void {
    if (!is_file($envPath)) {
        throw new RuntimeException('.env file not found at ' . $envPath);
    }

    if (!is_readable($envPath) || !is_writable($envPath)) {
        $currentUser = 'unknown';
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());
            if (is_array($user) && !empty($user['name'])) {
                $currentUser = (string)$user['name'];
            }
        }
        throw new RuntimeException('.env file is not readable/writable by web user ' . $currentUser . '. Update ownership/permissions, or copy manually.');
    }

    $content = file_get_contents($envPath);
    if ($content === false) {
        throw new RuntimeException('Unable to read .env file');
    }

    $newLine = $key . '=' . $value;
    $pattern = '/^' . preg_quote($key, '/') . '\s*=.*$/m';
    if (preg_match($pattern, $content)) {
        $updated = preg_replace($pattern, $newLine, $content, 1);
    } else {
        $separator = (strlen($content) > 0 && substr($content, -1) !== "\n") ? "\n" : '';
        $updated = $content . $separator . $newLine . "\n";
    }

    if ($updated === null || file_put_contents($envPath, $updated, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write .env file');
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        mobileApiJsonError('Method not allowed', 405);
    }

    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new RuntimeException('Database connection failed');
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $auth = new Auth($db);
    if (!$auth->isLoggedIn() || (!$auth->isAdmin() && !$auth->hasPermission('manage_settings'))) {
        mobileApiJsonError('Forbidden', 403);
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $saveToEnv = !empty($data['save_to_env']);
    $providedKey = isset($data['api_key']) ? trim((string)$data['api_key']) : '';
    if ($providedKey !== '' && !preg_match('/^[A-Za-z0-9_-]{32,128}$/', $providedKey)) {
        mobileApiJsonError('API key must be 32 to 128 characters using letters, numbers, underscore, or hyphen.');
    }

    $apiKey = $providedKey !== '' ? $providedKey : rtrim(strtr(base64_encode(random_bytes(33)), '+/', '-_'), '=');
    $settingsService = new BillingSettings($db);

    $savedToEnv = false;
    $savedToDatabase = false;
    $saveWarning = '';
    if ($saveToEnv) {
        $envPath = dirname(__DIR__, 2) . '/.env';
        try {
            mobileApiSaveEnvValue($envPath, 'MOBILE_API_KEY', $apiKey);
            $savedToEnv = true;
        } catch (RuntimeException $e) {
            $saveWarning = $e->getMessage();
            $savedToDatabase = $settingsService->updateMobileApiKey($apiKey);
        }
    } else {
        $savedToDatabase = $settingsService->updateMobileApiKey($apiKey);
    }

    echo json_encode([
        'status' => 'success',
        'message' => $savedToEnv
            ? 'Mobile API key generated and saved to .env.'
            : ($savedToDatabase
                ? ($saveToEnv
                    ? 'Mobile API key generated and saved in system settings because the web user cannot write .env.'
                    : 'Mobile API key generated and saved in system settings.')
                : ($saveToEnv ? 'Mobile API key generated. Copy it into .env manually because the web user cannot write that file.' : 'Mobile API key generated successfully.')),
        'data' => [
            'api_key' => $apiKey,
            'saved_to_env' => $savedToEnv,
            'saved_to_database' => $savedToDatabase,
            'save_warning' => $saveWarning,
        ],
    ]);
} catch (RuntimeException $e) {
    error_log('generate_mobile_api_key runtime error: ' . $e->getMessage());
    mobileApiJsonError($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('generate_mobile_api_key error: ' . $e->getMessage());
    mobileApiJsonError('Server error while generating mobile API key.', 500);
}