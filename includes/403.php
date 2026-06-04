<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Access Denied</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:#F8FAFC; }
        .err-box { text-align:center; padding:48px; background:#fff; border-radius:16px;
                   box-shadow:0 4px 24px rgba(0,0,0,.08); max-width:400px; }
        .err-icon { font-size:64px; margin-bottom:16px; }
        .err-code { font-size:72px; font-weight:900; color:#EF4444; margin:0; }
        .err-title { font-size:20px; font-weight:700; color:#1E293B; margin:8px 0; }
        .err-msg { color:#64748B; margin-bottom:24px; }
    </style>
</head>
<body>
<div class="err-box">
    <div class="err-icon">🚫</div>
    <div class="err-code">403</div>
    <div class="err-title">Access Denied</div>
    <p class="err-msg">You do not have permission to access this page. Contact your administrator.</p>
    <a href="javascript:history.back()" class="btn btn-primary">← Go Back</a>
</div>
</body>
</html>
