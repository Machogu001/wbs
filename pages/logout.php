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

// Redirect to login with a flag so we can show a sweet toast
header('Location: /login?logged_out=true');
exit;
