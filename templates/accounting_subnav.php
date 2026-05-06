<?php
// Detect active accounting section from current URI
$_acctUri = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
$_acctTab = 'overview';
if (strpos($_acctUri, '/accounting/reports') !== false || strpos($_acctUri, '/admin/accounting/reports') !== false) {
    $_acctTab = 'reports';
} elseif (strpos($_acctUri, '/accounting/budget') !== false || strpos($_acctUri, '/admin/accounting/budget') !== false) {
    $_acctTab = 'budget';
} elseif (strpos($_acctUri, '/accounting/transfers') !== false || strpos($_acctUri, '/admin/accounting/transfers') !== false) {
    $_acctTab = 'transfers';
} elseif (strpos($_acctUri, '/accounting/ledger') !== false || strpos($_acctUri, '/admin/accounting/ledger') !== false) {
    $_acctTab = 'ledger';
}
?>
<nav class="mb-4">
	<ul class="nav nav-pills gap-1 flex-wrap">
		<li class="nav-item">
			<a class="nav-link <?php echo $_acctTab === 'overview' ? 'active' : ''; ?>" href="/accounting">
				<i class="bi bi-house me-1"></i>Overview
			</a>
		</li>
		<li class="nav-item">
			<a class="nav-link <?php echo $_acctTab === 'reports' ? 'active' : ''; ?>" href="/accounting/reports">
				<i class="bi bi-bar-chart me-1"></i>Financial Reports
			</a>
		</li>
		<li class="nav-item">
			<a class="nav-link <?php echo $_acctTab === 'budget' ? 'active' : ''; ?>" href="/accounting/budget">
				<i class="bi bi-bar-chart-line me-1"></i>Budget
			</a>
		</li>
		<li class="nav-item">
			<a class="nav-link <?php echo $_acctTab === 'transfers' ? 'active' : ''; ?>" href="/accounting/transfers">
				<i class="bi bi-arrow-left-right me-1"></i>Transfers
			</a>
		</li>
		<li class="nav-item">
			<a class="nav-link <?php echo $_acctTab === 'ledger' ? 'active' : ''; ?>" href="/accounting/ledger">
				<i class="bi bi-list-ul me-1"></i>Ledger &amp; Journals
			</a>
		</li>
	</ul>
</nav>
