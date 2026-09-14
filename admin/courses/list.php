<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$flash = getFlash();

// Fetch all courses with assignment and lecture counts
$allCourses = $pdo->query("
    SELECT c.*, 
           COUNT(DISTINCT fca.id) AS assigned_count,
           COUNT(DISTINCT le.id)  AS lecture_count
    FROM courses c 
    LEFT JOIN faculty_course_assignments fca ON fca.course_id = c.id 
    LEFT JOIN lecture_entries le ON le.course_id = c.id
    GROUP BY c.id 
    ORDER BY c.program, c.semester, c.subject_name
")->fetchAll();

// Pre-defined list of the 9 semesters
$semesters = [
    '1st Semester',
    '2nd Semester',
    '3rd Semester',
    '4th Semester',
    '5th Semester',
    '6th Semester',
    '7th Semester',
    '8th Semester',
    '9th Semester'
];

// Group courses by semester
$coursesBySemester = [];
foreach ($semesters as $sem) {
    $coursesBySemester[$sem] = [];
}
$otherCourses = [];

foreach ($allCourses as $c) {
    $sem = trim($c['semester'] ?? '');
    if (isset($coursesBySemester[$sem])) {
        $coursesBySemester[$sem][] = $c;
    } else {
        $otherCourses[] = $c;
    }
}

$totalCoursesCount = count($allCourses);
$active_nav = 'courses-list';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Semester & Course Management — SDSF Admin</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
<style>
.sem-tabs {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    padding-bottom: 8px;
    margin-bottom: 24px;
    border-bottom: 1px solid #e2e8f0;
}
.sem-tab-btn {
    padding: 8px 16px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    border: 1.5px solid #e2e8f0;
    background: #fff;
    color: #475569;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.18s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.sem-tab-btn:hover {
    border-color: #c7d2fe;
    color: #4f46e5;
    background: #f8fafc;
}
.sem-tab-btn.active {
    background: #4f46e5;
    color: #ffffff;
    border-color: #4f46e5;
    box-shadow: 0 4px 14px rgba(79,70,229,0.25);
}
.sem-tab-count {
    font-size: 11px;
    padding: 2px 7px;
    border-radius: 20px;
    background: rgba(0,0,0,0.06);
}
.sem-tab-btn.active .sem-tab-count {
    background: rgba(255,255,255,0.25);
    color: #ffffff;
}
.sem-section {
    margin-bottom: 28px;
}
.sem-header {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-bottom: none;
    border-radius: 16px 16px 0 0;
    padding: 16px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
.sem-title-box {
    display: flex;
    align-items: center;
    gap: 12px;
}
.sem-badge {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    background: linear-gradient(135deg, #4f46e5, #6366f1);
    color: #fff;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    box-shadow: 0 4px 10px rgba(79,70,229,0.25);
}
</style>
</head>
<body>
<header class="topbar">
    <div class="tb-left">
        <a href="<?= BASE_URL ?>/admin/dashboard.php" style="color:#94a3b8;text-decoration:none;">Dashboard</a>
        <span class="tb-sep">/</span>
        <span class="tb-crumb">M.Tech AI&amp;DS Courses</span>
    </div>
    <div class="tb-right">
        <a href="<?= BASE_URL ?>/admin/courses/add.php?program=M.Tech+AI%26DS" class="btn btn-primary btn-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add New Subject
        </a>
    </div>
</header>

<div class="page">
    <div class="page-header fade-up">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
            <div>
                <h1>M.Tech AI&amp;DS — Semester &amp; Course Management</h1>
                <p>Manage curriculum subjects, theory/practical classifications, and faculty assignments across all 9 Semesters (<?= $totalCoursesCount ?> total subjects)</p>
            </div>
            <div style="display:flex;gap:10px;">
                <span class="badge badge-blue" style="font-size:13px;padding:6px 14px;">Program: M.Tech AI&amp;DS</span>
                <span class="badge badge-green" style="font-size:13px;padding:6px 14px;">9 Semesters Active</span>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> fade-up"><?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>

    <!-- Semester Navigation Tabs -->
    <div class="sem-tabs fade-up" id="semesterNav">
        <button type="button" class="sem-tab-btn active" onclick="filterSemester('all', this)">
            <span>All Semesters</span>
            <span class="sem-tab-count"><?= $totalCoursesCount ?></span>
        </button>
        <?php foreach ($semesters as $idx => $semName): ?>
            <?php $count = count($coursesBySemester[$semName]); ?>
            <button type="button" class="sem-tab-btn" onclick="filterSemester('sem-<?= $idx + 1 ?>', this)">
                <span>Sem <?= $idx + 1 ?></span>
                <span class="sem-tab-count"><?= $count ?></span>
            </button>
        <?php endforeach; ?>
    </div>

    <!-- Search filter input -->
    <div class="card fade-up" style="margin-bottom:20px;padding:12px 18px;">
        <div style="display:flex;align-items:center;gap:10px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="courseSearch" placeholder="Search subjects by name or course code..."
                   oninput="searchCourses()"
                   style="border:none;outline:none;font-size:14px;width:100%;background:transparent;color:#0f172a;">
        </div>
    </div>

    <!-- Render Each of the 9 Semesters -->
    <?php foreach ($semesters as $idx => $semName): ?>
        <?php $semCourses = $coursesBySemester[$semName]; ?>
        <div class="sem-section fade-up" id="sem-<?= $idx + 1 ?>">
            <div class="sem-header">
                <div class="sem-title-box">
                    <div class="sem-badge"><?= $idx + 1 ?></div>
                    <div>
                        <h2 style="font-size:16px;font-weight:800;color:#0f172a;margin:0;"><?= htmlspecialchars($semName) ?></h2>
                        <div style="font-size:12px;color:#64748b;margin-top:2px;">
                            Program: <strong>M.Tech AI&amp;DS</strong> &bull; <?= count($semCourses) ?> subject<?= count($semCourses) === 1 ? '' : 's' ?> configured
                        </div>
                    </div>
                </div>
                <div>
                    <a href="<?= BASE_URL ?>/admin/courses/add.php?program=M.Tech+AI%26DS&semester=<?= urlencode($semName) ?>" class="btn btn-outline btn-sm">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        + Add Subject to <?= htmlspecialchars($semName) ?>
                    </a>
                </div>
            </div>

            <div class="card" style="border-radius:0 0 16px 16px;border-top:none;">
                <?php if (empty($semCourses)): ?>
                    <div style="padding:36px;text-align:center;color:#94a3b8;">
                        <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 8px;display:block;opacity:.4"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                        No subjects added to <?= htmlspecialchars($semName) ?> yet.
                        <div style="margin-top:8px;">
                            <a href="<?= BASE_URL ?>/admin/courses/add.php?program=M.Tech+AI%26DS&semester=<?= urlencode($semName) ?>" style="color:#4f46e5;font-weight:600;font-size:13px;">
                                + Add first subject to <?= htmlspecialchars($semName) ?>
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <table class="dt course-table">
                        <thead>
                            <tr>
                                <th style="width:45px;">#</th>
                                <th>Subject / Paper Name</th>
                                <th>Course Code</th>
                                <th>Class Type</th>
                                <th>Base Honorarium</th>
                                <th>Faculty Assigned</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($semCourses as $sIdx => $c): ?>
                            <tr class="course-row" data-name="<?= strtolower(htmlspecialchars($c['subject_name'] . ' ' . $c['course_code'])) ?>">
                                <td style="color:#cbd5e1;font-size:12px;"><?= $sIdx + 1 ?></td>
                                <td style="font-weight:700;color:#0f172a;font-size:14px;">
                                    <?= htmlspecialchars($c['subject_name']) ?>
                                </td>
                                <td>
                                    <span style="font-family:monospace;font-size:12px;background:#f1f5f9;font-weight:700;color:#334155;padding:3px 9px;border-radius:6px;border:1px solid #e2e8f0;">
                                        <?= htmlspecialchars($c['course_code'] ?? '—') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($c['class_type'] === 'T'): ?>
                                        <span class="badge badge-blue">Theory Class</span>
                                    <?php else: ?>
                                        <span class="badge badge-amber">Practical / Lab</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-weight:700;color:#047857;">
                                    &#8377;<?= $c['class_type'] === 'T' ? 800 : 400 ?> / hr
                                </td>
                                <td>
                                    <span class="badge <?= $c['assigned_count'] > 0 ? 'badge-green' : 'badge-gray' ?>">
                                        <?= $c['assigned_count'] ?> faculty
                                    </span>
                                </td>
                                <td style="text-align:right;">
                                    <div style="display:inline-flex;gap:6px;">
                                        <a href="<?= BASE_URL ?>/admin/courses/edit.php?id=<?= $c['id'] ?>" class="btn btn-outline btn-sm" title="Edit Subject">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            Edit
                                        </a>
                                        <button type="button" class="btn btn-danger btn-sm"
                                            onclick="confirmDeleteCourse(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['subject_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars($c['course_code'] ?? '', ENT_QUOTES) ?>', <?= (int)$c['lecture_count'] ?>, '<?= htmlspecialchars(addslashes($semName), ENT_QUOTES) ?>')">
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
    <?php endforeach; ?>

    <!-- Other courses if any -->
    <?php if (!empty($otherCourses)): ?>
        <div class="sem-section fade-up" id="sem-other">
            <div class="sem-header">
                <div class="sem-title-box">
                    <div class="sem-badge" style="background:#64748b;">?</div>
                    <div>
                        <h2 style="font-size:16px;font-weight:800;color:#0f172a;margin:0;">Other / Elective Courses</h2>
                        <div style="font-size:12px;color:#64748b;margin-top:2px;"><?= count($otherCourses) ?> course(s)</div>
                    </div>
                </div>
            </div>
            <div class="card" style="border-radius:0 0 16px 16px;border-top:none;">
                <table class="dt course-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Subject</th>
                            <th>Semester</th>
                            <th>Code</th>
                            <th>Type</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($otherCourses as $i => $c): ?>
                        <tr class="course-row" data-name="<?= strtolower(htmlspecialchars($c['subject_name'] . ' ' . $c['course_code'])) ?>">
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($c['subject_name']) ?></td>
                            <td><?= htmlspecialchars($c['semester']) ?></td>
                            <td><?= htmlspecialchars($c['course_code'] ?? '—') ?></td>
                            <td><?= $c['class_type'] === 'T' ? 'Theory' : 'Practical' ?></td>
                            <td style="text-align:right;">
                                <a href="<?= BASE_URL ?>/admin/courses/edit.php?id=<?= $c['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                                <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteCourse(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['subject_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars($c['course_code'] ?? '', ENT_QUOTES) ?>', <?= (int)$c['lecture_count'] ?>, 'Other')">Delete</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>
</div>

<!-- Course Delete Confirmation Modal -->
<div id="deleteCourseModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,0.6);backdrop-filter:blur(5px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:20px;padding:32px;max-width:440px;width:90%;box-shadow:0 24px 64px rgba(0,0,0,0.22);animation:fadeUp .25s ease both;">
        <div style="width:58px;height:58px;border-radius:16px;background:#fef2f2;border:2px solid #fecaca;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <h2 style="font-size:18px;font-weight:800;color:#0f172a;text-align:center;margin:0 0 6px;">Delete Subject?</h2>
        <p style="font-size:13px;color:#64748b;text-align:center;margin:0 0 16px;">You are about to remove this subject from <span id="modal-csem" style="font-weight:700;color:#0f172a;"></span>:</p>
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
function filterSemester(targetId, btn) {
    // Update active tab button
    var buttons = document.querySelectorAll('.sem-tab-btn');
    buttons.forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');

    // Show/hide sections
    var sections = document.querySelectorAll('.sem-section');
    if (targetId === 'all') {
        sections.forEach(function(sec) { sec.style.display = 'block'; });
    } else {
        sections.forEach(function(sec) {
            if (sec.id === targetId) {
                sec.style.display = 'block';
            } else {
                sec.style.display = 'none';
            }
        });
    }
}

function searchCourses() {
    var q = document.getElementById('courseSearch').value.toLowerCase().trim();
    var rows = document.querySelectorAll('.course-row');
    rows.forEach(function(row) {
        var text = row.getAttribute('data-name') || '';
        if (!q || text.indexOf(q) !== -1) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function confirmDeleteCourse(id, name, code, lectureCount, semName) {
    document.getElementById('modal-cid').value = id;
    document.getElementById('modal-cname').textContent = name;
    document.getElementById('modal-ccode').textContent = code ? code : 'No Code';
    document.getElementById('modal-csem').textContent = semName;

    var warnDiv = document.getElementById('modal-warning');
    var submitBtn = document.getElementById('modal-submit-btn');

    if (lectureCount > 0) {
        warnDiv.style.background = '#fef2f2';
        warnDiv.style.borderColor = '#fecaca';
        warnDiv.style.color = '#991b1b';
        warnDiv.innerHTML = '<strong>Cannot Delete:</strong> ' + lectureCount + ' lecture session(s) are recorded under this subject. Historical attendance and remuneration data would be affected.';
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.4';
        submitBtn.style.cursor = 'not-allowed';
    } else {
        warnDiv.style.background = '#fffbeb';
        warnDiv.style.borderColor = '#fde68a';
        warnDiv.style.color = '#92400e';
        warnDiv.innerHTML = 'This subject has no recorded lectures. Deleting it will permanently remove it from ' + semName + '.';
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
