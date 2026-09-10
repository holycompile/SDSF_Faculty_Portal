<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$facultyId = (int)($_GET['faculty_id'] ?? 0);
if (!$facultyId) die("Invalid Faculty ID.");

// Session authorization (Admin or the faculty themselves)
if (empty($_SESSION['admin_username']) && (empty($_SESSION['faculty_id']) || (int)$_SESSION['faculty_id'] !== $facultyId)) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$month = (int)($_GET['month'] ?? date('m'));
if ($month < 1 || $month > 12) $month = (int)date('m');

$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2020 || $year > 2035) $year = (int)date('Y');

$submissionDate = trim($_GET['submission_date'] ?? date('d/m/Y'));
if (strpos($submissionDate, '-') !== false) {
    $submissionDate = date('d/m/Y', strtotime($submissionDate));
}

// ─── Fetch Faculty ────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM faculty_members WHERE id = ?");
$stmt->execute([$facultyId]);
$faculty = $stmt->fetch();
if (!$faculty) die("Faculty not found.");

// ─── Fetch Lectures ───────────────────────────────────────────────────────────
$lStmt = $pdo->prepare("
    SELECT le.*, c.id as c_id, c.program, c.semester, c.subject_name, c.course_code, c.class_type
    FROM lecture_entries le
    JOIN courses c ON c.id = le.course_id
    WHERE le.faculty_id = ? AND MONTH(le.lecture_date) = ? AND YEAR(le.lecture_date) = ?
    ORDER BY c.program, c.semester, c.subject_name, le.lecture_date ASC
");
$lStmt->execute([$facultyId, $month, $year]);
$allLectures = $lStmt->fetchAll();

// ─── Group Lectures by Course ─────────────────────────────────────────────────
$groupedCourses = [];
foreach ($allLectures as $lec) {
    $cid = $lec['c_id'];
    if (!isset($groupedCourses[$cid])) {
        $groupedCourses[$cid] = [
            'program'      => $lec['program'],
            'semester'     => $lec['semester'],
            'subject_name' => $lec['subject_name'],
            'course_code'  => $lec['course_code'],
            'class_type'   => $lec['class_type'],
            'rate'         => $lec['rate_per_hour'],
            'total_hours'  => 0,
            'total_amount' => 0,
            'dates'        => []
        ];
    }
    $groupedCourses[$cid]['total_hours']  += (float)$lec['hours'];
    $groupedCourses[$cid]['total_amount'] += (float)$lec['amount'];
    $dStr = date('d/m/Y', strtotime($lec['lecture_date'])) . ' (' . (float)$lec['hours'] . ' Hrs)';
    $groupedCourses[$cid]['dates'][] = $dStr;
}

// ─── Overall Totals ───────────────────────────────────────────────────────────
$overallHours   = 0;
$overallAmount  = 0;
$classTypesUsed = [];
foreach ($groupedCourses as $gc) {
    $overallHours  += $gc['total_hours'];
    $overallAmount += $gc['total_amount'];
    $classTypesUsed[$gc['class_type']] = true;
}
$multiType    = count($classTypesUsed) > 1;
$typeLabel    = $multiType ? 'Mixed (T+P)' : ((array_key_first($classTypesUsed) ?? 'T') === 'T' ? 'Theory' : 'Practical');
$amountWords  = numberToWords($overallAmount);
$monthNameStr = date('F', mktime(0, 0, 0, $month, 1));
$deptName     = !empty($faculty['department']) ? $faculty['department'] : 'School of Data Science and Forecasting (SDSF)';
$sessionStr   = $year . '-' . substr((string)($year + 1), -2);
$enrollNo     = str_pad($faculty['faculty_enrollment_no'] ?? '', 15);
$coursesArr   = array_values($groupedCourses);

// ┌──────────────────────────────────────────────────────────────────────────┐
// │                                                                          │
// │    ██████████████████████████████████████████████████████████████████   │
// │    ██                                                              ██   │
// │    ██      IF-ELSE LAYOUT SELECTION BASED ON SUBJECT COUNT         ██   │
// │    ██                                                              ██   │
// │    ██   1 subject  → layout_1 : Full-width single column           ██   │
// │    ██   2 subjects → layout_2 : Stacked top & bottom               ██   │
// │    ██   3 subjects → layout_3 : One full + two side-by-side        ██   │
// │    ██   4 subjects → layout_4 : 2×2 grid                          ██   │
// │    ██   5+ subjects→ layout_5 : Summary + one page per subject     ██   │
// │    ██                                                              ██   │
// │    ██████████████████████████████████████████████████████████████████   │
// │                                                                          │
// └──────────────────────────────────────────────────────────────────────────┘
$subjectCount = count($groupedCourses);

if ($subjectCount <= 1) {
    $layout = 'layout_1';          // Full-width single column (or empty state if 0)
} elseif ($subjectCount === 2) {
    $layout = 'layout_2';          // Stacked top & bottom
} elseif ($subjectCount === 3) {
    $layout = 'layout_3';          // One full + two side-by-side
} elseif ($subjectCount === 4) {
    $layout = 'layout_4';          // 2×2 grid
} else {
    $layout = 'layout_5';          // Summary overview + one page per subject (5+)
}

// ─── Helper: render UVFIN boxes ───────────────────────────────────────────────
function renderUVFIN(string $enrollNo): string {
    $chars = str_split($enrollNo);
    $html  = '';
    foreach ($chars as $ch) {
        $html .= '<span class="uvfin-box">' . htmlspecialchars($ch) . '</span>';
    }
    return $html;
}

// ─── Helper: render one subject card ─────────────────────────────────────────
function renderSubjectCard(array $course, string $extraClass = ''): string {
    $dates = implode('<br>', array_map('htmlspecialchars', $course['dates']));
    $subj  = htmlspecialchars($course['subject_name']);
    $code  = htmlspecialchars($course['course_code']);
    $prog  = htmlspecialchars($course['program']);
    $sem   = htmlspecialchars($course['semester']);
    $type  = $course['class_type'] === 'T' ? 'Theory' : 'Practical';
    $rate  = (int)$course['rate'];
    $hours = (float)$course['total_hours'];
    $amt   = number_format($course['total_amount']);

    return <<<HTML
    <div class="subject-card {$extraClass}">
        <table class="subject-table">
            <thead>
                <tr>
                    <th>Program</th><th>Semester</th><th>Subject</th><th>Type</th>
                    <th>Dates &amp; Duration</th><th>Total Hrs</th><th>Rate</th><th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="tc">{$prog}</td>
                    <td class="tc">{$sem}</td>
                    <td class="tl"><strong>{$subj}</strong><br><small>({$code})</small></td>
                    <td class="tc">{$type}</td>
                    <td class="tc small-dates">{$dates}</td>
                    <td class="tc bold">{$hours}</td>
                    <td class="tc">{$rate}</td>
                    <td class="tc bold">&#8377;{$amt}</td>
                </tr>
            </tbody>
        </table>
    </div>
HTML;
}

// ─── Helper: render attendance table rows ─────────────────────────────────────
function renderAttendanceRows(array $allLectures, string $monthNameStr, int $year): string {
    if (empty($allLectures)) {
        return '<tr><td colspan="7" class="tc">No lecture records found for '
            . htmlspecialchars($monthNameStr) . ' ' . $year . '</td></tr>';
    }
    $html = '';
    $sno  = 1;
    $totalAttHours = 0;
    foreach ($allLectures as $l) {
        $totalAttHours += (float)$l['hours'];
        $dateStr = date('d/m/Y', strtotime($l['lecture_date']));
        $typeStr = $l['class_type'] === 'T' ? 'Theory' : 'Practical';
        $progSem = htmlspecialchars($l['program']) . ' (Sem ' . ($l['semester'] ?? '—') . ')';
        $html .= '<tr>
            <td class="tc">' . $sno++ . '</td>
            <td class="tc">' . $dateStr . '</td>
            <td class="tc">' . htmlspecialchars($l['course_code'] ?: '—') . '</td>
            <td class="tl">&nbsp;' . htmlspecialchars(substr($l['subject_name'], 0, 50)) . '</td>
            <td class="tc">' . $progSem . '</td>
            <td class="tc">' . $typeStr . '</td>
            <td class="tc bold">' . (float)$l['hours'] . '</td>
        </tr>';
    }
    $html .= '<tr class="total-row">
        <td colspan="6" class="tr bold">Total Teaching Hours Delivered</td>
        <td class="tc bold">' . $totalAttHours . '</td>
    </tr>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SDSF – Remuneration Bill | <?= htmlspecialchars($faculty['name']) ?> | <?= $monthNameStr . ' ' . $year ?></title>
<style>
/* ═══════════════════════════════════════════════════════ RESET */
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'Times New Roman', Times, serif;
    font-size: 11pt;
    color: #111;
    background: #e8e8e8;
}

/* ═══════════════════════════════════════════════════ PRINT BAR */
.print-bar {
    position: fixed;
    top: 0; left: 0; right: 0; z-index: 9999;
    background: #1a1a2e;
    padding: 9px 22px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.4);
}
.print-bar h2 {
    color: #fff;
    font-size: 13px;
    font-family: Arial, sans-serif;
    font-weight: 600;
    flex: 1;
}
.layout-badge {
    background: rgba(255,255,255,0.15);
    color: #c5d8ff;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-family: Arial, sans-serif;
    font-weight: bold;
}
.btn-print {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: #fff;
    border: none;
    padding: 8px 22px;
    border-radius: 6px;
    font-size: 13px;
    font-family: Arial, sans-serif;
    cursor: pointer;
    font-weight: bold;
    transition: opacity 0.2s;
}
.btn-print:hover { opacity: 0.85; }

/* ══════════════════════════════════════════════════ PAGE SETUP */
.page-wrapper {
    margin-top: 52px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 20px;
    padding: 20px 0 40px;
}
.a4-page {
    width: 210mm;
    min-height: 297mm;
    background: #fff;
    padding: 10mm 10mm 8mm 10mm;
    box-shadow: 0 4px 24px rgba(0,0,0,0.18);
    page-break-after: always;
    position: relative;
}

/* ════════════════════════════════════════════════ DOCUMENT HEADER */
.doc-header {
    text-align: center;
    position: relative;
    border-bottom: 1.5px solid #333;
    padding-bottom: 4px;
    margin-bottom: 4px;
}
.doc-header .logo {
    position: absolute; left: 0; top: 0;
    width: 20mm; height: 20mm; object-fit: contain;
}
.doc-header .annexure-label  { font-size: 9.5pt; font-weight: bold; }
.doc-header .university-name { font-size: 13pt;  font-weight: bold; }
.doc-header .dept-label       { font-size: 8.5pt; font-weight: bold; }
.doc-header .dept-name        { font-size: 10pt;  font-weight: bold; }

/* ════════════════════════════════════════════════════ META / TITLE */
.meta-row {
    display: flex;
    justify-content: space-between;
    font-size: 8pt;
    margin: 3px 0;
}
.doc-title {
    text-align: center;
    font-size: 9.5pt;
    font-weight: bold;
    text-decoration: underline;
    margin: 3px 0 5px;
}

/* ════════════════════════════════════════════════════════ UVFIN */
.uvfin-row {
    display: flex;
    align-items: center;
    gap: 3px;
    margin-bottom: 4px;
    font-size: 8pt;
    font-weight: bold;
}
.uvfin-label { min-width: 68mm; }
.uvfin-box {
    display: inline-block;
    width: 6.2mm; height: 5mm;
    border: 1px solid #333;
    text-align: center;
    line-height: 5mm;
    font-size: 8pt;
    font-weight: bold;
}

/* ═══════════════════════════════════════════════ FACULTY INFO */
.info-grid { display: flex; flex-direction: column; gap: 3px; margin-bottom: 5px; font-size: 8.5pt; }
.info-row  { display: flex; align-items: baseline; gap: 4px; }
.info-label { min-width: 22mm; font-weight: normal; }
.info-value { flex: 1; font-weight: bold; border-bottom: 1px solid #555; padding: 0 0 1px 3px; }
.info-row-inline { display: flex; flex-wrap: wrap; gap: 8px; align-items: baseline; }
.info-chip { border-bottom: 1px solid #555; padding: 0 4px 1px; font-weight: bold; }

/* ════════════════════════════════════════════ SUBJECT CARDS / TABLES */
.subject-card { margin-bottom: 5px; }
.subject-card.compact { margin-bottom: 3px; }

.subject-table { width: 100%; border-collapse: collapse; font-size: 8pt; }
.subject-table th { background: #f0f0f0; border: 1px solid #555; padding: 3px 4px; text-align: center; font-weight: bold; }
.subject-table td { border: 1px solid #555; padding: 3px 4px; vertical-align: top; }
.small-dates { font-size: 7pt; line-height: 1.4; }

/* ─── Layout 3: side-by-side ─── */
.two-col-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 5px; }
.two-col-grid .subject-table th,
.two-col-grid .subject-table td { font-size: 7pt; padding: 2px 3px; }

/* ─── Layout 4: 2×2 grid ─── */
.four-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 5px; margin-bottom: 5px; }
.four-grid .subject-table th,
.four-grid .subject-table td { font-size: 6.5pt; padding: 2px; }

/* ════════════════════════════════════════ DISCLAIMER / TOTALS */
.disclaimer { font-size: 7.5pt; margin: 2px 0 4px; color: #444; }
.totals-row { display: flex; flex-wrap: wrap; gap: 14px; align-items: baseline; font-size: 8.5pt; font-weight: bold; margin-bottom: 4px; }
.totals-row span { border-bottom: 1px solid #333; padding: 0 4px 1px; }

/* ══════════════════════════════════════════════════════ NOTES */
.notes-section { margin: 3px 0; }
.notes-title { font-size: 8.5pt; font-weight: bold; text-decoration: underline; margin-bottom: 2px; }
.notes-section ol { padding-left: 18px; font-size: 7.2pt; line-height: 1.5; list-style-type: upper-alpha; }

/* ══════════════════════════════════════════════ UNDERTAKING */
.undertaking-section { margin: 3px 0; }
.undertaking-title { text-align: center; font-size: 8.5pt; font-weight: bold; text-decoration: underline; margin-bottom: 3px; }
.undertaking-section p { font-size: 7.2pt; font-weight: bold; line-height: 1.5; text-align: justify; }

/* ═══════════════════════════════════════════ BOTTOM SECTION */
.bottom-section { display: flex; gap: 8mm; margin-top: 6px; }
.banking-box {
    border: 1px solid #333;
    padding: 4px 6px;
    width: 78mm;
    font-size: 7.5pt;
    font-weight: bold;
    flex-shrink: 0;
}
.bank-row { display: flex; gap: 4px; margin-bottom: 2px; align-items: baseline; }
.bank-label { min-width: 22mm; font-weight: normal; }
.bank-value { flex: 1; border-bottom: 1px solid #555; padding-left: 3px; font-weight: bold; }
.bank-note { font-size: 6.5pt; font-style: italic; color: #555; margin-bottom: 2px; }
.received-section { margin-top: 4px; font-size: 7.5pt; font-weight: bold; }
.received-section p { margin-bottom: 2px; }

.signatures-col { flex: 1; display: flex; flex-direction: column; justify-content: space-between; padding-top: 4px; font-size: 7.5pt; font-weight: bold; }
.sig-block { text-align: center; margin-bottom: 5px; }
.sig-block .sig-line { height: 10mm; border-bottom: 1px solid #333; margin-bottom: 2px; }

/* ═══════════════════════════════════ PAGE 2: ATTENDANCE SHEET */
.att-header { text-align: center; border-bottom: 1.5px solid #333; padding-bottom: 4px; margin-bottom: 5px; position: relative; }
.att-header .logo { position: absolute; left: 0; top: 0; width: 20mm; height: 20mm; object-fit: contain; }
.att-title    { font-size: 11pt; font-weight: bold; text-decoration: underline; margin-bottom: 2px; }
.att-subtitle { font-size: 7.5pt; font-style: italic; }

.att-meta-grid { display: grid; grid-template-columns: 1fr 1fr; border: 1px solid #555; font-size: 8.5pt; font-weight: bold; margin-bottom: 5px; }
.att-meta-grid div { padding: 3px 6px; border: 1px solid #555; }

.att-table { width: 100%; border-collapse: collapse; font-size: 8pt; margin-top: 4px; }
.att-table th { background: #f0f0f0; border: 1px solid #555; padding: 3px 4px; text-align: center; font-weight: bold; }
.att-table td { border: 1px solid #555; padding: 3px 4px; }
.att-table .total-row td { font-weight: bold; background: #f5f5f5; }

.att-sigs { display: flex; justify-content: space-between; margin-top: 18mm; font-size: 7.5pt; font-weight: bold; }
.att-sigs .sig { text-align: center; flex: 1; }
.att-sigs .sig .sig-line { border-top: 1px solid #333; padding-top: 3px; }
.att-sig-bottom { text-align: right; margin-top: 16mm; font-size: 7.5pt; font-weight: bold; }

/* ═══════════════════════════════════════════════════ UTILITIES */
.tc { text-align: center; }
.tl { text-align: left;   }
.tr { text-align: right;  }
.bold { font-weight: bold; }
.section-label {
    font-size: 7pt; font-weight: bold; color: #444;
    text-transform: uppercase; letter-spacing: 0.5px;
    background: #f7f7f7; padding: 2px 6px;
    border-left: 3px solid #667eea; margin: 4px 0 2px;
}

/* ═══════════════════════════════════════════════ PRINT STYLES */
@media print {
    body { background: #fff; }
    .print-bar { display: none !important; }
    .page-wrapper { margin-top: 0; gap: 0; padding: 0; }
    .a4-page { width: 100%; min-height: auto; box-shadow: none; padding: 7mm 10mm; page-break-after: always; }
    .a4-page:last-child { page-break-after: avoid; }
    @page { size: A4 portrait; margin: 5mm; }
}
</style>
</head>
<body>

<!-- ══ PRINT TOOLBAR ══════════════════════════════════════════════════════════ -->
<div class="print-bar">
    <h2>SDSF Remuneration Bill &mdash; <?= htmlspecialchars($faculty['name']) ?> &nbsp;|&nbsp; <?= $monthNameStr . ' ' . $year ?></h2>

    <!-- ★ LAYOUT BADGE — shows which template was chosen by the if-else block ★ -->
    <span class="layout-badge">
        <?= $subjectCount ?> Subject<?= $subjectCount !== 1 ? 's' : '' ?>
        &nbsp;→&nbsp; <?= strtoupper(str_replace('_', ' ', $layout)) ?>
    </span>

    <button class="btn-print" onclick="window.print()">&#11015; Download / Print PDF</button>
</div>

<div class="page-wrapper">

<!-- ══════════════════════════════════════════════════════════════════════════
     PAGE 1 — ANNEXURE IV (REMUNERATION BILL)
══════════════════════════════════════════════════════════════════════════ -->
<div class="a4-page">

    <!-- DOCUMENT HEADER -->
    <div class="doc-header">
        <img class="logo" src="<?= BASE_URL ?>/assets/davv_logo.png" alt="DAVV Logo" onerror="this.style.display='none'">
        <div class="annexure-label">ANNEXURE &ndash; IV</div>
        <div class="university-name">DEVI AHILYA VISHWAVIDYALAYA, INDORE</div>
        <div class="dept-label">Department / School / Centre</div>
        <div class="dept-name"><?= htmlspecialchars($deptName) ?></div>
    </div>

    <div class="meta-row">
        <span>Page No. of Attendance Register &nbsp;_______________________</span>
        <span>S.No. &nbsp;_______________________</span>
    </div>

    <div class="doc-title">Bill For Claiming Remuneration / Honorarium for Visiting Faculty</div>

    <!-- UVFIN -->
    <div class="uvfin-row">
        <span class="uvfin-label">UVFIN (Unified Visiting Faculty ID No.)</span>
        <?= renderUVFIN($enrollNo) ?>
    </div>

    <!-- FACULTY INFO -->
    <div class="info-grid">
        <div class="info-row">
            <span class="info-label">Name</span>
            <span class="info-value"><?= htmlspecialchars($faculty['name']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Address</span>
            <span class="info-value"><?= htmlspecialchars($faculty['address'] ?: 'Indore (M.P.)') ?></span>
        </div>
        <div class="info-row-inline">
            <span class="info-label">Mob No.</span>
            <span class="info-chip"><?= htmlspecialchars($faculty['phone']) ?></span>
            &nbsp;
            <span class="info-label">Qualification</span>
            <span class="info-chip"><?= htmlspecialchars($faculty['qualification'] ?: '—') ?></span>
        </div>
        <div class="info-row-inline">
            <span class="info-label">Month</span>
            <span class="info-chip"><?= $monthNameStr ?></span>
            <span class="info-label">Year</span>
            <span class="info-chip"><?= $year ?></span>
            <span class="info-label">Date of Submission</span>
            <span class="info-chip"><?= htmlspecialchars($submissionDate) ?></span>
            <span class="info-label">Theory / Practical</span>
            <span class="info-chip"><?= htmlspecialchars($typeLabel) ?></span>
            <span style="font-size:8pt;margin-left:3px;"><?= (float)$overallHours ?> hrs/wk</span>
        </div>
    </div>

    <!-- ╔══════════════════════════════════════════════════════════════════════╗ -->
    <!-- ║        ★ IF-ELSE LAYOUT BLOCK — SUBJECT COUNT DRIVES LAYOUT ★      ║ -->
    <!-- ╚══════════════════════════════════════════════════════════════════════╝ -->

    <?php if ($layout === 'layout_1'): ?>
    <!-- ── LAYOUT 1 ─────────────────────────────────────────────────────────
         1 Subject → Full-width single column table
    ─────────────────────────────────────────────────────────────────────── -->
    <div class="section-label">Subject Detail (1 Subject &mdash; Full Width)</div>
    <?php if (empty($coursesArr)): ?>
        <p style="text-align:center;padding:10px;border:1px solid #ccc;">
            No lecture records found for <?= $monthNameStr . ' ' . $year ?>
        </p>
    <?php else: ?>
        <?= renderSubjectCard($coursesArr[0]) ?>
    <?php endif; ?>

    <?php elseif ($layout === 'layout_2'): ?>
    <!-- ── LAYOUT 2 ─────────────────────────────────────────────────────────
         2 Subjects → Stacked top & bottom (each full-width)
    ─────────────────────────────────────────────────────────────────────── -->
    <div class="section-label">Subject 1 of 2</div>
    <?= renderSubjectCard($coursesArr[0]) ?>
    <div class="section-label" style="margin-top:7px;">Subject 2 of 2</div>
    <?= renderSubjectCard($coursesArr[1]) ?>

    <?php elseif ($layout === 'layout_3'): ?>
    <!-- ── LAYOUT 3 ─────────────────────────────────────────────────────────
         3 Subjects → First full-width, next two side-by-side
    ─────────────────────────────────────────────────────────────────────── -->
    <div class="section-label">Subject 1 of 3 (Primary)</div>
    <?= renderSubjectCard($coursesArr[0]) ?>
    <div class="section-label" style="margin-top:6px;">Subject 2 &amp; 3 of 3 (Side by Side)</div>
    <div class="two-col-grid">
        <?= renderSubjectCard($coursesArr[1], 'compact') ?>
        <?= renderSubjectCard($coursesArr[2], 'compact') ?>
    </div>

    <?php elseif ($layout === 'layout_4'): ?>
    <!-- ── LAYOUT 4 ─────────────────────────────────────────────────────────
         4 Subjects → 2×2 grid
    ─────────────────────────────────────────────────────────────────────── -->
    <div class="section-label">All 4 Subjects &mdash; 2&times;2 Grid Layout</div>
    <div class="four-grid">
        <?= renderSubjectCard($coursesArr[0], 'compact') ?>
        <?= renderSubjectCard($coursesArr[1], 'compact') ?>
        <?= renderSubjectCard($coursesArr[2], 'compact') ?>
        <?= renderSubjectCard($coursesArr[3], 'compact') ?>
    </div>

    <?php else: ?>
    <!-- ── LAYOUT 5 ─────────────────────────────────────────────────────────
         5+ Subjects → Compact summary table on this page,
                       individual subject pages follow below
    ─────────────────────────────────────────────────────────────────────── -->
    <div class="section-label">Subject Summary Overview (<?= $subjectCount ?> Subjects Total)</div>
    <table class="subject-table">
        <thead>
            <tr>
                <th>#</th><th>Program</th><th>Semester</th><th>Subject</th>
                <th>Code</th><th>Type</th><th>Total Hrs</th><th>Rate</th><th>Amount</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($coursesArr as $idx => $course): ?>
            <tr>
                <td class="tc"><?= $idx + 1 ?></td>
                <td class="tc"><?= htmlspecialchars($course['program']) ?></td>
                <td class="tc"><?= htmlspecialchars($course['semester']) ?></td>
                <td class="tl"><strong><?= htmlspecialchars($course['subject_name']) ?></strong></td>
                <td class="tc"><?= htmlspecialchars($course['course_code']) ?></td>
                <td class="tc"><?= $course['class_type'] === 'T' ? 'Theory' : 'Practical' ?></td>
                <td class="tc bold"><?= (float)$course['total_hours'] ?></td>
                <td class="tc"><?= (int)$course['rate'] ?></td>
                <td class="tc bold">&#8377;<?= number_format($course['total_amount']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="font-size:7pt;color:#777;margin-top:3px;">
        * Detailed date-wise breakdown for each subject appears on the following pages.
    </p>
    <?php endif; ?>

    <!-- ╔══════════════════════════════════════════════════════════════════════╗ -->
    <!-- ║              END OF IF-ELSE LAYOUT BLOCK                            ║ -->
    <!-- ╚══════════════════════════════════════════════════════════════════════╝ -->

    <!-- DISCLAIMER -->
    <p class="disclaimer">* Total amount should not exceed the maximum limit of remuneration for a month.</p>

    <!-- TOTALS -->
    <div class="totals-row">
        <span>Total Hours</span><span><?= (float)$overallHours ?></span>
        &nbsp;
        <span>Total Amount</span><span>&#8377;<?= number_format($overallAmount) ?></span>
        &nbsp;
        <span>Amount in Words</span><span><?= htmlspecialchars($amountWords) ?></span>
    </div>

    <!-- NOTES -->
    <div class="notes-section">
        <div class="notes-title">Note:</div>
        <ol>
            <li>Rate of Remuneration will be as per university rules.</li>
            <li>Faculty members are requested to complete all the above entries.</li>
            <li>Rates to be verified as per visiting faculty attendance register and signed by authorized person.</li>
            <li>Fill this form for theory/practical classes for every month.</li>
            <li>Faculty should not be paid excess amount of Rs 30,000/- PM from D.A.V.V.</li>
            <li>Verified visiting faculty Teaching attendance details should be attached with this bill.</li>
        </ol>
    </div>

    <!-- UNDERTAKING -->
    <div class="undertaking-section">
        <div class="undertaking-title">UNDERTAKING</div>
        <p>I was directed and permitted by the Head to engage the above Classes. For this I have submitted this bill.
        I therefore, request you to deduct _______% against Income Tax Returns from my payment. Further, I certify
        that total amount received per month doesn't exceed the maximum permissible limit of remuneration of any
        amount paid by D.A.V.V. which is Rs. 30,000/- at present.</p>
    </div>

    <!-- BOTTOM: BANKING + SIGNATURES -->
    <div class="bottom-section">
        <div>
            <div class="banking-box">
                <div class="bank-row"><span class="bank-label">Pan Card No.</span><span class="bank-value"><?= htmlspecialchars($faculty['pan_no'] ?: '—') ?></span></div>
                <div class="bank-row"><span class="bank-label">A/c No.</span><span class="bank-value"><?= htmlspecialchars($faculty['account_no'] ?: '—') ?></span></div>
                <div class="bank-row"><span class="bank-label">Bank Name</span><span class="bank-value"><?= htmlspecialchars($faculty['bank_name'] ?: '—') ?></span></div>
                <div class="bank-note">(State Bank of India Compulsory)</div>
                <div class="bank-row"><span class="bank-label">IFSC Code</span><span class="bank-value"><?= htmlspecialchars($faculty['ifsc_code'] ?: '—') ?></span></div>
                <div class="bank-row"><span class="bank-label">Aadhaar No.</span><span class="bank-value"><?= htmlspecialchars($faculty['aadhaar_no'] ?: '—') ?></span></div>
            </div>
            <div class="received-section">
                <p>Date : &nbsp;<?= htmlspecialchars($submissionDate) ?></p>
                <p>Received Payment of Rs. &nbsp;<?= number_format($overallAmount) ?></p>
                <p>Cheque No. &nbsp;_______________________</p>
            </div>
        </div>
        <div class="signatures-col">
            <div class="sig-block"><div class="sig-line"></div>Name &amp; Signature of Visiting Faculty</div>
            <div class="sig-block"><div class="sig-line"></div>Name &amp; Signature of Batch Mentor</div>
            <div class="sig-block"><div class="sig-line"></div>Verified by Coordinator (Name &amp; Signature)</div>
            <div class="sig-block"><div class="sig-line"></div>Signature Director / Head (Name &amp; Seal)</div>
        </div>
    </div>

</div><!-- /PAGE 1 -->


<!-- ══════════════════════════════════════════════════════════════════════════
     LAYOUT 5 ONLY — Individual subject pages (one per subject, 5+)
══════════════════════════════════════════════════════════════════════════ -->
<?php if ($layout === 'layout_5'): ?>
<?php foreach ($coursesArr as $idx => $course): ?>
<div class="a4-page">
    <div class="doc-header" style="margin-bottom:8px;">
        <img class="logo" src="<?= BASE_URL ?>/assets/davv_logo.png" alt="DAVV Logo" onerror="this.style.display='none'">
        <div class="university-name" style="font-size:11pt;">DEVI AHILYA VISHWAVIDYALAYA, INDORE</div>
        <div class="dept-name"><?= htmlspecialchars($deptName) ?></div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
        <span style="font-size:9pt;font-weight:bold;">
            <?= htmlspecialchars($faculty['name']) ?> &nbsp;|&nbsp; <?= $monthNameStr . ' ' . $year ?>
        </span>
        <span class="section-label" style="margin:0;">
            Subject <?= $idx + 1 ?> of <?= $subjectCount ?>
        </span>
    </div>
    <?= renderSubjectCard($course) ?>
    <p style="font-size:7pt;color:#888;text-align:right;margin-top:4px;">
        (See next page for subject <?= ($idx + 2 <= $subjectCount) ? ($idx + 2) : 'summary' ?>)
    </p>
</div>
<?php endforeach; ?>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════════════════════
     PAGE 2 — VISITING FACULTY TEACHING ATTENDANCE SHEET
══════════════════════════════════════════════════════════════════════════ -->
<div class="a4-page">

    <!-- UVFIN top-right -->
    <div style="display:flex;justify-content:flex-end;align-items:center;gap:3px;margin-bottom:4px;">
        <span style="font-size:8pt;font-weight:bold;margin-right:4px;">UVFIN</span>
        <?= renderUVFIN($enrollNo) ?>
    </div>

    <!-- HEADER -->
    <div class="att-header">
        <img class="logo" src="<?= BASE_URL ?>/assets/davv_logo.png" alt="DAVV Logo" onerror="this.style.display='none'">
        <div class="university-name">DEVI AHILYA VISHWAVIDYALAYA, INDORE</div>
        <div class="dept-label">Department / School / Centre</div>
        <div class="dept-name"><?= htmlspecialchars($deptName) ?></div>
    </div>

    <div style="text-align:center;margin:5px 0;">
        <div class="att-title">VISITING FACULTY TEACHING ATTENDANCE</div>
        <div class="att-subtitle">(Official Teaching Attendance Record to be submitted with Annexure-IV Remuneration Bill)</div>
    </div>

    <!-- META BOXES -->
    <div class="att-meta-grid">
        <div>Name : <?= htmlspecialchars($faculty['name']) ?></div>
        <div>Designation : Visiting Faculty</div>
        <div>Month and Year : <?= $monthNameStr . ' ' . $year ?></div>
        <div>Academic Session : <?= $sessionStr ?></div>
    </div>

    <!-- ATTENDANCE TABLE -->
    <table class="att-table">
        <thead>
            <tr>
                <th style="width:7%">S.No.</th>
                <th style="width:11%">Date</th>
                <th style="width:12%">Subject Code</th>
                <th style="width:35%">Subject Name</th>
                <th style="width:16%">Program &amp; Sem</th>
                <th style="width:10%">Class Type</th>
                <th style="width:9%">Hours</th>
            </tr>
        </thead>
        <tbody>
            <?= renderAttendanceRows($allLectures, $monthNameStr, $year) ?>
        </tbody>
    </table>

    <!-- SIGNATURES -->
    <div class="att-sigs">
        <div class="sig"><div class="sig-line">&nbsp;</div>Name &amp; Sign. of Visiting Faculty</div>
        <div class="sig"><div class="sig-line">&nbsp;</div>Name &amp; Sign. of Program Incharge</div>
        <div class="sig"><div class="sig-line">&nbsp;</div>Name &amp; Sign. of Batch Mentor</div>
    </div>
    <div class="att-sig-bottom">Name &amp; Sign. of Director / Head of Department (with Seal)</div>

</div><!-- /PAGE 2 -->

</div><!-- /PAGE WRAPPER -->

<script>
// Auto-trigger print if URL has ?autoprint=1
if (new URLSearchParams(window.location.search).get('autoprint') === '1') {
    window.addEventListener('load', () => setTimeout(() => window.print(), 600));
}
</script>
</body>
</html>
