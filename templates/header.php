<?php
$install_lock = __DIR__ . '/../config/installed.lock';
$installed = false;

if (file_exists($install_lock)) {
    $installed = true;
} else {
    $config_file = __DIR__ . '/../config/database.php';
    if (file_exists($config_file)) {
        require_once $config_file;
        if (class_exists('Database') && method_exists('Database', 'testConnection')) {
            $installed = Database::testConnection();
        }
    }
}

if (!$installed && strpos($_SERVER['REQUEST_URI'], '/install') !== 0) {
    header('Location: /install/');
    exit;
}

// Application name from environment (.env APP_NAME), with a sensible default
if (!isset($appName) || $appName === '') {
    $envAppName = getenv('APP_NAME');
    $appName = ($envAppName !== false && $envAppName !== '') ? $envAppName : 'Water Billing System';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?><?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/svg+xml" href="/public/images/favicon-water.svg">
    <link rel="alternate icon" type="image/png" href="/public/images/favicon-water.png">
    <!-- PWA manifest & iOS home-screen meta -->
    <link rel="manifest" href="/manifest.php">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="theme-color" content="#0D47A1">
    <link rel="apple-touch-icon" href="/public/images/favicon-water.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:wght@400;700&family=Source+Sans+3:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <?php $styleVersion = @filemtime(__DIR__ . '/../public/css/style.css') ?: time(); ?>
    <link rel="stylesheet" href="/public/css/style.css?v=<?php echo (int)$styleVersion; ?>">
</head>
<body class="<?php echo !empty($hide_nav) ? 'auth-layout' : ''; ?>">
    <div aria-live="polite" aria-atomic="true" class="position-fixed top-0 end-0 p-3" style="z-index: 1080; min-width: 300px; pointer-events: none;">
        <div id="globalToast" class="toast align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body" id="globalToastBody">
                    <!-- Toast message -->
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    </div>

    <!-- Global confirmation modal for sweet confirmations instead of browser popups -->
    <div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="confirmModalLabel">Please Confirm</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="confirmModalMessage">
                    Are you sure?
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmModalConfirm">Yes, Continue</button>
                </div>
            </div>
        </div>
    </div>

    <?php if(!isset($hide_nav) || !$hide_nav): ?>
    <?php
        $currentPath    = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $navIsAdmin     = isset($_SESSION['user_data']['role']) && $_SESSION['user_data']['role'] === 'admin';
        $navUserRole    = strtolower((string)($_SESSION['user_data']['role'] ?? 'customer'));
        $navIsStaff     = in_array($navUserRole, ['admin', 'reader', 'finance', 'support'], true);
        $navCanManageSettings = in_array($navUserRole, ['admin', 'finance'], true);
        $navAdminPaths  = ['/admin/users','/admin/staff-users','/reports','/admin/payment-transactions',
                           '/admin/demand-notices','/admin/approvals','/admin/integration-health',
                           '/admin/messaging','/activity_log','/system-logs','/chat','/internal-chat',
                           '/invoicing','/settings'];
        $navCustomerPaths = ['/bills','/pay','/complaints'];
        $navServicesActive = in_array($currentPath, array_merge($navAdminPaths, $navCustomerPaths));
            $navProfileActive  = in_array($currentPath, ['/profile', '/receipts', '/settings']);
    ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top">
        <div class="container">
            <a class="navbar-brand" href="/">
                <i class="bi bi-droplet-half-fill navbar-brand-icon"></i>
                <span class="navbar-brand-name"><?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
                    aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
                    <?php if(isset($_SESSION['user_id'])): ?>

                        <li class="nav-item">
                            <a class="nav-link<?php echo $currentPath === '/dashboard' ? ' active' : ''; ?>" href="/dashboard">
                                <i class="bi bi-speedometer2"></i> Dashboard
                            </a>
                        </li>
                        <!-- Admin: Operations dropdown -->
                        <?php if ($navIsAdmin): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle<?php echo in_array($currentPath, ['/admin/users','/admin/staff-users','/invoicing','/reports','/admin/payment-transactions','/admin/demand-notices','/admin/approvals']) ? ' active' : ''; ?>"
                               href="#" id="navbarOperations" role="button"
                               <?php if (!empty($is_admin_page)): ?>
                                   onclick="(function(el){var m=el.nextElementSibling;if(!m)return;var shown=m.classList.contains('show');var open=document.querySelectorAll('.dropdown-menu.show');open.forEach(function(mm){mm.classList.remove('show');});if(!shown){m.classList.add('show');}})(this); return false;"
                               <?php else: ?>
                                   data-bs-toggle="dropdown"
                               <?php endif; ?>>
                                <i class="bi bi-briefcase"></i> Operations
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarOperations">
                                <li><a class="dropdown-item<?php echo $currentPath === '/admin/users' ? ' active' : ''; ?>" href="/admin/users"><i class="bi bi-people"></i> Customers</a></li>
                                <li><a class="dropdown-item<?php echo $currentPath === '/admin/staff-users' ? ' active' : ''; ?>" href="/admin/staff-users"><i class="bi bi-person-badge"></i> Staff Users</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item<?php echo $currentPath === '/invoicing' ? ' active' : ''; ?>" href="/invoicing"><i class="bi bi-file-earmark-text"></i> Invoicing</a></li>
                                <li><a class="dropdown-item<?php echo $currentPath === '/reports' ? ' active' : ''; ?>" href="/reports"><i class="bi bi-graph-up-arrow"></i> Reports</a></li>
                                <li><a class="dropdown-item<?php echo $currentPath === '/admin/payment-transactions' ? ' active' : ''; ?>" href="/admin/payment-transactions"><i class="bi bi-wallet2"></i> Transactions</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item<?php echo $currentPath === '/admin/demand-notices' ? ' active' : ''; ?>" href="/admin/demand-notices"><i class="bi bi-file-earmark-exclamation"></i> Demand Notices</a></li>
                                <li><a class="dropdown-item<?php echo $currentPath === '/admin/approvals' ? ' active' : ''; ?>" href="/admin/approvals"><i class="bi bi-check2-square"></i> Approvals</a></li>
                            </ul>
                        </li>
                        <?php endif; ?>

                        <!-- Admin/Staff: Monitoring dropdown -->
                        <?php if ($navIsStaff): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle<?php echo in_array($currentPath, ['/admin/integration-health','/admin/messaging','/activity_log','/system-logs','/chat','/internal-chat']) ? ' active' : ''; ?>"
                               href="#" id="navbarMonitoring" role="button"
                               <?php if (!empty($is_admin_page)): ?>
                                   onclick="(function(el){var m=el.nextElementSibling;if(!m)return;var shown=m.classList.contains('show');var open=document.querySelectorAll('.dropdown-menu.show');open.forEach(function(mm){mm.classList.remove('show');});if(!shown){m.classList.add('show');}})(this); return false;"
                               <?php else: ?>
                                   data-bs-toggle="dropdown"
                               <?php endif; ?>>
                                <i class="bi bi-display"></i> Monitoring
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarMonitoring">
                                <?php if ($navIsAdmin): ?>
                                    <li><a class="dropdown-item<?php echo $currentPath === '/admin/integration-health' ? ' active' : ''; ?>" href="/admin/integration-health"><i class="bi bi-hdd-network"></i> Integration Health</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                <?php endif; ?>
                                <li><a class="dropdown-item<?php echo $currentPath === '/admin/messaging' ? ' active' : ''; ?>" href="/admin/messaging"><i class="bi bi-chat-dots"></i> Messaging</a></li>
                                <?php if ($navIsAdmin): ?>
                                    <li><a class="dropdown-item<?php echo $currentPath === '/activity_log' ? ' active' : ''; ?>" href="/activity_log"><i class="bi bi-clipboard-check"></i> Activity Log</a></li>
                                    <li><a class="dropdown-item<?php echo $currentPath === '/system-logs' ? ' active' : ''; ?>" href="/system-logs"><i class="bi bi-terminal"></i> System Logs</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item<?php echo $currentPath === '/chat' ? ' active' : ''; ?>" href="/chat"><i class="bi bi-headset"></i> Support Chat</a></li>
                                <?php endif; ?>
                                <li><a class="dropdown-item<?php echo $currentPath === '/internal-chat' ? ' active' : ''; ?>" href="/internal-chat"><i class="bi bi-people-fill"></i> Internal Chat</a></li>
                            </ul>
                        </li>
                        <?php endif; ?>

                        <!-- My Account dropdown (all logged-in users) -->
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle<?php echo in_array($currentPath, ['/bills','/pay','/complaints']) ? ' active' : ''; ?>"
                               href="#" id="navbarMyAccount" role="button"
                               <?php if (!empty($is_admin_page)): ?>
                                   onclick="(function(el){var m=el.nextElementSibling;if(!m)return;var shown=m.classList.contains('show');var open=document.querySelectorAll('.dropdown-menu.show');open.forEach(function(mm){mm.classList.remove('show');});if(!shown){m.classList.add('show');}})(this); return false;"
                               <?php else: ?>
                                   data-bs-toggle="dropdown"
                               <?php endif; ?>>
                                <i class="bi bi-person"></i> My Account
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarMyAccount">
                                <li><a class="dropdown-item<?php echo $currentPath === '/bills' ? ' active' : ''; ?>" href="/bills"><i class="bi bi-receipt"></i> My Bills</a></li>
                                <li><a class="dropdown-item<?php echo $currentPath === '/pay' ? ' active' : ''; ?>" href="/pay"><i class="bi bi-credit-card"></i> Pay Bill</a></li>
                                <li><a class="dropdown-item<?php echo $currentPath === '/complaints' ? ' active' : ''; ?>" href="/complaints"><i class="bi bi-chat-left-dots"></i> Complaints</a></li>
                            </ul>
                        </li>

                        <!-- User / profile dropdown -->
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle navbar-user-toggle<?php echo $navProfileActive ? ' active' : ''; ?>"
                               href="<?php echo !empty($is_admin_page) ? 'javascript:void(0);' : '#'; ?>"
                               id="navbarDropdown" role="button"
                               <?php if (!empty($is_admin_page)): ?>
                                   onclick="(function(el){var m=el.nextElementSibling;if(!m)return;var shown=m.classList.contains('show');var open=document.querySelectorAll('.dropdown-menu.show');open.forEach(function(mm){mm.classList.remove('show');});if(!shown){m.classList.add('show');}})(this); return false;"
                               <?php else: ?>
                                   data-bs-toggle="dropdown"
                               <?php endif; ?>>
                                <i class="bi bi-person-circle"></i>
                                <?php if ($navIsAdmin): ?>
                                    <span class="navbar-role-badge navbar-role-admin">Admin</span>
                                <?php elseif ($navIsStaff): ?>
                                    <span class="navbar-role-badge navbar-role-staff"><?php echo ucfirst($navUserRole); ?></span>
                                <?php else: ?>
                                    <span class="navbar-user-name-label"><?php echo htmlspecialchars($_SESSION['user_data']['full_name'] ?? 'User'); ?></span>
                                <?php endif; ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                                <li>
                                    <div class="navbar-user-info-panel">
                                        <div class="navbar-user-info-name"><?php echo htmlspecialchars($_SESSION['user_data']['full_name'] ?? 'User'); ?></div>
                                        <?php if (!empty($_SESSION['user_data']['account_number'])): ?>
                                            <div class="navbar-user-info-account"><i class="bi bi-upc-scan me-1"></i><?php echo htmlspecialchars($_SESSION['user_data']['account_number']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </li>
                                <li><hr class="dropdown-divider mt-0 mb-1"></li>
                                <?php if ($navCanManageSettings): ?>
                                    <li><a class="dropdown-item<?php echo $currentPath === '/settings' ? ' active' : ''; ?>" href="/settings"><i class="bi bi-sliders"></i> Settings</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                <?php endif; ?>
                                <li><a class="dropdown-item<?php echo $currentPath === '/profile' ? ' active' : ''; ?>" href="/profile"><i class="bi bi-person"></i> My Profile</a></li>
                                <li><a class="dropdown-item<?php echo $currentPath === '/receipts' ? ' active' : ''; ?>" href="/receipts"><i class="bi bi-receipt-cutoff"></i> Receipts</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item navbar-logout-item" href="/logout"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                            </ul>
                        </li>

                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/register" data-bs-toggle="modal" data-bs-target="#registerModal">
                                <i class="bi bi-person-plus"></i> Register
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="btn btn-sm btn-outline-light navbar-login-btn" href="/login" data-bs-toggle="modal" data-bs-target="#loginModal">
                                <i class="bi bi-box-arrow-in-right"></i> Login
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    <?php endif; ?>
    
    <main class="container-fluid p-0">
