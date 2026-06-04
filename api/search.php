<?php
// ============================================================
// api/search.php — Universal Real-Time AJAX Search API
// Triggered on keypress from any search bar with data-ajax-search
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_login();

header('Content-Type: application/json');

$type  = sanitize($_GET['type'] ?? '');
$q     = sanitize($_GET['q']    ?? '');
$limit = min(50, max(5, int_val($_GET['limit'] ?? 25)));

if (strlen($q) < 1) {
    echo json_encode(['html' => '', 'count' => 0]);
    exit;
}

$like = "%{$q}%";

// ── Route to correct search ───────────────────────────────────
switch ($type) {

    // ── Students ──────────────────────────────────────────────
    case 'students':
        // Delegate to get_students.php logic
        $params = [current_session_id(), $like, $like, $like, $like, $like];
        $rows   = Database::fetchAll(
            "SELECT s.*, c.name AS class_name
             FROM students s JOIN classes c ON c.id = s.class_id
             WHERE s.session_id = ?
               AND (s.full_name LIKE ? OR s.student_id LIKE ?
                    OR s.parent_name LIKE ? OR s.parent_phone1 LIKE ?
                    OR s.parent_phone2 LIKE ?)
             ORDER BY s.full_name LIMIT {$limit}",
            $params
        );
        $html = '';
        foreach ($rows as $i => $s) {
            $waMsg  = 'Hello from ' . get_setting('school_name') . '.';
            $html  .= '<tr>';
            $html  .= '<td class="text-muted text-sm">' . ($i+1) . '</td>';
            $html  .= '<td><span class="code">' . e($s['student_id']) . '</span></td>';
            $html  .= '<td><div style="font-weight:700">' . e($s['full_name']) . '</div></td>';
            $html  .= '<td><span class="badge badge-navy">' . e($s['class_name']) . '</span></td>';
            $html  .= '<td>' . e($s['gender']) . '</td>';
            $html  .= '<td>' . e($s['parent_name'] ?? '—') . '</td>';
            $html  .= '<td><a href="' . e(wa_link($s['parent_phone1'],$waMsg)) . '" target="_blank" class="btn btn-sm btn-whatsapp">📲 ' . e($s['parent_phone1']) . '</a></td>';
            $html  .= '<td><a href="' . e(wa_link($s['parent_phone2'],$waMsg)) . '" target="_blank" class="btn btn-sm btn-wa-dark">📲 ' . e($s['parent_phone2']) . '</a></td>';
            $html  .= '<td>' . status_badge($s['status']) . '</td>';
            $html  .= '<td><div class="td-actions">';
            $html  .= '<a href="' . BASE_URL . '/admin/students_view.php?id=' . $s['id'] . '" class="btn btn-sm btn-outline">👁️</a>';
            if (is_admin()) {
                $html .= '<a href="' . BASE_URL . '/admin/students_edit.php?id=' . $s['id'] . '" class="btn btn-sm btn-primary">✏️</a>';
            }
            $html  .= '</div></td></tr>';
        }
        if (!$html) $html = '<tr><td colspan="10" class="table-empty">No students found for "' . e($q) . '".</td></tr>';
        break;

    // ── Teachers ──────────────────────────────────────────────
    case 'teachers':
        $rows = Database::fetchAll(
            "SELECT t.*, u.full_name, u.email, u.phone, u.status
             FROM teachers t JOIN users u ON u.id = t.user_id
             WHERE (u.full_name LIKE ? OR t.teacher_code LIKE ? OR u.email LIKE ?)
             ORDER BY u.full_name LIMIT {$limit}",
            [$like, $like, $like]
        );
        $html = '';
        foreach ($rows as $i => $t) {
            $html .= '<tr>';
            $html .= '<td class="text-muted text-sm">' . ($i+1) . '</td>';
            $html .= '<td><span class="code">' . e($t['teacher_code']) . '</span></td>';
            $html .= '<td><strong>' . e($t['full_name']) . '</strong></td>';
            $html .= '<td>' . e($t['qualification'] ?? '—') . '</td>';
            $html .= '<td>' . e($t['phone'] ?? '—') . '</td>';
            $html .= '<td>' . e($t['email'] ?? '—') . '</td>';
            $html .= '<td>' . status_badge($t['status']) . '</td>';
            $html .= '<td><div class="td-actions">';
            if (is_admin()) {
                $html .= '<a href="' . BASE_URL . '/admin/teachers_edit.php?id=' . $t['id'] . '" class="btn btn-sm btn-primary">✏️</a>';
            }
            $html .= '</div></td></tr>';
        }
        if (!$html) $html = '<tr><td colspan="8" class="table-empty">No teachers found.</td></tr>';
        break;

    // ── Results ───────────────────────────────────────────────
    case 'results':
        require_role([ROLE_ADMIN, ROLE_TEACHER]);
        $rows = Database::fetchAll(
            "SELECT r.*, s.full_name, s.student_id, c.name AS class_name, sub.name AS subject_name, t.name AS term_name
             FROM results r
             JOIN students s ON s.id = r.student_id
             JOIN classes  c ON c.id = r.class_id
             JOIN subjects sub ON sub.id = r.subject_id
             JOIN terms t ON t.id = r.term_id
             WHERE (s.full_name LIKE ? OR s.student_id LIKE ? OR sub.name LIKE ?)
               AND r.session_id = ?
             ORDER BY s.full_name, sub.name LIMIT {$limit}",
            [$like, $like, $like, current_session_id()]
        );
        $html = '';
        foreach ($rows as $r) {
            $html .= '<tr>';
            $html .= '<td><div style="font-weight:700">' . e($r['full_name']) . '</div><div class="text-xs text-muted">' . e($r['student_id']) . '</div></td>';
            $html .= '<td><span class="badge badge-navy">' . e($r['class_name']) . '</span></td>';
            $html .= '<td>' . e($r['subject_name']) . '</td>';
            $html .= '<td><span style="font-weight:700;color:var(--blue)">' . $r['test_score'] . '</span></td>';
            $html .= '<td><span style="font-weight:700;color:var(--purple)">' . $r['assignment_score'] . '</span></td>';
            $html .= '<td><span style="font-weight:700;color:var(--emerald)">' . $r['exam_score'] . '</span></td>';
            $html .= '<td><strong style="font-size:15px">' . number_format($r['total_score'],1) . '</strong></td>';
            $html .= '<td>' . grade_badge($r['grade'] ?? 'F') . '</td>';
            $html .= '<td>' . e($r['term_name']) . '</td>';
            $html .= '</tr>';
        }
        if (!$html) $html = '<tr><td colspan="9" class="table-empty">No results found.</td></tr>';
        break;

    // ── Payments ──────────────────────────────────────────────
    case 'payments':
        require_role([ROLE_ADMIN, ROLE_SECRETARY]);
        $rows = Database::fetchAll(
            "SELECT p.*, s.full_name, s.student_id, c.name AS class_name, t.name AS term_name
             FROM payments p
             JOIN students s ON s.id = p.student_id
             JOIN classes  c ON c.id = s.class_id
             JOIN terms    t ON t.id = p.term_id
             WHERE (s.full_name LIKE ? OR s.student_id LIKE ? OR p.payment_code LIKE ? OR p.receipt_no LIKE ?)
               AND p.session_id = ?
             ORDER BY p.created_at DESC LIMIT {$limit}",
            [$like, $like, $like, $like, current_session_id()]
        );
        $html = '';
        foreach ($rows as $p) {
            $html .= '<tr>';
            $html .= '<td><span class="code">' . e($p['payment_code']) . '</span></td>';
            $html .= '<td><div style="font-weight:700">' . e($p['full_name']) . '</div><div class="text-xs text-muted">' . e($p['student_id']) . '</div></td>';
            $html .= '<td><span class="badge badge-navy">' . e($p['class_name']) . '</span></td>';
            $html .= '<td>' . money($p['amount_due']) . '</td>';
            $html .= '<td style="color:var(--emerald);font-weight:700">' . money($p['amount_paid']) . '</td>';
            $html .= '<td style="color:' . ($p['balance']>0?'var(--red)':'var(--emerald)') . ';font-weight:700">' . money($p['balance']) . '</td>';
            $html .= '<td>' . status_badge($p['status']) . '</td>';
            $html .= '<td>' . e($p['term_name']) . '</td>';
            $html .= '<td><a href="' . BASE_URL . '/admin/payments_receipt.php?id=' . $p['id'] . '" class="btn btn-sm btn-outline">🖨️</a></td>';
            $html .= '</tr>';
        }
        if (!$html) $html = '<tr><td colspan="9" class="table-empty">No payments found.</td></tr>';
        break;

    // ── Announcements ─────────────────────────────────────────
    case 'announcements':
        $rows = Database::fetchAll(
            "SELECT a.*, u.full_name AS author
             FROM announcements a JOIN users u ON u.id = a.posted_by
             WHERE (a.title LIKE ? OR a.body LIKE ?)
             ORDER BY a.created_at DESC LIMIT {$limit}",
            [$like, $like]
        );
        $html = '';
        foreach ($rows as $a) {
            $html .= '<tr>';
            $html .= '<td>' . status_badge($a['priority']) . '</td>';
            $html .= '<td><strong>' . e($a['title']) . '</strong></td>';
            $html .= '<td class="text-sm">' . e(substr($a['body'],0,80)) . '…</td>';
            $html .= '<td>' . e($a['author']) . '</td>';
            $html .= '<td class="text-sm text-muted">' . format_date($a['created_at']) . '</td>';
            $html .= '</tr>';
        }
        if (!$html) $html = '<tr><td colspan="5" class="table-empty">No announcements found.</td></tr>';
        break;

    // ── Audit logs ────────────────────────────────────────────
    case 'audit':
        require_role(ROLE_ADMIN);
        $rows = Database::fetchAll(
            "SELECT * FROM audit_logs
             WHERE (username LIKE ? OR action LIKE ? OR description LIKE ? OR module LIKE ?)
             ORDER BY created_at DESC LIMIT {$limit}",
            [$like, $like, $like, $like]
        );
        $html = '';
        foreach ($rows as $log) {
            $html .= '<tr>';
            $html .= '<td><strong style="color:var(--navy)">' . e($log['username'] ?? '—') . '</strong></td>';
            $html .= '<td>' . e($log['description'] ?? $log['action']) . '</td>';
            $html .= '<td><span class="badge badge-primary">' . e($log['module']) . '</span></td>';
            $html .= '<td class="text-sm text-muted">' . e($log['created_at']) . '</td>';
            $html .= '<td class="code">' . e($log['ip_address'] ?? '—') . '</td>';
            $html .= '</tr>';
        }
        if (!$html) $html = '<tr><td colspan="5" class="table-empty">No logs found.</td></tr>';
        break;

    default:
        echo json_encode(['html' => '<tr><td class="table-empty">Unknown search type.</td></tr>', 'count' => 0]);
        exit;
}

echo json_encode(['html' => $html, 'count' => count($rows ?? [])]);
