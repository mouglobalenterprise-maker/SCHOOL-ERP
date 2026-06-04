<?php
// ============================================================
// admin/results.php — Result Management (Admin View)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role([ROLE_ADMIN, ROLE_TEACHER]);

$pageTitle  = 'Result Management';
$activeMenu = 'results';

// ── Handle inline delete ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'delete') {
        $rid = int_val($_POST['result_id'] ?? 0);
        if ($rid) {
            $r = Database::fetchOne("SELECT * FROM results WHERE id=?", [$rid]);
            if ($r) {
                Database::execute("DELETE FROM results WHERE id=?", [$rid]);
                audit_log(current_user_id(), current_username(), 'delete_result', 'Results',
                    "Deleted result ID {$rid}");
                flash_success('Result deleted.');
            }
        }
    }
    redirect(BASE_URL . '/admin/results.php?' . http_build_query($_GET));
}

// ── Filters ───────────────────────────────────────────────────
$sess_id    = current_session_id();
$term_id    = int_val($_GET['term_id']    ?? current_term_id());
$class_id   = int_val($_GET['class_id']  ?? 0);
$subject_id = int_val($_GET['subject_id']?? 0);
$search     = sanitize($_GET['q']        ?? '');
$page       = int_val($_GET['page']      ?? 1);

// Teacher restriction: only see their assigned classes/subjects
$teacherFilter = '';
$teacherParams = [];
if (is_teacher()) {
    $myTeacher = Database::fetchOne(
        "SELECT t.id FROM teachers t JOIN users u ON u.id=t.user_id WHERE u.id=?",
        [current_user_id()]
    );
    if ($myTeacher) {
        $teacherFilter = "AND EXISTS (
            SELECT 1 FROM teacher_subjects ts
            WHERE ts.teacher_id = ? AND ts.subject_id = r.subject_id AND ts.class_id = r.class_id
        )";
        $teacherParams = [$myTeacher['id']];
    }
}

$where  = ['r.session_id = ?'];
$params = [$sess_id];

if ($term_id)    { $where[] = 'r.term_id = ?';    $params[] = $term_id; }
if ($class_id)   { $where[] = 'r.class_id = ?';   $params[] = $class_id; }
if ($subject_id) { $where[] = 'r.subject_id = ?'; $params[] = $subject_id; }
if ($search) {
    $where[]  = '(s.full_name LIKE ? OR s.student_id LIKE ?)';
    $like     = "%{$search}%";
    $params   = array_merge($params, [$like, $like]);
}

$params = array_merge($params, $teacherParams);
$whereStr = 'WHERE ' . implode(' AND ', $where) . ' ' . $teacherFilter;

$baseSql = "SELECT r.id, r.student_id, r.subject_id, r.class_id, r.term_id,
                   r.session_id, r.teacher_id,
                   r.test_score, r.assignment_score, r.exam_score, r.total_score,
                   r.grade, r.remark, r.teacher_comment, r.created_at, r.updated_at,
                   s.full_name, s.student_id AS sid,
                   sub.name AS subject_name,
                   c.name   AS class_name,
                   t.name   AS term_name
            FROM results r
            JOIN students s   ON s.id  = r.student_id
            JOIN subjects sub ON sub.id = r.subject_id
            JOIN classes  c   ON c.id  = r.class_id
            JOIN terms    t   ON t.id  = r.term_id
            {$whereStr}
            ORDER BY c.sort_order, s.full_name, sub.name";

$pager   = paginate($baseSql, $params, $page);
$results = $pager['rows'];

// Dropdown data
$terms    = Database::fetchAll("SELECT t.* FROM terms t JOIN academic_sessions ses ON ses.id=t.session_id WHERE ses.is_current=1 ORDER BY t.id");
$classes  = Database::fetchAll("SELECT id,name FROM classes ORDER BY sort_order");
$subjects = Database::fetchAll("SELECT id,name FROM subjects ORDER BY name");

// Summary stats for current filter
$statSql = "SELECT
    COUNT(*)             AS total,
    AVG(r.total_score)  AS avg_score,
    MAX(r.total_score)  AS max_score,
    MIN(r.total_score)  AS min_score,
    SUM(r.grade='A')    AS grade_a,
    SUM(r.grade='B')    AS grade_b,
    SUM(r.grade='C')    AS grade_c,
    SUM(r.grade='D')    AS grade_d,
    SUM(r.grade='F')    AS grade_f
    FROM results r
    JOIN students s ON s.id = r.student_id
    {$whereStr}";
$stats = Database::fetchOne($statSql, $params);

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📈 Result Management</h1>
        <p class="page-subtitle">
            <?= $pager['total'] ?> results &nbsp;|&nbsp;
            <?= e(get_setting('current_session')) ?> &nbsp;|&nbsp;
            <?= $term_id ? (Database::fetchOne("SELECT name FROM terms WHERE id=?",[$term_id])['name'] ?? '') . ' Term' : 'All Terms' ?>
        </p>
    </div>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>/admin/results_enter.php" class="btn btn-primary">+ Enter Results</a>
        <a href="<?= BASE_URL ?>/admin/results_bulk.php"  class="btn btn-outline">📥 Bulk Entry</a>
    </div>
</div>

<!-- Stats row -->
<?php if ($stats['total'] > 0): ?>
<div class="stats-grid mb-20" style="grid-template-columns:repeat(6,1fr)">
    <div class="stat-card stat-blue">
        <div class="stat-icon">📊</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format((float)$stats['avg_score'],1) ?></div>
            <div class="stat-label">Average</div>
        </div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-icon">⬆️</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format((float)$stats['max_score'],1) ?></div>
            <div class="stat-label">Highest</div>
        </div>
    </div>
    <div class="stat-card stat-red">
        <div class="stat-icon">⬇️</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format((float)$stats['min_score'],1) ?></div>
            <div class="stat-label">Lowest</div>
        </div>
    </div>
    <?php foreach (['A'=>'green','B'=>'blue','F'=>'red'] as $g=>$col): ?>
    <div class="stat-card stat-<?= $col ?>">
        <div class="stat-icon"><?= ['A'=>'🏆','B'=>'👍','F'=>'⚠️'][$g] ?></div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['grade_'  . strtolower($g)] ?></div>
            <div class="stat-label">Grade <?= $g ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Grade distribution bar -->
<?php if ($stats['total'] > 0): ?>
<div class="card mb-20">
    <div class="card-header">📊 Grade Distribution</div>
    <div class="card-body">
        <div style="display:flex;gap:0;height:32px;border-radius:8px;overflow:hidden;margin-bottom:10px">
            <?php
            $gradeColors = ['A'=>'#10B981','B'=>'#3B82F6','C'=>'#F59E0B','D'=>'#8B5CF6','F'=>'#EF4444'];
            $gradeKeys   = ['A','B','C','D','F'];
            foreach ($gradeKeys as $g):
                $cnt = (int)$stats['grade_' . strtolower($g)];
                $pct = $stats['total'] > 0 ? ($cnt/$stats['total'])*100 : 0;
                if ($pct <= 0) continue;
            ?>
            <div style="width:<?= $pct ?>%;background:<?= $gradeColors[$g] ?>;
                        display:flex;align-items:center;justify-content:center;
                        color:#fff;font-size:12px;font-weight:700;min-width:<?= $pct>5?0:0 ?>px"
                 title="Grade <?= $g ?>: <?= $cnt ?> (<?= round($pct) ?>%)">
                <?= $pct > 8 ? "Grade {$g}: {$cnt}" : '' ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:16px;flex-wrap:wrap">
            <?php foreach ($gradeKeys as $g): ?>
                <div style="display:flex;align-items:center;gap:5px">
                    <div style="width:12px;height:12px;border-radius:3px;background:<?= $gradeColors[$g] ?>"></div>
                    <span class="text-sm fw-700">Grade <?= $g ?>:</span>
                    <span class="text-sm text-muted"><?= $stats['grade_'.strtolower($g)] ?> students</span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Main table card -->
<div class="card">
    <!-- Toolbar -->
    <div class="table-toolbar">
        <div class="search-bar-wrap">
            <span class="search-icon">🔍</span>
            <input type="text" class="search-input" id="resultSearch"
                   placeholder="Search student name or ID…"
                   value="<?= e($search) ?>"
                   data-ajax-search="#resultsTbody"
                   data-search-url="<?= BASE_URL ?>/api/search.php?type=results&term_id=<?= $term_id ?>&class_id=<?= $class_id ?>&subject_id=<?= $subject_id ?>"
                   autocomplete="off">
        </div>

        <form method="GET" id="filterForm" style="display:contents">
            <input type="hidden" name="q" id="hiddenQ" value="<?= e($search) ?>">

            <select name="term_id" class="filter-select" onchange="this.form.submit()">
                <option value="">All Terms</option>
                <?php foreach ($terms as $term): ?>
                    <option value="<?= $term['id'] ?>" <?= $term_id==$term['id']?'selected':'' ?>>
                        <?= e($term['name']) ?> Term
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="class_id" class="filter-select" onchange="this.form.submit()">
                <option value="">All Classes</option>
                <?php foreach ($classes as $cls): ?>
                    <option value="<?= $cls['id'] ?>" <?= $class_id==$cls['id']?'selected':'' ?>>
                        <?= e($cls['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="subject_id" class="filter-select" onchange="this.form.submit()">
                <option value="">All Subjects</option>
                <?php foreach ($subjects as $sub): ?>
                    <option value="<?= $sub['id'] ?>" <?= $subject_id==$sub['id']?'selected':'' ?>>
                        <?= e($sub['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <div class="table-toolbar-right">
            <a href="<?= BASE_URL ?>/admin/results.php" class="btn btn-outline btn-sm">↺ Reset</a>
            <a href="<?= BASE_URL ?>/api/get_results.php?export=csv&term_id=<?= $term_id ?>&class_id=<?= $class_id ?>&subject_id=<?= $subject_id ?>"
               class="btn btn-outline btn-sm">📤 Export</a>
        </div>
    </div>

    <!-- Table -->
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th data-sort>Student</th>
                    <th>Class</th>
                    <th data-sort>Subject</th>
                    <th>Term</th>
                    <th>Test /<?= e(get_setting('results_test_max','20')) ?></th>
                    <th>Assign. /<?= e(get_setting('results_asn_max','20')) ?></th>
                    <th>Exam /<?= e(get_setting('results_exam_max','60')) ?></th>
                    <th data-sort>Total</th>
                    <th>Grade</th>
                    <th>Remark</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="resultsTbody">
            <?php if ($results): $i = ($page-1)*ROWS_PER_PAGE+1; foreach ($results as $r): ?>
                <tr>
                    <td class="text-muted text-sm"><?= $i++ ?></td>
                    <td>
                        <div style="font-weight:700"><?= e($r['full_name']) ?></div>
                        <div class="text-xs text-muted"><?= e($r['sid']) ?></div>
                    </td>
                    <td><span class="badge badge-navy"><?= e($r['class_name']) ?></span></td>
                    <td><?= e($r['subject_name']) ?></td>
                    <td class="text-sm text-muted"><?= e($r['term_name']) ?></td>
                    <td><span style="font-weight:700;color:var(--blue)"><?= $r['test_score'] ?></span></td>
                    <td><span style="font-weight:700;color:var(--purple)"><?= $r['assignment_score'] ?></span></td>
                    <td><span style="font-weight:700;color:var(--emerald)"><?= $r['exam_score'] ?></span></td>
                    <td><strong style="font-size:15px"><?= number_format($r['total_score'],1) ?></strong></td>
                    <td><?= grade_badge($r['grade'] ?? 'F') ?></td>
                    <td class="text-sm text-muted"><?= e($r['remark'] ?? '—') ?></td>
                    <td>
                        <div class="td-actions">
                            <a href="<?= BASE_URL ?>/admin/results_enter.php?edit=<?= $r['id'] ?>"
                               class="btn btn-sm btn-primary">✏️</a>
                            <form method="POST" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="result_id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                        data-confirm="Delete this result for <?= e($r['full_name']) ?> — <?= e($r['subject_name']) ?>?">🗑️</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="12" class="table-empty">
                    <div class="table-empty-icon">📈</div>
                    No results found. <a href="<?= BASE_URL ?>/admin/results_enter.php" class="btn btn-sm btn-primary" style="margin-left:10px">+ Enter Results</a>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= pagination_links($pager, BASE_URL . '/admin/results.php?term_id=' . $term_id . '&class_id=' . $class_id . '&subject_id=' . $subject_id . '&q=' . urlencode($search)) ?>
    <div class="card-footer text-sm text-muted">
        Showing <?= count($results) ?> of <?= $pager['total'] ?> results
    </div>
</div>

<script>
document.getElementById('resultSearch').addEventListener('input', function() {
    document.getElementById('hiddenQ').value = this.value;
});
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
