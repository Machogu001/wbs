<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $themePreference = mobileApiNormalizeThemePreference($user['theme_preference'] ?? 'system');
        mobileApiJson(200, 'success', 'Theme preference loaded.', [
            'theme_preference' => $themePreference,
            'follow_device_theme' => $themePreference === 'system',
            'available_preferences' => ['system', 'light', 'dark'],
        ]);
    }

    mobileApiRequireMethod('POST');
    $payload = mobileApiReadJson();
    $themePreference = mobileApiNormalizeThemePreference($payload['theme_preference'] ?? 'system');

    $update = $db->prepare('UPDATE users SET theme_preference = :theme_preference WHERE id = :id');
    $update->execute([
        ':theme_preference' => $themePreference,
        ':id' => (int)$user['id'],
    ]);

    mobileApiJson(200, 'success', 'Theme preference updated successfully.', [
        'theme_preference' => $themePreference,
        'follow_device_theme' => $themePreference === 'system',
        'available_preferences' => ['system', 'light', 'dark'],
    ]);
} catch (InvalidArgumentException $e) {
    mobileApiJson(422, 'error', $e->getMessage());
} catch (Throwable $e) {
    error_log('Mobile API theme preference failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to update theme preference right now.');
}