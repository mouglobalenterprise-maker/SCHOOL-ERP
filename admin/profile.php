<?php
// ============================================================
// admin/profile.php — User Profile Page
// Available to ALL roles — each user sees their own profile
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_login();

$pageTitle  = 'My Profile';
$activeMenu = '';

$userId = current_user_id();

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
    $action = sanitize($_POST['action'] ?? '');

    // Change password
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $user = Database::fetchOne("SELECT password FROM users WHERE id=?", [$userId]);

        if (!$user || !verify_password($current, $user['password'])) {
            flash_error('Current password is incorrect.');
        } elseif (strlen($new) < 6) {
            flash_error('New password must be at least 6 characters.');
        } elseif ($new !== $confirm) {
            flash_error('New passwords do not match.');
        } else {
            Database::execute(
                "UPDATE users SET password=? WHERE id=?",
                [hash_password($new), $userId]
            );
            audit_log($userId, current_username(), 'change_password', 'Auth',
                'User changed own password');
            flash_success('Password changed successfully.');
        }
    }

    // Update contact info
    if ($action === 'update_info') {
        $phone = sanitize($_POST['phone'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        if ($email && !valid_email($email)) {
            flash_error('Invalid email address.');
        } else {
            Database::execute(
                "UPDATE users SET phone=?, email=? WHERE id=?",
                [$phone ?: null, $email ?: null, $userId]
            );
            unset($_SESSION['_user_cache']); // clear cache
            flash_success('Contact information updated.');
        }
    }

    redirect(BASE_URL . '/admin/profile.php');
}

// ── Load user data ────────────────────────────────────────────
$user = Database::fetchOne(
    "SELECT u.*, r.name AS role_name
     FROM users u JOIN roles r ON r.id=u.role_id
     WHERE u.id=?",
    [$userId]
);

// Role-specific extra info
$extraInfo = null;
if (is_teacher()) {
    $extraInfo = Database::fetchOne(
        "SELECT t.*, COUNT(DISTINCT ts.subject_id) AS subject_count,
                COUNT(DISTINCT ts.class_id) AS class_count
         FROM teachers t
         LEFT JOIN teacher_subjects ts ON ts.teacher_id=t.id
         WHERE t.user_id=?
         GROUP BY t.id",
        [$userId]
    );
}
if (is_student()) {
    $extraInfo = Database::fetchOne(
        "SELECT s.*, c.name AS class_name, ses.name AS session_name
         FROM students s
         JOIN classes c ON c.id=s.class_id
         JOIN academic_sessions ses ON ses.id=s.session_id
         WHERE s.user_id=?",
        [$userId]
    );
}

// Recent activity from audit logs
$recentActivity = Database::fetchAll(
    "SELECT * FROM audit_logs WHERE user_id=? ORDER BY created_at DESC LIMIT 10",
    [$userId]
);

// Login count
$loginCount = (int)(Database::fetchOne(
    "SELECT COUNT(*) AS c FROM audit_logs WHERE user_id=? AND action='login'",
    [$userId]
)['c'] ?? 0);

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">👤 My Profile</h1>
        <p class="page-subtitle"><?= e($user['full_name']) ?> — <?= e(ucfirst($user['role_name'])) ?></p>
    </div>
</div>

<div class="grid-2" style="gap:20px;align-items:start">

    <!-- Left: Profile info -->
    <div style="display:flex;flex-direction:column;gap:20px">

        <!-- Profile card -->
        <div class="card">
            <div style="background:var(--navy);padding:24px;text-align:center">
                <div style="width:80px;height:80px;background:var(--accent);border-radius:50%;
                            display:flex;align-items:center;justify-content:center;
                            font-size:36px;font-weight:900;color:var(--navy);
                            margin:0 auto 12px">
                    <?= strtoupper(substr($user['full_name'],0,1)) ?>
                </div>
                <div style="color:#fff;font-size:20px;font-weight:800"><?= e($user['full_name']) ?></div>
                <div style="color:var(--accent);font-size:13px;margin-top:4px">
                    <?= e(ucfirst($user['role_name'])) ?>
                </div>
                <div style="color:rgba(255,255,255,.5);font-size:12px;margin-top:2px">
                    @<?= e($user['username']) ?>
                </div>
            </div>
            <div class="card-body" style="padding:0">
                <?php
                $profileFields = [
                    ['Username',      $user['username']],
                    ['Role',          ucfirst($user['role_name'])],
                    ['Email',         $user['email'] ?: '—'],
                    ['Phone',         $user['phone'] ?: '—'],
                    ['Account Status',ucfirst($user['status'])],
                    ['Member Since',  format_date($user['created_at'], 'd M Y')],
                    ['Last Login',    $user['last_login'] ? format_date($user['last_login'],'d M Y H:i') : 'First time'],
                    ['Total Logins',  $loginCount],
                ];
                foreach ($profileFields as [$label,$val]):
                ?>
                <div style="display:flex;padding:10px 16px;border-bottom:1px solid var(--border)">
                    <span style="width:130px;font-size:12px;font-weight:700;color:var(--text-muted);flex-shrink:0"><?= $label ?></span>
                    <span style="font-size:13px;font-weight:600"><?= e((string)$val) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Role-specific info -->
        <?php if ($extraInfo && is_teacher()): ?>
        <div class="card">
            <div class="card-header">👨‍🏫 Teacher Details</div>
            <div class="card-body" style="padding:0">
                <?php foreach ([
                    ['Teacher Code', $extraInfo['teacher_code']],
                    ['Qualification',$extraInfo['qualification'] ?: '—'],
                    ['Date Joined',  format_date($extraInfo['joined_date'])],
                    ['Subjects',     $extraInfo['subject_count'] . ' subject(s)'],
                    ['Classes',      $extraInfo['class_count']   . ' class(es)'],
                ] as [$l,$v]): ?>
                <div style="display:flex;padding:10px 16px;border-bottom:1px solid var(--border)">
                    <span style="width:130px;font-size:12px;font-weight:700;color:var(--text-muted);flex-shrink:0"><?= $l ?></span>
                    <span style="font-size:13px;font-weight:600"><?= e((string)$v) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($extraInfo && is_student()): ?>
        <div class="card">
            <div class="card-header">👨‍🎓 Student Details</div>
            <div class="card-body" style="padding:0">
                <?php foreach ([
                    ['Student ID',   $extraInfo['student_id']],
                    ['Class',        $extraInfo['class_name']],
                    ['Session',      $extraInfo['session_name']],
                    ['Gender',       $extraInfo['gender']],
                ] as [$l,$v]): ?>
                <div style="display:flex;padding:10px 16px;border-bottom:1px solid var(--border)">
                    <span style="width:130px;font-size:12px;font-weight:700;color:var(--text-muted);flex-shrink:0"><?= $l ?></span>
                    <span style="font-size:13px;font-weight:600"><?= e((string)$v) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right: Edit forms + activity -->
    <div style="display:flex;flex-direction:column;gap:20px">

        <!-- Update contact info -->
        <div class="card">
            <div class="card-header">✏️ Update Contact Information</div>
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_info">
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control"
                               value="<?= e($user['email'] ?? '') ?>"
                               placeholder="your@email.com">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" name="phone" class="form-control"
                               value="<?= e($user['phone'] ?? '') ?>"
                               placeholder="e.g. 2207000001">
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">💾 Save Changes</button>
                </div>
            </form>
        </div>

        <!-- Change password -->
        <div class="card">
            <div class="card-header">🔐 Change Password</div>
            <form method="POST" action="" data-validate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_password">
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Current Password <span class="req">*</span></label>
                        <div class="input-icon-wrap">
                            <input type="password" name="current_password" id="curPass"
                                   class="form-control" required>
                            <button type="button" class="toggle-password"
                                    onclick="var p=document.getElementById('curPass');p.type=p.type==='password'?'text':'password'">👁️</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">New Password <span class="req">*</span></label>
                        <div class="input-icon-wrap">
                            <input type="password" name="new_password" id="newPass"
                                   class="form-control" required minlength="6"
                                   placeholder="Min. 6 characters">
                            <button type="button" class="toggle-password"
                                    onclick="var p=document.getElementById('newPass');p.type=p.type==='password'?'text':'password'">👁️</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm New Password <span class="req">*</span></label>
                        <input type="password" name="confirm_password" class="form-control"
                               required placeholder="Repeat new password">
                    </div>
                    <div style="background:#FEF3C7;border-radius:8px;padding:10px 12px;font-size:12px;color:#92400E">
                        🔒 Password is stored as a secure bcrypt hash. No one can see your plain password.
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-warning">🔑 Change Password</button>
                </div>
            </form>
        </div>

        <!-- Recent activity -->
        <div class="card">
            <div class="card-header">🔍 My Recent Activity</div>
            <?php if ($recentActivity): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Action</th><th>Module</th><th>Time</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentActivity as $log): ?>
                        <tr>
                            <td class="text-sm"><?= e($log['description'] ?? $log['action']) ?></td>
                            <td><span class="badge badge-secondary"><?= e($log['module']) ?></span></td>
                            <td class="text-xs text-muted"><?= format_date($log['created_at'],'d M H:i') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="card-body table-empty">No activity recorded yet.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
