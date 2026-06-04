<?php
// ============================================================
// api/get_results.php — Results API Endpoint
// Returns JSON list of results. Supports filters + CSV export.
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_login();

$isAjax     = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
$sess_id    = int_val($_GET['session_id'] ?? current_session_id());
$term_id    = int_val($_GET['term_id']    ?? 0);
$class_id   = int_val($_GET['class_id']  ?? 0);
$subject_id = int_val($_GET['subject_id']?? 0);
$student_id = int_val($_GET['student_id']?? 0);
$search     = sanitize($_GET['q']        ?? '');
$export     = sanitize($_GET['export']   ?? '');
$limit      = min(500, max(1, int_val($_GET['limit'] ?? 100)));
$page       = max(1, int_val($_GET['page'] ?? 1));
$offset     = ($page - 1) * $limit;

// Build where
$where  = ['r.session_id = ?'];
$params = [$sess_id];

if ($term_id)    { $where[] = 'r.term_id = ?';    $params[] = $term_id; }
if ($class_id)   { $where[] = 'r.class_id = ?';   $params[] = $class_id; }
if ($subject_id) { $where[] = 'r.subject_id = ?'; $params[] = $subject_id; }

// Student can only see their own
if (is_student()) {
    $myStudent = Database::fetchOne("SELECT id FROM students WHERE user_id=?", [current_user_id()]);
    if ($myStudent) {
        $where[]  = 'r.student_id = ?';
        $params[] = $myStudent['id'];
    }
} elseif ($student_id) {
    $where[]  = 'r.student_id = ?';
    $params[] = $student_id;
}

// Teacher can only see their subjects/classes
if (is_teacher()) {
    $myTeacher = Database::fetchOne(
        "SELECT t.id FROM teachers t JOIN users u ON u.id=t.user_id WHERE u.id=?",
        [current_user_id()]
    );
    if ($myTeacher) {
        $where[]  = "EXISTS (SELECT 1 FROM teacher_subjects ts WHERE ts.teacher_id=? AND ts.subject_id=r.subject_id AND ts.class_id=r.class_id)";
        $params[] = $myTeacher['id'];
    }
}

if ($search) {
    $where[]  = '(s.full_name LIKE ? OR s.student_id LIKE ? OR sub.name LIKE ?)';
    $like     = "%{$search}%";
    $params   = array_merge($params, [$like, $like, $like]);
}

$whereStr = 'WHERE ' . implode(' AND ', $where);

$sql = "SELECT r.*,
               s.full_name, s.student_id AS sid,
               sub.name AS subject_name, sub.code AS subject_code,
               c.name   AS class_name,
               t.name   AS term_name,
               ses.name AS session_name
        FROM results r
        JOIN students s   ON s.id  = r.student_id
        JOIN subjects sub ON sub.id = r.subject_id
        JOIN classes  c   ON c.id  = r.class_id
        JOIN terms    t   ON t.id  = r.term_id
        JOIN academic_sessions ses ON ses.id = r.session_id
        {$whereStr}
        ORDER BY c.sort_order, s.full_name, sub.name";

// ── CSV Export ─────────────────────────────────────────────
if ($export === 'csv') {
    if (!is_admin() && !is_teacher()) {
        http_response_code(403); die('Access denied.');
    }

    $rows = Database::fetchAll($sql, $params);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="results_export_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'Student ID','Student Name','Class','Subject','Term','Session',
        'Test Score','Assignment Score','Exam Score','Total Score','Grade','Remark','Teacher Comment'
    ]);

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['sid'],          $r['full_name'],      $r['class_name'],
            $r['subject_name'], $r['term_name'],       $r['session_name'],
            $r['test_score'],   $r['assignment_score'],$r['exam_score'],
            $r['total_score'],  $r['grade'],           $r['remark'],
            $r['teacher_comment']
        ]);
    }
    fclose($out);

    audit_log(current_user_id(), current_username(), 'export_results', 'Results',
        "Exported results CSV");
    exit;
}

// ── AJAX search HTML response ──────────────────────────────
if ($isAjax && isset($_GET['type']) && $_GET['type'] === 'results') {
    $rows = Database::fetchAll($sql . " LIMIT {$limit} OFFSET {$offset}", $params);

    if (empty($rows)) {
        echo json_encode(['html' => '<tr><td colspan="9" class="table-empty">No results found.</td></tr>']);
        exit;
    }

    $html = '';
    foreach ($rows as $r) {
        $html .= '<tr>';
        $html .= '<td><div style="font-weight:700">' . e($r['full_name']) . '</div><div class="text-xs text-muted">' . e($r['sid']) . '</div></td>';
        $html .= '<td><span class="badge badge-navy">' . e($r['class_name']) . '</span></td>';
        $html .= '<td>' . e($r['subject_name']) . '</td>';
        $html .= '<td>' . e($r['term_name']) . '</td>';
        $html .= '<td style="color:var(--blue);font-weight:700">' . $r['test_score'] . '</td>';
        $html .= '<td style="color:var(--purple);font-weight:700">' . $r['assignment_score'] . '</td>';
        $html .= '<td style="color:var(--emerald);font-weight:700">' . $r['exam_score'] . '</td>';
        $html .= '<td><strong style="font-size:15px">' . number_format($r['total_score'],1) . '</strong></td>';
        $html .= '<td>' . grade_badge($r['grade'] ?? 'F') . '</td>';
        if (is_admin() || is_teacher()) {
            $html .= '<td><div class="td-actions">';
            $html .= '<a href="' . BASE_URL . '/admin/results_enter.php?edit=' . $r['id'] . '" class="btn btn-sm btn-primary">✏️</a>';
            $html .= '</div></td>';
        }
        $html .= '</tr>';
    }
    echo json_encode(['html' => $html, 'count' => count($rows)]);
    exit;
}

// ── Standard JSON ─────────────────────────────────────────
$total = (int)(Database::fetchOne(
    "SELECT COUNT(*) AS c FROM results r
     JOIN students s ON s.id=r.student_id
     JOIN subjects sub ON sub.id=r.subject_id
     {$whereStr}", $params
)['c'] ?? 0);

$rows = Database::fetchAll($sql . " LIMIT {$limit} OFFSET {$offset}", $params);

// Compute summary stats
$avgScore = $total > 0 ? array_sum(array_column($rows,'total_score')) / count($rows) : 0;

echo json_encode([
    'success'   => true,
    'total'     => $total,
    'page'      => $page,
    'limit'     => $limit,
    'pages'     => (int)ceil($total / max(1,$limit)),
    'avg_score' => round($avgScore, 2),
    'data'      => $rows,
]);
