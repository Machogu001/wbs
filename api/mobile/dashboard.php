<?php

require_once __DIR__ . '/_bootstrap.php';

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