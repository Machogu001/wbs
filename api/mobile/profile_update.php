<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('POST');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    $data = mobileApiReadJson();

    $fullName = trim((string)($data['full_name'] ?? ($user['full_name'] ?? '')));
    $phoneNumber = trim((string)($data['phone_number'] ?? ($user['phone_number'] ?? '')));
    $email = trim((string)($data['email'] ?? ($user['email'] ?? '')));
    $address = trim((string)($data['address'] ?? ($user['address'] ?? '')));
    $taxPin = trim((string)($data['tax_pin'] ?? ($user['tax_pin'] ?? '')));
    $twoFactorEnabled = !empty($data['two_factor_enabled']) ? 1 : 0;
    $twoFactorMethod = trim((string)($data['two_factor_method'] ?? ($user['two_factor_method'] ?? 'sms')));
    $themePreference = mobileApiNormalizeThemePreference($data['theme_preference'] ?? ($user['theme_preference'] ?? 'system'));
    if ($twoFactorMethod !== 'email') {
        $twoFactorMethod = 'sms';
    }

    if ($fullName === '') {
        mobileApiJson(422, 'error', 'Full name is required.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        mobileApiJson(422, 'error', 'A valid email address is required.');
    }
    if ($phoneNumber !== '' && !preg_match('/^(?:254|\+254|0)?(7\d{8})$/', $phoneNumber)) {
        mobileApiJson(422, 'error', 'Please enter a valid phone number.');
    }
    if ($twoFactorEnabled && $twoFactorMethod === 'sms' && $phoneNumber === '') {
        mobileApiJson(422, 'error', 'A phone number is required before enabling SMS two-factor verification.');
    }
    if ($twoFactorEnabled && $twoFactorMethod === 'email' && $email === '') {
        mobileApiJson(422, 'error', 'An email address is required before enabling email two-factor verification.');
    }

    $stmt = $db->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
    $stmt->execute([
        ':email' => $email,
        ':id' => (int)$user['id'],
    ]);
    if ($email !== '' && $stmt->fetch(PDO::FETCH_ASSOC)) {
        mobileApiJson(422, 'error', 'That email address is already in use by another account.');
    }

    $stmt = $db->prepare('SELECT id FROM users WHERE phone_number = :phone_number AND id <> :id LIMIT 1');
    $stmt->execute([
        ':phone_number' => $phoneNumber,
        ':id' => (int)$user['id'],
    ]);
    if ($phoneNumber !== '' && $stmt->fetch(PDO::FETCH_ASSOC)) {
        mobileApiJson(422, 'error', 'That phone number is already in use by another account.');
    }

    $update = $db->prepare('UPDATE users
        SET full_name = :full_name,
            phone_number = :phone_number,
            email = :email,
            address = :address,
            tax_pin = :tax_pin,
            two_factor_enabled = :two_factor_enabled,
            two_factor_method = :two_factor_method,
            theme_preference = :theme_preference
        WHERE id = :id');
    $update->execute([
        ':full_name' => $fullName,
        ':phone_number' => $phoneNumber !== '' ? $phoneNumber : null,
        ':email' => $email !== '' ? $email : null,
        ':address' => $address !== '' ? $address : null,
        ':tax_pin' => $taxPin !== '' ? $taxPin : null,
        ':two_factor_enabled' => $twoFactorEnabled,
        ':two_factor_method' => $twoFactorMethod,
        ':theme_preference' => $themePreference,
        ':id' => (int)$user['id'],
    ]);

    $userService = new User($db);
    $freshUser = $userService->getById((int)$user['id']);
    mobileApiJson(200, 'success', 'Profile updated successfully.', [
        'user' => mobileApiFormatUser($db, $freshUser ?: $user),
    ]);
} catch (Throwable $e) {
    error_log('Mobile API profile update failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to update profile right now.');
}