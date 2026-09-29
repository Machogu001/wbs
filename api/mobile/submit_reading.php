<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    mobileApiRequireMethod('POST');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    $userId = (int)$user['id'];

    $currentReading = (float)($_POST['current_reading'] ?? 0);
    $billingMonth = trim((string)($_POST['billing_month'] ?? '')) ?: date('Y-m-01', strtotime('first day of last month'));
    $dueDate = trim((string)($_POST['due_date'] ?? '')) ?: date('Y-m-d', strtotime('+3 days'));
    $requestedMeter = strtoupper(trim((string)($_POST['meter_number'] ?? '')));

    if ($currentReading <= 0) {
        mobileApiJson(422, 'error', 'Current reading must be greater than 0.');
    }

    if (!isset($_FILES['meter_photo']) || (int)$_FILES['meter_photo']['error'] !== UPLOAD_ERR_OK) {
        mobileApiJson(422, 'error', 'Meter photo is required.');
    }

    $meterService = new ClientMeter($db);
    $meters = $meterService->listByUserId($userId);
    $selectedMeter = null;
    foreach ($meters as $meter) {
        if (($meter['status'] ?? 'active') !== 'active') {
            continue;
        }

        if ($requestedMeter !== '' && strtoupper((string)$meter['meter_number']) === $requestedMeter) {
            $selectedMeter = $meter;
            break;
        }

        if ($requestedMeter === '' && !empty($meter['is_primary'])) {
            $selectedMeter = $meter;
            break;
        }
    }

    if ($selectedMeter === null) {
        foreach ($meters as $meter) {
            if (($meter['status'] ?? 'active') === 'active') {
                $selectedMeter = $meter;
                break;
            }
        }
    }

    if ($selectedMeter === null) {
        mobileApiJson(422, 'error', 'No active meter is available for this account.');
    }

    $photo = $_FILES['meter_photo'];
    $imageInfo = getimagesize((string)$photo['tmp_name']);
    $allowedTypes = ['image/jpeg', 'image/png'];
    if ($imageInfo === false || !in_array((string)$imageInfo['mime'], $allowedTypes, true)) {
        mobileApiJson(422, 'error', 'Please upload a valid JPG or PNG image.');
    }

    $uploadDir = __DIR__ . '/../../uploads/meter_readings';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Unable to prepare the meter photo upload directory.');
    }

    $extension = (string)$imageInfo['mime'] === 'image/png' ? 'png' : 'jpg';
    $filename = 'reading_' . (string)$user['account_number'] . '_' . time() . '.' . $extension;
    $destination = $uploadDir . '/' . $filename;
    if (!move_uploaded_file((string)$photo['tmp_name'], $destination)) {
        throw new RuntimeException('Failed to upload the meter photo.');
    }

    $photoPath = '/uploads/meter_readings/' . $filename;
    $settingsService = new BillingSettings($db);
    $settings = $settingsService->getSettings();
    $billService = new Bill($db);
    $billResult = $billService->createBillForUser(
        $userId,
        (string)$user['account_number'],
        $currentReading,
        $billingMonth,
        $dueDate,
        (float)($settings['rate_per_unit'] ?? 0),
        (float)($settings['service_charge'] ?? 0),
        'pending'
    );

    if (empty($billResult['success'])) {
        mobileApiJson(400, 'error', (string)($billResult['message'] ?? 'Failed to create the pending bill.'));
    }

    $readingService = new MeterReading($db);
    $readingId = $readingService->createReading(
        $userId,
        (string)$user['account_number'],
        (string)$selectedMeter['meter_number'],
        $currentReading,
        $billingMonth,
        $dueDate,
        $photoPath,
        $userId,
        (int)$billResult['bill_id'],
        'pending',
        null
    );

    if (!$readingId) {
        mobileApiJson(500, 'error', 'Failed to save the meter reading.');
    }

    $payUrl = PaymentLink::generateLink((int)$billResult['bill_id']);
    $billDate = date('d-m-Y');
    $messageText = 'AC: ' . (string)$user['account_number'] . "\n"
        . 'BillDate: ' . $billDate . "\n"
        . 'CurRead: ' . number_format((float)$billResult['current_reading'], 2) . "\n"
        . 'PrevRead: ' . number_format((float)$billResult['previous_reading'], 2) . "\n"
        . 'Units: ' . number_format((float)$billResult['consumption'], 2) . "\n"
        . 'Bill: KES ' . number_format((float)$billResult['amount'], 2) . "\n"
        . 'PrevBal: KES 0.00' . "\n"
        . 'Total to Pay: KES ' . number_format((float)$billResult['amount'], 2) . "\n"
        . 'DueDate: ' . date('d-m-Y', strtotime($dueDate)) . "\n"
        . 'Paybill: ' . MpesaConfig::getShortCode() . "\n"
        . 'Acc: ' . (string)$user['account_number'] . "\n"
        . 'Pay online: ' . $payUrl;

    try {
        $sms = new SMS($db);
        if (!empty($user['phone_number'])) {
            $sms->sendWithFallback((string)$user['phone_number'], $messageText, 'bill_notification');
        }
    } catch (Throwable $e) {
        error_log('Mobile API bill SMS failed: ' . $e->getMessage());
    }

    try {
        if (!empty($user['email'])) {
            $email = new Email();
            $email->queue((string)$user['email'], 'New water bill generated', $messageText, 'bill_notification');
        }
    } catch (Throwable $e) {
        error_log('Mobile API bill email failed: ' . $e->getMessage());
    }

    mobileApiJson(200, 'success', 'Meter reading submitted successfully.', [
        'reading' => [
            'id' => (int)$readingId,
            'meter_number' => (string)$selectedMeter['meter_number'],
            'status' => 'pending',
            'photo_url' => mobileApiBuildAbsoluteUrl($photoPath),
        ],
        'bill' => [
            'id' => (int)$billResult['bill_id'],
            'amount' => (float)$billResult['amount'],
            'public_payment_url' => mobileApiBuildAbsoluteUrl($payUrl),
            'document_url' => mobileApiBuildAbsoluteUrl('/invoice?t=' . urlencode(PaymentLink::generateToken((int)$billResult['bill_id']))),
        ],
    ]);
} catch (Throwable $e) {
    error_log('Mobile API submit reading failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to submit the meter reading right now.');
}