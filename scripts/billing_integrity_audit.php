<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Accounting.php';
require_once __DIR__ . '/../includes/Payment.php';
require_once __DIR__ . '/../includes/FinanceApproval.php';
require_once __DIR__ . '/../includes/InstallmentPlan.php';

$auditStatusFile = __DIR__ . '/../logs/billing_audit_status.json';

function audit_print(string $status, string $label, string $detail = ''): void {
    $line = str_pad($status, 7, ' ', STR_PAD_RIGHT) . ' ' . $label;
    if ($detail !== '') {
        $line .= ' - ' . $detail;
    }
    echo $line . PHP_EOL;
}

function audit_write_status(string $filePath, array $payload): void {
    $directory = dirname($filePath);
    if (!is_dir($directory)) {
        return;
    }

    if ((!file_exists($filePath) && !is_writable($directory)) || (file_exists($filePath) && !is_writable($filePath))) {
        return;
    }

    file_put_contents($filePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    audit_write_status($auditStatusFile, [
        'generated_at' => date('c'),
        'status' => 'failure',
        'failures' => 1,
        'warnings' => 0,
        'checks' => [],
        'summary_line' => 'Database connection failed. Check .env and database availability.',
    ]);
    fwrite(STDERR, "Database connection failed. Check .env and database availability." . PHP_EOL);
    exit(2);
}

// Ensure compatibility tables exist before running checks.
new Payment($db);
new FinanceApproval($db);
new InstallmentPlan($db);
$accounting = new Accounting($db);

$failures = 0;
$warnings = 0;
$checkResults = [];

$checks = [];

$checks[] = [
    'label' => 'Duplicate posted journal references',
    'query' => "SELECT COUNT(*) FROM (
        SELECT reference_type, reference_id
        FROM journal_entries
        WHERE status = 'posted' AND reference_type IS NOT NULL AND reference_id IS NOT NULL
        GROUP BY reference_type, reference_id
        HAVING COUNT(*) > 1
    ) dup",
    'type' => 'failure',
    'ok_detail' => 'No duplicate posted references found.',
    'bad_detail' => 'Duplicate posted journal references detected.',
];

$checks[] = [
    'label' => 'Completed payments without payment journals',
    'query' => "SELECT COUNT(*)
        FROM payments p
        LEFT JOIN journal_entries je
            ON je.reference_type = 'payment' AND je.reference_id = p.id AND je.status = 'posted'
        WHERE p.status = 'completed' AND p.amount > 0 AND COALESCE(p.payment_method, '') <> 'wallet' AND je.id IS NULL",
    'type' => 'failure',
    'ok_detail' => 'All completed payments are journaled.',
    'bad_detail' => 'Completed payments exist without journal entries.',
];

$checks[] = [
    'label' => 'Bills without bill journals',
    'query' => "SELECT COUNT(*)
        FROM bills b
        LEFT JOIN journal_entries je
            ON je.reference_type = 'bill' AND je.reference_id = b.id AND je.status = 'posted'
        WHERE b.amount > 0 AND je.id IS NULL",
    'type' => 'warning',
    'ok_detail' => 'All bills have bill journal entries.',
    'bad_detail' => 'Some bills are missing bill journal entries.',
];

$checks[] = [
	'label' => 'Paid bills without completed payments',
	'query' => "SELECT COUNT(*)
		FROM bills b
		LEFT JOIN payments p
			ON p.bill_id = b.id AND p.status = 'completed'
		WHERE b.status = 'paid' AND b.amount > 0 AND p.id IS NULL",
	'type' => 'failure',
	'ok_detail' => 'Every paid bill has at least one completed payment record.',
	'bad_detail' => 'Paid bills exist without completed payment records.',
];

$checks[] = [
    'label' => 'Approved payment adjustments without journals',
    'query' => "SELECT COUNT(*)
        FROM payment_adjustments pa
        LEFT JOIN journal_entries je
            ON je.reference_type = CONCAT('payment_', pa.adjustment_type)
           AND je.reference_id = pa.id
           AND je.status = 'posted'
        WHERE pa.status = 'approved' AND je.id IS NULL",
    'type' => 'failure',
    'ok_detail' => 'All approved payment adjustments are journaled.',
    'bad_detail' => 'Approved payment adjustments exist without journal entries.',
];

$checks[] = [
    'label' => 'Approved adjustments exceeding source payment',
    'query' => "SELECT COUNT(*)
        FROM (
            SELECT p.id
            FROM payments p
            INNER JOIN payment_adjustments pa ON pa.payment_id = p.id AND pa.status = 'approved'
            GROUP BY p.id, p.amount
            HAVING SUM(pa.amount) > p.amount + 0.01
        ) overflowed",
    'type' => 'failure',
    'ok_detail' => 'No payment is over-adjusted.',
    'bad_detail' => 'Some payments have approved adjustments above original payment amount.',
];

$checks[] = [
    'label' => 'Approved reads not yet billed',
    'query' => "SELECT COUNT(*) FROM meter_readings WHERE status = 'approved' AND bill_id IS NULL",
    'type' => 'warning',
    'ok_detail' => 'No approved meter readings are waiting for billing.',
    'bad_detail' => 'Approved meter readings are still waiting for billing.',
];

foreach ($checks as $check) {
    $stmt = $db->query($check['query']);
    $count = (int)($stmt ? $stmt->fetchColumn() : 0);
    if ($count === 0) {
        $detail = $check['ok_detail'];
        $checkResults[] = [
            'status' => 'PASS',
            'label' => $check['label'],
            'detail' => $detail,
            'count' => 0,
        ];
        audit_print('PASS', $check['label'], $check['ok_detail']);
        continue;
    }

    if ($check['type'] === 'warning') {
        $warnings++;
        $detail = $check['bad_detail'] . ' Count: ' . $count . '.';
        $checkResults[] = [
            'status' => 'WARN',
            'label' => $check['label'],
            'detail' => $detail,
            'count' => $count,
        ];
        audit_print('WARN', $check['label'], $detail);
    } else {
        $failures++;
        $detail = $check['bad_detail'] . ' Count: ' . $count . '.';
        $checkResults[] = [
            'status' => 'FAIL',
            'label' => $check['label'],
            'detail' => $detail,
            'count' => $count,
        ];
        audit_print('FAIL', $check['label'], $detail);
    }
}

$fromDate = date('Y-m-01');
$toDate = date('Y-m-d');
$reconciliation = $accounting->getReconciliationSummary($fromDate, $toDate);
$billingDelta = round((float)($reconciliation['billing_to_journal_delta'] ?? 0), 2);
$paymentsDelta = round((float)($reconciliation['payments_to_journal_delta'] ?? 0), 2);

if (abs($billingDelta) > 0.009 || abs($paymentsDelta) > 0.009) {
    $warnings++;
    $detail = 'Billing delta: ' . number_format($billingDelta, 2) . ', payment delta: ' . number_format($paymentsDelta, 2) . '.';
    $checkResults[] = [
        'status' => 'WARN',
        'label' => 'Current-month reconciliation deltas',
        'detail' => $detail,
        'count' => null,
    ];
    audit_print(
        'WARN',
        'Current-month reconciliation deltas',
        $detail
    );
} else {
    $detail = 'Billing and payment deltas are zero for the current month.';
    $checkResults[] = [
        'status' => 'PASS',
        'label' => 'Current-month reconciliation deltas',
        'detail' => $detail,
        'count' => 0,
    ];
    audit_print('PASS', 'Current-month reconciliation deltas', 'Billing and payment deltas are zero for the current month.');
}

echo PHP_EOL;
echo 'Summary: ' . $failures . ' failure(s), ' . $warnings . ' warning(s).' . PHP_EOL;

$overallStatus = $failures > 0 ? 'failure' : ($warnings > 0 ? 'warning' : 'pass');
audit_write_status($auditStatusFile, [
    'generated_at' => date('c'),
    'status' => $overallStatus,
    'failures' => $failures,
    'warnings' => $warnings,
    'checks' => $checkResults,
    'summary_line' => 'Summary: ' . $failures . ' failure(s), ' . $warnings . ' warning(s).',
]);

exit($failures > 0 ? 1 : 0);