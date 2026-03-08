<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/SupportChat.php';

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

    // Only admin or support users can delete chat messages
    if (!($auth->isAdmin() || $auth->hasRole('support'))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }

    $input = $_POST;
    $messageId = isset($input['message_id']) ? (int)$input['message_id'] : 0;

    if ($messageId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Message ID is required']);
        exit;
    }

    $chat = new SupportChat($db);
    $ok = $chat->deleteMessage($messageId);

    if (!$ok) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Message not found or could not be deleted']);
        exit;
    }

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
