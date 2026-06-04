<?php
// ============================================================
// admin/whatsapp.php — WhatsApp Integration (Free wa.me Method)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role([ROLE_ADMIN, ROLE_SECRETARY]);

$pageTitle  = 'WhatsApp Integration';
$activeMenu = 'whatsapp';

$sess_id = current_session_id();
$term_id = current_term_id();

// Filters
$class_id   = int_val($_GET['class_id']  ?? 0);
$msgType    = sanitize($_GET['msg_type'] ?? 'fee_reminder');
$customMsg  = sanitize($_GET['custom_msg'] ?? '');

// Load students
$where  = ['s.session_id = ?', "s.status='active'"];
$params = [$sess_id];
if ($class_id) { $where[] = 's.class_id=?'; $params[] = $class_id; }
$whereStr = 'WHERE ' . implode(' AND ', $where);

$students = Database::fetchAll(
    "SELECT s.*, c.name AS class_name,
            p.status AS pay_status, p.balance AS pay_balance,
            (SELECT COUNT(*) FROM attendance a
             WHERE a.student_id=s.id AND a.term_id={$term_id} AND a.status='absent') AS absent_days
     FROM students s
     JOIN classes  c ON c.id=s.class_id
     LEFT JOIN payments p ON p.student_id=s.id AND p.term_id={$term_id} AND p.session_id={$sess_id}
     {$whereStr}
     ORDER BY c.sort_order, s.full_name",
    $params
);

$classes    = Database::fetchAll("SELECT id,name FROM classes ORDER BY sort_order");
$schoolName = get_setting('school_name', 'School');
$termName   = Database::fetchOne("SELECT name FROM terms WHERE id=?",[$term_id])['name'] ?? '';

// Message templates
function buildMessage(string $type, array $student, string $schoolName, string $termName, string $custom = ''): string {
    $name    = $student['full_name'];
    $class   = $student['class_name'];
    $balance = isset($student['pay_balance']) ? money((float)$student['pay_balance']) : '—';
    $absent  = $student['absent_days'] ?? 0;

    return match($type) {
        'fee_reminder' =>
            "Dear Parent/Guardian of *{$name}* ({$class}),\n\n"
            . "This is a reminder that your ward's school fees for *{$termName} Term* are outstanding.\n"
            . "Outstanding Balance: *{$balance}*\n\n"
            . "Please make payment at your earliest convenience.\n\n"
            . "Thank you — *{$schoolName}*",

        'fee_paid' =>
            "Dear Parent/Guardian of *{$name}* ({$class}),\n\n"
            . "We confirm that school fees for *{$termName} Term* have been *fully paid*. ✅\n"
            . "Thank you for your prompt payment.\n\n"
            . "— *{$schoolName}*",

        'result_ready' =>
            "Dear Parent/Guardian of *{$name}* ({$class}),\n\n"
            . "Your ward's academic results for *{$termName} Term* are now available. "
            . "Please visit the school or check the student portal to view them.\n\n"
            . "— *{$schoolName}*",

        'attendance_alert' =>
            "Dear Parent/Guardian of *{$name}* ({$class}),\n\n"
            . "⚠️ Your ward has been absent *{$absent} day(s)* this term.\n"
            . "Please ensure regular school attendance.\n\n"
            . "— *{$schoolName}*",

        'exam_reminder' =>
            "Dear Parent/Guardian of *{$name}* ({$class}),\n\n"
            . "📚 End of term examinations are approaching. Please ensure your ward revises adequately and is present for all exams.\n\n"
            . "— *{$schoolName}*",

        'custom' => $custom ?: "Hello, this is a message from *{$schoolName}*.",

        default => "Hello from *{$schoolName}*."
    };
}

$msgTypes = [
    'fee_reminder'     => '💳 Fee Payment Reminder',
    'fee_paid'         => '✅ Fee Payment Confirmation',
    'result_ready'     => '📊 Results Available',
    'attendance_alert' => '📅 Attendance Alert',
    'exam_reminder'    => '📚 Exam Reminder',
    'custom'           => '✏️ Custom Message',
];

// Preview message for first student
$previewMsg = !empty($students)
    ? buildMessage($msgType, $students[0], $schoolName, $termName, $customMsg)
    : buildMessage($msgType, ['full_name'=>'[Student Name]','class_name'=>'[Class]','pay_balance'=>0,'absent_days'=>0], $schoolName, $termName, $customMsg);

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📲 WhatsApp Integration</h1>
        <p class="page-subtitle">Send messages to parents via WhatsApp — <strong>Free wa.me method, no API cost</strong></p>
    </div>
</div>

<div class="grid-2" style="gap:20px;align-items:start">

    <!-- Left: Configuration -->
    <div style="display:flex;flex-direction:column;gap:20px">
        <div class="card">
            <div class="card-header">⚙️ Message Configuration</div>
            <div class="card-body">
                <form method="GET" id="waForm">
                    <div class="form-group">
                        <label class="form-label">Filter by Class</label>
                        <select name="class_id" class="form-control" onchange="this.form.submit()">
                            <option value="">All Classes (<?= count($students) ?> students)</option>
                            <?php foreach ($classes as $cls): ?>
                                <option value="<?= $cls['id'] ?>" <?= $class_id==$cls['id']?'selected':'' ?>>
                                    <?= e($cls['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Message Type</label>
                        <?php foreach ($msgTypes as $key => $label): ?>
                            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;
                                          padding:9px 12px;border-radius:8px;margin-bottom:4px;
                                          background:<?= $msgType===$key?'rgba(11,29,58,.06)':'' ?>;
                                          border:1px solid <?= $msgType===$key?'var(--navy)':'transparent' ?>">
                                <input type="radio" name="msg_type" value="<?= $key ?>"
                                       <?= $msgType===$key?'checked':'' ?>
                                       onchange="document.getElementById('waForm').submit()">
                                <span style="font-size:14px;font-weight:<?= $msgType===$key?700:500 ?>"><?= $label ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($msgType === 'custom'): ?>
                    <div class="form-group">
                        <label class="form-label">Custom Message <span class="req">*</span></label>
                        <textarea name="custom_msg" class="form-control" rows="5"
                                  placeholder="Type your message here… Use *text* for bold."><?= e($customMsg) ?></textarea>
                        <div class="form-hint">Supports WhatsApp formatting: *bold*, _italic_, ~strikethrough~</div>
                    </div>
                    <button type="submit" class="btn btn-primary">Update Preview →</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Stats card -->
        <div class="card">
            <div class="card-header">📊 Quick Stats</div>
            <div class="card-body">
                <?php
                $unpaidCount = count(array_filter($students, fn($s) => $s['pay_status'] !== 'paid' && $s['pay_balance'] > 0));
                $absentCount = count(array_filter($students, fn($s) => $s['absent_days'] >= 3));
                ?>
                <div style="display:flex;flex-direction:column;gap:8px">
                    <div style="display:flex;justify-content:space-between;padding:10px 12px;background:var(--light);border-radius:8px">
                        <span>👥 Total students selected</span>
                        <strong><?= count($students) ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:10px 12px;background:#FEF2F2;border-radius:8px">
                        <span>💳 Outstanding fees</span>
                        <strong style="color:var(--red)"><?= $unpaidCount ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:10px 12px;background:#FFFBEB;border-radius:8px">
                        <span>📅 3+ absences this term</span>
                        <strong style="color:var(--accent)"><?= $absentCount ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: Preview + Student list -->
    <div style="display:flex;flex-direction:column;gap:20px">

        <!-- Message preview -->
        <div class="card">
            <div class="card-header">
                👁️ Message Preview
                <span class="badge badge-success">Free wa.me Method</span>
            </div>
            <div class="card-body">
                <div style="background:#ECE5DD;border-radius:12px;padding:16px;margin-bottom:16px">
                    <div style="background:#fff;border-radius:8px;padding:12px 14px;
                                box-shadow:0 1px 3px rgba(0,0,0,.1);max-width:340px;margin-left:auto">
                        <div style="font-size:12px;color:#25D366;font-weight:700;margin-bottom:6px">
                            <?= e($schoolName) ?>
                        </div>
                        <div style="font-size:13px;line-height:1.6;color:#111;white-space:pre-line"><?= nl2br(e($previewMsg)) ?></div>
                        <div style="font-size:10px;color:#999;text-align:right;margin-top:6px"><?= date('H:i') ?> ✓✓</div>
                    </div>
                </div>
                <div style="background:#FEF3C7;border-radius:8px;padding:10px 12px;font-size:12px;color:#92400E">
                    ℹ️ Clicking "Send" opens WhatsApp Web/App with the message pre-filled.
                    Each student requires a separate click. No API key or paid service needed.
                </div>
            </div>
        </div>

        <!-- Student list with send buttons -->
        <div class="card">
            <div class="card-header">
                👨‍👩‍👧 Students — Send Messages
                <span class="badge badge-primary"><?= count($students) ?> contacts</span>
            </div>
            <div style="max-height:500px;overflow-y:auto">
                <table class="data-table">
                    <thead style="position:sticky;top:0;z-index:2">
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Class</th>
                            <th>Fee Status</th>
                            <th>📲 Phone 1</th>
                            <th>📲 Phone 2</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($students): $i=1; foreach ($students as $s):
                        $msg1 = buildMessage($msgType, $s, $schoolName, $termName, $customMsg);
                        $wa1  = wa_link($s['parent_phone1'], $msg1);
                        $wa2  = wa_link($s['parent_phone2'], $msg1);
                    ?>
                        <tr>
                            <td class="text-muted text-sm"><?= $i++ ?></td>
                            <td>
                                <div style="font-weight:700;font-size:13px"><?= e($s['full_name']) ?></div>
                                <div class="text-xs text-muted"><?= e($s['student_id']) ?></div>
                            </td>
                            <td><span class="badge badge-navy"><?= e($s['class_name']) ?></span></td>
                            <td>
                                <?php if (isset($s['pay_status'])): ?>
                                    <?= status_badge($s['pay_status']) ?>
                                    <?php if ($s['pay_balance'] > 0): ?>
                                        <div class="text-xs" style="color:var(--red);font-weight:700"><?= money($s['pay_balance']) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted text-xs">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= e($wa1) ?>" target="_blank" class="btn btn-sm btn-whatsapp">
                                    📲 +<?= e($s['parent_phone1']) ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?= e($wa2) ?>" target="_blank" class="btn btn-sm btn-wa-dark">
                                    📲 +<?= e($s['parent_phone2']) ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" class="table-empty">No students found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Broadcast all -->
            <?php if (!empty($students)): ?>
            <div class="card-footer">
                <div style="background:#FEF3C7;border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:12px;color:#92400E">
                    ⚠️ <strong>Bulk Send:</strong> Each link below opens a separate WhatsApp chat.
                    Use browser "Open in new tab" + right-click for faster multi-sending.
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <?php foreach (array_slice($students,0,20) as $s):
                        $msg = buildMessage($msgType, $s, $schoolName, $termName, $customMsg);
                    ?>
                        <a href="<?= e(wa_link($s['parent_phone1'],$msg)) ?>" target="_blank"
                           class="btn btn-sm btn-whatsapp" style="margin-bottom:4px">
                            📲 <?= e($s['full_name']) ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if (count($students) > 20): ?>
                        <span class="text-sm text-muted" style="align-self:center">
                            +<?= count($students)-20 ?> more — use the table above for remaining students
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>
