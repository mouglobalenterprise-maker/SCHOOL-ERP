<?php
// ============================================================
// teacher/results.php — Teacher Results Portal
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_TEACHER);

$pageTitle  = 'My Results';
$activeMenu = 'results';

$sess_id = current_session_id();
$term_id = current_term_id();

// Get teacher record
$myTeacher = Database::fetchOne(
    "SELECT t.id FROM teachers t JOIN users u ON u.id=t.user_id WHERE u.id=?",
    [current_user_id()]
);
if (!$myTeacher) {
    flash_error('No teacher profile found for your account. Contact the administrator.');
    redirect(BASE_URL . '/teacher/dashboard.php');
}
$teacherId = $myTeacher['id'];

// Get teacher's assigned subjects/classes
$assignments = Database::fetchAll(
    "SELECT ts.*, sub.name AS subject_name, c.name AS class_name, c.id AS cls_id,
            (SELECT COUNT(*) FROM students s WHERE s.class_id=c.id AND s.session_id=? AND s.status='active') AS student_count,
            (SELECT COUNT(*) FROM results r
                WHERE r.subject_id=ts.subject_id AND r.class_id=ts.class_id
                  AND r.term_id=? AND r.session_id=?) AS results_entered
     FROM teacher_subjects ts
     JOIN subjects sub ON sub.id=ts.subject_id
     JOIN classes  c   ON c.id =ts.class_id
     WHERE ts.teacher_id=?
     ORDER BY c.sort_order, sub.name",
    [$sess_id, $term_id, $sess_id, $teacherId]
);

// Recent results entered by this teacher
$recentResults = Database::fetchAll(
    "SELECT r.*, s.full_name, s.student_id AS sid,
            sub.name AS subject_name, c.name AS class_name
     FROM results r
     JOIN students s   ON s.id  = r.student_id
     JOIN subjects sub ON sub.id = r.subject_id
     JOIN classes  c   ON c.id  = r.class_id
     WHERE r.teacher_id = ? AND r.session_id = ? AND r.term_id = ?
     ORDER BY r.updated_at DESC LIMIT 20",
    [$teacherId, $sess_id, $term_id]
);

// Filters for recent results
$filterClass   = int_val($_GET['class_id']   ?? 0);
$filterSubject = int_val($_GET['subject_id'] ?? 0);
$search        = sanitize($_GET['q']         ?? '');

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📈 My Results</h1>
        <p class="page-subtitle">
            <?= e(get_setting('current_term')) ?> Term &nbsp;|&nbsp;
            <?= count($assignments) ?> subject-class assignments
        </p>
    </div>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>/admin/results_enter.php" class="btn btn-primary">+ Enter Result</a>
        <a href="<?= BASE_URL ?>/admin/results_bulk.php"  class="btn btn-outline">📥 Bulk Entry</a>
    </div>
</div>

<!-- Assignment overview cards -->
<div style="margin-bottom:24px">
    <h3 style="font-size:14px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px">
        My Subject Assignments — <?= e(get_setting('current_term')) ?> Term
    </h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px">
    <?php if ($assignments): foreach ($assignments as $asn):
        $completion = $asn['student_count'] > 0
            ? round(($asn['results_entered'] / $asn['student_count']) * 100)
            : 0;
        $barColor = $completion === 100 ? 'green' : ($completion > 50 ? 'orange' : 'red');
    ?>
        <div class="card">
            <div class="card-body">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
                    <div>
                        <div style="font-weight:800;font-size:15px"><?= e($asn['subject_name']) ?></div>
                        <span class="badge badge-navy"><?= e($asn['class_name']) ?></span>
                    </div>
                    <?php if ($completion === 100): ?>
                        <span class="badge badge-success">✅ Complete</span>
                    <?php elseif ($completion > 0): ?>
                        <span class="badge badge-warning">⏳ In Progress</span>
                    <?php else: ?>
                        <span class="badge badge-danger">⭕ Not Started</span>
                    <?php endif; ?>
                </div>

                <div style="display:flex;justify-content:space-between;margin-bottom:6px">
                    <span class="text-sm text-muted">Results entered</span>
                    <span class="text-sm fw-700"><?= $asn['results_entered'] ?> / <?= $asn['student_count'] ?></span>
                </div>
                <div class="progress mb-12">
                    <div class="progress-bar <?= $barColor ?>" style="width:<?= $completion ?>%"></div>
                </div>

                <div style="display:flex;gap:8px">
                    <a href="<?= BASE_URL ?>/admin/results_bulk.php?class_id=<?= $asn['cls_id'] ?>&subject_id=<?= $asn['subject_id'] ?>&term_id=<?= $term_id ?>"
                       class="btn btn-sm btn-primary">📥 Bulk Entry</a>
                    <a href="<?= BASE_URL ?>/admin/results_enter.php"
                       class="btn btn-sm btn-outline">+ Single</a>
                </div>
            </div>
        </div>
    <?php endforeach; else: ?>
        <div class="card">
            <div class="card-body table-empty">
                No subjects assigned to you yet. Contact the administrator.
            </div>
        </div>
    <?php endif; ?>
    </div>
</div>

<!-- Recent results I entered -->
<div class="card">
    <div class="card-header">
        📋 Recently Entered Results
        <span class="badge badge-primary"><?= count($recentResults) ?> records</span>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>#</th>
                <th data-sort>Student</th>
                <th>Class</th>
                <th>Subject</th>
                <th>Test</th>
                <th>Assignment</th>
                <th>Exam</th>
                <th data-sort>Total</th>
                <th>Grade</th>
                <th>Actions</th>
            </tr></thead>
            <tbody>
            <?php if ($recentResults): $i=1; foreach ($recentResults as $r): ?>
                <tr>
                    <td class="text-muted text-sm"><?= $i++ ?></td>
                    <td>
                        <div style="font-weight:700"><?= e($r['full_name']) ?></div>
                        <div class="text-xs text-muted"><?= e($r['sid']) ?></div>
                    </td>
                    <td><span class="badge badge-navy"><?= e($r['class_name']) ?></span></td>
                    <td><?= e($r['subject_name']) ?></td>
                    <td style="color:var(--blue);font-weight:700"><?= $r['test_score'] ?></td>
                    <td style="color:var(--purple);font-weight:700"><?= $r['assignment_score'] ?></td>
                    <td style="color:var(--emerald);font-weight:700"><?= $r['exam_score'] ?></td>
                    <td><strong style="font-size:15px"><?= number_format($r['total_score'],1) ?></strong></td>
                    <td><?= grade_badge($r['grade'] ?? 'F') ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>/admin/results_enter.php?edit=<?= $r['id'] ?>"
                           class="btn btn-sm btn-primary">✏️ Edit</a>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="10" class="table-empty">
                    <div class="table-empty-icon">📊</div>
                    No results entered yet for this term.
                    <br><a href="<?= BASE_URL ?>/admin/results_bulk.php" class="btn btn-sm btn-primary" style="margin-top:10px">Start Bulk Entry</a>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
