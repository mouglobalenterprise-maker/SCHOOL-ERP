<?php
// ============================================================
// admin/notifications.php — Notification Centre
// Referenced in header.php — required for bell icon links
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_login();

$pageTitle  = 'Notifications';
$activeMenu = '';

// ── Handle actions ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'mark_read') {
        $nid = int_val($_POST['notif_id'] ?? 0);
        Database::execute(
            "UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?",
            [$nid, current_user_id()]
        );
    }

    if ($action === 'delete') {
        $nid = int_val($_POST['notif_id'] ?? 0);
        Database::execute(
            "DELETE FROM notifications WHERE id=? AND user_id=?",
            [$nid, current_user_id()]
        );
    }

    if ($action === 'delete_all') {
        Database::execute("DELETE FROM notifications WHERE user_id=?", [current_user_id()]);
        flash_success('All notifications cleared.');
    }

    redirect(BASE_URL . '/admin/notifications.php');
}

// Mark all read if requested via GET
if (isset($_GET['mark_all'])) {
    Database::execute(
        "UPDATE notifications SET is_read=1 WHERE user_id=?", [current_user_id()]
    );
    flash_success('All notifications marked as read.');
    redirect(BASE_URL . '/admin/notifications.php');
}

// ── Load notifications ────────────────────────────────────────
$filter  = sanitize($_GET['filter'] ?? 'all'); // all | unread
$page    = int_val($_GET['page'] ?? 1);

$where  = ['user_id = ?'];
$params = [current_user_id()];
if ($filter === 'unread') { $where[] = 'is_read = 0'; }

$whereStr = 'WHERE ' . implode(' AND ', $where);
$baseSql  = "SELECT * FROM notifications {$whereStr} ORDER BY created_at DESC";
$pager    = paginate($baseSql, $params, $page);
$notifs   = $pager['rows'];

$unreadCount = count_unread_notifications(current_user_id());

// Type icons
$typeIcons = [
    'result'      => '📊',
    'attendance'  => '📅',
    'payment'     => '💳',
    'assignment'  => '📚',
    'message'     => '💬',
    'announcement'=> '📢',
    'info'        => 'ℹ️',
];

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">🔔 Notifications</h1>
        <p class="page-subtitle">
            <?= $unreadCount ?> unread &nbsp;|&nbsp; <?= $pager['total'] ?> total
        </p>
    </div>
    <div class="page-header-actions">
        <?php if ($unreadCount > 0): ?>
            <a href="?mark_all=1" class="btn btn-outline">✅ Mark All Read</a>
        <?php endif; ?>
        <?php if ($pager['total'] > 0): ?>
            <form method="POST" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_all">
                <button type="submit" class="btn btn-danger"
                        data-confirm="Clear all notifications?">🗑️ Clear All</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Filter tabs -->
<div class="tabs mb-20">
    <a href="?filter=all"    class="tab-btn <?= $filter==='all'   ?'active':'' ?>" style="text-decoration:none">
        All <span class="badge badge-secondary" style="margin-left:4px"><?= $pager['total'] ?></span>
    </a>
    <a href="?filter=unread" class="tab-btn <?= $filter==='unread'?'active':'' ?>" style="text-decoration:none">
        Unread <span class="badge badge-danger" style="margin-left:4px"><?= $unreadCount ?></span>
    </a>
</div>

<div class="card">
    <?php if ($notifs): ?>
    <div>
        <?php foreach ($notifs as $n):
            $icon     = $typeIcons[$n['type']] ?? '🔔';
            $isUnread = !$n['is_read'];
        ?>
        <div style="display:flex;align-items:flex-start;gap:14px;padding:14px 18px;
                    border-bottom:1px solid var(--border);
                    background:<?= $isUnread?'rgba(244,185,66,.04)':'var(--white)' ?>;
                    border-left:3px solid <?= $isUnread?'var(--accent)':'transparent' ?>">

            <!-- Icon -->
            <div style="width:40px;height:40px;border-radius:50%;
                        background:<?= $isUnread?'rgba(244,185,66,.15)':'var(--light)' ?>;
                        display:flex;align-items:center;justify-content:center;
                        font-size:18px;flex-shrink:0">
                <?= $icon ?>
            </div>

            <!-- Content -->
            <div style="flex:1;min-width:0">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:4px">
                    <div style="font-weight:<?= $isUnread?800:600 ?>;font-size:14px;color:var(--text)">
                        <?= e($n['title']) ?>
                    </div>
                    <span style="font-size:11px;color:var(--text-light);white-space:nowrap;flex-shrink:0">
                        <?= format_date($n['created_at'], 'd M Y H:i') ?>
                    </span>
                </div>
                <div style="font-size:13px;color:var(--text-muted);line-height:1.5">
                    <?= e($n['body']) ?>
                </div>
                <?php if ($n['link']): ?>
                    <a href="<?= e($n['link']) ?>" class="text-sm" style="color:var(--blue);margin-top:4px;display:inline-block">
                        View details →
                    </a>
                <?php endif; ?>
            </div>

            <!-- Actions -->
            <div style="display:flex;gap:6px;flex-shrink:0">
                <?php if ($isUnread): ?>
                    <form method="POST" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action"   value="mark_read">
                        <input type="hidden" name="notif_id" value="<?= $n['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline" title="Mark as read">✓</button>
                    </form>
                <?php endif; ?>
                <form method="POST" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action"   value="delete">
                    <input type="hidden" name="notif_id" value="<?= $n['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger" title="Delete">🗑️</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?= pagination_links($pager, BASE_URL . '/admin/notifications.php?filter=' . $filter) ?>
    <div class="card-footer text-sm text-muted">
        Showing <?= count($notifs) ?> of <?= $pager['total'] ?> notifications
    </div>

    <?php else: ?>
    <div class="table-empty" style="padding:60px">
        <div class="table-empty-icon" style="font-size:48px">🔔</div>
        <?= $filter==='unread' ? 'No unread notifications.' : 'No notifications yet.' ?>
    </div>
    <?php endif; ?>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
