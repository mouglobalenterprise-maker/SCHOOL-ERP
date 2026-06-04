<?php
// ============================================================
// student/payments.php — Student Fee Status Portal (Read-Only)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_STUDENT);

$pageTitle  = 'My Fee Status';
$activeMenu = 'payments';

$sess_id = current_session_id();

$student = Database::fetchOne(
    "SELECT s.*, c.name AS class_name
     FROM students s JOIN classes c ON c.id=s.class_id
     WHERE s.user_id=? AND s.session_id=?",
    [current_user_id(), $sess_id]
);
if (!$student) {
    flash_error('No student profile found.');
    redirect(BASE_URL . '/student/dashboard.php');
}

// All payments for this student
$payments = Database::fetchAll(
    "SELECT p.*, t.name AS term_name
     FROM payments p JOIN terms t ON t.id=p.term_id
     WHERE p.student_id=? AND p.session_id=?
     ORDER BY t.id ASC",
    [$student['id'], $sess_id]
);

$totalDue     = array_sum(array_column($payments,'amount_due'));
$totalPaid    = array_sum(array_column($payments,'amount_paid'));
$totalBalance = $totalDue - $totalPaid;
$colRate      = $totalDue > 0 ? round(($totalPaid/$totalDue)*100) : 0;

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">💳 My Fee Status</h1>
        <p class="page-subtitle">
            <?= e($student['full_name']) ?> &mdash;
            <?= e($student['class_name']) ?> &mdash;
            <?= e(get_setting('current_session')) ?>
        </p>
    </div>
</div>

<!-- Summary -->
<div class="stats-grid mb-20" style="grid-template-columns:repeat(4,1fr)">
    <div class="stat-card stat-blue">
        <div class="stat-icon">💰</div>
        <div class="stat-info">
            <div class="stat-value" style="font-size:20px"><?= money($totalDue) ?></div>
            <div class="stat-label">Total Fees</div>
        </div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-icon">✅</div>
        <div class="stat-info">
            <div class="stat-value" style="font-size:20px"><?= money($totalPaid) ?></div>
            <div class="stat-label">Amount Paid</div>
        </div>
    </div>
    <div class="stat-card <?= $totalBalance>0?'stat-red':'stat-green' ?>">
        <div class="stat-icon"><?= $totalBalance>0?'⚠️':'🏆' ?></div>
        <div class="stat-info">
            <div class="stat-value" style="font-size:20px"><?= money($totalBalance) ?></div>
            <div class="stat-label">Balance Due</div>
        </div>
    </div>
    <div class="stat-card <?= $colRate>=100?'stat-green':($colRate>=50?'stat-gold':'stat-red') ?>">
        <div class="stat-icon">📊</div>
        <div class="stat-info">
            <div class="stat-value"><?= $colRate ?>%</div>
            <div class="stat-label">Paid Rate</div>
        </div>
    </div>
</div>

<!-- Alert for outstanding balance -->
<?php if ($totalBalance > 0): ?>
<div style="background:#FEE2E2;border-radius:10px;padding:14px 18px;margin-bottom:20px;
            border:1px solid #FECACA;display:flex;align-items:center;gap:12px">
    <span style="font-size:28px">⚠️</span>
    <div>
        <div style="font-weight:800;color:#991B1B;font-size:15px">Outstanding Balance: <?= money($totalBalance) ?></div>
        <div style="color:#7F1D1D;font-size:13px;margin-top:2px">
            Please settle your outstanding fees before the end of term to avoid exam restrictions.
            Contact the school office or speak to the secretary.
        </div>
    </div>
</div>
<?php else: ?>
<div style="background:#D1FAE5;border-radius:10px;padding:14px 18px;margin-bottom:20px;
            border:1px solid #A7F3D0;display:flex;align-items:center;gap:12px">
    <span style="font-size:28px">🏆</span>
    <div>
        <div style="font-weight:800;color:#065F46;font-size:15px">All fees are fully paid!</div>
        <div style="color:#064E3B;font-size:13px;margin-top:2px">Thank you for keeping up with your payments.</div>
    </div>
</div>
<?php endif; ?>

<!-- Progress bar -->
<div class="card mb-20">
    <div class="card-body">
        <div style="display:flex;justify-content:space-between;margin-bottom:8px">
            <span style="font-weight:700">Payment Progress — <?= e(get_setting('current_session')) ?></span>
            <span style="font-weight:800;font-size:18px;color:<?= $colRate>=100?'var(--emerald)':($colRate>=50?'var(--accent)':'var(--red)') ?>">
                <?= $colRate ?>%
            </span>
        </div>
        <div class="progress" style="height:14px">
            <div class="progress-bar <?= $colRate>=100?'green':($colRate>=50?'orange':'red') ?>"
                 style="width:<?= $colRate ?>%"></div>
        </div>
    </div>
</div>

<!-- Payment records table -->
<div class="card">
    <div class="card-header">📋 Payment Records</div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>Payment Code</th>
                <th>Term</th>
                <th>Fee Type</th>
                <th>Amount Due</th>
                <th>Amount Paid</th>
                <th>Balance</th>
                <th>Method</th>
                <th>Date</th>
                <th>Status</th>
                <th>Receipt</th>
            </tr></thead>
            <tbody>
            <?php if ($payments): foreach ($payments as $p): ?>
                <tr style="<?= $p['status']==='unpaid'?'background:#FEF2F2':($p['status']==='partial'?'background:#FFFBEB':'') ?>">
                    <td><span class="code" style="font-size:11px"><?= e($p['payment_code']) ?></span></td>
                    <td><?= e($p['term_name']) ?> Term</td>
                    <td class="text-sm"><?= e($p['fee_type']) ?></td>
                    <td style="font-weight:700"><?= money($p['amount_due']) ?></td>
                    <td style="color:var(--emerald);font-weight:700"><?= money($p['amount_paid']) ?></td>
                    <td style="color:<?= $p['balance']>0?'var(--red)':'var(--emerald)' ?>;font-weight:700">
                        <?= money($p['balance']) ?>
                    </td>
                    <td class="text-sm text-muted"><?= e($p['payment_method'] ?? '—') ?></td>
                    <td class="text-sm text-muted">
                        <?= $p['payment_date'] ? format_date($p['payment_date']) : '—' ?>
                    </td>
                    <td><?= status_badge($p['status']) ?></td>
                    <td>
                        <?php if ($p['status'] !== 'unpaid'): ?>
                            <a href="<?= BASE_URL ?>/admin/payments_receipt.php?id=<?= $p['id'] ?>"
                               target="_blank" class="btn btn-sm btn-outline">🖨️ Receipt</a>
                        <?php else: ?>
                            <span class="text-muted text-xs">No receipt</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="10" class="table-empty">No payment records found.</td></tr>
            <?php endif; ?>
            </tbody>
            <?php if ($payments): ?>
            <tfoot>
                <tr style="background:var(--navy);color:var(--white)">
                    <td colspan="3" style="padding:12px 14px;font-weight:700">Totals</td>
                    <td style="padding:12px 14px;font-weight:800"><?= money($totalDue) ?></td>
                    <td style="padding:12px 14px;font-weight:800;color:#86EFAC"><?= money($totalPaid) ?></td>
                    <td style="padding:12px 14px;font-weight:800;color:<?= $totalBalance>0?'#FCA5A5':'#86EFAC' ?>">
                        <?= money($totalBalance) ?>
                    </td>
                    <td colspan="4"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
