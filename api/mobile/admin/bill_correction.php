<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/User.php';
require_once __DIR__ . '/../../../includes/BillCorrection.php';
require_once __DIR__ . '/../../../includes/BillingSettings.php';

try {
    $db = mobileApiGetDatabase();
    $actor = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $actor, ['correct_bills']);
    $userService = new User($db);
    $correctionService = new BillCorrection($db);
    $settings = (new BillingSettings($db))->getSettings();
    $currency = (string)($settings['currency_code'] ?? 'KES');
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $identifier = trim((string)($_GET['q'] ?? $_GET['account_or_meter'] ?? ''));
        $selectedBillId = (int)($_GET['bill_id'] ?? 0);
        $currentUser = $identifier !== '' ? mobileApiResolveClient($userService, $identifier) : null;
        $userBills = [];
        if ($currentUser) {
            $stmt = $db->prepare('SELECT id, billing_month, current_reading, amount, status, due_date, rate_per_unit FROM bills WHERE user_id = :user_id AND rate_per_unit > 0 ORDER BY billing_month DESC, id DESC');
            $stmt->execute([':user_id' => (int)$currentUser['id']]);
            $userBills = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $selectedBill = $selectedBillId > 0 ? $correctionService->getCorrectableBill($selectedBillId) : null;
        $history = $selectedBillId > 0 ? $correctionService->getHistoryForBill($selectedBillId) : [];
        mobileApiJson(200, 'success', 'Bill correction workspace loaded.', [
            'currency' => $currency,
            'current_user' => $currentUser,
            'bills' => $userBills,
            'selected_bill' => $selectedBill,
            'history' => $history,
        ]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        if ((string)($data['action'] ?? '') !== 'apply_correction') {
            mobileApiJson(422, 'error', 'Unsupported bill correction action.');
        }
        $billId = (int)($data['bill_id'] ?? 0);
        $newReading = (float)($data['new_reading'] ?? 0);
        $reason = trim((string)($data['reason'] ?? ''));
        $result = $correctionService->correctReading($billId, $newReading, $reason, (int)$actor['id']);
        mobileApiJson(200, 'success', 'Bill corrected successfully.', ['result' => $result]);
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin bill correction failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process bill correction right now.');
}