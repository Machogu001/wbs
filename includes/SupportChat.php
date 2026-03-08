<?php

class SupportChat
{
    private $conn;
    private $threadsTable = 'support_threads';
    private $messagesTable = 'support_messages';
    private $typingTable = 'support_typing';

    public function __construct($db)
    {
        $this->conn = $db;
        $this->ensureTables();
        $this->cleanupOldThreads(24);
    }

    private function ensureTables(): void
    {
        try {
            $sqlThreads = "CREATE TABLE IF NOT EXISTS {$this->threadsTable} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                last_message_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_status (user_id, status),
                INDEX idx_last_message_at (last_message_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

            $sqlMessages = "CREATE TABLE IF NOT EXISTS {$this->messagesTable} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                thread_id INT NOT NULL,
                sender_type ENUM('user','admin') NOT NULL,
                sender_id INT NULL,
                message TEXT NOT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_thread (thread_id),
                INDEX idx_created_at (created_at),
                CONSTRAINT fk_support_messages_thread FOREIGN KEY (thread_id)
                    REFERENCES {$this->threadsTable}(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

            $this->conn->exec($sqlThreads);
            $this->conn->exec($sqlMessages);

            $sqlTyping = "CREATE TABLE IF NOT EXISTS {$this->typingTable} (
                thread_id INT NOT NULL,
                actor ENUM('user','admin') NOT NULL,
                is_typing TINYINT(1) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (thread_id, actor),
                CONSTRAINT fk_support_typing_thread FOREIGN KEY (thread_id)
                    REFERENCES {$this->threadsTable}(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

            $this->conn->exec($sqlTyping);
        } catch (\PDOException $e) {
            // Do not break the app if chat tables cannot be created
        }
    }

    public function setTyping(int $threadId, string $actor, bool $isTyping): void
    {
        $actor = $actor === 'admin' ? 'admin' : 'user';
        try {
            $stmt = $this->conn->prepare("INSERT INTO {$this->typingTable} (thread_id, actor, is_typing) VALUES (:tid, :actor, :typing)
                ON DUPLICATE KEY UPDATE is_typing = VALUES(is_typing), updated_at = CURRENT_TIMESTAMP");
            $stmt->execute([
                ':tid' => $threadId,
                ':actor' => $actor,
                ':typing' => $isTyping ? 1 : 0,
            ]);
        } catch (\PDOException $e) {
            // ignore typing errors
        }
    }

    public function getTypingStatus(int $threadId): array
    {
        $status = ['user' => false, 'admin' => false];
        try {
            $stmt = $this->conn->prepare("SELECT actor, is_typing, updated_at FROM {$this->typingTable} WHERE thread_id = :tid");
            $stmt->execute([':tid' => $threadId]);
            $rows = $stmt->fetchAll() ?: [];
            $now = time();
            foreach ($rows as $row) {
                $actor = $row['actor'] === 'admin' ? 'admin' : 'user';
                $isTyping = (int)$row['is_typing'] === 1;
                $updatedAt = isset($row['updated_at']) ? strtotime($row['updated_at']) : 0;
                if ($isTyping && $updatedAt && ($now - $updatedAt) <= 10) {
                    $status[$actor] = true;
                }
            }
        } catch (\PDOException $e) {
            // ignore
        }
        return $status;
    }

    public function getOrCreateThreadForUser(int $userId): ?array
    {
        try {
            $stmt = $this->conn->prepare("SELECT * FROM {$this->threadsTable} WHERE user_id = :uid AND status = 'open' ORDER BY id DESC LIMIT 1");
            $stmt->execute([':uid' => $userId]);
            $thread = $stmt->fetch();
            if ($thread) {
                return $thread;
            }

            $stmt = $this->conn->prepare("INSERT INTO {$this->threadsTable} (user_id, status, last_message_at) VALUES (:uid, 'open', NOW())");
            $stmt->execute([':uid' => $userId]);
            $id = (int)$this->conn->lastInsertId();

            return [
                'id' => $id,
                'user_id' => $userId,
                'status' => 'open',
                'last_message_at' => date('Y-m-d H:i:s'),
            ];
        } catch (\PDOException $e) {
            return null;
        }
    }

    public function addMessage(int $threadId, string $senderType, ?int $senderId, string $message): bool
    {
        if ($message === '') {
            return false;
        }

        $senderType = $senderType === 'admin' ? 'admin' : 'user';

        try {
            $stmt = $this->conn->prepare("INSERT INTO {$this->messagesTable} (thread_id, sender_type, sender_id, message) VALUES (:tid, :stype, :sid, :msg)");
            $stmt->execute([
                ':tid' => $threadId,
                ':stype' => $senderType,
                ':sid' => $senderId,
                ':msg' => $message,
            ]);

            $stmt2 = $this->conn->prepare("UPDATE {$this->threadsTable} SET last_message_at = NOW() WHERE id = :tid");
            $stmt2->execute([':tid' => $threadId]);

            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    public function getMessages(int $threadId, ?int $sinceId = null): array
    {
        try {
            if ($sinceId !== null) {
                $stmt = $this->conn->prepare("SELECT * FROM {$this->messagesTable} WHERE thread_id = :tid AND id > :sid ORDER BY id ASC");
                $stmt->execute([':tid' => $threadId, ':sid' => $sinceId]);
            } else {
                $stmt = $this->conn->prepare("SELECT * FROM {$this->messagesTable} WHERE thread_id = :tid ORDER BY id ASC LIMIT 200");
                $stmt->execute([':tid' => $threadId]);
            }

            return $stmt->fetchAll() ?: [];
        } catch (\PDOException $e) {
            return [];
        }
    }

    public function getThreadsForAdmin(int $limit = 20): array
    {
        try {
            $stmt = $this->conn->prepare("SELECT t.*, u.account_number, u.full_name
                FROM {$this->threadsTable} t
                LEFT JOIN users u ON t.user_id = u.id
                ORDER BY (t.last_message_at IS NULL), t.last_message_at DESC, t.id DESC
                LIMIT :lim");
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll() ?: [];
        } catch (\PDOException $e) {
            return [];
        }
    }

    public function getMessageById(int $messageId): ?array
    {
        try {
            $stmt = $this->conn->prepare("SELECT * FROM {$this->messagesTable} WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $messageId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (\PDOException $e) {
            return null;
        }
    }

    public function deleteMessage(int $messageId): bool
    {
        try {
            $stmt = $this->conn->prepare("SELECT thread_id FROM {$this->messagesTable} WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $messageId]);
            $row = $stmt->fetch();
            if (!$row) {
                return false;
            }

            $threadId = (int)$row['thread_id'];

            $del = $this->conn->prepare("DELETE FROM {$this->messagesTable} WHERE id = :id");
            $del->execute([':id' => $messageId]);

            $stmt2 = $this->conn->prepare("SELECT MAX(created_at) AS last_created FROM {$this->messagesTable} WHERE thread_id = :tid");
            $stmt2->execute([':tid' => $threadId]);
            $last = $stmt2->fetch();
            $lastCreated = $last && !empty($last['last_created']) ? $last['last_created'] : null;

            $stmt3 = $this->conn->prepare("UPDATE {$this->threadsTable} SET last_message_at = :lm WHERE id = :tid");
            $stmt3->execute([
                ':lm' => $lastCreated,
                ':tid' => $threadId,
            ]);

            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    public function cleanupOldThreads(int $hours = 24): void
    {
        if ($hours <= 0) {
            return;
        }

        try {
            $cutoff = date('Y-m-d H:i:s', time() - ($hours * 3600));
            $stmt = $this->conn->prepare("DELETE FROM {$this->threadsTable}
                WHERE (last_message_at IS NOT NULL AND last_message_at < :cutoff)
                   OR (last_message_at IS NULL AND created_at < :cutoff)");
            $stmt->execute([':cutoff' => $cutoff]);
        } catch (\PDOException $e) {
            // ignore cleanup errors
        }
    }
}

