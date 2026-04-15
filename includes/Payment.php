<?php
require_once __DIR__ . '/Accounting.php';

class Payment {
	private $conn;
	private $table = 'payments';

	public $id;
	public $bill_id;
	public $user_id;
	public $phone_number;
	public $amount;
	public $merchant_request_id;
	public $checkout_request_id;
	public $mpesa_receipt;
	public $status;
	public $registration_id;

	public function __construct($db) {
		$this->conn = $db;
		$this->ensureRegistrationColumn();
	}

	public function getById($id) {
		$query = "SELECT * FROM " . $this->table . " WHERE id = :id LIMIT 1";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(':id', $id, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}

	public function getLatestCompletedByBillId($billId) {
		$query = "SELECT * FROM " . $this->table . " WHERE bill_id = :bill_id AND status = 'completed' ORDER BY id DESC LIMIT 1";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(':bill_id', $billId, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}

	public function getLatestPendingRegistrationByUserId($userId) {
		// Kept for backward compatibility; in case other parts of the
		// system explicitly want only a pending registration payment.
		$query = "SELECT * FROM " . $this->table . " WHERE user_id = :user_id AND status = 'pending' AND registration_id IS NOT NULL ORDER BY id DESC LIMIT 1";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}

	public function getLatestRegistrationByUserId($userId) {
		// Returns the most recent registration-related payment (any status)
		// for the given user. This is used to reuse the same registration
		// bill/invoice when a previous attempt failed.
		$query = "SELECT * FROM " . $this->table . " WHERE user_id = :user_id AND registration_id IS NOT NULL ORDER BY id DESC LIMIT 1";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}

	public function create() {
		$query = "INSERT INTO " . $this->table . "
			(bill_id, user_id, phone_number, amount, merchant_request_id, checkout_request_id, mpesa_receipt, status, registration_id, created_at)
			VALUES (:bill_id, :user_id, :phone_number, :amount, :merchant_request_id, :checkout_request_id, :mpesa_receipt, :status, :registration_id, NOW())";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(':bill_id', $this->bill_id);
		$stmt->bindParam(':user_id', $this->user_id);
		$stmt->bindParam(':phone_number', $this->phone_number);
		$stmt->bindParam(':amount', $this->amount);
		$stmt->bindParam(':merchant_request_id', $this->merchant_request_id);
		$stmt->bindParam(':checkout_request_id', $this->checkout_request_id);
		$stmt->bindParam(':mpesa_receipt', $this->mpesa_receipt);
		$stmt->bindParam(':status', $this->status);
		$stmt->bindParam(':registration_id', $this->registration_id);
		if ($stmt->execute()) {
			$this->id = $this->conn->lastInsertId();
			return true;
		}
		return false;
	}

	private function ensureRegistrationColumn() {
		try {
			$this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN registration_id INT NULL AFTER status");
		} catch (\PDOException $e) {
			// ignore if already exists
		}
	}

	public function getByCheckoutRequestId($checkoutRequestId) {
		$query = "SELECT * FROM " . $this->table . " WHERE checkout_request_id = :checkout_request_id LIMIT 1";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(':checkout_request_id', $checkoutRequestId);
		$stmt->execute();
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}

	public function updatePaymentStatus($id, $status, $mpesa_receipt = null, $result_code = null, $result_desc = null) {
		$existingPayment = $this->getById($id);
		$previousStatus = (string)($existingPayment['status'] ?? '');
		$isTransitionToCompleted = ($status === 'completed' && $previousStatus !== 'completed');

		$setParts = [
			"status = :status",
			"mpesa_receipt = :mpesa_receipt",
			"result_code = :result_code",
			"result_desc = :result_desc"
		];
		// For successful payments, record transaction date
		if ($isTransitionToCompleted) {
			$setParts[] = "transaction_date = NOW()";
		}
		$query = "UPDATE " . $this->table . " SET " . implode(', ', $setParts) . " WHERE id = :id";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(':id', $id, PDO::PARAM_INT);
		$stmt->bindParam(':status', $status);
		$stmt->bindParam(':mpesa_receipt', $mpesa_receipt);
		$stmt->bindParam(':result_code', $result_code);
		$stmt->bindParam(':result_desc', $result_desc);
		if (!$stmt->execute()) {
			return false;
		}

		if ($isTransitionToCompleted) {
			try {
				$accounting = new Accounting($this->conn);
				$paymentRow = $this->getById($id);
				$billRow = null;
				if ($paymentRow && !empty($paymentRow['bill_id'])) {
					$stmtBill = $this->conn->prepare('SELECT * FROM bills WHERE id = :id LIMIT 1');
					$stmtBill->bindParam(':id', $paymentRow['bill_id'], PDO::PARAM_INT);
					$stmtBill->execute();
					$billRow = $stmtBill->fetch(PDO::FETCH_ASSOC) ?: null;
				}
				$accounting->postPaymentReceived(
					(int)$id,
					$paymentRow,
					$billRow,
					'Payment received'
				);
			} catch (\Throwable $e) {
				error_log('Payment accounting posting failed for payment #' . (int)$id . ': ' . $e->getMessage());
			}
		}

		return true;
	}
}
