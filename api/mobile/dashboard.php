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

    mobileApiJson(200, 'success', 'Dashboard loaded.', [
        'summary' => [
            'outstanding_amount' => round($outstandingTotal, 2),
            'pending_bills' => $pendingCount,
            'overdue_bills' => $overdueCount,
            'active_meters' => count(array_filter($meters, static function (array $meter): bool {
                return ($meter['status'] ?? 'active') === 'active';
            })),
            'requires_registration_payment' => (string)($user['status'] ?? 'active') !== 'active',
        ],
        'latest_bills' => array_map(static function (array $billRow) use ($billService, $paymentService): array {
            return mobileApiFormatBill($billService, $paymentService, $billRow);
        }, $bills),
        'latest_payment' => $latestPayment ? mobileApiFormatPayment($latestPayment) : null,
        'meters' => $meters,
    ]);
} catch (Throwable $e) {
    error_log('Mobile API dashboard failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load the dashboard right now.');
}