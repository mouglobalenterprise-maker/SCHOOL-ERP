<?php
// ============================================================
// admin/payments.php — Payment Management (Admin View)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role([ROLE_ADMIN, ROLE_SECRETARY]);

$pageTitle  = 'Payment Management';
$activeMenu = 'payments';

$sess_id = current_session_id();
$term_id = current_term_id();

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
    $action = sanitize($_POST['action'] ?? '');

    // ── ADD / UPDATE payment ──────────────────────────────────
    if (in_array($action, ['add', 'edit'])) {
        $student_id  = int_val($_POST['student_id']  ?? 0);
        $term_sel    = int_val($_POST['term_id']     ?? $term_id);
        $fee_type    = sanitize($_POST['fee_type']   ?? 'School Fees');
        $amount_due  = float_val($_POST['amount_due'] ?? 0);
        $amount_paid = float_val($_POST['amount_paid']?? 0);
        $pay_method  = sanitize($_POST['payment_method'] ?? 'Cash');
        $receipt_no  = sanitize($_POST['receipt_no'] ?? '');
        $pay_date    = sanitize($_POST['payment_date']?? date('Y-m-d'));
        $notes       = sanitize($_POST['notes']      ?? '');

        $errors = [];
        if (!$student_id)   $errors[] = 'Student is required.';
        if ($amount_due<=0) $errors[] = 'Amount due must be greater than zero.';
        if ($amount_paid<0) $errors[] = 'Amount paid cannot be negative.';
        if ($amount_paid > $amount_due) $errors[] = 'Amount paid cannot exceed amount due.';

        $status = 'unpaid';
        if ($amount_paid >= $amount_due) $status = 'paid';
        elseif ($amount_paid > 0)        $status = 'partial';

        if (empty($errors)) {
            if ($action === 'add') {
                $payCode = generate_payment_code();
                Database::insert(
                    "INSERT INTO payments
                        (payment_code, student_id, term_id, session_id, fee_type,
                         amount_due, amount_paid, payment_date, payment_method,
                         receipt_no, status, notes, recorded_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [
                        $payCode, $student_id, $term_sel, $sess_id, $fee_type,
                        $amount_due, $amount_paid,
                        $amount_paid > 0 ? $pay_date : null,
                        $pay_method,
                        $receipt_no ?: null,
                        $status, $notes ?: null,
                        current_user_id()
                    ]
                );
                // Notify student
                $st = Database::fetchOne("SELECT user_id, full_name FROM students WHERE id=?", [$student_id]);
                if ($st && $st['user_id']) {
                    send_notification(
                        $st['user_id'],
                        'Payment Recorded',
                        "A payment of " . money($amount_paid) . " has been recorded for your account.",
                        'payment',
                        BASE_URL . '/student/payments.php'
                    );
                }
                audit_log(current_user_id(), current_username(), 'record_payment', 'Payments',
                    "Recorded {$payCode} — {$fee_type} — " . money($amount_paid) . " for student ID {$student_id}");
                flash_success("Payment recorded successfully. Code: <strong>{$payCode}</strong>");
            } else {
                $pay_id = int_val($_POST['pay_id'] ?? 0);
                Database::execute(
                    "UPDATE payments SET
                        fee_type=?, amount_due=?, amount_paid=?,
                        payment_date=?, payment_method=?, receipt_no=?,
                        status=?, notes=?, recorded_by=?
                     WHERE id=?",
                    [
                        $fee_type, $amount_due, $amount_paid,
                        $amount_paid > 0 ? $pay_date : null,
                        $pay_method,
                        $receipt_no ?: null,
                        $status, $notes ?: null,
                        current_user_id(),
                        $pay_id
                    ]
                );
                audit_log(current_user_id(), current_username(), 'update_payment', 'Payments',
                    "Updated payment ID {$pay_id}");
                flash_success('Payment updated successfully.');
            }
        } else {
            flash_error(implode('<br>', $errors));
        }
    }

    // ── DELETE ────────────────────────────────────────────────
    elseif ($action === 'delete') {
        $pay_id = int_val($_POST['pay_id'] ?? 0);
        if ($pay_id) {
            $p = Database::fetchOne("SELECT payment_code FROM payments WHERE id=?", [$pay_id]);
            Database::execute("DELETE FROM payments WHERE id=?", [$pay_id]);
            audit_log(current_user_id(), current_username(), 'delete_payment', 'Payments',
                "Deleted payment {$p['payment_code']}");
            flash_success('Payment record deleted.');
        }
    }

    redirect(BASE_URL . '/admin/payments.php?' . http_build_query($_GET));
}

// ── Filters ───────────────────────────────────────────────────
$filterClass  = int_val($_GET['class_id']  ?? 0);
$filterTerm   = int_val($_GET['term_id']   ?? $term_id);
$filterStatus = sanitize($_GET['status']   ?? '');
$search       = sanitize($_GET['q']        ?? '');
$page         = int_val($_GET['page']      ?? 1);

$where  = ['p.session_id = ?'];
$params = [$sess_id];

if ($filterTerm)   { $where[] = 'p.term_id = ?';     $params[] = $filterTerm; }
if ($filterClass)  { $where[] = 's.class_id = ?';    $params[] = $filterClass; }
if ($filterStatus) { $where[] = 'p.status = ?';      $params[] = $filterStatus; }
if ($search) {
    $where[]  = '(s.full_name LIKE ? OR s.student_id LIKE ? OR p.payment_code LIKE ? OR p.receipt_no LIKE ?)';
    $like     = "%{$search}%";
    $params   = array_merge($params, [$like, $like, $like, $like]);
}

$whereStr = 'WHERE ' . implode(' AND ', $where);

$baseSql = "SELECT p.*,
                   s.full_name, s.student_id AS sid, s.parent_phone1, s.parent_phone2,
                   c.name AS class_name,
                   t.name AS term_name,
                   u.full_name AS recorded_by_name
            FROM payments p
            JOIN students s ON s.id = p.student_id
            JOIN classes  c ON c.id = s.class_id
            JOIN terms    t ON t.id = p.term_id
            LEFT JOIN users u ON u.id = p.recorded_by
            {$whereStr}
            ORDER BY p.created_at DESC";

$pager    = paginate($baseSql, $params, $page);
$payments = $pager['rows'];

// ── Financial summary ─────────────────────────────────────────
$summary = Database::fetchOne(
    "SELECT
        SUM(p.amount_due)                              AS total_due,
        SUM(p.amount_paid)                             AS total_paid,
        SUM(p.amount_due - p.amount_paid)              AS total_balance,
        COUNT(CASE WHEN p.status='paid'    THEN 1 END) AS paid_count,
        COUNT(CASE WHEN p.status='partial' THEN 1 END) AS partial_count,
        COUNT(CASE WHEN p.status='unpaid'  THEN 1 END) AS unpaid_count,
        COUNT(*)                                        AS total_records
     FROM payments p
     JOIN students s ON s.id=p.student_id
     {$whereStr}",
    $params
);

// Dropdowns
$classes  = Database::fetchAll("SELECT id,name FROM classes ORDER BY sort_order");
$terms    = Database::fetchAll(
    "SELECT t.* FROM terms t JOIN academic_sessions ses ON ses.id=t.session_id WHERE ses.is_current=1 ORDER BY t.id"
);
$students = Database::fetchAll(
    "SELECT s.id, s.full_name, s.student_id AS sid, c.name AS class_name
     FROM students s JOIN classes c ON c.id=s.class_id
     WHERE s.session_id=? AND s.status='active'
     ORDER BY s.full_name",
    [$sess_id]
);
$feeTypes = ['School Fees', 'Exam Fees', 'Sports Levy', 'Library Fees', 'PTA Dues', 'Other'];
$payMethods = ['Cash', 'Bank Transfer', 'Mobile Money', 'Cheque', 'Online'];

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">💳 Payment Management</h1>
        <p class="page-subtitle">
            <?= $pager['total'] ?> payment records &nbsp;|&nbsp;
            <?= e(get_setting('current_term')) ?> Term, <?= e(get_setting('current_session')) ?>
        </p>
    </div>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>/api/get_payments.php?export=csv&session_id=<?= $sess_id ?>&term_id=<?= $filterTerm ?>&class_id=<?= $filterClass ?>&status=<?= $filterStatus ?>"
           class="btn btn-outline">📤 Export CSV</a>
        <button class="btn btn-primary" onclick="openModal('addPayModal')">+ Record Payment</button>
    </div>
</div>

<!-- Financial Summary Cards -->
<div class="stats-grid mb-20" style="grid-template-columns:repeat(6,1fr)">
    <div class="stat-card stat-blue">
        <div class="stat-icon">💰</div>
        <div class="stat-info">
            <div class="stat-value" style="font-size:18px"><?= money((float)($summary['total_due']??0)) ?></div>
            <div class="stat-label">Total Due</div>
        </div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-icon">✅</div>
        <div class="stat-info">
            <div class="stat-value" style="font-size:18px"><?= money((float)($summary['total_paid']??0)) ?></div>
            <div class="stat-label">Collected</div>
        </div>
    </div>
    <div class="stat-card stat-red">
        <div class="stat-icon">⚠️</div>
        <div class="stat-info">
            <div class="stat-value" style="font-size:18px"><?= money((float)($summary['total_balance']??0)) ?></div>
            <div class="stat-label">Outstanding</div>
        </div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-icon">🏆</div>
        <div class="stat-info">
            <div class="stat-value"><?= $summary['paid_count']??0 ?></div>
            <div class="stat-label">Fully Paid</div>
        </div>
    </div>
    <div class="stat-card stat-gold">
        <div class="stat-icon">⏳</div>
        <div class="stat-info">
            <div class="stat-value"><?= $summary['partial_count']??0 ?></div>
            <div class="stat-label">Partial</div>
        </div>
    </div>
    <div class="stat-card stat-red">
        <div class="stat-icon">❌</div>
        <div class="stat-info">
            <div class="stat-value"><?= $summary['unpaid_count']??0 ?></div>
            <div class="stat-label">Unpaid</div>
        </div>
    </div>
</div>

<!-- Collection rate bar -->
<?php
$totalDue  = (float)($summary['total_due']  ?? 0);
$totalPaid = (float)($summary['total_paid'] ?? 0);
$colRate   = $totalDue > 0 ? round(($totalPaid / $totalDue) * 100) : 0;
?>
<div class="card mb-20">
    <div class="card-body" style="padding:16px 20px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <span style="font-weight:700;font-size:14px">Fee Collection Rate</span>
            <span style="font-weight:900;font-size:20px;color:<?= $colRate>=80?'var(--emerald)':($colRate>=50?'var(--accent)':'var(--red)') ?>">
                <?= $colRate ?>%
            </span>
        </div>
        <div class="progress" style="height:14px">
            <div class="progress-bar <?= $colRate>=80?'green':($colRate>=50?'orange':'red') ?>"
                 style="width:<?= $colRate ?>%"></div>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:6px" class="text-xs text-muted">
            <span>Collected: <?= money($totalPaid) ?></span>
            <span>Total Due: <?= money($totalDue) ?></span>
        </div>
    </div>
</div>

<!-- Table card -->
<div class="card">
    <!-- Toolbar -->
    <div class="table-toolbar">
        <div class="search-bar-wrap">
            <span class="search-icon">🔍</span>
            <input type="text" class="search-input" id="paySearch"
                   placeholder="Search name, ID, code, receipt…"
                   value="<?= e($search) ?>"
                   data-ajax-search="#payTbody"
                   data-search-url="<?= BASE_URL ?>/api/search.php?type=payments"
                   autocomplete="off">
        </div>
        <form method="GET" id="filterForm" style="display:contents">
            <input type="hidden" name="q" id="hiddenQ" value="<?= e($search) ?>">
            <select name="term_id" class="filter-select" onchange="this.form.submit()">
                <option value="">All Terms</option>
                <?php foreach ($terms as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= $filterTerm==$t['id']?'selected':'' ?>>
                        <?= e($t['name']) ?> Term
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="class_id" class="filter-select" onchange="this.form.submit()">
                <option value="">All Classes</option>
                <?php foreach ($classes as $cls): ?>
                    <option value="<?= $cls['id'] ?>" <?= $filterClass==$cls['id']?'selected':'' ?>>
                        <?= e($cls['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="filter-select" onchange="this.form.submit()">
                <option value="">All Status</option>
                <?php foreach (['paid','partial','unpaid'] as $s): ?>
                    <option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <div class="table-toolbar-right">
            <a href="<?= BASE_URL ?>/admin/payments.php" class="btn btn-outline btn-sm">↺ Reset</a>
        </div>
    </div>

    <!-- Table -->
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>#</th>
                <th data-sort>Code</th>
                <th data-sort>Student</th>
                <th>Class</th>
                <th>Term</th>
                <th>Fee Type</th>
                <th data-sort>Due</th>
                <th data-sort>Paid</th>
                <th data-sort>Balance</th>
                <th>Method</th>
                <th>Date</th>
                <th>Status</th>
                <th>Actions</th>
            </tr></thead>
            <tbody id="payTbody">
            <?php if ($payments): $i=($page-1)*ROWS_PER_PAGE+1; foreach ($payments as $p): ?>
                <tr style="<?= $p['status']==='unpaid'?'background:#FEF2F2':($p['status']==='partial'?'background:#FFFBEB':'') ?>">
                    <td class="text-muted text-sm"><?= $i++ ?></td>
                    <td><span class="code" style="font-size:11px"><?= e($p['payment_code']) ?></span></td>
                    <td>
                        <div style="font-weight:700"><?= e($p['full_name']) ?></div>
                        <div class="text-xs text-muted"><?= e($p['sid']) ?></div>
                    </td>
                    <td><span class="badge badge-navy"><?= e($p['class_name']) ?></span></td>
                    <td class="text-sm"><?= e($p['term_name']) ?></td>
                    <td class="text-sm"><?= e($p['fee_type']) ?></td>
                    <td style="font-weight:700"><?= money($p['amount_due']) ?></td>
                    <td style="color:var(--emerald);font-weight:700"><?= money($p['amount_paid']) ?></td>
                    <td style="color:<?= $p['balance']>0?'var(--red)':'var(--emerald)' ?>;font-weight:700">
                        <?= money($p['balance']) ?>
                    </td>
                    <td class="text-sm text-muted"><?= e($p['payment_method'] ?? '—') ?></td>
                    <td class="text-sm text-muted"><?= $p['payment_date'] ? format_date($p['payment_date']) : '—' ?></td>
                    <td><?= status_badge($p['status']) ?></td>
                    <td>
                        <div class="td-actions">
                            <a href="<?= BASE_URL ?>/admin/payments_receipt.php?id=<?= $p['id'] ?>"
                               target="_blank" class="btn btn-sm btn-outline" title="Print Receipt">🖨️</a>
                            <button class="btn btn-sm btn-primary"
                                    onclick='openEditPayModal(<?= json_encode($p) ?>)'
                                    title="Edit">✏️</button>
                            <!-- WhatsApp reminder -->
                            <?php if ($p['balance'] > 0): ?>
                                <a href="<?= e(wa_link($p['parent_phone1'],
                                    "Dear Parent, your ward " . $p['full_name'] . "'s school fees balance of " .
                                    money($p['balance']) . " for " . $p['term_name'] . " Term is outstanding. " .
                                    "Please pay at your earliest convenience. Thank you. — " . get_setting('school_name'))) ?>"
                                   target="_blank" class="btn btn-sm btn-whatsapp" title="WhatsApp Reminder">📲</a>
                            <?php endif; ?>
                            <?php if (is_admin()): ?>
                                <form method="POST" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action"  value="delete">
                                    <input type="hidden" name="pay_id"  value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"
                                            data-confirm="Delete payment <?= e($p['payment_code']) ?>?">🗑️</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="13" class="table-empty">
                    <div class="table-empty-icon">💳</div>
                    No payment records found.
                    <br><button onclick="openModal('addPayModal')" class="btn btn-sm btn-primary" style="margin-top:10px">+ Record First Payment</button>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= pagination_links($pager, BASE_URL . '/admin/payments.php?term_id=' . $filterTerm . '&class_id=' . $filterClass . '&status=' . $filterStatus . '&q=' . urlencode($search)) ?>
    <div class="card-footer text-sm text-muted">
        Showing <?= count($payments) ?> of <?= $pager['total'] ?> records
    </div>
</div>

<!-- ── Add Payment Modal ── -->
<div class="modal-backdrop" id="addPayModal" style="display:none">
    <div class="modal modal-lg">
        <div class="modal-header">
            <span class="modal-title">+ Record Payment</span>
            <button class="modal-close" data-modal-close>×</button>
        </div>
        <form method="POST" action="" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Student <span class="req">*</span></label>
                    <select name="student_id" class="form-control" required>
                        <option value="">Select student…</option>
                        <?php foreach ($students as $st): ?>
                            <option value="<?= $st['id'] ?>">
                                <?= e($st['full_name']) ?> (<?= e($st['sid']) ?>) — <?= e($st['class_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Term</label>
                        <select name="term_id" class="form-control">
                            <?php foreach ($terms as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= $t['is_current']?'selected':'' ?>>
                                    <?= e($t['name']) ?> Term
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fee Type</label>
                        <select name="fee_type" class="form-control">
                            <?php foreach ($feeTypes as $ft): ?>
                                <option value="<?= $ft ?>"><?= $ft ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Amount Due (<?= e(get_setting('currency','GMD')) ?>) <span class="req">*</span></label>
                        <input type="number" name="amount_due" id="addAmountDue"
                               class="form-control" required min="0" step="0.01"
                               oninput="calcBalance()" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Amount Paid (<?= e(get_setting('currency','GMD')) ?>) <span class="req">*</span></label>
                        <input type="number" name="amount_paid" id="addAmountPaid"
                               class="form-control" required min="0" step="0.01"
                               oninput="calcBalance()" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-control">
                            <?php foreach ($payMethods as $pm): ?>
                                <option value="<?= $pm ?>"><?= $pm ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Date</label>
                        <input type="date" name="payment_date" class="form-control"
                               value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Receipt No.</label>
                        <input type="text" name="receipt_no" class="form-control"
                               placeholder="e.g. RCT-001">
                    </div>
                    <div class="form-group" style="display:flex;flex-direction:column;justify-content:flex-end">
                        <label class="form-label">Balance</label>
                        <div style="background:var(--light);border-radius:8px;padding:10px 14px;
                                    font-size:18px;font-weight:800" id="calc_balance">—</div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"
                              placeholder="Optional payment notes…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">💾 Save Payment</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Edit Payment Modal ── -->
<div class="modal-backdrop" id="editPayModal" style="display:none">
    <div class="modal modal-lg">
        <div class="modal-header">
            <span class="modal-title">✏️ Edit Payment</span>
            <button class="modal-close" data-modal-close>×</button>
        </div>
        <form method="POST" action="" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="action"  value="edit">
            <input type="hidden" name="pay_id"  id="editPayId">
            <div class="modal-body">
                <div style="background:var(--light);border-radius:8px;padding:10px 14px;margin-bottom:16px">
                    <span class="text-sm text-muted">Payment Code: </span>
                    <span class="code" id="editPayCode"></span>
                    &nbsp;&bull;&nbsp;
                    <span class="text-sm text-muted">Student: </span>
                    <span style="font-weight:700" id="editPayStudent"></span>
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Fee Type</label>
                        <select name="fee_type" id="editFeeType" class="form-control">
                            <?php foreach ($feeTypes as $ft): ?>
                                <option value="<?= $ft ?>"><?= $ft ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Amount Due <span class="req">*</span></label>
                        <input type="number" name="amount_due" id="editAmountDue"
                               class="form-control" required min="0" step="0.01"
                               oninput="calcEditBalance()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Amount Paid <span class="req">*</span></label>
                        <input type="number" name="amount_paid" id="editAmountPaid"
                               class="form-control" required min="0" step="0.01"
                               oninput="calcEditBalance()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Balance</label>
                        <div style="background:var(--light);border-radius:8px;padding:10px 14px;
                                    font-size:18px;font-weight:800" id="calc_balance_edit">—</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" id="editPayMethod" class="form-control">
                            <?php foreach ($payMethods as $pm): ?>
                                <option value="<?= $pm ?>"><?= $pm ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Date</label>
                        <input type="date" name="payment_date" id="editPayDate" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Receipt No.</label>
                        <input type="text" name="receipt_no" id="editReceiptNo" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" id="editNotes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">💾 Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function calcBalance() {
    const due  = parseFloat(document.getElementById('addAmountDue').value  || 0);
    const paid = parseFloat(document.getElementById('addAmountPaid').value || 0);
    const bal  = due - paid;
    const el   = document.getElementById('calc_balance');
    if (el) {
        el.textContent = '<?= get_setting('currency_symbol','D') ?> ' + bal.toFixed(2);
        el.style.color = bal <= 0 ? 'var(--emerald)' : 'var(--red)';
    }
}

function calcEditBalance() {
    const due  = parseFloat(document.getElementById('editAmountDue').value  || 0);
    const paid = parseFloat(document.getElementById('editAmountPaid').value || 0);
    const bal  = due - paid;
    const el   = document.getElementById('calc_balance_edit');
    if (el) {
        el.textContent = '<?= get_setting('currency_symbol','D') ?> ' + bal.toFixed(2);
        el.style.color = bal <= 0 ? 'var(--emerald)' : 'var(--red)';
    }
}

function openEditPayModal(p) {
    document.getElementById('editPayId').value         = p.id;
    document.getElementById('editPayCode').textContent  = p.payment_code;
    document.getElementById('editPayStudent').textContent = p.full_name;
    document.getElementById('editFeeType').value        = p.fee_type;
    document.getElementById('editAmountDue').value      = p.amount_due;
    document.getElementById('editAmountPaid').value     = p.amount_paid;
    document.getElementById('editPayMethod').value      = p.payment_method || 'Cash';
    document.getElementById('editPayDate').value        = p.payment_date || '';
    document.getElementById('editReceiptNo').value      = p.receipt_no || '';
    document.getElementById('editNotes').value          = p.notes || '';
    calcEditBalance();
    openModal('editPayModal');
}

document.getElementById('paySearch').addEventListener('input', function() {
    document.getElementById('hiddenQ').value = this.value;
});
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
