<?php
// ============================================================
// student/report_card.php — Student Report Card Portal
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_STUDENT);

// The admin/report_cards.php already handles ROLE_STUDENT
// locking the view to their own student record
require __DIR__ . '/../admin/report_cards.php';
