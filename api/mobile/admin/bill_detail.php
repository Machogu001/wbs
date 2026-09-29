<?php

require_once __DIR__ . '/../_bootstrap.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $user, ['view_bill_detail', 'view_payments', 'view_customers']);

    $billId = (int)($_GET['id'] ?? 0);
    if ($billId <= 0) {
        mobileApiJson(422, 'error', 'A valid bill ID is required.');
    }

    $billService = new Bill($db);
    $paymentService = new Payment($db);
    $bill = $billService->getById($billId);
    if (!$bill) {
        mobileApiJson(404, 'error', 'Bill not found.');
    }

    $stmtUser = $db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmtUser->execute([':id' => (int)($bill['user_id'] ?? 0)]);
    $billUser = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmtPayments = $db->prepare('SELECT p.*, b.account_number, b.billing_month
        FROM payments p
        LEFT JOIN bills b ON b.id = p.bill_id
        WHERE p.bill_id = :bill_id
        ORDER BY p.created_at DESC, p.id DESC');
    $stmtPayments->execute([':bill_id' => $billId]);
    $payments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmtCredits = $db->prepare('SELECT id, units_credited, amount_credited, type, created_by, created_at, note
        FROM credit_notes
        WHERE bill_id = :bill_id
        ORDER BY id DESC');
    $stmtCredits->execute([':bill_id' => $billId]);
    $credits = $stmtCredits->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmtApprovals = $db->prepare("SELECT id, entity_type, reference_no, title, amount, status, comments, metadata_json, created_at
        FROM financial_approval_items
        WHERE entity_id = :entity_id AND entity_type IN ('bill_writeoff', 'bill_waiver', 'bill_installment')
        ORDER BY created_at DESC");
    $stmtApprovals->execute([':entity_id' => $billId]);
    $approvals = $stmtApprovals->fetchAll(PDO::FETCH_ASSOC) ?: [];

    mobileApiJson(200, 'success', 'Bill detail loaded.', [
        'bill' => mobileApiFormatBill($billService, $paymentService, $bill) + [
            'line_items' => $billService->getBillLineItems($billId),
            'payments' => array_map('mobileApiFormatPayment', $payments),
            'credit_notes' => $credits,
            'approval_items' => $approvals,
        ],
        'customer' => !empty($billUser) ? mobileApiFormatUser($db, $billUser) : null,
    ]);
} catch (Throwable $e) {
    error_log('Mobile API admin bill detail failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load the bill detail right now.');
}