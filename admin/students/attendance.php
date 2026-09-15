<?php
if (!defined('ROOT')) define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$programs = $pdo->query("SELECT * FROM academic_programs ORDER BY id ASC")->fetchAll();
$facultyMembers = $pdo->query("SELECT id, name, faculty_enrollment_no AS emp_code FROM faculty_members ORDER BY name ASC")->fetchAll();

$view = $_GET['view'] ?? 'cards'; // 'cards' (default) or 'logs'

// Program & Semester selection for Card View
$selectedProgId = (int)($_GET['prog_id'] ?? ($programs[0]['id'] ?? 1));
$selectedSem = trim($_GET['semester'] ?? 'all');

$currentProgram = null;
foreach ($programs as $p) {
    if ($p['id'] == $selectedProgId) {
        $currentProgram = $p;
        break;
    }
}
if (!$currentProgram && !empty($programs)) {
    $currentProgram = $programs[0];
    $selectedProgId = (int)$currentProgram['id'];
}

// Generate semester list for this program
$progTotalSems = (int)($currentProgram['total_semesters'] ?? 4);
$progSemList = [];
for ($i = 1; $i <= $progTotalSems; $i++) {
    $suffix = 'th';
    if ($i === 1) $suffix = 'st';
    elseif ($i === 2) $suffix = 'nd';
    elseif ($i === 3) $suffix = 'rd';
    $progSemList[] = "{$i}{$suffix} Semester";
}

// Query curriculum courses for the selected program and semester
$curriculumQuery = "
    SELECT c.*, ap.program_name, fm.name AS assigned_faculty, fm.faculty_enrollment_no
    FROM courses c
    LEFT JOIN academic_programs ap ON ap.id = c.program_id
    LEFT JOIN faculty_course_assignments fca ON fca.course_id = c.id
    LEFT JOIN faculty_members fm ON fm.id = fca.faculty_id
    WHERE c.program_id = ?
";
$cParams = [$selectedProgId];
if ($selectedSem !== 'all' && !empty($selectedSem)) {
    $curriculumQuery .= " AND (c.semester = ? OR c.semester_number = ?)";
    $sNum = (int)filter_var($selectedSem, FILTER_SANITIZE_NUMBER_INT);
    $cParams[] = $selectedSem;
    $cParams[] = $sNum;
}
$curriculumQuery .= " ORDER BY c.semester_number, c.semester, c.subject_name";
$cStmt = $pdo->prepare($curriculumQuery);
$cStmt->execute($cParams);
$curriculumCourses = $cStmt->fetchAll(PDO::FETCH_ASSOC);

// Pre-load cohort attendance matrix for each curriculum course
$adminCourseMatrices = [];
foreach ($curriculumCourses as $cc) {
    $pName = $cc['program_name'] ?? ($currentProgram['program_name'] ?? '');
    $matrix = getCohortAttendanceMatrix($pdo, $pName, $cc['semester'], (int)$cc['id']);
    $adminCourseMatrices[$cc['id']] = $matrix;
}

// Filters for session history logs view
$filterProgram = (int)($_GET['program_id'] ?? 0);
$filterFaculty = (int)($_GET['faculty_id'] ?? 0);
$filterMonth   = (int)($_GET['month'] ?? 0);
$filterYear    = (int)($_GET['year'] ?? 0);

$query = "
    SELECT le.*, c.subject_name, c.course_code, c.program, c.semester, c.batch_year, c.class_type,
           c.program_id, fm.name AS faculty_name, fm.faculty_enrollment_no AS emp_code,
           COUNT(sa.id) AS att_total,
           COALESCE(SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END), 0) AS att_present
    FROM lecture_entries le
    JOIN courses c ON c.id = le.course_id
    JOIN faculty_members fm ON fm.id = le.faculty_id
    LEFT JOIN student_attendance sa ON sa.lecture_id = le.id
    WHERE 1=1
";
$params = [];

if ($filterProgram > 0) {
    $query .= " AND c.program_id = ?";
    $params[] = $filterProgram;
}
if ($filterFaculty > 0) {
    $query .= " AND le.faculty_id = ?";
    $params[] = $filterFaculty;
}
if ($filterMonth > 0) {
    $query .= " AND MONTH(le.lecture_date) = ?";
    $params[] = $filterMonth;
}
if ($filterYear > 0) {
    $query .= " AND YEAR(le.lecture_date) = ?";
    $params[] = $filterYear;
}

$query .= " GROUP BY le.id, c.subject_name, c.course_code, c.program, c.semester, c.batch_year, c.class_type, c.program_id, fm.name, fm.faculty_enrollment_no
           ORDER BY le.lecture_date DESC, le.id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$lectures = $stmt->fetchAll();

// Overall metrics
$totalSessions = count($lectures);
$totalStudentsMarked = 0;
$totalPresent = 0;
foreach ($lectures as $l) {
    $totalStudentsMarked += (int)$l['att_total'];
    $totalPresent += (int)$l['att_present'];
}
$avgRate = ($totalStudentsMarked > 0) ? round(($totalPresent / $totalStudentsMarked) * 100, 1) : 0;

$active_nav = 'students-attendance';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Attendance Logs — SDSF Admin</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
</head>
<body>
    <header class="topbar">
        <div class="tb-left">
            <span class="tb-crumb">Students</span>
            <span class="tb-sep">/</span>
            <span class="tb-crumb">Attendance Logs</span>
        </div>
        <div class="tb-right">
            <span class="tb-date"><?= date('l, d F Y') ?></span>
            <a href="<?= BASE_URL ?>/admin/students/index.php" class="btn btn-outline btn-sm">
                Student Rosters &rarr;
            </a>
        </div>
    </header>

    <div class="page">
        <div class="page-header">
            <h1>Student Attendance Logs</h1>
            <p>Comprehensive record of all student attendances marked by faculty members during lecture sessions.</p>
        </div>

        <!-- View Mode Navigation Tabs -->
        <div style="display:flex;gap:10px;margin-bottom:24px;border-bottom:2px solid #e2e8f0;padding-bottom:12px;flex-wrap:wrap;">
            <a href="?view=cards&prog_id=<?= $selectedProgId ?>&semester=<?= urlencode($selectedSem) ?>" class="btn <?= $view === 'cards' ? 'btn-primary' : 'btn-outline' ?>" style="font-weight:700;display:inline-flex;align-items:center;gap:7px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                Course &amp; Semester Attendance Cards (Database Tables)
            </a>
            <a href="?view=logs" class="btn <?= $view === 'logs' ? 'btn-primary' : 'btn-outline' ?>" style="font-weight:700;display:inline-flex;align-items:center;gap:7px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                Submitted Lecture Session History Logs
            </a>
        </div>

        <?php if ($view === 'cards'): ?>
            <!-- ============================================================== -->
            <!-- VIEW 1: COURSE-WISE & SEMESTER-WISE DATABASE TABLE CARDS       -->
            <!-- ============================================================== -->
            <!-- Program Selector Pills -->
            <div class="fade-up" style="display:flex;gap:10px;margin-bottom:14px;overflow-x:auto;padding-bottom:6px;flex-wrap:wrap;">
                <?php foreach ($programs as $prg): ?>
                    <?php $isActive = ($prg['id'] == $selectedProgId); ?>
                    <a href="?view=cards&prog_id=<?= $prg['id'] ?>&semester=<?= urlencode($selectedSem) ?>"
                       class="btn <?= $isActive ? 'btn-primary' : 'btn-outline' ?>"
                       style="font-size:13px;padding:7px 18px;border-radius:20px;font-weight:700;">
                        <?= htmlspecialchars($prg['program_name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Semester Pills -->
            <div class="fade-up" style="display:flex;gap:8px;margin-bottom:24px;overflow-x:auto;padding-bottom:6px;flex-wrap:wrap;">
                <a href="?view=cards&prog_id=<?= $selectedProgId ?>&semester=all"
                   class="btn btn-sm <?= ($selectedSem === 'all') ? 'btn-primary' : 'btn-outline' ?>" style="font-weight:700;">
                    All Semesters
                </a>
                <?php foreach ($progSemList as $sName): 
                    $isSemActive = ($selectedSem === $sName);
                ?>
                    <a href="?view=cards&prog_id=<?= $selectedProgId ?>&semester=<?= urlencode($sName) ?>"
                       class="btn btn-sm <?= $isSemActive ? 'btn-primary' : 'btn-outline' ?>" style="font-weight:700;">
                        <?= htmlspecialchars($sName) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Cards List for Curriculum Courses -->
            <?php if (empty($curriculumCourses)): ?>
                <div class="card fade-up" style="padding:48px 20px;text-align:center;color:#94a3b8;">
                    No subjects found for this program and semester filter.
                </div>
            <?php else: ?>
                <?php foreach ($curriculumCourses as $cc): 
                    $mat = $adminCourseMatrices[$cc['id']] ?? null;
                    $cStudents = $mat['students'] ?? [];
                    $attCols = $mat['attendance_cols'] ?? [];
                    $tblName = $mat['table_name'] ?? getCohortStudentTable($cc['program_name'] ?? ($currentProgram['program_name'] ?? ''), $cc['semester']);
                    $avgPct  = $mat['avg_attendance'] ?? 0;
                ?>
                <div class="card fade-up" style="margin-bottom:26px;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,0.03);" id="admin-card-<?= $cc['id'] ?>">
                    <!-- Card Header -->
                    <div style="background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:18px 22px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;">
                        <div style="display:flex;align-items:center;gap:14px;">
                            <div style="width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg, #1e3a8a, #3b82f6);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;">
                                <?= htmlspecialchars(substr($cc['course_code'] ?? 'CS', 0, 4)) ?>
                            </div>
                            <div>
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <h3 style="font-size:17px;font-weight:800;color:#0f172a;margin:0;">
                                        <?= htmlspecialchars($cc['subject_name']) ?>
                                    </h3>
                                    <code style="background:#eff6ff;color:#1e40af;padding:2px 8px;border-radius:6px;font-size:12px;font-weight:700;border:1px solid #bfdbfe;">
                                        <?= htmlspecialchars($cc['course_code'] ?? '—') ?>
                                    </code>
                                    <span style="background:<?= $cc['class_type'] === 'P' ? '#f0fdf4' : '#f8fafc' ?>;color:<?= $cc['class_type'] === 'P' ? '#166534' : '#475569' ?>;border:1px solid <?= $cc['class_type'] === 'P' ? '#bbf7d0' : '#e2e8f0' ?>;padding:1px 8px;border-radius:6px;font-size:11.5px;font-weight:700;">
                                        <?= $cc['class_type'] === 'P' ? 'Practical' : 'Theory' ?>
                                    </span>
                                </div>
                                <div style="font-size:12.5px;color:#64748b;margin-top:4px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <span style="font-weight:700;color:#334155;"><?= htmlspecialchars($cc['program_name'] ?? $currentProgram['program_name']) ?></span>
                                    &bull;
                                    <span style="background:#f1f5f9;color:#1e293b;font-weight:800;padding:1px 8px;border-radius:6px;border:1px solid #cbd5e1;">
                                        <?= htmlspecialchars($cc['semester']) ?>
                                    </span>
                                    &bull;
                                    <span>Faculty: <strong><?= htmlspecialchars($cc['assigned_faculty'] ?: 'Unassigned') ?></strong></span>
                                    &bull;
                                    <span>Database Table: <code style="color:#1e3a8a;font-weight:700;background:#eff6ff;padding:1px 6px;border-radius:4px;border:1px solid #bfdbfe;"><?= htmlspecialchars($tblName) ?></code></span>
                                </div>
                            </div>
                        </div>

                        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                            <span class="badge badge-blue" style="font-size:12px;padding:5px 12px;">
                                <?= count($cStudents) ?> Students
                            </span>
                            <span class="badge badge-green" style="font-size:12px;padding:5px 12px;">
                                <?= count($attCols) ?> Sessions Marked
                            </span>
                            <span class="badge <?= $avgPct >= 75 ? 'badge-green' : ($avgPct >= 50 ? 'badge-yellow' : 'badge-red') ?>" style="font-size:12px;padding:5px 12px;">
                                Avg: <?= $avgPct ?>%
                            </span>
                            <a href="<?= BASE_URL ?>/admin/courses/list.php?program=<?= urlencode($cc['program_name'] ?? ($currentProgram['program_name'] ?? '')) ?>" class="btn btn-outline btn-sm" style="font-size:12px;padding:5px 12px;">
                                View in Curriculum
                            </a>
                        </div>
                    </div>

                    <!-- Search Filter per Card -->
                    <div style="padding:10px 20px;background:#ffffff;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                        <div style="display:flex;align-items:center;gap:8px;flex:1;max-width:360px;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text" placeholder="Search student in this table by roll or name..." 
                                   oninput="filterAdminCardStudents(this, 'admin-table-course-<?= $cc['id'] ?>')"
                                   style="font-size:12.5px;border:none;outline:none;width:100%;color:#0f172a;background:transparent;">
                        </div>
                        <div style="font-size:11.5px;color:#64748b;font-weight:600;">
                            Legend: <span class="badge badge-green" style="font-size:10px;padding:1px 6px;">1</span> = Present &bull; <span class="badge badge-red" style="font-size:10px;padding:1px 6px;">0</span> = Absent &bull; <span style="color:#94a3b8;">—</span> = Unmarked
                        </div>
                    </div>

                    <!-- Exact Database Table Matrix Body -->
                    <?php if (empty($cStudents)): ?>
                        <div style="padding:36px 20px;text-align:center;color:#94a3b8;">
                            No students enrolled in this cohort yet.
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;max-height:480px;">
                            <table class="dt" id="admin-table-course-<?= $cc['id'] ?>" style="margin:0;font-size:13px;width:100%;">
                                <thead>
                                    <tr style="background:#f8fafc;position:sticky;top:0;z-index:2;box-shadow:0 1px 2px rgba(0,0,0,0.04);">
                                        <th style="width:40px;">#</th>
                                        <th style="width:85px;">Roll No</th>
                                        <th style="min-width:160px;">Student Name</th>
                                        <th style="width:130px;">Enrollment No</th>
                                        <?php if (empty($attCols)): ?>
                                            <th style="text-align:center;color:#94a3b8;font-style:italic;min-width:240px;">
                                                No attendance dates recorded yet
                                            </th>
                                        <?php else: ?>
                                            <?php foreach ($attCols as $col): 
                                                $d = str_replace('att_', '', $col);
                                                $dClean = preg_replace('/^c\d+_/', '', $d);
                                                $dClean = preg_replace('/_c\d+$/', '', $dClean);
                                                $isSession2 = str_contains($dClean, '_s2');
                                                $dClean = preg_replace('/_s\d+$/', '', $dClean);
                                                $parts = explode('_', $dClean);
                                                $label = (count($parts) >= 3) ? ($parts[2] . '/' . $parts[1] . '/' . substr($parts[0], 2)) : $dClean;
                                                if ($isSession2) $label .= ' (S2)';
                                            ?>
                                                <th style="width:80px;text-align:center;background:#f0f9ff;border-left:1px solid #e0f2fe;" title="Session Date: <?= htmlspecialchars($dClean) ?> (Column: <?= htmlspecialchars($col) ?>)">
                                                    <div style="font-size:11px;font-weight:800;color:#0369a1;"><?= htmlspecialchars($label) ?></div>
                                                    <div style="font-size:9px;color:#0284c7;font-weight:700;">(0 or 1)</div>
                                                </th>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        <th style="width:85px;text-align:center;background:#f8fafc;border-left:2px solid #e2e8f0;">Present</th>
                                        <th style="width:75px;text-align:center;background:#f8fafc;">%</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cStudents as $sIdx => $st): ?>
                                    <tr class="student-matrix-row">
                                        <td style="color:#94a3b8;font-size:12px;"><?= $sIdx + 1 ?></td>
                                        <td>
                                            <span class="roll-badge" style="width:30px;height:30px;border-radius:7px;background:#eef2ff;color:#4338ca;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;font-family:monospace;"><?= htmlspecialchars($st['roll_no']) ?></span>
                                        </td>
                                        <td>
                                            <div style="font-weight:700;color:#0f172a;" class="st-name-text">
                                                <?= htmlspecialchars($st['student_name']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span style="font-family:monospace;font-size:12px;color:#475569;background:#f8fafc;padding:2px 6px;border-radius:4px;border:1px solid #e2e8f0;" class="st-enroll-text">
                                                <?= htmlspecialchars($st['enrollment_no'] ?: '—') ?>
                                            </span>
                                        </td>
                                        <?php if (empty($attCols)): ?>
                                            <td style="text-align:center;color:#94a3b8;font-size:12px;">
                                                —
                                            </td>
                                        <?php else: ?>
                                            <?php foreach ($attCols as $col): 
                                                $val = $st[$col] ?? null;
                                            ?>
                                                <td style="text-align:center;background:#ffffff;border-left:1px solid #f1f5f9;">
                                                    <?php if ($val === 1 || $val === '1'): ?>
                                                        <span class="badge badge-green" style="font-size:11px;font-weight:800;padding:2px 8px;" title="Present (1)">1</span>
                                                    <?php elseif ($val === 0 || $val === '0'): ?>
                                                        <span class="badge badge-red" style="font-size:11px;font-weight:800;padding:2px 8px;" title="Absent (0)">0</span>
                                                    <?php else: ?>
                                                        <span style="color:#cbd5e1;font-weight:600;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        <td style="text-align:center;font-weight:800;color:#0f172a;border-left:2px solid #e2e8f0;background:#f8fafc;">
                                            <?= $st['present_count'] ?> / <?= $st['total_classes'] ?>
                                        </td>
                                        <td style="text-align:center;background:#f8fafc;">
                                            <span class="badge <?= $st['attendance_pct'] >= 75 ? 'badge-green' : ($st['attendance_pct'] >= 50 ? 'badge-yellow' : 'badge-red') ?>" style="font-size:11px;font-weight:800;padding:2px 8px;">
                                                <?= $st['attendance_pct'] ?>%
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php else: ?>
            <!-- ============================================================== -->
            <!-- VIEW 2: SUBMITTED LECTURE SESSION LOGS                         -->
            <!-- ============================================================== -->
            <!-- Metric Summary Cards -->
            <div class="grid-3 fade-up" style="margin-bottom:24px;">
                <div class="card" style="padding:20px;">
                    <div style="font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Sessions Conducted</div>
                    <div style="font-size:26px;font-weight:800;color:#0f172a;margin-top:4px;"><?= $totalSessions ?></div>
                    <div style="font-size:12.5px;color:#64748b;margin-top:4px;">Across all filtered lectures</div>
                </div>

                <div class="card" style="padding:20px;">
                    <div style="font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Average Attendance Rate</div>
                    <div style="font-size:26px;font-weight:800;color:#047857;margin-top:4px;"><?= $avgRate ?>%</div>
                    <div style="font-size:12.5px;color:#64748b;margin-top:4px;"><?= $totalPresent ?> / <?= $totalStudentsMarked ?> attendances</div>
                </div>

                <div class="card" style="padding:20px;">
                    <div style="font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Total Student Records</div>
                    <div style="font-size:26px;font-weight:800;color:#4f46e5;margin-top:4px;"><?= $totalStudentsMarked ?></div>
                    <div style="font-size:12.5px;color:#64748b;margin-top:4px;">Student-session check-ins</div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="card fade-up" style="margin-bottom:24px;padding:18px 22px;">
                <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:14px;">
                    <input type="hidden" name="view" value="logs">
                    <div style="flex:1;min-width:180px;">
                        <label class="form-label" for="program_id">Academic Program</label>
                        <select name="program_id" id="program_id" class="form-select">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= ($filterProgram == $p['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['program_name']) ?> (<?= htmlspecialchars($p['batch_year']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="flex:1;min-width:180px;">
                        <label class="form-label" for="faculty_id">Faculty Teacher</label>
                        <select name="faculty_id" id="faculty_id" class="form-select">
                            <option value="">All Faculty Members</option>
                            <?php foreach ($facultyMembers as $fm): ?>
                                <option value="<?= $fm['id'] ?>" <?= ($filterFaculty == $fm['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($fm['name']) ?> (<?= htmlspecialchars($fm['emp_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="width:130px;">
                        <label class="form-label" for="month">Month</label>
                        <select name="month" id="month" class="form-select">
                            <option value="">All Months</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= ($filterMonth == $m) ? 'selected' : '' ?>>
                                    <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div style="width:110px;">
                        <label class="form-label" for="year">Year</label>
                        <select name="year" id="year" class="form-select">
                            <option value="">All Years</option>
                            <?php for ($y = date('Y'); $y >= 2022; $y--): ?>
                                <option value="<?= $y ?>" <?= ($filterYear == $y) ? 'selected' : '' ?>>
                                    <?= $y ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div style="display:flex;gap:8px;">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <a href="<?= BASE_URL ?>/admin/students/attendance.php?view=logs" class="btn btn-outline">Reset</a>
                    </div>
                </form>
            </div>

            <!-- Lectures Attendance Table -->
            <div class="card fade-up">
                <div class="card-head">
                    <div>
                        <div class="card-title">Recorded Lecture Sessions with Attendance</div>
                        <div class="card-sub">Showing <?= count($lectures) ?> session<?= count($lectures) === 1 ? '' : 's' ?></div>
                    </div>
                </div>

                <?php if (empty($lectures)): ?>
                    <div style="padding:60px 20px;text-align:center;color:#94a3b8;">
                        No lecture sessions match your selected filter criteria.
                    </div>
                <?php else: ?>
                <table class="dt">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Faculty Teacher</th>
                            <th>Subject &amp; Code</th>
                            <th>Program &amp; Semester</th>
                            <th>Class Type</th>
                            <th>Duration</th>
                            <th>Attendance</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lectures as $l): 
                            $tot = (int)$l['att_total'];
                            $pres = (int)$l['att_present'];
                            $pct = ($tot > 0) ? round(($pres / $tot) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td style="font-weight:700;color:#0f172a;white-space:nowrap;">
                                <?= date('d M Y', strtotime($l['lecture_date'])) ?>
                            </td>
                            <td>
                                <div style="font-weight:700;color:#0f172a;"><?= htmlspecialchars($l['faculty_name']) ?></div>
                                <div style="font-size:11.5px;color:#64748b;"><?= htmlspecialchars($l['emp_code']) ?></div>
                            </td>
                            <td>
                                <div style="font-weight:600;color:#334155;"><?= htmlspecialchars($l['subject_name']) ?></div>
                                <div style="font-size:11.5px;font-family:monospace;color:#94a3b8;"><?= htmlspecialchars($l['course_code']) ?></div>
                            </td>
                            <td>
                                <div style="font-size:13px;font-weight:600;color:#475569;"><?= htmlspecialchars($l['program']) ?></div>
                                <div style="font-size:11.5px;color:#6366f1;font-weight:700;">Sem <?= htmlspecialchars($l['semester']) ?> &bull; <?= htmlspecialchars($l['batch_year'] ?? '') ?></div>
                            </td>
                            <td>
                                <?php if ($l['class_type'] === 'T'): ?>
                                    <span class="badge badge-blue">Theory</span>
                                <?php else: ?>
                                    <span class="badge badge-amber">Practical</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:600;"><?= (float)$l['hours'] ?> hrs</td>
                            <td>
                                <?php if ($tot > 0): ?>
                                    <div style="display:inline-flex;align-items:center;gap:6px;">
                                        <span class="badge badge-green" style="font-size:12px;padding:3px 8px;">
                                            <?= $pres ?> / <?= $tot ?> Present (<?= $pct ?>%)
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <span style="font-size:12px;color:#94a3b8;font-style:italic;">No students marked</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;">
                                <?php if ($tot > 0): ?>
                                    <button type="button" class="btn btn-outline btn-sm" onclick="viewAttendance(<?= $l['id'] ?>)">
                                        View Sheet &rarr;
                                    </button>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php endif; // end view mode cards vs logs ?>
    </div>
</div>

<!-- Attendance Sheet Modal -->
<div id="attModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:20px;max-width:650px;width:100%;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);overflow:hidden;">
        <div style="padding:20px 24px;border-bottom:1.5px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;">
            <div>
                <h3 style="font-size:16px;font-weight:800;color:#0f172a;margin:0;" id="modalSubject">Attendance Sheet</h3>
                <div style="font-size:12.5px;color:#64748b;margin-top:2px;" id="modalSubtitle">Details</div>
            </div>
            <button type="button" onclick="closeAttModal()" style="background:transparent;border:none;cursor:pointer;color:#64748b;padding:4px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <div style="padding:14px 24px;background:#eef2ff;border-bottom:1px solid #e0e7ff;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
            <div style="display:flex;gap:10px;font-size:13px;font-weight:600;">
                <span style="color:#475569;">Total: <strong id="modalTotal" style="color:#0f172a;">0</strong></span>
                <span style="color:#047857;">&#10003; Present: <strong id="modalPresent">0</strong></span>
                <span style="color:#e11d48;">&#10007; Absent: <strong id="modalAbsent">0</strong></span>
            </div>
            <div style="font-size:13px;font-weight:700;color:#4338ca;" id="modalRate">0% Attendance</div>
        </div>

        <div style="flex:1;overflow-y:auto;padding:16px 24px;" id="modalContent">
            <!-- Student Rows Injected Here -->
        </div>

        <div style="padding:14px 24px;border-top:1.5px solid #f1f5f9;text-align:right;background:#f8fafc;">
            <button type="button" class="btn btn-outline" onclick="closeAttModal()" style="padding:8px 18px;">Close Sheet</button>
        </div>
    </div>
</div>

<script>
function filterAdminCardStudents(input, tableId) {
    const q = input.value.toLowerCase().trim();
    const table = document.getElementById(tableId);
    if (!table) return;
    const rows = table.querySelectorAll('tbody tr.student-matrix-row');
    rows.forEach(r => {
        const name = r.querySelector('.st-name-text')?.textContent.toLowerCase() || '';
        const roll = r.querySelector('.roll-badge')?.textContent.toLowerCase() || '';
        const enroll = r.querySelector('.st-enroll-text')?.textContent.toLowerCase() || '';
        if (!q || name.includes(q) || roll.includes(q) || enroll.includes(q)) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
    });
}
function viewAttendance(lectureId) {
    const modal = document.getElementById('attModal');
    const content = document.getElementById('modalContent');
    content.innerHTML = '<div style="padding:30px;text-align:center;color:#64748b;">Loading student attendance sheet...</div>';
    modal.style.display = 'flex';

    fetch(`<?= BASE_URL ?>/api/get_lecture_attendance.php?lecture_id=${lectureId}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                content.innerHTML = `<div style="color:#ef4444;padding:20px;text-align:center;">${data.message || 'Error loading attendance.'}</div>`;
                return;
            }

            document.getElementById('modalSubject').textContent = `${data.lecture.subject_name} (${data.lecture.course_code})`;
            document.getElementById('modalSubtitle').textContent = `${data.lecture.faculty_name} &bull; ${data.lecture.program} &bull; ${data.lecture.semester} &bull; ${data.lecture.formatted_date}`;
            document.getElementById('modalTotal').textContent = data.summary.total;
            document.getElementById('modalPresent').textContent = data.summary.present;
            document.getElementById('modalAbsent').textContent = data.summary.absent;
            document.getElementById('modalRate').textContent = `${data.summary.rate}% Attendance Rate`;

            if (data.students.length === 0) {
                content.innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8;">No student attendance was recorded for this session.</div>';
                return;
            }

            let html = `
                <table style="width:100%;border-collapse:collapse;font-size:13.5px;">
                    <thead>
                        <tr style="border-bottom:1.5px solid #e2e8f0;color:#64748b;font-size:11px;text-transform:uppercase;text-align:left;">
                            <th style="padding:8px 12px;width:60px;">Roll</th>
                            <th style="padding:8px 12px;width:130px;">Enrollment</th>
                            <th style="padding:8px 12px;">Student Name</th>
                            <th style="padding:8px 12px;text-align:right;width:100px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            data.students.forEach(st => {
                const isPres = st.status === 'present';
                const badgeStyle = isPres 
                    ? 'background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;' 
                    : 'background:#fff1f2;color:#e11d48;border:1px solid #fecdd3;';
                const badgeText = isPres ? '&#10003; Present' : '&#10007; Absent';

                html += `
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:10px 12px;font-weight:700;color:#4338ca;">${st.roll_no}</td>
                        <td style="padding:10px 12px;font-family:monospace;color:#64748b;font-size:12px;">${st.enrollment_no}</td>
                        <td style="padding:10px 12px;font-weight:600;color:#0f172a;">${st.student_name}</td>
                        <td style="padding:10px 12px;text-align:right;">
                            <span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:11.5px;font-weight:700;${badgeStyle}">
                                ${badgeText}
                            </span>
                        </td>
                    </tr>
                `;
            });

            html += `</tbody></table>`;
            content.innerHTML = html;
        })
        .catch(err => {
            content.innerHTML = `<div style="color:#ef4444;padding:20px;text-align:center;">Failed to load attendance: ${err.message}</div>`;
        });
}

function closeAttModal() {
    document.getElementById('attModal').style.display = 'none';
}

window.addEventListener('click', e => {
    const modal = document.getElementById('attModal');
    if (e.target === modal) {
        closeAttModal();
    }
});
</script>
</body>
</html>
