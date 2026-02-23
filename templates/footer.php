    </main>
    
    <?php
    // Load billing settings for footer contact details
    $footerSupportPhone = '+254 700 000 000';
    $footerSupportEmail = 'support@waterbilling.com';

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
                            <li><a href="/register" class="text-white-50">Register</a></li>
                            <li><a href="/login" class="text-white-50">Login</a></li>
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
    </script>

    <?php if(isset($custom_scripts)): ?>
        <?php echo $custom_scripts; ?>
    <?php endif; ?>
</body>
</html>
