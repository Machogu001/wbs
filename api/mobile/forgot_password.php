<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('POST');
    $db = mobileApiGetDatabase();
    $payload = mobileApiReadJson();

    $identifier = trim((string)($payload['identifier'] ?? ''));
    if ($identifier === '') {
        mobileApiJson(422, 'error', 'Account number, phone or email is required.');
    }

    $userModel = new User($db);
    $userRow = $userModel->getAuthRowByIdentifier($identifier);
    $channelStatus = [
        'sms' => ['attempted' => false, 'sent' => false, 'required' => true],
        'email' => ['attempted' => false, 'sent' => false, 'required' => false],
    ];

    // Keep response generic so account existence is never exposed.
    if ($userRow && !empty($userRow['phone_number'])) {
        $newPassword = bin2hex(random_bytes(4));
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

        $stmt = $db->prepare('UPDATE users SET password_hash = :hash, must_change_password = 1 WHERE id = :id LIMIT 1');
        $stmt->execute([
            ':hash' => $newHash,
            ':id' => (int)$userRow['id'],
        ]);

        $message = "Your new WBS portal password is: {$newPassword}. Please login and change it immediately.";

        $sms = new SMS();
        $channelStatus['sms']['attempted'] = true;
        $smsResult = $sms->send((string)$userRow['phone_number'], $message, 'otp');
        $channelStatus['sms']['sent'] = !empty($smsResult['success']);
        $channelStatus['sms']['message'] = (string)($smsResult['message'] ?? '');

        if (!empty($userRow['email'])) {
            $channelStatus['email']['required'] = true;
            $channelStatus['email']['attempted'] = true;
            $email = new Email();
            $emailResult = $email->send((string)$userRow['email'], 'Your new WBS portal password', $message);
            $channelStatus['email']['sent'] = !empty($emailResult['success']);
            $channelStatus['email']['message'] = (string)($emailResult['message'] ?? '');
        }

        $deliveryOk = $channelStatus['sms']['sent']
            && (!$channelStatus['email']['required'] || $channelStatus['email']['sent']);
        if (!$deliveryOk) {
            mobileApiJson(503, 'error', 'Password reset could not be delivered to all required channels. Please try again shortly.', [
                'channels' => $channelStatus,
            ]);
        }
    }

    mobileApiJson(200, 'success', 'Password reset request received. If your account details match our records, a temporary password has been sent to your registered phone number.', [
        'channels' => $channelStatus,
    ]);
} catch (Throwable $e) {
    mobileApiJson(400, 'error', $e->getMessage());
}

