<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../config/mpesa_config.php';
require_once __DIR__ . '/../../../includes/User.php';
require_once __DIR__ . '/../../../includes/Bill.php';
require_once __DIR__ . '/../../../includes/BillingSettings.php';
require_once __DIR__ . '/../../../includes/MeterReading.php';
require_once __DIR__ . '/../../../includes/SMS.php';
require_once __DIR__ . '/../../../includes/PaymentLink.php';
require_once __DIR__ . '/../../../includes/ClientWallet.php';
require_once __DIR__ . '/../../../includes/Email.php';

try {
    $db = mobileApiGetDatabase();
    $actor = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $actor, ['view_invoicing']);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $settings = (new BillingSettings($db))->getSettings();

    if ($method === 'GET') {
        $billService = new Bill($db);
        $clientsSummary = $billService->getUsersBillingSummary();
        $totalClients = count($clientsSummary);
        $clientsWithUnpaid = 0;
        $paidClients = 0;
        $totalUnpaidAmount = 0.0;
        foreach ($clientsSummary as &$clientSummary) {
            $lastBillId = (int)($clientSummary['last_bill_id'] ?? 0);
            $totalUnpaid = (float)($clientSummary['total_unpaid'] ?? 0);
            $clientSummary['id'] = (int)($clientSummary['id'] ?? 0);
            $clientSummary['last_bill_id'] = $lastBillId;
            $clientSummary['last_amount'] = $clientSummary['last_amount'] !== null ? (float)$clientSummary['last_amount'] : null;
            $clientSummary['total_unpaid'] = $totalUnpaid;
            $clientSummary['document_url'] = $lastBillId > 0 ? mobileApiDocumentUrl('invoice', $lastBillId) : '';
            try {
                $clientSummary['payment_url'] = $lastBillId > 0 ? mobileApiBuildAbsoluteUrl(PaymentLink::generateLink($lastBillId)) : '';
            } catch (Throwable $e) {
                $clientSummary['payment_url'] = '';
            }
            $totalUnpaidAmount += $totalUnpaid;
            if ($totalUnpaid > 0) {
                $clientsWithUnpaid++;
            }
            if (($clientSummary['last_status'] ?? '') === 'paid') {
                $paidClients++;
            }
        }
        unset($clientSummary);
        mobileApiJson(200, 'success', 'Invoicing workspace loaded.', [
            'default_billing_month' => date('Y-m-01', strtotime('first day of last month')),
            'default_due_date' => date('Y-m-d', strtotime('+3 days')),
            'summary' => [
                'total_clients' => $totalClients,
                'clients_with_unpaid' => $clientsWithUnpaid,
                'paid_clients' => $paidClients,
                'total_unpaid_amount' => $totalUnpaidAmount,
            ],
            'clients_summary' => $clientsSummary,
            'field_metadata' => [
                'account_or_meter' => [
                    'input_type' => 'autocomplete',
                    'search_endpoint' => '/api/mobile/admin/search_clients.php?q={query}',
                    'placeholder' => 'Search by account, meter, or client name',
                    'selection_keys' => ['value', 'selection_value', 'account_number'],
                    'display_keys' => ['label', 'suggestion_text', 'full_name'],
                ],
                'current_reading' => [
                    'input_type' => 'number',
                    'min' => 0,
                    'step' => 0.01,
                ],
                'billing_month' => [
                    'input_type' => 'date',
                    'picker_mode' => 'month',
                    'format' => 'Y-m-01',
                    'default_value' => date('Y-m-01', strtotime('first day of last month')),
                ],
                'due_date' => [
                    'input_type' => 'date',
                    'picker_mode' => 'date',
                    'format' => 'Y-m-d',
                    'default_value' => date('Y-m-d', strtotime('+3 days')),
                ],
            ],
            'settings' => $settings,
        ]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $action = trim((string)($data['action'] ?? ''));
        $userService = new User($db);
        $readingService = new MeterReading($db);
        $billService = new Bill($db);

        $processEntry = static function (array $entry) use ($db, $userService, $readingService, $billService, $settings, $actor): array {
            $identifier = trim((string)($entry['account_or_meter'] ?? ''));
            $currentReading = (float)($entry['current_reading'] ?? 0);
            $billingMonth = trim((string)($entry['billing_month'] ?? '')) ?: date('Y-m-01', strtotime('first day of last month'));
            $dueDate = trim((string)($entry['due_date'] ?? '')) ?: date('Y-m-d', strtotime('+3 days'));
            if ($currentReading <= 0) {
                return ['success' => false, 'message' => 'Current reading must be greater than 0.'];
            }
            $user = mobileApiResolveClient($userService, $identifier);
            if (!$user) {
                return ['success' => false, 'message' => 'Account, meter number, or name not found.'];
            }
            $meterNumber = (string)($user['matched_meter_number'] ?? $user['meter_number'] ?? '');
            $billResult = $billService->createBillForUser((int)$user['id'], (string)$user['account_number'], $currentReading, $billingMonth, $dueDate, $settings['rate_per_unit'], $settings['service_charge'], 'pending', $meterNumber);
            if (empty($billResult['success'])) {
                return ['success' => false, 'message' => $billResult['message'] ?? 'Failed to create pending bill.'];
            }
            try {
                $billService->autoApplyWalletCreditToBill(
                    (int)$user['id'],
                    (int)$billResult['bill_id'],
                    (float)$billResult['amount'],
                    (string)($user['phone_number'] ?? ''),
                    (int)$actor['id']
                );
            } catch (Throwable $e) {
                error_log('Mobile API wallet auto-apply on bill creation failed: ' . $e->getMessage());
            }
            $balanceSummary = $billService->getNotificationBalanceSummary((int)$user['id'], (float)$billResult['amount']);
            $messageText = Bill::buildBillNotificationMessage(
                $user,
                $billResult,
                $dueDate,
                (float)$balanceSummary['previous_balance'],
                (float)$balanceSummary['total_to_pay'],
                MpesaConfig::getShortCode(),
                PaymentLink::generateLink((int)$billResult['bill_id']),
                null,
				$settings['bill_notification_template'] ?? null,
				$settings['company_name'] ?? null
            );
            try {
                (new SMS())->sendWithFallback((string)$user['phone_number'], $messageText, 'bill_notification');
            } catch (Throwable $e) {
            }
            if (!empty($user['email'])) {
                try {
                    (new Email())->queue((string)$user['email'], 'New water bill generated', $messageText, 'bill_notification');
                } catch (Throwable $e) {
                }
            }
            $readingId = $readingService->createReading((int)$user['id'], (string)$user['account_number'], $meterNumber, $currentReading, $billingMonth, $dueDate, null, (int)$actor['id'], (int)$billResult['bill_id'], 'approved', (int)$actor['id']);
            return $readingId ? ['success' => true, 'account_number' => $user['account_number'], 'bill_id' => $billResult['bill_id']] : ['success' => false, 'message' => 'Failed to submit meter reading.'];
        };

        if ($action === 'add_reading') {
            $entries = (array)($data['entries'] ?? []);
            if (empty($entries)) {
                $entries = [[
                    'account_or_meter' => $data['account_or_meter'] ?? '',
                    'current_reading' => $data['current_reading'] ?? '',
                    'billing_month' => $data['billing_month'] ?? date('Y-m-01', strtotime('first day of last month')),
                    'due_date' => $data['due_date'] ?? date('Y-m-d', strtotime('+3 days')),
                ]];
            }
            $success = [];
            $errors = [];
            foreach ($entries as $index => $entry) {
                $result = $processEntry($entry);
                if (!empty($result['success'])) {
                    $success[] = $result;
                } else {
                    $errors[] = 'Row ' . ($index + 1) . ': ' . ($result['message'] ?? 'Failed to process entry.');
                }
            }
            if (empty($success)) {
                mobileApiJson(422, 'error', implode(' ', $errors));
            }
            mobileApiJson(200, 'success', 'Meter readings submitted.', ['successful' => $success, 'errors' => $errors]);
        }

        if ($action === 'import_readings_csv') {
            $entries = (array)($data['entries'] ?? []);
            if (empty($entries)) {
                mobileApiJson(422, 'error', 'Entries are required for CSV import API mode.');
            }
            $success = [];
            $errors = [];
            foreach ($entries as $index => $entry) {
                $result = $processEntry($entry);
                if (!empty($result['success'])) {
                    $success[] = $result;
                } else {
                    $errors[] = 'Row ' . ($index + 1) . ': ' . ($result['message'] ?? 'Failed to process entry.');
                }
            }
            if (empty($success)) {
                mobileApiJson(422, 'error', implode(' ', $errors));
            }
            mobileApiJson(200, 'success', 'Reading import completed.', ['successful' => $success, 'errors' => $errors]);
        }

        mobileApiJson(422, 'error', 'Unsupported invoicing action.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin invoicing failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process invoicing right now.');
}