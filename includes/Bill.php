<?php
require_once __DIR__ . '/Accounting.php';
require_once __DIR__ . '/BillingSettings.php';
require_once __DIR__ . '/CustomerCredit.php';
require_once __DIR__ . '/ClientMeter.php';

class Bill {
	private $conn;
	private $table = "bills";
	private static bool $schemaEnsured = false;

	public function __construct($db) {
		$this->conn = $db;
		if (!self::$schemaEnsured) {
			$this->ensureServiceChargeColumn();
			$this->ensureEnhancedBillingColumns();
			$this->ensureTariffTables();
			$this->ensureBillLineItemsTable();
			self::$schemaEnsured = true;
		}
	}

	public function getLastBillByUser($user_id) {
		$query = "SELECT * FROM " . $this->table . " WHERE user_id = :user_id ORDER BY billing_month DESC, id DESC LIMIT 1";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(":user_id", $user_id);
		$stmt->execute();
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}

	private function getLastReadingByMeter(int $userId, string $meterNumber): ?array {
		$meterNumber = trim($meterNumber);
		if ($userId <= 0 || $meterNumber === '') {
			return null;
		}

		$query = "SELECT current_reading, billing_month, created_at
			FROM meter_readings
			WHERE user_id = :user_id AND meter_number = :meter_number
			ORDER BY billing_month DESC, id DESC
			LIMIT 1";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
		$stmt->bindParam(':meter_number', $meterNumber);
		$stmt->execute();

		return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	}

	private function getReplacementOpeningReading(int $userId, string $meterNumber): ?float {
		try {
			$meterService = new ClientMeter($this->conn);
			return $meterService->getReplacementOpeningReading($userId, $meterNumber);
		} catch (\Throwable $e) {
			return null;
		}
	}

	public function createBillForUser($user_id, $account_number, $current_reading, $billing_month, $due_date, $rate_per_unit, $service_charge, $status = 'pending', ?string $meter_number = null) {
		$billing_month = trim((string)$billing_month);
		$due_date = trim((string)$due_date);
		if ($billing_month === '') {
			$billing_month = date('Y-m-01', strtotime('first day of last month'));
		}
		if ($due_date === '') {
			$due_date = date('Y-m-d', strtotime('+3 days'));
		}

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
		$meterNumber = trim((string)$meter_number);
		if ($meterNumber !== '') {
			$lastReading = $this->getLastReadingByMeter((int)$user_id, $meterNumber);
			if ($lastReading) {
				$previous_reading = (float)$lastReading['current_reading'];
			} else {
				$previous_reading = $this->getReplacementOpeningReading((int)$user_id, $meterNumber) ?? 0.00;
			}
		} else {
			$previous_reading = $last_bill ? (float)$last_bill['current_reading'] : 0.00;
		}
		$consumption = max(0, (float)$current_reading - $previous_reading);
		$connectionType = 'domestic';
		try {
			$stmtUser = $this->conn->prepare("SELECT connection_type, unit_rate FROM users WHERE id = :id LIMIT 1");
			$stmtUser->execute([':id' => (int)$user_id]);
			$userRow = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];
			if (!empty($userRow['connection_type'])) {
				$connectionType = (string)$userRow['connection_type'];
			}
			$clientUnitRate = array_key_exists('unit_rate', $userRow) && $userRow['unit_rate'] !== null
				? max(0.0, (float)$userRow['unit_rate'])
				: null;
		} catch (\Throwable $e) {
			// Keep legacy behavior if user lookup fails.
			$clientUnitRate = null;
		}

		$billingDate = date('Y-m-d', strtotime((string)$billing_month));
		$activeTariff = $this->getActiveTariffPlan($billingDate, $connectionType);
		$effectiveRate = $clientUnitRate !== null ? $clientUnitRate : (float)$rate_per_unit;
		$usageCharge = $this->calculateUsageCharge($consumption, $effectiveRate, $activeTariff, $clientUnitRate !== null);
		$serviceChargeApplied = (float)$service_charge;
		$subtotalAmount = $usageCharge + $serviceChargeApplied;
		$taxRate = $this->resolveVatRate($activeTariff);
		$taxAmount = round($subtotalAmount * ($taxRate / 100), 2);
		$amount = round($subtotalAmount + $taxAmount, 2);

		// Check if deducting this amount will exceed credit limit
		if ($creditProfile && $creditProfile['available_credit'] - $amount < $creditProfile['credit_limit'] * -0.1) {
			// Allow slight overdraft (10% of credit limit) but log a warning
		}

		$query = "INSERT INTO " . $this->table . "
			(user_id, account_number, billing_month, previous_reading, current_reading, consumption, rate_per_unit, service_charge, base_amount, tax_rate, tax_amount, amount, due_date, status, tariff_plan_id)
			VALUES
			(:user_id, :account_number, :billing_month, :previous_reading, :current_reading, :consumption, :rate_per_unit, :service_charge, :base_amount, :tax_rate, :tax_amount, :amount, :due_date, :status, :tariff_plan_id)";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(":user_id", $user_id);
		$stmt->bindParam(":account_number", $account_number);
		$stmt->bindParam(":billing_month", $billing_month);
		$stmt->bindParam(":previous_reading", $previous_reading);
		$stmt->bindParam(":current_reading", $current_reading);
		$stmt->bindParam(":consumption", $consumption);
		$stmt->bindParam(":rate_per_unit", $effectiveRate);
		$stmt->bindParam(":service_charge", $service_charge);
		$stmt->bindParam(":base_amount", $subtotalAmount);
		$stmt->bindParam(":tax_rate", $taxRate);
		$stmt->bindParam(":tax_amount", $taxAmount);
		$stmt->bindParam(":amount", $amount);
		$stmt->bindParam(":due_date", $due_date);
		$stmt->bindParam(":status", $status);
		$tariffPlanId = $activeTariff ? (int)$activeTariff['id'] : null;
		if ($tariffPlanId === null) {
			$stmt->bindValue(':tariff_plan_id', null, PDO::PARAM_NULL);
		} else {
			$stmt->bindValue(':tariff_plan_id', $tariffPlanId, PDO::PARAM_INT);
		}

		if ($stmt->execute()) {
			$bill_id = $this->conn->lastInsertId();
			$this->insertBillLineItems((int)$bill_id, [
				[
					'line_type' => 'usage',
					'description' => 'Water usage charge',
					'quantity' => $consumption,
					'unit_rate' => $consumption > 0 ? round($usageCharge / $consumption, 4) : 0.0,
					'line_amount' => $usageCharge,
				],
				[
					'line_type' => 'service_charge',
					'description' => 'Service charge',
					'quantity' => 1,
					'unit_rate' => $serviceChargeApplied,
					'line_amount' => $serviceChargeApplied,
				],
				[
					'line_type' => 'tax',
					'description' => 'VAT (' . number_format($taxRate, 2) . '%)',
					'quantity' => 1,
					'unit_rate' => $taxAmount,
					'line_amount' => $taxAmount,
				],
			]);

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
				error_log('Accounting invoice post failed for bill #' . (int)$bill_id . ': ' . $e->getMessage());
			}

			return [
				'success' => true,
				'bill_id' => $bill_id,
				'billing_month' => $billing_month,
				'previous_reading' => $previous_reading,
				'current_reading' => (float)$current_reading,
				'consumption' => $consumption,
				'rate_per_unit' => $effectiveRate,
				'service_charge' => (float)$service_charge,
				'base_amount' => $subtotalAmount,
				'tax_rate' => $taxRate,
				'tax_amount' => $taxAmount,
				'amount' => $amount
			];
		}

		return [
			'success' => false,
			'message' => 'Failed to create bill'
		];
	}

	public static function buildBillNotificationMessage(
		array $user,
		array $billResult,
		string $dueDate,
		float $previousBalance,
		float $totalToPay,
		string $paybill,
		string $paymentUrl,
		?string $billDate = null,
		?string $template = null
	): string {
		$clientName = trim((string)($user['full_name'] ?? ''));
		if ($clientName === '') {
			$clientName = trim((string)($user['company_name'] ?? ''));
		}
		if ($clientName === '') {
			$clientName = trim((string)($user['account_number'] ?? 'Client'));
		}

		$accountNumber = trim((string)($user['account_number'] ?? ''));
		$resolvedBillDate = trim((string)($billDate ?? ''));
		if ($resolvedBillDate === '') {
			$resolvedBillDate = date('d-m-Y');
		}

		$billingMonthValue = trim((string)($billResult['billing_month'] ?? ''));
		if ($billingMonthValue === '') {
			$billingMonthValue = date('Y-m-01', strtotime('first day of last month'));
		}
		$billingMonthTimestamp = strtotime($billingMonthValue);
		$billingMonthLabel = $billingMonthTimestamp
			? date('M', $billingMonthTimestamp)
			: date('M', strtotime('first day of last month'));

		$templateText = trim(str_replace(["\r\n", "\r"], "\n", (string)($template ?? '')));
		if ($templateText === '') {
			$templateText = BillingSettings::getDefaultBillNotificationTemplate();
		}

		$rendered = strtr($templateText, [
			'{client_name}' => $clientName,
			'{month}' => $billingMonthLabel,
			'{total}' => number_format($totalToPay, 2),
			'{bill_amount}' => number_format((float)($billResult['amount'] ?? 0), 2),
			'{amount_due}' => number_format($totalToPay, 2),
			'{account}' => $accountNumber,
			'{bill_date}' => $resolvedBillDate,
			'{previous_reading}' => number_format((float)($billResult['previous_reading'] ?? 0), 2),
			'{current_reading}' => number_format((float)($billResult['current_reading'] ?? 0), 2),
			'{units}' => number_format((float)($billResult['consumption'] ?? 0), 2),
			'{service_fee}' => number_format((float)($billResult['service_charge'] ?? 0), 2),
			'{previous_balance}' => number_format($previousBalance, 2),
			'{due_date}' => date('d-m-Y', strtotime($dueDate)),
			'{payment_url}' => $paymentUrl,
			'{paybill}' => $paybill,
		]);

		$lines = explode("\n", $rendered);
		$lines = array_map(static function ($line) {
			return rtrim((string)$line);
		}, $lines);

		return trim(implode("\n", $lines));
	}

	public function createRegistrationFeeBill($user_id, $account_number, $amount, $due_date, $status = 'pending') {
		$billing_month = date('Y-m-01');
		$previous_reading = 0.00;
		$current_reading = 0.00;
		$consumption = 0.00;
		$rate_per_unit = 0.00;
		$service_charge = (float)$amount;

		$taxRate = $this->resolveVatRate(null);
		$subtotalAmount = (float)$amount;
		$taxAmount = round($subtotalAmount * ($taxRate / 100), 2);
		$totalAmount = round($subtotalAmount + $taxAmount, 2);

		$query = "INSERT INTO " . $this->table . "
			(user_id, account_number, billing_month, previous_reading, current_reading, consumption, rate_per_unit, service_charge, base_amount, tax_rate, tax_amount, amount, due_date, status, tariff_plan_id)
			VALUES
			(:user_id, :account_number, :billing_month, :previous_reading, :current_reading, :consumption, :rate_per_unit, :service_charge, :base_amount, :tax_rate, :tax_amount, :amount, :due_date, :status, NULL)";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(":user_id", $user_id);
		$stmt->bindParam(":account_number", $account_number);
		$stmt->bindParam(":billing_month", $billing_month);
		$stmt->bindParam(":previous_reading", $previous_reading);
		$stmt->bindParam(":current_reading", $current_reading);
		$stmt->bindParam(":consumption", $consumption);
		$stmt->bindParam(":rate_per_unit", $rate_per_unit);
		$stmt->bindParam(":service_charge", $service_charge);
		$stmt->bindParam(":base_amount", $subtotalAmount);
		$stmt->bindParam(":tax_rate", $taxRate);
		$stmt->bindParam(":tax_amount", $taxAmount);
		$stmt->bindParam(":amount", $totalAmount);
		$stmt->bindParam(":due_date", $due_date);
		$stmt->bindParam(":status", $status);

		if ($stmt->execute()) {
			$registrationBillId = (int)$this->conn->lastInsertId();
			$this->insertBillLineItems((int)$registrationBillId, [
				[
					'line_type' => 'registration_fee',
					'description' => 'Registration fee',
					'quantity' => 1,
					'unit_rate' => $subtotalAmount,
					'line_amount' => $subtotalAmount,
				],
				[
					'line_type' => 'tax',
					'description' => 'VAT (' . number_format($taxRate, 2) . '%)',
					'quantity' => 1,
					'unit_rate' => $taxAmount,
					'line_amount' => $taxAmount,
				],
			]);
			try {
				$accounting = new Accounting($this->conn);
				$accounting->postInvoiceIssued(
					$registrationBillId,
					(int)$user_id,
					(float)$totalAmount,
					'Registration fee bill issued for ' . (string)$account_number,
					'registration',
					(int)$user_id
				);
			} catch (\Throwable $e) {
				error_log('Accounting invoice post failed for registration bill #' . $registrationBillId . ': ' . $e->getMessage());
			}

			return $registrationBillId;
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

	public function getBillLineItems(int $billId): array {
		if ($billId <= 0) {
			return [];
		}

		$stmt = $this->conn->prepare("SELECT * FROM bill_line_items WHERE bill_id = :bill_id ORDER BY id ASC");
		$stmt->bindValue(':bill_id', $billId, PDO::PARAM_INT);
		$stmt->execute();

		return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

	public function isRegistrationFeeBill(array $billRow): bool {
		$billId = isset($billRow['id']) ? (int)$billRow['id'] : 0;
		if ($billId > 0) {
			$lineItems = $this->getBillLineItems($billId);
			foreach ($lineItems as $lineItem) {
				if (($lineItem['line_type'] ?? '') === 'registration_fee') {
					return true;
				}
			}
		}

		$consumption = isset($billRow['consumption']) ? (float)$billRow['consumption'] : 0.0;
		$ratePerUnit = isset($billRow['rate_per_unit']) ? (float)$billRow['rate_per_unit'] : 0.0;
		$baseAmount = isset($billRow['base_amount']) ? (float)$billRow['base_amount'] : 0.0;
		$serviceCharge = isset($billRow['service_charge']) ? (float)$billRow['service_charge'] : 0.0;

		return $consumption == 0.0 && $ratePerUnit == 0.0 && $serviceCharge > 0.0 && $baseAmount == 0.0;
	}

	public function getBillTypeLabel(array $billRow): string {
		return $this->isRegistrationFeeBill($billRow) ? 'Registration Fee' : 'Water Bill';
	}

	public function getUsersBillingSummary() {
		$query = "SELECT u.id, u.account_number, u.full_name, u.phone_number, u.meter_number,
						 lb.id AS last_bill_id, lb.amount AS last_amount, lb.status AS last_status, lb.due_date AS last_due_date,
						 COALESCE(SUM(CASE WHEN b.status IN ('pending','overdue') THEN GREATEST(0, COALESCE(b.amount, 0) - COALESCE(p_paid.completed_paid, 0) + COALESCE(pa_adj.approved_adjustments, 0)) ELSE 0 END), 0) AS total_unpaid
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
				  LEFT JOIN (
					  SELECT bill_id, COALESCE(SUM(amount), 0) AS completed_paid
					  FROM payments
					  WHERE status = 'completed' AND bill_id IS NOT NULL
					  GROUP BY bill_id
				  ) p_paid ON p_paid.bill_id = b.id
				  LEFT JOIN (
					  SELECT p.bill_id, COALESCE(SUM(pa.amount), 0) AS approved_adjustments
					  FROM payment_adjustments pa
					  INNER JOIN payments p ON p.id = pa.payment_id
					  WHERE pa.status = 'approved' AND p.bill_id IS NOT NULL
					  GROUP BY p.bill_id
				  ) pa_adj ON pa_adj.bill_id = b.id
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

	private function ensureEnhancedBillingColumns(): void {
		$columns = [
			'base_amount' => "ALTER TABLE {$this->table} ADD COLUMN base_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER service_charge",
			'tax_rate' => "ALTER TABLE {$this->table} ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER base_amount",
			'tax_amount' => "ALTER TABLE {$this->table} ADD COLUMN tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tax_rate",
			'tariff_plan_id' => "ALTER TABLE {$this->table} ADD COLUMN tariff_plan_id INT NULL AFTER status",
		];

		foreach ($columns as $columnName => $sql) {
			if (!$this->hasColumn($this->table, $columnName)) {
				$this->conn->exec($sql);
			}
		}
	}

	private function ensureTariffTables(): void {
		$this->conn->exec("CREATE TABLE IF NOT EXISTS tariff_plans (
			id INT AUTO_INCREMENT PRIMARY KEY,
			name VARCHAR(120) NOT NULL,
			category ENUM('domestic','commercial','industrial','all') NOT NULL DEFAULT 'all',
			effective_from DATE NOT NULL,
			effective_to DATE NULL,
			base_rate_per_unit DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
			service_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
			INDEX idx_tariff_dates (effective_from, effective_to),
			INDEX idx_tariff_category_active (category, is_active)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$this->conn->exec("CREATE TABLE IF NOT EXISTS tariff_blocks (
			id INT AUTO_INCREMENT PRIMARY KEY,
			tariff_plan_id INT NOT NULL,
			from_unit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			to_unit DECIMAL(10,2) NULL,
			rate_per_unit DECIMAL(10,4) NOT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_plan_from_unit (tariff_plan_id, from_unit),
			CONSTRAINT fk_tariff_blocks_plan FOREIGN KEY (tariff_plan_id) REFERENCES tariff_plans(id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$settingsService = new BillingSettings($this->conn);
		$settings = $settingsService->getSettings();

		$stmtCount = $this->conn->query("SELECT COUNT(*) FROM tariff_plans");
		$totalPlans = $stmtCount ? (int)$stmtCount->fetchColumn() : 0;
		if ($totalPlans === 0) {
			$stmt = $this->conn->prepare("INSERT INTO tariff_plans
				(name, category, effective_from, base_rate_per_unit, service_charge, vat_rate, is_active)
				VALUES (:name, 'all', :effective_from, :base_rate, :service_charge, :vat_rate, 1)");
			$stmt->execute([
				':name' => 'Default Standard Tariff',
				':effective_from' => date('Y-m-01'),
				':base_rate' => (float)($settings['rate_per_unit'] ?? 50.0),
				':service_charge' => (float)($settings['service_charge'] ?? 0.0),
				':vat_rate' => (float)($settings['vat_rate'] ?? 0.0),
			]);

			$planId = (int)$this->conn->lastInsertId();
			$stmtBlock = $this->conn->prepare("INSERT INTO tariff_blocks (tariff_plan_id, from_unit, to_unit, rate_per_unit) VALUES (:plan_id, 0, NULL, :rate)");
			$stmtBlock->execute([
				':plan_id' => $planId,
				':rate' => (float)($settings['rate_per_unit'] ?? 50.0),
			]);
		}
	}

	private function ensureBillLineItemsTable(): void {
		$this->conn->exec("CREATE TABLE IF NOT EXISTS bill_line_items (
			id INT AUTO_INCREMENT PRIMARY KEY,
			bill_id INT NOT NULL,
			line_type ENUM('usage','service_charge','registration_fee','tax','penalty','adjustment') NOT NULL,
			description VARCHAR(255) NOT NULL,
			quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			unit_rate DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
			line_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_bill_line (bill_id),
			CONSTRAINT fk_bill_line_items_bill FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	}

	private function hasColumn(string $tableName, string $columnName): bool {
		$stmt = $this->conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name");
		$stmt->execute([
			':table_name' => $tableName,
			':column_name' => $columnName,
		]);

		return (int)$stmt->fetchColumn() > 0;
	}

	private function getActiveTariffPlan(string $billingDate, string $connectionType): ?array {
		$stmt = $this->conn->prepare("SELECT * FROM tariff_plans
			WHERE is_active = 1
				AND effective_from <= :billing_date
				AND (effective_to IS NULL OR effective_to >= :billing_date)
				AND category IN ('all', :connection_type)
			ORDER BY
				CASE WHEN category = :connection_type2 THEN 0 ELSE 1 END,
				effective_from DESC,
				id DESC
			LIMIT 1");
		$stmt->execute([
			':billing_date' => $billingDate,
			':connection_type' => $connectionType,
			':connection_type2' => $connectionType,
		]);

		$plan = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
		if (!$plan) {
			return null;
		}

		$stmtBlocks = $this->conn->prepare("SELECT * FROM tariff_blocks WHERE tariff_plan_id = :plan_id ORDER BY from_unit ASC");
		$stmtBlocks->execute([':plan_id' => (int)$plan['id']]);
		$plan['blocks'] = $stmtBlocks->fetchAll(PDO::FETCH_ASSOC) ?: [];

		return $plan;
	}

	private function calculateUsageCharge(float $consumption, float $fallbackRate, ?array $tariffPlan, bool $useFlatRate = false): float {
		if ($consumption <= 0) {
			return 0.0;
		}
		if ($useFlatRate) {
			return round($consumption * $fallbackRate, 2);
		}

		$blocks = is_array($tariffPlan['blocks'] ?? null) ? $tariffPlan['blocks'] : [];
		if (empty($blocks)) {
			$rate = $tariffPlan ? (float)($tariffPlan['base_rate_per_unit'] ?? $fallbackRate) : $fallbackRate;
			return round($consumption * $rate, 2);
		}

		$remaining = $consumption;
		$total = 0.0;
		foreach ($blocks as $block) {
			$from = (float)($block['from_unit'] ?? 0);
			$to = isset($block['to_unit']) ? (float)$block['to_unit'] : null;
			$rate = (float)($block['rate_per_unit'] ?? $fallbackRate);

			if ($remaining <= 0) {
				break;
			}

			$start = max(0.0, $from);
			$span = $to === null ? $remaining : max(0.0, $to - $start);
			$unitsInBlock = min($remaining, $span > 0 ? $span : $remaining);
			if ($unitsInBlock <= 0) {
				continue;
			}

			$total += $unitsInBlock * $rate;
			$remaining -= $unitsInBlock;
		}

		if ($remaining > 0) {
			$lastRate = (float)($blocks[count($blocks) - 1]['rate_per_unit'] ?? $fallbackRate);
			$total += $remaining * $lastRate;
		}

		return round($total, 2);
	}

	private function resolveVatRate(?array $tariffPlan): float {
		if ($tariffPlan && isset($tariffPlan['vat_rate'])) {
			return max(0.0, (float)$tariffPlan['vat_rate']);
		}

		try {
			$stmt = $this->conn->query("SELECT vat_rate FROM billing_settings WHERE id = 1 LIMIT 1");
			$vat = $stmt ? $stmt->fetchColumn() : 0;
			return max(0.0, (float)$vat);
		} catch (\Throwable $e) {
			return 0.0;
		}
	}

	private function insertBillLineItems(int $billId, array $items): void {
		if ($billId <= 0 || empty($items)) {
			return;
		}

		$stmt = $this->conn->prepare("INSERT INTO bill_line_items (bill_id, line_type, description, quantity, unit_rate, line_amount)
			VALUES (:bill_id, :line_type, :description, :quantity, :unit_rate, :line_amount)");

		foreach ($items as $item) {
			$lineAmount = round((float)($item['line_amount'] ?? 0), 2);
			if ($lineAmount == 0.0 && (string)($item['line_type'] ?? '') === 'tax') {
				continue;
			}

			$stmt->execute([
				':bill_id' => $billId,
				':line_type' => (string)($item['line_type'] ?? 'adjustment'),
				':description' => (string)($item['description'] ?? 'Line item'),
				':quantity' => (float)($item['quantity'] ?? 0),
				':unit_rate' => (float)($item['unit_rate'] ?? 0),
				':line_amount' => $lineAmount,
			]);
		}
	}
}
?>
