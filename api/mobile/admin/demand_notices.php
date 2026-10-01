<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/DemandNotice.php';

try {
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $user, ['manage_demand_notices']);
    $service = new DemandNotice($db);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $status = trim((string)($_GET['status'] ?? ''));
        if (!in_array($status, ['draft', 'sent', 'acknowledged', 'resolved', 'cancelled'], true)) {
            $status = '';
        }
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
        mobileApiJson(200, 'success', 'Demand notices loaded.', [
            'summary' => $service->getSummary(),
            'notices' => $service->getRecent($limit, $status),
        ]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $action = trim((string)($data['action'] ?? ''));
        if ($action === 'generate') {
            $count = $service->generateForOverdueBills((int)($data['limit'] ?? 200));
            mobileApiJson(200, 'success', $count . ' demand notice(s) generated.', ['generated' => $count]);
        }

        if ($action === 'update_status' || $action === 'mark_status') {
            $noticeId = (int)($data['notice_id'] ?? 0);
            $status = trim((string)($data['status'] ?? ''));
            $note = trim((string)($data['note'] ?? ''));
            if ($noticeId <= 0 || $status === '') {
                mobileApiJson(422, 'error', 'A valid notice ID and status are required.');
            }
            if (!$service->updateStatus($noticeId, $status, $note !== '' ? $note : null)) {
                mobileApiJson(422, 'error', 'Could not update demand notice.');
            }
            mobileApiJson(200, 'success', 'Demand notice updated successfully.');
        }

        mobileApiJson(422, 'error', 'Unsupported demand notice action.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin demand notices failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process demand notices right now.');
}