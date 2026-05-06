<?php
require_once __DIR__ . '/Accounting.php';
require_once __DIR__ . '/Bill.php';
require_once __DIR__ . '/InstallmentPlan.php';
require_once __DIR__ . '/User.php';
require_once __DIR__ . '/SMS.php';
require_once __DIR__ . '/BillingSettings.php';
require_once __DIR__ . '/ErrorLog.php';

class Payment {
	private $conn;
	private $table = 'payments';
	private $adjustmentTable = 'payment_adjustments';

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
		$this->ensureManualReceiptColumns();
		$this->ensureAdjustmentTable();
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
			if ((string)$this->status === 'completed') {
				$this->handleCompletedPayment((int)$this->id);
			}
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

	private function ensureManualReceiptColumns(): void {
		try {
			$this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN payment_method VARCHAR(32) NULL AFTER phone_number");
		} catch (\PDOException $e) {
			// ignore if already exists
		}

		try {
			$this->conn->exec("ALTER TABLE " . $this->table . " ADD COLUMN received_by_user_id INT NULL AFTER registration_id");
		} catch (\PDOException $e) {
			// ignore if already exists
		}
	}

	private function ensureAdjustmentTable(): void {
		$this->conn->exec("CREATE TABLE IF NOT EXISTS {$this->adjustmentTable} (
			id INT AUTO_INCREMENT PRIMARY KEY,
			payment_id INT NOT NULL,
			bill_id INT NULL,
			user_id INT NULL,
			adjustment_type ENUM('refund','chargeback') NOT NULL,
			amount DECIMAL(10,2) NOT NULL,
			reason TEXT NULL,
			status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
			submitted_by INT NULL,
			approved_by INT NULL,
			approval_item_id INT NULL,
			approved_at TIMESTAMP NULL,
			processed_at TIMESTAMP NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
			INDEX idx_payment_status (payment_id, status),
			INDEX idx_user_created (user_id, created_at),
			INDEX idx_type_status (adjustment_type, status)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	}

	public function getByCheckoutRequestId($checkoutRequestId) {
		$query = "SELECT * FROM " . $this->table . " WHERE checkout_request_id = :checkout_request_id LIMIT 1";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(':checkout_request_id', $checkoutRequestId);
		$stmt->execute();
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}

	public function getCompletedPaymentsByUserId(int $userId, int $limit = 50): array {
		if ($userId <= 0) {
			return [];
		}

		$limit = max(1, min(200, $limit));
		$stmt = $this->conn->prepare("SELECT p.*, b.billing_month, b.amount AS bill_amount, b.status AS bill_status,
				COALESCE(adj.approved_amount, 0) AS approved_adjustment_amount,
				GREATEST(0, p.amount - COALESCE(adj.approved_amount, 0)) AS available_adjustment_amount
			FROM {$this->table} p
			LEFT JOIN bills b ON b.id = p.bill_id
			LEFT JOIN (
				SELECT payment_id, SUM(amount) AS approved_amount
				FROM {$this->adjustmentTable}
				WHERE status = 'approved'
				GROUP BY payment_id
			) adj ON adj.payment_id = p.id
			WHERE p.user_id = :user_id AND p.status = 'completed'
			ORDER BY COALESCE(p.transaction_date, p.created_at) DESC, p.id DESC
			LIMIT {$limit}");
		$stmt->execute([':user_id' => $userId]);

		return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	}

	public function getAdjustmentById(int $adjustmentId): ?array {
		if ($adjustmentId <= 0) {
			return null;
		}

		$stmt = $this->conn->prepare("SELECT * FROM {$this->adjustmentTable} WHERE id = :id LIMIT 1");
		$stmt->execute([':id' => $adjustmentId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return $row ?: null;
	}

	public function getAdjustmentsByUserId(int $userId, int $limit = 50): array {
		if ($userId <= 0) {
			return [];
		}

		$limit = max(1, min(200, $limit));
		$stmt = $this->conn->prepare("SELECT pa.*, p.mpesa_receipt, p.transaction_date, p.amount AS payment_amount,
				b.billing_month, b.status AS bill_status
			FROM {$this->adjustmentTable} pa
			INNER JOIN {$this->table} p ON p.id = pa.payment_id
			LEFT JOIN bills b ON b.id = pa.bill_id
			WHERE pa.user_id = :user_id
			ORDER BY pa.created_at DESC, pa.id DESC
			LIMIT {$limit}");
		$stmt->execute([':user_id' => $userId]);

		return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	}

	public function createAdjustmentRequest(int $paymentId, string $adjustmentType, float $amount, int $submittedBy, string $reason = ''): int {
		$adjustmentType = $adjustmentType === 'chargeback' ? 'chargeback' : 'refund';
		$amount = round(max(0.0, $amount), 2);
		if ($paymentId <= 0 || $amount <= 0) {
			throw new InvalidArgumentException('A valid payment and adjustment amount are required.');
		}

		$paymentRow = $this->getById($paymentId);
		if (!$paymentRow || (string)($paymentRow['status'] ?? '') !== 'completed') {
			throw new RuntimeException('Only completed payments can be adjusted.');
		}

		$availableAmount = $this->getAvailableAdjustmentAmount((int)$paymentRow['id']);
		if ($availableAmount <= 0) {
			throw new RuntimeException('This payment has no remaining adjustable amount.');
		}
		if ($amount - $availableAmount > 0.0001) {
			throw new RuntimeException('Adjustment amount exceeds the remaining adjustable amount for this payment.');
		}

		$stmt = $this->conn->prepare("INSERT INTO {$this->adjustmentTable}
			(payment_id, bill_id, user_id, adjustment_type, amount, reason, status, submitted_by, created_at)
			VALUES (:payment_id, :bill_id, :user_id, :adjustment_type, :amount, :reason, 'pending', :submitted_by, NOW())");
		$stmt->execute([
			':payment_id' => (int)$paymentRow['id'],
			':bill_id' => !empty($paymentRow['bill_id']) ? (int)$paymentRow['bill_id'] : null,
			':user_id' => !empty($paymentRow['user_id']) ? (int)$paymentRow['user_id'] : null,
			':adjustment_type' => $adjustmentType,
			':amount' => $amount,
			':reason' => trim($reason),
			':submitted_by' => $submittedBy > 0 ? $submittedBy : null,
		]);

		return (int)$this->conn->lastInsertId();
	}

	public function linkAdjustmentApprovalItem(int $adjustmentId, int $approvalItemId): bool {
		if ($adjustmentId <= 0 || $approvalItemId <= 0) {
			return false;
		}

		$stmt = $this->conn->prepare("UPDATE {$this->adjustmentTable} SET approval_item_id = :approval_item_id WHERE id = :id");
		return $stmt->execute([
			':approval_item_id' => $approvalItemId,
			':id' => $adjustmentId,
		]);
	}

	public function approveAdjustment(int $adjustmentId, ?int $approvedBy = null, ?string $note = null): bool {
		$adjustment = $this->getAdjustmentById($adjustmentId);
		if (!$adjustment || (string)($adjustment['status'] ?? '') !== 'pending') {
			return false;
		}

		$paymentRow = $this->getById((int)$adjustment['payment_id']);
		if (!$paymentRow || (string)($paymentRow['status'] ?? '') !== 'completed') {
			throw new RuntimeException('Original payment is not available for adjustment approval.');
		}

		$availableAmount = $this->getAvailableAdjustmentAmount((int)$paymentRow['id']);
		$amount = round((float)($adjustment['amount'] ?? 0), 2);
		if ($amount <= 0 || $amount - $availableAmount > 0.0001) {
			throw new RuntimeException('Adjustment amount is no longer available for approval.');
		}

		$billRow = null;
		if (!empty($paymentRow['bill_id'])) {
			$stmtBill = $this->conn->prepare('SELECT * FROM bills WHERE id = :id LIMIT 1');
			$stmtBill->execute([':id' => (int)$paymentRow['bill_id']]);
			$billRow = $stmtBill->fetch(PDO::FETCH_ASSOC) ?: null;
		}

		$this->conn->beginTransaction();
		try {
			$memo = ucfirst((string)$adjustment['adjustment_type']) . ' approved for payment #' . (int)$paymentRow['id'];
			if ($note !== null && trim($note) !== '') {
				$memo .= ' - ' . trim($note);
			}

			$accounting = new Accounting($this->conn);
			$accounting->postPaymentAdjustment(
				(string)$adjustment['adjustment_type'],
				(int)$adjustment['id'],
				$paymentRow,
				$billRow,
				$memo,
				$approvedBy
			);

			$stmt = $this->conn->prepare("UPDATE {$this->adjustmentTable}
				SET status = 'approved', approved_by = :approved_by, approved_at = NOW(), processed_at = NOW()
				WHERE id = :id AND status = 'pending'");
			$stmt->execute([
				':approved_by' => $approvedBy > 0 ? $approvedBy : null,
				':id' => $adjustmentId,
			]);

			if (!empty($paymentRow['bill_id'])) {
				$this->syncBillStatusFromPayments((int)$paymentRow['bill_id']);
			}

			$this->conn->commit();
			return true;
		} catch (Throwable $e) {
			if ($this->conn->inTransaction()) {
				$this->conn->rollBack();
			}
			throw $e;
		}
	}

	public function rejectAdjustment(int $adjustmentId, ?int $approvedBy = null): bool {
		$stmt = $this->conn->prepare("UPDATE {$this->adjustmentTable}
			SET status = 'rejected', approved_by = :approved_by, processed_at = NOW()
			WHERE id = :id AND status = 'pending'");
		return $stmt->execute([
			':approved_by' => $approvedBy > 0 ? $approvedBy : null,
			':id' => $adjustmentId,
		]);
	}

	private function getAvailableAdjustmentAmount(int $paymentId): float {
		$paymentRow = $this->getById($paymentId);
		if (!$paymentRow) {
			return 0.0;
		}

		$amount = round((float)($paymentRow['amount'] ?? 0), 2);
		$approvedAdjustments = $this->getApprovedAdjustmentTotalByPaymentId($paymentId);

		return round(max(0.0, $amount - $approvedAdjustments), 2);
	}

	private function getApprovedAdjustmentTotalByPaymentId(int $paymentId): float {
		$stmt = $this->conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM {$this->adjustmentTable} WHERE payment_id = :payment_id AND status = 'approved'");
		$stmt->execute([':payment_id' => $paymentId]);
		return round((float)$stmt->fetchColumn(), 2);
	}

	private function getNetCompletedPaidAmountByBillId(int $billId): float {
		$stmt = $this->conn->prepare("SELECT COALESCE(SUM(p.amount), 0) - COALESCE(SUM(adj.approved_amount), 0) AS net_paid
			FROM {$this->table} p
			LEFT JOIN (
				SELECT payment_id, SUM(amount) AS approved_amount
				FROM {$this->adjustmentTable}
				WHERE status = 'approved'
				GROUP BY payment_id
			) adj ON adj.payment_id = p.id
			WHERE p.bill_id = :bill_id AND p.status = 'completed'");
		$stmt->execute([':bill_id' => $billId]);

		return round(max(0.0, (float)$stmt->fetchColumn()), 2);
	}

	public function getBillOutstandingAmount(int $billId): float {
		if ($billId <= 0) {
			return 0.0;
		}

		$stmtBill = $this->conn->prepare('SELECT amount FROM bills WHERE id = :id LIMIT 1');
		$stmtBill->execute([':id' => $billId]);
		$billAmount = round((float)$stmtBill->fetchColumn(), 2);
		if ($billAmount <= 0) {
			return 0.0;
		}

		$netPaid = $this->getNetCompletedPaidAmountByBillId($billId);

		return round(max(0.0, $billAmount - $netPaid), 2);
	}

	private function syncBillStatusFromPayments(int $billId): void {
		if ($billId <= 0) {
			return;
		}

		$stmtBill = $this->conn->prepare('SELECT id, amount, due_date, status FROM bills WHERE id = :id LIMIT 1');
		$stmtBill->execute([':id' => $billId]);
		$billRow = $stmtBill->fetch(PDO::FETCH_ASSOC) ?: null;
		if (!$billRow) {
			return;
		}

		$netPaid = $this->getNetCompletedPaidAmountByBillId($billId);
		$billAmount = round((float)($billRow['amount'] ?? 0), 2);
		$newStatus = $netPaid + 0.01 >= $billAmount
			? 'paid'
			: ((string)($billRow['due_date'] ?? '') < date('Y-m-d') ? 'overdue' : 'pending');

		$stmt = $this->conn->prepare('UPDATE bills SET status = :status WHERE id = :id');
		$stmt->execute([
			':status' => $newStatus,
			':id' => $billId,
		]);
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
			$this->handleCompletedPayment((int)$id);
		}

		return true;
	}

	private function handleCompletedPayment(int $paymentId): void {
		$paymentRow = $this->getById($paymentId);
		if (!$paymentRow) {
			return;
		}

		$billRow = null;
		if (!empty($paymentRow['bill_id'])) {
			$stmtBill = $this->conn->prepare('SELECT * FROM bills WHERE id = :id LIMIT 1');
			$stmtBill->bindParam(':id', $paymentRow['bill_id'], PDO::PARAM_INT);
			$stmtBill->execute();
			$billRow = $stmtBill->fetch(PDO::FETCH_ASSOC) ?: null;
		}

		try {
			$accounting = new Accounting($this->conn);

			// Ensure the invoice journal (AR DR / Revenue CR) exists before posting
			// the payment receipt (Cash DR / AR CR). If the bill was created before
			// the accounting module was active the invoice entry may be absent, which
			// would leave AR with no matching debit and produce a negative balance.
			if ($billRow && (int)$billRow['id'] > 0) {
				$existing = $accounting->getPostedEntryByReference('bill', (int)$billRow['id']);
				if (!$existing) {
					$billHelper = new Bill($this->conn);
					$revenueType = $billHelper->isRegistrationFeeBill($billRow) ? 'registration' : 'water';
					$billDate = !empty($billRow['billing_month'])
						? date('Y-m-d', strtotime((string)$billRow['billing_month']))
						: date('Y-m-d');
					$accounting->postInvoiceIssued(
						(int)$billRow['id'],
						(int)$billRow['user_id'],
						(float)$billRow['amount'],
						($revenueType === 'registration' ? 'Registration fee bill issued for ' : 'Water bill issued for ')
							. (string)($billRow['account_number'] ?? ''),
						$revenueType,
						null,
						$billDate
					);
				}
			}

			$accounting->postPaymentReceived(
				$paymentId,
				$paymentRow,
				$billRow,
				'Payment received'
			);
		} catch (\Throwable $e) {
			error_log('Payment accounting posting failed for payment #' . (int)$paymentId . ': ' . $e->getMessage());
		}

		try {
			$planner = new InstallmentPlan($this->conn);
			$planner->allocatePayment($paymentId);
		} catch (\Throwable $e) {
			error_log('Installment allocation failed for payment #' . (int)$paymentId . ': ' . $e->getMessage());
		}

		if (!empty($paymentRow['bill_id'])) {
			$this->syncBillStatusFromPayments((int)$paymentRow['bill_id']);
		}
	}

	public function sendCompletedPaymentNotification(int $paymentId): array {
		$status = [
			'sms' => ['status' => 'skipped'],
			'email' => ['status' => 'skipped'],
			'warnings' => [],
		];

		$paymentRow = $this->getById($paymentId);
		if (!$paymentRow || (string)($paymentRow['status'] ?? '') !== 'completed') {
			return $status;
		}

		try {
			ErrorLog::ensureTable($this->conn);
		} catch (Throwable $e) {
			// Continue even if the diagnostics table cannot be prepared.
		}
		$errorLogger = null;
		try {
			$errorLogger = new ErrorLog($this->conn);
		} catch (Throwable $e) {
			$errorLogger = null;
		}

		$userId = (int)($paymentRow['user_id'] ?? 0);
		if ($userId <= 0) {
			return $status;
		}

		$userService = new User($this->conn);
		$user = $userService->getById($userId);
		if (!$user) {
			return $status;
		}

		$billHelper = new Bill($this->conn);
		$billRow = !empty($paymentRow['bill_id']) ? ($billHelper->getById((int)$paymentRow['bill_id']) ?: null) : null;
		$accountNumber = $billRow && !empty($billRow['account_number'])
			? (string)$billRow['account_number']
			: (string)($user['account_number'] ?? '');
		$amount = (float)($paymentRow['amount'] ?? 0);
		$receipt = trim((string)($paymentRow['mpesa_receipt'] ?? ''));
		$paymentMethod = strtolower(trim((string)($paymentRow['payment_method'] ?? 'mpesa')));

		$settingsService = new BillingSettings($this->conn);
		$settings = $settingsService->getSettings();
		$companyName = !empty($settings['company_name']) ? $settings['company_name'] : 'BreMac Consultant Ltd';

		$isRegistrationPayment = !empty($paymentRow['registration_id']);
		$registrationOutstanding = ($isRegistrationPayment && !empty($paymentRow['bill_id']))
			? $this->getBillOutstandingAmount((int)$paymentRow['bill_id'])
			: 0.0;
		$registrationFullyPaid = $isRegistrationPayment
			&& $registrationOutstanding <= 0.01
			&& $billRow
			&& (($billRow['status'] ?? '') === 'paid');

		$methodLabelMap = [
			'mpesa' => 'M-Pesa',
			'cash' => 'Cash',
			'bank' => 'Bank transfer',
			'card' => 'Card',
			'cheque' => 'Cheque',
			'wallet' => 'Wallet',
			'other' => 'Manual payment',
		];
		$methodLabel = $methodLabelMap[$paymentMethod] ?? ucfirst($paymentMethod ?: 'Payment');
		$customerName = (string)($user['full_name'] ?? 'Customer');

		if ($registrationFullyPaid) {
			$messageText = "Dear {$customerName},\n"
				. "Your registration payment of KES " . number_format($amount, 2)
				. ($receipt !== '' ? " (Ref: {$receipt})" : '') . " has been received successfully.\n"
				. "Your water account is now active.\n"
				. "Account No: {$accountNumber}\n"
				. (!empty($user['meter_number']) ? "Meter No: " . $user['meter_number'] . "\n" : '')
				. "You can now log in to view your bills and make payments.\n"
				. $companyName;
		} elseif ($isRegistrationPayment) {
			$messageText = "Dear {$customerName},\n"
				. "We have received KES " . number_format($amount, 2)
				. " toward your registration fee via {$methodLabel}.\n"
				. "Remaining registration balance: KES " . number_format($registrationOutstanding, 2) . "\n"
				. "Your account will be activated after the full registration fee is paid.\n"
				. "Please log in and complete payment at https://wbs.bremac.co.ke/registration-payment\n"
				. $companyName;
		} else {
			$messageText = "Dear Customer,\n"
				. "Your {$methodLabel} payment of KES " . number_format($amount, 2)
				. ($receipt !== '' ? " (Ref: {$receipt})" : '')
				. " for Account No. {$accountNumber} has been received successfully.\n"
				. "Thank you.\n"
				. $companyName;
		}

		$recipientPhone = (string)($user['phone_number'] ?? '');
		if ($recipientPhone !== '') {
			try {
				$sms = new SMS($this->conn);
				$smsResult = $sms->sendWithFallback($recipientPhone, $messageText, 'payment_confirmation');
				$smsStatus = (string)($smsResult['delivery_mode'] ?? (!empty($smsResult['success']) ? 'immediate' : 'failed'));
				$status['sms'] = [
					'status' => $smsStatus,
					'queued' => !empty($smsResult['queued']),
					'http_code' => isset($smsResult['http_code']) ? (int)$smsResult['http_code'] : null,
				];

				if ($smsStatus === 'queued') {
					$status['warnings'][] = 'SMS confirmation was queued for delivery.';
					if ($errorLogger) {
						$errorLogger->logSystemError('PaymentNotification', 'SMS confirmation queued for delivery.', __FILE__, __LINE__, [
							'payment_id' => $paymentId,
							'user_id' => $userId,
							'phone_number' => $recipientPhone,
							'payment_method' => $paymentMethod,
							'message' => $messageText,
							'result' => $smsResult,
						]);
					}
				} elseif ($smsStatus === 'failed') {
					$status['warnings'][] = 'SMS confirmation could not be sent.';
					if ($errorLogger) {
						$errorLogger->logSystemError('PaymentNotification', 'SMS confirmation failed to send.', __FILE__, __LINE__, [
							'payment_id' => $paymentId,
							'user_id' => $userId,
							'phone_number' => $recipientPhone,
							'payment_method' => $paymentMethod,
							'message' => $messageText,
							'result' => $smsResult,
						]);
					}
				}
			} catch (Throwable $e) {
			error_log('Payment SMS notification failed for payment #' . $paymentId . ': ' . $e->getMessage());
				$status['sms'] = ['status' => 'failed'];
				$status['warnings'][] = 'SMS confirmation could not be sent.';
			if ($errorLogger) {
				$errorLogger->logSystemError('PaymentNotification', 'SMS notification failed: ' . $e->getMessage(), __FILE__, __LINE__, [
					'payment_id' => $paymentId,
					'user_id' => $userId,
					'phone_number' => $recipientPhone,
					'payment_method' => $paymentMethod,
					'message' => $messageText,
				]);
			}
			}
		} else {
			$status['warnings'][] = 'Customer phone number is missing, so no SMS confirmation was sent.';
		}

		if (!empty($user['email'])) {
			try {
				require_once __DIR__ . '/Email.php';
				$email = new Email();
				$email->send((string)$user['email'], 'Payment received', $messageText);
				$status['email'] = ['status' => 'sent'];
			} catch (Throwable $e) {
				error_log('Payment email notification failed for payment #' . $paymentId . ': ' . $e->getMessage());
				$status['email'] = ['status' => 'failed'];
				$status['warnings'][] = 'Email confirmation could not be sent.';
				if ($errorLogger) {
					$errorLogger->logSystemError('PaymentNotification', 'Email notification failed: ' . $e->getMessage(), __FILE__, __LINE__, [
						'payment_id' => $paymentId,
						'user_id' => $userId,
						'email' => (string)$user['email'],
						'payment_method' => $paymentMethod,
					]);
				}
			}
		}

		return $status;
	}
}
