<?php
session_start();
if(isset($_SESSION['user_id'])) {
    header("Location: /dashboard");
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/BillingSettings.php';
require_once __DIR__ . '/../includes/CountryDialCode.php';

$database = new Database();
$db = $database->getConnection();
$settingsService = new BillingSettings($db);
$settings = $settingsService->getSettings();
$registrationFee = isset($settings['registration_fee']) ? (float)$settings['registration_fee'] : 0.00;

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
    // Use fallback options if table cannot be read.
}

$page_title = "Register";
$hide_nav = true;
require_once __DIR__ . '/../templates/header.php';
?>

<style>
/* Register page specific layout overrides to reduce vertical scrolling */
.auth-layout main.container-fluid {
    align-items: flex-start;
    padding-top: 1.25rem;
}

.auth-layout .card.register-wide-card {
    max-width: min(1400px, 98vw) !important;
}
</style>

<div class="container-fluid mt-4 px-3 px-lg-4 auth-register-page">
    <div class="row">
        <div class="col-12 mx-auto">
            <div class="card register-wide-card auth-main-card">
                <div class="card-header bg-primary text-white">
                    <h3 class="text-center mb-0"><i class="bi bi-person-plus"></i> Create Account</h3>
                </div>
                <div class="card-body">
                    <div id="registerMessage" class="alert d-none"></div>

                    <!-- M-Pesa payment countdown panel (hidden until STK is sent) -->
                    <div id="regPaymentWaiting" class="reg-payment-waiting d-none">
                        <div class="reg-countdown-ring">
                            <svg viewBox="0 0 90 90">
                                <circle class="ring-bg" cx="45" cy="45" r="38"/>
                                <circle class="ring-arc" id="regRingArc" cx="45" cy="45" r="38"/>
                            </svg>
                            <span class="reg-countdown-number" id="regCountdownNum">59</span>
                        </div>
                        <h6 class="mb-1" id="regCdTitle">Waiting for M-Pesa Payment</h6>
                        <p class="reg-payment-status-text" id="regCdStatus">Check your phone and approve the M-Pesa prompt to activate your account.</p>
                        <div class="reg-payment-actions d-none" id="regPaymentActions">
                            <button type="button" class="btn btn-primary btn-sm me-2" id="regRetryPayBtn">
                                <i class="bi bi-arrow-repeat"></i> Resend M-Pesa Prompt
                            </button>
                            <a href="/login" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-box-arrow-in-right"></i> Login to Pay
                            </a>
                        </div>
                    </div>

                    <form id="registerForm" novalidate>
                        <div class="mb-3">
                            <label for="registration_type" class="form-label">Register As *</label>
                            <select class="form-select" id="registration_type" name="registration_type" required>
                                <option value="client" selected>Client</option>
                                <option value="staff">Office Staff</option>
                            </select>
                            <div class="form-text">Clients get meter/account setup and registration payment flow. Office staff get a staff account without a meter number and must choose a unique username.</div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 col-lg-4">
                                <div class="mb-3">
                                    <label for="first_name" class="form-label">First Name *</label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" autocomplete="given-name" required>
                                    <div class="invalid-feedback">Please enter your first name.</div>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <div class="mb-3">
                                    <label for="middle_name" class="form-label">Middle Name (optional)</label>
                                    <input type="text" class="form-control" id="middle_name" name="middle_name" autocomplete="additional-name">
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <div class="mb-3">
                                    <label for="last_name" class="form-label">Last Name *</label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" autocomplete="family-name" required>
                                    <div class="invalid-feedback">Please enter your last name.</div>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <div class="mb-3">
                                    <label for="phone_number_local" class="form-label">Phone Number *</label>
                                    <div class="input-group">
                                        <span class="input-group-text">+</span>
                                        <select class="form-select" id="phone_country_code" name="phone_country_code" style="max-width: 190px;" required>
                                            <?php foreach ($countryCodeOptions as $code => $label): ?>
                                                <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $code === '254' ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="tel" class="form-control" id="phone_number_local" name="phone_number_local" placeholder="e.g. 712345678" autocomplete="tel-national" inputmode="numeric" required>
                                    </div>
                                    <div class="invalid-feedback">Please choose country code and enter a valid phone number.</div>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-4 staff-only-field d-none">
                                <div class="mb-3">
                                    <label for="username" class="form-label">Username *</label>
                                    <input type="text" class="form-control" id="username" name="username" minlength="3" maxlength="30" pattern="^[A-Za-z0-9._-]{3,30}$" autocomplete="username">
                                    <div class="form-text">Used to login for office staff accounts.</div>
                                    <div class="invalid-feedback">Please enter a valid username (3-30 characters: letters, numbers, dot, underscore, hyphen).</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row client-only-field">
                                                    <div class="col-md-6 col-lg-4">
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email Address *</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                        <input type="email" class="form-control" id="email" name="email" autocomplete="email">
                                    </div>
                                    <div class="invalid-feedback">Please enter a valid email address.</div>
                                </div>
                            </div>
                                                    <div class="col-md-6 col-lg-4">
                                <div class="mb-3">
                                    <label for="id_number" class="form-label">ID Number *</label>
                                    <input type="text" class="form-control" id="id_number" name="id_number" autocomplete="off" required>
                                    <div class="invalid-feedback">Please enter your ID number.</div>
                                </div>
                            </div>
                                                    <div class="col-md-6 col-lg-4">
                                <div class="mb-3">
                                    <label for="tax_pin" class="form-label">PIN / Tax ID (optional)</label>
                                    <input type="text" class="form-control" id="tax_pin" name="tax_pin" placeholder="e.g. P012345678Z" autocomplete="off">
                                    <div class="form-text">If you have a KRA PIN/Tax ID, you can provide it for eTIMS-compliant invoices. This is optional.</div>
                                </div>
                            </div>
                        </div>
                        
                                                <div class="row client-only-field">
                                                    <div class="col-lg-8">
                                                        <div class="mb-3">
                                                            <label for="address" class="form-label">Physical Address *</label>
                                                            <textarea class="form-control" id="address" name="address" rows="2"></textarea>
                                                            <div class="invalid-feedback">Please enter your address.</div>
                                                        </div>
                                                    </div>
                                                    <div class="col-lg-4">
                                                        <div class="mb-3">
                                                            <label for="location_label" class="form-label">Location (optional)</label>
                                                            <input type="text" class="form-control location-autocomplete" id="location_label" name="location_label" placeholder="e.g. P5PP+CJ, Nguluni" autocomplete="off">
                                                            <div class="form-text">Optional short location such as Plus Code or estate name.</div>
                                                        </div>
                                                    </div>
                        </div>
                        
                        <div class="row client-only-field">
                                                    <div class="col-md-6 col-lg-4">
                                <div class="mb-3">
                                    <label for="connection_type" class="form-label">Connection Type *</label>
                                    <select class="form-select" id="connection_type" name="connection_type">
                                        <option value="">Select type</option>
                                        <option value="domestic">Domestic</option>
                                        <option value="commercial">Commercial</option>
                                        <option value="industrial">Industrial</option>
                                    </select>
                                    <div class="invalid-feedback">Please select connection type.</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row client-only-field">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="password" class="form-label">Password *</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="password" name="password" autocomplete="new-password">
                                        <button class="btn btn-outline-secondary toggle-password" type="button" aria-label="Show or hide password">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div class="form-check mt-1">
                                        <input class="form-check-input" type="checkbox" id="registerShowPassword">
                                        <label class="form-check-label small" for="registerShowPassword">Show password</label>
                                    </div>
                                    <div class="invalid-feedback">Please enter a password.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm Password *</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password">
                                        <button class="btn btn-outline-secondary toggle-password" type="button" aria-label="Show or hide password">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div class="form-check mt-1">
                                        <input class="form-check-input" type="checkbox" id="registerShowConfirmPassword">
                                        <label class="form-check-label small" for="registerShowConfirmPassword">Show confirm password</label>
                                    </div>
                                    <div class="invalid-feedback">Please confirm your password.</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="terms" required>
                            <label class="form-check-label" for="terms">
                                I agree to the <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms and Conditions</a>
                            </label>
                            <div class="invalid-feedback">You must agree to the terms and conditions.</div>
                        </div>

                        <?php if ($registrationFee > 0): ?>
                        <div class="alert alert-info py-2 mb-3 client-only-field">
                            <small>
                                A one-time non-refundable installation/registration fee of
                                <strong><?php echo htmlspecialchars($settings['currency_code'] ?? 'KES'); ?>
                                <?php echo number_format($registrationFee, 2); ?></strong>
                                will be charged via M-Pesa STK push when you submit this form.
                            </small>
                        </div>
                        <?php endif; ?>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg" id="registerBtn">
                                <i class="bi bi-person-plus"></i> Create Account
                            </button>
                            <a href="/login" class="btn btn-link">Already have an account? Login here</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php 
$custom_scripts = <<<'JS'
<script>
$(document).ready(function() {
    function buildNormalizedPhone() {
        var code = String($('#phone_country_code').val() || '').replace(/\D/g, '');
        var local = String($('#phone_number_local').val() || '').replace(/\D/g, '');
        if (code && local.indexOf(code) === 0 && local.length > code.length) {
            local = local.slice(code.length);
        }
        local = local.replace(/^0+/, '');
        if (!code || !local) {
            return '';
        }
        return code + local;
    }

    function isClientRegistration() {
        return $('#registration_type').val() === 'client';
    }

    function isStaffRegistration() {
        return $('#registration_type').val() === 'staff';
    }

    function toggleRegistrationModeUI() {
        var isClient = isClientRegistration();
        var isStaff = isStaffRegistration();
        $('.client-only-field').toggleClass('d-none', !isClient);
        $('.staff-only-field').toggleClass('d-none', !isStaff);

        $('#email, #id_number, #address, #connection_type, #password, #confirm_password').prop('required', isClient);
        $('#username').prop('required', isStaff);

        if (!isClient) {
            $('#email, #id_number, #address, #tax_pin, #location_label, #password, #confirm_password').removeClass('is-invalid');
            $('#password, #confirm_password').val('');
        }
        if (!isStaff) {
            $('#username').removeClass('is-invalid').val('');
        }
    }

    $('#registration_type').on('change', toggleRegistrationModeUI);
    toggleRegistrationModeUI();

    // Show/hide passwords on full registration page
    $('#registerShowPassword').on('change', function() {
        var $input = $('#password');
        $input.attr('type', this.checked ? 'text' : 'password');
    });
    $('#registerShowConfirmPassword').on('change', function() {
        var $input = $('#confirm_password');
        $input.attr('type', this.checked ? 'text' : 'password');
    });

    $('#registerForm').on('submit', function(e) {
        e.preventDefault();
		
        // Validate form
        if(!this.checkValidity()) {
            e.stopPropagation();
            $(this).addClass('was-validated');
            return;
        }
		
        const isClient = isClientRegistration();
        const isStaff = isStaffRegistration();
        const password = $('#password').val();
        const confirmPassword = $('#confirm_password').val();
        const username = $('#username').val().trim();

        if (isClient && password !== confirmPassword) {
            $('#confirm_password').addClass('is-invalid');
            $('#confirm_password').siblings('.invalid-feedback').text('Passwords do not match.');
            return;
        }

        if (isStaff && !/^[A-Za-z0-9._-]{3,30}$/.test(username)) {
            $('#username').addClass('is-invalid');
            return;
        }
		
        // Prepare shared data
        const formData = {
            registration_type: $('#registration_type').val(),
            first_name: $('#first_name').val(),
            middle_name: $('#middle_name').val(),
            last_name: $('#last_name').val(),
            full_name: ($('#first_name').val() + ' ' + $('#middle_name').val() + ' ' + $('#last_name').val()).trim(),
            phone_country_code: $('#phone_country_code').val(),
            phone_number_local: $('#phone_number_local').val(),
            phone_number: buildNormalizedPhone(),
            id_number: $('#id_number').val()
        };

        if (isStaff) {
            formData.username = username;
        }

        // Client-specific requirements and fields
        if (isClient) {
            formData.email = $('#email').val();
            formData.address = $('#address').val();
            formData.location_label = $('#location_label').val();
            formData.connection_type = $('#connection_type').val();
            formData.password = password;
            formData.tax_pin = $('#tax_pin').val();
        }
		
        // Show loading
        const registerBtn = $('#registerBtn');
        registerBtn.prop('disabled', true);
        registerBtn.html('<span class="spinner-border spinner-border-sm"></span> Registering...');

        // ── countdown/poll state ────────────────────────────────────────────
        var REG_COUNTDOWN_S = 59;
        var REG_RING_CIRC = 238.76;
        var regPaySettled = false;
        var regCdInterval = null;
        var regPollInterval = null;
        var regTimeoutHandle = null;
        var regCurrentCheckoutId = null;

        function regClearTimers() {
            clearInterval(regCdInterval);
            clearInterval(regPollInterval);
            clearTimeout(regTimeoutHandle);
            regCdInterval = regPollInterval = regTimeoutHandle = null;
        }

        function regUpdateRing(sl) {
            var offset = REG_RING_CIRC * (1 - sl / REG_COUNTDOWN_S);
            var arc = document.getElementById('regRingArc');
            var num = document.getElementById('regCountdownNum');
            if (!arc || !num) return;
            arc.style.strokeDashoffset = offset;
            num.textContent = sl;
            if (sl <= 5) {
                arc.className.baseVal = 'ring-arc ring-danger';
                num.className = 'reg-countdown-number text-danger';
            } else if (sl <= 15) {
                arc.style.stroke = '#fd7e14';
                num.style.color = '#fd7e14';
                num.className = 'reg-countdown-number';
            } else {
                arc.className.baseVal = 'ring-arc';
                num.style.color = '';
                num.className = 'reg-countdown-number';
            }
        }

        function regShowWaiting(title, status) {
            $('#registerMessage').addClass('d-none').empty();
            $('#registerForm').addClass('d-none');
            $('#regPaymentActions').addClass('d-none');
            $('#regCdTitle').text(title || 'Waiting for M-Pesa Payment');
            $('#regCdStatus').text(status || 'Check your phone and approve the M-Pesa prompt.');
            var arc = document.getElementById('regRingArc');
            if (arc) { arc.style.strokeDashoffset = 0; arc.className.baseVal = 'ring-arc'; }
            var num = document.getElementById('regCountdownNum');
            if (num) { num.textContent = REG_COUNTDOWN_S; num.className = 'reg-countdown-number'; num.style.color = ''; }
            $('#regPaymentWaiting').removeClass('d-none');
        }

        function regStartCountdownPoll(checkoutId) {
            regClearTimers();
            regPaySettled = false;
            regCurrentCheckoutId = checkoutId;
            var sl = REG_COUNTDOWN_S;
            regUpdateRing(sl);

            regCdInterval = setInterval(function() {
                if (regPaySettled) return;
                sl = Math.max(0, sl - 1);
                regUpdateRing(sl);
            }, 1000);

            function doPoll() {
                if (regPaySettled) return;
                $.ajax({
                    url: '/api/payments/check_registration_status',
                    type: 'GET', dataType: 'json',
                    data: { checkout_request_id: checkoutId },
                    success: function(data) {
                        if (!data || regPaySettled) return;
                        if (data.status === 'success' && data.payment_status === 'completed') {
                            regPaySettled = true; regClearTimers();
                            var arc = document.getElementById('regRingArc');
                            if (arc) arc.className.baseVal = 'ring-arc ring-success';
                            var num = document.getElementById('regCountdownNum');
                            if (num) { num.innerHTML = '<i class="bi bi-check-lg"></i>'; num.className = 'reg-countdown-number text-success'; }
                            $('#regCdTitle').text('Payment Confirmed!');
                            $('#regCdStatus').text('Your account is now active. Redirecting to login...');
                            if (window.showToast) showToast('Payment confirmed! Redirecting to login...', 'success');
                            setTimeout(function() { window.location.href = '/login?registered=true'; }, 2500);
                        } else if (data.status === 'error' && data.payment_status === 'failed') {
                            regHandleFailure(data.message || 'M-Pesa payment was declined or cancelled.');
                        }
                    }
                });
            }

            function regHandleFailure(msg) {
                regPaySettled = true; regClearTimers();
                var arc = document.getElementById('regRingArc');
                if (arc) arc.className.baseVal = 'ring-arc ring-danger';
                var num = document.getElementById('regCountdownNum');
                if (num) { num.innerHTML = '<i class="bi bi-x-lg"></i>'; num.className = 'reg-countdown-number text-danger'; }
                $('#regCdTitle').text('Payment Failed');
                $('#regCdStatus').text(msg);
                if (window.showToast) showToast(msg, 'danger');
                $('#regPaymentActions').removeClass('d-none');
            }

            doPoll();
            regPollInterval = setInterval(doPoll, 3000);
            regTimeoutHandle = setTimeout(function() {
                if (regPaySettled) return;
                regPaySettled = true; regClearTimers();
                var num = document.getElementById('regCountdownNum');
                if (num) { num.textContent = '0'; num.className = 'reg-countdown-number text-danger'; }
                $('#regCdTitle').text('STK Prompt Expired');
                $('#regCdStatus').text('The M-Pesa prompt was not approved in time. Use the button below to resend it.');
                if (window.showToast) showToast('M-Pesa prompt expired. Tap "Resend" to try again.', 'warning');
                $('#regPaymentActions').removeClass('d-none');
            }, (REG_COUNTDOWN_S + 2) * 1000);
        }

        $('#regRetryPayBtn').on('click', function() {
            var btn = $(this);
            if (!regCurrentCheckoutId) return;
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Sending...');
            $.ajax({
                url: '/api/payments/resend_registration_stk',
                type: 'POST', contentType: 'application/json',
                data: JSON.stringify({ checkout_request_id: regCurrentCheckoutId }),
                success: function(res) {
                    if (res.status === 'success' && res.data && res.data.checkout_request_id) {
                        regShowWaiting('Waiting for M-Pesa Payment', 'A new prompt has been sent. Check your phone and approve it.');
                        if (window.showToast) showToast('New M-Pesa prompt sent. Check your phone.', 'info');
                        regStartCountdownPoll(res.data.checkout_request_id);
                    } else if (res.status === 'already_active') {
                        if (window.showToast) showToast('Account is already active. Redirecting to login...', 'success');
                        setTimeout(function() { window.location.href = '/login?registered=true'; }, 2000);
                    } else {
                        if (window.showToast) showToast(res.message || 'Could not resend payment.', 'danger');
                        $('#regCdStatus').text(res.message || 'Failed to resend prompt. Please try again.');
                        btn.prop('disabled', false).html('<i class="bi bi-arrow-repeat"></i> Resend M-Pesa Prompt');
                    }
                },
                error: function(xhr) {
                    var err = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to resend payment request.';
                    if (window.showToast) showToast(err, 'danger');
                    $('#regCdStatus').text(err);
                    btn.prop('disabled', false).html('<i class="bi bi-arrow-repeat"></i> Resend M-Pesa Prompt');
                }
            });
        });
        // ── end countdown/poll state ────────────────────────────────────────

        // Send request
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
                        if (window.showToast) {
                            showToast(baseMsg + (response.data && response.data.account_number ? ' Account: ' + response.data.account_number : ''), 'success');
                        } else {
                            $('#registerMessage')
                                .removeClass('d-none alert-danger')
                                .addClass('alert-success')
                                .html('<i class="bi bi-check-circle"></i> ' + baseMsg +
                                      (response.data && response.data.account_number ? '<br>Your account number is: <strong>' + response.data.account_number + '</strong>' : ''));
                        }
                        registerBtn.prop('disabled', false).html('<i class="bi bi-person-plus"></i> Create Account');
                        $('#registerForm')[0].reset();
                        $('#registerForm').removeClass('was-validated');
                        setTimeout(function() { window.location.href = '/login?registered=true'; }, 5000);

                    } else {
                        var checkoutId = response.data && response.data.checkout_request_id;
                        registerBtn.prop('disabled', false).html('<i class="bi bi-person-plus"></i> Create Account');

                        if (!checkoutId) {
                            if (window.showToast) showToast('Registration submitted. Please complete the M-Pesa prompt on your phone.', 'info');
                            return;
                        }

                        regShowWaiting(
                            'Waiting for M-Pesa Payment',
                            'An STK push has been sent to your phone. Please approve it within 59 seconds to activate your account.'
                        );
                        if (window.showToast) showToast('M-Pesa prompt sent! Approve it on your phone within 59 seconds.', 'info');
                        regStartCountdownPoll(checkoutId);
                    }

                } else {
                    var errMsg = response.message || 'Registration failed';
                    if (window.showToast) {
                        showToast(errMsg, 'danger');
                    } else {
                        $('#registerMessage')
                            .removeClass('d-none alert-success')
                            .addClass('alert-danger')
                            .html('<i class="bi bi-exclamation-triangle"></i> ' + errMsg);
                    }
                    registerBtn.prop('disabled', false).html('<i class="bi bi-person-plus"></i> Create Account');
                }
            },
            error: function(xhr) {
                var err = xhr.responseJSON ? xhr.responseJSON.message : 'Registration failed';
                if (window.showToast) {
                    showToast(err, 'danger');
                } else {
                    $('#registerMessage')
                        .removeClass('d-none alert-success')
                        .addClass('alert-danger')
                        .html('<i class="bi bi-exclamation-triangle"></i> ' + err);
                }
                registerBtn.prop('disabled', false).html('<i class="bi bi-person-plus"></i> Create Account');
            }
        });
    });
});
</script>
JS;
require_once __DIR__ . '/../templates/footer.php'; 
?>
