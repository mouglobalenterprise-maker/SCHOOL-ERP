<?php
// ============================================================
// admin/results_enter.php — Enter / Edit Single Result
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role([ROLE_ADMIN, ROLE_TEACHER]);

$pageTitle  = 'Enter Result';
$activeMenu = 'results';

$sess_id = current_session_id();
$term_id = current_term_id();
$editId  = int_val($_GET['edit'] ?? 0);
$isEdit  = $editId > 0;

// Load existing result for edit
$existing = null;
if ($isEdit) {
    $existing = Database::fetchOne(
        "SELECT r.*, s.full_name, s.student_id AS sid, sub.name AS subject_name, c.name AS class_name
         FROM results r
         JOIN students s   ON s.id  = r.student_id
         JOIN subjects sub ON sub.id = r.subject_id
         JOIN classes  c   ON c.id  = r.class_id
         WHERE r.id = ?",
        [$editId]
    );
    if (!$existing) { flash_error('Result not found.'); redirect(BASE_URL . '/admin/results.php'); }

    // Teacher can only edit their own subject/class
    if (is_teacher()) {
        $myTeacher = Database::fetchOne(
            "SELECT t.id FROM teachers t JOIN users u ON u.id=t.user_id WHERE u.id=?",
            [current_user_id()]
        );
        if ($myTeacher) {
            $allowed = Database::fetchOne(
                "SELECT id FROM teacher_subjects WHERE teacher_id=? AND subject_id=? AND class_id=?",
                [$myTeacher['id'], $existing['subject_id'], $existing['class_id']]
            );
            if (!$allowed) {
                flash_error('You are not assigned to this subject/class.');
                redirect(BASE_URL . '/teacher/results.php');
            }
        }
    }
}

$maxTest = (int) get_setting('results_test_max', '20');
$maxAsn  = (int) get_setting('results_asn_max',  '20');
$maxExam = (int) get_setting('results_exam_max',  '60');
$maxTotal = $maxTest + $maxAsn + $maxExam;

$errors = [];
$form = [
    'student_id'       => $existing['student_id']       ?? '',
    'subject_id'       => $existing['subject_id']       ?? '',
    'class_id'         => $existing['class_id']         ?? '',
    'term_id'          => $existing['term_id']          ?? $term_id,
    'test_score'       => $existing['test_score']       ?? '',
    'assignment_score' => $existing['assignment_score'] ?? '',
    'exam_score'       => $existing['exam_score']       ?? '',
    'teacher_comment'  => $existing['teacher_comment']  ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();

    $fields = ['student_id','subject_id','class_id','term_id',
               'test_score','assignment_score','exam_score','teacher_comment'];
    foreach ($fields as $k) $form[$k] = sanitize($_POST[$k] ?? '');

    $studentDbId = int_val($form['student_id']);
    $subjectId   = int_val($form['subject_id']);
    $classId     = int_val($form['class_id']);
    $termId      = int_val($form['term_id']);
    $testScore   = float_val($form['test_score']);
    $asnScore    = float_val($form['assignment_score']);
    $examScore   = float_val($form['exam_score']);

    // Validate
    if (!$studentDbId)  $errors['student_id']       = 'Please select a student.';
    if (!$subjectId)    $errors['subject_id']        = 'Please select a subject.';
    if (!$classId)      $errors['class_id']          = 'Please select a class.';
    if (!$termId)       $errors['term_id']           = 'Please select a term.';
    if ($form['test_score'] === '') $errors['test_score'] = 'Test score is required.';
    elseif ($testScore < 0 || $testScore > $maxTest)
        $errors['test_score'] = "Test score must be between 0 and {$maxTest}.";
    if ($form['assignment_score'] === '') $errors['assignment_score'] = 'Assignment score is required.';
    elseif ($asnScore < 0 || $asnScore > $maxAsn)
        $errors['assignment_score'] = "Assignment score must be between 0 and {$maxAsn}.";
    if ($form['exam_score'] === '') $errors['exam_score'] = 'Exam score is required.';
    elseif ($examScore < 0 || $examScore > $maxExam)
        $errors['exam_score'] = "Exam score must be between 0 and {$maxExam}.";

    // Check duplicate (skip for edit)
    if (empty($errors) && !$isEdit) {
        $dup = Database::fetchOne(
            "SELECT id FROM results WHERE student_id=? AND subject_id=? AND term_id=? AND session_id=?",
            [$studentDbId, $subjectId, $termId, $sess_id]
        );
        if ($dup) {
            $errors['_global'] = 'A result already exists for this student/subject/term. <a href="' . BASE_URL . '/admin/results_enter.php?edit=' . $dup['id'] . '">Edit it instead</a>.';
        }
    }

    if (empty($errors)) {
        $total     = $testScore + $asnScore + $examScore;
        $gradeInfo = get_grade($total);

        // Get teacher ID
        $teacherDbId = null;
        if (is_teacher()) {
            $myT = Database::fetchOne(
                "SELECT t.id FROM teachers t JOIN users u ON u.id=t.user_id WHERE u.id=?",
                [current_user_id()]
            );
            $teacherDbId = $myT['id'] ?? null;
        }

        if ($isEdit) {
            Database::execute(
                "UPDATE results SET
                    student_id=?, subject_id=?, class_id=?, term_id=?,
                    test_score=?, assignment_score=?, exam_score=?,
                    grade=?, remark=?, teacher_comment=?, teacher_id=?
                 WHERE id=?",
                [
                    $studentDbId, $subjectId, $classId, $termId,
                    $testScore, $asnScore, $examScore,
                    $gradeInfo['grade'], $gradeInfo['remark'],
                    $form['teacher_comment'] ?: null,
                    $teacherDbId, $editId
                ]
            );
            audit_log(current_user_id(), current_username(), 'update_result', 'Results',
                "Updated result ID {$editId}");
            flash_success('Result updated successfully.');
        } else {
            Database::insert(
                "INSERT INTO results
                    (student_id, subject_id, class_id, term_id, session_id,
                     test_score, assignment_score, exam_score,
                     grade, remark, teacher_comment, teacher_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                [
                    $studentDbId, $subjectId, $classId, $termId, $sess_id,
                    $testScore, $asnScore, $examScore,
                    $gradeInfo['grade'], $gradeInfo['remark'],
                    $form['teacher_comment'] ?: null, $teacherDbId
                ]
            );

            // Send notification to student if they have a portal account
            $studentRec = Database::fetchOne("SELECT user_id, full_name FROM students WHERE id=?",[$studentDbId]);
            if ($studentRec && $studentRec['user_id']) {
                $subName = Database::fetchOne("SELECT name FROM subjects WHERE id=?",[$subjectId])['name'] ?? '';
                send_notification(
                    $studentRec['user_id'],
                    'New Result Posted',
                    "Your {$subName} result for " . get_setting('current_term') . " Term has been posted. Total: {$total}, Grade: {$gradeInfo['grade']}.",
                    'result'
                );
            }

            audit_log(current_user_id(), current_username(), 'create_result', 'Results',
                "Added result for student ID {$studentDbId}, subject ID {$subjectId}");
            flash_success('Result saved successfully.');
        }

        $back = is_teacher() ? BASE_URL . '/teacher/results.php' : BASE_URL . '/admin/results.php';
        redirect($back);
    }
}

// Dropdown data
$terms = Database::fetchAll(
    "SELECT t.* FROM terms t JOIN academic_sessions ses ON ses.id=t.session_id WHERE ses.is_current=1 ORDER BY t.id"
);
$classes = Database::fetchAll("SELECT id,name FROM classes ORDER BY sort_order");

// Teacher-filtered subjects and classes
if (is_teacher()) {
    $myTeacher = Database::fetchOne(
        "SELECT t.id FROM teachers t JOIN users u ON u.id=t.user_id WHERE u.id=?",
        [current_user_id()]
    );
    $allowedSubjects = $myTeacher
        ? Database::fetchAll(
            "SELECT DISTINCT sub.id, sub.name FROM teacher_subjects ts
             JOIN subjects sub ON sub.id=ts.subject_id
             WHERE ts.teacher_id=? ORDER BY sub.name",
            [$myTeacher['id']]
          )
        : [];
    $allowedClasses = $myTeacher
        ? Database::fetchAll(
            "SELECT DISTINCT c.id, c.name FROM teacher_subjects ts
             JOIN classes c ON c.id=ts.class_id
             WHERE ts.teacher_id=? ORDER BY c.sort_order",
            [$myTeacher['id']]
          )
        : [];
    $subjects = $allowedSubjects;
    $classes  = $allowedClasses;
} else {
    $subjects = Database::fetchAll("SELECT id,name FROM subjects ORDER BY name");
}

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title"><?= $isEdit ? '✏️ Edit Result' : '+ Enter Result' ?></h1>
        <p class="page-subtitle">
            <?= $isEdit ? "Editing: {$existing['full_name']} — {$existing['subject_name']}" : 'Add a new student result for the current term' ?>
        </p>
    </div>
    <div class="page-header-actions">
        <a href="<?= is_teacher() ? BASE_URL.'/teacher/results.php' : BASE_URL.'/admin/results.php' ?>"
           class="btn btn-outline">← Back to Results</a>
    </div>
</div>

<?php if (!empty($errors['_global'])): ?>
    <div class="alert alert-error"><?= $errors['_global'] ?></div>
<?php endif; ?>

<div class="grid-2" style="gap:20px;align-items:start">

    <!-- ── Form ── -->
    <div>
        <form method="POST" action="" data-validate id="resultForm">
            <?= csrf_field() ?>
            <div class="card">
                <div class="card-header">📋 Student & Subject</div>
                <div class="card-body">
                    <?php if ($isEdit): ?>
                        <!-- Show read-only info when editing -->
                        <input type="hidden" name="student_id" value="<?= $existing['student_id'] ?>">
                        <input type="hidden" name="subject_id" value="<?= $existing['subject_id'] ?>">
                        <input type="hidden" name="class_id"   value="<?= $existing['class_id'] ?>">
                        <div style="background:var(--light);border-radius:10px;padding:14px;margin-bottom:16px">
                            <div style="font-weight:800;font-size:15px"><?= e($existing['full_name']) ?></div>
                            <div class="text-sm text-muted"><?= e($existing['student_id']) ?></div>
                            <div style="margin-top:8px;display:flex;gap:8px">
                                <span class="badge badge-navy"><?= e($existing['class_name']) ?></span>
                                <span class="badge badge-primary"><?= e($existing['subject_name']) ?></span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="form-group">
                            <label class="form-label">Class <span class="req">*</span></label>
                            <select name="class_id" id="classSelect"
                                    class="form-control <?= isset($errors['class_id'])?'is-invalid':'' ?>"
                                    onchange="loadStudents(this.value)" required>
                                <option value="">Select class…</option>
                                <?php foreach ($classes as $cls): ?>
                                    <option value="<?= $cls['id'] ?>" <?= $form['class_id']==$cls['id']?'selected':'' ?>>
                                        <?= e($cls['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['class_id'])): ?><div class="form-error"><?= e($errors['class_id']) ?></div><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Student <span class="req">*</span></label>
                            <select name="student_id" id="studentSelect"
                                    class="form-control <?= isset($errors['student_id'])?'is-invalid':'' ?>" required>
                                <option value="">Select class first…</option>
                            </select>
                            <?php if (isset($errors['student_id'])): ?><div class="form-error"><?= e($errors['student_id']) ?></div><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Subject <span class="req">*</span></label>
                            <select name="subject_id"
                                    class="form-control <?= isset($errors['subject_id'])?'is-invalid':'' ?>" required>
                                <option value="">Select subject…</option>
                                <?php foreach ($subjects as $sub): ?>
                                    <option value="<?= $sub['id'] ?>" <?= $form['subject_id']==$sub['id']?'selected':'' ?>>
                                        <?= e($sub['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['subject_id'])): ?><div class="form-error"><?= e($errors['subject_id']) ?></div><?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="form-label">Term <span class="req">*</span></label>
                        <select name="term_id"
                                class="form-control <?= isset($errors['term_id'])?'is-invalid':'' ?>" required>
                            <option value="">Select term…</option>
                            <?php foreach ($terms as $term): ?>
                                <option value="<?= $term['id'] ?>" <?= $form['term_id']==$term['id']?'selected':'' ?>>
                                    <?= e($term['name']) ?> Term
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['term_id'])): ?><div class="form-error"><?= e($errors['term_id']) ?></div><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card mt-20">
                <div class="card-header">🎯 Score Entry</div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">
                            Test Score
                            <small class="text-muted">(Max: <?= $maxTest ?>)</small>
                            <span class="req">*</span>
                        </label>
                        <input type="number" name="test_score" id="test_score"
                               class="form-control <?= isset($errors['test_score'])?'is-invalid':'' ?>"
                               value="<?= e($form['test_score']) ?>"
                               min="0" max="<?= $maxTest ?>" step="0.5"
                               oninput="calcResultTotal()" required
                               placeholder="0–<?= $maxTest ?>">
                        <?php if (isset($errors['test_score'])): ?><div class="form-error"><?= e($errors['test_score']) ?></div><?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            Assignment Score
                            <small class="text-muted">(Max: <?= $maxAsn ?>)</small>
                            <span class="req">*</span>
                        </label>
                        <input type="number" name="assignment_score" id="asn_score"
                               class="form-control <?= isset($errors['assignment_score'])?'is-invalid':'' ?>"
                               value="<?= e($form['assignment_score']) ?>"
                               min="0" max="<?= $maxAsn ?>" step="0.5"
                               oninput="calcResultTotal()" required
                               placeholder="0–<?= $maxAsn ?>">
                        <?php if (isset($errors['assignment_score'])): ?><div class="form-error"><?= e($errors['assignment_score']) ?></div><?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            Exam Score
                            <small class="text-muted">(Max: <?= $maxExam ?>)</small>
                            <span class="req">*</span>
                        </label>
                        <input type="number" name="exam_score" id="exam_score"
                               class="form-control <?= isset($errors['exam_score'])?'is-invalid':'' ?>"
                               value="<?= e($form['exam_score']) ?>"
                               min="0" max="<?= $maxExam ?>" step="0.5"
                               oninput="calcResultTotal()" required
                               placeholder="0–<?= $maxExam ?>">
                        <?php if (isset($errors['exam_score'])): ?><div class="form-error"><?= e($errors['exam_score']) ?></div><?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Teacher Comment</label>
                        <textarea name="teacher_comment" class="form-control" rows="3"
                                  placeholder="Optional comment about student's performance…"><?= e($form['teacher_comment']) ?></textarea>
                    </div>
                </div>
                <div class="card-footer">
                    <div style="display:flex;gap:10px">
                        <button type="submit" class="btn btn-primary btn-lg">💾 <?= $isEdit?'Save Changes':'Save Result' ?></button>
                        <a href="<?= is_teacher() ? BASE_URL.'/teacher/results.php' : BASE_URL.'/admin/results.php' ?>"
                           class="btn btn-outline btn-lg">Cancel</a>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- ── Live Preview ── -->
    <div>
        <div class="card" style="position:sticky;top:76px">
            <div class="card-header">📊 Live Score Preview</div>
            <div class="card-body" style="text-align:center">
                <!-- Score breakdown -->
                <div style="display:flex;justify-content:space-around;margin-bottom:24px">
                    <?php
                    $scoreBlocks = [
                        ['Test', 'test_score', $maxTest, '#3B82F6'],
                        ['Assignment', 'asn_score', $maxAsn, '#8B5CF6'],
                        ['Exam', 'exam_score', $maxExam, '#10B981'],
                    ];
                    foreach ($scoreBlocks as [$label, $inputId, $max, $color]): ?>
                    <div>
                        <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px"><?= $label ?></div>
                        <div id="preview_<?= $inputId ?>" style="font-size:24px;font-weight:900;color:<?= $color ?>">
                            <?= $isEdit ? (float)$existing[$inputId === 'asn_score' ? 'assignment_score' : $inputId] : '—' ?>
                        </div>
                        <div style="font-size:11px;color:var(--text-muted)">/ <?= $max ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Total & Grade display -->
                <div style="background:var(--navy);border-radius:14px;padding:24px;margin-bottom:16px">
                    <div style="font-size:13px;color:rgba(255,255,255,.6);margin-bottom:6px;text-transform:uppercase;letter-spacing:.06em">Total Score</div>
                    <div id="calc_total" style="font-size:52px;font-weight:900;color:var(--accent);line-height:1">
                        <?php if ($isEdit):
                            echo number_format($existing['test_score'] + $existing['assignment_score'] + $existing['exam_score'], 1);
                        else: ?>—<?php endif; ?>
                    </div>
                    <div style="font-size:13px;color:rgba(255,255,255,.6);margin-top:4px">/ <?= $maxTotal ?></div>
                </div>

                <div style="margin-bottom:16px">
                    <div style="font-size:13px;color:var(--text-muted);margin-bottom:8px">Grade</div>
                    <div id="calc_grade" style="font-size:40px">
                        <?= $isEdit ? grade_badge($existing['grade'] ?? 'F') : '—' ?>
                    </div>
                    <div id="calc_remark" style="font-size:14px;color:var(--text-muted);margin-top:6px">
                        <?= $isEdit ? e($existing['remark'] ?? '') : '' ?>
                    </div>
                </div>

                <!-- Grade scale reference -->
                <div style="border-top:1px solid var(--border);padding-top:14px;text-align:left">
                    <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:8px;text-transform:uppercase">Grade Scale</div>
                    <?php
                    $gradeRanges = Database::fetchAll("SELECT * FROM grade_ranges ORDER BY min DESC");
                    foreach ($gradeRanges as $gr): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                        <?= grade_badge($gr['grade']) ?>
                        <span class="text-sm"><?= $gr['min'] ?> – <?= $gr['max'] ?></span>
                        <span class="text-sm text-muted"><?= e($gr['remark']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
// Grade ranges from PHP
const gradeRanges = <?= json_encode($gradeRanges ?? []) ?>;
const maxTest = <?= $maxTest ?>;
const maxAsn  = <?= $maxAsn ?>;
const maxExam = <?= $maxExam ?>;

function calcResultTotal() {
    const test = parseFloat(document.getElementById('test_score')?.value || 0);
    const asn  = parseFloat(document.getElementById('asn_score')?.value  || 0);
    const exam = parseFloat(document.getElementById('exam_score')?.value  || 0);
    const total = test + asn + exam;

    // Update preview values
    document.getElementById('preview_test_score').textContent = isNaN(test) ? '—' : test;
    document.getElementById('preview_asn_score').textContent  = isNaN(asn)  ? '—' : asn;
    document.getElementById('preview_exam_score').textContent = isNaN(exam) ? '—' : exam;
    document.getElementById('calc_total').textContent         = isNaN(total)? '—' : total.toFixed(1);

    // Grade lookup
    const gradeEl  = document.getElementById('calc_grade');
    const remarkEl = document.getElementById('calc_remark');
    if (!isNaN(total)) {
        const found = gradeRanges.find(g => total >= parseFloat(g.min) && total <= parseFloat(g.max));
        const g     = found || { grade: 'F', remark: 'Fail' };
        const colors = { A:'badge-success', B:'badge-primary', C:'badge-warning', D:'badge-purple', F:'badge-danger' };
        gradeEl.innerHTML  = `<span class="badge ${colors[g.grade] || 'badge-secondary'}" style="font-size:28px;padding:8px 20px">${g.grade}</span>`;
        remarkEl.textContent = g.remark;
    } else {
        gradeEl.textContent  = '—';
        remarkEl.textContent = '';
    }
}

// Load students for selected class via AJAX
function loadStudents(classId) {
    const sel = document.getElementById('studentSelect');
    if (!classId) {
        sel.innerHTML = '<option value="">Select class first…</option>';
        return;
    }
    sel.innerHTML = '<option value="">Loading…</option>';

    fetch('<?= BASE_URL ?>/api/get_students.php?class_id=' + classId + '&status=active&limit=200', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.data && data.data.length) {
            sel.innerHTML = '<option value="">Select student…</option>' +
                data.data.map(s =>
                    `<option value="${s.id}">${s.full_name} (${s.student_id})</option>`
                ).join('');
        } else {
            sel.innerHTML = '<option value="">No students in this class</option>';
        }
    })
    .catch(() => {
        sel.innerHTML = '<option value="">Error loading students</option>';
    });
}

// Trigger on page load if class pre-selected (e.g. after validation error)
<?php if (!$isEdit && $form['class_id']): ?>
loadStudents(<?= (int)$form['class_id'] ?>);
<?php endif; ?>

// Trigger on page load for edit mode preview
<?php if ($isEdit): ?>
calcResultTotal();
<?php endif; ?>
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
