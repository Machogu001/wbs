<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Payment.php';

try {
    $checkoutRequestId = isset($_GET['checkout_request_id']) ? trim($_GET['checkout_request_id']) : '';

    if ($checkoutRequestId === '') {
        throw new Exception('Missing checkout_request_id');
    }

    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        throw new Exception('Database connection failed');
    }

    $paymentModel = new Payment($db);
    $paymentData = $paymentModel->getByCheckoutRequestId($checkoutRequestId);

    if (!$paymentData) {
        http_response_code(404);
        echo json_encode(array(
            'status' => 'error',
            'message' => 'Payment not found',
            'payment_status' => null
        ));
        exit;
    }

    $status = isset($paymentData['status']) ? $paymentData['status'] : '';

    // Map DB status to simple response
    if ($status === 'completed') {
        echo json_encode(array(
            'status' => 'success',
            'message' => 'Payment confirmed',
            'payment_status' => 'completed'
        ));
    } elseif ($status === 'failed') {
        echo json_encode(array(
            'status' => 'error',
            'message' => isset($paymentData['result_desc']) && $paymentData['result_desc'] !== ''
                ? $paymentData['result_desc']
                : 'Payment failed',
            'payment_status' => 'failed'
        ));
    } else {
        echo json_encode(array(
            'status' => 'pending',
            'message' => 'Payment pending',
            'payment_status' => $status
        ));
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(array(
        'status' => 'error',
        'message' => $e->getMessage(),
        'payment_status' => null
    ));
}

?>
