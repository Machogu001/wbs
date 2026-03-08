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

    $threadId = isset($_POST['thread_id']) ? (int)$_POST['thread_id'] : 0;
    $isTyping = isset($_POST['is_typing']) ? (int)$_POST['is_typing'] === 1 : false;

    if ($threadId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Thread is required']);
        exit;
    }

    $actor = 'user';
    if ($auth->isAdmin() || $auth->hasRole('support')) {
        $actor = 'admin';
    }

    $chat = new SupportChat($db);
    $chat->setTyping($threadId, $actor, $isTyping);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
