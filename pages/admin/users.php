<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/BillingSettings.php';
require_once __DIR__ . '/../../includes/Mpesa.php';
require_once __DIR__ . '/../../includes/Payment.php';
require_once __DIR__ . '/../../includes/Bill.php';
require_once __DIR__ . '/../../includes/SMS.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if(!$auth->isLoggedIn() || !$auth->isAdmin()) {
	header("Location: /login");
	exit;
}

// Load registration fee setting for display and logic
$settingsService = new BillingSettings($db);
$settings = $settingsService->getSettings();
$registrationFee = isset($settings['registration_fee']) ? (float)$settings['registration_fee'] : 0.00;

$successMessage = '';
$errorMessage = '';

// Load flash messages (for redirect-after-POST) if set
if (isset($_SESSION['flash_message'])) {
	if (!empty($_SESSION['flash_type']) && $_SESSION['flash_type'] === 'error') {
		$errorMessage = (string)$_SESSION['flash_message'];
	} else {
		$successMessage = (string)$_SESSION['flash_message'];
	}
	unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$formType = $_POST['form_type'] ?? 'create_user';

	if ($formType === 'update_status') {
		try {
			$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
			$newStatus = $_POST['new_status'] ?? '';
			$allowed = ['active', 'inactive', 'suspended'];
			if ($userId <= 0 || !in_array($newStatus, $allowed, true)) {
				throw new Exception('Invalid user or status.');
			}
			$stmt = $db->prepare('UPDATE users SET status = :status WHERE id = :id');
			$stmt->bindParam(':status', $newStatus);
			$stmt->bindParam(':id', $userId, PDO::PARAM_INT);
			if ($stmt->execute()) {
				$_SESSION['flash_message'] = 'User status updated.';
				$_SESSION['flash_type'] = 'success';
				header('Location: /admin/users?page=' . max(1, (int)$currentPage));
				exit;
			} else {
				throw new Exception('Failed to update user status.');
			}
		} catch (Exception $e) {
			$errorMessage = $e->getMessage();
		}
	} elseif ($formType === 'delete_user') {
		try {
			$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
			if ($userId <= 0) {
				throw new Exception('Invalid user.');
			}
			if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $userId) {
				throw new Exception('You cannot delete your own account.');
			}
			$stmt = $db->prepare('DELETE FROM users WHERE id = :id');
			$stmt->bindParam(':id', $userId, PDO::PARAM_INT);
			if ($stmt->execute()) {
				$_SESSION['flash_message'] = 'User deleted successfully.';
				$_SESSION['flash_type'] = 'success';
				header('Location: /admin/users');
				exit;
			} else {
				throw new Exception('Failed to delete user.');
			}
		} catch (Exception $e) {
			$errorMessage = $e->getMessage();
		}
	} elseif ($formType === 'edit_user_save') {
		try {
			$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
			if ($userId <= 0) {
				throw new Exception('Invalid user.');
			}

			$full_name = trim($_POST['full_name'] ?? '');
			$phone_number = trim($_POST['phone_number'] ?? '');
			$email = trim($_POST['email'] ?? '');
			$id_number = trim($_POST['id_number'] ?? '');
			$address = trim($_POST['address'] ?? '');
			$tax_pin = trim($_POST['tax_pin'] ?? '');
			$connection_type = trim($_POST['connection_type'] ?? 'domestic');
			$location_label = trim($_POST['location_label'] ?? '');
			$latitude = trim($_POST['latitude'] ?? '');
			$longitude = trim($_POST['longitude'] ?? '');
			$role = isset($_POST['role']) ? strtolower(trim((string)$_POST['role'])) : 'customer';
			$password = (string)($_POST['password'] ?? '');

			if ($full_name === '' || $phone_number === '' || $id_number === '' || $address === '') {
				throw new Exception('Please fill in all required fields.');
			}

			// Ensure phone is unique to this user
			$stmtCheck = $db->prepare('SELECT id FROM users WHERE phone_number = :phone AND id <> :id LIMIT 1');
			$stmtCheck->bindParam(':phone', $phone_number);
			$stmtCheck->bindParam(':id', $userId, PDO::PARAM_INT);
			$stmtCheck->execute();
			if ($stmtCheck->fetch(PDO::FETCH_ASSOC)) {
				throw new Exception('The phone number is already registered to another user.');
			}

			// Build update query
			// Validate role
			$allowedRoles = ['admin','reader','finance','support','customer'];
			if (!in_array($role, $allowedRoles, true)) {
				$role = 'customer';
			}

			$sql = 'UPDATE users SET full_name = :full_name, phone_number = :phone_number, email = :email, id_number = :id_number, address = :address, tax_pin = :tax_pin, connection_type = :connection_type, location_label = :location_label, latitude = :latitude, longitude = :longitude, role = :role';
			$updatePassword = ($password !== '');
			if ($updatePassword) {
				$sql .= ', password_hash = :password_hash';
			}
			$sql .= ' WHERE id = :id';

			$stmt = $db->prepare($sql);
			$stmt->bindParam(':full_name', $full_name);
			$stmt->bindParam(':phone_number', $phone_number);
			$stmt->bindParam(':email', $email);
			$stmt->bindParam(':id_number', $id_number);
			$stmt->bindParam(':address', $address);
			$taxPinValue = $tax_pin !== '' ? $tax_pin : null;
			$stmt->bindParam(':tax_pin', $taxPinValue);
			$stmt->bindParam(':connection_type', $connection_type);
			$locValue = $location_label !== '' ? $location_label : null;
			$latValue = $latitude !== '' ? (float)$latitude : null;
			$lngValue = $longitude !== '' ? (float)$longitude : null;
			$stmt->bindParam(':location_label', $locValue);
			$stmt->bindParam(':latitude', $latValue);
			$stmt->bindParam(':longitude', $lngValue);
			$stmt->bindParam(':role', $role);
			if ($updatePassword) {
				$password_hash = password_hash($password, PASSWORD_BCRYPT);
				$stmt->bindParam(':password_hash', $password_hash);
			}
			$stmt->bindParam(':id', $userId, PDO::PARAM_INT);

			if ($stmt->execute()) {
				$_SESSION['flash_message'] = 'User updated successfully.';
				$_SESSION['flash_type'] = 'success';
				header('Location: /admin/users?page=' . max(1, (int)$currentPage));
				exit;
			} else {
				throw new Exception('Failed to update user.');
			}
		} catch (Exception $e) {
			$errorMessage = $e->getMessage();
		}
		} else {
		// Default: create new user
		try {
			$full_name = trim($_POST['full_name'] ?? '');
			$phone_number = trim($_POST['phone_number'] ?? '');
			$email = trim($_POST['email'] ?? '');
			$id_number = trim($_POST['id_number'] ?? '');
			$address = trim($_POST['address'] ?? '');
			$tax_pin = trim($_POST['tax_pin'] ?? '');
			$connection_type = trim($_POST['connection_type'] ?? 'domestic');
				$location_label = trim($_POST['location_label'] ?? '');
				$latitude = trim($_POST['latitude'] ?? '');
				$longitude = trim($_POST['longitude'] ?? '');
			$password = (string)($_POST['password'] ?? '');
			$role = isset($_POST['role']) ? strtolower(trim((string)$_POST['role'])) : 'customer';
			$registration_already_paid = isset($_POST['registration_already_paid']);
			$send_stk = isset($_POST['send_stk']);
			$registration_mpesa_code = trim($_POST['registration_mpesa_code'] ?? '');

			// Basic validation
			if ($full_name === '' || $phone_number === '' || $email === '' || $id_number === '' || $address === '' || $password === '') {
				throw new Exception('Please fill in all required fields.');
			}

			$user = new User($db);
			if ($user->phoneExists($phone_number)) {
				throw new Exception('The phone number is already registered to another user.');
			}

			// Generate sequential account number like public registration
			$stmt = $db->query("SELECT account_number FROM users WHERE account_number LIKE 'MTR%' ORDER BY id DESC LIMIT 1");
			$last = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
			$nextNumber = 1;
			if ($last && !empty($last['account_number']) && preg_match('/^MTR(\d+)$/', $last['account_number'], $m)) {
				$nextNumber = (int)$m[1] + 1;
			}
			$account_number = 'MTR' . str_pad((string)$nextNumber, 4, '0', STR_PAD_LEFT);

			// Populate user model
			$user->account_number = $account_number;
			$user->full_name = $full_name;
			$user->phone_number = $phone_number;
			$user->email = $email;
			$user->id_number = $id_number;
			$user->address = $address;
			$user->tax_pin = $tax_pin !== '' ? $tax_pin : null;
			$user->meter_number = $account_number;
			$user->connection_type = $connection_type !== '' ? $connection_type : 'domestic';
			$user->location_label = $location_label !== '' ? $location_label : null;
			$user->latitude = $latitude !== '' ? (float)$latitude : null;
			$user->longitude = $longitude !== '' ? (float)$longitude : null;
			$user->password = $password;
			$allowedRoles = ['admin','reader','finance','support','customer'];
			if (!in_array($role, $allowedRoles, true)) {
				$role = 'customer';
			}
			$user->role = $role;

			$billService = new Bill($db);
			$paymentModel = new Payment($db);

			// Case 1: No registration fee configured OR admin marks as already paid
			if ($registrationFee <= 0 || $registration_already_paid) {
				$user->status = 'active';
				if (!$user->create()) {
					throw new Exception('Failed to create user account.');
				}

				// If a registration fee exists and is marked as already paid, record a paid registration bill + payment
				if ($registrationFee > 0 && $registration_already_paid) {
					$dueDate = date('Y-m-d');
					$billId = $billService->createRegistrationFeeBill($user->id, $user->account_number, $registrationFee, $dueDate, 'paid');
					if ($billId) {
						$payment = new Payment($db);
						$payment->bill_id = $billId;
						$payment->user_id = $user->id;
						$payment->phone_number = $phone_number;
						$payment->amount = $registrationFee;
						$payment->merchant_request_id = null;
						$payment->checkout_request_id = null;
						$payment->mpesa_receipt = $registration_mpesa_code !== '' ? $registration_mpesa_code : null;
						$payment->status = 'completed';
						$payment->registration_id = $user->id;
						$payment->create();
					}
				}

				// Send SMS with account details on successful creation
				$sms = new SMS();
				$companyName = !empty($settings['company_name']) ? $settings['company_name'] : 'BreMac Consultant Ltd';
				$loginUrl = 'https://wbs.bremac.co.ke/';
				$messageText = "Dear " . $user->full_name . ",\n" .
					"Your water account has been created successfully.\n" .
					"Account No: " . $user->account_number . "\n" .
					"Meter No: " . $user->meter_number . "\n" .
					"You can now log in at " . $loginUrl . " using your account number, phone or email to view your bills and make payments.\n" .
					$companyName;
				$sms->send($user->phone_number, $messageText);

				// Also send an email if available
				if (!empty($user->email)) {
					require_once __DIR__ . '/../../includes/Email.php';
					$email = new Email();
					$email->send(
						$user->email,
						'Your new water account details',
						$messageText
					);
				}

				$_SESSION['flash_message'] = 'User created successfully.';
				$_SESSION['flash_type'] = 'success';
				header('Location: /admin/users');
				exit;
			} else {
				// Case 2: Registration fee configured and not marked as already paid
				if (!$send_stk) {
					throw new Exception('Please either mark the registration fee as already paid or choose to send an M-Pesa STK push.');
				}

				// Validate and normalize phone for M-Pesa
				if (!preg_match('/^(?:254|\+254|0)?((?:7|1)\d{8})$/', $phone_number, $matches)) {
					throw new Exception('Invalid phone number format for M-Pesa payment.');
				}
				$formatted_phone = '254' . $matches[1];

				$mpesa = new Mpesa();
				$response = $mpesa->stkPush(
					$formatted_phone,
					$registrationFee,
					$account_number,
					'Registration Fee'
				);

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

				// Create user in inactive state awaiting registration fee
				$user->status = 'inactive';
				if (!$user->create()) {
					throw new Exception('Failed to create user account after initiating payment.');
				}

				// Create registration fee bill
				$dueDate = date('Y-m-d', strtotime('+14 days'));
				$billId = $billService->createRegistrationFeeBill($user->id, $user->account_number, $registrationFee, $dueDate, 'pending');

				// Save payment record tied to the registration fee bill
				$payment = new Payment($db);
				$payment->bill_id = $billId;
				$payment->user_id = $user->id;
				$payment->phone_number = $formatted_phone;
				$payment->amount = $registrationFee;
				$payment->merchant_request_id = $response['MerchantRequestID'] ?? null;
				$payment->checkout_request_id = $response['CheckoutRequestID'] ?? null;
				$payment->status = 'pending';
				$payment->registration_id = $user->id;
				$payment->create();

				$_SESSION['flash_message'] = 'User created and registration payment initiated via M-Pesa STK. The account will be activated automatically after payment is received.';
				$_SESSION['flash_type'] = 'success';
				header('Location: /admin/users');
				exit;
			}
		} catch (Exception $e) {
			$errorMessage = $e->getMessage();
		}
	}
}

// Pagination for existing users list
$usersList = [];
$totalUsers = 0;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) {
	$currentPage = 1;
}
$perPage = 10;

if ($db) {
	try {
		$stmtCount = $db->query('SELECT COUNT(*) AS total FROM users');
		$rowCount = $stmtCount ? $stmtCount->fetch(PDO::FETCH_ASSOC) : ['total' => 0];
		$totalUsers = (int)($rowCount['total'] ?? 0);
		$totalPages = max(1, (int)ceil($totalUsers / $perPage));
		if ($currentPage > $totalPages) {
			$currentPage = $totalPages;
		}
		$offset = ($currentPage - 1) * $perPage;

		$stmtUsers = $db->prepare('SELECT id, account_number, full_name, phone_number, meter_number, status, role FROM users ORDER BY full_name ASC LIMIT :limit OFFSET :offset');
		$stmtUsers->bindValue(':limit', $perPage, PDO::PARAM_INT);
		$stmtUsers->bindValue(':offset', $offset, PDO::PARAM_INT);
		$stmtUsers->execute();
		$usersList = $stmtUsers->fetchAll(PDO::FETCH_ASSOC) ?: [];
	} catch (Exception $e) {
		$usersList = [];
		$totalUsers = 0;
		$totalPages = 1;
	}
} else {
	$totalPages = 1;
}

$editUser = null;
$isEditMode = false;
if ($db && isset($_GET['edit_id'])) {
	$editId = (int)$_GET['edit_id'];
	if ($editId > 0) {
		try {
			$userRepo = new User($db);
			$editUser = $userRepo->getById($editId);
			$isEditMode = (bool)$editUser;
		} catch (Exception $e) {
			$editUser = null;
			$isEditMode = false;
		}
	}
}

$page_title = "Admin - Users";
require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container mt-4">
	<div class="row">
		<div class="col-md-12">
			<div class="admin-page-header d-flex justify-content-between align-items-center">
				<div>
					<h2 class="mb-1">Users</h2>
					<p class="text-muted mb-0">Create customer accounts directly from the admin panel.</p>
				</div>
				<div>
					<a href="/admin/customer-locations" class="btn btn-outline-primary btn-sm">
						<i class="bi bi-geo-alt"></i> View customer map
					</a>
				</div>
			</div>
		</div>
	</div>

	<div class="row mt-3">
		<div class="col-12">
			<div class="card">
				<div class="card-header d-flex justify-content-between align-items-center">
					<h5 class="mb-0">Existing Customers</h5>
					<small class="text-muted">Total: <?php echo (int)$totalUsers; ?></small>
				</div>
				<div class="card-body p-0">
					<?php if (empty($usersList)): ?>
						<p class="p-3 mb-0 text-muted">No customers found.</p>
					<?php else: ?>
						<div class="table-responsive">
							<table class="table table-striped mb-0">
								<thead>
									<tr>
										<th scope="col">Account</th>
										<th scope="col">Name</th>
										<th scope="col">Phone</th>
										<th scope="col">Meter</th>
										<th scope="col">Status</th>
										<th scope="col">Role</th>
										<th scope="col">Actions</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($usersList as $u): ?>
										<tr>
											<td><?php echo htmlspecialchars($u['account_number'] ?? ''); ?></td>
											<td><?php echo htmlspecialchars($u['full_name'] ?? ''); ?></td>
											<td><?php echo htmlspecialchars($u['phone_number'] ?? ''); ?></td>
											<td><?php echo htmlspecialchars($u['meter_number'] ?? ''); ?></td>
											<td>
												<span class="badge bg-<?php echo ($u['status'] === 'active') ? 'success' : (($u['status'] === 'inactive') ? 'secondary' : 'warning'); ?>">
													<?php echo htmlspecialchars(ucfirst($u['status'] ?? '')); ?>
												</span>
											</td>
											<td>
												<?php
													$roleLabel = $u['role'] ?? 'customer';
													switch (strtolower((string)$roleLabel)) {
														case 'admin':
															$badgeClass = 'danger';
															$roleText = 'Admin';
															break;
														case 'reader':
															$badgeClass = 'info';
															$roleText = 'Reader';
															break;
														case 'finance':
															$badgeClass = 'primary';
															$roleText = 'Finance';
															break;
														case 'support':
															$badgeClass = 'secondary';
															$roleText = 'Support';
															break;
														default:
															$badgeClass = 'light text-dark';
															$roleText = 'Customer';
													}
												?>
												<span class="badge bg-<?php echo $badgeClass; ?>"><?php echo htmlspecialchars($roleText); ?></span>
											</td>
											<td>
												<div class="d-flex flex-wrap gap-1">
													<a href="<?php echo htmlspecialchars('/admin/users?page=' . $currentPage . '&edit_id=' . (int)$u['id']); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
													<a href="<?php echo htmlspecialchars('/admin/customer-locations?user_id=' . (int)$u['id']); ?>" class="btn btn-sm btn-outline-info" title="View on map">
														<i class="bi bi-geo-alt"></i>
													</a>
													<?php if (($u['status'] ?? '') !== 'active'): ?>
														<form method="post" action="" class="d-inline">
															<input type="hidden" name="form_type" value="update_status">
															<input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
															<input type="hidden" name="new_status" value="active">
															<button type="submit" class="btn btn-sm btn-outline-success">Activate</button>
														</form>
													<?php endif; ?>
													<?php if (($u['status'] ?? '') !== 'inactive'): ?>
														<form method="post" action="" class="d-inline">
															<input type="hidden" name="form_type" value="update_status">
															<input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
															<input type="hidden" name="new_status" value="inactive">
															<button type="submit" class="btn btn-sm btn-outline-secondary">Inactive</button>
														</form>
													<?php endif; ?>
														<?php if (($u['status'] ?? '') !== 'suspended'): ?>
														<form method="post" action="" class="d-inline">
															<input type="hidden" name="form_type" value="update_status">
															<input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
															<input type="hidden" name="new_status" value="suspended">
															<button type="submit" class="btn btn-sm btn-outline-warning">Suspend</button>
														</form>
													<?php endif; ?>
															<form method="post" action="" class="d-inline" data-confirm-message="Are you sure you want to delete this user? This action cannot be undone.">
														<input type="hidden" name="form_type" value="delete_user">
														<input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
														<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
													</form>
												</div>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
				<?php if (!empty($totalPages) && $totalPages > 1): ?>
					<div class="card-footer">
						<nav aria-label="User pagination">
							<ul class="pagination mb-0">
								<?php $baseUrl = strtok($_SERVER['REQUEST_URI'], '?'); ?>
								<li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
									<a class="page-link" href="<?php echo $currentPage <= 1 ? '#' : htmlspecialchars($baseUrl . '?page=' . ($currentPage - 1)); ?>" tabindex="-1">Previous</a>
								</li>
								<?php for ($p = 1; $p <= $totalPages; $p++): ?>
									<li class="page-item <?php echo $p === $currentPage ? 'active' : ''; ?>">
										<a class="page-link" href="<?php echo htmlspecialchars($baseUrl . '?page=' . $p); ?>"><?php echo $p; ?></a>
									</li>
								<?php endfor; ?>
								<li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
									<a class="page-link" href="<?php echo $currentPage >= $totalPages ? '#' : htmlspecialchars($baseUrl . '?page=' . ($currentPage + 1)); ?>">Next</a>
								</li>
							</ul>
						</nav>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<div class="row mt-4">
		<div class="col-12">
			<?php if ($successMessage): ?>
				<script>
				(function(){
					var msg = <?php echo json_encode($successMessage); ?>;
					var show = function(){ if (window.showToast) { showToast(msg, 'success'); } };
					if (document.readyState === 'loading') {
						document.addEventListener('DOMContentLoaded', show);
					} else {
						show();
					}
				})();
				</script>
			<?php endif; ?>
			<?php if ($errorMessage): ?>
				<script>
				(function(){
					var msg = <?php echo json_encode($errorMessage); ?>;
					var show = function(){ if (window.showToast) { showToast(msg, 'danger'); } };
					if (document.readyState === 'loading') {
						document.addEventListener('DOMContentLoaded', show);
					} else {
						show();
					}
				})();
				</script>
			<?php endif; ?>

			<div class="card">
				<div class="card-header">
					<h5 class="mb-0"><?php echo $isEditMode ? 'Edit Customer' : 'Create New Customer'; ?></h5>
				</div>
				<div class="card-body">
					<form method="post" action="">
						<input type="hidden" name="form_type" value="<?php echo $isEditMode ? 'edit_user_save' : 'create_user'; ?>">
						<?php if ($isEditMode && !empty($editUser['id'])): ?>
							<input type="hidden" name="user_id" value="<?php echo (int)$editUser['id']; ?>">
						<?php endif; ?>
						<div class="row">
							<div class="col-md-6 mb-3">
								<label for="full_name" class="form-label">Full Name *</label>
								<input type="text" class="form-control" id="full_name" name="full_name" required value="<?php echo htmlspecialchars(isset($_POST['full_name']) ? $_POST['full_name'] : ($editUser['full_name'] ?? '')); ?>">
							</div>
							<div class="col-md-6 mb-3">
								<label for="phone_number" class="form-label">Phone Number *</label>
								<input type="text" class="form-control" id="phone_number" name="phone_number" required value="<?php echo htmlspecialchars(isset($_POST['phone_number']) ? $_POST['phone_number'] : ($editUser['phone_number'] ?? '')); ?>">
								<div class="form-text">Use format 07XXXXXXXX or 2547XXXXXXXX.</div>
							</div>
						</div>
						<div class="row">
							<div class="col-md-6 mb-3">
								<label for="email" class="form-label">Email *</label>
								<input type="email" class="form-control" id="email" name="email" required value="<?php echo htmlspecialchars(isset($_POST['email']) ? $_POST['email'] : ($editUser['email'] ?? '')); ?>">
							</div>
							<div class="col-md-6 mb-3">
								<label for="id_number" class="form-label">ID Number *</label>
								<input type="text" class="form-control" id="id_number" name="id_number" required value="<?php echo htmlspecialchars(isset($_POST['id_number']) ? $_POST['id_number'] : ($editUser['id_number'] ?? '')); ?>">
							</div>
						</div>
						<div class="row">
							<div class="col-md-6 mb-3">
								<label for="address" class="form-label">Address *</label>
								<input type="text" class="form-control" id="address" name="address" required value="<?php echo htmlspecialchars(isset($_POST['address']) ? $_POST['address'] : ($editUser['address'] ?? '')); ?>">
							</div>
							<div class="col-md-6 mb-3">
								<label for="tax_pin" class="form-label">KRA PIN (optional)</label>
								<input type="text" class="form-control" id="tax_pin" name="tax_pin" value="<?php echo htmlspecialchars(isset($_POST['tax_pin']) ? $_POST['tax_pin'] : ($editUser['tax_pin'] ?? '')); ?>">
							</div>
						</div>
						<div class="row">
							<div class="col-md-6 mb-3">
								<label for="location_label" class="form-label">Location (optional)</label>
								<input type="text" class="form-control location-autocomplete" id="location_label" name="location_label" value="<?php echo htmlspecialchars(isset($_POST['location_label']) ? $_POST['location_label'] : ($editUser['location_label'] ?? '')); ?>" placeholder="e.g. P5PP+CJ, Nguluni" autocomplete="off">
								<div class="form-text">Type a nearby landmark, estate, village name or Plus Code (e.g. "P5PP+CJ, Nguluni"); use the map below to fine-tune the exact pin.</div>
							</div>
							<div class="col-md-6 mb-3">
								<label class="form-label d-block">GPS (internal only)</label>
								<input type="hidden" id="latitude" name="latitude" value="<?php echo htmlspecialchars(isset($_POST['latitude']) ? $_POST['latitude'] : ($editUser['latitude'] ?? '')); ?>">
								<input type="hidden" id="longitude" name="longitude" value="<?php echo htmlspecialchars(isset($_POST['longitude']) ? $_POST['longitude'] : ($editUser['longitude'] ?? '')); ?>">
								<small class="text-muted d-block mb-1">Exact GPS coordinates are used internally for maps and reports; they are never shown to customers.</small>
								<input type="text" class="form-control form-control-sm" id="gps_dms_input" placeholder="e.g. 1°15'51.6&quot;S 37°11'15.2&quot;E">
								<div class="form-text">Advanced: paste latitude/longitude in DMS format and the map plus GPS fields will update automatically.</div>
							</div>
						</div>
						<div class="row">
							<div class="col-md-12 mb-2">
								<button type="button" class="btn btn-outline-secondary btn-sm" id="adminDetectLocationBtn">
									<i class="bi bi-geo-alt"></i> Use my current GPS location
								</button>
								<div class="form-text">Optional. Use this if you are physically at the property and want to pin it using your current device location.</div>
							</div>
						</div>
						<div class="row">
							<div class="col-12 mb-3">
								<label class="form-label">Pinned Location</label>
								<div id="customerLocationMap" style="height:260px;border-radius:0.5rem;overflow:hidden;border:1px solid #dee2e6;"></div>
								<div class="form-text">Zoom, drag and click on the map to place the pin exactly where the customer lives.</div>
							</div>
						</div>
						<div class="row">
							<div class="col-md-6 mb-3">
								<label for="connection_type" class="form-label">Connection Type</label>
								<select class="form-select" id="connection_type" name="connection_type">
									<?php $selType = isset($_POST['connection_type']) ? $_POST['connection_type'] : ($editUser['connection_type'] ?? 'domestic'); ?>
									<option value="domestic" <?php echo $selType === 'domestic' ? 'selected' : ''; ?>>Domestic</option>
									<option value="commercial" <?php echo $selType === 'commercial' ? 'selected' : ''; ?>>Commercial</option>
								</select>
							</div>
							<div class="col-md-6 mb-3">
								<label for="password" class="form-label"><?php echo $isEditMode ? 'Password (leave blank to keep current)' : 'Password *'; ?></label>
								<input type="password" class="form-control" id="password" name="password" <?php echo $isEditMode ? '' : 'required'; ?>>
								<div class="form-text"><?php echo $isEditMode ? 'Only set a value if you want to change the password.' : 'The customer can change this password after logging in.'; ?></div>
							</div>
						</div>
						<div class="row">
							<div class="col-md-6 mb-3">
								<label for="role" class="form-label">Role</label>
								<?php
									$roleValue = isset($_POST['role']) ? strtolower((string)$_POST['role']) : strtolower((string)($editUser['role'] ?? 'customer'));
								?>
								<select class="form-select" id="role" name="role">
									<option value="customer" <?php echo $roleValue === 'customer' ? 'selected' : ''; ?>>Customer</option>
									<option value="admin" <?php echo $roleValue === 'admin' ? 'selected' : ''; ?>>Admin (full access)</option>
									<option value="reader" <?php echo $roleValue === 'reader' ? 'selected' : ''; ?>>Reader (meter readings)</option>
									<option value="finance" <?php echo $roleValue === 'finance' ? 'selected' : ''; ?>>Finance (payments & reports)</option>
									<option value="support" <?php echo $roleValue === 'support' ? 'selected' : ''; ?>>Support (complaints & helpdesk)</option>
								</select>
								<div class="form-text">Determines what this user can access in the system.</div>
							</div>
						</div>

						<?php if ($registrationFee > 0 && !$isEditMode): ?>
							<div class="mb-3">
								<div class="form-text mb-1">Registration fee configured: KES <?php echo number_format($registrationFee, 2); ?></div>
								<div class="form-check">
									<input class="form-check-input" type="checkbox" value="1" id="registration_already_paid" name="registration_already_paid" <?php echo isset($_POST['registration_already_paid']) ? 'checked' : ''; ?>>
									<label class="form-check-label" for="registration_already_paid">
										Registration fee already paid (cash/M-Pesa/other).
									</label>
								</div>
								<div class="form-check mt-1">
									<input class="form-check-input" type="checkbox" value="1" id="send_stk" name="send_stk" <?php echo isset($_POST['send_stk']) ? 'checked' : ''; ?>>
									<label class="form-check-label" for="send_stk">
										Send M-Pesa STK push for registration fee now.
									</label>
								</div>
								<div class="mt-2">
									<label for="registration_mpesa_code" class="form-label">M-Pesa Transaction Code (if paid via M-Pesa)</label>
									<input type="text" class="form-control" id="registration_mpesa_code" name="registration_mpesa_code" value="<?php echo htmlspecialchars($_POST['registration_mpesa_code'] ?? ''); ?>" placeholder="e.g. QEU1XYZ123">
									<div class="form-text">Optional. Enter the M-Pesa transaction code for reconciliation when the registration fee was paid via M-Pesa.</div>
								</div>
								<div class="form-text mt-1">If neither option is selected, you will be asked to choose one.</div>
							</div>
						<?php endif; ?>

						<div class="mt-3">
								<button type="submit" class="btn btn-primary"><?php echo $isEditMode ? 'Save Changes' : 'Create Customer'; ?></button>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>
</div>

<?php
$googleMapsApiKey = getenv('GOOGLE_MAPS_API_KEY') ?: '';

if ($googleMapsApiKey) {
	$custom_scripts = <<<JS
<script>
function initAdminCustomerLocationMap() {
	var btn = document.getElementById('adminDetectLocationBtn');
	var latInput = document.getElementById('latitude');
	var lngInput = document.getElementById('longitude');
	var locationInput = document.getElementById('location_label');
	var mapEl = document.getElementById('customerLocationMap');
	var dmsInput = document.getElementById('gps_dms_input');

	if (!mapEl || typeof google === 'undefined' || !google.maps) {
		return;
	}

	var defaultLat = -1.292066; // Nairobi fallback
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

	function updateInputsFromLatLng(lat, lng) {
		if (!latInput || !lngInput) return;
		latInput.value = lat.toFixed(7);
		lngInput.value = lng.toFixed(7);
	}

	function ensureMarker(lat, lng) {
		if (!map) return;
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

		// Normalize quotes
		str = str.replace(/"/g, '"').replace(/'/g, "'");

		var latRegex = /(\d+)[°º]\s*(\d+)[']\s*([0-9.]+)["”]?\s*([NS])/i;
		var lonRegex = /(\d+)[°º]\s*(\d+)[']\s*([0-9.]+)["”]?\s*([EW])/i;

		var latMatch = str.match(latRegex);
		var lonMatch = str.match(lonRegex);

		if (!latMatch || !lonMatch) {
			return null;
		}

		function toDecimal(deg, min, sec, hemi) {
			var d = parseFloat(deg) + parseFloat(min) / 60 + parseFloat(sec) / 3600;
			if (/[SW]/i.test(hemi)) {
				return -d;
			}
			return d;
		}

		var lat = toDecimal(latMatch[1], latMatch[2], latMatch[3], latMatch[4]);
		var lng = toDecimal(lonMatch[1], lonMatch[2], lonMatch[3], lonMatch[4]);

		if (isNaN(lat) || isNaN(lng)) {
			return null;
		}
		return { lat: lat, lng: lng };
	}

	function geocodeAndZoomFromQuery(query, showToastOnFound) {
		if (!map) return;
		if (!query) return;
		query = query.trim();
		if (query.length < 3) return;

		var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(query + ', Kenya');
		fetch(url, { headers: { 'Accept-Language': 'en' } })
			.then(function(resp) { return resp.json(); })
			.then(function(results) {
				if (!Array.isArray(results) || results.length === 0) {
					return;
				}
				var r = results[0];
				var lat = parseFloat(r.lat);
				var lng = parseFloat(r.lon);
				if (isNaN(lat) || isNaN(lng)) return;
				map.setCenter({ lat: lat, lng: lng });
				map.setZoom(16);
				ensureMarker(lat, lng);
				if (showToastOnFound && window.showToast) {
					showToast('Suggested location for "' + query + '". Adjust on the map if needed.', 'info');
				}
			})
			.catch(function() {
				// Fail silently; admin can still place pin manually.
			});
	}

	// Initialize marker if existing GPS values are present
	if (latInput && lngInput && latInput.value && lngInput.value && !isNaN(parseFloat(latInput.value)) && !isNaN(parseFloat(lngInput.value))) {
		ensureMarker(parseFloat(latInput.value), parseFloat(lngInput.value));
	}

	// Click on map to place/move pin
	map.addListener('click', function(e) {
		var lat = e.latLng.lat();
		var lng = e.latLng.lng();
		ensureMarker(lat, lng);
	});

	// Geolocation button
	if (btn) {
		btn.addEventListener('click', function() {
			if (!navigator.geolocation) {
				var msg = 'Geolocation is not supported by this browser.';
				if (window.showToast) {
					showToast(msg, 'danger');
				} else {
					alert(msg);
				}
				return;
			}
			btn.disabled = true;
			btn.textContent = 'Detecting location...';
			navigator.geolocation.getCurrentPosition(function(position) {
				var lat = position.coords.latitude;
				var lng = position.coords.longitude;
				map.setCenter({ lat: lat, lng: lng });
				map.setZoom(16);
				ensureMarker(lat, lng);
				btn.disabled = false;
				btn.innerHTML = '<i class="bi bi-geo-alt"></i> Use my current GPS location';
			}, function() {
				var msg = 'Unable to get location. Please allow location access in your browser.';
				if (window.showToast) {
					showToast(msg, 'danger');
				} else {
					alert(msg);
				}
				btn.disabled = false;
				btn.innerHTML = '<i class="bi bi-geo-alt"></i> Use my current GPS location';
			}, {
				enableHighAccuracy: true,
				timeout: 10000,
				maximumAge: 0
			});
		});
	}

	// Allow admin to paste DMS coordinates like 1°15'51.6"S 37°11'15.2"E
	if (dmsInput) {
		var applyDms = function() {
			var parsed = parseDMSValue(dmsInput.value || '');
			if (!parsed) {
				if (window.showToast && dmsInput.value.trim() !== '') {
					showToast('Could not understand the coordinates. Please use a format like 1°15\'51.6"S 37°11\'15.2"E.', 'danger');
				}
				return;
			}
			map.setCenter({ lat: parsed.lat, lng: parsed.lng });
			map.setZoom(16);
			ensureMarker(parsed.lat, parsed.lng);
			if (window.showToast) {
				showToast('GPS coordinates applied from DMS input.', 'success');
			}
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

	// When admin types or changes the textual location, try to zoom map
	if (locationInput) {
		var lastLocationQuery = '';
		locationInput.addEventListener('change', function() {
			var q = locationInput.value || '';
			if (q.trim() === '' || q.trim() === lastLocationQuery) return;
			lastLocationQuery = q.trim();
			geocodeAndZoomFromQuery(lastLocationQuery, true);
		});
		locationInput.addEventListener('blur', function() {
			var q = locationInput.value || '';
			q = q.trim();
			if (q.length < 3 || q === lastLocationQuery) return;
			lastLocationQuery = q;
			geocodeAndZoomFromQuery(lastLocationQuery, true);
		});
	}

	// If we don't have GPS yet but we do have a textual location, try to suggest a pin
	if ((!latInput || !latInput.value || !lngInput || !lngInput.value) && locationInput && locationInput.value) {
		geocodeAndZoomFromQuery(locationInput.value, true);
	}
}
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=$googleMapsApiKey&callback=initAdminCustomerLocationMap" async defer></script>
JS;
} else {
	// Fallback to Leaflet map if Google Maps API key is not configured
	$custom_scripts = <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function() {
	var btn = document.getElementById('adminDetectLocationBtn');
	var latInput = document.getElementById('latitude');
	var lngInput = document.getElementById('longitude');
	var locationInput = document.getElementById('location_label');
	var mapEl = document.getElementById('customerLocationMap');
	var map = null;
	var marker = null;
	var dmsInput = document.getElementById('gps_dms_input');

	function updateInputsFromLatLng(lat, lng) {
		if (!latInput || !lngInput) return;
		latInput.value = lat.toFixed(7);
		lngInput.value = lng.toFixed(7);
	}

	function ensureMarker(lat, lng) {
		if (!map) return;
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

		// Normalize quotes
		str = str.replace(/"/g, '"').replace(/'/g, "'");

		var latRegex = /(\d+)[°º]\s*(\d+)[']\s*([0-9.]+)["”]?\s*([NS])/i;
		var lonRegex = /(\d+)[°º]\s*(\d+)[']\s*([0-9.]+)["”]?\s*([EW])/i;

		var latMatch = str.match(latRegex);
		var lonMatch = str.match(lonRegex);

		if (!latMatch || !lonMatch) {
			return null;
		}

		function toDecimal(deg, min, sec, hemi) {
			var d = parseFloat(deg) + parseFloat(min) / 60 + parseFloat(sec) / 3600;
			if (/[SW]/i.test(hemi)) {
				return -d;
			}
			return d;
		}

		var lat = toDecimal(latMatch[1], latMatch[2], latMatch[3], latMatch[4]);
		var lng = toDecimal(lonMatch[1], lonMatch[2], lonMatch[3], lonMatch[4]);

		if (isNaN(lat) || isNaN(lng)) {
			return null;
		}
		return { lat: lat, lng: lng };
	}

	function geocodeAndZoomFromQuery(query, showToastOnFound) {
		if (!map) return;
		if (!query) return;
		query = query.trim();
		if (query.length < 3) return;

		var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(query + ', Kenya');
		fetch(url, { headers: { 'Accept-Language': 'en' } })
			.then(function(resp) { return resp.json(); })
			.then(function(results) {
				if (!Array.isArray(results) || results.length === 0) {
					return;
				}
				var r = results[0];
				var lat = parseFloat(r.lat);
				var lng = parseFloat(r.lon);
				if (isNaN(lat) || isNaN(lng)) return;
				map.setView([lat, lng], 16);
				ensureMarker(lat, lng);
				if (showToastOnFound && window.showToast) {
					showToast('Suggested location for "' + query + '". Adjust on the map if needed.', 'info');
				}
			})
			.catch(function() {
				// Fail silently; admin can still place pin manually.
			});
	}

	function initMap() {
		if (!mapEl || typeof L === 'undefined') {
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

		map = L.map('customerLocationMap').setView([startLat, startLng], zoom);

		var streetsLayer = L.tileLayer('https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, Tiles style by Humanitarian OpenStreetMap Team hosted by OpenStreetMap France'
		});
		var satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{x}/{y}', {
			maxZoom: 19,
			attribution: 'Imagery &copy; <a href="https://www.esri.com/">Esri</a> &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community'
		});

		streetsLayer.addTo(map);
		L.control.layers({
			'Streets (OSM HOT)': streetsLayer,
			'Satellite (Esri)': satelliteLayer
		}, {}).addTo(map);

		if (latInput && lngInput && latInput.value && lngInput.value && !isNaN(parseFloat(latInput.value)) && !isNaN(parseFloat(lngInput.value))) {
			ensureMarker(parseFloat(latInput.value), parseFloat(lngInput.value));
		}

		map.on('click', function(e) {
			ensureMarker(e.latlng.lat, e.latlng.lng);
		});

		// Keep view focused on the current customer area when switching to satellite
		map.on('baselayerchange', function(e) {
			if (e && e.name === 'Satellite (Esri)') {
				if (latInput && lngInput && latInput.value && lngInput.value && !isNaN(parseFloat(latInput.value)) && !isNaN(parseFloat(lngInput.value))) {
					var clat = parseFloat(latInput.value);
					var clng = parseFloat(lngInput.value);
					map.setView([clat, clng], 16);
				} else {
					map.setView([startLat, startLng], zoom);
				}
			}
		});

		// If we don't have GPS yet but we do have a textual location,
		// try to suggest a pin using a geocoding service.
		if ((!latInput || !latInput.value || !lngInput || !lngInput.value) && locationInput && locationInput.value) {
			geocodeAndZoomFromQuery(locationInput.value, true);
		}
	}

	if (btn) {
		btn.addEventListener('click', function() {
			if (!navigator.geolocation) {
				var msg = 'Geolocation is not supported by this browser.';
				if (window.showToast) {
					showToast(msg, 'danger');
				} else {
					alert(msg);
				}
				return;
			}
			btn.disabled = true;
			btn.textContent = 'Detecting location...';
			navigator.geolocation.getCurrentPosition(function(position) {
				var lat = position.coords.latitude;
				var lng = position.coords.longitude;
				if (map) {
					map.setView([lat, lng], 16);
					ensureMarker(lat, lng);
				} else {
					updateInputsFromLatLng(lat, lng);
				}
				btn.disabled = false;
				btn.innerHTML = '<i class="bi bi-geo-alt"></i> Use my current GPS location';
			}, function(error) {
				var msg = 'Unable to get location. Please allow location access in your browser.';
				if (window.showToast) {
					showToast(msg, 'danger');
				} else {
					alert(msg);
				}
				btn.disabled = false;
				btn.innerHTML = '<i class="bi bi-geo-alt"></i> Use my current GPS location';
			}, {
				enableHighAccuracy: true,
				timeout: 10000,
				maximumAge: 0
			});
		});
	}

	// Allow admin to paste DMS coordinates like 1°15'51.6"S 37°11'15.2"E
	if (dmsInput) {
		var applyDms = function() {
			var parsed = parseDMSValue(dmsInput.value || '');
			if (!parsed) {
				if (window.showToast && dmsInput.value.trim() !== '') {
					showToast('Could not understand the coordinates. Please use a format like 1°15\'51.6"S 37°11\'15.2"E.', 'danger');
				}
				return;
			}
			if (map) {
				map.setView([parsed.lat, parsed.lng], 16);
				ensureMarker(parsed.lat, parsed.lng);
			} else {
				updateInputsFromLatLng(parsed.lat, parsed.lng);
			}
			if (window.showToast) {
				showToast('GPS coordinates applied from DMS input.', 'success');
			}
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

	// When admin types or changes the textual location, try to zoom map
	if (locationInput) {
		var lastLocationQuery = '';
		locationInput.addEventListener('change', function() {
			var q = locationInput.value || '';
			if (q.trim() === '' || q.trim() === lastLocationQuery) return;
			lastLocationQuery = q.trim();
			geocodeAndZoomFromQuery(lastLocationQuery, true);
		});
		locationInput.addEventListener('blur', function() {
			var q = locationInput.value || '';
			q = q.trim();
			if (q.length < 3 || q === lastLocationQuery) return;
			lastLocationQuery = q;
			geocodeAndZoomFromQuery(lastLocationQuery, true);
		});
	}

	if (mapEl && typeof L !== 'undefined') {
		initMap();
	}
});
</script>
JS;
}

require_once __DIR__ . '/../../templates/footer.php';
?>
