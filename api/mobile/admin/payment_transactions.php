<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/FinanceApproval.php';
require_once __DIR__ . '/../../../includes/PaymentLink.php';

try {
    $db = mobileApiGetDatabase();
    $actor = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $actor, ['view_payments']);
    $approvals = new FinanceApproval($db);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $status = trim((string)($_GET['status'] ?? ''));
        if (!in_array($status, ['pending', 'completed', 'failed', 'cancelled'], true)) {
            $status = '';
        }
        $counts = ['pending' => 0, 'completed' => 0, 'failed' => 0, 'cancelled' => 0];
        $stmtCounts = $db->query('SELECT status, COUNT(*) AS total FROM payments GROUP BY status');
        foreach (($stmtCounts ? $stmtCounts->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $key = (string)($row['status'] ?? '');
            if (isset($counts[$key])) {
                $counts[$key] = (int)($row['total'] ?? 0);
            }
        }
        $sql = "SELECT p.*, u.full_name, u.account_number, b.billing_month FROM payments p LEFT JOIN users u ON u.id = p.user_id LEFT JOIN bills b ON b.id = p.bill_id";
        $params = [];
        if ($status !== '') {
            $sql .= ' WHERE p.status = :status';
            $params[':status'] = $status;
        }
        $sql .= ' ORDER BY COALESCE(p.transaction_date, p.created_at) DESC LIMIT 200';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($payments as &$payment) {
            $payment['receipt_token'] = !empty($payment['bill_id']) ? PaymentLink::generateToken((int)$payment['bill_id']) : '';
            $payment['is_mpesa_payment'] = strtolower((string)($payment['payment_method'] ?? '')) === 'mpesa' || trim((string)($payment['checkout_request_id'] ?? '')) !== '';
            $payment['document_url'] = ((string)($payment['status'] ?? '') === 'completed' && !empty($payment['bill_id']))
                ? mobileApiDocumentUrl('receipt', (int)$payment['bill_id'], (int)$payment['id'])
                : '';
        }
        unset($payment);
        mobileApiJson(200, 'success', 'Payment transactions loaded.', ['counts' => $counts, 'payments' => $payments]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        if ((string)($data['action'] ?? '') !== 'request_approval') {
            mobileApiJson(422, 'error', 'Unsupported payment transaction action.');
        }
        $paymentId = (int)($data['payment_id'] ?? 0);
        $paymentStmt = $db->prepare('SELECT payment_method, checkout_request_id, amount, mpesa_receipt FROM payments WHERE id = ? LIMIT 1');
        $paymentStmt->execute([$paymentId]);
        $paymentForApproval = $paymentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $isMpesaPayment = $paymentForApproval !== null && (strtolower((string)($paymentForApproval['payment_method'] ?? '')) === 'mpesa' || trim((string)($paymentForApproval['checkout_request_id'] ?? '')) !== '');
        if ($isMpesaPayment) {
            mobileApiJson(422, 'error', 'M-Pesa payments are completed automatically and do not require finance approval.');
        }
        if (!$paymentForApproval || !$approvals->createFromPayment($paymentId, (float)$paymentForApproval['amount'], (int)$actor['id'], trim((string)($paymentForApproval['mpesa_receipt'] ?? '')))) {
            mobileApiJson(422, 'error', 'Could not create finance approval item.');
        }
        mobileApiJson(200, 'success', 'Finance approval item created.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin payment transactions failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process payment transactions right now.');
}