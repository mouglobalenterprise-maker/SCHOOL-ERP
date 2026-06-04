<?php
// ============================================================
// admin/payments_receipt.php — Printable Payment Receipt
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role([ROLE_ADMIN, ROLE_SECRETARY, ROLE_STUDENT]);

$id = int_val($_GET['id'] ?? 0);
if (!$id) { die('Invalid receipt ID.'); }

$payment = Database::fetchOne(
    "SELECT p.*,
            s.full_name, s.student_id AS sid, s.parent_name, s.parent_phone1,
            c.name AS class_name,
            t.name AS term_name,
            ses.name AS session_name,
            u.full_name AS recorded_by_name
     FROM payments p
     JOIN students s   ON s.id  = p.student_id
     JOIN classes  c   ON c.id  = s.class_id
     JOIN terms    t   ON t.id  = p.term_id
     JOIN academic_sessions ses ON ses.id = p.session_id
     LEFT JOIN users u ON u.id  = p.recorded_by
     WHERE p.id = ?",
    [$id]
);

if (!$payment) { die('Payment not found.'); }

// Students can only view their own receipts
if (is_student()) {
    $myStudent = Database::fetchOne(
        "SELECT id FROM students WHERE user_id=?", [current_user_id()]
    );
    if (!$myStudent || $myStudent['id'] != $payment['student_id']) {
        die('Access denied.');
    }
}

$schoolName    = get_setting('school_name', 'School');
$schoolAddress = get_setting('school_address', '');
$schoolPhone   = get_setting('school_phone', '');
$schoolEmail   = get_setting('school_email', '');
$logo          = get_setting('school_logo', '');
$currency      = get_setting('currency', 'GMD');
$currSymbol    = get_setting('currency_symbol', 'D');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Receipt — <?= e($payment['payment_code']) ?></title>
    <style>
        * { box-sizing:border-box; margin:0; padding:0; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 13px;
            color: #1E293B;
            background: #fff;
            padding: 20px;
        }
        .receipt {
            max-width: 680px;
            margin: 0 auto;
            border: 2px solid #0B1D3A;
            border-radius: 12px;
            overflow: hidden;
        }
        .receipt-header {
            background: #0B1D3A;
            color: #fff;
            padding: 24px 28px;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .school-logo {
            width: 64px; height: 64px;
            background: #F4B942;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 32px; flex-shrink: 0;
        }
        .school-logo img { width:64px; height:64px; object-fit:contain; border-radius:10px; }
        .school-name  { font-size: 20px; font-weight: 800; line-height:1.2; }
        .school-sub   { font-size: 12px; opacity: .7; margin-top: 2px; }
        .receipt-title {
            background: #F4B942;
            color: #0B1D3A;
            text-align: center;
            padding: 10px;
            font-size: 15px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .receipt-body { padding: 24px 28px; }
        .receipt-code {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; padding-bottom: 16px;
            border-bottom: 2px dashed #E2E8F0;
        }
        .code-label { font-size: 11px; text-transform: uppercase; color: #64748B; font-weight: 700; }
        .code-value { font-size: 18px; font-weight: 900; color: #0B1D3A; font-family: monospace; }
        .status-badge {
            padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: 800;
        }
        .status-paid    { background: #D1FAE5; color: #065F46; }
        .status-partial { background: #FEF3C7; color: #92400E; }
        .status-unpaid  { background: #FEE2E2; color: #991B1B; }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            margin-bottom: 20px;
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            overflow: hidden;
        }
        .info-cell {
            padding: 10px 14px;
            border-bottom: 1px solid #E2E8F0;
            border-right: 1px solid #E2E8F0;
        }
        .info-cell:nth-child(even) { border-right: none; }
        .info-cell:nth-last-child(-n+2) { border-bottom: none; }
        .info-label { font-size: 10px; text-transform: uppercase; color: #64748B; font-weight: 700; margin-bottom: 2px; }
        .info-value { font-size: 13px; font-weight: 700; color: #1E293B; }
        .amount-section {
            background: #F8FAFC;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 20px;
        }
        .amount-row {
            display: flex; justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #E2E8F0;
            font-size: 14px;
        }
        .amount-row:last-child { border-bottom: none; }
        .amount-row.total {
            font-size: 16px; font-weight: 900;
            padding-top: 12px; margin-top: 4px;
        }
        .amount-row.balance { color: #EF4444; }
        .amount-row.balance.zero { color: #10B981; }
        .receipt-footer {
            border-top: 2px dashed #E2E8F0;
            padding-top: 16px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }
        .sig-line {
            text-align: center;
            width: 180px;
        }
        .sig-line-bar { border-top: 1px solid #1E293B; padding-top: 6px; font-size: 11px; color: #64748B; }
        .watermark {
            text-align: center;
            padding: 12px;
            background: #F8FAFC;
            border-top: 1px solid #E2E8F0;
            font-size: 11px;
            color: #94A3B8;
        }
        .print-actions {
            text-align: center;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        .btn-print {
            background: #0B1D3A; color: #fff;
            border: none; padding: 10px 24px;
            border-radius: 8px; font-size: 14px; font-weight: 700;
            cursor: pointer; font-family: inherit;
        }
        .btn-back {
            background: #F1F5F9; color: #1E293B;
            border: 1px solid #E2E8F0; padding: 10px 24px;
            border-radius: 8px; font-size: 14px; font-weight: 700;
            cursor: pointer; font-family: inherit; text-decoration: none;
        }
        @media print {
            .print-actions { display: none !important; }
            body { padding: 0; }
            .receipt { border-radius: 0; border: 1px solid #ccc; }
        }
    </style>
</head>
<body>

<!-- Action buttons (hidden on print) -->
<div class="print-actions">
    <a href="javascript:history.back()" class="btn-back">← Back</a>
    <button class="btn-print" onclick="window.print()">🖨️ Print Receipt</button>
</div>

<div class="receipt">

    <!-- Header -->
    <div class="receipt-header">
        <div class="school-logo">
            <?php if ($logo && file_exists(UPLOADS_PATH . '/logos/' . $logo)): ?>
                <img src="<?= UPLOADS_URL ?>/logos/<?= e($logo) ?>" alt="Logo">
            <?php else: ?>🎓<?php endif; ?>
        </div>
        <div>
            <div class="school-name"><?= e($schoolName) ?></div>
            <div class="school-sub"><?= e($schoolAddress) ?></div>
            <div class="school-sub"><?= e($schoolPhone) ?> &bull; <?= e($schoolEmail) ?></div>
        </div>
    </div>

    <!-- Title bar -->
    <div class="receipt-title">Official Payment Receipt</div>

    <div class="receipt-body">

        <!-- Code + status -->
        <div class="receipt-code">
            <div>
                <div class="code-label">Receipt / Payment Code</div>
                <div class="code-value"><?= e($payment['payment_code']) ?></div>
                <?php if ($payment['receipt_no']): ?>
                    <div style="font-size:11px;color:#64748B;margin-top:2px">
                        Official Receipt No: <?= e($payment['receipt_no']) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <span class="status-badge status-<?= $payment['status'] ?>">
                    <?= strtoupper($payment['status']) ?>
                </span>
                <div style="font-size:11px;color:#64748B;margin-top:6px;text-align:right">
                    Issued: <?= date('d M Y, H:i') ?>
                </div>
            </div>
        </div>

        <!-- Student + payment info grid -->
        <div class="info-grid">
            <div class="info-cell">
                <div class="info-label">Student Name</div>
                <div class="info-value"><?= e($payment['full_name']) ?></div>
            </div>
            <div class="info-cell">
                <div class="info-label">Student ID</div>
                <div class="info-value"><?= e($payment['sid']) ?></div>
            </div>
            <div class="info-cell">
                <div class="info-label">Class</div>
                <div class="info-value"><?= e($payment['class_name']) ?></div>
            </div>
            <div class="info-cell">
                <div class="info-label">Term / Session</div>
                <div class="info-value"><?= e($payment['term_name']) ?> Term, <?= e($payment['session_name']) ?></div>
            </div>
            <div class="info-cell">
                <div class="info-label">Parent / Guardian</div>
                <div class="info-value"><?= e($payment['parent_name'] ?? '—') ?></div>
            </div>
            <div class="info-cell">
                <div class="info-label">Parent Phone</div>
                <div class="info-value">+<?= e($payment['parent_phone1']) ?></div>
            </div>
            <div class="info-cell">
                <div class="info-label">Fee Type</div>
                <div class="info-value"><?= e($payment['fee_type']) ?></div>
            </div>
            <div class="info-cell">
                <div class="info-label">Payment Method</div>
                <div class="info-value"><?= e($payment['payment_method'] ?? '—') ?></div>
            </div>
            <div class="info-cell">
                <div class="info-label">Payment Date</div>
                <div class="info-value">
                    <?= $payment['payment_date'] ? format_date($payment['payment_date'], 'd F Y') : 'Not yet paid' ?>
                </div>
            </div>
            <div class="info-cell">
                <div class="info-label">Recorded By</div>
                <div class="info-value"><?= e($payment['recorded_by_name'] ?? '—') ?></div>
            </div>
        </div>

        <!-- Amount section -->
        <div class="amount-section">
            <div class="amount-row">
                <span>Total Fee Amount</span>
                <strong><?= $currency ?> <?= number_format($payment['amount_due'],2) ?></strong>
            </div>
            <div class="amount-row" style="color:var(--emerald,#10B981)">
                <span>Amount Paid</span>
                <strong style="color:#10B981"><?= $currency ?> <?= number_format($payment['amount_paid'],2) ?></strong>
            </div>
            <div class="amount-row <?= $payment['balance']<=0?'balance zero':'balance' ?>">
                <span>Balance Remaining</span>
                <strong><?= $currency ?> <?= number_format($payment['balance'],2) ?></strong>
            </div>
            <?php if ($payment['notes']): ?>
            <div style="margin-top:10px;padding-top:10px;border-top:1px dashed #E2E8F0;
                        font-size:12px;color:#64748B">
                📝 Notes: <?= e($payment['notes']) ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Signatures -->
        <div class="receipt-footer">
            <div class="sig-line">
                <div style="height:40px"></div>
                <div class="sig-line-bar">Accountant / Secretary</div>
            </div>
            <div style="text-align:center;font-size:12px;color:#94A3B8">
                <?php if ($payment['status'] === 'paid'): ?>
                    <div style="font-size:32px;margin-bottom:4px">✅</div>
                    <div style="font-weight:700;color:#10B981">FULLY PAID</div>
                <?php elseif ($payment['status'] === 'partial'): ?>
                    <div style="font-size:32px;margin-bottom:4px">⏳</div>
                    <div style="font-weight:700;color:#F59E0B">PARTIAL PAYMENT</div>
                <?php else: ?>
                    <div style="font-size:32px;margin-bottom:4px">❌</div>
                    <div style="font-weight:700;color:#EF4444">UNPAID</div>
                <?php endif; ?>
            </div>
            <div class="sig-line">
                <div style="height:40px"></div>
                <div class="sig-line-bar">Principal / Head Teacher</div>
            </div>
        </div>
    </div>

    <!-- Footer stamp -->
    <div class="watermark">
        This is an official receipt from <?= e($schoolName) ?> &bull;
        Generated on <?= date('d M Y \a\t H:i') ?> &bull;
        <?= e(APP_NAME) ?> v<?= APP_VERSION ?>
    </div>
</div>

</body>
</html>
