    </main>
    
    <?php
    // Load billing settings for footer contact details and quick registration info
    $footerSupportPhone = '+254 700 000 000';
    $footerSupportEmail = 'support@waterbilling.com';
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
                    <h5><i class="bi bi-droplet"></i> Water Billing System</h5>
                    <p>Efficient water bill management with M-Pesa integration.</p>
                </div>
                <div class="col-md-4">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <?php if (!isset($_SESSION['user_id'])): ?>
                            <li><a href="/register" class="text-white-50" data-bs-toggle="modal" data-bs-target="#registerModal">Register</a></li>
                            <li><a href="/login" class="text-white-50" data-bs-toggle="modal" data-bs-target="#loginModal">Login</a></li>
                        <?php endif; ?>
                        <li><a href="/dashboard" class="text-white-50">Dashboard</a></li>
                    </ul>
                </div>
                <div class="col-md-4">
                    <h5>Contact</h5>
                    <p><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($footerSupportPhone); ?></p>
                    <p><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($footerSupportEmail); ?></p>
                </div>
            </div>
            <hr class="bg-light">
            <div class="text-center">
                <p>&copy; <?php echo date('Y'); ?> Water Billing System. All rights reserved.</p>
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
                                <input type="password" class="form-control" id="landing_password" name="password" required>
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            </div>
                            <div class="form-text text-end">
                                <a href="/forgot-password">Forgot password?</a>
                            </div>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary" id="landingLoginBtn">
                                <i class="bi bi-box-arrow-in-right"></i> Login
                            </button>
                            <a href="/register" class="btn btn-link">Need an account? Open full registration</a>
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
                                    <input type="password" class="form-control" id="landing_password" required>
                                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="landing_confirm_password" class="form-label">Confirm Password *</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="landing_confirm_password" required>
                                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                </div>
                                <div class="invalid-feedback">Please confirm your password.</div>
                            </div>
                            <div class="col-12">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="landing_terms" required>
                                    <label class="form-check-label" for="landing_terms">
                                        I agree to the terms and conditions.
                                    </label>
                                    <div class="invalid-feedback">You must agree to the terms and conditions.</div>
                                </div>
                                <?php if (!empty($footerRegistrationFee) && $footerRegistrationFee > 0): ?>
                                <div class="alert alert-info py-2 mb-2">
                                    <small>A one-time registration fee of <strong><?php echo htmlspecialchars($footerCurrencyCode); ?> <?php echo number_format($footerRegistrationFee, 2); ?></strong> will be charged via M-Pesa STK push when you submit this form.</small>
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <?php if (isset($page_title) && $page_title === 'Dashboard'): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <?php endif; ?>
    <script src="../public/js/script.js"></script>

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
