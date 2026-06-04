<?php
// ============================================================
// admin/import_process.php — Bulk Import AJAX Processor
// Called by bulk_import.php via fetch() for live progress
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_ADMIN);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'POST required.');
}

csrf_protect();

$type       = sanitize($_POST['type']             ?? 'students');
$classId    = int_val($_POST['default_class_id']  ?? 0);
$rows       = json_decode($_POST['rows']          ?? '[]', true);
$batchIndex = int_val($_POST['batch_index']       ?? 0);
$batchSize  = int_val($_POST['batch_size']        ?? 10);
$sess_id    = current_session_id();

if (empty($rows) || !is_array($rows)) {
    json_response(false, 'No rows to process.');
}

// Process only the current batch slice
$batch    = array_slice($rows, $batchIndex * $batchSize, $batchSize);
$imported = 0;
$skipped  = 0;
$errors   = [];
$total    = count($rows);
$done     = min(($batchIndex + 1) * $batchSize, $total);

Database::beginTransaction();
try {
    foreach ($batch as $row) {

        if ($type === 'students') {
            $name   = trim($row['Name']           ?? $row['full_name']    ?? '');
            $sid    = trim($row['Student ID']      ?? $row['student_id']   ?? '');
            $gender = trim($row['Gender']          ?? $row['gender']       ?? 'Male');
            $cls    = trim($row['Class']           ?? $row['class']        ?? '');
            $phone1 = preg_replace('/[^0-9]/', '', $row['Parent Phone 1'] ?? $row['parent_phone1'] ?? '');
            $phone2 = preg_replace('/[^0-9]/', '', $row['Parent Phone 2'] ?? $row['parent_phone2'] ?? '');
            $parent = trim($row['Parent Name']     ?? $row['parent_name']  ?? '');
            $dob    = trim($row['Date of Birth']   ?? $row['dob']          ?? '');
            $addr   = trim($row['Address']         ?? $row['address']      ?? '');

            // Validation
            if (empty($name)) {
                $skipped++; $errors[] = "Row skipped: missing name."; continue;
            }
            if (empty($phone1) || empty($phone2)) {
                $skipped++; $errors[] = "Skipped '{$name}': both parent phones are required."; continue;
            }

            // Resolve class
            $cid = $classId;
            if ($cls) {
                $cRow = Database::fetchOne("SELECT id FROM classes WHERE name = ?", [$cls]);
                if ($cRow) $cid = (int)$cRow['id'];
            }
            if (!$cid) {
                $skipped++; $errors[] = "Skipped '{$name}': no valid class found ('{$cls}')."; continue;
            }

            // Auto-generate student ID
            if (!$sid) $sid = generate_student_id();

            // Duplicate check
            if (Database::fetchOne("SELECT id FROM students WHERE student_id = ?", [$sid])) {
                $skipped++; $errors[] = "Skipped: duplicate student ID '{$sid}'."; continue;
            }

            Database::insert(
                "INSERT INTO students
                    (student_id, full_name, gender, dob, class_id, session_id,
                     parent_name, parent_phone1, parent_phone2, address,
                     status, enrolled_date)
                 VALUES (?,?,?,?,?,?,?,?,?,?,'active',CURDATE())",
                [
                    $sid,
                    $name,
                    in_array($gender, ['Male','Female']) ? $gender : 'Male',
                    $dob  ?: null,
                    $cid,
                    $sess_id,
                    $parent ?: null,
                    $phone1,
                    $phone2,
                    $addr  ?: null,
                ]
            );
            $imported++;

        } elseif ($type === 'teachers') {
            $name  = trim($row['Full Name']      ?? $row['full_name']      ?? '');
            $code  = trim($row['Teacher Code']   ?? $row['teacher_code']   ?? '');
            $email = trim($row['Email']          ?? $row['email']          ?? '');
            $phone = trim($row['Phone']          ?? $row['phone']          ?? '');
            $qual  = trim($row['Qualification']  ?? $row['qualification']  ?? '');

            if (empty($name)) { $skipped++; continue; }

            // Auto-generate code
            if (!$code) {
                $last = Database::fetchOne(
                    "SELECT teacher_code FROM teachers ORDER BY id DESC LIMIT 1"
                );
                $num  = $last ? (int)substr($last['teacher_code'], 3) + 1 : 1;
                $code = 'TCH' . str_pad($num, 3, '0', STR_PAD_LEFT);
            }

            // Duplicate check
            if (Database::fetchOne("SELECT id FROM teachers WHERE teacher_code = ?", [$code])) {
                $skipped++; $errors[] = "Skipped: duplicate teacher code '{$code}'."; continue;
            }
            if ($email && Database::fetchOne("SELECT id FROM users WHERE username = ?", [$code])) {
                $skipped++; $errors[] = "Skipped: username '{$code}' already taken."; continue;
            }

            $uid = Database::insert(
                "INSERT INTO users (username, password, role_id, full_name, email, phone, status)
                 VALUES (?,?,?,?,?,?,'active')",
                [
                    $code,
                    hash_password('changeme123'),
                    ROLE_TEACHER,
                    $name,
                    $email ?: null,
                    $phone ?: null,
                ]
            );

            Database::insert(
                "INSERT INTO teachers (user_id, teacher_code, qualification, status)
                 VALUES (?,?,?,'active')",
                [$uid, $code, $qual ?: null]
            );
            $imported++;

        } elseif ($type === 'results') {
            // Bulk results import
            $sid        = trim($row['Student ID'] ?? '');
            $subjectName= trim($row['Subject']    ?? '');
            $test       = float_val($row['Test']       ?? 0);
            $asn        = float_val($row['Assignment']  ?? 0);
            $exam       = float_val($row['Exam']        ?? 0);
            $termName   = trim($row['Term'] ?? '');

            if (!$sid || !$subjectName) { $skipped++; continue; }

            $studentRow = Database::fetchOne(
                "SELECT id, class_id FROM students WHERE student_id=? AND session_id=?",
                [$sid, $sess_id]
            );
            $subjectRow = Database::fetchOne("SELECT id FROM subjects WHERE name=?", [$subjectName]);
            $termRow    = $termName
                ? Database::fetchOne("SELECT t.id FROM terms t JOIN academic_sessions ses ON ses.id=t.session_id WHERE t.name=? AND ses.is_current=1 LIMIT 1", [$termName])
                : Database::fetchOne("SELECT id FROM terms WHERE is_current=1 LIMIT 1");

            if (!$studentRow || !$subjectRow || !$termRow) {
                $skipped++; $errors[] = "Skipped result for '{$sid}': student/subject/term not found.";
                continue;
            }

            $total     = $test + $asn + $exam;
            $gradeInfo = get_grade($total);
            $term_id   = $termRow['id'];

            // Upsert
            $existing = Database::fetchOne(
                "SELECT id FROM results WHERE student_id=? AND subject_id=? AND term_id=? AND session_id=?",
                [$studentRow['id'], $subjectRow['id'], $term_id, $sess_id]
            );
            if ($existing) {
                Database::execute(
                    "UPDATE results SET test_score=?,assignment_score=?,exam_score=?,grade=?,remark=? WHERE id=?",
                    [$test,$asn,$exam,$gradeInfo['grade'],$gradeInfo['remark'],$existing['id']]
                );
            } else {
                Database::insert(
                    "INSERT INTO results (student_id,subject_id,class_id,term_id,session_id,test_score,assignment_score,exam_score,grade,remark)
                     VALUES (?,?,?,?,?,?,?,?,?,?)",
                    [$studentRow['id'],$subjectRow['id'],$studentRow['class_id'],$term_id,$sess_id,$test,$asn,$exam,$gradeInfo['grade'],$gradeInfo['remark']]
                );
            }
            $imported++;
        }
    }

    Database::commit();

} catch (Exception $e) {
    Database::rollback();
    error_log('[ImportProcess] ' . $e->getMessage());
    json_response(false, 'Batch failed: ' . $e->getMessage(), [
        'imported' => $imported,
        'skipped'  => $skipped,
        'errors'   => $errors,
    ]);
}

$isComplete = $done >= $total;

if ($isComplete) {
    audit_log(current_user_id(), current_username(), 'bulk_import', 'Import',
        "Completed import of {$type}: {$imported} imported, {$skipped} skipped");
}

echo json_encode([
    'success'     => true,
    'imported'    => $imported,
    'skipped'     => $skipped,
    'errors'      => $errors,
    'batch_index' => $batchIndex,
    'done'        => $done,
    'total'       => $total,
    'percent'     => (int)round(($done / max(1,$total)) * 100),
    'complete'    => $isComplete,
    'message'     => $isComplete
        ? "Import complete: {$imported} imported, {$skipped} skipped."
        : "Processing batch " . ($batchIndex+1) . "… ({$done}/{$total})",
]);
