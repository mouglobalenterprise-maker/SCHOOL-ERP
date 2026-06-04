<?php
// ============================================================
// fix_passwords.php — ONE-TIME Password Fixer
// ============================================================
// Run this file ONCE in your browser after importing database.sql
// URL: http://localhost/edumanage/fix_passwords.php
//
// This script finds any user whose password starts with "PLAIN:"
// and replaces it with a proper PHP bcrypt hash.
//
// DELETE THIS FILE after running it successfully.
// ============================================================

// Direct DB connection — no framework needed
$host   = 'localhost';
$dbname = 'edumanage_pro';
$user   = 'root';
$pass   = '';          // ← change if your MySQL has a password

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('<b style="color:red">DB Connection failed:</b> ' . htmlspecialchars($e->getMessage())
        . '<br>Edit the \$host/\$user/\$pass variables at the top of this file.');
}

echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>EduManage — Fix Passwords</title>
<style>
  body{font-family:monospace;background:#0B1D3A;color:#fff;padding:30px}
  .ok{color:#10B981}.err{color:#EF4444}.box{background:#112347;padding:20px;border-radius:10px;max-width:700px;margin:0 auto}
  h1{color:#F4B942;margin-bottom:20px}
  .done{background:#D1FAE5;color:#065F46;padding:16px;border-radius:8px;margin-top:20px;font-size:14px}
  .btn{display:inline-block;background:#EF4444;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;margin-top:16px;font-weight:bold}
</style></head><body><div class="box">
<h1>🔑 EduManage Pro — Password Fixer</h1>';

// Find users with plain-text passwords
$users = $pdo->query("SELECT id, username, password FROM users WHERE password LIKE 'PLAIN:%'")->fetchAll(PDO::FETCH_ASSOC);

if (empty($users)) {
    echo '<p class="ok">✅ All passwords are already hashed. Nothing to do.</p>';
    echo '<p style="color:#94A3B8;margin-top:12px">You can delete this file now.</p>';
    echo '</div></body></html>';
    exit;
}

echo '<p>Found <b>' . count($users) . '</b> user(s) with plain-text passwords. Hashing now…</p><br>';

$fixed  = 0;
$errors = 0;

$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");

foreach ($users as $u) {
    // Extract plain password after "PLAIN:"
    $plainPass = substr($u['password'], 6); // removes "PLAIN:"

    if (empty($plainPass)) {
        echo '<p class="err">❌ ' . htmlspecialchars($u['username']) . ' — empty password, skipped.</p>';
        $errors++;
        continue;
    }

    $hash = password_hash($plainPass, PASSWORD_BCRYPT, ['cost' => 10]);

    try {
        $stmt->execute([$hash, $u['id']]);
        echo '<p class="ok">✅ ' . htmlspecialchars($u['username'])
             . ' → password "<b>' . htmlspecialchars($plainPass) . '</b>" hashed successfully.</p>';
        $fixed++;
    } catch (PDOException $e) {
        echo '<p class="err">❌ Failed for ' . htmlspecialchars($u['username']) . ': ' . htmlspecialchars($e->getMessage()) . '</p>';
        $errors++;
    }
}

echo '<br>';

if ($errors === 0) {
    echo '<div class="done">
    <b>✅ All ' . $fixed . ' passwords fixed successfully!</b><br><br>
    <b>You can now log in with:</b><br><br>
    <table style="border-collapse:collapse">
    <tr><td style="padding:4px 16px 4px 0"><b>Admin:</b></td><td>username: admin | password: admin123</td></tr>
    <tr><td style="padding:4px 16px 4px 0"><b>Teacher:</b></td><td>username: TCH001 | password: teacher123</td></tr>
    <tr><td style="padding:4px 16px 4px 0"><b>Student:</b></td><td>username: STU001 | password: student123</td></tr>
    <tr><td style="padding:4px 16px 4px 0"><b>Secretary:</b></td><td>username: sec001 | password: sec123</td></tr>
    </table>
    <br>
    ⚠️ <b>IMPORTANT: Delete this file now for security!</b>
    </div>
    <a class="btn" href="login.php">→ Go to Login Page</a>';
} else {
    echo '<p class="err"><b>' . $errors . ' error(s) occurred.</b> Check above for details.</p>';
}

echo '</div></body></html>';
