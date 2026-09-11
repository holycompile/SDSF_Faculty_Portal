<?php
/**
 * Shared Bootstrap for SDSF Faculty Reports
 * Loads DB, Auth, Faculty, Lecture Data, Aggregations & Snapshot Persistence
 */
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$facultyId = (int)($_GET['faculty_id'] ?? ($_SESSION['faculty_id'] ?? 0));
if (!$facultyId) {
    die("<div style='font-family:sans-serif;padding:30px;color:#c00;'><h3>Error: Invalid or Missing Faculty ID</h3><p><a href='javascript:history.back()'>Go Back</a></p></div>");
}

// Session authorization: Admin OR the faculty member themselves
$isAdmin = !empty($_SESSION['admin_username']);
$isFaculty = !empty($_SESSION['faculty_id']) && (int)$_SESSION['faculty_id'] === $facultyId;

if (!$isAdmin && !$isFaculty) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$month = (int)($_GET['month'] ?? date('m'));
if ($month < 1 || $month > 12) $month = (int)date('m');

$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2020 || $year > 2035) $year = (int)date('Y');

// Fetch Faculty Record
$fStmt = $pdo->prepare("SELECT * FROM faculty_members WHERE id = ?");
$fStmt->execute([$facultyId]);
$faculty = $fStmt->fetch();
if (!$faculty) {
    die("<div style='font-family:sans-serif;padding:30px;color:#c00;'><h3>Faculty Member Not Found</h3></div>");
}

// Check existing monthly snapshot
$snapStmt = $pdo->prepare("SELECT * FROM monthly_report_submissions WHERE faculty_id = ? AND month = ? AND year = ?");
$snapStmt->execute([$facultyId, $month, $year]);
$existingSnapshot = $snapStmt->fetch();

// Default or existing submission date
$submissionDate = trim($_GET['submission_date'] ?? ($existingSnapshot['submission_date'] ?? date('Y-m-d')));
if (strpos($submissionDate, '/') !== false) {
    // Convert DD/MM/YYYY to YYYY-MM-DD
    $dParts = explode('/', $submissionDate);
    if (count($dParts) === 3) {
        $submissionDate = $dParts[2] . '-' . str_pad($dParts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($dParts[0], 2, '0', STR_PAD_LEFT);
    }
}
$displaySubmissionDate = date('d-m-Y', strtotime($submissionDate));

// Page / S.No. of Attendance Register
$attendanceRegPage = trim($_GET['page_no'] ?? ($existingSnapshot['attendance_register_page'] ?? 'Page 02 - S.No. - 19'));
$chequeNo = trim($_GET['cheque_no'] ?? ($existingSnapshot['cheque_no'] ?? ''));

// Fetch all lectures for this month & year
$lStmt = $pdo->prepare("
    SELECT le.*, c.id as c_id, c.program, c.semester, c.subject_name, c.course_code, c.class_type
    FROM lecture_entries le
    JOIN courses c ON c.id = le.course_id
    WHERE le.faculty_id = ? AND MONTH(le.lecture_date) = ? AND YEAR(le.lecture_date) = ?
    ORDER BY le.lecture_date ASC, c.subject_name ASC
");
$lStmt->execute([$facultyId, $month, $year]);
$allLectures = $lStmt->fetchAll();

// Aggregations
$totalTheoryHours = 0.0;
$totalPracticalHours = 0.0;
$totalTutorialHours = 0.0;
$programsList = [];
$groupedByCourse = [];
$dailyLectures = [];

foreach ($allLectures as $lec) {
    $hrs = (float)$lec['hours'];
    $cType = strtoupper(trim($lec['class_type'] ?? 'T'));
    
    if ($cType === 'P') {
        $totalPracticalHours += $hrs;
    } elseif ($cType === 'TUT' || $cType === 'TUTORIAL') {
        $totalTutorialHours += $hrs;
    } else {
        $totalTheoryHours += $hrs;
    }
    
    if (!empty($lec['program']) && !in_array($lec['program'], $programsList)) {
        $programsList[] = $lec['program'];
    }
    
    // Group by Course for Annexure-IV
    $cid = $lec['c_id'];
    if (!isset($groupedByCourse[$cid])) {
        $groupedByCourse[$cid] = [
            'program'      => $lec['program'],
            'semester'     => $lec['semester'],
            'subject_name' => $lec['subject_name'],
            'course_code'  => $lec['course_code'],
            'class_type'   => $cType,
            'rate'         => (float)$lec['rate_per_hour'],
            'total_hours'  => 0.0,
            'total_amount' => 0.0,
            'dates'        => []
        ];
    }
    $groupedByCourse[$cid]['total_hours']  += $hrs;
    $groupedByCourse[$cid]['total_amount'] += (float)$lec['amount'];
    $groupedByCourse[$cid]['dates'][] = date('d/m', strtotime($lec['lecture_date'])) . '(' . (float)$hrs . ')';

    // Daily breakdown for Annexure IV-A and Attendance Matrix
    $dateKey = $lec['lecture_date'];
    if (!isset($dailyLectures[$dateKey])) {
        $dailyLectures[$dateKey] = [
            'date'      => $dateKey,
            'day'       => date('D', strtotime($dateKey)),
            'subjects'  => [],
            'theory'    => 0.0,
            'tutorial'  => 0.0,
            'practical' => 0.0,
            'total'     => 0.0,
            'by_course' => [] // for attendance matrix
        ];
    }
    if (!in_array($lec['subject_name'], $dailyLectures[$dateKey]['subjects'])) {
        $dailyLectures[$dateKey]['subjects'][] = $lec['subject_name'];
    }
    if ($cType === 'P') {
        $dailyLectures[$dateKey]['practical'] += $hrs;
    } elseif ($cType === 'TUT') {
        $dailyLectures[$dateKey]['tutorial'] += $hrs;
    } else {
        $dailyLectures[$dateKey]['theory'] += $hrs;
    }
    $dailyLectures[$dateKey]['total'] += $hrs;
    
    // Matrix tracking
    if (!isset($dailyLectures[$dateKey]['by_course'][$cid])) {
        $dailyLectures[$dateKey]['by_course'][$cid] = ['theory' => 0.0, 'practical' => 0.0];
    }
    if ($cType === 'P') {
        $dailyLectures[$dateKey]['by_course'][$cid]['practical'] += $hrs;
    } else {
        $dailyLectures[$dateKey]['by_course'][$cid]['theory'] += $hrs;
    }
}

$grandTotalHours = $totalTheoryHours + $totalPracticalHours + $totalTutorialHours;
$theoryRate = 800.00;
$practicalRate = 400.00;
$theoryAmount = $totalTheoryHours * $theoryRate;
$practicalAmount = $totalPracticalHours * $practicalRate;
$grandTotalAmount = $theoryAmount + $practicalAmount;

$programsCoveredStr = !empty($programsList) ? implode(', ', $programsList) : 'School of Data Science & Forecasting';
$monthNameStr = date('F', mktime(0, 0, 0, $month, 1));
$monthShortStr = strtoupper(date('M', mktime(0, 0, 0, $month, 1)));
$sessionStr = ($month >= 7) ? ('July to Dec ' . $year) : ('Jan to June ' . $year);
$academicYearStr = $year . '-' . substr((string)($year + 1), -2);
$deptName = !empty($faculty['department']) ? $faculty['department'] : 'School of Data Science & Forecasting';

// ─── UPSERT PERMANENT MONTHLY SNAPSHOT ─────────────────────────────────────────
try {
    $upsertStmt = $pdo->prepare("
        INSERT INTO monthly_report_submissions (
            faculty_id, month, year, submission_date, attendance_register_page, cheque_no,
            theory_hours, tutorial_hours, practical_hours, total_hours,
            theory_rate, practical_rate, theory_amount, practical_amount, total_amount,
            programs_covered, status
        ) VALUES (
            :faculty_id, :month, :year, :sub_date, :page_no, :cheque_no,
            :theory_hours, :tutorial_hours, :practical_hours, :total_hours,
            :theory_rate, :practical_rate, :theory_amount, :practical_amount, :total_amount,
            :programs, 'submitted'
        )
        ON DUPLICATE KEY UPDATE
            submission_date = VALUES(submission_date),
            attendance_register_page = COALESCE(VALUES(attendance_register_page), attendance_register_page),
            cheque_no = COALESCE(VALUES(cheque_no), cheque_no),
            theory_hours = VALUES(theory_hours),
            tutorial_hours = VALUES(tutorial_hours),
            practical_hours = VALUES(practical_hours),
            total_hours = VALUES(total_hours),
            theory_amount = VALUES(theory_amount),
            practical_amount = VALUES(practical_amount),
            total_amount = VALUES(total_amount),
            programs_covered = VALUES(programs_covered)
    ");
    $upsertStmt->execute([
        ':faculty_id'        => $facultyId,
        ':month'             => $month,
        ':year'              => $year,
        ':sub_date'          => $submissionDate,
        ':page_no'           => $attendanceRegPage,
        ':cheque_no'         => $chequeNo,
        ':theory_hours'      => $totalTheoryHours,
        ':tutorial_hours'    => $totalTutorialHours,
        ':practical_hours'   => $totalPracticalHours,
        ':total_hours'       => $grandTotalHours,
        ':theory_rate'       => $theoryRate,
        ':practical_rate'    => $practicalRate,
        ':theory_amount'     => $theoryAmount,
        ':practical_amount'  => $practicalAmount,
        ':total_amount'      => $grandTotalAmount,
        ':programs'          => $programsCoveredStr
    ]);
} catch (Exception $e) {
    // Non-blocking fallback if snapshot table write experiences minor lock
    error_log("Snapshot error: " . $e->getMessage());
}

// ─── HELPER: Render UVFIN Box ─────────────────────────────────────────────────
function renderUVFINBoxes(?string $enrollNo, int $minBoxes = 10): string {
    $clean = strtoupper(trim((string)$enrollNo));
    $chars = str_split($clean);
    while (count($chars) < $minBoxes) {
        $chars[] = '';
    }
    $html = '<div class="uvfin-container">';
    foreach ($chars as $ch) {
        $html .= '<span class="uvfin-cell">' . ($ch !== '' ? htmlspecialchars($ch) : '&nbsp;') . '</span>';
    }
    $html .= '</div>';
    return $html;
}

// ─── HELPER: Render Shared Top Navigation Bar ─────────────────────────────────
function renderReportNavBar(string $activeKey, int $facultyId, int $month, int $year, string $facultyName, $isAdmin): string {
    $qParams = "faculty_id={$facultyId}&month={$month}&year={$year}";
    $backUrl = $isAdmin 
        ? BASE_URL . "/admin/lectures/overview.php?month={$month}&year={$year}"
        : BASE_URL . "/faculty/history.php";

    $tabs = [
        'annexure_iv' => [
            'title' => 'Annexure-IV (Claim Bill)',
            'file'  => 'annexure_iv.php',
            'icon'  => '📄'
        ],
        'attendance' => [
            'title' => 'Teaching Attendance',
            'file'  => 'visiting_faculty_attendance.php',
            'icon'  => '📊'
        ],
        'detailed' => [
            'title' => 'Annexure IV-A (Detailed Sheet)',
            'file'  => 'detailed_remuneration.php',
            'icon'  => '📋'
        ],
    ];

    $tabHtml = '';
    foreach ($tabs as $k => $t) {
        $isActive = ($k === $activeKey);
        $cls = $isActive 
            ? 'background:#4f46e5;color:#ffffff;font-weight:700;border:1px solid #4338ca;box-shadow:0 1px 3px rgba(0,0,0,0.2);'
            : 'background:rgba(255,255,255,0.1);color:#e2e8f0;border:1px solid rgba(255,255,255,0.2);';
        $tabHtml .= "<a href='{$t['file']}?{$qParams}' style='{$cls}padding:6px 14px;border-radius:6px;text-decoration:none;font-size:13px;display:inline-flex;align-items:center;gap:6px;transition:all 0.15s ease;'>
            <span>{$t['icon']}</span><span>{$t['title']}</span>
        </a>";
    }

    $monthOptions = '';
    for ($m = 1; $m <= 12; $m++) {
        $sel = ($m === $month) ? 'selected' : '';
        $mName = date('F', mktime(0,0,0,$m,1));
        $monthOptions .= "<option value='{$m}' {$sel}>{$mName}</option>";
    }

    $yearOptions = '';
    $currY = (int)date('Y');
    for ($y = $currY - 2; $y <= $currY + 2; $y++) {
        $sel = ($y === $year) ? 'selected' : '';
        $yearOptions .= "<option value='{$y}' {$sel}>{$y}</option>";
    }

    $safeName = htmlspecialchars($facultyName);

    return <<<HTML
    <div class="no-print report-topbar">
        <div class="topbar-left">
            <a href="{$backUrl}" class="btn-back">⬅ Back</a>
            <span class="topbar-title"><strong>{$safeName}</strong> &bull; Monthly Reports</span>
        </div>
        <div class="topbar-tabs">
            {$tabHtml}
        </div>
        <div class="topbar-right">
            <form method="GET" class="month-form" id="monthForm">
                <input type="hidden" name="faculty_id" value="{$facultyId}">
                <select name="month" onchange="document.getElementById('monthForm').submit()" class="topbar-select">
                    {$monthOptions}
                </select>
                <select name="year" onchange="document.getElementById('monthForm').submit()" class="topbar-select">
                    {$yearOptions}
                </select>
            </form>
            <button onclick="window.print()" class="btn-print">🖨️ Print / Save PDF</button>
        </div>
    </div>
HTML;
}
