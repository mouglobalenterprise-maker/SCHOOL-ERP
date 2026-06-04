<?php
// ============================================================
// teacher/attendance.php — Teacher Attendance Portal
// Teachers only see classes they are assigned to
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_TEACHER);

$pageTitle  = 'Mark Attendance';
$activeMenu = 'attendance';

$sess_id = current_session_id();
$term_id = current_term_id();

// Get teacher's assigned classes
$myTeacher = Database::fetchOne(
    "SELECT t.id FROM teachers t JOIN users u ON u.id=t.user_id WHERE u.id=?",
    [current_user_id()]
);
if (!$myTeacher) {
    flash_error('No teacher profile found. Contact the administrator.');
    redirect(BASE_URL . '/teacher/dashboard.php');
}

$myClasses = Database::fetchAll(
    "SELECT DISTINCT c.id, c.name
     FROM teacher_subjects ts JOIN classes c ON c.id=ts.class_id
     WHERE ts.teacher_id=? ORDER BY c.sort_order",
    [$myTeacher['id']]
);

// Handle POST — delegate to admin attendance logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
    $action   = sanitize($_POST['action'] ?? '');
    $date     = sanitize($_POST['att_date'] ?? date('Y-m-d'));
    $class_id = int_val($_POST['class_id'] ?? 0);

    // Verify teacher is assigned to this class
    $isAssigned = false;
    foreach ($myClasses as $mc) {
        if ($mc['id'] == $class_id) { $isAssigned = true; break; }
    }

    if (!$isAssigned) {
        flash_error('You are not assigned to this class.');
        redirect(BASE_URL . '/teacher/attendance.php');
    }

    if ($action === 'mark_bulk') {
        $attendance = $_POST['attendance'] ?? [];
        $saved = 0;
        foreach ($attendance as $studentId => $status) {
            $studentId = (int)$studentId;
            $status    = in_array($status,['present','absent','late']) ? $status : 'present';
            $note      = sanitize($_POST['notes'][$studentId] ?? '');

            $existing = Database::fetchOne(
                "SELECT id FROM attendance WHERE student_id=? AND date=?",
                [$studentId, $date]
            );
            if ($existing) {
                Database::execute(
                    "UPDATE attendance SET status=?, note=?, marked_by=? WHERE id=?",
                    [$status, $note ?: null, current_user_id(), $existing['id']]
                );
            } else {
                Database::insert(
                    "INSERT INTO attendance (student_id,class_id,term_id,date,status,note,marked_by)
                     VALUES (?,?,?,?,?,?,?)",
                    [$studentId, $class_id, $term_id, $date, $status, $note ?: null, current_user_id()]
                );
            }
            $saved++;
        }
        audit_log(current_user_id(), current_username(), 'mark_attendance', 'Attendance',
            "Teacher marked attendance for class ID {$class_id} on {$date}");
        flash_success("Attendance saved for {$saved} student(s) on " . date('d M Y', strtotime($date)) . ".");
        redirect(BASE_URL . '/teacher/attendance.php?class_id=' . $class_id . '&att_date=' . $date);
    }
}

$class_id = int_val($_GET['class_id'] ?? ($myClasses[0]['id'] ?? 0));
$att_date = sanitize($_GET['att_date'] ?? date('Y-m-d'));

// Load students for selected class
$students = [];
if ($class_id) {
    $students = Database::fetchAll(
        "SELECT s.id, s.full_name, s.student_id,
                a.id AS att_id, a.status AS att_status, a.note AS att_note
         FROM students s
         LEFT JOIN attendance a ON a.student_id=s.id AND a.date=? AND a.class_id=?
         WHERE s.class_id=? AND s.session_id=? AND s.status='active'
         ORDER BY s.full_name",
        [$att_date, $class_id, $class_id, $sess_id]
    );
}
$alreadyMarked = !empty(array_filter($students, fn($s) => $s['att_id']));

// Recent attendance I marked
$recentlyMarked = Database::fetchAll(
    "SELECT DISTINCT a.date, c.name AS class_name,
            SUM(a.status='present') AS pres,
            SUM(a.status='absent')  AS abs,
            SUM(a.status='late')    AS late,
            COUNT(*) AS total
     FROM attendance a JOIN classes c ON c.id=a.class_id
     WHERE a.marked_by=? AND a.term_id=?
     GROUP BY a.date, a.class_id
     ORDER BY a.date DESC LIMIT 7",
    [current_user_id(), $term_id]
);

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📅 Mark Attendance</h1>
        <p class="page-subtitle">
            <?= e(get_setting('current_term')) ?> Term &nbsp;|&nbsp; <?= date('l, d F Y') ?>
        </p>
    </div>
</div>

<div class="grid-2" style="gap:20px;align-items:start">
    <!-- Left: Marking form -->
    <div>
        <div class="card mb-20">
            <div class="card-header">⚙️ Select Class & Date</div>
            <div class="card-body">
                <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
                    <div class="form-group" style="margin:0;flex:1">
                        <label class="form-label">My Classes</label>
                        <select name="class_id" class="form-control" onchange="this.form.submit()">
                            <option value="">Select class…</option>
                            <?php foreach ($myClasses as $cls): ?>
                                <option value="<?= $cls['id'] ?>" <?= $class_id==$cls['id']?'selected':'' ?>>
                                    <?= e($cls['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;flex:1">
                        <label class="form-label">Date</label>
                        <input type="date" name="att_date" class="form-control"
                               value="<?= e($att_date) ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div style="padding-bottom:16px">
                        <button type="submit" class="btn btn-primary">Load →</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($class_id && $students): ?>
        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action"   value="mark_bulk">
            <input type="hidden" name="class_id" value="<?= $class_id ?>">
            <input type="hidden" name="att_date" value="<?= e($att_date) ?>">

            <div class="card">
                <div class="card-header">
                    <span>
                        <?= e(Database::fetchOne("SELECT name FROM classes WHERE id=?",[$class_id])['name'] ?? '') ?>
                        &nbsp;—&nbsp; <?= date('d M Y', strtotime($att_date)) ?>
                        <?php if ($alreadyMarked): ?>
                            <span class="badge badge-warning" style="margin-left:6px">Already marked</span>
                        <?php endif; ?>
                    </span>
                    <div style="display:flex;gap:6px">
                        <button type="button" class="btn btn-sm btn-success" onclick="markAll('present')">✅ All Present</button>
                        <button type="submit" class="btn btn-primary btn-sm">💾 Save</button>
                    </div>
                </div>

                <!-- Live count -->
                <div style="padding:8px 16px;background:var(--light);border-bottom:1px solid var(--border);
                            display:flex;gap:16px;font-size:13px">
                    <span class="badge badge-success">✅ <span id="cntPresent">0</span></span>
                    <span class="badge badge-danger">❌ <span id="cntAbsent">0</span></span>
                    <span class="badge badge-warning">⏰ <span id="cntLate">0</span></span>
                    <span class="text-muted">of <?= count($students) ?></span>
                </div>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr>
                            <th>#</th>
                            <th>Student</th>
                            <th style="text-align:center">✅</th>
                            <th style="text-align:center">❌</th>
                            <th style="text-align:center">⏰</th>
                            <th>Note</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($students as $i => $s):
                            $cs = $s['att_status'] ?? 'present';
                        ?>
                            <tr id="attrow_<?= $s['id'] ?>"
                                style="<?= $cs==='absent'?'background:#FEF2F2':($cs==='late'?'background:#FFFBEB':'') ?>">
                                <td class="text-muted text-sm"><?= $i+1 ?></td>
                                <td>
                                    <div style="font-weight:700"><?= e($s['full_name']) ?></div>
                                    <div class="text-xs text-muted"><?= e($s['student_id']) ?></div>
                                </td>
                                <?php foreach (['present','absent','late'] as $st): ?>
                                <td style="text-align:center">
                                    <input type="radio" name="attendance[<?= $s['id'] ?>]" value="<?= $st ?>"
                                           <?= $cs===$st?'checked':'' ?>
                                           style="width:18px;height:18px;cursor:pointer"
                                           onchange="updateRow(<?= $s['id'] ?>,'<?= $st ?>','<?= $st ==='present'?'':($st==='absent'?'#FEF2F2':'#FFFBEB') ?>')">
                                </td>
                                <?php endforeach; ?>
                                <td>
                                    <input type="text" name="notes[<?= $s['id'] ?>]"
                                           class="form-control" style="width:140px;padding:5px 8px;font-size:12px"
                                           placeholder="Note…" value="<?= e($s['att_note'] ?? '') ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary btn-lg">💾 Save Attendance</button>
                </div>
            </div>
        </form>
        <?php elseif ($class_id): ?>
        <div class="card"><div class="card-body table-empty">No students in this class.</div></div>
        <?php else: ?>
        <div class="card"><div class="card-body table-empty">
            <div class="table-empty-icon">📅</div>
            Select one of your assigned classes above.
        </div></div>
        <?php endif; ?>
    </div>

    <!-- Right: Recent activity -->
    <div class="card">
        <div class="card-header">📋 My Recent Markings</div>
        <?php if ($recentlyMarked): ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr>
                    <th>Date</th><th>Class</th><th>✅</th><th>❌</th><th>⏰</th><th>Total</th>
                </tr></thead>
                <tbody>
                <?php foreach ($recentlyMarked as $rm): ?>
                    <tr>
                        <td>
                            <div style="font-weight:700"><?= format_date($rm['date'],'d M') ?></div>
                            <div class="text-xs text-muted"><?= date('l',strtotime($rm['date'])) ?></div>
                        </td>
                        <td><span class="badge badge-navy"><?= e($rm['class_name']) ?></span></td>
                        <td style="color:var(--emerald);font-weight:700"><?= $rm['pres'] ?></td>
                        <td style="color:var(--red);font-weight:700"><?= $rm['abs'] ?></td>
                        <td style="color:var(--accent);font-weight:700"><?= $rm['late'] ?></td>
                        <td class="text-muted"><?= $rm['total'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="card-body table-empty">No attendance marked yet this term.</div>
        <?php endif; ?>
    </div>
</div>

<script>
function updateRow(id, status, bg) {
    const row = document.getElementById('attrow_' + id);
    if (row) row.style.background = bg || '';
    updateLiveStats();
}
function updateLiveStats() {
    let p=0,a=0,l=0;
    document.querySelectorAll('input[type=radio]:checked').forEach(r => {
        if (r.value==='present') p++;
        else if (r.value==='absent') a++;
        else l++;
    });
    const cp=document.getElementById('cntPresent');
    const ca=document.getElementById('cntAbsent');
    const cl=document.getElementById('cntLate');
    if(cp) cp.textContent=p;
    if(ca) ca.textContent=a;
    if(cl) cl.textContent=l;
}
function markAll(status) {
    document.querySelectorAll('input[type=radio][value="'+status+'"]').forEach(r => {
        r.checked=true;
        const id=r.name.match(/\[(\d+)\]/)?.[1];
        if(id) {
            const bg=status==='absent'?'#FEF2F2':(status==='late'?'#FFFBEB':'');
            const row=document.getElementById('attrow_'+id);
            if(row) row.style.background=bg;
        }
    });
    updateLiveStats();
}
document.addEventListener('DOMContentLoaded', updateLiveStats);
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
