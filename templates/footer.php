    </main>
    
    <?php
    // Load billing settings for footer contact details and quick registration info
    $footerSupportPhone = '+25472400202';
    $footerSupportEmail = 'support@bremac.co.ke';
    $footerRegistrationFee = 0.00;
    $footerCurrencyCode = 'KES';

    try {
        if (file_exists(__DIR__ . '/../config/database.php')) {
            require_once __DIR__ . '/../config/database.php';
            require_once __DIR__ . '/../includes/BillingSettings.php';
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
                            <li><a href="/register" class="text-white-50" data-bs-toggle="modal" data-bs-target="#registerModal">Register</a></li>
                            <li><a href="/login" class="text-white-50" data-bs-toggle="modal" data-bs-target="#loginModal">Login</a></li>
                        <?php endif; ?>
                        <li><a href="/dashboard" class="text-white-50">Dashboard</a></li>
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
                        <div class="mb-3">
                            <label for="landing_identifier" class="form-label">Account Number, Phone or Email *</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control" id="landing_identifier" name="identifier" required
                                       placeholder="e.g. MTR0001, 07XXXXXXXX or name@example.com">
                            </div>
                            <div class="invalid-feedback">Please enter your account number, phone or email.</div>
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
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Global Registration Modal (quick access) -->
    <div class="modal fade" id="registerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus"></i> Create Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="small text-muted mb-3">
                        You can register directly from any page. For a larger view,
                        you can also use the full registration page from the menu.
                    </div>
                    <form id="landingRegisterForm" novalidate>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="landing_first_name" class="form-label">First Name *</label>
                                <input type="text" class="form-control" id="landing_first_name" required>
                                <div class="invalid-feedback">Please enter your first name.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="landing_last_name" class="form-label">Last Name *</label>
                                <input type="text" class="form-control" id="landing_last_name" required>
                                <div class="invalid-feedback">Please enter your last name.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="landing_phone_number" class="form-label">Phone Number *</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                    <input type="tel" class="form-control" id="landing_phone_number"
                                           placeholder="2547XXXXXXXX" pattern="^(?:254|\+254|0)?(7\d{8})$" required>
                                </div>
                                <div class="invalid-feedback">Please enter a valid phone number (e.g., 254712345678).</div>
                            </div>
                            <div class="col-md-6">
                                <label for="landing_email" class="form-label">Email Address *</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                    <input type="email" class="form-control" id="landing_email" required>
                                </div>
                                <div class="invalid-feedback">Please enter a valid email address.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="landing_id_number" class="form-label">ID Number *</label>
                                <input type="text" class="form-control" id="landing_id_number" required>
                                <div class="invalid-feedback">Please enter your ID number.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="landing_tax_pin" class="form-label">PIN / Tax ID (optional)</label>
                                <input type="text" class="form-control" id="landing_tax_pin" placeholder="e.g. P012345678Z">
                            </div>
                            <div class="col-12">
                                <label for="landing_address" class="form-label">Physical Address *</label>
                                <textarea class="form-control" id="landing_address" rows="2" required></textarea>
                                <div class="invalid-feedback">Please enter your address.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="landing_connection_type" class="form-label">Connection Type *</label>
                                <select class="form-select" id="landing_connection_type" required>
                                    <option value="">Select type</option>
                                    <option value="domestic">Domestic</option>
                                    <option value="commercial">Commercial</option>
                                    <option value="industrial">Industrial</option>
                                </select>
                                <div class="invalid-feedback">Please select connection type.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="landing_password" class="form-label">Password *</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="landing_password" autocomplete="new-password" required>
                                    <button class="btn btn-outline-secondary toggle-password" type="button" aria-label="Show or hide password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" id="landingRegisterShowPassword">
                                    <label class="form-check-label small" for="landingRegisterShowPassword">Show password</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="landing_confirm_password" class="form-label">Confirm Password *</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="landing_confirm_password" autocomplete="new-password" required>
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
                                <div class="alert alert-info py-2 mb-2">
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
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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

    <!-- Floating Live Chat toggle (logged-in users) -->
    <?php if (isset($_SESSION['user_id'])): ?>
        <button type="button"
                class="btn support-chat-toggle"
                id="supportChatToggle"
                title="Live chat with support"
                aria-label="Live chat with support"
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
    <?php endif; ?>

    <!-- Contact Form Modal -->
    <div class="modal fade" id="contactModal" tabindex="-1" aria-labelledby="contactModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="contactModalLabel"><i class="bi bi-chat-text"></i> Contact Support</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <p class="small text-muted mb-2">Choose how you would like to reach support:</p>
                        <?php if (!empty($whatsappNumber)): ?>
                            <a href="https://wa.me/<?php echo htmlspecialchars($whatsappNumber, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"
                               class="btn btn-success w-100 mb-2">
                                <i class="bi bi-whatsapp"></i> Private WhatsApp chat
                            </a>
                            <div class="text-center text-muted small mb-2">or send us a message using the form below</div>
                        <?php endif; ?>
                        <?php if (empty($whatsappNumber)): ?>
                            <p class="small text-muted mb-2">Send us a message using the form below.</p>
                        <?php endif; ?>
                    </div>
                    <form id="contactForm" novalidate>
                        <div class="mb-3">
                            <label for="contact_name" class="form-label">Your Name *</label>
                            <input type="text" class="form-control" id="contact_name" name="name" required>
                            <div class="invalid-feedback">Please enter your name.</div>
                        </div>
                        <div class="mb-3">
                            <label for="contact_email" class="form-label">Email Address *</label>
                            <input type="email" class="form-control" id="contact_email" name="email" required>
                            <div class="invalid-feedback">Please enter a valid email address.</div>
                        </div>
                        <div class="mb-3">
                            <label for="contact_phone" class="form-label">Phone (optional)</label>
                            <input type="tel" class="form-control" id="contact_phone" name="phone" placeholder="2547XXXXXXXX">
                        </div>
                        <div class="mb-3">
                            <label for="contact_message" class="form-label">Message *</label>
                            <textarea class="form-control" id="contact_message" name="message" rows="3" required></textarea>
                            <div class="invalid-feedback">Please enter your message.</div>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary" id="contactSubmitBtn">
                                <i class="bi bi-send"></i> Send Message
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <?php if (isset($page_title) && $page_title === 'Dashboard'): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <?php endif; ?>
    <script>
        window.CURRENT_USER_ID = <?php echo isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 'null'; ?>;
    </script>
    <script src="/public/js/script.js?v=20260305"></script>

    <script>
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

    // Global handlers for header/home login & registration modals
    $(document).ready(function() {
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
            var $input = $('#landing_password');
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
                    if (response.status === 'success') {
                        var requiresRegPayment = response.data && response.data.requires_registration_payment;
                        var requiresPasswordChange = response.data && response.data.requires_password_change;

                        if (requiresRegPayment) {
                            if (window.showToast) {
                                showToast('Login successful. Please complete your registration payment.','info');
                            }
                            setTimeout(function() { window.location.href = '/registration-payment'; }, 1000);
                        } else if (requiresPasswordChange) {
                            if (window.showToast) {
                                showToast('Login successful! Please change your password.','success');
                            }
                            setTimeout(function() { window.location.href = '/change-password'; }, 1000);
                        } else {
                            if (window.showToast) {
                                showToast('Login successful! Redirecting...','success');
                            }
                            setTimeout(function() { window.location.href = '/dashboard'; }, 1000);
                        }
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

        // Registration modal form
        $('#landingRegisterForm').on('submit', function(e) {
            e.preventDefault();

            if (!this.checkValidity()) {
                e.stopPropagation();
                $(this).addClass('was-validated');
                return;
            }

            const password = $('#landing_password').val();
            const confirmPassword = $('#landing_confirm_password').val();
            if (password !== confirmPassword) {
                $('#landing_confirm_password').addClass('is-invalid');
                $('#landing_confirm_password').siblings('.invalid-feedback').text('Passwords do not match.');
                return;
            }

            const formData = {
                first_name: $('#landing_first_name').val(),
                last_name: $('#landing_last_name').val(),
                full_name: ($('#landing_first_name').val() + ' ' + $('#landing_last_name').val()).trim(),
                phone_number: $('#landing_phone_number').val(),
                email: $('#landing_email').val(),
                id_number: $('#landing_id_number').val(),
                address: $('#landing_address').val(),
                connection_type: $('#landing_connection_type').val(),
                password: password,
                tax_pin: $('#landing_tax_pin').val()
            };

            const registerBtn = $('#landingRegisterBtn');
            registerBtn.prop('disabled', true);
            registerBtn.html('<span class="spinner-border spinner-border-sm"></span> Registering...');

            $.ajax({
                url: '/api/auth/register',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(formData),
                success: function(response) {
                    if (response.status === 'success') {
                        var requiresPayment = response.data && response.data.requires_payment;
                        var baseMsg = response.message || 'Registration successful';
                        var extra = '';
                        if (response.data && response.data.account_number) {
                            extra = '\nYour account number is: ' + response.data.account_number;
                        }
                        var successMsg = baseMsg + extra;

                        if (window.showToast) {
                            showToast(successMsg, 'success');
                        }

                        if (!requiresPayment) {
                            $('#landingRegisterForm')[0].reset();
                            $('#landingRegisterForm').removeClass('was-validated');
                            setTimeout(function() { window.location.href = '/login?registered=true'; }, 3000);
                        } else if (window.showToast) {
                            showToast('Waiting for M-Pesa payment confirmation. Please complete the STK prompt on your phone.', 'info');
                        }
                    } else if (window.showToast) {
                        showToast(response.message || 'Registration failed','danger');
                    }
                },
                error: function(xhr) {
                    const error = xhr.responseJSON ? xhr.responseJSON.message : 'Registration failed';
                    if (window.showToast) {
                        showToast(error,'danger');
                    }
                },
                complete: function() {
                    registerBtn.prop('disabled', false);
                    registerBtn.html('<i class="bi bi-person-plus"></i> Create Account');
                }
            });
        });
    });
    </script>

    <?php if(isset($custom_scripts)): ?>
        <?php echo $custom_scripts; ?>
    <?php endif; ?>
</body>
</html>
