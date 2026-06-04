<?php
// ============================================================
// api/get_attendance.php — Attendance API Endpoint
// Returns JSON list of attendance records. Supports CSV export.
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_login();

$isAjax   = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
$term_id  = int_val($_GET['term_id']   ?? current_term_id());
$class_id = int_val($_GET['class_id']  ?? 0);
$date     = sanitize($_GET['date']     ?? '');
$status   = sanitize($_GET['status']   ?? '');
$export   = sanitize($_GET['export']   ?? '');
$limit    = min(500, max(1, int_val($_GET['limit'] ?? 100)));
$page     = max(1, int_val($_GET['page'] ?? 1));
$offset   = ($page - 1) * $limit;

// Build WHERE
$where  = ['a.term_id = ?'];
$params = [$term_id];

if ($class_id) { $where[] = 'a.class_id = ?';  $params[] = $class_id; }
if ($date)     { $where[] = 'a.date = ?';       $params[] = $date; }
if ($status && in_array($status,['present','absent','late'])) {
    $where[]  = 'a.status = ?';
    $params[] = $status;
}

// Student can only see own records
if (is_student()) {
    $myStudent = Database::fetchOne("SELECT id FROM students WHERE user_id=?", [current_user_id()]);
    if ($myStudent) {
        $where[]  = 'a.student_id = ?';
        $params[] = $myStudent['id'];
    }
}

// Teacher sees assigned classes only
if (is_teacher()) {
    $myTeacher = Database::fetchOne(
        "SELECT t.id FROM teachers t JOIN users u ON u.id=t.user_id WHERE u.id=?",
        [current_user_id()]
    );
    if ($myTeacher) {
        $where[]  = "EXISTS (SELECT 1 FROM teacher_subjects ts WHERE ts.teacher_id=? AND ts.class_id=a.class_id)";
        $params[] = $myTeacher['id'];
    }
}

$whereStr = 'WHERE ' . implode(' AND ', $where);

$sql = "SELECT a.*,
               s.full_name, s.student_id AS sid,
               c.name AS class_name,
               u.full_name AS marked_by_name
        FROM attendance a
        JOIN students s ON s.id = a.student_id
        JOIN classes  c ON c.id = a.class_id
        LEFT JOIN users u ON u.id = a.marked_by
        {$whereStr}
        ORDER BY a.date DESC, s.full_name";

// ── CSV Export ─────────────────────────────────────────────
if ($export === 'csv') {
    if (!is_admin() && !is_teacher()) {
        http_response_code(403); die('Access denied.');
    }

    $rows = Database::fetchAll($sql, $params);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="attendance_export_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    fputcsv($out, ['Date','Day','Student ID','Student Name','Class','Status','Note','Marked By']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['date'],
            date('l', strtotime($r['date'])),
            $r['sid'],
            $r['full_name'],
            $r['class_name'],
            ucfirst($r['status']),
            $r['note'] ?? '',
            $r['marked_by_name'] ?? '',
        ]);
    }
    fclose($out);

    audit_log(current_user_id(), current_username(), 'export_attendance', 'Attendance',
        "Exported attendance CSV — term {$term_id}");
    exit;
}

// ── Summary endpoint ────────────────────────────────────────
if (isset($_GET['summary'])) {
    $studentId = int_val($_GET['student_id'] ?? 0);
    if (!$studentId) {
        json_response(false, 'Student ID required.');
    }

    $summary = Database::fetchOne(
        "SELECT COUNT(*) AS total,
                SUM(status='present') AS present,
                SUM(status='absent')  AS absent,
                SUM(status='late')    AS late
         FROM attendance WHERE student_id=? AND term_id=?",
        [$studentId, $term_id]
    );
    $total   = (int)($summary['total']   ?? 0);
    $present = (int)($summary['present'] ?? 0);
    $rate    = $total > 0 ? round(($present/$total)*100,1) : 0;

    echo json_encode([
        'success'  => true,
        'total'    => $total,
        'present'  => $present,
        'absent'   => (int)($summary['absent'] ?? 0),
        'late'     => (int)($summary['late']   ?? 0),
        'rate'     => $rate,
        'rate_str' => $rate . '%',
    ]);
    exit;
}

// ── Standard JSON ─────────────────────────────────────────
$countRow = Database::fetchOne(
    "SELECT COUNT(*) AS c FROM attendance a
     JOIN students s ON s.id=a.student_id
     JOIN classes  c ON c.id=a.class_id
     {$whereStr}", $params
);
$total = (int)($countRow['c'] ?? 0);
$rows  = Database::fetchAll($sql . " LIMIT {$limit} OFFSET {$offset}", $params);

echo json_encode([
    'success' => true,
    'total'   => $total,
    'page'    => $page,
    'limit'   => $limit,
    'pages'   => (int)ceil($total / max(1,$limit)),
    'data'    => $rows,
]);
