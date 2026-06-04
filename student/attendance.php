<?php
// ============================================================
// student/attendance.php — Student Attendance Portal (Read-Only)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_STUDENT);

$pageTitle  = 'My Attendance';
$activeMenu = 'attendance';

$sess_id = current_session_id();
$term_id = int_val($_GET['term_id'] ?? current_term_id());

// Get linked student record
$student = Database::fetchOne(
    "SELECT s.*, c.name AS class_name
     FROM students s JOIN classes c ON c.id=s.class_id
     WHERE s.user_id=? AND s.session_id=?",
    [current_user_id(), $sess_id]
);
if (!$student) {
    flash_error('No student profile linked to your account.');
    redirect(BASE_URL . '/student/dashboard.php');
}

// Term selector
$terms = Database::fetchAll(
    "SELECT t.* FROM terms t
     JOIN academic_sessions ses ON ses.id=t.session_id
     WHERE ses.is_current=1 ORDER BY t.id"
);

// Summary stats
$summary = Database::fetchOne(
    "SELECT COUNT(*) AS total,
            SUM(status='present') AS present,
            SUM(status='absent')  AS absent,
            SUM(status='late')    AS late
     FROM attendance
     WHERE student_id=? AND term_id=?",
    [$student['id'], $term_id]
);
$total       = (int)($summary['total']   ?? 0);
$present     = (int)($summary['present'] ?? 0);
$absent      = (int)($summary['absent']  ?? 0);
$late        = (int)($summary['late']    ?? 0);
$attRate     = $total > 0 ? round(($present / $total) * 100) : 0;

// Full attendance history
$page = int_val($_GET['page'] ?? 1);
$historySql = "SELECT a.*, a.status
               FROM attendance a
               WHERE a.student_id=? AND a.term_id=?
               ORDER BY a.date DESC";
$pager   = paginate($historySql, [$student['id'], $term_id], $page);
$records = $pager['rows'];

// Calendar data — all records this term as date=>status map
$calData = Database::fetchAll(
    "SELECT date, status FROM attendance WHERE student_id=? AND term_id=? ORDER BY date",
    [$student['id'], $term_id]
);
$calMap = [];
foreach ($calData as $cd) $calMap[$cd['date']] = $cd['status'];

// Absence streak (consecutive absences)
$absenceDates = array_keys(array_filter($calMap, fn($s) => $s === 'absent'));
rsort($absenceDates);
$streak = 0;
$prev   = null;
foreach ($absenceDates as $d) {
    if ($prev === null || (strtotime($prev) - strtotime($d)) === 86400) {
        $streak++;
        $prev = $d;
    } else break;
}

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📅 My Attendance</h1>
        <p class="page-subtitle">
            <?= e($student['full_name']) ?> &mdash; <?= e($student['class_name']) ?> &mdash;
            <?= e(get_setting('current_session')) ?>
        </p>
    </div>
</div>

<!-- Term tabs -->
<div class="tabs mb-20">
    <?php foreach ($terms as $term): ?>
        <a href="?term_id=<?= $term['id'] ?>"
           class="tab-btn <?= $term_id==$term['id']?'active':'' ?>"
           style="text-decoration:none">
            <?= e($term['name']) ?> Term
        </a>
    <?php endforeach; ?>
</div>

<!-- Summary cards -->
<div class="stats-grid mb-20" style="grid-template-columns:repeat(5,1fr)">
    <div class="stat-card stat-blue">
        <div class="stat-icon">📅</div>
        <div class="stat-info">
            <div class="stat-value"><?= $total ?></div>
            <div class="stat-label">Days Recorded</div>
        </div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-icon">✅</div>
        <div class="stat-info">
            <div class="stat-value"><?= $present ?></div>
            <div class="stat-label">Days Present</div>
        </div>
    </div>
    <div class="stat-card stat-red">
        <div class="stat-icon">❌</div>
        <div class="stat-info">
            <div class="stat-value"><?= $absent ?></div>
            <div class="stat-label">Days Absent</div>
        </div>
    </div>
    <div class="stat-card stat-gold">
        <div class="stat-icon">⏰</div>
        <div class="stat-info">
            <div class="stat-value"><?= $late ?></div>
            <div class="stat-label">Days Late</div>
        </div>
    </div>
    <div class="stat-card <?= $attRate>=80?'stat-green':($attRate>=60?'stat-orange':'stat-red') ?>">
        <div class="stat-icon">📊</div>
        <div class="stat-info">
            <div class="stat-value"><?= $attRate ?>%</div>
            <div class="stat-label">Attendance Rate</div>
        </div>
    </div>
</div>

<!-- Rate progress bar + alerts -->
<div class="card mb-20">
    <div class="card-body">
        <div style="display:flex;justify-content:space-between;margin-bottom:8px">
            <span style="font-weight:700">Attendance Rate — <?= e(Database::fetchOne("SELECT name FROM terms WHERE id=?",[$term_id])['name'] ?? '') ?> Term</span>
            <span style="font-weight:800;font-size:18px;color:<?= $attRate>=80?'var(--emerald)':($attRate>=60?'var(--accent)':'var(--red)') ?>"><?= $attRate ?>%</span>
        </div>
        <div class="progress" style="height:14px;margin-bottom:12px">
            <div class="progress-bar <?= $attRate>=80?'green':($attRate>=60?'orange':'red') ?>"
                 style="width:<?= $attRate ?>%"></div>
        </div>

        <?php if ($attRate < 75): ?>
        <div style="background:#FEE2E2;border-radius:8px;padding:12px 14px;border:1px solid #FECACA">
            <strong style="color:#991B1B">⚠️ Low Attendance Warning:</strong>
            <span style="color:#7F1D1D;font-size:13px">
                Your attendance rate is below 75%. This may affect your eligibility for exams.
                Please contact your class teacher.
            </span>
        </div>
        <?php elseif ($attRate >= 95): ?>
        <div style="background:var(--emerald-lt);border-radius:8px;padding:12px 14px;border:1px solid #A7F3D0">
            <strong style="color:#065F46">🏆 Excellent Attendance!</strong>
            <span style="color:#064E3B;font-size:13px">
                Keep it up! You have an outstanding attendance record this term.
            </span>
        </div>
        <?php endif; ?>

        <?php if ($streak >= 3): ?>
        <div style="background:#FEF3C7;border-radius:8px;padding:12px 14px;margin-top:8px;border:1px solid #FDE68A">
            <strong style="color:#92400E">⚠️ Consecutive Absences:</strong>
            <span style="color:#78350F;font-size:13px">
                You have been absent for <?= $streak ?> consecutive school day(s). Please provide a reason.
            </span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Calendar heatmap -->
<?php if (!empty($calMap)): ?>
<div class="card mb-20">
    <div class="card-header">📆 Attendance Calendar</div>
    <div class="card-body">
        <?php
        // Group by month
        $byMonth = [];
        foreach ($calMap as $date => $status) {
            $month = date('Y-m', strtotime($date));
            $byMonth[$month][$date] = $status;
        }
        foreach ($byMonth as $month => $days):
            $monthLabel = date('F Y', strtotime($month . '-01'));
        ?>
        <div style="margin-bottom:20px">
            <div style="font-weight:700;font-size:13px;margin-bottom:8px;color:var(--text-muted)"><?= $monthLabel ?></div>
            <div style="display:flex;flex-wrap:wrap;gap:4px">
                <?php foreach ($days as $date => $status):
                    $colors = ['present'=>'#10B981','absent'=>'#EF4444','late'=>'#F59E0B'];
                    $col    = $colors[$status] ?? '#E5E7EB';
                    $label  = ucfirst($status) . ' — ' . date('D, d M Y', strtotime($date));
                ?>
                <div title="<?= $label ?>" style="
                    width:28px;height:28px;border-radius:4px;
                    background:<?= $col ?>;
                    display:flex;align-items:center;justify-content:center;
                    font-size:10px;color:#fff;font-weight:700;cursor:default">
                    <?= date('d', strtotime($date)) ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Legend -->
        <div style="display:flex;gap:16px;margin-top:8px;padding-top:12px;border-top:1px solid var(--border)">
            <?php foreach (['present'=>['#10B981','Present'],'absent'=>['#EF4444','Absent'],'late'=>['#F59E0B','Late']] as $s=>[$col,$lbl]): ?>
            <div style="display:flex;align-items:center;gap:6px">
                <div style="width:14px;height:14px;border-radius:3px;background:<?= $col ?>"></div>
                <span class="text-sm"><?= $lbl ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Attendance history table -->
<div class="card">
    <div class="card-header">
        📋 Attendance History
        <span class="badge badge-primary"><?= $pager['total'] ?> records</span>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>#</th>
                <th data-sort>Date</th>
                <th>Day</th>
                <th>Status</th>
                <th>Note</th>
            </tr></thead>
            <tbody>
            <?php if ($records): $i=($page-1)*ROWS_PER_PAGE+1; foreach ($records as $r): ?>
                <tr style="<?= $r['status']==='absent'?'background:#FEF2F2':($r['status']==='late'?'background:#FFFBEB':'') ?>">
                    <td class="text-muted text-sm"><?= $i++ ?></td>
                    <td><strong><?= format_date($r['date'],'d M Y') ?></strong></td>
                    <td class="text-muted text-sm"><?= date('l', strtotime($r['date'])) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="text-sm text-muted"><?= e($r['note'] ?? '—') ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5" class="table-empty">
                    <div class="table-empty-icon">📅</div>
                    No attendance records found for this term.
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= pagination_links($pager, BASE_URL . '/student/attendance.php?term_id=' . $term_id) ?>
    <div class="card-footer text-sm text-muted">
        Showing <?= count($records) ?> of <?= $pager['total'] ?> records
    </div>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
