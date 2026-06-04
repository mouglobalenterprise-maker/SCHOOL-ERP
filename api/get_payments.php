<?php
// ============================================================
// api/get_payments.php — Payments API Endpoint
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_login();

// Only admin and secretary can access payments API
if (!is_admin() && !is_secretary() && !is_student()) {
    http_response_code(403);
    json_response(false, 'Access denied.');
}

$sess_id  = int_val($_GET['session_id'] ?? current_session_id());
$term_id  = int_val($_GET['term_id']   ?? 0);
$class_id = int_val($_GET['class_id']  ?? 0);
$status   = sanitize($_GET['status']   ?? '');
$export   = sanitize($_GET['export']   ?? '');
$limit    = min(500, max(1, int_val($_GET['limit'] ?? 100)));
$page     = max(1, int_val($_GET['page'] ?? 1));
$offset   = ($page - 1) * $limit;

$where  = ['p.session_id = ?'];
$params = [$sess_id];

if ($term_id)  { $where[] = 'p.term_id = ?';  $params[] = $term_id; }
if ($class_id) { $where[] = 's.class_id = ?'; $params[] = $class_id; }
if ($status && in_array($status,['paid','partial','unpaid'])) {
    $where[]  = 'p.status = ?';
    $params[] = $status;
}

// Students only see their own
if (is_student()) {
    $my = Database::fetchOne("SELECT id FROM students WHERE user_id=?", [current_user_id()]);
    if ($my) { $where[] = 'p.student_id = ?'; $params[] = $my['id']; }
}

$whereStr = 'WHERE ' . implode(' AND ', $where);

$sql = "SELECT p.*,
               s.full_name, s.student_id AS sid, s.parent_phone1, s.parent_phone2,
               c.name AS class_name,
               t.name AS term_name,
               ses.name AS session_name
        FROM payments p
        JOIN students s   ON s.id  = p.student_id
        JOIN classes  c   ON c.id  = s.class_id
        JOIN terms    t   ON t.id  = p.term_id
        JOIN academic_sessions ses ON ses.id = p.session_id
        {$whereStr}
        ORDER BY p.created_at DESC";

// ── CSV Export ─────────────────────────────────────────────
if ($export === 'csv') {
    if (!is_admin() && !is_secretary()) {
        http_response_code(403); die('Access denied.');
    }
    $rows = Database::fetchAll($sql, $params);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="payments_export_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output','w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Payment Code','Student ID','Student Name','Class','Term','Session',
        'Fee Type','Amount Due','Amount Paid','Balance','Payment Method',
        'Receipt No','Payment Date','Status','Notes'
    ]);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['payment_code'], $r['sid'],           $r['full_name'],
            $r['class_name'],   $r['term_name'],      $r['session_name'],
            $r['fee_type'],     $r['amount_due'],     $r['amount_paid'],
            $r['balance'],      $r['payment_method'], $r['receipt_no'],
            $r['payment_date'], $r['status'],         $r['notes'],
        ]);
    }
    fclose($out);
    audit_log(current_user_id(), current_username(), 'export_payments', 'Payments',
        'Exported payments CSV');
    exit;
}

// ── Summary stats ─────────────────────────────────────────
if (isset($_GET['summary'])) {
    $s = Database::fetchOne(
        "SELECT SUM(p.amount_due) AS total_due,
                SUM(p.amount_paid) AS total_paid,
                SUM(p.amount_due-p.amount_paid) AS total_balance,
                COUNT(CASE WHEN p.status='paid'    THEN 1 END) AS paid_count,
                COUNT(CASE WHEN p.status='partial' THEN 1 END) AS partial_count,
                COUNT(CASE WHEN p.status='unpaid'  THEN 1 END) AS unpaid_count
         FROM payments p
         JOIN students s ON s.id=p.student_id
         JOIN classes  c ON c.id=s.class_id
         {$whereStr}", $params
    );
    $due  = (float)($s['total_due']  ?? 0);
    $paid = (float)($s['total_paid'] ?? 0);
    echo json_encode([
        'success'         => true,
        'total_due'       => $due,
        'total_paid'      => $paid,
        'total_balance'   => (float)($s['total_balance'] ?? 0),
        'collection_rate' => $due > 0 ? round(($paid/$due)*100,1) : 0,
        'paid_count'      => (int)($s['paid_count']    ?? 0),
        'partial_count'   => (int)($s['partial_count'] ?? 0),
        'unpaid_count'    => (int)($s['unpaid_count']  ?? 0),
    ]);
    exit;
}

// ── Standard JSON ─────────────────────────────────────────
$total = (int)(Database::fetchOne(
    "SELECT COUNT(*) AS c FROM payments p
     JOIN students s ON s.id=p.student_id
     JOIN classes  c ON c.id=s.class_id
     {$whereStr}", $params
)['c'] ?? 0);

$rows = Database::fetchAll($sql . " LIMIT {$limit} OFFSET {$offset}", $params);

echo json_encode([
    'success' => true,
    'total'   => $total,
    'page'    => $page,
    'limit'   => $limit,
    'pages'   => (int)ceil($total / max(1,$limit)),
    'data'    => $rows,
]);
