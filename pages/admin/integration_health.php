<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/IntegrationHealth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
if (!$auth->isLoggedIn() || !$auth->isAdmin()) {
    header('Location: /login');
    exit;
}

$health = new IntegrationHealth($db);
$summary = $health->getLiveSummary();

$page_title = 'Integration Health';
$is_admin_page = true;
include __DIR__ . '/../../templates/header.php';
?>
<div class="container-fluid mt-4 admin-shell integration-health-page">
    <div class="pb-banner pb-banner--cyan mb-4">
        <div class="pb-bg" aria-hidden="true">
            <div class="pb-grid"></div>
            <div class="pb-blob pb-blob--a"></div>
            <div class="pb-blob pb-blob--b"></div>
            <i class="bi bi-activity pb-watermark"></i>
        </div>
        <div class="pb-inner">
            <div class="pb-left">
                <div class="pb-eyebrow-row">
                    <span class="pb-eyebrow-chip"><i class="bi bi-activity"></i> System Integrations</span>
                </div>
                <h2 class="pb-title">Integration Health</h2>
                <p class="pb-subtitle">Monitor configuration, last success, and last errors across external services.</p>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <?php foreach ($summary as $service => $item): ?>
            <div class="col-lg-3 col-md-6">
                <div class="card admin-kpi-card h-100">
                    <div class="card-body">
                        <h5 class="card-title text-uppercase"><?php echo htmlspecialchars($service); ?></h5>
                        <p class="mb-2"><strong>Configured:</strong> <?php echo !empty($item['configured']) ? 'Yes' : 'No'; ?></p>
                        <?php if (isset($item['pending'])): ?><p class="mb-1"><strong>Pending:</strong> <?php echo (int)$item['pending']; ?></p><?php endif; ?>
                        <?php if (isset($item['failed'])): ?><p class="mb-1"><strong>Failed:</strong> <?php echo (int)$item['failed']; ?></p><?php endif; ?>
                        <p class="mb-1"><strong>Last Success:</strong> <?php echo !empty($item['last_success']) ? htmlspecialchars(date('d-m-Y H:i', strtotime((string)$item['last_success']))) : '-'; ?></p>
                        <p class="mb-0"><strong>Last Error:</strong> <?php echo !empty($item['last_error']['error_message']) ? htmlspecialchars(substr((string)$item['last_error']['error_message'], 0, 80)) : '-'; ?></p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php include __DIR__ . '/../../templates/footer.php'; ?>
