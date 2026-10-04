<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/SMS.php';
require_once __DIR__ . '/../../includes/Email.php';
require_once __DIR__ . '/../../includes/PaymentLink.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';
require_once __DIR__ . '/../../includes/Payment.php';

header('Content-Type: application/json');

try {
	$database = new Database();
	$db = $database->getConnection();

	$auth = new Auth($db);
	if (!$auth->isLoggedIn() || (!$auth->isAdmin() && !$auth->hasPermission('view_invoicing') && !$auth->hasPermission('view_payments') && !$auth->hasPermission('receive_payments'))) {
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
		$settingsService = new BillingSettings($db);
		$settings = $settingsService->getSettings();
		if (!empty($settings['currency_code'])) {
			$currency = (string)$settings['currency_code'];
		}
	} catch (\Throwable $e) {
		$settings = [];
	}

	// Prepare reminder message
	$billingMonth = date('M', strtotime($bill['billing_month']));
	$billAmount = round((float)$bill['amount'], 2);
	$dueDate = date('d-m-Y', strtotime($bill['due_date']));
	$isOverdue = strtotime((string)$bill['due_date']) < strtotime(date('Y-m-d'));
	$paymentService = new Payment($db);
	$amountDueValue = $paymentService->getBillOutstandingAmount((int)$bill_id);
	if ($amountDueValue <= 0.01) {
		http_response_code(400);
		echo json_encode(['success' => false, 'message' => 'This bill has no outstanding balance to remind the client about.']);
		exit;
	}
	$balanceSummary = $billService->getNotificationBalanceSummary((int)$user['id'], $billAmount);
	$previousBalance = (float)($balanceSummary['previous_balance'] ?? 0.0);
	$balanceLabel = $previousBalance < 0 ? 'Credit Bal' : 'Prev Bal';
	$balanceAmount = abs($previousBalance);

	// Generate payment link
	$paymentLink = '';
	$shortPaymentLink = '';
	try {
		$shortPaymentLink = PaymentLink::generateLink((int)$bill_id);
		$paymentLink = $shortPaymentLink;
	} catch (\Throwable $e) {
		// If payment link generation fails, continue without it
		error_log('Payment link generation failed: ' . $e->getMessage());
	}

	$clientName = !empty($user['full_name']) ? (string)$user['full_name'] : 'Customer';
	$companyName = !empty($settings['company_name']) ? (string)$settings['company_name'] : 'WBS';
	$statusLine = $isOverdue ? 'Status: Overdue' : 'Status: Payment Reminder';
	$payLine = !empty($paymentLink) ? "Pay: {$paymentLink}" : 'Pay: Payment link unavailable';
	$smsText = "Dear {$clientName},\n"
		. "{$billingMonth} water bill reminder\n"
		. "AC: {$user['account_number']}\n"
		. "Bill Amount: {$currency} " . number_format($billAmount, 2) . "\n"
		. "{$balanceLabel}: {$currency} " . number_format($balanceAmount, 2) . "\n"
		. "Amount Due: {$currency} " . number_format($amountDueValue, 2) . "\n"
		. "Due Date: {$dueDate}\n"
		. "{$statusLine}\n"
		. $payLine . "\n"
		. "Thank you, {$companyName}.";

	$emailSubject = "Payment Reminder - {$billingMonth} Water Bill";
	$emailText = $smsText;

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
