<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Auth.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $auth = new Auth($db);
    $auth->logout();
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
