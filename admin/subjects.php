<?php
// ============================================================
// admin/subjects.php — Subject Management
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_ADMIN);

$pageTitle  = 'Subject Management';
$activeMenu = 'subjects';

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'add') {
        $name = sanitize($_POST['name'] ?? '');
        $code = strtoupper(sanitize($_POST['code'] ?? ''));

        if (empty($name)) {
            flash_error('Subject name is required.');
        } elseif (Database::fetchOne("SELECT id FROM subjects WHERE name=?",[$name])) {
            flash_error("Subject \"{$name}\" already exists.");
        } else {
            $subId = Database::insert(
                "INSERT INTO subjects (name, code) VALUES (?,?)",
                [$name, $code ?: null]
            );
            // Assign to selected classes
            $classIds = array_map('intval', $_POST['class_ids'] ?? []);
            foreach ($classIds as $cid) {
                if ($cid > 0) {
                    Database::execute(
                        "INSERT IGNORE INTO class_subjects (class_id, subject_id) VALUES (?,?)",
                        [$cid, $subId]
                    );
                }
            }
            audit_log(current_user_id(), current_username(), 'create_subject', 'Subjects',
                "Created subject: {$name}");
            flash_success("Subject <strong>{$name}</strong> created.");
        }
    }

    elseif ($action === 'edit') {
        $sid  = int_val($_POST['subject_id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $code = strtoupper(sanitize($_POST['code'] ?? ''));

        if (empty($name))  { flash_error('Subject name is required.'); }
        elseif (!$sid)     { flash_error('Invalid subject.'); }
        elseif (Database::fetchOne("SELECT id FROM subjects WHERE name=? AND id!=?",[$name,$sid])) {
            flash_error("Subject \"{$name}\" already exists.");
        } else {
            Database::execute(
                "UPDATE subjects SET name=?, code=? WHERE id=?",
                [$name, $code ?: null, $sid]
            );
            // Rebuild class assignments
            Database::execute("DELETE FROM class_subjects WHERE subject_id=?",[$sid]);
            $classIds = array_map('intval', $_POST['class_ids'] ?? []);
            foreach ($classIds as $cid) {
                if ($cid > 0) {
                    Database::execute(
                        "INSERT IGNORE INTO class_subjects (class_id, subject_id) VALUES (?,?)",
                        [$cid, $sid]
                    );
                }
            }
            audit_log(current_user_id(), current_username(), 'update_subject', 'Subjects',
                "Updated subject ID {$sid}: {$name}");
            flash_success("Subject <strong>{$name}</strong> updated.");
        }
    }

    elseif ($action === 'delete') {
        $sid = int_val($_POST['subject_id'] ?? 0);
        if ($sid) {
            // Check if results exist
            $resultCount = (int)Database::fetchOne(
                "SELECT COUNT(*) AS c FROM results WHERE subject_id=?",[$sid]
            )['c'];
            if ($resultCount > 0) {
                flash_error("Cannot delete: {$resultCount} result record(s) are linked to this subject.");
            } else {
                $sub = Database::fetchOne("SELECT name FROM subjects WHERE id=?",[$sid]);
                Database::execute("DELETE FROM subjects WHERE id=?",[$sid]);
                audit_log(current_user_id(), current_username(), 'delete_subject', 'Subjects',
                    "Deleted subject: {$sub['name']}");
                flash_success("Subject <strong>{$sub['name']}</strong> deleted.");
            }
        }
    }

    redirect(BASE_URL . '/admin/subjects.php');
}

// ── Fetch subjects with class and teacher info ────────────────
$subjects = Database::fetchAll(
    "SELECT s.*,
            GROUP_CONCAT(DISTINCT c.name ORDER BY c.sort_order SEPARATOR ', ') AS classes,
            GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') AS teachers,
            COUNT(DISTINCT r.id) AS result_count
     FROM subjects s
     LEFT JOIN class_subjects  cs ON cs.subject_id = s.id
     LEFT JOIN classes         c  ON c.id  = cs.class_id
     LEFT JOIN teacher_subjects ts ON ts.subject_id = s.id
     LEFT JOIN teachers        t  ON t.id  = ts.teacher_id
     LEFT JOIN users           u  ON u.id  = t.user_id
     LEFT JOIN results         r  ON r.subject_id = s.id
     GROUP BY s.id
     ORDER BY s.name"
);

$classes = Database::fetchAll("SELECT id, name FROM classes ORDER BY sort_order");

// Build class map for each subject (for edit modal)
$subjectClassMap = [];
foreach (Database::fetchAll("SELECT subject_id, class_id FROM class_subjects") as $row) {
    $subjectClassMap[$row['subject_id']][] = $row['class_id'];
}

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">📘 Subject Management</h1>
        <p class="page-subtitle"><?= count($subjects) ?> subjects configured</p>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-primary" onclick="openModal('addSubjectModal')">+ Add Subject</button>
    </div>
</div>

<!-- Subjects table -->
<div class="card">
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>#</th>
                <th data-sort>Code</th>
                <th data-sort>Subject Name</th>
                <th>Assigned Classes</th>
                <th>Teachers</th>
                <th>Results</th>
                <th>Actions</th>
            </tr></thead>
            <tbody>
            <?php if ($subjects): $i=1; foreach ($subjects as $sub): ?>
                <tr>
                    <td class="text-muted text-sm"><?= $i++ ?></td>
                    <td>
                        <?php if ($sub['code']): ?>
                            <span class="code"><?= e($sub['code']) ?></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><strong style="font-size:14px"><?= e($sub['name']) ?></strong></td>
                    <td style="max-width:200px">
                        <?php if ($sub['classes']): ?>
                            <?php foreach (explode(', ', $sub['classes']) as $cls): ?>
                                <span class="badge badge-navy" style="margin:1px"><?= e(trim($cls)) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="text-muted text-xs">Not assigned to any class</span>
                        <?php endif; ?>
                    </td>
                    <td style="max-width:200px">
                        <div class="text-xs" style="line-height:1.8">
                            <?= e($sub['teachers'] ?: '—') ?>
                        </div>
                    </td>
                    <td>
                        <span class="badge <?= $sub['result_count']>0?'badge-success':'badge-secondary' ?>">
                            <?= $sub['result_count'] ?> results
                        </span>
                    </td>
                    <td>
                        <div class="td-actions">
                            <button class="btn btn-sm btn-primary"
                                    onclick="openEditSubjectModal(
                                        <?= $sub['id'] ?>,
                                        '<?= e(addslashes($sub['name'])) ?>',
                                        '<?= e(addslashes($sub['code'] ?? '')) ?>',
                                        <?= json_encode($subjectClassMap[$sub['id']] ?? []) ?>
                                    )">✏️ Edit</button>
                            <form method="POST" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="subject_id" value="<?= $sub['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                        data-confirm="Delete subject <?= e($sub['name']) ?>? This cannot be undone if results exist.">
                                    🗑️
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="7" class="table-empty">
                    <div class="table-empty-icon">📘</div>
                    No subjects found.
                    <br><button onclick="openModal('addSubjectModal')" class="btn btn-primary btn-sm" style="margin-top:10px">+ Add First Subject</button>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Subject-Class matrix -->
<div class="card mt-24">
    <div class="card-header">📊 Subject-Class Assignment Matrix</div>
    <div class="table-wrap">
        <table class="data-table" style="font-size:12px">
            <thead>
                <tr>
                    <th>Subject</th>
                    <?php foreach ($classes as $cls): ?>
                        <th style="text-align:center"><?= e($cls['name']) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($subjects as $sub): ?>
                <tr>
                    <td><strong><?= e($sub['name']) ?></strong></td>
                    <?php foreach ($classes as $cls): ?>
                        <td style="text-align:center">
                            <?php $assigned = in_array($cls['id'], $subjectClassMap[$sub['id']] ?? []); ?>
                            <?= $assigned ? '<span style="color:var(--emerald);font-size:16px">✓</span>' : '<span style="color:var(--border)">—</span>' ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Add Subject Modal ── -->
<div class="modal-backdrop" id="addSubjectModal" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">+ Add New Subject</span>
            <button class="modal-close" data-modal-close>×</button>
        </div>
        <form method="POST" action="" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-grid-2">
                    <div class="form-group" style="grid-column:1/-1">
                        <label class="form-label">Subject Name <span class="req">*</span></label>
                        <input type="text" name="name" class="form-control" required
                               placeholder="e.g. Mathematics">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Subject Code</label>
                        <input type="text" name="code" class="form-control"
                               placeholder="e.g. MTH" maxlength="10"
                               style="text-transform:uppercase">
                        <div class="form-hint">Short code used in report cards.</div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Assign to Classes</label>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px">
                        <?php foreach ($classes as $cls): ?>
                        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;
                                      background:var(--light);border-radius:6px;padding:5px 12px;
                                      border:1px solid var(--border);font-size:13px">
                            <input type="checkbox" name="class_ids[]" value="<?= $cls['id'] ?>">
                            <?= e($cls['name']) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Add Subject</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Edit Subject Modal ── -->
<div class="modal-backdrop" id="editSubjectModal" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">✏️ Edit Subject</span>
            <button class="modal-close" data-modal-close>×</button>
        </div>
        <form method="POST" action="" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="subject_id" id="editSubjectId">
            <div class="modal-body">
                <div class="form-grid-2">
                    <div class="form-group" style="grid-column:1/-1">
                        <label class="form-label">Subject Name <span class="req">*</span></label>
                        <input type="text" name="name" id="editSubjectName" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Subject Code</label>
                        <input type="text" name="code" id="editSubjectCode" class="form-control"
                               maxlength="10" style="text-transform:uppercase">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Assigned Classes</label>
                    <div id="editClassCheckboxes" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px">
                        <?php foreach ($classes as $cls): ?>
                        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;
                                      background:var(--light);border-radius:6px;padding:5px 12px;
                                      border:1px solid var(--border);font-size:13px"
                               id="editClassLabel_<?= $cls['id'] ?>">
                            <input type="checkbox" name="class_ids[]"
                                   value="<?= $cls['id'] ?>"
                                   id="editClass_<?= $cls['id'] ?>">
                            <?= e($cls['name']) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
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
function openEditSubjectModal(id, name, code, classIds) {
    document.getElementById('editSubjectId').value   = id;
    document.getElementById('editSubjectName').value = name;
    document.getElementById('editSubjectCode').value = code;

    // Reset all class checkboxes
    document.querySelectorAll('#editClassCheckboxes input[type=checkbox]').forEach(cb => {
        cb.checked = classIds.includes(parseInt(cb.value));
    });

    openModal('editSubjectModal');
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
