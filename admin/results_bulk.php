<?php
// ============================================================
// admin/results_bulk.php — Bulk Result Entry (Full Class/Subject)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role([ROLE_ADMIN, ROLE_TEACHER]);

$pageTitle  = 'Bulk Result Entry';
$activeMenu = 'results';

$sess_id = current_session_id();
$term_id = current_term_id();

$class_id   = int_val($_GET['class_id']   ?? $_POST['class_id']   ?? 0);
$subject_id = int_val($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0);
$term_sel   = int_val($_GET['term_id']    ?? $_POST['term_id']    ?? $term_id);

$maxTest = (int)get_setting('results_test_max', '20');
$maxAsn  = (int)get_setting('results_asn_max',  '20');
$maxExam = (int)get_setting('results_exam_max',  '60');

$students  = [];
$subject   = null;
$className = '';
$saved     = 0;
$errors    = [];

// Load students for selected class
if ($class_id && $subject_id) {
    $students = Database::fetchAll(
        "SELECT s.id, s.full_name, s.student_id,
                r.id AS result_id,
                r.test_score, r.assignment_score, r.exam_score,
                r.grade, r.total_score, r.teacher_comment
         FROM students s
         LEFT JOIN results r ON r.student_id = s.id
             AND r.subject_id = ? AND r.term_id = ? AND r.session_id = ?
         WHERE s.class_id = ? AND s.session_id = ? AND s.status = 'active'
         ORDER BY s.full_name",
        [$subject_id, $term_sel, $sess_id, $class_id, $sess_id]
    );
    $subject   = Database::fetchOne("SELECT name FROM subjects WHERE id=?", [$subject_id]);
    $classRow  = Database::fetchOne("SELECT name FROM classes  WHERE id=?", [$class_id]);
    $className = $classRow['name'] ?? '';
}

// Handle bulk save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_bulk'])) {
    csrf_protect();
    $class_id   = int_val($_POST['class_id']   ?? 0);
    $subject_id = int_val($_POST['subject_id'] ?? 0);
    $term_sel   = int_val($_POST['term_id']    ?? $term_id);
    $scores     = $_POST['scores'] ?? [];

    if (!$class_id || !$subject_id || !$term_sel) {
        flash_error('Please select class, subject, and term before saving.');
    } else {
        $teacherDbId = null;
        if (is_teacher()) {
            $myT = Database::fetchOne(
                "SELECT t.id FROM teachers t JOIN users u ON u.id=t.user_id WHERE u.id=?",
                [current_user_id()]
            );
            $teacherDbId = $myT['id'] ?? null;
        }

        Database::beginTransaction();
        try {
            foreach ($scores as $studentId => $scoreData) {
                $studentId = (int)$studentId;
                $test      = float_val($scoreData['test']      ?? '');
                $asn       = float_val($scoreData['asn']       ?? '');
                $exam      = float_val($scoreData['exam']      ?? '');
                $comment   = sanitize($scoreData['comment']    ?? '');

                // Skip rows where all scores are empty
                if ($scoreData['test'] === '' && $scoreData['asn'] === '' && $scoreData['exam'] === '') continue;

                $total     = $test + $asn + $exam;
                $gradeInfo = get_grade($total);

                // Check if result exists
                $existing = Database::fetchOne(
                    "SELECT id FROM results WHERE student_id=? AND subject_id=? AND term_id=? AND session_id=?",
                    [$studentId, $subject_id, $term_sel, $sess_id]
                );

                if ($existing) {
                    Database::execute(
                        "UPDATE results SET test_score=?, assignment_score=?, exam_score=?,
                         grade=?, remark=?, teacher_comment=?, teacher_id=? WHERE id=?",
                        [$test, $asn, $exam, $gradeInfo['grade'], $gradeInfo['remark'],
                         $comment ?: null, $teacherDbId, $existing['id']]
                    );
                } else {
                    Database::insert(
                        "INSERT INTO results (student_id, subject_id, class_id, term_id, session_id,
                         test_score, assignment_score, exam_score, grade, remark, teacher_comment, teacher_id)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                        [$studentId, $subject_id, $class_id, $term_sel, $sess_id,
                         $test, $asn, $exam, $gradeInfo['grade'], $gradeInfo['remark'],
                         $comment ?: null, $teacherDbId]
                    );
                }
                $saved++;
            }
            Database::commit();
            audit_log(current_user_id(), current_username(), 'bulk_results', 'Results',
                "Bulk saved {$saved} results for class ID {$class_id}, subject ID {$subject_id}");
            flash_success("✅ {$saved} result(s) saved successfully.");
            redirect(BASE_URL . "/admin/results_bulk.php?class_id={$class_id}&subject_id={$subject_id}&term_id={$term_sel}");
        } catch (Exception $e) {
            Database::rollback();
            error_log('[Bulk Results] ' . $e->getMessage());
            flash_error('Failed to save results. Please try again.');
        }
    }
}

// Dropdowns
$classes  = Database::fetchAll("SELECT id,name FROM classes ORDER BY sort_order");
$subjects = Database::fetchAll("SELECT id,name FROM subjects ORDER BY name");
$terms    = Database::fetchAll(
    "SELECT t.* FROM terms t JOIN academic_sessions ses ON ses.id=t.session_id WHERE ses.is_current=1 ORDER BY t.id"
);

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📥 Bulk Result Entry</h1>
        <p class="page-subtitle">Enter scores for an entire class and subject at once</p>
    </div>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>/admin/results.php" class="btn btn-outline">← Back to Results</a>
    </div>
</div>

<!-- Filter form -->
<div class="card mb-20">
    <div class="card-header">🔍 Select Class, Subject & Term</div>
    <div class="card-body">
        <form method="GET" action="" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
            <div class="form-group" style="margin:0;flex:1;min-width:160px">
                <label class="form-label">Class</label>
                <select name="class_id" class="form-control" required>
                    <option value="">Select class…</option>
                    <?php foreach ($classes as $cls): ?>
                        <option value="<?= $cls['id'] ?>" <?= $class_id==$cls['id']?'selected':'' ?>><?= e($cls['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;flex:1;min-width:180px">
                <label class="form-label">Subject</label>
                <select name="subject_id" class="form-control" required>
                    <option value="">Select subject…</option>
                    <?php foreach ($subjects as $sub): ?>
                        <option value="<?= $sub['id'] ?>" <?= $subject_id==$sub['id']?'selected':'' ?>><?= e($sub['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;flex:1;min-width:140px">
                <label class="form-label">Term</label>
                <select name="term_id" class="form-control">
                    <?php foreach ($terms as $term): ?>
                        <option value="<?= $term['id'] ?>" <?= $term_sel==$term['id']?'selected':'' ?>><?= e($term['name']) ?> Term</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="padding-bottom:16px">
                <button type="submit" class="btn btn-primary">Load Students →</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk score entry table -->
<?php if ($students && $subject): ?>
<form method="POST" action="">
    <?= csrf_field() ?>
    <input type="hidden" name="save_bulk"  value="1">
    <input type="hidden" name="class_id"   value="<?= $class_id ?>">
    <input type="hidden" name="subject_id" value="<?= $subject_id ?>">
    <input type="hidden" name="term_id"    value="<?= $term_sel ?>">

    <div class="card">
        <div class="card-header">
            <div>
                📝 <?= e($subject['name']) ?> — <?= e($className) ?>
                <span class="badge badge-primary" style="margin-left:8px"><?= count($students) ?> students</span>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
                <button type="button" class="btn btn-sm btn-outline" onclick="fillAll()">Fill Sample Scores</button>
                <button type="submit" class="btn btn-success">💾 Save All Scores</button>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table" id="bulkTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student</th>
                        <th>ID</th>
                        <th>
                            Test
                            <small style="display:block;font-weight:400;color:var(--text-muted)">/<?= $maxTest ?></small>
                        </th>
                        <th>
                            Assignment
                            <small style="display:block;font-weight:400;color:var(--text-muted)">/<?= $maxAsn ?></small>
                        </th>
                        <th>
                            Exam
                            <small style="display:block;font-weight:400;color:var(--text-muted)">/<?= $maxExam ?></small>
                        </th>
                        <th>Total</th>
                        <th>Grade</th>
                        <th>Comment</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $i => $s): ?>
                    <tr id="row_<?= $s['id'] ?>">
                        <td class="text-muted text-sm"><?= $i+1 ?></td>
                        <td><strong><?= e($s['full_name']) ?></strong></td>
                        <td><span class="code"><?= e($s['student_id']) ?></span></td>
                        <td>
                            <input type="number" name="scores[<?= $s['id'] ?>][test]"
                                   class="form-control score-input" style="width:70px;padding:6px 8px"
                                   min="0" max="<?= $maxTest ?>" step="0.5"
                                   value="<?= $s['result_id'] ? $s['test_score'] : '' ?>"
                                   oninput="calcRow(<?= $s['id'] ?>)"
                                   id="test_<?= $s['id'] ?>">
                        </td>
                        <td>
                            <input type="number" name="scores[<?= $s['id'] ?>][asn]"
                                   class="form-control score-input" style="width:70px;padding:6px 8px"
                                   min="0" max="<?= $maxAsn ?>" step="0.5"
                                   value="<?= $s['result_id'] ? $s['assignment_score'] : '' ?>"
                                   oninput="calcRow(<?= $s['id'] ?>)"
                                   id="asn_<?= $s['id'] ?>">
                        </td>
                        <td>
                            <input type="number" name="scores[<?= $s['id'] ?>][exam]"
                                   class="form-control score-input" style="width:70px;padding:6px 8px"
                                   min="0" max="<?= $maxExam ?>" step="0.5"
                                   value="<?= $s['result_id'] ? $s['exam_score'] : '' ?>"
                                   oninput="calcRow(<?= $s['id'] ?>)"
                                   id="exam_<?= $s['id'] ?>">
                        </td>
                        <td id="total_<?= $s['id'] ?>" style="font-weight:800;font-size:15px">
                            <?= $s['result_id'] ? number_format($s['total_score'],1) : '—' ?>
                        </td>
                        <td id="grade_<?= $s['id'] ?>">
                            <?= $s['result_id'] ? grade_badge($s['grade']) : '—' ?>
                        </td>
                        <td>
                            <input type="text" name="scores[<?= $s['id'] ?>][comment]"
                                   class="form-control" style="width:160px;padding:6px 8px;font-size:12px"
                                   placeholder="Optional…"
                                   value="<?= e($s['teacher_comment'] ?? '') ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center">
            <div class="text-sm text-muted">
                Fields left blank will be skipped. Existing results will be updated.
            </div>
            <button type="submit" class="btn btn-success btn-lg">💾 Save All (<?= count($students) ?> students)</button>
        </div>
    </div>
</form>

<?php elseif ($class_id || $subject_id): ?>
<div class="card">
    <div class="card-body table-empty">
        <div class="table-empty-icon">👨‍🎓</div>
        No active students found for the selected class, or please select both class and subject.
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body table-empty">
        <div class="table-empty-icon">📋</div>
        Select a class, subject and term above to start entering scores.
    </div>
</div>
<?php endif; ?>

<script>
const gradeRanges = <?= json_encode(Database::fetchAll("SELECT * FROM grade_ranges ORDER BY min DESC")) ?>;
const gradeColors = { A:'badge-success', B:'badge-primary', C:'badge-warning', D:'badge-purple', F:'badge-danger' };

function calcRow(studentId) {
    const test  = parseFloat(document.getElementById('test_' + studentId)?.value || 0);
    const asn   = parseFloat(document.getElementById('asn_'  + studentId)?.value || 0);
    const exam  = parseFloat(document.getElementById('exam_' + studentId)?.value || 0);
    const total = test + asn + exam;

    const totalEl = document.getElementById('total_' + studentId);
    const gradeEl = document.getElementById('grade_' + studentId);

    if (totalEl) totalEl.textContent = total.toFixed(1);
    if (gradeEl) {
        const found = gradeRanges.find(g => total >= parseFloat(g.min) && total <= parseFloat(g.max));
        const g = found || { grade: 'F', remark: 'Fail' };
        gradeEl.innerHTML = `<span class="badge ${gradeColors[g.grade] || 'badge-secondary'}">${g.grade}</span>`;
    }

    // Highlight row if all scores entered
    const row = document.getElementById('row_' + studentId);
    if (row) {
        const hasAll = !isNaN(test) && !isNaN(asn) && !isNaN(exam);
        row.style.background = hasAll ? 'rgba(16,185,129,.04)' : '';
    }
}

function fillAll() {
    document.querySelectorAll('.score-input').forEach(input => {
        if (!input.value) {
            const max = parseFloat(input.max);
            input.value = Math.floor(Math.random() * (max * 0.4) + max * 0.5);
        }
    });
    // Recalc all rows
    <?php foreach ($students as $s): ?>
    calcRow(<?= $s['id'] ?>);
    <?php endforeach; ?>
}

// Initial calc for pre-filled rows
<?php foreach ($students as $s): if ($s['result_id']): ?>
calcRow(<?= $s['id'] ?>);
<?php endif; endforeach; ?>
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
