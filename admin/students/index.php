<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

// Handle Quick Add and Quick Edit POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'quick_add') {
        $pId       = (int)($_POST['program_id'] ?? 0);
        $sem       = trim($_POST['semester'] ?? '');
        $rollNo    = trim($_POST['roll_no'] ?? '');
        $name      = trim($_POST['student_name'] ?? '');
        $enrollNo  = trim($_POST['enrollment_no'] ?? '');
        $status    = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $returnUrl = $_POST['return_url'] ?? (BASE_URL . '/admin/students/index.php');

        if ($pId > 0 && !empty($sem) && !empty($rollNo) && !empty($name)) {
            try {
                // Determine batch year from semester_tags if available
                $semNum = (int)filter_var($sem, FILTER_SANITIZE_NUMBER_INT);
                $tagStmt = $pdo->prepare("SELECT year_tag FROM semester_tags WHERE program_id = ? AND semester_number = ?");
                $tagStmt->execute([$pId, $semNum]);
                $batchYear = $tagStmt->fetchColumn();

                if (!$batchYear) {
                    $progBatchStmt = $pdo->prepare("SELECT batch_year FROM academic_programs WHERE id = ?");
                    $progBatchStmt->execute([$pId]);
                    $batchYear = $progBatchStmt->fetchColumn() ?: '2025-2027';
                }

                $stmt = $pdo->prepare("
                    INSERT INTO students (program_id, batch_year, current_semester, roll_no, enrollment_no, student_name, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$pId, $batchYear, $sem, $rollNo, $enrollNo, $name, $status]);

                // Also update dedicated physical table (e.g. students_mtech_aids_sem1)
                $pRow = $pdo->query("SELECT program_name FROM academic_programs WHERE id = {$pId}")->fetch(PDO::FETCH_ASSOC);
                if ($pRow) {
                    $dedTbl = getCohortStudentTable($pRow['program_name'], $sem);
                    try {
                        $dStmt = $pdo->prepare("
                            INSERT INTO `{$dedTbl}` (roll_no, student_name, enrollment_no, batch_year, status)
                            VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE student_name = VALUES(student_name), enrollment_no = VALUES(enrollment_no), status = VALUES(status)
                        ");
                        $dStmt->execute([$rollNo, $name, $enrollNo, $batchYear, $status]);
                    } catch (Exception $e) {}
                }

                setFlash('success', "Student <strong>" . htmlspecialchars($name) . "</strong> (Roll: " . htmlspecialchars($rollNo) . ") successfully enrolled!");
            } catch (PDOException $e) {
                setFlash('error', "Could not add student: " . $e->getMessage());
            }
        } else {
            setFlash('error', "Please fill in all required fields (Student Name and Roll Number).");
        }
        header('Location: ' . $returnUrl);
        exit;
    }

    if ($action === 'quick_edit') {
        $id        = (int)($_POST['student_id'] ?? 0);
        $name      = trim($_POST['student_name'] ?? '');
        $rollNo    = trim($_POST['roll_no'] ?? '');
        $enrollNo  = trim($_POST['enrollment_no'] ?? '');
        $status    = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $returnUrl = $_POST['return_url'] ?? (BASE_URL . '/admin/students/index.php');

        if ($id > 0 && !empty($name) && !empty($rollNo)) {
            try {
                // Get original record to find dedicated physical table
                $orig = $pdo->query("SELECT s.roll_no, s.current_semester, ap.program_name FROM students s JOIN academic_programs ap ON ap.id = s.program_id WHERE s.id = {$id}")->fetch(PDO::FETCH_ASSOC);

                $stmt = $pdo->prepare("
                    UPDATE students
                    SET student_name = ?, roll_no = ?, enrollment_no = ?, status = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $rollNo, $enrollNo, $status, $id]);

                // Also update dedicated physical table
                if ($orig) {
                    $dedTbl = getCohortStudentTable($orig['program_name'], $orig['current_semester']);
                    try {
                        $dUpd = $pdo->prepare("
                            UPDATE `{$dedTbl}`
                            SET student_name = ?, roll_no = ?, enrollment_no = ?, status = ?
                            WHERE roll_no = ?
                        ");
                        $dUpd->execute([$name, $rollNo, $enrollNo, $status, $orig['roll_no']]);
                    } catch (Exception $e) {}
                }

                setFlash('success', "Student <strong>" . htmlspecialchars($name) . "</strong> details successfully updated!");
            } catch (PDOException $e) {
                setFlash('error', "Could not update student: " . $e->getMessage());
            }
        } else {
            setFlash('error', "Student Name and Roll Number cannot be empty.");
        }
        header('Location: ' . $returnUrl);
        exit;
    }
}

// Fetch all programs
$programs = $pdo->query("SELECT * FROM academic_programs ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
if (empty($programs)) {
    $selectedProgram = ['id' => 1, 'program_name' => 'M.Tech AI&DS', 'batch_year' => '2022-2027', 'total_semesters' => 10];
} else {
    $reqProgName = trim($_GET['program'] ?? '');
    $reqProgId   = (int)($_GET['program_id'] ?? 0);
    $selectedProgram = $programs[0];
    foreach ($programs as $p) {
        if (($reqProgName && strcasecmp($p['program_name'], $reqProgName) === 0) || ($reqProgId && (int)$p['id'] === $reqProgId)) {
            $selectedProgram = $p;
            break;
        }
    }
}

$progId     = (int)$selectedProgram['id'];
$progName   = $selectedProgram['program_name'];
$batchYear  = $selectedProgram['batch_year'];
$totalSems  = (int)$selectedProgram['total_semesters'];

// Check optional course context
$courseId = (int)($_GET['course_id'] ?? 0);
$selectedCourse = null;
if ($courseId > 0) {
    $cStmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
    $cStmt->execute([$courseId]);
    $selectedCourse = $cStmt->fetch(PDO::FETCH_ASSOC);
}

// Filter semester
$filterSem = trim($_GET['semester'] ?? 'all');
if ($selectedCourse && !isset($_GET['semester']) && !empty($selectedCourse['semester'])) {
    $filterSem = $selectedCourse['semester'];
}

// Fetch all semesters list for this program
$semestersList = [];
for ($i = 1; $i <= $totalSems; $i++) {
    $suffix = 'th';
    if ($i === 1) $suffix = 'st';
    elseif ($i === 2) $suffix = 'nd';
    elseif ($i === 3) $suffix = 'rd';
    $semestersList[] = "{$i}{$suffix} Semester";
}

// Fetch semester year tags for this program
$tagStmt = $pdo->prepare("SELECT semester_number, year_tag FROM semester_tags WHERE program_id = ?");
$tagStmt->execute([$progId]);
$semesterYearTags = $tagStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Count per semester for tabs
$countStmt = $pdo->prepare("
    SELECT current_semester, COUNT(*) AS count
    FROM students
    WHERE program_id = ?
    GROUP BY current_semester
");
$countStmt->execute([$progId]);
$semCounts = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$totalStudentsCount = array_sum($semCounts);

// Fetch students for this program
$query = "
    SELECT s.*, ap.program_name, ap.batch_year AS prog_batch
    FROM students s
    JOIN academic_programs ap ON ap.id = s.program_id
    WHERE s.program_id = ?
";
$params = [$progId];

if ($filterSem !== 'all' && !empty($filterSem)) {
    $query .= " AND (s.current_semester = ? OR s.current_semester = ?)";
    $semNum = (int)filter_var($filterSem, FILTER_SANITIZE_NUMBER_INT);
    $params[] = $filterSem;
    $params[] = (string)$semNum;
}

$query .= " ORDER BY CAST(s.current_semester AS UNSIGNED) ASC, s.current_semester ASC, CAST(s.roll_no AS UNSIGNED) ASC, s.roll_no ASC, s.student_name ASC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$allStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group students by semester for separate cohort tables
$studentsBySemester = [];
foreach ($semestersList as $sem) {
    $studentsBySemester[$sem] = [];
}
foreach ($allStudents as $st) {
    $stSem = $st['current_semester'];
    if (isset($studentsBySemester[$stSem])) {
        $studentsBySemester[$stSem][] = $st;
    } else {
        $studentsBySemester[$stSem][] = $st;
    }
}

$active_nav = 'students-list';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($progName) ?> Student Rosters — SDSF Admin</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
<style>
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
    background: rgba(255,255,255,0.25);
    padding: 2px 7px;
    border-radius: 6px;
}
.prog-pill:not(.active) .year-tag {
    background: #f1f5f9;
    color: #64748b;
}

.sem-tabs {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    padding-bottom: 8px;
    margin-bottom: 22px;
}
.sem-tab-btn {
    padding: 8px 16px;
    border-radius: 10px;
    border: 1.5px solid #e2e8f0;
    background: #ffffff;
    font-size: 13px;
    font-weight: 700;
    color: #475569;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all .15s;
    white-space: nowrap;
}
.sem-tab-btn:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
}
.sem-tab-btn.active {
    background: #1e3a8a;
    color: #ffffff;
    border-color: #1e3a8a;
}
.sem-tab-count {
    font-size: 11px;
    padding: 1px 7px;
    border-radius: 10px;
    background: rgba(0,0,0,0.08);
}
.sem-tab-btn.active .sem-tab-count {
    background: rgba(255,255,255,0.28);
}

.cohort-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.cohort-header {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 16px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.cohort-badge {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: linear-gradient(135deg, #1e3a8a, #3b82f6);
    color: #ffffff;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}
.roll-avatar {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    background: #eef2ff;
    color: #4338ca;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12.5px;
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
/* Multi-select & Bulk Delete Styles */
.bulk-action-bar {
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%) translateY(120%);
    z-index: 9990;
    background: #0f172a;
    color: #ffffff;
    padding: 12px 22px;
    border-radius: 16px;
    box-shadow: 0 20px 45px rgba(0,0,0,0.38), 0 0 0 1px rgba(255,255,255,0.1);
    display: flex;
    align-items: center;
    gap: 18px;
    transition: transform 0.28s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.28s ease;
    opacity: 0;
    pointer-events: none;
    max-width: 95vw;
    flex-wrap: wrap;
}
.bulk-action-bar.visible {
    transform: translateX(-50%) translateY(0);
    opacity: 1;
    pointer-events: auto;
}
.bulk-count-badge {
    background: #3b82f6;
    color: #ffffff;
    font-size: 13px;
    font-weight: 800;
    padding: 3px 11px;
    border-radius: 9999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
}
tr.student-row.row-selected {
    background-color: #f0f9ff !important;
}
tr.student-row.row-selected td {
    background-color: #f0f9ff !important;
}
input[type="checkbox"].student-cb,
input[type="checkbox"].cohort-select-all {
    width: 17px;
    height: 17px;
    border-radius: 4px;
    accent-color: #2563eb;
    cursor: pointer;
    vertical-align: middle;
}
</style>
</head>
<body>
    <header class="topbar">
        <div class="tb-left">
            <a href="<?= BASE_URL ?>/admin/dashboard.php" style="color:#94a3b8;text-decoration:none;">Dashboard</a>
            <span class="tb-sep">/</span>
            <a href="<?= BASE_URL ?>/admin/courses/list.php?program=<?= urlencode($progName) ?>" style="color:#94a3b8;text-decoration:none;"><?= htmlspecialchars($progName) ?></a>
            <span class="tb-sep">/</span>
            <span class="tb-crumb">Student Rosters</span>
        </div>
        <div class="tb-right">
            <a href="<?= BASE_URL ?>/admin/courses/list.php?program=<?= urlencode($progName) ?>" class="btn btn-outline btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                Course Curriculum
            </a>
            <a href="<?= BASE_URL ?>/admin/students/attendance.php" class="btn btn-outline btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                Attendance Logs
            </a>
            <button type="button" onclick="openQuickAddModal('<?= $progId ?>', '<?= htmlspecialchars(addslashes($filterSem !== 'all' ? $filterSem : '1st Semester')) ?>')" class="btn btn-primary btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                + Add Student
            </button>
        </div>
    </header>

    <div class="page">
        <!-- Page Title & Metrics -->
        <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
            <div>
                <h1><?= htmlspecialchars($progName) ?> — Student Rosters</h1>
                <p>Manage and edit enrolled students, roll numbers, and cohort rosters across each semester.</p>
            </div>
            <div style="display:flex;gap:10px;align-items:center;">
                <span class="badge badge-blue" style="font-size:13px;padding:6px 14px;">
                    Total Enrolled: <?= $totalStudentsCount ?> Students
                </span>
                <a href="<?= BASE_URL ?>/admin/students/bulk_import.php?program_id=<?= $progId ?>" class="btn btn-outline btn-sm">
                    Bulk Import
                </a>
            </div>
        </div>

        <?php if ($selectedCourse): ?>
            <div class="alert alert-info fade-up" style="display:flex;align-items:center;justify-content:space-between;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <div>
                        Filtered for Subject: <strong><?= htmlspecialchars($selectedCourse['subject_name']) ?></strong> 
                        (<code><?= htmlspecialchars($selectedCourse['course_code'] ?? '—') ?></code>) &bull; <?= htmlspecialchars($selectedCourse['semester']) ?>
                    </div>
                </div>
                <a href="<?= BASE_URL ?>/admin/students/index.php?program=<?= urlencode($progName) ?>&semester=all" class="btn btn-outline btn-sm" style="background:#fff;padding:4px 10px;font-size:12px;">
                    Clear Subject Filter &times;
                </a>
            </div>
        <?php endif; ?>

        <?php $f = getFlash(); if ($f): ?>
            <div class="alert alert-<?= $f['type'] === 'success' ? 'success' : ($f['type'] === 'warning' ? 'warning' : 'error') ?> fade-up">
                <?= $f['message'] ?? $f['msg'] ?? '' ?>
            </div>
        <?php endif; ?>

        <!-- Program Selector Tabs -->
        <div class="prog-pills-bar fade-up">
            <?php foreach ($programs as $prg): ?>
                <?php $isActive = ($prg['id'] == $progId); ?>
                <a href="<?= BASE_URL ?>/admin/students/index.php?program=<?= urlencode($prg['program_name']) ?>&semester=<?= urlencode($filterSem) ?>"
                   class="prog-pill <?= $isActive ? 'active' : '' ?>">
                    <span><?= htmlspecialchars($prg['program_name']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Semester Tabs -->
        <div class="sem-tabs fade-up">
            <a href="<?= BASE_URL ?>/admin/students/index.php?program=<?= urlencode($progName) ?>&semester=all"
               class="sem-tab-btn <?= $filterSem === 'all' ? 'active' : '' ?>">
                <span>All Cohorts</span>
                <span class="sem-tab-count"><?= $totalStudentsCount ?></span>
            </a>
            <?php for ($s = 1; $s <= $totalSems; $s++): 
                $suf = 'th';
                if ($s === 1) $suf = 'st';
                elseif ($s === 2) $suf = 'nd';
                elseif ($s === 3) $suf = 'rd';
                $sName = "{$s}{$suf} Semester";
                $c = $semCounts[$sName] ?? ($semCounts[(string)$s] ?? 0);
                $isSemActive = ($filterSem === $sName || $filterSem == (string)$s);
            ?>
                <a href="<?= BASE_URL ?>/admin/students/index.php?program=<?= urlencode($progName) ?>&semester=<?= urlencode($sName) ?>"
                   class="sem-tab-btn <?= $isSemActive ? 'active' : '' ?>">
                    <span>Sem <?= $s ?></span>
                    <span class="sem-tab-count"><?= $c ?></span>
                </a>
            <?php endfor; ?>
        </div>

        <!-- Search & Selection Controls -->
        <div class="card fade-up" style="margin-bottom:20px;padding:12px 18px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:260px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="studentSearch" placeholder="Search students across all cohorts by name, roll no, or enrollment no..."
                           oninput="searchStudents()"
                           style="border:none;outline:none;font-size:14px;width:100%;background:transparent;color:#0f172a;">
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="selectAllVisibleStudents(true)" style="font-size:12px;padding:5px 12px;display:inline-flex;align-items:center;gap:6px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        Select All Visible
                    </button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="clearAllSelections()" style="font-size:12px;padding:5px 12px;">
                        Clear Selection
                    </button>
                </div>
            </div>
        </div>

        <!-- Render Distinct Cohort Tables (M.Tech AI&DS Sem 1, Sem 2, etc.) -->
        <?php 
        $renderedSems = ($filterSem === 'all') ? $semestersList : [$filterSem];
        foreach ($renderedSems as $idx => $semName):
            $semNum = (int)filter_var($semName, FILTER_SANITIZE_NUMBER_INT) ?: ($idx + 1);
            $semTag = $semesterYearTags[$semNum] ?? $batchYear;
            $semStudents = $studentsBySemester[$semName] ?? [];
            $dedTbl = getCohortStudentTable($progName, $semName);
            $attCols = getCohortAttendanceColumns($pdo, $dedTbl);
            $dedRowsByRoll = [];
            if (!empty($attCols)) {
                try {
                    $stmtD = $pdo->query("SELECT * FROM `{$dedTbl}`");
                    while ($r = $stmtD->fetch(PDO::FETCH_ASSOC)) {
                        $dedRowsByRoll[$r['roll_no']] = $r;
                    }
                } catch (Exception $e) {}
            }
        ?>
        <div class="cohort-card fade-up" id="cohort-table-sem-<?= $semNum ?>">
            <div class="cohort-header">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div class="cohort-badge"><?= $semNum ?></div>
                    <div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <h2 style="font-size:16px;font-weight:800;color:#0f172a;margin:0;">
                                <?= htmlspecialchars($progName) ?> &mdash; <?= htmlspecialchars($semName) ?>
                            </h2>
                            <span class="c-badge" style="font-size:11.5px;padding:2px 8px;">
                                <?= htmlspecialchars($semTag ?: 'Current Batch') ?>
                            </span>
                        </div>
                        <div style="font-size:12px;color:#64748b;margin-top:2px;">
                            Dedicated Table: <code style="color:#1e3a8a;font-weight:700;background:#eff6ff;padding:1px 6px;border-radius:4px;border:1px solid #bfdbfe;"><?= htmlspecialchars($dedTbl) ?></code> &bull; <?= count($semStudents) ?> enrolled student<?= count($semStudents) === 1 ? '' : 's' ?>
                            <?php if (!empty($attCols)): ?>
                                &bull; <span class="badge badge-blue" style="font-size:11px;padding:2px 7px;"><?= count($attCols) ?> Attendance Date<?= count($attCols) === 1 ? '' : 's' ?> Recorded</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <?php if (!empty($semStudents)): ?>
                        <button type="button" class="btn btn-outline btn-sm" onclick="selectCohortOnly(<?= $semNum ?>)" title="Select all students in <?= htmlspecialchars($semName) ?>" style="font-size:12px;padding:4px 10px;">
                            Select All (<?= count($semStudents) ?>)
                        </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline btn-sm" onclick="openQuickAddModal('<?= $progId ?>', '<?= htmlspecialchars(addslashes($semName)) ?>')">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        + Add Student to <?= htmlspecialchars($semName) ?>
                    </button>
                    <a href="<?= BASE_URL ?>/admin/courses/list.php?program=<?= urlencode($progName) ?>&sem=<?= $semNum ?>#sem-<?= $semNum ?>" class="btn btn-outline btn-sm" title="View Subjects for <?= htmlspecialchars($semName) ?>">
                        View Subjects
                    </a>
                </div>
            </div>

            <?php if (empty($semStudents)): ?>
                <div style="padding:40px 20px;text-align:center;color:#94a3b8;">
                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 8px;display:block;opacity:.4"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    No students currently enrolled in <?= htmlspecialchars($semName) ?>.
                    <div style="margin-top:8px;">
                        <button type="button" onclick="openQuickAddModal('<?= $progId ?>', '<?= htmlspecialchars(addslashes($semName)) ?>')" class="btn btn-primary btn-sm">
                            + Enroll first student
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="dt student-data-table">
                    <thead>
                        <tr>
                            <th style="width:38px;text-align:center;">
                                <input type="checkbox" class="cohort-select-all" data-sem="<?= $semNum ?>" onchange="toggleCohortSelect(this, <?= $semNum ?>)" title="Select all in <?= htmlspecialchars($semName) ?>">
                            </th>
                            <th style="width:45px;">#</th>
                            <th style="width:90px;">Roll No</th>
                            <th>Student Name</th>
                            <th style="width:160px;">Enrollment No</th>
                            <th style="width:120px;">Batch Year</th>
                            <th style="width:100px;">Status</th>
                            <?php foreach ($attCols as $cCol): 
                                $dStr = str_replace('att_', '', $cCol);
                                $dParts = explode('_', $dStr);
                                $colLabel = $dStr;
                                if (count($dParts) >= 3) {
                                    $colLabel = $dParts[2] . '/' . $dParts[1];
                                }
                            ?>
                                <th style="width:85px;text-align:center;background:#f0f9ff;border-left:1px solid #e0f2fe;" title="Attendance Date: <?= htmlspecialchars($dStr) ?> (Dedicated Table Column: <?= htmlspecialchars($cCol) ?>)">
                                    <div style="font-size:11px;font-weight:800;color:#0369a1;"><?= htmlspecialchars($colLabel) ?></div>
                                    <div style="font-size:9px;color:#0284c7;font-weight:600;">(0 or 1)</div>
                                </th>
                            <?php endforeach; ?>
                            <th style="text-align:right;width:160px;">Actions (Editable)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($semStudents as $sIdx => $st): ?>
                        <tr class="student-row"
                            data-id="<?= $st['id'] ?>"
                            data-name="<?= strtolower(htmlspecialchars($st['student_name'])) ?>"
                            data-roll="<?= strtolower(htmlspecialchars($st['roll_no'])) ?>"
                            data-enroll="<?= strtolower(htmlspecialchars($st['enrollment_no'] ?? '')) ?>"
                            data-status="<?= htmlspecialchars($st['status']) ?>">
                            <td style="text-align:center;">
                                <input type="checkbox" class="student-cb sem-cb-<?= $semNum ?>" value="<?= $st['id'] ?>" data-name="<?= htmlspecialchars($st['student_name'], ENT_QUOTES) ?>" data-roll="<?= htmlspecialchars($st['roll_no'], ENT_QUOTES) ?>" data-sem="<?= $semNum ?>" onchange="onStudentSelectChange(this)">
                            </td>
                            <td style="color:#94a3b8;font-size:12px;"><?= $sIdx + 1 ?></td>
                            <td>
                                <span class="roll-avatar"><?= htmlspecialchars($st['roll_no']) ?></span>
                            </td>
                            <td>
                                <div style="font-weight:700;color:#0f172a;font-size:14px;" id="st-name-disp-<?= $st['id'] ?>">
                                    <?= htmlspecialchars($st['student_name']) ?>
                                </div>
                            </td>
                            <td>
                                <span style="font-family:monospace;font-size:12.5px;color:#475569;font-weight:600;background:#f8fafc;padding:3px 8px;border-radius:6px;border:1px solid #e2e8f0;" id="st-enroll-disp-<?= $st['id'] ?>">
                                    <?= htmlspecialchars($st['enrollment_no'] ?: '—') ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-size:12px;color:#64748b;font-weight:600;">
                                    <?= htmlspecialchars($st['batch_year'] ?: $semTag) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($st['status'] === 'active'): ?>
                                    <span class="badge badge-green">&#10003; Active</span>
                                <?php else: ?>
                                    <span class="badge badge-red">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <?php foreach ($attCols as $cCol): 
                                $attVal = $dedRowsByRoll[$st['roll_no']][$cCol] ?? null;
                            ?>
                                <td style="text-align:center;background:#f8fafc;border-left:1px solid #f1f5f9;">
                                    <?php if ($attVal === 1 || $attVal === '1'): ?>
                                        <span class="badge badge-green" style="font-size:11px;font-weight:800;padding:2px 8px;" title="Present (1)">1</span>
                                    <?php elseif ($attVal === 0 || $attVal === '0'): ?>
                                        <span class="badge badge-red" style="font-size:11px;font-weight:800;padding:2px 8px;" title="Absent (0)">0</span>
                                    <?php else: ?>
                                        <span style="color:#cbd5e1;font-weight:600;">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td style="text-align:right;">
                                <div style="display:inline-flex;gap:6px;align-items:center;">
                                    <button type="button" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px;font-weight:700;"
                                            onclick="openQuickEditModal(<?= $st['id'] ?>, '<?= htmlspecialchars(addslashes($st['student_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($st['roll_no']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($st['enrollment_no'] ?? ''), ENT_QUOTES) ?>', '<?= $st['status'] ?>')"
                                            title="Quick Edit Student">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        Edit
                                    </button>
                                    <button type="button" class="btn btn-danger btn-sm" style="padding:4px 10px;font-size:12px;" 
                                            onclick="confirmDelete(<?= $st['id'] ?>, '<?= htmlspecialchars(addslashes($st['student_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($st['roll_no']), ENT_QUOTES) ?>')">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

    </div>
</div>

<!-- Quick Add Student Modal -->
<div id="quickAddModal" class="modal-overlay">
    <div style="background:#fff;border-radius:20px;max-width:480px;width:95%;padding:28px;box-shadow:0 24px 60px rgba(0,0,0,0.22);animation:fadeUp .2s ease both;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:38px;height:38px;border-radius:10px;background:#eef2ff;color:#4f46e5;display:flex;align-items:center;justify-content:center;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                </div>
                <div>
                    <h3 style="font-size:17px;font-weight:800;color:#0f172a;margin:0;">Add Student to Cohort</h3>
                    <div style="font-size:12px;color:#64748b;" id="addModalCohortLabel"><?= htmlspecialchars($progName) ?></div>
                </div>
            </div>
            <button type="button" onclick="closeQuickAddModal()" style="background:none;border:none;font-size:22px;color:#94a3b8;cursor:pointer;">&times;</button>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/admin/students/index.php">
            <input type="hidden" name="action" value="quick_add">
            <input type="hidden" name="program_id" id="addProgramId" value="<?= $progId ?>">
            <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

            <div class="form-group">
                <label class="form-label" for="addSemester">Cohort Semester *</label>
                <select name="semester" id="addSemester" class="form-select" required>
                    <?php foreach ($semestersList as $sem): ?>
                        <option value="<?= htmlspecialchars($sem) ?>"><?= htmlspecialchars($sem) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label class="form-label" for="addRollNo">Roll Number *</label>
                    <input type="text" name="roll_no" id="addRollNo" class="form-input" placeholder="e.g. r-6, 101" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="addEnrollNo">Enrollment No</label>
                    <input type="text" name="enrollment_no" id="addEnrollNo" class="form-input" placeholder="e.g. DS25-01-06">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="addStudentName">Student Full Name *</label>
                <input type="text" name="student_name" id="addStudentName" class="form-input" placeholder="e.g. dummy6 or Rahul Sharma" required autofocus>
            </div>

            <div class="form-group">
                <label class="form-label" for="addStatus">Status</label>
                <select name="status" id="addStatus" class="form-select">
                    <option value="active" selected>Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
                <button type="button" class="btn btn-outline" onclick="closeQuickAddModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Student</button>
            </div>
        </form>
    </div>
</div>

<!-- Quick Edit Student Modal -->
<div id="quickEditModal" class="modal-overlay">
    <div style="background:#fff;border-radius:20px;max-width:480px;width:95%;padding:28px;box-shadow:0 24px 60px rgba(0,0,0,0.22);animation:fadeUp .2s ease both;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:38px;height:38px;border-radius:10px;background:#eef2ff;color:#4f46e5;display:flex;align-items:center;justify-content:center;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </div>
                <div>
                    <h3 style="font-size:17px;font-weight:800;color:#0f172a;margin:0;">Edit Student</h3>
                    <div style="font-size:12px;color:#64748b;">Directly editable by administrator</div>
                </div>
            </div>
            <button type="button" onclick="closeQuickEditModal()" style="background:none;border:none;font-size:22px;color:#94a3b8;cursor:pointer;">&times;</button>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/admin/students/index.php">
            <input type="hidden" name="action" value="quick_edit">
            <input type="hidden" name="student_id" id="editStudentId" value="">
            <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label class="form-label" for="editRollNo">Roll Number *</label>
                    <input type="text" name="roll_no" id="editRollNo" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="editEnrollNo">Enrollment No</label>
                    <input type="text" name="enrollment_no" id="editEnrollNo" class="form-input">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="editStudentName">Student Name *</label>
                <input type="text" name="student_name" id="editStudentName" class="form-input" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="editStatus">Status</label>
                <select name="status" id="editStatus" class="form-select">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
                <button type="button" class="btn btn-outline" onclick="closeQuickEditModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Details</button>
            </div>
        </form>
    </div>
</div>

<!-- Floating Bulk Action Bar -->
<div id="bulkActionBar" class="bulk-action-bar">
    <div style="display:flex;align-items:center;gap:12px;">
        <span class="bulk-count-badge" id="bulkSelectedCount">0</span>
        <div style="line-height:1.2;">
            <div style="font-size:13.5px;font-weight:700;color:#f8fafc;">Students Selected</div>
            <div style="font-size:11.5px;color:#94a3b8;" id="bulkSelectedSubtitle">Ready for multiple deletion</div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn btn-sm" onclick="selectAllVisibleStudents(true)" style="background:#1e293b;color:#e2e8f0;border:1px solid #475569;font-size:12px;padding:6px 12px;border-radius:8px;font-weight:600;">
            Select All Visible
        </button>
        <button type="button" class="btn btn-sm" onclick="clearAllSelections()" style="background:#1e293b;color:#cbd5e1;border:1px solid #475569;font-size:12px;padding:6px 12px;border-radius:8px;font-weight:600;">
            Clear Selection
        </button>
        <button type="button" class="btn btn-danger btn-sm" onclick="openBulkDeleteModal()" style="font-size:12.5px;font-weight:700;padding:6px 15px;border-radius:8px;display:inline-flex;align-items:center;gap:6px;box-shadow:0 4px 14px rgba(220,38,38,0.4);">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
            Delete (<span id="bulkDelBtnCount">0</span>) Selected
        </button>
    </div>
</div>

<!-- Delete Confirmation Modal (Handles Both Single and Multiple Bulk Delete) -->
<div id="deleteModal" class="modal-overlay">
    <div style="background:#fff;border-radius:20px;max-width:480px;width:95%;padding:28px;box-shadow:0 20px 40px rgba(0,0,0,0.22);animation:fadeUp .2s ease both;">
        <div style="width:48px;height:48px;border-radius:12px;background:#fef2f2;border:1.5px solid #fecaca;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <h3 style="font-size:18px;font-weight:800;color:#0f172a;text-align:center;margin:0 0 6px;" id="delModalTitle">Delete Student Record</h3>
        <p style="font-size:13.5px;color:#64748b;text-align:center;margin:0 0 16px;" id="delPrompt">Are you sure you want to delete this student?</p>
        
        <div id="delPreviewContainer" style="display:none;margin-bottom:18px;"></div>

        <form method="POST" action="<?= BASE_URL ?>/admin/students/delete.php">
            <input type="hidden" name="id" id="delStudentId" value="">
            <input type="hidden" name="ids" id="delStudentIds" value="">
            <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
            <div style="display:flex;justify-content:center;gap:12px;">
                <button type="button" class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn btn-danger" id="delConfirmBtn">Confirm Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
// State management for multi-selected students
const selectedStudents = new Map(); // id => { name, roll, sem }

function onStudentSelectChange(cb) {
    const row = cb.closest('tr');
    const id = cb.value;
    const name = cb.getAttribute('data-name') || '';
    const roll = cb.getAttribute('data-roll') || '';
    const sem = cb.getAttribute('data-sem') || '';

    if (cb.checked) {
        selectedStudents.set(id, { name, roll, sem });
        if (row) row.classList.add('row-selected');
    } else {
        selectedStudents.delete(id);
        if (row) row.classList.remove('row-selected');
    }
    updateBulkBar();
    updateCohortSelectAllState();
}

function toggleCohortSelect(headerCb, semNum) {
    const isChecked = headerCb.checked;
    const semCbs = document.querySelectorAll(`.sem-cb-${semNum}`);
    semCbs.forEach(cb => {
        const row = cb.closest('tr');
        if (row && row.style.display !== 'none') {
            cb.checked = isChecked;
            const id = cb.value;
            const name = cb.getAttribute('data-name') || '';
            const roll = cb.getAttribute('data-roll') || '';
            if (isChecked) {
                selectedStudents.set(id, { name, roll, sem: semNum });
                row.classList.add('row-selected');
            } else {
                selectedStudents.delete(id);
                row.classList.remove('row-selected');
            }
        }
    });
    updateBulkBar();
    updateCohortSelectAllState();
}

function selectCohortOnly(semNum) {
    const semCbs = document.querySelectorAll(`.sem-cb-${semNum}`);
    // If all are already checked, uncheck all. Otherwise, check all.
    const visibleCbs = Array.from(semCbs).filter(cb => {
        const r = cb.closest('tr');
        return r && r.style.display !== 'none';
    });
    const allChecked = visibleCbs.length > 0 && visibleCbs.every(cb => cb.checked);
    const targetState = !allChecked;

    visibleCbs.forEach(cb => {
        cb.checked = targetState;
        const row = cb.closest('tr');
        const id = cb.value;
        const name = cb.getAttribute('data-name') || '';
        const roll = cb.getAttribute('data-roll') || '';
        if (targetState) {
            selectedStudents.set(id, { name, roll, sem: semNum });
            if (row) row.classList.add('row-selected');
        } else {
            selectedStudents.delete(id);
            if (row) row.classList.remove('row-selected');
        }
    });

    const headerCb = document.querySelector(`.cohort-select-all[data-sem="${semNum}"]`);
    if (headerCb) {
        headerCb.checked = targetState;
        headerCb.indeterminate = false;
    }
    updateBulkBar();
    updateCohortSelectAllState();
}

function selectAllVisibleStudents(select) {
    const rows = document.querySelectorAll('.student-data-table tbody tr.student-row');
    rows.forEach(r => {
        if (r.style.display !== 'none') {
            const cb = r.querySelector('.student-cb');
            if (cb) {
                cb.checked = select;
                const id = cb.value;
                const name = cb.getAttribute('data-name') || '';
                const roll = cb.getAttribute('data-roll') || '';
                const sem = cb.getAttribute('data-sem') || '';
                if (select) {
                    selectedStudents.set(id, { name, roll, sem });
                    r.classList.add('row-selected');
                } else {
                    selectedStudents.delete(id);
                    r.classList.remove('row-selected');
                }
            }
        }
    });
    updateBulkBar();
    updateCohortSelectAllState();
}

function clearAllSelections() {
    selectedStudents.clear();
    document.querySelectorAll('.student-cb').forEach(cb => {
        cb.checked = false;
        const r = cb.closest('tr');
        if (r) r.classList.remove('row-selected');
    });
    document.querySelectorAll('.cohort-select-all').forEach(cb => {
        cb.checked = false;
        cb.indeterminate = false;
    });
    updateBulkBar();
}

function updateCohortSelectAllState() {
    document.querySelectorAll('.cohort-select-all').forEach(headerCb => {
        const sem = headerCb.getAttribute('data-sem');
        const cbs = Array.from(document.querySelectorAll(`.sem-cb-${sem}`)).filter(c => {
            const r = c.closest('tr');
            return r && r.style.display !== 'none';
        });
        if (cbs.length > 0) {
            const checkedCount = cbs.filter(c => c.checked).length;
            headerCb.checked = (checkedCount === cbs.length);
            headerCb.indeterminate = (checkedCount > 0 && checkedCount < cbs.length);
        } else {
            headerCb.checked = false;
            headerCb.indeterminate = false;
        }
    });
}

function updateBulkBar() {
    const bar = document.getElementById('bulkActionBar');
    const count = selectedStudents.size;
    const countEl = document.getElementById('bulkSelectedCount');
    const btnCountEl = document.getElementById('bulkDelBtnCount');
    const subtitleEl = document.getElementById('bulkSelectedSubtitle');

    if (countEl) countEl.innerText = count;
    if (btnCountEl) btnCountEl.innerText = count;
    if (subtitleEl) subtitleEl.innerText = `${count} student${count === 1 ? '' : 's'} ready for bulk actions`;

    if (count > 0) {
        bar.classList.add('visible');
    } else {
        bar.classList.remove('visible');
    }
}

function openBulkDeleteModal() {
    if (selectedStudents.size === 0) return;
    const count = selectedStudents.size;
    const ids = Array.from(selectedStudents.keys());

    document.getElementById('delStudentId').value = '';
    document.getElementById('delStudentIds').value = ids.join(',');

    document.getElementById('delModalTitle').innerText = `Delete ${count} Selected Students`;
    document.getElementById('delPrompt').innerHTML = `Are you sure you want to permanently delete <strong>${count} selected student${count === 1 ? '' : 's'}</strong> at once? This will remove them from the roster, dedicated cohort tables, and any linked attendance logs.`;

    let previewHtml = '<div style="max-height:160px;overflow-y:auto;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:12px 14px;text-align:left;">';
    previewHtml += `<div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Selected Students List (${count}):</div>`;
    selectedStudents.forEach((st) => {
        previewHtml += `<div style="font-size:12.5px;color:#0f172a;padding:4px 0;display:flex;align-items:center;justify-content:space-between;border-bottom:1px dashed #e2e8f0;">
            <span><strong style="font-family:monospace;background:#eef2ff;color:#4338ca;padding:1px 6px;border-radius:4px;font-size:11.5px;margin-right:6px;">${st.roll}</strong> ${st.name}</span>
            <span style="font-size:11px;color:#64748b;font-weight:600;">Sem ${st.sem || ''}</span>
        </div>`;
    });
    previewHtml += '</div>';

    const container = document.getElementById('delPreviewContainer');
    container.innerHTML = previewHtml;
    container.style.display = 'block';

    const confirmBtn = document.getElementById('delConfirmBtn');
    confirmBtn.innerText = `Delete ${count} Student${count === 1 ? '' : 's'}`;
    document.getElementById('deleteModal').style.display = 'flex';
}

function searchStudents() {
    const q = document.getElementById('studentSearch').value.trim().toLowerCase();
    const rows = document.querySelectorAll('.student-data-table tbody tr');
    rows.forEach(r => {
        const name = r.getAttribute('data-name') || '';
        const roll = r.getAttribute('data-roll') || '';
        const enroll = r.getAttribute('data-enroll') || '';
        if (!q || name.includes(q) || roll.includes(q) || enroll.includes(q)) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
    });
    updateCohortSelectAllState();
}

function openQuickAddModal(programId, semester) {
    document.getElementById('addProgramId').value = programId;
    if (semester) {
        document.getElementById('addSemester').value = semester;
    }
    document.getElementById('addRollNo').value = '';
    document.getElementById('addStudentName').value = '';
    document.getElementById('addEnrollNo').value = '';
    document.getElementById('quickAddModal').style.display = 'flex';
}

function closeQuickAddModal() {
    document.getElementById('quickAddModal').style.display = 'none';
}

function openQuickEditModal(id, name, roll, enroll, status) {
    document.getElementById('editStudentId').value = id;
    document.getElementById('editStudentName').value = name;
    document.getElementById('editRollNo').value = roll;
    document.getElementById('editEnrollNo').value = enroll;
    document.getElementById('editStatus').value = status || 'active';
    document.getElementById('quickEditModal').style.display = 'flex';
}

function closeQuickEditModal() {
    document.getElementById('quickEditModal').style.display = 'none';
}

function confirmDelete(id, name, roll) {
    document.getElementById('delStudentId').value = id;
    document.getElementById('delStudentIds').value = '';
    document.getElementById('delModalTitle').innerText = 'Delete Student Record';
    document.getElementById('delPrompt').innerHTML = `Are you sure you want to remove <strong>${name}</strong> (Roll: ${roll})? Any past attendance links for this student will also be removed.`;
    document.getElementById('delPreviewContainer').innerHTML = '';
    document.getElementById('delPreviewContainer').style.display = 'none';
    document.getElementById('delConfirmBtn').innerText = 'Confirm Delete';
    document.getElementById('deleteModal').style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}
</script>
</body>
</html>
