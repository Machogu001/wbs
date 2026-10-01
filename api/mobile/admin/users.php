<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/User.php';
require_once __DIR__ . '/../../../includes/ClientMeter.php';
require_once __DIR__ . '/../../../includes/BillingSettings.php';
require_once __DIR__ . '/../../../includes/Mpesa.php';
require_once __DIR__ . '/../../../includes/Payment.php';
require_once __DIR__ . '/../../../includes/Bill.php';
require_once __DIR__ . '/../../../includes/SMS.php';
require_once __DIR__ . '/../../../includes/Email.php';
require_once __DIR__ . '/../../../includes/PaymentLink.php';

function mobileApiNextCustomerAccountNumber(PDO $db): string
{
    $stmt = $db->query("SELECT account_number FROM users WHERE account_number LIKE 'MTR%' ORDER BY id DESC LIMIT 1");
    $last = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    $nextNumber = 1;
    if ($last && !empty($last['account_number']) && preg_match('/^MTR(\d+)$/', (string)$last['account_number'], $matches)) {
        $nextNumber = (int)$matches[1] + 1;
    }
    return 'MTR' . str_pad((string)$nextNumber, 4, '0', STR_PAD_LEFT);
}

try {
    $db = mobileApiGetDatabase();
    $actor = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $actor, ['view_customers']);

    $userService = new User($db);
    $meterService = new ClientMeter($db);
    $settingsService = new BillingSettings($db);
    $settings = $settingsService->getSettings();
    $registrationFee = isset($settings['registration_fee']) ? (float)$settings['registration_fee'] : 0.0;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $search = trim((string)($_GET['customer_search'] ?? $_GET['q'] ?? ''));
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
        $editId = (int)($_GET['edit_id'] ?? $_GET['user_id'] ?? 0);

        if ($search !== '') {
            $users = array_values(array_filter(
                $userService->searchByNameOrAccount($search, $limit),
                static fn(array $row): bool => strtolower((string)($row['role'] ?? 'customer')) === 'customer'
            ));
        } else {
            $stmt = $db->prepare("SELECT u.id, u.account_number, u.full_name, u.phone_number, u.email, u.id_number, u.address, u.tax_pin,
                u.meter_number, u.status, u.role, u.connection_type, u.unit_rate, u.location_label, u.latitude, u.longitude,
                COALESCE((SELECT GROUP_CONCAT(DISTINCT um.meter_number ORDER BY um.is_primary DESC, um.created_at ASC, um.id ASC SEPARATOR ',') FROM user_meters um WHERE um.user_id = u.id AND um.status = 'active'), u.meter_number) AS meter_numbers,
                COALESCE((SELECT GROUP_CONCAT(CONCAT(um.meter_number, '::', COALESCE(um.meter_label, '')) ORDER BY um.is_primary DESC, um.created_at ASC, um.id ASC SEPARATOR '||') FROM user_meters um WHERE um.user_id = u.id AND um.status = 'active'), CONCAT(COALESCE(u.meter_number, ''), '::')) AS meter_details
                FROM users u
                WHERE u.role = 'customer'
                ORDER BY u.full_name ASC
                LIMIT :limit");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $editUser = $editId > 0 ? $userService->getById($editId) : null;
        $editUserMeters = $editId > 0 ? $meterService->listByUserId($editId) : [];
        $editUserMeterReplacements = $editId > 0 ? $meterService->listReplacementsByUserId($editId) : [];

        mobileApiJson(200, 'success', 'Customers loaded.', [
            'registration_fee' => $registrationFee,
            'users' => $users,
            'edit_user' => $editUser,
            'edit_user_meters' => $editUserMeters,
            'edit_user_meter_replacements' => $editUserMeterReplacements,
            'form_metadata' => [
                'default_country_code' => '254',
                'country_code_options' => mobileApiCountryCodeOptions($db),
                'connection_type_options' => mobileApiConnectionTypeOptions(),
                'customer_type_options' => mobileApiCustomerTypeOptions(),
                'status_options' => mobileApiStatusOptions(),
            ],
        ]);
    }

    if ($method === 'POST') {
        if (!mobileApiUserHasRole($actor, 'admin')) {
            mobileApiJson(403, 'error', 'You do not have permission to modify customer records.');
        }
        $data = mobileApiReadJson();
        $formType = trim((string)($data['form_type'] ?? $data['action'] ?? ''));

        if ($formType === 'update_status') {
            $userId = (int)($data['user_id'] ?? 0);
            $newStatus = trim((string)($data['new_status'] ?? ''));
            if ($userId <= 0 || !in_array($newStatus, ['active', 'inactive', 'suspended'], true)) {
                mobileApiJson(422, 'error', 'Invalid user or status.');
            }
            $stmt = $db->prepare('UPDATE users SET status = :status WHERE id = :id AND role = :role');
            $stmt->execute([':status' => $newStatus, ':id' => $userId, ':role' => 'customer']);
            mobileApiJson(200, 'success', 'User status updated.');
        }

        if ($formType === 'delete_user') {
            $userId = (int)($data['user_id'] ?? 0);
            if ($userId <= 0) {
                mobileApiJson(422, 'error', 'Invalid user.');
            }
            if ((int)$actor['id'] === $userId) {
                mobileApiJson(422, 'error', 'You cannot delete your own account.');
            }
            $stmt = $db->prepare('DELETE FROM users WHERE id = :id AND role = :role');
            $stmt->execute([':id' => $userId, ':role' => 'customer']);
            mobileApiJson(200, 'success', 'User deleted successfully.');
        }

        if ($formType === 'add_client_meter') {
            $userId = (int)($data['user_id'] ?? 0);
            $meterNumber = mobileApiNormalizeMeter((string)($data['additional_meter_number'] ?? ''));
            $meterLabel = trim((string)($data['additional_meter_label'] ?? ''));
            if ($userId <= 0 || $meterNumber === '') {
                mobileApiJson(422, 'error', 'A valid customer and meter number are required.');
            }
            $targetUser = $userService->getById($userId);
            if (!$targetUser || strtolower((string)($targetUser['role'] ?? 'customer')) !== 'customer') {
                mobileApiJson(404, 'error', 'Customer not found.');
            }

            $primaryMeterNumber = mobileApiNormalizeMeter((string)($targetUser['meter_number'] ?? ''));
            if ($primaryMeterNumber !== '' && $primaryMeterNumber === $meterNumber) {
                mobileApiJson(422, 'error', 'That meter number is already the primary meter on this account.');
            }
            if ($meterService->meterExists($meterNumber)) {
                mobileApiJson(422, 'error', 'That meter number is already assigned to another account or meter record.');
            }

            $billId = null;
            $paymentLink = '';
            $db->beginTransaction();
            try {
                if ($registrationFee > 0) {
                    $billId = (new Bill($db))->createRegistrationFeeBill((int)$targetUser['id'], (string)$targetUser['account_number'], $registrationFee, date('Y-m-d', strtotime('+14 days')), 'pending');
                    if (!$billId) {
                        throw new RuntimeException('Failed to create the additional meter registration bill.');
                    }
                }
                $meterService->addMeter((int)$targetUser['id'], $meterNumber, $meterLabel !== '' ? $meterLabel : null, $billId ?: null, (int)$actor['id']);
                $db->commit();
                if ($billId) {
                    $paymentLink = PaymentLink::generateLink((int)$billId);
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }

            $amountText = $registrationFee > 0 ? ' A registration fee of KES ' . number_format($registrationFee, 2) . ' has been billed.' : '';
            $labelText = $meterLabel !== '' ? ' (' . $meterLabel . ')' : '';
            $messageText = 'Dear ' . (string)$targetUser['full_name'] . ', additional meter ' . $meterNumber . $labelText . ' has been linked to Account ' . (string)$targetUser['account_number'] . '.' . $amountText . ($paymentLink !== '' ? ' Pay here: ' . $paymentLink : '');
            if (!empty($targetUser['phone_number'])) {
                try {
                    (new SMS($db))->sendWithFallback((string)$targetUser['phone_number'], $messageText, 'additional_meter');
                } catch (Throwable $e) {
                    error_log('Additional meter SMS failed: ' . $e->getMessage());
                }
            }
            if (!empty($targetUser['email'])) {
                try {
                    (new Email())->queue((string)$targetUser['email'], 'Additional meter added to your account', $messageText, 'additional_meter');
                } catch (Throwable $e) {
                    error_log('Additional meter email failed: ' . $e->getMessage());
                }
            }
            mobileApiJson(200, 'success', $billId ? 'Additional meter added and registration fee bill created.' : 'Additional meter added successfully.', [
                'bill_id' => $billId,
                'payment_link' => $paymentLink,
            ]);
        }

        if ($formType === 'replace_client_meter') {
            $userId = (int)($data['user_id'] ?? 0);
            $oldMeterId = (int)($data['old_meter_id'] ?? 0);
            $newMeterNumber = mobileApiNormalizeMeter((string)($data['replacement_meter_number'] ?? ''));
            $newMeterLabel = trim((string)($data['replacement_meter_label'] ?? ''));
            $oldFinalReading = (float)($data['old_final_reading'] ?? 0);
            $newOpeningReading = (float)($data['new_opening_reading'] ?? 0);
            $replacementReason = trim((string)($data['replacement_reason'] ?? ''));

            if ($userId <= 0 || $oldMeterId <= 0 || $newMeterNumber === '') {
                mobileApiJson(422, 'error', 'Customer, faulty meter, and replacement meter number are required.');
            }

            $targetUser = $userService->getById($userId);
            if (!$targetUser || strtolower((string)($targetUser['role'] ?? 'customer')) !== 'customer') {
                mobileApiJson(404, 'error', 'Customer not found.');
            }

            $replacement = $meterService->replaceMeter(
                $userId,
                $oldMeterId,
                $newMeterNumber,
                $newMeterLabel !== '' ? $newMeterLabel : null,
                $oldFinalReading,
                $newOpeningReading,
                $replacementReason !== '' ? $replacementReason : null,
                (int)$actor['id']
            );

            $reasonText = $replacementReason !== '' ? ' Reason: ' . $replacementReason . '.' : '';
            $messageText = 'Dear ' . (string)$targetUser['full_name'] . ', faulty meter '
                . (string)($replacement['old_meter']['meter_number'] ?? '') . ' has been replaced with '
                . (string)$replacement['new_meter_number'] . ' on Account ' . (string)$targetUser['account_number']
                . '. Closing reading: ' . number_format((float)$replacement['old_final_reading'], 2)
                . '. New opening reading: ' . number_format((float)$replacement['new_opening_reading'], 2) . '.' . $reasonText;
            if (!empty($targetUser['phone_number'])) {
                try {
                    (new SMS($db))->sendWithFallback((string)$targetUser['phone_number'], $messageText, 'meter_replacement');
                } catch (Throwable $e) {
                    error_log('Meter replacement SMS failed: ' . $e->getMessage());
                }
            }
            if (!empty($targetUser['email'])) {
                try {
                    (new Email())->queue((string)$targetUser['email'], 'Meter replacement completed', $messageText, 'meter_replacement');
                } catch (Throwable $e) {
                    error_log('Meter replacement email failed: ' . $e->getMessage());
                }
            }

            mobileApiJson(200, 'success', 'Meter replaced successfully.', [
                'replacement' => $replacement,
            ]);
        }

        if ($formType === 'edit_user_save') {
            $userId = (int)($data['user_id'] ?? 0);
            $firstName = trim((string)($data['first_name'] ?? ''));
            $middleName = trim((string)($data['middle_name'] ?? ''));
            $lastName = trim((string)($data['last_name'] ?? ''));
            $fullName = trim(preg_replace('/\s+/', ' ', $firstName . ' ' . $middleName . ' ' . $lastName));
            $phoneNumber = mobileApiNormalizePhone((string)($data['phone_country_code'] ?? '254'), (string)($data['phone_number_local'] ?? ''));
            $email = trim((string)($data['email'] ?? ''));
            $idNumber = trim((string)($data['id_number'] ?? ''));
            $address = trim((string)($data['address'] ?? ''));
            $taxPin = trim((string)($data['tax_pin'] ?? ''));
            $customerType = trim((string)($data['customer_type'] ?? 'individual'));
            $companyName = trim((string)($data['company_name'] ?? ''));
            $contactPersonName = trim((string)($data['contact_person_name'] ?? ''));
            $companyRegistrationNumber = trim((string)($data['company_registration_number'] ?? ''));
            $meterNumber = mobileApiNormalizeMeter((string)($data['meter_number'] ?? ''));
            $connectionType = trim((string)($data['connection_type'] ?? 'domestic'));
            $unitRateInput = trim((string)($data['unit_rate'] ?? ''));
            $unitRate = $unitRateInput !== '' ? (float)$unitRateInput : null;
            $locationLabel = trim((string)($data['location_label'] ?? ''));
            $latitude = trim((string)($data['latitude'] ?? ''));
            $longitude = trim((string)($data['longitude'] ?? ''));
            $password = (string)($data['password'] ?? '');

            if ($userId <= 0 || $firstName === '' || $lastName === '' || $phoneNumber === '' || $idNumber === '' || $address === '') {
                mobileApiJson(422, 'error', 'Please fill in all required fields.');
            }
            if ($unitRate !== null && $unitRate < 0) {
                mobileApiJson(422, 'error', 'Client unit rate cannot be negative.');
            }

            $existingUser = $userService->getById($userId);
            if (!$existingUser || strtolower((string)($existingUser['role'] ?? '')) !== 'customer') {
                mobileApiJson(404, 'error', 'User not found.');
            }

            $stmtCheck = $db->prepare('SELECT id FROM users WHERE phone_number = :phone AND id <> :id LIMIT 1');
            $stmtCheck->execute([':phone' => $phoneNumber, ':id' => $userId]);
            if ($stmtCheck->fetch(PDO::FETCH_ASSOC)) {
                mobileApiJson(422, 'error', 'The phone number is already registered to another user.');
            }

            if ($meterNumber === '') {
                $meterNumber = mobileApiNormalizeMeter((string)($existingUser['meter_number'] ?? $existingUser['account_number'] ?? ''));
            }
            $existingPrimaryMeter = mobileApiNormalizeMeter((string)($existingUser['meter_number'] ?? ''));
            if ($meterNumber !== $existingPrimaryMeter && $meterService->meterExists($meterNumber)) {
                mobileApiJson(422, 'error', 'The meter number is already assigned to another user.');
            }

            $sql = 'UPDATE users SET full_name = :full_name, phone_number = :phone_number, email = :email, id_number = :id_number, address = :address, tax_pin = :tax_pin, customer_type = :customer_type, company_name = :company_name, contact_person_name = :contact_person_name, company_registration_number = :company_registration_number, meter_number = :meter_number, connection_type = :connection_type, unit_rate = :unit_rate, location_label = :location_label, latitude = :latitude, longitude = :longitude';
            $params = [
                ':full_name' => $fullName,
                ':phone_number' => $phoneNumber,
                ':email' => $email !== '' ? $email : null,
                ':id_number' => $idNumber,
                ':address' => $address,
                ':tax_pin' => $taxPin !== '' ? $taxPin : null,
                ':customer_type' => in_array($customerType, ['individual', 'company'], true) ? $customerType : 'individual',
                ':company_name' => $companyName !== '' ? $companyName : null,
                ':contact_person_name' => $contactPersonName !== '' ? $contactPersonName : null,
                ':company_registration_number' => $companyRegistrationNumber !== '' ? $companyRegistrationNumber : null,
                ':meter_number' => $meterNumber,
                ':connection_type' => $connectionType,
                ':unit_rate' => $unitRate,
                ':location_label' => $locationLabel !== '' ? $locationLabel : null,
                ':latitude' => $latitude !== '' ? (float)$latitude : null,
                ':longitude' => $longitude !== '' ? (float)$longitude : null,
                ':id' => $userId,
            ];
            if ($password !== '') {
                $sql .= ', password_hash = :password_hash';
                $params[':password_hash'] = password_hash($password, PASSWORD_BCRYPT);
            }
            $sql .= ' WHERE id = :id AND role = "customer"';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $meterService->syncPrimaryMeter($userId, $meterNumber);
            mobileApiJson(200, 'success', 'User updated successfully.');
        }

        if ($formType === 'resend_registration_stk') {
            $userId = (int)($data['user_id'] ?? 0);
            $targetUser = $userService->getById($userId);
            if (!$targetUser || strtolower((string)($targetUser['role'] ?? '')) !== 'customer') {
                mobileApiJson(404, 'error', 'Customer not found.');
            }
            if (($targetUser['status'] ?? '') === 'active') {
                mobileApiJson(422, 'error', 'This account is already active.');
            }
            if ($registrationFee <= 0) {
                mobileApiJson(422, 'error', 'No registration fee is configured.');
            }

            $paymentModel = new Payment($db);
            $billService = new Bill($db);
            $lastRegistrationPayment = $paymentModel->getLatestRegistrationByUserId($userId);
            $billId = !empty($lastRegistrationPayment['bill_id']) ? (int)$lastRegistrationPayment['bill_id'] : null;
            $amountToCharge = $billId ? $paymentModel->getBillOutstandingAmount($billId) : round($registrationFee, 2);
            if ($amountToCharge <= 0) {
                mobileApiJson(422, 'error', 'Registration fee already paid. Activate the account instead.');
            }

            if (!preg_match('/^(?:254|\+254|0)?((?:7|1)\d{8})$/', (string)($targetUser['phone_number'] ?? ''), $matches)) {
                mobileApiJson(422, 'error', 'A valid Kenyan M-Pesa phone number is required.');
            }
            $formattedPhone = '254' . $matches[1];
            $mpesa = new Mpesa();
            $response = $mpesa->stkPush($formattedPhone, $amountToCharge, (string)$targetUser['account_number'], 'Registration Fee');
            if (isset($response['error'])) {
                mobileApiJson(422, 'error', 'Payment initiation failed: ' . (string)$response['error']);
            }

            if (!$billId) {
                $billId = $billService->createRegistrationFeeBill((int)$targetUser['id'], (string)$targetUser['account_number'], $registrationFee, date('Y-m-d', strtotime('+14 days')), 'pending');
            }

            $newPayment = new Payment($db);
            $newPayment->bill_id = $billId;
            $newPayment->user_id = (int)$targetUser['id'];
            $newPayment->phone_number = $formattedPhone;
            $newPayment->amount = $amountToCharge;
            $newPayment->merchant_request_id = $response['MerchantRequestID'] ?? null;
            $newPayment->checkout_request_id = $response['CheckoutRequestID'] ?? null;
            $newPayment->status = 'pending';
            $newPayment->registration_id = (int)$targetUser['id'];
            if (!$newPayment->create()) {
                mobileApiJson(500, 'error', 'Failed to save the new payment record.');
            }
            mobileApiJson(200, 'success', 'Registration payment prompt sent successfully.', [
                'bill_id' => $billId,
                'amount' => $amountToCharge,
                'phone_number' => $formattedPhone,
            ]);
        }

        if ($formType === 'create_user' || $formType === 'create_customer' || $formType === '') {
            $firstName = trim((string)($data['first_name'] ?? ''));
            $middleName = trim((string)($data['middle_name'] ?? ''));
            $lastName = trim((string)($data['last_name'] ?? ''));
            $fullName = trim(preg_replace('/\s+/', ' ', $firstName . ' ' . $middleName . ' ' . $lastName));
            $phoneNumber = mobileApiNormalizePhone((string)($data['phone_country_code'] ?? '254'), (string)($data['phone_number_local'] ?? ''));
            $email = trim((string)($data['email'] ?? ''));
            $idNumber = trim((string)($data['id_number'] ?? ''));
            $address = trim((string)($data['address'] ?? ''));
            $taxPin = trim((string)($data['tax_pin'] ?? ''));
            $customerType = trim((string)($data['customer_type'] ?? 'individual'));
            $companyName = trim((string)($data['company_name'] ?? ''));
            $contactPersonName = trim((string)($data['contact_person_name'] ?? ''));
            $companyRegistrationNumber = trim((string)($data['company_registration_number'] ?? ''));
            $connectionType = trim((string)($data['connection_type'] ?? 'domestic'));
            $unitRateInput = trim((string)($data['unit_rate'] ?? ''));
            $unitRate = $unitRateInput !== '' ? (float)$unitRateInput : null;
            $locationLabel = trim((string)($data['location_label'] ?? ''));
            $latitude = trim((string)($data['latitude'] ?? ''));
            $longitude = trim((string)($data['longitude'] ?? ''));
            $password = (string)($data['password'] ?? '');
            $registrationAlreadyPaid = !empty($data['registration_already_paid']);
            $sendStk = !empty($data['send_stk']);
            $registrationMpesaCode = trim((string)($data['registration_mpesa_code'] ?? ''));
            $registrationPaidAmount = mobileApiNormalizeCurrencyAmount($data['registration_paid_amount'] ?? '');

            if ($firstName === '' || $lastName === '' || $phoneNumber === '' || $email === '' || $idNumber === '' || $address === '' || $password === '') {
                mobileApiJson(422, 'error', 'Please fill in all required fields.');
            }
            if ($unitRate !== null && $unitRate < 0) {
                mobileApiJson(422, 'error', 'Client unit rate cannot be negative.');
            }
            if ($userService->phoneExists($phoneNumber)) {
                mobileApiJson(422, 'error', 'The phone number is already registered to another user.');
            }
            if ($registrationFee > 0 && $registrationAlreadyPaid) {
                if ($registrationPaidAmount <= 0) {
                    $registrationPaidAmount = round($registrationFee, 2);
                }
                if ($registrationPaidAmount <= 0 || $registrationPaidAmount - round($registrationFee, 2) > 0.01) {
                    mobileApiJson(422, 'error', 'Amount already paid must be valid and cannot exceed the configured registration fee.');
                }
            }

            $accountNumber = mobileApiNextCustomerAccountNumber($db);
            $meterNumber = $accountNumber;
            if ($userService->meterNumberExists($meterNumber)) {
                mobileApiJson(422, 'error', 'Generated meter number is already in use. Please try again.');
            }

            $user = new User($db);
            $user->account_number = $accountNumber;
            $user->full_name = $fullName;
            $user->phone_number = $phoneNumber;
            $user->email = $email;
            $user->id_number = $idNumber;
            $user->address = $address;
            $user->tax_pin = $taxPin !== '' ? $taxPin : null;
            $user->customer_type = in_array($customerType, ['individual', 'company'], true) ? $customerType : 'individual';
            $user->company_name = $companyName !== '' ? $companyName : null;
            $user->contact_person_name = $contactPersonName !== '' ? $contactPersonName : null;
            $user->company_registration_number = $companyRegistrationNumber !== '' ? $companyRegistrationNumber : null;
            $user->meter_number = $meterNumber;
            $user->connection_type = $connectionType !== '' ? $connectionType : 'domestic';
            $user->unit_rate = $unitRate;
            $user->location_label = $locationLabel !== '' ? $locationLabel : null;
            $user->latitude = $latitude !== '' ? (float)$latitude : null;
            $user->longitude = $longitude !== '' ? (float)$longitude : null;
            $user->password = $password;
            $user->role = 'customer';

            $billService = new Bill($db);
            $paymentModel = new Payment($db);

            if ($registrationFee <= 0 || $registrationAlreadyPaid) {
                $user->status = $registrationFee > 0 ? 'inactive' : 'active';
                if (!$user->create()) {
                    mobileApiJson(500, 'error', 'Failed to create user account.');
                }

                if ($registrationFee > 0 && $registrationAlreadyPaid) {
                    $dueDate = date('Y-m-d');
                    $billId = $billService->createRegistrationFeeBill((int)$user->id, (string)$user->account_number, $registrationFee, $dueDate, 'pending');
                    if ($billId) {
                        $payment = new Payment($db);
                        $payment->bill_id = $billId;
                        $payment->user_id = (int)$user->id;
                        $payment->phone_number = $phoneNumber;
                        $payment->amount = $registrationPaidAmount;
                        $payment->merchant_request_id = null;
                        $payment->checkout_request_id = null;
                        $payment->mpesa_receipt = $registrationMpesaCode !== '' ? $registrationMpesaCode : null;
                        $payment->status = 'completed';
                        $payment->registration_id = (int)$user->id;
                        if (!$payment->create()) {
                            mobileApiJson(500, 'error', 'Failed to record the completed registration payment for the new user.');
                        }
                        $registrationBalance = $paymentModel->getBillOutstandingAmount((int)$billId);
                        $billRow = $billService->getById((int)$billId);
                        if ($registrationBalance <= 0.01 && (($billRow['status'] ?? '') === 'paid')) {
                            $db->prepare('UPDATE users SET status = "active" WHERE id = :id')->execute([':id' => (int)$user->id]);
                            $user->status = 'active';
                        }
                    }
                }
                mobileApiJson(201, 'success', 'User created successfully.', ['user_id' => (int)$user->id, 'account_number' => $accountNumber, 'status' => $user->status]);
            }

            if (!$sendStk) {
                mobileApiJson(422, 'error', 'Please either mark the registration fee as already paid or choose to send an M-Pesa STK push.');
            }
            if (!preg_match('/^(?:254|\+254|0)?((?:7|1)\d{8})$/', $phoneNumber, $matches)) {
                mobileApiJson(422, 'error', 'Invalid phone number format for M-Pesa payment.');
            }
            $formattedPhone = '254' . $matches[1];
            $response = (new Mpesa())->stkPush($formattedPhone, $registrationFee, $accountNumber, 'Registration Fee');
            if (isset($response['error'])) {
                mobileApiJson(422, 'error', 'Payment initiation failed: ' . (string)$response['error']);
            }

            $user->status = 'inactive';
            if (!$user->create()) {
                mobileApiJson(500, 'error', 'Failed to create user account after initiating payment.');
            }
            $billId = $billService->createRegistrationFeeBill((int)$user->id, (string)$user->account_number, $registrationFee, date('Y-m-d', strtotime('+14 days')), 'pending');
            $payment = new Payment($db);
            $payment->bill_id = $billId;
            $payment->user_id = (int)$user->id;
            $payment->phone_number = $formattedPhone;
            $payment->amount = $registrationFee;
            $payment->merchant_request_id = $response['MerchantRequestID'] ?? null;
            $payment->checkout_request_id = $response['CheckoutRequestID'] ?? null;
            $payment->status = 'pending';
            $payment->registration_id = (int)$user->id;
            $payment->create();
            mobileApiJson(201, 'success', 'User created and registration payment initiated via M-Pesa STK.', ['user_id' => (int)$user->id, 'account_number' => $accountNumber, 'bill_id' => $billId]);
        }

        mobileApiJson(422, 'error', 'Unsupported customer action.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin users failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process customer management right now.');
}