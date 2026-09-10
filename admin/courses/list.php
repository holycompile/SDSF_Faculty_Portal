<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT.'/includes/auth.php';
require_once ROOT.'/includes/db.php';
require_once ROOT.'/includes/helpers.php';
if(session_status()===PHP_SESSION_NONE)session_start();
requireAdmin();
$flash = getFlash();
$courses = $pdo->query("
    SELECT c.*, 
           COUNT(DISTINCT fca.id) AS assigned_count,
           COUNT(DISTINCT le.id)  AS lecture_count
    FROM courses c 
    LEFT JOIN faculty_course_assignments fca ON fca.course_id = c.id 
    LEFT JOIN lecture_entries le ON le.course_id = c.id
    GROUP BY c.id 
    ORDER BY c.program, c.semester, c.subject_name
")->fetchAll();
$active_nav = 'courses-list';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Courses — SDSF Admin</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
</head>
<body>
<header class="topbar">
    <div class="tb-left">
        <a href="<?= BASE_URL ?>/admin/dashboard.php" style="color:#94a3b8;text-decoration:none;">Dashboard</a>
        <span class="tb-sep">/</span>
        <span class="tb-crumb">Courses</span>
    </div>
    <div class="tb-right">
        <a href="<?= BASE_URL ?>/admin/courses/add.php" class="btn btn-primary btn-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Course
        </a>
    </div>
</header>

<div class="page">
    <div class="page-header fade-up">
        <h1>Course Management</h1>
        <p>All subjects available for faculty assignment (<?= count($courses) ?> total)</p>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> fade-up"><?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>

    <div class="card fade-up">
        <div class="card-head">
            <div>
                <div class="card-title">All Courses</div>
                <div class="card-sub">Manage, edit, and assign courses to faculty members</div>
            </div>
            <span class="c-badge"><?= count($courses) ?></span>
        </div>

        <?php if (empty($courses)): ?>
        <div style="padding:50px;text-align:center;color:#94a3b8;">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 12px;display:block;opacity:.4"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
            <div style="font-weight:600;margin-bottom:4px;">No courses yet</div>
            <a href="<?= BASE_URL ?>/admin/courses/add.php" style="color:#4f46e5;font-size:14px;">Add the first course</a>
        </div>
        <?php else: ?>
        <table class="dt">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Program</th>
                    <th>Semester</th>
                    <th>Subject</th>
                    <th>Code</th>
                    <th>Type</th>
                    <th>Rate</th>
                    <th>Assigned To</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($courses as $i => $c): ?>
                <tr>
                    <td style="color:#cbd5e1;font-size:12px;"><?= $i + 1 ?></td>
                    <td style="font-weight:600;color:#0f172a;"><?= htmlspecialchars($c['program']) ?></td>
                    <td><?= htmlspecialchars($c['semester'] ?? '—') ?></td>
                    <td style="font-weight:600;"><?= htmlspecialchars($c['subject_name']) ?></td>
                    <td><span style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 8px;border-radius:5px;"><?= htmlspecialchars($c['course_code'] ?? '—') ?></span></td>
                    <td>
                        <?php if ($c['class_type'] === 'T'): ?>
                            <span class="badge badge-blue">Theory</span>
                        <?php else: ?>
                            <span class="badge badge-amber">Practical</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight:600;color:#047857;">&#8377;<?= $c['class_type'] === 'T' ? 800 : 400 ?>/hr</td>
                    <td><span class="badge badge-green"><?= $c['assigned_count'] ?> faculty</span></td>
                    <td style="text-align:right;">
                        <div style="display:inline-flex;gap:6px;">
                            <a href="<?= BASE_URL ?>/admin/courses/edit.php?id=<?= $c['id'] ?>" class="btn btn-outline btn-sm" title="Edit Course">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Edit
                            </a>
                            <button type="button" class="btn btn-danger btn-sm"
                                onclick="confirmDeleteCourse(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['subject_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars($c['course_code'] ?? '', ENT_QUOTES) ?>', <?= (int)$c['lecture_count'] ?>)">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                                Delete
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- Course Delete Confirmation Modal -->
<div id="deleteCourseModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,0.6);backdrop-filter:blur(5px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:20px;padding:32px;max-width:440px;width:90%;box-shadow:0 24px 64px rgba(0,0,0,0.22);animation:fadeUp .25s ease both;">
        <div style="width:58px;height:58px;border-radius:16px;background:#fef2f2;border:2px solid #fecaca;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <h2 style="font-size:18px;font-weight:800;color:#0f172a;text-align:center;margin:0 0 6px;">Delete Course?</h2>
        <p style="font-size:13px;color:#64748b;text-align:center;margin:0 0 16px;">You are about to delete the following course:</p>
        <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:12px 16px;margin-bottom:16px;text-align:center;">
            <div id="modal-cname" style="font-size:15px;font-weight:800;color:#0f172a;"></div>
            <div id="modal-ccode" style="font-size:12px;font-family:monospace;font-weight:700;color:#4f46e5;background:#eef2ff;display:inline-block;padding:2px 8px;border-radius:5px;margin-top:4px;"></div>
        </div>
        <div id="modal-warning" style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 14px;margin-bottom:20px;font-size:12.5px;color:#92400e;line-height:1.5;">
        </div>
        <div style="display:flex;gap:12px;">
            <button type="button" onclick="closeCourseModal()"
                style="flex:1;padding:11px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;font-size:13.5px;font-weight:600;color:#475569;cursor:pointer;">
                Cancel
            </button>
            <form method="POST" action="<?= BASE_URL ?>/admin/courses/delete.php" id="deleteForm" style="flex:1;margin:0;">
                <input type="hidden" name="course_id" id="modal-cid">
                <button type="submit" id="modal-submit-btn"
                    style="width:100%;padding:11px;border-radius:10px;border:none;background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;font-size:13.5px;font-weight:700;cursor:pointer;">
                    Yes, Delete
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function confirmDeleteCourse(id, name, code, lectureCount) {
    document.getElementById('modal-cid').value = id;
    document.getElementById('modal-cname').textContent = name;
    document.getElementById('modal-ccode').textContent = code ? code : 'No Code';

    var warnDiv = document.getElementById('modal-warning');
    var submitBtn = document.getElementById('modal-submit-btn');

    if (lectureCount > 0) {
        warnDiv.style.background = '#fef2f2';
        warnDiv.style.borderColor = '#fecaca';
        warnDiv.style.color = '#991b1b';
        warnDiv.innerHTML = '<strong>Cannot Delete:</strong> ' + lectureCount + ' lecture session(s) are recorded under this course. Historical attendance & remuneration data would be lost.';
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.4';
        submitBtn.style.cursor = 'not-allowed';
    } else {
        warnDiv.style.background = '#fffbeb';
        warnDiv.style.borderColor = '#fde68a';
        warnDiv.style.color = '#92400e';
        warnDiv.innerHTML = 'This course has no recorded lectures. Deleting it will remove it from all assigned faculty.';
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
        submitBtn.style.cursor = 'pointer';
    }

    document.getElementById('deleteCourseModal').style.display = 'flex';
}
function closeCourseModal() {
    document.getElementById('deleteCourseModal').style.display = 'none';
}
document.getElementById('deleteCourseModal').addEventListener('click', function(e) {
    if (e.target === this) closeCourseModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeCourseModal();
});
</script>
</body>
</html>
