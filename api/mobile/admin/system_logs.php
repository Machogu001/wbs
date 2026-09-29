<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/ErrorLog.php';
require_once __DIR__ . '/../../../includes/SMSQueue.php';
require_once __DIR__ . '/../../../includes/SMS.php';
require_once __DIR__ . '/../../../config/sms_config.php';

try {
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    if (!mobileApiUserHasRole($user, 'admin')) {
        mobileApiJson(403, 'error', 'Forbidden.');
    }

    ErrorLog::ensureTable($db);
    SMSQueue::ensureTable($db);
    $errorLog = new ErrorLog($db);
    $smsQueue = new SMSQueue($db);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        mobileApiJson(200, 'success', 'System logs loaded.', [
            'error_logs' => $errorLog->getRecent(50),
            'error_stats' => $errorLog->getErrorCountByService(24),
            'sms_queue' => [
                'pending' => $smsQueue->getByStatusForReview('pending', 100),
                'sent' => $smsQueue->getByStatusForReview('sent', 100),
                'failed_permanent' => $smsQueue->getByStatusForReview('failed_permanent', 100),
                'stats' => $smsQueue->getStats(),
            ],
        ]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $action = trim((string)($data['action'] ?? ''));

        if ($action === 'delete_selected_errors') {
            $ids = array_values(array_filter(array_map('intval', (array)($data['log_ids'] ?? [])), static fn(int $id): bool => $id > 0));
            if (empty($ids)) {
                mobileApiJson(422, 'error', 'No logs selected.');
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("DELETE FROM error_logs WHERE id IN ({$placeholders})");
            foreach ($ids as $index => $id) {
                $stmt->bindValue($index + 1, $id, PDO::PARAM_INT);
            }
            $stmt->execute();
            mobileApiJson(200, 'success', 'Error logs deleted.', ['deleted' => (int)$stmt->rowCount()]);
        }

        if ($action === 'delete_all_errors') {
            $stmt = $db->prepare('DELETE FROM error_logs');
            $stmt->execute();
            mobileApiJson(200, 'success', 'All error logs deleted.', ['deleted' => (int)$stmt->rowCount()]);
        }

        if ($action === 'delete_sms_selected') {
            $status = trim((string)($data['sms_status'] ?? ''));
            $ids = array_values(array_filter(array_map('intval', (array)($data['sms_ids'] ?? [])), static fn(int $id): bool => $id > 0));
            if (!in_array($status, ['pending', 'sent', 'failed_permanent'], true) || empty($ids)) {
                mobileApiJson(422, 'error', 'A valid SMS status and selected records are required.');
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("DELETE FROM sms_queue WHERE id IN ({$placeholders}) AND status = ?");
            $params = $ids;
            $params[] = $status;
            $stmt->execute($params);
            mobileApiJson(200, 'success', 'Selected SMS queue items deleted.', ['deleted' => (int)$stmt->rowCount()]);
        }

        if ($action === 'delete_sms_status') {
            $status = trim((string)($data['sms_status'] ?? ''));
            if (!in_array($status, ['pending', 'sent', 'failed_permanent'], true)) {
                mobileApiJson(422, 'error', 'Invalid SMS status selected.');
            }
            $stmt = $db->prepare('DELETE FROM sms_queue WHERE status = ?');
            $stmt->execute([$status]);
            mobileApiJson(200, 'success', 'SMS queue items deleted.', ['deleted' => (int)$stmt->rowCount()]);
        }

        if ($action === 'retry_sms_now') {
            $smsId = (int)($data['sms_id'] ?? 0);
            if ($smsId <= 0) {
                mobileApiJson(422, 'error', 'A valid SMS queue item is required.');
            }
            $row = $smsQueue->getById($smsId);
            if (!$row) {
                mobileApiJson(404, 'error', 'SMS record not found.');
            }
            if (($row['status'] ?? '') === 'sent') {
                mobileApiJson(422, 'error', 'This SMS is already marked as sent.');
            }
            if (!SmsConfig::getApiToken() || !SmsConfig::getSenderId()) {
                mobileApiJson(422, 'error', 'SMS credentials are not configured; retry cannot be sent now.');
            }

            $result = (new SMS($db))->send((string)$row['phone'], (string)$row['message'], false);
            if (!empty($result['success'])) {
                $smsQueue->markSent($smsId, (string)($result['response'] ?? ''), (int)($result['http_code'] ?? 200));
                mobileApiJson(200, 'success', 'SMS retried and sent successfully.');
            }

            $errorMessage = (string)($result['message'] ?? ('HTTP ' . ($result['http_code'] ?? 'unknown')));
            $newStatus = $smsQueue->markFailedWithThreshold($smsId, $errorMessage, 3);
            if ($newStatus === 'failed_permanent') {
                mobileApiJson(200, 'success', 'Retry failed and message moved to permanent failure.', ['status' => $newStatus]);
            }
            mobileApiJson(200, 'success', 'Retry failed; message remains pending for next cron attempt.', ['status' => $newStatus]);
        }

        mobileApiJson(422, 'error', 'Unsupported system logs action.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin system logs failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process system logs right now.');
}