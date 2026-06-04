<?php
// ============================================================
// api/get_students.php — Students API Endpoint
// Returns JSON list of students. Supports search + CSV export.
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_login();

// Only allow AJAX or admin/secretary roles
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

// ── Parameters ────────────────────────────────────────────────
$search    = sanitize($_GET['q']         ?? '');
$classId   = int_val($_GET['class_id']   ?? 0);
$sessId    = int_val($_GET['session_id'] ?? current_session_id());
$status    = sanitize($_GET['status']    ?? '');
$export    = sanitize($_GET['export']    ?? '');
$limit     = min(500, max(1, int_val($_GET['limit'] ?? 50)));
$page      = max(1,   int_val($_GET['page']  ?? 1));
$offset    = ($page - 1) * $limit;

// ── Build query ───────────────────────────────────────────────
$where  = ['s.session_id = ?'];
$params = [$sessId];

if ($search) {
    $where[]  = '(s.full_name LIKE ? OR s.student_id LIKE ? OR s.parent_name LIKE ? OR s.parent_phone1 LIKE ? OR s.parent_phone2 LIKE ?)';
    $like     = "%{$search}%";
    $params   = array_merge($params, [$like, $like, $like, $like, $like]);
}
if ($classId) { $where[] = 's.class_id = ?';   $params[] = $classId; }
if ($status)  { $where[] = 's.status = ?';     $params[] = $status;  }

// Role restriction: students only see their own record
if (is_student()) {
    $myStudent = Database::fetchOne("SELECT id FROM students WHERE user_id = ?", [current_user_id()]);
    if ($myStudent) {
        $where[]  = 's.id = ?';
        $params[] = $myStudent['id'];
    } else {
        json_response(false, 'No student record linked to your account.', ['data' => []]);
    }
}

$whereStr = 'WHERE ' . implode(' AND ', $where);
$sql = "SELECT s.id, s.student_id, s.full_name, s.gender, s.dob,
               s.parent_name, s.parent_phone1, s.parent_phone2,
               s.parent_email, s.address, s.blood_group, s.status,
               s.enrolled_date, s.created_at,
               c.name AS class_name, c.id AS class_id,
               ses.name AS session_name
        FROM students s
        JOIN classes c ON c.id = s.class_id
        JOIN academic_sessions ses ON ses.id = s.session_id
        {$whereStr}
        ORDER BY c.sort_order, s.full_name";

// ── CSV Export ────────────────────────────────────────────────
if ($export === 'csv') {
    // Only admin/secretary can export
    if (!is_admin() && !is_secretary()) {
        http_response_code(403);
        die('Access denied.');
    }

    $students = Database::fetchAll($sql, $params);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="students_export_' . date('Ymd_His') . '.csv"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    // BOM for Excel UTF-8
    fputs($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'Student ID','Full Name','Gender','Date of Birth','Class',
        'Parent Name','Parent Phone 1','Parent Phone 2','Parent Email',
        'Address','Blood Group','Status','Enrolled Date','Session'
    ]);

    foreach ($students as $s) {
        fputcsv($out, [
            $s['student_id'],  $s['full_name'],   $s['gender'],
            $s['dob'],         $s['class_name'],  $s['parent_name'],
            $s['parent_phone1'],$s['parent_phone2'],$s['parent_email'],
            $s['address'],     $s['blood_group'], $s['status'],
            $s['enrolled_date'],$s['session_name']
        ]);
    }
    fclose($out);

    audit_log(current_user_id(), current_username(), 'export_students', 'Students',
        "Exported students CSV — session {$sessId}");
    exit;
}

// ── JSON Response ─────────────────────────────────────────────
// For AJAX table search — return HTML rows
if ($isAjax && isset($_GET['type']) && $_GET['type'] === 'students') {
    $students = Database::fetchAll($sql . " LIMIT {$limit} OFFSET {$offset}", $params);

    if (empty($students)) {
        echo json_encode(['html' => '<tr><td colspan="10" class="table-empty">No students found.</td></tr>']);
        exit;
    }

    $html = '';
    $i    = $offset + 1;
    $schoolName = get_setting('school_name');
    foreach ($students as $s) {
        $waMsg  = 'Hello, this is ' . $schoolName . '.';
        $waLnk1 = wa_link($s['parent_phone1'], $waMsg);
        $waLnk2 = wa_link($s['parent_phone2'], $waMsg);
        $status = status_badge($s['status']);
        $html  .= '<tr>';
        $html  .= "<td class='text-muted text-sm'>{$i}</td>";
        $html  .= "<td><span class='code'>" . e($s['student_id']) . "</span></td>";
        $html  .= "<td><div style='font-weight:700'>" . e($s['full_name']) . "</div></td>";
        $html  .= "<td><span class='badge badge-navy'>" . e($s['class_name']) . "</span></td>";
        $html  .= "<td>" . e($s['gender']) . "</td>";
        $html  .= "<td>" . e($s['parent_name'] ?? '—') . "</td>";
        $html  .= "<td><a href='" . e($waLnk1) . "' target='_blank' class='btn btn-sm btn-whatsapp'>📲 " . e($s['parent_phone1']) . "</a></td>";
        $html  .= "<td><a href='" . e($waLnk2) . "' target='_blank' class='btn btn-sm btn-wa-dark'>📲 " . e($s['parent_phone2']) . "</a></td>";
        $html  .= "<td>{$status}</td>";
        $html  .= "<td><div class='td-actions'>";
        $html  .= "<a href='" . BASE_URL . "/admin/students_view.php?id={$s['id']}' class='btn btn-sm btn-outline'>👁️</a>";
        if (is_admin()) {
            $html .= "<a href='" . BASE_URL . "/admin/students_edit.php?id={$s['id']}' class='btn btn-sm btn-primary'>✏️</a>";
        }
        $html  .= "</div></td>";
        $html  .= '</tr>';
        $i++;
    }

    echo json_encode(['html' => $html, 'count' => count($students)]);
    exit;
}

// ── Standard JSON response ────────────────────────────────────
$countRow = Database::fetchOne(
    "SELECT COUNT(*) AS c FROM students s {$whereStr}",
    $params
);
$total    = (int)($countRow['c'] ?? 0);
$students = Database::fetchAll($sql . " LIMIT {$limit} OFFSET {$offset}", $params);

// Remove sensitive data for non-admins
if (!is_admin() && !is_secretary()) {
    $students = array_map(function($s) {
        unset($s['parent_email'], $s['address'], $s['blood_group'], $s['medical_notes']);
        return $s;
    }, $students);
}

echo json_encode([
    'success' => true,
    'total'   => $total,
    'page'    => $page,
    'limit'   => $limit,
    'pages'   => (int)ceil($total / $limit),
    'data'    => $students,
]);
