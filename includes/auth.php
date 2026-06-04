<?php
// ============================================================
// includes/auth.php — Authentication, Sessions & RBAC
// ============================================================

// Start session securely
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => false,    // Set true in production (HTTPS)
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// ── Login ─────────────────────────────────────────────────────

/**
 * Attempt login. Returns user array on success, false on failure.
 */
function auth_login(string $username, string $password): array|false {
    $username = trim($username);

    if (empty($username) || empty($password)) return false;

    $user = Database::fetchOne(
        "SELECT u.*, r.name AS role_name
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.username = ? AND u.status = 'active'
         LIMIT 1",
        [$username]
    );

    if (!$user) return false;

    // For the sample data the password hash is Laravel's default for 'password'
    // In production all passwords go through password_hash()
    if (!password_verify($password, $user['password'])) return false;

    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);

    // Store minimal session data
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['username']   = $user['username'];
    $_SESSION['full_name']  = $user['full_name'];
    $_SESSION['role_id']    = $user['role_id'];
    $_SESSION['role_name']  = $user['role_name'];
    $_SESSION['login_time'] = time();

    // Update last login timestamp
    Database::execute(
        "UPDATE users SET last_login = NOW() WHERE id = ?",
        [$user['id']]
    );

    // Audit log
    audit_log($user['id'], $user['username'], 'login', 'Auth', 'User logged in');

    return $user;
}

// ── Logout ────────────────────────────────────────────────────

function auth_logout(): void {
    if (is_logged_in()) {
        audit_log(
            $_SESSION['user_id'],
            $_SESSION['username'],
            'logout', 'Auth', 'User logged out'
        );
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ── Checks ────────────────────────────────────────────────────

function is_logged_in(): bool {
    return isset($_SESSION['user_id'], $_SESSION['role_id']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    // Session timeout check
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > SESSION_LIFETIME) {
        auth_logout();
        header('Location: ' . BASE_URL . '/login.php?timeout=1');
        exit;
    }
    // Refresh login time
    $_SESSION['login_time'] = time();
}

/**
 * Require user to have a specific role (or one of several roles).
 */
function require_role(int|array $roles): void {
    require_login();
    $roles = is_array($roles) ? $roles : [$roles];
    if (!in_array((int)$_SESSION['role_id'], $roles, true)) {
        http_response_code(403);
        include INCLUDES_PATH . '/403.php';
        exit;
    }
}

/**
 * Check if current user has access to a named module.
 */
function can_access(string $module): bool {
    if (!is_logged_in()) return false;
    $access = MODULE_ACCESS[$module] ?? [];
    return in_array((int)$_SESSION['role_id'], $access, true);
}

function is_admin(): bool     { return is_logged_in() && (int)$_SESSION['role_id'] === ROLE_ADMIN; }
function is_teacher(): bool   { return is_logged_in() && (int)$_SESSION['role_id'] === ROLE_TEACHER; }
function is_student(): bool   { return is_logged_in() && (int)$_SESSION['role_id'] === ROLE_STUDENT; }
function is_secretary(): bool { return is_logged_in() && (int)$_SESSION['role_id'] === ROLE_SECRETARY; }

// ── Current user helpers ─────────────────────────────────────

function current_user_id(): int       { return (int)($_SESSION['user_id']   ?? 0); }
function current_username(): string   { return $_SESSION['username']  ?? ''; }
function current_full_name(): string  { return $_SESSION['full_name'] ?? ''; }
function current_role_id(): int       { return (int)($_SESSION['role_id']   ?? 0); }
function current_role_name(): string  { return $_SESSION['role_name'] ?? ''; }

/**
 * Get the full current user record from DB (cached in session).
 */
function current_user(): ?array {
    if (!is_logged_in()) return null;
    if (!isset($_SESSION['_user_cache'])) {
        $_SESSION['_user_cache'] = Database::fetchOne(
            "SELECT u.*, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = ?",
            [current_user_id()]
        );
    }
    return $_SESSION['_user_cache'];
}

// ── CSRF ─────────────────────────────────────────────────────

function csrf_token(): string {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function csrf_field(): string {
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify(): bool {
    $token = $_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals(csrf_token(), $token);
}

function csrf_protect(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
        http_response_code(419);
        die(json_encode(['success' => false, 'message' => 'CSRF token mismatch. Please refresh and try again.']));
    }
}

// ── Password helpers ─────────────────────────────────────────

function hash_password(string $plain): string {
    return password_hash($plain, HASH_ALGO, ['cost' => HASH_COST]);
}

function verify_password(string $plain, string $hash): bool {
    return password_verify($plain, $hash);
}

/**
 * Admin resets a user password manually (no email required).
 */
function admin_reset_password(int $userId, string $newPassword): bool {
    require_role(ROLE_ADMIN);
    $hash = hash_password($newPassword);
    $affected = Database::execute(
        "UPDATE users SET password = ? WHERE id = ?",
        [$hash, $userId]
    );
    if ($affected) {
        audit_log(current_user_id(), current_username(), 'password_reset', 'Auth',
            "Password reset for user ID {$userId}");
    }
    return $affected > 0;
}

// ── Audit log shortcut ────────────────────────────────────────

function audit_log(
    ?int $userId,
    ?string $username,
    string $action,
    string $module,
    string $description = ''
): void {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    try {
        Database::insert(
            "INSERT INTO audit_logs
               (user_id, username, action, module, description, ip_address, user_agent)
             VALUES (?,?,?,?,?,?,?)",
            [$userId, $username, $action, $module, $description,
             substr($ip, 0, 45), substr($ua, 0, 255)]
        );
    } catch (Exception $e) {
        error_log('[Audit Error] ' . $e->getMessage());
    }
}
