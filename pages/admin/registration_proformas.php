<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/Payment.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';
require_once __DIR__ . '/../../includes/Mpesa.php';
require_once __DIR__ . '/../../includes/CountryDialCode.php';
require_once __DIR__ . '/../../includes/PaymentLink.php';
require_once __DIR__ . '/../../includes/SMS.php';
require_once __DIR__ . '/../../includes/Email.php';

function ensureRegistrationProformasTable(PDO $db): void
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

	$columns = [
		'account_setup_token' => "ALTER TABLE registration_proformas ADD COLUMN account_setup_token VARCHAR(96) NULL AFTER notes",
		'account_setup_expires_at' => "ALTER TABLE registration_proformas ADD COLUMN account_setup_expires_at DATETIME NULL AFTER account_setup_token",
		'account_setup_completed_at' => "ALTER TABLE registration_proformas ADD COLUMN account_setup_completed_at DATETIME NULL AFTER account_setup_expires_at",
		'account_setup_sent_at' => "ALTER TABLE registration_proformas ADD COLUMN account_setup_sent_at DATETIME NULL AFTER account_setup_completed_at",
	];

	foreach ($columns as $columnName => $sql) {
		$check = $db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'registration_proformas' AND column_name = :column_name");
		$check->execute([':column_name' => $columnName]);
		if ((int)$check->fetchColumn() === 0) {
			$db->exec($sql);
		}
	}
}

function nextRegistrationAccountNumber(PDO $db, string $prefix): string
{
	$stmt = $db->prepare("SELECT account_number FROM users WHERE account_number LIKE :prefix ORDER BY id DESC LIMIT 1");
	$like = $prefix . '%';
	$stmt->execute([':prefix' => $like]);
	$last = $stmt->fetch(PDO::FETCH_ASSOC);

	$next = 1;
	if ($last && !empty($last['account_number']) && preg_match('/^' . preg_quote($prefix, '/') . '(\\d+)$/', (string)$last['account_number'], $matches)) {
		$next = (int)$matches[1] + 1;
	}

	return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function normalizeProformaPhone(string $countryCode, string $localNumber): string
{
	$code = preg_replace('/\D+/', '', $countryCode);
	$local = preg_replace('/\D+/', '', $localNumber);
	if ($code === '' || $local === '') {
		return '';
	}
	if (strpos($local, $code) === 0 && strlen($local) > strlen($code)) {
		$local = substr($local, strlen($code));
	}
	$local = ltrim($local, '0');
	if ($local === '') {
		return '';
	}

	return $code . $local;
}

function splitPhoneForProforma(string $rawPhone, array $countryOptions): array
{
	$phone = preg_replace('/\D+/', '', $rawPhone);
	if ($phone === '') {
		return ['country_code' => '254', 'local_number' => ''];
	}

	$codes = [];
	foreach ($countryOptions as $option) {
		$code = preg_replace('/\D+/', '', (string)($option['value'] ?? ''));
		if ($code !== '') {
			$codes[$code] = $code;
		}
	}
	$codes = array_values($codes);
	usort($codes, static function ($a, $b) {
		return strlen($b) <=> strlen($a);
	});

	foreach ($codes as $code) {
		if (strpos($phone, $code) === 0 && strlen($phone) > strlen($code)) {
			return [
				'country_code' => $code,
				'local_number' => substr($phone, strlen($code)),
			];
		}
	}

	return ['country_code' => '254', 'local_number' => $phone];
}

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if (!$auth->isLoggedIn() || (!$auth->isAdmin() && !$auth->hasPermission('manage_registration_proformas'))) {
	header('Location: /login');
	exit;
}

ensureRegistrationProformasTable($db);

$userService = new User($db);
$billService = new Bill($db);
$paymentService = new Payment($db);
$settingsService = new BillingSettings($db);
$settings = $settingsService->getSettings();
$registrationFee = isset($settings['registration_fee']) ? (float)$settings['registration_fee'] : 0.0;
$currency = !empty($settings['currency_code']) ? (string)$settings['currency_code'] : 'KES';
$isAdminUser = $auth->isAdmin();
$canViewCustomers = $isAdminUser || $auth->hasPermission('view_customers');

$countryCodeOptions = [
	['value' => '254', 'label' => 'Kenya (+254)'],
	['value' => '256', 'label' => 'Uganda (+256)'],
	['value' => '255', 'label' => 'Tanzania (+255)'],
	['value' => '1', 'label' => 'United States (+1)'],
	['value' => '44', 'label' => 'United Kingdom (+44)'],
];

try {
	$countryDialCodeService = new CountryDialCode($db);
	$dbCountryCodeOptions = $countryDialCodeService->listActive();
	if (!empty($dbCountryCodeOptions)) {
		$countryCodeOptions = $dbCountryCodeOptions;
	}
} catch (Throwable $e) {
	// Keep fallback options when country code storage is unavailable.
}

$successMessage = '';
$errorMessage = '';
if (isset($_SESSION['registration_proforma_flash'])) {
	$flash = $_SESSION['registration_proforma_flash'];
	if (($flash['type'] ?? 'success') === 'error') {
		$errorMessage = (string)($flash['message'] ?? '');
	} else {
		$successMessage = (string)($flash['message'] ?? '');
	}
	unset($_SESSION['registration_proforma_flash']);
}

$createdShareUrl = '';
if (isset($_SESSION['registration_proforma_link'])) {
	$createdShareUrl = (string)$_SESSION['registration_proforma_link'];
	unset($_SESSION['registration_proforma_link']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!hash_equals($_SESSION['app_csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
		http_response_code(403);
		die('Invalid CSRF token.');
	}

	$formType = trim((string)($_POST['form_type'] ?? ''));

	try {
		if ($formType === 'create_proforma') {
			if ($registrationFee <= 0) {
				throw new Exception('Registration fee is not configured. Use the normal customer creation flow until the fee is set.');
			}

			$firstName = trim((string)($_POST['first_name'] ?? ''));
			$middleName = trim((string)($_POST['middle_name'] ?? ''));
			$lastName = trim((string)($_POST['last_name'] ?? ''));
			$customerType = strtolower(trim((string)($_POST['customer_type'] ?? 'individual')));
			if (!in_array($customerType, ['individual', 'company'], true)) {
				$customerType = 'individual';
			}
			$contactPersonName = trim(preg_replace('/\s+/', ' ', $firstName . ' ' . $middleName . ' ' . $lastName));
			$companyName = trim((string)($_POST['company_name'] ?? ''));
			$companyRegistrationNumber = trim((string)($_POST['company_registration_number'] ?? ''));
			$fullName = $customerType === 'company'
				? $companyName
				: trim(preg_replace('/\s+/', ' ', $firstName . ' ' . $middleName . ' ' . $lastName));
			$phoneNumber = normalizeProformaPhone((string)($_POST['phone_country_code'] ?? ''), (string)($_POST['phone_number_local'] ?? ''));
			$email = trim((string)($_POST['email'] ?? ''));
			$idNumber = trim((string)($_POST['id_number'] ?? ''));
			$address = trim((string)($_POST['address'] ?? ''));
			$taxPin = trim((string)($_POST['tax_pin'] ?? ''));
			$locationLabel = trim((string)($_POST['location_label'] ?? ''));
			$latitude = trim((string)($_POST['latitude'] ?? ''));
			$longitude = trim((string)($_POST['longitude'] ?? ''));
			$connectionType = strtolower(trim((string)($_POST['connection_type'] ?? 'domestic')));
			$unitRateInput = trim((string)($_POST['unit_rate'] ?? ''));
			$unitRate = $unitRateInput !== '' ? (float)$unitRateInput : null;
			$notes = trim((string)($_POST['notes'] ?? ''));

			if ($firstName === '' || $lastName === '' || $phoneNumber === '' || $email === '' || $address === '') {
				throw new Exception('Please fill in all required customer details.');
			}
			if ($customerType === 'company') {
				if ($companyName === '' || $companyRegistrationNumber === '') {
					throw new Exception('Company name and company registration number are required for company proformas.');
				}
			} elseif ($idNumber === '') {
				throw new Exception('ID number is required for individual customer proformas.');
			}
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				throw new Exception('Enter a valid email address.');
			}
			if (!in_array($connectionType, ['domestic', 'commercial', 'industrial'], true)) {
				throw new Exception('Choose a valid connection type.');
			}
			if ($unitRate !== null && $unitRate < 0) {
				throw new Exception('Client unit rate cannot be negative.');
			}
			if ($userService->phoneExists($phoneNumber)) {
				throw new Exception('Phone number already exists in the system.');
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
				throw new Exception($customerType === 'company'
					? 'A user with the same email address or company registration number already exists.'
					: 'A user with the same email address or ID number already exists.');
			}

			$user = new User($db);
			$accountNumber = nextRegistrationAccountNumber($db, 'MTR');
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
				throw new Exception('Failed to create the dormant client account for this proforma.');
			}

			$db->prepare('UPDATE users SET must_change_password = 1 WHERE id = :id')->execute([':id' => (int)$user->id]);

			$dueDate = date('Y-m-d', strtotime('+14 days'));
			$billId = $billService->createRegistrationFeeBill((int)$user->id, $accountNumber, $registrationFee, $dueDate, 'pending');
			if (!$billId) {
				throw new Exception('Failed to generate the registration fee bill for this proforma.');
			}

			$stmtInsert = $db->prepare('INSERT INTO registration_proformas (user_id, bill_id, created_by_user_id, notes) VALUES (:user_id, :bill_id, :created_by_user_id, :notes)');
			$stmtInsert->execute([
				':user_id' => (int)$user->id,
				':bill_id' => (int)$billId,
				':created_by_user_id' => (int)($_SESSION['user_id'] ?? 0) ?: null,
				':notes' => $notes !== '' ? $notes : null,
			]);

			$shareUrl = PaymentLink::generateRegistrationProformaLink((int)$billId);
			$smsMessage = "Dear {$fullName}, your registration proforma is ready. Download and pay here: {$shareUrl}";
			try {
				$sms = new SMS($db);
				$sms->sendWithFallback($phoneNumber, $smsMessage, 'registration_proforma');
			} catch (Throwable $e) {
				// Do not block proforma creation when outbound messaging fails.
			}
			try {
				$emailService = new Email();
				$emailService->queue($email, 'Your registration proforma', $smsMessage, 'registration_proforma');
			} catch (Throwable $e) {
				// Best-effort only.
			}

			$_SESSION['registration_proforma_flash'] = [
				'type' => 'success',
				'message' => 'Registration proforma created for ' . $fullName . '. Account ' . $accountNumber . ' is inactive until the fee is fully paid.',
			];
			$_SESSION['registration_proforma_link'] = $shareUrl;
			header('Location: /admin/registration-proformas');
			exit;
		}

		if ($formType === 'send_stk') {
			$proformaId = (int)($_POST['proforma_id'] ?? 0);
			if ($proformaId <= 0) {
				throw new Exception('Invalid proforma selection.');
			}

			$stmtProforma = $db->prepare('SELECT rp.*, u.account_number, u.full_name, u.phone_number, u.status AS user_status FROM registration_proformas rp INNER JOIN users u ON u.id = rp.user_id WHERE rp.id = :id LIMIT 1');
			$stmtProforma->execute([':id' => $proformaId]);
			$proformaRow = $stmtProforma->fetch(PDO::FETCH_ASSOC) ?: null;
			if (!$proformaRow) {
				throw new Exception('Registration proforma not found.');
			}

			$billId = (int)($proformaRow['bill_id'] ?? 0);
			$billRow = $billService->getById($billId);
			if (!$billRow || !$billService->isRegistrationFeeBill($billRow)) {
				throw new Exception('Registration bill not found for this proforma.');
			}

			$amountToCharge = $paymentService->getBillOutstandingAmount($billId);
			if ($amountToCharge <= 0.01) {
				throw new Exception('This registration proforma is already fully settled.');
			}

			if (!preg_match('/^(?:254|\+254|0)?((?:7|1)\d{8})$/', (string)($proformaRow['phone_number'] ?? ''), $matches)) {
				throw new Exception('A valid Kenyan M-Pesa phone number is required to send an STK push.');
			}
			$formattedPhone = '254' . $matches[1];

			$mpesa = new Mpesa();
			$response = $mpesa->stkPush($formattedPhone, $amountToCharge, (string)$proformaRow['account_number'], 'Registration Fee');
			if (isset($response['error'])) {
				$details = '';
				if (isset($response['http_code'])) {
					$details .= ' (HTTP ' . $response['http_code'] . ')';
				}
				if (isset($response['details']) && is_array($response['details'])) {
					if (!empty($response['details']['errorMessage'])) {
						$details .= ': ' . $response['details']['errorMessage'];
					} elseif (!empty($response['details']['errorCode'])) {
						$details .= ' (Code ' . $response['details']['errorCode'] . ')';
					}
				}
				throw new Exception('Payment initiation failed: ' . $response['error'] . $details);
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
				throw new Exception('Failed to save the pending registration payment request.');
			}

			$_SESSION['registration_proforma_flash'] = [
				'type' => 'success',
				'message' => 'M-Pesa STK push sent to ' . $proformaRow['full_name'] . ' for ' . $currency . ' ' . number_format($amountToCharge, 2) . '.',
			];
			header('Location: /admin/registration-proformas');
			exit;
		}
	} catch (Exception $e) {
		$errorMessage = $e->getMessage();
	}
}

$proformas = [];
try {
	$stmtList = $db->query("SELECT rp.id, rp.user_id, rp.bill_id, rp.created_by_user_id, rp.notes, rp.created_at,
		u.account_number, u.full_name, u.phone_number, u.email, u.status AS user_status, u.connection_type,
		b.amount AS bill_amount, b.status AS bill_status, b.due_date
		FROM registration_proformas rp
		INNER JOIN users u ON u.id = rp.user_id
		INNER JOIN bills b ON b.id = rp.bill_id
		ORDER BY rp.created_at DESC, rp.id DESC");
	$proformas = $stmtList ? ($stmtList->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
	$errorMessage = $errorMessage !== '' ? $errorMessage : 'Unable to load registration proformas.';
}

$page_title = 'Registration Proformas';
$is_admin_page = true;
require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container mt-4">
	<div class="row">
		<div class="col-12">
			<div class="pb-banner pb-banner--teal mb-4">
				<div class="pb-bg" aria-hidden="true">
					<div class="pb-grid"></div>
					<div class="pb-blob pb-blob--a"></div>
					<div class="pb-blob pb-blob--b"></div>
					<i class="bi bi-file-earmark-medical-fill pb-watermark"></i>
				</div>
				<div class="pb-inner">
					<div class="pb-left">
						<div class="pb-eyebrow-row">
							<span class="pb-eyebrow-chip"><i class="bi bi-file-earmark-medical"></i> Controlled Registration</span>
						</div>
						<h2 class="pb-title">Registration Proformas</h2>
						<p class="pb-subtitle">Create an inactive client record, issue a registration-fee proforma, and activate the account only after payment clears.</p>
					</div>
					<div class="pb-right">
						<div class="mb-2">
							<a class="btn btn-light btn-sm" href="/admin/onboarding-tracker"><i class="bi bi-diagram-3 me-1"></i> Open Tracker</a>
						</div>
						<div class="text-end small text-white-50">Configured registration fee</div>
						<div class="fs-4 fw-semibold"><?php echo htmlspecialchars($currency); ?> <?php echo number_format($registrationFee, 2); ?></div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<?php if ($successMessage !== ''): ?>
		<div class="alert alert-success alert-dismissible fade show" role="alert">
			<i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($successMessage); ?>
			<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
		</div>
	<?php endif; ?>
	<?php if ($errorMessage !== ''): ?>
		<div class="alert alert-danger alert-dismissible fade show" role="alert">
			<i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($errorMessage); ?>
			<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
		</div>
	<?php endif; ?>
	<?php if ($createdShareUrl !== ''): ?>
		<div class="alert alert-info">
			<div class="fw-semibold mb-2">Shareable client URL</div>
			<div class="input-group">
				<input type="text" class="form-control" readonly value="<?php echo htmlspecialchars($createdShareUrl); ?>">
				<a class="btn btn-outline-primary" href="<?php echo htmlspecialchars($createdShareUrl); ?>" target="_blank" rel="noopener">Open</a>
			</div>
			<div class="form-text">The system also attempted to send this link to the client by SMS and email.</div>
		</div>
	<?php endif; ?>

	<div class="row g-4">
		<div class="col-12">
			<div class="card">
				<div class="card-header bg-primary text-white">
					<h5 class="mb-0"><i class="bi bi-plus-square me-2"></i>Create Registration Proforma</h5>
				</div>
				<div class="card-body">
					<?php if ($registrationFee <= 0): ?>
						<div class="alert alert-warning mb-0">
							Registration fee is not configured. Set it first in Settings before using this workflow.
						</div>
					<?php else: ?>
						<form method="POST" novalidate>
							<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['app_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
							<input type="hidden" name="form_type" value="create_proforma">
							<div class="row">
								<div class="col-md-6 mb-3">
									<label class="form-label" for="customer_type">Client Type *</label>
									<select class="form-select" id="customer_type" name="customer_type">
										<?php $selectedCustomerType = (string)($_POST['customer_type'] ?? 'individual'); ?>
										<option value="individual" <?php echo $selectedCustomerType === 'individual' ? 'selected' : ''; ?>>Individual / Personal</option>
										<option value="company" <?php echo $selectedCustomerType === 'company' ? 'selected' : ''; ?>>Company / Organization</option>
									</select>
									<div class="form-text">Choose company when the proforma belongs to a business or organization.</div>
								</div>
								<div class="col-md-6 mb-3 company-proforma-field <?php echo $selectedCustomerType === 'company' ? '' : 'd-none'; ?>">
									<label class="form-label" for="company_name">Company Name *</label>
									<input type="text" class="form-control" id="company_name" name="company_name" value="<?php echo htmlspecialchars($_POST['company_name'] ?? ''); ?>">
								</div>
								<div class="col-md-6 mb-3 company-proforma-field <?php echo $selectedCustomerType === 'company' ? '' : 'd-none'; ?>">
									<label class="form-label" for="company_registration_number">Company Registration Number *</label>
									<input type="text" class="form-control" id="company_registration_number" name="company_registration_number" value="<?php echo htmlspecialchars($_POST['company_registration_number'] ?? ''); ?>">
								</div>
							</div>
							<div class="row">
								<div class="col-md-6 mb-3">
									<label class="form-label" for="first_name" id="first_name_label">First Name *</label>
									<input type="text" class="form-control" id="first_name" name="first_name" required value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
								</div>
								<div class="col-md-6 mb-3">
									<label class="form-label" for="last_name" id="last_name_label">Last Name *</label>
									<input type="text" class="form-control" id="last_name" name="last_name" required value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
								</div>
							</div>
							<div class="mb-3">
								<label class="form-label" for="middle_name" id="middle_name_label">Middle Name</label>
								<input type="text" class="form-control" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>">
							</div>
							<div class="mb-3">
								<label class="form-label" for="phone_number_local">Phone Number *</label>
								<div class="input-group">
									<span class="input-group-text">+</span>
									<select class="form-select" id="phone_country_code" name="phone_country_code" style="max-width: 190px;">
										<?php foreach ($countryCodeOptions as $option): ?>
											<?php $code = (string)($option['value'] ?? ''); ?>
											<?php $selectedCode = (string)($_POST['phone_country_code'] ?? '254'); ?>
											<option value="<?php echo htmlspecialchars($code); ?>" <?php echo $selectedCode === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)($option['label'] ?? $code)); ?></option>
										<?php endforeach; ?>
									</select>
									<input type="tel" class="form-control" id="phone_number_local" name="phone_number_local" placeholder="e.g. 712345678" required value="<?php echo htmlspecialchars($_POST['phone_number_local'] ?? ''); ?>">
								</div>
							</div>
							<div class="row">
								<div class="col-md-6 mb-3">
									<label class="form-label" for="email">Email *</label>
									<input type="email" class="form-control" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
								</div>
								<div class="col-md-6 mb-3">
									<label class="form-label" for="id_number" id="id_number_label">ID Number *</label>
									<input type="text" class="form-control" id="id_number" name="id_number" <?php echo $selectedCustomerType === 'company' ? '' : 'required'; ?> value="<?php echo htmlspecialchars($_POST['id_number'] ?? ''); ?>">
								</div>
							</div>
							<div class="mb-3">
								<label class="form-label" for="address">Physical Address *</label>
								<textarea class="form-control" id="address" name="address" rows="2" required><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
							</div>
							<div class="row">
								<div class="col-md-6 mb-3">
									<label class="form-label" for="connection_type">Connection Type *</label>
									<select class="form-select" id="connection_type" name="connection_type">
										<?php $selectedType = (string)($_POST['connection_type'] ?? 'domestic'); ?>
										<option value="domestic" <?php echo $selectedType === 'domestic' ? 'selected' : ''; ?>>Domestic</option>
										<option value="commercial" <?php echo $selectedType === 'commercial' ? 'selected' : ''; ?>>Commercial</option>
										<option value="industrial" <?php echo $selectedType === 'industrial' ? 'selected' : ''; ?>>Industrial</option>
									</select>
								</div>
								<div class="col-md-6 mb-3">
									<label class="form-label" for="unit_rate">Client Unit Rate</label>
									<input type="number" class="form-control" id="unit_rate" name="unit_rate" min="0" step="0.0001" value="<?php echo htmlspecialchars($_POST['unit_rate'] ?? ''); ?>">
									<div class="form-text">Leave blank to use the standard tariff.</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-6 mb-3">
									<label class="form-label" for="tax_pin">KRA PIN</label>
									<input type="text" class="form-control" id="tax_pin" name="tax_pin" value="<?php echo htmlspecialchars($_POST['tax_pin'] ?? ''); ?>">
								</div>
								<div class="col-md-6 mb-3">
									<label class="form-label" for="location_label">Location / Landmark</label>
									<input type="text" class="form-control location-autocomplete" id="location_label" name="location_label" value="<?php echo htmlspecialchars($_POST['location_label'] ?? ''); ?>" placeholder="e.g. P5PP+CJ, Nguluni" autocomplete="off">
								</div>
							</div>
							<div class="row">
								<div class="col-12 mb-2">
									<button type="button" class="btn btn-outline-primary btn-sm" id="adminProformaDetectLocationBtn">
										<i class="bi bi-geo-alt"></i> Use my current GPS location
									</button>
									<div class="form-text">Optional. Use this when you are on site and want to capture the exact point for the client.</div>
									<input type="hidden" id="latitude" name="latitude" value="<?php echo htmlspecialchars($_POST['latitude'] ?? ''); ?>">
									<input type="hidden" id="longitude" name="longitude" value="<?php echo htmlspecialchars($_POST['longitude'] ?? ''); ?>">
									<input type="hidden" id="gps_accuracy" name="gps_accuracy" value="<?php echo htmlspecialchars($_POST['gps_accuracy'] ?? ''); ?>">
									<div id="gps_accuracy_feedback" class="form-text <?php echo !empty($_POST['gps_accuracy']) ? '' : 'd-none'; ?>">
										<?php if (!empty($_POST['gps_accuracy'])): ?>GPS accuracy: ~<?php echo (int)round((float)$_POST['gps_accuracy']); ?>m<?php endif; ?>
									</div>
								</div>
								<div class="col-12 mb-2">
									<input type="text" class="form-control form-control-sm" id="gps_dms_input" autocomplete="off" placeholder="e.g. 1°15'51.6&quot;S 37°11'15.2&quot;E">
									<div class="form-text">Advanced: paste DMS coordinates or click directly on the map.</div>
								</div>
								<div class="col-12 mb-3">
									<div id="registrationProformaLocationMap" style="height:260px;border-radius:0.5rem;overflow:hidden;border:1px solid #dee2e6;"></div>
									<div class="form-text">Click anywhere on the map to place the client pin precisely.</div>
								</div>
							</div>
							<div class="mb-3">
								<label class="form-label" for="notes">Internal Notes</label>
								<textarea class="form-control" id="notes" name="notes" rows="2"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
							</div>
							<button type="submit" class="btn btn-primary w-100"><i class="bi bi-file-earmark-plus me-1"></i> Create Proforma</button>
						</form>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<div class="col-12">
			<div class="card">
				<div class="card-header d-flex justify-content-between align-items-center">
					<h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Issued Proformas</h5>
					<span class="badge bg-secondary"><?php echo count($proformas); ?> records</span>
				</div>
				<div class="card-body p-0">
					<?php if (empty($proformas)): ?>
						<div class="p-4 text-muted">No registration proformas have been issued yet.</div>
					<?php else: ?>
						<div class="table-responsive">
							<table class="table table-sm align-middle mb-0">
								<thead class="table-light">
									<tr>
										<th>Client</th>
										<th>Account</th>
										<th>Fee</th>
										<th>Balance</th>
										<th>Status</th>
										<th>Actions</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($proformas as $row): ?>
										<?php $outstanding = $paymentService->getBillOutstandingAmount((int)$row['bill_id']); ?>
										<?php $isSettled = $outstanding <= 0.01; ?>
										<tr>
											<td>
												<div class="fw-semibold"><?php echo htmlspecialchars((string)$row['full_name']); ?></div>
												<div class="small text-muted"><?php echo htmlspecialchars((string)$row['phone_number']); ?></div>
											</td>
											<td>
												<div class="fw-semibold"><?php echo htmlspecialchars((string)$row['account_number']); ?></div>
												<div class="small text-muted">Due <?php echo htmlspecialchars(date('d M Y', strtotime((string)$row['due_date']))); ?></div>
											</td>
											<td><?php echo htmlspecialchars($currency); ?> <?php echo number_format((float)$row['bill_amount'], 2); ?></td>
											<td><?php echo htmlspecialchars($currency); ?> <?php echo number_format($outstanding, 2); ?></td>
											<td>
												<?php if ($isSettled && (string)$row['user_status'] === 'active'): ?>
													<span class="badge bg-success">Active</span>
												<?php elseif ($isSettled): ?>
													<span class="badge bg-info text-dark">Paid</span>
												<?php else: ?>
													<span class="badge bg-warning text-dark"><?php echo htmlspecialchars(ucfirst((string)$row['bill_status'])); ?></span>
												<?php endif; ?>
											</td>
											<td>
												<div class="d-flex gap-1 flex-wrap">
													<?php $shareUrl = PaymentLink::generateRegistrationProformaLink((int)$row['bill_id']); ?>
													<a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars($shareUrl); ?>" target="_blank" rel="noopener">Open</a>
													<a class="btn btn-sm btn-outline-secondary" href="/invoice?t=<?php echo urlencode(PaymentLink::generateToken((int)$row['bill_id'])); ?><?php echo $isSettled ? '' : '&amp;proforma=1'; ?>" target="_blank" rel="noopener"><?php echo $isSettled ? 'Invoice' : 'Proforma Invoice'; ?></a>
													<?php if (!$isSettled): ?>
														<form method="POST" class="d-inline">
															<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['app_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
															<input type="hidden" name="form_type" value="send_stk">
															<input type="hidden" name="proforma_id" value="<?php echo (int)$row['id']; ?>">
															<button type="submit" class="btn btn-sm btn-outline-success">Send STK</button>
														</form>
													<?php endif; ?>
													<a class="btn btn-sm btn-outline-secondary" href="/admin/payments?account=<?php echo urlencode((string)$row['account_number']); ?>">Payments</a>
													<?php if ($canViewCustomers): ?>
														<a class="btn btn-sm btn-outline-dark" href="/admin/users?edit_id=<?php echo (int)$row['user_id']; ?>">Customer</a>
													<?php endif; ?>
												</div>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</div>

<?php
$googleMapsApiKey = Database::env('GOOGLE_MAPS_API_KEY', '');

if ($googleMapsApiKey) {
	$custom_scripts = <<<JS
<script>
function initAdminRegistrationProformaMap() {
	var btn = document.getElementById('adminProformaDetectLocationBtn');
	var latInput = document.getElementById('latitude');
	var lngInput = document.getElementById('longitude');
	var locationInput = document.getElementById('location_label');
	var accuracyInput = document.getElementById('gps_accuracy');
	var accuracyFeedback = document.getElementById('gps_accuracy_feedback');
	var mapEl = document.getElementById('registrationProformaLocationMap');
	var dmsInput = document.getElementById('gps_dms_input');
	var customerTypeInput = document.getElementById('customer_type');
	var companyFields = document.querySelectorAll('.company-proforma-field');
	var companyNameInput = document.getElementById('company_name');
	var companyRegistrationNumberInput = document.getElementById('company_registration_number');
	var firstNameLabel = document.getElementById('first_name_label');
	var middleNameLabel = document.getElementById('middle_name_label');
	var lastNameLabel = document.getElementById('last_name_label');
	var idNumberLabel = document.getElementById('id_number_label');
	var idNumberInput = document.getElementById('id_number');

	if (!mapEl || typeof google === 'undefined' || !google.maps) {
		return;
	}

	var defaultLat = -1.292066;
	var defaultLng = 36.821945;
	var startLat = defaultLat;
	var startLng = defaultLng;
	var zoom = 13;

	if (latInput && lngInput && latInput.value && lngInput.value) {
		var parsedLat = parseFloat(latInput.value);
		var parsedLng = parseFloat(lngInput.value);
		if (!isNaN(parsedLat) && !isNaN(parsedLng)) {
			startLat = parsedLat;
			startLng = parsedLng;
			zoom = 16;
		}
	}

	var map = new google.maps.Map(mapEl, {
		center: { lat: startLat, lng: startLng },
		zoom: zoom,
		mapTypeId: google.maps.MapTypeId.ROADMAP,
		mapTypeControl: true,
		streetViewControl: false
	});

	var marker = null;

	function toggleCustomerTypeUI() {
		var isCompany = customerTypeInput && customerTypeInput.value === 'company';
		companyFields.forEach(function(field) {
			field.classList.toggle('d-none', !isCompany);
		});
		if (companyNameInput) companyNameInput.required = isCompany;
		if (companyRegistrationNumberInput) companyRegistrationNumberInput.required = isCompany;
		if (idNumberInput) idNumberInput.required = !isCompany;
		if (firstNameLabel) firstNameLabel.textContent = isCompany ? 'Contact Person First Name *' : 'First Name *';
		if (middleNameLabel) middleNameLabel.textContent = isCompany ? 'Contact Person Middle Name' : 'Middle Name';
		if (lastNameLabel) lastNameLabel.textContent = isCompany ? 'Contact Person Last Name *' : 'Last Name *';
		if (idNumberLabel) idNumberLabel.textContent = isCompany ? 'Contact Person ID Number (optional)' : 'ID Number *';
	}

	if (customerTypeInput) {
		customerTypeInput.addEventListener('change', toggleCustomerTypeUI);
		toggleCustomerTypeUI();
	}

	function updateInputsFromLatLng(lat, lng) {
		if (latInput) latInput.value = lat.toFixed(7);
		if (lngInput) lngInput.value = lng.toFixed(7);
	}

	function ensureMarker(lat, lng) {
		var pos = new google.maps.LatLng(lat, lng);
		if (!marker) {
			marker = new google.maps.Marker({
				position: pos,
				map: map,
				draggable: true
			});
			marker.addListener('dragend', function() {
				var ll = marker.getPosition();
				updateInputsFromLatLng(ll.lat(), ll.lng());
			});
		} else {
			marker.setPosition(pos);
		}
		updateInputsFromLatLng(lat, lng);
	}

	function parseDMSValue(input) {
		if (!input) return null;
		var str = input.trim();
		if (!str) return null;
		var latRegex = /(\d+)[°º]\s*(\d+)[']\s*([0-9.]+)["”]?\s*([NS])/i;
		var lonRegex = /(\d+)[°º]\s*(\d+)[']\s*([0-9.]+)["”]?\s*([EW])/i;
		var latMatch = str.match(latRegex);
		var lonMatch = str.match(lonRegex);
		if (!latMatch || !lonMatch) return null;
		function toDecimal(deg, min, sec, hemi) {
			var d = parseFloat(deg) + parseFloat(min) / 60 + parseFloat(sec) / 3600;
			return /[SW]/i.test(hemi) ? -d : d;
		}
		var lat = toDecimal(latMatch[1], latMatch[2], latMatch[3], latMatch[4]);
		var lng = toDecimal(lonMatch[1], lonMatch[2], lonMatch[3], lonMatch[4]);
		if (isNaN(lat) || isNaN(lng)) return null;
		return { lat: lat, lng: lng };
	}

	function geocodeAndZoomFromQuery(query, showToastOnFound) {
		if (!query) return;
		query = query.trim();
		if (query.length < 3) return;
		var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(query + ', Kenya');
		fetch(url, { headers: { 'Accept-Language': 'en' } })
			.then(function(resp) { return resp.json(); })
			.then(function(results) {
				if (!Array.isArray(results) || results.length === 0) return;
				var first = results[0];
				var lat = parseFloat(first.lat);
				var lng = parseFloat(first.lon);
				if (isNaN(lat) || isNaN(lng)) return;
				map.setCenter({ lat: lat, lng: lng });
				map.setZoom(16);
				ensureMarker(lat, lng);
				if (showToastOnFound && window.showToast) {
					showToast('Suggested location for "' + query + '". Adjust the pin if needed.', 'info');
				}
			})
			.catch(function() {});
	}

	if (latInput && lngInput && latInput.value && lngInput.value) {
		var currentLat = parseFloat(latInput.value);
		var currentLng = parseFloat(lngInput.value);
		if (!isNaN(currentLat) && !isNaN(currentLng)) {
			ensureMarker(currentLat, currentLng);
		}
	}

	map.addListener('click', function(e) {
		ensureMarker(e.latLng.lat(), e.latLng.lng());
	});

	function detectCurrentLocation() {
		if (!btn) return;
		if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost') {
			if (window.showToast) showToast('GPS detection requires HTTPS. Open this page using https:// and try again.', 'danger');
			return;
		}
		if (!navigator.geolocation) {
			if (window.showToast) showToast('Geolocation is not supported by this browser.', 'danger');
			return;
		}
		btn.disabled = true;
		btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Detecting...';
		var targetAccuracy = 15;
		var bestPosition = null;
		var finished = false;
		var watchId = null;
		var finishTimer = null;

		function resetButton() {
			btn.disabled = false;
			btn.innerHTML = '<i class="bi bi-geo-alt"></i> Use my current GPS location';
		}

		function updateAccuracyFeedback(accuracy) {
			if (!accuracyFeedback) return;
			accuracyFeedback.classList.remove('d-none', 'text-success', 'text-warning');
			if (accuracy <= targetAccuracy) {
				accuracyFeedback.textContent = 'GPS accuracy: ~' + Math.round(accuracy) + 'm Good';
				accuracyFeedback.classList.add('text-success');
			} else {
				accuracyFeedback.textContent = 'GPS accuracy: ~' + Math.round(accuracy) + 'm. Waiting for 15m or better; move outside and hold still.';
				accuracyFeedback.classList.add('text-warning');
			}
		}

		function applyPosition(position, metTarget) {
			var lat = position.coords.latitude;
			var lng = position.coords.longitude;
			var accuracy = typeof position.coords.accuracy === 'number' ? position.coords.accuracy : 999999;
			updateInputsFromLatLng(lat, lng);
			if (accuracyInput) accuracyInput.value = accuracy;
			updateAccuracyFeedback(accuracy);
			map.setCenter({ lat: lat, lng: lng });
			map.setZoom(18);
			ensureMarker(lat, lng);
			if (locationInput && locationInput.value.trim() === '') {
				var url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng);
				fetch(url, { headers: { 'Accept-Language': 'en' } })
					.then(function(resp) { return resp.json(); })
					.then(function(data) {
						if (!data || !locationInput) return;
						var label = (data.address && (data.address.suburb || data.address.neighbourhood || data.address.village || data.address.town || data.address.city)) || data.display_name || '';
						if (label) locationInput.value = String(label).slice(0, 120);
					})
					.catch(function() {});
			}
			if (window.showToast) {
				if (metTarget) {
					showToast('GPS captured (accuracy ~' + Math.round(accuracy) + 'm).', 'success');
				} else {
					showToast('Best GPS fix reached ~' + Math.round(accuracy) + 'm. Retry for 15m or better, or drag the pin manually.', 'warning');
				}
			}
		}

		function finishWithPosition(position, metTarget) {
			if (finished) return;
			finished = true;
			if (watchId !== null) navigator.geolocation.clearWatch(watchId);
			if (finishTimer) clearTimeout(finishTimer);
			applyPosition(position, metTarget);
			resetButton();
		}

		function finishWithError(error) {
			if (finished) return;
			finished = true;
			if (watchId !== null) navigator.geolocation.clearWatch(watchId);
			if (finishTimer) clearTimeout(finishTimer);
			var message = 'Unable to get location. Please allow location access in your browser.';
			if (error && typeof error.code !== 'undefined') {
				if (error.code === 1) message = 'Location access was denied. Allow permission and try again.';
				else if (error.code === 2) message = 'Location is unavailable. Check GPS/network and try again.';
				else if (error.code === 3) message = 'Location request timed out. Please try again.';
			}
			if (window.showToast) showToast(message, 'danger');
			resetButton();
		}

		watchId = navigator.geolocation.watchPosition(function(position) {
			var accuracy = typeof position.coords.accuracy === 'number' ? position.coords.accuracy : 999999;
			if (!bestPosition) {
				bestPosition = position;
			} else {
				var bestAccuracy = typeof bestPosition.coords.accuracy === 'number' ? bestPosition.coords.accuracy : 999999;
				if (accuracy < bestAccuracy) {
					bestPosition = position;
				}
			}
			updateAccuracyFeedback(accuracy);
			if (accuracy <= targetAccuracy) {
				finishWithPosition(position, true);
			}
		}, function(error) {
			if (error && error.code === 1) {
				finishWithError(error);
			}
		}, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });

		finishTimer = setTimeout(function() {
			if (bestPosition) {
				finishWithPosition(bestPosition, false);
			} else {
				finishWithError({ code: 3 });
			}
		}, 15000);
	}

	if (btn) btn.addEventListener('click', detectCurrentLocation);
	if (dmsInput) {
		var applyDms = function() {
			var parsed = parseDMSValue(dmsInput.value || '');
			if (!parsed) {
				if (window.showToast && dmsInput.value.trim() !== '') showToast('Could not understand the coordinates. Use a format like 1°15\'51.6"S 37°11\'15.2"E.', 'danger');
				return;
			}
			map.setCenter({ lat: parsed.lat, lng: parsed.lng });
			map.setZoom(16);
			ensureMarker(parsed.lat, parsed.lng);
			if (window.showToast) showToast('GPS coordinates applied from DMS input.', 'success');
		};
		dmsInput.addEventListener('change', applyDms);
		dmsInput.addEventListener('blur', applyDms);
		dmsInput.addEventListener('keydown', function(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				applyDms();
			}
		});
	}
	if (locationInput) {
		var lastLocationQuery = '';
		locationInput.addEventListener('change', function() {
			var q = (locationInput.value || '').trim();
			if (q.length < 3 || q === lastLocationQuery) return;
			lastLocationQuery = q;
			geocodeAndZoomFromQuery(q, true);
		});
		locationInput.addEventListener('blur', function() {
			var q = (locationInput.value || '').trim();
			if (q.length < 3 || q === lastLocationQuery) return;
			lastLocationQuery = q;
			geocodeAndZoomFromQuery(q, true);
		});
	}
	if ((!latInput || !latInput.value || !lngInput || !lngInput.value) && locationInput && locationInput.value) {
		geocodeAndZoomFromQuery(locationInput.value, false);
	}
}
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=$googleMapsApiKey&callback=initAdminRegistrationProformaMap" async defer></script>
JS;
} else {
	$custom_scripts = <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function() {
	var btn = document.getElementById('adminProformaDetectLocationBtn');
	var latInput = document.getElementById('latitude');
	var lngInput = document.getElementById('longitude');
	var locationInput = document.getElementById('location_label');
	var accuracyInput = document.getElementById('gps_accuracy');
	var accuracyFeedback = document.getElementById('gps_accuracy_feedback');
	var mapEl = document.getElementById('registrationProformaLocationMap');
	var dmsInput = document.getElementById('gps_dms_input');
	var customerTypeInput = document.getElementById('customer_type');
	var companyFields = document.querySelectorAll('.company-proforma-field');
	var companyNameInput = document.getElementById('company_name');
	var companyRegistrationNumberInput = document.getElementById('company_registration_number');
	var firstNameLabel = document.getElementById('first_name_label');
	var middleNameLabel = document.getElementById('middle_name_label');
	var lastNameLabel = document.getElementById('last_name_label');
	var idNumberLabel = document.getElementById('id_number_label');
	var idNumberInput = document.getElementById('id_number');
	var map = null;
	var marker = null;

	function toggleCustomerTypeUI() {
		var isCompany = customerTypeInput && customerTypeInput.value === 'company';
		companyFields.forEach(function(field) {
			field.classList.toggle('d-none', !isCompany);
		});
		if (companyNameInput) companyNameInput.required = isCompany;
		if (companyRegistrationNumberInput) companyRegistrationNumberInput.required = isCompany;
		if (idNumberInput) idNumberInput.required = !isCompany;
		if (firstNameLabel) firstNameLabel.textContent = isCompany ? 'Contact Person First Name *' : 'First Name *';
		if (middleNameLabel) middleNameLabel.textContent = isCompany ? 'Contact Person Middle Name' : 'Middle Name';
		if (lastNameLabel) lastNameLabel.textContent = isCompany ? 'Contact Person Last Name *' : 'Last Name *';
		if (idNumberLabel) idNumberLabel.textContent = isCompany ? 'Contact Person ID Number (optional)' : 'ID Number *';
	}

	if (customerTypeInput) {
		customerTypeInput.addEventListener('change', toggleCustomerTypeUI);
		toggleCustomerTypeUI();
	}

	function updateInputsFromLatLng(lat, lng) {
		if (latInput) latInput.value = lat.toFixed(7);
		if (lngInput) lngInput.value = lng.toFixed(7);
	}

	function ensureMarker(lat, lng) {
		if (!map || typeof L === 'undefined') return;
		var pos = [lat, lng];
		if (!marker) {
			marker = L.marker(pos, { draggable: true }).addTo(map);
			marker.on('dragend', function(e) {
				var ll = e.target.getLatLng();
				updateInputsFromLatLng(ll.lat, ll.lng);
			});
		} else {
			marker.setLatLng(pos);
		}
		updateInputsFromLatLng(lat, lng);
	}

	function parseDMSValue(input) {
		if (!input) return null;
		var str = input.trim();
		if (!str) return null;
		var latRegex = /(\d+)[°º]\s*(\d+)[']\s*([0-9.]+)["”]?\s*([NS])/i;
		var lonRegex = /(\d+)[°º]\s*(\d+)[']\s*([0-9.]+)["”]?\s*([EW])/i;
		var latMatch = str.match(latRegex);
		var lonMatch = str.match(lonRegex);
		if (!latMatch || !lonMatch) return null;
		function toDecimal(deg, min, sec, hemi) {
			var d = parseFloat(deg) + parseFloat(min) / 60 + parseFloat(sec) / 3600;
			return /[SW]/i.test(hemi) ? -d : d;
		}
		var lat = toDecimal(latMatch[1], latMatch[2], latMatch[3], latMatch[4]);
		var lng = toDecimal(lonMatch[1], lonMatch[2], lonMatch[3], lonMatch[4]);
		if (isNaN(lat) || isNaN(lng)) return null;
		return { lat: lat, lng: lng };
	}

	function geocodeAndZoomFromQuery(query, showToastOnFound) {
		if (!map || !query) return;
		query = query.trim();
		if (query.length < 3) return;
		var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(query + ', Kenya');
		fetch(url, { headers: { 'Accept-Language': 'en' } })
			.then(function(resp) { return resp.json(); })
			.then(function(results) {
				if (!Array.isArray(results) || results.length === 0) return;
				var first = results[0];
				var lat = parseFloat(first.lat);
				var lng = parseFloat(first.lon);
				if (isNaN(lat) || isNaN(lng)) return;
				map.setView([lat, lng], 16);
				ensureMarker(lat, lng);
				if (showToastOnFound && window.showToast) {
					showToast('Suggested location for "' + query + '". Adjust the pin if needed.', 'info');
				}
			})
			.catch(function() {});
	}

	function initMap() {
		if (!mapEl || typeof L === 'undefined') return;
		var defaultLat = -1.292066;
		var defaultLng = 36.821945;
		var startLat = defaultLat;
		var startLng = defaultLng;
		var zoom = 13;
		if (latInput && lngInput && latInput.value && lngInput.value) {
			var parsedLat = parseFloat(latInput.value);
			var parsedLng = parseFloat(lngInput.value);
			if (!isNaN(parsedLat) && !isNaN(parsedLng)) {
				startLat = parsedLat;
				startLng = parsedLng;
				zoom = 16;
			}
		}
		map = L.map('registrationProformaLocationMap').setView([startLat, startLng], zoom);
		var streets = L.tileLayer('https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' });
		var satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{x}/{y}', { maxZoom: 19, attribution: 'Imagery &copy; Esri' });
		streets.addTo(map);
		L.control.layers({ 'Streets': streets, 'Satellite': satellite }, {}).addTo(map);
		if (latInput && lngInput && latInput.value && lngInput.value) {
			var currentLat = parseFloat(latInput.value);
			var currentLng = parseFloat(lngInput.value);
			if (!isNaN(currentLat) && !isNaN(currentLng)) ensureMarker(currentLat, currentLng);
		}
		map.on('click', function(e) { ensureMarker(e.latlng.lat, e.latlng.lng); });
		if ((!latInput || !latInput.value || !lngInput || !lngInput.value) && locationInput && locationInput.value) {
			geocodeAndZoomFromQuery(locationInput.value, false);
		}
	}

	function detectCurrentLocation() {
		if (!btn) return;
		if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost') {
			if (window.showToast) showToast('GPS detection requires HTTPS. Open this page using https:// and try again.', 'danger');
			return;
		}
		if (!navigator.geolocation) {
			if (window.showToast) showToast('Geolocation is not supported by this browser.', 'danger');
			return;
		}
		btn.disabled = true;
		btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Detecting...';
		var targetAccuracy = 15;
		var bestPosition = null;
		var finished = false;
		var watchId = null;
		var finishTimer = null;

		function resetButton() {
			btn.disabled = false;
			btn.innerHTML = '<i class="bi bi-geo-alt"></i> Use my current GPS location';
		}

		function updateAccuracyFeedback(accuracy) {
			if (!accuracyFeedback) return;
			accuracyFeedback.classList.remove('d-none', 'text-success', 'text-warning');
			if (accuracy <= targetAccuracy) {
				accuracyFeedback.textContent = 'GPS accuracy: ~' + Math.round(accuracy) + 'm Good';
				accuracyFeedback.classList.add('text-success');
			} else {
				accuracyFeedback.textContent = 'GPS accuracy: ~' + Math.round(accuracy) + 'm. Waiting for 15m or better; move outside and hold still.';
				accuracyFeedback.classList.add('text-warning');
			}
		}

		function applyPosition(position, metTarget) {
			var lat = position.coords.latitude;
			var lng = position.coords.longitude;
			var accuracy = typeof position.coords.accuracy === 'number' ? position.coords.accuracy : 999999;
			updateInputsFromLatLng(lat, lng);
			if (accuracyInput) accuracyInput.value = accuracy;
			updateAccuracyFeedback(accuracy);
			if (map) {
				map.setView([lat, lng], 18);
				ensureMarker(lat, lng);
			}
			if (locationInput && locationInput.value.trim() === '') {
				var url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng);
				fetch(url, { headers: { 'Accept-Language': 'en' } })
					.then(function(resp) { return resp.json(); })
					.then(function(data) {
						if (!data || !locationInput) return;
						var label = (data.address && (data.address.suburb || data.address.neighbourhood || data.address.village || data.address.town || data.address.city)) || data.display_name || '';
						if (label) locationInput.value = String(label).slice(0, 120);
					})
					.catch(function() {});
			}
			if (window.showToast) {
				if (metTarget) {
					showToast('GPS captured (accuracy ~' + Math.round(accuracy) + 'm).', 'success');
				} else {
					showToast('Best GPS fix reached ~' + Math.round(accuracy) + 'm. Retry for 15m or better, or drag the pin manually.', 'warning');
				}
			}
		}

		function finishWithPosition(position, metTarget) {
			if (finished) return;
			finished = true;
			if (watchId !== null) navigator.geolocation.clearWatch(watchId);
			if (finishTimer) clearTimeout(finishTimer);
			applyPosition(position, metTarget);
			resetButton();
		}

		function finishWithError(error) {
			if (finished) return;
			finished = true;
			if (watchId !== null) navigator.geolocation.clearWatch(watchId);
			if (finishTimer) clearTimeout(finishTimer);
			var message = 'Unable to get location. Please allow location access in your browser.';
			if (error && typeof error.code !== 'undefined') {
				if (error.code === 1) message = 'Location access was denied. Allow permission and try again.';
				else if (error.code === 2) message = 'Location is unavailable. Check GPS/network and try again.';
				else if (error.code === 3) message = 'Location request timed out. Please try again.';
			}
			if (window.showToast) showToast(message, 'danger');
			resetButton();
		}

		watchId = navigator.geolocation.watchPosition(function(position) {
			var accuracy = typeof position.coords.accuracy === 'number' ? position.coords.accuracy : 999999;
			if (!bestPosition) {
				bestPosition = position;
			} else {
				var bestAccuracy = typeof bestPosition.coords.accuracy === 'number' ? bestPosition.coords.accuracy : 999999;
				if (accuracy < bestAccuracy) {
					bestPosition = position;
				}
			}
			updateAccuracyFeedback(accuracy);
			if (accuracy <= targetAccuracy) {
				finishWithPosition(position, true);
			}
		}, function(error) {
			if (error && error.code === 1) {
				finishWithError(error);
			}
		}, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });

		finishTimer = setTimeout(function() {
			if (bestPosition) {
				finishWithPosition(bestPosition, false);
			} else {
				finishWithError({ code: 3 });
			}
		}, 15000);
	}

	if (btn) btn.addEventListener('click', detectCurrentLocation);
	if (dmsInput) {
		var applyDms = function() {
			var parsed = parseDMSValue(dmsInput.value || '');
			if (!parsed) {
				if (window.showToast && dmsInput.value.trim() !== '') showToast('Could not understand the coordinates. Use a format like 1°15\'51.6"S 37°11\'15.2"E.', 'danger');
				return;
			}
			if (map) map.setView([parsed.lat, parsed.lng], 16);
			ensureMarker(parsed.lat, parsed.lng);
			if (window.showToast) showToast('GPS coordinates applied from DMS input.', 'success');
		};
		dmsInput.addEventListener('change', applyDms);
		dmsInput.addEventListener('blur', applyDms);
		dmsInput.addEventListener('keydown', function(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				applyDms();
			}
		});
	}
	if (locationInput) {
		var lastLocationQuery = '';
		locationInput.addEventListener('change', function() {
			var q = (locationInput.value || '').trim();
			if (q.length < 3 || q === lastLocationQuery) return;
			lastLocationQuery = q;
			geocodeAndZoomFromQuery(q, true);
		});
		locationInput.addEventListener('blur', function() {
			var q = (locationInput.value || '').trim();
			if (q.length < 3 || q === lastLocationQuery) return;
			lastLocationQuery = q;
			geocodeAndZoomFromQuery(q, true);
		});
	}
	initMap();
});
</script>
JS;
}
?>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>