<?php
session_start();
$page_title = "Home";
$hide_nav = false;

// One-time logout flash message via cookie
$loggedOut = isset($_COOKIE['flash_logged_out']) && $_COOKIE['flash_logged_out'] === '1';
if ($loggedOut) {
    $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    // Clear the flash cookie immediately so it only shows once
    setcookie('flash_logged_out', '', time() - 3600, '/', '', $isSecure, true);
}

// Load settings for registration fee display inside landing registration modal
$registrationFee = 0.00;
try {
    if (file_exists(__DIR__ . '/../config/database.php')) {
        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/../includes/BillingSettings.php';
        if (class_exists('Database')) {
            $database = new Database();
            $db = $database->getConnection();
            if ($db) {
                $settingsService = new BillingSettings($db);
                $settings = $settingsService->getSettings();
                $registrationFee = isset($settings['registration_fee']) ? (float)$settings['registration_fee'] : 0.00;
                $currencyCode = $settings['currency_code'] ?? 'KES';
            }
        }
    }
} catch (Throwable $e) {
    // Fail silently on landing page; registration modal will still work via API
}

require_once __DIR__ . '/../templates/header.php';

// Show a toast if the user just logged out
if ($loggedOut): ?>
    <script>
    window.addEventListener('load', function() {
        if (window.showToast) {
            showToast('Logged out successfully.','success');
        }
    });
    </script>
<?php endif; ?>

<section class="hero-section text-white py-5 py-lg-6">
    <div class="container">
        <div class="row align-items-center gy-4">
            <div class="col-lg-6">
                <span class="badge bg-light text-primary mb-3">
                    <i class="bi bi-droplet me-1"></i> Water Billing System
                </span>
                <h1 class="display-5 fw-bold mb-3">Water Billing Made Easy</h1>
                <p class="lead mb-4">
                    Manage your water bills, pay securely via M-Pesa, and track
                    your consumption and payments from a single, modern portal.
                </p>
                <?php if(!isset($_SESSION['user_id'])): ?>
                    <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
                        <button type="button" class="btn btn-light btn-lg" data-bs-toggle="modal" data-bs-target="#registerModal">
                            <i class="bi bi-person-plus"></i> Get Started
                        </button>
                        <button type="button" class="btn btn-outline-light btn-lg" data-bs-toggle="modal" data-bs-target="#loginModal">
                            <i class="bi bi-box-arrow-in-right"></i> Login
                        </button>
                    </div>
                    <p class="small mb-0 opacity-75">
                        Designed for utility teams, system administrators and customers who
                        need clear, timely billing – on desktop or mobile.
                    </p>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
                        <a href="/dashboard" class="btn btn-light btn-lg">
                            <i class="bi bi-speedometer2"></i> Open Dashboard
                        </a>
                        <a href="/pay" class="btn btn-outline-light btn-lg">
                            <i class="bi bi-credit-card"></i> Pay a Bill
                        </a>
                    </div>
                    <p class="small mb-0 opacity-75">
                        Quick access for Admin, Invoicing, Services and full
                        system administration from your dashboard.
                    </p>
                <?php endif; ?>

                <div class="mt-4 d-flex flex-wrap gap-2">
                    <span class="badge rounded-pill bg-light text-primary">
                        <i class="bi bi-shield-lock me-1"></i> Admin
                    </span>
                    <span class="badge rounded-pill bg-light text-primary">
                        <i class="bi bi-file-earmark-text me-1"></i> Invoicing
                    </span>
                    <span class="badge rounded-pill bg-light text-primary">
                        <i class="bi bi-phone-vibrate me-1"></i> Collections (M-Pesa)
                    </span>
                    <span class="badge rounded-pill bg-light text-primary">
                        <i class="bi bi-people me-1"></i> Customer Portal
                    </span>
                    <span class="badge rounded-pill bg-light text-primary">
                        <i class="bi bi-gear-wide-connected me-1"></i> System Administrator
                    </span>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card border-0 shadow-lg bg-light text-dark">
                    <div class="card-body p-4">
                        <h5 class="card-title fw-semibold mb-3">
                            <i class="bi bi-speedometer2 text-primary me-2"></i>
                            Operational at a glance
                        </h5>
                        <p class="small text-muted mb-4">
                            A single place to monitor billing, collections and customer
                            engagement in real time.
                        </p>
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2 d-flex align-items-start">
                                <span class="text-primary me-2"><i class="bi bi-droplet-half"></i></span>
                                <div>
                                    <div class="fw-semibold">Billing</div>
                                    <div class="small text-muted">Issue accurate water bills with meter readings and adjustments.</div>
                                </div>
                            </li>
                            <li class="mb-2 d-flex align-items-start">
                                <span class="text-primary me-2"><i class="bi bi-phone"></i></span>
                                <div>
                                    <div class="fw-semibold">Collections</div>
                                    <div class="small text-muted">Integrated M-Pesa payments and automatic posting of receipts.</div>
                                </div>
                            </li>
                            <li class="mb-2 d-flex align-items-start">
                                <span class="text-primary me-2"><i class="bi bi-bell"></i></span>
                                <div>
                                    <div class="fw-semibold">Notifications</div>
                                    <div class="small text-muted">Instant SMS and Email alerts for bills, payments and updates.</div>
                                </div>
                            </li>
                            <li class="d-flex align-items-start">
                                <span class="text-primary me-2"><i class="bi bi-graph-up-arrow"></i></span>
                                <div>
                                    <div class="fw-semibold">Oversight</div>
                                    <div class="small text-muted">Clear reporting for management, finance and system administrators.</div>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-5 bg-light">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="fw-bold mb-2">Why Choose Our System</h2>
            <p class="text-muted mb-0">Built for utilities who need reliable billing, transparent collections and clear customer communication.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="mb-3 text-primary"><i class="bi bi-credit-card-2-front fs-3"></i></div>
                        <h5 class="card-title fw-semibold">Integrated Payments</h5>
                        <p class="card-text small text-muted mb-0">
                            Customers pay directly via M-Pesa with automatic confirmation, receipting
                            and posting to their accounts.
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="mb-3 text-primary"><i class="bi bi-chat-dots fs-3"></i></div>
                        <h5 class="card-title fw-semibold">Smart Notifications</h5>
                        <p class="card-text small text-muted mb-0">
                            Bills, payment alerts and OTPs go out instantly over SMS and Email to keep
                            customers informed.
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="mb-3 text-primary"><i class="bi bi-bar-chart-line fs-3"></i></div>
                        <h5 class="card-title fw-semibold">Usage & History</h5>
                        <p class="card-text small text-muted mb-0">
                            Customers and admins can track consumption, previous bills and payments from
                            a single dashboard.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
