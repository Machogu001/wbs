<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Mpesa.php';
require_once __DIR__ . '/../../includes/Payment.php';
require_once __DIR__ . '/../../includes/Bill.php';

session_start();

try {
    // Check authentication
    if(!isset($_SESSION['user_id'])) {
        throw new Exception("Unauthorized access");
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    if(!$db) {
        throw new Exception("Database connection failed");
    }
    
    $data = json_decode(file_get_contents("php://input"));
    
    if(!$data) {
        throw new Exception("Invalid JSON input");
    }
    
    // Validate input
    if(empty($data->bill_id) || empty($data->phone)) {
        throw new Exception("Bill ID and phone number are required");
    }
    
    $bill = new Bill($db);
    
    // Get bill details
    $billData = $bill->getById($data->bill_id, $_SESSION['user_id']);
    
    if(!$billData) {
        throw new Exception("Bill not found or access denied");
    }
    
    if($billData['status'] == 'paid') {
        throw new Exception("Bill already paid");
    }
    
    // Validate and normalize phone number
    // Accept formats: 07XXXXXXXX, 01XXXXXXXX, 2547XXXXXXXX, 2541XXXXXXXX, +2547XXXXXXXX, +2541XXXXXXXX, 07/01 with leading 0
    if(!preg_match('/^(?:254|\+254|0)?((?:7|1)\d{8})$/', $data->phone, $matches)) {
        throw new Exception("Invalid phone number format");
    }

    $formatted_phone = '254' . $matches[1];
    
    // Initiate M-Pesa payment
    $mpesa = new Mpesa();
    $response = $mpesa->stkPush(
        $formatted_phone,
        $billData['amount'],
        $billData['account_number'],
        "Water Bill - " . date('F Y', strtotime($billData['billing_month']))
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
    
    // Save payment record
    $payment = new Payment($db);
    $payment->bill_id = $data->bill_id;
    $payment->user_id = $_SESSION['user_id'];
    $payment->phone_number = $formatted_phone;
    $payment->amount = $billData['amount'];
    $payment->merchant_request_id = $response['MerchantRequestID'];
    $payment->checkout_request_id = $response['CheckoutRequestID'];
    $payment->status = 'pending';
    
    if($payment->create()) {
        http_response_code(200);
        echo json_encode(array(
            "status" => "success",
            "message" => "Payment initiated successfully",
            "data" => array(
                "checkout_request_id" => $response['CheckoutRequestID'],
                "payment_id" => $payment->id,
                "amount" => $billData['amount']
            )
        ));
    } else {
        throw new Exception("Failed to save payment record");
    }
    
} catch(Exception $e) {
    http_response_code(400);
    echo json_encode(array(
        "status" => "error",
        "message" => $e->getMessage()
    ));
}
?>
