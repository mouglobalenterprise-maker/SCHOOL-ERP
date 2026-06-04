<?php
// ============================================================
// admin/settings.php — System Settings
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_ADMIN);

$pageTitle  = 'System Settings';
$activeMenu = 'settings';

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
    $section = sanitize($_POST['section'] ?? '');

    // ── School settings ───────────────────────────────────────
    if ($section === 'school') {
        $fields = ['school_name','school_address','school_phone','school_email',
                   'school_motto','current_session','current_term','currency',
                   'currency_symbol','class_label','results_test_max','results_asn_max','results_exam_max'];
        foreach ($fields as $key) {
            set_setting($key, sanitize($_POST[$key] ?? ''));
        }
        // Logo upload
        if (!empty($_FILES['school_logo']['name'])) {
            $upload = handle_upload($_FILES['school_logo'], 'logos', ALLOWED_IMG_TYPES);
            if ($upload['success']) set_setting('school_logo', $upload['filename']);
            else flash_error('Logo upload failed: ' . $upload['message']);
        }
        // Signature upload
        if (!empty($_FILES['principal_sig']['name'])) {
            $upload = handle_upload($_FILES['principal_sig'], 'logos', ALLOWED_IMG_TYPES);
            if ($upload['success']) set_setting('principal_sig', $upload['filename']);
            else flash_error('Signature upload failed: ' . $upload['message']);
        }
        // Clear settings cache
        unset($_SESSION['_settings_cache']);
        audit_log(current_user_id(), current_username(), 'update_settings', 'Settings', 'Updated school settings');
        flash_success('School settings saved successfully.');
    }

    // ── Grade ranges ──────────────────────────────────────────
    elseif ($section === 'grades') {
        $grades  = $_POST['grade']  ?? [];
        $mins    = $_POST['min']    ?? [];
        $maxs    = $_POST['max']    ?? [];
        $remarks = $_POST['remark'] ?? [];
        $points  = $_POST['points'] ?? [];

        Database::execute("DELETE FROM grade_ranges", []);
        foreach ($grades as $i => $grade) {
            $grade = strtoupper(sanitize($grade));
            if (!$grade) continue;
            Database::insert(
                "INSERT INTO grade_ranges (grade,min,max,remark,points) VALUES (?,?,?,?,?)",
                [$grade, int_val($mins[$i]??0), int_val($maxs[$i]??0),
                 sanitize($remarks[$i]??''), float_val($points[$i]??0)]
            );
        }
        audit_log(current_user_id(), current_username(), 'update_grades', 'Settings', 'Updated grade ranges');
        flash_success('Grade ranges updated successfully.');
    }

    // ── Password reset ────────────────────────────────────────
    elseif ($section === 'password_reset') {
        $uid      = int_val($_POST['user_id']  ?? 0);
        $newPass  = $_POST['new_password']     ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (!$uid)           { flash_error('Invalid user.'); }
        elseif (strlen($newPass) < 6) { flash_error('Password must be at least 6 characters.'); }
        elseif ($newPass !== $confirm) { flash_error('Passwords do not match.'); }
        else {
            $ok = admin_reset_password($uid, $newPass);
            if ($ok) flash_success('Password reset successfully. User must log in with the new password.');
            else     flash_error('Failed to reset password. User not found.');
        }
    }

    // ── Session/term toggle ───────────────────────────────────
    elseif ($section === 'session') {
        $sessionId = int_val($_POST['session_id'] ?? 0);
        $termId    = int_val($_POST['term_id']    ?? 0);
        if ($sessionId) {
            Database::execute("UPDATE academic_sessions SET is_current=0", []);
            Database::execute("UPDATE academic_sessions SET is_current=1 WHERE id=?", [$sessionId]);
        }
        if ($termId) {
            Database::execute("UPDATE terms SET is_current=0", []);
            Database::execute("UPDATE terms SET is_current=1 WHERE id=?", [$termId]);
            $termRow = Database::fetchOne("SELECT name FROM terms WHERE id=?",[$termId]);
            set_setting('current_term', $termRow['name'] ?? '');
        }
        flash_success('Current session/term updated.');
    }

    // ── Add session ───────────────────────────────────────────
    elseif ($section === 'add_session') {
        $name = sanitize($_POST['session_name'] ?? '');
        if ($name) {
            Database::insert("INSERT INTO academic_sessions (name) VALUES (?)", [$name]);
            // Add 3 terms
            $newSessId = Database::fetchOne("SELECT id FROM academic_sessions WHERE name=?",[$name])['id'];
            foreach (['First','Second','Third'] as $t) {
                Database::insert("INSERT INTO terms (name,session_id) VALUES (?,?)",[$t,$newSessId]);
            }
            flash_success("Session <strong>{$name}</strong> created with 3 terms.");
        }
    }

    redirect(BASE_URL . '/admin/settings.php');
}

// ── Load data ─────────────────────────────────────────────────
$settings    = get_all_settings();
$gradeRanges = Database::fetchAll("SELECT * FROM grade_ranges ORDER BY min DESC");
$sessions    = Database::fetchAll("SELECT * FROM academic_sessions ORDER BY id DESC");
$terms       = Database::fetchAll("SELECT t.*, ses.name AS session_name FROM terms t JOIN academic_sessions ses ON ses.id=t.session_id ORDER BY ses.id DESC, t.id");
$users       = Database::fetchAll(
    "SELECT u.id, u.username, u.full_name, u.status, r.name AS role_name, u.last_login
     FROM users u JOIN roles r ON r.id=u.role_id
     ORDER BY r.id, u.full_name"
);
$apiEndpoints = [
    ['GET', '/api/get_students.php',   'Returns list of students as JSON'],
    ['GET', '/api/get_results.php',    'Returns results filtered by term/class/subject'],
    ['GET', '/api/get_attendance.php', 'Returns attendance records'],
    ['GET', '/api/get_payments.php',   'Returns payment records'],
    ['GET', '/api/search.php?type=students&q=john', 'Real-time AJAX search'],
];

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">⚙️ System Settings</h1>
        <p class="page-subtitle">Configure your school ERP system</p>
    </div>
</div>

<!-- Tabs -->
<div class="tabs mb-24" id="settingsTabBar">
    <?php foreach ([
        'school'  => '🏫 School',
        'grades'  => '📊 Grading',
        'session' => '📅 Session & Term',
        'users'   => '👥 Users',
        'api'     => '🔌 API',
    ] as $tab => $label): ?>
        <button class="tab-btn"
                data-tab="<?= $tab ?>"
                onclick="settingsTab('<?= $tab ?>')">
            <?= $label ?>
        </button>
    <?php endforeach; ?>
</div>

<!-- ══ School Settings ══ -->
<div id="school" class="tab-content" style="display:none">
    <form method="POST" action="" enctype="multipart/form-data" data-validate>
        <?= csrf_field() ?>
        <input type="hidden" name="section" value="school">
        <div class="grid-2" style="gap:20px">
            <div class="card">
                <div class="card-header">🏫 School Information</div>
                <div class="card-body">
                    <?php
                    $schoolFields = [
                        ['school_name','School Name','text'],
                        ['school_address','School Address','text'],
                        ['school_phone','School Phone','text'],
                        ['school_email','School Email','email'],
                        ['school_motto','School Motto','text'],
                        ['class_label','Class Label (e.g. Class/Grade/Year)','text'],
                    ];
                    foreach ($schoolFields as [$key,$label,$type]): ?>
                    <div class="form-group">
                        <label class="form-label"><?= $label ?></label>
                        <input type="<?= $type ?>" name="<?= $key ?>" class="form-control"
                               value="<?= e($settings[$key] ?? '') ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display:flex;flex-direction:column;gap:20px">
                <div class="card">
                    <div class="card-header">💰 Financial & Score Settings</div>
                    <div class="card-body">
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label class="form-label">Currency Code</label>
                                <input type="text" name="currency" class="form-control"
                                       value="<?= e($settings['currency'] ?? 'GMD') ?>" maxlength="5">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Currency Symbol</label>
                                <input type="text" name="currency_symbol" class="form-control"
                                       value="<?= e($settings['currency_symbol'] ?? 'D') ?>" maxlength="3">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Test Score Max</label>
                                <input type="number" name="results_test_max" class="form-control"
                                       value="<?= e($settings['results_test_max'] ?? '20') ?>" min="1" max="50">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Assignment Score Max</label>
                                <input type="number" name="results_asn_max" class="form-control"
                                       value="<?= e($settings['results_asn_max'] ?? '20') ?>" min="1" max="50">
                            </div>
                            <div class="form-group" style="grid-column:1/-1">
                                <label class="form-label">Exam Score Max</label>
                                <input type="number" name="results_exam_max" class="form-control"
                                       value="<?= e($settings['results_exam_max'] ?? '60') ?>" min="1" max="100">
                                <div class="form-hint">Total = Test + Assignment + Exam</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">🖼️ Logo & Signature</div>
                    <div class="card-body">
                        <?php $logo = $settings['school_logo'] ?? ''; ?>
                        <?php if ($logo && file_exists(UPLOADS_PATH . '/logos/' . $logo)): ?>
                            <div style="margin-bottom:12px;text-align:center">
                                <img src="<?= UPLOADS_URL ?>/logos/<?= e($logo) ?>"
                                     style="max-height:80px;border-radius:8px;border:1px solid var(--border)">
                                <div class="text-xs text-muted mt-4">Current Logo</div>
                            </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label class="form-label">School Logo</label>
                            <input type="file" name="school_logo" class="form-control"
                                   accept="image/*">
                            <div class="form-hint">PNG/JPG, appears on report cards and receipts</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Principal's Signature</label>
                            <input type="file" name="principal_sig" class="form-control"
                                   accept="image/*">
                            <div class="form-hint">PNG with transparent background recommended</div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <button type="submit" class="btn btn-primary btn-lg">💾 Save Settings</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- ══ Grade Ranges ══ -->
<div id="grades" class="tab-content" style="display:none">
    <div class="card">
        <div class="card-header">📊 Grade Ranges (Dynamic — Admin Configurable)</div>
        <form method="POST" action="" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="section" value="grades">
            <div class="table-wrap">
                <table class="data-table" id="gradeTable">
                    <thead><tr>
                        <th>Grade</th>
                        <th>Min Score</th>
                        <th>Max Score</th>
                        <th>Remark</th>
                        <th>Grade Points</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody id="gradeRows">
                    <?php foreach ($gradeRanges as $i => $gr): ?>
                        <tr id="gradeRow_<?= $i ?>">
                            <td><input type="text" name="grade[]" class="form-control" style="width:60px"
                                       value="<?= e($gr['grade']) ?>" maxlength="3" required></td>
                            <td><input type="number" name="min[]" class="form-control" style="width:70px"
                                       value="<?= $gr['min'] ?>" min="0" max="100" required></td>
                            <td><input type="number" name="max[]" class="form-control" style="width:70px"
                                       value="<?= $gr['max'] ?>" min="0" max="100" required></td>
                            <td><input type="text" name="remark[]" class="form-control" style="width:120px"
                                       value="<?= e($gr['remark']) ?>"></td>
                            <td><input type="number" name="points[]" class="form-control" style="width:70px"
                                       value="<?= $gr['points'] ?>" step="0.1" min="0" max="5"></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-danger"
                                        onclick="this.closest('tr').remove()">✕</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center">
                <button type="button" class="btn btn-outline btn-sm" onclick="addGradeRow()">+ Add Grade</button>
                <button type="submit" class="btn btn-primary">💾 Save Grade Ranges</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Session & Term ══ -->
<div id="session" class="tab-content" style="display:none">
    <div class="grid-2" style="gap:20px">
        <div class="card">
            <div class="card-header">📅 Set Current Session & Term</div>
            <form method="POST" action="" data-validate>
                <?= csrf_field() ?>
                <input type="hidden" name="section" value="session">
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Current Academic Session</label>
                        <select name="session_id" class="form-control">
                            <?php foreach ($sessions as $ses): ?>
                                <option value="<?= $ses['id'] ?>" <?= $ses['is_current']?'selected':'' ?>>
                                    <?= e($ses['name']) ?> <?= $ses['is_current']?'(Current)':'' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Current Term</label>
                        <select name="term_id" class="form-control">
                            <?php foreach ($terms as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= $t['is_current']?'selected':'' ?>>
                                    <?= e($t['session_name']) ?> — <?= e($t['name']) ?> Term
                                    <?= $t['is_current']?'(Current)':'' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">Save Session/Term</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">➕ Create New Session</div>
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="section" value="add_session">
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Session Name</label>
                        <input type="text" name="session_name" class="form-control"
                               placeholder="e.g. 2025/2026">
                        <div class="form-hint">Three terms (First, Second, Third) will be created automatically.</div>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">Create Session</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ User Management ══ -->
<div id="users" class="tab-content" style="display:none">
    <div class="card">
        <div class="card-header">👥 User Accounts & Password Reset</div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Reset Password</th>
                </tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><span class="code"><?= e($u['username']) ?></span></td>
                        <td style="font-weight:700"><?= e($u['full_name']) ?></td>
                        <td><span class="badge badge-navy"><?= e($u['role_name']) ?></span></td>
                        <td><?= status_badge($u['status']) ?></td>
                        <td class="text-sm text-muted">
                            <?= $u['last_login'] ? format_date($u['last_login'],'d M Y H:i') : 'Never' ?>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-warning"
                                    onclick="openResetModal(<?= $u['id'] ?>, '<?= e(addslashes($u['full_name'])) ?>', '<?= e(addslashes($u['username'])) ?>')">
                                🔑 Reset Password
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══ API Info ══ -->
<div id="api" class="tab-content" style="display:none">
    <div class="card">
        <div class="card-header">🔌 Self-Built API Endpoints</div>
        <div class="card-body">
            <div class="form-hint mb-16">
                All endpoints return JSON. Authentication via session cookie is required.
                Send <code>X-Requested-With: XMLHttpRequest</code> header for AJAX calls.
            </div>
            <?php foreach ($apiEndpoints as [$method, $path, $desc]): ?>
            <div style="border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin-bottom:12px">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
                    <span style="background:var(--emerald);color:#fff;padding:2px 10px;border-radius:4px;
                                 font-size:11px;font-weight:800;font-family:monospace"><?= $method ?></span>
                    <code style="font-size:13px;font-weight:700;color:var(--navy)"><?= BASE_URL . $path ?></code>
                </div>
                <div class="text-sm text-muted"><?= $desc ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Password Reset Modal -->
<div class="modal-backdrop" id="resetPassModal" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">🔑 Reset Password</span>
            <button class="modal-close" data-modal-close>×</button>
        </div>
        <form method="POST" action="" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="section" value="password_reset">
            <input type="hidden" name="user_id" id="resetUserId">
            <div class="modal-body">
                <div style="background:var(--light);border-radius:8px;padding:10px 14px;margin-bottom:16px">
                    <div class="text-sm text-muted">Resetting password for:</div>
                    <div style="font-weight:800;font-size:15px" id="resetUserName"></div>
                    <div class="text-sm text-muted" id="resetUserUsername"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">New Password <span class="req">*</span></label>
                    <div class="input-icon-wrap">
                        <input type="password" name="new_password" id="resetPass"
                               class="form-control" required minlength="6"
                               placeholder="Min. 6 characters">
                        <button type="button" class="toggle-password"
                                onclick="var p=document.getElementById('resetPass');p.type=p.type==='password'?'text':'password'">👁️</button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm Password <span class="req">*</span></label>
                    <input type="password" name="confirm_password" class="form-control"
                           required placeholder="Repeat password">
                </div>
                <div style="background:#FEF3C7;border-radius:8px;padding:10px 12px;font-size:12px;color:#92400E">
                    🔒 Password is stored using secure bcrypt hashing (password_hash).
                    No email required — admin-only reset system.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-warning">🔑 Reset Password</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Settings-specific tab switcher ──────────────────────────
const SETTINGS_TABS = ['school','grades','session','users','api'];

function settingsTab(tabId) {
    // Update buttons
    document.querySelectorAll('#settingsTabBar .tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tabId);
    });
    // Update panels
    SETTINGS_TABS.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = (id === tabId) ? 'block' : 'none';
    });
    // Update URL without reload
    const url = new URL(window.location);
    url.searchParams.set('tab', tabId);
    window.history.replaceState({}, '', url);
}

// Init on load
document.addEventListener('DOMContentLoaded', function() {
    const tabParam = new URLSearchParams(window.location.search).get('tab') || 'school';
    settingsTab(tabParam);
});

function openResetModal(id, name, username) {
    document.getElementById('resetUserId').value             = id;
    document.getElementById('resetUserName').textContent     = name;
    document.getElementById('resetUserUsername').textContent = '@' + username;
    openModal('resetPassModal');
}

function addGradeRow() {
    const tbody = document.getElementById('gradeRows');
    const tr    = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text"   name="grade[]"  class="form-control" style="width:60px" maxlength="3" required></td>
        <td><input type="number" name="min[]"    class="form-control" style="width:70px" min="0" max="100" required></td>
        <td><input type="number" name="max[]"    class="form-control" style="width:70px" min="0" max="100" required></td>
        <td><input type="text"   name="remark[]" class="form-control" style="width:120px"></td>
        <td><input type="number" name="points[]" class="form-control" style="width:70px" step="0.1" min="0" max="5"></td>
        <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove()">✕</button></td>
    `;
    tbody.appendChild(tr);
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
