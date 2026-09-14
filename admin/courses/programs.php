<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$errors = [];
$fromProg = trim($_GET['from_prog'] ?? '');

// Handle ADD Program
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_program') {
    $pName     = trim($_POST['program_name'] ?? '');
    $pCode     = strtoupper(trim($_POST['program_code'] ?? ''));
    $bYear     = trim($_POST['batch_year'] ?? '');
    $semCount  = (int)($_POST['total_semesters'] ?? 4);

    if (!$pName) $errors[] = 'Program name is required.';
    if (!$pCode) $pCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $pName));
    if (!$bYear) $errors[] = 'Batch year tag (e.g. 2022-2027) is required.';
    if ($semCount <= 0 || $semCount > 12) $errors[] = 'Total semesters must be between 1 and 12.';

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO academic_programs (program_name, program_code, batch_year, total_semesters)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$pName, $pCode, $bYear, $semCount]);
            setFlash('success', "Program \"{$pName}\" ({$bYear}) added successfully with {$semCount} semesters!");
            header('Location: ' . BASE_URL . '/admin/courses/programs.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Failed to add program: ' . $e->getMessage();
        }
    }
}

// Handle EDIT Program
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_program') {
    $pId       = (int)($_POST['program_id'] ?? 0);
    $pName     = trim($_POST['program_name'] ?? '');
    $pCode     = strtoupper(trim($_POST['program_code'] ?? ''));
    $bYear     = trim($_POST['batch_year'] ?? '');
    $semCount  = (int)($_POST['total_semesters'] ?? 4);

    if (!$pId)   $errors[] = 'Invalid program ID.';
    if (!$pName) $errors[] = 'Program name is required.';
    if (!$bYear) $errors[] = 'Batch year tag is required.';
    if ($semCount <= 0 || $semCount > 12) $errors[] = 'Total semesters must be between 1 and 12.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $upd = $pdo->prepare("
                UPDATE academic_programs
                SET program_name = ?, program_code = ?, batch_year = ?, total_semesters = ?
                WHERE id = ?
            ");
            $upd->execute([$pName, $pCode, $bYear, $semCount, $pId]);

            // Synchronize program name & batch_year in courses table
            $updCourses = $pdo->prepare("
                UPDATE courses
                SET program = ?, batch_year = ?
                WHERE program_id = ?
            ");
            $updCourses->execute([$pName, $bYear, $pId]);

            $pdo->commit();
            setFlash('success', "Program \"{$pName}\" updated successfully!");
            header('Location: ' . BASE_URL . '/admin/courses/programs.php');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Failed to update program: ' . $e->getMessage();
        }
    }
}

// Handle DELETE Program
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_program') {
    $pId = (int)($_POST['program_id'] ?? 0);
    if ($pId > 0) {
        // Check if lectures exist under this program's courses
        $chk = $pdo->prepare("
            SELECT COUNT(*) FROM lecture_entries le
            JOIN courses c ON c.id = le.course_id
            WHERE c.program_id = ?
        ");
        $chk->execute([$pId]);
        $lecCount = (int)$chk->fetchColumn();

        if ($lecCount > 0) {
            setFlash('error', "Cannot delete program because {$lecCount} recorded lecture sessions exist under it.");
        } else {
            try {
                $del = $pdo->prepare("DELETE FROM academic_programs WHERE id = ?");
                $del->execute([$pId]);
                setFlash('success', "Program and associated subjects deleted successfully.");
            } catch (PDOException $e) {
                setFlash('error', "Failed to delete program: " . $e->getMessage());
            }
        }
    }
    header('Location: ' . BASE_URL . '/admin/courses/programs.php');
    exit;
}

// Fetch all programs with subject counts
$programs = $pdo->query("
    SELECT ap.*, 
           COUNT(c.id) AS subject_count
    FROM academic_programs ap
    LEFT JOIN courses c ON c.program_id = ap.id
    GROUP BY ap.id
    ORDER BY ap.id ASC
")->fetchAll();

$flash = getFlash();
$active_nav = 'courses-list';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Academic Programs &amp; Batches — SDSF Admin</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
<style>
.prog-card {
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 16px;
    padding: 22px;
    transition: all 0.2s ease;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.prog-card:hover {
    border-color: #c7d2fe;
    box-shadow: 0 10px 28px rgba(79,70,229,0.1);
    transform: translateY(-2px);
}
.batch-pill {
    background: #eef2ff;
    color: #4f46e5;
    font-weight: 700;
    font-size: 12px;
    padding: 3px 10px;
    border-radius: 6px;
    border: 1px solid #c7d2fe;
    display: inline-block;
}
</style>
</head>
<body>
<header class="topbar">
    <div class="tb-left">
        <a href="<?= BASE_URL ?>/admin/dashboard.php" style="color:#94a3b8;text-decoration:none;">Dashboard</a>
        <span class="tb-sep">/</span>
        <a href="<?= BASE_URL ?>/admin/courses/list.php<?= $fromProg ? '?program=' . urlencode($fromProg) : '' ?>" style="color:#94a3b8;text-decoration:none;">Courses</a>
        <span class="tb-sep">/</span>
        <span class="tb-crumb">Programs &amp; Batches</span>
    </div>
    <div class="tb-right">
        <a href="<?= BASE_URL ?>/admin/courses/list.php<?= $fromProg ? '?program=' . urlencode($fromProg) : '' ?>" class="btn btn-outline btn-sm">&larr; Back to Subjects</a>
        <button type="button" class="btn btn-primary btn-sm" onclick="openAddModal()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            + Add New Program / Batch
        </button>
    </div>
</header>

<div class="page">
    <div class="page-header fade-up">
        <h1>Academic Programs &amp; Batches</h1>
        <p>Manage degree courses, batch year tags (e.g. 2022-2027, 2025-2027), and designated semester counts</p>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> fade-up"><?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>

    <?php foreach ($errors as $e): ?>
    <div class="alert alert-error fade-up"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <!-- Program Cards Grid -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(340px, 1fr));gap:20px;margin-bottom:30px;">
        <?php foreach ($programs as $prog): ?>
        <div class="prog-card fade-up">
            <div>
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px;">
                    <div>
                        <h2 style="font-size:18px;font-weight:800;color:#0f172a;margin:0 0 4px;">
                            <?= htmlspecialchars($prog['program_name']) ?>
                        </h2>
                        <div style="font-size:12px;font-family:monospace;color:#64748b;">Code: <?= htmlspecialchars($prog['program_code']) ?></div>
                    </div>
                    <span class="badge <?= $prog['total_semesters'] == 10 ? 'badge-blue' : 'badge-green' ?>" style="font-size:12px;padding:4px 10px;">
                        <?= $prog['total_semesters'] ?> Semesters
                    </span>
                </div>

                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;margin:14px 0;">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <span style="font-size:12.5px;color:#64748b;font-weight:600;">Configured Subjects:</span>
                        <span style="font-size:15px;font-weight:800;color:#4f46e5;"><?= $prog['subject_count'] ?> subjects</span>
                    </div>
                </div>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;border-top:1px solid #f1f5f9;padding-top:14px;margin-top:10px;">
                <a href="<?= BASE_URL ?>/admin/courses/list.php?program=<?= urlencode($prog['program_name']) ?>" class="btn btn-outline btn-sm">
                    View Subjects &rarr;
                </a>
                <div style="display:flex;gap:6px;">
                    <button type="button" class="btn btn-outline btn-sm"
                        onclick="openEditModal(<?= $prog['id'] ?>, '<?= htmlspecialchars(addslashes($prog['program_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($prog['program_code']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($prog['batch_year']), ENT_QUOTES) ?>', <?= $prog['total_semesters'] ?>)">
                        Edit
                    </button>
                    <button type="button" class="btn btn-danger btn-sm"
                        onclick="confirmDeleteProgram(<?= $prog['id'] ?>, '<?= htmlspecialchars(addslashes($prog['program_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($prog['batch_year']), ENT_QUOTES) ?>', <?= $prog['subject_count'] ?>)">
                        Delete
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal: Add Program -->
<div id="addModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,0.6);backdrop-filter:blur(5px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:20px;padding:32px;max-width:480px;width:90%;box-shadow:0 24px 64px rgba(0,0,0,0.22);animation:fadeUp .25s ease both;">
        <h2 style="font-size:18px;font-weight:800;color:#0f172a;margin:0 0 6px;">Add Academic Program &amp; Batch</h2>
        <p style="font-size:13px;color:#64748b;margin:0 0 20px;">Configure a degree course, batch year tag, and semester count</p>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add_program">
            <div style="margin-bottom:14px;">
                <label class="form-label">Program Name *</label>
                <input type="text" name="program_name" class="form-input" placeholder="e.g. M.Tech AI&DS, M.Tech BDA" required>
            </div>
            <div class="grid-2" style="margin-bottom:14px;">
                <div>
                    <label class="form-label">Program Code</label>
                    <input type="text" name="program_code" class="form-input" placeholder="e.g. MTECH-AIDS" style="text-transform:uppercase;">
                </div>
                <div>
                    <label class="form-label">Batch Year Tag *</label>
                    <input type="text" name="batch_year" class="form-input" placeholder="e.g. 2022-2027, 2025-2027" required>
                </div>
            </div>
            <div style="margin-bottom:24px;">
                <label class="form-label">Total Semesters *</label>
                <select name="total_semesters" class="form-select" required>
                    <option value="4">4 Semesters (2-Year Degree)</option>
                    <option value="10">10 Semesters (5-Year Integrated Degree)</option>
                    <option value="2">2 Semesters (1-Year Program)</option>
                    <option value="6">6 Semesters (3-Year Degree)</option>
                    <option value="8">8 Semesters (4-Year Degree)</option>
                </select>
            </div>
            <div style="display:flex;gap:12px;">
                <button type="button" onclick="document.getElementById('addModal').style.display='none';" class="btn btn-outline" style="flex:1;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;">Save Program</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Program -->
<div id="editModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,0.6);backdrop-filter:blur(5px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:20px;padding:32px;max-width:480px;width:90%;box-shadow:0 24px 64px rgba(0,0,0,0.22);animation:fadeUp .25s ease both;">
        <h2 style="font-size:18px;font-weight:800;color:#0f172a;margin:0 0 6px;">Edit Academic Program</h2>
        <p style="font-size:13px;color:#64748b;margin:0 0 20px;">Update program details, year tag, and semester count</p>
        <form method="POST" action="">
            <input type="hidden" name="action" value="edit_program">
            <input type="hidden" name="program_id" id="edit-prog-id">
            <div style="margin-bottom:14px;">
                <label class="form-label">Program Name *</label>
                <input type="text" name="program_name" id="edit-prog-name" class="form-input" required>
            </div>
            <div class="grid-2" style="margin-bottom:14px;">
                <div>
                    <label class="form-label">Program Code</label>
                    <input type="text" name="program_code" id="edit-prog-code" class="form-input" style="text-transform:uppercase;">
                </div>
                <div>
                    <label class="form-label">Batch Year Tag *</label>
                    <input type="text" name="batch_year" id="edit-prog-batch" class="form-input" required>
                </div>
            </div>
            <div style="margin-bottom:24px;">
                <label class="form-label">Total Semesters *</label>
                <select name="total_semesters" id="edit-prog-sem" class="form-select" required>
                    <option value="4">4 Semesters (2-Year Degree)</option>
                    <option value="10">10 Semesters (5-Year Integrated Degree)</option>
                    <option value="2">2 Semesters (1-Year Program)</option>
                    <option value="6">6 Semesters (3-Year Degree)</option>
                    <option value="8">8 Semesters (4-Year Degree)</option>
                </select>
            </div>
            <div style="display:flex;gap:12px;">
                <button type="button" onclick="document.getElementById('editModal').style.display='none';" class="btn btn-outline" style="flex:1;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;">Update Program</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Delete Program -->
<div id="deleteModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,0.6);backdrop-filter:blur(5px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:20px;padding:32px;max-width:440px;width:90%;box-shadow:0 24px 64px rgba(0,0,0,0.22);animation:fadeUp .25s ease both;">
        <h2 style="font-size:18px;font-weight:800;color:#dc2626;margin:0 0 6px;">Delete Program?</h2>
        <p style="font-size:13px;color:#64748b;margin:0 0 16px;">Are you sure you want to remove this academic program?</p>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;margin-bottom:16px;">
            <div id="del-prog-title" style="font-size:15px;font-weight:700;color:#0f172a;"></div>
            <div id="del-prog-meta" style="font-size:12px;color:#64748b;margin-top:2px;"></div>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="delete_program">
            <input type="hidden" name="program_id" id="del-prog-id">
            <div style="display:flex;gap:12px;">
                <button type="button" onclick="document.getElementById('deleteModal').style.display='none';" class="btn btn-outline" style="flex:1;">Cancel</button>
                <button type="submit" class="btn btn-danger" style="flex:1;justify-content:center;">Yes, Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('addModal').style.display = 'flex';
}
function openEditModal(id, name, code, batch, sCount) {
    document.getElementById('edit-prog-id').value = id;
    document.getElementById('edit-prog-name').value = name;
    document.getElementById('edit-prog-code').value = code;
    document.getElementById('edit-prog-batch').value = batch;
    document.getElementById('edit-prog-sem').value = sCount;
    document.getElementById('editModal').style.display = 'flex';
}
function confirmDeleteProgram(id, name, batch, subCount) {
    document.getElementById('del-prog-id').value = id;
    document.getElementById('del-prog-title').textContent = name;
    document.getElementById('del-prog-meta').textContent = 'Batch: ' + batch + ' • ' + subCount + ' subjects attached';
    document.getElementById('deleteModal').style.display = 'flex';
}
</script>
</body>
</html>
