<?php
// ============================================================
// admin/students_view.php — Student Full Profile View
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role([ROLE_ADMIN, ROLE_SECRETARY]);

$pageTitle  = 'Student Profile';
$activeMenu = 'students';

$id = int_val($_GET['id'] ?? 0);
if (!$id) { flash_error('Invalid student.'); redirect(BASE_URL . '/admin/students.php'); }

$student = Database::fetchOne(
    "SELECT s.*, c.name AS class_name, ses.name AS session_name
     FROM students s
     JOIN classes c ON c.id = s.class_id
     JOIN academic_sessions ses ON ses.id = s.session_id
     WHERE s.id = ?",
    [$id]
);
if (!$student) { flash_error('Student not found.'); redirect(BASE_URL . '/admin/students.php'); }

$sess_id = current_session_id();
$term_id = current_term_id();

// Results
$results = Database::fetchAll(
    "SELECT r.*, sub.name AS subject_name, t.name AS term_name
     FROM results r
     JOIN subjects sub ON sub.id = r.subject_id
     JOIN terms t ON t.id = r.term_id
     WHERE r.student_id = ? AND r.session_id = ?
     ORDER BY t.id, sub.name",
    [$id, $sess_id]
);

// Attendance summary
$attSummary = Database::fetchOne(
    "SELECT
        COUNT(*) AS total_days,
        SUM(status='present') AS present_days,
        SUM(status='absent')  AS absent_days,
        SUM(status='late')    AS late_days
     FROM attendance WHERE student_id = ? AND term_id = ?",
    [$id, $term_id]
);

// Payments
$payments = Database::fetchAll(
    "SELECT p.*, t.name AS term_name
     FROM payments p JOIN terms t ON t.id = p.term_id
     WHERE p.student_id = ?
     ORDER BY p.created_at DESC",
    [$id]
);

// Avg score
$avgScore = count($results) > 0
    ? array_sum(array_column($results, 'total_score')) / count($results)
    : 0;

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">👁️ Student Profile</h1>
        <p class="page-subtitle"><?= e($student['full_name']) ?> &mdash; <?= e($student['student_id']) ?></p>
    </div>
    <div class="page-header-actions">
        <?php if (is_admin()): ?>
            <a href="<?= BASE_URL ?>/admin/students_edit.php?id=<?= $id ?>" class="btn btn-primary">✏️ Edit</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/admin/report_cards.php?student_id=<?= $id ?>" class="btn btn-outline">📜 Report Card</a>
        <a href="<?= BASE_URL ?>/admin/students.php" class="btn btn-outline">← Back</a>
    </div>
</div>

<!-- Profile hero -->
<div class="card mb-20">
    <div class="card-body">
        <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">
            <div style="width:80px;height:80px;background:var(--navy);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:36px;font-weight:800;color:var(--accent);flex-shrink:0">
                <?= strtoupper(substr($student['full_name'],0,1)) ?>
            </div>
            <div style="flex:1">
                <div style="font-size:22px;font-weight:800;color:var(--text)"><?= e($student['full_name']) ?></div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px">
                    <span class="badge badge-navy"><?= e($student['student_id']) ?></span>
                    <span class="badge badge-primary"><?= e($student['class_name']) ?></span>
                    <?= status_badge($student['status']) ?>
                    <span class="badge badge-secondary"><?= e($student['gender']) ?></span>
                    <?php if ($student['blood_group']): ?>
                        <span class="badge badge-danger"><?= e($student['blood_group']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <a href="<?= e(wa_link($student['parent_phone1'], "Hello, I am contacting you regarding {$student['full_name']} from " . get_setting('school_name') . ".")) ?>"
                   target="_blank" class="btn btn-whatsapp">📲 Phone 1: +<?= e($student['parent_phone1']) ?></a>
                <a href="<?= e(wa_link($student['parent_phone2'], "Hello, I am contacting you regarding {$student['full_name']} from " . get_setting('school_name') . ".")) ?>"
                   target="_blank" class="btn btn-wa-dark">📲 Phone 2: +<?= e($student['parent_phone2']) ?></a>
            </div>
        </div>
    </div>
</div>

<!-- Quick stats -->
<div class="stats-grid mb-20" style="grid-template-columns:repeat(4,1fr)">
    <div class="stat-card stat-blue">
        <div class="stat-icon">📊</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($avgScore,1) ?></div>
            <div class="stat-label">Avg Score</div>
        </div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-icon">📅</div>
        <div class="stat-info">
            <div class="stat-value"><?= $attSummary['present_days'] ?? 0 ?></div>
            <div class="stat-label">Days Present</div>
        </div>
    </div>
    <div class="stat-card stat-red">
        <div class="stat-icon">❌</div>
        <div class="stat-info">
            <div class="stat-value"><?= $attSummary['absent_days'] ?? 0 ?></div>
            <div class="stat-label">Days Absent</div>
        </div>
    </div>
    <div class="stat-card stat-gold">
        <div class="stat-icon">💳</div>
        <div class="stat-info">
            <?php $totalBalance = array_sum(array_column($payments,'balance')); ?>
            <div class="stat-value"><?= money($totalBalance) ?></div>
            <div class="stat-label">Outstanding Fees</div>
        </div>
    </div>
</div>

<!-- Details + Results grid -->
<div class="grid-2 mb-20">
    <!-- Personal details -->
    <div class="card">
        <div class="card-header">📋 Personal Details</div>
        <div class="card-body" style="padding:0">
            <?php
            $details = [
                ['Full Name',    $student['full_name']],
                ['Student ID',   $student['student_id']],
                ['Class',        $student['class_name']],
                ['Gender',       $student['gender']],
                ['Date of Birth',format_date($student['dob'])],
                ['Blood Group',  $student['blood_group'] ?: '—'],
                ['Session',      $student['session_name']],
                ['Enrolled',     format_date($student['enrolled_date'])],
                ['Status',       ucfirst($student['status'])],
                ['Address',      $student['address'] ?: '—'],
                ['Parent Name',  $student['parent_name'] ?: '—'],
                ['Parent Email', $student['parent_email'] ?: '—'],
            ];
            foreach ($details as [$label,$value]):
            ?>
            <div style="display:flex;padding:10px 16px;border-bottom:1px solid var(--border)">
                <span style="width:130px;font-size:12px;font-weight:700;color:var(--text-muted);flex-shrink:0"><?= $label ?></span>
                <span style="font-size:13.5px;font-weight:600"><?= e($value) ?></span>
            </div>
            <?php endforeach; ?>
            <?php if ($student['medical_notes']): ?>
            <div style="padding:10px 16px;background:#FEF3C7">
                <span style="font-size:12px;font-weight:700;color:#92400E">⚠️ Medical Notes:</span>
                <div style="font-size:13px;margin-top:4px"><?= e($student['medical_notes']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment history -->
    <div class="card">
        <div class="card-header">💳 Fee Payment History</div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr>
                    <th>Code</th><th>Term</th><th>Due</th><th>Paid</th><th>Balance</th><th>Status</th>
                </tr></thead>
                <tbody>
                <?php if ($payments): foreach ($payments as $p): ?>
                    <tr>
                        <td class="code"><?= e($p['payment_code']) ?></td>
                        <td><?= e($p['term_name']) ?></td>
                        <td><?= money($p['amount_due']) ?></td>
                        <td style="color:var(--emerald);font-weight:700"><?= money($p['amount_paid']) ?></td>
                        <td style="color:<?= $p['balance']>0?'var(--red)':'var(--emerald)' ?>;font-weight:700"><?= money($p['balance']) ?></td>
                        <td><?= status_badge($p['status']) ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6" class="table-empty">No payment records</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Results table -->
<div class="card mb-20">
    <div class="card-header">
        📈 Academic Results — <?= e(get_setting('current_session')) ?> (<?= e(get_setting('current_term')) ?> Term)
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th data-sort>Subject</th>
                <th>Term</th>
                <th>Test /<?= e(get_setting('results_test_max','20')) ?></th>
                <th>Assign. /<?= e(get_setting('results_asn_max','20')) ?></th>
                <th>Exam /<?= e(get_setting('results_exam_max','60')) ?></th>
                <th>Total /100</th>
                <th>Grade</th>
                <th>Remark</th>
            </tr></thead>
            <tbody>
            <?php if ($results): foreach ($results as $r): ?>
                <tr>
                    <td><strong><?= e($r['subject_name']) ?></strong></td>
                    <td><?= e($r['term_name']) ?></td>
                    <td><?= $r['test_score'] ?></td>
                    <td><?= $r['assignment_score'] ?></td>
                    <td><?= $r['exam_score'] ?></td>
                    <td><strong style="font-size:16px"><?= number_format($r['total_score'],1) ?></strong></td>
                    <td><?= grade_badge($r['grade'] ?? '—') ?></td>
                    <td class="text-sm text-muted"><?= e($r['remark'] ?? '—') ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="8" class="table-empty">No results recorded for this term</td></tr>
            <?php endif; ?>
            </tbody>
            <?php if ($results): ?>
            <tfoot>
                <tr style="background:var(--navy);color:var(--white)">
                    <td colspan="5" style="padding:12px 14px;font-weight:700">Average Score</td>
                    <td style="padding:12px 14px;font-size:18px;font-weight:900;color:var(--accent)"><?= number_format($avgScore,1) ?></td>
                    <td><?= grade_badge(get_grade($avgScore)['grade']) ?></td>
                    <td style="color:rgba(255,255,255,.7)"><?= get_grade($avgScore)['remark'] ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
