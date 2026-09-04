<?php
require_once __DIR__ . '/../config/database.php';

class EmailQueue
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        if ($db === null) {
            $database = new Database();
            $db = $database->getConnection();
        }
        if ($db === null) {
            throw new RuntimeException('Database connection required for EmailQueue');
        }

        $this->db = $db;
        self::ensureTable($this->db);
    }

    public function queue(string $toEmail, string $subject, string $body, string $type = 'general'): bool
    {
        $stmt = $this->db->prepare("INSERT INTO email_queue
            (recipient_email, subject, body, type, status, retry_count, created_at)
            VALUES (?, ?, ?, ?, 'pending', 0, NOW())");
        return $stmt->execute([$toEmail, $subject, $body, $type]);
    }

    public function getPending(int $limit = 50): array
    {
        $stmt = $this->db->prepare("SELECT * FROM email_queue
            WHERE status = 'pending' AND retry_count < 3
            ORDER BY created_at ASC
            LIMIT ?");
        $stmt->bindValue(1, max(1, min(250, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function markSent(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE email_queue
            SET status = 'sent', sent_at = NOW(), last_error = NULL
            WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function markFailed(int $id, string $error): bool
    {
        $stmt = $this->db->prepare("UPDATE email_queue
            SET retry_count = retry_count + 1, last_error = ?, last_attempt = NOW(),
                status = CASE WHEN retry_count + 1 >= 3 THEN 'failed_permanent' ELSE 'pending' END
            WHERE id = ?");
        return $stmt->execute([$error, $id]);
    }

    public static function ensureTable(?PDO $db = null): void
    {
        if ($db === null) {
            $database = new Database();
            $db = $database->getConnection();
        }
        if ($db === null) {
            throw new RuntimeException('Database connection required for email queue');
        }

        $db->exec("CREATE TABLE IF NOT EXISTS email_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipient_email VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body LONGTEXT NOT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'general',
            status ENUM('pending', 'sent', 'failed_permanent') NOT NULL DEFAULT 'pending',
            retry_count INT NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            sent_at TIMESTAMP NULL,
            last_attempt TIMESTAMP NULL,
            INDEX idx_email_status_created (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
