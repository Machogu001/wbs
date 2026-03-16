<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if(!$auth->isLoggedIn() || !$auth->isAdmin()) {
	header("Location: /login");
	exit;
}

// Handle bulk delete of selected log entries
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected']) && !empty($_POST['log_ids']) && is_array($_POST['log_ids'])) {
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
		$sql = "SELECT a.*, u.full_name, u.account_number
			FROM activity_log a
			LEFT JOIN users u ON a.user_id = u.id
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
?>

<div class="container mt-4 mb-4">
	<div class="row">
		<div class="col-12">
			<div class="admin-page-header d-flex justify-content-between align-items-center">
				<div>
					<h2 class="mb-1">Activity Log</h2>
					<p class="text-muted mb-0">Audit trail of key actions in the system.</p>
				</div>
			</div>
			<?php if (!empty($_SESSION['activity_log_message'])): ?>
				<div class="alert alert-success alert-dismissible fade show mt-2" role="alert">
					<?php echo htmlspecialchars($_SESSION['activity_log_message']); unset($_SESSION['activity_log_message']); ?>
					<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
				</div>
			<?php endif; ?>
			<?php if (!empty($dbError)): ?>
				<div class="alert alert-warning alert-dismissible fade show mt-2" role="alert">
					<?php echo htmlspecialchars($dbError); ?>
					<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<div class="card mt-3 mb-3">
		<div class="card-header d-flex justify-content-between align-items-center">
			<div>
				<h6 class="mb-0">Filters</h6>
				<small class="text-muted">Narrow down by date, admin, action, or text.</small>
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
				<div class="col-12 col-md-2 mt-2 mt-md-0 d-flex gap-2 justify-content-start justify-content-md-end">
					<button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i> Apply</button>
					<a href="/activity_log" class="btn btn-outline-secondary btn-sm">Reset</a>
				</div>
			</form>
		</div>
	</div>

	<div class="card">
		<div class="card-body p-0">
			<form method="post" action="" onsubmit="return confirm('Delete selected log entries? This cannot be undone.');">
				<div class="table-responsive">
					<table class="table table-striped table-sm mb-0 align-middle">
						<thead class="table-light">
							<tr>
								<th style="width:32px;"><input type="checkbox" id="selectAllLogs"></th>
								<th>Date/Time</th>
								<th>Admin</th>
								<th>Action</th>
								<th>Entity</th>
								<th>Description</th>
								<th>IP Address</th>
							</tr>
						</thead>
						<tbody>
						<?php if (empty($logs)): ?>
							<tr>
								<td colspan="7" class="text-center text-muted py-3">No activity found for the selected filters.</td>
							</tr>
						<?php else: ?>
							<?php foreach ($logs as $log): ?>
								<tr>
									<td><input type="checkbox" name="log_ids[]" value="<?php echo (int)$log['id']; ?>"></td>
									<td><?php echo htmlspecialchars($log['created_at']); ?></td>
									<td>
										<?php if (!empty($log['full_name'])): ?>
											<strong><?php echo htmlspecialchars($log['full_name']); ?></strong><br>
											<small class="text-muted">Acct: <?php echo htmlspecialchars($log['account_number']); ?></small>
										<?php else: ?>
											<em>System</em>
										<?php endif; ?>
									</td>
									<td><code><?php echo htmlspecialchars($log['action']); ?></code></td>
									<td>
										<?php echo htmlspecialchars($log['entity_type'] ?: '-'); ?>
										<?php if (!empty($log['entity_id'])): ?>
											<br><small class="text-muted">ID: <?php echo (int)$log['entity_id']; ?></small>
										<?php endif; ?>
									</td>
									<td style="max-width: 320px;">
										<?php echo nl2br(htmlspecialchars($log['description'])); ?>
									</td>
									<td><?php echo htmlspecialchars($log['ip_address'] ?: '-'); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
				<div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
					<div>
						<button type="submit" name="delete_selected" value="1" class="btn btn-sm btn-outline-danger" <?php echo empty($logs) ? 'disabled' : ''; ?>>
							<i class="bi bi-trash"></i> Delete selected
						</button>
					</div>
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
