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
    $chat = new SupportChat($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $user = $auth->check();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Not authenticated']);
            exit;
        }

        if (!($auth->isAdmin() || $auth->hasRole('support'))) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden']);
            exit;
        }

        $available = isset($_POST['available']) ? (int)$_POST['available'] === 1 : false;
        $ok = $chat->setAgentAvailability((int)$user['id'], $available);
        if (!$ok) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not update availability']);
            exit;
        }
    }

    $agents = $chat->getAvailableAgents();
    $agentPayload = [];
    $names = [];
    foreach ($agents as $agent) {
        $fullName = trim((string)($agent['full_name'] ?? ''));
        $name = $fullName !== '' ? $fullName : ('Support #' . (int)($agent['user_id'] ?? 0));
        $agentPayload[] = [
            'id' => (int)($agent['user_id'] ?? 0),
            'name' => $name,
            'role' => (string)($agent['role'] ?? 'support'),
            'updated_at' => (string)($agent['updated_at'] ?? ''),
        ];
        $names[] = $name;
    }

    $currentUserAvailable = false;
    $user = $auth->check();
    if ($user && ($auth->isAdmin() || $auth->hasRole('support'))) {
        $currentUserAvailable = $chat->isAgentAvailable((int)$user['id']);
    }

    echo json_encode([
        'success' => true,
        'available' => count($agentPayload) > 0,
        'available_count' => count($agentPayload),
        'available_names' => $names,
        'agents' => $agentPayload,
        'current_user_available' => $currentUserAvailable,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
