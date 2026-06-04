<?php
// ============================================================
// student/results.php — Student Results Portal (Read-Only)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_STUDENT);

$pageTitle  = 'My Results';
$activeMenu = 'results';

$sess_id = current_session_id();

// Get student record linked to this user
$student = Database::fetchOne(
    "SELECT s.*, c.name AS class_name
     FROM students s JOIN classes c ON c.id = s.class_id
     WHERE s.user_id = ? AND s.session_id = ?",
    [current_user_id(), $sess_id]
);
if (!$student) {
    flash_error('No student profile linked to your account. Contact the administrator.');
    redirect(BASE_URL . '/student/dashboard.php');
}

$term_id = int_val($_GET['term_id'] ?? current_term_id());

// ── Fee gate ──────────────────────────────────────────────────
$feeGate = student_can_view_results($student['id'], $sess_id, $term_id);
if (!$feeGate['allowed']) {
    // Student has unpaid fees — show block page instead of results
    include INCLUDES_PATH . '/header.php';
    ?>
    <div class="page-header">
        <div class="page-header-left">
            <h1 class="page-title">📈 My Academic Results</h1>
            <p class="page-subtitle"><?= e($student['full_name']) ?> &mdash; <?= e($student['class_name']) ?></p>
        </div>
    </div>
    <div class="card" style="border-left:5px solid var(--red);max-width:680px;margin:0 auto">
        <div class="card-body" style="padding:40px 36px;text-align:center">
            <div style="font-size:64px;margin-bottom:16px">🔒</div>
            <h2 style="font-size:22px;font-weight:900;color:var(--red);margin-bottom:10px">Results Locked</h2>
            <p style="font-size:15px;color:var(--text-muted);line-height:1.8;max-width:440px;margin:0 auto 20px">
                Your academic results for
                <strong style="color:var(--text)"><?= e($feeGate['term']) ?> Term</strong>
                are not available because your school fees have not been fully paid.
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
                Please visit the school accounts office or contact your parent/guardian to complete your fee payment.
                Your results will be available immediately once full payment is confirmed.
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



// Fetch results for selected term
$results = Database::fetchAll(
    "SELECT r.*, sub.name AS subject_name, sub.code AS subject_code,
            t.name AS term_name
     FROM results r
     JOIN subjects sub ON sub.id = r.subject_id
     JOIN terms    t   ON t.id  = r.term_id
     WHERE r.student_id = ? AND r.session_id = ?
       AND r.term_id = ?
     ORDER BY sub.name",
    [$student['id'], $sess_id, $term_id]
);

// All terms with results
$termsWithResults = Database::fetchAll(
    "SELECT DISTINCT t.id, t.name
     FROM results r JOIN terms t ON t.id = r.term_id
     WHERE r.student_id = ? AND r.session_id = ?
     ORDER BY t.id",
    [$student['id'], $sess_id]
);

// Summary stats
$totalScore = 0;
$subjectCount = count($results);
foreach ($results as $r) $totalScore += $r['total_score'];
$avgScore = $subjectCount > 0 ? $totalScore / $subjectCount : 0;
$avgGrade = get_grade($avgScore);

// Highest and lowest
$highest = $subjectCount > 0 ? max(array_column($results, 'total_score')) : 0;
$lowest  = $subjectCount > 0 ? min(array_column($results, 'total_score')) : 0;

// All terms comparison (for trend)
$allTermResults = Database::fetchAll(
    "SELECT t.name AS term_name, AVG(r.total_score) AS avg_score, t.id
     FROM results r JOIN terms t ON t.id = r.term_id
     WHERE r.student_id = ? AND r.session_id = ?
     GROUP BY t.id ORDER BY t.id",
    [$student['id'], $sess_id]
);

$terms = Database::fetchAll(
    "SELECT t.* FROM terms t JOIN academic_sessions ses ON ses.id=t.session_id WHERE ses.is_current=1 ORDER BY t.id"
);

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📈 My Academic Results</h1>
        <p class="page-subtitle">
            <?= e($student['full_name']) ?> &mdash;
            <?= e($student['class_name']) ?> &mdash;
            <?= e(get_setting('current_session')) ?>
        </p>
    </div>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>/student/report_card.php?term_id=<?= $term_id ?>"
           class="btn btn-primary">📜 View Report Card</a>
    </div>
</div>

<!-- Term selector tabs -->
<div class="tabs" style="margin-bottom:20px">
    <?php foreach ($terms as $term): ?>
        <a href="?term_id=<?= $term['id'] ?>"
           class="tab-btn <?= $term_id==$term['id']?'active':'' ?>"
           style="text-decoration:none">
            <?= e($term['name']) ?> Term
            <?php
            $hasResults = in_array($term['id'], array_column($termsWithResults,'id'));
            if ($hasResults): ?>
                <span class="badge badge-success" style="margin-left:4px;font-size:9px">✓</span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Performance summary cards -->
<div class="stats-grid mb-20" style="grid-template-columns:repeat(4,1fr)">
    <div class="stat-card stat-blue">
        <div class="stat-icon">📊</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($avgScore,1) ?></div>
            <div class="stat-label">Average Score</div>
            <div class="stat-sub" style="color:var(--blue)"><?= $avgGrade['remark'] ?></div>
        </div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-icon">🏆</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($highest,1) ?></div>
            <div class="stat-label">Highest Score</div>
        </div>
    </div>
    <div class="stat-card stat-red">
        <div class="stat-icon">📉</div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($lowest,1) ?></div>
            <div class="stat-label">Lowest Score</div>
        </div>
    </div>
    <div class="stat-card" style="<?= $avgGrade['grade']==='A'?'border-left:4px solid var(--emerald)':($avgGrade['grade']==='F'?'border-left:4px solid var(--red)':'') ?>">
        <div class="stat-icon">🎓</div>
        <div class="stat-info">
            <div class="stat-value"><?= grade_badge($avgGrade['grade']) ?></div>
            <div class="stat-label">Overall Grade</div>
        </div>
    </div>
</div>

<?php if ($results): ?>

<!-- Score bar chart (visual) -->
<div class="card mb-20">
    <div class="card-header">📊 Subject Performance Chart</div>
    <div class="card-body">
        <?php foreach ($results as $r):
            $pct = min(100, round($r['total_score']));
            $barCol = $r['total_score'] >= 80 ? 'var(--emerald)'
                    : ($r['total_score'] >= 60 ? 'var(--blue)'
                    : ($r['total_score'] >= 50 ? 'var(--accent)'
                    : 'var(--red)'));
        ?>
        <div style="margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                <span style="font-size:13px;font-weight:700"><?= e($r['subject_name']) ?></span>
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="font-size:13px;font-weight:800;color:<?= $barCol ?>"><?= number_format($r['total_score'],1) ?></span>
                    <?= grade_badge($r['grade'] ?? 'F') ?>
                </div>
            </div>
            <div style="background:var(--light);border-radius:6px;height:10px;overflow:hidden">
                <div style="height:100%;width:<?= $pct ?>%;background:<?= $barCol ?>;border-radius:6px;transition:width .6s ease"></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Detailed results table -->
<div class="card mb-20">
    <div class="card-header">
        📋 Detailed Results — <?= e(Database::fetchOne("SELECT name FROM terms WHERE id=?",[$term_id])['name'] ?? '') ?> Term
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>#</th>
                <th>Subject</th>
                <th>Code</th>
                <th>Test /<?= e(get_setting('results_test_max','20')) ?></th>
                <th>Assignment /<?= e(get_setting('results_asn_max','20')) ?></th>
                <th>Exam /<?= e(get_setting('results_exam_max','60')) ?></th>
                <th>Total /100</th>
                <th>Grade</th>
                <th>Remark</th>
                <th>Teacher Comment</th>
            </tr></thead>
            <tbody>
            <?php $i=1; foreach ($results as $r): ?>
                <tr>
                    <td class="text-muted text-sm"><?= $i++ ?></td>
                    <td><strong><?= e($r['subject_name']) ?></strong></td>
                    <td>
                        <?php if ($r['subject_code']): ?>
                            <span class="code"><?= e($r['subject_code']) ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="color:var(--blue);font-weight:700"><?= $r['test_score'] ?></td>
                    <td style="color:var(--purple);font-weight:700"><?= $r['assignment_score'] ?></td>
                    <td style="color:var(--emerald);font-weight:700"><?= $r['exam_score'] ?></td>
                    <td>
                        <strong style="font-size:16px;color:<?= $r['total_score']>=50?'var(--text)':'var(--red)' ?>">
                            <?= number_format($r['total_score'],1) ?>
                        </strong>
                    </td>
                    <td><?= grade_badge($r['grade'] ?? 'F') ?></td>
                    <td class="text-sm text-muted"><?= e($r['remark'] ?? '—') ?></td>
                    <td class="text-sm" style="max-width:160px;font-style:italic">
                        <?= $r['teacher_comment'] ? e($r['teacher_comment']) : '<span class="text-muted">—</span>' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:var(--navy);color:var(--white)">
                    <td colspan="6" style="padding:12px 14px;font-weight:700;font-size:14px">
                        Overall Average (<?= $subjectCount ?> subjects)
                    </td>
                    <td style="padding:12px 14px;font-size:20px;font-weight:900;color:var(--accent)">
                        <?= number_format($avgScore,1) ?>
                    </td>
                    <td style="padding:12px 14px"><?= grade_badge($avgGrade['grade']) ?></td>
                    <td colspan="2" style="padding:12px 14px;color:rgba(255,255,255,.7)">
                        <?= e($avgGrade['remark']) ?>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php if (count($allTermResults) > 1): ?>
<!-- Term comparison -->
<div class="card">
    <div class="card-header">📈 Term Performance Comparison</div>
    <div class="card-body">
        <div style="display:flex;align-items:flex-end;gap:16px;height:100px">
            <?php foreach ($allTermResults as $tr):
                $pct = min(100, round($tr['avg_score']));
            ?>
            <div style="flex:1;text-align:center">
                <div style="font-size:13px;font-weight:800;margin-bottom:4px"><?= number_format($tr['avg_score'],1) ?></div>
                <div style="height:<?= $pct ?>px;background:<?= $term_id==$tr['id']?'var(--accent)':'var(--navy)' ?>;
                            border-radius:4px 4px 0 0;min-height:4px;transition:height .6s"></div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:6px;font-weight:700"><?= e($tr['term_name']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<div class="card">
    <div class="card-body table-empty">
        <div class="table-empty-icon">📊</div>
        No results have been entered for the selected term yet.
        <div class="text-sm text-muted mt-8">Check back after your teacher enters your scores.</div>
    </div>
</div>
<?php endif; ?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
