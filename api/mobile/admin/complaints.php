<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/Complaint.php';

try {
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $user, ['handle_support', 'view_customers']);
    $complaintService = new Complaint($db);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $status = trim((string)($_GET['status'] ?? ''));
        $items = $complaintService->listAll();
        if ($status !== '') {
            $items = array_values(array_filter($items, static function (array $row) use ($status): bool {
                return (string)($row['status'] ?? '') === $status;
            }));
        }
        mobileApiJson(200, 'success', 'Complaints loaded.', ['complaints' => $items]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $complaintId = (int)($data['complaint_id'] ?? 0);
        $status = trim((string)($data['status'] ?? ''));
        if ($complaintId <= 0 || !in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) {
            mobileApiJson(422, 'error', 'A valid complaint and status are required.');
        }

        if (!$complaintService->updateStatus($complaintId, $status)) {
            mobileApiJson(422, 'error', 'Could not update the complaint status.');
        }

        mobileApiJson(200, 'success', 'Complaint updated successfully.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin complaints failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process complaints right now.');
}