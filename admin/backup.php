<?php
// ============================================================
// admin/backup.php — Database Backup & Restore
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_ADMIN);

$pageTitle  = 'Backup & Restore';
$activeMenu = 'backup';

// ── Handle backup export ──────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    csrf_protect_get: // verify token in query
    $token = sanitize($_GET['token'] ?? '');
    if (!hash_equals(csrf_token(), $token)) {
        flash_error('Invalid security token.');
        redirect(BASE_URL . '/admin/backup.php');
    }

    $filename  = 'edumanage_backup_' . date('Ymd_His') . '.sql';
    $filepath  = EXPORTS_PATH . '/' . $filename;

    // Generate SQL dump using PHP (no exec required)
    $sql = generateSqlDump();
    file_put_contents($filepath, $sql);

    audit_log(current_user_id(), current_username(), 'database_backup', 'Backup',
        "Created backup: {$filename}");

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Pragma: no-cache');
    readfile($filepath);
    exit;
}

// ── Handle restore ────────────────────────────────────────────
$restoreResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['restore_file'])) {
    csrf_protect();
    $file = $_FILES['restore_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        flash_error('Upload error: ' . $file['error']);
    } elseif (!preg_match('/\.sql$/i', $file['name'])) {
        flash_error('Only .sql files are allowed for restore.');
    } else {
        $content = file_get_contents($file['tmp_name']);
        if (empty($content)) {
            flash_error('The SQL file is empty.');
        } else {
            // Execute SQL statements
            $pdo        = Database::getInstance();
            $statements = array_filter(
                array_map('trim', explode(';', $content)),
                fn($s) => !empty($s) && !preg_match('/^--/', $s)
            );
            $executed = 0;
            $failed   = 0;
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            foreach ($statements as $stmt) {
                try {
                    $pdo->exec($stmt);
                    $executed++;
                } catch (PDOException $e) {
                    $failed++;
                }
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

            audit_log(current_user_id(), current_username(), 'database_restore', 'Backup',
                "Restored from: {$file['name']} — {$executed} statements executed, {$failed} failed");

            if ($failed === 0) {
                flash_success("Restore complete! {$executed} SQL statements executed successfully.");
            } else {
                flash_warning("Restore completed with {$failed} error(s). {$executed} statements succeeded.");
            }
        }
    }
    redirect(BASE_URL . '/admin/backup.php');
}

// ── List existing backup files ────────────────────────────────
$backupFiles = [];
if (is_dir(EXPORTS_PATH)) {
    $files = glob(EXPORTS_PATH . '/*.sql');
    if ($files) {
        rsort($files);
        foreach ($files as $f) {
            $backupFiles[] = [
                'name'     => basename($f),
                'size'     => filesize($f),
                'modified' => filemtime($f),
                'path'     => $f,
            ];
        }
    }
}

// ── SQL Dump Generator ────────────────────────────────────────
function generateSqlDump(): string {
    $pdo    = Database::getInstance();
    $output = "-- ============================================================\n";
    $output .= "-- EduManage Pro Database Backup\n";
    $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $output .= "-- Server: " . DB_HOST . " | Database: " . DB_NAME . "\n";
    $output .= "-- ============================================================\n\n";
    $output .= "SET NAMES utf8mb4;\n";
    $output .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    // Get all tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $output .= "-- Table: `{$table}`\n";
        $output .= "DROP TABLE IF EXISTS `{$table}`;\n";

        // Create table statement
        $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
        $output .= $createRow['Create Table'] . ";\n\n";

        // Data
        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $cols    = '`' . implode('`,`', array_keys($rows[0])) . '`';
            $output .= "INSERT INTO `{$table}` ({$cols}) VALUES\n";
            $inserts = [];
            foreach ($rows as $row) {
                $vals = array_map(function($v) use ($pdo) {
                    return $v === null ? 'NULL' : $pdo->quote($v);
                }, $row);
                $inserts[] = '(' . implode(',', $vals) . ')';
            }
            // Split large inserts into chunks
            foreach (array_chunk($inserts, 100) as $chunk) {
                $output .= implode(",\n", $chunk) . ";\n";
            }
        }
        $output .= "\n";
    }

    $output .= "SET FOREIGN_KEY_CHECKS=1;\n";
    $output .= "-- End of backup\n";
    return $output;
}

function formatBytes(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes/1048576,2) . ' MB';
    if ($bytes >= 1024)    return number_format($bytes/1024,1)    . ' KB';
    return $bytes . ' B';
}

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">☁️ Database Backup & Restore</h1>
        <p class="page-subtitle">Export and restore your complete school database</p>
    </div>
</div>

<div class="grid-2" style="gap:20px;margin-bottom:24px">

    <!-- Backup card -->
    <div class="card">
        <div class="card-header" style="background:var(--navy);color:#fff">☁️ Create Backup</div>
        <div class="card-body">
            <div style="text-align:center;padding:20px 0">
                <div style="font-size:56px;margin-bottom:12px">💾</div>
                <h3 style="margin:0 0 8px;font-size:18px">Export Full Database</h3>
                <p class="text-muted" style="font-size:14px;margin-bottom:20px">
                    Downloads a complete SQL backup of all tables including students, results,
                    payments, settings, and all other data.
                </p>
                <a href="<?= BASE_URL ?>/admin/backup.php?action=download&token=<?= e(csrf_token()) ?>"
                   class="btn btn-primary btn-lg">
                    ⬇️ Download Backup (.sql)
                </a>
            </div>

            <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
                <div style="font-size:13px;font-weight:700;margin-bottom:8px">What is included:</div>
                <?php
                $tableList = Database::fetchAll("SHOW TABLES");
                $tables    = array_map('current', $tableList);
                foreach ($tables as $t): ?>
                    <span style="display:inline-block;background:var(--light);border:1px solid var(--border);
                                 border-radius:6px;padding:2px 10px;font-size:12px;margin:2px">
                        <?= e($t) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Restore card -->
    <div class="card">
        <div class="card-header" style="background:var(--red);color:#fff">⚠️ Restore Database</div>
        <div class="card-body">
            <div style="background:#FEF2F2;border-radius:10px;padding:14px;margin-bottom:20px;
                        border:1px solid #FECACA">
                <div style="font-weight:800;color:#991B1B;margin-bottom:4px">⚠️ DANGER ZONE</div>
                <ul style="font-size:13px;color:#7F1D1D;margin:0;padding-left:16px;line-height:1.7">
                    <li>Restore will <strong>overwrite ALL current data</strong></li>
                    <li>This action <strong>cannot be undone</strong></li>
                    <li>Always create a backup before restoring</li>
                    <li>Only upload backups from this same system</li>
                </ul>
            </div>

            <form method="POST" action="" enctype="multipart/form-data" data-validate>
                <?= csrf_field() ?>
                <div class="form-group">
                    <label class="form-label">SQL Backup File <span class="req">*</span></label>
                    <input type="file" name="restore_file" class="form-control"
                           accept=".sql" required>
                    <div class="form-hint">Only .sql files generated by this system are supported.</div>
                </div>
                <button type="submit" class="btn btn-danger btn-block"
                        data-confirm="⚠️ WARNING: This will overwrite ALL current data with the backup file. This CANNOT be undone. Are you absolutely sure?">
                    🔄 Restore Database
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Backup history -->
<div class="card">
    <div class="card-header">
        📋 Backup History
        <span class="badge badge-primary"><?= count($backupFiles) ?> files</span>
    </div>
    <?php if ($backupFiles): ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>Filename</th>
                <th>Size</th>
                <th>Created</th>
                <th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($backupFiles as $f): ?>
                <tr>
                    <td>
                        <span class="code" style="font-size:12px"><?= e($f['name']) ?></span>
                    </td>
                    <td><?= formatBytes($f['size']) ?></td>
                    <td class="text-sm text-muted"><?= date('d M Y H:i', $f['modified']) ?></td>
                    <td>
                        <div class="td-actions">
                            <a href="<?= BASE_URL ?>/admin/backup.php?action=download&token=<?= e(csrf_token()) ?>"
                               class="btn btn-sm btn-outline">⬇️ Re-download</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="table-empty" style="padding:40px">
        <div class="table-empty-icon">💾</div>
        No backups found. Create your first backup above.
    </div>
    <?php endif; ?>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
