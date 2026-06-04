<?php
// ============================================================
// includes/pdf_report.php — PDF Report Card Generator
// Pure PHP implementation — no external library required.
// Generates an HTML page optimised for print-to-PDF.
// Usage: include this file after setting $student_id + $term_id
// OR call directly: pdf_report.php?student_id=X&term_id=Y
// ============================================================

if (basename(__FILE__) === 'pdf_report.php') {
    // Direct URL access
    require_once __DIR__ . '/../config/config.php';
    require_login();
}

/**
 * Generate a complete HTML report card page ready for browser print-to-PDF.
 * Returns the HTML string.
 */
function generate_pdf_report(int $studentId, int $termId): string {
    $sess_id = current_session_id();

    $student = Database::fetchOne(
        "SELECT s.*, c.name AS class_name, ses.name AS session_name
         FROM students s
         JOIN classes c ON c.id = s.class_id
         JOIN academic_sessions ses ON ses.id = s.session_id
         WHERE s.id = ?",
        [$studentId]
    );
    if (!$student) return '<p>Student not found.</p>';

    $results = Database::fetchAll(
        "SELECT r.*, sub.name AS subject_name, sub.code AS subject_code,
                u.full_name AS teacher_name
         FROM results r
         JOIN subjects sub ON sub.id = r.subject_id
         LEFT JOIN teachers t ON t.id = r.teacher_id
         LEFT JOIN users u ON u.id = t.user_id
         WHERE r.student_id = ? AND r.session_id = ? AND r.term_id = ?
         ORDER BY sub.name",
        [$studentId, $sess_id, $termId]
    );

    $termRow  = Database::fetchOne("SELECT name FROM terms WHERE id=?", [$termId]);
    $termName = $termRow['name'] ?? '';

    $gradeRanges = Database::fetchAll("SELECT * FROM grade_ranges ORDER BY min DESC");

    $attSummary = Database::fetchOne(
        "SELECT COUNT(*) AS total, SUM(status='present') AS present,
                SUM(status='absent') AS absent, SUM(status='late') AS late
         FROM attendance WHERE student_id=? AND term_id=?",
        [$studentId, $termId]
    );

    $payment = Database::fetchOne(
        "SELECT status, balance FROM payments WHERE student_id=? AND term_id=? AND session_id=? LIMIT 1",
        [$studentId, $termId, $sess_id]
    );

    $totalScore   = array_sum(array_column($results, 'total_score'));
    $subjectCount = count($results);
    $avgScore     = $subjectCount > 0 ? $totalScore / $subjectCount : 0;
    $avgGrade     = get_grade($avgScore);
    $attRate      = ($attSummary['total'] ?? 0) > 0
        ? round(($attSummary['present'] / $attSummary['total']) * 100) : 0;

    // Class position
    $position = null;
    $classAvgs = Database::fetchAll(
        "SELECT student_id, AVG(total_score) AS avg
         FROM results WHERE class_id=? AND session_id=? AND term_id=?
         GROUP BY student_id ORDER BY avg DESC",
        [$student['class_id'], $sess_id, $termId]
    );
    foreach ($classAvgs as $i => $ca) {
        if ($ca['student_id'] == $studentId) { $position = $i + 1; break; }
    }

    $schoolName   = get_setting('school_name', 'School');
    $schoolAddr   = get_setting('school_address', '');
    $schoolPhone  = get_setting('school_phone', '');
    $schoolMotto  = get_setting('school_motto', '');
    $schoolLogo   = get_setting('school_logo', '');
    $principalSig = get_setting('principal_sig', '');
    $testMax      = get_setting('results_test_max', '20');
    $asnMax       = get_setting('results_asn_max', '20');
    $examMax      = get_setting('results_exam_max', '60');

    $gradeColorMap = [
        'A'=>['bg'=>'#D1FAE5','color'=>'#065F46'],
        'B'=>['bg'=>'#DBEAFE','color'=>'#1E40AF'],
        'C'=>['bg'=>'#FEF3C7','color'=>'#92400E'],
        'D'=>['bg'=>'#EDE9FE','color'=>'#5B21B6'],
        'F'=>['bg'=>'#FEE2E2','color'=>'#991B1B'],
    ];

    function ordinal_suffix(int $n): string {
        $s = ['th','st','nd','rd'];
        $v = $n % 100;
        return $n . ($s[($v-20)%10] ?? $s[$v] ?? $s[0]);
    }

    ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Report Card — <?= htmlspecialchars($student['full_name']) ?></title>
<style>
  * { box-sizing: border-box; margin:0; padding:0; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size:12px; color:#1E293B; background:#fff; padding:16px; }
  .report { max-width:780px; margin:0 auto; border:2px solid #0B1D3A; border-radius:10px; overflow:hidden; }

  /* Header */
  .rpt-header { background:#0B1D3A; padding:20px 24px; display:flex; align-items:center; gap:16px; }
  .rpt-logo { width:60px;height:60px;background:#F4B942;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:30px;flex-shrink:0; }
  .rpt-logo img { width:60px;height:60px;object-fit:contain;border-radius:10px; }
  .rpt-school-name { color:#fff;font-size:18px;font-weight:900; }
  .rpt-school-sub  { color:rgba(255,255,255,.65);font-size:11px;margin-top:2px; }
  .rpt-school-motto{ color:#F4B942;font-size:11px;font-style:italic;margin-top:2px; }
  .rpt-title-bar { background:#F4B942;text-align:center;padding:7px;font-size:12px;font-weight:900;color:#0B1D3A;text-transform:uppercase;letter-spacing:.1em; }

  /* Info grid */
  .info-grid { display:grid;grid-template-columns:1fr 1fr;border:1px solid #E2E8F0;border-radius:6px;overflow:hidden;margin:14px 16px; }
  .info-cell { padding:7px 12px;border-bottom:1px solid #E2E8F0;border-right:1px solid #E2E8F0; }
  .info-cell:nth-child(even) { border-right:none; }
  .info-cell:nth-last-child(-n+2) { border-bottom:none; }
  .info-label { font-size:9px;text-transform:uppercase;font-weight:700;color:#64748B;margin-bottom:1px; }
  .info-value { font-size:12px;font-weight:700; }

  /* Results table */
  table.results { width:100%;border-collapse:collapse;margin:0 0 12px; }
  table.results th { background:#0B1D3A;color:#fff;padding:7px 10px;font-size:10px;font-weight:700;text-align:left; }
  table.results th.center { text-align:center; }
  table.results td { padding:7px 10px;border-bottom:1px solid #E2E8F0;font-size:11px; }
  table.results td.center { text-align:center; }
  table.results tr:nth-child(even) td { background:#F8FAFC; }
  table.results tfoot td { background:#0B1D3A;color:#fff;padding:9px 10px;font-weight:700; }

  .grade-badge { display:inline-block;padding:2px 9px;border-radius:20px;font-size:10px;font-weight:800; }

  /* Summary row */
  .summary-row { display:flex;gap:0;margin:0 16px 14px;border:1px solid #E2E8F0;border-radius:8px;overflow:hidden; }
  .summary-cell { flex:1;padding:12px 10px;text-align:center;border-right:1px solid #E2E8F0; }
  .summary-cell:last-child { border-right:none; }
  .summary-val { font-size:22px;font-weight:900;color:#0B1D3A; }
  .summary-lbl { font-size:9px;text-transform:uppercase;font-weight:700;color:#64748B;margin-top:2px; }

  /* Grade key */
  .grade-key { margin:0 16px 14px;background:#F8FAFC;border-radius:6px;padding:8px 12px;font-size:10px;color:#64748B; }

  /* Comments */
  .comments { margin:0 16px 14px;border:1px solid #E2E8F0;border-radius:6px;padding:10px 12px; }
  .comment-title { font-size:9px;font-weight:700;text-transform:uppercase;color:#64748B;margin-bottom:6px; }
  .comment-item { font-size:11px;margin-bottom:3px; }

  /* Signatures */
  .sigs { display:flex;justify-content:space-between;margin:0 16px 16px;padding-top:14px;border-top:2px dashed #E2E8F0; }
  .sig-box { text-align:center;width:160px; }
  .sig-img { height:40px;object-fit:contain;margin-bottom:4px; }
  .sig-line { border-top:1px solid #1E293B;padding-top:5px;font-size:9px;color:#64748B; }
  .sig-space { height:40px; }

  /* Footer stamp */
  .rpt-footer { background:#F8FAFC;border-top:1px solid #E2E8F0;text-align:center;padding:8px;font-size:9px;color:#94A3B8; }

  /* Print styles */
  @media print {
    body { padding:0; background:#fff; }
    .report { border-radius:0;border:1px solid #ccc; }
    * { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .no-print { display:none !important; }
  }
</style>
</head>
<body>

<!-- Print button (hidden on print) -->
<div class="no-print" style="text-align:center;margin-bottom:14px;display:flex;gap:10px;justify-content:center">
    <button onclick="window.print()" style="background:#0B1D3A;color:#fff;border:none;padding:10px 24px;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer">🖨️ Print / Save as PDF</button>
    <button onclick="window.close()" style="background:#F1F5F9;color:#1E293B;border:1px solid #E2E8F0;padding:10px 24px;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer">✕ Close</button>
</div>

<div class="report">

  <!-- Header -->
  <div class="rpt-header">
    <div class="rpt-logo">
      <?php if ($schoolLogo && file_exists(UPLOADS_PATH . '/logos/' . $schoolLogo)): ?>
        <img src="<?= UPLOADS_URL ?>/logos/<?= htmlspecialchars($schoolLogo) ?>" alt="Logo">
      <?php else: ?>🎓<?php endif; ?>
    </div>
    <div style="flex:1;text-align:center">
      <div class="rpt-school-name"><?= htmlspecialchars($schoolName) ?></div>
      <?php if ($schoolAddr):  ?><div class="rpt-school-sub"><?= htmlspecialchars($schoolAddr) ?></div><?php endif; ?>
      <?php if ($schoolPhone): ?><div class="rpt-school-sub"><?= htmlspecialchars($schoolPhone) ?></div><?php endif; ?>
      <?php if ($schoolMotto): ?><div class="rpt-school-motto">"<?= htmlspecialchars($schoolMotto) ?>"</div><?php endif; ?>
    </div>
    <div style="text-align:right">
      <div style="color:#F4B942;font-size:10px;font-weight:700;text-transform:uppercase">Academic Report</div>
      <div style="color:#fff;font-size:12px;margin-top:2px"><?= htmlspecialchars($student['session_name']) ?></div>
      <div style="color:rgba(255,255,255,.65);font-size:11px"><?= htmlspecialchars($termName) ?> Term</div>
    </div>
  </div>

  <div class="rpt-title-bar">Student Academic Report Card</div>

  <!-- Student info -->
  <div class="info-grid">
    <?php
    $infoItems = [
      ['Student Name',    $student['full_name']],
      ['Student ID',      $student['student_id']],
      ['Class',           $student['class_name']],
      ['Gender',          $student['gender']],
      ['Date of Birth',   $student['dob'] ? date('d M Y', strtotime($student['dob'])) : '—'],
      ['Term',            $termName . ' Term'],
      ['Days Present',    ($attSummary['present']??0) . ' / ' . ($attSummary['total']??0)],
      ['Attendance Rate', $attRate . '%'],
      ['Position',        $position ? ordinal_suffix($position) . ' in class' : 'N/A'],
      ['Subjects',        $subjectCount],
      ['Fee Status',      ucfirst($payment['status'] ?? 'No record')],
      ['Balance',         $payment ? money($payment['balance']) : '—'],
    ];
    foreach ($infoItems as [$lbl, $val]):
    ?>
    <div class="info-cell">
      <div class="info-label"><?= htmlspecialchars($lbl) ?></div>
      <div class="info-value"><?= htmlspecialchars((string)$val) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Results table -->
  <div style="margin:0 16px 12px">
  <table class="results">
    <thead>
      <tr>
        <th>Subject</th>
        <th>Code</th>
        <th class="center">Test /<?= $testMax ?></th>
        <th class="center">Assign /<?= $asnMax ?></th>
        <th class="center">Exam /<?= $examMax ?></th>
        <th class="center">Total /100</th>
        <th class="center">Grade</th>
        <th>Remark</th>
        <th>Teacher</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($results as $r):
        $gc = $gradeColorMap[$r['grade']] ?? ['bg'=>'#F1F5F9','color'=>'#475569'];
    ?>
      <tr>
        <td style="font-weight:700"><?= htmlspecialchars($r['subject_name']) ?></td>
        <td style="font-family:monospace;font-size:10px;color:#64748B"><?= htmlspecialchars($r['subject_code']??'—') ?></td>
        <td class="center" style="color:#3B82F6;font-weight:700"><?= $r['test_score'] ?></td>
        <td class="center" style="color:#8B5CF6;font-weight:700"><?= $r['assignment_score'] ?></td>
        <td class="center" style="color:#10B981;font-weight:700"><?= $r['exam_score'] ?></td>
        <td class="center" style="font-weight:900;font-size:14px"><?= number_format($r['total_score'],1) ?></td>
        <td class="center">
          <span class="grade-badge" style="background:<?= $gc['bg'] ?>;color:<?= $gc['color'] ?>">
            <?= htmlspecialchars($r['grade']??'—') ?>
          </span>
        </td>
        <td style="font-size:10px;color:#64748B"><?= htmlspecialchars($r['remark']??'—') ?></td>
        <td style="font-size:10px;color:#64748B"><?= htmlspecialchars($r['teacher_name']??'—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="5" style="font-size:12px">Average Score (<?= $subjectCount ?> subjects)</td>
        <td class="center" style="font-size:18px;color:#F4B942"><?= number_format($avgScore,1) ?></td>
        <td class="center">
          <?php $gc2 = $gradeColorMap[$avgGrade['grade']] ?? ['bg'=>'#F1F5F9','color'=>'#fff']; ?>
          <span class="grade-badge" style="background:<?= $gc2['bg'] ?>;color:<?= $gc2['color'] ?>;font-size:12px;padding:3px 12px">
            <?= $avgGrade['grade'] ?>
          </span>
        </td>
        <td colspan="2" style="color:rgba(255,255,255,.7);font-size:11px"><?= htmlspecialchars($avgGrade['remark']) ?></td>
      </tr>
    </tfoot>
  </table>
  </div>

  <!-- Summary row -->
  <div class="summary-row">
    <?php
    $summaries = [
      [$subjectCount, 'Subjects'],
      [number_format($avgScore,1), 'Average Score'],
      [$avgGrade['grade'], 'Overall Grade'],
      [($attSummary['present']??0) . '/' . ($attSummary['total']??0), 'Days Present'],
      [$attRate . '%', 'Attendance Rate'],
      [$position ? ordinal_suffix($position) : 'N/A', 'Class Position'],
    ];
    foreach ($summaries as [$v,$l]): ?>
    <div class="summary-cell">
      <div class="summary-val"><?= htmlspecialchars((string)$v) ?></div>
      <div class="summary-lbl"><?= $l ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Grade key -->
  <div class="grade-key">
    <strong>Grade Scale: </strong>
    <?php foreach ($gradeRanges as $gr): ?>
      <strong><?= htmlspecialchars($gr['grade']) ?></strong>: <?= $gr['min'] ?>–<?= $gr['max'] ?> (<?= htmlspecialchars($gr['remark']) ?>)&nbsp;&nbsp;
    <?php endforeach; ?>
  </div>

  <!-- Teacher comments -->
  <?php $comments = array_filter($results, fn($r) => !empty($r['teacher_comment'])); ?>
  <?php if ($comments): ?>
  <div class="comments">
    <div class="comment-title">Teacher Comments</div>
    <?php foreach ($comments as $r): ?>
      <div class="comment-item">
        <strong><?= htmlspecialchars($r['subject_name']) ?>:</strong>
        <span style="color:#64748B"><?= htmlspecialchars($r['teacher_comment']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Signatures -->
  <div class="sigs">
    <div class="sig-box">
      <div class="sig-space"></div>
      <div class="sig-line">Class Teacher's Signature</div>
    </div>
    <div style="text-align:center;align-self:flex-end;font-size:10px;color:#94A3B8">
      Generated: <?= date('d M Y') ?><br>
      <?= htmlspecialchars(APP_NAME) ?> v<?= APP_VERSION ?>
    </div>
    <div class="sig-box">
      <?php if ($principalSig && file_exists(UPLOADS_PATH . '/logos/' . $principalSig)): ?>
        <img src="<?= UPLOADS_URL ?>/logos/<?= htmlspecialchars($principalSig) ?>" class="sig-img" alt="Signature">
      <?php else: ?>
        <div class="sig-space"></div>
      <?php endif; ?>
      <div class="sig-line">Principal's Signature & Stamp</div>
    </div>
  </div>

  <!-- Footer -->
  <div class="rpt-footer">
    This is an official academic report card from <?= htmlspecialchars($schoolName) ?> &bull;
    <?= htmlspecialchars($termName) ?> Term, <?= htmlspecialchars($student['session_name']) ?> &bull;
    Printed: <?= date('d M Y H:i') ?>
  </div>

</div>
</body>
</html>
<?php
    return ob_get_clean();
}

// ── Direct access: output HTML for print/PDF ──────────────────
if (basename(__FILE__) === 'pdf_report.php') {
    require_login();

    $studentId = int_val($_GET['student_id'] ?? 0);
    $termId    = int_val($_GET['term_id']    ?? current_term_id());

    if (!$studentId) {
        die('Student ID required. Use: pdf_report.php?student_id=X&term_id=Y');
    }

    // Students can only access their own
    if (is_student()) {
        $mine = Database::fetchOne(
            "SELECT id FROM students WHERE user_id=?", [current_user_id()]
        );
        if (!$mine || $mine['id'] != $studentId) {
            http_response_code(403); die('Access denied.');
        }
    }

    echo generate_pdf_report($studentId, $termId);
    exit;
}
