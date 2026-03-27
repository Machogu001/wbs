<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/InternalComms.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $auth = new Auth($db);

    $user = $auth->check();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }

    if (!$auth->hasRole(['admin', 'reader', 'finance', 'support'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    $message = trim((string)($_POST['message'] ?? ''));
    if ($message === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Message is required']);
        exit;
    }

    $comms = new InternalComms($db);
    $senderId = (int)($auth->getUserId() ?? 0);
    $ok = $comms->addInternalMessage($senderId, $message);

    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not send message']);
        exit;
    }

    $latest = $comms->getInternalMessages(null, 1);
    $messageData = !empty($latest) ? $latest[0] : null;

    echo json_encode([
        'success' => true,
        'message_data' => $messageData,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
