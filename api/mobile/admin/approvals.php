<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/FinanceApproval.php';

try {
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $user, ['manage_approvals']);
    $finance = new FinanceApproval($db);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $status = trim((string)($_GET['status'] ?? ''));
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
        $items = $finance->getItems($status, $limit);
        mobileApiJson(200, 'success', 'Approval items loaded.', [
            'summary' => $finance->getSummary(),
            'items' => $items,
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