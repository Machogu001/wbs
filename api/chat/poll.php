<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/SupportChat.php';

header('Content-Type: application/json');

function getGuestChatUserId(): int
{
    if (!isset($_SESSION['guest_chat_uid']) || (int)$_SESSION['guest_chat_uid'] >= 0) {
        $seed = session_id();
        if ($seed === '') {
            $seed = bin2hex(random_bytes(8));
        }
        $_SESSION['guest_chat_uid'] = -1 * (abs(crc32($seed)) + 1);
    }
    return (int)$_SESSION['guest_chat_uid'];
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $auth = new Auth($db);

    $threadId = isset($_GET['thread_id']) ? (int)$_GET['thread_id'] : 0;
    $sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : null;

    if ($threadId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Thread is required']);
        exit;
    }

    $chat = new SupportChat($db);
    $user = $auth->check();
    $isStaff = $user && ($auth->isAdmin() || $auth->hasPermission('handle_support'));
    $ownerId = $user ? (int)$user['id'] : getGuestChatUserId();

    if (!$isStaff && !$chat->isThreadOwnedByUser($threadId, $ownerId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }

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
