<?php
// ============================================================
// student/dashboard.php — Student Portal Dashboard
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_STUDENT);

$pageTitle  = 'My Dashboard';
$activeMenu = 'dashboard';

$sess_id = current_session_id();
$term_id = current_term_id();

$student = Database::fetchOne(
    "SELECT s.*, c.name AS class_name FROM students s JOIN classes c ON c.id=s.class_id
     WHERE s.user_id=? AND s.session_id=?",
    [current_user_id(), $sess_id]
);

$results      = [];
$avgScore     = 0;
$attendanceSummary = ['total'=>0,'present'=>0,'absent'=>0,'late'=>0];
$paymentStatus = null;
$pendingAssignments = 0;
$unreadMessages = 0;

if ($student) {
    $results  = Database::fetchAll(
        "SELECT r.total_score FROM results r WHERE r.student_id=? AND r.session_id=? AND r.term_id=?",
        [$student['id'],$sess_id,$term_id]
    );
    $avgScore = count($results) > 0 ? array_sum(array_column($results,'total_score'))/count($results) : 0;

    $attRow = Database::fetchOne(
        "SELECT COUNT(*) AS total, SUM(status='present') AS present,
                SUM(status='absent') AS absent, SUM(status='late') AS late
         FROM attendance WHERE student_id=? AND term_id=?",
        [$student['id'],$term_id]
    );
    if ($attRow) $attendanceSummary = $attRow;

    $paymentStatus = Database::fetchOne(
        "SELECT status, balance FROM payments WHERE student_id=? AND term_id=? AND session_id=? LIMIT 1",
        [$student['id'],$term_id,$sess_id]
    );

    $pendingAssignments = (int)(Database::fetchOne(
        "SELECT COUNT(*) AS c FROM assignments a
         WHERE a.class_id=? AND a.term_id=? AND a.due_date>=CURDATE()
           AND NOT EXISTS (SELECT 1 FROM assignment_submissions s WHERE s.assignment_id=a.id AND s.student_id=?)",
        [$student['class_id'],$term_id,$student['id']]
    )['c'] ?? 0);

    $unreadMessages = (int)(Database::fetchOne(
        "SELECT COUNT(*) AS c FROM messages WHERE to_user=? AND is_read=0",
        [current_user_id()]
    )['c'] ?? 0);
}

$attRate    = ($attendanceSummary['total']??0) > 0 ? round(($attendanceSummary['present']/$attendanceSummary['total'])*100) : 0;
$avgGrade   = get_grade($avgScore);

// ── Fee gate check for dashboard ──────────────────────────────
$dashFeeGate = $student
    ? student_can_view_results($student['id'], $sess_id, $term_id)
    : ['allowed' => true, 'reason' => 'no_student', 'balance' => 0, 'term' => ''];

$announcements = Database::fetchAll(
    "SELECT a.*,u.full_name AS author FROM announcements a JOIN users u ON u.id=a.posted_by
     WHERE target IN ('all','students','parents') AND (expires_at IS NULL OR expires_at>=CURDATE())
     ORDER BY FIELD(priority,'high','normal','low'), created_at DESC LIMIT 4"
);

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📊 My Dashboard</h1>
        <p class="page-subtitle">
            Welcome, <strong><?= e($student['full_name'] ?? current_full_name()) ?></strong>
            <?php if ($student): ?> &mdash; <?= e($student['class_name']) ?><?php endif; ?>
            &mdash; <?= e(get_setting('current_term')) ?> Term
        </p>
    </div>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>/student/report_card.php" class="btn btn-outline">📜 Report Card</a>
    </div>
</div>

<div class="stats-grid mb-24" style="grid-template-columns:repeat(4,1fr)">
    <div class="stat-card <?= $dashFeeGate['allowed'] ? 'stat-blue' : 'stat-red' ?>">
        <div class="stat-icon"><?= $dashFeeGate['allowed'] ? '📊' : '🔒' ?></div>
        <div class="stat-info">
            <?php if ($dashFeeGate['allowed']): ?>
                <div class="stat-value"><?= number_format($avgScore,1) ?></div>
                <div class="stat-label">Avg Score</div>
                <div class="stat-sub" style="color:var(--blue)"><?= e($avgGrade['remark']) ?></div>
            <?php else: ?>
                <div class="stat-value" style="font-size:16px">Locked</div>
                <div class="stat-label">Results</div>
                <div class="stat-sub" style="color:var(--red)">Fees outstanding</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="stat-card <?= $attRate>=80?'stat-green':($attRate>=60?'stat-gold':'stat-red') ?>">
        <div class="stat-icon">📅</div>
        <div class="stat-info">
            <div class="stat-value"><?= $attRate ?>%</div>
            <div class="stat-label">Attendance</div>
            <div class="stat-sub"><?= $attendanceSummary['present'] ?> / <?= $attendanceSummary['total'] ?> days</div>
        </div>
    </div>
    <div class="stat-card stat-gold">
        <div class="stat-icon">📚</div>
        <div class="stat-info">
            <div class="stat-value"><?= $pendingAssignments ?></div>
            <div class="stat-label">Assignments Due</div>
        </div>
    </div>
    <div class="stat-card <?= ($paymentStatus['status']??'unpaid')==='paid'?'stat-green':'stat-red' ?>">
        <div class="stat-icon">💳</div>
        <div class="stat-info">
            <div class="stat-value" style="font-size:16px"><?= ucfirst($paymentStatus['status'] ?? 'No Record') ?></div>
            <div class="stat-label">Fee Status</div>
            <?php if (($paymentStatus['balance']??0)>0): ?>
                <div class="stat-sub" style="color:var(--red)"><?= money($paymentStatus['balance']) ?> due</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Quick links -->
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:24px">
    <?php if ($dashFeeGate['allowed']): ?>
        <a href="<?= BASE_URL ?>/student/results.php" class="btn btn-primary">📈 View Results</a>
    <?php else: ?>
        <a href="<?= BASE_URL ?>/student/payments.php"
           class="btn btn-danger"
           title="Pay fees to unlock results">
            🔒 Results Locked — Pay Fees
        </a>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/student/attendance.php"  class="btn btn-outline">📅 Attendance</a>
    <a href="<?= BASE_URL ?>/student/assignments.php" class="btn btn-outline">📚 Assignments</a>
    <a href="<?= BASE_URL ?>/student/payments.php"    class="btn btn-outline">💳 Fee Status</a>
    <?php if ($unreadMessages > 0): ?>
        <a href="<?= BASE_URL ?>/student/messages.php" class="btn btn-accent">
            💬 Messages <span class="badge badge-danger" style="margin-left:4px"><?= $unreadMessages ?></span>
        </a>
    <?php endif; ?>
</div>

<!-- Announcements -->
<?php if ($announcements): ?>
<div class="card">
    <div class="card-header">📢 Latest Announcements</div>
    <div>
    <?php foreach ($announcements as $ann): ?>
        <div style="padding:14px 18px;border-bottom:1px solid var(--border);
                    border-left:4px solid <?= $ann['priority']==='high'?'var(--red)':($ann['priority']==='normal'?'var(--blue)':'var(--gray)') ?>">
            <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
                <?= status_badge($ann['priority']) ?>
                <span class="text-xs text-muted">By <?= e($ann['author']) ?> &bull; <?= format_date($ann['created_at'],'d M Y') ?></span>
            </div>
            <div style="font-weight:800;font-size:15px;margin-bottom:4px"><?= e($ann['title']) ?></div>
            <div class="text-sm text-muted" style="line-height:1.6"><?= e(substr($ann['body'],0,200)) ?><?= strlen($ann['body'])>200?'…':'' ?></div>
        </div>
    <?php endforeach; ?>
    </div>
    <div class="card-footer">
        <a href="<?= BASE_URL ?>/student/announcements.php" class="btn btn-outline btn-sm">View All →</a>
    </div>
</div>
<?php endif; ?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
