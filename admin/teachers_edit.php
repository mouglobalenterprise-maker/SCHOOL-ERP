<?php
// ============================================================
// admin/teachers_edit.php — Edit Teacher
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_ADMIN);

$pageTitle  = 'Edit Teacher';
$activeMenu = 'teachers';

$id = int_val($_GET['id'] ?? 0);
if (!$id) { flash_error('Invalid teacher.'); redirect(BASE_URL . '/admin/teachers.php'); }

$teacher = Database::fetchOne(
    "SELECT t.*, u.full_name, u.email, u.phone, u.status, u.username, u.id AS user_id
     FROM teachers t JOIN users u ON u.id = t.user_id WHERE t.id = ?",
    [$id]
);
if (!$teacher) { flash_error('Teacher not found.'); redirect(BASE_URL . '/admin/teachers.php'); }

// Existing subject/class assignments
$existingAssignments = Database::fetchAll(
    "SELECT subject_id, class_id FROM teacher_subjects WHERE teacher_id = ?", [$id]
);
$assignedSubjectIds = array_unique(array_column($existingAssignments, 'subject_id'));
$assignedClassMap   = [];
foreach ($existingAssignments as $a) {
    $assignedClassMap[$a['subject_id']][] = $a['class_id'];
}

$errors = [];
$form   = array_merge($teacher, ['password' => '']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();

    $fields = ['full_name','email','phone','qualification','address','joined_date','status'];
    foreach ($fields as $k) $form[$k] = sanitize($_POST[$k] ?? '');
    $form['password']    = $_POST['password'] ?? '';
    $selectedSubjects    = array_map('intval', $_POST['subject_ids'] ?? []);
    $selectedClasses     = $_POST['class_ids'] ?? [];

    // Validate
    if (empty($form['full_name'])) $errors['full_name'] = 'Full name is required.';
    if (!empty($form['email']) && !valid_email($form['email'])) $errors['email'] = 'Invalid email.';
    if (!empty($form['password']) && strlen($form['password']) < 6) {
        $errors['password'] = 'Password must be at least 6 characters.';
    }

    if (empty($errors)) {
        Database::beginTransaction();
        try {
            // Update user record
            $userFields = "full_name=?, email=?, phone=?, status=?";
            $userParams = [$form['full_name'], $form['email'] ?: null, $form['phone'] ?: null, $form['status']];

            if (!empty($form['password'])) {
                $userFields  .= ', password=?';
                $userParams[] = hash_password($form['password']);
                audit_log(current_user_id(), current_username(), 'password_reset', 'Teachers',
                    "Password changed for teacher ID {$id}");
            }
            $userParams[] = $teacher['user_id'];
            Database::execute("UPDATE users SET {$userFields} WHERE id=?", $userParams);

            // Update teacher record
            Database::execute(
                "UPDATE teachers SET qualification=?, address=?, joined_date=?, status=? WHERE id=?",
                [
                    $form['qualification'] ?: null,
                    $form['address'] ?: null,
                    $form['joined_date'] ?: null,
                    $form['status'],
                    $id,
                ]
            );

            // Rebuild subject/class assignments
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

            Database::commit();
            audit_log(current_user_id(), current_username(), 'update_teacher', 'Teachers',
                "Updated teacher {$teacher['teacher_code']} — {$form['full_name']}");
            flash_success("Teacher <strong>{$form['full_name']}</strong> updated successfully.");
            redirect(BASE_URL . '/admin/teachers.php');

        } catch (Exception $e) {
            Database::rollback();
            error_log('[Edit Teacher] ' . $e->getMessage());
            $errors['_global'] = 'Update failed. Please try again.';
        }
    }

    // Re-render with posted values
    $assignedSubjectIds = $selectedSubjects;
    $assignedClassMap   = [];
    foreach ($selectedSubjects as $sid) {
        $assignedClassMap[$sid] = array_map('intval', $selectedClasses[$sid] ?? []);
    }
}

$subjects = Database::fetchAll("SELECT * FROM subjects ORDER BY name");
$classes  = Database::fetchAll("SELECT * FROM classes ORDER BY sort_order");

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">✏️ Edit Teacher</h1>
        <p class="page-subtitle"><?= e($teacher['full_name']) ?> — <?= e($teacher['teacher_code']) ?></p>
    </div>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>/admin/teachers.php" class="btn btn-outline">← Back</a>
    </div>
</div>

<?php if (!empty($errors['_global'])): ?>
    <div class="alert alert-error"><?= e($errors['_global']) ?></div>
<?php endif; ?>

<form method="POST" action="" data-validate>
    <?= csrf_field() ?>
    <div class="grid-2" style="gap:20px">

        <!-- Left -->
        <div style="display:flex;flex-direction:column;gap:20px">
            <div class="card">
                <div class="card-header">📋 Teacher Information</div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Teacher Code / Username</label>
                        <input type="text" class="form-control" value="<?= e($teacher['teacher_code']) ?>" disabled>
                        <div class="form-hint">Teacher code cannot be changed.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Full Name <span class="req">*</span></label>
                        <input type="text" name="full_name"
                               class="form-control <?= isset($errors['full_name'])?'is-invalid':'' ?>"
                               value="<?= e($form['full_name']) ?>" required>
                        <?php if (isset($errors['full_name'])): ?><div class="form-error"><?= e($errors['full_name']) ?></div><?php endif; ?>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email"
                                   class="form-control <?= isset($errors['email'])?'is-invalid':'' ?>"
                                   value="<?= e($form['email']) ?>">
                            <?php if (isset($errors['email'])): ?><div class="form-error"><?= e($errors['email']) ?></div><?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" class="form-control" value="<?= e($form['phone']) ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Qualification</label>
                        <input type="text" name="qualification" class="form-control"
                               value="<?= e($form['qualification']) ?>">
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Date Joined</label>
                            <input type="date" name="joined_date" class="form-control"
                                   value="<?= e($form['joined_date']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-control">
                                <?php foreach (['active','inactive'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $form['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="2"><?= e($form['address']) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Password reset -->
            <div class="card">
                <div class="card-header">🔐 Reset Password</div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">New Password <small class="text-muted">(leave blank to keep current)</small></label>
                        <div class="input-icon-wrap">
                            <input type="password" name="password" id="newPass"
                                   class="form-control <?= isset($errors['password'])?'is-invalid':'' ?>"
                                   placeholder="Enter new password to change">
                            <button type="button" class="toggle-password"
                                    onclick="var p=document.getElementById('newPass');p.type=p.type==='password'?'text':'password'">👁️</button>
                        </div>
                        <?php if (isset($errors['password'])): ?><div class="form-error"><?= e($errors['password']) ?></div><?php endif; ?>
                        <div class="form-hint">Minimum 6 characters. Only fill if you want to change the password.</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <button type="submit" class="btn btn-primary btn-lg">💾 Save Changes</button>
                        <a href="<?= BASE_URL ?>/admin/teachers.php" class="btn btn-outline btn-lg">Cancel</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Subject assignments -->
        <div>
            <div class="card">
                <div class="card-header">📘 Subject & Class Assignments</div>
                <div class="card-body">
                    <div class="form-hint mb-16">
                        Check a subject to expand its class options. Uncheck to remove all assignments for that subject.
                    </div>
                    <?php foreach ($subjects as $sub):
                        $isChecked = in_array($sub['id'], $assignedSubjectIds);
                        $checkedClasses = $assignedClassMap[$sub['id']] ?? [];
                    ?>
                    <div class="subject-assign-row" style="
                        border:1px solid <?= $isChecked ? 'var(--navy)' : 'var(--border)' ?>;
                        background:<?= $isChecked ? 'rgba(11,29,58,.03)' : '' ?>;
                        border-radius:10px;padding:12px 14px;margin-bottom:10px"
                        id="subrow_<?= $sub['id'] ?>">

                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:0">
                            <input type="checkbox" name="subject_ids[]"
                                   value="<?= $sub['id'] ?>"
                                   class="subject-check"
                                   data-subject="<?= $sub['id'] ?>"
                                   onchange="toggleSubjectClasses(<?= $sub['id'] ?>)"
                                   <?= $isChecked ? 'checked' : '' ?>>
                            <span style="font-weight:700;font-size:14px">
                                <?php if ($sub['code']): ?><span class="code"><?= e($sub['code']) ?></span><?php endif; ?>
                                <?= e($sub['name']) ?>
                            </span>
                        </label>

                        <div id="classes_<?= $sub['id'] ?>" style="<?= $isChecked ? '' : 'display:none' ?>;margin-top:10px;padding-top:10px;border-top:1px solid var(--border)">
                            <div style="font-size:12px;font-weight:700;color:var(--text-muted);margin-bottom:8px">Classes:</div>
                            <div style="display:flex;flex-wrap:wrap;gap:8px">
                                <?php foreach ($classes as $cls): ?>
                                <label style="display:flex;align-items:center;gap:5px;cursor:pointer;
                                              background:var(--light);border-radius:6px;padding:4px 10px;
                                              border:1px solid var(--border);font-size:13px">
                                    <input type="checkbox"
                                           name="class_ids[<?= $sub['id'] ?>][]"
                                           value="<?= $cls['id'] ?>"
                                           <?= in_array($cls['id'], $checkedClasses) ? 'checked' : '' ?>>
                                    <?= e($cls['name']) ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div>
</form>

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
        classDiv.querySelectorAll('input[type=checkbox]').forEach(cb => cb.checked = false);
    }
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
