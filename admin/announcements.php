<?php
// ============================================================
// admin/announcements.php — Announcement Management
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role([ROLE_ADMIN, ROLE_SECRETARY]);

$pageTitle  = 'Announcements';
$activeMenu = 'announcements';

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'add') {
        $title      = sanitize($_POST['title']      ?? '');
        $body       = sanitize($_POST['body']       ?? '');
        $priority   = sanitize($_POST['priority']   ?? 'normal');
        $target     = sanitize($_POST['target']     ?? 'all');
        $expires_at = sanitize($_POST['expires_at'] ?? '');

        if (empty($title) || empty($body)) {
            flash_error('Title and message are required.');
        } else {
            $annId = Database::insert(
                "INSERT INTO announcements (title, body, priority, target, posted_by, expires_at)
                 VALUES (?,?,?,?,?,?)",
                [
                    $title, $body,
                    in_array($priority,['high','normal','low']) ? $priority : 'normal',
                    in_array($target,['all','students','teachers','parents']) ? $target : 'all',
                    current_user_id(),
                    $expires_at ?: null
                ]
            );

            // Send notifications to relevant users
            $notifRoles = match($target) {
                'students' => [ROLE_STUDENT],
                'teachers' => [ROLE_TEACHER],
                'parents'  => [ROLE_STUDENT], // students represent parents
                default    => [ROLE_ADMIN, ROLE_TEACHER, ROLE_STUDENT, ROLE_SECRETARY],
            };
            $roleStr = implode(',', $notifRoles);
            $users   = Database::fetchAll(
                "SELECT id FROM users WHERE role_id IN ({$roleStr}) AND status='active'"
            );
            foreach ($users as $u) {
                send_notification($u['id'], $title,
                    substr($body, 0, 120) . (strlen($body) > 120 ? '…' : ''),
                    'announcement',
                    BASE_URL . '/admin/announcements.php'
                );
            }

            audit_log(current_user_id(), current_username(), 'create_announcement', 'Announcements',
                "Posted: {$title}");
            flash_success("Announcement <strong>{$title}</strong> posted successfully.");
        }
    }

    elseif ($action === 'edit') {
        $aid      = int_val($_POST['ann_id']     ?? 0);
        $title    = sanitize($_POST['title']     ?? '');
        $body     = sanitize($_POST['body']      ?? '');
        $priority = sanitize($_POST['priority']  ?? 'normal');
        $target   = sanitize($_POST['target']    ?? 'all');
        $expires  = sanitize($_POST['expires_at']?? '');

        if (!$aid || empty($title) || empty($body)) {
            flash_error('All fields are required.');
        } else {
            Database::execute(
                "UPDATE announcements SET title=?,body=?,priority=?,target=?,expires_at=? WHERE id=?",
                [$title, $body, $priority, $target, $expires ?: null, $aid]
            );
            audit_log(current_user_id(), current_username(), 'update_announcement', 'Announcements',
                "Updated announcement ID {$aid}");
            flash_success('Announcement updated.');
        }
    }

    elseif ($action === 'delete') {
        $aid = int_val($_POST['ann_id'] ?? 0);
        if ($aid) {
            $ann = Database::fetchOne("SELECT title FROM announcements WHERE id=?", [$aid]);
            Database::execute("DELETE FROM announcements WHERE id=?", [$aid]);
            audit_log(current_user_id(), current_username(), 'delete_announcement', 'Announcements',
                "Deleted: {$ann['title']}");
            flash_success('Announcement deleted.');
        }
    }

    redirect(BASE_URL . '/admin/announcements.php');
}

// ── Filters ───────────────────────────────────────────────────
$filterPri  = sanitize($_GET['priority'] ?? '');
$filterTgt  = sanitize($_GET['target']   ?? '');
$page       = int_val($_GET['page']      ?? 1);

$where  = ['1=1'];
$params = [];
if ($filterPri) { $where[] = 'a.priority=?'; $params[] = $filterPri; }
if ($filterTgt) { $where[] = 'a.target=?';   $params[] = $filterTgt; }
$whereStr = 'WHERE ' . implode(' AND ', $where);

$baseSql = "SELECT a.*, u.full_name AS author_name
            FROM announcements a JOIN users u ON u.id=a.posted_by
            {$whereStr}
            ORDER BY a.created_at DESC";
$pager  = paginate($baseSql, $params, $page);
$anns   = $pager['rows'];

// Pre-load one for edit if ?edit=id
$editAnn = null;
if (!empty($_GET['edit'])) {
    $editAnn = Database::fetchOne("SELECT * FROM announcements WHERE id=?", [int_val($_GET['edit'])]);
}

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📢 Announcements</h1>
        <p class="page-subtitle"><?= $pager['total'] ?> announcements &nbsp;|&nbsp; School-wide communication board</p>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-primary" onclick="openModal('addAnnModal')">+ Post Announcement</button>
    </div>
</div>

<!-- Filter toolbar -->
<div class="card mb-20">
    <div class="table-toolbar">
        <form method="GET" style="display:contents">
            <select name="priority" class="filter-select" onchange="this.form.submit()">
                <option value="">All Priorities</option>
                <?php foreach (['high','normal','low'] as $p): ?>
                    <option value="<?= $p ?>" <?= $filterPri===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="target" class="filter-select" onchange="this.form.submit()">
                <option value="">All Audiences</option>
                <?php foreach (['all','students','teachers','parents'] as $t): ?>
                    <option value="<?= $t ?>" <?= $filterTgt===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="table-toolbar-right">
                <a href="<?= BASE_URL ?>/admin/announcements.php" class="btn btn-outline btn-sm">↺ Reset</a>
            </div>
        </form>
    </div>

    <!-- Announcements list -->
    <div style="padding:0">
        <?php if ($anns): foreach ($anns as $ann):
            $isExpired = $ann['expires_at'] && strtotime($ann['expires_at']) < time();
        ?>
        <div style="padding:18px 20px;border-bottom:1px solid var(--border);
                    <?= $isExpired?'opacity:.6':'' ?>;
                    border-left:4px solid <?= $ann['priority']==='high'?'var(--red)':($ann['priority']==='normal'?'var(--blue)':'var(--gray)') ?>">

            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
                <div style="flex:1">
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:6px">
                        <?= status_badge($ann['priority']) ?>
                        <span class="badge badge-secondary"><?= ucfirst($ann['target']) ?></span>
                        <?php if ($isExpired): ?>
                            <span class="badge badge-danger">Expired</span>
                        <?php endif; ?>
                        <span class="text-xs text-muted">
                            By <strong><?= e($ann['author_name']) ?></strong>
                            &bull; <?= format_date($ann['created_at'], 'd M Y H:i') ?>
                        </span>
                        <?php if ($ann['expires_at']): ?>
                            <span class="text-xs text-muted">
                                &bull; Expires: <?= format_date($ann['expires_at']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <h3 style="margin:0 0 6px;font-size:16px;font-weight:800;color:var(--text)">
                        <?= e($ann['title']) ?>
                    </h3>
                    <p style="margin:0;color:var(--text-muted);line-height:1.65;font-size:14px">
                        <?= nl2br(e($ann['body'])) ?>
                    </p>
                </div>
                <div style="display:flex;gap:6px;flex-shrink:0">
                    <button class="btn btn-sm btn-outline"
                            onclick='openEditAnn(<?= json_encode($ann) ?>)'>✏️ Edit</button>
                    <form method="POST" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action"  value="delete">
                        <input type="hidden" name="ann_id"  value="<?= $ann['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger"
                                data-confirm="Delete this announcement?">🗑️</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; else: ?>
        <div class="table-empty" style="padding:48px">
            <div class="table-empty-icon">📢</div>
            No announcements yet.
            <br>
            <button onclick="openModal('addAnnModal')" class="btn btn-primary btn-sm" style="margin-top:10px">
                + Post First Announcement
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?= pagination_links($pager, BASE_URL . '/admin/announcements.php?priority=' . $filterPri . '&target=' . $filterTgt) ?>
</div>

<!-- ── Add Announcement Modal ── -->
<div class="modal-backdrop" id="addAnnModal" style="display:none">
    <div class="modal modal-lg">
        <div class="modal-header">
            <span class="modal-title">📢 Post New Announcement</span>
            <button class="modal-close" data-modal-close>×</button>
        </div>
        <form method="POST" action="" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Title <span class="req">*</span></label>
                    <input type="text" name="title" class="form-control" required
                           placeholder="e.g. End of Term Examinations">
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-control">
                            <option value="normal">Normal</option>
                            <option value="high">High (⚠️ Urgent)</option>
                            <option value="low">Low</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Target Audience</label>
                        <select name="target" class="form-control">
                            <option value="all">Everyone</option>
                            <option value="students">Students Only</option>
                            <option value="teachers">Teachers Only</option>
                            <option value="parents">Parents Only</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Message <span class="req">*</span></label>
                    <textarea name="body" class="form-control" rows="5" required
                              placeholder="Write your announcement message here…"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Expiry Date <small class="text-muted">(optional — hides after this date)</small></label>
                    <input type="date" name="expires_at" class="form-control" min="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">📢 Post Announcement</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Edit Announcement Modal ── -->
<div class="modal-backdrop" id="editAnnModal" style="display:none">
    <div class="modal modal-lg">
        <div class="modal-header">
            <span class="modal-title">✏️ Edit Announcement</span>
            <button class="modal-close" data-modal-close>×</button>
        </div>
        <form method="POST" action="" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="ann_id" id="editAnnId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Title <span class="req">*</span></label>
                    <input type="text" name="title" id="editAnnTitle" class="form-control" required>
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select name="priority" id="editAnnPriority" class="form-control">
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                            <option value="low">Low</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Target Audience</label>
                        <select name="target" id="editAnnTarget" class="form-control">
                            <option value="all">Everyone</option>
                            <option value="students">Students Only</option>
                            <option value="teachers">Teachers Only</option>
                            <option value="parents">Parents Only</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Message <span class="req">*</span></label>
                    <textarea name="body" id="editAnnBody" class="form-control" rows="5" required></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Expiry Date</label>
                    <input type="date" name="expires_at" id="editAnnExpiry" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditAnn(ann) {
    document.getElementById('editAnnId').value      = ann.id;
    document.getElementById('editAnnTitle').value   = ann.title;
    document.getElementById('editAnnBody').value    = ann.body;
    document.getElementById('editAnnPriority').value= ann.priority;
    document.getElementById('editAnnTarget').value  = ann.target;
    document.getElementById('editAnnExpiry').value  = ann.expires_at || '';
    openModal('editAnnModal');
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
