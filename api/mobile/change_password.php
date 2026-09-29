<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('POST');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    $data = mobileApiReadJson();

    $currentPassword = (string)($data['current_password'] ?? '');
    $newPassword = (string)($data['new_password'] ?? '');
    $confirmPassword = (string)($data['confirm_password'] ?? '');

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        mobileApiJson(422, 'error', 'Current password, new password, and confirmation are required.');
    }
    if ($newPassword !== $confirmPassword) {
        mobileApiJson(422, 'error', 'New password and confirmation do not match.');
    }
    if (strlen($newPassword) < 6) {
        mobileApiJson(422, 'error', 'New password must be at least 6 characters long.');
    }

    $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int)$user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row || empty($row['password_hash']) || !password_verify($currentPassword, (string)$row['password_hash'])) {
        mobileApiJson(422, 'error', 'Current password is incorrect.');
    }

    $stmt = $db->prepare('UPDATE users SET password_hash = :password_hash, must_change_password = 0 WHERE id = :id');
    $stmt->execute([
        ':password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
        ':id' => (int)$user['id'],
    ]);

    mobileApiJson(200, 'success', 'Password changed successfully.');
} catch (Throwable $e) {
    error_log('Mobile API change password failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to change password right now.');
}