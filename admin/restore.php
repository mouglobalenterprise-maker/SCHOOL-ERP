<?php
// ============================================================
// admin/restore.php — Database Restore (Standalone Page)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_ADMIN);

$pageTitle  = 'Restore Database';
$activeMenu = 'backup';

$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();

    $confirmed = isset($_POST['confirmed']) && $_POST['confirmed'] === '1';
    if (!$confirmed) {
        flash_error('You must confirm that you understand the risks before restoring.');
        redirect(BASE_URL . '/admin/restore.php');
    }

    if (empty($_FILES['restore_file']['name'])) {
        flash_error('No file uploaded.');
        redirect(BASE_URL . '/admin/restore.php');
    }

    $file = $_FILES['restore_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        flash_error('File upload error code: ' . $file['error']);
        redirect(BASE_URL . '/admin/restore.php');
    }

    if ($file['size'] > 50 * 1024 * 1024) {
        flash_error('File too large. Maximum 50MB.');
        redirect(BASE_URL . '/admin/restore.php');
    }

    if (!preg_match('/\.sql$/i', $file['name'])) {
        flash_error('Only .sql files are allowed.');
        redirect(BASE_URL . '/admin/restore.php');
    }

    $content = file_get_contents($file['tmp_name']);

    if (empty($content)) {
        flash_error('The SQL file appears to be empty.');
        redirect(BASE_URL . '/admin/restore.php');
    }

    // Validate it looks like a real SQL file
    if (!preg_match('/CREATE TABLE|INSERT INTO|DROP TABLE/i', $content)) {
        flash_error('This does not appear to be a valid MySQL SQL backup file.');
        redirect(BASE_URL . '/admin/restore.php');
    }

    // ── Create auto-backup before restoring ──────────────────
    $preBackupFile = EXPORTS_PATH . '/pre_restore_' . date('Ymd_His') . '.sql';
    try {
        $preBackup = generatePreRestoreDump();
        file_put_contents($preBackupFile, $preBackup);
    } catch (Exception $e) {
        error_log('[Restore] Pre-backup failed: ' . $e->getMessage());
    }

    // ── Execute SQL ───────────────────────────────────────────
    $pdo        = Database::getInstance();
    $statements = preg_split('/;\s*\n/', $content);
    $executed   = 0;
    $failed     = 0;
    $failedStmts = [];

    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $pdo->exec("SET SQL_MODE = ''");

        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            // Skip empty lines and comments
            if (empty($stmt) || preg_match('/^(--|\/\*|#)/', $stmt)) continue;
            if (strtolower($stmt) === 'set names utf8mb4') {
                $executed++; continue;
            }

            try {
                $pdo->exec($stmt);
                $executed++;
            } catch (PDOException $e) {
                $failed++;
                if ($failed <= 10) {
                    $failedStmts[] = substr($stmt, 0, 80) . '… (' . $e->getMessage() . ')';
                }
            }
        }

        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    } catch (Exception $e) {
        flash_error('Critical restore error: ' . $e->getMessage());
        audit_log(current_user_id(), current_username(), 'restore_failed', 'Backup',
            'Restore FAILED from: ' . $file['name'] . ' — ' . $e->getMessage());
        redirect(BASE_URL . '/admin/restore.php');
    }

    audit_log(current_user_id(), current_username(), 'database_restore', 'Backup',
        "Restored from: {$file['name']} — {$executed} OK, {$failed} errors");

    $result = [
        'file'        => $file['name'],
        'size'        => $file['size'],
        'executed'    => $executed,
        'failed'      => $failed,
        'failedStmts' => $failedStmts,
        'preBackup'   => basename($preBackupFile),
        'success'     => $failed === 0,
    ];
}

// ── Pre-restore SQL dump helper ───────────────────────────────
function generatePreRestoreDump(): string {
    $pdo    = Database::getInstance();
    $output = "-- Pre-restore auto-backup: " . date('Y-m-d H:i:s') . "\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
        $output .= "DROP TABLE IF EXISTS `{$table}`;\n" . $create['Create Table'] . ";\n\n";
        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $cols = '`' . implode('`,`', array_keys($rows[0])) . '`';
            foreach (array_chunk($rows, 50) as $chunk) {
                $vals = array_map(function($r) use ($pdo) {
                    return '(' . implode(',', array_map(fn($v) => $v===null?'NULL':$pdo->quote($v), $r)) . ')';
                }, $chunk);
                $output .= "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $vals) . ";\n";
            }
        }
        $output .= "\n";
    }
    $output .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $output;
}

// ── Backup history ────────────────────────────────────────────
$backupFiles = [];
if (is_dir(EXPORTS_PATH)) {
    $files = glob(EXPORTS_PATH . '/*.sql');
    if ($files) { rsort($files); $backupFiles = array_slice($files, 0, 10); }
}

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">🔄 Database Restore</h1>
        <p class="page-subtitle">Restore your database from a .sql backup file</p>
    </div>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>/admin/backup.php" class="btn btn-outline">← Back to Backup</a>
    </div>
</div>

<?php if ($result): ?>
<!-- ══ Result ══ -->
<div class="card mb-24" style="border-left:4px solid <?= $result['success']?'var(--emerald)':'var(--accent)' ?>">
    <div class="card-body">
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px">
            <div style="font-size:48px"><?= $result['success']?'✅':'⚠️' ?></div>
            <div>
                <h2 style="margin:0 0 4px;color:<?= $result['success']?'var(--emerald)':'var(--accent)' ?>">
                    <?= $result['success'] ? 'Restore Complete' : 'Restore Completed with Warnings' ?>
                </h2>
                <div class="text-muted text-sm"><?= e($result['file']) ?> (<?= round($result['size']/1024,1) ?> KB)</div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px">
            <div style="background:var(--emerald-lt);border-radius:10px;padding:14px;text-align:center">
                <div style="font-size:28px;font-weight:900;color:var(--emerald)"><?= number_format($result['executed']) ?></div>
                <div class="text-sm text-muted">Statements Executed</div>
            </div>
            <div style="background:<?= $result['failed']>0?'#FEF3C7':'var(--light)' ?>;border-radius:10px;padding:14px;text-align:center">
                <div style="font-size:28px;font-weight:900;color:<?= $result['failed']>0?'var(--accent)':'var(--gray)' ?>"><?= $result['failed'] ?></div>
                <div class="text-sm text-muted">Statements Failed</div>
            </div>
            <div style="background:var(--light);border-radius:10px;padding:14px;text-align:center">
                <div style="font-size:14px;font-weight:700;color:var(--navy);margin-bottom:4px">Pre-Restore Backup</div>
                <div class="text-sm text-muted code"><?= e($result['preBackup']) ?></div>
            </div>
        </div>

        <?php if (!empty($result['failedStmts'])): ?>
        <div style="background:#FEF3C7;border-radius:8px;padding:12px 14px;border:1px solid #FDE68A">
            <div style="font-weight:700;color:#92400E;margin-bottom:8px">⚠️ Failed Statements (first 10):</div>
            <?php foreach ($result['failedStmts'] as $stmt): ?>
                <div class="code text-xs" style="margin-bottom:4px;color:#78350F"><?= e($stmt) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div style="margin-top:16px;display:flex;gap:10px">
            <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-primary">Go to Dashboard →</a>
            <a href="<?= BASE_URL ?>/admin/restore.php"   class="btn btn-outline">Restore Again</a>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ══ Upload Form ══ -->

<!-- Warning banner -->
<div style="background:#FEE2E2;border:1px solid #FECACA;border-radius:12px;padding:18px 20px;margin-bottom:24px">
    <div style="font-size:18px;font-weight:900;color:#991B1B;margin-bottom:8px">⚠️ DANGER ZONE — Read Before Proceeding</div>
    <ul style="color:#7F1D1D;font-size:14px;margin:0;padding-left:20px;line-height:1.8">
        <li>Restoring will <strong>permanently overwrite ALL current data</strong> in the database</li>
        <li>This includes students, results, payments, settings, and all other records</li>
        <li>This action <strong>cannot be undone</strong> without another backup</li>
        <li>A pre-restore backup will be automatically created before proceeding</li>
        <li>Only use backup files generated by <strong>this EduManage Pro system</strong></li>
    </ul>
</div>

<div class="grid-2" style="gap:20px">
    <!-- Upload form -->
    <div class="card">
        <div class="card-header" style="background:var(--red);color:#fff">🔄 Upload & Restore</div>
        <form method="POST" action="" enctype="multipart/form-data" data-validate
              onsubmit="return confirmRestore()">
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">SQL Backup File <span class="req">*</span></label>
                    <input type="file" name="restore_file" class="form-control" accept=".sql" required
                           onchange="showFileInfo(this)">
                    <div id="fileInfo" class="form-hint" style="margin-top:6px"></div>
                </div>

                <!-- File info preview -->
                <div id="filePreview" style="display:none;background:var(--light);border-radius:8px;
                     padding:12px 14px;margin-bottom:16px;border:1px solid var(--border)">
                    <div class="text-sm fw-700">Selected file:</div>
                    <div class="code text-sm" id="fileName"></div>
                    <div class="text-xs text-muted" id="fileSize"></div>
                </div>

                <!-- Confirmation checkbox -->
                <div style="background:#FEF2F2;border-radius:8px;padding:14px;border:1px solid #FECACA">
                    <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
                        <input type="checkbox" name="confirmed" id="confirmCheck"
                               value="1" style="margin-top:2px;flex-shrink:0">
                        <span style="font-size:13px;color:#7F1D1D;font-weight:600;line-height:1.5">
                            I understand that this will <strong>permanently overwrite all current data</strong>
                            and that this action cannot be undone. A pre-restore backup will be created automatically.
                        </span>
                    </label>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" id="restoreBtn" class="btn btn-danger btn-lg btn-block" disabled>
                    🔄 Restore Database Now
                </button>
            </div>
        </form>
    </div>

    <!-- Available backups to restore from -->
    <div class="card">
        <div class="card-header">📋 Available Backup Files</div>
        <?php if ($backupFiles): ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr>
                    <th>Filename</th>
                    <th>Size</th>
                    <th>Created</th>
                </tr></thead>
                <tbody>
                <?php foreach ($backupFiles as $f): ?>
                    <tr>
                        <td><span class="code" style="font-size:11px"><?= e(basename($f)) ?></span></td>
                        <td class="text-sm text-muted"><?= round(filesize($f)/1024,1) ?> KB</td>
                        <td class="text-sm text-muted"><?= date('d M Y H:i', filemtime($f)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer text-sm text-muted">
            Backups are stored in <code><?= EXPORTS_PATH ?></code>
        </div>
        <?php else: ?>
        <div class="card-body table-empty">
            <div class="table-empty-icon">💾</div>
            No backup files found.
            <br>
            <a href="<?= BASE_URL ?>/admin/backup.php" class="btn btn-primary btn-sm" style="margin-top:10px">
                Create Backup First
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
document.getElementById('confirmCheck')?.addEventListener('change', function() {
    const btn = document.getElementById('restoreBtn');
    if (btn) btn.disabled = !this.checked;
});

function showFileInfo(input) {
    const file    = input.files[0];
    const preview = document.getElementById('filePreview');
    const name    = document.getElementById('fileName');
    const size    = document.getElementById('fileSize');
    if (!file || !preview) return;
    preview.style.display = '';
    name.textContent = file.name;
    size.textContent = (file.size / 1024).toFixed(1) + ' KB';

    if (!file.name.toLowerCase().endsWith('.sql')) {
        document.getElementById('fileInfo').textContent = '⚠️ Warning: Only .sql files are supported.';
        document.getElementById('fileInfo').style.color = 'var(--red)';
    } else {
        document.getElementById('fileInfo').textContent = '✅ File type valid.';
        document.getElementById('fileInfo').style.color = 'var(--emerald)';
    }
}

function confirmRestore() {
    const file = document.querySelector('input[name="restore_file"]')?.files[0];
    if (!file) return false;
    return confirm(
        '⚠️ FINAL WARNING\n\n' +
        'You are about to restore from:\n"' + file.name + '"\n\n' +
        'ALL current data will be overwritten. This cannot be undone.\n\n' +
        'A pre-restore backup will be created automatically.\n\n' +
        'Click OK to proceed or Cancel to abort.'
    );
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
