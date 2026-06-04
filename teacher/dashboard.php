<?php
// ============================================================
// teacher/dashboard.php — Teacher Dashboard
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_TEACHER);

$pageTitle  = 'Dashboard';
$activeMenu = 'dashboard';

$sess_id = current_session_id();
$term_id = current_term_id();

$myTeacher = Database::fetchOne(
    "SELECT t.* FROM teachers t JOIN users u ON u.id=t.user_id WHERE u.id=?",
    [current_user_id()]
);
$teacherId = $myTeacher['id'] ?? 0;

// My assignments
$myAssignments = Database::fetchAll(
    "SELECT ts.subject_id, ts.class_id, sub.name AS subj, c.name AS cls,
            (SELECT COUNT(*) FROM results r WHERE r.subject_id=ts.subject_id AND r.class_id=ts.class_id AND r.term_id=? AND r.session_id=?) AS results_entered,
            (SELECT COUNT(*) FROM students s WHERE s.class_id=ts.class_id AND s.session_id=? AND s.status='active') AS student_count
     FROM teacher_subjects ts
     JOIN subjects sub ON sub.id=ts.subject_id
     JOIN classes c ON c.id=ts.class_id
     WHERE ts.teacher_id=?",
    [$term_id, $sess_id, $sess_id, $teacherId]
);

$upcomingAssignments = Database::fetchAll(
    "SELECT a.*, sub.name AS subject_name, c.name AS class_name
     FROM assignments a JOIN subjects sub ON sub.id=a.subject_id JOIN classes c ON c.id=a.class_id
     WHERE a.teacher_id=? AND a.due_date >= CURDATE()
     ORDER BY a.due_date ASC LIMIT 5",
    [$teacherId]
);

$recentAnn = Database::fetchAll(
    "SELECT a.*, u.full_name AS author FROM announcements a JOIN users u ON u.id=a.posted_by
     WHERE (expires_at IS NULL OR expires_at >= CURDATE()) ORDER BY created_at DESC LIMIT 5"
);

$unreadMessages = (int)(Database::fetchOne(
    "SELECT COUNT(*) AS c FROM messages WHERE to_user=? AND is_read=0",
    [current_user_id()]
)['c'] ?? 0);

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📊 Dashboard</h1>
        <p class="page-subtitle">
            Welcome, <?= e(current_full_name()) ?> &mdash;
            <?= e(get_setting('current_term')) ?> Term, <?= e(get_setting('current_session')) ?>
        </p>
    </div>
</div>

<div class="stats-grid mb-24" style="grid-template-columns:repeat(3,1fr)">
    <div class="stat-card stat-blue">
        <div class="stat-icon">📘</div>
        <div class="stat-info">
            <div class="stat-value"><?= count($myAssignments) ?></div>
            <div class="stat-label">Subject Assignments</div>
        </div>
    </div>
    <div class="stat-card stat-gold">
        <div class="stat-icon">📚</div>
        <div class="stat-info">
            <div class="stat-value"><?= count($upcomingAssignments) ?></div>
            <div class="stat-label">Upcoming Due Dates</div>
        </div>
    </div>
    <div class="stat-card stat-purple">
        <div class="stat-icon">💬</div>
        <div class="stat-info">
            <div class="stat-value"><?= $unreadMessages ?></div>
            <div class="stat-label">Unread Messages</div>
        </div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header">📈 My Results Progress</div>
        <div class="card-body">
        <?php if ($myAssignments): foreach ($myAssignments as $a):
            $pct = $a['student_count']>0 ? round(($a['results_entered']/$a['student_count'])*100) : 0;
        ?>
            <div style="margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                    <span style="font-weight:700;font-size:13px"><?= e($a['subj']) ?> — <?= e($a['cls']) ?></span>
                    <span class="text-sm text-muted"><?= $a['results_entered'] ?>/<?= $a['student_count'] ?></span>
                </div>
                <div class="progress" style="height:10px">
                    <div class="progress-bar <?= $pct>=100?'green':($pct>0?'orange':'red') ?>"
                         style="width:<?= $pct ?>%"></div>
                </div>
            </div>
        <?php endforeach; else: ?>
            <div class="table-empty">No subject assignments yet.</div>
        <?php endif; ?>
        </div>
        <div class="card-footer">
            <a href="<?= BASE_URL ?>/admin/results_bulk.php" class="btn btn-primary btn-sm">📥 Enter Results</a>
        </div>
    </div>

    <div class="card">
        <div class="card-header">📢 Announcements</div>
        <div>
        <?php foreach ($recentAnn as $ann): ?>
            <div style="padding:12px 16px;border-bottom:1px solid var(--border)">
                <div style="display:flex;gap:8px;margin-bottom:4px">
                    <?= status_badge($ann['priority']) ?>
                    <span class="text-xs text-muted"><?= format_date($ann['created_at'],'d M') ?></span>
                </div>
                <div style="font-weight:700;font-size:13px"><?= e($ann['title']) ?></div>
                <div class="text-xs text-muted" style="margin-top:2px"><?= e(substr($ann['body'],0,80)) ?>…</div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
