<?php
// ============================================================
// admin/teachers_add.php — Add New Teacher
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_ADMIN);

$pageTitle  = 'Add New Teacher';
$activeMenu = 'teachers';

$errors = [];
$form   = [
    'teacher_code'  => '',
    'full_name'     => '',
    'email'         => '',
    'phone'         => '',
    'qualification' => '',
    'address'       => '',
    'joined_date'   => date('Y-m-d'),
    'status'        => 'active',
    'password'      => '',
    'subjects'      => [],   // array of subject_id => [class_ids]
];

// Auto-generate teacher code
$lastTeacher = Database::fetchOne("SELECT teacher_code FROM teachers ORDER BY id DESC LIMIT 1");
if ($lastTeacher) {
    $num = (int)substr($lastTeacher['teacher_code'], 3) + 1;
    $form['teacher_code'] = 'TCH' . str_pad($num, 3, '0', STR_PAD_LEFT);
} else {
    $form['teacher_code'] = 'TCH001';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();

    $fields = ['teacher_code','full_name','email','phone','qualification','address','joined_date','status','password'];
    foreach ($fields as $k) $form[$k] = sanitize($_POST[$k] ?? '');
    $selectedSubjects = array_map('intval', $_POST['subject_ids'] ?? []);
    $selectedClasses  = $_POST['class_ids'] ?? [];  // keyed by subject_id

    // Validate
    if (empty($form['teacher_code']))  $errors['teacher_code'] = 'Teacher code is required.';
    if (empty($form['full_name']))     $errors['full_name']    = 'Full name is required.';
    if (empty($form['password']))      $errors['password']     = 'Password is required.';
    elseif (strlen($form['password']) < 6) $errors['password'] = 'Password must be at least 6 characters.';
    if (!empty($form['email']) && !valid_email($form['email'])) $errors['email'] = 'Invalid email.';

    // Check uniqueness
    if (Database::fetchOne("SELECT id FROM users WHERE username = ?", [$form['teacher_code']])) {
        $errors['teacher_code'] = 'Username already exists.';
    }
    if (Database::fetchOne("SELECT id FROM teachers WHERE teacher_code = ?", [$form['teacher_code']])) {
        $errors['teacher_code'] = 'Teacher code already exists.';
    }

    if (empty($errors)) {
        Database::beginTransaction();
        try {
            // Create user account
            $userId = Database::insert(
                "INSERT INTO users (username, password, role_id, full_name, email, phone, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $form['teacher_code'],
                    hash_password($form['password']),
                    ROLE_TEACHER,
                    $form['full_name'],
                    $form['email'] ?: null,
                    $form['phone'] ?: null,
                    $form['status'],
                ]
            );

            // Create teacher record
            $teacherId = Database::insert(
                "INSERT INTO teachers (user_id, teacher_code, qualification, address, joined_date, status)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $userId,
                    $form['teacher_code'],
                    $form['qualification'] ?: null,
                    $form['address'] ?: null,
                    $form['joined_date'] ?: null,
                    $form['status'],
                ]
            );

            // Assign subjects to classes
            foreach ($selectedSubjects as $subjectId) {
                $classIds = array_map('intval', $selectedClasses[$subjectId] ?? []);
                foreach ($classIds as $classId) {
                    if ($classId > 0) {
                        Database::execute(
                            "INSERT IGNORE INTO teacher_subjects (teacher_id, subject_id, class_id)
                             VALUES (?, ?, ?)",
                            [$teacherId, $subjectId, $classId]
                        );
                    }
                }
            }

            Database::commit();
            audit_log(current_user_id(), current_username(), 'create_teacher', 'Teachers',
                "Created teacher {$form['teacher_code']} — {$form['full_name']}");
            flash_success("Teacher <strong>{$form['full_name']}</strong> added successfully!");
            redirect(BASE_URL . '/admin/teachers.php');

        } catch (Exception $e) {
            Database::rollback();
            error_log('[Add Teacher] ' . $e->getMessage());
            $errors['_global'] = 'Failed to save teacher. Please try again.';
        }
    }
}

$subjects = Database::fetchAll("SELECT * FROM subjects ORDER BY name");
$classes  = Database::fetchAll("SELECT * FROM classes ORDER BY sort_order");

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">+ Add New Teacher</h1>
        <p class="page-subtitle">Create teacher account and assign subjects/classes.</p>
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

        <!-- Left: Profile info -->
        <div style="display:flex;flex-direction:column;gap:20px">
            <div class="card">
                <div class="card-header">📋 Teacher Information</div>
                <div class="card-body">
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Teacher Code <span class="req">*</span></label>
                            <input type="text" name="teacher_code"
                                   class="form-control <?= isset($errors['teacher_code'])?'is-invalid':'' ?>"
                                   value="<?= e($form['teacher_code']) ?>" required>
                            <?php if (isset($errors['teacher_code'])): ?>
                                <div class="form-error"><?= e($errors['teacher_code']) ?></div>
                            <?php endif; ?>
                            <div class="form-hint">This will also be the login username.</div>
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
                        <label class="form-label">Full Name <span class="req">*</span></label>
                        <input type="text" name="full_name"
                               class="form-control <?= isset($errors['full_name'])?'is-invalid':'' ?>"
                               value="<?= e($form['full_name']) ?>" required
                               placeholder="e.g. Mr. John Smith">
                        <?php if (isset($errors['full_name'])): ?>
                            <div class="form-error"><?= e($errors['full_name']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email"
                                   class="form-control <?= isset($errors['email'])?'is-invalid':'' ?>"
                                   value="<?= e($form['email']) ?>"
                                   placeholder="teacher@school.edu">
                            <?php if (isset($errors['email'])): ?>
                                <div class="form-error"><?= e($errors['email']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" class="form-control"
                                   value="<?= e($form['phone']) ?>" placeholder="e.g. 2207100001">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Qualification</label>
                        <input type="text" name="qualification" class="form-control"
                               value="<?= e($form['qualification']) ?>"
                               placeholder="e.g. B.Sc Mathematics, PGDE">
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Date Joined</label>
                            <input type="date" name="joined_date" class="form-control"
                                   value="<?= e($form['joined_date']) ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="2"
                                  placeholder="Residential address"><?= e($form['address']) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Login credentials -->
            <div class="card">
                <div class="card-header">🔐 Login Credentials</div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="Same as Teacher Code" disabled>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password <span class="req">*</span></label>
                        <div class="input-icon-wrap">
                            <input type="password" name="password" id="teacherPass"
                                   class="form-control <?= isset($errors['password'])?'is-invalid':'' ?>"
                                   placeholder="Min. 6 characters" required>
                            <button type="button" class="toggle-password"
                                    onclick="document.getElementById('teacherPass').type==='password'?document.getElementById('teacherPass').type='text':document.getElementById('teacherPass').type='password'">👁️</button>
                        </div>
                        <?php if (isset($errors['password'])): ?>
                            <div class="form-error"><?= e($errors['password']) ?></div>
                        <?php endif; ?>
                        <div class="form-hint">Admin can reset this anytime from Settings → Users.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Subject/Class assignment -->
        <div style="display:flex;flex-direction:column;gap:20px">
            <div class="card">
                <div class="card-header">📘 Subject & Class Assignment</div>
                <div class="card-body">
                    <div class="form-hint mb-16">
                        Select subjects this teacher will teach, then choose which class(es) for each subject.
                    </div>

                    <?php foreach ($subjects as $sub): ?>
                    <div class="subject-assign-row" style="
                        border:1px solid var(--border);border-radius:10px;
                        padding:12px 14px;margin-bottom:10px;
                        transition:border-color .2s,background .2s" id="subrow_<?= $sub['id'] ?>">

                        <!-- Subject toggle -->
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:0">
                            <input type="checkbox" name="subject_ids[]"
                                   value="<?= $sub['id'] ?>"
                                   class="subject-check"
                                   data-subject="<?= $sub['id'] ?>"
                                   onchange="toggleSubjectClasses(<?= $sub['id'] ?>)"
                                   <?= in_array($sub['id'], (array)($form['subjects'])) ? 'checked' : '' ?>>
                            <span style="font-weight:700;font-size:14px">
                                <?php if ($sub['code']): ?><span class="code"><?= e($sub['code']) ?></span><?php endif; ?>
                                <?= e($sub['name']) ?>
                            </span>
                        </label>

                        <!-- Class checkboxes (hidden until subject checked) -->
                        <div id="classes_<?= $sub['id'] ?>" style="display:none;margin-top:10px;padding-top:10px;border-top:1px solid var(--border)">
                            <div style="font-size:12px;font-weight:700;color:var(--text-muted);margin-bottom:8px">
                                Assign to which classes?
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:8px">
                                <?php foreach ($classes as $cls): ?>
                                <label style="display:flex;align-items:center;gap:5px;cursor:pointer;
                                              background:var(--light);border-radius:6px;padding:4px 10px;
                                              border:1px solid var(--border);font-size:13px">
                                    <input type="checkbox"
                                           name="class_ids[<?= $sub['id'] ?>][]"
                                           value="<?= $cls['id'] ?>">
                                    <?= e($cls['name']) ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Actions -->
            <div class="card">
                <div class="card-body">
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <button type="submit" class="btn btn-primary btn-lg">💾 Save Teacher</button>
                        <a href="<?= BASE_URL ?>/admin/teachers.php" class="btn btn-outline btn-lg">Cancel</a>
                    </div>
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
        // Uncheck all class checkboxes
        classDiv.querySelectorAll('input[type=checkbox]').forEach(cb => cb.checked = false);
    }
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
