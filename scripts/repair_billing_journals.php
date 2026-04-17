<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Accounting.php';

function repair_print(string $status, string $label, string $detail = ''): void {
	$line = str_pad($status, 7, ' ', STR_PAD_RIGHT) . ' ' . $label;
	if ($detail !== '') {
		$line .= ' - ' . $detail;
	}
	echo $line . PHP_EOL;
}

function find_invalid_bill_entries(PDO $db): array {
	$stmt = $db->query("SELECT id, entry_no, entry_date, memo
		FROM journal_entries
		WHERE status = 'posted' AND reference_type = 'bill' AND (reference_id IS NULL OR reference_id <= 0)
		ORDER BY id ASC");
	return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function find_missing_bill_journals(PDO $db): array {
	$sql = "SELECT b.id, b.user_id, b.account_number, b.amount, b.billing_month, b.created_at,
			CASE
				WHEN EXISTS (
					SELECT 1 FROM bill_line_items bli
					WHERE bli.bill_id = b.id AND bli.line_type = 'registration_fee'
				) THEN 'registration'
				WHEN EXISTS (
					SELECT 1 FROM payments p
					WHERE p.bill_id = b.id AND p.registration_id IS NOT NULL
				) THEN 'registration'
				WHEN COALESCE(b.previous_reading, 0) = 0
					AND COALESCE(b.current_reading, 0) = 0
					AND COALESCE(b.consumption, 0) = 0
					AND COALESCE(b.rate_per_unit, 0) = 0
					AND ABS(COALESCE(b.service_charge, 0) - COALESCE(b.amount, 0)) <= 0.01 THEN 'registration'
				ELSE 'water'
			END AS revenue_type
		FROM bills b
		LEFT JOIN journal_entries je
			ON je.reference_type = 'bill' AND je.reference_id = b.id AND je.status = 'posted'
		WHERE b.amount > 0 AND je.id IS NULL
		ORDER BY b.id ASC";
	$stmt = $db->query($sql);
	return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function find_missing_payment_journals(PDO $db): array {
	$sql = "SELECT p.*
		FROM payments p
		LEFT JOIN journal_entries je
			ON je.reference_type = 'payment' AND je.reference_id = p.id AND je.status = 'posted'
		WHERE p.status = 'completed' AND p.amount > 0 AND je.id IS NULL
		ORDER BY p.id ASC";
	$stmt = $db->query($sql);
	return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function find_paid_bills_without_completed_payments(PDO $db): array {
	$sql = "SELECT b.id, b.user_id, b.account_number, b.amount, b.billing_month, b.created_at,
			u.phone_number,
			CASE
				WHEN EXISTS (
					SELECT 1 FROM bill_line_items bli
					WHERE bli.bill_id = b.id AND bli.line_type = 'registration_fee'
				) THEN 'registration'
				WHEN COALESCE(b.previous_reading, 0) = 0
					AND COALESCE(b.current_reading, 0) = 0
					AND COALESCE(b.consumption, 0) = 0
					AND COALESCE(b.rate_per_unit, 0) = 0
					AND ABS(COALESCE(b.service_charge, 0) - COALESCE(b.amount, 0)) <= 0.01 THEN 'registration'
				ELSE 'water'
			END AS payment_context
		FROM bills b
		LEFT JOIN users u ON u.id = b.user_id
		LEFT JOIN payments p ON p.bill_id = b.id AND p.status = 'completed'
		WHERE b.status = 'paid' AND b.amount > 0 AND p.id IS NULL
		ORDER BY b.id ASC";
	$stmt = $db->query($sql);
	return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function create_backfilled_completed_payment(PDO $db, array $bill): array {
	$entryDate = !empty($bill['created_at']) ? date('Y-m-d H:i:s', strtotime((string)$bill['created_at'])) : date('Y-m-d H:i:s');
	$receipt = 'LEGACY-BILL-' . (int)$bill['id'];
	$registrationId = ((string)($bill['payment_context'] ?? 'water') === 'registration') ? (int)$bill['user_id'] : null;

	$stmt = $db->prepare("INSERT INTO payments
		(bill_id, user_id, mpesa_receipt, phone_number, amount, transaction_date, status, registration_id, merchant_request_id, checkout_request_id, result_code, result_desc, created_at)
		VALUES (:bill_id, :user_id, :mpesa_receipt, :phone_number, :amount, :transaction_date, 'completed', :registration_id, NULL, NULL, '0', :result_desc, :created_at)");
	$stmt->execute([
		':bill_id' => (int)$bill['id'],
		':user_id' => (int)$bill['user_id'],
		':mpesa_receipt' => $receipt,
		':phone_number' => !empty($bill['phone_number']) ? (string)$bill['phone_number'] : null,
		':amount' => (float)$bill['amount'],
		':transaction_date' => $entryDate,
		':registration_id' => $registrationId,
		':result_desc' => 'Legacy repair backfill for paid bill without completed payment record',
		':created_at' => $entryDate,
	]);

	$paymentId = (int)$db->lastInsertId();
	$stmtPayment = $db->prepare('SELECT * FROM payments WHERE id = :id LIMIT 1');
	$stmtPayment->execute([':id' => $paymentId]);
	$paymentRow = $stmtPayment->fetch(PDO::FETCH_ASSOC) ?: null;
	if (!$paymentRow) {
		throw new RuntimeException('Backfilled payment row could not be reloaded.');
	}

	return $paymentRow;
}

$apply = in_array('--apply', $argv, true);

$database = new Database();
$db = $database->getConnection();
if (!$db) {
	fwrite(STDERR, "Database connection failed. Check .env and database availability." . PHP_EOL);
	exit(2);
}

$accounting = new Accounting($db);

$invalidBillEntries = find_invalid_bill_entries($db);
$missingBillJournals = find_missing_bill_journals($db);
$missingPaymentJournals = find_missing_payment_journals($db);
$paidBillsWithoutPayments = find_paid_bills_without_completed_payments($db);

repair_print('INFO', 'Mode', $apply ? 'apply' : 'dry-run');
repair_print('INFO', 'Invalid bill journals', (string)count($invalidBillEntries));
repair_print('INFO', 'Missing bill journals', (string)count($missingBillJournals));
repair_print('INFO', 'Missing payment journals', (string)count($missingPaymentJournals));
repair_print('INFO', 'Paid bills without completed payments', (string)count($paidBillsWithoutPayments));
echo PHP_EOL;

if (!$apply) {
	foreach ($invalidBillEntries as $entry) {
		repair_print('PLAN', 'Reverse invalid bill journal', 'Entry #' . (int)$entry['id'] . ' (' . (string)$entry['entry_no'] . ')');
	}
	foreach ($missingBillJournals as $bill) {
		repair_print('PLAN', 'Backfill bill journal', 'Bill #' . (int)$bill['id'] . ' [' . (string)$bill['revenue_type'] . '] amount ' . number_format((float)$bill['amount'], 2));
	}
	foreach ($missingPaymentJournals as $payment) {
		repair_print('PLAN', 'Backfill payment journal', 'Payment #' . (int)$payment['id'] . ' amount ' . number_format((float)$payment['amount'], 2));
	}
	foreach ($paidBillsWithoutPayments as $bill) {
		repair_print('PLAN', 'Create missing completed payment', 'Bill #' . (int)$bill['id'] . ' amount ' . number_format((float)$bill['amount'], 2));
	}
	echo PHP_EOL;
	echo "Run with --apply to perform the repair." . PHP_EOL;
	exit(0);
}

$errors = 0;

foreach ($invalidBillEntries as $entry) {
	try {
		$accounting->reverseJournalEntry(
			(int)$entry['id'],
			date('Y-m-d'),
			'Legacy repair: reverse invalid bill journal reference ' . (string)$entry['entry_no']
		);
		repair_print('DONE', 'Reversed invalid bill journal', 'Entry #' . (int)$entry['id']);
	} catch (Throwable $e) {
		$errors++;
		repair_print('ERROR', 'Reverse invalid bill journal failed', 'Entry #' . (int)$entry['id'] . ': ' . $e->getMessage());
	}
}

foreach ($missingBillJournals as $bill) {
	try {
		$entryDate = !empty($bill['created_at']) ? (string)$bill['created_at'] : (string)$bill['billing_month'];
		$memo = ((string)$bill['revenue_type'] === 'registration' ? 'Registration fee bill issued for ' : 'Water bill issued for ')
			. (string)($bill['account_number'] ?? ('bill #' . (int)$bill['id']));
		$accounting->postInvoiceIssued(
			(int)$bill['id'],
			(int)$bill['user_id'],
			(float)$bill['amount'],
			$memo,
			(string)$bill['revenue_type'],
			null,
			$entryDate
		);
		repair_print('DONE', 'Backfilled bill journal', 'Bill #' . (int)$bill['id']);
	} catch (Throwable $e) {
		$errors++;
		repair_print('ERROR', 'Backfill bill journal failed', 'Bill #' . (int)$bill['id'] . ': ' . $e->getMessage());
	}
}

foreach ($missingPaymentJournals as $payment) {
	try {
		$billRow = null;
		if (!empty($payment['bill_id'])) {
			$stmtBill = $db->prepare('SELECT * FROM bills WHERE id = :id LIMIT 1');
			$stmtBill->execute([':id' => (int)$payment['bill_id']]);
			$billRow = $stmtBill->fetch(PDO::FETCH_ASSOC) ?: null;
		}
		$accounting->postPaymentReceived(
			(int)$payment['id'],
			$payment,
			$billRow,
			'Legacy journal backfill for payment #' . (int)$payment['id']
		);
		repair_print('DONE', 'Backfilled payment journal', 'Payment #' . (int)$payment['id']);
	} catch (Throwable $e) {
		$errors++;
		repair_print('ERROR', 'Backfill payment journal failed', 'Payment #' . (int)$payment['id'] . ': ' . $e->getMessage());
	}
}

foreach ($paidBillsWithoutPayments as $bill) {
	try {
		$paymentRow = create_backfilled_completed_payment($db, $bill);
		$accounting->postPaymentReceived(
			(int)$paymentRow['id'],
			$paymentRow,
			$bill,
			'Legacy repair backfill for paid bill #' . (int)$bill['id']
		);
		repair_print('DONE', 'Created missing completed payment', 'Bill #' . (int)$bill['id'] . ' -> Payment #' . (int)$paymentRow['id']);
	} catch (Throwable $e) {
		$errors++;
		repair_print('ERROR', 'Create missing completed payment failed', 'Bill #' . (int)$bill['id'] . ': ' . $e->getMessage());
	}
}

echo PHP_EOL;
echo 'Repair summary: ' . $errors . ' error(s).' . PHP_EOL;

exit($errors > 0 ? 1 : 0);