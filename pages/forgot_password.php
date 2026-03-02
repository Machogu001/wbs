<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: /dashboard');
    exit;
}

$page_title = 'Forgot Password';
$hide_nav = true;
require_once __DIR__ . '/../templates/header.php';
?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h3 class="text-center mb-0"><i class="bi bi-key"></i> Forgot Password</h3>
                </div>
                <div class="card-body">
                    <p class="mb-4">Enter your account number, phone number or email address. If we find a matching account, we will send a new password to your registered phone number via SMS.</p>

                    <div id="forgotMessage" class="alert d-none"></div>

                    <form id="forgotPasswordForm" novalidate>
                        <div class="mb-3">
                            <label for="identifier" class="form-label">Account Number, Phone or Email *</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control" id="identifier" name="identifier"
                                       placeholder="e.g. MTR0001, 07XXXXXXXX or name@example.com" required>
                            </div>
                            <div class="invalid-feedback">Please enter your account number, phone or email.</div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg" id="forgotBtn">
                                <i class="bi bi-arrow-repeat"></i> Reset Password
                            </button>
                            <a href="/login" class="btn btn-link"><i class="bi bi-box-arrow-in-right"></i> Back to Login</a>
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
    $('#forgotPasswordForm').on('submit', function(e) {
        e.preventDefault();

        if (!this.checkValidity()) {
            e.stopPropagation();
            $(this).addClass('was-validated');
            return;
        }

        const formData = {
            identifier: $('#identifier').val()
        };

        const btn = $('#forgotBtn');
        btn.prop('disabled', true);
        btn.html('<span class="spinner-border spinner-border-sm"></span> Processing...');

        $.ajax({
            url: '/api/auth/forgot_password',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(formData),
            success: function(response) {
                const message = response.message || 'If the account exists, a new password has been sent to the registered phone number.';
                if (window.showToast) {
                    showToast(message, 'success');
                } else {
                    $('#forgotMessage')
                        .removeClass('d-none alert-danger')
                        .addClass('alert-success')
                        .html('<i class="bi bi-check-circle"></i> ' + message);
                }
            },
            error: function(xhr) {
                const error = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to process request';
                if (window.showToast) {
                    showToast(error, 'danger');
                } else {
                    $('#forgotMessage')
                        .removeClass('d-none alert-success')
                        .addClass('alert-danger')
                        .html('<i class="bi bi-exclamation-triangle"></i> ' + error);
                }
            },
            complete: function() {
                btn.prop('disabled', false);
                btn.html('<i class="bi bi-arrow-repeat"></i> Reset Password');
            }
        });
    });
});
</script>
JS;
require_once __DIR__ . '/../templates/footer.php';
?>
