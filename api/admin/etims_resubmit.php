<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/Etims.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $paymentId = isset($data['payment_id']) ? (int)$data['payment_id'] : 0;
    if ($paymentId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid payment ID']);
        exit;
    }

    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception('Database connection failed');
    }

    $auth = new Auth($db);
    if (!$auth->isLoggedIn() || !$auth->isAdmin()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
        exit;
    }

    $stmt = $db->prepare('SELECT * FROM payments WHERE id = :id LIMIT 1');
    $stmt->bindParam(':id', $paymentId, PDO::PARAM_INT);
    $stmt->execute();
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Payment not found']);
        exit;
    }

    if ($payment['status'] !== 'completed') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Only completed payments can be submitted to ETIMS']);
        exit;
    }

    $existingStatus = $payment['etims_status'] ?? null;
    if ($existingStatus === 'sent') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'ETIMS already marked as sent for this payment']);
        exit;
    }

    $etims = new Etims($db);
    if (!$etims->isConfigured()) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'ETIMS is not configured']);
        exit;
    }

    $billService = new Bill($db);
    $bill = $billService->getById($payment['bill_id']);
    if (!$bill) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Related bill not found']);
        exit;
    }

    $userService = new User($db);
    $user = $userService->getById($payment['user_id']);
    if (!$user) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Related user not found']);
        exit;
    }

    $result = $etims->submitSale($payment, $bill, $user);

    $stmt = $db->prepare('SELECT etims_status, etims_sent_at, etims_last_status_code, etims_last_error FROM payments WHERE id = :id LIMIT 1');
    $stmt->bindParam(':id', $paymentId, PDO::PARAM_INT);
    $stmt->execute();
    $updated = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'status' => 'success',
        'data' => [
            'payment_id' => $paymentId,
            'etims_status' => $updated['etims_status'] ?? null,
            'etims_sent_at' => $updated['etims_sent_at'] ?? null,
            'etims_last_status_code' => isset($updated['etims_last_status_code']) ? (int)$updated['etims_last_status_code'] : null,
            'etims_last_error' => $updated['etims_last_error'] ?? null,
            'response' => $result,
        ],
    ]);
} catch (Exception $e) {
    error_log('ETIMS resubmit error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error while submitting to ETIMS']);
}
