<?php
// ============================================================
// includes/sidebar.php — Dynamic Role-Based Sidebar
// ============================================================

// Build navigation based on current user role
$roleId = current_role_id();

// Full nav definition — role access is checked per item
$navItems = [
    // Section: Main
    ['section' => 'Main'],
    [
        'id'    => 'dashboard',
        'label' => 'Dashboard',
        'icon'  => '📊',
        'url'   => BASE_URL . '/admin/dashboard.php',
        'roles' => [ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT, ROLE_SECRETARY],
        'url_teacher'   => BASE_URL . '/teacher/dashboard.php',
        'url_student'   => BASE_URL . '/student/dashboard.php',
        'url_secretary' => BASE_URL . '/secretary/dashboard.php',
    ],

    // Section: Academic
    ['section' => 'Academic'],
    [
        'id'    => 'students',
        'label' => 'Students',
        'icon'  => '👨‍🎓',
        'url'   => BASE_URL . '/admin/students.php',
        'roles' => [ROLE_ADMIN, ROLE_SECRETARY],
    ],
    [
        'id'    => 'teachers',
        'label' => 'Teachers',
        'icon'  => '👨‍🏫',
        'url'   => BASE_URL . '/admin/teachers.php',
        'roles' => [ROLE_ADMIN],
    ],
    [
        'id'    => 'classes',
        'label' => 'Classes',
        'icon'  => '🏛️',
        'url'   => BASE_URL . '/admin/classes.php',
        'roles' => [ROLE_ADMIN],
    ],
    [
        'id'    => 'subjects',
        'label' => 'Subjects',
        'icon'  => '📘',
        'url'   => BASE_URL . '/admin/subjects.php',
        'roles' => [ROLE_ADMIN],
    ],
    [
        'id'    => 'results',
        'label' => 'Results',
        'icon'  => '📈',
        'url'   => BASE_URL . '/admin/results.php',
        'roles' => [ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT],
        'url_teacher' => BASE_URL . '/teacher/results.php',
        'url_student' => BASE_URL . '/student/results.php',
    ],
    [
        'id'    => 'attendance',
        'label' => 'Attendance',
        'icon'  => '📅',
        'url'   => BASE_URL . '/admin/attendance.php',
        'roles' => [ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT],
        'url_teacher' => BASE_URL . '/teacher/attendance.php',
        'url_student' => BASE_URL . '/student/attendance.php',
    ],
    [
        'id'    => 'assignments',
        'label' => 'Assignments',
        'icon'  => '📚',
        'url'   => BASE_URL . '/admin/assignments.php',
        'roles' => [ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT],
        'url_teacher' => BASE_URL . '/teacher/assignments.php',
        'url_student' => BASE_URL . '/student/assignments.php',
    ],
    [
        'id'    => 'timetable',
        'label' => 'Timetable',
        'icon'  => '📆',
        'url'   => BASE_URL . '/admin/timetable.php',
        'roles' => [ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT],
        'url_student' => BASE_URL . '/student/timetable.php',
    ],
    [
        'id'    => 'report_cards',
        'label' => 'Report Cards',
        'icon'  => '📜',
        'url'   => BASE_URL . '/admin/report_cards.php',
        'roles' => [ROLE_ADMIN, ROLE_STUDENT],
        'url_student' => BASE_URL . '/student/report_card.php',
    ],
    [
        'id'    => 'documents',
        'label' => 'Documents',
        'icon'  => '📂',
        'url'   => BASE_URL . '/admin/documents.php',
        'roles' => [ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT],
    ],

    // Section: Communication
    ['section' => 'Communication'],
    [
        'id'    => 'announcements',
        'label' => 'Announcements',
        'icon'  => '📢',
        'url'   => BASE_URL . '/admin/announcements.php',
        'roles' => [ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT, ROLE_SECRETARY],
        'url_student' => BASE_URL . '/student/announcements.php',
    ],
    [
        'id'    => 'messages',
        'label' => 'Messages',
        'icon'  => '💬',
        'url'   => BASE_URL . '/admin/messages.php',
        'roles' => [ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT, ROLE_SECRETARY],
        'url_student' => BASE_URL . '/student/messages.php',
    ],
    [
        'id'    => 'whatsapp',
        'label' => 'WhatsApp',
        'icon'  => '📲',
        'url'   => BASE_URL . '/admin/whatsapp.php',
        'roles' => [ROLE_ADMIN, ROLE_SECRETARY],
    ],

    // Section: Finance
    ['section' => 'Finance'],
    [
        'id'    => 'payments',
        'label' => 'Payments',
        'icon'  => '💳',
        'url'   => BASE_URL . '/admin/payments.php',
        'roles' => [ROLE_ADMIN, ROLE_SECRETARY, ROLE_STUDENT],
        'url_secretary' => BASE_URL . '/secretary/payments.php',
        'url_student'   => BASE_URL . '/student/payments.php',
    ],

    // Section: Reports & Analytics
    ['section' => 'Reports'],
    [
        'id'    => 'analytics',
        'label' => 'Analytics',
        'icon'  => '📉',
        'url'   => BASE_URL . '/admin/analytics.php',
        'roles' => [ROLE_ADMIN],
    ],

    // Section: System
    ['section' => 'System'],
    [
        'id'    => 'bulk_import',
        'label' => 'Bulk Import',
        'icon'  => '📥',
        'url'   => BASE_URL . '/admin/bulk_import.php',
        'roles' => [ROLE_ADMIN],
    ],
    [
        'id'    => 'promotions',
        'label' => 'Promotions',
        'icon'  => '🔄',
        'url'   => BASE_URL . '/admin/promotions.php',
        'roles' => [ROLE_ADMIN],
    ],
    [
        'id'    => 'audit_logs',
        'label' => 'Audit Logs',
        'icon'  => '🔍',
        'url'   => BASE_URL . '/admin/audit_logs.php',
        'roles' => [ROLE_ADMIN],
    ],
    [
        'id'    => 'backup',
        'label' => 'Backup & Restore',
        'icon'  => '☁️',
        'url'   => BASE_URL . '/admin/backup.php',
        'roles' => [ROLE_ADMIN],
    ],
    [
        'id'    => 'settings',
        'label' => 'Settings',
        'icon'  => '⚙️',
        'url'   => BASE_URL . '/admin/settings.php',
        'roles' => [ROLE_ADMIN],
    ],
];

// Resolve URL for current role
function resolve_nav_url(array $item, int $roleId): string {
    return match($roleId) {
        ROLE_TEACHER   => $item['url_teacher']   ?? $item['url'],
        ROLE_STUDENT   => $item['url_student']   ?? $item['url'],
        ROLE_SECRETARY => $item['url_secretary'] ?? $item['url'],
        default        => $item['url'],
    };
}
?>

<aside class="sidebar" id="sidebar">

    <!-- Logo / School name -->
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <?php $logo = get_setting('school_logo'); ?>
            <?php if ($logo && file_exists(UPLOADS_PATH . '/logos/' . $logo)): ?>
                <img src="<?= UPLOADS_URL ?>/logos/<?= e($logo) ?>" alt="Logo" class="sidebar-logo-img">
            <?php else: ?>
                <div class="sidebar-logo-icon">🎓</div>
            <?php endif; ?>
        </div>
        <div class="sidebar-brand">
            <div class="sidebar-brand-name"><?= e(get_setting('school_name', APP_NAME)) ?></div>
            <div class="sidebar-brand-sub">ERP System</div>
        </div>
    </div>

    <!-- User card -->
    <div class="sidebar-user">
        <div class="sidebar-user-avatar">
            <?= strtoupper(substr(current_full_name(), 0, 1)) ?>
        </div>
        <div class="sidebar-user-info">
            <div class="sidebar-user-name"><?= e(current_full_name()) ?></div>
            <div class="sidebar-user-role"><?= e(ucfirst(current_role_name())) ?></div>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav" id="sidebarNav">
        <?php foreach ($navItems as $item): ?>

            <?php if (isset($item['section'])): ?>
                <div class="sidebar-section-label"><?= e($item['section']) ?></div>

            <?php elseif (in_array($roleId, $item['roles'], true)): ?>
                <?php
                $url     = resolve_nav_url($item, $roleId);
                $isActive = ($activeMenu === $item['id']);
                ?>
                <a href="<?= e($url) ?>"
                   class="sidebar-nav-item <?= $isActive ? 'active' : '' ?>"
                   title="<?= e($item['label']) ?>">
                    <span class="nav-icon"><?= $item['icon'] ?></span>
                    <span class="nav-label"><?= e($item['label']) ?></span>
                    <?php if ($isActive): ?>
                        <span class="nav-active-bar"></span>
                    <?php endif; ?>
                </a>

            <?php endif; ?>

        <?php endforeach; ?>
    </nav>

    <!-- Sidebar footer -->
    <div class="sidebar-footer">
        <a href="<?= BASE_URL ?>/logout.php" class="sidebar-logout">
            <span>🚪</span>
            <span class="nav-label">Logout</span>
        </a>
        <div class="sidebar-version"><?= APP_NAME ?> v<?= APP_VERSION ?></div>
    </div>

</aside>

<!-- Overlay for mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>
