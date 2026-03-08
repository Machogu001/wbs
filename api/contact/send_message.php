<?php

require_once __DIR__ . '/../../config/email_config.php';
require_once __DIR__ . '/../../includes/Email.php';

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

try {
    $toAddress = EmailConfig::getFromAddress();
    $toName = EmailConfig::getFromName();

    $subject = 'New Contact Form Message from ' . $name;

    $body = "You have received a new contact form message from the Water Billing System portal.\n\n" .
        "Name: {$name}\n" .
        "Email: {$email}\n" .
        ($phone !== '' ? "Phone: {$phone}\n" : '') .
        "\nMessage:\n{$message}\n";

    $emailClient = new Email();
    $sent = $emailClient->sendMail($toAddress, $toName, $subject, nl2br($body), $email, $name);

    if ($sent) {
        echo json_encode(['success' => true, 'message' => 'Thank you, your message has been sent to support.']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to send your message. Please try again later.']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred while sending your message.']);
}

