<?php
require_once __DIR__ . '/Accounting.php';

class Bill {
	private $conn;
	private $table = "bills";

	public function __construct($db) {
		$this->conn = $db;
		$this->ensureServiceChargeColumn();
	}

	public function getLastBillByUser($user_id) {
		$query = "SELECT * FROM " . $this->table . " WHERE user_id = :user_id ORDER BY billing_month DESC, id DESC LIMIT 1";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(":user_id", $user_id);
		$stmt->execute();
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}

	public function createBillForUser($user_id, $account_number, $current_reading, $billing_month, $due_date, $rate_per_unit, $service_charge, $status = 'pending') {
		// Check credit before creating bill
		$credit = new CustomerCredit($this->conn);
		$creditProfile = $credit->getProfile($user_id);
		
		if ($creditProfile && $creditProfile['is_suspended']) {
			return [
				'success' => false,
				'message' => 'Account suspended due to non-payment'
			];
		}

		$last_bill = $this->getLastBillByUser($user_id);
		$previous_reading = $last_bill ? (float)$last_bill['current_reading'] : 0.00;
		$consumption = max(0, (float)$current_reading - $previous_reading);
		$amount = ($consumption * (float)$rate_per_unit) + (float)$service_charge;

		// Check if deducting this amount will exceed credit limit
		if ($creditProfile && $creditProfile['available_credit'] - $amount < $creditProfile['credit_limit'] * -0.1) {
			// Allow slight overdraft (10% of credit limit) but log a warning
		}

		$query = "INSERT INTO " . $this->table . "
			(user_id, account_number, billing_month, previous_reading, current_reading, consumption, rate_per_unit, service_charge, amount, due_date, status)
			VALUES
			(:user_id, :account_number, :billing_month, :previous_reading, :current_reading, :consumption, :rate_per_unit, :service_charge, :amount, :due_date, :status)";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(":user_id", $user_id);
		$stmt->bindParam(":account_number", $account_number);
		$stmt->bindParam(":billing_month", $billing_month);
		$stmt->bindParam(":previous_reading", $previous_reading);
		$stmt->bindParam(":current_reading", $current_reading);
		$stmt->bindParam(":consumption", $consumption);
		$stmt->bindParam(":rate_per_unit", $rate_per_unit);
		$stmt->bindParam(":service_charge", $service_charge);
		$stmt->bindParam(":amount", $amount);
		$stmt->bindParam(":due_date", $due_date);
		$stmt->bindParam(":status", $status);

		if ($stmt->execute()) {
			$bill_id = $this->conn->lastInsertId();

			// Deduct from credit
			$credit->deductCredit($user_id, $amount);

			try {
				$accounting = new Accounting($this->conn);
				$accounting->postInvoiceIssued(
					(int)$bill_id,
					(int)$user_id,
					(float)$amount,
					'Water bill issued for ' . (string)$account_number,
					'water',
					(int)$user_id
				);
			} catch (\Throwable $e) {
				// Accounting should not block bill creation.
			}

			return [
				'success' => true,
				'bill_id' => $bill_id,
				'previous_reading' => $previous_reading,
				'current_reading' => (float)$current_reading,
				'consumption' => $consumption,
				'rate_per_unit' => (float)$rate_per_unit,
				'service_charge' => (float)$service_charge,
				'amount' => $amount
			];
		}

		return [
			'success' => false,
			'message' => 'Failed to create bill'
		];
	}

	public function createRegistrationFeeBill($user_id, $account_number, $amount, $due_date, $status = 'pending') {
		$billing_month = date('Y-m-01');
		$previous_reading = 0.00;
		$current_reading = 0.00;
		$consumption = 0.00;
		$rate_per_unit = 0.00;
		$service_charge = (float)$amount;

		$query = "INSERT INTO " . $this->table . "
			(user_id, account_number, billing_month, previous_reading, current_reading, consumption, rate_per_unit, service_charge, amount, due_date, status)
			VALUES
			(:user_id, :account_number, :billing_month, :previous_reading, :current_reading, :consumption, :rate_per_unit, :service_charge, :amount, :due_date, :status)";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(":user_id", $user_id);
		$stmt->bindParam(":account_number", $account_number);
		$stmt->bindParam(":billing_month", $billing_month);
		$stmt->bindParam(":previous_reading", $previous_reading);
		$stmt->bindParam(":current_reading", $current_reading);
		$stmt->bindParam(":consumption", $consumption);
		$stmt->bindParam(":rate_per_unit", $rate_per_unit);
		$stmt->bindParam(":service_charge", $service_charge);
		$stmt->bindParam(":amount", $amount);
		$stmt->bindParam(":due_date", $due_date);
		$stmt->bindParam(":status", $status);

		if ($stmt->execute()) {
			try {
				$accounting = new Accounting($this->conn);
				$accounting->postInvoiceIssued(
					(int)$this->conn->lastInsertId(),
					(int)$user_id,
					(float)$amount,
					'Registration fee bill issued for ' . (string)$account_number,
					'registration',
					(int)$user_id
				);
			} catch (\Throwable $e) {
				// Accounting should not block registration billing.
			}

			return $this->conn->lastInsertId();
		}

		return false;
	}

	public function getBillsByUser($user_id) {
		$query = "SELECT * FROM " . $this->table . " WHERE user_id = :user_id ORDER BY billing_month DESC, id DESC";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(":user_id", $user_id);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function getById($bill_id, $user_id = null) {
		$query = "SELECT * FROM " . $this->table . " WHERE id = :bill_id";
		if ($user_id !== null) {
			$query .= " AND user_id = :user_id";
		}
		$query .= " LIMIT 1";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(":bill_id", $bill_id);
		if ($user_id !== null) {
			$stmt->bindParam(":user_id", $user_id);
		}
		$stmt->execute();
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}

	public function updateStatus($bill_id, $status) {
		$query = "UPDATE " . $this->table . " SET status = :status WHERE id = :bill_id";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(":status", $status);
		$stmt->bindParam(":bill_id", $bill_id);
		return $stmt->execute();
	}

	/**
	 * Mark bill as paid and restore customer credit
	 */
	public function markAsPaid($bill_id, $payment_id = null) {
		$bill = $this->getById($bill_id);
		if (!$bill) {
			return false;
		}

		// Update bill status
		if (!$this->updateStatus($bill_id, 'paid')) {
			return false;
		}

		// Restore customer credit
		$credit = new CustomerCredit($this->conn);
		$credit->restoreCredit($bill['user_id'], $bill['amount']);

		return true;
	}

	public function getUsersBillingSummary() {
		$query = "SELECT u.id, u.account_number, u.full_name, u.phone_number, u.meter_number,
						 lb.id AS last_bill_id, lb.amount AS last_amount, lb.status AS last_status, lb.due_date AS last_due_date,
						 COALESCE(SUM(CASE WHEN b.status IN ('pending','overdue') THEN b.amount ELSE 0 END), 0) AS total_unpaid
				  FROM users u
				  LEFT JOIN (
					  SELECT b1.* FROM bills b1
					  INNER JOIN (
						  SELECT user_id, MAX(billing_month) AS max_month, MAX(id) AS max_id
						  FROM bills
						  GROUP BY user_id
					  ) b2 ON b1.user_id = b2.user_id AND b1.id = b2.max_id
				  ) lb ON lb.user_id = u.id
				  LEFT JOIN bills b ON b.user_id = u.id
				  GROUP BY u.id, u.account_number, u.full_name, u.phone_number, u.meter_number, lb.id, lb.amount, lb.status, lb.due_date
				  ORDER BY u.full_name ASC";
		$stmt = $this->conn->prepare($query);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function getUserSummary($user_id) {
		$query = "SELECT
					COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) AS total_paid,
					COALESCE(SUM(CASE WHEN status IN ('pending','overdue') THEN amount ELSE 0 END), 0) AS total_unpaid,
					COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount,
					COALESCE(SUM(CASE WHEN status = 'overdue' THEN amount ELSE 0 END), 0) AS overdue_amount,
					COALESCE(SUM(CASE WHEN status IN ('pending','overdue') THEN 1 ELSE 0 END), 0) AS pending_count
				  FROM " . $this->table . " WHERE user_id = :user_id";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(":user_id", $user_id);
		$stmt->execute();
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}

	public function getSystemSummary() {
		$query = "SELECT
					COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) AS total_paid,
					COALESCE(SUM(CASE WHEN status IN ('pending','overdue') THEN amount ELSE 0 END), 0) AS total_unpaid,
					COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount,
					COALESCE(SUM(CASE WHEN status = 'overdue' THEN amount ELSE 0 END), 0) AS overdue_amount,
					COALESCE(SUM(CASE WHEN status IN ('pending','overdue') THEN 1 ELSE 0 END), 0) AS pending_count
				  FROM " . $this->table;
		$stmt = $this->conn->prepare($query);
		$stmt->execute();
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}

	private function ensureServiceChargeColumn() {
		$dbName = $this->conn->query("SELECT DATABASE()")?->fetchColumn();
		if (!$dbName) {
			return;
		}
		$check = $this->conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND COLUMN_NAME = 'service_charge'");
		$check->bindParam(":db", $dbName);
		$check->bindParam(":table", $this->table);
		$check->execute();
		$exists = (int)$check->fetchColumn();
		if ($exists === 0) {
			$this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN service_charge DECIMAL(10,2) DEFAULT 0.00 AFTER rate_per_unit");
		}
	}
}
?>
