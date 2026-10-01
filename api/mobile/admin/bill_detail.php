<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/FinanceApproval.php';
require_once __DIR__ . '/../../../includes/InstallmentPlan.php';

try {
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $user, ['view_bill_detail']);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $data = $method === 'POST' ? mobileApiReadJson() : [];

    $billId = (int)($_GET['id'] ?? $_GET['bill_id'] ?? 0);
    if ($method === 'POST') {
        $billId = (int)($data['bill_id'] ?? $billId);
    }
    if ($billId <= 0) {
        mobileApiJson(422, 'error', 'A valid bill ID is required.');
    }

    $billService = new Bill($db);
    $paymentService = new Payment($db);
    $bill = $billService->getById($billId);
    if (!$bill) {
        mobileApiJson(404, 'error', 'Bill not found.');
    }
    if ($method === 'POST') {
        $action = trim((string)($data['action'] ?? ''));
        $financeApproval = new FinanceApproval($db);
        $submittedBy = (int)($user['id'] ?? 0);
        if (in_array($action, ['request_writeoff', 'request_waiver'], true)) {
            $result = $financeApproval->createBillWriteOffRequest(
                $billId,
                (float)($data['request_amount'] ?? 0),
                $submittedBy,
                trim((string)($data['reason'] ?? '')),
                $action === 'request_waiver' ? 'waiver' : 'writeoff'
            );
            if (!$result) {
                mobileApiJson(422, 'error', 'Could not submit approval request. Ensure amount is valid and bill has outstanding balance.');
            }
            mobileApiJson(200, 'success', 'Approval request submitted successfully.', ['bill_id' => $billId]);
        }
        if ($action === 'request_installment') {
            $result = $financeApproval->createInstallmentPlanRequest(
                $billId,
                $submittedBy,
                (float)($data['plan_amount'] ?? 0),
                (int)($data['installment_count'] ?? 3),
                trim((string)($data['frequency'] ?? 'monthly')),
                trim((string)($data['start_date'] ?? date('Y-m-d'))),
                trim((string)($data['plan_reason'] ?? $data['reason'] ?? ''))
            );
            if (!$result) {
                mobileApiJson(422, 'error', 'Could not submit installment request. Check amount, dates, and outstanding balance.');
            }
            mobileApiJson(200, 'success', 'Installment approval request submitted successfully.', ['bill_id' => $billId]);
        }
        mobileApiJson(422, 'error', 'Unsupported bill detail action.');
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
    $installmentService = new InstallmentPlan($db);
    $latestInstallmentPlan = $installmentService->getLatestPlanByBillId($billId, ['active', 'completed']);
    $installmentAllocationLedger = $latestInstallmentPlan ? $installmentService->getAllocationLedgerByPlanId((int)$latestInstallmentPlan['id']) : [];
    foreach ($payments as &$paymentRow) {
        $paymentRow['document_url'] = ((string)($paymentRow['status'] ?? '') === 'completed')
            ? mobileApiDocumentUrl('receipt', (int)($paymentRow['bill_id'] ?? 0), (int)($paymentRow['id'] ?? 0))
            : '';
    }
    unset($paymentRow);

    mobileApiJson(200, 'success', 'Bill detail loaded.', [
        'bill' => mobileApiFormatBill($billService, $paymentService, $bill) + [
            'line_items' => $billService->getBillLineItems($billId),
            'payments' => array_map('mobileApiFormatPayment', $payments),
            'credit_notes' => $credits,
            'approval_items' => $approvals,
            'installment_plan' => $latestInstallmentPlan,
            'installment_allocation_ledger' => $installmentAllocationLedger,
        ],
        'customer' => !empty($billUser) ? mobileApiFormatUser($db, $billUser) : null,
    ]);
} catch (Throwable $e) {
    error_log('Mobile API admin bill detail failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load the bill detail right now.');
}