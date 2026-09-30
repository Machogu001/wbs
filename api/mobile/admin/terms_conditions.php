<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/BillingSettings.php';
require_once __DIR__ . '/../../../includes/User.php';
require_once __DIR__ . '/../../../includes/ActivityLog.php';

try {
    $db = mobileApiGetDatabase();
    $actor = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $actor, ['manage_settings']);
    $settingsService = new BillingSettings($db);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $settings = $settingsService->getSettings();
        $renderedTerms = BillingSettings::renderTermsContent($settings, 'https://wbs.bremac.co.ke/');
        $renderedTermsText = BillingSettings::renderTermsPlainText($settings, 'https://wbs.bremac.co.ke/');
        $renderedTermsSections = BillingSettings::renderTermsSections($settings, 'https://wbs.bremac.co.ke/');
        mobileApiJson(200, 'success', 'Terms and conditions loaded.', [
            'terms_conditions_content' => $renderedTermsText,
            'terms_conditions_template' => $settings['terms_conditions_content'] ?? '',
            'rendered_terms' => $renderedTerms,
            'rendered_terms_text' => $renderedTermsText,
            'rendered_terms_sections' => $renderedTermsSections,
            'display_metadata' => [
                'preferred_format' => 'sections',
                'available_formats' => ['sections', 'text', 'html'],
            ],
            'sample_templates' => BillingSettings::getTermsTemplateSamples(),
        ]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $currentPassword = (string)($data['current_password'] ?? '');
        $termsContent = trim((string)($data['terms_conditions_template'] ?? ($data['terms_conditions_content'] ?? '')));
        $currentUser = (new User($db))->getById((int)$actor['id']);
        if (!$currentUser || empty($currentUser['password_hash']) || !password_verify($currentPassword, (string)$currentUser['password_hash'])) {
            mobileApiJson(422, 'error', 'Password is incorrect. Terms were not updated.');
        }
        if (!$settingsService->updateTermsContent($termsContent)) {
            mobileApiJson(500, 'error', 'Failed to save Terms & Conditions.');
        }
        try {
            (new ActivityLog($db))->log((int)$actor['id'], 'update_terms_conditions', 'billing_settings', 1, 'Updated terms and conditions content', ['content_length' => strlen($termsContent)]);
        } catch (Throwable $e) {
        }
        mobileApiJson(200, 'success', 'Terms & Conditions updated successfully.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin terms conditions failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process terms and conditions right now.');
}