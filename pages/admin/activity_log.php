<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

$is_admin_page = true;

if(!$auth->isLoggedIn() || !$auth->isAdmin()) {
	header("Location: /login");
	exit;
}

// Ensure a CSRF token exists for this page's forms
if (empty($_SESSION['activity_log_csrf'])) {
	$_SESSION['activity_log_csrf'] = bin2hex(random_bytes(32));
}

// Handle bulk delete of selected log entries
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected']) && !empty($_POST['log_ids']) && is_array($_POST['log_ids'])) {
	$csrfToken = (string)($_POST['csrf_token'] ?? '');
	if (!hash_equals($_SESSION['activity_log_csrf'], $csrfToken)) {
		http_response_code(403);
		die('Security validation failed. Please refresh and try again.');
	}
	$ids = array_filter(array_map('intval', $_POST['log_ids']), function($v) { return $v > 0; });
	if (!empty($ids) && $db) {
		$placeholders = implode(',', array_fill(0, count($ids), '?'));
		$sqlDelete = "DELETE FROM activity_log WHERE id IN ($placeholders)";
		$stmtDel = $db->prepare($sqlDelete);
		foreach ($ids as $index => $id) {
			$stmtDel->bindValue($index + 1, $id, PDO::PARAM_INT);
		}
		$stmtDel->execute();
		$_SESSION['activity_log_message'] = 'Selected log entries have been deleted.';
	}
	header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#'));
	exit;
}

$page_title = "Admin - Activity Log";
require_once __DIR__ . '/../../templates/header.php';

// Filters
$from = isset($_GET['from']) ? trim($_GET['from']) : '';
$to = isset($_GET['to']) ? trim($_GET['to']) : '';
$userIdFilter = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$actionFilter = isset($_GET['action']) ? trim($_GET['action']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$networkOwnerFilter = isset($_GET['network_owner']) ? trim($_GET['network_owner']) : '';
$asnFilter = isset($_GET['asn']) ? trim($_GET['asn']) : '';

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$pageSize = 20;
$offset = ($page - 1) * $pageSize;

$where = array();
$params = array();


if ($from !== '') {
	$where[] = "DATE(a.created_at) >= :from";
	$params[':from'] = $from;
}
if ($to !== '') {
	$where[] = "DATE(a.created_at) <= :to";
	$params[':to'] = $to;
}
if ($userIdFilter > 0) {
	$where[] = "a.user_id = :user_id";
	$params[':user_id'] = $userIdFilter;
}
if ($actionFilter !== '') {
	$where[] = "a.action = :action";
	$params[':action'] = $actionFilter;
}
if ($search !== '') {
	$where[] = "(a.description LIKE :search OR a.entity_type LIKE :search)";
	$params[':search'] = '%' . $search . '%';
}
if ($networkOwnerFilter !== '') {
	$where[] = "a.metadata LIKE :network_owner";
	$params[':network_owner'] = '%"network_org":"' . str_replace(array('%', '_'), array('\\%', '\\_'), $networkOwnerFilter) . '%';
}
if ($asnFilter !== '') {
	$where[] = "a.metadata LIKE :asn";
	$params[':asn'] = '%"asn":"' . str_replace(array('%', '_'), array('\\%', '\\_'), $asnFilter) . '%';
}

$whereSql = '';
if (!empty($where)) {
	$whereSql = 'WHERE ' . implode(' AND ', $where);
}

// Count total
$total = 0;
$dbError = '';
if ($db) {
	try {
		$sqlCount = "SELECT COUNT(*) FROM activity_log a " . $whereSql;
		$stmtCount = $db->prepare($sqlCount);
		foreach ($params as $k => $v) {
			$stmtCount->bindValue($k, $v);
		}
		$stmtCount->execute();
		$total = (int)$stmtCount->fetchColumn();
	} catch (Exception $e) {
		$total = 0;
		$dbError = 'Unable to load activity summary for the selected filters: ' . $e->getMessage();
	}
}

$totalPages = max(1, (int)ceil($total / $pageSize));
if ($page > $totalPages) {
	$page = $totalPages;
	$offset = ($page - 1) * $pageSize;
}

// Fetch logs
$logs = array();
if ($db) {
	try {
		$sql = "SELECT a.*, u.full_name, u.account_number, l.location_label AS lookup_location
			FROM activity_log a
			LEFT JOIN users u ON a.user_id = u.id
			LEFT JOIN activity_ip_lookup l ON l.ip_address = a.ip_address
			" . $whereSql . "
			ORDER BY a.created_at DESC
			LIMIT :limit OFFSET :offset";
		$stmt = $db->prepare($sql);
		foreach ($params as $k => $v) {
			$stmt->bindValue($k, $v);
		}
		$stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
		$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
		$stmt->execute();
		$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
	} catch (Exception $e) {
		$logs = array();
		if ($dbError === '') {
			$dbError = 'Unable to load activity records for the selected filters: ' . $e->getMessage();
		}
	}
}

// Load list of admin users for filter dropdown
$admins = array();
if ($db) {
	$stmtAdmins = $db->query("SELECT id, full_name, account_number FROM users WHERE role = 'admin' ORDER BY full_name ASC");
	$admins = $stmtAdmins ? $stmtAdmins->fetchAll(PDO::FETCH_ASSOC) : array();
}

$toastMessage = '';
$toastType = 'success';
if (!empty($_SESSION['activity_log_message'])) {
	$toastMessage = (string)$_SESSION['activity_log_message'];
	$toastType = 'success';
	unset($_SESSION['activity_log_message']);
} elseif (!empty($dbError)) {
	$toastMessage = (string)$dbError;
	$toastType = 'warning';
}

$networkOwnerOptions = array();
$asnOptions = array();
if ($db) {
	try {
		$stmtOwners = $db->query("SELECT metadata FROM activity_log WHERE metadata IS NOT NULL AND metadata <> '' ORDER BY created_at DESC LIMIT 1500");
		$ownerSeen = array();
		$asnSeen = array();
		foreach (($stmtOwners ? $stmtOwners->fetchAll(PDO::FETCH_ASSOC) : array()) as $row) {
			$meta = parseActivityMetadata($row['metadata'] ?? '');
			$owner = trim((string)($meta['network_org'] ?? ''));
			$asn = trim((string)($meta['asn'] ?? ''));
			if ($owner !== '' && !isset($ownerSeen[$owner])) {
				$ownerSeen[$owner] = true;
				$networkOwnerOptions[] = $owner;
			}
			if ($asn !== '' && !isset($asnSeen[$asn])) {
				$asnSeen[$asn] = true;
				$asnOptions[] = $asn;
			}
		}
		sort($networkOwnerOptions, SORT_NATURAL | SORT_FLAG_CASE);
		sort($asnOptions, SORT_NATURAL | SORT_FLAG_CASE);
	} catch (Exception $e) {
		$networkOwnerOptions = array();
		$asnOptions = array();
	}
}

function parseActivityMetadata($raw)
{
	if (is_array($raw)) {
		return $raw;
	}
	if (!is_string($raw) || trim($raw) === '') {
		return array();
	}
	$decoded = json_decode($raw, true);
	return is_array($decoded) ? $decoded : array();
}

function isGenericLocationLabel($label)
{
	$normalized = strtolower(trim((string)$label));
	if ($normalized === '') {
		return true;
	}

	return in_array($normalized, array(
		'public network',
		'private/local network',
		'server/unknown origin',
		'unknown',
	), true);
}

function deriveLocationLabel($ip, array $metadata, $lookupLocation = '')
{
	$metaLocation = !empty($metadata['location']) && is_string($metadata['location'])
		? trim($metadata['location'])
		: '';
	$lookupLocation = trim((string)$lookupLocation);

	if ($metaLocation !== '' && !isGenericLocationLabel($metaLocation)) {
		return $metaLocation;
	}
	if ($lookupLocation !== '' && !isGenericLocationLabel($lookupLocation)) {
		return $lookupLocation;
	}
	if ($metaLocation !== '') {
		return $metaLocation;
	}
	if (!empty($metadata['execution_context']) && stripos((string)$metadata['execution_context'], 'cli') !== false) {
		return 'Server (CLI)';
	}
	$ip = trim((string)$ip);
	if ($ip === '') {
		return 'Server/Unknown Origin';
	}
	if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
		return 'Private/Local Network';
	}
	if (function_exists('geoip_country_name_by_name')) {
		$country = @geoip_country_name_by_name($ip);
		if (!empty($country)) {
			return (string)$country;
		}
	}
	return 'Public Network';
}

function deriveGadgetLabel(array $metadata)
{
	$device = !empty($metadata['device_type']) ? (string)$metadata['device_type'] : 'Unknown';
	$browser = !empty($metadata['browser']) ? (string)$metadata['browser'] : '';
	$os = !empty($metadata['os']) ? (string)$metadata['os'] : '';
	$context = !empty($metadata['execution_context']) ? strtolower((string)$metadata['execution_context']) : '';

	if ($context !== '' && strpos($context, 'cli') !== false && $device === 'Unknown' && $browser === '' && $os === '') {
		return 'Server Task / CLI';
	}

	if ($device === 'Unknown' && $browser === '' && $os === '') {
		return '-';
	}

	$parts = array();
	if ($device !== 'Unknown') {
		$parts[] = $device;
	}
	if ($browser !== '') {
		$parts[] = $browser;
	}
	if ($os !== '') {
		$parts[] = $os;
	}

	return !empty($parts) ? implode(' / ', $parts) : '-';
}

function deriveNetworkOwnerLabel(array $metadata)
{
	$org = !empty($metadata['network_org']) ? (string)$metadata['network_org'] : '';
	$asn = !empty($metadata['asn']) ? (string)$metadata['asn'] : '';

	if ($org === '' && $asn === '') {
		return '-';
	}
	if ($org !== '' && $asn !== '') {
		return $org . ' (' . $asn . ')';
	}
	return $org !== '' ? $org : $asn;
}
?>

<style>
.activity-log-wrap {
	padding-left: 0.5rem;
	padding-right: 0.5rem;
}

.activity-log-table {
	min-width: 1380px;
}

.activity-log-table th {
	white-space: nowrap;
	font-size: 0.8rem;
}

.activity-log-col-time,
.activity-log-col-action,
.activity-log-col-entity,
.activity-log-col-location,
.activity-log-col-network,
.activity-log-col-ip {
	white-space: nowrap;
}

.activity-log-col-admin,
.activity-log-col-gadget,
.activity-log-col-desc {
	white-space: normal;
}

.activity-log-col-desc {
	min-width: 260px;
	max-width: 420px;
}

.activity-log-col-gadget {
	min-width: 180px;
	max-width: 260px;
}
</style>

<div class="container-fluid mt-4 mb-4 admin-shell activity-log-wrap" id="activityLogDensityTarget">
	<div class="row">
		<div class="col-12">
			<div class="pb-banner pb-banner--slate mb-4">
					<div class="pb-bg" aria-hidden="true">
							<div class="pb-grid"></div>
							<div class="pb-blob pb-blob--a"></div>
							<div class="pb-blob pb-blob--b"></div>
							<i class="bi bi-clock-history pb-watermark"></i>
					</div>
					<div class="pb-inner">
							<div class="pb-left">
									<div class="pb-eyebrow-row">
											<span class="pb-eyebrow-chip"><i class="bi bi-clock-history"></i> Audit &amp; Compliance</span>
									</div>
									<h2 class="pb-title">Activity Log</h2>
									<p class="pb-subtitle">Audit trail of key actions in the system.</p>
							</div>
				</div>
			</div>
		</div>
	</div>

	<div class="card mt-3 mb-3">
		<div class="card-header d-flex justify-content-between align-items-center">
			<div>
				<h6 class="mb-0">Filters</h6>
				<small class="text-muted">Narrow down by date, admin, action, or text.</small>
			</div>
			<div>
				<button type="button" class="btn btn-sm btn-outline-secondary" data-density-toggle data-density-target="#activityLogDensityTarget" data-density-key="activity-log-table" data-density-compact-text="Compact View" data-density-comfy-text="Comfortable View">
					<i class="bi bi-arrows-collapse"></i> <span class="js-density-label">Compact View</span>
				</button>
			</div>
		</div>
		<div class="card-body">
			<form method="get" class="row gy-2 gx-3 align-items-end">
				<div class="col-sm-3 col-md-2">
					<label for="from" class="form-label">From</label>
					<input type="date" id="from" name="from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($from); ?>">
				</div>
				<div class="col-sm-3 col-md-2">
					<label for="to" class="form-label">To</label>
					<input type="date" id="to" name="to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($to); ?>">
				</div>
				<div class="col-sm-3 col-md-3">
					<label for="user_id" class="form-label">Admin</label>
					<select id="user_id" name="user_id" class="form-select form-select-sm">
						<option value="0">All admins</option>
						<?php foreach ($admins as $admin): ?>
							<option value="<?php echo (int)$admin['id']; ?>" <?php echo $userIdFilter === (int)$admin['id'] ? 'selected' : ''; ?>>
								<?php echo htmlspecialchars($admin['full_name']); ?> (<?php echo htmlspecialchars($admin['account_number']); ?>)
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-sm-3 col-md-2">
					<label for="action" class="form-label">Action</label>
					<input type="text" id="action" name="action" class="form-control form-control-sm" placeholder="e.g. update_settings" value="<?php echo htmlspecialchars($actionFilter); ?>">
				</div>
				<div class="col-sm-6 col-md-3">
					<label for="search" class="form-label">Contains</label>
					<input type="text" id="search" name="search" class="form-control form-control-sm" placeholder="Description or entity" value="<?php echo htmlspecialchars($search); ?>">
				</div>
				<div class="col-sm-6 col-md-3">
					<label for="network_owner" class="form-label">Network Owner</label>
					<select id="network_owner" name="network_owner" class="form-select form-select-sm">
						<option value="">All networks</option>
						<?php foreach ($networkOwnerOptions as $owner): ?>
							<option value="<?php echo htmlspecialchars($owner); ?>" <?php echo $networkOwnerFilter === $owner ? 'selected' : ''; ?>>
								<?php echo htmlspecialchars($owner); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-sm-6 col-md-2">
					<label for="asn" class="form-label">ASN</label>
					<select id="asn" name="asn" class="form-select form-select-sm">
						<option value="">All ASN</option>
						<?php foreach ($asnOptions as $asn): ?>
							<option value="<?php echo htmlspecialchars($asn); ?>" <?php echo $asnFilter === $asn ? 'selected' : ''; ?>>
								<?php echo htmlspecialchars($asn); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-12 col-md-2 mt-2 mt-md-0 d-flex gap-2 justify-content-start justify-content-md-end">
					<button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i> Apply</button>
					<a href="/activity_log" class="btn btn-outline-secondary btn-sm">Reset</a>
				</div>
			</form>
		</div>
	</div>

	<div class="card">
		<div class="card-header d-flex justify-content-end align-items-center">
			<button type="submit" form="activityLogForm" name="delete_selected" value="1" class="btn btn-sm btn-outline-danger" data-confirm-message="Delete selected log entries? This cannot be undone." <?php echo empty($logs) ? 'disabled' : ''; ?>>
				<i class="bi bi-trash"></i> Delete selected
			</button>
		</div>
		<div class="card-body p-0">
			<form method="post" action="" id="activityLogForm">
				<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['activity_log_csrf']); ?>">
				<div class="table-responsive">
					<table class="table table-striped table-sm mb-0 align-middle activity-log-table table-density-target">
						<thead class="table-light">
							<tr>
								<th style="width:32px;"><input type="checkbox" id="selectAllLogs"></th>
								<th class="activity-log-col-time">Date/Time</th>
								<th class="activity-log-col-admin">Admin</th>
								<th class="activity-log-col-action">Action</th>
								<th class="activity-log-col-entity">Entity</th>
								<th class="activity-log-col-desc">Description</th>
								<th class="activity-log-col-location">Location</th>
								<th class="activity-log-col-network">Network Owner</th>
								<th class="activity-log-col-gadget">Gadget Type</th>
								<th class="activity-log-col-ip">IP Address</th>
							</tr>
						</thead>
						<tbody>
						<?php if (empty($logs)): ?>
							<tr>
								<td colspan="10" class="text-center text-muted py-3">No activity found for the selected filters.</td>
							</tr>
						<?php else: ?>
							<?php foreach ($logs as $log): ?>
								<?php
									$logMetadata = parseActivityMetadata($log['metadata'] ?? '');
									$locationLabel = deriveLocationLabel($log['ip_address'] ?? '', $logMetadata, $log['lookup_location'] ?? '');
									$networkOwnerLabel = deriveNetworkOwnerLabel($logMetadata);
									$gadgetLabel = deriveGadgetLabel($logMetadata);
								?>
								<tr>
									<td><input type="checkbox" name="log_ids[]" value="<?php echo (int)$log['id']; ?>"></td>
									<?php
													$logCreatedAt = !empty($log['created_at']) ? (string)$log['created_at'] : '';
													$logCreatedAtDisplay = $logCreatedAt !== '' && strtotime($logCreatedAt) ? date('d-m-Y H:i', strtotime($logCreatedAt)) : ($logCreatedAt !== '' ? $logCreatedAt : '—');
												?>
												<td class="activity-log-col-time"><?php echo htmlspecialchars($logCreatedAtDisplay); ?></td>
									<td class="activity-log-col-admin">
										<?php if (!empty($log['full_name'])): ?>
											<strong><?php echo htmlspecialchars($log['full_name']); ?></strong><br>
											<small class="text-muted">Acct: <?php echo htmlspecialchars($log['account_number']); ?></small>
										<?php else: ?>
											<em>System</em>
										<?php endif; ?>
									</td>
									<td class="activity-log-col-action"><code><?php echo htmlspecialchars($log['action']); ?></code></td>
									<td class="activity-log-col-entity">
										<?php echo htmlspecialchars($log['entity_type'] ?: '-'); ?>
										<?php if (!empty($log['entity_id'])): ?>
											<br><small class="text-muted">ID: <?php echo (int)$log['entity_id']; ?></small>
										<?php endif; ?>
									</td>
									<td class="activity-log-col-desc">
										<?php echo nl2br(htmlspecialchars($log['description'])); ?>
									</td>
									<td class="activity-log-col-location"><?php echo htmlspecialchars($locationLabel); ?></td>
									<td class="activity-log-col-network"><?php echo htmlspecialchars($networkOwnerLabel); ?></td>
									<td class="activity-log-col-gadget"><?php echo htmlspecialchars($gadgetLabel); ?></td>
									<td class="activity-log-col-ip"><?php echo htmlspecialchars($log['ip_address'] ?: '-'); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			</form>
		</div>
		<?php if ($totalPages > 1): ?>
			<nav class="mt-2">
				<ul class="pagination pagination-sm justify-content-end mb-0 px-3 pb-2">
					<?php
						$prevPage = max(1, $page - 1);
						$nextPage = min($totalPages, $page + 1);
						$queryBase = $_GET;
					?>
					<li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
						<?php $q = $queryBase; $q['page'] = 1; $firstUrl = '/activity_log?' . htmlspecialchars(http_build_query($q)); ?>
						<a class="page-link" href="<?php echo $firstUrl; ?>" aria-label="First"><span aria-hidden="true">&laquo;&laquo;</span></a>
					</li>
					<li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
						<?php $q = $queryBase; $q['page'] = $prevPage; $prevUrl = '/activity_log?' . htmlspecialchars(http_build_query($q)); ?>
						<a class="page-link" href="<?php echo $prevUrl; ?>" aria-label="Previous"><span aria-hidden="true">&laquo;</span></a>
					</li>
					<?php for ($i = 1; $i <= $totalPages; $i++): ?>
						<?php $q = $queryBase; $q['page'] = $i; $url = '/activity_log?' . htmlspecialchars(http_build_query($q)); ?>
						<li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
							<a class="page-link" href="<?php echo $url; ?>"><?php echo $i; ?></a>
						</li>
					<?php endfor; ?>
					<li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
						<?php $q = $queryBase; $q['page'] = $nextPage; $nextUrl = '/activity_log?' . htmlspecialchars(http_build_query($q)); ?>
						<a class="page-link" href="<?php echo $nextUrl; ?>" aria-label="Next"><span aria-hidden="true">&raquo;</span></a>
					</li>
					<li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
						<?php $q = $queryBase; $q['page'] = $totalPages; $lastUrl = '/activity_log?' . htmlspecialchars(http_build_query($q)); ?>
						<a class="page-link" href="<?php echo $lastUrl; ?>" aria-label="Last"><span aria-hidden="true">&raquo;&raquo;</span></a>
					</li>
				</ul>
			</nav>
		<?php endif; ?>
	</div>
	</div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
	if (window.WbsAdminUi && <?php echo json_encode($toastMessage); ?>) {
		window.WbsAdminUi.showFlashToast(<?php echo json_encode($toastMessage); ?>, <?php echo json_encode($toastType); ?>);
	}

	if (window.WbsAdminUi) {
		window.WbsAdminUi.bindConfirmButtons(document);
	}

	var selectAll = document.getElementById('selectAllLogs');
	if (!selectAll) return;
	selectAll.addEventListener('change', function() {
		var checkboxes = document.querySelectorAll('input[name="log_ids[]"]');
		checkboxes.forEach(function(cb) {
			cb.checked = selectAll.checked;
		});
	});
});
</script>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
