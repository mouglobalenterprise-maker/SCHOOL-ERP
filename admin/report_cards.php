<?php
// ============================================================
// admin/report_cards.php — Report Card Generator
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role([ROLE_ADMIN, ROLE_STUDENT]);
// ── Helper: ordinal suffix (1st, 2nd, 3rd…) ──────────────────
if (!function_exists('ordinal')) {
    function ordinal(int $n): string {
        $s = ['th','st','nd','rd'];
        $v = $n % 100;
        return $n . ($s[($v-20)%10] ?? $s[$v] ?? $s[0]);
    }
}

$pageTitle  = 'Report Cards';
$activeMenu = 'report_cards';

$sess_id = current_session_id();
$term_id = int_val($_GET['term_id'] ?? current_term_id());

// For students, lock to their own record
if (is_student()) {
    $myStudent = Database::fetchOne(
        "SELECT id FROM students WHERE user_id=? AND session_id=?",
        [current_user_id(), $sess_id]
    );
    if (!$myStudent) {
        flash_error('No student profile linked.');
        redirect(BASE_URL . '/student/dashboard.php');
    }
    $student_id = $myStudent['id'];
}

// ── Fee gate — students only ──────────────────────────────────
if (is_student() && isset($myStudent)) {
    $rcTermId  = int_val($_GET['term_id'] ?? current_term_id());
    $feeGate   = student_can_view_results((int)$myStudent['id'], $sess_id, $rcTermId);
    if (!$feeGate['allowed']) {
        include INCLUDES_PATH . '/header.php';
        ?>
        <div class="card" style="border-left:5px solid var(--red);max-width:680px;margin:40px auto">
            <div class="card-body" style="padding:40px 36px;text-align:center">
                <div style="font-size:64px;margin-bottom:16px">🔒</div>
                <h2 style="font-size:22px;font-weight:900;color:var(--red);margin-bottom:10px">Report Card Locked</h2>
                <p style="font-size:15px;color:var(--text-muted);line-height:1.8;max-width:440px;margin:0 auto 20px">
                    Your report card is not available because your school fees for
                    <strong style="color:var(--text)"><?= e($feeGate['term']) ?> Term</strong>
                    have not been fully paid.
                </p>
                <div style="background:var(--light);border-radius:12px;padding:18px 24px;
                            margin-bottom:24px;display:inline-block;border:1px solid var(--border)">
                    <div style="font-size:12px;font-weight:700;text-transform:uppercase;
                                 letter-spacing:.08em;color:var(--text-muted);margin-bottom:4px">
                        Outstanding Balance
                    </div>
                    <div style="font-size:28px;font-weight:900;color:var(--red)">
                        <?= money($feeGate['balance']) ?>
                    </div>
                </div>
                <p style="font-size:13px;color:var(--text-muted);margin-bottom:24px;line-height:1.7">
                    Please visit the school accounts office to complete your fee payment.
                    Your report card will be available immediately once full payment is confirmed.
                </p>
                <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
                    <a href="<?= BASE_URL ?>/student/payments.php" class="btn btn-primary">💳 View Fee Details</a>
                    <a href="<?= BASE_URL ?>/student/dashboard.php" class="btn btn-outline">← Dashboard</a>
                </div>
            </div>
        </div>
        <?php
        include INCLUDES_PATH . '/footer.php';
        exit;
    }
}

 else {
    $student_id = int_val($_GET['student_id'] ?? 0);
}

// Dropdowns
$classes  = Database::fetchAll("SELECT id,name FROM classes ORDER BY sort_order");
$students = Database::fetchAll(
    "SELECT s.id, s.full_name, s.student_id AS sid, c.name AS class_name
     FROM students s JOIN classes c ON c.id=s.class_id
     WHERE s.session_id=? AND s.status='active'
     ORDER BY c.sort_order, s.full_name",
    [$sess_id]
);
$terms = Database::fetchAll(
    "SELECT t.* FROM terms t
     JOIN academic_sessions ses ON ses.id=t.session_id
     WHERE ses.is_current=1 ORDER BY t.id"
);

// Load student data
$student      = null;
$results      = [];
$attendanceSummary = null;
$paymentStatus = null;

if ($student_id) {
    $student = Database::fetchOne(
        "SELECT s.*, c.name AS class_name, ses.name AS session_name
         FROM students s
         JOIN classes c ON c.id=s.class_id
         JOIN academic_sessions ses ON ses.id=s.session_id
         WHERE s.id=?", [$student_id]
    );

    $results = Database::fetchAll(
        "SELECT r.*, sub.name AS subject_name, sub.code AS subject_code,
                u.full_name AS teacher_name
         FROM results r
         JOIN subjects sub ON sub.id=r.subject_id
         LEFT JOIN teachers t ON t.id=r.teacher_id
         LEFT JOIN users u ON u.id=t.user_id
         WHERE r.student_id=? AND r.session_id=? AND r.term_id=?
         ORDER BY sub.name",
        [$student_id, $sess_id, $term_id]
    );

    $attendanceSummary = Database::fetchOne(
        "SELECT COUNT(*) AS total,
                SUM(status='present') AS present,
                SUM(status='absent')  AS absent,
                SUM(status='late')    AS late
         FROM attendance WHERE student_id=? AND term_id=?",
        [$student_id, $term_id]
    );

    $paymentStatus = Database::fetchOne(
        "SELECT status, amount_due, amount_paid, balance
         FROM payments WHERE student_id=? AND term_id=? AND session_id=?
         LIMIT 1",
        [$student_id, $term_id, $sess_id]
    );
}

// Grade ranges
$gradeRanges = Database::fetchAll("SELECT * FROM grade_ranges ORDER BY min DESC");

// School settings
$schoolName     = get_setting('school_name', 'School');
$schoolAddress  = get_setting('school_address', '');
$schoolPhone    = get_setting('school_phone', '');
$schoolMotto    = get_setting('school_motto', '');
$schoolLogo     = get_setting('school_logo', '');
$principalSig   = get_setting('principal_sig', '');

// Compute averages, position
$totalScore    = array_sum(array_column($results, 'total_score'));
$subjectCount  = count($results);
$avgScore      = $subjectCount > 0 ? $totalScore / $subjectCount : 0;
$avgGrade      = get_grade($avgScore);

// Class position
$position = null;
if ($student_id && $subjectCount > 0) {
    $classAvgs = Database::fetchAll(
        "SELECT student_id, AVG(total_score) AS avg
         FROM results WHERE class_id=? AND session_id=? AND term_id=?
         GROUP BY student_id ORDER BY avg DESC",
        [$student['class_id'] ?? 0, $sess_id, $term_id]
    );
    foreach ($classAvgs as $i => $ca) {
        if ($ca['student_id'] == $student_id) {
            $position = $i + 1;
            break;
        }
    }
}

$attRate = ($attendanceSummary && $attendanceSummary['total'] > 0)
    ? round(($attendanceSummary['present'] / $attendanceSummary['total']) * 100) : 0;

// Term name
$termRow = Database::fetchOne("SELECT name FROM terms WHERE id=?", [$term_id]);
$termName = $termRow['name'] ?? '';

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📜 Report Cards</h1>
        <p class="page-subtitle">Generate and print student academic report cards</p>
    </div>
    <div class="page-header-actions">
        <?php if ($student_id && !empty($results)): ?>
            <button onclick="printReportCard()" class="btn btn-primary">🖨️ Print Report Card</button>
        <?php endif; ?>
    </div>
</div>

<?php if (!is_student()): ?>
<!-- Selector -->
<div class="card mb-20">
    <div class="card-header">⚙️ Select Student & Term</div>
    <div class="card-body">
        <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
            <div class="form-group" style="margin:0;flex:2;min-width:220px">
                <label class="form-label">Student</label>
                <select name="student_id" class="form-control" required onchange="this.form.submit()">
                    <option value="">Select student…</option>
                    <?php foreach ($students as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $student_id==$s['id']?'selected':'' ?>>
                            <?= e($s['full_name']) ?> (<?= e($s['sid']) ?>) — <?= e($s['class_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;flex:1;min-width:160px">
                <label class="form-label">Term</label>
                <select name="term_id" class="form-control" onchange="this.form.submit()">
                    <?php foreach ($terms as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $term_id==$t['id']?'selected':'' ?>>
                            <?= e($t['name']) ?> Term
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════
     REPORT CARD (printable)
══════════════════════════════════════════════════════ -->
<?php if ($student && !empty($results)): ?>

<div id="reportCard" class="card">
    <!-- Header -->
    <div style="background:var(--navy);padding:24px 28px;display:flex;align-items:center;gap:20px">
        <div style="width:72px;height:72px;background:#F4B942;border-radius:14px;
                    display:flex;align-items:center;justify-content:center;font-size:38px;flex-shrink:0">
            <?php if ($schoolLogo && file_exists(UPLOADS_PATH . '/logos/' . $schoolLogo)): ?>
                <img src="<?= UPLOADS_URL ?>/logos/<?= e($schoolLogo) ?>"
                     style="width:72px;height:72px;object-fit:contain;border-radius:12px">
            <?php else: ?>🎓<?php endif; ?>
        </div>
        <div style="flex:1;text-align:center">
            <div style="color:#fff;font-size:22px;font-weight:900;letter-spacing:.02em"><?= e($schoolName) ?></div>
            <div style="color:rgba(255,255,255,.7);font-size:12px;margin-top:2px"><?= e($schoolAddress) ?></div>
            <?php if ($schoolMotto): ?>
                <div style="color:#F4B942;font-size:12px;font-style:italic;margin-top:2px">"<?= e($schoolMotto) ?>"</div>
            <?php endif; ?>
        </div>
        <div style="text-align:right">
            <div style="color:#F4B942;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em">Academic Report</div>
            <div style="color:#fff;font-size:13px;margin-top:2px"><?= e($student['session_name']) ?></div>
            <div style="color:rgba(255,255,255,.7);font-size:12px"><?= e($termName) ?> Term</div>
        </div>
    </div>

    <!-- Title bar -->
    <div style="background:#F4B942;text-align:center;padding:8px;
                font-size:13px;font-weight:900;color:var(--navy);text-transform:uppercase;letter-spacing:.1em">
        Student Academic Report Card
    </div>

    <div style="padding:20px 24px">

        <!-- Student info grid -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:16px;
                    border:1px solid var(--border);border-radius:8px;overflow:hidden">
            <?php
            $attTotal   = (int)($attendanceSummary['total']   ?? 0);
            $attPresent = (int)($attendanceSummary['present'] ?? 0);
            $infoItems  = [
                ['Student Name',   $student['full_name']],
                ['Student ID',     $student['student_id']],
                ['Class',          $student['class_name']],
                ['Gender',         $student['gender']],
                ['Days Present',   "{$attPresent} / {$attTotal}"],
                ['Attendance Rate', "{$attRate}%"],
                ['Position in Class', $position ? ordinal($position ?? 0) : 'N/A'],
                ['No. of Subjects', $subjectCount],
            ];
            foreach ($infoItems as [$label, $value]): ?>
            <div style="display:flex;padding:9px 14px;border-bottom:1px solid var(--border);border-right:1px solid var(--border)">
                <span style="width:130px;font-size:11px;font-weight:700;color:var(--text-muted);flex-shrink:0;text-transform:uppercase;letter-spacing:.04em"><?= $label ?></span>
                <span style="font-size:13px;font-weight:700;color:var(--text)"><?= e($value) ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Results table -->
        <table style="width:100%;border-collapse:collapse;margin-bottom:16px;font-size:13px">
            <thead>
                <tr style="background:var(--navy);color:#fff">
                    <th style="padding:9px 12px;text-align:left;font-weight:700;font-size:12px">SUBJECT</th>
                    <th style="padding:9px 12px;text-align:left;font-size:12px">CODE</th>
                    <th style="padding:9px 8px;text-align:center;font-size:11px">TEST<br>/<?= e(get_setting('results_test_max','20')) ?></th>
                    <th style="padding:9px 8px;text-align:center;font-size:11px">ASGN<br>/<?= e(get_setting('results_asn_max','20')) ?></th>
                    <th style="padding:9px 8px;text-align:center;font-size:11px">EXAM<br>/<?= e(get_setting('results_exam_max','60')) ?></th>
                    <th style="padding:9px 8px;text-align:center;font-size:11px">TOTAL<br>/100</th>
                    <th style="padding:9px 8px;text-align:center;font-size:11px">GRADE</th>
                    <th style="padding:9px 12px;text-align:left;font-size:11px">REMARK</th>
                    <th style="padding:9px 12px;text-align:left;font-size:11px">TEACHER</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($results as $i => $r): ?>
                <tr style="background:<?= $i%2===0?'#fff':'#F8FAFC' ?>;border-bottom:1px solid var(--border)">
                    <td style="padding:9px 12px;font-weight:700"><?= e($r['subject_name']) ?></td>
                    <td style="padding:9px 12px;font-family:monospace;font-size:12px;color:var(--text-muted)">
                        <?= e($r['subject_code'] ?? '—') ?>
                    </td>
                    <td style="padding:9px 8px;text-align:center;color:var(--blue);font-weight:700"><?= $r['test_score'] ?></td>
                    <td style="padding:9px 8px;text-align:center;color:var(--purple);font-weight:700"><?= $r['assignment_score'] ?></td>
                    <td style="padding:9px 8px;text-align:center;color:var(--emerald);font-weight:700"><?= $r['exam_score'] ?></td>
                    <td style="padding:9px 8px;text-align:center;font-weight:900;font-size:15px"><?= number_format($r['total_score'],1) ?></td>
                    <td style="padding:9px 8px;text-align:center">
                        <span style="
                            background:<?= ['A'=>'#D1FAE5','B'=>'#DBEAFE','C'=>'#FEF3C7','D'=>'#EDE9FE','F'=>'#FEE2E2'][$r['grade']] ?? '#F1F5F9' ?>;
                            color:<?= ['A'=>'#065F46','B'=>'#1E40AF','C'=>'#92400E','D'=>'#5B21B6','F'=>'#991B1B'][$r['grade']] ?? '#475569' ?>;
                            padding:2px 10px;border-radius:20px;font-size:12px;font-weight:800">
                            <?= e($r['grade'] ?? '—') ?>
                        </span>
                    </td>
                    <td style="padding:9px 12px;font-size:12px;color:var(--text-muted)"><?= e($r['remark'] ?? '—') ?></td>
                    <td style="padding:9px 12px;font-size:12px;color:var(--text-muted)"><?= e($r['teacher_name'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:var(--navy);color:#fff">
                    <td colspan="5" style="padding:11px 12px;font-weight:700;font-size:13px">
                        AVERAGE SCORE (<?= $subjectCount ?> subjects)
                    </td>
                    <td style="padding:11px 8px;text-align:center;font-size:20px;font-weight:900;color:#F4B942">
                        <?= number_format($avgScore,1) ?>
                    </td>
                    <td style="padding:11px 8px;text-align:center">
                        <span style="background:#F4B942;color:var(--navy);padding:3px 12px;
                                     border-radius:20px;font-weight:900;font-size:13px">
                            <?= e($avgGrade['grade']) ?>
                        </span>
                    </td>
                    <td colspan="2" style="padding:11px 12px;color:rgba(255,255,255,.7);font-size:13px">
                        <?= e($avgGrade['remark']) ?>
                    </td>
                </tr>
            </tfoot>
        </table>

        <!-- Grade key -->
        <div style="margin-bottom:20px;background:var(--light);border-radius:8px;padding:10px 14px">
            <span style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-right:12px">Grade Key:</span>
            <?php foreach ($gradeRanges as $gr): ?>
                <span style="margin-right:12px;font-size:12px">
                    <strong><?= e($gr['grade']) ?></strong>: <?= $gr['min'] ?>–<?= $gr['max'] ?>% (<?= e($gr['remark']) ?>)
                </span>
            <?php endforeach; ?>
        </div>

        <!-- Teacher comment -->
        <?php
        $comments = array_filter(array_column($results,'teacher_comment'));
        if (!empty($comments)):
        ?>
        <div style="margin-bottom:20px;border:1px solid var(--border);border-radius:8px;padding:12px 14px">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px">
                Teacher Comments:
            </div>
            <?php foreach ($results as $r): if ($r['teacher_comment']): ?>
                <div style="font-size:13px;margin-bottom:4px">
                    <strong><?= e($r['subject_name']) ?>:</strong>
                    <span style="color:var(--text-muted)"><?= e($r['teacher_comment']) ?></span>
                </div>
            <?php endif; endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Next term info + Payment status -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
            <div style="background:var(--light);border-radius:8px;padding:12px 14px">
                <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px">
                    Fee Status
                </div>
                <?php if ($paymentStatus): ?>
                    <span style="
                        padding:4px 14px;border-radius:20px;font-size:12px;font-weight:800;
                        background:<?= $paymentStatus['status']==='paid'?'#D1FAE5':($paymentStatus['status']==='partial'?'#FEF3C7':'#FEE2E2') ?>;
                        color:<?= $paymentStatus['status']==='paid'?'#065F46':($paymentStatus['status']==='partial'?'#92400E':'#991B1B') ?>">
                        <?= strtoupper($paymentStatus['status']) ?>
                    </span>
                    <?php if ($paymentStatus['balance'] > 0): ?>
                        <span style="font-size:12px;color:var(--red);margin-left:8px;font-weight:700">
                            Balance: <?= money($paymentStatus['balance']) ?>
                        </span>
                    <?php endif; ?>
                <?php else: ?>
                    <span style="color:var(--text-muted);font-size:13px">No payment record</span>
                <?php endif; ?>
            </div>
            <div style="background:var(--light);border-radius:8px;padding:12px 14px">
                <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px">
                    Attendance — <?= $termName ?> Term
                </div>
                <span style="font-size:13px;font-weight:700"><?= $attPresent ?> of <?= $attTotal ?> days present</span>
                <span style="font-size:12px;font-weight:800;margin-left:8px;color:<?= $attRate>=80?'var(--emerald)':($attRate>=60?'var(--accent)':'var(--red)') ?>">
                    (<?= $attRate ?>%)
                </span>
            </div>
        </div>

        <!-- Signatures -->
        <div style="display:flex;justify-content:space-between;padding-top:20px;border-top:2px dashed var(--border)">
            <div style="text-align:center;width:180px">
                <?php if ($principalSig && file_exists(UPLOADS_PATH . '/logos/' . $principalSig)): ?>
                    <img src="<?= UPLOADS_URL ?>/logos/<?= e($principalSig) ?>"
                         style="height:50px;margin-bottom:4px;object-fit:contain">
                <?php else: ?>
                    <div style="height:50px"></div>
                <?php endif; ?>
                <div style="border-top:1px solid var(--text);padding-top:6px;font-size:11px;color:var(--text-muted)">
                    Class Teacher's Signature
                </div>
            </div>
            <div style="text-align:center;font-size:11px;color:var(--text-muted);align-self:flex-end">
                Generated: <?= date('d M Y') ?><br>
                <?= e(APP_NAME) ?> v<?= APP_VERSION ?>
            </div>
            <div style="text-align:center;width:180px">
                <div style="height:50px"></div>
                <div style="border-top:1px solid var(--text);padding-top:6px;font-size:11px;color:var(--text-muted)">
                    Principal's Signature & Stamp
                </div>
            </div>
        </div>

    </div>
</div>

<?php elseif ($student_id): ?>
<div class="card">
    <div class="card-body table-empty">
        <div class="table-empty-icon">📜</div>
        No results have been recorded for this student in the selected term.
        <?php if (is_admin()): ?>
            <br><a href="<?= BASE_URL ?>/admin/results_enter.php" class="btn btn-primary btn-sm" style="margin-top:10px">
                + Enter Results
            </a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body table-empty">
        <div class="table-empty-icon">📜</div>
        Select a student and term above to generate their report card.
    </div>
</div>
<?php endif; ?>

<style>
@media print {
    .sidebar,.topnav,.page-header,.card:not(#reportCard),
    .flash-container,.modal-backdrop,
    select,button,a.btn,.table-toolbar,
    form:not(#reportCard form) { display:none !important; }
    .main-area { margin:0 !important; }
    body { background:#fff; }
    #reportCard { border:none; box-shadow:none; border-radius:0; }
    * { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
}
</style>

<script>
function printReportCard() {
    window.print();
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
