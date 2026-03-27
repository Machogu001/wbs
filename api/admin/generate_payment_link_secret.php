<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';

function jsonError(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

function saveEnvValue(string $envPath, string $key, string $value): void {
    if (!is_file($envPath)) {
        throw new RuntimeException('.env file not found at ' . $envPath);
    }

    if (!is_readable($envPath) || !is_writable($envPath)) {
        $currentUser = 'unknown';
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $u = posix_getpwuid(posix_geteuid());
            if (is_array($u) && !empty($u['name'])) {
                $currentUser = (string)$u['name'];
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
        jsonError('Method not allowed', 405);
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
    if (!$auth->isLoggedIn() || !$auth->hasPermission('manage_settings')) {
        jsonError('Forbidden', 403);
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $saveToEnv = !empty($data['save_to_env']);
    $providedSecret = isset($data['secret']) ? trim((string)$data['secret']) : '';

    if ($providedSecret !== '' && !preg_match('/^[a-fA-F0-9]{64}$/', $providedSecret)) {
        jsonError('Secret must be a 64-character hexadecimal string.');
    }

    $secret = $providedSecret !== '' ? strtolower($providedSecret) : bin2hex(random_bytes(32));

    if ($saveToEnv) {
        $envPath = dirname(__DIR__, 2) . '/.env';
        saveEnvValue($envPath, 'PAYMENT_LINK_SECRET', $secret);
    }

    echo json_encode([
        'status' => 'success',
        'message' => $saveToEnv ? 'Secret generated and saved to .env.' : 'Secret generated successfully.',
        'data' => [
            'secret' => $secret,
            'saved_to_env' => $saveToEnv,
        ],
    ]);
} catch (RuntimeException $e) {
    error_log('generate_payment_link_secret runtime error: ' . $e->getMessage());
    jsonError($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('generate_payment_link_secret error: ' . $e->getMessage());
    jsonError('Server error while generating secret.', 500);
}
