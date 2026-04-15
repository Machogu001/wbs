<?php
require_once __DIR__ . '/../config/database.php';

class Accounting {
	private $db;
	private $chartTable = 'chart_of_accounts';
	private $entryTable = 'journal_entries';
	private $lineTable = 'journal_entry_lines';
	private $periodLockTable = 'accounting_period_locks';

	public function __construct($db = null) {
		if ($db === null) {
			$database = new Database();
			$db = $database->getConnection();
		}

		if ($db === null) {
			throw new Exception('Database connection required for Accounting');
		}

		$this->db = $db;
		self::ensureTables($this->db);
	}

	public static function ensureTables($db = null) {
		if ($db === null) {
			$database = new Database();
			$db = $database->getConnection();
		}

		if ($db === null) {
			throw new Exception('Database connection required for Accounting');
		}

		$db->exec("CREATE TABLE IF NOT EXISTS chart_of_accounts (
			id INT AUTO_INCREMENT PRIMARY KEY,
			code VARCHAR(20) NOT NULL UNIQUE,
			name VARCHAR(191) NOT NULL,
			account_type ENUM('asset','liability','equity','revenue','expense','cost_of_sales') NOT NULL,
			normal_balance ENUM('debit','credit') NOT NULL,
			parent_id INT NULL,
			description TEXT NULL,
			is_system TINYINT(1) NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
			INDEX idx_type_active (account_type, is_active),
			INDEX idx_parent (parent_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$db->exec("CREATE TABLE IF NOT EXISTS journal_entries (
			id INT AUTO_INCREMENT PRIMARY KEY,
			entry_no VARCHAR(40) NOT NULL UNIQUE,
			entry_date DATE NOT NULL,
			reference_type VARCHAR(50) NULL,
			reference_id INT NULL,
			memo VARCHAR(255) NULL,
			status ENUM('draft','posted','reversed') NOT NULL DEFAULT 'posted',
			posted_by INT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			posted_at TIMESTAMP NULL,
			INDEX idx_entry_date (entry_date),
			INDEX idx_reference (reference_type, reference_id),
			INDEX idx_status (status)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		try {
			$indexCheck = $db->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'journal_entries' AND index_name = 'uniq_reference_status'");
			$indexCheck->execute();
			$hasIndex = (int)$indexCheck->fetchColumn() > 0;
			if (!$hasIndex) {
				$db->exec("ALTER TABLE journal_entries ADD UNIQUE KEY uniq_reference_status (reference_type, reference_id, status)");
			}
		} catch (\Throwable $e) {
			// Best effort: keep backward compatibility when legacy duplicate rows exist.
		}

		$db->exec("CREATE TABLE IF NOT EXISTS journal_entry_lines (
			id INT AUTO_INCREMENT PRIMARY KEY,
			journal_entry_id INT NOT NULL,
			account_id INT NOT NULL,
			line_memo VARCHAR(255) NULL,
			debit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			credit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_entry (journal_entry_id),
			INDEX idx_account (account_id),
			CONSTRAINT fk_journal_entry_lines_entry FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
			CONSTRAINT fk_journal_entry_lines_account FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$db->exec("CREATE TABLE IF NOT EXISTS accounting_period_locks (
			period_key CHAR(7) PRIMARY KEY,
			is_locked TINYINT(1) NOT NULL DEFAULT 1,
			locked_by INT NULL,
			note VARCHAR(255) NULL,
			locked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
			INDEX idx_is_locked (is_locked)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$seedAccounts = [
			['1000', 'Cash and Cash Equivalents', 'asset', 'debit', null, 'Cash, bank balances, and mobile money clearing accounts', 1],
			['1100', 'Accounts Receivable', 'asset', 'debit', null, 'Customer balances due from billed services', 1],
			['1200', 'Customer Credits', 'liability', 'credit', null, 'Prepaid or excess customer balances', 1],
			['2000', 'Accounts Payable', 'liability', 'credit', null, 'Suppliers and vendor obligations', 1],
			['2100', 'Tax Payable', 'liability', 'credit', null, 'Taxes collected or payable', 1],
			['3000', 'Owner Equity', 'equity', 'credit', null, 'Owner capital and retained equity', 1],
			['4000', 'Water Sales Revenue', 'revenue', 'credit', null, 'Revenue from metered water sales', 1],
			['4100', 'Registration Fee Revenue', 'revenue', 'credit', null, 'One-off registration and connection income', 1],
			['4200', 'Other Income', 'revenue', 'credit', null, 'Miscellaneous income', 1],
			['5000', 'Water Supply Expense', 'expense', 'debit', null, 'Bulk water and production cost', 1],
			['5100', 'Administration Expense', 'expense', 'debit', null, 'Office and administrative overhead', 1],
			['5200', 'Salary Expense', 'expense', 'debit', null, 'Staff salaries and wages', 1],
			['5300', 'Bank Charges', 'expense', 'debit', null, 'Bank and payment processor fees', 1],
			['5400', 'Bad Debt Expense', 'expense', 'debit', null, 'Write-offs and doubtful debts', 1],
		];

		$stmt = $db->prepare("INSERT IGNORE INTO chart_of_accounts
			(code, name, account_type, normal_balance, parent_id, description, is_system)
			VALUES (:code, :name, :account_type, :normal_balance, :parent_id, :description, :is_system)");
		foreach ($seedAccounts as $account) {
			$stmt->execute([
				':code' => $account[0],
				':name' => $account[1],
				':account_type' => $account[2],
				':normal_balance' => $account[3],
				':parent_id' => $account[4],
				':description' => $account[5],
				':is_system' => $account[6],
			]);
		}
	}

	public function getSummary(): array {
		$summary = [
			'asset' => ['count' => 0, 'balance' => 0.0],
			'liability' => ['count' => 0, 'balance' => 0.0],
			'equity' => ['count' => 0, 'balance' => 0.0],
			'revenue' => ['count' => 0, 'balance' => 0.0],
			'expense' => ['count' => 0, 'balance' => 0.0],
			'cost_of_sales' => ['count' => 0, 'balance' => 0.0],
		];

		$stmt = $this->db->query("SELECT account_type, COUNT(*) AS total
			FROM {$this->chartTable}
			WHERE is_active = 1
			GROUP BY account_type");
		foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
			$type = (string)($row['account_type'] ?? '');
			if (isset($summary[$type])) {
				$summary[$type]['count'] = (int)($row['total'] ?? 0);
			}
		}

		$balances = $this->getTrialBalance();
		foreach ($balances as $row) {
			$type = (string)($row['account_type'] ?? '');
			if (!isset($summary[$type])) {
				continue;
			}
			$summary[$type]['balance'] += (float)($row['balance'] ?? 0);
		}

		return $summary;
	}

	public function getAccounts(bool $activeOnly = true): array {
		$sql = "SELECT coa.*, parent.code AS parent_code, parent.name AS parent_name
			FROM {$this->chartTable} coa
			LEFT JOIN {$this->chartTable} parent ON parent.id = coa.parent_id";
		if ($activeOnly) {
			$sql .= " WHERE coa.is_active = 1";
		}
		$sql .= " ORDER BY coa.account_type, coa.code";
		$stmt = $this->db->query($sql);
		return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
	}

	public function getAccountById(int $accountId) {
		$stmt = $this->db->prepare("SELECT * FROM {$this->chartTable} WHERE id = :id LIMIT 1");
		$stmt->bindParam(':id', $accountId, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	}

	public function getAccountByCode(string $code) {
		$stmt = $this->db->prepare("SELECT * FROM {$this->chartTable} WHERE code = :code LIMIT 1");
		$stmt->bindParam(':code', $code);
		$stmt->execute();
		return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	}

	public function createAccount(string $code, string $name, string $accountType, string $normalBalance, ?int $parentId = null, ?string $description = null, int $isSystem = 0): bool {
		$stmt = $this->db->prepare("INSERT INTO {$this->chartTable}
			(code, name, account_type, normal_balance, parent_id, description, is_system)
			VALUES (:code, :name, :account_type, :normal_balance, :parent_id, :description, :is_system)");
		return $stmt->execute([
			':code' => $code,
			':name' => $name,
			':account_type' => $accountType,
			':normal_balance' => $normalBalance,
			':parent_id' => $parentId,
			':description' => $description,
			':is_system' => $isSystem,
		]);
	}

	public function updateAccount(int $accountId, string $name, string $accountType, string $normalBalance, ?int $parentId = null, ?string $description = null, int $isActive = 1): bool {
		$stmt = $this->db->prepare("UPDATE {$this->chartTable}
			SET name = :name,
				account_type = :account_type,
				normal_balance = :normal_balance,
				parent_id = :parent_id,
				description = :description,
				is_active = :is_active,
				updated_at = NOW()
			WHERE id = :id");
		return $stmt->execute([
			':name' => $name,
			':account_type' => $accountType,
			':normal_balance' => $normalBalance,
			':parent_id' => $parentId,
			':description' => $description,
			':is_active' => $isActive,
			':id' => $accountId,
		]);
	}

	public function setAccountStatus(int $accountId, int $isActive): bool {
		$stmt = $this->db->prepare("UPDATE {$this->chartTable} SET is_active = :is_active, updated_at = NOW() WHERE id = :id");
		return $stmt->execute([
			':is_active' => $isActive,
			':id' => $accountId,
		]);
	}

	public function getTrialBalance(?string $fromDate = null, ?string $toDate = null): array {
		$sql = "SELECT coa.id, coa.code, coa.name, coa.account_type, coa.normal_balance, coa.is_active,
			COALESCE(SUM(CASE WHEN je.status = 'posted' THEN jel.debit ELSE 0 END), 0) AS total_debit,
			COALESCE(SUM(CASE WHEN je.status = 'posted' THEN jel.credit ELSE 0 END), 0) AS total_credit
			FROM {$this->chartTable} coa
			LEFT JOIN {$this->lineTable} jel ON jel.account_id = coa.id
			LEFT JOIN {$this->entryTable} je ON je.id = jel.journal_entry_id";
		$params = [];
		$conditions = ["coa.is_active = 1"];
		if ($fromDate !== null && $fromDate !== '') {
			$conditions[] = "je.entry_date >= :from_date";
			$params[':from_date'] = $fromDate;
		}
		if ($toDate !== null && $toDate !== '') {
			$conditions[] = "je.entry_date <= :to_date";
			$params[':to_date'] = $toDate;
		}
		if (!empty($conditions)) {
			$sql .= " WHERE " . implode(' AND ', $conditions);
		}
		$sql .= " GROUP BY coa.id, coa.code, coa.name, coa.account_type, coa.normal_balance, coa.is_active ORDER BY coa.account_type, coa.code";
		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
		foreach ($rows as &$row) {
			$debit = (float)($row['total_debit'] ?? 0);
			$credit = (float)($row['total_credit'] ?? 0);
			if (($row['normal_balance'] ?? 'debit') === 'credit') {
				$row['balance'] = max(0, $credit - $debit);
			} else {
				$row['balance'] = max(0, $debit - $credit);
			}
		}
		unset($row);
		return $rows;
	}

	public function getLedgerByAccount(int $accountId, ?string $fromDate = null, ?string $toDate = null, int $limit = 100): array {
		$limit = max(1, min(500, $limit));
		$sql = "SELECT je.id AS entry_id, je.entry_no, je.entry_date, je.reference_type, je.reference_id, je.memo,
			jel.line_memo, jel.debit, jel.credit,
			CASE WHEN jel.debit > 0 THEN jel.debit ELSE -jel.credit END AS movement
			FROM {$this->lineTable} jel
			INNER JOIN {$this->entryTable} je ON je.id = jel.journal_entry_id
			WHERE jel.account_id = :account_id AND je.status = 'posted'";
		$params = [':account_id' => $accountId];
		if ($fromDate !== null && $fromDate !== '') {
			$sql .= " AND je.entry_date >= :from_date";
			$params[':from_date'] = $fromDate;
		}
		if ($toDate !== null && $toDate !== '') {
			$sql .= " AND je.entry_date <= :to_date";
			$params[':to_date'] = $toDate;
		}
		$sql .= " ORDER BY je.entry_date DESC, je.id DESC LIMIT {$limit}";
		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	}

	public function getJournalEntries(int $limit = 50): array {
		$limit = max(1, min(500, $limit));
		$stmt = $this->db->query("SELECT * FROM {$this->entryTable} ORDER BY entry_date DESC, id DESC LIMIT {$limit}");
		$entries = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
		if (empty($entries)) {
			return [];
		}

		$ids = array_map(static fn($row) => (int)$row['id'], $entries);
		$placeholders = implode(',', array_fill(0, count($ids), '?'));
		$stmtLines = $this->db->prepare("SELECT jel.*, coa.code, coa.name, coa.account_type
			FROM {$this->lineTable} jel
			INNER JOIN {$this->chartTable} coa ON coa.id = jel.account_id
			WHERE jel.journal_entry_id IN ($placeholders)
			ORDER BY jel.journal_entry_id ASC, jel.id ASC");
		$stmtLines->execute($ids);
		$lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC) ?: [];
		$grouped = [];
		foreach ($lines as $line) {
			$grouped[(int)$line['journal_entry_id']][] = $line;
		}

		foreach ($entries as &$entry) {
			$entry['lines'] = $grouped[(int)$entry['id']] ?? [];
		}
		unset($entry);
		return $entries;
	}

	public function getJournalEntryById(int $entryId): ?array {
		$stmt = $this->db->prepare("SELECT * FROM {$this->entryTable} WHERE id = :id LIMIT 1");
		$stmt->bindParam(':id', $entryId, PDO::PARAM_INT);
		$stmt->execute();
		$entry = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$entry) {
			return null;
		}

		$stmtLines = $this->db->prepare("SELECT jel.*, coa.code, coa.name, coa.account_type
			FROM {$this->lineTable} jel
			INNER JOIN {$this->chartTable} coa ON coa.id = jel.account_id
			WHERE jel.journal_entry_id = :journal_entry_id
			ORDER BY jel.id ASC");
		$stmtLines->bindParam(':journal_entry_id', $entryId, PDO::PARAM_INT);
		$stmtLines->execute();
		$entry['lines'] = $stmtLines->fetchAll(PDO::FETCH_ASSOC) ?: [];

		return $entry;
	}

	public function isPeriodLocked(string $entryDate): bool {
		$periodKey = substr($entryDate, 0, 7);
		if (!preg_match('/^\d{4}-\d{2}$/', $periodKey)) {
			return false;
		}

		$stmt = $this->db->prepare("SELECT is_locked FROM {$this->periodLockTable} WHERE period_key = :period_key LIMIT 1");
		$stmt->execute([':period_key' => $periodKey]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return !empty($row) && (int)($row['is_locked'] ?? 0) === 1;
	}

	public function lockPeriod(string $periodKey, ?int $lockedBy = null, ?string $note = null): bool {
		if (!preg_match('/^\d{4}-\d{2}$/', $periodKey)) {
			throw new InvalidArgumentException('Invalid period format. Use YYYY-MM.');
		}

		$stmt = $this->db->prepare("INSERT INTO {$this->periodLockTable}
			(period_key, is_locked, locked_by, note, locked_at)
			VALUES (:period_key, 1, :locked_by, :note, NOW())
			ON DUPLICATE KEY UPDATE
				is_locked = 1,
				locked_by = VALUES(locked_by),
				note = VALUES(note),
				locked_at = NOW()");

		return $stmt->execute([
			':period_key' => $periodKey,
			':locked_by' => $lockedBy,
			':note' => $note,
		]);
	}

	public function unlockPeriod(string $periodKey, ?int $lockedBy = null, ?string $note = null): bool {
		if (!preg_match('/^\d{4}-\d{2}$/', $periodKey)) {
			throw new InvalidArgumentException('Invalid period format. Use YYYY-MM.');
		}

		$stmt = $this->db->prepare("INSERT INTO {$this->periodLockTable}
			(period_key, is_locked, locked_by, note, locked_at)
			VALUES (:period_key, 0, :locked_by, :note, NOW())
			ON DUPLICATE KEY UPDATE
				is_locked = 0,
				locked_by = VALUES(locked_by),
				note = VALUES(note),
				updated_at = NOW()");

		return $stmt->execute([
			':period_key' => $periodKey,
			':locked_by' => $lockedBy,
			':note' => $note,
		]);
	}

	public function getPeriodLocks(int $limit = 24): array {
		$limit = max(1, min(120, $limit));
		$stmt = $this->db->query("SELECT * FROM {$this->periodLockTable} ORDER BY period_key DESC LIMIT {$limit}");
		return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
	}

	public function getPostedEntryByReference(string $referenceType, int $referenceId): ?array {
		$stmt = $this->db->prepare("SELECT * FROM {$this->entryTable}
			WHERE reference_type = :reference_type
				AND reference_id = :reference_id
				AND status = 'posted'
			LIMIT 1");
		$stmt->execute([
			':reference_type' => $referenceType,
			':reference_id' => $referenceId,
		]);

		return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	}

	public function postJournalEntry(string $entryDate, string $memo, array $lines, ?string $referenceType = null, ?int $referenceId = null, ?int $postedBy = null): int {
		if ($this->isPeriodLocked($entryDate)) {
			throw new RuntimeException('The accounting period for this entry date is locked.');
		}

		if (count($lines) < 2) {
			throw new InvalidArgumentException('A journal entry requires at least two lines.');
		}

		$totalDebit = 0.0;
		$totalCredit = 0.0;
		foreach ($lines as $line) {
			$totalDebit += (float)($line['debit'] ?? 0);
			$totalCredit += (float)($line['credit'] ?? 0);
		}

		if (round($totalDebit, 2) !== round($totalCredit, 2)) {
			throw new InvalidArgumentException('Journal entry must balance debits and credits.');
		}

		$this->db->beginTransaction();
		try {
			$entryNo = 'JE-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
			$stmtEntry = $this->db->prepare("INSERT INTO {$this->entryTable}
				(entry_no, entry_date, reference_type, reference_id, memo, status, posted_by, posted_at)
				VALUES (:entry_no, :entry_date, :reference_type, :reference_id, :memo, 'posted', :posted_by, NOW())");
			$stmtEntry->execute([
				':entry_no' => $entryNo,
				':entry_date' => $entryDate,
				':reference_type' => $referenceType,
				':reference_id' => $referenceId,
				':memo' => $memo,
				':posted_by' => $postedBy,
			]);

			$entryId = (int)$this->db->lastInsertId();
			$stmtLine = $this->db->prepare("INSERT INTO {$this->lineTable}
				(journal_entry_id, account_id, line_memo, debit, credit)
				VALUES (:journal_entry_id, :account_id, :line_memo, :debit, :credit)");
			foreach ($lines as $line) {
				$stmtLine->execute([
					':journal_entry_id' => $entryId,
					':account_id' => (int)$line['account_id'],
					':line_memo' => $line['memo'] ?? null,
					':debit' => (float)($line['debit'] ?? 0),
					':credit' => (float)($line['credit'] ?? 0),
				]);
			}

			$this->db->commit();
			return $entryId;
		} catch (Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	public function reverseJournalEntry(int $entryId, string $reversalDate, ?string $memo = null, ?int $postedBy = null): int {
		$original = $this->getJournalEntryById($entryId);
		if (!$original) {
			throw new InvalidArgumentException('Journal entry not found.');
		}
		if ((string)($original['status'] ?? '') !== 'posted') {
			throw new RuntimeException('Only posted journal entries can be reversed.');
		}

		$existingReversal = $this->getPostedEntryByReference('reversal', $entryId);
		if ($existingReversal) {
			return (int)$existingReversal['id'];
		}

		$lines = $original['lines'] ?? [];
		if (empty($lines)) {
			throw new RuntimeException('Cannot reverse an entry without lines.');
		}

		$reversalLines = [];
		foreach ($lines as $line) {
			$reversalLines[] = [
				'account_id' => (int)$line['account_id'],
				'debit' => (float)($line['credit'] ?? 0),
				'credit' => (float)($line['debit'] ?? 0),
				'memo' => 'Reversal: ' . (string)($line['line_memo'] ?? ''),
			];
		}

		$reversalMemo = $memo !== null && trim($memo) !== ''
			? trim($memo)
			: ('Reversal of ' . (string)($original['entry_no'] ?? ('entry #' . $entryId)));

		$reversalId = $this->postJournalEntry(
			$reversalDate,
			$reversalMemo,
			$reversalLines,
			'reversal',
			$entryId,
			$postedBy
		);

		$update = $this->db->prepare("UPDATE {$this->entryTable} SET status = 'reversed' WHERE id = :id AND status = 'posted'");
		$update->execute([':id' => $entryId]);

		return $reversalId;
	}

	public function getReconciliationSummary(string $fromDate, string $toDate): array {
		$totals = [
			'billed_total' => 0.0,
			'payments_total' => 0.0,
			'journal_bill_total' => 0.0,
			'journal_payment_total' => 0.0,
			'open_ar_total' => 0.0,
			'billing_to_journal_delta' => 0.0,
			'payments_to_journal_delta' => 0.0,
		];

		$stmtBilled = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) FROM bills WHERE DATE(billing_month) BETWEEN :from_date AND :to_date");
		$stmtBilled->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
		$totals['billed_total'] = (float)$stmtBilled->fetchColumn();

		$stmtPayments = $this->db->prepare("SELECT COALESCE(SUM(amount), 0)
			FROM payments
			WHERE status = 'completed'
				AND DATE(COALESCE(transaction_date, created_at)) BETWEEN :from_date AND :to_date");
		$stmtPayments->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
		$totals['payments_total'] = (float)$stmtPayments->fetchColumn();

		$stmtJournalBill = $this->db->prepare("SELECT COALESCE(SUM(jel.debit), 0)
			FROM {$this->entryTable} je
			INNER JOIN {$this->lineTable} jel ON jel.journal_entry_id = je.id
			WHERE je.status = 'posted'
				AND je.reference_type = 'bill'
				AND je.entry_date BETWEEN :from_date AND :to_date
				AND jel.debit > 0");
		$stmtJournalBill->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
		$totals['journal_bill_total'] = (float)$stmtJournalBill->fetchColumn();

		$stmtJournalPayment = $this->db->prepare("SELECT COALESCE(SUM(jel.debit), 0)
			FROM {$this->entryTable} je
			INNER JOIN {$this->lineTable} jel ON jel.journal_entry_id = je.id
			WHERE je.status = 'posted'
				AND je.reference_type = 'payment'
				AND je.entry_date BETWEEN :from_date AND :to_date
				AND jel.debit > 0");
		$stmtJournalPayment->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
		$totals['journal_payment_total'] = (float)$stmtJournalPayment->fetchColumn();

		$stmtOpenAr = $this->db->prepare("SELECT COALESCE(SUM(
			CASE WHEN b.status IN ('pending','overdue') THEN
				GREATEST(0, b.amount - COALESCE(pp.paid_amount, 0))
			ELSE 0 END
		), 0) AS open_ar
			FROM bills b
			LEFT JOIN (
				SELECT bill_id, SUM(amount) AS paid_amount
				FROM payments
				WHERE status = 'completed'
					AND DATE(COALESCE(transaction_date, created_at)) <= :to_date
				GROUP BY bill_id
			) pp ON pp.bill_id = b.id
			WHERE DATE(b.billing_month) <= :to_date");
		$stmtOpenAr->execute([':to_date' => $toDate]);
		$totals['open_ar_total'] = (float)$stmtOpenAr->fetchColumn();

		$totals['billing_to_journal_delta'] = round($totals['billed_total'] - $totals['journal_bill_total'], 2);
		$totals['payments_to_journal_delta'] = round($totals['payments_total'] - $totals['journal_payment_total'], 2);

		return $totals;
	}

	private function resolveSystemAccount(string $code, string $name, string $type, string $normalBalance): int {
		$account = $this->getAccountByCode($code);
		if ($account) {
			return (int)$account['id'];
		}

		$this->createAccount($code, $name, $type, $normalBalance, null, null, 1);
		$account = $this->getAccountByCode($code);
		if (!$account) {
			throw new RuntimeException('Unable to resolve system account ' . $code);
		}

		return (int)$account['id'];
	}

	public function postInvoiceIssued(int $billId, int $userId, float $amount, string $memo, string $revenueType = 'water', ?int $postedBy = null): ?int {
		if ($amount <= 0) {
			return null;
		}

		$existing = $this->getPostedEntryByReference('bill', $billId);
		if ($existing) {
			return (int)$existing['id'];
		}

		$accountsReceivableId = $this->resolveSystemAccount('1100', 'Accounts Receivable', 'asset', 'debit');
		$revenueAccountId = $revenueType === 'registration'
			? $this->resolveSystemAccount('4100', 'Registration Fee Revenue', 'revenue', 'credit')
			: $this->resolveSystemAccount('4000', 'Water Sales Revenue', 'revenue', 'credit');

		$entryDate = date('Y-m-d');
		return $this->postJournalEntry($entryDate, $memo, [
			['account_id' => $accountsReceivableId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Bill #' . $billId . ' for user #' . $userId],
			['account_id' => $revenueAccountId, 'debit' => 0, 'credit' => $amount, 'memo' => $memo],
		], 'bill', $billId, $postedBy);
	}

	public function postPaymentReceived(int $paymentId, ?array $paymentRow, ?array $billRow, string $memo = 'Payment received', ?int $postedBy = null): ?int {
		if (!$paymentRow) {
			return null;
		}

		$existing = $this->getPostedEntryByReference('payment', $paymentId);
		if ($existing) {
			return (int)$existing['id'];
		}

		$amount = (float)($paymentRow['amount'] ?? 0);
		if ($amount <= 0) {
			return null;
		}

		$cashId = $this->resolveSystemAccount('1000', 'Cash and Cash Equivalents', 'asset', 'debit');
		$receivableId = $this->resolveSystemAccount('1100', 'Accounts Receivable', 'asset', 'debit');
		$entryDate = !empty($paymentRow['transaction_date']) ? date('Y-m-d', strtotime((string)$paymentRow['transaction_date'])) : date('Y-m-d');

		return $this->postJournalEntry($entryDate, $memo, [
			['account_id' => $cashId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Payment #' . $paymentId],
			['account_id' => $receivableId, 'debit' => 0, 'credit' => $amount, 'memo' => $billRow ? ('Bill #' . (int)$billRow['id']) : 'Customer payment'],
		], 'payment', $paymentId, $postedBy);
	}
}