<?php
// ============================================================
// secretary/dashboard.php — Secretary Dashboard
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_SECRETARY);

$pageTitle  = 'Dashboard';
$activeMenu = 'dashboard';

$sess_id = current_session_id();
$term_id = current_term_id();

// Secretary-specific stats: payments only
$payStats = Database::fetchOne(
    "SELECT SUM(amount_due) AS total_due, SUM(amount_paid) AS total_paid,
            SUM(amount_due-amount_paid) AS total_balance,
            COUNT(CASE WHEN status='paid'    THEN 1 END) AS paid,
            COUNT(CASE WHEN status='partial' THEN 1 END) AS partial,
            COUNT(CASE WHEN status='unpaid'  THEN 1 END) AS unpaid,
            COUNT(*) AS total
     FROM payments WHERE session_id=? AND term_id=?",
    [$sess_id, $term_id]
);

$recentPayments = Database::fetchAll(
    "SELECT p.*, s.full_name, s.student_id AS sid, c.name AS class_name
     FROM payments p JOIN students s ON s.id=p.student_id JOIN classes c ON c.id=s.class_id
     WHERE p.session_id=? AND p.term_id=?
     ORDER BY p.created_at DESC LIMIT 10",
    [$sess_id, $term_id]
);

$announcements = Database::fetchAll(
    "SELECT a.*, u.full_name AS author FROM announcements a JOIN users u ON u.id=a.posted_by
     WHERE (a.expires_at IS NULL OR a.expires_at >= CURDATE())
     ORDER BY a.created_at DESC LIMIT 5"
);

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📊 Dashboard</h1>
        <p class="page-subtitle">Welcome, <?= e(current_full_name()) ?> — Secretary/Accountant Portal</p>
    </div>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>/secretary/payments.php" class="btn btn-primary">+ Record Payment</a>
    </div>
</div>

<div class="stats-grid mb-24" style="grid-template-columns:repeat(4,1fr)">
    <div class="stat-card stat-blue">
        <div class="stat-icon">💰</div>
        <div class="stat-info">
            <div class="stat-value" style="font-size:18px"><?= money((float)($payStats['total_due']??0)) ?></div>
            <div class="stat-label">Total Due</div>
        </div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-icon">✅</div>
        <div class="stat-info">
            <div class="stat-value" style="font-size:18px"><?= money((float)($payStats['total_paid']??0)) ?></div>
            <div class="stat-label">Collected</div>
        </div>
    </div>
    <div class="stat-card stat-red">
        <div class="stat-icon">⚠️</div>
        <div class="stat-info">
            <div class="stat-value" style="font-size:18px"><?= money((float)($payStats['total_balance']??0)) ?></div>
            <div class="stat-label">Outstanding</div>
        </div>
    </div>
    <div class="stat-card stat-gold">
        <div class="stat-icon">📊</div>
        <?php $rate = (float)($payStats['total_due']??0)>0 ? round(((float)$payStats['total_paid']/(float)$payStats['total_due'])*100) : 0; ?>
        <div class="stat-info">
            <div class="stat-value"><?= $rate ?>%</div>
            <div class="stat-label">Collection Rate</div>
        </div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header">💳 Recent Payments
            <a href="<?= BASE_URL ?>/secretary/payments.php" class="btn btn-sm btn-outline">View All</a>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Student</th><th>Class</th><th>Paid</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($recentPayments as $p): ?>
                    <tr>
                        <td><div style="font-weight:700;font-size:13px"><?= e($p['full_name']) ?></div></td>
                        <td><span class="badge badge-navy"><?= e($p['class_name']) ?></span></td>
                        <td style="color:var(--emerald);font-weight:700"><?= money($p['amount_paid']) ?></td>
                        <td><?= status_badge($p['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-header">📢 Announcements</div>
        <div style="padding:0">
            <?php foreach ($announcements as $ann): ?>
                <div style="padding:12px 16px;border-bottom:1px solid var(--border)">
                    <div style="display:flex;gap:8px;margin-bottom:4px">
                        <?= status_badge($ann['priority']) ?>
                        <span class="text-xs text-muted"><?= format_date($ann['created_at'],'d M Y') ?></span>
                    </div>
                    <div style="font-weight:700;font-size:13px"><?= e($ann['title']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
