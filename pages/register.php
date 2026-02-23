<?php
session_start();
if(isset($_SESSION['user_id'])) {
    header("Location: /dashboard");
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/BillingSettings.php';

$database = new Database();
$db = $database->getConnection();
$settingsService = new BillingSettings($db);
$settings = $settingsService->getSettings();
$registrationFee = isset($settings['registration_fee']) ? (float)$settings['registration_fee'] : 0.00;

$page_title = "Register";
$hide_nav = true;
require_once __DIR__ . '/../templates/header.php';
?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h3 class="text-center mb-0"><i class="bi bi-person-plus"></i> Create Account</h3>
                </div>
                <div class="card-body">
                    <div id="registerMessage" class="alert d-none"></div>
                    
                    <form id="registerForm" novalidate>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="first_name" class="form-label">First Name *</label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" required>
                                    <div class="invalid-feedback">Please enter your first name.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="last_name" class="form-label">Last Name *</label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" required>
                                    <div class="invalid-feedback">Please enter your last name.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="phone_number" class="form-label">Phone Number *</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                        <input type="tel" class="form-control" id="phone_number" name="phone_number" 
						   placeholder="2547XXXXXXXX" pattern="^(?:254|\+254|0)?(7\d{8})$" required>
                                    </div>
                                    <div class="invalid-feedback">Please enter a valid phone number (e.g., 254712345678).</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email Address</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                        <input type="email" class="form-control" id="email" name="email">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="id_number" class="form-label">ID Number *</label>
                                    <input type="text" class="form-control" id="id_number" name="id_number" required>
                                    <div class="invalid-feedback">Please enter your ID number.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="tax_pin" class="form-label">PIN / Tax ID (optional)</label>
                                    <input type="text" class="form-control" id="tax_pin" name="tax_pin" placeholder="e.g. P012345678Z">
                                    <div class="form-text">If you have a KRA PIN/Tax ID, you can provide it for eTIMS-compliant invoices. This is optional.</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="address" class="form-label">Physical Address *</label>
                            <textarea class="form-control" id="address" name="address" rows="2" required></textarea>
                            <div class="invalid-feedback">Please enter your address.</div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="connection_type" class="form-label">Connection Type *</label>
                                    <select class="form-select" id="connection_type" name="connection_type" required>
                                        <option value="">Select type</option>
                                        <option value="domestic">Domestic</option>
                                        <option value="commercial">Commercial</option>
                                        <option value="industrial">Industrial</option>
                                    </select>
                                    <div class="invalid-feedback">Please select connection type.</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="password" class="form-label">Password *</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="password" name="password" required>
                                        <button class="btn btn-outline-secondary toggle-password" type="button">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div class="invalid-feedback">Please enter a password.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm Password *</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                        <button class="btn btn-outline-secondary toggle-password" type="button">
                                            <i class="bi bi-eye"></i>
                                        </button>
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
                        <div class="alert alert-info py-2 mb-3">
                            <small>A one-time registration fee of <strong><?php echo htmlspecialchars($settings['currency_code'] ?? 'KES'); ?> <?php echo number_format($registrationFee, 2); ?></strong> will be charged via M-Pesa STK push when you submit this form.</small>
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
</div>

<!-- Terms Modal -->
<div class="modal fade" id="termsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Terms and Conditions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <h6>1. Account Registration</h6>
                <p>You must provide accurate and complete information when registering for an account.</p>
                
                <h6>2. Bill Payments</h6>
                <p>All payments made through the system are final and non-refundable.</p>
                
                <h6>3. Privacy</h6>
                <p>Your personal information will be protected and used only for billing purposes.</p>
                
                <h6>4. Service Availability</h6>
                <p>The system may be temporarily unavailable for maintenance.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php 
$custom_scripts = <<<'JS'
<script>
$(document).ready(function() {
    $('#registerForm').on('submit', function(e) {
        e.preventDefault();
		
        // Validate form
        if(!this.checkValidity()) {
            e.stopPropagation();
            $(this).addClass('was-validated');
            return;
        }
		
        // Check password match
        const password = $('#password').val();
        const confirmPassword = $('#confirm_password').val();
		
        if(password !== confirmPassword) {
            $('#confirm_password').addClass('is-invalid');
            $('#confirm_password').siblings('.invalid-feedback').text('Passwords do not match.');
            return;
        }
		
        // Prepare data
        const formData = {
            first_name: $('#first_name').val(),
            last_name: $('#last_name').val(),
            full_name: ($('#first_name').val() + ' ' + $('#last_name').val()).trim(),
            phone_number: $('#phone_number').val(),
            email: $('#email').val(),
            id_number: $('#id_number').val(),
            address: $('#address').val(),
            connection_type: $('#connection_type').val(),
            password: password,
            tax_pin: $('#tax_pin').val()
        };
		
        // Show loading
        const registerBtn = $('#registerBtn');
        registerBtn.prop('disabled', true);
        registerBtn.html('<span class="spinner-border spinner-border-sm"></span> Registering...');
		
        // Send request
        $.ajax({
            url: '/api/auth/register',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(formData),
            success: function(response) {
                if(response.status === 'success') {
                    var requiresPayment = response.data && response.data.requires_payment;
                    var baseMsg = response.message || 'Registration successful';
                    var extra = '';
                    if (response.data && response.data.account_number) {
                        extra = '\nYour account number is: ' + response.data.account_number;
                    }
                    var successMsg = baseMsg + extra;

                    if (window.showToast) {
                        showToast(successMsg, 'success');
                    } else {
                        $('#registerMessage')
                            .removeClass('d-none alert-danger')
                            .addClass('alert-success')
                            .html('<i class="bi bi-check-circle"></i> ' + baseMsg +
                                  (response.data && response.data.account_number ? '<br>Your account number is: <strong>' + response.data.account_number + '</strong>' : ''));
                    }

                    // Only clear form and redirect immediately when no registration fee is required
                    if (!requiresPayment) {
                        $('#registerForm')[0].reset();
                        $('#registerForm').removeClass('was-validated');
                        setTimeout(function() {
                            window.location.href = '/login?registered=true';
                        }, 5000);
                    } else {
                        // When registration fee is required, start a 59s countdown
                        // and poll the payment status using the checkout_request_id.
                        var checkoutId = response.data && response.data.checkout_request_id;

                        // Re-enable button so user isn't locked out of the page
                        registerBtn.prop('disabled', false);
                        registerBtn.html('<i class="bi bi-person-plus"></i> Create Account');

                        if (!checkoutId) {
                            // If for some reason we don't have an ID, just show a message.
                            if (window.showToast) {
                                showToast('Waiting for M-Pesa payment confirmation. Please complete the STK prompt on your phone.', 'info');
                            } else {
                                $('#registerMessage')
                                    .removeClass('d-none alert-danger')
                                    .addClass('alert-info')
                                    .html('<i class="bi bi-info-circle"></i> Waiting for M-Pesa payment confirmation.');
                            }
                            return;
                        }

                        var secondsRemaining = 59;
                        var countdownInterval = null;
                        var pollInterval = null;
                        var finished = false;

                        function updateCountdownMessage() {
                            var text = 'Waiting for M-Pesa payment confirmation... ' + secondsRemaining + 's remaining.';
                            $('#registerMessage')
                                .removeClass('d-none alert-danger alert-success')
                                .addClass('alert-info')
                                .html('<i class="bi bi-clock-history"></i> ' + text);
                        }

                        // Show initial countdown message
                        updateCountdownMessage();

                        countdownInterval = setInterval(function() {
                            if (finished) return;
                            if (secondsRemaining > 0) {
                                secondsRemaining--;
                                updateCountdownMessage();
                            }
                        }, 1000);

                        function stopTimers() {
                            finished = true;
                            if (countdownInterval) {
                                clearInterval(countdownInterval);
                                countdownInterval = null;
                            }
                            if (pollInterval) {
                                clearInterval(pollInterval);
                                pollInterval = null;
                            }
                        }

                        function handlePaymentSuccess() {
                            stopTimers();
                            if (window.showToast) {
                                showToast('Payment confirmed. Redirecting to login...', 'success');
                            } else {
                                $('#registerMessage')
                                    .removeClass('d-none alert-danger')
                                    .addClass('alert-success')
                                    .html('<i class="bi bi-check-circle"></i> Payment confirmed. Redirecting to login...');
                            }
                            setTimeout(function() {
                                window.location.href = '/login?registered=true';
                            }, 2000);
                        }

                        function handlePaymentError(message) {
                            stopTimers();
                            var msg = message || 'Payment failed or was not completed.';
                            if (window.showToast) {
                                showToast(msg, 'danger');
                            } else {
                                $('#registerMessage')
                                    .removeClass('d-none alert-success')
                                    .addClass('alert-danger')
                                    .html('<i class="bi bi-exclamation-triangle"></i> ' + msg);
                            }
                        }

                        function pollStatus() {
                            if (finished) return;
                            $.ajax({
                                url: '/api/payments/check_registration_status',
                                type: 'GET',
                                dataType: 'json',
                                data: { checkout_request_id: checkoutId },
                                success: function(data) {
                                    if (!data) return;
                                    if (data.status === 'success' && data.payment_status === 'completed') {
                                        handlePaymentSuccess();
                                    } else if (data.status === 'error' && data.payment_status === 'failed') {
                                        handlePaymentError(data.message);
                                    }
                                },
                                error: function() {
                                    // Ignore transient errors; countdown/timeout will handle.
                                }
                            });
                        }

                        // Start polling immediately, then every 3 seconds
                        pollStatus();
                        pollInterval = setInterval(pollStatus, 3000);

                        // Hard timeout after 59 seconds
                        setTimeout(function() {
                            if (finished) return;
                            handlePaymentError('Payment not confirmed within 59 seconds. If you approved the STK later, please try logging in; otherwise, please try again.');
                        }, 59000);

                        return;
                    }
                } else {
                    if (window.showToast) {
                        showToast(response.message || 'Registration failed','danger');
                    } else {
                        $('#registerMessage')
                            .removeClass('d-none alert-success')
                            .addClass('alert-danger')
                            .html('<i class="bi bi-exclamation-triangle"></i> ' + response.message);
                    }
                }
            },
            error: function(xhr) {
                const error = xhr.responseJSON ? xhr.responseJSON.message : 'Registration failed';
                if (window.showToast) {
                    showToast(error,'danger');
                } else {
                    $('#registerMessage')
                        .removeClass('d-none alert-success')
                        .addClass('alert-danger')
                        .html('<i class="bi bi-exclamation-triangle"></i> ' + error);
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
JS;
require_once __DIR__ . '/../templates/footer.php'; 
?>
