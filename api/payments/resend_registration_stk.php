<?php
/**
 * Resend registration STK push (guest endpoint — no login required).
 *
 * The caller supplies the checkout_request_id that was issued at
 * registration time. We use it to look up the user, verify the
 * account is still inactive, then fire a fresh STK push and return
 * the new checkout_request_id for polling.
 *
 * Security notes:
 *  – checkout_request_id is Safaricom-issued (UUID-like) and cannot be
 *    guessed. It is treated as a one-time proof of registration intent.
 *  – We verify the user's account is NOT yet active before pushing,
 *    so we cannot be used to harass already-active customers.
 */
header("Content-Type: application/json");

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Payment.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/Mpesa.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';
require_once __DIR__ . '/../../includes/ErrorLog.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $checkoutRequestId = isset($input['checkout_request_id']) ? trim($input['checkout_request_id']) : '';

    if ($checkoutRequestId === '') {
        throw new Exception('Missing checkout_request_id');
    }

    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        throw new Exception('Database connection failed');
    }

    $paymentModel = new Payment($db);
    $existingPayment = $paymentModel->getByCheckoutRequestId($checkoutRequestId);

    if (!$existingPayment) {
        throw new Exception('Payment record not found. Please re-register or contact support.');
    }

    // Verify this is a registration payment (not a bill payment)
    if (empty($existingPayment['registration_id'])) {
        throw new Exception('This payment is not a registration payment.');
    }

    $userModel = new User($db);
    $user = $userModel->getById((int)$existingPayment['user_id']);

    if (!$user) {
        throw new Exception('User account not found');
    }

    // If already active, no payment needed
    if (isset($user['status']) && $user['status'] === 'active') {
        http_response_code(200);
        echo json_encode([
            'status' => 'already_active',
            'message' => 'Your account is already active. Please log in to continue.'
        ]);
        exit;
    }

    // Get settings for registration fee
    $settingsService = new BillingSettings($db);
    $settings = $settingsService->getSettings();
    $registrationFee = isset($settings['registration_fee']) ? (float)$settings['registration_fee'] : 0.00;

    if ($registrationFee <= 0) {
        throw new Exception('No registration fee is configured. Please contact support.');
    }

    $amountToCharge = !empty($existingPayment['bill_id'])
        ? $paymentModel->getBillOutstandingAmount((int)$existingPayment['bill_id'])
        : round($registrationFee, 2);

    if ($amountToCharge <= 0.01) {
        throw new Exception('Registration fee already paid. Your account should now be active.');
    }

    // Validate phone number
    if (empty($user['phone_number']) || !preg_match('/^(?:254|\+254|0)?((?:7|1)\d{8})$/', $user['phone_number'], $matches)) {
        throw new Exception('Invalid or missing phone number for M-Pesa payment. Please contact support.');
    }
    $formattedPhone = '254' . $matches[1];

    // Initiate new STK push
    $mpesa = new Mpesa();
    $response = $mpesa->stkPush(
        $formattedPhone,
        $amountToCharge,
        $user['account_number'] ?? 'REG',
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
            }
        }
        throw new Exception('Payment initiation failed: ' . $response['error'] . $details);
    }

    // Reuse the same bill (same invoice) on retry
    $billId = !empty($existingPayment['bill_id']) ? (int)$existingPayment['bill_id'] : null;

    if (!$billId) {
        // Fallback: create a new bill if somehow the original is missing
        $billService = new Bill($db);
        $dueDate = date('Y-m-d', strtotime('+14 days'));
        $billId = $billService->createRegistrationFeeBill(
            $user['id'],
            $user['account_number'] ?? 'REG',
            $registrationFee,
            $dueDate,
            'pending'
        );
    }

    // Record the new payment attempt
    $newPayment = new Payment($db);
    $newPayment->bill_id = $billId;
    $newPayment->user_id = $user['id'];
    $newPayment->phone_number = $formattedPhone;
    $newPayment->amount = $amountToCharge;
    $newPayment->merchant_request_id = $response['MerchantRequestID'] ?? null;
    $newPayment->checkout_request_id = $response['CheckoutRequestID'] ?? null;
    $newPayment->status = 'pending';
    $newPayment->registration_id = $user['id'];

    if (!$newPayment->create()) {
        throw new Exception('Failed to save new payment record');
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'A new M-Pesa prompt has been sent to your phone. Please approve it to activate your account.',
        'data' => [
            'checkout_request_id' => $newPayment->checkout_request_id,
            'amount' => $amountToCharge
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
