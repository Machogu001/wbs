<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/Complaint.php';

try {
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    $complaintService = new Complaint($db);

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        $complaints = $complaintService->listByUser((int)$user['id']);
        $payload = array_map(static function (array $row): array {
            return [
                'id' => (int)($row['id'] ?? 0),
                'subject' => (string)($row['subject'] ?? ''),
                'message' => (string)($row['message'] ?? ''),
                'status' => (string)($row['status'] ?? 'open'),
                'created_at' => (string)($row['created_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }, $complaints);

        mobileApiJson(200, 'success', 'Complaints loaded.', ['complaints' => $payload]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $subject = trim((string)($data['subject'] ?? ''));
        $message = trim((string)($data['message'] ?? ''));

        if ($subject === '' || $message === '') {
            mobileApiJson(422, 'error', 'Subject and message are required.');
        }

        $created = $complaintService->create((int)$user['id'], $subject, $message);
        if (!$created) {
            mobileApiJson(500, 'error', 'Unable to submit complaint right now.');
        }

        mobileApiJson(201, 'success', 'Complaint submitted successfully.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API complaints failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process complaints right now.');
}