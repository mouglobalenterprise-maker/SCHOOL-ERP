<?php
// ============================================================
// admin/assignments.php — Assignment Management (Admin View)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role([ROLE_ADMIN, ROLE_TEACHER]);

$pageTitle  = 'Assignments';
$activeMenu = 'assignments';

$sess_id = current_session_id();
$term_id = current_term_id();

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
    $action = sanitize($_POST['action'] ?? '');

    // ── ADD assignment ────────────────────────────────────────
    if ($action === 'add') {
        $title      = sanitize($_POST['title']       ?? '');
        $desc       = sanitize($_POST['description'] ?? '');
        $subject_id = int_val($_POST['subject_id']   ?? 0);
        $class_id   = int_val($_POST['class_id']     ?? 0);
        $due_date   = sanitize($_POST['due_date']    ?? '');
        $max_score  = float_val($_POST['max_score']  ?? 20);
        $term_sel   = int_val($_POST['term_id']      ?? $term_id);

        $errors = [];
        if (empty($title))      $errors[] = 'Title is required.';
        if (!$subject_id)       $errors[] = 'Subject is required.';
        if (!$class_id)         $errors[] = 'Class is required.';
        if (empty($due_date))   $errors[] = 'Due date is required.';

        // Get teacher ID
        $teacherDbId = null;
        if (is_teacher()) {
            $myT = Database::fetchOne(
                "SELECT t.id FROM teachers t JOIN users u ON u.id=t.user_id WHERE u.id=?",
                [current_user_id()]
            );
            $teacherDbId = $myT['id'] ?? null;
            if (!$teacherDbId) $errors[] = 'Teacher profile not found.';
        } elseif (is_admin()) {
            $teacherDbId = int_val($_POST['teacher_id'] ?? 0) ?: null;
        }

        if (empty($errors)) {
            // Handle optional file upload
            $filePath = null;
            if (!empty($_FILES['assignment_file']['name'])) {
                $upload = handle_upload(
                    $_FILES['assignment_file'],
                    'assignments',
                    array_merge(ALLOWED_DOC_TYPES, ['image/jpeg','image/png','application/pdf'])
                );
                if ($upload['success']) {
                    $filePath = $upload['filename'];
                } else {
                    flash_error('File upload failed: ' . $upload['message']);
                }
            }

            $asnId = Database::insert(
                "INSERT INTO assignments
                    (title, description, subject_id, class_id, teacher_id,
                     term_id, file_path, due_date, max_score)
                 VALUES (?,?,?,?,?,?,?,?,?)",
                [$title, $desc ?: null, $subject_id, $class_id,
                 $teacherDbId, $term_sel, $filePath, $due_date, $max_score]
            );

            // Notify students in that class
            $students = Database::fetchAll(
                "SELECT user_id FROM students
                 WHERE class_id=? AND session_id=? AND status='active' AND user_id IS NOT NULL",
                [$class_id, $sess_id]
            );
            $subName = Database::fetchOne("SELECT name FROM subjects WHERE id=?", [$subject_id])['name'] ?? '';
            $clsName = Database::fetchOne("SELECT name FROM classes WHERE id=?",  [$class_id])['name']   ?? '';
            foreach ($students as $st) {
                send_notification(
                    $st['user_id'],
                    "New Assignment: {$title}",
                    "A new {$subName} assignment has been posted for {$clsName}. Due: {$due_date}.",
                    'assignment',
                    BASE_URL . '/student/assignments.php'
                );
            }

            audit_log(current_user_id(), current_username(), 'create_assignment', 'Assignments',
                "Created assignment: {$title} for class ID {$class_id}");
            flash_success("Assignment <strong>{$title}</strong> created successfully.");
        } else {
            flash_error(implode('<br>', $errors));
        }
    }

    // ── DELETE assignment ─────────────────────────────────────
    elseif ($action === 'delete') {
        $aid = int_val($_POST['asn_id'] ?? 0);
        if ($aid) {
            $asn = Database::fetchOne("SELECT title, file_path FROM assignments WHERE id=?", [$aid]);
            if ($asn) {
                if ($asn['file_path'] && file_exists(UPLOADS_PATH . '/assignments/' . $asn['file_path'])) {
                    unlink(UPLOADS_PATH . '/assignments/' . $asn['file_path']);
                }
                Database::execute("DELETE FROM assignments WHERE id=?", [$aid]);
                audit_log(current_user_id(), current_username(), 'delete_assignment', 'Assignments',
                    "Deleted: {$asn['title']}");
                flash_success('Assignment deleted.');
            }
        }
    }

    // ── GRADE submission ──────────────────────────────────────
    elseif ($action === 'grade') {
        $sub_id = int_val($_POST['sub_id'] ?? 0);
        $score  = float_val($_POST['score'] ?? 0);
        if ($sub_id) {
            Database::execute(
                "UPDATE assignment_submissions SET score=?, graded_at=NOW() WHERE id=?",
                [$score, $sub_id]
            );
            flash_success('Submission graded.');
        }
    }

    redirect(BASE_URL . '/admin/assignments.php?' . http_build_query($_GET));
}

// ── Filters ───────────────────────────────────────────────────
$class_id   = int_val($_GET['class_id']   ?? 0);
$subject_id = int_val($_GET['subject_id'] ?? 0);
$view       = sanitize($_GET['view']      ?? 'list');  // list | submissions
$asn_id     = int_val($_GET['asn_id']     ?? 0);
$page       = int_val($_GET['page']       ?? 1);

// Teacher filter: only their assignments
$teacherFilter = '';
$teacherParams = [];
if (is_teacher()) {
    $myT = Database::fetchOne(
        "SELECT t.id FROM teachers t JOIN users u ON u.id=t.user_id WHERE u.id=?",
        [current_user_id()]
    );
    if ($myT) {
        $teacherFilter = 'AND a.teacher_id = ?';
        $teacherParams = [$myT['id']];
    }
}

$where  = ['1=1'];
$params = [];
if ($class_id)   { $where[] = 'a.class_id=?';   $params[] = $class_id; }
if ($subject_id) { $where[] = 'a.subject_id=?';  $params[] = $subject_id; }
$params = array_merge($params, $teacherParams);
$whereStr = 'WHERE ' . implode(' AND ', $where) . ' ' . $teacherFilter;

$baseSql = "SELECT a.*,
                   sub.name AS subject_name,
                   c.name   AS class_name,
                   u.full_name AS teacher_name,
                   (SELECT COUNT(*) FROM assignment_submissions s WHERE s.assignment_id=a.id) AS submission_count,
                   (SELECT COUNT(*) FROM students st WHERE st.class_id=a.class_id AND st.session_id={$sess_id} AND st.status='active') AS total_students
            FROM assignments a
            JOIN subjects sub ON sub.id = a.subject_id
            JOIN classes  c   ON c.id  = a.class_id
            LEFT JOIN teachers t ON t.id = a.teacher_id
            LEFT JOIN users    u ON u.id = t.user_id
            {$whereStr}
            ORDER BY a.due_date ASC";

$pager       = paginate($baseSql, $params, $page);
$assignments = $pager['rows'];

// Submissions view
$submissions = [];
$currentAsn  = null;
if ($view === 'submissions' && $asn_id) {
    $currentAsn  = Database::fetchOne(
        "SELECT a.*, sub.name AS subject_name, c.name AS class_name
         FROM assignments a
         JOIN subjects sub ON sub.id=a.subject_id
         JOIN classes  c   ON c.id =a.class_id
         WHERE a.id=?", [$asn_id]
    );
    $submissions = Database::fetchAll(
        "SELECT s.*, st.full_name, st.student_id AS sid
         FROM assignment_submissions s
         JOIN students st ON st.id = s.student_id
         WHERE s.assignment_id=?
         ORDER BY s.submitted_at DESC",
        [$asn_id]
    );
}

// Dropdowns
$classes  = Database::fetchAll("SELECT id,name FROM classes ORDER BY sort_order");
$subjects = Database::fetchAll("SELECT id,name FROM subjects ORDER BY name");
$teachers = is_admin()
    ? Database::fetchAll("SELECT t.id, u.full_name FROM teachers t JOIN users u ON u.id=t.user_id WHERE t.status='active' ORDER BY u.full_name")
    : [];
$terms = Database::fetchAll(
    "SELECT t.* FROM terms t JOIN academic_sessions ses ON ses.id=t.session_id WHERE ses.is_current=1 ORDER BY t.id"
);

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📚 Assignments</h1>
        <p class="page-subtitle">
            <?= $pager['total'] ?> assignment(s) &nbsp;|&nbsp;
            <?= e(get_setting('current_term')) ?> Term
        </p>
    </div>
    <div class="page-header-actions">
        <?php if ($view === 'submissions'): ?>
            <a href="<?= BASE_URL ?>/admin/assignments.php" class="btn btn-outline">← Back to Assignments</a>
        <?php else: ?>
            <button class="btn btn-primary" onclick="openModal('addAsnModal')">+ Create Assignment</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($view === 'submissions' && $currentAsn): ?>
<!-- ══════════════════════════════════════════════════════
     SUBMISSIONS VIEW
══════════════════════════════════════════════════════ -->
<div class="card mb-20">
    <div class="card-header" style="background:var(--navy);color:var(--white)">
        <div>
            <div style="font-size:17px;font-weight:800"><?= e($currentAsn['title']) ?></div>
            <div style="font-size:13px;opacity:.7;margin-top:4px">
                <?= e($currentAsn['subject_name']) ?> &bull;
                <?= e($currentAsn['class_name']) ?> &bull;
                Due: <?= format_date($currentAsn['due_date']) ?>
            </div>
        </div>
        <div style="text-align:right">
            <div style="font-size:28px;font-weight:900;color:var(--accent)"><?= count($submissions) ?></div>
            <div style="font-size:12px;opacity:.7">submissions</div>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>#</th>
                <th data-sort>Student</th>
                <th>Submitted At</th>
                <th>File</th>
                <th>Comment</th>
                <th>Score /<?= $currentAsn['max_score'] ?></th>
                <th>Graded</th>
                <th>Actions</th>
            </tr></thead>
            <tbody>
            <?php if ($submissions): $i=1; foreach ($submissions as $sub): ?>
                <tr>
                    <td class="text-muted text-sm"><?= $i++ ?></td>
                    <td>
                        <div style="font-weight:700"><?= e($sub['full_name']) ?></div>
                        <div class="text-xs text-muted"><?= e($sub['sid']) ?></div>
                    </td>
                    <td class="text-sm"><?= format_date($sub['submitted_at'], 'd M Y H:i') ?></td>
                    <td>
                        <?php if ($sub['file_path']): ?>
                            <a href="<?= UPLOADS_URL ?>/assignments/<?= e($sub['file_path']) ?>"
                               target="_blank" class="btn btn-sm btn-outline">📎 Download</a>
                        <?php else: ?>
                            <span class="text-muted text-sm">No file</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm" style="max-width:180px">
                        <?= e($sub['comment'] ?: '—') ?>
                    </td>
                    <td>
                        <strong style="font-size:15px;color:<?= $sub['score'] !== null ? 'var(--navy)' : 'var(--text-muted)' ?>">
                            <?= $sub['score'] !== null ? $sub['score'] : '—' ?>
                        </strong>
                    </td>
                    <td>
                        <?= $sub['graded_at']
                            ? '<span class="badge badge-success">✅ Graded</span>'
                            : '<span class="badge badge-warning">Pending</span>' ?>
                    </td>
                    <td>
                        <form method="POST" style="display:flex;gap:6px;align-items:center">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="grade">
                            <input type="hidden" name="sub_id" value="<?= $sub['id'] ?>">
                            <input type="number" name="score" class="form-control"
                                   style="width:70px;padding:5px 8px"
                                   min="0" max="<?= $currentAsn['max_score'] ?>" step="0.5"
                                   value="<?= $sub['score'] ?? '' ?>"
                                   placeholder="Score">
                            <button type="submit" class="btn btn-sm btn-success">✓ Grade</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="8" class="table-empty">No submissions yet for this assignment.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════
     ASSIGNMENTS LIST VIEW
══════════════════════════════════════════════════════ -->

<!-- Filters -->
<div class="card mb-20">
    <div class="table-toolbar">
        <form method="GET" style="display:contents">
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
            <div class="table-toolbar-right">
                <a href="<?= BASE_URL ?>/admin/assignments.php" class="btn btn-outline btn-sm">↺ Reset</a>
            </div>
        </form>
    </div>

    <!-- Assignment cards grid -->
    <div style="padding:16px">
        <?php if ($assignments): ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px">
        <?php foreach ($assignments as $asn):
            $daysLeft   = days_until($asn['due_date']);
            $isOverdue  = $daysLeft < 0;
            $subPct     = $asn['total_students'] > 0
                ? round(($asn['submission_count'] / $asn['total_students']) * 100) : 0;
            $barCol     = $subPct >= 80 ? 'var(--emerald)' : ($subPct >= 40 ? 'var(--accent)' : 'var(--red)');
        ?>
            <div class="card" style="border-top:3px solid <?= $isOverdue?'var(--red)':($daysLeft<=2?'var(--accent)':'var(--navy)') ?>">
                <div style="padding:16px">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
                        <div style="flex:1">
                            <div style="font-weight:800;font-size:15px;margin-bottom:4px"><?= e($asn['title']) ?></div>
                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                                <span class="badge badge-navy"><?= e($asn['class_name']) ?></span>
                                <span class="badge badge-primary"><?= e($asn['subject_name']) ?></span>
                            </div>
                        </div>
                        <?php if ($isOverdue): ?>
                            <span class="badge badge-danger">Overdue</span>
                        <?php elseif ($daysLeft === 0): ?>
                            <span class="badge badge-warning">Due Today</span>
                        <?php elseif ($daysLeft <= 2): ?>
                            <span class="badge badge-warning">Due in <?= $daysLeft ?>d</span>
                        <?php else: ?>
                            <span class="badge badge-success"><?= $daysLeft ?>d left</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($asn['description']): ?>
                        <p class="text-sm text-muted" style="margin:8px 0;line-height:1.5">
                            <?= e(substr($asn['description'],0,100)) ?><?= strlen($asn['description'])>100?'…':'' ?>
                        </p>
                    <?php endif; ?>

                    <div class="text-xs text-muted" style="margin-bottom:10px">
                        👤 <?= e($asn['teacher_name'] ?? 'N/A') ?> &bull;
                        📅 Due: <strong><?= format_date($asn['due_date']) ?></strong> &bull;
                        Max: <?= $asn['max_score'] ?> pts
                        <?php if ($asn['file_path']): ?>
                            &bull; <a href="<?= UPLOADS_URL ?>/assignments/<?= e($asn['file_path']) ?>" target="_blank">📎 File attached</a>
                        <?php endif; ?>
                    </div>

                    <!-- Submission progress -->
                    <div style="margin-bottom:10px">
                        <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                            <span class="text-xs text-muted">Submissions</span>
                            <span class="text-xs fw-700"><?= $asn['submission_count'] ?> / <?= $asn['total_students'] ?></span>
                        </div>
                        <div class="progress" style="height:8px">
                            <div class="progress-bar" style="width:<?= $subPct ?>%;background:<?= $barCol ?>"></div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <a href="?view=submissions&asn_id=<?= $asn['id'] ?>"
                           class="btn btn-sm btn-primary">📋 Submissions (<?= $asn['submission_count'] ?>)</a>
                        <form method="POST" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="asn_id" value="<?= $asn['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger"
                                    data-confirm="Delete assignment '<?= e($asn['title']) ?>'?">🗑️</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="table-empty">
            <div class="table-empty-icon">📚</div>
            No assignments found.
            <br>
            <button onclick="openModal('addAsnModal')" class="btn btn-primary btn-sm" style="margin-top:10px">
                + Create First Assignment
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?= pagination_links($pager, BASE_URL . '/admin/assignments.php?class_id=' . $class_id . '&subject_id=' . $subject_id) ?>
</div>
<?php endif; ?>

<!-- ── Add Assignment Modal ── -->
<div class="modal-backdrop" id="addAsnModal" style="display:none">
    <div class="modal modal-lg">
        <div class="modal-header">
            <span class="modal-title">+ Create Assignment</span>
            <button class="modal-close" data-modal-close>×</button>
        </div>
        <form method="POST" action="" enctype="multipart/form-data" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Assignment Title <span class="req">*</span></label>
                    <input type="text" name="title" class="form-control" required
                           placeholder="e.g. Chapter 5 Practice Set">
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Class <span class="req">*</span></label>
                        <select name="class_id" class="form-control" required>
                            <option value="">Select class…</option>
                            <?php foreach ($classes as $cls): ?>
                                <option value="<?= $cls['id'] ?>"><?= e($cls['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Subject <span class="req">*</span></label>
                        <select name="subject_id" class="form-control" required>
                            <option value="">Select subject…</option>
                            <?php foreach ($subjects as $sub): ?>
                                <option value="<?= $sub['id'] ?>"><?= e($sub['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (is_admin()): ?>
                    <div class="form-group">
                        <label class="form-label">Teacher</label>
                        <select name="teacher_id" class="form-control">
                            <option value="">Select teacher…</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= e($t['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label">Term</label>
                        <select name="term_id" class="form-control">
                            <?php foreach ($terms as $term): ?>
                                <option value="<?= $term['id'] ?>" <?= $term['is_current']?'selected':'' ?>>
                                    <?= e($term['name']) ?> Term
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Due Date <span class="req">*</span></label>
                        <input type="date" name="due_date" class="form-control" required
                               min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Max Score</label>
                        <input type="number" name="max_score" class="form-control"
                               value="20" min="1" max="100" step="0.5">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description / Instructions</label>
                    <textarea name="description" class="form-control" rows="4"
                              placeholder="Describe the assignment task and any special instructions…"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Attach File <small class="text-muted">(PDF, Word, Image — max 5MB)</small></label>
                    <input type="file" name="assignment_file" class="form-control"
                           accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">📚 Create Assignment</button>
            </div>
        </form>
    </div>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
