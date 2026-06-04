<?php
// ============================================================
// admin/documents.php — Document / Study Materials Management
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_login();

$pageTitle  = 'Documents';
$activeMenu = 'documents';

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'upload') {
        $title      = sanitize($_POST['title']      ?? '');
        $desc       = sanitize($_POST['description']?? '');
        $subject_id = int_val($_POST['subject_id']  ?? 0);
        $class_id   = int_val($_POST['class_id']    ?? 0);

        if (empty($title)) {
            flash_error('Document title is required.');
        } elseif (empty($_FILES['doc_file']['name'])) {
            flash_error('Please select a file to upload.');
        } else {
            $allowed = array_merge(ALLOWED_DOC_TYPES, ['image/jpeg','image/png','application/pdf','text/plain']);
            $upload  = handle_upload($_FILES['doc_file'], 'documents', $allowed);
            if ($upload['success']) {
                Database::insert(
                    "INSERT INTO documents
                        (title, description, file_path, file_type, file_size,
                         subject_id, class_id, uploaded_by)
                     VALUES (?,?,?,?,?,?,?,?)",
                    [
                        $title, $desc ?: null,
                        $upload['filename'],
                        $_FILES['doc_file']['type'],
                        $_FILES['doc_file']['size'],
                        $subject_id ?: null,
                        $class_id   ?: null,
                        current_user_id(),
                    ]
                );
                audit_log(current_user_id(), current_username(), 'upload_document', 'Documents',
                    "Uploaded: {$title}");
                flash_success("Document <strong>{$title}</strong> uploaded successfully.");
            } else {
                flash_error('Upload failed: ' . $upload['message']);
            }
        }
    }

    if ($action === 'delete' && (is_admin() || is_teacher())) {
        $did = int_val($_POST['doc_id'] ?? 0);
        if ($did) {
            $doc = Database::fetchOne("SELECT * FROM documents WHERE id=?", [$did]);
            if ($doc) {
                // Only admin or the uploader can delete
                if (is_admin() || $doc['uploaded_by'] == current_user_id()) {
                    $fpath = UPLOADS_PATH . '/documents/' . $doc['file_path'];
                    if (file_exists($fpath)) @unlink($fpath);
                    Database::execute("DELETE FROM documents WHERE id=?", [$did]);
                    audit_log(current_user_id(), current_username(), 'delete_document', 'Documents',
                        "Deleted: {$doc['title']}");
                    flash_success('Document deleted.');
                } else {
                    flash_error('You can only delete your own uploads.');
                }
            }
        }
    }

    redirect(BASE_URL . '/admin/documents.php?' . http_build_query($_GET));
}

// ── Filters ───────────────────────────────────────────────────
$filterClass   = int_val($_GET['class_id']   ?? 0);
$filterSubject = int_val($_GET['subject_id'] ?? 0);
$search        = sanitize($_GET['q']         ?? '');
$page          = int_val($_GET['page']       ?? 1);

$where  = ['1=1'];
$params = [];
if ($filterClass)   { $where[] = 'd.class_id=?';   $params[] = $filterClass; }
if ($filterSubject) { $where[] = 'd.subject_id=?';  $params[] = $filterSubject; }
if ($search) {
    $where[]  = '(d.title LIKE ? OR d.description LIKE ?)';
    $like     = "%{$search}%";
    $params   = array_merge($params, [$like, $like]);
}
$whereStr = 'WHERE ' . implode(' AND ', $where);

$baseSql = "SELECT d.*, u.full_name AS uploader,
                   sub.name AS subject_name, c.name AS class_name
            FROM documents d
            LEFT JOIN users    u   ON u.id   = d.uploaded_by
            LEFT JOIN subjects sub ON sub.id = d.subject_id
            LEFT JOIN classes  c   ON c.id   = d.class_id
            {$whereStr}
            ORDER BY d.created_at DESC";

$pager = paginate($baseSql, $params, $page);
$docs  = $pager['rows'];

$classes  = Database::fetchAll("SELECT id,name FROM classes ORDER BY sort_order");
$subjects = Database::fetchAll("SELECT id,name FROM subjects ORDER BY name");

function fileSizeHuman(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes/1048576,1) . ' MB';
    if ($bytes >= 1024)    return round($bytes/1024)     . ' KB';
    return $bytes . ' B';
}

function fileIcon(string $type): string {
    if (str_contains($type,'pdf'))   return '📄';
    if (str_contains($type,'word') || str_contains($type,'document')) return '📝';
    if (str_contains($type,'excel') || str_contains($type,'sheet'))   return '📊';
    if (str_contains($type,'image')) return '🖼️';
    if (str_contains($type,'text'))  return '📃';
    return '📎';
}

$canUpload = is_admin() || is_teacher();

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📂 Documents & Study Materials</h1>
        <p class="page-subtitle"><?= $pager['total'] ?> document(s) available</p>
    </div>
    <div class="page-header-actions">
        <?php if ($canUpload): ?>
            <button class="btn btn-primary" onclick="openModal('uploadModal')">📤 Upload Document</button>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="card mb-20">
    <div class="table-toolbar">
        <div class="search-bar-wrap">
            <span class="search-icon">🔍</span>
            <input type="text" class="search-input" value="<?= e($search) ?>"
                   placeholder="Search title…"
                   onkeyup="if(event.key==='Enter'||this.value===''){
                       document.getElementById('filterForm').submit()}">
        </div>
        <form method="GET" id="filterForm" style="display:contents">
            <input type="hidden" name="q" value="<?= e($search) ?>">
            <select name="class_id" class="filter-select" onchange="this.form.submit()">
                <option value="">All Classes</option>
                <?php foreach ($classes as $cls): ?>
                    <option value="<?= $cls['id'] ?>" <?= $filterClass==$cls['id']?'selected':'' ?>>
                        <?= e($cls['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="subject_id" class="filter-select" onchange="this.form.submit()">
                <option value="">All Subjects</option>
                <?php foreach ($subjects as $sub): ?>
                    <option value="<?= $sub['id'] ?>" <?= $filterSubject==$sub['id']?'selected':'' ?>>
                        <?= e($sub['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="table-toolbar-right">
                <a href="<?= BASE_URL ?>/admin/documents.php" class="btn btn-outline btn-sm">↺ Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Document grid -->
<?php if ($docs): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:24px">
<?php foreach ($docs as $doc): ?>
    <div class="card">
        <div style="padding:16px">
            <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:10px">
                <div style="font-size:36px;flex-shrink:0"><?= fileIcon($doc['file_type']??'') ?></div>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:800;font-size:14px;margin-bottom:4px;
                                overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                         title="<?= e($doc['title']) ?>">
                        <?= e($doc['title']) ?>
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <?php if ($doc['class_name']): ?>
                            <span class="badge badge-navy"><?= e($doc['class_name']) ?></span>
                        <?php endif; ?>
                        <?php if ($doc['subject_name']): ?>
                            <span class="badge badge-primary"><?= e($doc['subject_name']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($doc['description']): ?>
                <p class="text-sm text-muted" style="margin-bottom:10px;line-height:1.5">
                    <?= e(substr($doc['description'],0,80)) ?><?= strlen($doc['description'])>80?'…':'' ?>
                </p>
            <?php endif; ?>

            <div class="text-xs text-muted" style="margin-bottom:12px">
                👤 <?= e($doc['uploader'] ?? '—') ?> &bull;
                <?= fileSizeHuman((int)($doc['file_size']??0)) ?> &bull;
                <?= format_date($doc['created_at'], 'd M Y') ?>
            </div>

            <div style="display:flex;gap:8px">
                <a href="<?= UPLOADS_URL ?>/documents/<?= e($doc['file_path']) ?>"
                   target="_blank" class="btn btn-sm btn-primary">⬇️ Download</a>
                <?php if ($canUpload && (is_admin() || $doc['uploaded_by']==current_user_id())): ?>
                    <form method="POST" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger"
                                data-confirm="Delete '<?= e($doc['title']) ?>'?">🗑️</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?= pagination_links($pager, BASE_URL . '/admin/documents.php?class_id=' . $filterClass . '&subject_id=' . $filterSubject . '&q=' . urlencode($search)) ?>

<?php else: ?>
<div class="card">
    <div class="card-body table-empty">
        <div class="table-empty-icon">📂</div>
        No documents found.
        <?php if ($canUpload): ?>
            <br>
            <button onclick="openModal('uploadModal')" class="btn btn-primary btn-sm" style="margin-top:10px">
                📤 Upload First Document
            </button>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Upload Modal -->
<?php if ($canUpload): ?>
<div class="modal-backdrop" id="uploadModal" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">📤 Upload Document</span>
            <button class="modal-close" data-modal-close>×</button>
        </div>
        <form method="POST" action="" enctype="multipart/form-data" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Title <span class="req">*</span></label>
                    <input type="text" name="title" class="form-control" required
                           placeholder="e.g. Chapter 5 Notes — Algebra">
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Subject</label>
                        <select name="subject_id" class="form-control">
                            <option value="">All Subjects</option>
                            <?php foreach ($subjects as $sub): ?>
                                <option value="<?= $sub['id'] ?>"><?= e($sub['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Class</label>
                        <select name="class_id" class="form-control">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $cls): ?>
                                <option value="<?= $cls['id'] ?>"><?= e($cls['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2"
                              placeholder="Brief description of the document…"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">File <span class="req">*</span></label>
                    <input type="file" name="doc_file" class="form-control" required
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.png">
                    <div class="form-hint">PDF, Word, Excel, PowerPoint, Images — max 5MB</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">📤 Upload</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include INCLUDES_PATH . '/footer.php'; ?>
