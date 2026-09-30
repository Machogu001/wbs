<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../config/mpesa_config.php';
require_once __DIR__ . '/../../../includes/BillingSettings.php';
require_once __DIR__ . '/../../../includes/ActivityLog.php';

try {
    $db = mobileApiGetDatabase();
    $actor = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $actor, ['manage_settings']);
    $settingsService = new BillingSettings($db);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $settings = $settingsService->getSettings();
        $mobileApiKey = MpesaConfig::getMobileApiKeyFromDatabase($db);
        if ($mobileApiKey === null || $mobileApiKey === '') {
            $mobileApiKey = MpesaConfig::getMobileApiKey();
        }
        $maskedKey = $mobileApiKey ? str_repeat('*', max(0, strlen($mobileApiKey) - 4)) . substr($mobileApiKey, -4) : '';
        mobileApiJson(200, 'success', 'Settings loaded.', [
            'settings' => $settings,
            'mobile_api_key_masked' => $maskedKey,
            'mobile_api_key' => !empty($_GET['include_api_key']) ? $mobileApiKey : null,
            'tariff_plans' => $settingsService->listTariffPlans(false),
        ]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $action = trim((string)($data['action'] ?? 'update_settings'));
        if ($action === 'update_settings') {
            $currentSettings = $settingsService->getSettings();
            $rate = array_key_exists('rate_per_unit', $data) ? (float)$data['rate_per_unit'] : (float)($currentSettings['rate_per_unit'] ?? 0);
            $service = array_key_exists('service_charge', $data) ? (float)$data['service_charge'] : (float)($currentSettings['service_charge'] ?? 0);
            if ($rate <= 0) {
                mobileApiJson(422, 'error', 'Rate per m3 must be greater than 0.');
            }
            $ok = $settingsService->updateSettings(
                $rate,
                $service,
                array_key_exists('company_pin', $data) ? trim((string)$data['company_pin']) : ($currentSettings['company_pin'] ?? null),
                array_key_exists('etims_integration_url', $data) ? trim((string)$data['etims_integration_url']) : ($currentSettings['etims_integration_url'] ?? null),
                array_key_exists('etims_api_key', $data) ? trim((string)$data['etims_api_key']) : ($currentSettings['etims_api_key'] ?? null),
                array_key_exists('company_name', $data) ? trim((string)$data['company_name']) : ($currentSettings['company_name'] ?? null),
                array_key_exists('support_phone', $data) ? trim((string)$data['support_phone']) : ($currentSettings['support_phone'] ?? null),
                array_key_exists('support_email', $data) ? trim((string)$data['support_email']) : ($currentSettings['support_email'] ?? null),
                array_key_exists('currency_code', $data) ? strtoupper(trim((string)$data['currency_code'])) : ($currentSettings['currency_code'] ?? null),
                array_key_exists('financial_year_start_month', $data) ? (int)$data['financial_year_start_month'] : (int)($currentSettings['financial_year_start_month'] ?? 1),
                array_key_exists('vat_rate', $data) ? $data['vat_rate'] : ($currentSettings['vat_rate'] ?? null),
                array_key_exists('etims_taxation_type_code', $data) ? $data['etims_taxation_type_code'] : ($currentSettings['etims_taxation_type_code'] ?? null),
                array_key_exists('registration_fee', $data) ? $data['registration_fee'] : ($currentSettings['registration_fee'] ?? null),
                array_key_exists('locale_code', $data) ? trim((string)$data['locale_code']) : ($currentSettings['locale_code'] ?? null),
                array_key_exists('timezone_name', $data) ? trim((string)$data['timezone_name']) : ($currentSettings['timezone_name'] ?? null),
                !empty($data['enforce_location_accuracy']) ? 1 : (int)($currentSettings['enforce_location_accuracy'] ?? 0),
                null,
                !empty($data['mobile_api_key_required']) ? 1 : 0
            );
            if (!$ok) {
                mobileApiJson(500, 'error', 'Failed to update billing settings.');
            }
            try {
                (new ActivityLog($db))->log((int)$actor['id'], 'update_settings', 'billing_settings', 1, 'Updated billing and company settings');
            } catch (Throwable $e) {
            }
            mobileApiJson(200, 'success', 'Billing settings updated successfully.');
        }

        if ($action === 'save_tariff_plan') {
            $savedId = $settingsService->saveTariffPlan([
                'id' => (int)($data['tariff_plan_id'] ?? 0),
                'name' => trim((string)($data['tariff_name'] ?? '')),
                'category' => trim((string)($data['tariff_category'] ?? 'all')),
                'effective_from' => trim((string)($data['effective_from'] ?? '')),
                'effective_to' => trim((string)($data['effective_to'] ?? '')),
                'base_rate_per_unit' => (float)($data['base_rate_per_unit'] ?? 0),
                'service_charge' => (float)($data['tariff_service_charge'] ?? 0),
                'vat_rate' => (float)($data['tariff_vat_rate'] ?? 0),
                'is_active' => !empty($data['tariff_is_active']) ? 1 : 0,
            ], (array)($data['blocks'] ?? []));
            mobileApiJson(200, 'success', 'Tariff plan saved successfully.', ['tariff_plan_id' => $savedId]);
        }

        if ($action === 'toggle_tariff_plan') {
            $planId = (int)($data['tariff_plan_id'] ?? 0);
            $isActive = !empty($data['is_active']) ? 1 : 0;
            if (!$settingsService->setTariffPlanStatus($planId, $isActive)) {
                mobileApiJson(500, 'error', 'Failed to update tariff status.');
            }
            mobileApiJson(200, 'success', $isActive ? 'Tariff plan activated.' : 'Tariff plan deactivated.');
        }

        if ($action === 'delete_tariff_plan') {
            if (!mobileApiUserHasRole($actor, 'admin')) {
                mobileApiJson(403, 'error', 'You are not allowed to delete tariff plans.');
            }
            if (!$settingsService->deleteTariffPlan((int)($data['tariff_plan_id'] ?? 0))) {
                mobileApiJson(404, 'error', 'Tariff plan not found or could not be deleted.');
            }
            mobileApiJson(200, 'success', 'Tariff plan deleted successfully.');
        }

        if ($action === 'delete_failed_payment') {
            if (!mobileApiUserHasRole($actor, 'admin')) {
                mobileApiJson(403, 'error', 'You are not allowed to delete payments.');
            }
            $paymentId = (int)($data['payment_id'] ?? 0);
            $stmt = $db->prepare('SELECT status FROM payments WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$payment || (string)$payment['status'] !== 'failed') {
                mobileApiJson(422, 'error', 'Only failed payments can be deleted.');
            }
            $db->beginTransaction();
            try {
                $db->prepare('DELETE FROM payment_adjustments WHERE payment_id = :id')->execute([':id' => $paymentId]);
                $deletePayment = $db->prepare("DELETE FROM payments WHERE id = :id AND status = 'failed'");
                $deletePayment->execute([':id' => $paymentId]);
                if ($deletePayment->rowCount() !== 1) {
                    throw new RuntimeException('Payment was changed before it could be deleted.');
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
            mobileApiJson(200, 'success', 'Failed payment deleted successfully.');
        }

        mobileApiJson(422, 'error', 'Unsupported settings action.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin settings failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process settings right now.');
}