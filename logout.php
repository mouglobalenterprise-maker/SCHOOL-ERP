<?php
// ============================================================
// logout.php — Secure Logout Handler
// ============================================================
require_once __DIR__ . '/config/config.php';

// CSRF check for POST logout (optional but best practice)
// GET logout is also supported for simplicity (sidebar link)
auth_logout();

redirect(BASE_URL . '/login.php');
