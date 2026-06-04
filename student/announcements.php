<?php
// ============================================================
// student/announcements.php — Student Announcement Board
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_STUDENT);

$pageTitle  = 'Announcements';
$activeMenu = 'announcements';

$page = int_val($_GET['page'] ?? 1);

// Fetch announcements visible to students (target = all or students or parents)
$baseSql = "SELECT a.*, u.full_name AS author_name
            FROM announcements a JOIN users u ON u.id=a.posted_by
            WHERE a.target IN ('all','students','parents')
              AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())
            ORDER BY
              FIELD(a.priority,'high','normal','low'),
              a.created_at DESC";

$pager = paginate($baseSql, [], $page);
$anns  = $pager['rows'];

// Unread count (notifications)
$unreadAnn = Database::fetchOne(
    "SELECT COUNT(*) AS c FROM notifications
     WHERE user_id=? AND type='announcement' AND is_read=0",
    [current_user_id()]
);

// Mark announcement notifications as read
Database::execute(
    "UPDATE notifications SET is_read=1, read_at=NOW()
     WHERE user_id=? AND type='announcement' AND is_read=0",
    [current_user_id()]
);

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📢 Announcements</h1>
        <p class="page-subtitle">
            <?= $pager['total'] ?> announcement(s) &nbsp;|&nbsp;
            <?= e(get_setting('school_name')) ?>
        </p>
    </div>
</div>

<?php if (empty($anns)): ?>
    <div class="card">
        <div class="card-body table-empty">
            <div class="table-empty-icon">📢</div>
            No announcements at the moment. Check back later.
        </div>
    </div>
<?php else: ?>

<!-- Priority grouping -->
<?php
$grouped = ['high'=>[],'normal'=>[],'low'=>[]];
foreach ($anns as $ann) $grouped[$ann['priority']][] = $ann;
$labels = ['high'=>'🚨 Urgent / High Priority','normal'=>'📋 General Announcements','low'=>'📌 Notices'];
foreach ($grouped as $prio => $items):
    if (empty($items)) continue;
?>
<div style="margin-bottom:24px">
    <h3 style="font-size:13px;font-weight:700;color:var(--text-muted);text-transform:uppercase;
               letter-spacing:.05em;margin-bottom:12px;display:flex;align-items:center;gap:8px">
        <?= $labels[$prio] ?>
        <span class="badge <?= $prio==='high'?'badge-danger':($prio==='normal'?'badge-primary':'badge-secondary') ?>">
            <?= count($items) ?>
        </span>
    </h3>

    <div style="display:flex;flex-direction:column;gap:12px">
    <?php foreach ($items as $ann): ?>
        <div class="card" style="border-left:4px solid <?= $prio==='high'?'var(--red)':($prio==='normal'?'var(--blue)':'var(--gray)') ?>">
            <div style="padding:18px 20px">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap">
                    <?= status_badge($ann['priority']) ?>
                    <?php if ($ann['target'] !== 'all'): ?>
                        <span class="badge badge-navy"><?= ucfirst($ann['target']) ?></span>
                    <?php endif; ?>
                    <span class="text-xs text-muted">
                        Posted by <strong><?= e($ann['author_name']) ?></strong>
                        &bull; <?= format_date($ann['created_at'], 'd M Y') ?>
                    </span>
                    <?php if ($ann['expires_at']): ?>
                        <span class="text-xs text-muted">&bull; Until <?= format_date($ann['expires_at']) ?></span>
                    <?php endif; ?>
                </div>
                <h3 style="margin:0 0 8px;font-size:17px;font-weight:800;color:var(--text)">
                    <?= e($ann['title']) ?>
                </h3>
                <p style="margin:0;color:var(--text-muted);line-height:1.7;font-size:14px">
                    <?= nl2br(e($ann['body'])) ?>
                </p>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<?= pagination_links($pager, BASE_URL . '/student/announcements.php') ?>

<?php endif; ?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
