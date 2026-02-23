<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';

session_start();

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $auth = new Auth($db);
    $auth->logout();
    
    http_response_code(200);
    echo json_encode(array(
        "status" => "success",
        "message" => "Logged out successfully"
    ));
    
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(array(
        "status" => "error",
        "message" => $e->getMessage()
    ));
}
?>
