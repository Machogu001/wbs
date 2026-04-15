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

    $chat = new SupportChat($db);
    $user = $auth->check();
    $isAuthenticated = (bool)$user;

    $ownerId = $isAuthenticated ? (int)$user['id'] : getGuestChatUserId();

    if (!$isAuthenticated) {
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

    $thread = $chat->getOrCreateThreadForUser($ownerId);

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
        'guest_mode' => !$isAuthenticated,
        'messages' => $messages,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
