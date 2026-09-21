<?php
define('ROOT', dirname(dirname(__FILE__)));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireFaculty();

$facultyId   = getFacultyId() ?? (int)($_SESSION['faculty_id'] ?? 0);
$facultyName = $_SESSION['faculty_name'] ?? 'Faculty Member';

// ── Handle DELETE ATTENDANCE COLUMN (AJAX JSON) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_att_col') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    $delCourseId  = (int)($_POST['course_id']  ?? 0);
    $delColName   = trim($_POST['col_name']   ?? '');
    $delTableName = trim($_POST['table_name'] ?? '');

    // Validate inputs
    if (!$delCourseId || $delColName === '' || $delTableName === '') {
        echo json_encode(['success' => false, 'message' => 'Missing parameters.']); exit;
    }
    if (!preg_match('/^att_[a-z0-9_]+$/i', $delColName)) {
        echo json_encode(['success' => false, 'message' => 'Invalid column name.']); exit;
    }
    if (!preg_match('/^students_[a-z0-9_]+$/i', $delTableName)) {
        echo json_encode(['success' => false, 'message' => 'Invalid table name.']); exit;
    }

    // Verify faculty owns the course
    try {
        $ownChk = $pdo->prepare("SELECT id FROM courses c JOIN faculty_course_assignments fca ON fca.course_id = c.id WHERE c.id = ? AND fca.faculty_id = ? LIMIT 1");
        $ownChk->execute([$delCourseId, $facultyId]);
        if (!$ownChk->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'Permission denied.']); exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]); exit;
    }

    // Verify column exists
    try {
        if (!$pdo->query("SHOW COLUMNS FROM `{$delTableName}` LIKE '{$delColName}'")->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'Column not found.']); exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Column check error: ' . $e->getMessage()]); exit;
    }

    // Decode date from column name: att_c{id}_YYYY_MM_DD[_s2]  or  att_YYYY_MM_DD
    $stripped = preg_replace('/^att_c\d+_/', '', $delColName);
    $stripped = preg_replace('/^att_/', '', $stripped);
    $stripped = preg_replace('/_s\d+$/', '', $stripped);
    $dp = explode('_', $stripped);
    $delDate = (count($dp) === 3) ? "{$dp[0]}-{$dp[1]}-{$dp[2]}" : null;

    try {
        $delLecCount = 0;
        if ($delDate) {
            $lStmt = $pdo->prepare("SELECT id FROM lecture_entries WHERE course_id = ? AND lecture_date = ?");
            $lStmt->execute([$delCourseId, $delDate]);
            $lIds = $lStmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($lIds)) {
                $ph = implode(',', array_fill(0, count($lIds), '?'));
                $pdo->prepare("DELETE FROM student_attendance WHERE lecture_id IN ({$ph})")->execute($lIds);
                $pdo->prepare("DELETE FROM lecture_entries WHERE id IN ({$ph})")->execute($lIds);
                $delLecCount = count($lIds);
            }
        }
        // DDL — implicit MySQL commit; run after all DML
        $pdo->exec("ALTER TABLE `{$delTableName}` DROP COLUMN `{$delColName}`");
        echo json_encode(['success' => true, 'message' => 'Deleted.', 'deleted_lectures' => $delLecCount]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Delete error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle attendance update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_attendance') {
    $lectureId = (int)($_POST['lecture_id'] ?? 0);
    $attUpdates = $_POST['attendance'] ?? [];

    // Verify lecture belongs to this faculty and fetch course info
    $vStmt = $pdo->prepare("
        SELECT le.id, le.lecture_date, le.course_id, c.program, c.semester, ap.program_name
        FROM lecture_entries le
        JOIN courses c ON c.id = le.course_id
        LEFT JOIN academic_programs ap ON ap.id = c.program_id
        WHERE le.id = ? AND le.faculty_id = ?
    ");
    $vStmt->execute([$lectureId, $facultyId]);
    $lec = $vStmt->fetch(PDO::FETCH_ASSOC);

    if ($lec && is_array($attUpdates) && !empty($attUpdates)) {
        try {
            $updStmt = $pdo->prepare("
                INSERT INTO student_attendance (lecture_id, student_id, attendance_date, status)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status)
            ");
            foreach ($attUpdates as $stId => $status) {
                $status = ($status === 'absent') ? 'absent' : 'present';
                $updStmt->execute([$lectureId, (int)$stId, $lec['lecture_date'], $status]);
            }

            // Record into dedicated physical cohort table as dynamic date column with 1 and 0
            $progName = $lec['program_name'] ?? $lec['program'];
            recordCohortAttendance($pdo, $progName, $lec['semester'], $lec['lecture_date'], $attUpdates, $lectureId, (int)$lec['course_id']);

            setFlash('success', 'Attendance record for this lecture has been successfully updated!');
        } catch (PDOException $e) {
            setFlash('error', 'Could not update attendance: ' . $e->getMessage());
        }
    }
    header('Location: ' . BASE_URL . '/faculty/attendance.php');
    exit;
}


// Fetch assigned courses with full program details
$cStmt = $pdo->prepare("
    SELECT c.id, c.subject_name, c.course_code, c.program, c.semester, c.batch_year, c.class_type,
           ap.program_name
    FROM courses c
    JOIN faculty_course_assignments fca ON fca.course_id = c.id
    LEFT JOIN academic_programs ap ON ap.id = c.program_id
    WHERE fca.faculty_id = ?
    ORDER BY c.program, c.semester_number, c.semester, c.subject_name
");
$cStmt->execute([$facultyId]);
$assignedCourses = $cStmt->fetchAll(PDO::FETCH_ASSOC);

// Pre-load dedicated database table attendance matrix for each assigned course
$courseMatrices = [];
$totalStudentsAcrossCourses = 0;
$totalSessionsAcrossCourses = 0;
foreach ($assignedCourses as $ac) {
    $pName = $ac['program_name'] ?? $ac['program'];
    $matrix = getCohortAttendanceMatrix($pdo, $pName, $ac['semester'], (int)$ac['id']);
    $courseMatrices[$ac['id']] = $matrix;
    if ($matrix) {
        $totalStudentsAcrossCourses += (int)$matrix['total_students'];
        $totalSessionsAcrossCourses += (int)$matrix['total_sessions'];
    }
}

$view = $_GET['view'] ?? 'cards';

// Filters for session history logs view
$filterCourse = (int)($_GET['course_id'] ?? 0);
$filterMonth  = (int)($_GET['month'] ?? 0);
$filterYear   = (int)($_GET['year'] ?? 0);

$query = "
    SELECT le.*, c.subject_name, c.course_code, c.program, c.semester, c.batch_year, c.class_type,
           COUNT(sa.id) AS att_total,
           COALESCE(SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END), 0) AS att_present
    FROM lecture_entries le
    JOIN courses c ON c.id = le.course_id
    LEFT JOIN student_attendance sa ON sa.lecture_id = le.id
    WHERE le.faculty_id = ?
";
$params = [$facultyId];

if ($filterCourse > 0) {
    $query .= " AND le.course_id = ?";
    $params[] = $filterCourse;
}
if ($filterMonth > 0) {
    $query .= " AND MONTH(le.lecture_date) = ?";
    $params[] = $filterMonth;
}
if ($filterYear > 0) {
    $query .= " AND YEAR(le.lecture_date) = ?";
    $params[] = $filterYear;
}

$query .= " GROUP BY le.id, c.subject_name, c.course_code, c.program, c.semester, c.batch_year, c.class_type
            ORDER BY le.lecture_date DESC, le.id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$lectures = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate overall summary metrics
$totalLectures = count($lectures);
$totalMarked = 0;
$totalPresent = 0;
foreach ($lectures as $l) {
    $totalMarked += (int)$l['att_total'];
    $totalPresent += (int)$l['att_present'];
}
$overallRate = ($totalMarked > 0) ? round(($totalPresent / $totalMarked) * 100, 1) : 0;

$active_nav = 'attendance';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Attendance Sheets — Faculty Portal</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/faculty_sidebar.php'; ?>
<style>
.att-toggle-group {
    display: inline-flex;
    background: #f1f5f9;
    padding: 3px;
    border-radius: 8px;
    border: 1.5px solid #cbd5e1;
    gap: 2px;
}
.att-toggle-group input[type="radio"] { display: none; }
.att-toggle-group label {
    padding: 4px 10px;
    font-size: 11.5px;
    font-weight: 700;
    border-radius: 6px;
    cursor: pointer;
    transition: all .15s ease;
    user-select: none;
    color: #64748b;
}
.att-toggle-group input[value="present"]:checked + label {
    background: #16a34a;
    color: #ffffff;
}
.att-toggle-group input[value="absent"]:checked + label {
    background: #dc2626;
    color: #ffffff;
}
.roll-badge {
    width: 30px;
    height: 30px;
    border-radius: 7px;
    background: #eef2ff;
    color: #4338ca;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 800;
    font-family: monospace;
}
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,0.6);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
@media(max-width: 640px) {
    .modal-overlay {
        padding: 10px;
    }
    .modal-card-box {
        width: 100% !important;
        max-height: 94vh !important;
        border-radius: 14px !important;
    }
    .tb-right .btn-sm {
        padding: 6px 10px;
        font-size: 12px;
    }
}
</style>
</head>
<body>
    <header class="topbar">
        <div class="tb-left">
            <span class="tb-crumb">Student Attendance Sheets</span>
        </div>
        <div class="tb-right">
            <span class="tb-date"><?= date('l, d F Y') ?></span>
            <a href="<?= BASE_URL ?>/faculty/lecture_entry.php" class="btn btn-primary btn-sm">
                + Log Lecture &amp; Mark Attendance
            </a>
        </div>
    </header>

    <div class="page">
        <!-- Header -->
        <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;">
            <div>
                <h1>Daily Student Attendance Records</h1>
                <p>View and inspect all student attendance logs recorded for your assigned courses.</p>
            </div>
            <div>
                <a href="<?= BASE_URL ?>/admin/reports/visiting_faculty_attendance.php" target="_blank" class="btn btn-outline btn-sm">
                    Print Attendance Register &rarr;
                </a>
            </div>
        </div>

        <?php $f = getFlash(); if ($f): ?>
            <div class="alert alert-<?= $f['type'] === 'success' ? 'success' : ($f['type'] === 'warning' ? 'warning' : 'error') ?>">
                <?= $f['message'] ?? $f['msg'] ?? '' ?>
            </div>
        <?php endif; ?>

        <!-- Stats Metric Cards -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;margin-bottom:24px;">
            <div class="card" style="padding:18px 22px;">
                <div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Conducted Lectures</div>
                <div style="font-size:26px;font-weight:800;color:#0f172a;margin-top:4px;"><?= $totalLectures ?></div>
                <div style="font-size:12px;color:#94a3b8;margin-top:2px;">Recorded teaching sessions</div>
            </div>
            <div class="card" style="padding:18px 22px;">
                <div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Total Attendances</div>
                <div style="font-size:26px;font-weight:800;color:#4f46e5;margin-top:4px;"><?= $totalMarked ?></div>
                <div style="font-size:12px;color:#94a3b8;margin-top:2px;"><?= $totalPresent ?> Present &bull; <?= $totalMarked - $totalPresent ?> Absent</div>
            </div>
            <div class="card" style="padding:18px 22px;">
                <div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Overall Attendance Rate</div>
                <div style="font-size:26px;font-weight:800;color:<?= $overallRate >= 75 ? '#16a34a' : '#b45309' ?>;margin-top:4px;"><?= $overallRate ?>%</div>
                <div style="font-size:12px;color:#94a3b8;margin-top:2px;">Across all conducted lectures</div>
            </div>
        </div>

        <!-- View Mode Navigation Tabs -->
        <div style="display:flex;gap:10px;margin-bottom:24px;border-bottom:2px solid #e2e8f0;padding-bottom:12px;flex-wrap:wrap;">
            <a href="?view=cards" class="btn <?= $view === 'cards' ? 'btn-primary' : 'btn-outline' ?>" style="font-weight:700;display:inline-flex;align-items:center;gap:7px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                Course &amp; Semester Attendance Cards (Database Tables)
            </a>
            <a href="?view=logs" class="btn <?= $view === 'logs' ? 'btn-primary' : 'btn-outline' ?>" style="font-weight:700;display:inline-flex;align-items:center;gap:7px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                Lecture Session History Logs
            </a>
        </div>

        <?php if ($view === 'cards'): ?>
            <!-- ============================================================== -->
            <!-- VIEW 1: COURSE-WISE & SEMESTER-WISE DATABASE TABLE CARDS       -->
            <!-- ============================================================== -->
            <?php if (empty($assignedCourses)): ?>
                <div class="card" style="padding:48px 20px;text-align:center;color:#94a3b8;">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 12px;display:block;opacity:.4"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                    <div style="font-size:16px;font-weight:700;color:#334155;">No Courses Assigned</div>
                    <p style="font-size:13.5px;color:#64748b;margin-top:4px;">You currently do not have any subjects assigned to your faculty profile.</p>
                </div>
            <?php else: ?>
                <?php foreach ($assignedCourses as $ac): 
                    $mat = $courseMatrices[$ac['id']] ?? null;
                    $cStudents = $mat['students'] ?? [];
                    $attCols = $mat['attendance_cols'] ?? [];
                    $tblName = $mat['table_name'] ?? getCohortStudentTable($ac['program_name'] ?? $ac['program'], $ac['semester']);
                    $avgPct  = $mat['avg_attendance'] ?? 0;
                    $courseCardId = 'course-card-' . $ac['id'];
                ?>
                <div class="card" style="margin-bottom:26px;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,0.03);" id="<?= $courseCardId ?>">
                    <!-- Card Header -->
                    <div style="background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:18px 22px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;">
                        <div style="display:flex;align-items:center;gap:14px;">
                            <div style="width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg, #1e3a8a, #3b82f6);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;letter-spacing:0.5px;">
                                <?= htmlspecialchars(substr($ac['course_code'] ?? 'DS', 0, 4)) ?>
                            </div>
                            <div>
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <h3 style="font-size:17px;font-weight:800;color:#0f172a;margin:0;">
                                        <?= htmlspecialchars($ac['subject_name']) ?>
                                    </h3>
                                    <code style="background:#eff6ff;color:#1e40af;padding:2px 8px;border-radius:6px;font-size:12px;font-weight:700;border:1px solid #bfdbfe;">
                                        <?= htmlspecialchars($ac['course_code'] ?? '—') ?>
                                    </code>
                                    <span style="background:<?= $ac['class_type'] === 'P' ? '#f0fdf4' : '#f8fafc' ?>;color:<?= $ac['class_type'] === 'P' ? '#166534' : '#475569' ?>;border:1px solid <?= $ac['class_type'] === 'P' ? '#bbf7d0' : '#e2e8f0' ?>;padding:1px 8px;border-radius:6px;font-size:11.5px;font-weight:700;">
                                        <?= $ac['class_type'] === 'P' ? 'Practical' : 'Theory' ?>
                                    </span>
                                </div>
                                <div style="font-size:12.5px;color:#64748b;margin-top:4px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <span style="font-weight:700;color:#334155;"><?= htmlspecialchars($ac['program_name'] ?? $ac['program']) ?></span>
                                    &bull;
                                    <span style="background:#f1f5f9;color:#1e293b;font-weight:800;padding:1px 8px;border-radius:6px;border:1px solid #cbd5e1;">
                                        <?= htmlspecialchars($ac['semester']) ?>
                                    </span>
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
                            <a href="<?= BASE_URL ?>/admin/reports/student_attendance_pdf.php?course_id=<?= $ac['id'] ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:6px;" title="Download complete attendance matrix for this subject as PDF">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                Download PDF
                            </a>
                            <a href="<?= BASE_URL ?>/faculty/lecture_entry.php?course_id=<?= $ac['id'] ?>" class="btn btn-primary btn-sm" style="font-weight:700;">
                                + Mark Today's Attendance
                            </a>
                        </div>
                    </div>

                    <!-- Search Filter per Card -->
                    <div style="padding:10px 20px;background:#ffffff;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                        <div style="display:flex;align-items:center;gap:8px;flex:1;max-width:360px;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text" placeholder="Search student in this table by roll or name..." 
                                   oninput="filterCardStudents(this, 'table-course-<?= $ac['id'] ?>')"
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
                            <table class="dt" id="table-course-<?= $ac['id'] ?>" style="margin:0;font-size:13px;width:100%;">
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
                                                $label = (count($parts) >= 3) ? ($parts[2] . '/' . $parts[1]) : $dClean;
                                                if ($isSession2) $label .= ' (S2)';
                                            ?>
                                                <th style="width:80px;text-align:center;background:#f0f9ff;border-left:1px solid #e0f2fe;position:relative;" title="Session Date: <?= htmlspecialchars($dClean) ?> (Column: <?= htmlspecialchars($col) ?>)" class="att-date-th" data-col="<?= htmlspecialchars($col) ?>" data-table="<?= htmlspecialchars($tblName) ?>" data-course="<?= $ac['id'] ?>" data-label="<?= htmlspecialchars($label) ?>"
                                                >
                                                    <div style="font-size:11px;font-weight:800;color:#0369a1;"><?= htmlspecialchars($label) ?></div>
                                                    <div style="font-size:9px;color:#0284c7;font-weight:700;">(0 or 1)</div>
                                                    <button type="button"
                                                        class="att-col-del-btn"
                                                        title="Delete this attendance date permanently"
                                                        onclick="confirmDeleteAttCol(this)"
                                                        style="position:absolute;top:3px;right:3px;background:none;border:none;cursor:pointer;padding:2px;border-radius:4px;opacity:0;transition:opacity .15s;line-height:1;"
                                                    >
                                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                                    </button>
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
                                            <span class="roll-badge"><?= htmlspecialchars($st['roll_no']) ?></span>
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
            <!-- VIEW 2: LECTURE SESSION HISTORY LOGS                           -->
            <!-- ============================================================== -->
            <!-- Filter Bar -->
            <div class="card" style="padding:16px 20px;margin-bottom:24px;">
                <form method="GET" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;">
                    <input type="hidden" name="view" value="logs">
                    <div style="flex:1;min-width:240px;">
                        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:6px;">Assigned Subject / Course</label>
                        <select name="course_id" class="form-select" style="font-size:13.5px;padding:8px 12px;">
                            <option value="0">-- All Assigned Courses --</option>
                            <?php foreach ($assignedCourses as $ac): ?>
                                <option value="<?= $ac['id'] ?>" <?= $filterCourse === (int)$ac['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ac['program']) ?> &bull; <?= htmlspecialchars($ac['semester']) ?> &bull; <?= htmlspecialchars($ac['subject_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="width:140px;">
                        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:6px;">Month</label>
                        <select name="month" class="form-select" style="font-size:13.5px;padding:8px 12px;">
                            <option value="0">All Months</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $filterMonth === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div style="width:120px;">
                        <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:6px;">Year</label>
                        <select name="year" class="form-select" style="font-size:13.5px;padding:8px 12px;">
                            <option value="0">All Years</option>
                            <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                                <option value="<?= $y ?>" <?= $filterYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div>
                        <button type="submit" class="btn btn-primary" style="padding:9px 18px;font-size:13.5px;">Filter</button>
                        <?php if ($filterCourse || $filterMonth || $filterYear): ?>
                            <a href="<?= BASE_URL ?>/faculty/attendance.php?view=logs" class="btn btn-outline" style="padding:9px 14px;font-size:13.5px;">Reset</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

        <!-- Lecture Sessions & Attendance Table -->
        <div class="card">
            <div class="card-head">
                <div>
                    <div class="card-title">Conducted Lecture Sessions &amp; Attendance Sheets</div>
                    <div class="card-sub">Showing <?= count($lectures) ?> session<?= count($lectures) === 1 ? '' : 's' ?></div>
                </div>
            </div>

            <?php if (empty($lectures)): ?>
                <div style="padding:48px 20px;text-align:center;color:#94a3b8;">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 10px;display:block;opacity:.4"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    No lecture sessions found matching your filters.
                    <div style="margin-top:10px;">
                        <a href="<?= BASE_URL ?>/faculty/lecture_entry.php" class="btn btn-primary btn-sm">+ Log New Lecture</a>
                    </div>
                </div>
            <?php else: ?>
                <table class="dt">
                    <thead>
                        <tr>
                            <th style="width:110px;">Date</th>
                            <th>Subject / Course</th>
                            <th style="width:130px;">Program &amp; Sem</th>
                            <th style="width:90px;">Type</th>
                            <th style="width:70px;">Hours</th>
                            <th style="width:150px;">Student Attendance</th>
                            <th style="text-align:right;width:150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lectures as $lec): 
                            $attTot = (int)$lec['att_total'];
                            $attPres = (int)$lec['att_present'];
                            $rate = ($attTot > 0) ? round(($attPres / $attTot) * 100) : 0;
                        ?>
                        <tr>
                            <td style="font-weight:700;color:#0f172a;white-space:nowrap;">
                                <?= date('d M Y', strtotime($lec['lecture_date'])) ?>
                            </td>
                            <td>
                                <div style="font-weight:700;color:#0f172a;"><?= htmlspecialchars($lec['subject_name']) ?></div>
                                <div style="font-size:11.5px;color:#64748b;font-family:monospace;"><?= htmlspecialchars($lec['course_code'] ?? '—') ?></div>
                            </td>
                            <td>
                                <div style="font-size:12.5px;font-weight:600;color:#334155;"><?= htmlspecialchars($lec['program']) ?></div>
                                <span style="font-size:11px;color:#4f46e5;background:#eef2ff;padding:2px 6px;border-radius:4px;font-weight:700;">
                                    <?= htmlspecialchars($lec['semester']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($lec['class_type'] === 'P'): ?>
                                    <span class="badge badge-blue">Practical</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#f1f5f9;color:#334155;">Theory</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:700;color:#0f172a;">
                                <?= number_format($lec['hours'], 1) ?>h
                            </td>
                            <td>
                                <?php if ($attTot > 0): ?>
                                    <span class="badge <?= $rate >= 75 ? 'badge-green' : ($rate >= 50 ? 'badge-amber' : 'badge-red') ?>">
                                        <?= $attPres ?> / <?= $attTot ?> (<?= $rate ?>%)
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background:#f8fafc;color:#94a3b8;border:1px solid #e2e8f0;">No roster saved</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;">
                                <div style="display:inline-flex;gap:6px;align-items:center;">
                                    <?php if ($attTot > 0): ?>
                                        <a href="<?= BASE_URL ?>/admin/reports/student_attendance_pdf.php?lecture_id=<?= $lec['id'] ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size:12px;padding:4px 9px;" title="Download this session's attendance sheet as PDF">
                                            PDF
                                        </a>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-outline btn-sm" style="font-size:12px;padding:4px 10px;"
                                            onclick="openAttendanceSheet(<?= $lec['id'] ?>)">
                                        View / Edit
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php endif; // end view mode cards vs logs ?>
    </div>

    <!-- Delete Attendance Column Confirmation Modal -->
    <div id="delColModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);backdrop-filter:blur(4px);z-index:10000;align-items:center;justify-content:center;padding:20px;">
        <div style="background:#fff;border-radius:16px;max-width:400px;width:95%;box-shadow:0 20px 50px rgba(0,0,0,0.25);animation:fadeUp .18s ease both;overflow:hidden;">
            <div style="padding:20px 22px 14px;border-bottom:1px solid #fee2e2;background:#fff5f5;display:flex;align-items:center;gap:12px;">
                <div style="width:36px;height:36px;border-radius:10px;background:#fee2e2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                </div>
                <div>
                    <div style="font-size:15px;font-weight:800;color:#0f172a;">Delete Attendance Date</div>
                    <div style="font-size:12px;color:#64748b;margin-top:1px;">This action is permanent and cannot be undone.</div>
                </div>
            </div>
            <div style="padding:18px 22px;">
                <p style="font-size:13.5px;color:#334155;margin:0 0 6px;">You are about to permanently delete all attendance data for:</p>
                <div id="delColModalLabel" style="font-size:15px;font-weight:800;color:#dc2626;background:#fff5f5;border:1px solid #fecaca;border-radius:8px;padding:8px 14px;text-align:center;margin-bottom:14px;"></div>
                <p style="font-size:12px;color:#94a3b8;margin:0;">This will also delete the associated lecture entry and all student attendance records for that session.</p>
            </div>
            <div style="padding:14px 22px 18px;display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" onclick="closeDelColModal()" class="btn btn-outline" style="font-size:13px;">Cancel</button>
                <button type="button" id="delColConfirmBtn" onclick="executeDeleteAttCol()" style="background:#dc2626;color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                    Delete Permanently
                </button>
            </div>
        </div>
    </div>

    <!-- Attendance Sheet Modal -->
    <div id="attModal" class="modal-overlay">
        <div class="modal-card-box" style="background:#fff;border-radius:20px;max-width:620px;width:95%;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(0,0,0,0.22);animation:fadeUp .2s ease both;">
            <div style="padding:20px 24px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <h3 style="font-size:17px;font-weight:800;color:#0f172a;margin:0;" id="modalTitle">Lecture Attendance Sheet</h3>
                    <div style="font-size:12px;color:#64748b;margin-top:2px;" id="modalSubtitle">Loading session details...</div>
                </div>
                <button type="button" onclick="closeAttModal()" style="background:none;border:none;font-size:22px;color:#94a3b8;cursor:pointer;">&times;</button>
            </div>

            <div id="modalLoading" style="padding:40px;text-align:center;color:#94a3b8;">
                Loading attendance records...
            </div>

            <form method="POST" action="<?= BASE_URL ?>/faculty/attendance.php" id="modalForm" style="display:none;flex:1;overflow-y:auto;display:flex;flex-direction:column;">
                <input type="hidden" name="action" value="update_attendance">
                <input type="hidden" name="lecture_id" id="modalLectureId" value="">

                <div style="padding:14px 24px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <button type="button" onclick="modalMarkAll('present')" style="font-size:12.5px;padding:7px 15px;background:#16a34a;color:#ffffff;font-weight:700;border:1px solid #15803d;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:5px;box-shadow:0 1px 3px rgba(22,163,74,0.25);">
                            ✓ All Present
                        </button>
                        <button type="button" onclick="modalMarkAll('absent')" style="font-size:12.5px;padding:7px 15px;background:#dc2626;color:#ffffff;font-weight:700;border:1px solid #b91c1c;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:5px;box-shadow:0 1px 3px rgba(220,38,38,0.25);">
                            ✕ All Absent
                        </button>
                    </div>
                    <div style="font-size:12.5px;font-weight:700;color:#0f172a;" id="modalAttSummary">
                        Summary
                    </div>
                </div>

                <div style="padding:16px 24px;flex:1;overflow-y:auto;">
                    <table class="dt" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width:40px;">#</th>
                                <th style="width:85px;">Roll No</th>
                                <th>Student Name</th>
                                <th style="text-align:center;width:170px;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="modalTbody">
                        </tbody>
                    </table>
                </div>

                <div style="padding:16px 24px;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;background:#fff;flex-wrap:wrap;gap:10px;">
                    <a id="modalDownloadPdfBtn" href="#" target="_blank" class="btn btn-outline btn-sm" style="font-size:12.5px;display:inline-flex;align-items:center;gap:6px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Download PDF Sheet
                    </a>
                    <div style="display:flex;gap:10px;">
                        <button type="button" class="btn btn-outline" onclick="closeAttModal()">Close</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <style>
    .att-date-th:hover .att-col-del-btn { opacity: 1 !important; }
    </style>
    <script>
    function filterCardStudents(input, tableId) {
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

    function openAttendanceSheet(lectureId) {
        document.getElementById('attModal').style.display = 'flex';
        document.getElementById('modalLoading').style.display = 'block';
        document.getElementById('modalForm').style.display = 'none';
        document.getElementById('modalLectureId').value = lectureId;
        const pdfBtn = document.getElementById('modalDownloadPdfBtn');
        if (pdfBtn) pdfBtn.href = '<?= BASE_URL ?>/admin/reports/student_attendance_pdf.php?lecture_id=' + lectureId;

        fetch('<?= BASE_URL ?>/api/get_lecture_attendance.php?lecture_id=' + lectureId)
            .then(res => res.json())
            .then(data => {
                document.getElementById('modalLoading').style.display = 'none';
                if (data.success && data.lecture) {
                    document.getElementById('modalTitle').textContent = `${data.lecture.subject_name} (${data.lecture.course_code || '—'})`;
                    document.getElementById('modalSubtitle').textContent = `${data.lecture.program} • ${data.lecture.semester} • Date: ${data.lecture.formatted_date} (${data.lecture.hours} hrs)`;
                    
                    const tbody = document.getElementById('modalTbody');
                    tbody.innerHTML = '';

                    if (data.students && data.students.length > 0) {
                        data.students.forEach((st, idx) => {
                            const isPresent = (st.status === 'present');
                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td style="color:#94a3b8;font-size:12px;">${idx + 1}</td>
                                <td><span class="roll-badge">${escapeHtml(st.roll_no)}</span></td>
                                <td>
                                    <strong style="color:#0f172a;font-size:13.5px;">${escapeHtml(st.student_name)}</strong>
                                    <div style="font-family:monospace;font-size:11px;color:#64748b;">${escapeHtml(st.enrollment_no || '')}</div>
                                </td>
                                <td style="text-align:center;">
                                    <div class="att-toggle-group">
                                        <input type="radio" name="attendance[${st.student_id}]" id="m_att_${st.student_id}_p" value="present" ${isPresent ? 'checked' : ''} onchange="updateModalSummary()">
                                        <label for="m_att_${st.student_id}_p">Present</label>
                                        <input type="radio" name="attendance[${st.student_id}]" id="m_att_${st.student_id}_a" value="absent" ${!isPresent ? 'checked' : ''} onchange="updateModalSummary()">
                                        <label for="m_att_${st.student_id}_a">Absent</label>
                                    </div>
                                </td>
                            `;
                            tbody.appendChild(tr);
                        });
                        document.getElementById('modalForm').style.display = 'flex';
                        updateModalSummary();
                    } else {
                        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:#94a3b8;">No student attendance was recorded for this lecture.</td></tr>';
                        document.getElementById('modalForm').style.display = 'flex';
                    }
                } else {
                    alert(data.message || 'Could not load lecture attendance.');
                    closeAttModal();
                }
            })
            .catch(err => {
                document.getElementById('modalLoading').style.display = 'none';
                alert('Network error loading attendance sheet: ' + err.message);
                closeAttModal();
            });
    }

    function closeAttModal() {
        document.getElementById('attModal').style.display = 'none';
    }

    function updateModalSummary() {
        const present = document.querySelectorAll('#modalTbody input[value="present"]:checked').length;
        const absent = document.querySelectorAll('#modalTbody input[value="absent"]:checked').length;
        const total = present + absent;
        const pct = total > 0 ? Math.round((present / total) * 100) : 0;
        document.getElementById('modalAttSummary').innerHTML = `<span style="color:#16a34a;font-weight:800;">${present} Present</span> &bull; <span style="color:#dc2626;font-weight:800;">${absent} Absent</span> &bull; <span style="color:#1e3a8a;">${pct}% Attendance</span>`;
    }

    function modalMarkAll(status) {
        const radios = document.querySelectorAll(`#modalTbody input[value="${status}"]`);
        radios.forEach(r => r.checked = true);
        updateModalSummary();
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ── Delete Attendance Column ──────────────────────────────────────────────
    let _delColPending = null; // { thEl, colName, tableName, courseId, label }

    function confirmDeleteAttCol(btnEl) {
        const th = btnEl.closest('th');
        _delColPending = {
            thEl      : th,
            colName   : th.dataset.col,
            tableName : th.dataset.table,
            courseId  : th.dataset.course,
            label     : th.dataset.label,
        };
        document.getElementById('delColModalLabel').textContent = _delColPending.label;
        const modal = document.getElementById('delColModal');
        modal.style.display = 'flex';
    }

    function closeDelColModal() {
        document.getElementById('delColModal').style.display = 'none';
        _delColPending = null;
    }

    function executeDeleteAttCol() {
        if (!_delColPending) return;
        const btn = document.getElementById('delColConfirmBtn');
        btn.disabled = true;
        btn.textContent = 'Deleting…';

        const formData = new URLSearchParams({
            action     : 'delete_att_col',
            course_id  : parseInt(_delColPending.courseId),
            col_name   : _delColPending.colName,
            table_name : _delColPending.tableName,
        });

        fetch('<?= BASE_URL ?>/faculty/attendance.php', {
            method      : 'POST',
            credentials : 'same-origin',
            headers     : { 'Content-Type': 'application/x-www-form-urlencoded' },
            body        : formData.toString(),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Remove the entire <th> from the header
                const th = _delColPending.thEl;
                const colIdx = Array.from(th.parentElement.children).indexOf(th);
                th.remove();

                // Remove the matching <td> in every body row of the same table
                const table = th.closest('table');
                if (table && colIdx >= 0) {
                    table.querySelectorAll('tbody tr').forEach(row => {
                        const td = row.children[colIdx];
                        if (td) td.remove();
                    });
                }

                // Update the Sessions Marked badge in the card header
                const card = th.closest('.card') || document.body;
                const badges = card.querySelectorAll('.badge-green');
                badges.forEach(b => {
                    if (b.textContent.includes('Sessions Marked')) {
                        const cur = parseInt(b.textContent) || 0;
                        b.textContent = Math.max(0, cur - 1) + ' Sessions Marked';
                    }
                });

                closeDelColModal();
            } else {
                alert('Error: ' + (data.message || 'Could not delete column.'));
                btn.disabled = false;
                btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg> Delete Permanently';
            }
        })
        .catch(err => {
            alert('Network error: ' + err.message);
            btn.disabled = false;
            btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg> Delete Permanently';
        });
    }

    // Close del modal on backdrop click
    document.getElementById('delColModal').addEventListener('click', function(e) {
        if (e.target === this) closeDelColModal();
    });
    </script>
</body>
</html>
