<?php
// ============================================================
// admin/students_edit.php — Edit Student
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_ADMIN);

$pageTitle  = 'Edit Student';
$activeMenu = 'students';

$id = int_val($_GET['id'] ?? 0);
if (!$id) {
    flash_error('Invalid student ID.');
    redirect(BASE_URL . '/admin/students.php');
}

$student = Database::fetchOne(
    "SELECT s.*, c.name AS class_name
     FROM students s JOIN classes c ON c.id = s.class_id
     WHERE s.id = ?",
    [$id]
);

if (!$student) {
    flash_error('Student not found.');
    redirect(BASE_URL . '/admin/students.php');
}

$errors = [];
$form   = $student; // Pre-populate from DB

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();

    $fields = ['full_name','gender','dob','class_id','parent_name',
               'parent_phone1','parent_phone2','parent_email',
               'address','blood_group','medical_notes','status','enrolled_date'];
    foreach ($fields as $k) {
        $form[$k] = sanitize($_POST[$k] ?? '');
    }

    // Validation
    if (empty($form['full_name']))    $errors['full_name']     = 'Full name is required.';
    if (empty($form['gender']))       $errors['gender']        = 'Gender is required.';
    if (empty($form['class_id']))     $errors['class_id']      = 'Class is required.';
    if (empty($form['parent_phone1']))$errors['parent_phone1'] = 'Parent Phone 1 is required.';
    if (empty($form['parent_phone2']))$errors['parent_phone2'] = 'Parent Phone 2 is required.';
    if (!empty($form['parent_email']) && !valid_email($form['parent_email'])) {
        $errors['parent_email'] = 'Invalid email address.';
    }

    if (empty($errors)) {
        Database::execute(
            "UPDATE students SET
                full_name=?, gender=?, dob=?, class_id=?, parent_name=?,
                parent_phone1=?, parent_phone2=?, parent_email=?,
                address=?, blood_group=?, medical_notes=?, status=?, enrolled_date=?,
                result_access_override=?
             WHERE id=?",
            [
                $form['full_name'], $form['gender'],
                $form['dob'] ?: null, (int)$form['class_id'],
                $form['parent_name'], $form['parent_phone1'], $form['parent_phone2'],
                $form['parent_email'] ?: null, $form['address'],
                $form['blood_group'], $form['medical_notes'],
                $form['status'], $form['enrolled_date'] ?: null,
                $resultOverride,
                $id
            ]
        );

        // Update user full_name if linked
        if ($student['user_id']) {
            Database::execute("UPDATE users SET full_name=? WHERE id=?",
                [$form['full_name'], $student['user_id']]);
        }

        audit_log(current_user_id(), current_username(), 'update_student', 'Students',
            "Updated student {$student['student_id']} — {$form['full_name']}");

        flash_success("Student <strong>{$form['full_name']}</strong> updated successfully.");
        redirect(BASE_URL . '/admin/students.php');
    }
}

$classes     = Database::fetchAll("SELECT id, name FROM classes ORDER BY sort_order");
$bloodGroups = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">✏️ Edit Student</h1>
        <p class="page-subtitle"><?= e($student['full_name']) ?> &mdash; <?= e($student['student_id']) ?></p>
    </div>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>/admin/students_view.php?id=<?= $id ?>" class="btn btn-outline">👁️ View Profile</a>
        <a href="<?= BASE_URL ?>/admin/students.php" class="btn btn-outline">← Back</a>
    </div>
</div>

<?php if (!empty($errors['_global'])): ?>
    <div class="alert alert-error"><?= $errors['_global'] ?></div>
<?php endif; ?>

<form method="POST" action="" data-validate>
    <?= csrf_field() ?>
    <div class="grid-2" style="gap:20px">

        <div style="display:flex;flex-direction:column;gap:20px">
            <div class="card">
                <div class="card-header">📋 Basic Information</div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Student ID</label>
                        <input type="text" class="form-control" value="<?= e($form['student_id']) ?>" disabled>
                        <div class="form-hint">Student ID cannot be changed.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Full Name <span class="req">*</span></label>
                        <input type="text" name="full_name" class="form-control <?= isset($errors['full_name']) ? 'is-invalid':'' ?>"
                               value="<?= e($form['full_name']) ?>" required>
                        <?php if (isset($errors['full_name'])): ?><div class="form-error"><?= e($errors['full_name']) ?></div><?php endif; ?>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Gender <span class="req">*</span></label>
                            <select name="gender" class="form-control" required>
                                <?php foreach (['Male','Female'] as $g): ?>
                                    <option value="<?= $g ?>" <?= $form['gender']===$g?'selected':'' ?>><?= $g ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Class <span class="req">*</span></label>
                            <select name="class_id" class="form-control" required>
                                <?php foreach ($classes as $cls): ?>
                                    <option value="<?= $cls['id'] ?>" <?= $form['class_id']==$cls['id']?'selected':'' ?>><?= e($cls['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="dob" class="form-control" value="<?= e($form['dob']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Enrolled Date</label>
                            <input type="date" name="enrolled_date" class="form-control" value="<?= e($form['enrolled_date']) ?>">
                        </div>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Blood Group</label>
                            <select name="blood_group" class="form-control">
                                <option value="">Select…</option>
                                <?php foreach ($bloodGroups as $bg): ?>
                                    <option value="<?= $bg ?>" <?= $form['blood_group']===$bg?'selected':'' ?>><?= $bg ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-control">
                                <?php foreach (['active','inactive','graduated','transferred'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $form['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="2"><?= e($form['address']) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Medical Notes</label>
                        <textarea name="medical_notes" class="form-control" rows="2"><?= e($form['medical_notes']) ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div style="display:flex;flex-direction:column;gap:20px">
            <div class="card">
                <div class="card-header">👨‍👩‍👧 Parent / Guardian</div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Parent / Guardian Name</label>
                        <input type="text" name="parent_name" class="form-control" value="<?= e($form['parent_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">📱 Parent Phone 1 <span class="req">*</span></label>
                        <input type="tel" name="parent_phone1"
                               class="form-control <?= isset($errors['parent_phone1'])?'is-invalid':'' ?>"
                               value="<?= e($form['parent_phone1']) ?>" required>
                        <?php if (isset($errors['parent_phone1'])): ?><div class="form-error"><?= e($errors['parent_phone1']) ?></div><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label">📱 Parent Phone 2 <span class="req">*</span></label>
                        <input type="tel" name="parent_phone2"
                               class="form-control <?= isset($errors['parent_phone2'])?'is-invalid':'' ?>"
                               value="<?= e($form['parent_phone2']) ?>" required>
                        <?php if (isset($errors['parent_phone2'])): ?><div class="form-error"><?= e($errors['parent_phone2']) ?></div><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Parent Email</label>
                        <input type="email" name="parent_email"
                               class="form-control <?= isset($errors['parent_email'])?'is-invalid':'' ?>"
                               value="<?= e($form['parent_email']) ?>">
                        <?php if (isset($errors['parent_email'])): ?><div class="form-error"><?= e($errors['parent_email']) ?></div><?php endif; ?>
                    </div>

                    <!-- Quick WhatsApp links -->
                    <div style="background:var(--light);border-radius:8px;padding:12px;margin-top:8px">
                        <div class="text-sm fw-700 mb-8">Quick WhatsApp:</div>
                        <div style="display:flex;gap:8px">
                            <a href="<?= e(wa_link($form['parent_phone1'], 'Hello from ' . get_setting('school_name'))) ?>"
                               target="_blank" class="btn btn-sm btn-whatsapp">📲 Phone 1</a>
                            <a href="<?= e(wa_link($form['parent_phone2'], 'Hello from ' . get_setting('school_name'))) ?>"
                               target="_blank" class="btn btn-sm btn-wa-dark">📲 Phone 2</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Result Access Override -->
            <div class="card" style="border-left:4px solid var(--accent)">
                <div class="card-header">
                    🔒 Result Access Control
                </div>
                <div class="card-body">
                    <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer">
                        <input type="checkbox" name="result_access_override" value="1"
                               <?= !empty($student['result_access_override']) ? 'checked' : '' ?>
                               style="width:18px;height:18px;margin-top:2px;flex-shrink:0;cursor:pointer">
                        <div>
                            <div style="font-weight:700;font-size:14px;color:var(--navy)">
                                Allow result access despite outstanding fees
                            </div>
                            <div class="text-sm text-muted" style="margin-top:4px;line-height:1.6">
                                When ticked, this student can view their results and report card
                                even if school fees have not been fully paid. Use this for students
                                on approved payment plans or special arrangements.
                            </div>
                            <?php if (!empty($student['result_access_override'])): ?>
                                <div style="margin-top:8px;background:#FEF3C7;border:1px solid #FDE68A;
                                            border-radius:6px;padding:6px 10px;font-size:12px;
                                            font-weight:700;color:#92400E">
                                    ⚠️ Override is currently ACTIVE — this student can see their results regardless of fees.
                                </div>
                            <?php endif; ?>
                        </div>
                    </label>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <button type="submit" class="btn btn-primary btn-lg">💾 Save Changes</button>
                        <a href="<?= BASE_URL ?>/admin/students.php" class="btn btn-outline btn-lg">Cancel</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<?php include INCLUDES_PATH . '/footer.php'; ?>
