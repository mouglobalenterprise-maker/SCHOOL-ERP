<?php
// ============================================================
// admin/teachers.php — Teacher Management (List View)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_ADMIN);

$pageTitle  = 'Teacher Management';
$activeMenu = 'teachers';

// ── Quick actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
    $action = sanitize($_POST['action'] ?? '');
    $tid    = int_val($_POST['teacher_db_id'] ?? 0);

    if ($action === 'toggle_status' && $tid) {
        $t = Database::fetchOne(
            "SELECT u.status FROM teachers t JOIN users u ON u.id = t.user_id WHERE t.id = ?", [$tid]
        );
        if ($t) {
            $new = $t['status'] === 'active' ? 'inactive' : 'active';
            Database::execute(
                "UPDATE users u JOIN teachers t ON t.user_id = u.id SET u.status = ?, t.status = ? WHERE t.id = ?",
                [$new, $new, $tid]
            );
            audit_log(current_user_id(), current_username(), 'toggle_teacher', 'Teachers',
                "Set teacher ID {$tid} to {$new}");
            flash_success("Teacher status updated to {$new}.");
        }
    }

    if ($action === 'delete' && $tid) {
        // Soft delete — deactivate
        Database::execute(
            "UPDATE users u JOIN teachers t ON t.user_id = u.id
             SET u.status='inactive', t.status='inactive' WHERE t.id = ?",
            [$tid]
        );
        audit_log(current_user_id(), current_username(), 'deactivate_teacher', 'Teachers',
            "Deactivated teacher ID {$tid}");
        flash_success('Teacher deactivated.');
    }

    redirect(BASE_URL . '/admin/teachers.php');
}

// ── Filters ───────────────────────────────────────────────────
$search = sanitize($_GET['q']      ?? '');
$status = sanitize($_GET['status'] ?? '');
$page   = int_val($_GET['page']    ?? 1);

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = '(u.full_name LIKE ? OR t.teacher_code LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $like     = "%{$search}%";
    $params   = array_merge($params, [$like, $like, $like, $like]);
}
if ($status) {
    $where[]  = 't.status = ?';
    $params[] = $status;
}

$whereStr = 'WHERE ' . implode(' AND ', $where);

$baseSql = "SELECT t.id, t.teacher_code, t.qualification, t.joined_date,
                   t.status,
                   u.full_name, u.email, u.phone, u.username, u.last_login,
                   GROUP_CONCAT(DISTINCT CONCAT(sub.name,' (',c.name,')') ORDER BY sub.name SEPARATOR ', ') AS assignments
            FROM teachers t
            JOIN users u ON u.id = t.user_id
            LEFT JOIN teacher_subjects ts  ON ts.teacher_id = t.id
            LEFT JOIN subjects sub ON sub.id = ts.subject_id
            LEFT JOIN classes  c   ON c.id  = ts.class_id
            {$whereStr}
            GROUP BY t.id, t.teacher_code, t.qualification, t.joined_date, t.status,
                     u.full_name, u.email, u.phone, u.username, u.last_login
            ORDER BY u.full_name";

$pager    = paginate($baseSql, $params, $page);
$teachers = $pager['rows'];

// Counts
$counts = Database::fetchOne(
    "SELECT COUNT(*) AS total,
            SUM(t.status='active')   AS active_count,
            SUM(t.status='inactive') AS inactive_count
     FROM teachers t JOIN users u ON u.id = t.user_id"
);

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">👨‍🏫 Teacher Management</h1>
        <p class="page-subtitle">
            <?= $counts['total'] ?> teachers &nbsp;|&nbsp;
            <?= $counts['active_count'] ?> active &nbsp;|&nbsp;
            <?= $counts['inactive_count'] ?> inactive
        </p>
    </div>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>/admin/teachers_add.php" class="btn btn-primary">+ Add Teacher</a>
    </div>
</div>

<!-- Stats -->
<div class="stats-grid mb-20" style="grid-template-columns:repeat(3,1fr)">
    <div class="stat-card stat-blue">
        <div class="stat-icon">👨‍🏫</div>
        <div class="stat-info"><div class="stat-value"><?= $counts['total'] ?></div><div class="stat-label">Total Teachers</div></div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-icon">✅</div>
        <div class="stat-info"><div class="stat-value"><?= $counts['active_count'] ?></div><div class="stat-label">Active</div></div>
    </div>
    <div class="stat-card stat-red">
        <div class="stat-icon">⏸️</div>
        <div class="stat-info"><div class="stat-value"><?= $counts['inactive_count'] ?></div><div class="stat-label">Inactive</div></div>
    </div>
</div>

<div class="card">
    <!-- Toolbar -->
    <div class="table-toolbar">
        <div class="search-bar-wrap">
            <span class="search-icon">🔍</span>
            <input type="text" class="search-input" id="teacherSearch"
                   placeholder="Search name, code, email, phone…"
                   value="<?= e($search) ?>"
                   data-ajax-search="#teachersTbody"
                   data-search-url="<?= BASE_URL ?>/api/search.php?type=teachers"
                   data-min-len="1"
                   autocomplete="off">
        </div>
        <form method="GET" id="filterForm" style="display:contents">
            <input type="hidden" name="q" id="hiddenQ" value="<?= e($search) ?>">
            <select name="status" class="filter-select" onchange="this.form.submit()">
                <option value="">All Status</option>
                <option value="active"   <?= $status==='active'   ?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $status==='inactive' ?'selected':'' ?>>Inactive</option>
            </select>
        </form>
        <div class="table-toolbar-right">
            <a href="<?= BASE_URL ?>/admin/teachers.php" class="btn btn-outline btn-sm">↺ Reset</a>
        </div>
    </div>

    <!-- Table -->
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>#</th>
                <th data-sort>Code</th>
                <th data-sort>Full Name</th>
                <th>Username</th>
                <th>Qualification</th>
                <th>Phone</th>
                <th>Email</th>
                <th>Subjects / Classes</th>
                <th>Last Login</th>
                <th>Status</th>
                <th>Actions</th>
            </tr></thead>
            <tbody id="teachersTbody">
            <?php if ($teachers): $i = ($page-1)*ROWS_PER_PAGE+1; foreach ($teachers as $t): ?>
                <tr>
                    <td class="text-muted text-sm"><?= $i++ ?></td>
                    <td><span class="code"><?= e($t['teacher_code']) ?></span></td>
                    <td>
                        <div style="font-weight:700"><?= e($t['full_name']) ?></div>
                        <?php if ($t['joined_date']): ?>
                            <div class="text-xs text-muted">Since <?= format_date($t['joined_date'],'M Y') ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="code"><?= e($t['username']) ?></td>
                    <td class="text-sm"><?= e($t['qualification'] ?? '—') ?></td>
                    <td class="text-sm"><?= e($t['phone'] ?? '—') ?></td>
                    <td class="text-sm"><?= e($t['email'] ?? '—') ?></td>
                    <td style="max-width:220px">
                        <?php if ($t['assignments']): ?>
                            <div class="text-xs" style="line-height:1.6"><?= e($t['assignments']) ?></div>
                        <?php else: ?>
                            <span class="text-muted text-xs">Not assigned</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm text-muted">
                        <?= $t['last_login'] ? format_date($t['last_login'], 'd M Y H:i') : 'Never' ?>
                    </td>
                    <td><?= status_badge($t['status']) ?></td>
                    <td>
                        <div class="td-actions">
                            <a href="<?= BASE_URL ?>/admin/teachers_edit.php?id=<?= $t['id'] ?>"
                               class="btn btn-sm btn-primary" title="Edit">✏️</a>
                            <a href="<?= BASE_URL ?>/admin/teachers_subjects.php?id=<?= $t['id'] ?>"
                               class="btn btn-sm btn-outline" title="Assign Subjects">📘</a>
                            <form method="POST" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="teacher_db_id" value="<?= $t['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-warning" title="Toggle Status">
                                    <?= $t['status']==='active'?'⏸️':'▶️' ?>
                                </button>
                            </form>
                            <form method="POST" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="teacher_db_id" value="<?= $t['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                        data-confirm="Deactivate <?= e($t['full_name']) ?>?">🗑️</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="11" class="table-empty">
                    <div class="table-empty-icon">👨‍🏫</div>
                    No teachers found.
                    <br><a href="<?= BASE_URL ?>/admin/teachers_add.php" class="btn btn-primary btn-sm" style="margin-top:10px">+ Add First Teacher</a>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= pagination_links($pager, BASE_URL . '/admin/teachers.php?q=' . urlencode($search) . '&status=' . $status) ?>
    <div class="card-footer text-sm text-muted">
        Showing <?= count($teachers) ?> of <?= $pager['total'] ?> teachers
    </div>
</div>

<script>
document.getElementById('teacherSearch').addEventListener('input', function() {
    document.getElementById('hiddenQ').value = this.value;
});
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
