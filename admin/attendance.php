<?php
// ============================================================
// admin/attendance.php — Attendance Management (Admin View)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role([ROLE_ADMIN, ROLE_TEACHER]);

$pageTitle  = 'Attendance Management';
$activeMenu = 'attendance';

$sess_id = current_session_id();
$term_id = current_term_id();

// ── Handle bulk attendance submission ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'mark_bulk') {
        $date       = sanitize($_POST['att_date']  ?? date('Y-m-d'));
        $class_id   = int_val($_POST['class_id']   ?? 0);
        $attendance = $_POST['attendance']          ?? [];

        if (!$class_id || !$date) {
            flash_error('Please select a class and date.');
        } else {
            $saved = 0;
            foreach ($attendance as $studentId => $status) {
                $studentId = (int)$studentId;
                $status    = in_array($status, ['present','absent','late']) ? $status : 'present';
                $note      = sanitize($_POST['notes'][$studentId] ?? '');

                // Upsert attendance record
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
                        "INSERT INTO attendance (student_id, class_id, term_id, date, status, note, marked_by)
                         VALUES (?,?,?,?,?,?,?)",
                        [$studentId, $class_id, $term_id, $date, $status, $note ?: null, current_user_id()]
                    );
                }
                $saved++;
            }
            audit_log(current_user_id(), current_username(), 'mark_attendance', 'Attendance',
                "Marked attendance for class ID {$class_id} on {$date} — {$saved} students");
            flash_success("Attendance marked for {$saved} student(s) on " . date('d M Y', strtotime($date)) . ".");
        }
        redirect(BASE_URL . '/admin/attendance.php?class_id=' . $class_id . '&att_date=' . $date);
    }

    if ($action === 'delete') {
        $att_id = int_val($_POST['att_id'] ?? 0);
        if ($att_id) {
            Database::execute("DELETE FROM attendance WHERE id=?", [$att_id]);
            audit_log(current_user_id(), current_username(), 'delete_attendance', 'Attendance',
                "Deleted attendance record ID {$att_id}");
            flash_success('Attendance record deleted.');
        }
        redirect(BASE_URL . '/admin/attendance.php?' . http_build_query($_GET));
    }
}

// ── Filters ───────────────────────────────────────────────────
$att_date   = sanitize($_GET['att_date']  ?? date('Y-m-d'));
$class_id   = int_val($_GET['class_id']   ?? 0);
$filterSts  = sanitize($_GET['status']    ?? '');
$viewMode   = sanitize($_GET['view']      ?? 'mark'); // mark | history | summary
$page       = int_val($_GET['page']       ?? 1);

// Classes for dropdown
$classes = Database::fetchAll("SELECT id,name FROM classes ORDER BY sort_order");

// Students in selected class for marking
$students = [];
$alreadyMarked = false;
if ($class_id) {
    $students = Database::fetchAll(
        "SELECT s.id, s.full_name, s.student_id,
                a.id AS att_id, a.status AS att_status, a.note AS att_note
         FROM students s
         LEFT JOIN attendance a ON a.student_id = s.id AND a.date = ? AND a.class_id = ?
         WHERE s.class_id = ? AND s.session_id = ? AND s.status = 'active'
         ORDER BY s.full_name",
        [$att_date, $class_id, $class_id, $sess_id]
    );
    $alreadyMarked = !empty(array_filter($students, fn($s) => $s['att_id']));
}

// ── Attendance history (for history view) ─────────────────────
$historyWhere  = ['a.term_id = ?'];
$historyParams = [$term_id];
if ($class_id)  { $historyWhere[] = 'a.class_id = ?'; $historyParams[] = $class_id; }
if ($filterSts) { $historyWhere[] = 'a.status = ?';   $historyParams[] = $filterSts; }
if ($att_date && $viewMode === 'history') {
    $historyWhere[] = 'a.date = ?';
    $historyParams[] = $att_date;
}
$historyWhereStr = 'WHERE ' . implode(' AND ', $historyWhere);
$historySql = "SELECT a.*, s.full_name, s.student_id AS sid, c.name AS class_name,
                      u.full_name AS marked_by_name
               FROM attendance a
               JOIN students s ON s.id = a.student_id
               JOIN classes  c ON c.id = a.class_id
               LEFT JOIN users u ON u.id = a.marked_by
               {$historyWhereStr}
               ORDER BY a.date DESC, s.full_name";
$pager   = paginate($historySql, $historyParams, $page);
$history = $pager['rows'];

// ── Summary stats for today / selected class ──────────────────
$todayStats = Database::fetchOne(
    "SELECT
        COUNT(*) AS total,
        SUM(status='present') AS present_count,
        SUM(status='absent')  AS absent_count,
        SUM(status='late')    AS late_count
     FROM attendance
     WHERE date = ? AND term_id = ?" . ($class_id ? " AND class_id = {$class_id}" : ""),
    array_filter([$att_date, $term_id])
);

// ── Monthly summary per class ─────────────────────────────────
$monthlySummary = Database::fetchAll(
    "SELECT c.name AS class_name,
            COUNT(DISTINCT a.date) AS days_recorded,
            SUM(a.status='present') AS present_total,
            SUM(a.status='absent')  AS absent_total,
            SUM(a.status='late')    AS late_total,
            COUNT(*) AS total_records
     FROM attendance a
     JOIN classes c ON c.id = a.class_id
     WHERE a.term_id = ?
     GROUP BY c.id ORDER BY c.sort_order",
    [$term_id]
);

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📅 Attendance Management</h1>
        <p class="page-subtitle">
            <?= e(get_setting('current_term')) ?> Term &nbsp;|&nbsp;
            Today: <?= date('l, d F Y') ?>
        </p>
    </div>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>/api/get_attendance.php?export=csv&term_id=<?= $term_id ?>&class_id=<?= $class_id ?>"
           class="btn btn-outline">📤 Export CSV</a>
    </div>
</div>

<!-- Today's overview stats -->
<div class="stats-grid mb-20" style="grid-template-columns:repeat(4,1fr)">
    <div class="stat-card stat-green">
        <div class="stat-icon">✅</div>
        <div class="stat-info">
            <div class="stat-value"><?= $todayStats['present_count'] ?? 0 ?></div>
            <div class="stat-label">Present Today</div>
        </div>
    </div>
    <div class="stat-card stat-red">
        <div class="stat-icon">❌</div>
        <div class="stat-info">
            <div class="stat-value"><?= $todayStats['absent_count'] ?? 0 ?></div>
            <div class="stat-label">Absent Today</div>
        </div>
    </div>
    <div class="stat-card stat-gold">
        <div class="stat-icon">⏰</div>
        <div class="stat-info">
            <div class="stat-value"><?= $todayStats['late_count'] ?? 0 ?></div>
            <div class="stat-label">Late Today</div>
        </div>
    </div>
    <div class="stat-card stat-blue">
        <div class="stat-icon">📊</div>
        <div class="stat-info">
            <?php
            $t = (int)($todayStats['total'] ?? 0);
            $p = (int)($todayStats['present_count'] ?? 0);
            $rate = $t > 0 ? round(($p/$t)*100) : 0;
            ?>
            <div class="stat-value"><?= $rate ?>%</div>
            <div class="stat-label">Attendance Rate</div>
        </div>
    </div>
</div>

<!-- View mode tabs -->
<div class="tabs mb-20">
    <?php foreach (['mark'=>'✏️ Mark Attendance','history'=>'📋 History','summary'=>'📊 Summary'] as $mode => $label): ?>
        <a href="?view=<?= $mode ?>&class_id=<?= $class_id ?>&att_date=<?= $att_date ?>"
           class="tab-btn <?= $viewMode===$mode?'active':'' ?>"
           style="text-decoration:none"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<?php if ($viewMode === 'mark'): ?>
<!-- ══════════════════════════════════════════════════════
     MARK ATTENDANCE VIEW
══════════════════════════════════════════════════════ -->

<div class="card mb-20">
    <div class="card-header">⚙️ Select Class & Date</div>
    <div class="card-body">
        <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="view" value="mark">
            <div class="form-group" style="margin:0;flex:1;min-width:160px">
                <label class="form-label">Class</label>
                <select name="class_id" class="form-control" onchange="this.form.submit()">
                    <option value="">Select class…</option>
                    <?php foreach ($classes as $cls): ?>
                        <option value="<?= $cls['id'] ?>" <?= $class_id==$cls['id']?'selected':'' ?>>
                            <?= e($cls['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;flex:1;min-width:180px">
                <label class="form-label">Date</label>
                <input type="date" name="att_date" class="form-control"
                       value="<?= e($att_date) ?>" max="<?= date('Y-m-d') ?>">
            </div>
            <div style="padding-bottom:16px">
                <button type="submit" class="btn btn-primary">Load Students →</button>
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
            <div>
                📋 Marking Attendance: <?= e(Database::fetchOne("SELECT name FROM classes WHERE id=?",[$class_id])['name'] ?? '') ?>
                &nbsp;—&nbsp; <?= date('l, d F Y', strtotime($att_date)) ?>
                <?php if ($alreadyMarked): ?>
                    <span class="badge badge-warning" style="margin-left:8px">⚠️ Already marked — updating</span>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:8px">
                <button type="button" class="btn btn-sm btn-success" onclick="markAll('present')">✅ All Present</button>
                <button type="button" class="btn btn-sm btn-danger"  onclick="markAll('absent')">❌ All Absent</button>
                <button type="submit" class="btn btn-primary">💾 Save Attendance</button>
            </div>
        </div>

        <!-- Live stats bar -->
        <div style="padding:10px 20px;background:var(--light);border-bottom:1px solid var(--border);
                    display:flex;gap:20px;align-items:center" id="liveStats">
            <span style="font-size:13px;font-weight:700">Live count:</span>
            <span class="badge badge-success">✅ Present: <span id="cntPresent">0</span></span>
            <span class="badge badge-danger">❌ Absent: <span id="cntAbsent">0</span></span>
            <span class="badge badge-warning">⏰ Late: <span id="cntLate">0</span></span>
            <span class="text-sm text-muted">of <?= count($students) ?> students</span>
        </div>

        <div class="table-wrap">
            <table class="data-table" id="attTable">
                <thead><tr>
                    <th>#</th>
                    <th data-sort>Student Name</th>
                    <th>ID</th>
                    <th style="text-align:center">✅ Present</th>
                    <th style="text-align:center">❌ Absent</th>
                    <th style="text-align:center">⏰ Late</th>
                    <th>Note</th>
                </tr></thead>
                <tbody>
                <?php foreach ($students as $i => $s):
                    $currentStatus = $s['att_status'] ?? 'present';
                ?>
                    <tr id="attrow_<?= $s['id'] ?>"
                        style="<?= $currentStatus==='absent'?'background:#FEF2F2':($currentStatus==='late'?'background:#FFFBEB':'') ?>">
                        <td class="text-muted text-sm"><?= $i+1 ?></td>
                        <td><strong><?= e($s['full_name']) ?></strong></td>
                        <td><span class="code"><?= e($s['student_id']) ?></span></td>
                        <?php foreach (['present','absent','late'] as $statusOpt): ?>
                        <td style="text-align:center">
                            <input type="radio"
                                   name="attendance[<?= $s['id'] ?>]"
                                   value="<?= $statusOpt ?>"
                                   id="att_<?= $s['id'] ?>_<?= $statusOpt ?>"
                                   <?= $currentStatus===$statusOpt ? 'checked' : '' ?>
                                   onchange="updateRow(<?= $s['id'] ?>, '<?= $statusOpt ?>')"
                                   style="width:18px;height:18px;cursor:pointer">
                        </td>
                        <?php endforeach; ?>
                        <td>
                            <input type="text" name="notes[<?= $s['id'] ?>]"
                                   class="form-control" style="width:160px;padding:5px 8px;font-size:12px"
                                   placeholder="Optional note…"
                                   value="<?= e($s['att_note'] ?? '') ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center">
            <div class="text-sm text-muted">
                Default is <strong>Present</strong>. Change individual students as needed.
            </div>
            <button type="submit" class="btn btn-primary btn-lg">💾 Save All (<?= count($students) ?> students)</button>
        </div>
    </div>
</form>

<?php elseif ($class_id): ?>
<div class="card"><div class="card-body table-empty">No active students in this class.</div></div>
<?php else: ?>
<div class="card"><div class="card-body table-empty">
    <div class="table-empty-icon">📅</div>
    Select a class and date above to start marking attendance.
</div></div>
<?php endif; ?>

<?php elseif ($viewMode === 'history'): ?>
<!-- ══════════════════════════════════════════════════════
     HISTORY VIEW
══════════════════════════════════════════════════════ -->

<div class="card">
    <div class="table-toolbar">
        <form method="GET" style="display:contents">
            <input type="hidden" name="view" value="history">
            <div class="search-bar-wrap">
                <span class="search-icon">🔍</span>
                <input type="text" name="q" class="search-input" placeholder="Search student…"
                       value="<?= e($_GET['q'] ?? '') ?>">
            </div>
            <input type="date" name="att_date" class="filter-select" value="<?= e($att_date) ?>">
            <select name="class_id" class="filter-select" onchange="this.form.submit()">
                <option value="">All Classes</option>
                <?php foreach ($classes as $cls): ?>
                    <option value="<?= $cls['id'] ?>" <?= $class_id==$cls['id']?'selected':'' ?>><?= e($cls['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="filter-select" onchange="this.form.submit()">
                <option value="">All Status</option>
                <option value="present" <?= $filterSts==='present'?'selected':'' ?>>Present</option>
                <option value="absent"  <?= $filterSts==='absent' ?'selected':'' ?>>Absent</option>
                <option value="late"    <?= $filterSts==='late'   ?'selected':'' ?>>Late</option>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
            <div class="table-toolbar-right">
                <a href="?view=history&class_id=<?= $class_id ?>" class="btn btn-outline btn-sm">↺ Reset</a>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>#</th>
                <th data-sort>Date</th>
                <th data-sort>Student</th>
                <th>Class</th>
                <th>Status</th>
                <th>Note</th>
                <th>Marked By</th>
                <th>Actions</th>
            </tr></thead>
            <tbody>
            <?php if ($history): $i=($page-1)*ROWS_PER_PAGE+1; foreach ($history as $r): ?>
                <tr style="<?= $r['status']==='absent'?'background:#FEF2F2':($r['status']==='late'?'background:#FFFBEB':'') ?>">
                    <td class="text-muted text-sm"><?= $i++ ?></td>
                    <td><strong><?= format_date($r['date'], 'd M Y') ?></strong>
                        <div class="text-xs text-muted"><?= date('l', strtotime($r['date'])) ?></div>
                    </td>
                    <td>
                        <div style="font-weight:700"><?= e($r['full_name']) ?></div>
                        <div class="text-xs text-muted"><?= e($r['sid']) ?></div>
                    </td>
                    <td><span class="badge badge-navy"><?= e($r['class_name']) ?></span></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="text-sm text-muted"><?= e($r['note'] ?? '—') ?></td>
                    <td class="text-sm"><?= e($r['marked_by_name'] ?? '—') ?></td>
                    <td>
                        <form method="POST" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="att_id" value="<?= $r['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger"
                                    data-confirm="Delete this attendance record?">🗑️</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="8" class="table-empty">No attendance records found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= pagination_links($pager, BASE_URL . '/admin/attendance.php?view=history&class_id=' . $class_id . '&status=' . $filterSts) ?>
    <div class="card-footer text-sm text-muted">
        Showing <?= count($history) ?> of <?= $pager['total'] ?> records
    </div>
</div>

<?php elseif ($viewMode === 'summary'): ?>
<!-- ══════════════════════════════════════════════════════
     SUMMARY VIEW
══════════════════════════════════════════════════════ -->

<div class="card mb-20">
    <div class="card-header">📊 Term Attendance Summary by Class</div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>Class</th>
                <th>Days Recorded</th>
                <th>Total Present</th>
                <th>Total Absent</th>
                <th>Total Late</th>
                <th>Total Records</th>
                <th>Attendance Rate</th>
            </tr></thead>
            <tbody>
            <?php foreach ($monthlySummary as $row):
                $rate = $row['total_records'] > 0
                    ? round(($row['present_total'] / $row['total_records']) * 100) : 0;
                $barCol = $rate >= 80 ? 'var(--emerald)' : ($rate >= 60 ? 'var(--accent)' : 'var(--red)');
            ?>
                <tr>
                    <td><span style="font-weight:800"><?= e($row['class_name']) ?></span></td>
                    <td><?= $row['days_recorded'] ?></td>
                    <td style="color:var(--emerald);font-weight:700"><?= $row['present_total'] ?></td>
                    <td style="color:var(--red);font-weight:700"><?= $row['absent_total'] ?></td>
                    <td style="color:var(--accent);font-weight:700"><?= $row['late_total'] ?></td>
                    <td><?= $row['total_records'] ?></td>
                    <td style="min-width:160px">
                        <div style="display:flex;align-items:center;gap:8px">
                            <div style="flex:1;background:var(--light);border-radius:6px;height:8px;overflow:hidden">
                                <div style="height:100%;width:<?= $rate ?>%;background:<?= $barCol ?>;border-radius:6px"></div>
                            </div>
                            <span style="font-weight:700;font-size:13px;color:<?= $barCol ?>"><?= $rate ?>%</span>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($monthlySummary)): ?>
                <tr><td colspan="7" class="table-empty">No attendance recorded this term.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Per-student summary for selected class -->
<?php if ($class_id): ?>
<div class="card">
    <div class="card-header">
        👨‍🎓 Per-Student Summary —
        <?= e(Database::fetchOne("SELECT name FROM classes WHERE id=?",[$class_id])['name'] ?? '') ?>
    </div>
    <div class="table-wrap">
        <?php
        $studentSummary = Database::fetchAll(
            "SELECT s.full_name, s.student_id,
                    COUNT(*) AS total_days,
                    SUM(a.status='present') AS present_days,
                    SUM(a.status='absent')  AS absent_days,
                    SUM(a.status='late')    AS late_days
             FROM students s
             LEFT JOIN attendance a ON a.student_id=s.id AND a.term_id=? AND a.class_id=?
             WHERE s.class_id=? AND s.session_id=? AND s.status='active'
             GROUP BY s.id ORDER BY s.full_name",
            [$term_id, $class_id, $class_id, $sess_id]
        );
        ?>
        <table class="data-table">
            <thead><tr>
                <th data-sort>Student</th>
                <th>Days Recorded</th>
                <th>Present</th>
                <th>Absent</th>
                <th>Late</th>
                <th>Rate</th>
            </tr></thead>
            <tbody>
            <?php foreach ($studentSummary as $ss):
                $rate = $ss['total_days'] > 0 ? round(($ss['present_days']/$ss['total_days'])*100) : 0;
                $rateCol = $rate >= 80?'var(--emerald)':($rate>=60?'var(--accent)':'var(--red)');
            ?>
                <tr>
                    <td>
                        <div style="font-weight:700"><?= e($ss['full_name']) ?></div>
                        <div class="text-xs text-muted"><?= e($ss['student_id']) ?></div>
                    </td>
                    <td><?= $ss['total_days'] ?></td>
                    <td style="color:var(--emerald);font-weight:700"><?= $ss['present_days'] ?></td>
                    <td style="color:var(--red);font-weight:700"><?= $ss['absent_days'] ?></td>
                    <td style="color:var(--accent);font-weight:700"><?= $ss['late_days'] ?></td>
                    <td style="min-width:140px">
                        <div style="display:flex;align-items:center;gap:8px">
                            <div style="flex:1;background:var(--light);border-radius:6px;height:8px;overflow:hidden">
                                <div style="height:100%;width:<?= $rate ?>%;background:<?= $rateCol ?>;border-radius:6px"></div>
                            </div>
                            <span style="font-weight:700;font-size:13px;color:<?= $rateCol ?>"><?= $rate ?>%</span>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<script>
// Live counter update
function updateLiveStats() {
    let present = 0, absent = 0, late = 0;
    document.querySelectorAll('input[type=radio]:checked').forEach(r => {
        if (r.value === 'present') present++;
        else if (r.value === 'absent') absent++;
        else if (r.value === 'late') late++;
    });
    const cP = document.getElementById('cntPresent');
    const cA = document.getElementById('cntAbsent');
    const cL = document.getElementById('cntLate');
    if (cP) cP.textContent = present;
    if (cA) cA.textContent = absent;
    if (cL) cL.textContent = late;
}

// Update row highlight and count
function updateRow(studentId, status) {
    const row = document.getElementById('attrow_' + studentId);
    if (!row) return;
    row.style.background = status === 'absent' ? '#FEF2F2'
                         : status === 'late'   ? '#FFFBEB' : '';
    updateLiveStats();
}

// Mark all students with one status
function markAll(status) {
    document.querySelectorAll('input[type=radio][value="' + status + '"]').forEach(radio => {
        radio.checked = true;
        const id = radio.name.match(/\[(\d+)\]/)?.[1];
        if (id) updateRow(parseInt(id), status);
    });
    updateLiveStats();
}

// Init counters on page load
document.addEventListener('DOMContentLoaded', updateLiveStats);
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
