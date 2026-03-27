<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/User.php';
require_once __DIR__ . '/../../includes/CountryDialCode.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if (!$auth->isLoggedIn() || !$auth->isAdmin()) {
    header('Location: /login');
    exit;
}

$successMessage = '';
$errorMessage = '';

if (isset($_SESSION['flash_message'])) {
    if (!empty($_SESSION['flash_type']) && $_SESSION['flash_type'] === 'error') {
        $errorMessage = (string)$_SESSION['flash_message'];
    } else {
        $successMessage = (string)$_SESSION['flash_message'];
    }
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

function nextStaffAccountNumber(PDO $db): string {
    $stmt = $db->query("SELECT account_number FROM users WHERE account_number LIKE 'STF%' ORDER BY id DESC LIMIT 1");
    $last = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

    $next = 1;
    if ($last && !empty($last['account_number']) && preg_match('/^STF(\\d+)$/', $last['account_number'], $m)) {
        $next = (int)$m[1] + 1;
    }

    return 'STF' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function normalizePhoneFromForm(string $countryCode, string $localNumber): string {
    $code = preg_replace('/\D+/', '', $countryCode);
    $local = preg_replace('/\D+/', '', $localNumber);
    $local = ltrim($local, '0');
    if ($code === '' || $local === '') {
        return '';
    }
    return $code . $local;
}

$countryCodeOptions = [
    '254' => 'Kenya (+254)',
    '256' => 'Uganda (+256)',
    '255' => 'Tanzania (+255)',
    '1' => 'USA/Canada (+1)',
    '44' => 'United Kingdom (+44)'
];
try {
    if ($db) {
        $countryDialCodeService = new CountryDialCode($db);
        $dbCountryCodeOptions = $countryDialCodeService->listActive();
        if (!empty($dbCountryCodeOptions)) {
            $countryCodeOptions = $dbCountryCodeOptions;
        }
    }
} catch (Exception $e) {
    // Keep fallback options if table creation/loading fails.
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = $_POST['form_type'] ?? '';

    try {
        if ($formType === 'create_staff') {
            $firstName = trim($_POST['first_name'] ?? '');
            $middleName = trim($_POST['middle_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $phoneCountryCode = trim((string)($_POST['phone_country_code'] ?? '254'));
            $phoneLocal = trim((string)($_POST['phone_number_local'] ?? ''));
            $phone = normalizePhoneFromForm($phoneCountryCode, $phoneLocal);
            $idNumber = trim($_POST['id_number'] ?? '');
            $role = strtolower(trim($_POST['role'] ?? 'reader'));
            $password = (string)($_POST['password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($firstName === '' || $lastName === '' || $username === '' || $phone === '' || $idNumber === '') {
                throw new Exception('First name, last name, username, phone number and ID number are required.');
            }

            if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $username)) {
                throw new Exception('Username must be 3-30 characters and contain only letters, numbers, dot, underscore or hyphen.');
            }

            if ($password === '' || $confirmPassword === '') {
                throw new Exception('Password and confirm password are required.');
            }

            if (strlen($password) < 8) {
                throw new Exception('Password must be at least 8 characters long.');
            }

            if (!hash_equals($password, $confirmPassword)) {
                throw new Exception('Password and confirm password do not match.');
            }

            $allowedRoles = ['admin', 'reader', 'finance', 'support'];
            if (!in_array($role, $allowedRoles, true)) {
                $role = 'reader';
            }

            $userModel = new User($db);
            if ($userModel->phoneExists($phone)) {
                throw new Exception('Phone number already registered.');
            }
            if ($userModel->usernameExists($username)) {
                throw new Exception('Username already exists. Please choose another username.');
            }

            $fullName = trim(preg_replace('/\s+/', ' ', $firstName . ' ' . $middleName . ' ' . $lastName));
            $accountNumber = nextStaffAccountNumber($db);

            $sql = "INSERT INTO users (account_number, username, full_name, phone_number, email, id_number, tax_pin, address, meter_number, connection_type, location_label, latitude, longitude, password_hash, role, status)
                    VALUES (:account_number, :username, :full_name, :phone_number, NULL, :id_number, NULL, NULL, NULL, 'domestic', NULL, NULL, NULL, :password_hash, :role, 'active')";
            $stmt = $db->prepare($sql);
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $stmt->bindParam(':account_number', $accountNumber);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':full_name', $fullName);
            $stmt->bindParam(':phone_number', $phone);
            $stmt->bindParam(':id_number', $idNumber);
            $stmt->bindParam(':password_hash', $passwordHash);
            $stmt->bindParam(':role', $role);
            $stmt->execute();

                $_SESSION['flash_message'] = 'Staff user created successfully. Username: ' . $username . ' | Account: ' . $accountNumber;
            $_SESSION['flash_type'] = 'success';
            header('Location: /admin/staff-users');
            exit;
        }

        if ($formType === 'update_status') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $newStatus = trim($_POST['new_status'] ?? '');
            $allowed = ['active', 'inactive', 'suspended'];
            if ($userId <= 0 || !in_array($newStatus, $allowed, true)) {
                throw new Exception('Invalid staff user or status.');
            }
            $stmt = $db->prepare("UPDATE users SET status = :status WHERE id = :id AND role <> 'customer'");
            $stmt->bindParam(':status', $newStatus);
            $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            $_SESSION['flash_message'] = 'Staff status updated.';
            $_SESSION['flash_type'] = 'success';
            header('Location: /admin/staff-users');
            exit;
        }

        if ($formType === 'delete_user') {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new Exception('Invalid staff user.');
            }

            if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $userId) {
                throw new Exception('You cannot delete your own account.');
            }

            $stmt = $db->prepare("DELETE FROM users WHERE id = :id AND role <> 'customer'");
            $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            $_SESSION['flash_message'] = 'Staff user deleted successfully.';
            $_SESSION['flash_type'] = 'success';
            header('Location: /admin/staff-users');
            exit;
        }

    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

$staffUsers = [];
$totalStaff = 0;
try {
    $countStmt = $db->query("SELECT COUNT(*) AS total FROM users WHERE role <> 'customer'");
    $countRow = $countStmt ? $countStmt->fetch(PDO::FETCH_ASSOC) : ['total' => 0];
    $totalStaff = (int)($countRow['total'] ?? 0);

    $staffStmt = $db->query("SELECT id, account_number, username, full_name, phone_number, id_number, status, role, created_at FROM users WHERE role <> 'customer' ORDER BY full_name ASC");
    $staffUsers = $staffStmt ? ($staffStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Exception $e) {
    $staffUsers = [];
}

$formFirstName = trim((string)($_POST['first_name'] ?? ''));
$formMiddleName = trim((string)($_POST['middle_name'] ?? ''));
$formLastName = trim((string)($_POST['last_name'] ?? ''));
$formUsername = trim((string)($_POST['username'] ?? ''));
$formIdNumber = trim((string)($_POST['id_number'] ?? ''));
$formRole = strtolower(trim((string)($_POST['role'] ?? 'reader')));
$formPhoneCountryCode = preg_replace('/\D+/', '', (string)($_POST['phone_country_code'] ?? '254'));
if (!isset($countryCodeOptions[$formPhoneCountryCode])) {
    $formPhoneCountryCode = '254';
}
$formPhoneLocalNumber = preg_replace('/\D+/', '', (string)($_POST['phone_number_local'] ?? ''));

$page_title = 'Admin - Staff Users';
require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container mt-4">
    <div class="row">
        <div class="col-md-12">
            <div class="admin-page-header d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Office Staff Users</h2>
                    <p class="text-muted mb-0">Manage office staff accounts separately from customer accounts.</p>
                </div>
                <div>
                    <a href="/admin/users" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-people"></i> Customers Page
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($successMessage): ?>
        <div class="alert alert-success mt-3"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>
    <?php if ($errorMessage): ?>
        <div class="alert alert-danger mt-3"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <div class="row mt-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Existing Staff Users</h5>
                    <small class="text-muted">Total: <?php echo (int)$totalStaff; ?></small>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($staffUsers)): ?>
                        <p class="p-3 mb-0 text-muted">No office staff users found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Account</th>
                                        <th>Username</th>
                                        <th>Name</th>
                                        <th>Phone</th>
                                        <th>ID Number</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($staffUsers as $u): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($u['account_number'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($u['username'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($u['full_name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($u['phone_number'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($u['id_number'] ?? ''); ?></td>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst((string)($u['role'] ?? 'staff'))); ?></span></td>
                                        <td>
                                            <span class="badge bg-<?php echo ($u['status'] === 'active') ? 'success' : (($u['status'] === 'inactive') ? 'secondary' : 'warning'); ?>">
                                                <?php echo htmlspecialchars(ucfirst((string)($u['status'] ?? ''))); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php if (($u['status'] ?? '') !== 'active'): ?>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="form_type" value="update_status">
                                                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                                        <input type="hidden" name="new_status" value="active">
                                                        <button type="submit" class="btn btn-sm btn-outline-success">Activate</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if (($u['status'] ?? '') !== 'inactive'): ?>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="form_type" value="update_status">
                                                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                                        <input type="hidden" name="new_status" value="inactive">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Inactive</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if (($u['status'] ?? '') !== 'suspended'): ?>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="form_type" value="update_status">
                                                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                                        <input type="hidden" name="new_status" value="suspended">
                                                        <button type="submit" class="btn btn-sm btn-outline-warning">Suspend</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this staff user?');">
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
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Create Office Staff User</h5>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="form_type" value="create_staff">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="first_name" class="form-label">First Name *</label>
                                <input type="text" class="form-control" id="first_name" name="first_name" required value="<?php echo htmlspecialchars($formFirstName); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="middle_name" class="form-label">Middle Name</label>
                                <input type="text" class="form-control" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($formMiddleName); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="last_name" class="form-label">Last Name *</label>
                                <input type="text" class="form-control" id="last_name" name="last_name" required value="<?php echo htmlspecialchars($formLastName); ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="username" class="form-label">Username *</label>
                                <input type="text" class="form-control" id="username" name="username" minlength="3" maxlength="30" pattern="^[A-Za-z0-9._-]{3,30}$" autocomplete="username" required value="<?php echo htmlspecialchars($formUsername); ?>">
                                <div class="form-text">Staff can use this username to login.</div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="phone_number_local" class="form-label">Phone Number *</label>
                                <div class="input-group">
                                    <span class="input-group-text">+</span>
                                    <select class="form-select" id="phone_country_code" name="phone_country_code" style="max-width: 190px;" required>
                                        <?php foreach ($countryCodeOptions as $code => $label): ?>
                                            <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $formPhoneCountryCode === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" class="form-control" id="phone_number_local" name="phone_number_local" inputmode="numeric" autocomplete="tel-national" placeholder="e.g. 712345678" required value="<?php echo htmlspecialchars($formPhoneLocalNumber); ?>">
                                </div>
                                <div class="form-text">Choose country code, then enter number without spaces or symbols.</div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="id_number" class="form-label">ID Number *</label>
                                <input type="text" class="form-control" id="id_number" name="id_number" required value="<?php echo htmlspecialchars($formIdNumber); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="role" class="form-label">Staff Role *</label>
                                <select class="form-select" id="role" name="role">
                                    <option value="reader" <?php echo $formRole === 'reader' ? 'selected' : ''; ?>>Reader</option>
                                    <option value="finance" <?php echo $formRole === 'finance' ? 'selected' : ''; ?>>Finance</option>
                                    <option value="support" <?php echo $formRole === 'support' ? 'selected' : ''; ?>>Support</option>
                                    <option value="admin" <?php echo $formRole === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="password" class="form-label">Password *</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" required>
                                    <button class="btn btn-outline-secondary" type="button" id="toggleStaffPassword" aria-label="Show or hide password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="confirm_password" class="form-label">Confirm Password *</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                                    <button class="btn btn-outline-secondary" type="button" id="toggleStaffConfirmPassword" aria-label="Show or hide confirm password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Create Staff User</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function setupToggle(inputId, buttonId) {
        var input = document.getElementById(inputId);
        var button = document.getElementById(buttonId);
        if (!input || !button) return;

        button.addEventListener('click', function() {
            var icon = button.querySelector('i');
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            if (icon) {
                icon.classList.toggle('bi-eye', !show);
                icon.classList.toggle('bi-eye-slash', show);
            }
        });
    }

    setupToggle('password', 'toggleStaffPassword');
    setupToggle('confirm_password', 'toggleStaffConfirmPassword');

    // Swal is loaded synchronously in footer.php, so it is available by DOMContentLoaded
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'info',
            title: 'No meter number for staff',
            text: 'Staff accounts are created without a meter number.',
            showConfirmButton: false,
            timer: 4500,
            timerProgressBar: true,
        });
    }
});
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
