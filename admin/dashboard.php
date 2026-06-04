<?php
// ============================================================
// admin/dashboard.php — Admin Dashboard
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_login();

$pageTitle  = 'Dashboard';
$activeMenu = 'dashboard';

// ── Fetch all dashboard stats ────────────────────────────────
$sess_id = current_session_id();
$term_id = current_term_id();

// Student stats
$totalStudents  = (int) Database::fetchOne("SELECT COUNT(*) AS c FROM students WHERE session_id = ?", [$sess_id])['c'];
$activeStudents = (int) Database::fetchOne("SELECT COUNT(*) AS c FROM students WHERE session_id = ? AND status='active'", [$sess_id])['c'];
$maleStudents   = (int) Database::fetchOne("SELECT COUNT(*) AS c FROM students WHERE session_id = ? AND gender='Male'", [$sess_id])['c'];
$femaleStudents = (int) Database::fetchOne("SELECT COUNT(*) AS c FROM students WHERE session_id = ? AND gender='Female'", [$sess_id])['c'];

// Teacher stats
$totalTeachers  = (int) Database::fetchOne("SELECT COUNT(*) AS c FROM teachers WHERE status='active'")['c'];

// Class stats
$totalClasses   = (int) Database::fetchOne("SELECT COUNT(*) AS c FROM classes")['c'];
$totalSubjects  = (int) Database::fetchOne("SELECT COUNT(*) AS c FROM subjects")['c'];

// Payment stats
$payStats = Database::fetchOne(
    "SELECT
        SUM(amount_due)  AS total_due,
        SUM(amount_paid) AS total_paid,
        SUM(amount_due - amount_paid) AS total_balance,
        COUNT(CASE WHEN status='paid'    THEN 1 END) AS paid_count,
        COUNT(CASE WHEN status='partial' THEN 1 END) AS partial_count,
        COUNT(CASE WHEN status='unpaid'  THEN 1 END) AS unpaid_count
     FROM payments WHERE session_id = ? AND term_id = ?",
    [$sess_id, $term_id]
);

// Today's attendance
$today     = date('Y-m-d');
$attToday  = Database::fetchAll(
    "SELECT status, COUNT(*) AS c FROM attendance WHERE date = ? GROUP BY status",
    [$today]
);
$attMap = ['present' => 0, 'absent' => 0, 'late' => 0];
foreach ($attToday as $row) $attMap[$row['status']] = (int)$row['c'];
$attTotal   = array_sum($attMap);
$attPercent = $attTotal > 0 ? round(($attMap['present'] / $attTotal) * 100) : 0;

// Recent announcements
$recentAnn = Database::fetchAll(
    "SELECT a.*, u.full_name AS author_name
     FROM announcements a
     JOIN users u ON u.id = a.posted_by
     ORDER BY a.created_at DESC LIMIT 5"
);

// Recent audit logs
$recentLogs = Database::fetchAll(
    "SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 8"
);

// Students per class (for chart)
$classData = Database::fetchAll(
    "SELECT c.name AS class_name, COUNT(s.id) AS student_count
     FROM classes c
     LEFT JOIN students s ON s.class_id = c.id AND s.session_id = ?
     GROUP BY c.id, c.name
     ORDER BY c.sort_order",
    [$sess_id]
);

// Top performing students
$topStudents = Database::fetchAll(
    "SELECT s.full_name, s.student_id, c.name AS class_name,
            AVG(r.total_score) AS avg_score
     FROM results r
     JOIN students s ON s.id = r.student_id
     JOIN classes  c ON c.id = s.class_id
     WHERE r.session_id = ? AND r.term_id = ?
     GROUP BY s.id
     ORDER BY avg_score DESC
     LIMIT 5",
    [$sess_id, $term_id]
);

// Upcoming assignments
$upcomingAsn = Database::fetchAll(
    "SELECT a.title, a.due_date, c.name AS class_name, sub.name AS subject_name,
            u.full_name AS teacher_name
     FROM assignments a
     JOIN classes  c   ON c.id   = a.class_id
     JOIN subjects sub ON sub.id = a.subject_id
     JOIN teachers t   ON t.id   = a.teacher_id
     JOIN users    u   ON u.id   = t.user_id
     WHERE a.due_date >= CURDATE()
     ORDER BY a.due_date ASC
     LIMIT 5"
);

// Fee collection chart data
$feeChart = [
    ['label' => 'Collected', 'value' => (float)($payStats['total_paid']    ?? 0), 'color' => '#10B981'],
    ['label' => 'Outstanding','value' => (float)($payStats['total_balance'] ?? 0), 'color' => '#EF4444'],
];

include INCLUDES_PATH . '/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📊 Dashboard</h1>
        <p class="page-subtitle">
            Welcome back, <strong><?= e(current_full_name()) ?></strong> &mdash;
            <?= e(get_setting('current_term')) ?> Term,
            <?= e(get_setting('current_session')) ?>
            &nbsp;|&nbsp; <?= date('l, d F Y') ?>
        </p>
    </div>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>/admin/report_cards.php" class="btn btn-outline">📜 Report Cards</a>
        <a href="<?= BASE_URL ?>/admin/analytics.php"   class="btn btn-primary">📉 Analytics</a>
    </div>
</div>

<!-- ── Stat Cards ── -->
<div class="stats-grid">
    <div class="stat-card stat-blue">
        <div class="stat-icon">👨‍🎓</div>
        <div class="stat-info">
            <div class="stat-value"><?= $totalStudents ?></div>
            <div class="stat-label">Total Students</div>
            <div class="stat-sub" style="color:var(--blue)"><?= $maleStudents ?>M / <?= $femaleStudents ?>F</div>
        </div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-icon">👨‍🏫</div>
        <div class="stat-info">
            <div class="stat-value"><?= $totalTeachers ?></div>
            <div class="stat-label">Active Teachers</div>
            <div class="stat-sub" style="color:var(--emerald)"><?= $totalSubjects ?> subjects</div>
        </div>
    </div>
    <div class="stat-card stat-purple">
        <div class="stat-icon">🏛️</div>
        <div class="stat-info">
            <div class="stat-value"><?= $totalClasses ?></div>
            <div class="stat-label">Classes</div>
            <div class="stat-sub" style="color:var(--purple)">All active</div>
        </div>
    </div>
    <div class="stat-card stat-gold">
        <div class="stat-icon">💳</div>
        <div class="stat-info">
            <div class="stat-value"><?= money((float)($payStats['total_paid'] ?? 0)) ?></div>
            <div class="stat-label">Fees Collected</div>
            <div class="stat-sub" style="color:#D97706"><?= money((float)($payStats['total_balance'] ?? 0)) ?> outstanding</div>
        </div>
    </div>
</div>

<!-- ── Row 1: Attendance + Fee Status ── -->
<div class="grid-2 mb-24">

    <!-- Attendance card -->
    <div class="card">
        <div class="card-header">
            📅 Today's Attendance
            <span class="text-sm text-muted"><?= $today ?></span>
        </div>
        <div class="card-body">
            <div style="display:flex;gap:24px;margin-bottom:16px">
                <?php foreach (['present' => ['badge-success','✅'], 'absent' => ['badge-danger','❌'], 'late' => ['badge-warning','⏰']] as $s => [$cls, $ico]): ?>
                    <div style="text-align:center">
                        <div style="font-size:28px;font-weight:800;color:var(--text)"><?= $attMap[$s] ?></div>
                        <span class="badge <?= $cls ?>"><?= ucfirst($s) ?></span>
                    </div>
                <?php endforeach; ?>
                <div style="text-align:center;margin-left:auto">
                    <div style="font-size:28px;font-weight:800;color:var(--text)"><?= $attPercent ?>%</div>
                    <span class="badge badge-navy">Rate</span>
                </div>
            </div>
            <div class="progress">
                <div class="progress-bar green" style="width:<?= $attPercent ?>%"></div>
            </div>
            <div class="text-sm text-muted mt-8">
                <?= $attMap['present'] ?> of <?= $attTotal ?> students marked present today
            </div>
            <div class="mt-12">
                <a href="<?= BASE_URL ?>/admin/attendance.php" class="btn btn-outline btn-sm">View Full Attendance →</a>
            </div>
        </div>
    </div>

    <!-- Fee status card -->
    <div class="card">
        <div class="card-header">
            💳 Fee Collection — <?= e(get_setting('current_term')) ?> Term
        </div>
        <div class="card-body">
            <?php
            $totalDue = (float)($payStats['total_due'] ?? 0);
            $totalPaid = (float)($payStats['total_paid'] ?? 0);
            $feePercent = $totalDue > 0 ? round(($totalPaid / $totalDue) * 100) : 0;
            ?>
            <div style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap">
                <div>
                    <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:2px">Due</div>
                    <div style="font-size:18px;font-weight:800"><?= money($totalDue) ?></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:700;color:var(--emerald);text-transform:uppercase;margin-bottom:2px">Collected</div>
                    <div style="font-size:18px;font-weight:800;color:var(--emerald)"><?= money($totalPaid) ?></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:700;color:var(--red);text-transform:uppercase;margin-bottom:2px">Outstanding</div>
                    <div style="font-size:18px;font-weight:800;color:var(--red)"><?= money((float)($payStats['total_balance'] ?? 0)) ?></div>
                </div>
            </div>
            <div class="progress mb-8">
                <div class="progress-bar green" style="width:<?= $feePercent ?>%"></div>
            </div>
            <div class="text-sm text-muted"><?= $feePercent ?>% of total fees collected</div>
            <div style="display:flex;gap:8px;margin-top:12px">
                <span class="badge badge-success"><?= $payStats['paid_count'] ?? 0 ?> Paid</span>
                <span class="badge badge-warning"><?= $payStats['partial_count'] ?? 0 ?> Partial</span>
                <span class="badge badge-danger"><?= $payStats['unpaid_count'] ?? 0 ?> Unpaid</span>
            </div>
            <div class="mt-12">
                <a href="<?= BASE_URL ?>/admin/payments.php" class="btn btn-outline btn-sm">Manage Payments →</a>
            </div>
        </div>
    </div>
</div>

<!-- ── Row 2: Students per Class + Top Students ── -->
<div class="grid-2 mb-24">

    <!-- Students per class bar chart -->
    <div class="card">
        <div class="card-header">🏛️ Students per Class</div>
        <div class="card-body">
            <?php
            $maxCount = max(array_column($classData, 'student_count') ?: [1]);
            foreach ($classData as $cd):
                $pct = $maxCount > 0 ? round(($cd['student_count'] / $maxCount) * 100) : 0;
            ?>
            <div style="margin-bottom:12px">
                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                    <span style="font-size:13px;font-weight:700"><?= e($cd['class_name']) ?></span>
                    <span style="font-size:13px;color:var(--text-muted)"><?= $cd['student_count'] ?> students</span>
                </div>
                <div class="progress">
                    <div class="progress-bar blue" style="width:<?= $pct ?>%"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Top Students -->
    <div class="card">
        <div class="card-header">🏆 Top Performing Students</div>
        <div class="card-body" style="padding:0">
            <?php if ($topStudents): ?>
            <table class="data-table">
                <thead><tr>
                    <th>#</th>
                    <th data-sort>Student</th>
                    <th>Class</th>
                    <th data-sort>Avg Score</th>
                </tr></thead>
                <tbody>
                <?php foreach ($topStudents as $i => $ts): ?>
                    <tr>
                        <td>
                            <?php if ($i === 0): ?>🥇
                            <?php elseif ($i === 1): ?>🥈
                            <?php elseif ($i === 2): ?>🥉
                            <?php else: ?><?= $i+1 ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-weight:700"><?= e($ts['full_name']) ?></div>
                            <div class="text-xs text-muted"><?= e($ts['student_id']) ?></div>
                        </td>
                        <td><?= e($ts['class_name']) ?></td>
                        <td><strong><?= number_format($ts['avg_score'],1) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="table-empty"><div class="table-empty-icon">📊</div>No results recorded yet</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Row 3: Announcements + Upcoming Assignments ── -->
<div class="grid-2 mb-24">

    <!-- Recent Announcements -->
    <div class="card">
        <div class="card-header">
            📢 Recent Announcements
            <a href="<?= BASE_URL ?>/admin/announcements.php" class="btn btn-sm btn-outline">View All</a>
        </div>
        <div class="card-body" style="padding:0">
            <?php if ($recentAnn): foreach ($recentAnn as $ann): ?>
            <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;gap:10px;align-items:flex-start">
                <?= status_badge($ann['priority']) ?>
                <div style="flex:1">
                    <div style="font-weight:700;font-size:13.5px"><?= e($ann['title']) ?></div>
                    <div class="text-xs text-muted mt-4">
                        By <?= e($ann['author_name']) ?> &bull; <?= format_date($ann['created_at']) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; else: ?>
                <div class="table-empty">No announcements yet</div>
            <?php endif; ?>
            <div style="padding:10px 16px;background:var(--light)">
                <a href="<?= BASE_URL ?>/admin/announcements.php?action=add" class="btn btn-sm btn-primary">+ Post Announcement</a>
            </div>
        </div>
    </div>

    <!-- Upcoming Assignments -->
    <div class="card">
        <div class="card-header">
            📚 Upcoming Assignments
            <a href="<?= BASE_URL ?>/admin/assignments.php" class="btn btn-sm btn-outline">View All</a>
        </div>
        <div class="card-body" style="padding:0">
            <?php if ($upcomingAsn): foreach ($upcomingAsn as $asn): 
                $daysLeft = days_until($asn['due_date']);
            ?>
            <div style="padding:12px 16px;border-bottom:1px solid var(--border)">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <div style="font-weight:700;font-size:13.5px"><?= e($asn['title']) ?></div>
                    <span class="badge <?= $daysLeft <= 1 ? 'badge-danger' : ($daysLeft <= 3 ? 'badge-warning' : 'badge-primary') ?>">
                        <?= $daysLeft <= 0 ? 'Due Today' : ($daysLeft === 1 ? 'Tomorrow' : "In {$daysLeft}d") ?>
                    </span>
                </div>
                <div class="text-xs text-muted mt-4">
                    <?= e($asn['subject_name']) ?> &bull; <?= e($asn['class_name']) ?> &bull; <?= e($asn['teacher_name']) ?>
                </div>
            </div>
            <?php endforeach; else: ?>
                <div class="table-empty">No upcoming assignments</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Row 4: Recent Activity Log ── -->
<div class="card mb-24">
    <div class="card-header">
        🔍 Recent System Activity
        <a href="<?= BASE_URL ?>/admin/audit_logs.php" class="btn btn-sm btn-outline">Full Audit Log</a>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>User</th>
                <th>Action</th>
                <th>Module</th>
                <th>Time</th>
                <th>IP</th>
            </tr></thead>
            <tbody>
            <?php if ($recentLogs): foreach ($recentLogs as $log): ?>
                <tr>
                    <td><span style="font-weight:700;color:var(--navy)"><?= e($log['username'] ?? '—') ?></span></td>
                    <td><?= e($log['description'] ?? $log['action']) ?></td>
                    <td>
                        <?php
                        $mColors = ['Students'=>'badge-primary','Results'=>'badge-success',
                                   'Payments'=>'badge-gold','Attendance'=>'badge-warning',
                                   'Auth'=>'badge-navy','Settings'=>'badge-purple'];
                        $mc = $mColors[$log['module']] ?? 'badge-secondary';
                        ?>
                        <span class="badge <?= $mc ?>"><?= e($log['module']) ?></span>
                    </td>
                    <td class="text-sm text-muted"><?= e($log['created_at']) ?></td>
                    <td class="code"><?= e($log['ip_address'] ?? '—') ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5" class="table-empty">No activity recorded</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
