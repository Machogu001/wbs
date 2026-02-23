<?php
header('Content-Type: application/json');
// Prevent any caching so the latest payment status is always returned
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Payment.php';
require_once __DIR__ . '/../../includes/PaymentLink.php';
require_once __DIR__ . '/../../includes/Bill.php';

try {
    $token = '';
    $paymentId = 0;

    // Prefer JSON body for POST, but gracefully fall back to form/query params
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (is_array($data)) {
            $token = isset($data['token']) ? trim($data['token']) : '';
            $paymentId = isset($data['payment_id']) ? (int)$data['payment_id'] : 0;
        } else {
            // Fallback: standard POST fields
            $token = isset($_POST['token']) ? trim($_POST['token']) : '';
            $paymentId = isset($_POST['payment_id']) ? (int)$_POST['payment_id'] : 0;
        }
    } else {
        // Allow simple GET-based checks: /check_status_from_link.php?t=...&p=...
        if (isset($_GET['t'])) {
            $token = trim($_GET['t']);
        } elseif (isset($_GET['token'])) {
            $token = trim($_GET['token']);
        }

        if (isset($_GET['p'])) {
            $paymentId = (int)$_GET['p'];
        } elseif (isset($_GET['payment_id'])) {
            $paymentId = (int)$_GET['payment_id'];
        }
    }

    if ($token === '' || $paymentId <= 0) {
        throw new Exception('Missing token or payment id');
    }

    $billId = PaymentLink::getBillIdFromToken($token);
    if (!$billId) {
        throw new Exception('Invalid or expired payment link');
    }

    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Database connection failed');
    }

    $payment = new Payment($db);
    $paymentRow = $payment->getById($paymentId);
    if (!$paymentRow || (int)$paymentRow['bill_id'] !== (int)$billId) {
        throw new Exception('Payment not found for this link');
    }

    // Derive effective status: if the related bill is marked paid, treat as completed
    $effectiveStatus = $paymentRow['status'];
    $bill = new Bill($db);
    $billRow = $bill->getById($billId);
    if ($billRow && $billRow['status'] === 'paid') {
        $effectiveStatus = 'completed';
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'data' => [
            'payment_status' => $effectiveStatus,
            'mpesa_receipt' => $paymentRow['mpesa_receipt'] ?? null,
            'amount' => (float)$paymentRow['amount'],
            'bill_id' => (int)$paymentRow['bill_id']
        ]
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
