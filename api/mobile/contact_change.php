<?php

// Mirrors the phone/email change flow on pages/profile.php: an OTP is sent to the
// current phone number (and current email) and must be confirmed before the contact
// detail is updated. The website keeps the OTP in the session; the app is stateless,
// so the pending request is stored (hashed) per user.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/SMS.php';
require_once __DIR__ . '/../../includes/Email.php';

function mobileContactChangeEnsureTable(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS mobile_contact_change_otps (
        user_id INT NOT NULL,
        change_type VARCHAR(10) NOT NULL,
        new_value VARCHAR(255) NOT NULL,
        code_hash VARCHAR(255) NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, change_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

try {
    mobileApiRequireMethod('POST');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    $data = mobileApiReadJson();
    $userId = (int)$user['id'];
    $action = trim((string)($data['action'] ?? ''));
    mobileContactChangeEnsureTable($db);

    $currentPhone = trim((string)($user['phone_number'] ?? ''));
    $currentEmail = trim((string)($user['email'] ?? ''));

    if ($action === 'request_phone_otp' || $action === 'request_email_otp') {
        $isPhone = $action === 'request_phone_otp';
        $newValue = trim((string)($data[$isPhone ? 'new_phone' : 'new_email'] ?? ''));

        if ($isPhone) {
            if ($newValue === '' || $currentPhone === '') {
                mobileApiJson(422, 'error', 'Please enter a new phone number.');
            }
            if ($newValue === $currentPhone) {
                mobileApiJson(422, 'error', 'New phone number must be different from the current one.');
            }
            if (!preg_match('/^(?:254|\+254|0)?(7\d{8})$/', $newValue)) {
                mobileApiJson(422, 'error', 'Please enter a valid phone number (e.g., 254712345678).');
            }
            if ((new User($db))->phoneExists($newValue)) {
                mobileApiJson(422, 'error', 'That phone number is already registered to another account.');
            }
        } else {
            if ($newValue === '') {
                mobileApiJson(422, 'error', 'Please enter a new email address.');
            }
            if (!filter_var($newValue, FILTER_VALIDATE_EMAIL)) {
                mobileApiJson(422, 'error', 'Please enter a valid email address.');
            }
            if ($newValue === $currentEmail) {
                mobileApiJson(422, 'error', 'New email must be different from the current one.');
            }
            $check = $db->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
            $check->execute([':email' => $newValue, ':id' => $userId]);
            if ($check->fetch(PDO::FETCH_ASSOC)) {
                mobileApiJson(422, 'error', 'That email address is already registered to another account.');
            }
            if ($currentPhone === '') {
                mobileApiJson(422, 'error', 'No phone number on file to send OTP. Please contact support.');
            }
        }

        $otp = (string)random_int(100000, 999999);
        $messageText = $isPhone
            ? "Your OTP to change your phone number is {$otp}. It expires in 5 minutes."
            : "Your OTP to change your email address is {$otp}. It expires in 5 minutes.";
        $smsResult = (new SMS())->send($currentPhone, $messageText, 'otp');
        if (empty($smsResult['success'])) {
            mobileApiJson(502, 'error', 'Failed to send OTP SMS. Please try again.');
        }
        if ($currentEmail !== '') {
            try {
                (new Email())->send($currentEmail, $isPhone ? 'OTP to change your phone number' : 'OTP to change your email address', $messageText);
            } catch (Throwable $mailError) {
                error_log('Mobile contact change OTP email failed: ' . $mailError->getMessage());
            }
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare('REPLACE INTO mobile_contact_change_otps (user_id, change_type, new_value, code_hash, attempts, expires_at, created_at)
            VALUES (:user_id, :change_type, :new_value, :code_hash, 0, :expires_at, :created_at)');
        $stmt->execute([
            ':user_id' => $userId,
            ':change_type' => $isPhone ? 'phone' : 'email',
            ':new_value' => $newValue,
            ':code_hash' => password_hash($otp, PASSWORD_DEFAULT),
            ':expires_at' => date('Y-m-d H:i:s', time() + 300),
            ':created_at' => $now,
        ]);

        mobileApiJson(200, 'success', $isPhone ? 'OTP sent to your current phone number.' : 'OTP sent via SMS to your registered phone number.', [
            'change_type' => $isPhone ? 'phone' : 'email',
            'new_value' => $newValue,
            'expires_in' => 300,
        ]);
    }

    if ($action === 'confirm_phone_change' || $action === 'confirm_email_change') {
        $isPhone = $action === 'confirm_phone_change';
        $type = $isPhone ? 'phone' : 'email';
        $enteredOtp = trim((string)($data['otp'] ?? ''));
        if ($enteredOtp === '') {
            mobileApiJson(422, 'error', $isPhone ? 'Please enter the OTP sent to your current phone number.' : 'Please enter the OTP sent via SMS.');
        }

        $stmt = $db->prepare('SELECT * FROM mobile_contact_change_otps WHERE user_id = :user_id AND change_type = :change_type LIMIT 1');
        $stmt->execute([':user_id' => $userId, ':change_type' => $type]);
        $pending = $stmt->fetch(PDO::FETCH_ASSOC);
        $clear = $db->prepare('DELETE FROM mobile_contact_change_otps WHERE user_id = :user_id AND change_type = :change_type');
        if (!$pending) {
            mobileApiJson(422, 'error', "No active {$type} change request. Please request a new OTP.");
        }
        if (strtotime((string)$pending['expires_at']) < time() || (int)$pending['attempts'] >= 5) {
            $clear->execute([':user_id' => $userId, ':change_type' => $type]);
            mobileApiJson(422, 'error', 'The OTP has expired. Please request a new one.');
        }
        if (!password_verify($enteredOtp, (string)$pending['code_hash'])) {
            $db->prepare('UPDATE mobile_contact_change_otps SET attempts = attempts + 1 WHERE user_id = :user_id AND change_type = :change_type')
                ->execute([':user_id' => $userId, ':change_type' => $type]);
            mobileApiJson(422, 'error', 'Invalid OTP. Please check and try again.');
        }

        $newValue = (string)$pending['new_value'];
        $update = $db->prepare($isPhone ? 'UPDATE users SET phone_number = :value WHERE id = :id' : 'UPDATE users SET email = :value WHERE id = :id');
        $update->execute([':value' => $newValue, ':id' => $userId]);
        $clear->execute([':user_id' => $userId, ':change_type' => $type]);

        $freshUser = (new User($db))->getById($userId);
        mobileApiJson(200, 'success', $isPhone ? 'Phone number updated successfully.' : 'Email address updated successfully.', [
            'user' => mobileApiFormatUser($db, $freshUser ?: $user),
        ]);
    }

    mobileApiJson(422, 'error', 'Unsupported contact change action.');
} catch (Throwable $e) {
    error_log('Mobile API contact change failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to change contact details right now.');
}
