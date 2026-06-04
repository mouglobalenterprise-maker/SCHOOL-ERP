<?php
// ============================================================
// config/config.php — Global Application Constants & Bootstrap
// ============================================================

// ── Directory paths ──────────────────────────────────────────
define('ROOT_PATH',    dirname(__DIR__));
define('CONFIG_PATH',  ROOT_PATH . '/config');
define('INCLUDES_PATH',ROOT_PATH . '/includes');
define('ASSETS_PATH',  ROOT_PATH . '/assets');
define('UPLOADS_PATH', ROOT_PATH . '/uploads');
define('LOGS_PATH',    ROOT_PATH . '/logs');
define('EXPORTS_PATH', ROOT_PATH . '/exports');

// ── URL paths ────────────────────────────────────────────────
define('BASE_URL', 'http://localhost/edumanage');   // Change for production
define('ASSETS_URL',  BASE_URL . '/assets');
define('UPLOADS_URL', BASE_URL . '/uploads');

// ── App meta ─────────────────────────────────────────────────
define('APP_NAME',    'EduManage Pro');
define('APP_VERSION', '1.0.0');
define('APP_AUTHOR',  'EduManage');

// ── Session config ───────────────────────────────────────────
define('SESSION_NAME',    'EDUMANAGE_SESSION');
define('SESSION_LIFETIME', 7200);   // 2 hours in seconds

// ── Security ─────────────────────────────────────────────────
define('HASH_ALGO',   PASSWORD_BCRYPT);
define('HASH_COST',   10);
define('CSRF_TOKEN_NAME', 'csrf_token');

// ── Pagination ───────────────────────────────────────────────
define('ROWS_PER_PAGE', 25);

// ── Upload limits ────────────────────────────────────────────
define('MAX_UPLOAD_SIZE',  5 * 1024 * 1024);  // 5 MB
define('ALLOWED_IMG_TYPES', ['image/jpeg','image/png','image/gif','image/webp']);
define('ALLOWED_DOC_TYPES', ['application/pdf','application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
define('ALLOWED_CSV_TYPES', ['text/csv','text/plain','application/csv',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);

// ── Role IDs (must match DB) ─────────────────────────────────
define('ROLE_ADMIN',     1);
define('ROLE_TEACHER',   2);
define('ROLE_STUDENT',   3);
define('ROLE_SECRETARY', 4);

// ── Role names ───────────────────────────────────────────────
define('ROLE_NAMES', [
    ROLE_ADMIN     => 'Admin',
    ROLE_TEACHER   => 'Teacher',
    ROLE_STUDENT   => 'Student',
    ROLE_SECRETARY => 'Secretary',
]);

// ── Module → allowed roles ───────────────────────────────────
define('MODULE_ACCESS', [
    'dashboard'   => [ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT, ROLE_SECRETARY],
    'students'    => [ROLE_ADMIN, ROLE_SECRETARY],
    'teachers'    => [ROLE_ADMIN],
    'classes'     => [ROLE_ADMIN],
    'subjects'    => [ROLE_ADMIN],
    'results'     => [ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT],
    'attendance'  => [ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT],
    'assignments' => [ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT],
    'timetable'   => [ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT],
    'payments'    => [ROLE_ADMIN, ROLE_SECRETARY, ROLE_STUDENT],
    'announcements'=> [ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT, ROLE_SECRETARY],
    'messages'    => [ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT, ROLE_SECRETARY],
    'report_cards'=> [ROLE_ADMIN, ROLE_STUDENT],
    'whatsapp'    => [ROLE_ADMIN, ROLE_SECRETARY],
    'analytics'   => [ROLE_ADMIN],
    'bulk_import' => [ROLE_ADMIN],
    'audit_logs'  => [ROLE_ADMIN],
    'backup'      => [ROLE_ADMIN],
    'settings'    => [ROLE_ADMIN],
    'promotions'  => [ROLE_ADMIN],
    'documents'   => [ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT],
]);

// ── Days of the week ─────────────────────────────────────────
define('WEEKDAYS', ['Monday','Tuesday','Wednesday','Thursday','Friday']);

// ── WhatsApp link base ───────────────────────────────────────
define('WA_BASE_URL', 'https://wa.me/');

// ── Error reporting (set to 0 in production) ─────────────────
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ── Timezone ─────────────────────────────────────────────────
date_default_timezone_set('Africa/Banjul');

// ── Auto-create required directories ─────────────────────────
foreach ([UPLOADS_PATH, LOGS_PATH, EXPORTS_PATH,
          UPLOADS_PATH . '/photos',
          UPLOADS_PATH . '/assignments',
          UPLOADS_PATH . '/documents',
          UPLOADS_PATH . '/logos'] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ── Require core files ───────────────────────────────────────
require_once CONFIG_PATH  . '/db.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/functions.php';
