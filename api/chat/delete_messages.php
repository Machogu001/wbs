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

    // Only admin users can clear/delete chat messages
    if (!$auth->isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only admin can delete messages']);
        exit;
    }

    $ids = [];
    if (isset($_POST['message_ids'])) {
        if (is_array($_POST['message_ids'])) {
            foreach ($_POST['message_ids'] as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        } else {
            $parts = explode(',', (string)$_POST['message_ids']);
            foreach ($parts as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
    }

    $ids = array_values(array_unique($ids));

    if (empty($ids)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'No messages selected']);
        exit;
    }

    $chat = new SupportChat($db);
    $deleted = 0;
    foreach ($ids as $id) {
        if ($chat->deleteMessage($id)) {
            $deleted++;
        }
    }

    if ($deleted === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Messages could not be deleted']);
        exit;
    }

    echo json_encode(['success' => true, 'deleted' => $deleted]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
