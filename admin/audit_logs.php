<?php
// ============================================================
// admin/audit_logs.php — System Audit Trail
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_ADMIN);

$pageTitle  = 'Audit Logs';
$activeMenu = 'audit_logs';

// ── Filters ───────────────────────────────────────────────────
$search    = sanitize($_GET['q']        ?? '');
$module    = sanitize($_GET['module']   ?? '');
$username  = sanitize($_GET['username'] ?? '');
$dateFrom  = sanitize($_GET['date_from']?? '');
$dateTo    = sanitize($_GET['date_to']  ?? '');
$page      = int_val($_GET['page']      ?? 1);
$export    = sanitize($_GET['export']   ?? '');

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = '(l.username LIKE ? OR l.description LIKE ? OR l.action LIKE ? OR l.ip_address LIKE ?)';
    $like     = "%{$search}%";
    $params   = array_merge($params, [$like,$like,$like,$like]);
}
if ($module)   { $where[] = 'l.module = ?';         $params[] = $module; }
if ($username) { $where[] = 'l.username = ?';        $params[] = $username; }
if ($dateFrom) { $where[] = 'DATE(l.created_at)>=?'; $params[] = $dateFrom; }
if ($dateTo)   { $where[] = 'DATE(l.created_at)<=?'; $params[] = $dateTo; }

$whereStr = 'WHERE ' . implode(' AND ', $where);

$baseSql = "SELECT l.* FROM audit_logs l {$whereStr} ORDER BY l.created_at DESC";

// CSV export
if ($export === 'csv') {
    $rows = Database::fetchAll($baseSql, $params);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="audit_logs_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output','w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID','Username','Action','Module','Description','IP Address','Timestamp']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'],$r['username'],$r['action'],$r['module'],$r['description'],$r['ip_address'],$r['created_at']]);
    }
    fclose($out);
    exit;
}

$pager = paginate($baseSql, $params, $page);
$logs  = $pager['rows'];

// Module list for filter dropdown
$modules = Database::fetchAll("SELECT DISTINCT module FROM audit_logs ORDER BY module");
$users   = Database::fetchAll("SELECT DISTINCT username FROM audit_logs WHERE username IS NOT NULL ORDER BY username");

// Summary counts
$moduleSummary = Database::fetchAll(
    "SELECT module, COUNT(*) AS cnt FROM audit_logs GROUP BY module ORDER BY cnt DESC LIMIT 8"
);

$moduleColors = [
    'Students'    => 'badge-primary',
    'Teachers'    => 'badge-success',
    'Results'     => 'badge-purple',
    'Payments'    => 'badge-gold',
    'Attendance'  => 'badge-warning',
    'Auth'        => 'badge-navy',
    'Settings'    => 'badge-secondary',
    'Import'      => 'badge-info',
    'Timetable'   => 'badge-primary',
    'Assignments' => 'badge-success',
    'Announcements'=> 'badge-warning',
];

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">🔍 Audit Logs</h1>
        <p class="page-subtitle"><?= $pager['total'] ?> total activity records</p>
    </div>
    <div class="page-header-actions">
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>"
           class="btn btn-outline">📤 Export CSV</a>
    </div>
</div>

<!-- Module activity summary -->
<div class="stats-grid mb-20" style="grid-template-columns:repeat(4,1fr)">
    <?php foreach (array_slice($moduleSummary,0,4) as $ms): ?>
    <div class="stat-card">
        <div class="stat-icon">📋</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($ms['cnt']) ?></div>
            <div class="stat-label"><?= e($ms['module']) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <!-- Filters toolbar -->
    <div class="table-toolbar" style="flex-wrap:wrap;gap:10px">
        <div class="search-bar-wrap">
            <span class="search-icon">🔍</span>
            <input type="text" class="search-input" id="auditSearch"
                   placeholder="Search action, user, description, IP…"
                   value="<?= e($search) ?>"
                   data-ajax-search="#auditTbody"
                   data-search-url="<?= BASE_URL ?>/api/search.php?type=audit"
                   autocomplete="off">
        </div>
        <form method="GET" id="auditFilter" style="display:contents">
            <input type="hidden" name="q" id="hiddenQ" value="<?= e($search) ?>">

            <select name="module" class="filter-select" onchange="this.form.submit()">
                <option value="">All Modules</option>
                <?php foreach ($modules as $m): ?>
                    <option value="<?= e($m['module']) ?>" <?= $module===$m['module']?'selected':'' ?>>
                        <?= e($m['module']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="username" class="filter-select" onchange="this.form.submit()">
                <option value="">All Users</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= e($u['username']) ?>" <?= $username===$u['username']?'selected':'' ?>>
                        <?= e($u['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="date" name="date_from" class="filter-select" value="<?= e($dateFrom) ?>"
                   placeholder="From date" title="From date" onchange="this.form.submit()">
            <input type="date" name="date_to" class="filter-select" value="<?= e($dateTo) ?>"
                   placeholder="To date" title="To date" onchange="this.form.submit()">

            <div class="table-toolbar-right">
                <a href="<?= BASE_URL ?>/admin/audit_logs.php" class="btn btn-outline btn-sm">↺ Reset</a>
            </div>
        </form>
    </div>

    <!-- Log table -->
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>#</th>
                <th data-sort>Timestamp</th>
                <th data-sort>User</th>
                <th data-sort>Module</th>
                <th>Action</th>
                <th>Description</th>
                <th>IP Address</th>
            </tr></thead>
            <tbody id="auditTbody">
            <?php if ($logs): $i=($page-1)*ROWS_PER_PAGE+1; foreach ($logs as $log): ?>
                <tr>
                    <td class="text-muted text-sm"><?= $log['id'] ?></td>
                    <td style="white-space:nowrap">
                        <div style="font-weight:700;font-size:12px"><?= format_date($log['created_at'],'d M Y') ?></div>
                        <div class="text-xs text-muted"><?= date('H:i:s', strtotime($log['created_at'])) ?></div>
                    </td>
                    <td>
                        <span style="font-weight:700;color:var(--navy)"><?= e($log['username'] ?? '—') ?></span>
                    </td>
                    <td>
                        <span class="badge <?= $moduleColors[$log['module']] ?? 'badge-secondary' ?>">
                            <?= e($log['module']) ?>
                        </span>
                    </td>
                    <td class="text-sm" style="white-space:nowrap"><?= e($log['action']) ?></td>
                    <td class="text-sm text-muted" style="max-width:280px">
                        <?= e($log['description'] ?? '—') ?>
                    </td>
                    <td><span class="code" style="font-size:11px"><?= e($log['ip_address'] ?? '—') ?></span></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="7" class="table-empty">
                    <div class="table-empty-icon">🔍</div>
                    No audit logs found for the selected filters.
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= pagination_links($pager, BASE_URL . '/admin/audit_logs.php?q=' . urlencode($search) . '&module=' . $module . '&username=' . $username . '&date_from=' . $dateFrom . '&date_to=' . $dateTo) ?>
    <div class="card-footer text-sm text-muted">
        Showing <?= count($logs) ?> of <?= $pager['total'] ?> records
    </div>
</div>

<script>
document.getElementById('auditSearch').addEventListener('input', function() {
    document.getElementById('hiddenQ').value = this.value;
});
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
