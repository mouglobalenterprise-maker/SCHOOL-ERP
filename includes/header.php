<?php
// ============================================================
// includes/header.php — Global Page Header
// Usage: include at top of every authenticated page AFTER
//        require_once config.php and require_login()
//
// Expects $pageTitle to be set before including.
// ============================================================

$pageTitle   = $pageTitle   ?? 'Dashboard';
$activeMenu  = $activeMenu  ?? '';
$schoolName  = get_setting('school_name', APP_NAME);
$unreadCount = is_logged_in() ? count_unread_notifications(current_user_id()) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e($schoolName) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <!-- Page-specific CSS injected via $extraCss -->
    <?php if (!empty($extraCss)): ?>
        <?php foreach ((array)$extraCss as $css): ?>
            <link rel="stylesheet" href="<?= e($css) ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body>
<div class="app-wrapper">

    <!-- ── Sidebar ── -->
    <?php include INCLUDES_PATH . '/sidebar.php'; ?>

    <!-- ── Main content area ── -->
    <div class="main-area">

        <!-- ── Top navbar ── -->
        <header class="topnav">
            <div class="topnav-left">
                <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                    ☰
                </button>
                <nav class="breadcrumb-nav">
                    <span class="bc-home">
                        <a href="<?= BASE_URL ?>/admin/dashboard.php">🏠</a>
                    </span>
                    <span class="bc-sep">›</span>
                    <span class="bc-current"><?= e($pageTitle) ?></span>
                </nav>
            </div>

            <div class="topnav-right">
                <!-- Session / Term info -->
                <div class="topnav-meta hidden-sm">
                    <span class="meta-pill">
                        📚 <?= e(get_setting('current_session', '—')) ?>
                        &nbsp;|&nbsp;
                        <?= e(get_setting('current_term', '—')) ?> Term
                    </span>
                </div>

                <!-- Notifications bell -->
                <div class="notif-wrap" id="notifWrap">
                    <button class="notif-btn" id="notifBtn" title="Notifications">
                        🔔
                        <?php if ($unreadCount > 0): ?>
                            <span class="notif-badge"><?= $unreadCount ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notif-dropdown" id="notifDropdown" style="display:none;">
                        <div class="notif-header">
                            <strong>Notifications</strong>
                            <?php if ($unreadCount > 0): ?>
                                <a href="<?= BASE_URL ?>/admin/notifications.php?mark_all=1" class="notif-mark-all">Mark all read</a>
                            <?php endif; ?>
                        </div>
                        <?php
                        $notifs = get_unread_notifications(current_user_id());
                        if ($notifs): foreach ($notifs as $n): ?>
                            <a href="<?= e($n['link'] ?: '#') ?>" class="notif-item">
                                <div class="notif-item-title"><?= e($n['title']) ?></div>
                                <div class="notif-item-body"><?= e(substr($n['body'], 0, 80)) ?>…</div>
                                <div class="notif-item-time"><?= format_date($n['created_at'], 'd M H:i') ?></div>
                            </a>
                        <?php endforeach; else: ?>
                            <div class="notif-empty">✅ No new notifications</div>
                        <?php endif; ?>
                        <div class="notif-footer">
                            <a href="<?= BASE_URL ?>/admin/notifications.php">View all</a>
                        </div>
                    </div>
                </div>

                <!-- User dropdown -->
                <div class="user-wrap" id="userWrap">
                    <button class="user-btn" id="userBtn">
                        <div class="user-avatar">
                            <?= strtoupper(substr(current_full_name(), 0, 1)) ?>
                        </div>
                        <div class="user-info hidden-sm">
                            <span class="user-name"><?= e(current_full_name()) ?></span>
                            <span class="user-role"><?= e(ucfirst(current_role_name())) ?></span>
                        </div>
                        <span class="user-caret">▾</span>
                    </button>
                    <div class="user-dropdown" id="userDropdown" style="display:none;">
                        <div class="user-dropdown-header">
                            <div class="user-avatar-lg"><?= strtoupper(substr(current_full_name(), 0, 1)) ?></div>
                            <div>
                                <div class="ud-name"><?= e(current_full_name()) ?></div>
                                <div class="ud-role"><?= e(ucfirst(current_role_name())) ?></div>
                            </div>
                        </div>
                        <a href="<?= BASE_URL ?>/admin/profile.php" class="ud-item">👤 My Profile</a>
                        <?php if (is_admin()): ?>
                            <a href="<?= BASE_URL ?>/admin/settings.php" class="ud-item">⚙️ Settings</a>
                        <?php endif; ?>
                        <div class="ud-divider"></div>
                        <a href="<?= BASE_URL ?>/logout.php" class="ud-item ud-logout">🚪 Logout</a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Flash messages -->
        <div class="flash-container">
            <?= flash_render() ?>
        </div>

        <!-- ── Page content starts here ── -->
        <main class="main-content">
