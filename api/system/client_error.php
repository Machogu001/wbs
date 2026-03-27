<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/ErrorLog.php';

try {
    $raw = file_get_contents('php://input');
    $data = json_decode((string)$raw, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid payload']);
        exit;
    }

    $message = trim((string)($data['message'] ?? ''));
    if ($message === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing message']);
        exit;
    }

    $source = trim((string)($data['source'] ?? ''));

    // Ignore known browser extension noise.
    $ignoreSources = ['chrome-extension://', 'moz-extension://', 'safari-extension://', 'extensions::'];
    foreach ($ignoreSources as $prefix) {
        if ($source !== '' && strpos($source, $prefix) === 0) {
            echo json_encode(['status' => 'ok', 'ignored' => true]);
            exit;
        }
    }
    if (stripos($source, 'CloseDisplay.js') !== false || stripos($message, 'CloseDisplay.js') !== false) {
        echo json_encode(['status' => 'ok', 'ignored' => true]);
        exit;
    }

    $message = substr($message, 0, 500);
    $source = substr($source, 0, 300);
    $line = (int)($data['line'] ?? 0);
    $column = (int)($data['column'] ?? 0);

    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new RuntimeException('Database unavailable');
    }

    ErrorLog::ensureTable($db);
    $logger = new ErrorLog($db);

    $context = [
        'type' => substr((string)($data['type'] ?? 'error'), 0, 50),
        'page' => substr((string)($data['page'] ?? ''), 0, 300),
        'column' => $column,
        'stack' => substr((string)($data['stack'] ?? ''), 0, 3000),
        'user_agent' => substr((string)($data['userAgent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 600),
        'client_ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
    ];

    $logger->logSystemError('browser_js', $message, $source !== '' ? $source : null, $line > 0 ? $line : null, $context);

    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    // Never break the UI due to logging failures.
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'logged' => false]);
}
