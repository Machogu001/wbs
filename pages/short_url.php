<?php
/**
 * Short URL Redirect Page
 * Handles /s/{code} pattern
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ShortUrl.php';

try {
    // Extract short code from query parameter or URL path
    $shortCode = $_GET['code'] ?? null;

    if (empty($shortCode)) {
        // Try to extract from URL path as fallback
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $shortCode = trim(str_replace('/s/', '', $requestUri), '/');
    }

    if (empty($shortCode) || !preg_match('/^[A-Za-z0-9]+$/', $shortCode)) {
        http_response_code(400);
        die('Invalid short code');
    }

    $database = new Database();
    $db = $database->getConnection();
    $shortUrl = new ShortUrl($db);

    $fullUrl = $shortUrl->getFullUrl($shortCode);

    if ($fullUrl === null) {
        http_response_code(404);
        die('Short URL not found');
    }

    // Redirect to full URL
    header('Location: ' . $fullUrl, true, 301);
    exit;

} catch (Exception $e) {
    error_log('Short URL redirect error: ' . $e->getMessage());
    http_response_code(500);
    die('Server error');
}

