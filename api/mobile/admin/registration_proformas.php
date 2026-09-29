<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/User.php';
require_once __DIR__ . '/../../../includes/Bill.php';
require_once __DIR__ . '/../../../includes/Payment.php';
require_once __DIR__ . '/../../../includes/BillingSettings.php';
require_once __DIR__ . '/../../../includes/Mpesa.php';
require_once __DIR__ . '/../../../includes/PaymentLink.php';
require_once __DIR__ . '/../../../includes/SMS.php';
require_once __DIR__ . '/../../../includes/Email.php';

function mobileApiEnsureRegistrationProformasTable(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS registration_proformas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        bill_id INT NOT NULL,
        created_by_user_id INT NULL,
        notes TEXT NULL,
        account_setup_token VARCHAR(96) NULL,
        account_setup_expires_at DATETIME NULL,
        account_setup_completed_at DATETIME NULL,
        account_setup_sent_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_registration_proforma_user (user_id),
        UNIQUE KEY uniq_registration_proforma_bill (bill_id),
        KEY idx_registration_proforma_created_by (created_by_user_id),
        KEY idx_registration_proforma_setup_token (account_setup_token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function mobileApiNextRegistrationAccountNumber(PDO $db, string $prefix): string
{
    $stmt = $db->prepare('SELECT account_number FROM users WHERE account_number LIKE :prefix ORDER BY id DESC LIMIT 1');
    $stmt->execute([':prefix' => $prefix . '%']);
    $last = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $next = 1;
    if ($last && !empty($last['account_number']) && preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', (string)$last['account_number'], $matches)) {
        $next = (int)$matches[1] + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

try {
    $db = mobileApiGetDatabase();
    $actor = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $actor, ['manage_registration_proformas']);
    mobileApiEnsureRegistrationProformasTable($db);
    $userService = new User($db);
    $billService = new Bill($db);
    $paymentService = new Payment($db);
    $settings = (new BillingSettings($db))->getSettings();
    $registrationFee = isset($settings['registration_fee']) ? (float)$settings['registration_fee'] : 0.0;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $stmt = $db->query("SELECT rp.*, u.account_number, u.full_name, u.phone_number, u.email, u.status AS user_status, b.amount AS bill_amount, b.status AS bill_status FROM registration_proformas rp INNER JOIN users u ON u.id = rp.user_id INNER JOIN bills b ON b.id = rp.bill_id ORDER BY rp.created_at DESC LIMIT 200");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as &$row) {
            $row['share_url'] = PaymentLink::generateRegistrationProformaLink((int)$row['bill_id']);
            $row['outstanding_amount'] = $paymentService->getBillOutstandingAmount((int)$row['bill_id']);
        }
        unset($row);
        mobileApiJson(200, 'success', 'Registration proformas loaded.', ['registration_fee' => $registrationFee, 'proformas' => $rows]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $formType = trim((string)($data['form_type'] ?? $data['action'] ?? ''));

        if ($formType === 'create_proforma') {
            if ($registrationFee <= 0) {
                mobileApiJson(422, 'error', 'Registration fee is not configured.');
            }
            $firstName = trim((string)($data['first_name'] ?? ''));
            $middleName = trim((string)($data['middle_name'] ?? ''));
            $lastName = trim((string)($data['last_name'] ?? ''));
            $customerType = strtolower(trim((string)($data['customer_type'] ?? 'individual')));
            $customerType = in_array($customerType, ['individual', 'company'], true) ? $customerType : 'individual';
            $contactPersonName = trim(preg_replace('/\s+/', ' ', $firstName . ' ' . $middleName . ' ' . $lastName));
            $companyName = trim((string)($data['company_name'] ?? ''));
            $companyRegistrationNumber = trim((string)($data['company_registration_number'] ?? ''));
            $fullName = $customerType === 'company' ? $companyName : trim(preg_replace('/\s+/', ' ', $firstName . ' ' . $middleName . ' ' . $lastName));
            $phoneNumber = mobileApiNormalizePhone((string)($data['phone_country_code'] ?? ''), (string)($data['phone_number_local'] ?? ''));
            $email = trim((string)($data['email'] ?? ''));
            $idNumber = trim((string)($data['id_number'] ?? ''));
            $address = trim((string)($data['address'] ?? ''));
            $taxPin = trim((string)($data['tax_pin'] ?? ''));
            $locationLabel = trim((string)($data['location_label'] ?? ''));
            $latitude = trim((string)($data['latitude'] ?? ''));
            $longitude = trim((string)($data['longitude'] ?? ''));
            $connectionType = strtolower(trim((string)($data['connection_type'] ?? 'domestic')));
            $unitRateInput = trim((string)($data['unit_rate'] ?? ''));
            $unitRate = $unitRateInput !== '' ? (float)$unitRateInput : null;
            $notes = trim((string)($data['notes'] ?? ''));

            if ($firstName === '' || $lastName === '' || $phoneNumber === '' || $email === '' || $address === '') {
                mobileApiJson(422, 'error', 'Please fill in all required customer details.');
            }
            if ($customerType === 'company') {
                if ($companyName === '' || $companyRegistrationNumber === '') {
                    mobileApiJson(422, 'error', 'Company name and company registration number are required for company proformas.');
                }
            } elseif ($idNumber === '') {
                mobileApiJson(422, 'error', 'ID number is required for individual customer proformas.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                mobileApiJson(422, 'error', 'Enter a valid email address.');
            }
            if (!in_array($connectionType, ['domestic', 'commercial', 'industrial'], true)) {
                mobileApiJson(422, 'error', 'Choose a valid connection type.');
            }
            if ($unitRate !== null && $unitRate < 0) {
                mobileApiJson(422, 'error', 'Client unit rate cannot be negative.');
            }
            if ($userService->phoneExists($phoneNumber)) {
                mobileApiJson(422, 'error', 'Phone number already exists in the system.');
            }

            $conflictSql = 'SELECT id FROM users WHERE email = :email';
            $conflictParams = [':email' => $email];
            if ($customerType === 'company') {
                $conflictSql .= ' OR company_registration_number = :company_registration_number';
                $conflictParams[':company_registration_number'] = $companyRegistrationNumber;
            } else {
                $conflictSql .= ' OR id_number = :id_number';
                $conflictParams[':id_number'] = $idNumber;
            }
            $stmtConflict = $db->prepare($conflictSql . ' LIMIT 1');
            $stmtConflict->execute($conflictParams);
            if ($stmtConflict->fetch(PDO::FETCH_ASSOC)) {
                mobileApiJson(422, 'error', 'A user with the same primary identity already exists.');
            }

            $user = new User($db);
            $accountNumber = mobileApiNextRegistrationAccountNumber($db, 'MTR');
            $user->account_number = $accountNumber;
            $user->username = null;
            $user->full_name = $fullName;
            $user->customer_type = $customerType;
            $user->company_name = $customerType === 'company' ? $companyName : null;
            $user->contact_person_name = $customerType === 'company' ? $contactPersonName : null;
            $user->company_registration_number = $customerType === 'company' ? $companyRegistrationNumber : null;
            $user->phone_number = $phoneNumber;
            $user->email = $email;
            $user->id_number = $customerType === 'company' ? ($idNumber !== '' ? $idNumber : null) : $idNumber;
            $user->tax_pin = $taxPin !== '' ? $taxPin : null;
            $user->address = $address;
            $user->meter_number = $accountNumber;
            $user->connection_type = $connectionType;
            $user->unit_rate = $unitRate;
            $user->location_label = $locationLabel !== '' ? $locationLabel : null;
            $user->latitude = $latitude !== '' ? (float)$latitude : null;
            $user->longitude = $longitude !== '' ? (float)$longitude : null;
            $user->password = bin2hex(random_bytes(8));
            $user->role = 'customer';
            $user->status = 'inactive';
            if (!$user->create()) {
                mobileApiJson(500, 'error', 'Failed to create the dormant client account for this proforma.');
            }
            $db->prepare('UPDATE users SET must_change_password = 1 WHERE id = :id')->execute([':id' => (int)$user->id]);
            $billId = $billService->createRegistrationFeeBill((int)$user->id, $accountNumber, $registrationFee, date('Y-m-d', strtotime('+14 days')), 'pending');
            if (!$billId) {
                mobileApiJson(500, 'error', 'Failed to generate the registration fee bill for this proforma.');
            }
            $stmtInsert = $db->prepare('INSERT INTO registration_proformas (user_id, bill_id, created_by_user_id, notes) VALUES (:user_id, :bill_id, :created_by_user_id, :notes)');
            $stmtInsert->execute([':user_id' => (int)$user->id, ':bill_id' => (int)$billId, ':created_by_user_id' => (int)$actor['id'], ':notes' => $notes !== '' ? $notes : null]);
            $shareUrl = PaymentLink::generateRegistrationProformaLink((int)$billId);
            $smsMessage = "Dear {$fullName}, your registration proforma is ready. Download and pay here: {$shareUrl}";
            try {
                (new SMS($db))->sendWithFallback($phoneNumber, $smsMessage, 'registration_proforma');
            } catch (Throwable $e) {
            }
            try {
                (new Email())->queue($email, 'Your registration proforma', $smsMessage, 'registration_proforma');
            } catch (Throwable $e) {
            }
            mobileApiJson(201, 'success', 'Registration proforma created successfully.', ['account_number' => $accountNumber, 'bill_id' => $billId, 'share_url' => $shareUrl]);
        }

        if ($formType === 'send_stk') {
            $proformaId = (int)($data['proforma_id'] ?? 0);
            if ($proformaId <= 0) {
                mobileApiJson(422, 'error', 'Invalid proforma selection.');
            }
            $stmtProforma = $db->prepare('SELECT rp.*, u.account_number, u.full_name, u.phone_number, u.status AS user_status FROM registration_proformas rp INNER JOIN users u ON u.id = rp.user_id WHERE rp.id = :id LIMIT 1');
            $stmtProforma->execute([':id' => $proformaId]);
            $proformaRow = $stmtProforma->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$proformaRow) {
                mobileApiJson(404, 'error', 'Registration proforma not found.');
            }
            $billId = (int)($proformaRow['bill_id'] ?? 0);
            $billRow = $billService->getById($billId);
            if (!$billRow || !$billService->isRegistrationFeeBill($billRow)) {
                mobileApiJson(422, 'error', 'Registration bill not found for this proforma.');
            }
            $amountToCharge = $paymentService->getBillOutstandingAmount($billId);
            if ($amountToCharge <= 0.01) {
                mobileApiJson(422, 'error', 'This registration proforma is already fully settled.');
            }
            if (!preg_match('/^(?:254|\+254|0)?((?:7|1)\d{8})$/', (string)($proformaRow['phone_number'] ?? ''), $matches)) {
                mobileApiJson(422, 'error', 'A valid Kenyan M-Pesa phone number is required.');
            }
            $formattedPhone = '254' . $matches[1];
            $response = (new Mpesa())->stkPush($formattedPhone, $amountToCharge, (string)$proformaRow['account_number'], 'Registration Fee');
            if (isset($response['error'])) {
                mobileApiJson(422, 'error', 'Payment initiation failed: ' . (string)$response['error']);
            }
            $payment = new Payment($db);
            $payment->bill_id = $billId;
            $payment->user_id = (int)$proformaRow['user_id'];
            $payment->phone_number = $formattedPhone;
            $payment->amount = $amountToCharge;
            $payment->merchant_request_id = $response['MerchantRequestID'] ?? null;
            $payment->checkout_request_id = $response['CheckoutRequestID'] ?? null;
            $payment->status = 'pending';
            $payment->registration_id = (int)$proformaRow['user_id'];
            if (!$payment->create()) {
                mobileApiJson(500, 'error', 'Failed to save the pending registration payment request.');
            }
            mobileApiJson(200, 'success', 'Registration STK push sent successfully.', ['amount' => $amountToCharge, 'phone_number' => $formattedPhone]);
        }

        mobileApiJson(422, 'error', 'Unsupported registration proforma action.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin registration proformas failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process registration proformas right now.');
}