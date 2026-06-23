<?php
/**
 * Short URL redirect handler
 * Maps short codes to full URLs with tracking
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ShortUrl.php';

header('Content-Type: application/json');

try {
    $shortCode = $_GET['code'] ?? '';
    
    if (empty($shortCode)) {
        header('HTTP/1.1 400 Bad Request');
        exit(json_encode(['error' => 'Short code is required']));
    }

    // Validate short code format (alphanumeric only)
    if (!preg_match('/^[A-Za-z0-9]+$/', $shortCode)) {
        header('HTTP/1.1 400 Bad Request');
        exit(json_encode(['error' => 'Invalid short code format']));
    }

    $database = new Database();
    $db = $database->getConnection();
    $shortUrl = new ShortUrl($db);

    $fullUrl = $shortUrl->getFullUrl($shortCode);

    if ($fullUrl === null) {
        header('HTTP/1.1 404 Not Found');
        exit(json_encode(['error' => 'Short URL not found']));
    }

    // Return JSON response that JavaScript can handle
    // The redirect will be done by the frontend or by the short_url.php page
    header('HTTP/1.1 200 OK');
    echo json_encode(['redirect_url' => $fullUrl]);
    exit;

} catch (Exception $e) {
    error_log('Short URL redirect error: ' . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    exit(json_encode(['error' => 'Server error']));
}
