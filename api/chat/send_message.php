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

    $input = $_POST;
    $threadId = isset($input['thread_id']) ? (int)$input['thread_id'] : 0;
    $message = isset($input['message']) ? trim($input['message']) : '';

    if ($threadId <= 0 || $message === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Thread and message are required']);
        exit;
    }

    // Decide sender type based on role: admin/support messages should be marked as 'admin'
    $senderType = ($auth->isAdmin() || $auth->hasRole('support')) ? 'admin' : 'user';

    $chat = new SupportChat($db);
    $ok = $chat->addMessage($threadId, $senderType, (int)$user['id'], $message);

    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not send message']);
        exit;
    }

    // Try to return the newly created message so clients can render it with the correct ID and timestamp
    $newMessage = null;
    try {
        $lastId = (int)$db->lastInsertId();
        if ($lastId > 0) {
            $newMessage = $chat->getMessageById($lastId);
        }
    } catch (Throwable $e) {
        // ignore, fallback to success only
    }

    echo json_encode([
        'success' => true,
        'message_data' => $newMessage,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
