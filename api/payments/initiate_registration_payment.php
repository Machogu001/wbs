<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/Mpesa.php';
require_once __DIR__ . '/../../includes/Payment.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';

session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized access');
    }

    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        throw new Exception('Database connection failed');
    }

    $userModel = new User($db);
    $user = $userModel->getById($_SESSION['user_id']);

    if (!$user) {
        throw new Exception('User not found');
    }

    // Admin accounts should not be charged a registration fee.
    // They can still pay normal water bills via the regular Pay Bill flow.
    if (isset($user['role']) && $user['role'] === 'admin') {
        throw new Exception('Registration fee does not apply to admin accounts. Use the normal Pay Bill section to pay water bills as an administrative expense.');
    }

    $settingsService = new BillingSettings($db);
    $settings = $settingsService->getSettings();
    $registrationFee = isset($settings['registration_fee']) ? (float)$settings['registration_fee'] : 0.00;

    if ($registrationFee <= 0) {
        throw new Exception('Registration fee is not configured.');
    }

    // Before initiating a new payment, check if there is an existing
    // registration bill that is already marked as paid. If so, block
    // a new payment to avoid double-charging.
    $billService = new Bill($db);
    $paymentModel = new Payment($db);
    $existingRegistration = $paymentModel->getLatestRegistrationByUserId($user['id']);
    $billId = null;
    $amountToCharge = round($registrationFee, 2);
    if ($existingRegistration && !empty($existingRegistration['bill_id'])) {
        $existingBill = $billService->getById((int)$existingRegistration['bill_id']);
        if ($existingBill && isset($existingBill['status'])) {
            if ($existingBill['status'] === 'paid') {
                throw new Exception('Registration fee already paid. Your account should now be active. If you cannot access your account, please contact support.');
            }
            // For pending/failed bills, reuse the same bill so the
            // invoice/bill number remains the same on retries.
            if ($existingBill['status'] !== 'paid') {
                $billId = (int)$existingRegistration['bill_id'];
                $amountToCharge = $paymentModel->getBillOutstandingAmount($billId);
            }
        }
    }

    $dueDate = date('Y-m-d', strtotime('+14 days'));
    // If there is no unpaid registration bill, create a fresh one
    if (!$billId) {
        $billId = $billService->createRegistrationFeeBill($user['id'], $user['account_number'], $registrationFee, $dueDate, 'pending');
    }

    if ($amountToCharge <= 0.01) {
        throw new Exception('Registration fee already paid. Your account should now be active. If you cannot access your account, please contact support.');
    }

    // Validate and normalize phone number
    if (empty($user['phone_number']) || !preg_match('/^(?:254|\+254|0)?((?:7|1)\d{8})$/', $user['phone_number'], $matches)) {
        throw new Exception('Invalid or missing phone number for M-Pesa payment.');
    }
    $formatted_phone = '254' . $matches[1];

    // Initiate M-Pesa STK push
    $mpesa = new Mpesa();
    $response = $mpesa->stkPush(
        $formatted_phone,
        $amountToCharge,
        $user['account_number'],
        'Registration Fee'
    );

    if (isset($response['error'])) {
        $details = '';
        if (isset($response['http_code'])) {
            $details .= ' (HTTP ' . $response['http_code'] . ')';
        }
        if (isset($response['details']) && is_array($response['details'])) {
            if (!empty($response['details']['errorMessage'])) {
                $details .= ': ' . $response['details']['errorMessage'];
            } elseif (!empty($response['details']['errorCode'])) {
                $details .= ' (Code ' . $response['details']['errorCode'] . ')';
            }
        }
        throw new Exception('Payment initiation failed: ' . $response['error'] . $details);
    }

    // Create a new payment record tied to the registration fee bill
    $payment = new Payment($db);
    $payment->bill_id = $billId;
    $payment->user_id = $user['id'];
    $payment->phone_number = $formatted_phone;
    $payment->amount = $amountToCharge;
    $payment->merchant_request_id = $response['MerchantRequestID'] ?? null;
    $payment->checkout_request_id = $response['CheckoutRequestID'] ?? null;
    $payment->status = 'pending';
    $payment->registration_id = $user['id'];

    if (!$payment->create()) {
        throw new Exception('Failed to save registration payment record.');
    }

    http_response_code(200);
    echo json_encode(array(
        'status' => 'success',
        'message' => 'Registration payment initiated. Please approve the M-Pesa prompt on your phone.',
        'data' => array(
            'amount' => $amountToCharge,
            'checkout_request_id' => $payment->checkout_request_id
        )
    ));
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(array(
        'status' => 'error',
        'message' => $e->getMessage()
    ));
}

?>
