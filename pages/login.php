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

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h3 class="text-center mb-0"><i class="bi bi-box-arrow-in-right"></i> Login</h3>
                </div>
                <div class="card-body">
                    <?php if($registered): ?>
                        <script>
                        window.addEventListener('load', function() {
                            if (window.showToast) {
                                showToast('Registration successful! Please login with your account number, phone or email.','success');
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
                        <div class="mb-3">
                            <label for="identifier" class="form-label">Account Number, Phone or Email *</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control" id="identifier" name="identifier" 
                                       placeholder="e.g. MTR0001, 07XXXXXXXX or name@example.com" required>
                            </div>
                            <div class="invalid-feedback">Please enter your account number, phone or email.</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Password *</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="password" name="password" required>
                                <button class="btn btn-outline-secondary toggle-password" type="button">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="d-flex justify-content-between mt-1">
                                <small class="text-muted">Please enter your password.</small>
                                <a href="/forgot-password" class="small">Forgot password?</a>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg" id="loginBtn">
                                <i class="bi bi-box-arrow-in-right"></i> Login
                            </button>
                            <a href="/register" class="btn btn-link">Don't have an account? Register here</a>
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
    $('#loginForm').on('submit', function(e) {
        e.preventDefault();
        
        // Validate form
        if(!this.checkValidity()) {
            e.stopPropagation();
            $(this).addClass('was-validated');
            return;
        }
        
        // Prepare data
        const formData = {
            identifier: $('#identifier').val(),
            password: $('#password').val()
        };
        
        // Show loading
        const loginBtn = $('#loginBtn');
        loginBtn.prop('disabled', true);
        loginBtn.html('<span class=\"spinner-border spinner-border-sm\"></span> Logging in...');
        
        // Send request
        $.ajax({
            url: '/api/auth/login',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(formData),
            success: function(response) {
                if(response.status === 'success') {
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

                        // Redirect to registration payment page instead of dashboard
                        setTimeout(function() {
                            window.location.href = '/registration-payment';
                        }, 1000);
                    } else if (requiresPasswordChange) {
                        if (window.showToast) {
                            showToast('Login successful! Please change your password.','success');
                        } else {
                            $('#loginMessage')
                                .removeClass('d-none alert-danger')
                                .addClass('alert-success')
                                .html('<i class="bi bi-check-circle"></i> Login successful! Redirecting to change password...');
                        }

						// Redirect to change-password page first
						setTimeout(function() {
							window.location.href = '/change-password';
						}, 1000);
                    } else {
                        if (window.showToast) {
                            showToast('Login successful! Redirecting...','success');
                        } else {
                            $('#loginMessage')
                                .removeClass('d-none alert-danger')
                                .addClass('alert-success')
                                .html('<i class="bi bi-check-circle"></i> Login successful! Redirecting...');
                        }

                        // Redirect to dashboard for normal logins
                        setTimeout(function() {
                            window.location.href = '/dashboard';
                        }, 1000);
                    }
                } else {
                    if (window.showToast) {
                        showToast(response.message || 'Login failed','danger');
                    } else {
                        $('#loginMessage')
                            .removeClass('d-none alert-success')
                            .addClass('alert-danger')
                            .html('<i class=\"bi bi-exclamation-triangle\"></i> ' + response.message);
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
