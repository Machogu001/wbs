<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/SupportChat.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

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

        $currentUserId = (int)($auth->getUserId() ?? 0);
        if ($currentUserId <= 0) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid session']);
            exit;
        }

        $available = isset($_POST['available']) ? (int)$_POST['available'] === 1 : false;
        $ok = $chat->setAgentAvailability($currentUserId, $available);
        if (!$ok) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not update availability']);
            exit;
        }
    }

    $agents = $chat->getAvailableAgents();
    $recentAgents = $chat->getLastSeenAgents(5);
    $agentPayload = [];
    $recentAgentPayload = [];
    $names = [];
    foreach ($agents as $agent) {
        $fullName = trim((string)($agent['full_name'] ?? ''));
        $name = $fullName !== '' ? $fullName : ('Support #' . (int)($agent['user_id'] ?? 0));
        $agentPayload[] = [
            'id' => (int)($agent['user_id'] ?? 0),
            'name' => $name,
            'role' => (string)($agent['role'] ?? 'support'),
            'is_available' => true,
            'updated_at' => (string)($agent['updated_at'] ?? ''),
        ];
        $names[] = $name;
    }

    foreach ($recentAgents as $agent) {
        $fullName = trim((string)($agent['full_name'] ?? ''));
        $name = $fullName !== '' ? $fullName : ('Support #' . (int)($agent['user_id'] ?? 0));
        $recentAgentPayload[] = [
            'id' => (int)($agent['user_id'] ?? 0),
            'name' => $name,
            'role' => (string)($agent['role'] ?? 'support'),
            'is_available' => (int)($agent['is_available'] ?? 0) === 1,
            'updated_at' => (string)($agent['updated_at'] ?? ''),
        ];
    }

    $currentUserAvailable = false;
    $user = $auth->check();
    if ($user && ($auth->isAdmin() || $auth->hasRole('support'))) {
        $currentUserId = (int)($auth->getUserId() ?? 0);
        if ($currentUserId > 0) {
            $currentUserAvailable = $chat->isAgentAvailable($currentUserId);
        }
    }

    echo json_encode([
        'success' => true,
        'available' => count($agentPayload) > 0,
        'available_count' => count($agentPayload),
        'available_names' => $names,
        'agents' => $agentPayload,
        'recent_agents' => $recentAgentPayload,
        'current_user_available' => $currentUserAvailable,
        'message' => (count($agentPayload) > 0)
            ? 'Support team availability loaded.'
            : 'No support team members are currently visible as available.',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
