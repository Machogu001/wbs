<?php

require_once __DIR__ . '/SMS.php';

class InternalComms
{
    private $conn;
    private $broadcastsTable = 'admin_broadcasts';
    private $chatTable = 'internal_chat_messages';
    private $templatesTable = 'sms_message_templates';
    private const DIRECT_SEND_LIMIT = 25;

    public function __construct($db)
    {
        $this->conn = $db;
        $this->ensureTables();
    }

    private function ensureTables(): void
    {
        try {
            $sqlBroadcasts = "CREATE TABLE IF NOT EXISTS {$this->broadcastsTable} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                created_by INT NOT NULL,
                audience ENUM('clients_all','clients_selected','staff_all','staff_selected') NOT NULL,
                subject VARCHAR(191) NOT NULL,
                message TEXT NOT NULL,
                recipient_count INT NOT NULL DEFAULT 0,
                target_ids_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_created_at (created_at),
                INDEX idx_created_by (created_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            $sqlChat = "CREATE TABLE IF NOT EXISTS {$this->chatTable} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sender_id INT NOT NULL,
                message TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_created_at (created_at),
                INDEX idx_sender_id (sender_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            $sqlTemplates = "CREATE TABLE IF NOT EXISTS {$this->templatesTable} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                created_by INT NOT NULL,
                recipient_group ENUM('clients','staff') NOT NULL,
                title VARCHAR(120) NOT NULL,
                subject VARCHAR(191) NOT NULL,
                message TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_group_created (recipient_group, created_at),
                INDEX idx_created_by (created_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            $this->conn->exec($sqlBroadcasts);
            $this->conn->exec($sqlChat);
            $this->conn->exec($sqlTemplates);
            $this->ensureBroadcastAudienceEnum();
        } catch (\PDOException $e) {
            // Keep app running even if comms tables fail to auto-create.
        }
    }

    private function ensureBroadcastAudienceEnum(): void
    {
        try {
            $stmt = $this->conn->query("SHOW COLUMNS FROM {$this->broadcastsTable} LIKE 'audience'");
            $col = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;
            if (!$col || empty($col['Type'])) {
                return;
            }

            $type = strtolower((string)$col['Type']);
            if (strpos($type, 'staff_all') === false || strpos($type, 'staff_selected') === false) {
                $this->conn->exec("ALTER TABLE {$this->broadcastsTable} MODIFY audience ENUM('clients_all','clients_selected','staff_all','staff_selected') NOT NULL");
            }
        } catch (\PDOException $e) {
            // Ignore schema migration issues to avoid breaking messaging.
        }
    }

    public function getActiveClients(): array
    {
        try {
            $stmt = $this->conn->query("SELECT id, account_number, full_name, phone_number
                FROM users
                WHERE role = 'customer' AND status = 'active'
                ORDER BY full_name ASC");
            return $stmt ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];
        } catch (\PDOException $e) {
            return [];
        }
    }

    public function getActiveStaff(): array
    {
        try {
            $stmt = $this->conn->query("SELECT id, account_number, full_name, phone_number, role
                FROM users
                WHERE role <> 'customer' AND status = 'active'
                ORDER BY full_name ASC");
            return $stmt ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];
        } catch (\PDOException $e) {
            return [];
        }
    }

    public function sendSmsBroadcast(int $adminId, string $subject, string $message, string $recipientGroup, bool $sendToAll, array $selectedIds = []): array
    {
        $subject = trim($subject);
        $message = trim($message);
        $recipientGroup = strtolower(trim($recipientGroup));
        if (!in_array($recipientGroup, ['clients', 'staff'], true)) {
            $recipientGroup = 'clients';
        }

        if ($subject === '' || $message === '') {
            return [
                'success' => false,
                'message' => 'Subject and message are required.',
                'recipient_count' => 0,
            ];
        }

        $recipients = [];
        $audience = $recipientGroup . '_' . ($sendToAll ? 'all' : 'selected');
        $whereRole = $recipientGroup === 'staff' ? "role <> 'customer'" : "role = 'customer'";
        $recipientLabel = $recipientGroup === 'staff' ? 'staff member' : 'client';

        try {
            if ($sendToAll) {
                $stmt = $this->conn->query("SELECT id, phone_number FROM users WHERE {$whereRole} AND status = 'active' AND phone_number IS NOT NULL AND phone_number <> ''");
                $recipients = $stmt ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];
            } else {
                $ids = array_values(array_filter(array_map('intval', $selectedIds), function ($id) {
                    return $id > 0;
                }));

                if (empty($ids)) {
                    return [
                        'success' => false,
                        'message' => 'Select at least one ' . $recipientLabel . '.',
                        'recipient_count' => 0,
                    ];
                }

                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $this->conn->prepare("SELECT id, phone_number FROM users WHERE {$whereRole} AND status = 'active' AND id IN ($placeholders) AND phone_number IS NOT NULL AND phone_number <> ''");
                $stmt->execute($ids);
                $recipients = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            }
        } catch (\PDOException $e) {
            return [
                'success' => false,
                'message' => 'Could not load recipient list.',
                'recipient_count' => 0,
            ];
        }

        if (empty($recipients)) {
            return [
                'success' => false,
                'message' => 'No eligible ' . ($recipientGroup === 'staff' ? 'staff' : 'client') . ' recipients found.',
                'recipient_count' => 0,
            ];
        }

        $sms = new SMS();
        $sentPhones = [];
        $count = 0;
        $immediateSentCount = 0;
        $queuedCount = 0;
        $failedCount = 0;
        $smsText = "[" . $subject . "]\n" . $message;

        $deliverablePhones = [];
        foreach ($recipients as $recipient) {
            $phoneCandidate = trim((string)($recipient['phone_number'] ?? ''));
            if ($phoneCandidate !== '') {
                $deliverablePhones[$phoneCandidate] = true;
            }
        }

        $deliverableCount = count($deliverablePhones);
        if ($deliverableCount < 1) {
            return [
                'success' => false,
                'message' => 'No eligible ' . ($recipientGroup === 'staff' ? 'staff' : 'client') . ' recipients found.',
                'recipient_count' => 0,
            ];
        }

        // Treat only truly large deliverable batches as bulk.
        $sendImmediately = $deliverableCount <= self::DIRECT_SEND_LIMIT;

        foreach ($recipients as $recipient) {
            $phone = trim((string)($recipient['phone_number'] ?? ''));
            if ($phone === '') {
                continue;
            }
            if (isset($sentPhones[$phone])) {
                continue;
            }

            if ($sendImmediately) {
                $result = $sms->send($phone, $smsText);
                if (!empty($result['success'])) {
                    $immediateSentCount++;
                    try {
                        $stmt = $this->conn->prepare("INSERT INTO sms_queue (phone, message, type, status, http_code, response, retry_count, created_at, sent_at) VALUES (:phone, :message, :type, 'sent', :http_code, :response, 0, NOW(), NOW())");
                        $stmt->execute([
                            ':phone' => $phone,
                            ':message' => $smsText,
                            ':type' => 'announcement',
                            ':http_code' => isset($result['http_code']) ? (int)$result['http_code'] : null,
                            ':response' => isset($result['response']) ? (string)$result['response'] : null,
                        ]);
                    } catch (\PDOException $e) {
                        // Do not block successful sends if audit insert fails.
                    }
                } else {
                    $responseMessage = isset($result['message']) ? (string)$result['message'] : '';
                    $alreadyQueuedBySend = stripos($responseMessage, 'queued for later') !== false;
                    if ($alreadyQueuedBySend) {
                        $queuedCount++;
                    } else {
                        $queuedOk = $sms->queue($phone, $smsText, 'announcement');
                        if ($queuedOk) {
                            $queuedCount++;
                        } else {
                            $failedCount++;
                        }
                    }
                }
            } else {
                $queuedOk = $sms->queue($phone, $smsText, 'announcement');
                if ($queuedOk) {
                    $queuedCount++;
                } else {
                    $failedCount++;
                }
            }

            $sentPhones[$phone] = true;
            $count++;
        }

        if (!$sendImmediately && $queuedCount > 0) {
            try {
                $sms->processPendingQueue(min(max($queuedCount, 50), 250));
            } catch (\Throwable $e) {
                // Keep broadcast completion non-blocking even if accelerated queue processing fails.
            }
        }

        try {
            $targetJson = null;
            if (!$sendToAll) {
                $targetJson = json_encode(array_values(array_map('intval', $selectedIds)));
            }

            $stmt = $this->conn->prepare("INSERT INTO {$this->broadcastsTable}
                (created_by, audience, subject, message, recipient_count, target_ids_json)
                VALUES (:created_by, :audience, :subject, :message, :recipient_count, :target_ids_json)");
            $stmt->bindValue(':created_by', $adminId, \PDO::PARAM_INT);
            $stmt->bindValue(':audience', $audience);
            $stmt->bindValue(':subject', $subject);
            $stmt->bindValue(':message', $message);
            $stmt->bindValue(':recipient_count', $count, \PDO::PARAM_INT);
            $stmt->bindValue(':target_ids_json', $targetJson);
            $stmt->execute();
        } catch (\PDOException $e) {
            // Ignore history write failures; broadcast queueing already happened.
        }

        return [
            'success' => true,
            'message' => $sendImmediately ? 'Broadcast sent using immediate mode.' : 'Broadcast queued successfully.',
            'recipient_count' => $count,
            'immediate_sent_count' => $immediateSentCount,
            'queued_count' => $queuedCount,
            'failed_count' => $failedCount,
            'delivery_mode' => $sendImmediately ? 'immediate' : 'queued',
        ];
    }

    // Backward-compatible wrapper
    public function sendClientBroadcast(int $adminId, string $subject, string $message, bool $sendToAll, array $selectedClientIds = []): array
    {
        return $this->sendSmsBroadcast($adminId, $subject, $message, 'clients', $sendToAll, $selectedClientIds);
    }

    public function getRecentBroadcasts(int $limit = 20): array
    {
        try {
            $stmt = $this->conn->prepare("SELECT b.*, u.full_name AS sender_name
                FROM {$this->broadcastsTable} b
                LEFT JOIN users u ON u.id = b.created_by
                ORDER BY b.id DESC
                LIMIT :lim");
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            return [];
        }
    }

    public function saveCustomTemplate(int $adminId, string $recipientGroup, string $title, string $subject, string $message): array
    {
        $recipientGroup = strtolower(trim($recipientGroup));
        if (!in_array($recipientGroup, ['clients', 'staff'], true)) {
            $recipientGroup = 'clients';
        }

        $title = trim($title);
        $subject = trim($subject);
        $message = trim($message);

        if ($title === '' || $subject === '' || $message === '') {
            return [
                'success' => false,
                'message' => 'Template title, subject, and message are required.',
            ];
        }

        try {
            $stmt = $this->conn->prepare("INSERT INTO {$this->templatesTable}
                (created_by, recipient_group, title, subject, message)
                VALUES (:created_by, :recipient_group, :title, :subject, :message)");
            $stmt->execute([
                ':created_by' => $adminId,
                ':recipient_group' => $recipientGroup,
                ':title' => $title,
                ':subject' => $subject,
                ':message' => $message,
            ]);

            return [
                'success' => true,
                'message' => 'Template saved successfully.',
                'id' => (int)$this->conn->lastInsertId(),
            ];
        } catch (\PDOException $e) {
            return [
                'success' => false,
                'message' => 'Could not save template.',
            ];
        }
    }

    public function deleteCustomTemplate(int $templateId, int $adminId): array
    {
        unset($adminId);
        if ($templateId <= 0) {
            return [
                'success' => false,
                'message' => 'Invalid template selected.',
            ];
        }

        try {
            $stmt = $this->conn->prepare("DELETE FROM {$this->templatesTable} WHERE id = :id");
            $stmt->execute([
                ':id' => $templateId,
            ]);

            if ($stmt->rowCount() < 1) {
                return [
                    'success' => false,
                    'message' => 'Template not found.',
                ];
            }

            return [
                'success' => true,
                'message' => 'Template deleted successfully.',
            ];
        } catch (\PDOException $e) {
            return [
                'success' => false,
                'message' => 'Could not delete template.',
            ];
        }
    }

    public function getCustomTemplates(string $recipientGroup, int $limit = 200): array
    {
        $recipientGroup = strtolower(trim($recipientGroup));
        if (!in_array($recipientGroup, ['clients', 'staff'], true)) {
            $recipientGroup = 'clients';
        }

        try {
            $stmt = $this->conn->prepare("SELECT id, created_by, recipient_group, title, subject, message, created_at
                FROM {$this->templatesTable}
                WHERE recipient_group = :recipient_group
                ORDER BY id DESC
                LIMIT :lim");
            $stmt->bindValue(':recipient_group', $recipientGroup);
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            return [];
        }
    }

    public function addInternalMessage(int $senderId, string $message): bool
    {
        $message = trim($message);
        if ($senderId <= 0 || $message === '') {
            return false;
        }

        try {
            $stmt = $this->conn->prepare("INSERT INTO {$this->chatTable} (sender_id, message) VALUES (:sender_id, :message)");
            return $stmt->execute([
                ':sender_id' => $senderId,
                ':message' => $message,
            ]);
        } catch (\PDOException $e) {
            return false;
        }
    }

    public function getInternalMessages(?int $sinceId = null, int $limit = 120): array
    {
        try {
            if ($sinceId !== null && $sinceId > 0) {
                $stmt = $this->conn->prepare("SELECT m.id, m.sender_id, m.message, m.created_at, u.full_name, u.role
                    FROM {$this->chatTable} m
                    LEFT JOIN users u ON u.id = m.sender_id
                    WHERE m.id > :since_id
                    ORDER BY m.id ASC
                    LIMIT :lim");
                $stmt->bindValue(':since_id', $sinceId, \PDO::PARAM_INT);
                $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
                $stmt->execute();
                return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            }

            $stmt = $this->conn->prepare("SELECT * FROM (
                    SELECT m.id, m.sender_id, m.message, m.created_at, u.full_name, u.role
                    FROM {$this->chatTable} m
                    LEFT JOIN users u ON u.id = m.sender_id
                    ORDER BY m.id DESC
                    LIMIT :lim
                ) x ORDER BY x.id ASC");
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            return [];
        }
    }
}
