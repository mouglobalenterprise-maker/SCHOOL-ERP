<?php
// ============================================================
// student/timetable.php — Student Timetable (Read-Only)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_STUDENT);

$pageTitle  = 'My Timetable';
$activeMenu = 'timetable';

$sess_id = current_session_id();

$student = Database::fetchOne(
    "SELECT s.*, c.name AS class_name
     FROM students s JOIN classes c ON c.id=s.class_id
     WHERE s.user_id=? AND s.session_id=?",
    [current_user_id(), $sess_id]
);
if (!$student) {
    flash_error('No student profile found.');
    redirect(BASE_URL . '/student/dashboard.php');
}

// Reuse admin timetable with student's class_id forced
$_GET['class_id'] = $student['class_id'];

// Include admin timetable page (it handles roles gracefully)
require __DIR__ . '/../admin/timetable.php';
