<?php

require_once __DIR__ . '/../_bootstrap.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $user, ['view_payments', 'receive_payments']);

    $days = max(1, min(90, (int)($_GET['days'] ?? 30)));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $fromSql = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

    $summaryStmt = $db->prepare("SELECT
        COUNT(*) AS total_payments,
        COALESCE(SUM(amount), 0) AS total_collected,
        COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) AS completed_collected,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_collected,
        COALESCE(SUM(CASE WHEN status = 'failed' THEN amount ELSE 0 END), 0) AS failed_collected,
        COUNT(DISTINCT user_id) AS customers_served
        FROM payments
        WHERE created_at >= :from_sql");
    $summaryStmt->execute([':from_sql' => $fromSql]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $methodStmt = $db->prepare("SELECT COALESCE(NULLIF(payment_method, ''), 'mpesa') AS payment_method,
        COUNT(*) AS payment_count,
        COALESCE(SUM(amount), 0) AS total_amount
        FROM payments
        WHERE created_at >= :from_sql AND status = 'completed'
        GROUP BY COALESCE(NULLIF(payment_method, ''), 'mpesa')
        ORDER BY total_amount DESC");
    $methodStmt->execute([':from_sql' => $fromSql]);
    $methodRows = $methodStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $paymentMethodOptions = array_map(static function (array $row): array {
        $value = (string)($row['payment_method'] ?? 'mpesa');
        $labels = [
            'mpesa' => 'M-Pesa',
            'cash' => 'Cash',
            'bank' => 'Bank Transfer',
            'card' => 'Card',
            'cheque' => 'Cheque',
            'wallet' => 'Wallet',
            'other' => 'Other',
        ];
        return [
            'value' => $value,
            'label' => $labels[$value] ?? ucfirst($value),
        ];
    }, $methodRows);

    $paymentsStmt = $db->prepare("SELECT p.*, u.full_name, u.account_number, b.billing_month
        FROM payments p
        LEFT JOIN users u ON u.id = p.user_id
        LEFT JOIN bills b ON b.id = p.bill_id
        WHERE p.created_at >= :from_sql
        ORDER BY COALESCE(p.transaction_date, p.created_at) DESC, p.id DESC
        LIMIT :limit");
    $paymentsStmt->bindValue(':from_sql', $fromSql);
    $paymentsStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $paymentsStmt->execute();
    $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    mobileApiJson(200, 'success', 'Collections loaded.', [
        'period_days' => $days,
        'summary' => [
            'total_payments' => (int)($summary['total_payments'] ?? 0),
            'total_collected' => (float)($summary['total_collected'] ?? 0),
            'completed_collected' => (float)($summary['completed_collected'] ?? 0),
            'pending_collected' => (float)($summary['pending_collected'] ?? 0),
            'failed_collected' => (float)($summary['failed_collected'] ?? 0),
            'customers_served' => (int)($summary['customers_served'] ?? 0),
        ],
        'by_payment_method' => array_map(static function (array $row): array {
            return [
                'payment_method' => (string)($row['payment_method'] ?? 'mpesa'),
                'payment_count' => (int)($row['payment_count'] ?? 0),
                'total_amount' => (float)($row['total_amount'] ?? 0),
            ];
        }, $methodRows),
        'period_options' => [
            ['value' => 7, 'label' => 'Last 7 days'],
            ['value' => 14, 'label' => 'Last 14 days'],
            ['value' => 30, 'label' => 'Last 30 days'],
            ['value' => 60, 'label' => 'Last 60 days'],
            ['value' => 90, 'label' => 'Last 90 days'],
        ],
        'payment_method_options' => $paymentMethodOptions,
        'field_metadata' => [
            'days' => [
                'label' => 'Period',
                'input_type' => 'dropdown',
                'options' => [
                    ['value' => 7, 'label' => 'Last 7 days'],
                    ['value' => 14, 'label' => 'Last 14 days'],
                    ['value' => 30, 'label' => 'Last 30 days'],
                    ['value' => 60, 'label' => 'Last 60 days'],
                    ['value' => 90, 'label' => 'Last 90 days'],
                ],
                'default_value' => $days,
            ],
            'limit' => [
                'label' => 'Rows',
                'input_type' => 'dropdown',
                'options' => [
                    ['value' => 10, 'label' => '10 records'],
                    ['value' => 20, 'label' => '20 records'],
                    ['value' => 50, 'label' => '50 records'],
                    ['value' => 100, 'label' => '100 records'],
                ],
                'default_value' => $limit,
            ],
        ],
        'summary_cards' => [
            ['key' => 'total_collected', 'label' => 'Total Collected', 'value' => (float)($summary['total_collected'] ?? 0)],
            ['key' => 'completed_collected', 'label' => 'Completed Payments', 'value' => (float)($summary['completed_collected'] ?? 0)],
            ['key' => 'customers_served', 'label' => 'Customers Served', 'value' => (int)($summary['customers_served'] ?? 0)],
            ['key' => 'total_payments', 'label' => 'Payment Count', 'value' => (int)($summary['total_payments'] ?? 0)],
        ],
        'recent_payments' => array_map(static function (array $row): array {
            return mobileApiFormatPayment($row) + [
                'full_name' => (string)($row['full_name'] ?? ''),
                'account_number' => (string)($row['account_number'] ?? ''),
            ];
        }, $payments),
    ]);
} catch (Throwable $e) {
    error_log('Mobile API admin collections failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load collections right now.');
}