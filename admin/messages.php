<?php
// ============================================================
// admin/messages.php — Internal Messaging System (REWRITTEN)
// Works for ALL roles: admin, teacher, student, secretary
// Fixes:
//  - Students can compose and view messages correctly
//  - Read messages stay visible (not deleted on view)
//  - Auto-delete ONLY read messages older than 24 hours
//  - Compose modal works for all roles
//  - Thread view shows full conversation
//  - Unread messages are NEVER auto-deleted
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_login();

$pageTitle  = 'Messages';
$activeMenu = 'messages';

// ── Auto-cleanup: delete READ messages older than 24 hours ────
// Only deletes messages where the recipient has read them AND
// they are more than 24 hours old. Unread = NEVER deleted.
Database::execute(
    "DELETE FROM messages
     WHERE is_read = 1
       AND read_at IS NOT NULL
       AND read_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)",
    []
);

// ── Determine correct message URL for this role ───────────────
$msgUrl = BASE_URL . '/admin/messages.php';

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
    $action = sanitize($_POST['action'] ?? '');

    // ── SEND ─────────────────────────────────────────────────
    if ($action === 'send') {
        $to_user   = int_val($_POST['to_user']  ?? 0);
        $subject   = sanitize($_POST['subject'] ?? '');
        $body      = sanitize($_POST['body']    ?? '');
        $broadcast = isset($_POST['broadcast']) && is_admin() ? 1 : 0;

        if (empty($subject)) { flash_error('Subject is required.'); }
        elseif (empty($body))  { flash_error('Message body is required.'); }
        elseif ($broadcast) {
            // Admin broadcast to all active users
            $users = Database::fetchAll(
                "SELECT id FROM users WHERE status='active' AND id != ?",
                [current_user_id()]
            );
            foreach ($users as $u) {
                Database::insert(
                    "INSERT INTO messages (from_user,to_user,subject,body,is_broadcast)
                     VALUES (?,?,?,?,1)",
                    [current_user_id(), $u['id'], $subject, $body]
                );
                send_notification($u['id'], "📢 {$subject}", substr($body,0,100),
                    'message', $msgUrl);
            }
            flash_success('Broadcast sent to ' . count($users) . ' users.');
        } elseif ($to_user) {
            // Verify recipient exists and is active
            $recipient = Database::fetchOne(
                "SELECT id, full_name FROM users WHERE id=? AND status='active'",
                [$to_user]
            );
            if (!$recipient) {
                flash_error('Recipient not found or inactive.');
            } else {
                Database::insert(
                    "INSERT INTO messages (from_user,to_user,subject,body,is_broadcast)
                     VALUES (?,?,?,?,0)",
                    [current_user_id(), $to_user, $subject, $body]
                );
                send_notification($to_user, "💬 {$subject}", substr($body,0,100),
                    'message', $msgUrl);
                flash_success('Message sent to ' . $recipient['full_name'] . '.');
            }
        } else {
            flash_error('Please select a recipient.');
        }
    }

    // ── DELETE single message ─────────────────────────────────
    elseif ($action === 'delete') {
        $mid = int_val($_POST['msg_id'] ?? 0);
        if ($mid) {
            // User can delete messages they sent OR received
            $deleted = Database::execute(
                "DELETE FROM messages
                 WHERE id=? AND (from_user=? OR to_user=?)",
                [$mid, current_user_id(), current_user_id()]
            );
            if ($deleted) flash_success('Message deleted.');
        }
    }

    // ── DELETE all read messages ──────────────────────────────
    elseif ($action === 'delete_read') {
        Database::execute(
            "DELETE FROM messages
             WHERE to_user=? AND is_read=1",
            [current_user_id()]
        );
        flash_success('All read messages cleared.');
    }

    // ── MARK single as read ───────────────────────────────────
    elseif ($action === 'mark_read') {
        $mid = int_val($_POST['msg_id'] ?? 0);
        if ($mid) {
            Database::execute(
                "UPDATE messages SET is_read=1, read_at=NOW()
                 WHERE id=? AND to_user=?",
                [$mid, current_user_id()]
            );
        }
    }

    // ── MARK ALL read ─────────────────────────────────────────
    elseif ($action === 'mark_all_read') {
        Database::execute(
            "UPDATE messages SET is_read=1, read_at=NOW()
             WHERE to_user=? AND is_read=0",
            [current_user_id()]
        );
        flash_success('All messages marked as read.');
    }

    redirect($msgUrl . '?' . http_build_query(
        array_filter(['box' => $_GET['box'] ?? 'inbox',
                      'msg' => $_GET['msg'] ?? ''])
    ));
}

// ── Filters ───────────────────────────────────────────────────
$box  = sanitize($_GET['box']    ?? 'inbox'); // inbox | sent
$mid  = int_val($_GET['msg']     ?? 0);
$page = int_val($_GET['page']    ?? 1);

// ── Inbox query ───────────────────────────────────────────────
// Include ALL received messages (read AND unread)
// (auto-cleanup already removed read > 24h ones above)
$inboxSql = "SELECT m.*,
                    u.full_name AS from_name,
                    u.username  AS from_username
             FROM messages m
             JOIN users u ON u.id = m.from_user
             WHERE m.to_user = ?
             ORDER BY m.is_read ASC, m.created_at DESC";
$inboxPager = paginate($inboxSql, [current_user_id()], $page);
$inbox      = $inboxPager['rows'];

// ── Sent query ────────────────────────────────────────────────
$sentSql = "SELECT m.*,
                   COALESCE(u.full_name,'All Users') AS to_name
            FROM messages m
            LEFT JOIN users u ON u.id = m.to_user
            WHERE m.from_user = ?
            ORDER BY m.created_at DESC";
$sentPager = paginate($sentSql, [current_user_id()], $page);
$sent      = $sentPager['rows'];

// ── Unread count ──────────────────────────────────────────────
$unreadCount = (int)(Database::fetchOne(
    "SELECT COUNT(*) AS c FROM messages WHERE to_user=? AND is_read=0",
    [current_user_id()]
)['c'] ?? 0);

// ── Open specific message ─────────────────────────────────────
$openMsg  = null;
$thread   = [];
if ($mid) {
    $openMsg = Database::fetchOne(
        "SELECT m.*,
                fu.full_name AS from_name, fu.username AS from_uname,
                tu.full_name AS to_name,   tu.username AS to_uname
         FROM messages m
         JOIN users fu ON fu.id = m.from_user
         LEFT JOIN users tu ON tu.id = m.to_user
         WHERE m.id = ?
           AND (m.from_user = ? OR m.to_user = ?)",
        [$mid, current_user_id(), current_user_id()]
    );

    if ($openMsg) {
        // Mark as read when recipient opens it
        if ($openMsg['to_user'] == current_user_id() && !$openMsg['is_read']) {
            Database::execute(
                "UPDATE messages SET is_read=1, read_at=NOW() WHERE id=?",
                [$mid]
            );
            $openMsg['is_read'] = 1;
        }

        // Load conversation thread (same subject between same two users)
        $u1 = (int)$openMsg['from_user'];
        $u2 = (int)($openMsg['to_user'] ?? 0);
        $me = current_user_id();

        if ($u2 > 0) {
            // Build base subject (strip Re: prefix for matching)
            $baseSubject = preg_replace('/^(Re:\s*)+/i', '', $openMsg['subject']);

            $thread = Database::fetchAll(
                "SELECT m.*,
                        fu.full_name AS from_name,
                        tu.full_name AS to_name
                 FROM messages m
                 JOIN users fu ON fu.id = m.from_user
                 LEFT JOIN users tu ON tu.id = m.to_user
                 WHERE (
                     (m.from_user=? AND m.to_user=?)
                  OR (m.from_user=? AND m.to_user=?)
                 )
                 AND (m.subject = ? OR m.subject LIKE ?)
                 AND (m.from_user=? OR m.to_user=?)
                 ORDER BY m.created_at ASC",
                [$u1,$u2, $u2,$u1,
                 $openMsg['subject'], '%'.$baseSubject,
                 $me, $me]
            );
        }

        // If thread is empty, just show this message
        if (empty($thread)) {
            $thread = [$openMsg];
        }
    }
}

// ── Recipients list ───────────────────────────────────────────
// ALL roles can compose to any active user
$recipients = Database::fetchAll(
    "SELECT u.id, u.full_name, u.username, r.name AS role_name
     FROM users u JOIN roles r ON r.id=u.role_id
     WHERE u.status='active' AND u.id != ?
     ORDER BY r.id, u.full_name",
    [current_user_id()]
);

$currentMessages = $box === 'sent' ? $sent : $inbox;
$currentPager    = $box === 'sent' ? $sentPager : $inboxPager;

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">💬 Messages</h1>
        <p class="page-subtitle">
            <?php if ($unreadCount > 0): ?>
                <span style="color:var(--red);font-weight:700"><?= $unreadCount ?> unread</span>
                &nbsp;|&nbsp;
            <?php endif; ?>
            Read messages auto-delete after 24 hours
        </p>
    </div>
    <div class="page-header-actions">
        <?php if ($unreadCount > 0): ?>
            <form method="POST" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn btn-outline btn-sm">✅ Mark All Read</button>
            </form>
        <?php endif; ?>
        <!-- Compose: available to ALL roles -->
        <button class="btn btn-primary" id="composeBtn" onclick="openModal('composeModal')">
            ✉️ Compose Message
        </button>
    </div>
</div>

<div style="display:grid;grid-template-columns:260px 1fr;gap:20px;align-items:start">

    <!-- ── Left panel: Navigation ── -->
    <div style="display:flex;flex-direction:column;gap:12px">

        <!-- Folder navigation -->
        <div class="card">
            <div style="padding:6px 0">
                <a href="<?= $msgUrl ?>?box=inbox"
                   style="display:flex;align-items:center;gap:10px;padding:12px 16px;
                          text-decoration:none;color:var(--text);
                          background:<?= $box==='inbox'?'rgba(11,29,58,.06)':'' ?>;
                          border-left:3px solid <?= $box==='inbox'?'var(--navy)':'transparent' ?>;
                          font-weight:<?= $box==='inbox'?700:500 ?>">
                    <span style="font-size:18px">📥</span>
                    <span>Inbox</span>
                    <?php if ($unreadCount > 0): ?>
                        <span class="badge badge-danger" style="margin-left:auto"><?= $unreadCount ?></span>
                    <?php else: ?>
                        <span class="badge badge-secondary" style="margin-left:auto"><?= $inboxPager['total'] ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?= $msgUrl ?>?box=sent"
                   style="display:flex;align-items:center;gap:10px;padding:12px 16px;
                          text-decoration:none;color:var(--text);
                          background:<?= $box==='sent'?'rgba(11,29,58,.06)':'' ?>;
                          border-left:3px solid <?= $box==='sent'?'var(--navy)':'transparent' ?>;
                          font-weight:<?= $box==='sent'?700:500 ?>">
                    <span style="font-size:18px">📤</span>
                    <span>Sent</span>
                    <span class="badge badge-secondary" style="margin-left:auto"><?= $sentPager['total'] ?></span>
                </a>
            </div>
        </div>

        <!-- Quick compose button (large, visible) -->
        <button onclick="openModal('composeModal')"
                style="width:100%;padding:14px;background:var(--navy);color:var(--white);
                       border:none;border-radius:10px;font-size:14px;font-weight:700;
                       cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px">
            ✏️ New Message
        </button>

        <!-- Admin broadcast -->
        <?php if (is_admin()): ?>
        <div class="card">
            <div style="padding:14px 16px">
                <div style="font-weight:700;font-size:13px;margin-bottom:6px">📢 Broadcast</div>
                <div class="text-xs text-muted" style="margin-bottom:10px">
                    Send one message to ALL active users at once.
                </div>
                <button onclick="openModal('broadcastModal')"
                        class="btn btn-accent btn-sm btn-block">
                    📢 Send Broadcast
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Cleanup -->
        <?php if ($inboxPager['total'] > 0): ?>
        <div class="card">
            <div style="padding:14px 16px">
                <div class="text-xs text-muted" style="margin-bottom:8px">
                    📌 Read messages auto-delete after 24h.<br>
                    Manually clear read messages now:
                </div>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_read">
                    <button type="submit" class="btn btn-outline btn-sm btn-block"
                            data-confirm="Delete all read messages from your inbox?">
                        🗑️ Clear Read Messages
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Right panel: Message list + Reader ── -->
    <div>
        <?php if ($openMsg && !empty($thread)): ?>
        <!-- ══ THREAD / MESSAGE READER ══ -->
        <div class="card mb-16">
            <div class="card-header" style="background:var(--navy);color:#fff">
                <div>
                    <div style="font-size:16px;font-weight:800"><?= e($openMsg['subject']) ?></div>
                    <div style="font-size:12px;opacity:.7;margin-top:2px">
                        <?= count($thread) ?> message(s) in conversation
                    </div>
                </div>
                <a href="<?= $msgUrl ?>?box=<?= $box ?>"
                   style="color:rgba(255,255,255,.8);text-decoration:none;font-size:20px">×</a>
            </div>

            <!-- Thread messages -->
            <?php foreach ($thread as $tm):
                $isMe = ($tm['from_user'] == current_user_id());
            ?>
            <div style="padding:16px 20px;border-bottom:1px solid var(--border);
                        background:<?= $isMe?'#F0F7FF':'#FFFFFF' ?>">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
                    <div style="display:flex;align-items:center;gap:10px">
                        <div style="width:34px;height:34px;border-radius:50%;
                                    background:<?= $isMe?'var(--blue)':'var(--navy)' ?>;
                                    color:#fff;display:flex;align-items:center;justify-content:center;
                                    font-size:14px;font-weight:800;flex-shrink:0">
                            <?= strtoupper(substr($tm['from_name'],0,1)) ?>
                        </div>
                        <div>
                            <div style="font-weight:700;font-size:13px">
                                <?= e($tm['from_name']) ?>
                                <?= $isMe ? '<span style="color:var(--blue);font-size:11px">(You)</span>' : '' ?>
                            </div>
                            <div class="text-xs text-muted"><?= format_date($tm['created_at'],'d M Y H:i') ?></div>
                        </div>
                    </div>
                    <form method="POST" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action"  value="delete">
                        <input type="hidden" name="msg_id"  value="<?= $tm['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger"
                                style="opacity:.5" title="Delete this message"
                                data-confirm="Delete this message?">🗑️</button>
                    </form>
                </div>
                <div style="margin-left:44px;background:<?= $isMe?'rgba(59,130,246,.08)':'var(--light)' ?>;
                            border-radius:10px;padding:12px 14px;font-size:14px;
                            line-height:1.7;color:var(--text);white-space:pre-line">
                    <?= e($tm['body']) ?>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Reply box -->
            <?php
            // Find the other person in this conversation
            $replyToUser = ($openMsg['from_user'] == current_user_id())
                ? $openMsg['to_user']
                : $openMsg['from_user'];
            $replyToName = ($openMsg['from_user'] == current_user_id())
                ? ($openMsg['to_name'] ?? 'Unknown')
                : $openMsg['from_name'];
            $replySubject = preg_match('/^Re:/i', $openMsg['subject'])
                ? $openMsg['subject']
                : 'Re: ' . $openMsg['subject'];
            ?>
            <?php if ($replyToUser && $replyToUser != current_user_id()): ?>
            <div style="padding:16px 20px;background:var(--light)">
                <div style="font-weight:700;font-size:13px;margin-bottom:10px;color:var(--text)">
                    ↩️ Reply to <?= e($replyToName) ?>
                </div>
                <form method="POST" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action"  value="send">
                    <input type="hidden" name="to_user" value="<?= $replyToUser ?>">
                    <input type="hidden" name="subject" value="<?= e($replySubject) ?>">
                    <div style="display:flex;gap:10px;align-items:flex-end">
                        <textarea name="body" class="form-control"
                                  style="flex:1;min-height:70px;resize:vertical"
                                  placeholder="Type your reply…" required></textarea>
                        <button type="submit" class="btn btn-primary" style="flex-shrink:0">
                            Send ↩️
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <!-- Back to list button -->
        <a href="<?= $msgUrl ?>?box=<?= $box ?>" class="btn btn-outline btn-sm">
            ← Back to <?= $box === 'sent' ? 'Sent' : 'Inbox' ?>
        </a>

        <?php else: ?>
        <!-- ══ MESSAGE LIST ══ -->
        <div class="card">
            <div class="card-header">
                <?= $box === 'sent' ? '📤 Sent Messages' : '📥 Inbox' ?>
                <div style="display:flex;gap:8px;align-items:center">
                    <?php if ($box === 'inbox' && $unreadCount > 0): ?>
                        <span class="badge badge-danger"><?= $unreadCount ?> unread</span>
                    <?php endif; ?>
                    <span class="badge badge-secondary"><?= $currentPager['total'] ?> total</span>
                </div>
            </div>

            <?php if ($currentMessages): ?>
            <?php foreach ($currentMessages as $msg):
                $isUnread  = !$msg['is_read'] && $box === 'inbox';
                $isOpen    = $mid == $msg['id'];
                $otherName = $box === 'sent'
                    ? ($msg['to_name'] ?? 'All Users (Broadcast)')
                    : ($msg['from_name'] ?? 'Unknown');
            ?>
            <a href="<?= $msgUrl ?>?box=<?= $box ?>&msg=<?= $msg['id'] ?>"
               style="display:block;padding:14px 18px;text-decoration:none;
                      border-bottom:1px solid var(--border);
                      background:<?= $isUnread ? 'rgba(244,185,66,.06)' : ($isOpen ? 'var(--light)' : '#fff') ?>;
                      border-left:4px solid <?= $isUnread ? 'var(--accent)' : ($isOpen ? 'var(--navy)' : 'transparent') ?>;
                      transition:background .15s">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                    <div style="display:flex;align-items:center;gap:8px">
                        <!-- Unread dot -->
                        <?php if ($isUnread): ?>
                            <span style="width:9px;height:9px;border-radius:50%;
                                         background:var(--accent);flex-shrink:0"></span>
                        <?php endif; ?>
                        <span style="font-weight:<?= $isUnread ? 800 : 600 ?>;font-size:14px;color:var(--text)">
                            <?= e($otherName) ?>
                            <?php if ($msg['is_broadcast']): ?>
                                <span class="badge badge-purple" style="margin-left:4px;font-size:9px">Broadcast</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
                        <?php if ($isUnread): ?>
                            <span class="badge badge-warning" style="font-size:10px">NEW</span>
                        <?php elseif ($box==='inbox' && $msg['is_read'] && $msg['read_at']): ?>
                            <span class="text-xs text-muted">Read <?= format_date($msg['read_at'],'d M H:i') ?></span>
                        <?php endif; ?>
                        <span class="text-xs text-muted"><?= format_date($msg['created_at'],'d M H:i') ?></span>
                    </div>
                </div>
                <div style="font-size:13px;font-weight:<?= $isUnread?700:600 ?>;
                             color:<?= $isUnread?'var(--text)':'var(--text-muted)' ?>;margin-bottom:2px">
                    <?= e($msg['subject']) ?>
                </div>
                <div class="text-xs text-muted">
                    <?= e(mb_substr(strip_tags($msg['body']), 0, 80)) ?>…
                </div>
            </a>
            <?php endforeach; ?>

            <?= pagination_links($currentPager, $msgUrl . '?box=' . $box) ?>

            <?php else: ?>
            <div class="table-empty" style="padding:60px">
                <div style="font-size:48px;margin-bottom:12px">
                    <?= $box === 'sent' ? '📤' : '📥' ?>
                </div>
                <div style="font-weight:700;font-size:16px;margin-bottom:6px">
                    <?= $box === 'sent' ? 'No sent messages' : 'Your inbox is empty' ?>
                </div>
                <div class="text-sm text-muted" style="margin-bottom:16px">
                    <?= $box === 'inbox'
                        ? 'Messages sent to you will appear here.'
                        : 'Messages you send will appear here.' ?>
                </div>
                <button onclick="openModal('composeModal')" class="btn btn-primary">
                    ✉️ Compose Message
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════
     COMPOSE MODAL — works for ALL roles
══════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="composeModal" style="display:none">
    <div class="modal modal-lg">
        <div class="modal-header">
            <span class="modal-title">✉️ New Message</span>
            <button class="modal-close" data-modal-close>×</button>
        </div>
        <form method="POST" action="<?= $msgUrl ?>" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">To <span class="req">*</span></label>
                    <select name="to_user" class="form-control" required>
                        <option value="">— Select recipient —</option>
                        <?php
                        $grouped = [];
                        foreach ($recipients as $r) {
                            $grouped[$r['role_name']][] = $r;
                        }
                        $roleLabels = [
                            'admin'     => '👑 Administrators',
                            'teacher'   => '👨‍🏫 Teachers',
                            'student'   => '👨‍🎓 Students',
                            'secretary' => '📋 Secretaries',
                        ];
                        foreach ($grouped as $role => $users):
                        ?>
                            <optgroup label="<?= e($roleLabels[$role] ?? ucfirst($role)) ?>">
                                <?php foreach ($users as $r): ?>
                                    <option value="<?= $r['id'] ?>">
                                        <?= e($r['full_name']) ?>
                                        (<?= e($r['username']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($recipients)): ?>
                        <div class="form-hint" style="color:var(--red)">
                            No other active users found in the system.
                        </div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label">Subject <span class="req">*</span></label>
                    <input type="text" name="subject" class="form-control" required
                           placeholder="e.g. Question about homework">
                </div>
                <div class="form-group">
                    <label class="form-label">Message <span class="req">*</span></label>
                    <textarea name="body" class="form-control" rows="6" required
                              placeholder="Write your message here…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary" <?= empty($recipients)?'disabled':'' ?>>
                    📤 Send Message
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════
     BROADCAST MODAL — Admin only
══════════════════════════════════════════════════ -->
<?php if (is_admin()): ?>
<div class="modal-backdrop" id="broadcastModal" style="display:none">
    <div class="modal modal-lg">
        <div class="modal-header">
            <span class="modal-title">📢 Broadcast to All Users</span>
            <button class="modal-close" data-modal-close>×</button>
        </div>
        <form method="POST" action="<?= $msgUrl ?>" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="action"    value="send">
            <input type="hidden" name="broadcast" value="1">
            <div class="modal-body">
                <div style="background:#FEF3C7;border-radius:8px;padding:12px 14px;
                            margin-bottom:16px;border:1px solid #FDE68A;font-size:13px">
                    ⚠️ <strong>Broadcast Warning:</strong>
                    This will send the message to ALL <?= count($recipients) ?> active users.
                </div>
                <div class="form-group">
                    <label class="form-label">Subject <span class="req">*</span></label>
                    <input type="text" name="subject" class="form-control" required
                           placeholder="Broadcast subject…">
                </div>
                <div class="form-group">
                    <label class="form-label">Message <span class="req">*</span></label>
                    <textarea name="body" class="form-control" rows="6" required
                              placeholder="Write your broadcast message…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-accent">📢 Send to All Users</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
