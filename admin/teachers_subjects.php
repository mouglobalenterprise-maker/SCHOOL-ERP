<?php
// ============================================================
// admin/teachers_subjects.php — Manage Teacher Subject Assignments
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_ADMIN);

$pageTitle  = 'Teacher Subject Assignment';
$activeMenu = 'teachers';

$id = int_val($_GET['id'] ?? 0);
if (!$id) { flash_error('Invalid teacher.'); redirect(BASE_URL . '/admin/teachers.php'); }

$teacher = Database::fetchOne(
    "SELECT t.*, u.full_name, u.email FROM teachers t JOIN users u ON u.id=t.user_id WHERE t.id=?",
    [$id]
);
if (!$teacher) { flash_error('Teacher not found.'); redirect(BASE_URL . '/admin/teachers.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();

    $selectedSubjects = array_map('intval', $_POST['subject_ids'] ?? []);
    $selectedClasses  = $_POST['class_ids'] ?? [];

    Database::execute("DELETE FROM teacher_subjects WHERE teacher_id=?", [$id]);
    foreach ($selectedSubjects as $subjectId) {
        $classIds = array_map('intval', $selectedClasses[$subjectId] ?? []);
        foreach ($classIds as $classId) {
            if ($classId > 0) {
                Database::execute(
                    "INSERT IGNORE INTO teacher_subjects (teacher_id, subject_id, class_id) VALUES (?,?,?)",
                    [$id, $subjectId, $classId]
                );
            }
        }
    }

    audit_log(current_user_id(), current_username(), 'update_teacher_subjects', 'Teachers',
        "Updated subject assignments for teacher ID {$id}");
    flash_success("Subject assignments updated for <strong>{$teacher['full_name']}</strong>.");
    redirect(BASE_URL . '/admin/teachers.php');
}

$subjects    = Database::fetchAll("SELECT * FROM subjects ORDER BY name");
$classes     = Database::fetchAll("SELECT * FROM classes ORDER BY sort_order");
$assignments = Database::fetchAll("SELECT subject_id, class_id FROM teacher_subjects WHERE teacher_id=?", [$id]);
$assignedSubjectIds = array_unique(array_column($assignments, 'subject_id'));
$assignedClassMap   = [];
foreach ($assignments as $a) $assignedClassMap[$a['subject_id']][] = $a['class_id'];

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📘 Subject Assignment</h1>
        <p class="page-subtitle">
            Assign subjects and classes to <strong><?= e($teacher['full_name']) ?></strong>
            (<?= e($teacher['teacher_code']) ?>)
        </p>
    </div>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>/admin/teachers.php" class="btn btn-outline">← Back to Teachers</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        👨‍🏫 <?= e($teacher['full_name']) ?> — Subject & Class Assignments
    </div>
    <form method="POST" action="">
        <?= csrf_field() ?>
        <div class="card-body">
            <div class="form-hint mb-16">
                ✅ Check a subject to assign it. Then select which class(es) this teacher will teach that subject in.
            </div>

            <?php foreach ($subjects as $sub):
                $isChecked    = in_array($sub['id'], $assignedSubjectIds);
                $checkedClasses = $assignedClassMap[$sub['id']] ?? [];
            ?>
            <div class="subject-assign-row" id="subrow_<?= $sub['id'] ?>" style="
                border:1.5px solid <?= $isChecked?'var(--navy)':'var(--border)' ?>;
                background:<?= $isChecked?'rgba(11,29,58,.03)':'' ?>;
                border-radius:10px;padding:14px 16px;margin-bottom:10px;
                transition:border-color .2s,background .2s">

                <label style="display:flex;align-items:center;gap:12px;cursor:pointer;margin-bottom:0">
                    <input type="checkbox" name="subject_ids[]"
                           value="<?= $sub['id'] ?>"
                           data-subject="<?= $sub['id'] ?>"
                           onchange="toggleSubjectClasses(<?= $sub['id'] ?>)"
                           <?= $isChecked?'checked':'' ?>>
                    <div>
                        <?php if ($sub['code']): ?>
                            <span class="badge badge-navy" style="margin-right:6px"><?= e($sub['code']) ?></span>
                        <?php endif; ?>
                        <span style="font-weight:700;font-size:15px"><?= e($sub['name']) ?></span>
                    </div>
                </label>

                <div id="classes_<?= $sub['id'] ?>"
                     style="<?= $isChecked?'':'display:none' ?>;margin-top:12px;padding-top:12px;border-top:1px solid var(--border)">
                    <div style="font-size:12px;font-weight:700;color:var(--text-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em">
                        Teach in which class(es)?
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:8px">
                        <?php foreach ($classes as $cls): ?>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;
                                      padding:6px 14px;border-radius:8px;font-size:13px;font-weight:600;
                                      border:1.5px solid var(--border);
                                      background:<?= in_array($cls['id'],$checkedClasses)?'var(--navy)':'var(--light)' ?>;
                                      color:<?= in_array($cls['id'],$checkedClasses)?'var(--white)':'var(--text)' ?>;"
                               id="classLabel_<?= $sub['id'] ?>_<?= $cls['id'] ?>"
                               onclick="styleClassLabel(this)">
                            <input type="checkbox"
                                   name="class_ids[<?= $sub['id'] ?>][]"
                                   value="<?= $cls['id'] ?>"
                                   style="display:none"
                                   <?= in_array($cls['id'],$checkedClasses)?'checked':'' ?>>
                            <?= e($cls['name']) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="card-footer">
            <div style="display:flex;gap:10px">
                <button type="submit" class="btn btn-primary">💾 Save Assignments</button>
                <a href="<?= BASE_URL ?>/admin/teachers.php" class="btn btn-outline">Cancel</a>
            </div>
        </div>
    </form>
</div>

<script>
function toggleSubjectClasses(subjectId) {
    const check    = document.querySelector('[data-subject="' + subjectId + '"]');
    const classDiv = document.getElementById('classes_' + subjectId);
    const row      = document.getElementById('subrow_' + subjectId);
    if (check.checked) {
        classDiv.style.display = 'block';
        row.style.borderColor  = 'var(--navy)';
        row.style.background   = 'rgba(11,29,58,.03)';
    } else {
        classDiv.style.display = 'none';
        row.style.borderColor  = 'var(--border)';
        row.style.background   = '';
        classDiv.querySelectorAll('input[type=checkbox]').forEach(cb => {
            cb.checked = false;
            styleClassLabel(cb.closest('label'));
        });
    }
}

function styleClassLabel(label) {
    const cb = label.querySelector('input[type=checkbox]');
    if (!cb) return;
    cb.checked = !cb.checked;
    label.style.background = cb.checked ? 'var(--navy)' : 'var(--light)';
    label.style.color      = cb.checked ? 'var(--white)' : 'var(--text)';
    label.style.borderColor = cb.checked ? 'var(--navy)' : 'var(--border)';
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
