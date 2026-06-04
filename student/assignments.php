<?php
// ============================================================
// student/assignments.php — Student Assignment Portal
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_STUDENT);

$pageTitle  = 'My Assignments';
$activeMenu = 'assignments';

$sess_id = current_session_id();
$term_id = current_term_id();

// Get student record
$student = Database::fetchOne(
    "SELECT s.*, c.name AS class_name
     FROM students s JOIN classes c ON c.id=s.class_id
     WHERE s.user_id=? AND s.session_id=?",
    [current_user_id(), $sess_id]
);
if (!$student) {
    flash_error('No student profile linked to your account.');
    redirect(BASE_URL . '/student/dashboard.php');
}

// ── Handle file submission ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
    $action = sanitize($_POST['action'] ?? '');
    $asn_id = int_val($_POST['asn_id']  ?? 0);

    if ($action === 'submit' && $asn_id) {
        // Check not already submitted
        $existing = Database::fetchOne(
            "SELECT id FROM assignment_submissions WHERE assignment_id=? AND student_id=?",
            [$asn_id, $student['id']]
        );
        if ($existing) {
            flash_error('You have already submitted this assignment.');
        } else {
            $comment  = sanitize($_POST['comment'] ?? '');
            $filePath = null;

            if (!empty($_FILES['submit_file']['name'])) {
                $upload = handle_upload(
                    $_FILES['submit_file'],
                    'assignments',
                    array_merge(ALLOWED_DOC_TYPES, ['image/jpeg','image/png','application/pdf'])
                );
                if ($upload['success']) {
                    $filePath = $upload['filename'];
                } else {
                    flash_error('Upload failed: ' . $upload['message']);
                    redirect(BASE_URL . '/student/assignments.php');
                }
            }

            Database::insert(
                "INSERT INTO assignment_submissions (assignment_id, student_id, file_path, comment)
                 VALUES (?,?,?,?)",
                [$asn_id, $student['id'], $filePath, $comment ?: null]
            );

            audit_log(current_user_id(), current_username(), 'submit_assignment', 'Assignments',
                "Student {$student['student_id']} submitted assignment ID {$asn_id}");
            flash_success('Assignment submitted successfully! ✅');
        }
        redirect(BASE_URL . '/student/assignments.php');
    }
}

// Fetch assignments for this student's class
$assignments = Database::fetchAll(
    "SELECT a.*,
            sub.name AS subject_name,
            u.full_name AS teacher_name,
            s.id AS sub_id,
            s.score, s.submitted_at, s.graded_at, s.file_path AS sub_file
     FROM assignments a
     JOIN subjects sub ON sub.id = a.subject_id
     LEFT JOIN teachers t  ON t.id = a.teacher_id
     LEFT JOIN users    u  ON u.id = t.user_id
     LEFT JOIN assignment_submissions s
           ON s.assignment_id = a.id AND s.student_id = ?
     WHERE a.class_id = ? AND a.term_id = ?
     ORDER BY a.due_date ASC",
    [$student['id'], $student['class_id'], $term_id]
);

// Split into pending/submitted/overdue
$pending    = array_filter($assignments, fn($a) => !$a['sub_id'] && days_until($a['due_date']) >= 0);
$submitted  = array_filter($assignments, fn($a) =>  $a['sub_id']);
$overdue    = array_filter($assignments, fn($a) => !$a['sub_id'] && days_until($a['due_date']) < 0);

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📚 My Assignments</h1>
        <p class="page-subtitle">
            <?= e($student['full_name']) ?> &mdash;
            <?= e($student['class_name']) ?> &mdash;
            <?= e(get_setting('current_term')) ?> Term
        </p>
    </div>
</div>

<!-- Summary stats -->
<div class="stats-grid mb-20" style="grid-template-columns:repeat(4,1fr)">
    <div class="stat-card stat-blue">
        <div class="stat-icon">📚</div>
        <div class="stat-info"><div class="stat-value"><?= count($assignments) ?></div><div class="stat-label">Total</div></div>
    </div>
    <div class="stat-card stat-gold">
        <div class="stat-icon">⏳</div>
        <div class="stat-info"><div class="stat-value"><?= count($pending) ?></div><div class="stat-label">Pending</div></div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-icon">✅</div>
        <div class="stat-info"><div class="stat-value"><?= count($submitted) ?></div><div class="stat-label">Submitted</div></div>
    </div>
    <div class="stat-card stat-red">
        <div class="stat-icon">⚠️</div>
        <div class="stat-info"><div class="stat-value"><?= count($overdue) ?></div><div class="stat-label">Overdue</div></div>
    </div>
</div>

<!-- Overdue alert -->
<?php if (!empty($overdue)): ?>
<div class="alert alert-error mb-20" style="font-size:14px">
    ⚠️ You have <strong><?= count($overdue) ?></strong> overdue assignment(s). Please speak to your teacher immediately.
</div>
<?php endif; ?>

<!-- Pending Assignments -->
<?php if (!empty($pending)): ?>
<h3 style="font-size:14px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin:0 0 12px">
    ⏳ Pending Assignments (<?= count($pending) ?>)
</h3>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:24px">
<?php foreach ($pending as $asn):
    $daysLeft = days_until($asn['due_date']);
?>
    <div class="card" style="border-left:4px solid <?= $daysLeft<=1?'var(--red)':($daysLeft<=3?'var(--accent)':'var(--navy)') ?>">
        <div style="padding:16px">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
                <div>
                    <div style="font-weight:800;font-size:15px"><?= e($asn['title']) ?></div>
                    <div style="display:flex;gap:6px;margin-top:4px">
                        <span class="badge badge-primary"><?= e($asn['subject_name']) ?></span>
                    </div>
                </div>
                <span class="badge <?= $daysLeft<=1?'badge-danger':($daysLeft<=3?'badge-warning':'badge-success') ?>">
                    <?= $daysLeft===0?'Due Today':"Due in {$daysLeft}d" ?>
                </span>
            </div>

            <?php if ($asn['description']): ?>
                <p class="text-sm text-muted" style="margin-bottom:10px;line-height:1.5">
                    <?= e(substr($asn['description'],0,120)) ?><?= strlen($asn['description'])>120?'…':'' ?>
                </p>
            <?php endif; ?>

            <div class="text-xs text-muted" style="margin-bottom:12px">
                👤 <?= e($asn['teacher_name'] ?? '—') ?> &bull;
                📅 Due: <strong><?= format_date($asn['due_date']) ?></strong> &bull;
                Max: <?= $asn['max_score'] ?> pts
                <?php if ($asn['file_path']): ?>
                    &bull; <a href="<?= UPLOADS_URL ?>/assignments/<?= e($asn['file_path']) ?>" target="_blank">📎 View File</a>
                <?php endif; ?>
            </div>

            <button class="btn btn-sm btn-primary btn-block"
                    onclick="openSubmitModal(<?= $asn['id'] ?>, '<?= e(addslashes($asn['title'])) ?>')">
                📤 Submit Assignment
            </button>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Submitted Assignments -->
<?php if (!empty($submitted)): ?>
<h3 style="font-size:14px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin:0 0 12px">
    ✅ Submitted Assignments (<?= count($submitted) ?>)
</h3>
<div class="card mb-24">
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>Assignment</th>
                <th>Subject</th>
                <th>Submitted</th>
                <th>Score</th>
                <th>Max</th>
                <th>Status</th>
                <th>File</th>
            </tr></thead>
            <tbody>
            <?php foreach ($submitted as $asn): ?>
                <tr>
                    <td><strong><?= e($asn['title']) ?></strong></td>
                    <td><?= e($asn['subject_name']) ?></td>
                    <td class="text-sm text-muted"><?= format_date($asn['submitted_at'],'d M Y') ?></td>
                    <td>
                        <?php if ($asn['score'] !== null): ?>
                            <strong style="font-size:16px;color:<?= $asn['score']>=$asn['max_score']*0.5?'var(--emerald)':'var(--red)' ?>">
                                <?= $asn['score'] ?>
                            </strong>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted"><?= $asn['max_score'] ?></td>
                    <td>
                        <?= $asn['graded_at']
                            ? '<span class="badge badge-success">✅ Graded</span>'
                            : '<span class="badge badge-warning">⏳ Awaiting Grade</span>' ?>
                    </td>
                    <td>
                        <?php if ($asn['sub_file']): ?>
                            <a href="<?= UPLOADS_URL ?>/assignments/<?= e($asn['sub_file']) ?>"
                               target="_blank" class="btn btn-sm btn-outline">📎 My File</a>
                        <?php else: ?>
                            <span class="text-muted text-sm">Text only</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Overdue -->
<?php if (!empty($overdue)): ?>
<h3 style="font-size:14px;font-weight:700;color:var(--red);text-transform:uppercase;letter-spacing:.05em;margin:0 0 12px">
    ⚠️ Overdue (<?= count($overdue) ?>)
</h3>
<div class="card">
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Assignment</th><th>Subject</th><th>Due Date</th><th>Days Overdue</th></tr></thead>
            <tbody>
            <?php foreach ($overdue as $asn): ?>
                <tr style="background:#FEF2F2">
                    <td><strong><?= e($asn['title']) ?></strong></td>
                    <td><?= e($asn['subject_name']) ?></td>
                    <td style="color:var(--red);font-weight:700"><?= format_date($asn['due_date']) ?></td>
                    <td><span class="badge badge-danger"><?= abs(days_until($asn['due_date'])) ?> days overdue</span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (empty($assignments)): ?>
<div class="card"><div class="card-body table-empty">
    <div class="table-empty-icon">📚</div>
    No assignments posted for your class this term yet.
</div></div>
<?php endif; ?>

<!-- Submit Modal -->
<div class="modal-backdrop" id="submitModal" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">📤 Submit Assignment</span>
            <button class="modal-close" data-modal-close>×</button>
        </div>
        <form method="POST" action="" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action"  value="submit">
            <input type="hidden" name="asn_id"  id="submitAsnId">
            <div class="modal-body">
                <div style="background:var(--light);border-radius:8px;padding:12px 14px;margin-bottom:16px">
                    <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px">Submitting:</div>
                    <div style="font-weight:800;font-size:15px" id="submitAsnTitle">—</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Upload File <small class="text-muted">(PDF, Word, Image)</small></label>
                    <input type="file" name="submit_file" class="form-control"
                           accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                    <div class="form-hint">Max 5MB. You can also just leave a comment without a file.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Comment <small class="text-muted">(optional)</small></label>
                    <textarea name="comment" class="form-control" rows="3"
                              placeholder="Add a note for your teacher…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">📤 Submit</button>
            </div>
        </form>
    </div>
</div>

<script>
function openSubmitModal(id, title) {
    document.getElementById('submitAsnId').value  = id;
    document.getElementById('submitAsnTitle').textContent = title;
    openModal('submitModal');
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
