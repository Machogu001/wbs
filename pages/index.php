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
$currencyCode = 'KES';
$isLoggedIn = !empty($_SESSION['user_id']);
$currentRole = strtolower((string)($_SESSION['user_data']['role'] ?? 'guest'));
$isAdmin = $currentRole === 'admin';
$isStaff = in_array($currentRole, ['reader', 'finance', 'support'], true);

$paymentsDrillUrl = ($isAdmin || $isStaff) ? '/admin/payments' : '/pay';
$paymentsDrillLabel = ($isAdmin || $isStaff) ? 'Click to open' : 'Pay Bill';

$notificationsDrillUrl = ($isAdmin || $isStaff) ? '/admin/messaging' : ($isLoggedIn ? '/dashboard' : '/register');
$notificationsDrillLabel = ($isAdmin || $isStaff) ? 'Click to open' : ($isLoggedIn ? 'Alerts on Dashboard' : 'Register for Alerts');

$historyDrillUrl = ($isAdmin || $isStaff) ? '/reports' : '/bills';
$historyDrillLabel = ($isAdmin || $isStaff) ? 'Click to open' : 'Bills & History';

$paymentsDrillDataAttrs = '';
$notificationsDrillDataAttrs = '';
$historyDrillDataAttrs = '';
if (!$isLoggedIn) {
    // Guests get direct modals for conversion, with href as fallback if JS is unavailable.
    $paymentsDrillUrl = '/login';
    $paymentsDrillLabel = 'Login to Pay';
    $paymentsDrillDataAttrs = ' data-bs-toggle="modal" data-bs-target="#loginModal" data-guest-toast="Please login to pay your bills."';

    $notificationsDrillUrl = '/register';
    $notificationsDrillLabel = 'Create an Account';
    $notificationsDrillDataAttrs = ' data-bs-toggle="modal" data-bs-target="#registerModal" data-guest-toast="Create an account to receive bill and payment alerts."';

    $historyDrillUrl = '/login';
    $historyDrillLabel = 'Login for History';
    $historyDrillDataAttrs = ' data-bs-toggle="modal" data-bs-target="#loginModal" data-guest-toast="Please login to view usage and payment history."';
}
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

<?php if (!$isLoggedIn): ?>
    <script>
    window.addEventListener('DOMContentLoaded', function() {
        var gatedCards = document.querySelectorAll('.landing-drill-card[data-guest-toast]');
        gatedCards.forEach(function(card) {
            card.addEventListener('click', function() {
                var msg = this.getAttribute('data-guest-toast');
                if (msg && window.showToast) {
                    showToast(msg, 'info');
                }
            });
        });
    });
    </script>
<?php endif; ?>

<section class="hero-section text-white py-5 py-lg-6">
    <div class="container">
        <div class="row align-items-center gy-4">
            <div class="col-lg-6">
                <span class="badge bg-light text-primary mb-3">
                    <i class="bi bi-droplet me-1"></i>
                    <span style="color:#ff4b5c; font-weight:600;">Smart utility billing portal</span>
                </span>
                <h1 class="display-5 fw-bold mb-3">
                    <span style="color:#22c55e;">Water</span>
                    <span style="color:#ef4444;">Billing</span>
                    <span style="color:#ffffff;">Made</span>
                    <span style="color:#facc15;">Easy</span>
                </h1>
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
                    <?php if (!empty($registrationFee) && $registrationFee > 0): ?>
                    <p class="small mt-2 mb-0 opacity-75">
                        A one-time non-refundable installation/registration fee of
                        <strong><?php echo htmlspecialchars($currencyCode); ?>
                        <?php echo number_format($registrationFee, 2); ?></strong>
                        applies when opening a new water connection account.
                        See the
                        <a href="#" class="text-decoration-underline text-light" data-bs-toggle="modal" data-bs-target="#termsModal">Terms and Conditions</a>.
                    </p>
                    <?php endif; ?>
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
            <h2 class="fw-bold mb-2 landing-section-title color-cycle" style="color:#0ea5e9;">
                Why Choose Our System
            </h2>
            <p class="mb-0 landing-section-subtitle" style="color:#000;">Built for utilities who need reliable billing, transparent collections and clear customer communication.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <a href="<?php echo htmlspecialchars($paymentsDrillUrl); ?>" class="card h-100 border-0 shadow-sm landing-drill-card text-decoration-none" aria-label="Open payments details"<?php echo $paymentsDrillDataAttrs; ?>>
                    <div class="card-body">
                        <div class="mb-3 text-primary"><i class="bi bi-credit-card-2-front fs-3"></i></div>
                        <h5 class="card-title fw-semibold landing-feature-title color-cycle" style="color:#0ea5e9;">Integrated Payments</h5>
                        <p class="card-text small mb-0" style="color:#000;">
                            Customers pay directly via M-Pesa with automatic confirmation, receipting
                            and posting to their accounts.
                        </p>
                        <p class="small mt-3 mb-0 fw-semibold text-primary"><?php echo htmlspecialchars($paymentsDrillLabel); ?> <i class="bi bi-arrow-right"></i></p>
                    </div>
                </a>
            </div>
            <div class="col-md-4">
                <a href="<?php echo htmlspecialchars($notificationsDrillUrl); ?>" class="card h-100 border-0 shadow-sm landing-drill-card text-decoration-none" aria-label="Open notifications details"<?php echo $notificationsDrillDataAttrs; ?>>
                    <div class="card-body">
                        <div class="mb-3 text-primary"><i class="bi bi-chat-dots fs-3"></i></div>
                        <h5 class="card-title fw-semibold landing-feature-title color-cycle" style="color:#0ea5e9;">Smart Notifications</h5>
                        <p class="card-text small mb-0" style="color:#000;">
                            Bills, payment alerts and OTPs go out instantly over SMS and Email to keep
                            customers informed.
                        </p>
                        <p class="small mt-3 mb-0 fw-semibold text-primary"><?php echo htmlspecialchars($notificationsDrillLabel); ?> <i class="bi bi-arrow-right"></i></p>
                    </div>
                </a>
            </div>
            <div class="col-md-4">
                <a href="<?php echo htmlspecialchars($historyDrillUrl); ?>" class="card h-100 border-0 shadow-sm landing-drill-card text-decoration-none" aria-label="Open usage and history details"<?php echo $historyDrillDataAttrs; ?>>
                    <div class="card-body">
                        <div class="mb-3 text-primary"><i class="bi bi-bar-chart-line fs-3"></i></div>
                        <h5 class="card-title fw-semibold landing-feature-title color-cycle" style="color:#0ea5e9;">Usage & History</h5>
                        <p class="card-text small mb-0" style="color:#000;">
                            Customers and admins can track consumption, previous bills and payments from
                            a single dashboard.
                        </p>
                        <p class="small mt-3 mb-0 fw-semibold text-primary"><?php echo htmlspecialchars($historyDrillLabel); ?> <i class="bi bi-arrow-right"></i></p>
                    </div>
                </a>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
