<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/User.php';
require_once __DIR__ . '/../../../includes/SMS.php';

function mobileApiNextStaffAccountNumber(PDO $db): string
{
    $stmt = $db->query("SELECT account_number FROM users WHERE account_number LIKE 'STF%' ORDER BY id DESC LIMIT 1");
    $last = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    $next = 1;
    if ($last && !empty($last['account_number']) && preg_match('/^STF(\d+)$/', (string)$last['account_number'], $matches)) {
        $next = (int)$matches[1] + 1;
    }
    return 'STF' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

try {
    $db = mobileApiGetDatabase();
    $actor = mobileApiRequireUser($db);
    if (!mobileApiUserHasRole($actor, 'admin')) {
        mobileApiJson(403, 'error', 'Forbidden.');
    }
    $userModel = new User($db);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $stmt = $db->query("SELECT id, account_number, full_name, username, phone_number, id_number, role, status, created_at FROM users WHERE role <> 'customer' ORDER BY created_at DESC, id DESC");
        mobileApiJson(200, 'success', 'Staff users loaded.', [
            'users' => $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [],
            'form_metadata' => [
                'default_country_code' => '254',
                'country_code_options' => mobileApiCountryCodeOptions($db),
                'role_options' => mobileApiStaffRoleOptions(),
                'status_options' => mobileApiStatusOptions(),
            ],
        ]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $formType = trim((string)($data['form_type'] ?? $data['action'] ?? ''));

        if ($formType === 'create_staff') {
            $firstName = trim((string)($data['first_name'] ?? ''));
            $middleName = trim((string)($data['middle_name'] ?? ''));
            $lastName = trim((string)($data['last_name'] ?? ''));
            $username = trim((string)($data['username'] ?? ''));
            $phone = mobileApiNormalizePhone((string)($data['phone_country_code'] ?? '254'), (string)($data['phone_number_local'] ?? ''));
            $idNumber = trim((string)($data['id_number'] ?? ''));
            $role = strtolower(trim((string)($data['role'] ?? 'reader')));
            $password = (string)($data['password'] ?? '');
            $confirmPassword = (string)($data['confirm_password'] ?? '');
            if ($firstName === '' || $lastName === '' || $username === '' || $phone === '' || $idNumber === '') {
                mobileApiJson(422, 'error', 'First name, last name, username, phone number and ID number are required.');
            }
            if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $username)) {
                mobileApiJson(422, 'error', 'Username must be 3-30 characters and contain only letters, numbers, dot, underscore or hyphen.');
            }
            if ($password === '' || strlen($password) < 8 || !hash_equals($password, $confirmPassword)) {
                mobileApiJson(422, 'error', 'Password is required, must be at least 8 characters, and must match the confirmation.');
            }
            $allowedRoles = ['admin', 'reader', 'finance', 'support'];
            if (!in_array($role, $allowedRoles, true)) {
                $role = 'reader';
            }
            if ($userModel->phoneExists($phone)) {
                mobileApiJson(422, 'error', 'Phone number already registered.');
            }
            if ($userModel->usernameExists($username)) {
                mobileApiJson(422, 'error', 'Username already exists.');
            }
            $fullName = trim(preg_replace('/\s+/', ' ', $firstName . ' ' . $middleName . ' ' . $lastName));
            $accountNumber = mobileApiNextStaffAccountNumber($db);
            $stmt = $db->prepare("INSERT INTO users (account_number, username, full_name, phone_number, email, id_number, tax_pin, address, meter_number, connection_type, location_label, latitude, longitude, password_hash, role, status) VALUES (:account_number, :username, :full_name, :phone_number, NULL, :id_number, NULL, NULL, NULL, 'domestic', NULL, NULL, NULL, :password_hash, :role, 'active')");
            $stmt->execute([
                ':account_number' => $accountNumber,
                ':username' => $username,
                ':full_name' => $fullName,
                ':phone_number' => $phone,
                ':id_number' => $idNumber,
                ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
                ':role' => $role,
            ]);
            try {
                (new SMS($db))->queue($phone, "Hello {$fullName}, your staff account has been created.\nAccount No: {$accountNumber}\nUsername: {$username}\nPassword: {$password}\nRole: " . ucfirst($role) . "\nPlease log in and change your password immediately.", 'account_creation');
            } catch (Throwable $e) {
                error_log('Staff account SMS failed: ' . $e->getMessage());
            }
            mobileApiJson(201, 'success', 'Staff user created successfully.', ['account_number' => $accountNumber, 'username' => $username]);
        }

        if ($formType === 'edit_staff') {
            $userId = (int)($data['user_id'] ?? 0);
            $fullName = trim((string)($data['full_name'] ?? ''));
            $username = trim((string)($data['username'] ?? ''));
            $phone = mobileApiNormalizePhone((string)($data['phone_country_code'] ?? '254'), (string)($data['phone_number_local'] ?? ''));
            $idNumber = trim((string)($data['id_number'] ?? ''));
            $role = strtolower(trim((string)($data['role'] ?? 'reader')));
            $status = trim((string)($data['status'] ?? 'active'));
            if ($userId <= 0 || $fullName === '' || $username === '' || $phone === '' || $idNumber === '') {
                mobileApiJson(422, 'error', 'Full name, username, phone and ID number are required.');
            }
            $allowedRoles = ['admin', 'reader', 'finance', 'support'];
            $allowedStatuses = ['active', 'inactive', 'suspended'];
            if (!in_array($role, $allowedRoles, true)) {
                $role = 'reader';
            }
            if (!in_array($status, $allowedStatuses, true)) {
                $status = 'active';
            }
            $chk = $db->prepare('SELECT id FROM users WHERE username = :username AND id <> :id LIMIT 1');
            $chk->execute([':username' => $username, ':id' => $userId]);
            if ($chk->fetch(PDO::FETCH_ASSOC)) {
                mobileApiJson(422, 'error', 'Username already in use by another user.');
            }
            $chk = $db->prepare('SELECT id FROM users WHERE phone_number = :phone AND id <> :id LIMIT 1');
            $chk->execute([':phone' => $phone, ':id' => $userId]);
            if ($chk->fetch(PDO::FETCH_ASSOC)) {
                mobileApiJson(422, 'error', 'Phone number already in use by another user.');
            }
            $stmt = $db->prepare("UPDATE users SET full_name = :full_name, username = :username, phone_number = :phone, id_number = :id_number, role = :role, status = :status WHERE id = :id AND role <> 'customer'");
            $stmt->execute([':full_name' => $fullName, ':username' => $username, ':phone' => $phone, ':id_number' => $idNumber, ':role' => $role, ':status' => $status, ':id' => $userId]);
            mobileApiJson(200, 'success', 'Staff user updated successfully.');
        }

        if ($formType === 'reset_password') {
            $userId = (int)($data['user_id'] ?? 0);
            $newPassword = (string)($data['new_password'] ?? '');
            $confirmPassword = (string)($data['confirm_password'] ?? '');
            if ($userId <= 0 || $newPassword === '' || strlen($newPassword) < 8 || !hash_equals($newPassword, $confirmPassword)) {
                mobileApiJson(422, 'error', 'A valid staff user and matching passwords are required.');
            }
            $staffRow = $db->prepare("SELECT id, full_name, phone_number FROM users WHERE id = :id AND role <> 'customer' LIMIT 1");
            $staffRow->execute([':id' => $userId]);
            $staffData = $staffRow->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$staffData) {
                mobileApiJson(404, 'error', 'Staff user not found.');
            }
            $upd = $db->prepare("UPDATE users SET password_hash = :hash, must_change_password = 1 WHERE id = :id AND role <> 'customer'");
            $upd->execute([':hash' => password_hash($newPassword, PASSWORD_BCRYPT), ':id' => $userId]);
            try {
                (new SMS($db))->queue((string)$staffData['phone_number'], "Hello {$staffData['full_name']}, your account password has been reset by an administrator.\nNew Password: {$newPassword}\nPlease log in and change your password immediately.", 'password_reset');
            } catch (Throwable $e) {
                error_log('Reset password SMS failed: ' . $e->getMessage());
            }
            mobileApiJson(200, 'success', 'Password reset successfully.');
        }

        if ($formType === 'update_status') {
            $userId = (int)($data['user_id'] ?? 0);
            $newStatus = trim((string)($data['new_status'] ?? ''));
            if ($userId <= 0 || !in_array($newStatus, ['active', 'inactive', 'suspended'], true)) {
                mobileApiJson(422, 'error', 'Invalid staff user or status.');
            }
            $stmt = $db->prepare("UPDATE users SET status = :status WHERE id = :id AND role <> 'customer'");
            $stmt->execute([':status' => $newStatus, ':id' => $userId]);
            mobileApiJson(200, 'success', 'Staff status updated.');
        }

        if ($formType === 'delete_user') {
            $userId = (int)($data['user_id'] ?? 0);
            if ($userId <= 0) {
                mobileApiJson(422, 'error', 'Invalid staff user.');
            }
            if ((int)$actor['id'] === $userId) {
                mobileApiJson(422, 'error', 'You cannot delete your own account.');
            }
            $stmt = $db->prepare("DELETE FROM users WHERE id = :id AND role <> 'customer'");
            $stmt->execute([':id' => $userId]);
            mobileApiJson(200, 'success', 'Staff user deleted successfully.');
        }

        mobileApiJson(422, 'error', 'Unsupported staff user action.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin staff users failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process staff users right now.');
}