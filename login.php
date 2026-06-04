<?php
// ============================================================
// login.php — EduManage Pro Login Page
// ============================================================
require_once __DIR__ . '/config/config.php';

// Already logged in → redirect to dashboard
if (is_logged_in()) {
    redirect(BASE_URL . '/admin/dashboard.php');
}

$error   = '';
$timeout = isset($_GET['timeout']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();

    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter your username and password.';
    } else {
        $user = auth_login($username, $password);

        if ($user) {
            // Role-based redirect
            $redirect = match((int)$user['role_id']) {
                ROLE_TEACHER   => BASE_URL . '/teacher/dashboard.php',
                ROLE_STUDENT   => BASE_URL . '/student/dashboard.php',
                ROLE_SECRETARY => BASE_URL . '/secretary/dashboard.php',
                default        => BASE_URL . '/admin/dashboard.php',
            };
            redirect($redirect);
        } else {
            $error = 'Invalid username or password. Please try again.';
            // Small delay to slow brute-force
            sleep(1);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= e(get_setting('school_name', APP_NAME)) ?></title>
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/style.css">
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/login.css">
</head>
<body class="login-body">

<div class="login-wrapper">

    <!-- Left panel -->
    <div class="login-left">
        <div class="login-left-inner">
            <div class="login-logo">
                <?php
                $logo = get_setting('school_logo');
                if ($logo && file_exists(UPLOADS_PATH . '/logos/' . $logo)): ?>
                    <img src="<?= UPLOADS_URL ?>/logos/<?= e($logo) ?>" alt="School Logo" class="school-logo-img">
                <?php else: ?>
                    <div class="login-logo-icon">🎓</div>
                <?php endif; ?>
            </div>
            <h1 class="login-school-name"><?= e(get_setting('school_name', 'Excellence Secondary School')) ?></h1>
            <p class="login-motto"><?= e(get_setting('school_motto', 'Knowledge is Power')) ?></p>

            <div class="login-features">
                <div class="login-feature-item"><span>📊</span> Results & Report Cards</div>
                <div class="login-feature-item"><span>📅</span> Attendance Tracking</div>
                <div class="login-feature-item"><span>💳</span> Fee Management</div>
                <div class="login-feature-item"><span>📲</span> WhatsApp Notifications</div>
            </div>
        </div>
        <div class="login-left-footer">
            <?= e(APP_NAME) ?> v<?= APP_VERSION ?> &copy; <?= date('Y') ?>
        </div>
    </div>

    <!-- Right panel / form -->
    <div class="login-right">
        <div class="login-card">
            <div class="login-card-header">
                <h2>Welcome Back</h2>
                <p>Sign in to your account to continue</p>
            </div>

            <?php if ($timeout): ?>
                <div class="alert alert-warning">
                    ⚠️ Your session expired. Please sign in again.
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    ❌ <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm" novalidate>
                <?= csrf_field() ?>

                <div class="form-group">
                    <label for="username" class="form-label">Username / Student ID</label>
                    <div class="input-icon-wrap">
                        <span class="input-icon">👤</span>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="form-control"
                            value="<?= e($_POST['username'] ?? '') ?>"
                            placeholder="Enter your username"
                            autocomplete="username"
                            required
                            autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-icon-wrap">
                        <span class="input-icon">🔒</span>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required>
                        <button type="button" class="toggle-password" onclick="togglePassword()" title="Show/Hide Password">
                            👁️
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-login" id="loginBtn">
                    <span class="btn-text">Sign In</span>
                    <span class="btn-spinner" style="display:none;">⏳ Signing in...</span>
                </button>
            </form>

            <div class="login-note">
                <p>🔑 <strong>No self-service password reset.</strong><br>
                Contact your administrator to reset your password.</p>
            </div>

            <!-- Demo credentials hint (remove in production) -->
            <div class="demo-credentials">
                <div class="demo-title">Demo Credentials</div>
                <div class="demo-row"><span class="demo-role">Admin:</span> admin / admin123</div>
                <div class="demo-row"><span class="demo-role">Teacher:</span> TCH001 / password</div>
                <div class="demo-row"><span class="demo-role">Student:</span> STU001 / password</div>
                <div class="demo-row"><span class="demo-role">Secretary:</span> sec001 / password</div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassword() {
    const input = document.getElementById('password');
    input.type = input.type === 'password' ? 'text' : 'password';
}

document.getElementById('loginForm').addEventListener('submit', function () {
    const btn = document.getElementById('loginBtn');
    btn.querySelector('.btn-text').style.display = 'none';
    btn.querySelector('.btn-spinner').style.display = 'inline';
    btn.disabled = true;
});
</script>
</body>
</html>
