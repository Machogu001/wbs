<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/SupportChat.php';

header('Content-Type: application/json');

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

    $chat = new SupportChat($db);
    $thread = $chat->getOrCreateThreadForUser((int)$user['id']);

    if (!$thread) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to start chat']);
        exit;
    }

    $messages = $chat->getMessages((int)$thread['id']);

    echo json_encode([
        'success' => true,
        'thread' => [
            'id' => (int)$thread['id'],
            'status' => $thread['status'],
        ],
        'messages' => $messages,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
