<?php

// Streams invoice, proforma and receipt PDFs to the mobile app using bearer-token
// authentication, so the app can render documents natively instead of loading
// website pages.

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);

    $type = strtolower(trim((string)($_GET['type'] ?? 'invoice')));
    if (!in_array($type, ['invoice', 'proforma', 'receipt'], true)) {
        mobileApiJson(422, 'error', 'Unsupported document type.');
    }

    $billService = new Bill($db);
    $paymentService = new Payment($db);
    $paymentId = (int)($_GET['payment_id'] ?? 0);
    $billId = (int)($_GET['bill_id'] ?? 0);
    $payment = null;

    if ($type === 'receipt') {
        $payment = $paymentId > 0 ? $paymentService->getById($paymentId) : null;
        if (!$payment || empty($payment['bill_id'])) {
            mobileApiJson(404, 'error', 'Receipt not found.');
        }
        if ((string)($payment['status'] ?? '') !== 'completed') {
            mobileApiJson(422, 'error', 'A receipt is only available for completed payments.');
        }
        $billId = (int)$payment['bill_id'];
    }

    if ($billId <= 0) {
        mobileApiJson(422, 'error', 'A valid bill is required.');
    }

    $bill = $billService->getById($billId, null);
    if (!$bill) {
        mobileApiJson(404, 'error', 'Document not found.');
    }

    $isOwner = (int)($bill['user_id'] ?? 0) === (int)($user['id'] ?? 0)
        && ($payment === null || (int)($payment['user_id'] ?? 0) === (int)($user['id'] ?? 0));
    $isRegistrationBill = $billService->isRegistrationFeeBill($bill);
    $staffPermissions = ['view_invoicing', 'view_bill_detail'];
    if ($type === 'receipt') {
        $staffPermissions = array_merge($staffPermissions, ['view_payments', 'receive_payments']);
    }
    $isAllowedStaff = mobileApiUserHasAnyPermission($db, $user, $staffPermissions)
        || ($isRegistrationBill && mobileApiUserHasPermission($db, $user, 'manage_registration_proformas'));

    if (!$isOwner && !$isAllowedStaff) {
        mobileApiJson(403, 'error', 'You do not have access to this document.');
    }

    // The website document pages authorise via a signed bill token; generate one
    // after the bearer-token checks above and render the same PDF as the website.
    $_GET = ['t' => PaymentLink::generateToken($billId)];
    header_remove('Content-Type');

    if ($type === 'receipt') {
        $_GET['p'] = (string)(int)$payment['id'];
        require __DIR__ . '/../../pages/payment_receipt_pdf.php';
        exit;
    }

    if ($type === 'proforma' && $isRegistrationBill) {
        $_GET['proforma'] = '1';
    }
    require __DIR__ . '/../../pages/invoice.php';
    exit;
} catch (Throwable $e) {
    error_log('Mobile API document failed: ' . $e->getMessage());
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    mobileApiJson(500, 'error', 'Unable to load this document right now.');
}
