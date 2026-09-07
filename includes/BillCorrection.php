<?php
require_once __DIR__ . '/Accounting.php';
require_once __DIR__ . '/ClientWallet.php';
require_once __DIR__ . '/ActivityLog.php';

/**
 * BillCorrection — lets a permitted staff member correct a wrongly entered
 * meter reading on an existing bill and keeps the bill, its line items,
 * the linked meter reading, the accounting ledgers, and the client wallet
 * (for any resulting overpayment) all consistent.
 */
class BillCorrection {
	private $conn;
	private $table = 'bill_reading_corrections';

	public function __construct($db) {
		$this->conn = $db;
		$this->ensureTable();
	}

	private function ensureTable(): void {
		$this->conn->exec("CREATE TABLE IF NOT EXISTS {$this->table} (
			id INT AUTO_INCREMENT PRIMARY KEY,
			bill_id INT NOT NULL,
			user_id INT NOT NULL,
			old_current_reading DECIMAL(10,2) NOT NULL,
			new_current_reading DECIMAL(10,2) NOT NULL,
			old_amount DECIMAL(10,2) NOT NULL,
			new_amount DECIMAL(10,2) NOT NULL,
			amount_delta DECIMAL(10,2) NOT NULL,
			overpayment_credited DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			reason TEXT NULL,
			corrected_by INT NOT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_bill (bill_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	}

	/**
	 * Find the bill plus its linked meter reading, if any.
	 * Returns null if the bill does not exist or has no linked reading
	 * (e.g. a registration fee bill), since only reading-based bills
	 * can be corrected here.
	 */
	public function getCorrectableBill(int $billId): ?array {
		$stmtBill = $this->conn->prepare('SELECT * FROM bills WHERE id = :id LIMIT 1');
		$stmtBill->bindValue(':id', $billId, PDO::PARAM_INT);
		$stmtBill->execute();
		$bill = $stmtBill->fetch(PDO::FETCH_ASSOC);
		if (!$bill || (float)$bill['rate_per_unit'] <= 0) {
			return null;
		}

		$stmtReading = $this->conn->prepare("SELECT id, current_reading FROM meter_readings WHERE bill_id = :bill_id ORDER BY id DESC LIMIT 1");
		$stmtReading->bindValue(':bill_id', $billId, PDO::PARAM_INT);
		$stmtReading->execute();
		$reading = $stmtReading->fetch(PDO::FETCH_ASSOC) ?: null;

		$stmtPaid = $this->conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE bill_id = :bill_id AND status = 'completed'");
		$stmtPaid->bindValue(':bill_id', $billId, PDO::PARAM_INT);
		$stmtPaid->execute();
		$bill['total_paid'] = round((float)$stmtPaid->fetchColumn(), 2);
		$bill['linked_reading_id'] = $reading['id'] ?? null;

		return $bill;
	}

	public function getHistoryForBill(int $billId): array {
		$stmt = $this->conn->prepare("SELECT brc.*, u.full_name AS corrected_by_name
			FROM {$this->table} brc
			LEFT JOIN users u ON u.id = brc.corrected_by
			WHERE brc.bill_id = :bill_id
			ORDER BY brc.id DESC");
		$stmt->bindValue(':bill_id', $billId, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	}

	public function getRecentCorrections(int $limit = 100): array {
		$limit = max(1, min(500, $limit));
		$stmt = $this->conn->prepare("SELECT brc.*, u.full_name AS corrected_by_name,
				bu.full_name AS client_name, bu.account_number
			FROM {$this->table} brc
			LEFT JOIN users u ON u.id = brc.corrected_by
			LEFT JOIN bills b ON b.id = brc.bill_id
			LEFT JOIN users bu ON bu.id = b.user_id
			ORDER BY brc.id DESC
			LIMIT {$limit}");
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	}

	/**
	 * Apply a corrected current reading to a bill.
	 *
	 * @throws InvalidArgumentException on validation failure
	 * @return array Summary of the correction that was applied
	 */
	public function correctReading(int $billId, float $newReading, string $reason, int $correctedBy): array {
		$bill = $this->getCorrectableBill($billId);
		if (!$bill) {
			throw new InvalidArgumentException('This bill cannot be corrected here (no linked meter reading found).');
		}

		$previousReading = (float)$bill['previous_reading'];
		$oldReading = (float)$bill['current_reading'];
		if ($newReading <= $previousReading) {
			throw new InvalidArgumentException('Corrected reading must be greater than the previous reading (' . number_format($previousReading, 2) . ').');
		}
		if (abs($newReading - $oldReading) < 0.01) {
			throw new InvalidArgumentException('Corrected reading is the same as the current reading; nothing to change.');
		}

		$rate = (float)$bill['rate_per_unit'];
		$serviceCharge = (float)$bill['service_charge'];
		$taxRate = (float)$bill['tax_rate'];
		$oldAmount = round((float)$bill['amount'], 2);

		$newConsumption = round($newReading - $previousReading, 2);
		$newUsageCharge = round($newConsumption * $rate, 2);
		$newBaseAmount = round($newUsageCharge + $serviceCharge, 2);
		$newTaxAmount = round($newBaseAmount * ($taxRate / 100), 2);
		$newAmount = round($newBaseAmount + $newTaxAmount, 2);
		$amountDelta = round($oldAmount - $newAmount, 2);

		$totalPaid = (float)$bill['total_paid'];
		$overpayment = $amountDelta > 0 ? round(max(0, $totalPaid - $newAmount), 2) : 0.0;
		$newStatus = $totalPaid + 0.01 >= $newAmount
			? 'paid'
			: ((string)$bill['due_date'] < date('Y-m-d') ? 'overdue' : 'pending');

		$ownTransaction = !$this->conn->inTransaction();
		if ($ownTransaction) {
			$this->conn->beginTransaction();
		}
		try {
			if (!empty($bill['linked_reading_id'])) {
				$upd = $this->conn->prepare('UPDATE meter_readings SET current_reading = :reading WHERE id = :id');
				$upd->execute([':reading' => $newReading, ':id' => $bill['linked_reading_id']]);
			}

			$upd = $this->conn->prepare('UPDATE bills SET current_reading = :reading, consumption = :consumption, base_amount = :base_amount, tax_amount = :tax_amount, amount = :amount, status = :status WHERE id = :id');
			$upd->execute([
				':reading' => $newReading,
				':consumption' => $newConsumption,
				':base_amount' => $newBaseAmount,
				':tax_amount' => $newTaxAmount,
				':amount' => $newAmount,
				':status' => $newStatus,
				':id' => $billId,
			]);

			$upd = $this->conn->prepare("UPDATE bill_line_items SET quantity = :qty, unit_rate = :rate, line_amount = :amount WHERE bill_id = :bill_id AND line_type = 'usage'");
			$upd->execute([
				':qty' => $newConsumption,
				':rate' => $rate,
				':amount' => $newUsageCharge,
				':bill_id' => $billId,
			]);

			$logStmt = $this->conn->prepare("INSERT INTO {$this->table}
				(bill_id, user_id, old_current_reading, new_current_reading, old_amount, new_amount, amount_delta, overpayment_credited, reason, corrected_by)
				VALUES (:bill_id, :user_id, :old_reading, :new_reading, :old_amount, :new_amount, :delta, :overpayment, :reason, :corrected_by)");
			$logStmt->execute([
				':bill_id' => $billId,
				':user_id' => (int)$bill['user_id'],
				':old_reading' => $oldReading,
				':new_reading' => $newReading,
				':old_amount' => $oldAmount,
				':new_amount' => $newAmount,
				':delta' => $amountDelta,
				':overpayment' => $overpayment,
				':reason' => $reason !== '' ? $reason : null,
				':corrected_by' => $correctedBy,
			]);
			$correctionId = (int)$this->conn->lastInsertId();

			$accounting = new Accounting($this->conn);
			$reflect = new ReflectionMethod($accounting, 'resolveSystemAccount');
			$reflect->setAccessible(true);
			$receivableId = $reflect->invoke($accounting, '1100', 'Accounts Receivable', 'asset', 'debit');

			if (abs($amountDelta) > 0.01) {
				$revenueId = $reflect->invoke($accounting, '4000', 'Water Sales Revenue', 'revenue', 'credit');
				$correctionAmount = abs($amountDelta);
				$lines = $amountDelta > 0
					// Reading corrected down: revenue and receivable both reduce.
					? [
						['account_id' => $revenueId, 'debit' => $correctionAmount, 'credit' => 0, 'memo' => 'Bill #' . $billId . ' reading correction'],
						['account_id' => $receivableId, 'debit' => 0, 'credit' => $correctionAmount, 'memo' => 'Bill #' . $billId . ' reading correction'],
					]
					// Reading corrected up: revenue and receivable both increase.
					: [
						['account_id' => $receivableId, 'debit' => $correctionAmount, 'credit' => 0, 'memo' => 'Bill #' . $billId . ' reading correction'],
						['account_id' => $revenueId, 'debit' => 0, 'credit' => $correctionAmount, 'memo' => 'Bill #' . $billId . ' reading correction'],
					];

				$accounting->postJournalEntry(
					date('Y-m-d'),
					'Meter reading correction for bill #' . $billId . ': ' . number_format($oldReading, 2) . ' -> ' . number_format($newReading, 2) . ' m3',
					$lines,
					'bill_reading_correction',
					$correctionId,
					$correctedBy
				);
			}

			if ($overpayment > 0.01) {
				$customerCreditsId = $reflect->invoke($accounting, '1200', 'Customer Credits', 'liability', 'credit');
				$accounting->postJournalEntry(
					date('Y-m-d'),
					'Overpayment of KES ' . number_format($overpayment, 2) . ' credited to customer wallet after reading correction (bill #' . $billId . ')',
					[
						['account_id' => $receivableId, 'debit' => $overpayment, 'credit' => 0, 'memo' => 'Clear overpaid balance for bill #' . $billId],
						['account_id' => $customerCreditsId, 'debit' => 0, 'credit' => $overpayment, 'memo' => 'Customer wallet credit for bill #' . $billId],
					],
					'bill_reading_wallet_credit',
					$correctionId,
					$correctedBy
				);

				$wallet = new ClientWallet($this->conn);
				$wallet->addCredit(
					(int)$bill['user_id'],
					$overpayment,
					'Reading correction - Bill #' . $billId,
					0,
					'Overpayment after correcting meter reading on bill #' . $billId,
					$correctedBy
				);
			}

			$activityLog = new ActivityLog($this->conn);
			$activityLog->log(
				$correctedBy,
				'bill_reading_correction',
				'bill',
				$billId,
				'Corrected meter reading for bill #' . $billId . ' from ' . $oldReading . ' to ' . $newReading . ' m3',
				[
					'old_amount' => $oldAmount,
					'new_amount' => $newAmount,
					'delta' => $amountDelta,
					'overpayment_credited' => $overpayment,
					'new_status' => $newStatus,
					'reason' => $reason,
				]
			);

			if ($ownTransaction) {
				$this->conn->commit();
			}

			return [
				'bill_id' => $billId,
				'old_reading' => $oldReading,
				'new_reading' => $newReading,
				'old_amount' => $oldAmount,
				'new_amount' => $newAmount,
				'amount_delta' => $amountDelta,
				'overpayment_credited' => $overpayment,
				'new_status' => $newStatus,
			];
		} catch (Throwable $e) {
			if ($ownTransaction && $this->conn->inTransaction()) {
				$this->conn->rollBack();
			}
			throw $e;
		}
	}
}
