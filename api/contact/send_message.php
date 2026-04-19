<?php

require_once __DIR__ . '/../../config/email_config.php';
require_once __DIR__ . '/../../includes/Email.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$message = trim($_POST['message'] ?? '');

if ($name === '' || $email === '' || $message === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please fill in your name, email and message.']);
    exit;
}

// Basic rate limiting: max 5 submissions per IP in a 10-minute window
session_start();
$_ipKey = 'contact_rate_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
$_rateData = $_SESSION[$_ipKey] ?? ['count' => 0, 'window_start' => time()];
if ((time() - $_rateData['window_start']) > 600) {
    $_rateData = ['count' => 0, 'window_start' => time()];
}
if ($_rateData['count'] >= 5) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again later.']);
    exit;
}
$_rateData['count']++;
$_SESSION[$_ipKey] = $_rateData;
unset($_ipKey, $_rateData);

try {
    $db = null;
    try {
        $database = new Database();
        $db = $database->getConnection();
    } catch (Throwable $ignored) {
        $db = null;
    }

    $savedInquiryId = null;
    if ($db) {
        // Create inbox table on demand so inquiries are not lost even if email service is unavailable.
        $db->exec("CREATE TABLE IF NOT EXISTS support_inquiries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(60) DEFAULT NULL,
            message TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            email_sent TINYINT(1) NOT NULL DEFAULT 0,
            email_error TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_support_inquiries_status (status),
            INDEX idx_support_inquiries_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $insert = $db->prepare("INSERT INTO support_inquiries
            (name, email, phone, message, status, email_sent)
            VALUES (:name, :email, :phone, :message, 'pending', 0)");
        $insert->bindValue(':name', $name);
        $insert->bindValue(':email', $email);
        $insert->bindValue(':phone', $phone !== '' ? $phone : null);
        $insert->bindValue(':message', $message);
        if ($insert->execute()) {
            $savedInquiryId = (int)$db->lastInsertId();
        }
    }

    $toAddress = EmailConfig::getFromAddress();
    $toName = EmailConfig::getFromName();
    $subject = 'New Contact Form Message from ' . $name;
    $body = "You have received a new contact form message from the Water Billing System portal.\n\n" .
        "Name: {$name}\n" .
        "Email: {$email}\n" .
        ($phone !== '' ? "Phone: {$phone}\n" : '') .
        "\nMessage:\n{$message}\n";

    $emailSent = false;
    $emailError = null;
    try {
        $emailClient = new Email();
        $emailSent = (bool)$emailClient->sendMail($toAddress, $toName, $subject, nl2br($body), $email, $name);
        if (!$emailSent) {
            $emailError = 'Mailer returned false';
        }
    } catch (Throwable $mailError) {
        $emailSent = false;
        $emailError = 'Mailer exception';
    }

    if ($db && $savedInquiryId) {
        $update = $db->prepare("UPDATE support_inquiries
            SET status = :status,
                email_sent = :email_sent,
                email_error = :email_error
            WHERE id = :id");
        $update->bindValue(':status', $emailSent ? 'sent' : 'queued');
        $update->bindValue(':email_sent', $emailSent ? 1 : 0, PDO::PARAM_INT);
        $update->bindValue(':email_error', $emailError);
        $update->bindValue(':id', $savedInquiryId, PDO::PARAM_INT);
        $update->execute();
    }

    if ($emailSent || $savedInquiryId) {
        echo json_encode([
            'success' => true,
            'message' => $emailSent
                ? 'Thank you, your inquiry has been sent to support.'
                : 'Thank you, your inquiry has been received. Our team will respond shortly.'
        ]);
        exit;
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not receive your inquiry right now. Please try again shortly.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred while sending your message.']);
}

