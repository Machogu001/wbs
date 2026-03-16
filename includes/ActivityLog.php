<?php
class ActivityLog {
    private $conn;
    private $table = "activity_log";

    public function __construct($db) {
        $this->conn = $db;
        $this->ensureTable();
        $this->purgeOlderThanDays(30);
    }

    private function ensureTable() {
        $sql = "CREATE TABLE IF NOT EXISTS " . $this->table . " (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            action VARCHAR(100) NOT NULL,
            entity_type VARCHAR(100) NULL,
            entity_id INT NULL,
            description TEXT NULL,
            metadata JSON NULL,
            ip_address VARCHAR(45) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_entity (entity_type, entity_id),
            INDEX idx_user (user_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        try {
            $this->conn->exec($sql);
        } catch (\PDOException $e) {
            // Fallback for older MySQL that may not support JSON
            try {
                $sqlFallback = "CREATE TABLE IF NOT EXISTS " . $this->table . " (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NULL,
                    action VARCHAR(100) NOT NULL,
                    entity_type VARCHAR(100) NULL,
                    entity_id INT NULL,
                    description TEXT NULL,
                    metadata TEXT NULL,
                    ip_address VARCHAR(45) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_entity (entity_type, entity_id),
                    INDEX idx_user (user_id),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
                $this->conn->exec($sqlFallback);
            } catch (\PDOException $e2) {
                // If even this fails, silently ignore to avoid breaking the app
            }
        }
    }

    private function purgeOlderThanDays($days) {
        $days = (int)$days;
        if ($days <= 0) {
            return;
        }

        try {
            $sql = "DELETE FROM " . $this->table . " WHERE created_at < (NOW() - INTERVAL :days DAY)";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            // Do not break main flow if cleanup fails
        }
    }

    public function log($userId, $action, $entityType = null, $entityId = null, $description = '', array $metadata = array()) {
        try {
            $ip = isset($_SERVER['HTTP_X_FORWARDED_FOR']) && $_SERVER['HTTP_X_FORWARDED_FOR']
                ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0])
                : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null);

            $sql = "INSERT INTO " . $this->table . " (user_id, action, entity_type, entity_id, description, metadata, ip_address)
                    VALUES (:user_id, :action, :entity_type, :entity_id, :description, :metadata, :ip_address)";
            $stmt = $this->conn->prepare($sql);
            $json = !empty($metadata) ? json_encode($metadata) : null;
            $userIdParam = $userId !== null ? (int)$userId : null;

            $stmt->bindParam(':user_id', $userIdParam, $userIdParam === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            $stmt->bindParam(':action', $action);
            $stmt->bindParam(':entity_type', $entityType);
            $stmt->bindParam(':entity_id', $entityId);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':metadata', $json);
            $stmt->bindParam(':ip_address', $ip);
            $stmt->execute();
        } catch (\PDOException $e) {
            // Do not break the main flow if logging fails
        }
    }
}
?>
