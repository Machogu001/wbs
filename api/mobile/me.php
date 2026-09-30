<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    $formattedUser = mobileApiFormatUser($db, $user);
    $requiresRegistrationPayment = (string)($user['status'] ?? 'active') !== 'active';
    $registrationPayment = $requiresRegistrationPayment ? mobileApiGetRegistrationPaymentData($db, (int)$user['id']) : null;

    mobileApiJson(200, 'success', 'Profile loaded.', [
        'user' => $formattedUser,
        'requires_registration_payment' => $requiresRegistrationPayment,
        'registration_payment' => $registrationPayment,
        'screen' => [
            'title' => 'Profile',
            'layout' => 'detail_sections',
            'primary_action' => [
                'type' => 'navigate',
                'label' => 'Edit Profile',
                'target' => '/api/mobile/profile_update.php',
            ],
            'secondary_actions' => [
                [
                    'type' => 'navigate',
                    'label' => 'Change Password',
                    'target' => '/api/mobile/change_password.php',
                ],
                [
                    'type' => 'navigate',
                    'label' => 'Theme Preference',
                    'target' => '/api/mobile/theme.php',
                ],
            ],
            'sections' => [
                [
                    'key' => 'user',
                    'title' => 'Account Details',
                    'presentation' => 'detail_list',
                    'fields' => [
                        ['key' => 'full_name', 'label' => 'Full Name'],
                        ['key' => 'account_number', 'label' => 'Account Number'],
                        ['key' => 'phone_number', 'label' => 'Phone Number'],
                        ['key' => 'email', 'label' => 'Email Address'],
                        ['key' => 'address', 'label' => 'Address'],
                        ['key' => 'connection_type', 'label' => 'Connection Type'],
                        ['key' => 'theme_preference', 'label' => 'Theme Preference'],
                    ],
                ],
                [
                    'key' => 'meters',
                    'title' => 'Linked Meters',
                    'presentation' => 'list',
                    'source' => 'user.meters',
                    'empty_state' => 'No linked meters found.',
                ],
            ],
            'alerts' => $requiresRegistrationPayment ? [[
                'type' => 'warning',
                'title' => 'Registration Payment Pending',
                'message' => 'Your account needs registration payment completion before full activation.',
                'action' => [
                    'type' => 'navigate',
                    'label' => 'Pay Registration Fee',
                    'target' => '/api/mobile/registration_payment.php',
                ],
            ]] : [],
        ],
    ]);
} catch (Throwable $e) {
    error_log('Mobile API profile failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load the profile right now.');
}