<?php
// ============================================================
// teacher/assignments.php — Teacher Assignment Portal
// Delegates to admin/assignments.php with teacher-scoped view
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_TEACHER);

// Teacher assignment portal just uses the admin page with teacher session
// This keeps code DRY — the admin/assignments.php already handles teacher RBAC
require __DIR__ . '/../admin/assignments.php';
