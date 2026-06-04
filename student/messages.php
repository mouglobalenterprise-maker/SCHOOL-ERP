<?php
// ============================================================
// student/messages.php — Student Messages Portal
// Delegates to admin/messages.php (RBAC handled inside)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_STUDENT);

// The messages system is fully role-aware — students see
// only their own inbox/sent, cannot broadcast, compose to
// any active user (teachers/admin)
require __DIR__ . '/../admin/messages.php';
