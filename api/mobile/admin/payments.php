<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/User.php';
require_once __DIR__ . '/../../../includes/Bill.php';
require_once __DIR__ . '/../../../includes/CreditNote.php';
require_once __DIR__ . '/../../../includes/BillingSettings.php';
require_once __DIR__ . '/../../../includes/Payment.php';
require_once __DIR__ . '/../../../includes/FinanceApproval.php';
require_once __DIR__ . '/../../../includes/ClientWallet.php';

function mobileApiResolvePaymentClient(User $userService, string $identifier): ?array
{
    return mobileApiResolveClient($userService, $identifier);
}

try {
    $db = mobileApiGetDatabase();
    $actor = mobileApiRequireUser($db);
    if (!mobileApiUserHasAnyPermission($db, $actor, ['view_payments', 'receive_payments'])) {
        mobileApiJson(403, 'error', 'Forbidden.');
    }
    $userService = new User($db);
    $billService = new Bill($db);
    $creditService = new CreditNote($db);
    $paymentService = new Payment($db);
    $financeApproval = new FinanceApproval($db);
    $wallet = new ClientWallet($db);
    $settings = (new BillingSettings($db))->getSettings();
    $canReceivePayments = mobileApiUserHasRole($actor, 'admin') || mobileApiUserHasPermission($db, (int)$actor['id'], 'receive_payments');
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $account = trim((string)($_GET['account'] ?? $_GET['account_number'] ?? ''));
        $currentUser = $account !== '' ? mobileApiResolvePaymentClient($userService, $account) : null;
        $userBills = [];
        $userPayments = [];
        $paymentAdjustments = [];
        if ($currentUser) {
            $userBills = $billService->getBillsByUser((int)$currentUser['id']);
            foreach ($userBills as &$billRow) {
                $billRow['outstanding_amount'] = $paymentService->getBillOutstandingAmount((int)($billRow['id'] ?? 0));
            }
            unset($billRow);
            $userBills = array_values(array_filter($userBills, static function (array $bill): bool {
                return isset($bill['status']) && in_array((string)$bill['status'], ['pending', 'overdue'], true);
            }));
            $userPayments = $paymentService->getCompletedPaymentsByUserId((int)$currentUser['id']);
            $paymentAdjustments = $paymentService->getAdjustmentsByUserId((int)$currentUser['id']);
        }
        $paymentTargetOptions = [
            ['value' => 'invoice', 'label' => 'Invoice'],
            ['value' => 'balance', 'label' => 'Outstanding Balance'],
        ];
        $paymentMethodOptions = [
            ['value' => 'mpesa', 'label' => 'M-Pesa'],
            ['value' => 'cash', 'label' => 'Cash'],
            ['value' => 'bank', 'label' => 'Bank Transfer'],
            ['value' => 'card', 'label' => 'Card'],
            ['value' => 'cheque', 'label' => 'Cheque'],
            ['value' => 'wallet', 'label' => 'Wallet'],
            ['value' => 'other', 'label' => 'Other'],
        ];
        mobileApiJson(200, 'success', 'Payments workspace loaded.', [
            'can_receive_payments' => $canReceivePayments,
            'currency' => $settings['currency_code'] ?? 'KES',
            'current_user' => $currentUser,
            'bills' => $userBills,
            'payments' => $userPayments,
            'payment_adjustments' => $paymentAdjustments,
            'wallet_balance' => $currentUser ? $wallet->getBalance((int)$currentUser['id']) : 0.0,
            'payment_target_options' => $paymentTargetOptions,
            'payment_method_options' => $paymentMethodOptions,
            'field_metadata' => [
                'account_number' => [
                    'input_type' => 'autocomplete',
                    'search_endpoint' => '/api/mobile/admin/search_clients.php?q={query}',
                    'placeholder' => 'Search by account, meter, or client name',
                    'selection_keys' => ['value', 'selection_value', 'account_number'],
                    'display_keys' => ['label', 'suggestion_text', 'full_name'],
                ],
                'bill_id' => [
                    'input_type' => 'dropdown',
                    'source' => 'bills',
                    'label_key' => 'bill_number',
                    'fallback_label_template' => 'Invoice #{id} - {billing_month} - {outstanding_amount}',
                ],
                'payment_target' => [
                    'input_type' => 'dropdown',
                    'options' => $paymentTargetOptions,
                    'default_value' => 'invoice',
                ],
                'payment_method' => [
                    'input_type' => 'dropdown',
                    'options' => $paymentMethodOptions,
                    'default_value' => 'mpesa',
                ],
                'amount' => [
                    'input_type' => 'number',
                    'min' => 0.01,
                    'step' => 0.01,
                ],
                'paid_date' => [
                    'input_type' => 'date',
                    'picker_mode' => 'date',
                    'format' => 'Y-m-d',
                    'default_value' => date('Y-m-d'),
                ],
                'paid_time' => [
                    'input_type' => 'time',
                    'picker_mode' => 'time',
                    'format' => 'H:i',
                    'default_value' => date('H:i'),
                ],
                'payment_reference' => [
                    'input_type' => 'text',
                    'required_when' => [
                        'payment_method' => ['mpesa'],
                    ],
                ],
            ],
        ]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $action = trim((string)($data['action'] ?? ''));

        if ($action === 'search_account') {
            $account = trim((string)($data['account_number'] ?? ''));
            if ($account === '') {
                mobileApiJson(422, 'error', 'Please enter an account number.');
            }
            $currentUser = mobileApiResolvePaymentClient($userService, $account);
            if (!$currentUser) {
                mobileApiJson(404, 'error', 'Account, meter number, or name not found.');
            }
            $userBills = $billService->getBillsByUser((int)$currentUser['id']);
            foreach ($userBills as &$billRow) {
                $billRow['outstanding_amount'] = $paymentService->getBillOutstandingAmount((int)($billRow['id'] ?? 0));
            }
            unset($billRow);
            mobileApiJson(200, 'success', 'Account found.', [
                'current_user' => $currentUser,
                'bills' => $userBills,
                'payments' => $paymentService->getCompletedPaymentsByUserId((int)$currentUser['id']),
                'payment_adjustments' => $paymentService->getAdjustmentsByUserId((int)$currentUser['id']),
            ]);
        }

        if ($action === 'manual_payment') {
            if (!$canReceivePayments) {
                mobileApiJson(403, 'error', 'You are not allowed to record payments.');
            }
            $account = trim((string)($data['account_number'] ?? ''));
            $billId = (int)($data['bill_id'] ?? 0);
            $paymentTarget = trim((string)($data['payment_target'] ?? 'invoice'));
            $paymentTarget = in_array($paymentTarget, ['invoice', 'balance'], true) ? $paymentTarget : 'invoice';
            $paymentMethod = strtolower(trim((string)($data['payment_method'] ?? 'mpesa')));
            $allowedPaymentMethods = ['mpesa', 'cash', 'bank', 'card', 'cheque', 'wallet', 'other'];
            if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
                $paymentMethod = 'other';
            }
            $referenceNo = trim((string)($data['payment_reference'] ?? ''));
            $amount = (float)($data['amount'] ?? 0);
            $paidDate = trim((string)($data['paid_date'] ?? ''));
            $paidTime = trim((string)($data['paid_time'] ?? ''));
            $phone = trim((string)($data['phone_number'] ?? ''));
            $paymentNote = trim((string)($data['payment_note'] ?? ''));
            $currentUser = $account !== '' ? mobileApiResolvePaymentClient($userService, $account) : null;

            if (!$currentUser) {
                mobileApiJson(404, 'error', 'Account, meter number, or name not found.');
            }
            if ($amount <= 0 || $paidDate === '') {
                mobileApiJson(422, 'error', 'Please fill in all required fields (amount and paid date).');
            }
            if ($paymentMethod === 'mpesa' && $referenceNo === '') {
                mobileApiJson(422, 'error', 'M-Pesa reference number is required for M-Pesa payments.');
            }

            $paidDateTime = date('Y-m-d H:i:s', strtotime($paidDate . ($paidTime !== '' ? ' ' . $paidTime : '')));
            if ($referenceNo === '') {
                $referenceNo = 'MANUAL-' . strtoupper(substr($paymentMethod, 0, 4)) . '-' . date('YmdHis');
            }
            $openBills = array_values(array_filter($billService->getBillsByUser((int)$currentUser['id']), static function (array $bill): bool {
                return isset($bill['status']) && in_array((string)$bill['status'], ['pending', 'overdue'], true);
            }));
            usort($openBills, static function (array $a, array $b): int {
                $ad = strtotime((string)($a['due_date'] ?? $a['billing_month'] ?? '1970-01-01')) ?: 0;
                $bd = strtotime((string)($b['due_date'] ?? $b['billing_month'] ?? '1970-01-01')) ?: 0;
                return $ad <=> $bd;
            });
            $getOutstanding = static function (PDO $conn, int $forBillId, float $billAmount): float {
                $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE bill_id = :bill_id AND status = 'completed'");
                $stmt->execute([':bill_id' => $forBillId]);
                return max(0.0, round($billAmount - (float)$stmt->fetchColumn(), 2));
            };
            $allocationPlan = [];
            if ($paymentTarget === 'invoice') {
                $billRow = $billService->getById($billId, (int)$currentUser['id']);
                if (!$billRow || !in_array((string)$billRow['status'], ['pending', 'overdue'], true)) {
                    mobileApiJson(422, 'error', 'Selected bill not found or cannot be receipted manually.');
                }
                $outstanding = $getOutstanding($db, (int)$billRow['id'], (float)$billRow['amount']);
                if ($outstanding <= 0.0 || $amount > $outstanding + 0.01) {
                    mobileApiJson(422, 'error', 'Amount paid cannot exceed invoice outstanding amount.');
                }
                $allocationPlan[] = ['bill' => $billRow, 'amount' => round($amount, 2), 'excess_to_wallet' => 0.0];
            } else {
                $remaining = round($amount, 2);
                foreach ($openBills as $openBill) {
                    if ($remaining <= 0.0) {
                        break;
                    }
                    $billOutstanding = $getOutstanding($db, (int)$openBill['id'], (float)$openBill['amount']);
                    if ($billOutstanding <= 0.0) {
                        continue;
                    }
                    $applyAmount = min($remaining, $billOutstanding);
                    $allocationPlan[] = ['bill' => $openBill, 'amount' => round($applyAmount, 2), 'excess_to_wallet' => 0.0];
                    $remaining = round($remaining - $applyAmount, 2);
                }
                if ($remaining > 0.01) {
                    if (!empty($allocationPlan)) {
                        $allocationPlan[count($allocationPlan) - 1]['excess_to_wallet'] = $remaining;
                    } else {
                        $allocationPlan[] = ['bill' => null, 'amount' => 0.0, 'excess_to_wallet' => $remaining];
                    }
                }
            }

            $recordedPaymentIds = [];
            $db->beginTransaction();
            try {
                foreach ($allocationPlan as $index => $alloc) {
                    $allocBill = $alloc['bill'] ?? null;
                    if ($allocBill && ($alloc['amount'] ?? 0) > 0) {
                        $payment = new Payment($db);
                        $payment->bill_id = (int)$allocBill['id'];
                        $payment->user_id = (int)$currentUser['id'];
                        $payment->phone_number = $phone !== '' ? $phone : (string)($currentUser['phone_number'] ?? '');
                        $payment->payment_method = $paymentMethod;
                        $payment->amount = (float)$alloc['amount'];
                        $payment->mpesa_receipt = $referenceNo . (count($allocationPlan) > 1 ? '-' . ($index + 1) : '');
                        $payment->status = 'completed';
                        $payment->transaction_date = $paidDateTime;
                        $payment->received_by_user_id = (int)$actor['id'];
                        $payment->note = $paymentNote;
                        if (!$payment->create()) {
                            throw new RuntimeException('Failed to record manual payment.');
                        }
                        $recordedPaymentIds[] = (int)$payment->id;
                    }
                    $walletAmount = (float)($alloc['excess_to_wallet'] ?? 0.0);
                    if ($walletAmount > 0.01) {
                        $wallet->credit((int)$currentUser['id'], $walletAmount, 'manual_payment_excess', 'Excess manual payment credited to wallet', (int)$actor['id']);
                    }
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
            mobileApiJson(200, 'success', 'Manual payment recorded successfully.', ['payment_ids' => $recordedPaymentIds]);
        }

        if ($action === 'credit_note') {
            $account = trim((string)($data['account_number'] ?? ''));
            $billId = (int)($data['bill_id'] ?? 0);
            $type = trim((string)($data['credit_type'] ?? 'full'));
            $units = (float)($data['units'] ?? 0);
            $note = trim((string)($data['note'] ?? ''));
            $currentUser = $account !== '' ? mobileApiResolvePaymentClient($userService, $account) : null;
            if (!$currentUser) {
                mobileApiJson(404, 'error', 'Account, meter number, or name not found.');
            }
            $billRow = $billService->getById($billId, (int)$currentUser['id']);
            if (!$billRow || !in_array((string)$billRow['status'], ['pending', 'overdue'], true)) {
                mobileApiJson(422, 'error', 'Selected bill not found or cannot be adjusted by credit note.');
            }
            $prev = (float)$billRow['previous_reading'];
            $consumption = (float)$billRow['consumption'];
            $rate = (float)$billRow['rate_per_unit'];
            $serviceCharge = (float)$billRow['service_charge'];
            if ($type === 'full') {
                $unitsCredited = $consumption;
                $newConsumption = 0.0;
                $newCurrent = $prev;
                $newService = 0.0;
                $newAmount = 0.0;
            } else {
                if ($units <= 0 || $units > $consumption) {
                    mobileApiJson(422, 'error', 'Units to credit must be between 0 and current consumption.');
                }
                $unitsCredited = $units;
                $newConsumption = max(0.0, $consumption - $unitsCredited);
                $newCurrent = $prev + $newConsumption;
                $newService = $serviceCharge;
                $newAmount = ($newConsumption * $rate) + $newService;
            }
            $oldAmount = (float)$billRow['amount'];
            $amountCredited = max(0.0, $oldAmount - $newAmount);
            $stmt = $db->prepare('UPDATE bills SET previous_reading = :prev, current_reading = :curr, consumption = :consumption, service_charge = :service_charge, amount = :amount, status = :status WHERE id = :id');
            $status = $type === 'full' ? 'cancelled' : (string)$billRow['status'];
            $stmt->execute([':prev' => $prev, ':curr' => $newCurrent, ':consumption' => $newConsumption, ':service_charge' => $newService, ':amount' => $newAmount, ':status' => $status, ':id' => $billId]);
            $creditService->create($billId, (int)$currentUser['id'], $unitsCredited, $amountCredited, $type, (int)$actor['id'], $note);
            mobileApiJson(200, 'success', 'Credit note applied successfully.');
        }

        if ($action === 'payment_adjustment_request') {
            $account = trim((string)($data['account_number'] ?? ''));
            $paymentId = (int)($data['payment_id'] ?? 0);
            $adjustmentType = trim((string)($data['adjustment_type'] ?? 'refund'));
            $amount = (float)($data['amount'] ?? 0);
            $reason = trim((string)($data['reason'] ?? ''));
            $currentUser = $account !== '' ? mobileApiResolvePaymentClient($userService, $account) : null;
            if (!$currentUser) {
                mobileApiJson(404, 'error', 'Account, meter number, or name not found.');
            }
            $paymentRow = $paymentService->getById($paymentId);
            if (!$paymentRow || (int)($paymentRow['user_id'] ?? 0) !== (int)$currentUser['id']) {
                mobileApiJson(422, 'error', 'Selected payment does not belong to this account.');
            }
            $financeApproval->createPaymentAdjustmentRequest($paymentId, $adjustmentType, $amount, (int)$actor['id'], $reason);
            mobileApiJson(200, 'success', ucfirst($adjustmentType) . ' request submitted for approval.');
        }

        mobileApiJson(422, 'error', 'Unsupported payments action.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin payments failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process payments right now.');
}