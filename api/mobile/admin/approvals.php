<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/FinanceApproval.php';
require_once __DIR__ . '/../../../includes/ApprovalWorkflow.php';
require_once __DIR__ . '/../../../includes/InstallmentPlan.php';

try {
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $user, ['manage_approvals']);
    ApprovalWorkflow::ensureTables($db);
    $finance = new FinanceApproval($db);
    $installments = new InstallmentPlan($db);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $status = trim((string)($_GET['status'] ?? ''));
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
        $items = $finance->getItems($status, $limit);
        $billInstallmentPlans = [];
        foreach ($items as $item) {
            $entityType = (string)($item['entity_type'] ?? '');
            if ($entityType !== 'bill_installment' || (string)($item['status'] ?? '') !== 'approved') {
                continue;
            }
            $metadata = [];
            if (!empty($item['metadata_json']) && is_string($item['metadata_json'])) {
                $decoded = json_decode($item['metadata_json'], true);
                if (is_array($decoded)) {
                    $metadata = $decoded;
                }
            }
            $billId = (int)($metadata['bill_id'] ?? $item['entity_id'] ?? 0);
            if ($billId > 0) {
                $plan = $installments->getLatestPlanByBillId($billId, ['active', 'completed']);
                if ($plan) {
                    $billInstallmentPlans[(string)$billId] = $plan;
                }
            }
        }

        $workflowStmt = $db->query("SELECT aw.*, mr.account_number, mr.meter_number
            FROM approval_workflows aw
            LEFT JOIN meter_readings mr ON mr.id = aw.meter_reading_id
            ORDER BY aw.created_at DESC
            LIMIT 100");
        $workflows = $workflowStmt ? ($workflowStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        mobileApiJson(200, 'success', 'Approval items loaded.', [
            'summary' => $finance->getSummary(),
            'items' => $items,
            'bill_installment_plans' => $billInstallmentPlans,
            'workflow_records' => $workflows,
        ]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $itemId = (int)($data['item_id'] ?? 0);
        $action = trim((string)($data['action'] ?? ''));
        $comments = trim((string)($data['comments'] ?? ''));
        if ($itemId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
            mobileApiJson(422, 'error', 'A valid approval item and action are required.');
        }

        if (!$finance->decideItem($itemId, $action, (int)$user['id'], $comments)) {
            mobileApiJson(422, 'error', 'Could not update the approval item.');
        }

        mobileApiJson(200, 'success', 'Approval item updated successfully.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin approvals failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process approvals right now.');
}