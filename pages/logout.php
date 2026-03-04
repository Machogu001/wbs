<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/ActivityLog.php';

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
                'User logged out',
                array()
            );
        } catch (Exception $e) {
            // Ignore logging errors
        }
    }
} catch (Exception $e) {
    // Even if something goes wrong, clear session as best as possible
    session_unset();
    session_destroy();
}

// Set a short-lived flash cookie so we can show a toast once
$isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
setcookie('flash_logged_out', '1', time() + 60, '/', '', $isSecure, true);

// Redirect to home (landing page)
header('Location: /');
exit;
