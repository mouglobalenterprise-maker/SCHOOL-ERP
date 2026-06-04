<?php
// ============================================================
// admin/promotions.php — Student Promotion System
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_ADMIN);

$pageTitle  = 'Student Promotions';
$activeMenu = 'promotions';

$sess_id = current_session_id();
$term_id = current_term_id();

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
    $action = sanitize($_POST['action'] ?? '');

    // ── Single student promote/retain ─────────────────────────
    if (in_array($action, ['promote','retain','graduate','transfer'])) {
        $student_id   = int_val($_POST['student_id'] ?? 0);
        $to_class_id  = int_val($_POST['to_class_id']?? 0);
        $to_session   = int_val($_POST['to_session']  ?? 0);
        $notes        = sanitize($_POST['notes']      ?? '');

        if (!$student_id) { flash_error('Invalid student.'); redirect(BASE_URL . '/admin/promotions.php'); }

        $student = Database::fetchOne(
            "SELECT * FROM students WHERE id=?", [$student_id]
        );
        if (!$student) { flash_error('Student not found.'); redirect(BASE_URL . '/admin/promotions.php'); }

        Database::beginTransaction();
        try {
            // Log promotion
            Database::insert(
                "INSERT INTO promotions
                    (student_id, from_class_id, to_class_id, from_session, to_session,
                     action, promoted_by, notes)
                 VALUES (?,?,?,?,?,?,?,?)",
                [
                    $student_id,
                    $student['class_id'],
                    $to_class_id ?: null,
                    $student['session_id'],
                    $to_session ?: null,
                    $action,
                    current_user_id(),
                    $notes ?: null,
                ]
            );

            // Update student record
            $updateData = [];
            if ($action === 'promote' && $to_class_id) {
                Database::execute(
                    "UPDATE students SET class_id=?, session_id=? WHERE id=?",
                    [$to_class_id, $to_session ?: $sess_id, $student_id]
                );
                $newClass = Database::fetchOne("SELECT name FROM classes WHERE id=?",[$to_class_id]);
                $msg = "Promoted to " . ($newClass['name'] ?? 'new class');
            } elseif ($action === 'retain') {
                // Keep in same class, move to new session
                if ($to_session) {
                    Database::execute("UPDATE students SET session_id=? WHERE id=?",[to_session,$student_id]);
                }
                $msg = 'Retained in current class';
            } elseif ($action === 'graduate') {
                Database::execute("UPDATE students SET status='graduated' WHERE id=?",[$student_id]);
                $msg = 'Marked as graduated';
            } elseif ($action === 'transfer') {
                Database::execute("UPDATE students SET status='transferred' WHERE id=?",[$student_id]);
                $msg = 'Marked as transferred';
            }

            Database::commit();
            audit_log(current_user_id(), current_username(), $action.'_student', 'Promotions',
                "{$msg}: {$student['full_name']} (ID: {$student['student_id']})");
            flash_success("<strong>{$student['full_name']}</strong>: {$msg} successfully.");

        } catch (Exception $e) {
            Database::rollback();
            error_log('[Promotion] ' . $e->getMessage());
            flash_error('Action failed. Please try again.');
        }
    }

    // ── Bulk promote entire class ──────────────────────────────
    elseif ($action === 'bulk_promote') {
        $from_class  = int_val($_POST['from_class_id'] ?? 0);
        $to_class    = int_val($_POST['to_class_id']   ?? 0);
        $to_session  = int_val($_POST['to_session_id'] ?? $sess_id);
        $student_ids = array_map('intval', $_POST['student_ids'] ?? []);

        if (!$from_class || !$to_class || empty($student_ids)) {
            flash_error('Please select classes and students to promote.');
        } else {
            $promoted = 0;
            Database::beginTransaction();
            try {
                foreach ($student_ids as $sid) {
                    $st = Database::fetchOne("SELECT * FROM students WHERE id=?",[$sid]);
                    if (!$st) continue;

                    Database::insert(
                        "INSERT INTO promotions
                            (student_id,from_class_id,to_class_id,from_session,to_session,action,promoted_by)
                         VALUES (?,?,?,?,?,'promoted',?)",
                        [$sid,$from_class,$to_class,$sess_id,$to_session,current_user_id()]
                    );
                    Database::execute(
                        "UPDATE students SET class_id=?,session_id=? WHERE id=?",
                        [$to_class,$to_session,$sid]
                    );
                    $promoted++;
                }
                Database::commit();
                audit_log(current_user_id(), current_username(), 'bulk_promote', 'Promotions',
                    "Bulk promoted {$promoted} students from class {$from_class} to {$to_class}");
                flash_success("✅ {$promoted} students promoted successfully.");
            } catch (Exception $e) {
                Database::rollback();
                flash_error('Bulk promotion failed: ' . $e->getMessage());
            }
        }
    }

    redirect(BASE_URL . '/admin/promotions.php?' . http_build_query(array_intersect_key($_GET,['class_id'=>1])));
}

// ── Load data ─────────────────────────────────────────────────
$selClass   = int_val($_GET['class_id'] ?? 0);
$classes    = Database::fetchAll("SELECT id,name FROM classes ORDER BY sort_order");
$sessions   = Database::fetchAll("SELECT id,name FROM academic_sessions ORDER BY id DESC");

// Students in selected class with their avg scores
$students = [];
if ($selClass) {
    $students = Database::fetchAll(
        "SELECT s.*,
                ROUND(AVG(r.total_score),1) AS avg_score,
                COUNT(r.id) AS result_count,
                (SELECT COUNT(*) FROM attendance a WHERE a.student_id=s.id AND a.term_id=? AND a.status='absent') AS absent_days
         FROM students s
         LEFT JOIN results r ON r.student_id=s.id AND r.session_id=? AND r.term_id=?
         WHERE s.class_id=? AND s.session_id=? AND s.status='active'
         GROUP BY s.id ORDER BY s.full_name",
        [$term_id, $sess_id, $term_id, $selClass, $sess_id]
    );
}

// Promotion history
$promoHistory = Database::fetchAll(
    "SELECT p.*, s.full_name, s.student_id AS sid,
            fc.name AS from_class, tc.name AS to_class,
            u.full_name AS promoted_by_name,
            fses.name AS from_session_name,
            tses.name AS to_session_name
     FROM promotions p
     JOIN students s ON s.id=p.student_id
     JOIN classes  fc ON fc.id=p.from_class_id
     LEFT JOIN classes tc ON tc.id=p.to_class_id
     LEFT JOIN academic_sessions fses ON fses.id=p.from_session
     LEFT JOIN academic_sessions tses ON tses.id=p.to_session
     JOIN users u ON u.id=p.promoted_by
     ORDER BY p.promoted_at DESC LIMIT 30"
);

$passGrade = (float)(Database::fetchOne("SELECT MIN(min) AS m FROM grade_ranges WHERE grade != 'F'")['m'] ?? 50);

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">🔄 Student Promotions</h1>
        <p class="page-subtitle">Promote or retain students for the next academic session</p>
    </div>
</div>

<!-- Class selector -->
<div class="card mb-20">
    <div class="card-header">⚙️ Select Class to Manage</div>
    <div class="card-body">
        <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <div class="form-group" style="margin:0;flex:1;min-width:200px">
                <label class="form-label">From Class</label>
                <select name="class_id" class="form-control" onchange="this.form.submit()">
                    <option value="">Select class…</option>
                    <?php foreach ($classes as $cls): ?>
                        <option value="<?= $cls['id'] ?>" <?= $selClass==$cls['id']?'selected':'' ?>>
                            <?= e($cls['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if ($selClass && !empty($students)): ?>

<!-- Bulk promotion form -->
<div class="card mb-20">
    <div class="card-header">🚀 Bulk Promotion</div>
    <form method="POST" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk_promote">
        <input type="hidden" name="from_class_id" value="<?= $selClass ?>">
        <div class="card-body">
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Promote To Class <span class="req">*</span></label>
                    <select name="to_class_id" class="form-control" required>
                        <option value="">Select next class…</option>
                        <?php foreach ($classes as $cls): if ($cls['id'] == $selClass) continue; ?>
                            <option value="<?= $cls['id'] ?>"><?= e($cls['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Move to Session</label>
                    <select name="to_session_id" class="form-control">
                        <?php foreach ($sessions as $ses): ?>
                            <option value="<?= $ses['id'] ?>"><?= e($ses['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Select students to promote -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                <label class="form-label" style="margin:0">Select Students to Promote</label>
                <div style="display:flex;gap:8px">
                    <button type="button" class="btn btn-sm btn-success" onclick="selectQualifying()">
                        ✅ Select Qualifying (avg ≥ <?= $passGrade ?>)
                    </button>
                    <button type="button" class="btn btn-sm btn-outline" onclick="toggleAll()">
                        Toggle All
                    </button>
                </div>
            </div>

            <div style="background:var(--light);border-radius:10px;max-height:300px;overflow-y:auto;border:1px solid var(--border)">
                <table class="data-table" style="margin:0">
                    <thead style="position:sticky;top:0;z-index:1">
                        <tr><th style="width:40px">✓</th>
                        <th>Student</th><th>Avg Score</th><th>Subjects</th><th>Absences</th><th>Recommendation</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($students as $s):
                        $avg      = (float)($s['avg_score'] ?? 0);
                        $qualifies = $avg >= $passGrade || $s['result_count'] == 0;
                        $recColor  = $qualifies ? 'var(--emerald)' : 'var(--red)';
                        $recLabel  = $qualifies ? '✅ Promote' : '❌ Retain';
                    ?>
                        <tr id="srow_<?= $s['id'] ?>"
                            style="<?= $qualifies?'background:rgba(16,185,129,.04)':'background:#FEF2F2' ?>">
                            <td style="text-align:center">
                                <input type="checkbox" name="student_ids[]" value="<?= $s['id'] ?>"
                                       class="promote-cb"
                                       data-avg="<?= $avg ?>"
                                       data-qualifies="<?= $qualifies?1:0 ?>"
                                       <?= $qualifies?'checked':'' ?>>
                            </td>
                            <td>
                                <div style="font-weight:700"><?= e($s['full_name']) ?></div>
                                <div class="text-xs text-muted"><?= e($s['student_id']) ?></div>
                            </td>
                            <td>
                                <span style="font-weight:800;color:<?= $avg>=$passGrade?'var(--emerald)':'var(--red)' ?>">
                                    <?= $avg ?: '—' ?>
                                </span>
                            </td>
                            <td class="text-muted"><?= $s['result_count'] ?></td>
                            <td style="color:<?= $s['absent_days']>=5?'var(--red)':'var(--text-muted)' ?>">
                                <?= $s['absent_days'] ?> days
                            </td>
                            <td><span style="font-weight:700;font-size:12px;color:<?= $recColor ?>"><?= $recLabel ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center">
            <div class="text-sm text-muted">
                <span id="selectedCount">0</span> students selected for promotion
            </div>
            <button type="submit" class="btn btn-success btn-lg"
                    data-confirm="Promote selected students? This will update their class records.">
                🚀 Promote Selected Students
            </button>
        </div>
    </form>
</div>

<!-- Individual student actions -->
<div class="card mb-24">
    <div class="card-header">👤 Individual Student Actions</div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>Student</th><th>Avg Score</th><th>Results</th><th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($students as $s): ?>
                <tr>
                    <td>
                        <div style="font-weight:700"><?= e($s['full_name']) ?></div>
                        <div class="text-xs text-muted"><?= e($s['student_id']) ?></div>
                    </td>
                    <td>
                        <strong style="color:<?= ($s['avg_score']??0)>=$passGrade?'var(--emerald)':'var(--red)' ?>">
                            <?= $s['avg_score'] ?: '—' ?>
                        </strong>
                    </td>
                    <td class="text-muted"><?= $s['result_count'] ?> subjects</td>
                    <td>
                        <div class="td-actions">
                            <button class="btn btn-sm btn-success"
                                    onclick="openActionModal(<?= $s['id'] ?>, '<?= e(addslashes($s['full_name'])) ?>', 'promote')">
                                ✅ Promote
                            </button>
                            <button class="btn btn-sm btn-warning"
                                    onclick="openActionModal(<?= $s['id'] ?>, '<?= e(addslashes($s['full_name'])) ?>', 'retain')">
                                ↺ Retain
                            </button>
                            <button class="btn btn-sm btn-outline"
                                    onclick="openActionModal(<?= $s['id'] ?>, '<?= e(addslashes($s['full_name'])) ?>', 'graduate')">
                                🎓 Graduate
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($selClass): ?>
<div class="card mb-24"><div class="card-body table-empty">No active students in this class.</div></div>
<?php else: ?>
<div class="card mb-24"><div class="card-body table-empty">
    <div class="table-empty-icon">🔄</div>
    Select a class above to manage promotions.
</div></div>
<?php endif; ?>

<!-- Promotion History -->
<div class="card">
    <div class="card-header">📋 Recent Promotion History</div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>Student</th><th>Action</th><th>From Class</th><th>To Class</th><th>Session</th><th>Promoted By</th><th>Date</th>
            </tr></thead>
            <tbody>
            <?php if ($promoHistory): foreach ($promoHistory as $p): ?>
                <tr>
                    <td>
                        <div style="font-weight:700"><?= e($p['full_name']) ?></div>
                        <div class="text-xs text-muted"><?= e($p['sid']) ?></div>
                    </td>
                    <td>
                        <span class="badge <?= $p['action']==='promoted'?'badge-success':($p['action']==='retain'?'badge-warning':($p['action']==='graduated'?'badge-primary':'badge-secondary')) ?>">
                            <?= ucfirst($p['action']) ?>
                        </span>
                    </td>
                    <td><?= e($p['from_class']) ?></td>
                    <td><?= e($p['to_class'] ?? '—') ?></td>
                    <td class="text-sm text-muted"><?= e($p['from_session_name'] ?? '—') ?> → <?= e($p['to_session_name'] ?? '—') ?></td>
                    <td class="text-sm"><?= e($p['promoted_by_name']) ?></td>
                    <td class="text-sm text-muted"><?= format_date($p['promoted_at'], 'd M Y') ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="7" class="table-empty">No promotion history yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Individual Action Modal -->
<div class="modal-backdrop" id="actionModal" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title" id="actionModalTitle">Student Action</span>
            <button class="modal-close" data-modal-close>×</button>
        </div>
        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action"     id="actionType">
            <input type="hidden" name="student_id" id="actionStudentId">
            <div class="modal-body">
                <div style="background:var(--light);border-radius:8px;padding:10px 14px;margin-bottom:16px">
                    <div class="text-sm text-muted">Student:</div>
                    <div style="font-weight:800;font-size:15px" id="actionStudentName"></div>
                </div>
                <div class="form-group" id="toClassGroup">
                    <label class="form-label">Promote to Class</label>
                    <select name="to_class_id" class="form-control">
                        <option value="">Select class…</option>
                        <?php foreach ($classes as $cls): ?>
                            <option value="<?= $cls['id'] ?>"><?= e($cls['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="toSessionGroup">
                    <label class="form-label">Move to Session</label>
                    <select name="to_session" class="form-control">
                        <?php foreach ($sessions as $ses): ?>
                            <option value="<?= $ses['id'] ?>"><?= e($ses['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes (optional)</label>
                    <textarea name="notes" class="form-control" rows="2"
                              placeholder="Reason for this action…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary" id="actionSubmitBtn">Confirm</button>
            </div>
        </form>
    </div>
</div>

<script>
const passGrade = <?= $passGrade ?>;

function openActionModal(id, name, action) {
    document.getElementById('actionType').value       = action;
    document.getElementById('actionStudentId').value  = id;
    document.getElementById('actionStudentName').textContent = name;

    const titles  = { promote:'✅ Promote Student', retain:'↺ Retain Student', graduate:'🎓 Graduate Student', transfer:'🚌 Transfer Student' };
    document.getElementById('actionModalTitle').textContent = titles[action] || 'Student Action';
    document.getElementById('actionSubmitBtn').textContent  = titles[action] || 'Confirm';

    const showClass   = action === 'promote';
    const showSession = action === 'promote' || action === 'retain';
    document.getElementById('toClassGroup').style.display   = showClass   ? '' : 'none';
    document.getElementById('toSessionGroup').style.display = showSession ? '' : 'none';

    openModal('actionModal');
}

function selectQualifying() {
    document.querySelectorAll('.promote-cb').forEach(cb => {
        cb.checked = cb.dataset.qualifies === '1';
    });
    updateCount();
}

let allSelected = false;
function toggleAll() {
    allSelected = !allSelected;
    document.querySelectorAll('.promote-cb').forEach(cb => cb.checked = allSelected);
    updateCount();
}

function updateCount() {
    const count = document.querySelectorAll('.promote-cb:checked').length;
    const el = document.getElementById('selectedCount');
    if (el) el.textContent = count;
}

document.querySelectorAll('.promote-cb').forEach(cb => {
    cb.addEventListener('change', updateCount);
});
updateCount();
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
