<?php
require_once __DIR__ . '/../config/database.php';

class FinanceApproval {
    private $db;

    public function __construct($db = null) {
        if ($db === null) {
            $database = new Database();
            $db = $database->getConnection();
        }

        if ($db === null) {
            throw new Exception('Database connection required for FinanceApproval');
        }

        $this->db = $db;
        self::ensureTable($this->db);
    }

    public static function ensureTable($db = null) {
        if ($db === null) {
            $database = new Database();
            $db = $database->getConnection();
        }

        $db->exec("CREATE TABLE IF NOT EXISTS financial_approval_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(50) NOT NULL,
            entity_id INT NOT NULL,
            reference_no VARCHAR(50) NULL,
            title VARCHAR(191) NOT NULL,
            amount DECIMAL(10,2) DEFAULT 0.00,
            submitted_by INT NULL,
            current_approver_role VARCHAR(50) DEFAULT 'finance',
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            metadata_json LONGTEXT NULL,
            comments TEXT NULL,
            approved_by INT NULL,
            approved_at TIMESTAMP NULL,
            rejected_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_entity (entity_type, entity_id),
            INDEX idx_status_role (status, current_approver_role),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function getSummary() {
        $stmt = $this->db->query("SELECT status, COUNT(*) AS total FROM financial_approval_items GROUP BY status");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $summary = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '');
            if (isset($summary[$status])) {
                $summary[$status] = (int)($row['total'] ?? 0);
            }
        }
        return $summary;
    }

    public function getItems($status = '', $limit = 100) {
        $limit = max(1, min(500, (int)$limit));
        $sql = "SELECT fai.*, u.full_name AS submitted_by_name, au.full_name AS approved_by_name
            FROM financial_approval_items fai
            LEFT JOIN users u ON u.id = fai.submitted_by
            LEFT JOIN users au ON au.id = fai.approved_by";
        $params = [];
        if ($status !== '') {
            $sql .= " WHERE fai.status = :status";
            $params[':status'] = $status;
        }
        $sql .= " ORDER BY fai.created_at DESC LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function createFromPayment($paymentId, $amount, $submittedBy, $referenceNo = null) {
        $stmt = $this->db->prepare("INSERT INTO financial_approval_items
            (entity_type, entity_id, reference_no, title, amount, submitted_by, current_approver_role, status, created_at)
            VALUES ('payment', :entity_id, :reference_no, :title, :amount, :submitted_by, 'finance', 'pending', NOW())
            ON DUPLICATE KEY UPDATE amount = VALUES(amount), reference_no = VALUES(reference_no), title = VALUES(title)");
        return $stmt->execute([
            ':entity_id' => (int)$paymentId,
            ':reference_no' => $referenceNo,
            ':title' => 'Payment Approval #' . (int)$paymentId,
            ':amount' => (float)$amount,
            ':submitted_by' => $submittedBy ? (int)$submittedBy : null,
        ]);
    }

    public function updateStatus($itemId, $status, $approvedBy = null, $comments = null) {
        $allowed = ['approved', 'rejected'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        $sql = "UPDATE financial_approval_items
            SET status = :status,
                comments = :comments,
                approved_by = :approved_by,
                approved_at = " . ($status === 'approved' ? 'NOW()' : 'approved_at') . ",
                rejected_at = " . ($status === 'rejected' ? 'NOW()' : 'rejected_at') . "
            WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':status' => $status,
            ':comments' => $comments,
            ':approved_by' => $approvedBy ? (int)$approvedBy : null,
            ':id' => (int)$itemId,
        ]);
    }
}
