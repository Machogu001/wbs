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

    if (!$auth->isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only admin can clear conversations']);
        exit;
    }

    $scope = isset($_POST['scope']) ? trim((string)$_POST['scope']) : 'open';
    if (!in_array($scope, ['open', 'closed', 'all'], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid clear scope']);
        exit;
    }

    $chat = new SupportChat($db);
    if ($scope === 'all') {
        $deleted = $chat->clearThreads(null);
    } else {
        $deleted = $chat->clearThreads($scope);
    }

    echo json_encode([
        'success' => true,
        'deleted' => (int)$deleted,
        'message' => 'Conversations cleared: ' . (int)$deleted,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
