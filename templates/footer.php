    </main>
    
    <?php
    // Load billing settings for footer contact details and quick registration info
    $footerSupportPhone = '+25472400202';
    $footerSupportEmail = 'support@bremac.co.ke';
    $footerRegistrationFee = 0.00;
    $footerCurrencyCode = 'KES';
    $footerEnforceLocationAccuracy = 0;
    $footerCountryCodeOptions = [
        ['value' => '254', 'label' => 'Kenya (+254)'],
        ['value' => '256', 'label' => 'Uganda (+256)'],
        ['value' => '255', 'label' => 'Tanzania (+255)'],
        ['value' => '1', 'label' => 'United States (+1)'],
        ['value' => '1', 'label' => 'Canada (+1)'],
        ['value' => '44', 'label' => 'United Kingdom (+44)']
    ];

    try {
        if (file_exists(__DIR__ . '/../config/database.php')) {
            require_once __DIR__ . '/../config/database.php';
            require_once __DIR__ . '/../includes/BillingSettings.php';
            require_once __DIR__ . '/../includes/CountryDialCode.php';
            require_once __DIR__ . '/../includes/SupportChat.php';
            if (class_exists('Database')) {
                $dbFooter = (new Database())->getConnection();
                if ($dbFooter) {
                    $bsFooter = new BillingSettings($dbFooter);
                    $footerSettings = $bsFooter->getSettings();
                    if (!empty($footerSettings['support_phone'])) {
                        $footerSupportPhone = $footerSettings['support_phone'];
                    }
                    if (!empty($footerSettings['support_email'])) {
                        $footerSupportEmail = $footerSettings['support_email'];
                    }
                    if (isset($footerSettings['registration_fee'])) {
                        $footerRegistrationFee = (float)$footerSettings['registration_fee'];
                    }
                    if (!empty($footerSettings['currency_code'])) {
                        $footerCurrencyCode = $footerSettings['currency_code'];
                    }
                    if (!empty($footerSettings['enforce_location_accuracy'])) {
                        $footerEnforceLocationAccuracy = 1;
                    }

                    $countryDialCodeService = new CountryDialCode($dbFooter);
                    $dbCountryCodeOptions = $countryDialCodeService->listActive();
                    if (!empty($dbCountryCodeOptions)) {
                        $footerCountryCodeOptions = $dbCountryCodeOptions;
                    }

                    $footerAvailabilityStatus = 'Checking support availability...';
                    $footerAvailabilityAgents = '';
                    try {
                        $footerSupportChat = new SupportChat($dbFooter);
                        $footerAvailableAgents = $footerSupportChat->getAvailableAgents();
                        if (!empty($footerAvailableAgents)) {
                            $footerAvailabilityStatus = 'Support team is online now';
                            $footerAvailabilityAgents = 'Available: ' . implode(', ', array_map(static function ($agent) {
                                $fullName = trim((string)($agent['full_name'] ?? ''));
                                return $fullName !== '' ? $fullName . ' (online)' : 'Support';
                            }, $footerAvailableAgents));
                        } else {
                            $footerRecentAgents = $footerSupportChat->getLastSeenAgents(5);
                            $footerAvailabilityStatus = 'Support team currently offline';
                            if (!empty($footerRecentAgents)) {
                                $footerAvailabilityAgents = 'Offline. ' . implode(', ', array_map(static function ($agent) {
                                    $fullName = trim((string)($agent['full_name'] ?? ''));
                                    $label = $fullName !== '' ? $fullName : 'Support';
                                    $updatedAt = trim((string)($agent['updated_at'] ?? ''));
                                    if ($updatedAt !== '') {
                                        $timestamp = strtotime($updatedAt);
                                        if ($timestamp) {
                                            $today    = strtotime('today');
                                            $yesterday = strtotime('yesterday');
                                            if ($timestamp >= $today) {
                                                $dateLabel = 'today';
                                            } elseif ($timestamp >= $yesterday) {
                                                $dateLabel = 'yesterday';
                                            } else {
                                                $dateLabel = date('D, M j', $timestamp);
                                            }
                                            return $label . ' (last seen ' . $dateLabel . ' at ' . date('H:i', $timestamp) . ')';
                                        }
                                    }
                                    return $label;
                                }, $footerRecentAgents));
                            } else {
                                $footerAvailabilityAgents = 'Leave a message via contact form; we will respond as soon as possible.';
                            }
                        }
                    } catch (\Throwable $e) {
                        $footerAvailabilityStatus = 'Checking support availability...';
                        $footerAvailabilityAgents = '';
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        // Fail silently; fall back to defaults
    }
    ?>

    <footer class="bg-dark text-white mt-5 py-4">
        <div class="container">
            <div class="row">
                <div class="col-md-4">
                    <h5 class="footer-title-accent"><i class="bi bi-droplet"></i> Water Billing System</h5>
                    <p class="footer-text-soft" style="color:#0ea5e9;">Efficient water bill management with M-Pesa integration.</p>
                </div>
                <div class="col-md-4">
                    <h5 class="footer-title-accent">Quick Links</h5>
                    <ul class="list-unstyled">
                        <?php if (!isset($_SESSION['user_id'])): ?>
                            <li><a href="/register" class="footer-quick-link" data-bs-toggle="modal" data-bs-target="#registerModal">Register</a></li>
                            <li><a href="/login" class="footer-quick-link" data-bs-toggle="modal" data-bs-target="#loginModal">Login</a></li>
                        <?php endif; ?>
                        <li><a href="/dashboard" class="footer-quick-link">Dashboard</a></li>
                    </ul>
                </div>
                <div class="col-md-4">
                    <h5 class="footer-title-accent">Contact</h5>
                    <p class="footer-text-soft">
                        <i class="bi bi-telephone"></i>
                        <a href="tel:<?php echo htmlspecialchars($footerSupportPhone); ?>" class="text-decoration-none footer-text-soft">
                            <?php echo htmlspecialchars($footerSupportPhone); ?>
                        </a>
                    </p>
                    <p class="footer-text-soft">
                        <i class="bi bi-envelope"></i>
                        <a href="mailto:<?php echo htmlspecialchars($footerSupportEmail); ?>" class="text-decoration-none footer-text-soft">
                            <?php echo htmlspecialchars($footerSupportEmail); ?>
                        </a>
                    </p>
                </div>
            </div>
            <hr class="bg-light">
            <div class="text-center">
                <p class="footer-text-soft">&copy; <?php echo date('Y'); ?> Water Billing System. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Global Login Modal -->
    <div class="modal fade" id="loginModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-box-arrow-in-right"></i> Login</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="landingLoginForm" novalidate>
                        <div id="landingCredentialsSection">
                            <div class="mb-3">
                                <label for="landing_identifier" class="form-label">Username, Account Number, Phone or Email *</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                                        <input type="text" class="form-control" id="landing_identifier" name="identifier" required
                                            placeholder="e.g. MTR0001, 07XXXXXXXX or name@example.com" autocomplete="username">
                                </div>
                                <div class="invalid-feedback">Please enter your username, account number, phone or email.</div>
                            </div>
                            <div class="mb-3">
                                <label for="landing_password" class="form-label">Password *</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="landing_password" name="password" autocomplete="current-password" required>
                                    <button class="btn btn-outline-secondary toggle-password" type="button" aria-label="Show or hide password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" id="landingShowPasswordToggle">
                                        <label class="form-check-label small" for="landingShowPasswordToggle">Show password</label>
                                    </div>
                                    <a href="/forgot-password" class="small">Forgot password?</a>
                                </div>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary" id="landingLoginBtn">
                                    <i class="bi bi-box-arrow-in-right"></i> Login
                                </button>
                                <a href="#" id="openFullRegistrationFromLogin" class="btn btn-link">Need an account? Open full registration</a>
                            </div>
                        </div>

                        <div id="landingTwoFactorSection" class="d-none">
                            <h6 class="mb-2"><i class="bi bi-shield-lock"></i> Enter verification code</h6>
                            <p class="small text-muted mb-1" id="landingTwoFactorMessage">We sent a code to your phone. Enter it below to finish logging in.</p>
                            <p class="small text-muted mb-3">
                                Didn't receive the code?
                                <span><a href="#" id="landingTwoFactorUsePhone">Use phone</a></span>
                                <span class="ms-2"><a href="#" id="landingTwoFactorUseEmail">Use email</a></span>
                                <span class="ms-2 text-primary fw-semibold d-none" id="landingTwoFactorCountdown"></span>
                            </p>
                            <div class="mb-3">
                                <label for="landing_two_factor_code" class="form-label">6-digit code</label>
                                <input type="text" class="form-control" id="landing_two_factor_code" placeholder="6-digit code" autocomplete="one-time-code">
                            </div>
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-primary" id="landingVerify2faBtn">Verify and Login</button>
                                <button type="button" class="btn btn-link" id="landingBackToCredentialsBtn">Back to login details</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Global Registration Modal (quick access) -->
        <div class="modal fade" id="registerModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus"></i> Create Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="small text-muted mb-3" id="landingRegisterIntro">
                        You can register directly from any page. For a larger view,
                        you can also use the full registration page from the menu.
                    </div>
                    <div id="landingRegisterMessage" class="alert d-none"></div>

                    <!-- M-Pesa payment waiting/countdown panel (hidden until STK is sent) -->
                    <div id="landingPaymentWaiting" class="reg-payment-waiting d-none">
                        <div class="reg-countdown-ring">
                            <svg viewBox="0 0 90 90">
                                <circle class="ring-bg" cx="45" cy="45" r="38"/>
                                <circle class="ring-arc" id="landingRingArc" cx="45" cy="45" r="38"/>
                            </svg>
                            <span class="reg-countdown-number" id="landingCountdownNum">59</span>
                        </div>
                        <h6 class="mb-1" id="landingCdTitle">Waiting for M-Pesa Payment</h6>
                        <p class="reg-payment-status-text" id="landingCdStatus">Check your phone and approve the M-Pesa prompt to activate your account.</p>
                        <div class="reg-payment-actions d-none" id="landingPaymentActions">
                            <button type="button" class="btn btn-primary btn-sm me-2" id="landingRetryPayBtn">
                                <i class="bi bi-arrow-repeat"></i> Resend M-Pesa Prompt
                            </button>
                            <a href="/login" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-box-arrow-in-right"></i> Login to Pay
                            </a>
                        </div>
                    </div>

                    <form id="landingRegisterForm" novalidate>
                        <div class="mb-3">
                            <label for="landing_registration_type" class="form-label">Register As *</label>
                            <select class="form-select" id="landing_registration_type" required>
                                <option value="client" selected>Client</option>
                                <option value="staff">Office Staff</option>
                            </select>
                            <div class="form-text">Clients include billing and meter setup. Office staff accounts are created without meter numbers and require a unique username.</div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="landing_first_name" class="form-label">First Name *</label>
                                <input type="text" class="form-control" id="landing_first_name" autocomplete="given-name" required>
                                <div class="invalid-feedback">Please enter your first name.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="landing_middle_name" class="form-label">Middle Name</label>
                                <input type="text" class="form-control" id="landing_middle_name" autocomplete="additional-name">
                            </div>
                            <div class="col-md-6">
                                <label for="landing_last_name" class="form-label">Last Name *</label>
                                <input type="text" class="form-control" id="landing_last_name" autocomplete="family-name" required>
                                <div class="invalid-feedback">Please enter your last name.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="landing_phone_number_local" class="form-label">Phone Number *</label>
                                <div class="input-group">
                                    <span class="input-group-text">+</span>
                                    <select class="form-select" id="landing_phone_country_code" style="max-width: 190px;" required>
                                        <?php foreach ($footerCountryCodeOptions as $option): ?>
                                            <?php $code = (string)($option['value'] ?? ''); ?>
                                            <?php $label = (string)($option['label'] ?? ''); ?>
                                            <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $code === '254' ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="tel" class="form-control" id="landing_phone_number_local" placeholder="e.g. 712345678" autocomplete="tel-national" inputmode="numeric" required>
                                </div>
                                <div class="invalid-feedback">Please choose country code and enter a valid phone number.</div>
                            </div>
                            <div class="col-md-6 landing-staff-only-field d-none">
                                <label for="landing_username" class="form-label">Username *</label>
                                <input type="text" class="form-control" id="landing_username" minlength="3" maxlength="30" pattern="^[A-Za-z0-9._-]{3,30}$" autocomplete="username">
                                <div class="form-text">Used to login for office staff accounts.</div>
                                <div class="invalid-feedback">Please enter a valid username (3-30 characters: letters, numbers, dot, underscore, hyphen).</div>
                            </div>
                            <div class="col-md-6 landing-client-only-field">
                                <label for="landing_email" class="form-label">Email Address *</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                    <input type="email" class="form-control" id="landing_email" autocomplete="email">
                                </div>
                                <div class="invalid-feedback">Please enter a valid email address.</div>
                            </div>
                            <div class="col-md-6 landing-client-only-field">
                                <label for="landing_id_number" class="form-label">ID Number *</label>
                                <input type="text" class="form-control" id="landing_id_number" autocomplete="off" required>
                                <div class="invalid-feedback">Please enter your ID number.</div>
                            </div>
                            <div class="col-md-6 landing-client-only-field">
                                <label for="landing_tax_pin" class="form-label">PIN / Tax ID (optional)</label>
                                <input type="text" class="form-control" id="landing_tax_pin" placeholder="e.g. P012345678Z" autocomplete="off">
                            </div>
                            <div class="col-12 landing-client-only-field">
                                <label for="landing_address" class="form-label">Physical Address *</label>
                                <textarea class="form-control" id="landing_address" rows="2"></textarea>
                                <div class="invalid-feedback">Please enter your address.</div>
                            </div>
                            <div class="col-12 landing-client-only-field">
                                <label for="landing_location_label" class="form-label" id="landing_location_label_label">Location (optional)</label>
                                <input type="text" class="form-control location-autocomplete" id="landing_location_label" placeholder="e.g. P5PP+CJ, Nguluni" autocomplete="off">
                                <div class="invalid-feedback">Please enter your location.</div>
                                <div class="form-text" id="landing_location_label_help">Optional short location such as Plus Code or estate name (e.g. "P5PP+CJ, Nguluni").</div>
                                <div class="mt-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm" id="landingUseGpsBtn">
                                        <i class="bi bi-geo-alt"></i> Use my current GPS location
                                    </button>
                                </div>
                                <input type="hidden" id="landing_latitude">
                                <input type="hidden" id="landing_longitude">
                                <input type="hidden" id="landing_gps_accuracy" value="">
                                <div id="landing_gps_accuracy_feedback" class="form-text d-none"></div>
                            </div>
                            <div class="col-md-6 landing-client-only-field">
                                <label for="landing_connection_type" class="form-label">Connection Type *</label>
                                <select class="form-select" id="landing_connection_type">
                                    <option value="">Select type</option>
                                    <option value="domestic">Domestic</option>
                                    <option value="commercial">Commercial</option>
                                    <option value="industrial">Industrial</option>
                                </select>
                                <div class="invalid-feedback">Please select connection type.</div>
                            </div>
                            <div class="col-md-6 landing-client-only-field">
                                <label for="landing_register_password" class="form-label">Password *</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="landing_register_password" autocomplete="new-password">
                                    <button class="btn btn-outline-secondary toggle-password" type="button" aria-label="Show or hide password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" id="landingRegisterShowPassword">
                                    <label class="form-check-label small" for="landingRegisterShowPassword">Show password</label>
                                </div>
                            </div>
                            <div class="col-md-6 landing-client-only-field">
                                <label for="landing_confirm_password" class="form-label">Confirm Password *</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="landing_confirm_password" autocomplete="new-password">
                                    <button class="btn btn-outline-secondary toggle-password" type="button" aria-label="Show or hide password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" id="landingRegisterShowConfirmPassword">
                                    <label class="form-check-label small" for="landingRegisterShowConfirmPassword">Show confirm password</label>
                                </div>
                                <div class="invalid-feedback">Please confirm your password.</div>
                            </div>
                            <div class="col-12">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="landing_terms" required>
                                    <label class="form-check-label" for="landing_terms">
                                        I agree to the <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms and Conditions</a>
                                    </label>
                                    <div class="invalid-feedback">You must agree to the terms and conditions.</div>
                                </div>
                                <?php if (!empty($footerRegistrationFee) && $footerRegistrationFee > 0): ?>
                                <div class="alert alert-info py-2 mb-2 landing-client-only-field">
                                    <small>
                                        A one-time non-refundable installation/registration fee of
                                        <strong><?php echo htmlspecialchars($footerCurrencyCode); ?>
                                        <?php echo number_format($footerRegistrationFee, 2); ?></strong>
                                        will be charged via M-Pesa STK push when you submit this form.
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-grid gap-2 mt-3">
                            <button type="submit" class="btn btn-primary" id="landingRegisterBtn">
                                <i class="bi bi-person-plus"></i> Create Account
                            </button>
                            <a href="/register" class="btn btn-link">Open full registration page</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Global Terms & Conditions Modal (used by all registration forms) -->
    <div class="modal fade" id="termsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Terms and Conditions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p><strong>Community Water Supply Connection – BREMAC CONSULTANT LIMITED</strong></p>

                    <h6>1. Registration and Membership</h6>
                    <p>
                        All individuals wishing to receive a household water connection must first register through the
                        official registration platform
                        <a href="https://wbs.bremac.co.ke/" target="_blank" rel="noopener noreferrer">BreMac Water Supply</a>.
                        Registration requires accurate personal details including the applicant’s full name, phone number,
                        and location.
                    </p>

                    <h6>2. Installation Fee</h6>
                    <p>
                        A one-time non-refundable installation fee of
                        <?php echo htmlspecialchars($footerCurrencyCode); ?>
                        <?php echo number_format($footerRegistrationFee, 2); ?>
                        is required for each household connection. This fee covers planning, materials, labor,
                        and connection to the main distribution line.
                    </p>

                    <h6>3. Payment Method</h6>
                    <p>
                        The preferred payment method is through the online registration system where applicants will
                        receive a prompt to complete payment.
                    </p>
                    <p>
                        <strong>Alternative payment (for members unable to access the online platform):</strong><br>
                        M-Pesa Paybill Number: 4166503<br>
                        Business Name: BREMAC CONSULTANT LIMITED<br>
                        Account Number: Registered Customer Account Number
                    </p>
                    <p>
                        Applicants must retain the M-Pesa confirmation message as proof of payment.
                    </p>

                    <h6>4. Installation Schedule</h6>
                    <p>
                        Installation of household connections will begin as soon as site conditions are suitable for
                        safe trenching and pipe laying. Scheduling may be adjusted due to factors such as adverse
                        weather, very hard ground, or other conditions that make excavation unsafe or impractical.
                    </p>

                    <h6>5. Connection Approval</h6>
                    <p>
                        A connection will only be scheduled after:
                    </p>
                    <ul>
                        <li>Successful registration</li>
                        <li>Full payment of the installation fee</li>
                        <li>Verification of payment by the project administrators</li>
                    </ul>

                    <h6>6. Account Creation</h6>
                    <p>
                        Upon successful payment, a customer account will be created. Account details and confirmation
                        will be sent to the registered phone number via SMS from the Sender ID: <strong>BREMAC LTD</strong>.
                    </p>

                    <h6>7. Water Usage Charges</h6>
                    <p>
                        Water usage charges, tariffs, and billing procedures will be communicated to members separately
                        once the supply system becomes fully operational.
                    </p>

                    <h6>8. Access for Installation</h6>
                    <p>
                        Members must allow reasonable access to their property for trenching, pipe installation, meter
                        installation, and maintenance work.
                    </p>

                    <h6>9. Responsibility for Internal Plumbing</h6>
                    <p>
                        The project installation covers connection from the main distribution line to the designated
                        connection point. Any internal plumbing within the property is the responsibility of the property
                        owner.
                    </p>

                    <h6>10. Damage or Interference</h6>
                    <p>
                        Tampering with pipelines, meters, valves, or any part of the water infrastructure is strictly
                        prohibited. Any damage caused intentionally or through negligence will be repaired at the
                        responsible member’s cost.
                    </p>

                    <h6>11. Service Interruptions</h6>
                    <p>
                        While every effort will be made to ensure a reliable water supply, the project management shall
                        not be liable for temporary service interruptions caused by maintenance, repairs, weather
                        conditions, or other unforeseen circumstances.
                    </p>

                    <h6>12. Refund Policy</h6>
                    <p>
                        The installation fee is non-refundable once registration and payment have been confirmed and
                        planning or procurement processes have commenced.
                    </p>

                    <h6>13. Changes to Terms</h6>
                    <p>
                        BREMAC CONSULTANT LIMITED reserves the right to update or modify these terms and conditions when
                        necessary. Members will be notified of any significant changes.
                    </p>

                    <h6>14. Compliance</h6>
                    <p>
                        All registered members agree to abide by these terms and conditions as part of participating in
                        the community water supply project.
                    </p>

                    <hr>

                    <h6>Community Water Supply Rules</h6>
                    <p><strong>BREMAC CONSULTANT LIMITED</strong></p>
                    <p>
                        To ensure fair access, sustainability, and proper management of the community water supply
                        system, all members are required to observe the following rules:
                    </p>

                    <h6>1. Registered Members Only</h6>
                    <p>
                        Only individuals who have completed registration and paid the required installation fee are
                        eligible for a household water connection.
                    </p>

                    <h6>2. Authorized Connections</h6>
                    <p>
                        All water connections must be installed <strong>only by authorized technicians</strong>
                        appointed by BREMAC CONSULTANT LIMITED. Members are not allowed to install or modify
                        connections themselves.
                    </p>

                    <h6>3. Prohibition of Illegal Connections</h6>
                    <p>
                        Unauthorized tapping into the main pipeline, bypassing meters, or sharing connections without
                        approval is strictly prohibited. Any illegal connection will lead to immediate disconnection
                        and penalties.
                    </p>

                    <h6>4. Protection of Water Infrastructure</h6>
                    <p>
                        Members must help protect the water infrastructure including pipelines, valves, meters, and
                        fittings. Any damage caused intentionally or through negligence must be repaired at the
                        responsible person’s cost.
                    </p>

                    <h6>5. Water Meter Integrity</h6>
                    <p>
                        Water meters must not be tampered with, altered, bypassed, or interfered with in any way.
                        Tampering may result in disconnection, penalties, and possible termination of service.
                    </p>

                    <h6>6. Timely Payment of Bills</h6>
                    <p>
                        All members must settle their water usage bills within the stipulated payment period.
                        Persistent non-payment may result in temporary suspension of water supply until outstanding
                        balances are cleared.
                    </p>

                    <h6>7. Access for Maintenance</h6>
                    <p>
                        Authorized personnel may need access to properties for meter reading, maintenance, inspection,
                        or repair. Members must cooperate and allow reasonable access when required.
                    </p>

                    <h6>8. Responsible Water Use</h6>
                    <p>
                        Members are encouraged to use water responsibly and avoid wastage. Water should not be used
                        for activities that may strain the supply system or reduce availability for other members.
                    </p>

                    <h6>9. Leak Reporting</h6>
                    <p>
                        Members should promptly report any leaks, pipe bursts, or system faults to help prevent water
                        loss and infrastructure damage.
                    </p>

                    <h6>10. Connection Transfer</h6>
                    <p>
                        Water connections are linked to the registered property and member. Any transfer, relocation,
                        or change of ownership must be communicated to the project administration for proper records
                        update.
                    </p>

                    <h6>11. Dispute Resolution</h6>
                    <p>
                        Any concerns or disputes related to billing, connections, or services should be reported to
                        the project administration team for review and resolution.
                    </p>

                    <h6>12. Compliance with Rules</h6>
                    <p>
                        Failure to comply with these community rules may lead to penalties, suspension of service, or
                        disconnection from the water supply system.
                    </p>

                    <h6>13. Community Cooperation</h6>
                    <p>
                        The success of the water project depends on cooperation among all members. Every member is
                        encouraged to support the proper use, protection, and sustainability of the water system.
                    </p>

                    <p>
                        For inquiries or assistance, members may contact the project administration team.<br>
                        <strong>BREMAC CONSULTANT LIMITED</strong>
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="termsModalCloseButton">Close</button>
                    <button type="button" class="btn btn-primary d-none" id="termsAcceptButton">I Agree</button>
                </div>
            </div>
        </div>
    </div>

    <?php
    // Prepare WhatsApp chat number from support phone (digits only for wa.me)
    $whatsappNumber = preg_replace('/\D+/', '', $footerSupportPhone);
    // Fallback to default support number if settings are missing or malformed
    if ($whatsappNumber === '') {
        $whatsappNumber = '25472400202';
    }
    $whatsappGroupUrl = 'https://chat.whatsapp.com/JB5hBksMTuQ9ZNgQ8PeXDO?mode=hqctcla';
    ?>

    <?php if (!empty($whatsappNumber)): ?>
        <!-- (Private chat now offered inside Contact modal) -->
    <?php endif; ?>

    <!-- Floating WhatsApp group button (join group link) -->
    <a href="<?php echo htmlspecialchars($whatsappGroupUrl, ENT_QUOTES, 'UTF-8'); ?>"
       target="_blank" rel="noopener"
       class="whatsapp-group-float"
       aria-label="Join WhatsApp Group"
       title="WhatsApp Group: Water Billing System (click to join)"
       style="position:fixed;bottom:96px;right:32px;z-index:9999;">
        <i class="bi bi-people-fill" style="font-size: 1.4rem;"></i>
    </a>

    <!-- Floating Contact button (opens contact form modal) -->
    <button type="button"
            class="btn contact-float"
            title="Contact support via form"
            aria-label="Contact support form"
            data-bs-toggle="modal"
            data-bs-target="#contactModal">
        <i class="bi bi-envelope" style="font-size: 1.4rem;"></i>
    </button>

    <div class="support-availability-badge is-offline" id="supportAvailabilityBadge" aria-live="polite">
        <div class="support-availability-title" id="supportAvailabilityStatus"><?php echo htmlspecialchars($footerAvailabilityStatus ?? 'Checking support availability...'); ?></div>
        <div class="support-availability-agents" id="supportAvailabilityAgents"><?php echo htmlspecialchars($footerAvailabilityAgents ?? ''); ?></div>
    </div>

    <?php $isFooterChatAuthenticated = isset($_SESSION['user_id']); ?>

    <!-- Floating Live Chat toggle (available to visitors and logged-in users) -->
    <button type="button"
            class="btn support-chat-toggle"
            id="supportChatToggle"
            data-authenticated="<?php echo $isFooterChatAuthenticated ? '1' : '0'; ?>"
            title="Contact support"
            aria-label="Contact support"
            style="position:fixed;right:32px;bottom:224px;z-index:9999;">
        <i class="bi bi-chat-dots" style="font-size: 1.3rem;"></i>
    </button>

    <div class="support-chat-window" id="supportChatWindow" aria-live="polite" aria-label="Support chat window"
        style="position:fixed;right:24px;bottom:290px;z-index:9999;display:none;">
        <div class="support-chat-header">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-headset"></i>
                <div>
                    <div class="small fw-semibold">Support Chat</div>
                    <div class="small" style="font-size: 0.75rem; opacity: 0.85;">We usually reply in a few minutes</div>
                    <div class="small" id="supportChatAvailabilityLabel" style="font-size: 0.72rem; opacity: 0.95;"></div>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-light" id="supportChatClose" aria-label="Close chat">
                <i class="bi bi-x"></i>
            </button>
        </div>
        <div class="support-chat-body" id="supportChatMessages"></div>
        <div class="support-chat-input">
            <div class="support-chat-typing" id="supportChatTypingIndicator" style="display:none;">
                <small><i class="bi bi-three-dots"></i> Support is typing...</small>
            </div>
            <form id="supportChatForm" class="d-flex align-items-center gap-2">
                <input type="text" class="form-control form-control-sm" id="supportChatMessageInput" placeholder="Type your message..." autocomplete="off">
                <button type="submit" class="btn btn-primary btn-sm" id="supportChatSendBtn">
                    <i class="bi bi-send"></i>
                </button>
            </form>
        </div>
    </div>

    <?php if (!$isFooterChatAuthenticated): ?>
        <div class="support-chat-window" id="supportGuestInquiryWindow" aria-live="polite" aria-label="Support inquiry window"
            style="position:fixed;right:24px;bottom:290px;z-index:9999;display:none;">
            <div class="support-chat-header">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-chat-square-text"></i>
                    <div>
                        <div class="small fw-semibold">Quick Inquiry</div>
                        <div class="small" style="font-size: 0.75rem; opacity: 0.85;">Ask support a question without logging in</div>
                        <div class="small" id="supportGuestAvailabilityLabel" style="font-size: 0.72rem; opacity: 0.95;"></div>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-light" id="supportGuestInquiryClose" aria-label="Close inquiry panel">
                    <i class="bi bi-x"></i>
                </button>
            </div>
            <div class="support-chat-input" style="padding: 0.85rem;">
                <form id="supportGuestInquiryForm" novalidate>
                    <div class="mb-2">
                        <label for="guestInquiryName" class="form-label small mb-1">Name *</label>
                        <input type="text" class="form-control form-control-sm" id="guestInquiryName" name="name" required autocomplete="name">
                    </div>
                    <div class="mb-2">
                        <label for="guestInquiryEmail" class="form-label small mb-1">Email *</label>
                        <input type="email" class="form-control form-control-sm" id="guestInquiryEmail" name="email" required autocomplete="email">
                    </div>
                    <div class="mb-2">
                        <label for="guestInquiryPhone" class="form-label small mb-1">Phone (optional)</label>
                        <input type="tel" class="form-control form-control-sm" id="guestInquiryPhone" name="phone" autocomplete="tel">
                    </div>
                    <div class="mb-2">
                        <label for="guestInquiryMessage" class="form-label small mb-1">Inquiry *</label>
                        <textarea class="form-control form-control-sm" id="guestInquiryMessage" name="message" rows="3" required></textarea>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-sm" id="supportGuestInquirySendBtn">
                            <i class="bi bi-send"></i> Send Inquiry
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Contact Form Modal -->
    <div class="modal fade" id="contactModal" tabindex="-1" aria-labelledby="contactModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="contactModalLabel"><i class="bi bi-chat-text"></i> Contact Support</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body contact-modal-body">
                    <div class="contact-support-intro mb-3">
                        <div class="contact-support-intro-copy">
                            <span class="contact-support-kicker">Support desk</span>
                            <p class="small text-muted mb-2">Choose how you would like to reach support:</p>
                        </div>
                        <?php if (!empty($whatsappNumber)): ?>
                            <a href="https://wa.me/<?php echo htmlspecialchars($whatsappNumber, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"
                               class="btn btn-success w-100 contact-support-whatsapp">
                                <i class="bi bi-whatsapp"></i> Private WhatsApp chat
                            </a>
                            <div class="contact-support-divider"><span>or send us a message using the form below</span></div>
                        <?php else: ?>
                            <p class="small text-muted mb-0">Send us a message using the form below.</p>
                        <?php endif; ?>
                    </div>
                    <form id="contactForm" class="contact-support-form" novalidate>
                        <div class="contact-support-grid">
                            <div class="mb-3 contact-support-field">
                                <label for="contact_name" class="form-label">Your Name *</label>
                                <input type="text" class="form-control" id="contact_name" name="name" autocomplete="name" required>
                                <div class="invalid-feedback">Please enter your name.</div>
                            </div>
                            <div class="mb-3 contact-support-field">
                                <label for="contact_email" class="form-label">Email Address *</label>
                                <input type="email" class="form-control" id="contact_email" name="email" autocomplete="email" required>
                                <div class="invalid-feedback">Please enter a valid email address.</div>
                            </div>
                        </div>
                        <div class="mb-3 contact-support-field">
                            <label for="contact_phone" class="form-label">Phone (optional)</label>
                            <input type="tel" class="form-control" id="contact_phone" name="phone" placeholder="2547XXXXXXXX" autocomplete="tel">
                        </div>
                        <div class="mb-3 contact-support-field">
                            <label for="contact_message" class="form-label">Message *</label>
                            <textarea class="form-control" id="contact_message" name="message" rows="4" required></textarea>
                            <div class="invalid-feedback">Please enter your message.</div>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary contact-support-submit" id="contactSubmitBtn">
                                <i class="bi bi-send"></i> Send Message
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <button type="button"
            class="btn btn-sm btn-outline-secondary system-density-toggle"
            data-density-toggle
            data-density-target="body"
            data-density-key="global-system"
            data-density-auto-enabled="1"
            data-density-default="auto"
            data-density-auto-text="Auto Mode"
            data-density-compact-text="Compact Mode"
            data-density-comfy-text="Comfortable Mode"
            aria-label="Toggle compact mode for the whole system"
            title="Toggle compact mode for the whole system">
        <i class="bi bi-layout-text-window-reverse"></i>
        <span class="js-density-label">Auto Mode</span>
    </button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php if (isset($page_title) && $page_title === 'Dashboard'): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <?php endif; ?>
    <script>
        window.CURRENT_USER_ID = <?php echo isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 'null'; ?>;
        window.ENFORCE_LOCATION_ACCURACY = <?php echo (int)$footerEnforceLocationAccuracy; ?>;
    </script>
    <?php $scriptVersion = @filemtime(__DIR__ . '/../public/js/script.js') ?: time(); ?>
    <script src="/public/js/script.js?v=<?php echo (int)$scriptVersion; ?>"></script>

    <script>
    // Navbar hover-dropdowns: open on mouseenter, close on mouseleave
    (function () {
        function initNavHoverDropdowns() {
            document.querySelectorAll('.navbar .nav-item.dropdown').forEach(function (li) {
                var menu = li.querySelector('.dropdown-menu');
                var toggle = li.querySelector('.dropdown-toggle');
                if (!menu) return;

                li.addEventListener('mouseenter', function () {
                    // Close any other open menus first
                    document.querySelectorAll('.navbar .nav-item.dropdown').forEach(function (other) {
                        if (other !== li) {
                            var otherMenu = other.querySelector('.dropdown-menu');
                            var otherToggle = other.querySelector('.dropdown-toggle');
                            if (otherMenu) { otherMenu.classList.remove('show'); otherMenu.setAttribute('data-bs-popper', ''); }
                            if (otherToggle) otherToggle.setAttribute('aria-expanded', 'false');
                            other.classList.remove('show');
                        }
                    });
                    li.classList.add('show');
                    menu.classList.add('show');
                    if (toggle) toggle.setAttribute('aria-expanded', 'true');
                });

                li.addEventListener('mouseleave', function () {
                    li.classList.remove('show');
                    menu.classList.remove('show');
                    if (toggle) toggle.setAttribute('aria-expanded', 'false');
                });
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initNavHoverDropdowns);
        } else {
            initNavHoverDropdowns();
        }
    })();
    </script>

    <script>
    // Simple location autocomplete using Nominatim (OpenStreetMap)
    // Helps customers pick a clear text location while admins still control the actual GPS pin.
    function setupLocationAutocomplete() {
        if (typeof $ === 'undefined') {
            return;
        }

        $('.location-autocomplete').each(function() {
            var $input = $(this);
            var typingTimer = null;
            var lastQuery = '';
            var suggestionMouseDown = false;

            // Create suggestions container just after the input
            var $suggestions = $('<div class="list-group location-suggestions mt-1"></div>').hide();
            $input.after($suggestions);

            $suggestions.on('mousedown', function() {
                suggestionMouseDown = true;
            });
            $suggestions.on('mouseup', function() {
                suggestionMouseDown = false;
            });

            $input.on('input', function() {
                var query = $input.val().trim();

                if (typingTimer) {
                    clearTimeout(typingTimer);
                }

                if (query.length < 3) {
                    $suggestions.empty().hide();
                    return;
                }

                // Avoid spamming the API with the same query
                if (query === lastQuery) {
                    return;
                }
                lastQuery = query;

                typingTimer = setTimeout(function() {
                    var url = 'https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=5&q=' +
                              encodeURIComponent(query + ', Kenya');

                    fetch(url, {
                        headers: {
                            'Accept': 'application/json'
                        }
                    })
                        .then(function(response) { return response.json(); })
                        .then(function(results) {
                            $suggestions.empty();

                            if (!Array.isArray(results) || results.length === 0) {
                                $suggestions.hide();
                                return;
                            }

                            results.forEach(function(item) {
                                var label = item.display_name || '';
                                if (!label) return;

                                var $item = $('<button type="button" class="list-group-item list-group-item-action small"></button>');
                                $item.text(label);
                                $item.on('click', function(e) {
                                    e.preventDefault();
                                    $input.val(label);
                                    $suggestions.empty().hide();
                                });
                                $suggestions.append($item);
                            });

                            $suggestions.show();
                        })
                        .catch(function() {
                            $suggestions.empty().hide();
                        });
                }, 400);
            });

            // Hide suggestions on blur, unless a suggestion click is in progress.
            $input.on('blur', function() {
                if (suggestionMouseDown) return;
                requestAnimationFrame(function() {
                    $suggestions.hide();
                });
            });
        });
    }

    window.showToast = function(message, type) {
        var toastEl = document.getElementById('globalToast');
        var bodyEl = document.getElementById('globalToastBody');
        if (!toastEl || !bodyEl) return;

        var classes = ['bg-success', 'bg-danger', 'bg-warning', 'bg-info', 'bg-primary'];
        classes.forEach(function(c) { toastEl.classList.remove(c); });

        switch (type) {
            case 'danger':
            case 'error':
                toastEl.classList.add('bg-danger');
                break;
            case 'warning':
                toastEl.classList.add('bg-warning');
                break;
            case 'info':
                toastEl.classList.add('bg-info');
                break;
            default:
                toastEl.classList.add('bg-success');
        }

        bodyEl.textContent = message;
        var toast = new bootstrap.Toast(toastEl, { delay: 4000 });
        toast.show();
    };

    // Sweet confirmation helper using Bootstrap modal instead of browser confirm()
    window.confirmToast = function(message, options) {
        return new Promise(function(resolve) {
            var modalEl = document.getElementById('confirmModal');
            var msgEl = document.getElementById('confirmModalMessage');
            var confirmBtn = document.getElementById('confirmModalConfirm');
            if (!modalEl || !msgEl || !confirmBtn || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                var ok = window.confirm(message || 'Are you sure?');
                resolve(!!ok);
                return;
            }

            msgEl.textContent = message || 'Are you sure?';
            var modal = bootstrap.Modal.getOrCreateInstance(modalEl);

            var handled = false;
            var onHide = function() {
                if (!handled) {
                    resolve(false);
                    handled = true;
                }
                modalEl.removeEventListener('hidden.bs.modal', onHide);
                confirmBtn.removeEventListener('click', onConfirm);
            };

            var onConfirm = function() {
                handled = true;
                resolve(true);
                modal.hide();
            };

            modalEl.addEventListener('hidden.bs.modal', onHide);
            confirmBtn.addEventListener('click', onConfirm);

            modal.show();
        });
    };

    // Attach confirmToast to any form with data-confirm-message attribute
    document.addEventListener('DOMContentLoaded', function() {
        var forms = document.querySelectorAll('form[data-confirm-message]');
        forms.forEach(function(form) {
            form.addEventListener('submit', function(e) {
                var msg = form.getAttribute('data-confirm-message') || 'Are you sure?';
                if (!window.confirmToast) {
                    // Fallback to native confirm
                    if (!window.confirm(msg)) {
                        e.preventDefault();
                    }
                    return;
                }

                e.preventDefault();
                window.confirmToast(msg, { type: 'warning' }).then(function(confirmed) {
                    if (confirmed) {
                        form.submit();
                    }
                });
            });
        });
    });

    // Enforce Terms & Conditions on first visit
    document.addEventListener('DOMContentLoaded', function() {
        try {
            function hasAcceptedTerms() {
                return document.cookie.split(';').some(function(part) {
                    return part.trim().indexOf('terms_accepted=1') === 0;
                });
            }

            function markTermsAccepted() {
                var oneYear = 365 * 24 * 60 * 60;
                document.cookie = 'terms_accepted=1; path=/; max-age=' + oneYear;
            }

            if (hasAcceptedTerms()) {
                return;
            }

            if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                return;
            }

            var modalEl = document.getElementById('termsModal');
            if (!modalEl) {
                return;
            }

            var headerClose = modalEl.querySelector('.btn-close');
            var footerClose = document.getElementById('termsModalCloseButton');
            var acceptBtn = document.getElementById('termsAcceptButton');
            if (!acceptBtn) {
                return;
            }

            // Hide normal close controls so user must either agree or close the page
            if (headerClose) {
                headerClose.style.display = 'none';
            }
            if (footerClose) {
                footerClose.classList.add('d-none');
            }
            acceptBtn.classList.remove('d-none');

            var modal = bootstrap.Modal.getOrCreateInstance(modalEl, {
                backdrop: 'static',
                keyboard: false
            });

            function onAccept() {
                markTermsAccepted();
                if (headerClose) {
                    headerClose.style.display = '';
                }
                if (footerClose) {
                    footerClose.classList.remove('d-none');
                }
                acceptBtn.classList.add('d-none');
                modal.hide();
                acceptBtn.removeEventListener('click', onAccept);
            }

            acceptBtn.addEventListener('click', onAccept);
            modal.show();
        } catch (e) {
            // Fail open if anything goes wrong
        }
    });

    // Global handlers for header/home login & registration modals
    $(document).ready(function() {
        function colorizeRequiredAsterisks() {
            var selectors = 'label, legend';
            document.querySelectorAll(selectors).forEach(function(el) {
                if (!el || !el.innerHTML) return;
                if (el.innerHTML.indexOf('*') === -1) return;
                if (el.innerHTML.indexOf('required-asterisk') !== -1) return;

                // Replace visual required markers with a styled span.
                el.innerHTML = el.innerHTML.replace(/\*/g, '<span class="required-asterisk" style="color:#dc3545 !important; font-weight:800 !important;">*</span>');
            });
        }

        colorizeRequiredAsterisks();
        setTimeout(colorizeRequiredAsterisks, 250);
        setTimeout(colorizeRequiredAsterisks, 1000);

        if (typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(function() {
                colorizeRequiredAsterisks();
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }

        // Initialize location autocomplete for any customer-facing location fields
        setupLocationAutocomplete();

        // Login modal form

        // Switch from login modal to full registration modal without leaving the page
        $('#openFullRegistrationFromLogin').on('click', function(e) {
            e.preventDefault();
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                var loginEl = document.getElementById('loginModal');
                var registerEl = document.getElementById('registerModal');
                if (loginEl && registerEl) {
                    var loginModal = bootstrap.Modal.getInstance(loginEl) || bootstrap.Modal.getOrCreateInstance(loginEl);
                    loginModal.hide();
                    var registerModal = bootstrap.Modal.getInstance(registerEl) || bootstrap.Modal.getOrCreateInstance(registerEl);
                    registerModal.show();
                }
            }
        });

        // Show/hide password in landing login modal via checkbox
        $('#landingShowPasswordToggle').on('change', function() {
            var $input = $('#landing_password');
            if (this.checked) {
                $input.attr('type', 'text');
            } else {
                $input.attr('type', 'password');
            }
        });

        // Show/hide password fields in landing quick registration modal
        $('#landingRegisterShowPassword').on('change', function() {
            var $input = $('#landing_register_password');
            if (this.checked) {
                $input.attr('type', 'text');
            } else {
                $input.attr('type', 'password');
            }
        });
        $('#landingRegisterShowConfirmPassword').on('change', function() {
            var $input = $('#landing_confirm_password');
            if (this.checked) {
                $input.attr('type', 'text');
            } else {
                $input.attr('type', 'password');
            }
        });

        function isLandingClientRegistration() {
            return $('#landing_registration_type').val() === 'client';
        }

        function isLandingStaffRegistration() {
            return $('#landing_registration_type').val() === 'staff';
        }

        function toggleLandingRegistrationModeUI() {
            var isClient = isLandingClientRegistration();
            var isStaff = isLandingStaffRegistration();
            var enforceLocation = !!window.ENFORCE_LOCATION_ACCURACY;
            $('.landing-client-only-field').toggleClass('d-none', !isClient);
            $('.landing-staff-only-field').toggleClass('d-none', !isStaff);

            $('#landing_email, #landing_id_number, #landing_address, #landing_connection_type, #landing_register_password, #landing_confirm_password')
                .prop('required', isClient);
            $('#landing_location_label').prop('required', isClient && enforceLocation);
            $('#landing_username').prop('required', isStaff);

            $('#landing_location_label_label').text(enforceLocation ? 'Location *' : 'Location (optional)');
            $('#landing_location_label_help').text(
                enforceLocation
                    ? 'Required short location such as Plus Code or estate name (e.g. "P5PP+CJ, Nguluni").'
                    : 'Optional short location such as Plus Code or estate name (e.g. "P5PP+CJ, Nguluni").'
            );

            if (!isClient) {
                $('#landing_email, #landing_id_number, #landing_address, #landing_location_label, #landing_connection_type, #landing_register_password, #landing_confirm_password')
                    .removeClass('is-invalid');
                $('#landing_register_password, #landing_confirm_password').val('');
            }
            if (!isStaff) {
                $('#landing_username').removeClass('is-invalid').val('');
            }
        }

        function clearLandingLocationInvalidState() {
            if ($.trim($('#landing_location_label').val()) !== '') {
                $('#landing_location_label').removeClass('is-invalid');
            }
        }

        $('#landing_registration_type').on('change', toggleLandingRegistrationModeUI);
        $('#landing_location_label').on('input change', clearLandingLocationInvalidState);
        toggleLandingRegistrationModeUI();

        function handleLandingPostLoginSuccess(response) {
            var requiresRegPayment = response.data && response.data.requires_registration_payment;
            var requiresPasswordChange = response.data && response.data.requires_password_change;

            if (requiresRegPayment) {
                if (window.showToast) {
                    showToast('Login successful. Please complete your registration payment.','info');
                }
                setTimeout(function() { window.location.href = '/registration-payment'; }, 1000);
                return;
            }

            if (requiresPasswordChange) {
                if (window.showToast) {
                    showToast('Login successful! Please change your password.','success');
                }
                setTimeout(function() { window.location.href = '/change-password'; }, 1000);
                return;
            }

            if (window.showToast) {
                showToast('Login successful! Redirecting...','success');
            }
            setTimeout(function() { window.location.href = '/dashboard'; }, 1000);
        }

        function showLandingTwoFactorPrompt(methodLabel) {
            var $credentials = $('#landingCredentialsSection');
            var $twofa = $('#landingTwoFactorSection');
            var $msg = $('#landingTwoFactorMessage');
            if (!$credentials.length || !$twofa.length) return;

            var where = methodLabel === 'email' ? 'email' : 'phone';
            $msg.text('We sent a code to your ' + where + '. Enter it below to finish logging in.');

            $credentials.addClass('d-none');
            $twofa.removeClass('d-none');
            $('#landing_two_factor_code').val('').focus();
        }

        var landingTwoFactorVerifying = false;
        var landingTwoFactorResendTimer = null;
        var landingTwoFactorResendRemaining = 0;

        function startLandingTwoFactorCooldown(seconds) {
            var $phoneLink = $('#landingTwoFactorUsePhone');
            var $emailLink = $('#landingTwoFactorUseEmail');
            var $countdown = $('#landingTwoFactorCountdown');
            if (!$countdown.length) return;

            if (landingTwoFactorResendTimer) {
                clearInterval(landingTwoFactorResendTimer);
            }

            landingTwoFactorResendRemaining = parseInt(seconds, 10) || 0;
            if (landingTwoFactorResendRemaining <= 0) {
                return;
            }

            if ($phoneLink.length) {
                $phoneLink.addClass('disabled').css('pointer-events', 'none');
            }
            if ($emailLink.length) {
                $emailLink.addClass('disabled').css('pointer-events', 'none');
            }

            $countdown.removeClass('d-none');
            $countdown.text('You can resend in ' + landingTwoFactorResendRemaining + 's');

            landingTwoFactorResendTimer = setInterval(function() {
                landingTwoFactorResendRemaining--;
                if (landingTwoFactorResendRemaining <= 0) {
                    clearInterval(landingTwoFactorResendTimer);
                    landingTwoFactorResendTimer = null;
                    $countdown.addClass('d-none').text('');
                    if ($phoneLink.length) {
                        $phoneLink.removeClass('disabled').css('pointer-events', '');
                    }
                    if ($emailLink.length) {
                        $emailLink.removeClass('disabled').css('pointer-events', '');
                    }
                } else {
                    $countdown.text('You can resend in ' + landingTwoFactorResendRemaining + 's');
                }
            }, 1000);
        }

        function setLandingTwoFactorResendBusy(isBusy) {
            var $phoneLink = $('#landingTwoFactorUsePhone');
            var $emailLink = $('#landingTwoFactorUseEmail');

            if ($phoneLink.length) {
                $phoneLink.toggleClass('disabled', isBusy).css('pointer-events', isBusy ? 'none' : '');
            }
            if ($emailLink.length) {
                $emailLink.toggleClass('disabled', isBusy).css('pointer-events', isBusy ? 'none' : '');
            }
        }

        function requestLandingTwoFactorResend(method) {
            if (landingTwoFactorResendRemaining > 0) {
                if (window.showToast) {
                    showToast('Please wait ' + landingTwoFactorResendRemaining + ' seconds before requesting another code.','warning');
                }
                return;
            }

            setLandingTwoFactorResendBusy(true);

            $.ajax({
                url: '/api/auth/resend_2fa',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ method: method }),
                success: function(resp) {
                    if (resp.status === 'success') {
                        var actualMethod = (resp.data && resp.data.method) ? resp.data.method : method;
                        var label = actualMethod === 'email' ? 'email' : 'phone';
                        if (window.showToast) {
                            showToast(resp.message || ('Verification code sent to your ' + label + '.'),'info');
                        }
                        showLandingTwoFactorPrompt(actualMethod === 'email' ? 'email' : 'phone');
                        startLandingTwoFactorCooldown(60);
                        if (actualMethod === 'sms' && window.startWebOtpListener) startWebOtpListener('#landing_two_factor_code');
                    } else if (resp.status === 'cooldown') {
                        var remaining = resp.data && resp.data.remaining ? resp.data.remaining : 0;
                        if (remaining > 0) {
                            startLandingTwoFactorCooldown(remaining);
                        }
                        if (window.showToast) {
                            showToast(resp.message || 'Please wait before requesting another code.','warning');
                        }
                    } else if (window.showToast) {
                        showToast(resp.message || 'Could not resend code.','danger');
                    }
                },
                error: function(xhr) {
                    const error = xhr.responseJSON ? xhr.responseJSON.message : 'Could not resend code.';
                    if (window.showToast) {
                        showToast(error,'danger');
                    }
                },
                complete: function() {
                    if (landingTwoFactorResendRemaining <= 0) {
                        setLandingTwoFactorResendBusy(false);
                    }
                }
            });
        }

        function submitLandingTwoFactorCode() {
            var code = $('#landing_two_factor_code').val().trim();
            if (!code) {
                if (window.showToast) {
                    showToast('Please enter the verification code.','danger');
                }
                return;
            }

            if (landingTwoFactorVerifying) {
                return;
            }
            landingTwoFactorVerifying = true;

            var $btn = $('#landingVerify2faBtn');
            if ($btn.length) {
                $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Verifying...');
            }

            $.ajax({
                url: '/api/auth/verify_2fa',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ code: code }),
                success: function(response) {
                    if (response.status === 'success') {
                        handleLandingPostLoginSuccess(response);
                    } else if (window.showToast) {
                        showToast(response.message || 'Verification failed','danger');
                    }
                },
                error: function(xhr) {
                    const error = xhr.responseJSON ? xhr.responseJSON.message : 'Verification failed';
                    if (window.showToast) {
                        showToast(error,'danger');
                    }
                },
                complete: function() {
                    if ($btn.length) {
                        $btn.prop('disabled', false).html('Verify and Login');
                    }
                    landingTwoFactorVerifying = false;
                }
            });
        }

        $(document).on('click', '#landingVerify2faBtn', function() {
            submitLandingTwoFactorCode();
        });

        $(document).on('click', '#landingBackToCredentialsBtn', function() {
            if (window.stopWebOtpListener) stopWebOtpListener();
            $('#landingTwoFactorSection').addClass('d-none');
            $('#landingCredentialsSection').removeClass('d-none');
        });

        // Auto-verify when 6-digit code is fully entered in modal
        $('#landing_two_factor_code').on('input', function() {
            var val = $(this).val().replace(/\D/g, '');
            $(this).val(val);
            if (val.length === 6) {
                submitLandingTwoFactorCode();
            }
        });

        $(document).on('click', '#landingTwoFactorUsePhone', function(e) {
            e.preventDefault();
            requestLandingTwoFactorResend('sms');
        });

        $(document).on('click', '#landingTwoFactorUseEmail', function(e) {
            e.preventDefault();
            requestLandingTwoFactorResend('email');
        });

        /* ── WebOTP: auto-fill SMS verification codes on phones/tablets —
             Uses the Web OTP API (OTPCredential) available on Android Chrome.
             iOS Safari uses autocomplete="one-time-code" which prompts the
             keyboard suggestion bar automatically — no JS needed there.
        ── */
        var _otpAbortController = null;

        window.startWebOtpListener = function(inputSelector) {
            if (!('OTPCredential' in window)) return;  // not supported
            // Abort previous listener before starting a new one
            if (_otpAbortController) {
                try { _otpAbortController.abort(); } catch (e) {}
            }
            _otpAbortController = new AbortController();
            navigator.credentials.get({
                otp: { transport: ['sms'] },
                signal: _otpAbortController.signal
            }).then(function (otp) {
                var input = document.querySelector(inputSelector);
                if (!input) return;
                input.value = otp.code;
                // Dispatch input event so the auto-verify handler fires
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }).catch(function () {
                // Silently ignore: user dismissed, timed out, or unsupported
            });
        };

        window.stopWebOtpListener = function() {
            if (_otpAbortController) {
                try { _otpAbortController.abort(); } catch (e) {}
                _otpAbortController = null;
            }
        };

        $('#landingLoginForm').on('submit', function(e) {
            e.preventDefault();

            if (!this.checkValidity()) {
                e.stopPropagation();
                $(this).addClass('was-validated');
                return;
            }

            const formData = {
                identifier: $('#landing_identifier').val(),
                password: $('#landing_password').val()
            };

            const loginBtn = $('#landingLoginBtn');
            loginBtn.prop('disabled', true);
            loginBtn.html('<span class="spinner-border spinner-border-sm"></span> Logging in...');

            $.ajax({
                url: '/api/auth/login',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(formData),
                success: function(response) {
                    if (response.status === 'two_factor_required') {
                        var method = (response.data && response.data.method) || 'phone';
                        var label = method === 'email' ? 'email' : 'phone';
                        if (window.showToast) {
                            showToast('Verification code sent to your ' + label + '.','info');
                        }
                        showLandingTwoFactorPrompt(label);
                        // Start initial resend cooldown
                        startLandingTwoFactorCooldown(60);
                        // WebOTP: auto-fill SMS code on phones/tablets
                        if (window.startWebOtpListener) startWebOtpListener('#landing_two_factor_code');
                    } else if (response.status === 'success') {
                        handleLandingPostLoginSuccess(response);
                    } else if (window.showToast) {
                        showToast(response.message || 'Login failed','danger');
                    }
                },
                error: function(xhr) {
                    const error = xhr.responseJSON ? xhr.responseJSON.message : 'Login failed';
                    if (window.showToast) {
                        showToast(error,'danger');
                    }
                },
                complete: function() {
                    loginBtn.prop('disabled', false);
                    loginBtn.html('<i class="bi bi-box-arrow-in-right"></i> Login');
                }
            });
        });

        function buildLandingNormalizedPhone() {
            var code = String($('#landing_phone_country_code').val() || '').replace(/\D/g, '');
            var local = String($('#landing_phone_number_local').val() || '').replace(/\D/g, '');
            if (code && local.indexOf(code) === 0 && local.length > code.length) {
                local = local.slice(code.length);
            }
            local = local.replace(/^0+/, '');
            if (!code || !local) {
                return '';
            }
            return code + local;
        }

        function detectLandingCurrentLocation() {
            var $btn = $('#landingUseGpsBtn');

            if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost') {
                if (window.showToast) {
                    showToast('GPS detection requires HTTPS. Open this page using https:// and try again.', 'danger');
                }
                return;
            }

            if (!navigator.geolocation) {
                if (window.showToast) {
                    showToast('Geolocation is not supported by this browser.', 'danger');
                }
                return;
            }

            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Detecting...');

            navigator.geolocation.getCurrentPosition(function(position) {
                var lat = position.coords.latitude;
                var lng = position.coords.longitude;
                var accuracy = typeof position.coords.accuracy === 'number' ? position.coords.accuracy : 999999;

                $('#landing_latitude').val(lat.toFixed(7));
                $('#landing_longitude').val(lng.toFixed(7));
                $('#landing_gps_accuracy').val(accuracy);
                var feedbackDiv = document.getElementById('landing_gps_accuracy_feedback');
                if (feedbackDiv) {
                    feedbackDiv.classList.remove('d-none', 'text-success', 'text-warning', 'text-danger');
                    if (accuracy <= 14) {
                        feedbackDiv.textContent = 'GPS accuracy: ~' + Math.round(accuracy) + 'm ✓ Good';
                        feedbackDiv.classList.add('text-success');
                    } else {
                        feedbackDiv.textContent = 'GPS accuracy: ~' + Math.round(accuracy) + 'm — move outside for better signal, then retry.';
                        feedbackDiv.classList.add('text-warning');
                    }
                }

                if (accuracy <= 14) {
                    if (window.showToast) {
                        showToast('GPS captured (accuracy ~' + Math.round(accuracy) + 'm).', 'success');
                    }
                } else if (accuracy <= 100) {
                    if (window.showToast) {
                        showToast('GPS captured (accuracy ~' + Math.round(accuracy) + 'm). Move outside for better accuracy.', 'warning');
                    }
                } else {
                    if (window.showToast) {
                        showToast('GPS captured but accuracy is very low (~' + Math.round(accuracy) + 'm). You may edit location manually.', 'warning');
                    }
                }

                // Light reverse-geocode hint for location label if empty
                if (!$('#landing_location_label').val()) {
                    var url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng);
                    fetch(url, { headers: { 'Accept-Language': 'en' } })
                        .then(function(resp) { return resp.json(); })
                        .then(function(data) {
                            if (!data) return;
                            var label = (data.address && (data.address.suburb || data.address.neighbourhood || data.address.village || data.address.town || data.address.city)) || data.display_name || '';
                            if (label) {
                                $('#landing_location_label').val(String(label).slice(0, 120));
                                clearLandingLocationInvalidState();
                            }
                        })
                        .catch(function() {
                            // Ignore reverse-geocode failures
                        });
                }

                $btn.prop('disabled', false).html('<i class="bi bi-geo-alt"></i> Use my current GPS location');
            }, function(error) {
                var msg = 'Unable to get location. Please allow location access in your browser.';
                if (error && typeof error.code !== 'undefined') {
                    if (error.code === 1) {
                        msg = 'Location access was denied. Allow permission and try again.';
                    } else if (error.code === 2) {
                        msg = 'Location is unavailable. Check GPS/network and try again.';
                    } else if (error.code === 3) {
                        msg = 'Location request timed out. Please try again.';
                    }
                }
                if (window.showToast) {
                    showToast(msg, 'danger');
                }
                $btn.prop('disabled', false).html('<i class="bi bi-geo-alt"></i> Use my current GPS location');
            }, {
                enableHighAccuracy: true,
                timeout: 12000,
                maximumAge: 0
            });
        }

        $(document).on('click', '#landingUseGpsBtn', function() {
            detectLandingCurrentLocation();
        });

        // ── Registration modal: payment countdown & retry ──────────────────
        var ldgPaySettled = false;
        var ldgCdInterval = null;
        var ldgPollInterval = null;
        var ldgTimeoutHandle = null;
        var ldgCurrentCheckoutId = null;
        var COUNTDOWN_S = 59;                  // M-Pesa STK typically expires in 60 s
        var RING_CIRC = 238.76;                // 2π × r(38)

        function ldgClearTimers() {
            clearInterval(ldgCdInterval);
            clearInterval(ldgPollInterval);
            clearTimeout(ldgTimeoutHandle);
            ldgCdInterval = ldgPollInterval = ldgTimeoutHandle = null;
        }

        function ldgUpdateRing(secondsLeft) {
            var pct = secondsLeft / COUNTDOWN_S;
            var offset = RING_CIRC * (1 - pct);
            var arc   = document.getElementById('landingRingArc');
            var num   = document.getElementById('landingCountdownNum');
            if (!arc || !num) return;
            arc.style.strokeDashoffset = offset;
            num.textContent = secondsLeft;
            // Colour shifts: blue → orange (≤15 s) → red (≤5 s)
            if (secondsLeft <= 5) {
                arc.className.baseVal = 'ring-arc ring-danger';
                num.className = 'reg-countdown-number text-danger';
            } else if (secondsLeft <= 15) {
                arc.style.stroke = '#fd7e14';
                num.className = 'reg-countdown-number';
                num.style.color = '#fd7e14';
            } else {
                arc.className.baseVal = 'ring-arc';
                num.style.color = '';
                num.className = 'reg-countdown-number';
            }
        }

        function ldgShowWaiting(title, status) {
            $('#landingRegisterIntro').addClass('d-none');
            $('#landingRegisterMessage').addClass('d-none').empty();
            $('#landingRegisterForm').addClass('d-none');
            $('#landingPaymentActions').addClass('d-none');
            $('#landingCdTitle').text(title || 'Waiting for M-Pesa Payment');
            $('#landingCdStatus').text(status || 'Check your phone and approve the M-Pesa prompt.');
            // reset ring to full
            var arc = document.getElementById('landingRingArc');
            if (arc) {
                arc.style.strokeDashoffset = 0;
                arc.className.baseVal = 'ring-arc';
            }
            var num = document.getElementById('landingCountdownNum');
            if (num) { num.textContent = COUNTDOWN_S; num.className = 'reg-countdown-number'; num.style.color = ''; }
            $('#landingPaymentWaiting').removeClass('d-none');
        }

        function ldgShowWaitingActions() {
            $('#landingPaymentActions').removeClass('d-none');
        }

        function ldgHideWaiting() {
            $('#landingPaymentWaiting').addClass('d-none');
            $('#landingPaymentActions').addClass('d-none');
            $('#landingRegisterIntro').removeClass('d-none');
            $('#landingRegisterForm').removeClass('d-none');
        }

        function ldgStartCountdownPoll(checkoutId) {
            ldgClearTimers();
            ldgPaySettled = false;
            ldgCurrentCheckoutId = checkoutId;
            var secondsLeft = COUNTDOWN_S;
            ldgUpdateRing(secondsLeft);

            ldgCdInterval = setInterval(function() {
                if (ldgPaySettled) return;
                secondsLeft = Math.max(0, secondsLeft - 1);
                ldgUpdateRing(secondsLeft);
                if (secondsLeft === 0) {
                    clearInterval(ldgCdInterval);
                }
            }, 1000);

            function doPoll() {
                if (ldgPaySettled) return;
                $.ajax({
                    url: '/api/payments/check_registration_status',
                    type: 'GET',
                    dataType: 'json',
                    data: { checkout_request_id: checkoutId },
                    success: function(data) {
                        if (!data || ldgPaySettled) return;
                        if (data.status === 'success' && data.payment_status === 'completed') {
                            ldgHandleSuccess();
                        } else if (data.status === 'error' && data.payment_status === 'failed') {
                            ldgHandleFailure(data.message || 'M-Pesa payment was declined or cancelled.');
                        }
                    }
                });
            }

            doPoll();
            ldgPollInterval = setInterval(doPoll, 3000);

            // STK expires after 59 s; give a 2-second grace, then show retry
            ldgTimeoutHandle = setTimeout(function() {
                if (ldgPaySettled) return;
                ldgHandleTimeout();
            }, (COUNTDOWN_S + 2) * 1000);
        }

        function ldgHandleSuccess() {
            ldgPaySettled = true;
            ldgClearTimers();
            var arc = document.getElementById('landingRingArc');
            if (arc) arc.className.baseVal = 'ring-arc ring-success';
            var num = document.getElementById('landingCountdownNum');
            if (num) { num.className = 'reg-countdown-number text-success'; num.innerHTML = '<i class="bi bi-check-lg"></i>'; }
            $('#landingCdTitle').text('Payment Confirmed!');
            $('#landingCdStatus').text('Your account is now active. Redirecting to login...');
            if (window.showToast) showToast('Payment confirmed! Redirecting to login...', 'success');
            setTimeout(function() { window.location.href = '/login?registered=true'; }, 2500);
        }

        function ldgHandleFailure(message) {
            ldgPaySettled = true;
            ldgClearTimers();
            var arc = document.getElementById('landingRingArc');
            if (arc) arc.className.baseVal = 'ring-arc ring-danger';
            var num = document.getElementById('landingCountdownNum');
            if (num) { num.innerHTML = '<i class="bi bi-x-lg"></i>'; num.className = 'reg-countdown-number text-danger'; }
            $('#landingCdTitle').text('Payment Failed');
            $('#landingCdStatus').text(message || 'The payment was not completed.');
            if (window.showToast) showToast(message || 'Payment failed.', 'danger');
            ldgShowWaitingActions();
        }

        function ldgHandleTimeout() {
            ldgPaySettled = true;
            ldgClearTimers();
            var num = document.getElementById('landingCountdownNum');
            if (num) { num.textContent = '0'; num.className = 'reg-countdown-number text-danger'; }
            $('#landingCdTitle').text('STK Prompt Expired');
            $('#landingCdStatus').text('The M-Pesa prompt was not approved in time. Use the button below to resend it.');
            if (window.showToast) showToast('M-Pesa prompt expired. Tap "Resend" to try again.', 'warning');
            ldgShowWaitingActions();
        }

        // Retry button
        $(document).on('click', '#landingRetryPayBtn', function() {
            var btn = $(this);
            if (!ldgCurrentCheckoutId) return;
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Sending...');
            $.ajax({
                url: '/api/payments/resend_registration_stk',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ checkout_request_id: ldgCurrentCheckoutId }),
                success: function(res) {
                    if (res.status === 'success' && res.data && res.data.checkout_request_id) {
                        ldgPaySettled = false;
                        ldgCurrentCheckoutId = res.data.checkout_request_id;
                        ldgShowWaiting('Waiting for M-Pesa Payment', 'A new prompt has been sent. Check your phone and approve it.');
                        if (window.showToast) showToast('New M-Pesa prompt sent. Check your phone.', 'info');
                        ldgStartCountdownPoll(ldgCurrentCheckoutId);
                    } else if (res.status === 'already_active') {
                        ldgHandleSuccess();
                    } else {
                        if (window.showToast) showToast(res.message || 'Could not resend payment.', 'danger');
                        $('#landingCdStatus').text(res.message || 'Failed to resend prompt. Please try again.');
                        btn.prop('disabled', false).html('<i class="bi bi-arrow-repeat"></i> Resend M-Pesa Prompt');
                    }
                },
                error: function(xhr) {
                    var err = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to resend payment request.';
                    if (window.showToast) showToast(err, 'danger');
                    $('#landingCdStatus').text(err);
                    btn.prop('disabled', false).html('<i class="bi bi-arrow-repeat"></i> Resend M-Pesa Prompt');
                }
            });
        });

        // Registration modal form submission
        $('#landingRegisterForm').on('submit', function(e) {
            e.preventDefault();
            var $form = $(this);

            if (!this.checkValidity()) {
                e.stopPropagation();
                $form.addClass('was-validated');
                return;
            }

            const isClient = isLandingClientRegistration();
            const isStaff  = isLandingStaffRegistration();
            const password        = String($form.find('#landing_register_password').val() || '');
            const confirmPassword = String($form.find('#landing_confirm_password').val() || '');
            const username        = String($form.find('#landing_username').val() || '').trim();

            if (isClient && password !== confirmPassword) {
                $form.find('#landing_confirm_password').addClass('is-invalid');
                $form.find('#landing_confirm_password').siblings('.invalid-feedback').text('Passwords do not match.');
                return;
            }
            if (isStaff && !/^[A-Za-z0-9._-]{3,30}$/.test(username)) {
                $form.find('#landing_username').addClass('is-invalid');
                return;
            }

            // Enforce GPS accuracy when admin has enabled the requirement
            if (isClient && window.ENFORCE_LOCATION_ACCURACY) {
                var capturedAccuracy = parseFloat($form.find('#landing_gps_accuracy').val());
                var hasCoords = $form.find('#landing_latitude').val() !== '' && $form.find('#landing_longitude').val() !== '';
                var locationLabel = String($form.find('#landing_location_label').val() || '').trim();
                if (!locationLabel) {
                    $form.find('#landing_location_label').addClass('is-invalid');
                    if (window.showToast) {
                        showToast('Please enter your location before submitting.', 'danger');
                    }
                    return;
                }
                if (!hasCoords || isNaN(capturedAccuracy) || capturedAccuracy > 14) {
                    if (window.showToast) {
                        showToast('Please capture your GPS location with accuracy ≤14m before submitting. Use the \"Use my current GPS location\" button.', 'danger');
                    }
                    return;
                }
            }

            const formData = {
                registration_type:  $form.find('#landing_registration_type').val(),
                first_name:         $form.find('#landing_first_name').val(),
                middle_name:        $form.find('#landing_middle_name').val(),
                last_name:          $form.find('#landing_last_name').val(),
                full_name:          ($form.find('#landing_first_name').val() + ' ' + $form.find('#landing_middle_name').val() + ' ' + $form.find('#landing_last_name').val()).trim(),
                phone_country_code: $form.find('#landing_phone_country_code').val(),
                phone_number_local: $form.find('#landing_phone_number_local').val(),
                phone_number:       buildLandingNormalizedPhone(),
                id_number:          $form.find('#landing_id_number').val(),
                latitude:           $form.find('#landing_latitude').val(),
                longitude:          $form.find('#landing_longitude').val()
            };

            if (isStaff)  formData.username = username;
            if (isClient) {
                formData.email           = $form.find('#landing_email').val();
                formData.address         = $form.find('#landing_address').val();
                formData.location_label  = $form.find('#landing_location_label').val();
                formData.connection_type = $form.find('#landing_connection_type').val();
                formData.password        = password;
                formData.tax_pin         = $form.find('#landing_tax_pin').val();
            }

            var registerBtn = $('#landingRegisterBtn');
            registerBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Registering...');

            $.ajax({
                url: '/api/auth/register',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(formData),
                success: function(response) {
                    if (response.status === 'success') {
                        var requiresPayment = response.data && response.data.requires_payment;
                        var baseMsg = response.message || 'Registration successful';

                        if (!requiresPayment) {
                            // No payment needed — show success toast then go to login
                            if (window.showToast) showToast(baseMsg + (response.data && response.data.account_number ? ' Account: ' + response.data.account_number : ''), 'success');
                            registerBtn.prop('disabled', false).html('<i class="bi bi-person-plus"></i> Create Account');
                            $form[0].reset();
                            $form.removeClass('was-validated');
                            setTimeout(function() { window.location.href = '/login?registered=true'; }, 3000);
                            return;
                        }

                        // Payment required — show countdown UI
                        var checkoutId = response.data && response.data.checkout_request_id;
                        if (!checkoutId) {
                            if (window.showToast) showToast('Registration submitted. Please complete the M-Pesa prompt on your phone.', 'info');
                            registerBtn.prop('disabled', false).html('<i class="bi bi-person-plus"></i> Create Account');
                            return;
                        }

                        registerBtn.prop('disabled', false).html('<i class="bi bi-person-plus"></i> Create Account');
                        ldgShowWaiting(
                            'Waiting for M-Pesa Payment',
                            'An STK push has been sent to your phone. Please approve it within 59 seconds to activate your account.'
                        );
                        if (window.showToast) showToast('M-Pesa prompt sent! Approve it on your phone within 59 seconds.', 'info');
                        ldgStartCountdownPoll(checkoutId);

                    } else {
                        var errMsg = response.message || 'Registration failed';
                        if (window.showToast) showToast(errMsg, 'danger');
                        $('#landingRegisterMessage')
                            .removeClass('d-none alert-success alert-info alert-warning')
                            .addClass('alert-danger')
                            .html('<i class="bi bi-exclamation-triangle"></i> ' + errMsg);
                        registerBtn.prop('disabled', false).html('<i class="bi bi-person-plus"></i> Create Account');
                    }
                },
                error: function(xhr) {
                    var err = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Registration failed';
                    if (window.showToast) showToast(err, 'danger');
                    $('#landingRegisterMessage')
                        .removeClass('d-none alert-success alert-info alert-warning')
                        .addClass('alert-danger')
                        .html('<i class="bi bi-exclamation-triangle"></i> ' + err);
                    registerBtn.prop('disabled', false).html('<i class="bi bi-person-plus"></i> Create Account');
                }
            });
        });

        // Reset the waiting panel whenever the modal is fully closed
        $('#registerModal').on('hidden.bs.modal', function() {
            ldgPaySettled = true;
            ldgClearTimers();
            ldgCurrentCheckoutId = null;
            ldgHideWaiting();
            $('#landingRegisterMessage').addClass('d-none').empty();
        });
    });
    </script>

    <?php if(isset($custom_scripts)): ?>
        <?php echo $custom_scripts; ?>
    <?php endif; ?>

    <script>
    /* Auto-inject CSRF token into any POST form that doesn't already have one */
    (function() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (!meta) { return; }
        var token = meta.getAttribute('content');
        document.querySelectorAll('form').forEach(function(form) {
            var method = (form.getAttribute('method') || '').toUpperCase();
            if (method !== 'POST') { return; }
            if (form.querySelector('input[name="csrf_token"]')) { return; }
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'csrf_token';
            inp.value = token;
            form.insertBefore(inp, form.firstChild);
        });
    })();
    </script>

    <!-- ═══════════════════════════════════════════════
         PWA INSTALL BANNER
         Shows on phones & tablets only.  Handles:
          • Android / Chrome  — native beforeinstallprompt
          • iOS Safari        — manual "Add to Home Screen" guide
         Dismissal is stored in localStorage for 14 days.
         ═════════════════════════════════════════════ -->

    <!-- Install prompt banner -->
    <div id="pwa-install-banner" role="complementary" aria-label="Install app prompt">
        <div class="pwa-banner-icon" aria-hidden="true">
            <img src="/public/images/favicon-water.svg" width="32" height="32" alt="">
        </div>
        <div class="pwa-banner-text">
            <strong><?php echo htmlspecialchars($appName ?? 'Water Billing System', ENT_QUOTES, 'UTF-8'); ?></strong>
            <span>Install for quick, offline access</span>
        </div>
        <div class="pwa-banner-actions">
            <button class="pwa-install-btn" id="pwa-install-btn" type="button">
                Install
            </button>
            <button class="pwa-dismiss-btn" id="pwa-dismiss-btn" type="button" aria-label="Dismiss install prompt">
                &times;
            </button>
        </div>
    </div>

    <!-- iOS "Add to Home Screen" instruction sheet -->
    <div id="pwa-ios-tip" role="dialog" aria-modal="true" aria-label="How to install on iPhone or iPad">
        <button class="pwa-ios-tip-close" id="pwa-ios-tip-close" type="button" aria-label="Close">&times;</button>
        <h6><i class="bi bi-phone"></i> Install on your iPhone / iPad</h6>
        <ol>
            <li>Tap the <span class="pwa-share-icon"><i class="bi bi-box-arrow-up"></i></span> <strong>Share</strong> button at the bottom of Safari</li>
            <li>Scroll down and tap <strong>"Add to Home Screen"</strong></li>
            <li>Tap <strong>"Add"</strong> in the top-right corner</li>
        </ol>
        <p style="font-size:0.78rem;opacity:0.65;margin-top:0.6rem;margin-bottom:0;">
            The app will appear on your home screen like a native app.
        </p>
    </div>

    <script>
    (function () {
        'use strict';

        /* ── helpers ── */
        var DISMISS_KEY = 'pwa_banner_dismissed';
        var DAYS_14     = 14 * 24 * 60 * 60 * 1000;

        function wasDismissed() {
            try {
                var ts = localStorage.getItem(DISMISS_KEY);
                return ts && (Date.now() - parseInt(ts, 10)) < DAYS_14;
            } catch (e) { return false; }
        }

        function markDismissed() {
            try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch (e) {}
        }

        function hideBanner()  { banner.classList.remove('pwa-show'); document.body.classList.remove('pwa-banner-visible'); }
        function showBanner()  { banner.classList.add('pwa-show'); document.body.classList.add('pwa-banner-visible'); }
        function hideIosTip()  { iosTip.classList.remove('pwa-show'); }
        function showIosTip()  { iosTip.classList.add('pwa-show'); }

        var banner     = document.getElementById('pwa-install-banner');
        var iosTip     = document.getElementById('pwa-ios-tip');
        var installBtn = document.getElementById('pwa-install-btn');
        var dismissBtn = document.getElementById('pwa-dismiss-btn');
        var iosTipClose= document.getElementById('pwa-ios-tip-close');

        if (!banner || !iosTip) return;

        /* ── Do not show on desktop ── */
        if (window.innerWidth >= 992) return;

        /* ── Do not show if already installed as PWA ── */
        if (window.matchMedia('(display-mode: standalone)').matches) return;
        if (window.navigator.standalone === true) return;      // iOS check

        /* ── Do not show if recently dismissed ── */
        if (wasDismissed()) return;

        /* ── Detect iOS Safari ── */
        var isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) ||
                    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        var isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
        var isIosSafari = isIos && isSafari;

        /* ════════════════════════════════
           Android / Chrome: native prompt
           ════════════════════════════════ */
        var deferredPrompt = null;

        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            deferredPrompt = e;

            // Small delay so the page finishes loading before we slide up
            setTimeout(showBanner, 1800);
        });

        installBtn.addEventListener('click', function () {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then(function (result) {
                    deferredPrompt = null;
                    hideBanner();
                    if (result.outcome === 'accepted') {
                        markDismissed();      // accepted — don't pester again
                    }
                });
            } else if (isIosSafari) {
                hideBanner();
                showIosTip();
            }
        });

        dismissBtn.addEventListener('click', function () {
            hideBanner();
            markDismissed();
        });

        /* ════════════════════════════════
           iOS Safari: manual guide
           ════════════════════════════════ */
        if (isIosSafari) {
            // Chrome/Android prompt won't fire, show banner after delay
            setTimeout(showBanner, 1800);

            // Button label tweak for iOS
            installBtn.textContent = 'Add to Home Screen';
        }

        iosTipClose.addEventListener('click', function () {
            hideIosTip();
            markDismissed();
        });

        /* ── Register service worker ── */
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                <?php
                $swVersion = 0;
                $swVersionFiles = [
                    __DIR__ . '/../sw.js',
                    __DIR__ . '/../public/css/style.css',
                    __DIR__ . '/../public/js/script.js',
                ];
                foreach ($swVersionFiles as $swFile) {
                    if (is_file($swFile)) {
                        $swVersion = max($swVersion, (int) filemtime($swFile));
                    }
                }
                if ($swVersion <= 0) {
                    $swVersion = time();
                }
                $swScriptUrl = '/sw.js?v=' . $swVersion;
                ?>
                navigator.serviceWorker.register('<?php echo htmlspecialchars($swScriptUrl, ENT_QUOTES, 'UTF-8'); ?>', { scope: '/' })
                    .then(function (reg) {
                        // Service worker registered — PWA install criteria met
                        console.debug('[WBS] Service worker registered:', reg.scope);
                    })
                    .catch(function (err) {
                        console.debug('[WBS] Service worker registration failed:', err);
                    });
            });
        }

    }());
    </script>
</body>
</html>
