<?php
session_start();
if(!isset($_SESSION['user_id'])) {
	header("Location: /login");
	exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/User.php';
require_once __DIR__ . '/../includes/SMS.php';
require_once __DIR__ . '/../includes/Email.php';
require_once __DIR__ . '/../includes/ActivityLog.php';

$message = null;
$message_type = 'success';

$database = new Database();
$db = $database->getConnection();
$user = $_SESSION['user_data'] ?? [];
$activeSection = '';

// Fetch last login and logout from activity log
$lastLogin = null;
$lastLogout = null;
if ($db && !empty($_SESSION['user_id'])) {
	try {
		$userId = (int)$_SESSION['user_id'];
		$sqlLogin = "SELECT created_at FROM activity_log WHERE user_id = :uid AND action = 'login' ORDER BY created_at DESC LIMIT 1";
		$stmtLogin = $db->prepare($sqlLogin);
		$stmtLogin->bindParam(':uid', $userId, PDO::PARAM_INT);
		$stmtLogin->execute();
		$lastLogin = $stmtLogin->fetchColumn() ?: null;

		$sqlLogout = "SELECT created_at FROM activity_log WHERE user_id = :uid AND action = 'logout' ORDER BY created_at DESC LIMIT 1";
		$stmtLogout = $db->prepare($sqlLogout);
		$stmtLogout->bindParam(':uid', $userId, PDO::PARAM_INT);
		$stmtLogout->execute();
		$lastLogout = $stmtLogout->fetchColumn() ?: null;
	} catch (Exception $e) {
		$lastLogin = null;
		$lastLogout = null;
	}
}


// Load any flash message/section from previous redirect
if (isset($_SESSION['profile_message'])) {
	$message = (string)$_SESSION['profile_message'];
	$message_type = (string)($_SESSION['profile_message_type'] ?? 'success');
	unset($_SESSION['profile_message'], $_SESSION['profile_message_type']);
}
if (isset($_SESSION['profile_active_section'])) {
	$activeSection = (string)$_SESSION['profile_active_section'];
	unset($_SESSION['profile_active_section']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	// Decide which logical action to run for this POST
	$rawFormAction = isset($_POST['form_action']) ? trim((string)$_POST['form_action']) : '';
	if ($rawFormAction !== '') {
		$action = $rawFormAction;
	} elseif (isset($_POST['submit_action']) && $_POST['submit_action'] !== '') {
		// Send OTP buttons use submit_action
		$action = (string)$_POST['submit_action'];
	} else {
		// Fallback to hidden action field (e.g. update_tax_pin, change_password)
		$action = $_POST['action'] ?? 'update_tax_pin';
	}

	if (!$db) {
		$message = 'Database connection error.';
		$message_type = 'danger';
	} else {
		try {
			$userId = (int)($_SESSION['user_id'] ?? 0);
			if ($userId <= 0) {
				throw new Exception('Invalid session user. Please log in again.');
			}

			switch ($action) {
				case 'update_tax_pin':
					$tax_pin = isset($_POST['tax_pin']) ? trim($_POST['tax_pin']) : '';
					$stmt = $db->prepare("UPDATE users SET tax_pin = :tax_pin WHERE id = :id");
					$stmt->bindParam(':tax_pin', $tax_pin);
					$stmt->bindParam(':id', $userId, PDO::PARAM_INT);
					if ($stmt->execute()) {
							$_SESSION['user_data']['tax_pin'] = $tax_pin;
							$user['tax_pin'] = $tax_pin;
							$_SESSION['profile_message'] = 'PIN / Tax ID updated successfully.';
							$_SESSION['profile_message_type'] = 'success';
							header('Location: /profile');
							exit;
					} else {
						throw new Exception('Failed to update PIN / Tax ID.');
					}
					break;

					case 'request_phone_otp':
						$activeSection = 'change_phone';
					$newPhone = trim($_POST['new_phone'] ?? '');
					$currentPhone = $user['phone_number'] ?? '';
					if ($newPhone === '' || $currentPhone === '') {
						throw new Exception('Please enter a new phone number.');
					}
					if ($newPhone === $currentPhone) {
						throw new Exception('New phone number must be different from the current one.');
					}
					if (!preg_match('/^(?:254|\+254|0)?(7\d{8})$/', $newPhone)) {
						throw new Exception('Please enter a valid phone number (e.g., 254712345678).');
					}

					// Ensure phone not used by another user
					$userModel = new User($db);
					if ($userModel->phoneExists($newPhone)) {
						throw new Exception('That phone number is already registered to another account.');
					}

					$otp = (string)random_int(100000, 999999);
					$sms = new SMS();
					$maskPhone = $currentPhone;
					$messageText = "Your OTP to change your phone number is {$otp}. It expires in 5 minutes.";
					$smsResult = $sms->send($currentPhone, $messageText);
					if (!$smsResult['success']) {
						throw new Exception('Failed to send OTP SMS. Please try again.');
					}

					// Also send OTP via email to the current email address if available
					if (!empty($user['email'])) {
						$email = new Email();
						$email->send(
							$user['email'],
							'OTP to change your phone number',
							$messageText
						);
					}

						$_SESSION['phone_change_otp'] = [
						'code' => $otp,
						'expires_at' => time() + 300,
						'new_phone' => $newPhone,
					];
						$_SESSION['profile_message'] = 'OTP sent to your current phone number.';
						$_SESSION['profile_message_type'] = 'success';
						$_SESSION['profile_active_section'] = $activeSection;
						header('Location: /profile');
						exit;
					break;

					case 'confirm_phone_change':
						$activeSection = 'change_phone';
					$enteredOtp = trim($_POST['phone_otp'] ?? '');
					if ($enteredOtp === '') {
						throw new Exception('Please enter the OTP sent to your current phone number.');
					}
					if (empty($_SESSION['phone_change_otp'])) {
						// No active phone change request (already used, expired, or refreshed)
						$_SESSION['profile_message'] = 'No active phone change request. If you already updated your phone number, you can ignore this message. Otherwise, please request a new OTP.';
						$_SESSION['profile_message_type'] = 'info';
						$_SESSION['profile_active_section'] = $activeSection;
						header('Location: /profile');
						exit;
					}
					$otpData = $_SESSION['phone_change_otp'];
					if (time() > ($otpData['expires_at'] ?? 0)) {
						unset($_SESSION['phone_change_otp']);
						throw new Exception('The OTP has expired. Please request a new one.');
					}
					if ($enteredOtp !== (string)$otpData['code']) {
						throw new Exception('Invalid OTP. Please check and try again.');
					}

					$newPhone = $otpData['new_phone'] ?? '';
					if ($newPhone === '') {
						unset($_SESSION['phone_change_otp']);
						throw new Exception('Invalid phone change request. Please start again.');
					}

					$stmt = $db->prepare('UPDATE users SET phone_number = :phone WHERE id = :id');
					$stmt->bindParam(':phone', $newPhone);
					$stmt->bindParam(':id', $userId, PDO::PARAM_INT);
					if ($stmt->execute()) {
						$_SESSION['user_data']['phone_number'] = $newPhone;
						$user['phone_number'] = $newPhone;
						unset($_SESSION['phone_change_otp']);
							$_SESSION['profile_message'] = 'Phone number updated successfully.';
							$_SESSION['profile_message_type'] = 'success';
							$_SESSION['profile_active_section'] = $activeSection;
							// Clear phone form fields after successful change (on next render)
							unset($_POST['new_phone'], $_POST['phone_otp']);
							header('Location: /profile');
							exit;
					} else {
						throw new Exception('Failed to update phone number.');
					}
					break;

					case 'request_email_otp':
						$activeSection = 'change_email';
					$newEmail = trim($_POST['new_email'] ?? '');
					$currentEmail = $user['email'] ?? '';
					$currentPhoneForOtp = $user['phone_number'] ?? '';
					if ($newEmail === '') {
						throw new Exception('Please enter a new email address.');
					}
					if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
						throw new Exception('Please enter a valid email address.');
					}
					if ($newEmail === $currentEmail) {
						throw new Exception('New email must be different from the current one.');
					}

					// Ensure email not used by another user
					$stmtCheck = $db->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
					$stmtCheck->bindParam(':email', $newEmail);
					$stmtCheck->bindParam(':id', $userId, PDO::PARAM_INT);
					$stmtCheck->execute();
					if ($stmtCheck->fetch(PDO::FETCH_ASSOC)) {
						throw new Exception('That email address is already registered to another account.');
					}

					if ($currentPhoneForOtp === '') {
						throw new Exception('No phone number on file to send OTP. Please contact support.');
					}

					$otp = (string)random_int(100000, 999999);
					$sms = new SMS();
					$messageText = "Your OTP to change your email address is {$otp}. It expires in 5 minutes.";
					$smsResult = $sms->send($currentPhoneForOtp, $messageText);
					if (!$smsResult['success']) {
						throw new Exception('Failed to send OTP SMS. Please try again.');
					}

					// Also send OTP via email to the current email address if available
					if (!empty($currentEmail)) {
						$email = new Email();
						$email->send(
							$currentEmail,
							'OTP to change your email address',
							$messageText
						);
					}

						$_SESSION['email_change_otp'] = [
						'code' => $otp,
						'expires_at' => time() + 300,
						'new_email' => $newEmail,
					];
						$_SESSION['profile_message'] = 'OTP sent via SMS to your registered phone number.';
						$_SESSION['profile_message_type'] = 'success';
						$_SESSION['profile_active_section'] = $activeSection;
						header('Location: /profile');
						exit;
					break;

					case 'confirm_email_change':
						$activeSection = 'change_email';
					$enteredOtp = trim($_POST['email_otp'] ?? '');
					if ($enteredOtp === '') {
						throw new Exception('Please enter the OTP sent via SMS.');
					}
					if (empty($_SESSION['email_change_otp'])) {
						// No active email change request (already used, expired, or refreshed)
						$_SESSION['profile_message'] = 'No active email change request. If you already updated your email address, you can ignore this message. Otherwise, please request a new OTP.';
						$_SESSION['profile_message_type'] = 'info';
						$_SESSION['profile_active_section'] = $activeSection;
						header('Location: /profile');
						exit;
					}
					$otpData = $_SESSION['email_change_otp'];
					if (time() > ($otpData['expires_at'] ?? 0)) {
						unset($_SESSION['email_change_otp']);
						throw new Exception('The OTP has expired. Please request a new one.');
					}
					if ($enteredOtp !== (string)$otpData['code']) {
						throw new Exception('Invalid OTP. Please check and try again.');
					}

					$newEmail = $otpData['new_email'] ?? '';
					if ($newEmail === '') {
						unset($_SESSION['email_change_otp']);
						throw new Exception('Invalid email change request. Please start again.');
					}

					$stmt = $db->prepare('UPDATE users SET email = :email WHERE id = :id');
					$stmt->bindParam(':email', $newEmail);
					$stmt->bindParam(':id', $userId, PDO::PARAM_INT);
					if ($stmt->execute()) {
						$_SESSION['user_data']['email'] = $newEmail;
						$user['email'] = $newEmail;
						unset($_SESSION['email_change_otp']);
							$_SESSION['profile_message'] = 'Email address updated successfully.';
							$_SESSION['profile_message_type'] = 'success';
							$_SESSION['profile_active_section'] = $activeSection;
							// Clear email form fields after successful change (on next render)
							unset($_POST['new_email'], $_POST['email_otp']);
							header('Location: /profile');
							exit;
					} else {
						throw new Exception('Failed to update email address.');
					}
					break;

					case 'change_password':
						$activeSection = 'change_password';
					$oldPassword = (string)($_POST['old_password'] ?? '');
					$newPassword = (string)($_POST['new_password'] ?? '');
					$confirmPassword = (string)($_POST['confirm_new_password'] ?? '');

					if ($oldPassword === '' || $newPassword === '' || $confirmPassword === '') {
						throw new Exception('Please fill in all password fields.');
					}
					if ($newPassword !== $confirmPassword) {
						throw new Exception('New password and confirmation do not match.');
					}
					if (strlen($newPassword) < 6) {
						throw new Exception('New password must be at least 6 characters long.');
					}

					$stmt = $db->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
					$stmt->bindParam(':id', $userId, PDO::PARAM_INT);
					$stmt->execute();
					$row = $stmt->fetch(PDO::FETCH_ASSOC);
					if (!$row || empty($row['password_hash']) || !password_verify($oldPassword, $row['password_hash'])) {
						throw new Exception('Old password is incorrect.');
					}

					$newHash = password_hash($newPassword, PASSWORD_BCRYPT);
					$stmtUpdate = $db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
					$stmtUpdate->bindParam(':hash', $newHash);
					$stmtUpdate->bindParam(':id', $userId, PDO::PARAM_INT);
						if ($stmtUpdate->execute()) {
							$_SESSION['profile_message'] = 'Password updated successfully.';
							$_SESSION['profile_message_type'] = 'success';
							$_SESSION['profile_active_section'] = $activeSection;
							header('Location: /profile');
							exit;
						} else {
						throw new Exception('Failed to update password.');
					}
					break;

				default:
					throw new Exception('Unknown action.');
			}
		} catch (Exception $e) {
			$message = $e->getMessage();
			$message_type = 'danger';
		}
	}
}

	$phoneOtpPending = !empty($_SESSION['phone_change_otp']);
	$emailOtpPending = !empty($_SESSION['email_change_otp']);

$page_title = "My Profile";
require_once __DIR__ . '/../templates/header.php';
?>

<div class="container mt-4">
	<div class="row">
		<div class="col-md-12">
			<h2>My Profile</h2>
			<p class="text-muted">Your account details and security settings.</p>
		</div>
	</div>

	<div class="row mt-3">
		<div class="col-md-6">
			<div class="card">
				<div class="card-body">
					<?php if($message): ?>
						<script>
						window.addEventListener('load', function() {
							if (window.showToast) {
								showToast(<?php echo json_encode($message); ?>, <?php echo json_encode($message_type); ?>);
							}
						});
						</script>
					<?php endif; ?>
					<form method="POST">
						<input type="hidden" name="action" value="update_tax_pin">
						<div class="mb-3">
							<label class="form-label">Full Name</label>
							<div class="form-control bg-light"><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></div>
						</div>
						<div class="mb-3">
							<label class="form-label">Account Number</label>
							<div class="form-control bg-light"><?php echo htmlspecialchars($user['account_number'] ?? ''); ?></div>
						</div>
						<div class="mb-3">
							<label class="form-label">Phone Number</label>
							<div class="form-control bg-light"><?php echo htmlspecialchars($user['phone_number'] ?? ''); ?></div>
						</div>
						<div class="mb-3">
							<label class="form-label">Meter Number</label>
							<div class="form-control bg-light"><?php echo htmlspecialchars($user['meter_number'] ?? ''); ?></div>
						</div>
						<?php if ($lastLogin): ?>
						<div class="mb-3">
							<label class="form-label">Last Login</label>
							<div class="form-control bg-light"><?php echo htmlspecialchars(date('d M Y, H:i', strtotime($lastLogin))); ?></div>
						</div>
						<?php endif; ?>
						<?php if ($lastLogout): ?>
						<div class="mb-3">
							<label class="form-label">Last Logout</label>
							<div class="form-control bg-light"><?php echo htmlspecialchars(date('d M Y, H:i', strtotime($lastLogout))); ?></div>
						</div>
						<?php endif; ?>
						<div class="mb-3">
							<label class="form-label">PIN / Tax ID (optional)</label>
							<input type="text" name="tax_pin" class="form-control" value="<?php echo htmlspecialchars($user['tax_pin'] ?? ''); ?>" placeholder="e.g. P012345678Z">
							<div class="form-text">If you have a PIN/Tax ID you want used on eTIMS submissions, you can add it here. This field is optional.</div>
						</div>
						<button type="submit" class="btn btn-primary">Save PIN</button>
					</form>
				</div>
			</div>
		</div>
		<div class="col-md-6">
			<div class="card">
				<div class="card-body">
					<div class="mb-3">
						<label for="profile_action_select" class="form-label">What would you like to change?</label>
						<select id="profile_action_select" class="form-select">
							<option value="" <?php echo $activeSection === '' ? 'selected' : ''; ?> disabled>Select an option</option>
							<option value="change_phone" <?php echo $activeSection === 'change_phone' ? 'selected' : ''; ?>>Change Phone Number</option>
							<option value="change_email" <?php echo $activeSection === 'change_email' ? 'selected' : ''; ?>>Change Email Address</option>
							<option value="change_password" <?php echo $activeSection === 'change_password' ? 'selected' : ''; ?>>Change Password</option>
						</select>
					</div>
					<div id="change_phone_section" class="profile-change-section <?php echo $activeSection === 'change_phone' ? '' : 'd-none'; ?>">
						<h5 class="card-title">Change Phone Number</h5>
						<p class="text-muted small mb-2">An OTP will be sent via SMS (and email if available) to your current contact details before the change is applied.</p>
						<form method="POST">
							<input type="hidden" name="action" value="request_or_confirm_phone">
							<input type="hidden" name="form_action" value="">
							<div class="mb-3">
								<label class="form-label">Current Phone Number</label>
								<div class="form-control bg-light"><?php echo htmlspecialchars($user['phone_number'] ?? ''); ?></div>
							</div>
							<div class="mb-3">
								<label for="new_phone" class="form-label">New Phone Number</label>
								<input type="text" class="form-control" id="new_phone" name="new_phone" placeholder="e.g. 254712345678" value="<?php echo htmlspecialchars($_POST['new_phone'] ?? ''); ?>">
							</div>
							<div class="mb-3 d-none">
								<label for="phone_otp" class="form-label">OTP</label>
								<input type="text" class="form-control" id="phone_otp" name="phone_otp" placeholder="Enter OTP after you receive it" value="<?php echo htmlspecialchars($_POST['phone_otp'] ?? ''); ?>">
							</div>
							<div class="d-flex gap-2">
								<button type="submit" name="submit_action" value="request_phone_otp" class="btn btn-outline-primary btn-sm">Send OTP</button>
							</div>
						</form>
					</div>
					<div id="change_email_section" class="profile-change-section <?php echo $activeSection === 'change_email' ? '' : 'd-none'; ?>">
						<h5 class="card-title">Change Email Address</h5>
						<p class="text-muted small mb-2">An OTP will be sent via SMS (and email if available) to your registered contacts before the change is applied.</p>
						<form method="POST">
							<input type="hidden" name="action" value="request_or_confirm_email">
							<input type="hidden" name="form_action" value="">
							<div class="mb-3">
								<label class="form-label">Current Email</label>
								<div class="form-control bg-light"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
							</div>
							<div class="mb-3">
								<label for="new_email" class="form-label">New Email Address</label>
								<input type="email" class="form-control" id="new_email" name="new_email" value="<?php echo htmlspecialchars($_POST['new_email'] ?? ''); ?>">
							</div>
							<div class="mb-3 d-none">
								<label for="email_otp" class="form-label">OTP</label>
								<input type="text" class="form-control" id="email_otp" name="email_otp" placeholder="Enter OTP after you receive it" value="<?php echo htmlspecialchars($_POST['email_otp'] ?? ''); ?>">
							</div>
							<div class="d-flex gap-2">
								<button type="submit" name="submit_action" value="request_email_otp" class="btn btn-outline-primary btn-sm">Send OTP</button>
							</div>
						</form>
					</div>
					<div id="change_password_section" class="profile-change_section <?php echo $activeSection === 'change_password' ? '' : 'd-none'; ?>">
						<h5 class="card-title">Change Password</h5>
						<form method="POST">
							<input type="hidden" name="action" value="change_password">
							<div class="mb-3">
								<label for="old_password" class="form-label">Current Password</label>
								<input type="password" class="form-control" id="old_password" name="old_password">
							</div>
							<div class="mb-3">
								<label for="new_password" class="form-label">New Password</label>
								<input type="password" class="form-control" id="new_password" name="new_password">
							</div>
							<div class="mb-3">
								<label for="confirm_new_password" class="form-label">Confirm New Password</label>
								<input type="password" class="form-control" id="confirm_new_password" name="confirm_new_password">
							</div>
							<button type="submit" class="btn btn-primary">Update Password</button>
						</form>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- OTP Modal for phone/email changes -->
	<div class="modal fade" id="otpModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="otpModalTitle">Enter OTP</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<p id="otpModalMessage">Enter OTP after you receive it.</p>
					<input type="text" class="form-control mt-2" id="otpModalInput" placeholder="Enter OTP">
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
					<button type="button" class="btn btn-primary" id="otpModalConfirmBtn">Confirm Change</button>
				</div>
			</div>
		</div>
	</div>

	<script>
	document.addEventListener('DOMContentLoaded', function() {
		var select = document.getElementById('profile_action_select');
		if (!select) return;
		var sections = {
			'change_phone': document.getElementById('change_phone_section'),
			'change_email': document.getElementById('change_email_section'),
			'change_password': document.getElementById('change_password_section')
		};

		function updateVisibleSection(value) {
			Object.keys(sections).forEach(function(key) {
				if (sections[key]) {
					sections[key].classList.add('d-none');
				}
			});
			var target = sections[value];
			if (target) {
				target.classList.remove('d-none');
			}
		}

		select.addEventListener('change', function() {
			updateVisibleSection(this.value);
		});

		// If server set an active section, ensure it is visible on load
		var initial = select.value;
		if (initial) {
			updateVisibleSection(initial);
		}

		// OTP popup handling
		var phoneOtpPending = <?php echo $phoneOtpPending ? 'true' : 'false'; ?>;
		var emailOtpPending = <?php echo $emailOtpPending ? 'true' : 'false'; ?>;
		var otpModalEl = document.getElementById('otpModal');
		var otpInputEl = document.getElementById('otpModalInput');
		var otpTitleEl = document.getElementById('otpModalTitle');
		var otpMsgEl = document.getElementById('otpModalMessage');
		var otpConfirmBtn = document.getElementById('otpModalConfirmBtn');
		var otpModalType = null;

		function openOtpModal(type) {
			if (!otpModalEl || !window.bootstrap) return;
			otpModalType = type;
			otpInputEl.value = '';
			if (type === 'phone') {
				otpTitleEl.textContent = 'Confirm Phone Number Change';
				otpMsgEl.textContent = 'Enter the OTP sent to your current phone number.';
			} else if (type === 'email') {
				otpTitleEl.textContent = 'Confirm Email Address Change';
				otpMsgEl.textContent = 'Enter the OTP sent via SMS to your registered phone number.';
			}
			var modal = new bootstrap.Modal(otpModalEl);
			otpModalEl.addEventListener('shown.bs.modal', function onShown() {
				otpModalEl.removeEventListener('shown.bs.modal', onShown);
				otpInputEl.focus();
			});
			otpConfirmBtn.onclick = function() {
				var otpVal = otpInputEl.value.trim();
				if (!otpVal) {
					if (window.showToast) {
						showToast('Please enter the OTP first.', 'danger');
					}
					return;
				}
				if (otpModalType === 'phone') {
					var form = document.querySelector('#change_phone_section form');
					if (!form) return;
					var hiddenOtp = form.querySelector('input[name="phone_otp"]');
					var actionInput = form.querySelector('input[name="form_action"]');
					if (!hiddenOtp || !actionInput) return;
					hiddenOtp.value = otpVal;
					actionInput.value = 'confirm_phone_change';
					form.submit();
				} else if (otpModalType === 'email') {
					var eform = document.querySelector('#change_email_section form');
					if (!eform) return;
					var hiddenEmailOtp = eform.querySelector('input[name="email_otp"]');
					var eActionInput = eform.querySelector('input[name="form_action"]');
					if (!hiddenEmailOtp || !eActionInput) return;
					hiddenEmailOtp.value = otpVal;
					eActionInput.value = 'confirm_email_change';
					eform.submit();
				}
				modal.hide();
			};
			modal.show();
		}

		// If an OTP is pending from the server, open the corresponding modal
		if (phoneOtpPending) {
			select.value = 'change_phone';
			updateVisibleSection('change_phone');
			openOtpModal('phone');
		} else if (emailOtpPending) {
			select.value = 'change_email';
			updateVisibleSection('change_email');
			openOtpModal('email');
		}
	});
	</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
