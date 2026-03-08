<?php

// Web routes for the main WBS application.

return [
    '' => 'pages/index.php',
    'home' => 'pages/index.php',
    'register' => 'pages/register.php',
    'login' => 'pages/login.php',
    'change-password' => 'pages/change_password.php',
    'forgot-password' => 'pages/forgot_password.php',
    'registration-payment' => 'pages/registration_payment.php',
    'dashboard' => 'pages/dashboard.php',
    'bills' => 'pages/bills.php',
    'pay' => 'pages/pay_bill.php',
    'pay-link' => 'pages/pay_link.php',
    'payment-receipt' => 'pages/payment_receipt.php',
    'payment-receipt-pdf' => 'pages/payment_receipt_pdf.php',
    'profile' => 'pages/profile.php',
    'submit-reading' => 'pages/submit_reading.php',
    'invoice' => 'pages/invoice.php',
    'statement' => 'pages/statement.php',
    // Complaints (user & admin unified)
    'complaints' => 'pages/admin/complaints.php',
    'logout' => 'pages/logout.php',
    // Admin dashboard (System Setting)
    'settings' => 'pages/admin/index.php',
    // Legacy admin route kept for compatibility
    'admin' => 'pages/admin/index.php',
    'admin/payments' => 'pages/admin/payments.php',
    'admin/users' => 'pages/admin/users.php',
    // Invoicing workspace
    'invoicing' => 'pages/admin/invoicing.php',
    'admin/invoicing' => 'pages/admin/invoicing.php',
    // Financial reports
    'reports' => 'pages/admin/reports.php',
    'admin/reports' => 'pages/admin/reports.php',
    // Complaints admin alias
    'admin/complaints' => 'pages/admin/complaints.php',
    // Activity log & support chat
    'activity_log' => 'pages/admin/activity_log.php',
    'admin/activity_log' => 'pages/admin/activity_log.php',
    'chat' => 'pages/admin/chat.php',
    'admin/chat' => 'pages/admin/chat.php',
];
