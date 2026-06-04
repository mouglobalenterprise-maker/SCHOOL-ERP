<?php
// ============================================================
// admin/students.php — Student Management (List View)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role([ROLE_ADMIN, ROLE_SECRETARY]);

$pageTitle  = 'Student Management';
$activeMenu = 'students';

// ── Handle quick actions (status toggle, delete) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();

    $action = sanitize($_POST['action'] ?? '');
    $id     = int_val($_POST['student_db_id'] ?? 0);

    if ($action === 'delete' && is_admin()) {
        // Soft-delete: mark inactive
        Database::execute("UPDATE students SET status='inactive' WHERE id = ?", [$id]);
        audit_log(current_user_id(), current_username(), 'delete_student', 'Students',
            "Deactivated student ID {$id}");
        flash_success('Student deactivated successfully.');
    }

    if ($action === 'toggle_status' && is_admin()) {
        $student = Database::fetchOne("SELECT status FROM students WHERE id = ?", [$id]);
        if ($student) {
            $newStatus = $student['status'] === 'active' ? 'inactive' : 'active';
            Database::execute("UPDATE students SET status = ? WHERE id = ?", [$newStatus, $id]);
            audit_log(current_user_id(), current_username(), 'toggle_student_status', 'Students',
                "Set student ID {$id} to {$newStatus}");
            flash_success("Student status updated to {$newStatus}.");
        }
    }

    redirect(BASE_URL . '/admin/students.php');
}

// ── Filters ──────────────────────────────────────────────────
$search    = sanitize($_GET['q']       ?? '');
$filterCls = int_val($_GET['class_id'] ?? 0);
$filterSts = sanitize($_GET['status']  ?? '');
$page      = int_val($_GET['page']     ?? 1);
$sess_id   = current_session_id();

// ── Build query ───────────────────────────────────────────────
$where  = ['s.session_id = ?'];
$params = [$sess_id];

if ($search) {
    $where[]  = '(s.full_name LIKE ? OR s.student_id LIKE ? OR s.parent_name LIKE ? OR s.parent_phone1 LIKE ?)';
    $like     = "%{$search}%";
    $params   = array_merge($params, [$like, $like, $like, $like]);
}
if ($filterCls) {
    $where[]  = 's.class_id = ?';
    $params[] = $filterCls;
}
if ($filterSts) {
    $where[]  = 's.status = ?';
    $params[] = $filterSts;
}

$whereStr = 'WHERE ' . implode(' AND ', $where);

$baseSql = "SELECT s.*, c.name AS class_name
            FROM students s
            JOIN classes  c ON c.id = s.class_id
            {$whereStr}
            ORDER BY c.sort_order, s.full_name";

$pager   = paginate($baseSql, $params, $page);
$students = $pager['rows'];

// ── Dropdowns data ────────────────────────────────────────────
$classes = Database::fetchAll("SELECT id, name FROM classes ORDER BY sort_order");

// ── Summary counts ────────────────────────────────────────────
$counts = Database::fetchOne(
    "SELECT
        COUNT(*) AS total,
        SUM(status='active')   AS active_count,
        SUM(status='inactive') AS inactive_count,
        SUM(gender='Male')     AS male_count,
        SUM(gender='Female')   AS female_count
     FROM students WHERE session_id = ?",
    [$sess_id]
);

include INCLUDES_PATH . '/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">👨‍🎓 Student Management</h1>
        <p class="page-subtitle">
            <?= $counts['total'] ?> students &nbsp;|&nbsp;
            <?= $counts['active_count'] ?> active &nbsp;|&nbsp;
            <?= $counts['male_count'] ?>M / <?= $counts['female_count'] ?>F
        </p>
    </div>
    <div class="page-header-actions">
        <?php if (is_admin()): ?>
            <a href="<?= BASE_URL ?>/admin/bulk_import.php?type=students" class="btn btn-outline">📥 Bulk Import</a>
            <a href="<?= BASE_URL ?>/admin/students_add.php" class="btn btn-primary">+ Add Student</a>
        <?php endif; ?>
    </div>
</div>

<!-- Summary stat cards -->
<div class="stats-grid mb-20" style="grid-template-columns:repeat(5,1fr)">
    <div class="stat-card stat-blue">
        <div class="stat-icon">👨‍🎓</div>
        <div class="stat-info"><div class="stat-value"><?= $counts['total'] ?></div><div class="stat-label">Total</div></div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-icon">✅</div>
        <div class="stat-info"><div class="stat-value"><?= $counts['active_count'] ?></div><div class="stat-label">Active</div></div>
    </div>
    <div class="stat-card stat-red">
        <div class="stat-icon">❌</div>
        <div class="stat-info"><div class="stat-value"><?= $counts['inactive_count'] ?></div><div class="stat-label">Inactive</div></div>
    </div>
    <div class="stat-card stat-blue">
        <div class="stat-icon">👦</div>
        <div class="stat-info"><div class="stat-value"><?= $counts['male_count'] ?></div><div class="stat-label">Male</div></div>
    </div>
    <div class="stat-card stat-purple">
        <div class="stat-icon">👧</div>
        <div class="stat-info"><div class="stat-value"><?= $counts['female_count'] ?></div><div class="stat-label">Female</div></div>
    </div>
</div>

<!-- Table card -->
<div class="card">

    <!-- Toolbar -->
    <div class="table-toolbar">
        <!-- Real-time AJAX search -->
        <div class="search-bar-wrap">
            <span class="search-icon">🔍</span>
            <input
                type="text"
                id="studentSearch"
                class="search-input"
                placeholder="Search name, ID, parent, phone…"
                value="<?= e($search) ?>"
                data-ajax-search="#studentsTbody"
                data-search-url="<?= BASE_URL ?>/api/search.php?type=students"
                data-min-len="1"
                autocomplete="off">
        </div>

        <!-- Class filter -->
        <form method="GET" id="filterForm" style="display:contents">
            <input type="hidden" name="q" id="hiddenQ" value="<?= e($search) ?>">
            <select name="class_id" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                <option value="">All Classes</option>
                <?php foreach ($classes as $cls): ?>
                    <option value="<?= $cls['id'] ?>" <?= $filterCls == $cls['id'] ? 'selected' : '' ?>>
                        <?= e($cls['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="status" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                <option value="">All Status</option>
                <option value="active"   <?= $filterSts === 'active'   ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $filterSts === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                <option value="graduated"<?= $filterSts === 'graduated'? 'selected' : '' ?>>Graduated</option>
            </select>
        </form>

        <div class="table-toolbar-right">
            <a href="<?= BASE_URL ?>/admin/students.php" class="btn btn-outline btn-sm">↺ Reset</a>
            <a href="<?= BASE_URL ?>/api/get_students.php?export=csv&session_id=<?= $sess_id ?>&class_id=<?= $filterCls ?>&status=<?= $filterSts ?>"
               class="btn btn-outline btn-sm">📤 Export CSV</a>
        </div>
    </div>

    <!-- Table -->
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th data-sort>Student ID</th>
                    <th data-sort>Full Name</th>
                    <th>Class</th>
                    <th>Gender</th>
                    <th>Parent / Guardian</th>
                    <th>📱 Phone 1</th>
                    <th>📱 Phone 2</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="studentsTbody">
            <?php if ($students): ?>
                <?php $i = ($page - 1) * ROWS_PER_PAGE + 1; foreach ($students as $s): ?>
                <tr>
                    <td class="text-muted text-sm"><?= $i++ ?></td>
                    <td><span class="code"><?= e($s['student_id']) ?></span></td>
                    <td>
                        <div style="font-weight:700"><?= e($s['full_name']) ?></div>
                        <?php if ($s['dob']): ?>
                            <div class="text-xs text-muted">DOB: <?= format_date($s['dob']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-navy"><?= e($s['class_name']) ?></span></td>
                    <td><?= e($s['gender']) ?></td>
                    <td>
                        <div style="font-weight:600;font-size:13px"><?= e($s['parent_name'] ?? '—') ?></div>
                    </td>
                    <td>
                        <a href="<?= e(wa_link($s['parent_phone1'], 'Hello, this is ' . get_setting('school_name') . '.')) ?>"
                           target="_blank" class="btn btn-sm btn-whatsapp" title="WhatsApp Phone 1">
                            📲 <?= e($s['parent_phone1']) ?>
                        </a>
                    </td>
                    <td>
                        <a href="<?= e(wa_link($s['parent_phone2'], 'Hello, this is ' . get_setting('school_name') . '.')) ?>"
                           target="_blank" class="btn btn-sm btn-wa-dark" title="WhatsApp Phone 2">
                            📲 <?= e($s['parent_phone2']) ?>
                        </a>
                    </td>
                    <td><?= status_badge($s['status']) ?></td>
                    <td>
                        <div class="td-actions">
                            <a href="<?= BASE_URL ?>/admin/students_view.php?id=<?= $s['id'] ?>"
                               class="btn btn-sm btn-outline" title="View Profile">👁️</a>
                            <?php if (is_admin()): ?>
                                <a href="<?= BASE_URL ?>/admin/students_edit.php?id=<?= $s['id'] ?>"
                                   class="btn btn-sm btn-primary" title="Edit">✏️</a>
                                <form method="POST" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="student_db_id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-warning" title="Toggle Status">
                                        <?= $s['status'] === 'active' ? '⏸️' : '▶️' ?>
                                    </button>
                                </form>
                                <form method="POST" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="student_db_id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"
                                            data-confirm="Deactivate student <?= e($s['full_name']) ?>? They will not be deleted from the database."
                                            title="Deactivate">🗑️</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="10" class="table-empty">
                        <div class="table-empty-icon">👨‍🎓</div>
                        No students found<?= $search ? " matching \"" . e($search) . "\"" : '' ?>.
                        <?php if (is_admin()): ?>
                            <br><a href="<?= BASE_URL ?>/admin/students_add.php" class="btn btn-primary btn-sm" style="margin-top:10px">+ Add First Student</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?= pagination_links($pager, BASE_URL . '/admin/students.php?q=' . urlencode($search) . '&class_id=' . $filterCls . '&status=' . $filterSts) ?>

    <!-- Footer info -->
    <div class="card-footer text-sm text-muted">
        Showing <?= count($students) ?> of <?= $pager['total'] ?> students
        &nbsp;|&nbsp; Page <?= $pager['page'] ?> of <?= max(1, $pager['pages']) ?>
    </div>
</div>

<script>
// Update hidden search field when filter form submits
document.getElementById('studentSearch').addEventListener('input', function () {
    document.getElementById('hiddenQ').value = this.value;
});
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
