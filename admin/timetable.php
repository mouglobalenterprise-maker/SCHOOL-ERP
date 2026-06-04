<?php
// ============================================================
// admin/timetable.php — Timetable Management
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_login();

$pageTitle  = 'Timetable';
$activeMenu = 'timetable';

$isAdmin   = is_admin();
$isTeacher = is_teacher();

// ── Handle POST (admin only) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    csrf_protect();
    $action = sanitize($_POST['action'] ?? '');

    // ── SAVE slot ─────────────────────────────────────────────
    if ($action === 'save_slot') {
        $class_id   = int_val($_POST['class_id']   ?? 0);
        $day        = sanitize($_POST['day']        ?? '');
        $period     = int_val($_POST['period']      ?? 0);
        $subject_id = int_val($_POST['subject_id']  ?? 0);
        $teacher_id = int_val($_POST['teacher_id']  ?? 0);
        $start_time = sanitize($_POST['start_time'] ?? '');
        $end_time   = sanitize($_POST['end_time']   ?? '');
        $is_break   = isset($_POST['is_break']) ? 1 : 0;
        $label      = sanitize($_POST['label']      ?? '');

        if (!$class_id || !$day || !$period) {
            json_response(false, 'Missing required fields.');
        }

        if (!in_array($day, ['Monday','Tuesday','Wednesday','Thursday','Friday'])) {
            json_response(false, 'Invalid day.');
        }

        $existing = Database::fetchOne(
            "SELECT id FROM timetable WHERE class_id=? AND day=? AND period=?",
            [$class_id, $day, $period]
        );

        $data = [
            $is_break ? null : ($subject_id ?: null),
            $is_break ? null : ($teacher_id ?: null),
            $start_time ?: null,
            $end_time   ?: null,
            $is_break,
            $is_break ? ($label ?: 'Break') : null,
        ];

        if ($existing) {
            Database::execute(
                "UPDATE timetable SET subject_id=?,teacher_id=?,start_time=?,end_time=?,is_break=?,label=? WHERE id=?",
                array_merge($data, [$existing['id']])
            );
        } else {
            Database::insert(
                "INSERT INTO timetable (class_id,day,period,subject_id,teacher_id,start_time,end_time,is_break,label)
                 VALUES (?,?,?,?,?,?,?,?,?)",
                array_merge([$class_id,$day,$period], $data)
            );
        }

        audit_log(current_user_id(), current_username(), 'update_timetable', 'Timetable',
            "Updated slot: class {$class_id}, {$day} period {$period}");
        json_response(true, 'Slot saved.', ['slot' => ['day'=>$day,'period'=>$period]]);
    }

    // ── CLEAR slot ────────────────────────────────────────────
    if ($action === 'clear_slot') {
        $class_id = int_val($_POST['class_id'] ?? 0);
        $day      = sanitize($_POST['day']     ?? '');
        $period   = int_val($_POST['period']   ?? 0);
        Database::execute(
            "DELETE FROM timetable WHERE class_id=? AND day=? AND period=?",
            [$class_id, $day, $period]
        );
        json_response(true, 'Slot cleared.');
    }

    // ── CLEAR entire class timetable ──────────────────────────
    if ($action === 'clear_class') {
        $class_id = int_val($_POST['class_id'] ?? 0);
        if ($class_id) {
            Database::execute("DELETE FROM timetable WHERE class_id=?", [$class_id]);
            flash_success('Timetable cleared for this class.');
        }
        redirect(BASE_URL . '/admin/timetable.php?class_id=' . $class_id);
    }
}

// ── Selected class ────────────────────────────────────────────
$classes  = Database::fetchAll("SELECT id,name FROM classes ORDER BY sort_order");
$class_id = int_val($_GET['class_id'] ?? ($classes[0]['id'] ?? 0));

// If teacher: default to their first assigned class
if ($isTeacher && !$_GET['class_id']) {
    $myT = Database::fetchOne(
        "SELECT t.id FROM teachers t JOIN users u ON u.id=t.user_id WHERE u.id=?",
        [current_user_id()]
    );
    if ($myT) {
        $firstClass = Database::fetchOne(
            "SELECT DISTINCT c.id FROM teacher_subjects ts JOIN classes c ON c.id=ts.class_id WHERE ts.teacher_id=? LIMIT 1",
            [$myT['id']]
        );
        if ($firstClass) $class_id = $firstClass['id'];
    }
}

// Build timetable data
$days    = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
$periods = range(1, 8);
$timeSlots = [
    1 => ['07:30','08:20'], 2 => ['08:20','09:10'], 3 => ['09:10','09:30'],
    4 => ['09:30','10:20'], 5 => ['10:20','11:10'], 6 => ['11:10','12:00'],
    7 => ['12:00','12:50'], 8 => ['12:50','13:40'],
];

// Load existing timetable
$ttRows = Database::fetchAll(
    "SELECT tt.*, sub.name AS subject_name, u.full_name AS teacher_name
     FROM timetable tt
     LEFT JOIN subjects sub ON sub.id=tt.subject_id
     LEFT JOIN teachers t   ON t.id =tt.teacher_id
     LEFT JOIN users    u   ON u.id =t.user_id
     WHERE tt.class_id=?",
    [$class_id]
);

// Index: $grid[$day][$period]
$grid = [];
foreach ($ttRows as $row) {
    $grid[$row['day']][$row['period']] = $row;
}

// Dropdowns
$subjects = Database::fetchAll("SELECT id,name FROM subjects ORDER BY name");
$teachers = Database::fetchAll(
    "SELECT t.id, u.full_name FROM teachers t JOIN users u ON u.id=t.user_id WHERE t.status='active' ORDER BY u.full_name"
);

// Period colors
$periodColors = ['#DBEAFE','#D1FAE5','#FEF3C7','#EDE9FE','#FCE7F3','#CFFAFE','#FEE2E2','#F3F4F6'];

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📆 Timetable</h1>
        <p class="page-subtitle">
            <?= e(Database::fetchOne("SELECT name FROM classes WHERE id=?",[$class_id])['name'] ?? '') ?>
            &mdash; Weekly Schedule
        </p>
    </div>
    <div class="page-header-actions">
        <?php if ($isAdmin): ?>
            <form method="POST" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action"   value="clear_class">
                <input type="hidden" name="class_id" value="<?= $class_id ?>">
                <button type="submit" class="btn btn-danger btn-sm"
                        data-confirm="Clear entire timetable for this class?">🗑️ Clear All</button>
            </form>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-outline">🖨️ Print</button>
    </div>
</div>

<!-- Class selector -->
<div class="card mb-20">
    <div class="card-body" style="padding:14px 16px">
        <form method="GET" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <label class="form-label" style="margin:0;white-space:nowrap">View Class:</label>
            <select name="class_id" class="filter-select" onchange="this.form.submit()" style="min-width:160px">
                <?php foreach ($classes as $cls): ?>
                    <option value="<?= $cls['id'] ?>" <?= $class_id==$cls['id']?'selected':'' ?>><?= e($cls['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="text-sm text-muted">
                <?= count($ttRows) ?> periods configured
                <?php if ($isAdmin): ?>
                    &nbsp;|&nbsp; <em>Click any cell to edit</em>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Timetable grid -->
<div class="card" id="ttCard">
    <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;min-width:700px">
            <thead>
                <tr style="background:var(--navy)">
                    <th style="padding:12px 14px;color:var(--accent);font-size:12px;font-weight:700;
                               text-align:left;width:100px;white-space:nowrap">Period / Time</th>
                    <?php foreach ($days as $day): ?>
                        <th style="padding:12px 14px;color:var(--white);font-size:13px;font-weight:700;
                                   text-align:center;white-space:nowrap"><?= $day ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($periods as $period):
                $times = $timeSlots[$period] ?? ['—','—'];
            ?>
                <tr>
                    <!-- Period label -->
                    <td style="padding:10px 14px;background:var(--light);border:1px solid var(--border);
                               font-size:12px;font-weight:700;color:var(--text-muted);vertical-align:middle;
                               white-space:nowrap">
                        <div style="font-size:13px;font-weight:800;color:var(--text)">P<?= $period ?></div>
                        <div style="font-size:11px"><?= $times[0] ?>–<?= $times[1] ?></div>
                    </td>

                    <?php foreach ($days as $day):
                        $slot = $grid[$day][$period] ?? null;
                        $isBreak = $slot && $slot['is_break'];
                        $cellBg  = $isBreak ? '#F8FAFC' : ($slot ? $periodColors[($period-1)%count($periodColors)] : '#FFFFFF');
                    ?>
                    <td style="padding:0;border:1px solid var(--border);vertical-align:middle;
                               background:<?= $cellBg ?>;
                               <?= $isAdmin?'cursor:pointer':'' ?>"
                        <?php if ($isAdmin): ?>
                        onclick="openSlotEditor('<?= $day ?>', <?= $period ?>, <?= $class_id ?>,
                            <?= json_encode($slot) ?>)"
                        onmouseover="this.style.outline='2px solid var(--navy)'"
                        onmouseout="this.style.outline=''"
                        <?php endif; ?>
                        id="cell_<?= $day ?>_<?= $period ?>">

                        <?php if ($slot): ?>
                            <?php if ($isBreak): ?>
                                <div style="padding:10px 12px;text-align:center;
                                            color:var(--text-muted);font-size:12px;font-weight:700">
                                    🍎 <?= e($slot['label'] ?? 'Break') ?>
                                </div>
                            <?php else: ?>
                                <div style="padding:10px 12px">
                                    <div style="font-weight:800;font-size:13px;color:var(--text);margin-bottom:2px">
                                        <?= e($slot['subject_name'] ?? '—') ?>
                                    </div>
                                    <div style="font-size:11px;color:var(--text-muted)">
                                        👤 <?= e($slot['teacher_name'] ?? 'TBD') ?>
                                    </div>
                                    <?php if ($slot['start_time']): ?>
                                        <div style="font-size:10px;color:var(--text-light);margin-top:2px">
                                            <?= substr($slot['start_time'],0,5) ?>–<?= substr($slot['end_time'],0,5) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div style="padding:16px;text-align:center;color:var(--text-light);font-size:12px">
                                <?= $isAdmin ? '+ Add' : '—' ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Slot Editor Modal (Admin only) ── -->
<?php if ($isAdmin): ?>
<div class="modal-backdrop" id="slotEditorModal" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title" id="slotModalTitle">Edit Timetable Slot</span>
            <button class="modal-close" data-modal-close>×</button>
        </div>
        <div class="modal-body">
            <div style="background:var(--light);border-radius:8px;padding:10px 14px;margin-bottom:16px;
                        font-size:13px;font-weight:700" id="slotInfo"></div>

            <!-- Is Break -->
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:700">
                    <input type="checkbox" id="slotIsBreak" onchange="toggleBreakFields()">
                    Mark as Break / Free Period
                </label>
            </div>

            <!-- Break fields -->
            <div id="breakFields" style="display:none">
                <div class="form-group">
                    <label class="form-label">Label</label>
                    <input type="text" id="slotLabel" class="form-control" placeholder="e.g. Break, Lunch, Assembly">
                </div>
            </div>

            <!-- Subject/Teacher fields -->
            <div id="subjectFields">
                <div class="form-group">
                    <label class="form-label">Subject</label>
                    <select id="slotSubject" class="form-control">
                        <option value="">Select subject…</option>
                        <?php foreach ($subjects as $sub): ?>
                            <option value="<?= $sub['id'] ?>"><?= e($sub['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Teacher</label>
                    <select id="slotTeacher" class="form-control">
                        <option value="">Select teacher…</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?= $t['id'] ?>"><?= e($t['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Start Time</label>
                        <input type="time" id="slotStart" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">End Time</label>
                        <input type="time" id="slotEnd" class="form-control">
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-danger btn-sm" onclick="clearSlot()">🗑️ Clear Slot</button>
            <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
            <button type="button" class="btn btn-primary" onclick="saveSlot()">💾 Save Slot</button>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
@media print {
    .sidebar, .topnav, .page-header, .card:first-of-type,
    .flash-container, .modal-backdrop, button, a.btn { display:none !important; }
    .main-area { margin:0 !important; }
    #ttCard { box-shadow:none; border:none; }
}
</style>

<script>
let currentDay, currentPeriod, currentClassId;

function openSlotEditor(day, period, classId, slot) {
    currentDay     = day;
    currentPeriod  = period;
    currentClassId = classId;

    document.getElementById('slotModalTitle').textContent = `Edit: ${day}, Period ${period}`;
    document.getElementById('slotInfo').textContent       = `${day} — Period ${period} — Class ID ${classId}`;

    const isBreak = slot && slot.is_break == '1';
    document.getElementById('slotIsBreak').checked = isBreak;
    document.getElementById('slotLabel').value     = slot?.label || '';
    document.getElementById('slotSubject').value   = slot?.subject_id || '';
    document.getElementById('slotTeacher').value   = slot?.teacher_id || '';
    document.getElementById('slotStart').value     = slot?.start_time?.slice(0,5) || '';
    document.getElementById('slotEnd').value       = slot?.end_time?.slice(0,5)   || '';

    toggleBreakFields();
    openModal('slotEditorModal');
}

function toggleBreakFields() {
    const isBreak = document.getElementById('slotIsBreak').checked;
    document.getElementById('breakFields').style.display   = isBreak ? '' : 'none';
    document.getElementById('subjectFields').style.display = isBreak ? 'none' : '';
}

async function saveSlot() {
    const isBreak = document.getElementById('slotIsBreak').checked;
    const body = new FormData();
    body.append('action',     'save_slot');
    body.append('class_id',   currentClassId);
    body.append('day',        currentDay);
    body.append('period',     currentPeriod);
    body.append('is_break',   isBreak ? '1' : '0');
    body.append('label',      document.getElementById('slotLabel').value);
    body.append('subject_id', document.getElementById('slotSubject').value);
    body.append('teacher_id', document.getElementById('slotTeacher').value);
    body.append('start_time', document.getElementById('slotStart').value);
    body.append('end_time',   document.getElementById('slotEnd').value);
    body.append('<?= CSRF_TOKEN_NAME ?>', '<?= csrf_token() ?>');

    try {
        const res  = await postForm('<?= BASE_URL ?>/admin/timetable.php', body);
        if (res.success) {
            showToast('Slot saved!', 'success');
            closeModal(document.getElementById('slotEditorModal'));
            setTimeout(() => location.reload(), 600);
        } else {
            showToast(res.message || 'Error saving slot.', 'error');
        }
    } catch (e) {
        showToast('Network error.', 'error');
    }
}

async function clearSlot() {
    if (!confirm('Clear this slot?')) return;
    const body = new FormData();
    body.append('action',   'clear_slot');
    body.append('class_id', currentClassId);
    body.append('day',      currentDay);
    body.append('period',   currentPeriod);
    body.append('<?= CSRF_TOKEN_NAME ?>', '<?= csrf_token() ?>');

    const res = await postForm('<?= BASE_URL ?>/admin/timetable.php', body);
    if (res.success) {
        showToast('Slot cleared.', 'success');
        closeModal(document.getElementById('slotEditorModal'));
        setTimeout(() => location.reload(), 400);
    }
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
