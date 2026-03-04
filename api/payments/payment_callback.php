<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Payment.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/SMS.php';
require_once __DIR__ . '/../../includes/Etims.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';

// Log callback data
$log_file = __DIR__ . '/../../../logs/mpesa_callback.log';
$callbackData = file_get_contents('php://input');
$timestamp = date('Y-m-d H:i:s');

// Create logs directory if it doesn't exist
if(!is_dir(dirname($log_file))) {
    mkdir(dirname($log_file), 0755, true);
}

file_put_contents($log_file, "[" . $timestamp . "] " . $callbackData . "\n", FILE_APPEND);

try {
    $data = json_decode($callbackData, true);
    
    if(!$data || !isset($data['Body']['stkCallback'])) {
        throw new Exception("Invalid callback data");
    }
    
    $callback = $data['Body']['stkCallback'];
    
    $database = new Database();
    $db = $database->getConnection();
    
    if(!$db) {
        throw new Exception("Database connection failed");
    }
    
    $payment = new Payment($db);
    $bill = new Bill($db);
	
    // Find payment by checkout request ID
    $paymentData = $payment->getByCheckoutRequestId($callback['CheckoutRequestID']);
    
    if(!$paymentData) {
        throw new Exception("Payment not found for checkout ID: " . $callback['CheckoutRequestID']);
    }
    
    if($callback['ResultCode'] == 0) {
        // Payment successful
        $metadata = isset($callback['CallbackMetadata']['Item']) ? $callback['CallbackMetadata']['Item'] : array();

        $receipt = '';
        $amount = 0;
        $phone = '';

        foreach($metadata as $item) {
            if($item['Name'] == 'MpesaReceiptNumber') $receipt = $item['Value'];
            if($item['Name'] == 'Amount') $amount = $item['Value'];
            if($item['Name'] == 'PhoneNumber') $phone = $item['Value'];
        }

        // Update payment record
        $payment->updatePaymentStatus(
            $paymentData['id'],
            'completed',
            $receipt,
            '0',
            'Success'
        );

        // Update bill status if linked to a bill
        if (!empty($paymentData['bill_id'])) {
            $bill->updateStatus($paymentData['bill_id'], 'paid');
        }

        // Send SMS notification
        $userService = new User($db);
        $user = $userService->getById($paymentData['user_id']);
        if ($user) {
            // Registration-related payment is identified via registration_id
            $isRegistrationPayment = !empty($paymentData['registration_id']);
            $wasInactive = $isRegistrationPayment && isset($user['status']) && $user['status'] !== 'active';

            // If user was pending/inactive for registration, activate them now
            if ($wasInactive) {
                $stmtActivate = $db->prepare('UPDATE users SET status = "active" WHERE id = :id');
                $stmtActivate->bindParam(':id', $user['id'], PDO::PARAM_INT);
                $stmtActivate->execute();
            }

            $sms = new SMS();

            // Try to get account number from latest bill data
            $billRow = $bill->getById($paymentData['bill_id']);
            $accountNumber = $billRow && !empty($billRow['account_number'])
                ? $billRow['account_number']
                : ($user['account_number'] ?? '');

            // Company name from billing settings, with fallback
            $settingsService = new BillingSettings($db);
            $settings = $settingsService->getSettings();
            $companyName = !empty($settings['company_name']) ? $settings['company_name'] : 'BreMac Consultant Ltd';

            if ($wasInactive) {
                // Registration fee success: send SMS/Email with account details
                $messageText = "Dear " . ($user['full_name'] ?? 'Customer') . ",\n" .
                    "Your registration payment of KES " . number_format($amount, 2) .
                    " (Ref: " . $receipt . ") has been received successfully.\n" .
                    "Your water account is now active.\n" .
                    "Account No: " . $accountNumber . "\n" .
                    (isset($user['meter_number']) && $user['meter_number'] !== '' ? "Meter No: " . $user['meter_number'] . "\n" : '') .
                    "You can now log in to view your bills and make payments.\n" .
                    $companyName;
            } else {
                // Normal bill payment SMS/Email
                $messageText = "Dear Customer,\n" .
                    "Your M-Pesa payment of KES " . number_format($amount, 2) .
                    " (Ref: " . $receipt . ") for Account No. " . $accountNumber . " has been received successfully.\n" .
                    "Thank you.\n" .
                    $companyName;
            }

            $sms->send($user['phone_number'], $messageText);

            // Also send an email if the user has an email address
            if (!empty($user['email'])) {
                require_once __DIR__ . '/../../includes/Email.php';
                $email = new Email();
                $email->send(
                    $user['email'],
                    'Payment received',
                    $messageText
                );
            }
        }

        // Submit sale to ETIMS gateway if configured
        try {
            $etims = new Etims($db);
            if ($etims->isConfigured()) {
                $billRow = $bill->getById($paymentData['bill_id']);
                if ($billRow && $user) {
                    $etims->submitSale($paymentData, $billRow, $user);
                }
            }
        } catch (Exception $etimsEx) {
            error_log('ETIMS submission error: ' . $etimsEx->getMessage());
        }
        
        error_log("Payment successful: Receipt - $receipt, Amount - $amount");
        
    } else {
        // Payment failed
        $payment->updatePaymentStatus(
            $paymentData['id'],
            'failed',
            null,
            $callback['ResultCode'],
            $callback['ResultDesc']
        );

        // Notify user via SMS about failure
        $userService = new User($db);
        $user = $userService->getById($paymentData['user_id']);
        if ($user) {
            $isRegistrationPayment = !empty($paymentData['registration_id']);
            $sms = new SMS();

            // Try to get account number from latest bill data
            $billRow = $bill->getById($paymentData['bill_id']);
            $accountNumber = $billRow && !empty($billRow['account_number'])
                ? $billRow['account_number']
                : ($user['account_number'] ?? '');

            // Company name from billing settings, with fallback
            $settingsService = new BillingSettings($db);
            $settings = $settingsService->getSettings();
            $companyName = !empty($settings['company_name']) ? $settings['company_name'] : 'BreMac Consultant Ltd';

            $amount = isset($paymentData['amount']) ? (float)$paymentData['amount'] : 0;
            $resultDesc = isset($callback['ResultDesc']) ? $callback['ResultDesc'] : 'Payment failed';

            if ($isRegistrationPayment) {
                // Registration payment failed: inform user that account is pending payment
                $messageText = "Dear " . ($user['full_name'] ?? 'Customer') . ",\n" .
                    "Your registration payment of KES " . number_format($amount, 2) . " did not complete.\n" .
                    "Reason: " . $resultDesc . ".\n" .
                    "Your account registration is still pending payment. " .
                    "Please try paying the registration fee again to activate your water account.\n" .
                    $companyName;
            } else {
                // Normal bill payment failure
                $messageText = "Dear Customer,\n" .
                    "Your M-Pesa payment of KES " . number_format($amount, 2) .
                    " for Account No. " . $accountNumber . " did not complete.\n" .
                    "Reason: " . $resultDesc . ".\n" .
                    "Please try again.\n" .
                    $companyName;
            }

            $sms->send($user['phone_number'], $messageText);

            // Also send an email if the user has an email address
            if (!empty($user['email'])) {
                require_once __DIR__ . '/../../includes/Email.php';
                $email = new Email();
                $email->send(
                    $user['email'],
                    'Payment failed',
                    $messageText
                );
            }
        }

        error_log("Payment failed: " . $callback['ResultDesc']);
    }
    
} catch(Exception $e) {
    error_log("Callback error: " . $e->getMessage());
}

// Always return success to M-Pesa
header('Content-Type: application/json');
echo json_encode(["ResultCode" => 0, "ResultDesc" => "Success"]);
?>
