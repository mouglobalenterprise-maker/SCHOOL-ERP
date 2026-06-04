<?php
// ============================================================
// admin/bulk_import.php — Bulk CSV Import (Students/Teachers)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_ADMIN);

$pageTitle  = 'Bulk Import';
$activeMenu = 'bulk_import';

$sess_id = current_session_id();
$type    = sanitize($_GET['type'] ?? 'students');
$step    = int_val($_GET['step']  ?? 1);

$classes  = Database::fetchAll("SELECT id,name FROM classes ORDER BY sort_order");
$subjects = Database::fetchAll("SELECT id,name FROM subjects ORDER BY name");

// ── STEP 3: Process final import ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    csrf_protect();
    $rows    = json_decode($_POST['preview_data'] ?? '[]', true);
    $type    = sanitize($_POST['import_type'] ?? 'students');
    $classId = int_val($_POST['default_class_id'] ?? 0);

    $imported = 0;
    $skipped  = 0;
    $errors   = [];

    Database::beginTransaction();
    try {
        foreach ($rows as $row) {
            if ($type === 'students') {
                $name   = trim($row['Name'] ?? $row['full_name'] ?? '');
                $sid    = trim($row['Student ID'] ?? $row['student_id'] ?? '');
                $gender = trim($row['Gender'] ?? $row['gender'] ?? 'Male');
                $cls    = trim($row['Class'] ?? $row['class'] ?? '');
                $phone1 = preg_replace('/[^0-9]/', '', $row['Parent Phone 1'] ?? $row['parent_phone1'] ?? '');
                $phone2 = preg_replace('/[^0-9]/', '', $row['Parent Phone 2'] ?? $row['parent_phone2'] ?? '');
                $parent = trim($row['Parent Name'] ?? $row['parent_name'] ?? '');
                $dob    = trim($row['Date of Birth'] ?? $row['dob'] ?? '');

                if (empty($name) || empty($phone1) || empty($phone2)) {
                    $skipped++;
                    $errors[] = "Skipped (missing name/phones): " . ($sid ?: $name);
                    continue;
                }

                // Resolve class ID
                $cid = $classId;
                if ($cls) {
                    $cRow = Database::fetchOne("SELECT id FROM classes WHERE name=?", [$cls]);
                    if ($cRow) $cid = $cRow['id'];
                }
                if (!$cid) { $skipped++; $errors[] = "No class for: {$name}"; continue; }

                // Auto-generate student ID if missing
                if (!$sid) $sid = generate_student_id();

                // Check duplicate
                if (Database::fetchOne("SELECT id FROM students WHERE student_id=?", [$sid])) {
                    $skipped++;
                    $errors[] = "Duplicate ID skipped: {$sid}";
                    continue;
                }

                Database::insert(
                    "INSERT INTO students
                        (student_id, full_name, gender, dob, class_id, session_id,
                         parent_name, parent_phone1, parent_phone2, status, enrolled_date)
                     VALUES (?,?,?,?,?,?,'?',?,?,'active',CURDATE())",
                    [$sid, $name, in_array($gender,['Male','Female'])?$gender:'Male',
                     $dob ?: null, $cid, $sess_id, $parent, $phone1, $phone2]
                );
                $imported++;

            } elseif ($type === 'teachers') {
                $name   = trim($row['Full Name'] ?? $row['full_name'] ?? '');
                $code   = trim($row['Teacher Code'] ?? $row['teacher_code'] ?? '');
                $email  = trim($row['Email'] ?? $row['email'] ?? '');
                $phone  = trim($row['Phone'] ?? $row['phone'] ?? '');
                $qual   = trim($row['Qualification'] ?? $row['qualification'] ?? '');

                if (empty($name)) { $skipped++; continue; }

                if (!$code) {
                    $last = Database::fetchOne("SELECT teacher_code FROM teachers ORDER BY id DESC LIMIT 1");
                    $num  = $last ? (int)substr($last['teacher_code'],3)+1 : 1;
                    $code = 'TCH' . str_pad($num,3,'0',STR_PAD_LEFT);
                }

                if (Database::fetchOne("SELECT id FROM teachers WHERE teacher_code=?", [$code])) {
                    $skipped++; $errors[] = "Duplicate code: {$code}"; continue;
                }

                $uid = Database::insert(
                    "INSERT INTO users (username,password,role_id,full_name,email,phone,status)
                     VALUES (?,?,?,?,?,?,'active')",
                    [$code, hash_password('changeme123'), ROLE_TEACHER, $name,
                     $email ?: null, $phone ?: null]
                );
                Database::insert(
                    "INSERT INTO teachers (user_id,teacher_code,qualification,status)
                     VALUES (?,?,?,'active')",
                    [$uid, $code, $qual ?: null]
                );
                $imported++;
            }
        }
        Database::commit();
        audit_log(current_user_id(), current_username(), 'bulk_import', 'Import',
            "Imported {$imported} {$type}, skipped {$skipped}");

        flash_success("✅ Import complete! <strong>{$imported}</strong> records imported, {$skipped} skipped."
            . ($errors ? " <a href='#' onclick='document.getElementById(\"importErrors\").style.display=\"block\"'>View errors</a>" : ''));
        $_SESSION['import_errors'] = $errors;
        redirect(BASE_URL . '/admin/bulk_import.php?type=' . $type . '&step=4');

    } catch (Exception $e) {
        Database::rollback();
        error_log('[BulkImport] ' . $e->getMessage());
        flash_error('Import failed: ' . $e->getMessage());
    }
}

// ── STEP 2: Handle file upload + preview ─────────────────────
$preview = [];
$previewJson = '';
$defaultClassId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    csrf_protect();
    $type    = sanitize($_POST['import_type'] ?? 'students');
    $defaultClassId = int_val($_POST['default_class_id'] ?? 0);
    $file    = $_FILES['import_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        flash_error('File upload error: ' . $file['error']);
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        flash_error('File too large. Max 5MB.');
    } else {
        // Save temp file
        $tmpPath = UPLOADS_PATH . '/temp_import_' . session_id() . '.csv';
        move_uploaded_file($file['tmp_name'], $tmpPath);
        $preview = parse_csv($tmpPath);
        @unlink($tmpPath);

        if (empty($preview)) {
            flash_error('No valid data found in file. Check format.');
        } else {
            $step        = 2;
            $previewJson = json_encode($preview);
        }
    }
}

// Step 4 — complete
$importErrors = $_SESSION['import_errors'] ?? [];
unset($_SESSION['import_errors']);

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📥 Bulk Import</h1>
        <p class="page-subtitle">Import students or teachers from CSV files</p>
    </div>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>/admin/bulk_import.php" class="btn btn-outline">↺ Start Over</a>
    </div>
</div>

<!-- Progress stepper -->
<div class="stepper mb-24">
    <?php
    $steps = ['1. Upload File','2. Preview Data','3. Confirm Import','4. Complete'];
    foreach ($steps as $i => $label):
        $n = $i + 1;
        $cls = $n < $step ? 'done' : ($n === $step ? 'active' : '');
    ?>
        <div class="step <?= $cls ?>"><?= $label ?></div>
    <?php endforeach; ?>
</div>

<?php if ($step === 4): ?>
<!-- ══ STEP 4: Complete ══ -->
<div class="card">
    <div style="padding:48px;text-align:center">
        <div style="font-size:64px;margin-bottom:16px">✅</div>
        <h2 style="color:var(--emerald);font-size:24px;margin:0 0 8px">Import Complete!</h2>
        <p style="color:var(--text-muted);margin-bottom:24px">Your records have been imported successfully.</p>
        <div style="display:flex;gap:12px;justify-content:center">
            <a href="<?= BASE_URL ?>/admin/<?= $type === 'teachers' ? 'teachers' : 'students' ?>.php"
               class="btn btn-primary btn-lg">View <?= ucfirst($type) ?> →</a>
            <a href="<?= BASE_URL ?>/admin/bulk_import.php" class="btn btn-outline btn-lg">Import More</a>
        </div>
        <?php if (!empty($importErrors)): ?>
        <div id="importErrors" style="display:none;margin-top:20px;text-align:left;
             background:var(--light);border-radius:8px;padding:14px;max-width:500px;margin:20px auto 0">
            <div style="font-weight:700;color:var(--red);margin-bottom:8px">Skipped Records:</div>
            <?php foreach ($importErrors as $err): ?>
                <div class="text-sm text-muted" style="margin-bottom:2px">• <?= e($err) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($step === 2 && !empty($preview)): ?>
<!-- ══ STEP 2: Preview ══ -->
<div class="card mb-20">
    <div class="card-header">
        📋 Preview — <?= count($preview) ?> records found
        <span class="badge badge-success"><?= count($preview) ?> rows</span>
    </div>
    <div style="overflow-x:auto;max-height:400px">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <?php foreach (array_keys($preview[0]) as $col): ?>
                        <th><?= e($col) ?></th>
                    <?php endforeach; ?>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($preview as $i => $row): ?>
                <tr>
                    <td class="text-muted text-sm"><?= $i+1 ?></td>
                    <?php foreach ($row as $val): ?>
                        <td class="text-sm"><?= e($val) ?></td>
                    <?php endforeach; ?>
                    <td><span class="badge badge-success">Ready</span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center">
        <div class="text-sm text-muted">
            ✅ Empty rows removed &nbsp;|&nbsp; ✅ Spaces trimmed &nbsp;|&nbsp;
            ✅ Duplicates will be skipped automatically
        </div>
        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="confirm_import" value="1">
            <input type="hidden" name="import_type" value="<?= e($type) ?>">
            <input type="hidden" name="default_class_id" value="<?= $defaultClassId ?>">
            <input type="hidden" name="preview_data" value="<?= e($previewJson) ?>">
            <div style="display:flex;gap:10px">
                <a href="<?= BASE_URL ?>/admin/bulk_import.php?type=<?= $type ?>" class="btn btn-outline">← Back</a>
                <button type="submit" class="btn btn-success btn-lg">
                    ✅ Confirm Import (<?= count($preview) ?> records)
                </button>
            </div>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ══ STEP 1: Upload ══ -->
<div class="grid-2" style="gap:20px">

    <!-- Upload form -->
    <div class="card">
        <div class="card-header">📁 Upload CSV File</div>
        <form method="POST" action="" enctype="multipart/form-data" data-validate>
            <?= csrf_field() ?>
            <div class="card-body">
                <!-- Type selector -->
                <div class="form-group">
                    <label class="form-label">Import Type</label>
                    <div style="display:flex;gap:12px">
                        <?php foreach (['students'=>'👨‍🎓 Students','teachers'=>'👨‍🏫 Teachers'] as $val=>$label): ?>
                        <label style="flex:1;display:flex;align-items:center;gap:8px;cursor:pointer;
                                      padding:12px 14px;border-radius:10px;border:1.5px solid var(--border);
                                      background:<?= $type===$val?'rgba(11,29,58,.04)':'var(--white)' ?>;
                                      border-color:<?= $type===$val?'var(--navy)':'var(--border)' ?>">
                            <input type="radio" name="import_type" value="<?= $val ?>"
                                   <?= $type===$val?'checked':'' ?>>
                            <span style="font-weight:700"><?= $label ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Default Class <small class="text-muted">(for students without class column)</small></label>
                    <select name="default_class_id" class="form-control">
                        <option value="">Select class…</option>
                        <?php foreach ($classes as $cls): ?>
                            <option value="<?= $cls['id'] ?>"><?= e($cls['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">CSV File <span class="req">*</span></label>
                    <div style="border:2px dashed var(--border);border-radius:10px;padding:28px;
                                text-align:center;cursor:pointer;transition:border-color .2s"
                         onclick="document.getElementById('csvFile').click()"
                         id="dropZone"
                         ondragover="event.preventDefault();this.style.borderColor='var(--navy)'"
                         ondragleave="this.style.borderColor='var(--border)'"
                         ondrop="event.preventDefault();handleFileDrop(event)">
                        <div style="font-size:36px;margin-bottom:8px">📄</div>
                        <div style="font-weight:700;margin-bottom:4px">Click to upload or drag & drop</div>
                        <div class="text-sm text-muted">CSV or Excel (.csv, .xlsx) — max 5MB</div>
                        <div id="fileNameDisplay" style="margin-top:8px;font-weight:700;color:var(--navy)"></div>
                    </div>
                    <input type="file" id="csvFile" name="import_file" style="display:none"
                           accept=".csv,.xlsx,.xls" required
                           onchange="document.getElementById('fileNameDisplay').textContent=this.files[0].name;
                                     document.getElementById('dropZone').style.borderColor='var(--emerald)'">
                </div>
            </div>
            <div class="card-footer" style="display:flex;justify-content:flex-end">
                <button type="submit" class="btn btn-primary btn-lg">Preview Data →</button>
            </div>
        </form>
    </div>

    <!-- Format guide -->
    <div style="display:flex;flex-direction:column;gap:16px">
        <div class="card">
            <div class="card-header">📋 Student CSV Format</div>
            <div class="card-body">
                <div class="form-hint mb-8">Required columns for student import:</div>
                <div style="overflow-x:auto">
                    <table style="font-size:11px;width:100%;border-collapse:collapse">
                        <thead>
                            <tr style="background:var(--navy);color:#fff">
                                <?php foreach (['Name','Gender','Class','Parent Phone 1','Parent Phone 2','Parent Name','Date of Birth'] as $col): ?>
                                    <th style="padding:5px 8px;text-align:left;white-space:nowrap"><?= $col ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="background:var(--light)">
                                <td style="padding:5px 8px">John Doe</td>
                                <td style="padding:5px 8px">Male</td>
                                <td style="padding:5px 8px">SS1</td>
                                <td style="padding:5px 8px">2207000001</td>
                                <td style="padding:5px 8px">2207000002</td>
                                <td style="padding:5px 8px">Mr. Doe</td>
                                <td style="padding:5px 8px">2008-03-12</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="form-hint mt-8">
                    ✅ Student ID auto-generated if not provided<br>
                    ✅ Empty rows automatically removed<br>
                    ✅ Leading/trailing spaces trimmed<br>
                    ✅ Duplicates skipped with report
                </div>
                <a href="data:text/csv;charset=utf-8,Name,Gender,Class,Parent Phone 1,Parent Phone 2,Parent Name,Date of Birth%0AJohn Doe,Male,SS1,2207000001,2207000002,Mr. Doe,2008-03-12"
                   download="students_template.csv" class="btn btn-outline btn-sm" style="margin-top:10px">
                    ⬇️ Download Template
                </a>
            </div>
        </div>

        <div class="card">
            <div class="card-header">📋 Teacher CSV Format</div>
            <div class="card-body">
                <div class="form-hint mb-8">Required columns for teacher import:</div>
                <div style="overflow-x:auto">
                    <table style="font-size:11px;width:100%;border-collapse:collapse">
                        <thead>
                            <tr style="background:var(--navy);color:#fff">
                                <?php foreach (['Full Name','Teacher Code','Email','Phone','Qualification'] as $col): ?>
                                    <th style="padding:5px 8px;text-align:left;white-space:nowrap"><?= $col ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="background:var(--light)">
                                <td style="padding:5px 8px">Mr. Smith</td>
                                <td style="padding:5px 8px">TCH010</td>
                                <td style="padding:5px 8px">smith@school.edu</td>
                                <td style="padding:5px 8px">2207100010</td>
                                <td style="padding:5px 8px">B.Sc Mathematics</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="form-hint mt-8">
                    ✅ Default password: <code>changeme123</code> (must be reset)<br>
                    ✅ Teacher code auto-generated if missing
                </div>
                <a href="data:text/csv;charset=utf-8,Full Name,Teacher Code,Email,Phone,Qualification%0AMr. Smith,TCH010,smith@school.edu,2207100010,B.Sc Mathematics"
                   download="teachers_template.csv" class="btn btn-outline btn-sm" style="margin-top:10px">
                    ⬇️ Download Template
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function handleFileDrop(e) {
    const file = e.dataTransfer.files[0];
    if (!file) return;
    const input = document.getElementById('csvFile');
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    document.getElementById('fileNameDisplay').textContent = file.name;
    document.getElementById('dropZone').style.borderColor = 'var(--emerald)';
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
