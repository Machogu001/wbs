<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/SMS.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        throw new Exception('Database connection failed');
    }

    $data = json_decode(file_get_contents('php://input'));
    if (!$data || empty($data->identifier)) {
        throw new Exception('Account number, phone or email is required');
    }

    $identifier = trim($data->identifier);

    $userModel = new User($db);
    $userRow = $userModel->getAuthRowByIdentifier($identifier);

    // For security, do not reveal whether the account exists in the response
    if ($userRow && !empty($userRow['phone_number'])) {
        // Generate a new temporary password
        $newPassword = bin2hex(random_bytes(4)); // 8 hex characters
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

        // Update password in database and mark that user must change it on next login
        $stmt = $db->prepare('UPDATE users SET password_hash = :hash, must_change_password = 1 WHERE id = :id LIMIT 1');
        $stmt->bindParam(':hash', $newHash);
        $stmt->bindParam(':id', $userRow['id']);
        $stmt->execute();

        // Send SMS with the new password
        $sms = new SMS();
        $message = "Your new WBS portal password is: {$newPassword}. Please login and change it immediately.";
        $smsResult = $sms->send($userRow['phone_number'], $message, 'otp');

        // Also send an email if the user has an email address
        if (!empty($userRow['email'])) {
            require_once __DIR__ . '/../../includes/Email.php';
            $email = new Email();
            $email->send(
                $userRow['email'],
                'Your new WBS portal password',
                $message
            );
        }

        // We intentionally ignore detailed SMS/Email errors in the public message
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'If the account exists, a new password has been sent to the registered phone number.'
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

