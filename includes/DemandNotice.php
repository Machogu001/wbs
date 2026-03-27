<?php
require_once __DIR__ . '/../config/database.php';

class DemandNotice {
    private $db;

    public function __construct($db = null) {
        if ($db === null) {
            $database = new Database();
            $db = $database->getConnection();
        }

        if ($db === null) {
            throw new Exception('Database connection required for DemandNotice');
        }

        $this->db = $db;
        self::ensureTable($this->db);
    }

    public static function ensureTable($db = null) {
        if ($db === null) {
            $database = new Database();
            $db = $database->getConnection();
        }

        $db->exec("CREATE TABLE IF NOT EXISTS demand_notices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bill_id INT NOT NULL,
            user_id INT NOT NULL,
            notice_number VARCHAR(40) NOT NULL UNIQUE,
            notice_type ENUM('overdue','final') DEFAULT 'overdue',
            amount_due DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            balance_due DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('draft','sent','acknowledged','resolved','cancelled') DEFAULT 'draft',
            channel VARCHAR(30) NULL,
            note TEXT NULL,
            generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            sent_at TIMESTAMP NULL,
            resolved_at TIMESTAMP NULL,
            INDEX idx_bill_status (bill_id, status),
            INDEX idx_user_status (user_id, status),
            INDEX idx_generated_at (generated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function generateForOverdueBills($limit = 100) {
        $limit = max(1, min(500, (int)$limit));
        $query = "SELECT b.id AS bill_id, b.user_id, b.amount,
                COALESCE(SUM(CASE WHEN p.status = 'completed' THEN p.amount ELSE 0 END), 0) AS paid_amount
            FROM bills b
            LEFT JOIN payments p ON p.bill_id = b.id
            LEFT JOIN demand_notices dn ON dn.bill_id = b.id AND dn.status IN ('draft', 'sent', 'acknowledged')
            WHERE b.status IN ('pending', 'overdue')
                AND b.due_date < CURDATE()
                AND dn.id IS NULL
            GROUP BY b.id, b.user_id, b.amount
            ORDER BY b.due_date ASC
            LIMIT {$limit}";

        $stmt = $this->db->query($query);
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $created = 0;

        foreach ($rows as $row) {
            $amount = (float)($row['amount'] ?? 0);
            $paidAmount = (float)($row['paid_amount'] ?? 0);
            $balance = max(0, $amount - $paidAmount);
            if ($balance <= 0) {
                continue;
            }

            $noticeNumber = 'DN-' . date('Ymd') . '-' . str_pad((string)mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            $insert = $this->db->prepare("INSERT INTO demand_notices
                (bill_id, user_id, notice_number, notice_type, amount_due, balance_due, status, generated_at)
                VALUES (:bill_id, :user_id, :notice_number, 'overdue', :amount_due, :balance_due, 'draft', NOW())");

            $ok = $insert->execute([
                ':bill_id' => (int)$row['bill_id'],
                ':user_id' => (int)$row['user_id'],
                ':notice_number' => $noticeNumber,
                ':amount_due' => $amount,
                ':balance_due' => $balance,
            ]);

            if ($ok) {
                $created++;
            }
        }

        return $created;
    }

    public function getSummary() {
        $stmt = $this->db->query("SELECT status, COUNT(*) AS total FROM demand_notices GROUP BY status");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $summary = [
            'draft' => 0,
            'sent' => 0,
            'acknowledged' => 0,
            'resolved' => 0,
            'cancelled' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '');
            if (array_key_exists($status, $summary)) {
                $summary[$status] = (int)($row['total'] ?? 0);
            }
        }

        return $summary;
    }

    public function getRecent($limit = 100, $status = '') {
        $limit = max(1, min(500, (int)$limit));
        $params = [];
        $sql = "SELECT dn.*, u.full_name, u.account_number, b.billing_month, b.due_date
            FROM demand_notices dn
            INNER JOIN users u ON u.id = dn.user_id
            INNER JOIN bills b ON b.id = dn.bill_id";

        if ($status !== '') {
            $sql .= " WHERE dn.status = :status";
            $params[':status'] = $status;
        }

        $sql .= " ORDER BY dn.generated_at DESC LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function updateStatus($noticeId, $status, $note = null) {
        $allowed = ['draft', 'sent', 'acknowledged', 'resolved', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        $sql = "UPDATE demand_notices SET status = :status, note = :note";
        if ($status === 'sent') {
            $sql .= ", sent_at = NOW()";
        }
        if ($status === 'resolved') {
            $sql .= ", resolved_at = NOW()";
        }
        $sql .= " WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':status' => $status,
            ':note' => $note,
            ':id' => (int)$noticeId,
        ]);
    }
}
