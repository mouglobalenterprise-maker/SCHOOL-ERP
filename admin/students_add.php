<?php
// ============================================================
// admin/students_add.php — Add New Student
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_ADMIN);

$pageTitle  = 'Add New Student';
$activeMenu = 'students';

$errors = [];
$form   = [
    'student_id'   => generate_student_id(),
    'full_name'    => '',
    'gender'       => '',
    'dob'          => '',
    'class_id'     => '',
    'parent_name'  => '',
    'parent_phone1'=> '',
    'parent_phone2'=> '',
    'parent_email' => '',
    'address'      => '',
    'blood_group'  => '',
    'medical_notes'=> '',
    'status'       => 'active',
    'enrolled_date'=> date('Y-m-d'),
    'create_login' => '1',
    'login_pass'   => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();

    // Collect & sanitize
    foreach (array_keys($form) as $k) {
        $form[$k] = sanitize($_POST[$k] ?? '');
    }
    $form['create_login'] = isset($_POST['create_login']) ? '1' : '0';

    // Validate required fields
    if (empty($form['full_name']))    $errors['full_name']     = 'Full name is required.';
    if (empty($form['gender']))       $errors['gender']        = 'Gender is required.';
    if (empty($form['class_id']))     $errors['class_id']      = 'Class is required.';
    if (empty($form['parent_phone1']))$errors['parent_phone1'] = 'Parent Phone 1 is required.';
    if (empty($form['parent_phone2']))$errors['parent_phone2'] = 'Parent Phone 2 is required.';

    if (!empty($form['parent_email']) && !valid_email($form['parent_email'])) {
        $errors['parent_email'] = 'Invalid email address.';
    }

    // Check duplicate student ID
    $existing = Database::fetchOne("SELECT id FROM students WHERE student_id = ?", [$form['student_id']]);
    if ($existing) $errors['student_id'] = 'Student ID already exists.';

    // Login account validation
    if ($form['create_login'] === '1') {
        if (empty($form['login_pass'])) {
            $errors['login_pass'] = 'Password is required when creating a login account.';
        } elseif (strlen($form['login_pass']) < 6) {
            $errors['login_pass'] = 'Password must be at least 6 characters.';
        }
        // Check username availability
        $existingUser = Database::fetchOne("SELECT id FROM users WHERE username = ?", [$form['student_id']]);
        if ($existingUser) {
            $errors['student_id'] = 'A user with this Student ID already exists.';
        }
    }

    if (empty($errors)) {
        Database::beginTransaction();
        try {
            $userId    = null;
            $sess_id   = current_session_id();

            // Create login account if requested
            if ($form['create_login'] === '1') {
                $userId = Database::insert(
                    "INSERT INTO users (username, password, role_id, full_name, status)
                     VALUES (?, ?, ?, ?, 'active')",
                    [$form['student_id'], hash_password($form['login_pass']),
                     ROLE_STUDENT, $form['full_name']]
                );
            }

            // Insert student
            $studentDbId = Database::insert(
                "INSERT INTO students
                    (user_id, student_id, full_name, gender, dob, class_id, session_id,
                     parent_name, parent_phone1, parent_phone2, parent_email,
                     address, blood_group, medical_notes, status, enrolled_date)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [
                    $userId,
                    $form['student_id'],
                    $form['full_name'],
                    $form['gender'],
                    $form['dob']          ?: null,
                    (int)$form['class_id'],
                    $sess_id,
                    $form['parent_name'],
                    $form['parent_phone1'],
                    $form['parent_phone2'],
                    $form['parent_email'] ?: null,
                    $form['address'],
                    $form['blood_group'],
                    $form['medical_notes'],
                    $form['status'],
                    $form['enrolled_date'] ?: date('Y-m-d'),
                ]
            );

            // Create payment record for current term
            $term_id = current_term_id();
            if ($term_id) {
                $payCode = generate_payment_code();
                Database::insert(
                    "INSERT INTO payments
                        (payment_code, student_id, term_id, session_id, fee_type, amount_due, status, recorded_by)
                     VALUES (?, ?, ?, ?, 'School Fees', 0, 'unpaid', ?)",
                    [$payCode, $studentDbId, $term_id, $sess_id, current_user_id()]
                );
            }

            Database::commit();
            audit_log(current_user_id(), current_username(), 'create_student', 'Students',
                "Created student {$form['student_id']} — {$form['full_name']}");

            flash_success("Student <strong>{$form['full_name']}</strong> ({$form['student_id']}) added successfully!");
            redirect(BASE_URL . '/admin/students.php');

        } catch (Exception $e) {
            Database::rollback();
            error_log('[Add Student] ' . $e->getMessage());
            $errors['_global'] = 'Failed to save student. Please try again.';
        }
    }
}

$classes     = Database::fetchAll("SELECT id, name FROM classes ORDER BY sort_order");
$bloodGroups = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">+ Add New Student</h1>
        <p class="page-subtitle">Fill in all required fields. Parent phones are mandatory.</p>
    </div>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>/admin/students.php" class="btn btn-outline">← Back to Students</a>
    </div>
</div>

<?php if (!empty($errors['_global'])): ?>
    <div class="alert alert-error"><?= $errors['_global'] ?></div>
<?php endif; ?>

<form method="POST" action="" data-validate enctype="multipart/form-data">
    <?= csrf_field() ?>

    <div class="grid-2" style="gap:20px">

        <!-- ── Left column ── -->
        <div style="display:flex;flex-direction:column;gap:20px">

            <!-- Basic Info -->
            <div class="card">
                <div class="card-header">📋 Basic Information</div>
                <div class="card-body">
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Student ID <span class="req">*</span></label>
                            <input type="text" name="student_id" class="form-control <?= isset($errors['student_id']) ? 'is-invalid' : '' ?>"
                                   value="<?= e($form['student_id']) ?>" required>
                            <?php if (isset($errors['student_id'])): ?>
                                <div class="form-error"><?= e($errors['student_id']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-control">
                                <?php foreach (['active','inactive'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $form['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Full Name <span class="req">*</span></label>
                        <input type="text" name="full_name" class="form-control <?= isset($errors['full_name']) ? 'is-invalid' : '' ?>"
                               value="<?= e($form['full_name']) ?>" required placeholder="e.g. John Doe">
                        <?php if (isset($errors['full_name'])): ?>
                            <div class="form-error"><?= e($errors['full_name']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Gender <span class="req">*</span></label>
                            <select name="gender" class="form-control <?= isset($errors['gender']) ? 'is-invalid' : '' ?>" required>
                                <option value="">Select gender…</option>
                                <option value="Male"   <?= $form['gender'] === 'Male'   ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= $form['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                            </select>
                            <?php if (isset($errors['gender'])): ?>
                                <div class="form-error"><?= e($errors['gender']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Class <span class="req">*</span></label>
                            <select name="class_id" class="form-control <?= isset($errors['class_id']) ? 'is-invalid' : '' ?>" required>
                                <option value="">Select class…</option>
                                <?php foreach ($classes as $cls): ?>
                                    <option value="<?= $cls['id'] ?>" <?= $form['class_id'] == $cls['id'] ? 'selected' : '' ?>>
                                        <?= e($cls['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['class_id'])): ?>
                                <div class="form-error"><?= e($errors['class_id']) ?></div>
                            <?php endif; ?>
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
                                    <option value="<?= $bg ?>" <?= $form['blood_group'] === $bg ? 'selected' : '' ?>><?= $bg ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="2" placeholder="Home address"><?= e($form['address']) ?></textarea>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Medical Notes</label>
                        <textarea name="medical_notes" class="form-control" rows="2" placeholder="Any allergies, conditions, etc."><?= e($form['medical_notes']) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Login Account -->
            <div class="card">
                <div class="card-header">🔐 Portal Login Account</div>
                <div class="card-body">
                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:700">
                            <input type="checkbox" name="create_login" id="createLogin"
                                   value="1" <?= $form['create_login'] === '1' ? 'checked' : '' ?>>
                            Create student portal login account
                        </label>
                        <div class="form-hint">Username will be the Student ID. Student/parent can log in to view results, attendance, fees.</div>
                    </div>
                    <div id="loginFields" style="<?= $form['create_login'] === '1' ? '' : 'display:none' ?>">
                        <div class="form-group">
                            <label class="form-label">Password <span class="req">*</span></label>
                            <input type="password" name="login_pass" class="form-control <?= isset($errors['login_pass']) ? 'is-invalid' : '' ?>"
                                   placeholder="Minimum 6 characters">
                            <?php if (isset($errors['login_pass'])): ?>
                                <div class="form-error"><?= e($errors['login_pass']) ?></div>
                            <?php endif; ?>
                            <div class="form-hint">Admin can reset this password later from Settings → Users.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Right column ── -->
        <div style="display:flex;flex-direction:column;gap:20px">

            <!-- Parent / Guardian Info -->
            <div class="card">
                <div class="card-header">👨‍👩‍👧 Parent / Guardian Information</div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Parent / Guardian Name</label>
                        <input type="text" name="parent_name" class="form-control"
                               value="<?= e($form['parent_name']) ?>" placeholder="e.g. Mr. John Doe Sr.">
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            📱 Parent Phone 1 <span class="req">*</span>
                            <small class="text-muted">(WhatsApp preferred)</small>
                        </label>
                        <input type="tel" name="parent_phone1"
                               class="form-control <?= isset($errors['parent_phone1']) ? 'is-invalid' : '' ?>"
                               value="<?= e($form['parent_phone1']) ?>"
                               placeholder="e.g. 2207000001" required>
                        <?php if (isset($errors['parent_phone1'])): ?>
                            <div class="form-error"><?= e($errors['parent_phone1']) ?></div>
                        <?php endif; ?>
                        <div class="form-hint">Used for WhatsApp notifications. Include country code (e.g. 220 for Gambia).</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            📱 Parent Phone 2 <span class="req">*</span>
                            <small class="text-muted">(Alternate)</small>
                        </label>
                        <input type="tel" name="parent_phone2"
                               class="form-control <?= isset($errors['parent_phone2']) ? 'is-invalid' : '' ?>"
                               value="<?= e($form['parent_phone2']) ?>"
                               placeholder="e.g. 2207000002" required>
                        <?php if (isset($errors['parent_phone2'])): ?>
                            <div class="form-error"><?= e($errors['parent_phone2']) ?></div>
                        <?php endif; ?>
                        <div class="form-hint">Mandatory backup contact number.</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Parent Email</label>
                        <input type="email" name="parent_email"
                               class="form-control <?= isset($errors['parent_email']) ? 'is-invalid' : '' ?>"
                               value="<?= e($form['parent_email']) ?>"
                               placeholder="parent@email.com">
                        <?php if (isset($errors['parent_email'])): ?>
                            <div class="form-error"><?= e($errors['parent_email']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="card">
                <div class="card-body">
                    <div style="display:flex;gap:10px;flex-wrap:wrap">
                        <button type="submit" class="btn btn-primary btn-lg">💾 Save Student</button>
                        <a href="<?= BASE_URL ?>/admin/students.php" class="btn btn-outline btn-lg">Cancel</a>
                    </div>
                    <div class="form-hint mt-8">
                        Fields marked <span class="req">*</span> are required.
                        Both parent phone numbers are <strong>mandatory</strong>.
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
document.getElementById('createLogin').addEventListener('change', function () {
    document.getElementById('loginFields').style.display = this.checked ? '' : 'none';
});
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
