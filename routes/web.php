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
    'receipts' => 'pages/receipts.php',
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
    'admin/payment-transactions' => 'pages/admin/payment_transactions.php',
    'admin/bill-detail' => 'pages/admin/bill_detail.php',
    'admin/users' => 'pages/admin/users.php',
    'admin/staff-users' => 'pages/admin/staff_users.php',
    // Role permissions management (admin-only)
    'admin/role-permissions' => 'pages/admin/role_permissions.php',
    // Invoicing workspace
    'invoicing' => 'pages/admin/invoicing.php',
    'admin/invoicing' => 'pages/admin/invoicing.php',
    // Bill reading correction
    'admin/bill-correction' => 'pages/admin/bill_correction.php',
    // Financial reports
    'reports' => 'pages/admin/reports.php',
    'admin/reports' => 'pages/admin/reports.php',
    // Accounting management
    'accounting' => 'pages/admin/accounting.php',
    'admin/accounting' => 'pages/admin/accounting.php',
        'accounting/reports'         => 'pages/admin/accounting_reports.php',
        'accounting/budget'          => 'pages/admin/accounting_budget.php',
        'accounting/transfers'       => 'pages/admin/accounting_transfers.php',
        'accounting/ledger'          => 'pages/admin/accounting_ledger.php',
        'admin/accounting/reports'   => 'pages/admin/accounting_reports.php',
        'admin/accounting/budget'    => 'pages/admin/accounting_budget.php',
        'admin/accounting/transfers' => 'pages/admin/accounting_transfers.php',
        'admin/accounting/ledger'    => 'pages/admin/accounting_ledger.php',
    // Complaints admin alias
    'admin/complaints' => 'pages/admin/complaints.php',
    // Activity log & support chat
    'activity_log' => 'pages/admin/activity_log.php',
    'admin/activity_log' => 'pages/admin/activity_log.php',
    'chat' => 'pages/admin/chat.php',
    'admin/chat' => 'pages/admin/chat.php',
    'messaging' => 'pages/admin/messaging.php',
    'admin/messaging' => 'pages/admin/messaging.php',
    'admin/support-inquiries' => 'pages/admin/support_inquiries.php',
    'admin/demand-notices' => 'pages/admin/demand_notices.php',
    'admin/approvals' => 'pages/admin/approvals.php',
    'admin/integration-health' => 'pages/admin/integration_health.php',
    'internal-chat' => 'pages/admin/messaging.php',
    // Customer locations map (admin only)
    'admin/customer-locations' => 'pages/admin/customer_locations.php',
    'system-logs' => 'pages/admin/system_logs.php',
    'admin/system-logs' => 'pages/admin/system_logs.php',
    // Blog / News
    'blog' => 'pages/blog.php',
    'admin/news-updates' => 'pages/admin/blog.php',
    'admin/blog' => 'pages/admin/blog.php', // legacy alias kept for old links
];
