<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if (!$auth->isLoggedIn() || !$auth->isAdmin()) {
    header('Location: /login');
    exit;
}

// All configurable permissions with human-friendly metadata
$permissionDefs = [
    'view_customers'        => ['label' => 'View Customers',           'description' => 'Access customer list and map', 'icon' => 'bi-people'],
    'view_accounting'       => ['label' => 'View Accounting',          'description' => 'Access accounting journals', 'icon' => 'bi-journal-bookmark'],
    'view_reports'          => ['label' => 'View Reports',             'description' => 'Access financial reports', 'icon' => 'bi-bar-chart'],
    'view_payments'         => ['label' => 'View Payments',            'description' => 'View payments and transaction history', 'icon' => 'bi-cash-stack'],
    'receive_payments'      => ['label' => 'Receive Payments',         'description' => 'Record manual payment receipts for invoices and balances', 'icon' => 'bi-receipt-cutoff'],
    'view_invoicing'        => ['label' => 'View Invoicing',           'description' => 'Access invoicing workspace', 'icon' => 'bi-receipt'],
    'view_bill_detail'      => ['label' => 'View Bill Detail',         'description' => 'View individual bill details', 'icon' => 'bi-file-earmark-text'],
    'manage_demand_notices' => ['label' => 'Demand Notices',           'description' => 'Access and manage demand notices', 'icon' => 'bi-exclamation-triangle'],
    'manage_approvals'      => ['label' => 'Finance Approvals',        'description' => 'Access approval workflows', 'icon' => 'bi-check2-circle'],
    'handle_support'        => ['label' => 'Support Chat & Inquiries', 'description' => 'Access support chat and inquiry inbox', 'icon' => 'bi-headset'],
    'send_messages'         => ['label' => 'Messaging / SMS',          'description' => 'Access messaging and bulk SMS tools', 'icon' => 'bi-chat-dots'],
];

$roles = ['finance', 'reader', 'support'];
$roleLabels = ['finance' => 'Finance', 'reader' => 'Reader', 'support' => 'Support'];

$successMessage = '';
$errorMessage = '';

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_permissions'])) {
    // CSRF check
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['role_perm_csrf'] ?? '', $_POST['csrf_token'])) {
        $errorMessage = 'Invalid security token. Please refresh and try again.';
    } else {
        try {
            $db->beginTransaction();

            // Build the full set of checked permissions from POST
            // Format: permissions[role][permission] = "1"
            $submitted = $_POST['permissions'] ?? [];

            // Delete all existing permissions and re-insert checked ones
            $db->exec("DELETE FROM role_permissions");

            $stmt = $db->prepare("INSERT INTO role_permissions (role, permission) VALUES (:role, :permission)");
            foreach ($roles as $role) {
                foreach (array_keys($permissionDefs) as $perm) {
                    if (!empty($submitted[$role][$perm])) {
                        $stmt->execute([':role' => $role, ':permission' => $perm]);
                    }
                }
            }

            $db->commit();

            // Clear the static permission cache so subsequent checks on this request reflect changes
            Auth::clearPermissionCache();

            $successMessage = 'Role permissions saved successfully.';
        } catch (Exception $e) {
            $db->rollBack();
            error_log('role_permissions save error: ' . $e->getMessage());
            $errorMessage = 'Failed to save permissions. Please try again.';
        }
    }
}

// Regenerate CSRF token
$_SESSION['role_perm_csrf'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['role_perm_csrf'];

// Load current permissions from DB into a 2D lookup: $current[role][permission] = true
$current = [];
try {
    $rows = $db->query("SELECT role, permission FROM role_permissions")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $current[$row['role']][$row['permission']] = true;
    }
} catch (Exception $e) {
    $errorMessage = 'Could not load permissions from database. The role_permissions table may not exist yet.';
}

$page_title = 'Role Permissions';
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
                    <i class="bi bi-shield-lock-fill pb-watermark"></i>
                </div>
                <div class="pb-inner">
                    <div class="pb-left">
                        <div class="pb-eyebrow-row">
                            <span class="pb-eyebrow-chip"><i class="bi bi-shield-lock-fill"></i> Access Control</span>
                        </div>
                        <h2 class="pb-title">Role Permissions</h2>
                        <p class="pb-subtitle">Configure which features each staff role can access. Admin users always have full access and are not listed here.</p>
                    </div>
                    <div class="pb-right">
                        <div class="pb-btn-row">
                            <a href="/admin/staff-users" class="pb-btn"><i class="bi bi-person-badge"></i> Staff Users</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($successMessage): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($successMessage); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($errorMessage): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($errorMessage); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row mt-3">
        <div class="col-12">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="save_permissions" value="1">

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div class="row align-items-center w-100 mx-0">
                            <div class="col">
                                <span class="fw-semibold text-muted text-uppercase small">Permission</span>
                            </div>
                            <?php foreach ($roles as $role): ?>
                            <div class="col-auto text-center" style="min-width:110px;">
                                <span class="badge bg-secondary px-3 py-2"><?php echo $roleLabels[$role]; ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php foreach ($permissionDefs as $perm => $meta): ?>
                        <div class="row align-items-center border-bottom mx-0 py-3 perm-row">
                            <div class="col">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi <?php echo $meta['icon']; ?> text-primary fs-5"></i>
                                    <div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($meta['label']); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($meta['description']); ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php foreach ($roles as $role): ?>
                            <div class="col-auto text-center" style="min-width:110px;">
                                <div class="form-check form-switch d-flex justify-content-center">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        role="switch"
                                        name="permissions[<?php echo $role; ?>][<?php echo $perm; ?>]"
                                        value="1"
                                        id="perm_<?php echo $role; ?>_<?php echo $perm; ?>"
                                        <?php echo !empty($current[$role][$perm]) ? 'checked' : ''; ?>
                                        style="width:2.5em;height:1.4em;">
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="card-footer d-flex justify-content-end py-3">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-floppy me-1"></i> Save Permissions
                        </button>
                    </div>
                </div>

            </form>
        </div>
    </div>
</div>

<style>
.perm-row:hover { background-color: #f8f9fa; }
.perm-row:last-child { border-bottom: none !important; }
</style>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
