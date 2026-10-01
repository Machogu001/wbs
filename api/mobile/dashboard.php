<?php

require_once __DIR__ . '/_bootstrap.php';

// Same system-wide figures the website dashboard shows administrators.
function mobileDashboardOverview(PDO $db): array
{
    $regBill = "(EXISTS (SELECT 1 FROM bill_line_items bli_reg WHERE bli_reg.bill_id = b.id AND bli_reg.line_type = 'registration_fee') OR (b.consumption = 0 AND b.rate_per_unit = 0 AND b.base_amount = 0 AND b.service_charge > 0))";
    $monthlyBill = 'NOT ' . $regBill;
    $regPay = "(p.registration_id IS NOT NULL OR EXISTS (SELECT 1 FROM bill_line_items bli_reg WHERE bli_reg.bill_id = p.bill_id AND bli_reg.line_type = 'registration_fee') OR EXISTS (SELECT 1 FROM bills b_reg WHERE b_reg.id = p.bill_id AND b_reg.consumption = 0 AND b_reg.rate_per_unit = 0 AND b_reg.base_amount = 0 AND b_reg.service_charge > 0))";
    $monthlyPay = 'NOT ' . $regPay;
    $outstandingExpr = "GREATEST(0, COALESCE(b.amount, 0) - COALESCE(p_paid.completed_paid, 0) + COALESCE(pa_adj.approved_adjustments, 0))";

    $billSummary = $db->query("SELECT
        COALESCE(SUM(CASE WHEN {$monthlyBill} AND b.status IN ('pending','overdue') AND b.amount > 0 THEN {$outstandingExpr} ELSE 0 END), 0) AS monthly_unpaid,
        COALESCE(SUM(CASE WHEN {$monthlyBill} AND b.status = 'pending' AND b.amount > 0 THEN {$outstandingExpr} ELSE 0 END), 0) AS monthly_pending_amount,
        COALESCE(SUM(CASE WHEN {$monthlyBill} AND b.status = 'overdue' AND b.amount > 0 THEN {$outstandingExpr} ELSE 0 END), 0) AS monthly_overdue_amount,
        COALESCE(SUM(CASE WHEN {$monthlyBill} AND b.status IN ('pending','overdue') AND b.amount > 0 AND {$outstandingExpr} > 0 THEN 1 ELSE 0 END), 0) AS monthly_pending_count,
        COALESCE(SUM(CASE WHEN {$regBill} AND b.amount > 0 THEN b.amount ELSE 0 END), 0) AS registration_billed_total,
        COALESCE(SUM(CASE WHEN {$regBill} AND b.amount > 0 THEN 1 ELSE 0 END), 0) AS registration_bills_count
        FROM bills b
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
        ) pa_adj ON pa_adj.bill_id = b.id")->fetch(PDO::FETCH_ASSOC) ?: [];

    $paymentSummary = $db->query("SELECT
        COALESCE(SUM(CASE WHEN {$monthlyPay} AND p.status = 'completed' AND p.amount > 0 THEN p.amount ELSE 0 END), 0) AS monthly_paid,
        COALESCE(SUM(CASE WHEN {$regPay} AND p.status = 'completed' AND p.amount > 0 THEN p.amount ELSE 0 END), 0) AS registration_collected_total,
        COALESCE(SUM(CASE WHEN {$regPay} AND p.status = 'completed' AND p.amount > 0 THEN 1 ELSE 0 END), 0) AS registration_payments_count
        FROM payments p")->fetch(PDO::FETCH_ASSOC) ?: [];

    $collected = (float)($paymentSummary['monthly_paid'] ?? 0);
    $unpaid = (float)($billSummary['monthly_unpaid'] ?? 0);
    $registrationBilled = (float)($billSummary['registration_billed_total'] ?? 0);
    $registrationCollected = (float)($paymentSummary['registration_collected_total'] ?? 0);

    $monthly = static function (PDO $db, string $sql): array {
        $rows = [];
        $stmt = $db->query($sql);
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rows[(string)$row['ym']] = (float)$row['total_amount'];
            }
        }
        return $rows;
    };
    $bills = $monthly($db, "SELECT DATE_FORMAT(billing_month, '%Y-%m') AS ym, SUM(amount) AS total_amount FROM bills b WHERE {$monthlyBill} GROUP BY ym ORDER BY ym ASC");
    $payments = $monthly($db, "SELECT DATE_FORMAT(COALESCE(transaction_date, created_at), '%Y-%m') AS ym, SUM(amount) AS total_amount FROM payments p WHERE {$monthlyPay} GROUP BY ym ORDER BY ym ASC");
    $regBills = $monthly($db, "SELECT DATE_FORMAT(billing_month, '%Y-%m') AS ym, SUM(amount) AS total_amount FROM bills b WHERE {$regBill} GROUP BY ym ORDER BY ym ASC");
    $regPayments = $monthly($db, "SELECT DATE_FORMAT(COALESCE(transaction_date, created_at), '%Y-%m') AS ym, SUM(amount) AS total_amount FROM payments p WHERE {$regPay} GROUP BY ym ORDER BY ym ASC");
    $usage = $monthly($db, "SELECT DATE_FORMAT(billing_month, '%Y-%m') AS ym, GREATEST(0, MAX(current_reading) - MIN(current_reading)) AS total_amount FROM meter_readings GROUP BY ym ORDER BY ym ASC");

    $months = array_unique(array_merge(array_keys($bills), array_keys($payments), array_keys($regBills), array_keys($regPayments), array_keys($usage)));
    sort($months);
    $months = array_slice(array_values(array_filter($months, static fn($ym) => $ym !== '')), -24);
    $trend = [];
    foreach ($months as $ym) {
        $dt = DateTime::createFromFormat('!Y-m', $ym);
        $trend[] = [
            'month' => $ym,
            'label' => $dt ? $dt->format('M Y') : $ym,
            'billed' => round($bills[$ym] ?? 0, 2),
            'paid' => round($payments[$ym] ?? 0, 2),
            'registration_billed' => round($regBills[$ym] ?? 0, 2),
            'registration_paid' => round($regPayments[$ym] ?? 0, 2),
            'usage' => round($usage[$ym] ?? 0, 2),
        ];
    }
    $usageValues = array_column($trend, 'usage');

    $audit = null;
    $auditFile = __DIR__ . '/../../logs/billing_audit_status.json';
    if (is_file($auditFile) && is_readable($auditFile)) {
        $decoded = json_decode((string)file_get_contents($auditFile), true);
        if (is_array($decoded)) {
            $audit = [
                'status' => (string)($decoded['status'] ?? ''),
                'summary_line' => (string)($decoded['summary_line'] ?? ''),
                'generated_at' => (string)($decoded['generated_at'] ?? ''),
            ];
        }
    }

    $recentPayments = $db->query("SELECT p.id, p.user_id, p.amount, p.status, p.mpesa_receipt, u.account_number, u.full_name, COALESCE(p.transaction_date, p.created_at) AS paid_at
        FROM payments p LEFT JOIN users u ON u.id = p.user_id ORDER BY COALESCE(p.transaction_date, p.created_at) DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $recentBills = $db->query("SELECT b.id, b.user_id, b.account_number, b.billing_month, b.amount, b.status, b.due_date
        FROM bills b ORDER BY b.billing_month DESC, b.id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $customers = (int)($db->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn() ?: 0);

    $denominator = $collected + $unpaid;

    return [
        'monthly_balance' => round($unpaid, 2),
        'monthly_collected' => round($collected, 2),
        'monthly_pending_count' => (int)($billSummary['monthly_pending_count'] ?? 0),
        'monthly_pending_amount' => round((float)($billSummary['monthly_pending_amount'] ?? 0), 2),
        'monthly_overdue_amount' => round((float)($billSummary['monthly_overdue_amount'] ?? 0), 2),
        'registration_billed_total' => round($registrationBilled, 2),
        'registration_bills_count' => (int)($billSummary['registration_bills_count'] ?? 0),
        'registration_collected_total' => round($registrationCollected, 2),
        'registration_payments_count' => (int)($paymentSummary['registration_payments_count'] ?? 0),
        'registration_outstanding_total' => round(max(0, $registrationBilled - $registrationCollected), 2),
        'collection_rate' => $denominator > 0 ? round($collected / $denominator * 100, 1) : null,
        'latest_usage' => $usageValues ? (float)end($usageValues) : 0.0,
        'previous_usage' => count($usageValues) > 1 ? (float)$usageValues[count($usageValues) - 2] : 0.0,
        'registered_customers' => $customers,
        'billing_audit' => $audit,
        'trend' => $trend,
        'recent_bills' => array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'user_id' => (int)($row['user_id'] ?? 0),
            'account_number' => (string)($row['account_number'] ?? ''),
            'billing_month' => (string)($row['billing_month'] ?? ''),
            'amount' => (float)($row['amount'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'due_date' => (string)($row['due_date'] ?? ''),
        ], $recentBills),
        'recent_payments' => array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'user_id' => (int)($row['user_id'] ?? 0),
            'account_number' => (string)($row['account_number'] ?? ''),
            'amount' => (float)($row['amount'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'full_name' => (string)($row['full_name'] ?? ''),
            'mpesa_receipt' => (string)($row['mpesa_receipt'] ?? ''),
            'paid_at' => (string)($row['paid_at'] ?? ''),
        ], $recentPayments),
    ];
}
try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    $userId = (int)$user['id'];

    $billService = new Bill($db);
    $paymentService = new Payment($db);
    $meterService = new ClientMeter($db);

    $stmt = $db->prepare('SELECT * FROM bills WHERE user_id = :user_id ORDER BY billing_month DESC, id DESC LIMIT 5');
    $stmt->execute([':user_id' => $userId]);
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $outstandingTotal = 0.0;
    $pendingCount = 0;
    $overdueCount = 0;
    foreach ($billService->getBillsByUser($userId) as $billRow) {
        $outstanding = $paymentService->getBillOutstandingAmount((int)$billRow['id']);
        if ($outstanding > 0.01) {
            $outstandingTotal += $outstanding;
            $pendingCount++;
            if ((string)($billRow['status'] ?? '') === 'overdue') {
                $overdueCount++;
            }
        }
    }

    $latestPaymentStmt = $db->prepare('SELECT p.*, b.account_number, b.billing_month
        FROM payments p
        LEFT JOIN bills b ON b.id = p.bill_id
        WHERE p.user_id = :user_id
        ORDER BY p.created_at DESC, p.id DESC
        LIMIT 1');
    $latestPaymentStmt->execute([':user_id' => $userId]);
    $latestPayment = $latestPaymentStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $meters = array_map('mobileApiFormatMeter', $meterService->listByUserId($userId));
    $latestBills = array_map(static function (array $billRow) use ($billService, $paymentService): array {
        return mobileApiFormatBill($billService, $paymentService, $billRow);
    }, $bills);
    $formattedLatestPayment = $latestPayment ? mobileApiFormatPayment($latestPayment) : null;
    $activeMeterCount = count(array_filter($meters, static function (array $meter): bool {
        return ($meter['status'] ?? 'active') === 'active';
    }));
    $requiresRegistrationPayment = (string)($user['status'] ?? 'active') !== 'active';

    $overview = null;
    $overviewError = null;
    if (mobileApiUserHasAnyPermission($db, $user, ['view_reports', 'view_payments', 'view_accounting'])) {
        try {
            $overview = mobileDashboardOverview($db);
        } catch (Throwable $overviewException) {
            error_log('Mobile API dashboard overview failed: ' . $overviewException->getMessage());
            $overviewError = 'The operations overview could not be loaded right now.';
        }
    }
    mobileApiJson(200, 'success', 'Dashboard loaded.', [
        'summary' => [
            'outstanding_amount' => round($outstandingTotal, 2),
            'pending_bills' => $pendingCount,
            'overdue_bills' => $overdueCount,
            'active_meters' => $activeMeterCount,
            'requires_registration_payment' => $requiresRegistrationPayment,
        ],
        'latest_bills' => $latestBills,
        'latest_payment' => $formattedLatestPayment,
        'meters' => $meters,
        'overview' => $overview,
        'overview_error' => $overviewError,
        'screen' => [
            'title' => 'Dashboard',
            'layout' => 'summary_first',
            'primary_action' => [
                'type' => 'navigate',
                'label' => $requiresRegistrationPayment ? 'Complete Registration Payment' : 'View Bills',
                'target' => $requiresRegistrationPayment ? '/api/mobile/registration_payment.php' : '/api/mobile/bills.php',
            ],
            'secondary_actions' => [
                [
                    'type' => 'navigate',
                    'label' => 'Payment History',
                    'target' => '/api/mobile/payments.php',
                ],
                [
                    'type' => 'navigate',
                    'label' => 'Profile',
                    'target' => '/api/mobile/me.php',
                ],
            ],
            'summary_cards' => [
                [
                    'key' => 'outstanding_amount',
                    'label' => 'Outstanding Balance',
                    'value' => round($outstandingTotal, 2),
                    'emphasis' => $outstandingTotal > 0.01 ? 'warning' : 'positive',
                ],
                [
                    'key' => 'pending_bills',
                    'label' => 'Pending Bills',
                    'value' => $pendingCount,
                    'emphasis' => $pendingCount > 0 ? 'warning' : 'neutral',
                ],
                [
                    'key' => 'overdue_bills',
                    'label' => 'Overdue Bills',
                    'value' => $overdueCount,
                    'emphasis' => $overdueCount > 0 ? 'danger' : 'neutral',
                ],
                [
                    'key' => 'active_meters',
                    'label' => 'Active Meters',
                    'value' => $activeMeterCount,
                    'emphasis' => 'neutral',
                ],
            ],
            'sections' => [
                [
                    'key' => 'latest_bills',
                    'title' => 'Latest Bills',
                    'presentation' => 'list',
                    'empty_state' => 'No bills available yet.',
                ],
                [
                    'key' => 'latest_payment',
                    'title' => 'Latest Payment',
                    'presentation' => 'detail_card',
                    'empty_state' => 'No payment has been recorded yet.',
                ],
                [
                    'key' => 'meters',
                    'title' => 'Meters',
                    'presentation' => 'list',
                    'empty_state' => 'No active meters found.',
                ],
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log('Mobile API dashboard failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load the dashboard right now.');
}