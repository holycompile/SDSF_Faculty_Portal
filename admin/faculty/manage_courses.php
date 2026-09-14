<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setFlash('error', 'Invalid faculty ID.');
    header('Location: ' . BASE_URL . '/admin/faculty/list.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM faculty_members WHERE id = ?");
$stmt->execute([$id]);
$faculty = $stmt->fetch();
if (!$faculty) {
    setFlash('error', 'Faculty member not found.');
    header('Location: ' . BASE_URL . '/admin/faculty/list.php');
    exit;
}

// Handle ADD courses
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $addIds = $_POST['course_ids'] ?? [];
    if (!empty($addIds)) {
        try {
            $allCourseStmt = $pdo->query("SELECT * FROM courses");
            $courseMap = [];
            foreach ($allCourseStmt->fetchAll() as $ac) {
                $courseMap[$ac['id']] = $ac;
            }
            $assign = $pdo->prepare("
                INSERT IGNORE INTO faculty_course_assignments
                    (faculty_id, faculty_name, faculty_enrollment_no, course_id, course_name, course_code)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $addedCount = 0;
            foreach ($addIds as $cid) {
                $cid = (int)$cid;
                if (!$cid) continue;
                $cName = $courseMap[$cid]['subject_name'] ?? '';
                $cCode = $courseMap[$cid]['course_code'] ?? '';
                $assign->execute([$id, $faculty['name'], $faculty['faculty_enrollment_no'], $cid, $cName, $cCode]);
                $addedCount++;
            }
            setFlash('success', $addedCount . ' course(s) assigned to ' . $faculty['name'] . ' successfully.');
        } catch (PDOException $e) {
            setFlash('error', 'Failed to assign course(s): ' . $e->getMessage());
        }
    } else {
        setFlash('error', 'Please select at least one course to add.');
    }
    header('Location: ' . BASE_URL . '/admin/faculty/manage_courses.php?id=' . $id);
    exit;
}

// Handle REMOVE single course
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove') {
    $removeCourseId = (int)($_POST['course_id'] ?? 0);
    if ($removeCourseId) {
        try {
            $del = $pdo->prepare("DELETE FROM faculty_course_assignments WHERE faculty_id = ? AND course_id = ?");
            $del->execute([$id, $removeCourseId]);
            setFlash('success', 'Course removed from ' . $faculty['name'] . "'s assignments.");
        } catch (PDOException $e) {
            setFlash('error', 'Failed to remove course: ' . $e->getMessage());
        }
    }
    header('Location: ' . BASE_URL . '/admin/faculty/manage_courses.php?id=' . $id);
    exit;
}

// Fetch currently assigned courses
$cStmt = $pdo->prepare("
    SELECT c.*, fca.assigned_at
    FROM courses c
    JOIN faculty_course_assignments fca ON fca.course_id = c.id
    WHERE fca.faculty_id = ?
    ORDER BY c.program, c.semester, c.subject_name
");
$cStmt->execute([$id]);
$assignedCourses = $cStmt->fetchAll();
$assignedIds = array_column($assignedCourses, 'id');

// Fetch ALL courses
$allCourses = $pdo->query("SELECT * FROM courses ORDER BY program, semester, subject_name")->fetchAll();

$flash = getFlash();
$active_nav = 'faculty-list';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Courses — <?= htmlspecialchars($faculty['name']) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
<style>
.course-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
.course-item { display: flex; align-items: flex-start; gap: 10px; padding: 13px 15px; border-radius: 12px; border: 1.5px solid #e2e8f0; background: #f8fafc; cursor: pointer; transition: all .18s; }
.course-item:has(input:checked) { border-color: #c7d2fe; background: #eef2ff; }
.course-item:hover { border-color: #a5b4fc; background: #f5f3ff; }
.course-item input[type=checkbox] { margin-top: 2px; accent-color: #4f46e5; width: 16px; height: 16px; flex-shrink: 0; cursor: pointer; }
.course-prog { font-size: 11.5px; font-weight: 700; color: #4f46e5; text-transform: uppercase; letter-spacing: .04em; }
.course-sub  { font-size: 13.5px; font-weight: 600; color: #0f172a; margin: 3px 0 2px; }
.course-meta { font-size: 11.5px; color: #64748b; }
.assigned-row { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid #f1f5f9; gap: 12px; }
.assigned-row:last-child { border-bottom: none; }
.assigned-row:hover { background: #fafafa; }
.btn-danger-sm { background: #fff; color: #dc2626; border: 1.5px solid #fecaca; padding: 6px 13px; border-radius: 8px; font-size: 12.5px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: all .16s; font-family: inherit; }
.btn-danger-sm:hover { background: #fef2f2; border-color: #f87171; }
.empty-state { padding: 40px 20px; text-align: center; color: #94a3b8; font-size: 14px; }
</style>
</head>
<body>
<header class="topbar">
    <div class="tb-left">
        <a href="<?= BASE_URL ?>/admin/dashboard.php" style="color:#94a3b8;text-decoration:none;">Dashboard</a>
        <span class="tb-sep">/</span>
        <a href="<?= BASE_URL ?>/admin/faculty/list.php" style="color:#94a3b8;text-decoration:none;">Faculty</a>
        <span class="tb-sep">/</span>
        <a href="<?= BASE_URL ?>/admin/faculty/view.php?id=<?= $id ?>" style="color:#94a3b8;text-decoration:none;"><?= htmlspecialchars($faculty['name']) ?></a>
        <span class="tb-sep">/</span>
        <span class="tb-crumb">Manage Courses</span>
    </div>
    <div class="tb-right">
        <span class="tb-date"><?= date('d M Y') ?></span>
        <a href="<?= BASE_URL ?>/admin/faculty/view.php?id=<?= $id ?>" class="btn btn-outline btn-sm">
            &larr; Back to Profile
        </a>
    </div>
</header>

<div class="page">

    <!-- Faculty Identity Banner -->
    <div class="fade-up" style="background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%);border-radius:18px;padding:22px 28px;margin-bottom:24px;display:flex;align-items:center;gap:18px;color:#fff;">
        <div style="width:54px;height:54px;border-radius:14px;background:rgba(255,255,255,0.2);color:#fff;font-size:20px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <?= strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $faculty['name']), 0, 2)) ?>
        </div>
        <div>
            <div style="font-size:18px;font-weight:800;"><?= htmlspecialchars($faculty['name']) ?></div>
            <div style="font-size:13px;opacity:.8;margin-top:2px;">
                <?= htmlspecialchars($faculty['faculty_enrollment_no']) ?>
                &bull; <?= htmlspecialchars($faculty['qualification']) ?>
                &bull; <?= htmlspecialchars($faculty['department']) ?>
            </div>
        </div>
        <div style="margin-left:auto;background:rgba(255,255,255,0.15);border-radius:10px;padding:10px 20px;text-align:center;">
            <div style="font-size:24px;font-weight:800;"><?= count($assignedCourses) ?></div>
            <div style="font-size:11px;opacity:.8;text-transform:uppercase;letter-spacing:.05em;">Courses Assigned</div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> fade-up">
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start;">

        <!-- Left: Currently Assigned Courses -->
        <div class="card fade-up">
            <div class="card-head">
                <div>
                    <div class="card-title">Currently Assigned Courses</div>
                    <div class="card-sub">Remove individual courses from this faculty member</div>
                </div>
                <span class="c-badge"><?= count($assignedCourses) ?> Courses</span>
            </div>
            <?php if (empty($assignedCourses)): ?>
                <div class="empty-state">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 12px;display:block;opacity:.3"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                    No courses currently assigned.<br>Use the panel on the right to add courses.
                </div>
            <?php else: ?>
                <?php foreach ($assignedCourses as $ac): ?>
                <div class="assigned-row">
                    <div>
                        <div style="font-size:11.5px;font-weight:700;color:#4f46e5;text-transform:uppercase;letter-spacing:.04em;">
                            <?= htmlspecialchars($ac['program']) ?> &bull; <?= htmlspecialchars($ac['semester'] ?? '') ?>
                        </div>
                        <div style="font-size:14px;font-weight:600;color:#0f172a;margin:3px 0 2px;">
                            <?= htmlspecialchars($ac['subject_name']) ?>
                        </div>
                        <div style="font-size:12px;color:#64748b;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <span style="font-family:monospace;background:#f1f5f9;padding:2px 7px;border-radius:5px;"><?= htmlspecialchars($ac['course_code'] ?? '—') ?></span>
                            <?php if ($ac['class_type'] === 'T'): ?>
                                <span class="badge badge-blue">Theory — &#8377;<?= number_format($faculty['theory_rate'] ?? 800, 0) ?>/hr</span>
                            <?php else: ?>
                                <span class="badge badge-amber">Practical — &#8377;<?= number_format($faculty['practical_rate'] ?? 400, 0) ?>/hr</span>
                            <?php endif; ?>
                            <span style="color:#cbd5e1;">Since: <?= date('d M Y', strtotime($ac['assigned_at'])) ?></span>
                        </div>
                    </div>
                    <form method="POST" onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($ac['subject_name']), ENT_QUOTES) ?> from <?= htmlspecialchars(addslashes($faculty['name']), ENT_QUOTES) ?>?')">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="course_id" value="<?= $ac['id'] ?>">
                        <button type="submit" class="btn-danger-sm">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                            Remove
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Right: Add More Courses -->
        <div class="card fade-up">
            <div class="card-head">
                <div>
                    <div class="card-title">Add More Courses</div>
                    <div class="card-sub">Select additional courses to assign to this faculty</div>
                </div>
                <span class="c-badge" id="add-selected-count">0 selected</span>
            </div>
            <div style="padding:20px;">
                <?php
                $availableCourses = array_values(array_filter($allCourses, fn($c) => !in_array($c['id'], $assignedIds)));
                ?>
                <?php if (empty($availableCourses)): ?>
                    <div class="empty-state">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 10px;display:block;opacity:.3"><polyline points="20 6 9 17 4 12"/></svg>
                        <strong style="display:block;color:#334155;margin-bottom:4px;">All courses are already assigned!</strong>
                        This faculty member has been assigned every available course.
                    </div>
                <?php else: ?>
                    <form method="POST" id="addCoursesForm">
                        <input type="hidden" name="action" value="add">
                        <div style="margin-bottom:14px;">
                            <input type="text" id="courseSearch" placeholder="&#128269; Search by subject, code, program..." oninput="filterCourses()"
                                style="width:100%;padding:9px 13px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13.5px;color:#0f172a;background:#f8fafc;outline:none;font-family:inherit;box-sizing:border-box;">
                        </div>
                        <div class="course-grid" id="courseGrid" style="max-height:420px;overflow-y:auto;padding-right:4px;">
                            <?php foreach ($availableCourses as $course): ?>
                            <label class="course-item" data-search="<?= strtolower($course['program'].' '.$course['subject_name'].' '.$course['course_code'].' '.$course['semester']) ?>">
                                <input type="checkbox" name="course_ids[]" value="<?= $course['id'] ?>" onchange="updateAddCount()">
                                <div>
                                    <div class="course-prog"><?= htmlspecialchars($course['program']) ?> &bull; <?= htmlspecialchars($course['semester'] ?? '') ?></div>
                                    <div class="course-sub"><?= htmlspecialchars($course['subject_name']) ?></div>
                                    <div class="course-meta">
                                        <?= $course['course_code'] ? '<span style="font-family:monospace;">' . htmlspecialchars($course['course_code']) . '</span> &middot; ' : '' ?>
                                        <?= $course['class_type'] === 'T'
                                            ? '<span style="color:#2563eb;font-weight:600;">Theory</span> &#8377;' . number_format($faculty['theory_rate'] ?? 800, 0) . '/hr'
                                            : '<span style="color:#b45309;font-weight:600;">Practical</span> &#8377;' . number_format($faculty['practical_rate'] ?? 400, 0) . '/hr' ?>
                                    </div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top:18px;">
                            <button type="submit" class="btn btn-primary" id="addBtn" disabled style="width:100%;justify-content:center;padding:12px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                Assign Selected Courses
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script>
function updateAddCount() {
    const n = document.querySelectorAll('#courseGrid input[type=checkbox]:checked').length;
    document.getElementById('add-selected-count').textContent = n + ' selected';
    const btn = document.getElementById('addBtn');
    if (btn) btn.disabled = (n === 0);
}
function filterCourses() {
    const q = document.getElementById('courseSearch').value.toLowerCase().trim();
    document.querySelectorAll('.course-item').forEach(item => {
        const text = item.getAttribute('data-search') || '';
        item.style.display = (!q || text.includes(q)) ? '' : 'none';
    });
}
document.addEventListener('DOMContentLoaded', updateAddCount);
</script>
</body>
</html>
