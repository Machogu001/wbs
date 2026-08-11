<?php
session_start();
if(isset($_SESSION['user_id'])) {
    header("Location: /dashboard");
    exit;
}

$page_title = "Login";
$hide_nav = true;
require_once __DIR__ . '/../templates/header.php';

// Check if user just registered or logged out
$registered = isset($_GET['registered']) && $_GET['registered'] == 'true';

// One-time logout flash message via cookie
$logged_out = isset($_COOKIE['flash_logged_out']) && $_COOKIE['flash_logged_out'] === '1';
if ($logged_out) {
    $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    // Clear the flash cookie immediately so it only shows once
    setcookie('flash_logged_out', '', time() - 3600, '/', '', $isSecure, true);
}
?>

<div class="container mt-5 auth-login-page">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card auth-main-card">
                <div class="card-header bg-primary text-white">
                    <h3 class="text-center mb-0"><i class="bi bi-box-arrow-in-right"></i> Login</h3>
                </div>
                <div class="card-body">
                    <?php if($registered): ?>
                        <script>
                        window.addEventListener('load', function() {
                            if (window.showToast) {
                                showToast('Registration successful! Please login with your username, account number, phone or email.','success');
                            }
                        });
                        </script>
                    <?php endif; ?>
                    <?php if($logged_out): ?>
                        <script>
                        window.addEventListener('load', function() {
                            if (window.showToast) {
                                showToast('Logged out successfully.','success');
                            }
                        });
                        </script>
                    <?php endif; ?>
					
                    <div id="loginMessage" class="alert d-none"></div>
                    
                    <form id="loginForm" novalidate>
                        <div id="loginCredentialsSection">
                            <div class="mb-3">
                                <label for="identifier" class="form-label">Username, Account Number, Phone or Email *</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                                    <input type="text" class="form-control" id="identifier" name="identifier" 
                                           placeholder="e.g. MTR0001, 07XXXXXXXX or name@example.com" autocomplete="username" required>
                                </div>
                                <div class="invalid-feedback">Please enter your username, account number, phone or email.</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">Password *</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                                    <button class="btn btn-outline-secondary toggle-password" type="button" aria-label="Show or hide password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" id="showPasswordToggle">
                                        <label class="form-check-label small" for="showPasswordToggle">Show password</label>
                                    </div>
                                    <a href="/forgot-password" class="small">Forgot password?</a>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg" id="loginBtn">
                                    <i class="bi bi-box-arrow-in-right"></i> Login
                                </button>
                                <a href="/register" class="btn btn-link">Don't have an account? Register here</a>
                            </div>
                        </div>

                        <div id="loginTwoFactorSection" class="d-none">
                            <h5 class="mb-2"><i class="bi bi-shield-lock"></i> Enter verification code</h5>
                            <p class="small text-muted mb-1" id="loginTwoFactorMessage">We sent a code to your phone. Enter it below to finish logging in.</p>
                            <p class="small text-muted mb-3">
                                Didn't receive the code?
                                <span><a href="#" id="loginTwoFactorUsePhone">Use phone</a></span>
                                <span class="ms-2"><a href="#" id="loginTwoFactorUseEmail">Use email</a></span>
                                <span class="ms-2 text-primary fw-semibold d-none" id="loginTwoFactorCountdown"></span>
                            </p>
                            <div class="mb-3">
                                <label for="two_factor_code" class="form-label">6-digit code</label>
                                <input type="text" class="form-control" id="two_factor_code" placeholder="6-digit code" autocomplete="one-time-code">
                            </div>
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-primary btn-lg" id="verify2faBtn">Verify and Login</button>
                                <button type="button" class="btn btn-link" id="backToCredentialsBtn">Back to login details</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
$custom_scripts = <<<'JS'
<script>
$(document).ready(function() {
    var twoFactorAvailableMethods = [];
    var twoFactorCurrentMethod = null;
    var twoFactorVerifying = false;
    var twoFactorResendTimer = null;
    var twoFactorResendRemaining = 0;
    // Show/hide password using the checkbox
    $('#showPasswordToggle').on('change', function() {
        var input = $('#password');
        if (this.checked) {
            input.attr('type', 'text');
        } else {
            input.attr('type', 'password');
        }
    });

    function handlePostLoginSuccess(response) {
        var requiresRegPayment = response.data && response.data.requires_registration_payment;
        var requiresPasswordChange = response.data && response.data.requires_password_change;

        if (requiresRegPayment) {
            if (window.showToast) {
                showToast('Login successful. Please complete your registration payment.','info');
            } else {
                $('#loginMessage')
                    .removeClass('d-none alert-danger')
                    .addClass('alert-info')
                    .html('<i class="bi bi-info-circle"></i> Login successful. Please complete your registration payment.');
            }

            setTimeout(function() {
                window.location.href = '/registration-payment';
            }, 1000);
            return;
        }

        if (requiresPasswordChange) {
            if (window.showToast) {
                showToast('Login successful! Please change your password.','success');
            } else {
                $('#loginMessage')
                    .removeClass('d-none alert-danger')
                    .addClass('alert-success')
                    .html('<i class="bi bi-check-circle"></i> Login successful! Redirecting to change password...');
            }

            setTimeout(function() {
                window.location.href = '/change-password';
            }, 1000);
            return;
        }

        if (window.showToast) {
            showToast('Login successful! Redirecting...','success');
        } else {
            $('#loginMessage')
                .removeClass('d-none alert-danger')
                .addClass('alert-success')
                .html('<i class="bi bi-check-circle"></i> Login successful! Redirecting...');
        }

        setTimeout(function() {
            window.location.href = '/dashboard';
        }, 1000);
    }

    function showTwoFactorPrompt(methodLabel) {
        var $credentials = $('#loginCredentialsSection');
        var $twofa = $('#loginTwoFactorSection');
        var $msg = $('#loginTwoFactorMessage');
        if (!$credentials.length || !$twofa.length) return;

        var where = methodLabel === 'email' ? 'email' : 'phone';
        $msg.text('We sent a code to your ' + where + '. Enter it below to finish logging in.');

        $credentials.addClass('d-none');
        $twofa.removeClass('d-none');
        $('#two_factor_code').val('').focus();
    }

    function startTwoFactorCooldownMain(seconds) {
        var $phoneLink = $('#loginTwoFactorUsePhone');
        var $emailLink = $('#loginTwoFactorUseEmail');
        var $countdown = $('#loginTwoFactorCountdown');
        if (!$countdown.length) return;

        if (twoFactorResendTimer) {
            clearInterval(twoFactorResendTimer);
        }

        twoFactorResendRemaining = parseInt(seconds, 10) || 0;
        if (twoFactorResendRemaining <= 0) {
            return;
        }

        if ($phoneLink.length) {
            $phoneLink.addClass('disabled').css('pointer-events', 'none');
        }
        if ($emailLink.length) {
            $emailLink.addClass('disabled').css('pointer-events', 'none');
        }

        $countdown.removeClass('d-none');
        $countdown.text('You can resend in ' + twoFactorResendRemaining + 's');

        twoFactorResendTimer = setInterval(function() {
            twoFactorResendRemaining--;
            if (twoFactorResendRemaining <= 0) {
                clearInterval(twoFactorResendTimer);
                twoFactorResendTimer = null;
                $countdown.addClass('d-none').text('');
                if ($phoneLink.length) {
                    $phoneLink.removeClass('disabled').css('pointer-events', '');
                }
                if ($emailLink.length) {
                    $emailLink.removeClass('disabled').css('pointer-events', '');
                }
            } else {
                $countdown.text('You can resend in ' + twoFactorResendRemaining + 's');
            }
        }, 1000);
    }

    function updateTwoFactorMethodSwitch() {
        var $phoneLink = $('#loginTwoFactorUsePhone');
        var $emailLink = $('#loginTwoFactorUseEmail');
        if (!twoFactorAvailableMethods.length) {
            if ($phoneLink.length) $phoneLink.closest('span').hide();
            if ($emailLink.length) $emailLink.closest('span').hide();
            return;
        }

        if ($phoneLink.length) {
            var showPhone = twoFactorAvailableMethods.indexOf('sms') !== -1;
            $phoneLink.closest('span').toggle(showPhone);
        }
        if ($emailLink.length) {
            var showEmail = twoFactorAvailableMethods.indexOf('email') !== -1;
            $emailLink.closest('span').toggle(showEmail);
        }
    }

    function setTwoFactorResendBusy(isBusy) {
        var $phoneLink = $('#loginTwoFactorUsePhone');
        var $emailLink = $('#loginTwoFactorUseEmail');

        if ($phoneLink.length) {
            $phoneLink.toggleClass('disabled', isBusy).css('pointer-events', isBusy ? 'none' : '');
        }
        if ($emailLink.length) {
            $emailLink.toggleClass('disabled', isBusy).css('pointer-events', isBusy ? 'none' : '');
        }
    }

    function requestTwoFactorResend(method) {
        var methodLabel = method === 'email' ? 'email' : 'phone';

        if (twoFactorResendRemaining > 0) {
            if (window.showToast) {
                showToast('Please wait ' + twoFactorResendRemaining + ' seconds before requesting another code.','warning');
            }
            return;
        }

        if (twoFactorAvailableMethods.indexOf(method) === -1) {
            if (window.showToast) {
                showToast('This account cannot receive a verification code by ' + methodLabel + '.','warning');
            }
            return;
        }

        setTwoFactorResendBusy(true);

        $.ajax({
            url: '/api/auth/resend_2fa',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ method: method }),
            success: function(resp) {
                if (resp.status === 'success') {
                    var actualMethod = (resp.data && resp.data.method) ? resp.data.method : method;
                    var label = actualMethod === 'email' ? 'email' : 'phone';
                    twoFactorCurrentMethod = actualMethod;
                    if (resp.data && $.isArray(resp.data.available_methods) && resp.data.available_methods.length) {
                        twoFactorAvailableMethods = resp.data.available_methods;
                        updateTwoFactorMethodSwitch();
                    }
                    if (window.showToast) {
                        showToast(resp.message || ('Verification code sent to your ' + label + '.'),'info');
                    }
                    showTwoFactorPrompt(actualMethod === 'email' ? 'email' : 'phone');
                    startTwoFactorCooldownMain(60);
                    if (actualMethod === 'sms' && window.startWebOtpListener) startWebOtpListener('#two_factor_code');
                } else if (resp.status === 'cooldown') {
                    var remaining = resp.data && resp.data.remaining ? resp.data.remaining : 0;
                    if (remaining > 0) {
                        startTwoFactorCooldownMain(remaining);
                    }
                    if (window.showToast) {
                        showToast(resp.message || 'Please wait before requesting another code.','warning');
                    }
                } else if (window.showToast) {
                    showToast(resp.message || 'Could not resend code.','danger');
                }
            },
            error: function(xhr) {
                var error = xhr.responseJSON ? xhr.responseJSON.message : 'Could not resend code.';
                if (window.showToast) {
                    showToast(error,'danger');
                }
            },
            complete: function() {
                if (twoFactorResendRemaining <= 0) {
                    setTwoFactorResendBusy(false);
                }
            }
        });
    }

    function submitTwoFactorCode() {
        var code = $('#two_factor_code').val().trim();
        if (!code) {
            if (window.showToast) {
                showToast('Please enter the verification code.','danger');
            }
            return;
        }

        if (twoFactorVerifying) {
            return;
        }
        twoFactorVerifying = true;

        var $btn = $('#verify2faBtn');
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
                    handlePostLoginSuccess(response);
                } else {
                    if (window.showToast) {
                        showToast(response.message || 'Verification failed','danger');
                    }
                }
            },
            error: function(xhr) {
                var error = xhr.responseJSON ? xhr.responseJSON.message : 'Verification failed';
                if (window.showToast) {
                    showToast(error,'danger');
                }
            },
            complete: function() {
                if ($btn.length) {
                    $btn.prop('disabled', false).html('Verify and Login');
                }
                twoFactorVerifying = false;
            }
        });
    }

    $(document).on('click', '#verify2faBtn', function() {
        submitTwoFactorCode();
    });

    $(document).on('click', '#backToCredentialsBtn', function() {
        if (window.stopWebOtpListener) stopWebOtpListener();
        $('#loginTwoFactorSection').addClass('d-none');
        $('#loginCredentialsSection').removeClass('d-none');
    });

    // Auto-verify when 6-digit code is fully entered
    $('#two_factor_code').on('input', function() {
        var val = $(this).val().replace(/\D/g, '');
        $(this).val(val);
        if (val.length === 6) {
            submitTwoFactorCode();
        }
    });

    $(document).on('click', '#loginTwoFactorUsePhone', function(e) {
        e.preventDefault();
        requestTwoFactorResend('sms');
    });

    $(document).on('click', '#loginTwoFactorUseEmail', function(e) {
        e.preventDefault();
        requestTwoFactorResend('email');
    });

    $('#loginForm').on('submit', function(e) {
        e.preventDefault();

        if(!this.checkValidity()) {
            e.stopPropagation();
            $(this).addClass('was-validated');
            return;
        }

        const formData = {
            identifier: $('#identifier').val(),
            password: $('#password').val()
        };

        const loginBtn = $('#loginBtn');
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
                    twoFactorCurrentMethod = method;
                    twoFactorAvailableMethods = (response.data && response.data.available_methods) || [method];
                    if (window.showToast) {
                        showToast('Verification code sent to your ' + label + '.','info');
                    }
                    showTwoFactorPrompt(label);
                    updateTwoFactorMethodSwitch();
                    // Start initial resend cooldown
                    startTwoFactorCooldownMain(60);
                    // WebOTP: auto-fill SMS code on phones/tablets
                    if (window.startWebOtpListener) startWebOtpListener('#two_factor_code');
                } else if (response.status === 'success') {
                    handlePostLoginSuccess(response);
                } else {
                    if (window.showToast) {
                        showToast(response.message || 'Login failed','danger');
                    } else {
                        $('#loginMessage')
                            .removeClass('d-none alert-success')
                            .addClass('alert-danger')
                            .html('<i class="bi bi-exclamation-triangle"></i> ' + response.message);
                    }
                }
            },
            error: function(xhr) {
                const error = xhr.responseJSON ? xhr.responseJSON.message : 'Login failed';
                if (window.showToast) {
                    showToast(error,'danger');
                } else {
                    $('#loginMessage')
                        .removeClass('d-none alert-success')
                        .addClass('alert-danger')
                        .html('<i class="bi bi-exclamation-triangle"></i> ' + error);
                }
            },
            complete: function() {
                loginBtn.prop('disabled', false);
                loginBtn.html('<i class="bi bi-box-arrow-in-right"></i> Login');
            }
        });
    });
});
</script>
JS;
require_once __DIR__ . '/../templates/footer.php'; 
?>
