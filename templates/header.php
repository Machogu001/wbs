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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>Water Billing System</title>
    <link rel="icon" type="image/svg+xml" href="/public/images/favicon-water.svg">
    <link rel="alternate icon" type="image/png" href="/public/images/favicon-water.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../public/css/style.css">
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

    <?php if(!isset($hide_nav) || !$hide_nav): ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="../index.php">
                <i class="bi bi-droplet"></i> Water Billing System
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <?php if(isset($_SESSION['user_id'])): ?>
                        <?php if(isset($_SESSION['user_data']['role']) && $_SESSION['user_data']['role'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="/admin">
                                    <i class="bi bi-shield-lock"></i> Admin
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="/admin/invoicing">
                                    <i class="bi bi-file-earmark-text"></i> Invoicing
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/dashboard">
                                <i class="bi bi-speedometer2"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item dropdown">
                            <?php if (!empty($is_admin_page)): ?>
                                <a class="nav-link dropdown-toggle" href="javascript:void(0);" id="navbarServices" role="button" onclick="(function(el){var m=el.nextElementSibling;if(!m)return;var shown=m.classList.contains('show');var open=document.querySelectorAll('.dropdown-menu.show');open.forEach(function(mm){mm.classList.remove('show');});if(!shown){m.classList.add('show');}})(this); return false;">
                            <?php else: ?>
                                <a class="nav-link dropdown-toggle" href="#" id="navbarServices" role="button" data-bs-toggle="dropdown">
                            <?php endif; ?>
                                <i class="bi bi-list-task"></i> Services
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="navbarServices">
                                <?php if(isset($_SESSION['user_data']['role']) && $_SESSION['user_data']['role'] === 'admin'): ?>
                                    <li><a class="dropdown-item" href="/admin/reports"><i class="bi bi-graph-up"></i> Reports</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                <?php endif; ?>
                                <li><a class="dropdown-item" href="/bills"><i class="bi bi-receipt"></i> My Bills</a></li>
                                <li><a class="dropdown-item" href="/complaints"><i class="bi bi-chat-left-text"></i> Complaints</a></li>
                                <li><a class="dropdown-item" href="/pay"><i class="bi bi-credit-card"></i> Pay Bill</a></li>
                            </ul>
                        </li>
                        <li class="nav-item dropdown">
                            <?php if (!empty($is_admin_page)): ?>
                                <a class="nav-link dropdown-toggle" href="javascript:void(0);" id="navbarDropdown" role="button" onclick="(function(el){var m=el.nextElementSibling;if(!m)return;var shown=m.classList.contains('show');var open=document.querySelectorAll('.dropdown-menu.show');open.forEach(function(mm){mm.classList.remove('show');});if(!shown){m.classList.add('show');}})(this); return false;">
                            <?php else: ?>
                                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <?php endif; ?>
                                <i class="bi bi-person-circle"></i> 
                                <?php echo htmlspecialchars($_SESSION['user_data']['full_name'] ?? 'User'); ?>
                            </a>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="/profile"><i class="bi bi-person"></i> Profile</a></li>
                                <?php if(isset($_SESSION['user_data']['role']) && $_SESSION['user_data']['role'] === 'admin'): ?>
                                    <li><a class="dropdown-item" href="/admin/invoicing"><i class="bi bi-file-earmark-text"></i> Invoicing</a></li>
                                    <li><a class="dropdown-item" href="/admin/reports"><i class="bi bi-graph-up"></i> Reports</a></li>
                                    <li><a class="dropdown-item" href="/admin/complaints"><i class="bi bi-flag"></i> Complaints</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="/logout"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/register">
                                <i class="bi bi-person-plus"></i> Register
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/login">
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
