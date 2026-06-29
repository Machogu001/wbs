<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/SMS.php';
require_once __DIR__ . '/../../includes/Email.php';
require_once __DIR__ . '/../../includes/PaymentLink.php';
require_once __DIR__ . '/../../includes/ShortUrl.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

header('Content-Type: application/json');

try {
	$database = new Database();
	$db = $database->getConnection();

	$auth = new Auth($db);
	if (!$auth->isLoggedIn() || (!$auth->isAdmin() && !$auth->hasPermission('view_invoicing'))) {
		http_response_code(403);
		echo json_encode(['success' => false, 'message' => 'Unauthorized']);
		exit;
	}

	$bill_id = isset($_POST['bill_id']) ? (int)$_POST['bill_id'] : 0;
	if ($bill_id <= 0) {
		http_response_code(400);
		echo json_encode(['success' => false, 'message' => 'Invalid bill ID']);
		exit;
	}

	// Get bill details
	$billService = new Bill($db);
	$bill = $billService->getById($bill_id);

	if (!$bill) {
		http_response_code(404);
		echo json_encode(['success' => false, 'message' => 'Bill not found']);
		exit;
	}

	// Only allow reminders for pending/overdue bills
	if (!in_array((string)$bill['status'], ['pending', 'overdue'], true)) {
		http_response_code(400);
		echo json_encode(['success' => false, 'message' => 'Payment reminders can only be sent for pending or overdue bills']);
		exit;
	}

	// Get user details
	$stmt = $db->prepare("SELECT id, full_name, phone_number, email, account_number FROM users WHERE id = ? LIMIT 1");
	$stmt->execute([(int)$bill['user_id']]);
	$user = $stmt->fetch(PDO::FETCH_ASSOC);

	if (!$user) {
		http_response_code(404);
		echo json_encode(['success' => false, 'message' => 'User not found']);
		exit;
	}

	$currency = 'KES';
	try {
		$settingsStmt = $db->prepare("SELECT currency_code FROM system_settings LIMIT 1");
		$settingsStmt->execute();
		$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
		if ($settings && !empty($settings['currency_code'])) {
			$currency = (string)$settings['currency_code'];
		}
	} catch (\Throwable $e) {
		// Use default currency
	}

	// Prepare reminder message
	$billingMonth = date('M Y', strtotime($bill['billing_month']));
	$amount = number_format((float)$bill['amount'], 2);
	$dueDate = date('d/m/Y', strtotime($bill['due_date']));
	$isOverdue = strtotime((string)$bill['due_date']) < strtotime(date('Y-m-d'));

	// Generate payment link
	$paymentLink = '';
	$shortPaymentLink = '';
	try {
		$fullPaymentLink = PaymentLink::generateLink((int)$bill_id);
		$shortUrl = new ShortUrl($db);
		$shortPaymentLink = $shortUrl->shortenUrl($fullPaymentLink, (int)$bill_id);
		$paymentLink = $shortPaymentLink; // Use short link for SMS
	} catch (\Throwable $e) {
		// If payment link generation fails, continue without it
		error_log('Payment link generation failed: ' . $e->getMessage());
	}

	$clientName = !empty($user['full_name']) ? (string)$user['full_name'] : 'Customer';
	$smsText = $isOverdue
		? "Dear {$clientName}, this is a reminder that your {$billingMonth} water bill for Account {$user['account_number']} amounting to {$currency} {$amount} was due on {$dueDate} and is now overdue. Kindly settle the bill as soon as possible to avoid service interruption."
		: "Dear {$clientName}, this is a reminder that your {$billingMonth} water bill for Account {$user['account_number']} amounting to {$currency} {$amount} is due on {$dueDate}. Kindly settle the bill on or before the due date to avoid service interruption.";
	if (!empty($paymentLink)) {
		$smsText .= " Pay here: {$paymentLink}";
	}

	$emailSubject = "Payment Reminder - {$billingMonth} Water Bill";
	$emailIntro = $isOverdue
		? "This is a reminder that your {$billingMonth} water bill is overdue for payment.\n\n"
		: "This is a reminder that your {$billingMonth} water bill is due for payment.\n\n";
	$emailActionText = $isOverdue
		? "Kindly settle the bill as soon as possible to avoid service interruption.\n\n"
		: "Kindly settle the bill on or before the due date to avoid service interruption.\n\n";
	$emailText = "Dear {$user['full_name']},\n\n" .
		$emailIntro .
		"Billing Details:\n" .
		"Account Number: {$user['account_number']}\n" .
		"Amount Due: {$currency} {$amount}\n" .
		"Due Date: {$dueDate}\n\n" .
		$emailActionText;

	if (!empty($shortPaymentLink)) {
		$emailText .= "Click the link below to pay now:\n" .
			"{$shortPaymentLink}\n\n";
	}

	$emailText .= "Thank you for your prompt attention to this matter.\n\n" .
		"Regards,\nWater Billing System";

	$smsSuccess = false;
	$emailSuccess = false;
	$remindersSent = [];

	// Send SMS
	if (!empty($user['phone_number'])) {
		try {
			$sms = new SMS($db);
			$smsResult = $sms->sendWithFallback($user['phone_number'], $smsText, 'payment_reminder');
			$smsSuccess = $smsResult['success'] ?? false;
			if ($smsSuccess || ($smsResult['queued'] ?? false)) {
				$remindersSent[] = 'SMS';
			}
		} catch (\Throwable $e) {
			// SMS sending failed, but continue with email
		}
	}

	// Send Email
	if (!empty($user['email'])) {
		try {
			$email = new Email();
			$emailResult = $email->send($user['email'], $emailSubject, $emailText);
			$emailSuccess = ($emailResult === true) || (is_array($emailResult) && ($emailResult['success'] ?? false));
			if ($emailSuccess) {
				$remindersSent[] = 'Email';
			}
		} catch (\Throwable $e) {
			// Email sending failed
		}
	}

	// Log activity
	try {
		$activityLog = new ActivityLog($db);
		$activityLog->log(
			(int)$_SESSION['user_id'],
			'payment_reminder_sent',
			'bill',
			$bill_id,
			'Sent payment reminder to ' . $user['full_name'],
			[
				'bill_amount' => (float)$bill['amount'],
				'billing_month' => $bill['billing_month'],
				'reminders_sent' => $remindersSent,
				'phone_number' => !empty($user['phone_number']) ? substr($user['phone_number'], -4) : null,
				'email' => !empty($user['email']) ? $user['email'] : null,
			]
		);
	} catch (\Throwable $e) {
		// Logging failure should not block the success response
	}

	if (empty($remindersSent)) {
		http_response_code(500);
		echo json_encode([
			'success' => false,
			'message' => 'Could not send reminder. No valid contact information available.'
		]);
	} else {
		http_response_code(200);
		echo json_encode([
			'success' => true,
			'message' => 'Payment reminder sent successfully via ' . implode(' and ', $remindersSent),
			'reminders_sent' => $remindersSent
		]);
	}
} catch (\Throwable $e) {
	http_response_code(500);
	echo json_encode([
		'success' => false,
		'message' => 'Error processing reminder: ' . $e->getMessage()
	]);
	error_log('Send reminder API error: ' . $e->getMessage() . ' - ' . $e->getFile() . ':' . $e->getLine());
}
