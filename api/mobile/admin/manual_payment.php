<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/ClientWallet.php';

try {
    mobileApiRequireMethod('POST');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $user, ['receive_payments']);
    $data = mobileApiReadJson();

    $identifier = trim((string)($data['account_number'] ?? $data['account_or_meter'] ?? ''));
    $billId = (int)($data['bill_id'] ?? 0);
    $paymentTarget = trim((string)($data['payment_target'] ?? 'invoice'));
    if (!in_array($paymentTarget, ['invoice', 'balance'], true)) {
        $paymentTarget = 'invoice';
    }

    $paymentMethod = strtolower(trim((string)($data['payment_method'] ?? 'mpesa')));
    $allowedPaymentMethods = ['mpesa', 'cash', 'bank', 'card', 'cheque', 'wallet', 'other'];
    if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
        $paymentMethod = 'other';
    }

    $referenceNo = trim((string)($data['payment_reference'] ?? ''));
    $amount = (float)($data['amount'] ?? 0);
    $paidDate = trim((string)($data['paid_date'] ?? date('Y-m-d')));
    $paidTime = trim((string)($data['paid_time'] ?? date('H:i:s')));
    $phone = trim((string)($data['phone_number'] ?? ''));
    $paymentNote = trim((string)($data['payment_note'] ?? ''));
    $currentPassword = (string)($data['current_password'] ?? '');

    if ($identifier === '' || $amount <= 0 || $paidDate === '') {
        mobileApiJson(422, 'error', 'Account identifier, amount, and paid date are required.');
    }
    if ($paymentMethod === 'mpesa' && $referenceNo === '') {
        mobileApiJson(422, 'error', 'M-Pesa reference number is required for M-Pesa payments.');
    }

    $userService = new User($db);
    $billService = new Bill($db);
    $paymentService = new Payment($db);
    $wallet = new ClientWallet($db);

    $client = $userService->getByAccountNumber($identifier);
    if (!$client) {
        $client = $userService->getByMeterNumber($identifier);
    }
    if (!$client) {
        $matches = $userService->searchByNameOrAccount($identifier, 2);
        if (count($matches) === 1) {
            $client = $matches[0];
        }
    }
    if (!$client) {
        mobileApiJson(404, 'error', 'Account, meter number, or client name not found.');
    }

    $paidDateTime = date('Y-m-d H:i:s', strtotime($paidDate . ($paidTime !== '' ? ' ' . $paidTime : '')));
    if ($referenceNo === '') {
        $referenceNo = 'MANUAL-' . strtoupper(substr($paymentMethod, 0, 4)) . '-' . date('YmdHis');
    }

    $openBillsRaw = $billService->getBillsByUser((int)$client['id']);
    $openBills = array_values(array_filter($openBillsRaw, static function (array $row): bool {
        return in_array((string)($row['status'] ?? ''), ['pending', 'overdue'], true);
    }));
    usort($openBills, static function (array $a, array $b): int {
        $ad = strtotime((string)($a['due_date'] ?? $a['billing_month'] ?? '1970-01-01')) ?: 0;
        $bd = strtotime((string)($b['due_date'] ?? $b['billing_month'] ?? '1970-01-01')) ?: 0;
        return $ad === $bd ? ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0)) : ($ad <=> $bd);
    });

    $getOutstanding = static function (PDO $conn, int $forBillId, float $billAmount): float {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE bill_id = :bill_id AND status = 'completed'");
        $stmt->execute([':bill_id' => $forBillId]);
        return max(0.0, round($billAmount - (float)$stmt->fetchColumn(), 2));
    };

    $allocationPlan = [];
    $requiresPasswordConfirmation = false;
    $projectedExcessToWallet = 0.0;
    if ($paymentTarget === 'invoice') {
        if ($billId <= 0) {
            mobileApiJson(422, 'error', 'A bill ID is required when the payment target is invoice.');
        }
        $billRow = $billService->getById($billId, (int)$client['id']);
        if (!$billRow) {
            mobileApiJson(404, 'error', 'Selected bill was not found for this customer.');
        }
        $outstanding = $getOutstanding($db, (int)$billRow['id'], (float)$billRow['amount']);
        if ($outstanding <= 0.0) {
            mobileApiJson(422, 'error', 'This invoice is already fully settled.');
        }
        $applyAmount = round(min($amount, $outstanding), 2);
        $excessToWallet = round(max(0.0, $amount - $applyAmount), 2);
        $requiresPasswordConfirmation = $excessToWallet > 0.01;
        $projectedExcessToWallet = $excessToWallet;
        $allocationPlan[] = ['bill' => $billRow, 'amount' => $applyAmount, 'excess_to_wallet' => $excessToWallet];
    } else {
        $remaining = round($amount, 2);
        $totalOutstanding = 0.0;
        foreach ($openBills as $openBill) {
            $totalOutstanding += $getOutstanding($db, (int)$openBill['id'], (float)$openBill['amount']);
        }
        if ($totalOutstanding <= 0.0) {
            $allocationPlan[] = ['bill' => null, 'amount' => 0.0, 'excess_to_wallet' => round($amount, 2)];
        } else {
            foreach ($openBills as $openBill) {
                if ($remaining <= 0.0) {
                    break;
                }
                $billOutstanding = $getOutstanding($db, (int)$openBill['id'], (float)$openBill['amount']);
                if ($billOutstanding <= 0.0) {
                    continue;
                }
                $applyAmount = min($remaining, $billOutstanding);
                if ($applyAmount > 0.0) {
                    $allocationPlan[] = ['bill' => $openBill, 'amount' => round($applyAmount, 2), 'excess_to_wallet' => 0.0];
                    $remaining = round($remaining - $applyAmount, 2);
                }
            }
            if ($remaining > 0.01) {
                $requiresPasswordConfirmation = true;
                $projectedExcessToWallet = round($remaining, 2);
                if (!empty($allocationPlan)) {
                    $allocationPlan[count($allocationPlan) - 1]['excess_to_wallet'] = round($remaining, 2);
                } else {
                    $allocationPlan[] = ['bill' => null, 'amount' => 0.0, 'excess_to_wallet' => round($remaining, 2)];
                }
            }
        }
    }

    if ($requiresPasswordConfirmation) {
        $actorRow = $userService->getById((int)$user['id']);
        if (!$actorRow || empty($actorRow['password_hash']) || !password_verify($currentPassword, (string)$actorRow['password_hash'])) {
            mobileApiJson(422, 'error', 'Current password is required to confirm excess payment of ' . number_format($projectedExcessToWallet, 2) . ' as client credit.');
        }
    }

    $recordedPayments = [];
    $totalExcessToWallet = 0.0;
    $db->beginTransaction();
    try {
        $totalParts = count(array_filter($allocationPlan, static function (array $row): bool {
            return ($row['bill'] ?? null) !== null && (float)($row['amount'] ?? 0) > 0;
        }));

        foreach ($allocationPlan as $idx => $alloc) {
            $allocBill = $alloc['bill'];
            $excessToWallet = round((float)($alloc['excess_to_wallet'] ?? 0), 2);
            if ($allocBill === null || (float)$alloc['amount'] <= 0) {
                $totalExcessToWallet = round($totalExcessToWallet + $excessToWallet, 2);
                continue;
            }

            $allocAmount = (float)$alloc['amount'];
            $allocBillId = (int)$allocBill['id'];
            $allocReference = $referenceNo;
            if ($totalParts > 1) {
                $allocReference .= '-P' . ($idx + 1);
            }

            $isRegistrationBill = $billService->isRegistrationFeeBill($allocBill);
            $stmt = $db->prepare("INSERT INTO payments (bill_id, user_id, phone_number, payment_method, amount, mpesa_receipt, status, registration_id, transaction_date, received_by_user_id, created_at)
                VALUES (:bill_id, :user_id, :phone_number, :payment_method, :amount, :mpesa_receipt, 'completed', :registration_id, :transaction_date, :received_by_user_id, :created_at)");
            $stmt->execute([
                ':bill_id' => $allocBillId,
                ':user_id' => (int)$client['id'],
                ':phone_number' => $phone !== '' ? $phone : null,
                ':payment_method' => $paymentMethod,
                ':amount' => $allocAmount,
                ':mpesa_receipt' => $allocReference,
                ':registration_id' => $isRegistrationBill ? (int)$client['id'] : null,
                ':transaction_date' => $paidDateTime,
                ':received_by_user_id' => (int)$user['id'],
                ':created_at' => date('Y-m-d H:i:s'),
            ]);

            $paymentId = (int)$db->lastInsertId();
            $paymentService->finalizeCompletedPayment($paymentId, false);
            $paymentRow = $paymentService->getById($paymentId) ?: [];
            $paymentRow['account_number'] = (string)($allocBill['account_number'] ?? $client['account_number'] ?? '');
            $paymentRow['billing_month'] = (string)($allocBill['billing_month'] ?? '');
            $recordedPayments[] = mobileApiFormatPayment($paymentRow);
            $totalExcessToWallet = round($totalExcessToWallet + $excessToWallet, 2);
        }

        if ($totalExcessToWallet > 0.01) {
            $wallet->addCredit(
                (int)$client['id'],
                $totalExcessToWallet,
                $referenceNo,
                0,
                'Excess from receipt ' . $referenceNo . ($paymentNote !== '' ? ' - ' . $paymentNote : ''),
                (int)$user['id']
            );
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    mobileApiJson(201, 'success', 'Manual payment recorded successfully.', [
        'payments' => $recordedPayments,
        'wallet_credit_added' => $totalExcessToWallet,
    ]);
} catch (Throwable $e) {
    error_log('Mobile API admin manual payment failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to record the manual payment right now.');
}