<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/Email.php';

function mobileApiEnsureSupportInquiryTable(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS support_inquiries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL,
        phone VARCHAR(60) DEFAULT NULL,
        message TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        email_sent TINYINT(1) NOT NULL DEFAULT 0,
        email_error TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_support_inquiries_status (status),
        INDEX idx_support_inquiries_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $requiredColumns = [
        'reply_subject' => "ALTER TABLE support_inquiries ADD COLUMN reply_subject VARCHAR(191) DEFAULT NULL AFTER email_error",
        'reply_message' => "ALTER TABLE support_inquiries ADD COLUMN reply_message TEXT DEFAULT NULL AFTER reply_subject",
        'replied_at' => "ALTER TABLE support_inquiries ADD COLUMN replied_at TIMESTAMP NULL DEFAULT NULL AFTER reply_message",
        'replied_by_user_id' => "ALTER TABLE support_inquiries ADD COLUMN replied_by_user_id INT DEFAULT NULL AFTER replied_at",
    ];

    $check = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name');
    foreach ($requiredColumns as $columnName => $ddl) {
        $check->execute([':table_name' => 'support_inquiries', ':column_name' => $columnName]);
        if ((int)$check->fetchColumn() === 0) {
            $db->exec($ddl);
        }
    }
}

try {
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $user, ['handle_support']);
    mobileApiEnsureSupportInquiryTable($db);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $status = trim((string)($_GET['status'] ?? 'open'));
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
        $summaryQuery = $db->query("SELECT
            SUM(CASE WHEN status IN ('pending', 'queued', 'sent') THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN status = 'handled' THEN 1 ELSE 0 END) AS handled_count,
            COUNT(*) AS total_count
            FROM support_inquiries");
        $summaryRow = $summaryQuery ? ($summaryQuery->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $summary = [
            'open' => (int)($summaryRow['open_count'] ?? 0),
            'handled' => (int)($summaryRow['handled_count'] ?? 0),
            'all' => (int)($summaryRow['total_count'] ?? 0),
        ];

        $whereSql = '';
        if ($status === 'open') {
            $whereSql = "WHERE status IN ('pending', 'queued', 'sent')";
        } elseif ($status === 'handled') {
            $whereSql = "WHERE status = 'handled'";
        }

        $stmt = $db->query("SELECT * FROM support_inquiries {$whereSql} ORDER BY created_at DESC LIMIT {$limit}");
        $items = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        mobileApiJson(200, 'success', 'Support inquiries loaded.', [
            'summary' => $summary,
            'inquiries' => $items,
        ]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $action = trim((string)($data['action'] ?? ''));
        $inquiryId = (int)($data['inquiry_id'] ?? 0);

        if ($action === 'reply_email') {
            $replySubject = trim((string)($data['reply_subject'] ?? ''));
            $replyMessage = trim((string)($data['reply_message'] ?? ''));
            if ($inquiryId <= 0 || $replySubject === '' || $replyMessage === '') {
                mobileApiJson(422, 'error', 'Inquiry ID, reply subject, and reply message are required.');
            }

            $stmt = $db->prepare('SELECT * FROM support_inquiries WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $inquiryId]);
            $inquiry = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$inquiry) {
                mobileApiJson(404, 'error', 'Inquiry not found.');
            }

            $visitorEmail = trim((string)($inquiry['email'] ?? ''));
            if ($visitorEmail === '' || !filter_var($visitorEmail, FILTER_VALIDATE_EMAIL)) {
                mobileApiJson(422, 'error', 'Visitor email address is invalid.');
            }

            $body = $replyMessage . "\n\n---\nOriginal inquiry from " . (string)$inquiry['name'] . ":\n" . (string)$inquiry['message'];
            $sendResult = (new Email())->send($visitorEmail, $replySubject, $body);
            if (empty($sendResult['success'])) {
                mobileApiJson(422, 'error', (string)($sendResult['message'] ?? 'Could not send reply email.'));
            }

            $update = $db->prepare("UPDATE support_inquiries
                SET status = 'handled',
                    reply_subject = :reply_subject,
                    reply_message = :reply_message,
                    replied_at = NOW(),
                    replied_by_user_id = :replied_by_user_id,
                    updated_at = NOW()
                WHERE id = :id");
            $update->execute([
                ':reply_subject' => $replySubject,
                ':reply_message' => $replyMessage,
                ':replied_by_user_id' => (int)$user['id'],
                ':id' => $inquiryId,
            ]);
            mobileApiJson(200, 'success', 'Reply sent successfully.');
        }

        if (in_array($action, ['mark_handled', 'reopen'], true)) {
            if ($inquiryId <= 0) {
                mobileApiJson(422, 'error', 'A valid inquiry ID is required.');
            }
            $nextStatus = $action === 'mark_handled' ? 'handled' : 'queued';
            $stmt = $db->prepare('UPDATE support_inquiries SET status = :status WHERE id = :id');
            $stmt->execute([':status' => $nextStatus, ':id' => $inquiryId]);
            mobileApiJson(200, 'success', $action === 'mark_handled' ? 'Inquiry marked as handled.' : 'Inquiry reopened.');
        }

        if ($action === 'delete') {
            if (!mobileApiUserHasRole($user, 'admin')) {
                mobileApiJson(403, 'error', 'Forbidden.');
            }
            if ($inquiryId <= 0) {
                mobileApiJson(422, 'error', 'A valid inquiry ID is required.');
            }
            $stmt = $db->prepare('DELETE FROM support_inquiries WHERE id = :id');
            $stmt->execute([':id' => $inquiryId]);
            mobileApiJson(200, 'success', 'Inquiry deleted.');
        }

        mobileApiJson(422, 'error', 'Unsupported support inquiry action.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin support inquiries failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process support inquiries right now.');
}