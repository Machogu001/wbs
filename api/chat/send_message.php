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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $auth = new Auth($db);

    $input = $_POST;
    $threadId = isset($input['thread_id']) ? (int)$input['thread_id'] : 0;
    $message = isset($input['message']) ? trim($input['message']) : '';

    if ($threadId <= 0 || $message === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Thread and message are required']);
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

    if (!$user) {
        $availableAgents = $chat->getAvailableAgents();
        if (count($availableAgents) === 0) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Support team is currently offline. Please leave an inquiry message.',
                'offline' => true,
            ]);
            exit;
        }
    }

    // Decide sender type based on role: admin/support messages should be marked as 'admin'
    $senderType = $isStaff ? 'admin' : 'user';
    $senderId = $isStaff ? (int)$ownerId : null;

    $ok = $chat->addMessage($threadId, $senderType, $senderId, $message);

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
