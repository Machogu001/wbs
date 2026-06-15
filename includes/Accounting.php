<?php
require_once __DIR__ . '/../config/database.php';

class Accounting {
	private $db;
	private $chartTable = 'chart_of_accounts';
	private $entryTable = 'journal_entries';
	private $lineTable = 'journal_entry_lines';
	private $periodLockTable = 'accounting_period_locks';
	private $budgetTable = 'accounting_budgets';
	private $transferTable = 'accounting_transfers';

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

		$db->exec("CREATE TABLE IF NOT EXISTS accounting_budgets (
			id INT AUTO_INCREMENT PRIMARY KEY,
			chart_of_account_id INT NOT NULL,
			financial_year CHAR(4) NOT NULL,
			month_1  DECIMAL(14,2) NOT NULL DEFAULT 0,
			month_2  DECIMAL(14,2) NOT NULL DEFAULT 0,
			month_3  DECIMAL(14,2) NOT NULL DEFAULT 0,
			month_4  DECIMAL(14,2) NOT NULL DEFAULT 0,
			month_5  DECIMAL(14,2) NOT NULL DEFAULT 0,
			month_6  DECIMAL(14,2) NOT NULL DEFAULT 0,
			month_7  DECIMAL(14,2) NOT NULL DEFAULT 0,
			month_8  DECIMAL(14,2) NOT NULL DEFAULT 0,
			month_9  DECIMAL(14,2) NOT NULL DEFAULT 0,
			month_10 DECIMAL(14,2) NOT NULL DEFAULT 0,
			month_11 DECIMAL(14,2) NOT NULL DEFAULT 0,
			month_12 DECIMAL(14,2) NOT NULL DEFAULT 0,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
			UNIQUE KEY uq_budget_account_year (chart_of_account_id, financial_year),
			INDEX idx_budget_year (financial_year),
			CONSTRAINT fk_accounting_budgets_account FOREIGN KEY (chart_of_account_id) REFERENCES chart_of_accounts(id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		$db->exec("CREATE TABLE IF NOT EXISTS accounting_transfers (
			id INT AUTO_INCREMENT PRIMARY KEY,
			transfer_no VARCHAR(40) NOT NULL UNIQUE,
			transfer_date DATE NOT NULL,
			from_account_id INT NOT NULL,
			to_account_id INT NOT NULL,
			amount DECIMAL(14,2) NOT NULL,
			memo VARCHAR(255) NULL,
			journal_entry_id INT NULL,
			transferred_by INT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_transfer_date (transfer_date),
			INDEX idx_transfer_from (from_account_id),
			INDEX idx_transfer_to (to_account_id),
			CONSTRAINT fk_accounting_transfers_from FOREIGN KEY (from_account_id) REFERENCES chart_of_accounts(id),
			CONSTRAINT fk_accounting_transfers_to FOREIGN KEY (to_account_id) REFERENCES chart_of_accounts(id),
			CONSTRAINT fk_accounting_transfers_entry FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL
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
		// Date conditions are placed in the JOIN ON clause (not WHERE) so that
		// accounts with no journal entries in the period still appear with zero balances.
		$joinDateConditions = "je.status = 'posted'";
		$params = [];
		if ($fromDate !== null && $fromDate !== '') {
			$joinDateConditions .= " AND je.entry_date >= :from_date";
			$params[':from_date'] = $fromDate;
		}
		if ($toDate !== null && $toDate !== '') {
			$joinDateConditions .= " AND je.entry_date <= :to_date";
			$params[':to_date'] = $toDate;
		}

		$sql = "SELECT coa.id, coa.code, coa.name, coa.account_type, coa.normal_balance, coa.is_active,
			COALESCE(SUM(jel.debit), 0) AS total_debit,
			COALESCE(SUM(jel.credit), 0) AS total_credit
			FROM {$this->chartTable} coa
			LEFT JOIN {$this->lineTable} jel ON jel.account_id = coa.id
			LEFT JOIN {$this->entryTable} je ON je.id = jel.journal_entry_id AND {$joinDateConditions}
			WHERE coa.is_active = 1
			GROUP BY coa.id, coa.code, coa.name, coa.account_type, coa.normal_balance, coa.is_active
			ORDER BY coa.account_type, coa.code";

		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
		foreach ($rows as &$row) {
			$debit = (float)($row['total_debit'] ?? 0);
			$credit = (float)($row['total_credit'] ?? 0);
			if (($row['normal_balance'] ?? 'debit') === 'credit') {
				$row['balance'] = $credit - $debit;
			} else {
				$row['balance'] = $debit - $credit;
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

		$ownTransaction = !$this->db->inTransaction();
		if ($ownTransaction) {
			$this->db->beginTransaction();
		}
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

			if ($ownTransaction) {
				$this->db->commit();
			}
			return $entryId;
		} catch (Throwable $e) {
			if ($ownTransaction && $this->db->inTransaction()) {
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

	public function getBalanceSheet(?string $asOfDate = null): array {
		$rows = $this->getTrialBalance(null, $asOfDate !== null && $asOfDate !== '' ? $asOfDate : null);
		$sections = [
			'asset' => [],
			'liability' => [],
			'equity' => [],
		];
		$totals = [
			'asset' => 0.0,
			'liability' => 0.0,
			'equity' => 0.0,
		];

		foreach ($rows as $row) {
			$type = (string)($row['account_type'] ?? '');
			if (!isset($sections[$type])) {
				continue;
			}

			$balance = round((float)($row['balance'] ?? 0), 2);
			if ($balance == 0.0) {
				continue;
			}

			$sections[$type][] = $row;
			$totals[$type] += $balance;
		}

		return [
			'as_of_date' => $asOfDate !== null && $asOfDate !== '' ? $asOfDate : date('Y-m-d'),
			'sections' => $sections,
			'totals' => [
				'assets' => round($totals['asset'], 2),
				'liabilities' => round($totals['liability'], 2),
				'equity' => round($totals['equity'], 2),
				'liabilities_and_equity' => round($totals['liability'] + $totals['equity'], 2),
				'balance_delta' => round($totals['asset'] - ($totals['liability'] + $totals['equity']), 2),
			],
		];
	}

	public function getProfitAndLoss(?string $fromDate = null, ?string $toDate = null): array {
		$rows = $this->getTrialBalance($fromDate, $toDate);
		$revenue = [];
		$expenses = [];
		$costOfSales = [];
		$totals = [
			'revenue' => 0.0,
			'expenses' => 0.0,
			'cost_of_sales' => 0.0,
		];

		foreach ($rows as $row) {
			$type = (string)($row['account_type'] ?? '');
			$balance = round((float)($row['balance'] ?? 0), 2);
			if ($balance == 0.0) {
				continue;
			}

			if ($type === 'revenue') {
				$revenue[] = $row;
				$totals['revenue'] += $balance;
			} elseif ($type === 'expense') {
				$expenses[] = $row;
				$totals['expenses'] += $balance;
			} elseif ($type === 'cost_of_sales') {
				$costOfSales[] = $row;
				$totals['cost_of_sales'] += $balance;
			}
		}

		$grossProfit = $totals['revenue'] - $totals['cost_of_sales'];
		$netProfit = $grossProfit - $totals['expenses'];

		return [
			'from_date' => $fromDate,
			'to_date' => $toDate,
			'sections' => [
				'revenue' => $revenue,
				'cost_of_sales' => $costOfSales,
				'expenses' => $expenses,
			],
			'totals' => [
				'revenue' => round($totals['revenue'], 2),
				'cost_of_sales' => round($totals['cost_of_sales'], 2),
				'gross_profit' => round($grossProfit, 2),
				'operating_expenses' => round($totals['expenses'], 2),
				'net_profit' => round($netProfit, 2),
			],
		];
	}

	public function getCashFlow(?string $fromDate = null, ?string $toDate = null): array {
		$cashAccount = $this->getAccountByCode('1000');
		$summary = [
			'from_date' => $fromDate,
			'to_date' => $toDate,
			'cash_account' => $cashAccount,
			'opening_balance' => 0.0,
			'cash_in' => 0.0,
			'cash_out' => 0.0,
			'net_cash_flow' => 0.0,
			'closing_balance' => 0.0,
			'activities' => [
				'operating_inflows' => 0.0,
				'operating_outflows' => 0.0,
				'financing_inflows' => 0.0,
				'financing_outflows' => 0.0,
			],
			'lines' => [],
		];

		if (!$cashAccount) {
			return $summary;
		}

		$accountId = (int)$cashAccount['id'];

		$openingSql = "SELECT COALESCE(SUM(jel.debit - jel.credit), 0)
			FROM {$this->lineTable} jel
			INNER JOIN {$this->entryTable} je ON je.id = jel.journal_entry_id
			WHERE je.status = 'posted'
				AND jel.account_id = :account_id";
		$openingParams = [':account_id' => $accountId];
		if ($fromDate !== null && $fromDate !== '') {
			$openingSql .= " AND je.entry_date < :from_date";
			$openingParams[':from_date'] = $fromDate;
		}
		$stmtOpening = $this->db->prepare($openingSql);
		$stmtOpening->execute($openingParams);
		$summary['opening_balance'] = round((float)$stmtOpening->fetchColumn(), 2);

		$sql = "SELECT je.entry_date, je.entry_no, je.reference_type, je.reference_id, je.memo,
			SUM(jel.debit) AS debit,
			SUM(jel.credit) AS credit,
			CASE
				WHEN je.reference_type IN ('payment', 'bill') THEN 'operating'
				WHEN je.reference_type = 'owner_equity' THEN 'financing'
				ELSE 'other'
			END AS cash_activity
			FROM {$this->lineTable} jel
			INNER JOIN {$this->entryTable} je ON je.id = jel.journal_entry_id
			WHERE je.status = 'posted'
				AND jel.account_id = :account_id";
		$params = [':account_id' => $accountId];
		if ($fromDate !== null && $fromDate !== '') {
			$sql .= " AND je.entry_date >= :from_date";
			$params[':from_date'] = $fromDate;
		}
		if ($toDate !== null && $toDate !== '') {
			$sql .= " AND je.entry_date <= :to_date";
			$params[':to_date'] = $toDate;
		}
		$sql .= " GROUP BY je.id, je.entry_date, je.entry_no, je.reference_type, je.reference_id, je.memo
			ORDER BY je.entry_date ASC, je.id ASC";

		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);
		$lines = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
		foreach ($lines as $line) {
			$debit = (float)($line['debit'] ?? 0);
			$credit = (float)($line['credit'] ?? 0);
			$net = round($debit - $credit, 2);
			$line['net_cash_movement'] = $net;
			$summary['lines'][] = $line;

			if ($net >= 0) {
				$summary['cash_in'] += $net;
			} else {
				$summary['cash_out'] += abs($net);
			}

			$activity = (string)($line['cash_activity'] ?? 'other');
			if ($activity === 'operating') {
				if ($net >= 0) {
					$summary['activities']['operating_inflows'] += $net;
				} else {
					$summary['activities']['operating_outflows'] += abs($net);
				}
			} elseif ($activity === 'financing') {
				if ($net >= 0) {
					$summary['activities']['financing_inflows'] += $net;
				} else {
					$summary['activities']['financing_outflows'] += abs($net);
				}
			}
		}

		$summary['cash_in'] = round($summary['cash_in'], 2);
		$summary['cash_out'] = round($summary['cash_out'], 2);
		$summary['activities']['operating_inflows'] = round($summary['activities']['operating_inflows'], 2);
		$summary['activities']['operating_outflows'] = round($summary['activities']['operating_outflows'], 2);
		$summary['activities']['financing_inflows'] = round($summary['activities']['financing_inflows'], 2);
		$summary['activities']['financing_outflows'] = round($summary['activities']['financing_outflows'], 2);
		$summary['net_cash_flow'] = round($summary['cash_in'] - $summary['cash_out'], 2);
		$summary['closing_balance'] = round($summary['opening_balance'] + $summary['net_cash_flow'], 2);

		return $summary;
	}

	public function getAccountsReceivableAging(?string $asOfDate = null): array {
		$asOfDate = $this->normalizeEntryDate($asOfDate);
		$stmt = $this->db->prepare("SELECT
			b.id,
			b.user_id,
			b.amount,
			b.status,
			DATE(COALESCE(b.due_date, b.billing_month)) AS due_date,
			u.full_name AS customer_name,
			COALESCE(payments.total_paid, 0) AS total_paid
			FROM bills b
			LEFT JOIN users u ON u.id = b.user_id
			LEFT JOIN (
				SELECT bill_id, SUM(amount) AS total_paid
				FROM payments
				WHERE status = 'completed'
					AND DATE(COALESCE(transaction_date, created_at)) <= :as_of_date
				GROUP BY bill_id
			) payments ON payments.bill_id = b.id
			WHERE DATE(COALESCE(b.due_date, b.billing_month)) <= :as_of_date
			ORDER BY due_date ASC, b.id ASC");
		$stmt->execute([':as_of_date' => $asOfDate]);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

		$buckets = [
			'current' => 0.0,
			'days_1_30' => 0.0,
			'days_31_60' => 0.0,
			'days_61_90' => 0.0,
			'days_91_plus' => 0.0,
		];
		$items = [];

		$asOfTimestamp = strtotime($asOfDate) ?: time();
		foreach ($rows as $row) {
			$outstanding = round((float)($row['amount'] ?? 0) - (float)($row['total_paid'] ?? 0), 2);
			if ($outstanding <= 0) {
				continue;
			}

			$dueDate = (string)($row['due_date'] ?? $asOfDate);
			$dueTimestamp = strtotime($dueDate) ?: $asOfTimestamp;
			$daysPastDue = (int)floor(($asOfTimestamp - $dueTimestamp) / 86400);
			if ($daysPastDue <= 0) {
				$bucket = 'current';
			} elseif ($daysPastDue <= 30) {
				$bucket = 'days_1_30';
			} elseif ($daysPastDue <= 60) {
				$bucket = 'days_31_60';
			} elseif ($daysPastDue <= 90) {
				$bucket = 'days_61_90';
			} else {
				$bucket = 'days_91_plus';
			}

			$buckets[$bucket] += $outstanding;
			$row['outstanding'] = $outstanding;
			$row['days_past_due'] = max(0, $daysPastDue);
			$row['bucket'] = $bucket;
			$items[] = $row;
		}

		foreach ($buckets as $key => $amount) {
			$buckets[$key] = round($amount, 2);
		}

		return [
			'as_of_date' => $asOfDate,
			'buckets' => $buckets,
			'total_outstanding' => round(array_sum($buckets), 2),
			'items' => $items,
		];
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

	private function normalizeEntryDate(?string $candidate): string {
		if ($candidate === null || trim($candidate) === '') {
			return date('Y-m-d');
		}

		$timestamp = strtotime($candidate);
		if ($timestamp === false) {
			return date('Y-m-d');
		}

		return date('Y-m-d', $timestamp);
	}

	public function postInvoiceIssued(int $billId, int $userId, float $amount, string $memo, string $revenueType = 'water', ?int $postedBy = null, ?string $entryDate = null): ?int {
		if ($billId <= 0) {
			throw new InvalidArgumentException('A valid bill reference is required to post an invoice journal entry.');
		}

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

		$entryDate = $this->normalizeEntryDate($entryDate);
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
		$entryDate = $this->normalizeEntryDate((string)($paymentRow['transaction_date'] ?? $paymentRow['created_at'] ?? ''));

		return $this->postJournalEntry($entryDate, $memo, [
			['account_id' => $cashId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Payment #' . $paymentId],
			['account_id' => $receivableId, 'debit' => 0, 'credit' => $amount, 'memo' => $billRow ? ('Bill #' . (int)$billRow['id']) : 'Customer payment'],
		], 'payment', $paymentId, $postedBy);
	}

	public function postReceivableReduction(string $referenceType, int $referenceId, int $billId, int $userId, float $amount, string $memo = 'Receivable reduction', ?int $postedBy = null): ?int {
		if ($amount <= 0) {
			return null;
		}

		$existing = $this->getPostedEntryByReference($referenceType, $referenceId);
		if ($existing) {
			return (int)$existing['id'];
		}

		$badDebtExpenseId = $this->resolveSystemAccount('5400', 'Bad Debt Expense', 'expense', 'debit');
		$receivableId = $this->resolveSystemAccount('1100', 'Accounts Receivable', 'asset', 'debit');
		$entryDate = date('Y-m-d');

		return $this->postJournalEntry($entryDate, $memo, [
			['account_id' => $badDebtExpenseId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Bill #' . $billId . ' user #' . $userId],
			['account_id' => $receivableId, 'debit' => 0, 'credit' => $amount, 'memo' => $memo],
		], $referenceType, $referenceId, $postedBy);
	}

	public function postPaymentAdjustment(string $adjustmentType, int $adjustmentId, ?array $paymentRow, ?array $billRow, string $memo = 'Payment adjustment', ?int $postedBy = null): ?int {
		if (!$paymentRow) {
			return null;
		}

		$referenceType = $adjustmentType === 'chargeback' ? 'payment_chargeback' : 'payment_refund';
		$existing = $this->getPostedEntryByReference($referenceType, $adjustmentId);
		if ($existing) {
			return (int)$existing['id'];
		}

		$amount = (float)($paymentRow['adjustment_amount'] ?? $paymentRow['amount'] ?? 0);
		if ($amount <= 0) {
			return null;
		}

		$cashId = $this->resolveSystemAccount('1000', 'Cash and Cash Equivalents', 'asset', 'debit');
		$receivableId = $this->resolveSystemAccount('1100', 'Accounts Receivable', 'asset', 'debit');
		$entryDate = date('Y-m-d');

		return $this->postJournalEntry($entryDate, $memo, [
			['account_id' => $receivableId, 'debit' => $amount, 'credit' => 0, 'memo' => $billRow ? ('Bill #' . (int)$billRow['id']) : 'Customer receivable restored'],
			['account_id' => $cashId, 'debit' => 0, 'credit' => $amount, 'memo' => ucfirst($adjustmentType) . ' for payment #' . (int)($paymentRow['id'] ?? 0)],
		], $referenceType, $adjustmentId, $postedBy);
	}
	// -------------------------------------------------------------------------
	// Budget Planning
	// -------------------------------------------------------------------------

	/**
	 * Return all budget rows for a financial year, merged with COA info.
	 */
	public function getBudgets(string $financialYear): array {
		$stmt = $this->db->prepare("SELECT coa.id, coa.code, coa.name, coa.account_type,
			ab.id AS budget_id,
			COALESCE(ab.month_1,0)  AS month_1,
			COALESCE(ab.month_2,0)  AS month_2,
			COALESCE(ab.month_3,0)  AS month_3,
			COALESCE(ab.month_4,0)  AS month_4,
			COALESCE(ab.month_5,0)  AS month_5,
			COALESCE(ab.month_6,0)  AS month_6,
			COALESCE(ab.month_7,0)  AS month_7,
			COALESCE(ab.month_8,0)  AS month_8,
			COALESCE(ab.month_9,0)  AS month_9,
			COALESCE(ab.month_10,0) AS month_10,
			COALESCE(ab.month_11,0) AS month_11,
			COALESCE(ab.month_12,0) AS month_12
			FROM {$this->chartTable} coa
			LEFT JOIN {$this->budgetTable} ab
				ON ab.chart_of_account_id = coa.id AND ab.financial_year = :year
			WHERE coa.is_active = 1
			ORDER BY coa.account_type, coa.code");
		$stmt->execute([':year' => $financialYear]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	}

	/**
	 * Upsert a 12-month budget for one account + financial year.
	 * $months = ['month_1' => val, ..., 'month_12' => val]
	 */
	public function saveBudget(int $accountId, string $financialYear, array $months): bool {
		$allowed = ['month_1','month_2','month_3','month_4','month_5','month_6',
					'month_7','month_8','month_9','month_10','month_11','month_12'];
		$sets = [];
		$params = [':account_id' => $accountId, ':year' => $financialYear];
		foreach ($allowed as $col) {
			$val = (float)($months[$col] ?? 0);
			$sets[] = "{$col} = :{$col}";
			$params[":{$col}"] = $val;
		}
		$setCols = implode(', ', $sets);
		$colList = implode(', ', $allowed);
		$valList = implode(', ', array_map(fn($c) => ":{$c}", $allowed));

		$sql = "INSERT INTO {$this->budgetTable}
			(chart_of_account_id, financial_year, {$colList})
			VALUES (:account_id, :year, {$valList})
			ON DUPLICATE KEY UPDATE {$setCols}, updated_at = NOW()";
		$stmt = $this->db->prepare($sql);
		return $stmt->execute($params);
	}

	/**
	 * Budget vs Actual comparison for a given financial year.
	 * $startMonth: 1-12, first month of the FY (default Jan = 1).
	 */
	public function getBudgetVsActual(string $financialYear, int $startMonth = 1): array {
		$budgets = $this->getBudgets($financialYear);
		if (empty($budgets)) {
			return [];
		}

		$startMonth = max(1, min(12, $startMonth));
		$months = [];
		for ($i = 0; $i < 12; $i++) {
			$mo = (($startMonth - 1 + $i) % 12) + 1;
			$year = (int)$financialYear + ($mo < $startMonth ? 1 : 0);
			$months[] = ['column' => 'month_' . ($i + 1), 'ym' => sprintf('%04d-%02d', $year, $mo)];
		}

		$accountIds = array_column($budgets, 'id');
		$placeholders = implode(',', array_fill(0, count($accountIds), '?'));
		$sql = "SELECT jel.account_id,
			DATE_FORMAT(je.entry_date, '%Y-%m') AS ym,
			SUM(jel.debit) AS total_debit,
			SUM(jel.credit) AS total_credit
			FROM {$this->lineTable} jel
			INNER JOIN {$this->entryTable} je ON je.id = jel.journal_entry_id
			WHERE je.status = 'posted'
				AND jel.account_id IN ({$placeholders})
			GROUP BY jel.account_id, ym";
		$stmt = $this->db->prepare($sql);
		$stmt->execute($accountIds);
		$actuals = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$actuals[(int)$row['account_id']][$row['ym']] = $row;
		}

		$result = [];
		foreach ($budgets as $account) {
			$accountId = (int)$account['id'];
			$normalBalance = $account['normal_balance'] ?? 'debit';
			$row = [
				'id' => $accountId,
				'code' => $account['code'],
				'name' => $account['name'],
				'account_type' => $account['account_type'],
				'budget_total' => 0.0,
				'actual_total' => 0.0,
				'months' => [],
			];
			foreach ($months as $m) {
				$budgetAmt = (float)($account[$m['column']] ?? 0);
				$ym = $m['ym'];
				$ar = $actuals[$accountId][$ym] ?? null;
				if ($normalBalance === 'credit') {
					$actualAmt = (float)($ar['total_credit'] ?? 0) - (float)($ar['total_debit'] ?? 0);
				} else {
					$actualAmt = (float)($ar['total_debit'] ?? 0) - (float)($ar['total_credit'] ?? 0);
				}
				$row['months'][] = [
					'label' => date('M Y', mktime(0, 0, 0, (int)substr($ym, 5, 2), 1, (int)substr($ym, 0, 4))),
					'ym' => $ym,
					'budget' => $budgetAmt,
					'actual' => round($actualAmt, 2),
					'variance' => round($actualAmt - $budgetAmt, 2),
				];
				$row['budget_total'] += $budgetAmt;
				$row['actual_total'] += $actualAmt;
			}
			$row['budget_total'] = round($row['budget_total'], 2);
			$row['actual_total'] = round($row['actual_total'], 2);
			$row['variance_total'] = round($row['actual_total'] - $row['budget_total'], 2);
			$result[] = $row;
		}
		return $result;
	}

	// -------------------------------------------------------------------------
	// Fund Transfers between accounts
	// -------------------------------------------------------------------------

	/**
	 * Record a fund transfer between two GL accounts and post a journal entry.
	 * Returns the new transfer ID.
	 */
	public function postTransfer(
		string $date,
		int $fromAccountId,
		int $toAccountId,
		float $amount,
		string $memo = '',
		?int $postedBy = null
	): int {
		if ($fromAccountId === $toAccountId) {
			throw new InvalidArgumentException('Transfer from and to accounts must be different.');
		}
		if ($amount <= 0) {
			throw new InvalidArgumentException('Transfer amount must be positive.');
		}

		$date = $this->normalizeEntryDate($date);

		$this->db->beginTransaction();
		try {
			$entryId = $this->postJournalEntry($date, $memo ?: 'Fund transfer', [
				['account_id' => $toAccountId,   'debit' => $amount, 'credit' => 0,       'memo' => $memo],
				['account_id' => $fromAccountId, 'debit' => 0,       'credit' => $amount, 'memo' => $memo],
			], 'transfer', null, $postedBy);

			$transferNo = 'TRF-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
			$stmt = $this->db->prepare("INSERT INTO {$this->transferTable}
				(transfer_no, transfer_date, from_account_id, to_account_id, amount, memo, journal_entry_id, transferred_by)
				VALUES (:transfer_no, :transfer_date, :from_id, :to_id, :amount, :memo, :entry_id, :by)");
			$stmt->execute([
				':transfer_no'   => $transferNo,
				':transfer_date' => $date,
				':from_id'       => $fromAccountId,
				':to_id'         => $toAccountId,
				':amount'        => $amount,
				':memo'          => $memo ?: null,
				':entry_id'      => $entryId,
				':by'            => $postedBy,
			]);

			$transferId = (int)$this->db->lastInsertId();
			$this->db->commit();
			return $transferId;
		} catch (Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Return recent fund transfers with from/to account names.
	 */
	public function getTransfers(int $limit = 30): array {
		$limit = max(1, min(200, $limit));
		$stmt = $this->db->prepare("SELECT t.*,
			fa.code AS from_code, fa.name AS from_name,
			ta.code AS to_code,   ta.name AS to_name
			FROM {$this->transferTable} t
			LEFT JOIN {$this->chartTable} fa ON fa.id = t.from_account_id
			LEFT JOIN {$this->chartTable} ta ON ta.id = t.to_account_id
			ORDER BY t.transfer_date DESC, t.id DESC
			LIMIT {$limit}");
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	}

}