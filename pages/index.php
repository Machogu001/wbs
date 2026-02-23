<?php
session_start();
$page_title = "Home";
$hide_nav = false;
require_once __DIR__ . '/../templates/header.php';
?>

<div class="hero-section bg-primary text-white py-5">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h1 class="display-4 fw-bold">Water Billing Made Easy</h1>
                <p class="lead">Manage your water bills, make payments via M-Pesa, and track your consumption all in one place.</p>
                <?php if(!isset($_SESSION['user_id'])): ?>
                    <div class="mt-4">
                        <a href="/register" class="btn btn-light btn-lg me-3">
                            <i class="bi bi-person-plus"></i> Get Started
                        </a>
                        <a href="/login" class="btn btn-outline-light btn-lg">
                            <i class="bi bi-box-arrow-in-right"></i> Login
                        </a>
                    </div>
                <?php else: ?>
                    <div class="mt-4">
                        <a href="/dashboard" class="btn btn-light btn-lg me-3">
                            <i class="bi bi-speedometer2"></i> Go to Dashboard
                        </a>
                        <a href="/pay" class="btn btn-outline-light btn-lg">
                            <i class="bi bi-credit-card"></i> Pay Bill
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <div class="text-center">
                    <i class="bi bi-droplet" style="font-size: 8rem; opacity: 0.8;"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="features-section py-5">
    <div class="container">
        <h2 class="text-center mb-5">Why Choose Our System</h2>
        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="card h-100 text-center">
                    <div class="card-body">
                        <div class="feature-icon mb-3">
                            <i class="bi bi-phone text-primary" style="font-size: 3rem;"></i>
                        </div>
                        <h4 class="card-title">M-Pesa Payments</h4>
                        <p class="card-text">Pay your water bills instantly using M-Pesa from anywhere, anytime.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="card h-100 text-center">
                    <div class="card-body">
                        <div class="feature-icon mb-3">
                            <i class="bi bi-bell text-primary" style="font-size: 3rem;"></i>
                        </div>
                        <h4 class="card-title">SMS Notifications</h4>
                        <p class="card-text">Receive timely bill notifications and payment confirmations via SMS.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="card h-100 text-center">
                    <div class="card-body">
                        <div class="feature-icon mb-3">
                            <i class="bi bi-graph-up text-primary" style="font-size: 3rem;"></i>
                        </div>
                        <h4 class="card-title">Usage Tracking</h4>
                        <p class="card-text">Monitor your water consumption and track your payment history.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
