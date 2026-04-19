<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/User.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    if(!$db) {
        throw new Exception("Database connection failed");
    }

    $auth = new Auth($db);
    if(!$auth->isLoggedIn() || (!$auth->isAdmin() && !$auth->hasPermission('view_invoicing'))) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Forbidden"]);
        exit;
    }

    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if($q === '') {
        echo json_encode(["status" => "success", "data" => []]);
        exit;
    }

    $userService = new User($db);
    $results = $userService->searchByNameOrAccount($q, 10);

    $data = array_map(function($row) {
        return [
            "id" => $row['id'],
            "account_number" => $row['account_number'],
            "full_name" => $row['full_name'],
            "meter_number" => $row['meter_number']
        ];
    }, $results);

    echo json_encode(["status" => "success", "data" => $data]);
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
