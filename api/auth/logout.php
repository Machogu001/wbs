<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

session_start();

try {
    $database = new Database();
    $db = $database->getConnection();

    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    $auth = new Auth($db);
    $auth->logout();

    if ($db && $userId) {
        try {
            $logger = new ActivityLog($db);
            $logger->log(
                $userId,
                'logout',
                'user',
                $userId,
                'User logged out (API)',
                array()
            );
        } catch (Exception $e) {
            // Ignore logging errors
        }
    }
    
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
