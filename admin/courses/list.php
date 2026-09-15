<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$flash = getFlash();

// Fetch all programs
$programs = $pdo->query("SELECT * FROM academic_programs ORDER BY id ASC")->fetchAll();
if (empty($programs)) {
    // Safety fallback
    $selectedProgram = ['id' => 0, 'program_name' => 'M.Tech AI&DS', 'batch_year' => '2022-2027', 'total_semesters' => 10];
} else {
    // Determine active program
    $reqProgName = trim($_GET['program'] ?? '');
    $reqProgId   = (int)($_GET['program_id'] ?? 0);
    $selectedProgram = $programs[0]; // default
    foreach ($programs as $p) {
        if (($reqProgName && strcasecmp($p['program_name'], $reqProgName) === 0) || ($reqProgId && (int)$p['id'] === $reqProgId)) {
            $selectedProgram = $p;
            break;
        }
    }
}

$progId   = (int)$selectedProgram['id'];
$progName = $selectedProgram['program_name'];
$batchYear= $selectedProgram['batch_year'];
$totalSem = (int)$selectedProgram['total_semesters'];

// Fetch courses for selected program
$stmt = $pdo->prepare("
    SELECT c.*, 
           COUNT(DISTINCT fca.id) AS assigned_count,
           COUNT(DISTINCT le.id)  AS lecture_count
    FROM courses c 
    LEFT JOIN faculty_course_assignments fca ON fca.course_id = c.id 
    LEFT JOIN lecture_entries le ON le.course_id = c.id
    WHERE (c.program_id = ? OR c.program = ?)
    GROUP BY c.id 
    ORDER BY c.semester_number ASC, c.semester ASC, c.subject_name ASC
");
$stmt->execute([$progId, $progName]);
$courses = $stmt->fetchAll();

// Build semesters array according to total_semesters
$semesters = [];
for ($i = 1; $i <= $totalSem; $i++) {
    $suffix = 'th';
    if ($i === 1) $suffix = 'st';
    elseif ($i === 2) $suffix = 'nd';
    elseif ($i === 3) $suffix = 'rd';
    $semesters[] = "{$i}{$suffix} Semester";
}

// Fetch semester year tags for this program
$tagStmt = $pdo->prepare("SELECT semester_number, year_tag FROM semester_tags WHERE program_id = ?");
$tagStmt->execute([$progId]);
$semesterYearTags = $tagStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Fetch student counts per semester for this program
$stCountStmt = $pdo->prepare("
    SELECT current_semester, COUNT(*) AS count
    FROM students
    WHERE program_id = ? AND status = 'active'
    GROUP BY current_semester
");
$stCountStmt->execute([$progId]);
$semStudentCounts = $stCountStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Group courses by semester
$coursesBySemester = [];
foreach ($semesters as $sem) {
    $coursesBySemester[$sem] = [];
}
$otherCourses = [];

foreach ($courses as $c) {
    $sem = trim($c['semester'] ?? '');
    if (isset($coursesBySemester[$sem])) {
        $coursesBySemester[$sem][] = $c;
    } else {
        $otherCourses[] = $c;
    }
}

$totalCoursesCount = count($courses);
$active_nav = 'courses-list';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($progName) ?> — SDSF Curriculum</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
<style>
.sem-year-badge {
    font-size: 11.5px;
    font-weight: 700;
    color: #4338ca;
    background: #eef2ff;
    padding: 3px 9px;
    border-radius: 6px;
    border: 1px solid #c7d2fe;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all .2s;
}
.btn-edit-tag {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    padding: 3px 8px;
    border-radius: 6px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all .15s ease;
}
.btn-edit-tag:hover {
    background: #e0e7ff;
    color: #4338ca;
    border-color: #a5b4fc;
}
.prog-pills-bar {
    display: flex;
    gap: 10px;
    overflow-x: auto;
    padding-bottom: 12px;
    margin-bottom: 22px;
    border-bottom: 1.5px solid #e2e8f0;
}
.prog-pill {
    padding: 10px 18px;
    border-radius: 12px;
    border: 1.5px solid #e2e8f0;
    background: #ffffff;
    color: #334155;
    font-size: 13.5px;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all .2s ease;
}
.prog-pill:hover {
    border-color: #c7d2fe;
    color: #4f46e5;
    background: #f8fafc;
}
.prog-pill.active {
    background: #4f46e5;
    color: #ffffff;
    border-color: #4f46e5;
    box-shadow: 0 6px 18px rgba(79,70,229,0.28);
}
.prog-pill .year-tag {
    font-size: 11px;
    padding: 2px 7px;
    border-radius: 6px;
    background: #f1f5f9;
    color: #475569;
}
.prog-pill.active .year-tag {
    background: rgba(255,255,255,0.25);
    color: #ffffff;
}

.sem-tabs {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    padding-bottom: 8px;
    margin-bottom: 24px;
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
    background: #0f172a;
    color: #ffffff;
    border-color: #0f172a;
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
.sem-section { margin-bottom: 28px; }
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
.sem-badge {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: linear-gradient(135deg, #4f46e5, #6366f1);
    color: #fff;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}
</style>
</head>
<body>
<header class="topbar">
    <div class="tb-left">
        <a href="<?= BASE_URL ?>/admin/dashboard.php" style="color:#94a3b8;text-decoration:none;">Dashboard</a>
        <span class="tb-sep">/</span>
        <span class="tb-crumb"><?= htmlspecialchars($progName) ?></span>
    </div>
    <div class="tb-right" style="display:flex;gap:10px;align-items:center;">
        <a href="<?= BASE_URL ?>/admin/courses/programs.php?from_prog=<?= urlencode($progName) ?>" class="btn btn-outline btn-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
            Manage Programs &amp; Batches
        </a>
        <a href="<?= BASE_URL ?>/admin/courses/add.php?program=<?= urlencode($progName) ?>" class="btn btn-primary btn-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            + Add New Subject
        </a>
    </div>
</header>

<div class="page">
    <div class="page-header fade-up">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
            <div>
                <h1><?= htmlspecialchars($progName) ?></h1>
                <p>Curriculum structure &bull; <?= $totalSem ?> Semesters &bull; <?= $totalCoursesCount ?> configured subjects</p>
            </div>
            <div style="display:flex;gap:10px;align-items:center;">
                <span class="badge badge-green" style="font-size:13px;padding:6px 14px;"><?= $totalSem ?> Semesters</span>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> fade-up"><?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>

    <!-- Program / Degree Selector Bar -->
    <div class="prog-pills-bar fade-up">
        <?php foreach ($programs as $prg): ?>
            <?php $isActive = ($prg['id'] == $progId); ?>
            <a href="<?= BASE_URL ?>/admin/courses/list.php?program=<?= urlencode($prg['program_name']) ?>"
               class="prog-pill <?= $isActive ? 'active' : '' ?>">
                <span><?= htmlspecialchars($prg['program_name']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Semester Navigation Tabs for this Program -->
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
            <input type="text" id="courseSearch" placeholder="Search subjects in <?= htmlspecialchars($progName) ?> by name or course code..."
                   oninput="searchCourses()"
                   style="border:none;outline:none;font-size:14px;width:100%;background:transparent;color:#0f172a;">
        </div>
    </div>

    <!-- Render Each Semester of this Program -->
    <?php foreach ($semesters as $idx => $semName): ?>
        <?php 
            $semCourses = $coursesBySemester[$semName]; 
            $semNum = $idx + 1;
            $semTag = $semesterYearTags[$semNum] ?? '';
        ?>
        <div class="sem-section fade-up" id="sem-<?= $semNum ?>">
            <div class="sem-header">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div class="sem-badge"><?= $semNum ?></div>
                    <div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <h2 style="font-size:16px;font-weight:800;color:#0f172a;margin:0;"><?= htmlspecialchars($semName) ?></h2>
                            <span class="sem-year-badge" id="sem-tag-badge-<?= $semNum ?>">
                                <?= !empty($semTag) ? htmlspecialchars($semTag) : 'No Tag Set' ?>
                            </span>
                            <button type="button" class="btn-edit-tag"
                                    onclick="openEditTagModal(<?= $progId ?>, <?= $semNum ?>, '<?= htmlspecialchars(addslashes($semName)) ?>', '<?= htmlspecialchars(addslashes($semTag)) ?>')"
                                    title="Edit Year Tag for this Semester">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Edit Tag
                            </button>
                        </div>
                        <div style="font-size:12px;color:#64748b;margin-top:2px;">
                            <?= count($semCourses) ?> subject<?= count($semCourses) === 1 ? '' : 's' ?> configured in this semester
                        </div>
                    </div>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <a href="<?= BASE_URL ?>/admin/students/index.php?program=<?= urlencode($progName) ?>&semester=<?= urlencode($semName) ?>" class="btn btn-outline btn-sm" style="background:#f8fafc;border-color:#cbd5e1;color:#1e293b;font-weight:700;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2.2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Student List (<?= (int)($semStudentCounts[$semName] ?? 0) ?>)
                    </a>
                    <a href="<?= BASE_URL ?>/admin/courses/add.php?program=<?= urlencode($progName) ?>&semester=<?= urlencode($semName) ?>" class="btn btn-outline btn-sm">
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
                            <a href="<?= BASE_URL ?>/admin/courses/add.php?program=<?= urlencode($progName) ?>&semester=<?= urlencode($semName) ?>" style="color:#4f46e5;font-weight:600;font-size:13px;">
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
                                <th>Course Code / ID</th>
                                <th style="font-weight:800;font-size:13px;letter-spacing:0.3px;">Credits (L T P)</th>
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
                                    <?php 
                                        $ltpText = !empty($c['ltp_pattern']) 
                                            ? $c['ltp_pattern'] 
                                            : (($c['credits'] ?? 4) . '(' . ($c['lecture_hours'] ?? 3) . '-' . ($c['tutorial_hours'] ?? 0) . '-' . ($c['practical_hours'] ?? 0) . ')');
                                    ?>
                                    <span style="font-family:'Segoe UI Mono', SFMono-Regular, Consolas, monospace;font-size:14px;font-weight:800;color:#0f172a;letter-spacing:0.5px;background:#f8fafc;padding:4px 10px;border-radius:7px;border:1.5px solid #cbd5e1;display:inline-block;">
                                        <?= htmlspecialchars($ltpText) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $c['assigned_count'] > 0 ? 'badge-green' : 'badge-gray' ?>">
                                        <?= $c['assigned_count'] ?> faculty
                                    </span>
                                </td>
                                <td style="text-align:right;">
                                    <div style="display:inline-flex;gap:6px;align-items:center;">
                                        <a href="<?= BASE_URL ?>/admin/students/index.php?program=<?= urlencode($progName) ?>&semester=<?= urlencode($semName) ?>&course_id=<?= $c['id'] ?>" class="btn btn-outline btn-sm" style="color:#0f766e;border-color:#99f6e4;background:#f0fdfa;" title="View Student List for this Subject">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                            Students
                                        </a>
                                        <a href="<?= BASE_URL ?>/admin/courses/edit.php?id=<?= $c['id'] ?>&from_prog=<?= urlencode($progName) ?>&from_sem=<?= $semNum ?>" class="btn btn-outline btn-sm" title="Edit Subject">
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
                <input type="hidden" name="return_url" id="modal-return-url" value="">
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
    var buttons = document.querySelectorAll('.sem-tab-btn');
    buttons.forEach(function(b) { b.classList.remove('active'); });
    if (btn) btn.classList.add('active');

    var sections = document.querySelectorAll('.sem-section');
    var currentUrl = new URL(window.location.href);
    if (targetId === 'all') {
        sections.forEach(function(sec) { sec.style.display = 'block'; });
        currentUrl.searchParams.delete('sem');
        currentUrl.hash = '';
        history.replaceState(null, '', currentUrl.toString());
    } else {
        sections.forEach(function(sec) {
            sec.style.display = (sec.id === targetId) ? 'block' : 'none';
        });
        var semNum = targetId.replace('sem-', '');
        currentUrl.searchParams.set('sem', semNum);
        currentUrl.hash = targetId;
        history.replaceState(null, '', currentUrl.toString());
    }
}

function searchCourses() {
    var q = document.getElementById('courseSearch').value.toLowerCase().trim();
    var rows = document.querySelectorAll('.course-row');
    rows.forEach(function(row) {
        var text = row.getAttribute('data-name') || '';
        row.style.display = (!q || text.indexOf(q) !== -1) ? '' : 'none';
    });
}

function confirmDeleteCourse(id, name, code, lectureCount, semName) {
    document.getElementById('modal-cid').value = id;
    document.getElementById('modal-cname').textContent = name;
    document.getElementById('modal-ccode').textContent = code ? code : 'No Code';
    document.getElementById('modal-csem').textContent = semName;

    // Ensure return_url keeps the active program, semester query, and hash
    var semMatch = semName ? semName.match(/\d+/) : null;
    var semNum = semMatch ? semMatch[0] : '';
    var returnUrl = new URL(window.location.href);
    if (semNum) {
        returnUrl.searchParams.set('sem', semNum);
        returnUrl.hash = 'sem-' + semNum;
    }
    document.getElementById('modal-return-url').value = returnUrl.toString();

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

document.addEventListener('DOMContentLoaded', function() {
    var params = new URLSearchParams(window.location.search);
    var semParam = params.get('sem');
    var hash = window.location.hash;
    var targetId = null;

    if (semParam) {
        targetId = 'sem-' + semParam;
    } else if (hash && hash.indexOf('sem-') !== -1) {
        targetId = hash.replace('#', '');
    }

    if (targetId) {
        var btn = document.querySelector('.sem-tab-btn[onclick*="\'' + targetId + '\'"]');
        if (btn) {
            filterSemester(targetId, btn);
        }
        var targetSec = document.getElementById(targetId);
        if (targetSec) {
            setTimeout(function() {
                targetSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 100);
        }
    }
});

function openEditTagModal(progId, semNum, semName, currentTag) {
    document.getElementById('tagModalProgId').value = progId;
    document.getElementById('tagModalSemNum').value = semNum;
    document.getElementById('tagModalTitle').textContent = `Edit Year Tag · ${semName}`;
    document.getElementById('tagModalInput').value = currentTag || '';
    document.getElementById('tagModalMsg').style.display = 'none';
    document.getElementById('tagModalSaveBtn').disabled = false;
    document.getElementById('tagModalSaveBtn').textContent = 'Save Year Tag';
    document.getElementById('editSemTagModal').style.display = 'flex';
    document.getElementById('tagModalInput').focus();
}

function closeEditTagModal() {
    document.getElementById('editSemTagModal').style.display = 'none';
}

function submitEditTag(e) {
    e.preventDefault();
    const progId = document.getElementById('tagModalProgId').value;
    const semNum = document.getElementById('tagModalSemNum').value;
    const newTag = document.getElementById('tagModalInput').value.trim();
    const saveBtn = document.getElementById('tagModalSaveBtn');
    const msgDiv = document.getElementById('tagModalMsg');

    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving...';

    const formData = new FormData();
    formData.append('program_id', progId);
    formData.append('semester_number', semNum);
    formData.append('year_tag', newTag);
    formData.append('ajax', '1');

    fetch('<?= BASE_URL ?>/admin/courses/update_semester_tag.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const badge = document.getElementById('sem-tag-badge-' + semNum);
            if (badge) {
                badge.textContent = newTag ? newTag : 'No Tag Set';
                badge.style.background = '#dcfce7';
                badge.style.color = '#15803d';
                badge.style.borderColor = '#86efac';
                setTimeout(() => {
                    badge.style.background = '';
                    badge.style.color = '';
                    badge.style.borderColor = '';
                }, 1800);
            }
            closeEditTagModal();
        } else {
            msgDiv.style.display = 'block';
            msgDiv.style.background = '#fef2f2';
            msgDiv.style.color = '#ef4444';
            msgDiv.textContent = data.message || 'Failed to update tag.';
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Year Tag';
        }
    })
    .catch(err => {
        msgDiv.style.display = 'block';
        msgDiv.style.background = '#fef2f2';
        msgDiv.style.color = '#ef4444';
        msgDiv.textContent = 'Network or server error: ' + err.message;
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save Year Tag';
    });
}

window.addEventListener('click', function(e) {
    const modal = document.getElementById('editSemTagModal');
    if (e.target === modal) {
        closeEditTagModal();
    }
});
</script>

<!-- Edit Semester Year Tag Modal -->
<div id="editSemTagModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:18px;max-width:440px;width:100%;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);overflow:hidden;">
        <div style="padding:18px 24px;border-bottom:1px solid #f1f5f9;background:#f8fafc;display:flex;align-items:center;justify-content:space-between;">
            <div>
                <h3 style="font-size:16px;font-weight:800;color:#0f172a;margin:0;" id="tagModalTitle">Edit Semester Year Tag</h3>
                <div style="font-size:12px;color:#64748b;margin-top:2px;"><?= htmlspecialchars($progName) ?></div>
            </div>
            <button type="button" onclick="closeEditTagModal()" style="background:none;border:none;cursor:pointer;color:#64748b;padding:4px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form id="tagEditForm" onsubmit="submitEditTag(event)" style="padding:22px 24px;">
            <input type="hidden" id="tagModalProgId" value="">
            <input type="hidden" id="tagModalSemNum" value="">

            <div class="form-group" style="margin-bottom:18px;">
                <label class="form-label" for="tagModalInput">Year Tag (e.g. 2022-2027, 2025-2027)</label>
                <input type="text" id="tagModalInput" class="form-input" placeholder="e.g. 2022-2027" required style="font-size:15px;font-weight:600;">
                <div style="font-size:12px;color:#64748b;margin-top:6px;">
                    This year tag will appear directly beside this semester on curriculum and reports.
                </div>
            </div>

            <div id="tagModalMsg" style="display:none;margin-bottom:14px;font-size:13px;padding:8px 12px;border-radius:8px;"></div>

            <div style="display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" class="btn btn-outline btn-sm" onclick="closeEditTagModal()">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm" id="tagModalSaveBtn">Save Year Tag</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
