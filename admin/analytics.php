<?php
// ============================================================
// admin/analytics.php — Analytics Dashboard
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_ADMIN);

$pageTitle  = 'Analytics Dashboard';
$activeMenu = 'analytics';

$sess_id = current_session_id();
$term_id = current_term_id();

// ── Core stats ────────────────────────────────────────────────
$studentStats = Database::fetchOne(
    "SELECT COUNT(*) AS total,
            SUM(status='active')   AS active,
            SUM(status='inactive') AS inactive,
            SUM(gender='Male')     AS male,
            SUM(gender='Female')   AS female
     FROM students WHERE session_id=?", [$sess_id]
);

$teacherCount  = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM teachers WHERE status='active'")['c'] ?? 0);
$subjectCount  = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM subjects")['c'] ?? 0);
$classCount    = (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM classes")['c'] ?? 0);

// ── Students per class ────────────────────────────────────────
$studentsPerClass = Database::fetchAll(
    "SELECT c.name, COUNT(s.id) AS count
     FROM classes c LEFT JOIN students s ON s.class_id=c.id AND s.session_id=?
     GROUP BY c.id ORDER BY c.sort_order", [$sess_id]
);

// ── Grade distribution ────────────────────────────────────────
$gradeDistribution = Database::fetchAll(
    "SELECT grade, COUNT(*) AS count
     FROM results WHERE session_id=? AND term_id=?
     GROUP BY grade ORDER BY grade",
    [$sess_id, $term_id]
);

// ── Average score per subject ─────────────────────────────────
$avgPerSubject = Database::fetchAll(
    "SELECT sub.name, ROUND(AVG(r.total_score),1) AS avg_score,
            MAX(r.total_score) AS max_score,
            MIN(r.total_score) AS min_score,
            COUNT(*) AS result_count
     FROM results r JOIN subjects sub ON sub.id=r.subject_id
     WHERE r.session_id=? AND r.term_id=?
     GROUP BY r.subject_id ORDER BY avg_score DESC",
    [$sess_id, $term_id]
);

// ── Average score per class ───────────────────────────────────
$avgPerClass = Database::fetchAll(
    "SELECT c.name, ROUND(AVG(r.total_score),1) AS avg_score, COUNT(DISTINCT r.student_id) AS student_count
     FROM results r JOIN classes c ON c.id=r.class_id
     WHERE r.session_id=? AND r.term_id=?
     GROUP BY r.class_id ORDER BY avg_score DESC",
    [$sess_id, $term_id]
);

// ── Fee collection stats ──────────────────────────────────────
$feeStats = Database::fetchOne(
    "SELECT SUM(amount_due) AS total_due, SUM(amount_paid) AS total_paid,
            COUNT(CASE WHEN status='paid'    THEN 1 END) AS paid,
            COUNT(CASE WHEN status='partial' THEN 1 END) AS partial,
            COUNT(CASE WHEN status='unpaid'  THEN 1 END) AS unpaid
     FROM payments WHERE session_id=? AND term_id=?",
    [$sess_id, $term_id]
);

// ── Attendance rate per class ─────────────────────────────────
$attPerClass = Database::fetchAll(
    "SELECT c.name AS class_name,
            COUNT(*) AS total,
            SUM(a.status='present') AS present,
            ROUND(SUM(a.status='present')/COUNT(*)*100,1) AS rate
     FROM attendance a JOIN classes c ON c.id=a.class_id
     WHERE a.term_id=?
     GROUP BY c.id ORDER BY rate DESC",
    [$term_id]
);

// ── Top 10 students overall ───────────────────────────────────
$topStudents = Database::fetchAll(
    "SELECT s.full_name, s.student_id, c.name AS class_name,
            ROUND(AVG(r.total_score),1) AS avg_score,
            COUNT(r.id) AS subjects
     FROM results r
     JOIN students s ON s.id=r.student_id
     JOIN classes  c ON c.id=s.class_id
     WHERE r.session_id=? AND r.term_id=?
     GROUP BY s.id
     HAVING subjects >= 2
     ORDER BY avg_score DESC LIMIT 10",
    [$sess_id, $term_id]
);

// ── Fee collection per class ──────────────────────────────────
$feePerClass = Database::fetchAll(
    "SELECT c.name AS class_name,
            SUM(p.amount_due)  AS total_due,
            SUM(p.amount_paid) AS total_paid,
            ROUND(SUM(p.amount_paid)/NULLIF(SUM(p.amount_due),0)*100,1) AS rate
     FROM payments p
     JOIN students s ON s.id=p.student_id
     JOIN classes  c ON c.id=s.class_id
     WHERE p.session_id=? AND p.term_id=?
     GROUP BY c.id ORDER BY c.sort_order",
    [$sess_id, $term_id]
);

// ── Daily attendance trend (last 14 days) ─────────────────────
$attTrend = Database::fetchAll(
    "SELECT date,
            COUNT(*) AS total,
            SUM(status='present') AS present,
            ROUND(SUM(status='present')/COUNT(*)*100,1) AS rate
     FROM attendance
     WHERE date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND term_id=?
     GROUP BY date ORDER BY date ASC",
    [$term_id]
);

$colRate = (float)($feeStats['total_due'] ?? 0) > 0
    ? round(((float)$feeStats['total_paid'] / (float)$feeStats['total_due']) * 100)
    : 0;

$gradeColors = ['A'=>'#10B981','B'=>'#3B82F6','C'=>'#F59E0B','D'=>'#8B5CF6','F'=>'#EF4444'];

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📉 Analytics Dashboard</h1>
        <p class="page-subtitle">
            <?= e(get_setting('current_session')) ?> &mdash;
            <?= e(get_setting('current_term')) ?> Term &mdash;
            <?= date('d F Y') ?>
        </p>
    </div>
    <div class="page-header-actions">
        <button onclick="window.print()" class="btn btn-outline">🖨️ Print Report</button>
    </div>
</div>

<!-- ── Core KPI Cards ── -->
<div class="stats-grid mb-24" style="grid-template-columns:repeat(5,1fr)">
    <div class="stat-card stat-blue">
        <div class="stat-icon">👨‍🎓</div>
        <div class="stat-info">
            <div class="stat-value"><?= $studentStats['total'] ?? 0 ?></div>
            <div class="stat-label">Total Students</div>
            <div class="stat-sub" style="color:var(--blue)"><?= $studentStats['male'] ?? 0 ?>M / <?= $studentStats['female'] ?? 0 ?>F</div>
        </div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-icon">👨‍🏫</div>
        <div class="stat-info">
            <div class="stat-value"><?= $teacherCount ?></div>
            <div class="stat-label">Active Teachers</div>
        </div>
    </div>
    <div class="stat-card stat-purple">
        <div class="stat-icon">🏛️</div>
        <div class="stat-info">
            <div class="stat-value"><?= $classCount ?></div>
            <div class="stat-label">Classes</div>
        </div>
    </div>
    <div class="stat-card stat-gold">
        <div class="stat-icon">💰</div>
        <div class="stat-info">
            <div class="stat-value" style="font-size:18px"><?= money((float)($feeStats['total_paid']??0)) ?></div>
            <div class="stat-label">Fees Collected</div>
            <div class="stat-sub" style="color:#D97706"><?= $colRate ?>% of total</div>
        </div>
    </div>
    <div class="stat-card stat-blue">
        <div class="stat-icon">📊</div>
        <div class="stat-info">
            <?php
            $overallAvg = !empty($avgPerSubject)
                ? round(array_sum(array_column($avgPerSubject,'avg_score'))/count($avgPerSubject),1)
                : 0;
            ?>
            <div class="stat-value"><?= $overallAvg ?></div>
            <div class="stat-label">Avg Score /100</div>
        </div>
    </div>
</div>

<!-- ── Row 1: Students per Class + Grade Distribution ── -->
<div class="grid-2 mb-24">

    <!-- Students per class bar chart -->
    <div class="card">
        <div class="card-header">🏛️ Students per Class</div>
        <div class="card-body">
            <?php
            $maxCount = max(array_column($studentsPerClass,'count') ?: [1]);
            foreach ($studentsPerClass as $cls):
                $pct = $maxCount > 0 ? round(($cls['count']/$maxCount)*100) : 0;
            ?>
            <div style="margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;margin-bottom:5px">
                    <span style="font-weight:700;font-size:13px"><?= e($cls['name']) ?></span>
                    <span style="font-size:13px;color:var(--text-muted)"><?= $cls['count'] ?> students</span>
                </div>
                <div class="progress" style="height:12px">
                    <div class="progress-bar blue" style="width:<?= $pct ?>%"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Grade distribution -->
    <div class="card">
        <div class="card-header">📊 Grade Distribution — <?= e(get_setting('current_term')) ?> Term</div>
        <div class="card-body">
            <?php
            $totalResults = array_sum(array_column($gradeDistribution,'count'));
            $gradeMap     = array_column($gradeDistribution, 'count', 'grade');
            $allGrades    = ['A','B','C','D','F'];
            foreach ($allGrades as $g):
                $cnt = (int)($gradeMap[$g] ?? 0);
                $pct = $totalResults > 0 ? round(($cnt/$totalResults)*100) : 0;
            ?>
            <div style="margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;margin-bottom:5px;align-items:center">
                    <div style="display:flex;align-items:center;gap:8px">
                        <div style="width:14px;height:14px;border-radius:3px;background:<?= $gradeColors[$g] ?>"></div>
                        <span style="font-weight:700;font-size:13px">Grade <?= $g ?></span>
                    </div>
                    <span style="font-size:13px;color:var(--text-muted)"><?= $cnt ?> students (<?= $pct ?>%)</span>
                </div>
                <div class="progress" style="height:12px">
                    <div style="height:100%;width:<?= $pct ?>%;background:<?= $gradeColors[$g] ?>;border-radius:6px;transition:width .6s"></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (!$totalResults): ?>
                <div class="table-empty" style="padding:20px">No results recorded this term</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Row 2: Subject Performance + Attendance by Class ── -->
<div class="grid-2 mb-24">

    <!-- Subject averages -->
    <div class="card">
        <div class="card-header">📚 Subject Performance</div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr>
                    <th data-sort>Subject</th>
                    <th data-sort>Avg Score</th>
                    <th>Highest</th>
                    <th>Lowest</th>
                    <th>Students</th>
                </tr></thead>
                <tbody>
                <?php if ($avgPerSubject): foreach ($avgPerSubject as $i => $sub): ?>
                    <tr>
                        <td>
                            <div style="font-weight:700;font-size:13px"><?= e($sub['name']) ?></div>
                            <div class="progress" style="height:4px;margin-top:4px">
                                <div style="height:100%;width:<?= $sub['avg_score'] ?>%;
                                           background:<?= $sub['avg_score']>=70?'var(--emerald)':($sub['avg_score']>=50?'var(--accent)':'var(--red)') ?>;
                                           border-radius:2px"></div>
                            </div>
                        </td>
                        <td>
                            <span style="font-weight:800;font-size:16px;
                                        color:<?= $sub['avg_score']>=70?'var(--emerald)':($sub['avg_score']>=50?'var(--accent)':'var(--red)') ?>">
                                <?= $sub['avg_score'] ?>
                            </span>
                        </td>
                        <td style="color:var(--emerald);font-weight:700"><?= number_format($sub['max_score'],1) ?></td>
                        <td style="color:var(--red);font-weight:700"><?= number_format($sub['min_score'],1) ?></td>
                        <td class="text-muted"><?= $sub['result_count'] ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" class="table-empty">No results this term</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Attendance by class -->
    <div class="card">
        <div class="card-header">📅 Attendance Rate by Class</div>
        <div class="card-body">
            <?php if ($attPerClass): foreach ($attPerClass as $ac):
                $rateCol = $ac['rate']>=80?'var(--emerald)':($ac['rate']>=60?'var(--accent)':'var(--red)');
            ?>
            <div style="margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;margin-bottom:5px;align-items:center">
                    <span style="font-weight:700;font-size:13px"><?= e($ac['class_name']) ?></span>
                    <span style="font-weight:800;color:<?= $rateCol ?>"><?= $ac['rate'] ?>%</span>
                </div>
                <div class="progress" style="height:12px">
                    <div style="height:100%;width:<?= $ac['rate'] ?>%;background:<?= $rateCol ?>;
                                border-radius:6px;transition:width .6s"></div>
                </div>
                <div class="text-xs text-muted" style="margin-top:3px">
                    <?= $ac['present'] ?> present of <?= $ac['total'] ?> records
                </div>
            </div>
            <?php endforeach; else: ?>
                <div class="table-empty" style="padding:20px">No attendance recorded this term</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Row 3: Top Students + Fee Collection ── -->
<div class="grid-2 mb-24">

    <!-- Top students -->
    <div class="card">
        <div class="card-header">🏆 Top 10 Students</div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr>
                    <th>#</th>
                    <th data-sort>Student</th>
                    <th>Class</th>
                    <th data-sort>Avg Score</th>
                </tr></thead>
                <tbody>
                <?php if ($topStudents): foreach ($topStudents as $i => $ts): ?>
                    <tr>
                        <td style="font-size:18px">
                            <?= ['🥇','🥈','🥉'][$i] ?? ($i+1) ?>
                        </td>
                        <td>
                            <div style="font-weight:700"><?= e($ts['full_name']) ?></div>
                            <div class="text-xs text-muted"><?= e($ts['student_id']) ?></div>
                        </td>
                        <td><span class="badge badge-navy"><?= e($ts['class_name']) ?></span></td>
                        <td>
                            <span style="font-weight:800;font-size:16px;
                                        color:<?= $ts['avg_score']>=80?'var(--emerald)':($ts['avg_score']>=60?'var(--blue)':'var(--accent)') ?>">
                                <?= $ts['avg_score'] ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="4" class="table-empty">No results this term</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Fee collection by class -->
    <div class="card">
        <div class="card-header">💳 Fee Collection by Class</div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr>
                    <th>Class</th>
                    <th>Due</th>
                    <th>Paid</th>
                    <th>Rate</th>
                </tr></thead>
                <tbody>
                <?php if ($feePerClass): foreach ($feePerClass as $fc):
                    $r    = (float)($fc['rate'] ?? 0);
                    $col  = $r>=80?'var(--emerald)':($r>=50?'var(--accent)':'var(--red)');
                ?>
                    <tr>
                        <td><strong><?= e($fc['class_name']) ?></strong></td>
                        <td class="text-sm"><?= money($fc['total_due']) ?></td>
                        <td style="color:var(--emerald);font-weight:700"><?= money($fc['total_paid']) ?></td>
                        <td style="min-width:120px">
                            <div style="display:flex;align-items:center;gap:8px">
                                <div style="flex:1;background:var(--light);border-radius:4px;height:8px;overflow:hidden">
                                    <div style="height:100%;width:<?= $r ?>%;background:<?= $col ?>;border-radius:4px"></div>
                                </div>
                                <span style="font-weight:800;font-size:13px;color:<?= $col ?>"><?= $r ?>%</span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="4" class="table-empty">No fee records</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Attendance Trend (14 days) ── -->
<?php if (!empty($attTrend)): ?>
<div class="card mb-24">
    <div class="card-header">📈 Daily Attendance Trend — Last 14 Days</div>
    <div class="card-body">
        <div style="display:flex;align-items:flex-end;gap:6px;height:100px;padding:0 10px">
            <?php foreach ($attTrend as $day):
                $rate = (float)$day['rate'];
                $h    = max(4, round($rate));
                $col  = $rate>=80?'var(--emerald)':($rate>=60?'var(--accent)':'var(--red)');
            ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px">
                <span style="font-size:9px;font-weight:700;color:<?= $col ?>"><?= $rate ?>%</span>
                <div style="width:100%;height:<?= $h ?>px;background:<?= $col ?>;
                            border-radius:3px 3px 0 0;min-height:4px;transition:height .6s"
                     title="<?= format_date($day['date'],'d M') ?>: <?= $rate ?>%"></div>
                <span style="font-size:9px;color:var(--text-muted);white-space:nowrap">
                    <?= date('d M', strtotime($day['date'])) ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <!-- Legend -->
        <div style="display:flex;gap:16px;margin-top:12px;padding-top:10px;border-top:1px solid var(--border)">
            <?php foreach (['≥80% Good'=>'var(--emerald)','60–79% Average'=>'var(--accent)','<60% Poor'=>'var(--red)'] as $lbl=>$col): ?>
            <div style="display:flex;align-items:center;gap:5px">
                <div style="width:12px;height:12px;border-radius:2px;background:<?= $col ?>"></div>
                <span class="text-xs text-muted"><?= $lbl ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Average Score per Class ── -->
<div class="card mb-24">
    <div class="card-header">🏛️ Average Score per Class — <?= e(get_setting('current_term')) ?> Term</div>
    <div class="card-body">
        <?php if ($avgPerClass): ?>
        <div style="display:flex;align-items:flex-end;gap:20px;height:120px;padding:0 20px">
            <?php foreach ($avgPerClass as $cls):
                $h   = max(4, round($cls['avg_score']));
                $col = $cls['avg_score']>=70?'var(--navy)':($cls['avg_score']>=50?'var(--accent)':'var(--red)');
            ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px">
                <span style="font-size:13px;font-weight:900;color:<?= $col ?>"><?= $cls['avg_score'] ?></span>
                <div style="width:100%;height:<?= $h ?>px;background:<?= $col ?>;
                            border-radius:4px 4px 0 0;transition:height .6s"></div>
                <span style="font-size:12px;font-weight:700;color:var(--text-muted)"><?= e($cls['name']) ?></span>
                <span style="font-size:10px;color:var(--text-light)"><?= $cls['student_count'] ?> students</span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="table-empty">No results recorded this term.</div>
        <?php endif; ?>
    </div>
</div>

<style>
@media print {
    .sidebar,.topnav,.page-header-actions,.flash-container { display:none !important; }
    .main-area { margin:0 !important; }
    .card { break-inside:avoid; box-shadow:none; border:1px solid #ccc; }
}
</style>

<?php include INCLUDES_PATH . '/footer.php'; ?>
