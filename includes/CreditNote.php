<?php
class CreditNote {
	private $conn;
	private $table = 'credit_notes';

	public function __construct($db) {
		$this->conn = $db;
		$this->ensureTable();
	}

	private function ensureTable(): void {
		try {
			$this->conn->exec("CREATE TABLE IF NOT EXISTS {$this->table} (
				id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				bill_id INT UNSIGNED NOT NULL,
				user_id INT UNSIGNED NOT NULL,
				units_credited DECIMAL(10,2) DEFAULT 0.00,
				amount_credited DECIMAL(10,2) NOT NULL,
				type VARCHAR(20) NOT NULL,
				created_by INT UNSIGNED NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				note TEXT NULL,
				INDEX idx_bill_id (bill_id),
				INDEX idx_user_id (user_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
		} catch (\PDOException $e) {
			// ignore; app can still run without credit notes table
		}
	}

	public function create($billId, $userId, $unitsCredited, $amountCredited, $type, $createdBy, $note = null): bool {
		$query = "INSERT INTO {$this->table} (bill_id, user_id, units_credited, amount_credited, type, created_by, note)
			VALUES (:bill_id, :user_id, :units_credited, :amount_credited, :type, :created_by, :note)";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(':bill_id', $billId, PDO::PARAM_INT);
		$stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
		$stmt->bindParam(':units_credited', $unitsCredited);
		$stmt->bindParam(':amount_credited', $amountCredited);
		$stmt->bindParam(':type', $type);
		$stmt->bindParam(':created_by', $createdBy, PDO::PARAM_INT);
		$stmt->bindParam(':note', $note);
		return $stmt->execute();
	}
}
