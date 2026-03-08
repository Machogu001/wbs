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

    $threadId = isset($_GET['thread_id']) ? (int)$_GET['thread_id'] : 0;
    $sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : null;

    if ($threadId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Thread is required']);
        exit;
    }

    $chat = new SupportChat($db);
    $messages = $chat->getMessages($threadId, $sinceId);
    $typing = $chat->getTypingStatus($threadId);

    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'typing' => $typing,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
