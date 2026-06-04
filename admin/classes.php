<?php
// ============================================================
// admin/classes.php — Class Management (Dynamic)
// Admin can add, rename, reorder, or delete classes
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_ADMIN);

$pageTitle  = 'Class Management';
$activeMenu = 'classes';

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
    $action = sanitize($_POST['action'] ?? '');

    // ADD
    if ($action === 'add') {
        $name     = sanitize($_POST['name']     ?? '');
        $capacity = int_val($_POST['capacity']  ?? 40);
        $desc     = sanitize($_POST['description'] ?? '');

        if (empty($name)) { flash_error('Class name is required.'); }
        elseif (Database::fetchOne("SELECT id FROM classes WHERE name=?",[$name])) {
            flash_error("Class \"{$name}\" already exists.");
        } else {
            $maxOrder = Database::fetchOne("SELECT MAX(sort_order) AS m FROM classes")['m'] ?? 0;
            Database::insert(
                "INSERT INTO classes (name, capacity, description, sort_order) VALUES (?,?,?,?)",
                [$name, $capacity, $desc, $maxOrder + 1]
            );
            audit_log(current_user_id(), current_username(), 'create_class', 'Classes', "Created class: {$name}");
            flash_success("Class <strong>{$name}</strong> created successfully.");
        }
    }

    // RENAME
    elseif ($action === 'rename') {
        $cid     = int_val($_POST['class_id'] ?? 0);
        $newName = sanitize($_POST['new_name'] ?? '');
        if (empty($newName))  { flash_error('New class name is required.'); }
        elseif (!$cid)        { flash_error('Invalid class.'); }
        elseif (Database::fetchOne("SELECT id FROM classes WHERE name=? AND id!=?",[$newName,$cid])) {
            flash_error("Class \"{$newName}\" already exists.");
        } else {
            $old = Database::fetchOne("SELECT name FROM classes WHERE id=?",[$cid]);
            Database::execute("UPDATE classes SET name=? WHERE id=?",[$newName,$cid]);
            audit_log(current_user_id(), current_username(), 'rename_class', 'Classes',
                "Renamed class from {$old['name']} to {$newName}");
            flash_success("Class renamed to <strong>{$newName}</strong>.");
        }
    }

    // UPDATE CAPACITY
    elseif ($action === 'update_capacity') {
        $cid      = int_val($_POST['class_id'] ?? 0);
        $capacity = int_val($_POST['capacity']  ?? 40);
        if ($cid) {
            Database::execute("UPDATE classes SET capacity=? WHERE id=?",[$capacity,$cid]);
            flash_success('Capacity updated.');
        }
    }

    // DELETE
    elseif ($action === 'delete') {
        $cid = int_val($_POST['class_id'] ?? 0);
        if ($cid) {
            $studentCount = (int)Database::fetchOne(
                "SELECT COUNT(*) AS c FROM students WHERE class_id=? AND status='active'",[$cid]
            )['c'];
            if ($studentCount > 0) {
                flash_error("Cannot delete: {$studentCount} active student(s) are in this class. Reassign them first.");
            } else {
                $cls = Database::fetchOne("SELECT name FROM classes WHERE id=?",[$cid]);
                Database::execute("DELETE FROM classes WHERE id=?",[$cid]);
                audit_log(current_user_id(), current_username(), 'delete_class', 'Classes',
                    "Deleted class: {$cls['name']}");
                flash_success("Class <strong>{$cls['name']}</strong> deleted.");
            }
        }
    }

    // REORDER
    elseif ($action === 'reorder') {
        $order = array_map('intval', $_POST['order'] ?? []);
        foreach ($order as $position => $classId) {
            Database::execute("UPDATE classes SET sort_order=? WHERE id=?",[$position+1,$classId]);
        }
        json_response(true, 'Order saved.');
    }

    redirect(BASE_URL . '/admin/classes.php');
}

// ── Fetch classes with counts ────────────────────────────────
$sess_id = current_session_id();
$classes = Database::fetchAll(
    "SELECT c.*,
            COUNT(DISTINCT s.id) AS student_count,
            COUNT(DISTINCT cs.subject_id) AS subject_count,
            COUNT(DISTINCT ts.teacher_id) AS teacher_count
     FROM classes c
     LEFT JOIN students s  ON s.class_id = c.id AND s.session_id = ? AND s.status='active'
     LEFT JOIN class_subjects cs ON cs.class_id = c.id
     LEFT JOIN teacher_subjects ts ON ts.class_id = c.id
     GROUP BY c.id
     ORDER BY c.sort_order, c.name",
    [$sess_id]
);

include INCLUDES_PATH . '/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title">🏛️ Class Management</h1>
        <p class="page-subtitle">
            <?= count($classes) ?> classes configured &nbsp;|&nbsp;
            Label: <strong>"<?= e(get_setting('class_label','Class')) ?>"</strong>
            — <a href="<?= BASE_URL ?>/admin/settings.php" class="text-sm">Change in Settings</a>
        </p>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-primary" onclick="openModal('addClassModal')">+ Add Class</button>
    </div>
</div>

<!-- Classes grid -->
<div class="grid-2 mb-24">
<?php foreach ($classes as $cls):
    $fillPct = $cls['capacity'] > 0 ? min(100, round(($cls['student_count'] / $cls['capacity']) * 100)) : 0;
    $barColor = $fillPct >= 90 ? 'red' : ($fillPct >= 70 ? 'orange' : 'green');
?>
<div class="card">
    <div class="card-header" style="background:var(--navy);color:var(--white)">
        <span style="font-size:17px;font-weight:800"><?= e($cls['name']) ?></span>
        <div style="display:flex;gap:6px">
            <button class="btn btn-sm btn-accent"
                    onclick="openRenameModal(<?= $cls['id'] ?>, '<?= e(addslashes($cls['name'])) ?>')">
                ✏️ Rename
            </button>
            <form method="POST" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="class_id" value="<?= $cls['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger"
                        data-confirm="Delete class <?= e($cls['name']) ?>? Students must be reassigned first.">
                    🗑️
                </button>
            </form>
        </div>
    </div>
    <div class="card-body">
        <!-- Stats row -->
        <div style="display:flex;gap:20px;margin-bottom:16px">
            <div style="text-align:center">
                <div style="font-size:26px;font-weight:800;color:var(--navy)"><?= $cls['student_count'] ?></div>
                <div class="text-xs text-muted">Students</div>
            </div>
            <div style="text-align:center">
                <div style="font-size:26px;font-weight:800;color:var(--blue)"><?= $cls['subject_count'] ?></div>
                <div class="text-xs text-muted">Subjects</div>
            </div>
            <div style="text-align:center">
                <div style="font-size:26px;font-weight:800;color:var(--emerald)"><?= $cls['teacher_count'] ?></div>
                <div class="text-xs text-muted">Teachers</div>
            </div>
            <div style="text-align:center;margin-left:auto">
                <div style="font-size:26px;font-weight:800;color:var(--gray)"><?= $cls['capacity'] ?></div>
                <div class="text-xs text-muted">Capacity</div>
            </div>
        </div>

        <!-- Fill bar -->
        <div style="margin-bottom:8px">
            <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                <span class="text-sm text-muted">Occupancy</span>
                <span class="text-sm fw-700"><?= $fillPct ?>%</span>
            </div>
            <div class="progress">
                <div class="progress-bar <?= $barColor ?>" style="width:<?= $fillPct ?>%"></div>
            </div>
        </div>

        <!-- Quick actions -->
        <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
            <a href="<?= BASE_URL ?>/admin/students.php?class_id=<?= $cls['id'] ?>"
               class="btn btn-sm btn-outline">👨‍🎓 View Students</a>
            <a href="<?= BASE_URL ?>/admin/timetable.php?class_id=<?= $cls['id'] ?>"
               class="btn btn-sm btn-outline">📆 Timetable</a>
            <a href="<?= BASE_URL ?>/admin/results.php?class_id=<?= $cls['id'] ?>"
               class="btn btn-sm btn-outline">📈 Results</a>
        </div>

        <!-- Inline capacity edit -->
        <form method="POST" style="display:flex;gap:8px;align-items:center;margin-top:12px;
                                   padding-top:12px;border-top:1px solid var(--border)">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_capacity">
            <input type="hidden" name="class_id" value="<?= $cls['id'] ?>">
            <label class="text-sm fw-700" style="white-space:nowrap">Set Capacity:</label>
            <input type="number" name="capacity" value="<?= $cls['capacity'] ?>"
                   min="1" max="200"
                   class="form-control" style="width:80px;padding:6px 10px">
            <button type="submit" class="btn btn-sm btn-primary">Save</button>
        </form>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- All classes summary table -->
<div class="card">
    <div class="card-header">📋 All Classes Overview</div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>Order</th>
                <th data-sort>Class Name</th>
                <th>Students</th>
                <th>Capacity</th>
                <th>Subjects</th>
                <th>Teachers</th>
                <th>Description</th>
                <th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($classes as $i => $cls): ?>
                <tr>
                    <td class="text-muted text-sm"><?= $cls['sort_order'] ?></td>
                    <td><span style="font-weight:800;font-size:15px"><?= e($cls['name']) ?></span></td>
                    <td>
                        <span style="font-weight:700;color:var(--navy)"><?= $cls['student_count'] ?></span>
                        <span class="text-muted text-xs">/ <?= $cls['capacity'] ?></span>
                    </td>
                    <td><?= $cls['capacity'] ?></td>
                    <td><?= $cls['subject_count'] ?></td>
                    <td><?= $cls['teacher_count'] ?></td>
                    <td class="text-sm text-muted"><?= e($cls['description'] ?: '—') ?></td>
                    <td>
                        <div class="td-actions">
                            <button class="btn btn-sm btn-primary"
                                    onclick="openRenameModal(<?= $cls['id'] ?>, '<?= e(addslashes($cls['name'])) ?>')">
                                ✏️ Rename
                            </button>
                            <form method="POST" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="class_id" value="<?= $cls['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                        data-confirm="Delete class <?= e($cls['name']) ?>?">🗑️</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Add Class Modal ── -->
<div class="modal-backdrop" id="addClassModal" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">+ Add New Class</span>
            <button class="modal-close" data-modal-close>×</button>
        </div>
        <form method="POST" action="" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Class Name <span class="req">*</span></label>
                    <input type="text" name="name" class="form-control" required
                           placeholder="e.g. SS1, Grade 7, Year 10">
                    <div class="form-hint">
                        This can be anything: JSS1, SS1, Grade 7, Year 10, Form 3, etc.
                    </div>
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Capacity (max students)</label>
                        <input type="number" name="capacity" class="form-control"
                               value="40" min="1" max="200">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description <small class="text-muted">(optional)</small></label>
                    <input type="text" name="description" class="form-control"
                           placeholder="e.g. Senior Secondary, Year 1">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Create Class</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Rename Class Modal ── -->
<div class="modal-backdrop" id="renameClassModal" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">✏️ Rename Class</span>
            <button class="modal-close" data-modal-close>×</button>
        </div>
        <form method="POST" action="" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="class_id" id="renameClassId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Current Name</label>
                    <input type="text" id="renameCurrentName" class="form-control" disabled>
                </div>
                <div class="form-group">
                    <label class="form-label">New Name <span class="req">*</span></label>
                    <input type="text" name="new_name" id="renameNewName" class="form-control" required
                           placeholder="Enter new class name">
                    <div class="form-hint">All student records, results, and timetables will update automatically.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-accent">✏️ Rename</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRenameModal(classId, currentName) {
    document.getElementById('renameClassId').value    = classId;
    document.getElementById('renameCurrentName').value = currentName;
    document.getElementById('renameNewName').value    = currentName;
    openModal('renameClassModal');
}
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
