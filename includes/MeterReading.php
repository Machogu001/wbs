<?php
class MeterReading {
    private $conn;
    private $table = "meter_readings";

    public function __construct($db) {
        $this->conn = $db;
        $this->ensureTable();
        $this->ensurePhotoNullable();
        $this->ensureBillIdColumn();
    }

    public function createReading($user_id, $account_number, $meter_number, $current_reading, $billing_month, $due_date, $photo_path, $created_by, $bill_id = null, $status = 'pending', $approved_by = null) {
        $query = "INSERT INTO " . $this->table . "
            (user_id, account_number, meter_number, current_reading, billing_month, due_date, photo_path, status, created_by, bill_id, approved_by, approved_at)
            VALUES
            (:user_id, :account_number, :meter_number, :current_reading, :billing_month, :due_date, :photo_path, :status, :created_by, :bill_id, :approved_by, :approved_at)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->bindParam(":account_number", $account_number);
        $stmt->bindParam(":meter_number", $meter_number);
        $stmt->bindParam(":current_reading", $current_reading);
        $stmt->bindParam(":billing_month", $billing_month);
        $stmt->bindParam(":due_date", $due_date);
        $stmt->bindParam(":photo_path", $photo_path);
        $stmt->bindParam(":created_by", $created_by);
        $stmt->bindParam(":bill_id", $bill_id);
        $stmt->bindParam(":status", $status);

        $approved_at = null;
        if ($status === 'approved' && $approved_by) {
            $approved_at = date('Y-m-d H:i:s');
        }
        $stmt->bindParam(":approved_by", $approved_by);
        $stmt->bindParam(":approved_at", $approved_at);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }

        return false;
    }

    public function listPending() {
        $query = "SELECT * FROM " . $this->table . " WHERE status = 'pending' ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById($id) {
        $query = "SELECT * FROM " . $this->table . " WHERE id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function markApproved($id, $approved_by) {
        $query = "UPDATE " . $this->table . " SET status = 'approved', approved_by = :approved_by, approved_at = NOW() WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":approved_by", $approved_by);
        $stmt->bindParam(":id", $id);
        return $stmt->execute();
    }

    public function markRejected($id, $approved_by) {
        $query = "UPDATE " . $this->table . " SET status = 'rejected', approved_by = :approved_by, approved_at = NOW() WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":approved_by", $approved_by);
        $stmt->bindParam(":id", $id);
        return $stmt->execute();
    }

    public function attachBill($id, $bill_id) {
        $query = "UPDATE " . $this->table . " SET bill_id = :bill_id WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":bill_id", $bill_id);
        $stmt->bindParam(":id", $id);
        return $stmt->execute();
    }

    private function ensureTable() {
        $sql = "CREATE TABLE IF NOT EXISTS " . $this->table . " (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            account_number VARCHAR(20),
            meter_number VARCHAR(50),
            current_reading DECIMAL(10,2) NOT NULL,
            billing_month DATE NOT NULL,
            due_date DATE NOT NULL,
            photo_path VARCHAR(255) NULL,
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            approved_by INT NULL,
            approved_at TIMESTAMP NULL,
            bill_id INT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $this->conn->exec($sql);
    }

    private function ensurePhotoNullable() {
        $dbName = $this->conn->query("SELECT DATABASE()")?->fetchColumn();
        if (!$dbName) {
            return;
        }
        $check = $this->conn->prepare("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND COLUMN_NAME = 'photo_path'");
        $check->bindParam(":db", $dbName);
        $check->bindParam(":table", $this->table);
        $check->execute();
        $nullable = $check->fetchColumn();
        if ($nullable === 'NO') {
            $this->conn->exec("ALTER TABLE " . $this->table . " MODIFY photo_path VARCHAR(255) NULL");
        }
    }

    private function ensureBillIdColumn() {
        $dbName = $this->conn->query("SELECT DATABASE()")?->fetchColumn();
        if (!$dbName) {
            return;
        }
        $check = $this->conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND COLUMN_NAME = 'bill_id'");
        $check->bindParam(":db", $dbName);
        $check->bindParam(":table", $this->table);
        $check->execute();
        $exists = (int)$check->fetchColumn();
        if ($exists === 0) {
            $this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN bill_id INT NULL AFTER approved_at");
        }
    }
}
?>
