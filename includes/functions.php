<?php
// ============================================================
// includes/functions.php — Global Helper Functions
// ============================================================

// ── Output helpers ────────────────────────────────────────────

/** Safely echo HTML-escaped string */
function e(mixed $val): string {
    return htmlspecialchars((string)($val ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** JSON response and exit */
function json_response(bool $success, string $message = '', array $data = []): never {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

/** Redirect */
function redirect(string $url, bool $permanent = false): never {
    header('Location: ' . $url, true, $permanent ? 301 : 302);
    exit;
}

// ── Flash messages ────────────────────────────────────────────

function flash_set(string $type, string $message): void {
    $_SESSION['_flash'][$type][] = $message;
}

function flash_success(string $msg): void { flash_set('success', $msg); }
function flash_error(string $msg): void   { flash_set('error',   $msg); }
function flash_info(string $msg): void    { flash_set('info',    $msg); }
function flash_warning(string $msg): void { flash_set('warning', $msg); }

function flash_get(): array {
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $flashes;
}

function flash_render(): string {
    $html = '';
    foreach (flash_get() as $type => $messages) {
        $icon = match($type) {
            'success' => '✅', 'error' => '❌', 'warning' => '⚠️', default => 'ℹ️'
        };
        foreach ($messages as $msg) {
            $html .= '<div class="alert alert-' . e($type) . '">'
                   . $icon . ' ' . e($msg)
                   . '<button class="alert-close" onclick="this.parentElement.remove()">×</button>'
                   . '</div>';
        }
    }
    return $html;
}

// ── Settings helper ───────────────────────────────────────────

/** Cache settings to avoid repeated queries */
function get_setting(string $key, string $default = ''): string {
    static $cache = [];
    if (!isset($cache[$key])) {
        $row = Database::fetchOne(
            "SELECT setting_val FROM settings WHERE setting_key = ? LIMIT 1",
            [$key]
        );
        $cache[$key] = $row ? $row['setting_val'] : $default;
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, string $value): void {
    Database::execute(
        "INSERT INTO settings (setting_key, setting_val) VALUES (?,?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)",
        [$key, $value]
    );
}

function get_all_settings(): array {
    $rows = Database::fetchAll("SELECT setting_key, setting_val FROM settings");
    $out  = [];
    foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_val'];
    return $out;
}

// ── Current session / term ────────────────────────────────────

function current_session(): ?array {
    return Database::fetchOne(
        "SELECT * FROM academic_sessions WHERE is_current = 1 LIMIT 1"
    );
}

function current_term(): ?array {
    return Database::fetchOne(
        "SELECT t.* FROM terms t
         JOIN academic_sessions s ON s.id = t.session_id
         WHERE t.is_current = 1 AND s.is_current = 1
         LIMIT 1"
    );
}

function current_session_id(): int {
    $s = current_session();
    return $s ? (int)$s['id'] : 0;
}

function current_term_id(): int {
    $t = current_term();
    return $t ? (int)$t['id'] : 0;
}

// ── Grade helpers ─────────────────────────────────────────────

function get_grade(float $total): array {
    static $ranges = null;
    if ($ranges === null) {
        $ranges = Database::fetchAll(
            "SELECT * FROM grade_ranges ORDER BY min DESC"
        );
    }
    foreach ($ranges as $r) {
        if ($total >= $r['min'] && $total <= $r['max']) {
            return ['grade' => $r['grade'], 'remark' => $r['remark'], 'points' => $r['points']];
        }
    }
    return ['grade' => 'F', 'remark' => 'Fail', 'points' => 0.0];
}

function grade_badge(string $grade): string {
    $colors = [
        'A' => 'badge-success',
        'B' => 'badge-primary',
        'C' => 'badge-warning',
        'D' => 'badge-purple',
        'F' => 'badge-danger',
    ];
    $cls = $colors[$grade] ?? 'badge-secondary';
    return '<span class="badge ' . $cls . '">' . e($grade) . '</span>';
}

// ── Status badge ─────────────────────────────────────────────

function status_badge(string $status): string {
    $map = [
        'active'    => 'badge-success',
        'inactive'  => 'badge-danger',
        'present'   => 'badge-success',
        'absent'    => 'badge-danger',
        'late'      => 'badge-warning',
        'paid'      => 'badge-success',
        'partial'   => 'badge-warning',
        'unpaid'    => 'badge-danger',
        'high'      => 'badge-danger',
        'normal'    => 'badge-primary',
        'low'       => 'badge-secondary',
        'graduated' => 'badge-purple',
        'transferred'=> 'badge-info',
    ];
    $cls = $map[strtolower($status)] ?? 'badge-secondary';
    return '<span class="badge ' . $cls . '">' . e(ucfirst($status)) . '</span>';
}

// ── Pagination ────────────────────────────────────────────────

function paginate(string $sql, array $params, int $page, int $perPage = ROWS_PER_PAGE): array {
    $page    = max(1, (int)$page);
    $offset  = ($page - 1) * $perPage;

    // Count total
    $countSql = "SELECT COUNT(*) as cnt FROM ({$sql}) AS _count_query";
    $total    = (int)(Database::fetchOne($countSql, $params)['cnt'] ?? 0);
    $pages    = (int)ceil($total / $perPage);

    // Fetch page
    $rows = Database::fetchAll($sql . " LIMIT {$perPage} OFFSET {$offset}", $params);

    return [
        'rows'       => $rows,
        'total'      => $total,
        'pages'      => $pages,
        'page'       => $page,
        'per_page'   => $perPage,
        'has_prev'   => $page > 1,
        'has_next'   => $page < $pages,
    ];
}

function pagination_links(array $pager, string $baseUrl): string {
    if ($pager['pages'] <= 1) return '';
    $html = '<div class="pagination">';
    if ($pager['has_prev']) {
        $html .= '<a href="' . $baseUrl . '&page=' . ($pager['page'] - 1) . '" class="page-btn">‹ Prev</a>';
    }
    for ($i = max(1, $pager['page'] - 2); $i <= min($pager['pages'], $pager['page'] + 2); $i++) {
        $active = $i === $pager['page'] ? ' active' : '';
        $html .= '<a href="' . $baseUrl . '&page=' . $i . '" class="page-btn' . $active . '">' . $i . '</a>';
    }
    if ($pager['has_next']) {
        $html .= '<a href="' . $baseUrl . '&page=' . ($pager['page'] + 1) . '" class="page-btn">Next ›</a>';
    }
    $html .= '</div>';
    return $html;
}

// ── Input sanitisation ────────────────────────────────────────

function sanitize(mixed $val): string {
    return trim(strip_tags((string)($val ?? '')));
}

function int_val(mixed $val, int $default = 0): int {
    return filter_var($val, FILTER_VALIDATE_INT) !== false ? (int)$val : $default;
}

function float_val(mixed $val, float $default = 0.0): float {
    return filter_var($val, FILTER_VALIDATE_FLOAT) !== false ? (float)$val : $default;
}

function valid_email(string $email): bool {
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function valid_phone(string $phone): bool {
    return (bool)preg_match('/^\+?[\d\s\-()]{7,20}$/', $phone);
}

// ── Student ID generator ──────────────────────────────────────

function generate_student_id(): string {
    $last = Database::fetchOne(
        "SELECT student_id FROM students ORDER BY id DESC LIMIT 1"
    );
    if (!$last) return 'STU001';
    $num = (int)substr($last['student_id'], 3) + 1;
    return 'STU' . str_pad($num, 3, '0', STR_PAD_LEFT);
}

function generate_payment_code(): string {
    return 'PAY-' . date('Y') . '-' . str_pad(
        (int)(Database::fetchOne("SELECT COUNT(*) AS c FROM payments")['c'] ?? 0) + 1,
        4, '0', STR_PAD_LEFT
    );
}

// ── Date helpers ──────────────────────────────────────────────

function format_date(string $date, string $format = 'd M Y'): string {
    if (!$date || $date === '0000-00-00') return '—';
    return date($format, strtotime($date));
}

function days_until(string $date): int {
    return (int)ceil((strtotime($date) - time()) / 86400);
}

// ── WhatsApp link builder ─────────────────────────────────────

function wa_link(string $phone, string $message): string {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    return WA_BASE_URL . $phone . '?text=' . rawurlencode($message);
}

// ── File upload handler ───────────────────────────────────────

function handle_upload(
    array  $file,
    string $subDir,
    array  $allowedTypes = [],
    int    $maxSize = MAX_UPLOAD_SIZE
): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload error: ' . $file['error']];
    }
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => 'File too large. Max ' . ($maxSize / 1024 / 1024) . 'MB.'];
    }
    if ($allowedTypes && !in_array($file['type'], $allowedTypes, true)) {
        return ['success' => false, 'message' => 'File type not allowed.'];
    }

    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
    $dir      = UPLOADS_PATH . '/' . trim($subDir, '/');
    $dest     = $dir . '/' . $filename;

    if (!is_dir($dir)) mkdir($dir, 0755, true);

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => false, 'message' => 'Failed to save file.'];
    }

    return [
        'success'  => true,
        'filename' => $filename,
        'path'     => $dest,
        'url'      => UPLOADS_URL . '/' . $subDir . '/' . $filename,
    ];
}

// ── Notifications ─────────────────────────────────────────────

function send_notification(int $userId, string $title, string $body, string $type = 'info', string $link = ''): void {
    Database::insert(
        "INSERT INTO notifications (user_id, title, body, type, link) VALUES (?,?,?,?,?)",
        [$userId, $title, $body, $type, $link]
    );
}

function get_unread_notifications(int $userId): array {
    return Database::fetchAll(
        "SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 10",
        [$userId]
    );
}

function count_unread_notifications(int $userId): int {
    $row = Database::fetchOne(
        "SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0",
        [$userId]
    );
    return (int)($row['c'] ?? 0);
}

// ── Number helpers ────────────────────────────────────────────


// ── Fee gate: check if student can view results ───────────────
// Returns array: ['allowed'=>bool, 'reason'=>string, 'balance'=>float, 'term'=>string]
// Logic:
//   - If admin override is set on student: ALWAYS allowed
//   - If no payment record for a term: ALLOWED (admin hasn't recorded fees yet)
//   - If ANY payment record for a term has status partial/unpaid: BLOCKED
//   - All terms are checked independently
function student_can_view_results(int $studentId, int $sessionId, ?int $termId = null): array {

    // Check admin override first
    $override = Database::fetchOne(
        "SELECT result_access_override FROM students WHERE id=?",
        [$studentId]
    );
    if ($override && (int)$override['result_access_override'] === 1) {
        return ['allowed' => true, 'reason' => 'override', 'balance' => 0, 'term' => ''];
    }

    // Build WHERE clause — check specific term or all terms in session
    if ($termId) {
        $payments = Database::fetchAll(
            "SELECT p.status, p.balance, t.name AS term_name
             FROM payments p JOIN terms t ON t.id = p.term_id
             WHERE p.student_id=? AND p.session_id=? AND p.term_id=?",
            [$studentId, $sessionId, $termId]
        );
    } else {
        $payments = Database::fetchAll(
            "SELECT p.status, p.balance, t.name AS term_name
             FROM payments p JOIN terms t ON t.id = p.term_id
             WHERE p.student_id=? AND p.session_id=?",
            [$studentId, $sessionId]
        );
    }

    // No payment records at all — admin hasn't entered fees yet — ALLOW
    if (empty($payments)) {
        return ['allowed' => true, 'reason' => 'no_record', 'balance' => 0, 'term' => ''];
    }

    // Check every payment record — if ANY is partial or unpaid, block
    foreach ($payments as $pay) {
        if (in_array($pay['status'], ['partial', 'unpaid'])) {
            return [
                'allowed' => false,
                'reason'  => 'unpaid',
                'balance' => (float)$pay['balance'],
                'term'    => $pay['term_name'],
            ];
        }
    }

    return ['allowed' => true, 'reason' => 'paid', 'balance' => 0, 'term' => ''];
}
function money(float $amount): string {
    return get_setting('currency_symbol', 'D') . ' ' . number_format($amount, 2);
}

function percentage(float $part, float $total): string {
    if ($total == 0) return '0%';
    return round(($part / $total) * 100, 1) . '%';
}

// ── CSV reader (for bulk import) ──────────────────────────────

function parse_csv(string $filePath, bool $hasHeader = true): array {
    if (!file_exists($filePath)) return [];

    $rows   = [];
    $header = null;

    if (($handle = fopen($filePath, 'r')) === false) return [];

    while (($data = fgetcsv($handle, 2000, ',')) !== false) {
        // Trim all values
        $data = array_map('trim', $data);
        // Skip empty rows
        if (array_filter($data) === []) continue;

        if ($hasHeader && $header === null) {
            $header = $data;
            continue;
        }
        if ($header) {
            $rows[] = array_combine($header, array_pad($data, count($header), ''));
        } else {
            $rows[] = $data;
        }
    }
    fclose($handle);
    return $rows;
}

// ── Breadcrumb builder ────────────────────────────────────────

function breadcrumb(array $items): string {
    $html = '<nav class="breadcrumb">';
    $last = array_key_last($items);
    foreach ($items as $i => [$label, $url]) {
        if ($i === $last || !$url) {
            $html .= '<span class="bc-current">' . e($label) . '</span>';
        } else {
            $html .= '<a href="' . e($url) . '" class="bc-link">' . e($label) . '</a>';
            $html .= '<span class="bc-sep">›</span>';
        }
    }
    return $html . '</nav>';
}

// ── Active nav link ───────────────────────────────────────────

function active_if(string $page): string {
    $current = basename($_SERVER['PHP_SELF']);
    return $current === $page ? ' active' : '';
}
