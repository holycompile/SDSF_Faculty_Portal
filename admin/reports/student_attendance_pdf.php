<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Authorization: Admin or Faculty
$isAdmin = !empty($_SESSION['admin_username']);
$facultySessionId = (int)($_SESSION['faculty_id'] ?? 0);

if (!$isAdmin && !$facultySessionId) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$courseId  = (int)($_GET['course_id'] ?? 0);
$lectureId = (int)($_GET['lecture_id'] ?? 0);
$archiveId = (int)($_GET['archive_id'] ?? 0);

$course = null;
$faculty = null;
$students = [];
$attCols = [];
$lectures = [];
$sheetTitle = 'Student Attendance Register';
$cohortTableName = '';
$avgCohortPct = 0;

// Case 1: From Old Records / Archive
if ($archiveId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM archived_faculty_records WHERE id = ?");
    $stmt->execute([$archiveId]);
    $arch = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$arch) die("Archived record not found.");

    $faculty = [
        'name' => $arch['name'],
        'faculty_enrollment_no' => $arch['faculty_enrollment_no'],
        'department' => $arch['department'] ?: 'SDSF'
    ];

    $coursesData    = json_decode($arch['courses_data_json'] ?? '[]', true);
    $lecturesData   = json_decode($arch['lectures_data_json'] ?? '[]', true);
    $attendanceData = json_decode($arch['attendance_data_json'] ?? '[]', true);

    if ($lectureId > 0) {
        // Specific lecture from archive
        foreach ($lecturesData as $lec) {
            if ((int)$lec['id'] === $lectureId) {
                $course = [
                    'subject_name' => $lec['subject_name'] ?? 'Subject',
                    'course_code'  => $lec['course_code'] ?? '—',
                    'program'      => $lec['program'] ?? 'SDSF',
                    'semester'     => $lec['semester'] ?? '—',
                    'class_type'   => $lec['class_type'] ?? 'T',
                    'lecture_date' => $lec['lecture_date']
                ];
                break;
            }
        }
        $attCols = ['att_' . str_replace('-', '_', $course['lecture_date'] ?? date('Y_m_d'))];
        foreach ($attendanceData as $att) {
            if ((int)$att['lecture_id'] === $lectureId) {
                $colKey = $attCols[0];
                $students[] = [
                    'roll_no'        => $att['roll_no'] ?? '—',
                    'student_name'   => $att['student_name'] ?? 'Student',
                    'enrollment_no'  => $att['enrollment_no'] ?? '—',
                    $colKey          => ($att['status'] === 'present') ? 1 : 0,
                    'present_count'  => ($att['status'] === 'present') ? 1 : 0,
                    'total_classes'  => 1,
                    'attendance_pct' => ($att['status'] === 'present') ? 100 : 0
                ];
            }
        }
    } else {
        // Course level from archive
        foreach ($coursesData as $c) {
            if ((int)($c['course_id'] ?? 0) === $courseId || $courseId === 0) {
                $course = $c;
                $courseId = (int)($c['course_id'] ?? 0);
                break;
            }
        }
        if (!$course && !empty($coursesData)) {
            $course = $coursesData[0];
            $courseId = (int)$course['course_id'];
        }

        // Filter lectures and attendance for this course
        $courseLecs = array_filter($lecturesData, fn($l) => (int)($l['course_id'] ?? 0) === $courseId);
        $cLecIds = array_column($courseLecs, 'id');

        $studentMap = [];
        foreach ($courseLecs as $l) {
            $colName = 'att_' . str_replace('-', '_', $l['lecture_date']);
            if (!in_array($colName, $attCols)) {
                $attCols[] = $colName;
            }
        }

        foreach ($attendanceData as $att) {
            if (in_array((int)$att['lecture_id'], $cLecIds)) {
                $r = (string)$att['roll_no'];
                if (!isset($studentMap[$r])) {
                    $studentMap[$r] = [
                        'roll_no' => $r,
                        'student_name' => $att['student_name'],
                        'enrollment_no' => $att['enrollment_no'],
                        'present_count' => 0,
                        'total_classes' => count($attCols)
                    ];
                }
                // Find lecture date
                foreach ($courseLecs as $cl) {
                    if ((int)$cl['id'] === (int)$att['lecture_id']) {
                        $col = 'att_' . str_replace('-', '_', $cl['lecture_date']);
                        $val = ($att['status'] === 'present') ? 1 : 0;
                        $studentMap[$r][$col] = $val;
                        if ($val === 1) $studentMap[$r]['present_count']++;
                        break;
                    }
                }
            }
        }
        foreach ($studentMap as &$st) {
            $st['total_classes'] = count($attCols);
            $st['attendance_pct'] = (count($attCols) > 0) ? round(($st['present_count'] / count($attCols)) * 100, 1) : 0;
        }
        unset($st);
        $students = array_values($studentMap);
    }
} elseif ($lectureId > 0) {
    // Case 2: Specific Single Lecture Sheet
    $stmt = $pdo->prepare("
        SELECT le.*, c.subject_name, c.course_code, c.program, c.semester, c.batch_year, c.class_type,
               fm.name AS faculty_name, fm.faculty_enrollment_no, fm.department
        FROM lecture_entries le
        JOIN courses c ON c.id = le.course_id
        JOIN faculty_members fm ON fm.id = le.faculty_id
        WHERE le.id = ?
    ");
    $stmt->execute([$lectureId]);
    $lec = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lec) die("Lecture record not found.");

    // Security check: if faculty, must be their own lecture
    if (!$isAdmin && (int)$lec['faculty_id'] !== $facultySessionId) {
        die("Access denied.");
    }

    $course = $lec;
    $faculty = [
        'name' => $lec['faculty_name'],
        'faculty_enrollment_no' => $lec['faculty_enrollment_no'],
        'department' => $lec['department'] ?: 'SDSF'
    ];

    $dClean = date('d/m/Y', strtotime($lec['lecture_date']));
    $colKey = 'att_' . date('Y_m_d', strtotime($lec['lecture_date']));
    $attCols = [$colKey];

    // Fetch student attendances for this lecture
    $aStmt = $pdo->prepare("
        SELECT sa.status, s.roll_no, s.student_name, s.enrollment_no
        FROM student_attendance sa
        JOIN students s ON s.id = sa.student_id
        WHERE sa.lecture_id = ?
        ORDER BY CAST(s.roll_no AS UNSIGNED) ASC, s.roll_no ASC, s.student_name ASC
    ");
    $aStmt->execute([$lectureId]);
    $rawSt = $aStmt->fetchAll(PDO::FETCH_ASSOC);

    $presCount = 0;
    foreach ($rawSt as $rst) {
        $isP = ($rst['status'] === 'present');
        if ($isP) $presCount++;
        $students[] = [
            'roll_no'        => $rst['roll_no'],
            'student_name'   => $rst['student_name'],
            'enrollment_no'  => $rst['enrollment_no'],
            $colKey          => $isP ? 1 : 0,
            'present_count'  => $isP ? 1 : 0,
            'total_classes'  => 1,
            'attendance_pct' => $isP ? 100 : 0
        ];
    }
    $avgCohortPct = count($students) > 0 ? round(($presCount / count($students)) * 100, 1) : 0;
    $sheetTitle = "Lecture Attendance Sheet — " . date('d M Y', strtotime($lec['lecture_date']));

} else {
    // Case 3: Course / Subject Cohort Matrix Sheet
    $cStmt = $pdo->prepare("
        SELECT c.*, ap.program_name, fm.name AS faculty_name, fm.faculty_enrollment_no, fm.department, fm.id AS f_id
        FROM courses c
        LEFT JOIN academic_programs ap ON ap.id = c.program_id
        LEFT JOIN faculty_course_assignments fca ON fca.course_id = c.id
        LEFT JOIN faculty_members fm ON fm.id = fca.faculty_id
        WHERE c.id = ?
    ");
    $cStmt->execute([$courseId]);
    $course = $cStmt->fetch(PDO::FETCH_ASSOC);

    if (!$course) die("Subject / Course not found.");

    // Security check: if faculty, verify course assignment
    if (!$isAdmin) {
        $chk = $pdo->prepare("SELECT id FROM faculty_course_assignments WHERE course_id = ? AND faculty_id = ?");
        $chk->execute([$courseId, $facultySessionId]);
        if (!$chk->fetchColumn()) {
            die("Access denied: You are not assigned to this course.");
        }
        $fStmt = $pdo->prepare("SELECT name, faculty_enrollment_no, department FROM faculty_members WHERE id = ?");
        $fStmt->execute([$facultySessionId]);
        $faculty = $fStmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $faculty = [
            'name' => $course['faculty_name'] ?: 'Visiting Faculty',
            'faculty_enrollment_no' => $course['faculty_enrollment_no'] ?: '—',
            'department' => $course['department'] ?: 'SDSF'
        ];
    }

    $pName = $course['program_name'] ?? $course['program'];
    $matrix = getCohortAttendanceMatrix($pdo, $pName, $course['semester'], $courseId);

    if ($matrix) {
        $students = $matrix['students'] ?? [];
        $attCols = $matrix['attendance_cols'] ?? [];
        $cohortTableName = $matrix['table_name'] ?? '';
        $avgCohortPct = $matrix['avg_attendance'] ?? 0;
    }

    $sheetTitle = "Curriculum Attendance Register — " . ($course['subject_name'] ?? 'Course');
}

$totalPresentAll = 0;
$totalPossibleAll = count($students) * count($attCols);
foreach ($students as $st) {
    $totalPresentAll += (int)($st['present_count'] ?? 0);
}
if ($totalPossibleAll > 0) {
    $avgCohortPct = round(($totalPresentAll / $totalPossibleAll) * 100, 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($sheetTitle) ?> — <?= htmlspecialchars($course['subject_name'] ?? 'Attendance') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ─── RESET & FONTS ──────────────────────────────────────────────────────── */
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    background-color: #f8fafc;
    color: #0f172a;
    font-family: 'Times New Roman', Times, serif;
    font-size: 10pt;
    line-height: 1.25;
}

/* ─── SCREEN TOPBAR ──────────────────────────────────────────────────────── */
.screen-topbar {
    position: sticky;
    top: 0;
    z-index: 1000;
    background: #0f172a;
    color: #ffffff;
    padding: 10px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    font-family: 'Inter', sans-serif;
    box-shadow: 0 4px 12px rgba(0,0,0,0.25);
}
.btn-topbar {
    padding: 7px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border: none;
    font-family: 'Inter', sans-serif;
    transition: all .18s ease;
}
.btn-print {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #ffffff;
    box-shadow: 0 2px 8px rgba(37,99,235,0.4);
}
.btn-print:hover { opacity: 0.95; transform: translateY(-1px); }
.btn-close {
    background: #334155;
    color: #ffffff;
}
.btn-close:hover { background: #475569; }

/* ─── A4 LANDSCAPE PAPER CONTAINER ───────────────────────────────────────── */
.sheet-container {
    width: 297mm;
    min-height: 210mm;
    margin: 20px auto 40px auto;
    background: #ffffff;
    padding: 10mm 12mm;
    box-shadow: 0 4px 24px rgba(0,0,0,0.12);
    position: relative;
    display: flex;
    flex-direction: column;
}

@page {
    size: A4 landscape;
    margin: 8mm 10mm 8mm 10mm;
}

@media print {
    body { background: #ffffff !important; }
    .no-print { display: none !important; }
    .sheet-container {
        width: 100% !important;
        min-height: auto !important;
        margin: 0 !important;
        padding: 0 !important;
        box-shadow: none !important;
    }
}

/* ─── UNIVERSITY HEADER ──────────────────────────────────────────────────── */
.header-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 8px;
}
.header-logo {
    width: 60px;
    text-align: center;
    vertical-align: middle;
}
.header-logo img {
    max-width: 58px;
    max-height: 58px;
    object-fit: contain;
}
.header-center {
    text-align: center;
    vertical-align: middle;
    padding: 0 10px;
}
.uni-name {
    font-size: 15pt;
    font-weight: bold;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #000;
}
.dept-name {
    font-size: 11.5pt;
    font-weight: bold;
    color: #1e3a8a;
    margin-top: 2px;
    letter-spacing: 0.3px;
}
.doc-title {
    font-size: 11pt;
    font-weight: bold;
    text-transform: uppercase;
    text-decoration: underline;
    margin-top: 4px;
    letter-spacing: 0.8px;
}

/* ─── METADATA GRID ──────────────────────────────────────────────────────── */
.meta-box {
    border: 1.5px solid #000;
    margin-bottom: 10px;
    background: #fff;
}
.meta-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 9.5pt;
}
.meta-table td {
    padding: 4px 8px;
    border-bottom: 1px solid #ddd;
}
.meta-table tr:last-child td {
    border-bottom: none;
}
.meta-table td strong {
    font-family: 'Times New Roman', serif;
}

/* ─── ATTENDANCE MATRIX TABLE ────────────────────────────────────────────── */
.att-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 9pt;
    border: 1.5px solid #000;
}
.att-table th, .att-table td {
    border: 1px solid #666;
    padding: 4px 4px;
    text-align: center;
}
.att-table th {
    background: #f1f5f9;
    font-weight: bold;
    color: #000;
    text-transform: uppercase;
    font-size: 8.5pt;
}
.att-table td.text-left {
    text-align: left;
    padding-left: 6px;
}
.att-table tr:nth-child(even) td {
    background: #fafafa;
}
.att-present {
    font-weight: bold;
    color: #047857;
}
.att-absent {
    font-weight: bold;
    color: #dc2626;
}

/* ─── SUMMARY & SIGNATURES ───────────────────────────────────────────────── */
.summary-bar {
    margin-top: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 9.5pt;
    font-weight: bold;
    padding: 6px 8px;
    background: #f8fafc;
    border: 1px solid #999;
}
.sign-box {
    margin-top: 36px;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    font-size: 9.5pt;
    padding: 0 10px;
}
.sign-col {
    text-align: center;
    min-width: 180px;
}
.sign-line {
    border-top: 1px dashed #000;
    margin-bottom: 5px;
    width: 100%;
}
</style>
</head>
<body>

    <!-- Screen Toolbar -->
    <div class="screen-topbar no-print">
        <div style="display:flex;align-items:center;gap:12px;">
            <button type="button" class="btn-topbar btn-close" onclick="window.history.back()">
                &larr; Back
            </button>
            <div style="font-size:14px;color:#cbd5e1;">
                <strong><?= htmlspecialchars($sheetTitle) ?></strong> &bull; <?= htmlspecialchars($course['subject_name'] ?? 'Attendance') ?>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:12px;">
            <span style="font-size:12px;color:#94a3b8;">Format: A4 Landscape</span>
            <button type="button" class="btn-topbar btn-print" onclick="window.print()">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Print / Save as PDF
            </button>
        </div>
    </div>

    <!-- Printable Paper Sheet -->
    <div class="sheet-container">
        <!-- Header -->
        <table class="header-table">
            <tr>
                <td class="header-logo">
                    <img src="<?= BASE_URL ?>/assets/davvLogo.png" alt="DAVV Crest" onerror="this.src='<?= BASE_URL ?>/assets/davv_logo.png'">
                </td>
                <td class="header-center">
                    <div class="uni-name">Devi Ahilya Vishwavidyalaya, Indore</div>
                    <div class="dept-name">School of Data Science &amp; Forecasting (SDSF)</div>
                    <div class="doc-title"><?= htmlspecialchars($sheetTitle) ?></div>
                </td>
                <td class="header-logo">
                    <img src="<?= BASE_URL ?>/assets/departmentlogo_transparent.png" alt="SDSF Emblem">
                </td>
            </tr>
        </table>

        <!-- Metadata Box -->
        <div class="meta-box">
            <table class="meta-table">
                <tr>
                    <td style="width:33%;">
                        <strong>Academic Program:</strong> <?= htmlspecialchars($course['program'] ?? ($course['program_name'] ?? 'SDSF')) ?>
                    </td>
                    <td style="width:34%;">
                        <strong>Subject / Course:</strong> <?= htmlspecialchars($course['subject_name'] ?? '—') ?>
                    </td>
                    <td style="width:33%;">
                        <strong>Subject Code:</strong> <?= htmlspecialchars($course['course_code'] ?? '—') ?>
                    </td>
                </tr>
                <tr>
                    <td>
                        <strong>Semester:</strong> <?= htmlspecialchars($course['semester'] ?? '—') ?> <?= !empty($course['batch_year']) ? '('.htmlspecialchars($course['batch_year']).')' : '' ?>
                    </td>
                    <td>
                        <strong>Class Type:</strong> <?= ($course['class_type'] ?? 'T') === 'P' ? 'Practical / Lab (P)' : 'Theory (T)' ?>
                    </td>
                    <td>
                        <strong>Faculty Member:</strong> <?= htmlspecialchars($faculty['name'] ?? 'Visiting Faculty') ?> (<?= htmlspecialchars($faculty['faculty_enrollment_no'] ?? '—') ?>)
                    </td>
                </tr>
            </table>
        </div>

        <!-- Attendance Matrix Table -->
        <table class="att-table">
            <thead>
                <tr>
                    <th style="width:32px;">#</th>
                    <th style="width:68px;">Roll No</th>
                    <th style="width:110px;">Enrollment No</th>
                    <th style="text-align:left;min-width:140px;padding-left:6px;">Student Name</th>
                    <?php if (empty($attCols)): ?>
                        <th style="color:#666;font-style:italic;">No recorded dates</th>
                    <?php else: ?>
                        <?php foreach ($attCols as $col): 
                            $d = str_replace('att_', '', $col);
                            $dClean = preg_replace('/^c\d+_/', '', $d);
                            $dClean = preg_replace('/_c\d+$/', '', $dClean);
                            $isS2 = str_contains($dClean, '_s2');
                            $dClean = preg_replace('/_s\d+$/', '', $dClean);
                            $parts = explode('_', $dClean);
                            $colLabel = (count($parts) >= 3) ? ($parts[2] . '/' . $parts[1]) : $dClean;
                            if ($isS2) $colLabel .= ' (S2)';
                        ?>
                            <th style="width:38px;font-size:7.5pt;" title="Date: <?= htmlspecialchars($dClean) ?>">
                                <?= htmlspecialchars($colLabel) ?>
                            </th>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <th style="width:50px;">Pres.</th>
                    <th style="width:48px;">Total</th>
                    <th style="width:52px;">%</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="<?= count($attCols) + 7 ?>" style="padding:24px;text-align:center;color:#666;font-style:italic;">
                            No student attendance records recorded for this subject cohort.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $sIdx => $st): 
                        $pres = (int)($st['present_count'] ?? 0);
                        $tot = (int)($st['total_classes'] ?? count($attCols));
                        $pct = (float)($st['attendance_pct'] ?? 0);
                    ?>
                    <tr>
                        <td><?= $sIdx + 1 ?></td>
                        <td style="font-weight:bold;font-family:monospace;"><?= htmlspecialchars($st['roll_no']) ?></td>
                        <td style="font-family:monospace;font-size:8.5pt;"><?= htmlspecialchars($st['enrollment_no'] ?: '—') ?></td>
                        <td class="text-left" style="font-weight:600;"><?= htmlspecialchars($st['student_name']) ?></td>
                        <?php if (empty($attCols)): ?>
                            <td style="color:#999;">—</td>
                        <?php else: ?>
                            <?php foreach ($attCols as $col): 
                                $val = $st[$col] ?? null;
                            ?>
                                <td>
                                    <?php if ($val === 1 || $val === '1'): ?>
                                        <span class="att-present">1</span>
                                    <?php elseif ($val === 0 || $val === '0'): ?>
                                        <span class="att-absent">0</span>
                                    <?php else: ?>
                                        <span style="color:#bbb;">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <td style="font-weight:bold;"><?= $pres ?></td>
                        <td><?= $tot ?></td>
                        <td style="font-weight:bold;color:<?= $pct >= 75 ? '#047857' : ($pct >= 50 ? '#b45309' : '#dc2626') ?>;">
                            <?= $pct ?>%
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Summary Bar -->
        <div class="summary-bar">
            <div>
                Total Enrolled Students: <strong><?= count($students) ?></strong> &bull;
                Total Sessions Held: <strong><?= count($attCols) ?></strong>
            </div>
            <div>
                Legend: <strong>1</strong> = Present &bull; <strong>0</strong> = Absent &bull; <strong>—</strong> = Unmarked
            </div>
            <div>
                Cohort Average Attendance: <strong><?= $avgCohortPct ?>%</strong>
            </div>
        </div>

        <!-- Verification Signature Blocks -->
        <div class="sign-box">
            <div class="sign-col">
                <div class="sign-line"></div>
                <div>Signature of Visiting Faculty</div>
                <div style="font-size:8pt;color:#555;"><?= htmlspecialchars($faculty['name'] ?? 'Faculty In-charge') ?></div>
            </div>
            <div class="sign-col">
                <div class="sign-line"></div>
                <div>Course In-charge / Coordinator</div>
                <div style="font-size:8pt;color:#555;">SDSF, DAVV, Indore</div>
            </div>
            <div class="sign-col">
                <div class="sign-line"></div>
                <div>Head of Department</div>
                <div style="font-size:8pt;color:#555;">School of Data Science &amp; Forecasting</div>
            </div>
        </div>
    </div>

</body>
</html>
