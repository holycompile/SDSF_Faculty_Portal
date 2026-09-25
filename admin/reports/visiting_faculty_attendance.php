<?php
require_once __DIR__ . '/report_bootstrap.php';
$activeKey = 'attendance';

// Determine column structure for distinct courses taught this month
$distinctCourses = [];
foreach ($allLectures as $l) {
    $cid = $l['c_id'];
    if (!isset($distinctCourses[$cid])) {
        $distinctCourses[$cid] = [
            'id'           => $cid,
            'program'      => $l['program'],
            'course_code'  => $l['course_code'],
            'subject_name' => $l['subject_name'],
            'has_theory'   => false,
            'has_practice' => false
        ];
    }
    if (strtoupper($l['class_type']) === 'P') {
        $distinctCourses[$cid]['has_practice'] = true;
    } else {
        $distinctCourses[$cid]['has_theory'] = true;
    }
}

// If no lectures recorded yet, create fallback placeholder columns
if (empty($distinctCourses)) {
    $distinctCourses[0] = [
        'id' => 0,
        'program' => 'Subject Code',
        'course_code' => '',
        'subject_name' => 'General',
        'has_theory' => true,
        'has_practice' => true
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teaching Attendance — <?= htmlspecialchars($faculty['name']) ?> (<?= $monthShortStr ?> <?= $year ?>)</title>
<style>
/* ─── RESET & COMMON ──────────────────────────────────────────────────────── */
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    background-color: #f1f5f9;
    color: #000000;
    font-family: "Times New Roman", Times, serif;
    font-size: 10pt;
    line-height: 1.25;
}

/* ─── SCREEN TOOLBAR ─────────────────────────────────────────────────────── */
.report-topbar {
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
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    box-shadow: 0 4px 12px rgba(0,0,0,0.25);
}
.topbar-left { display: flex; align-items: center; gap: 14px; }
.topbar-title { font-size: 14px; color: #cbd5e1; }
.topbar-title strong { color: #ffffff; }
.btn-back {
    background: #334155;
    color: #ffffff;
    padding: 6px 12px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
}
.btn-back:hover { background: #475569; }
.topbar-tabs { display: flex; align-items: center; gap: 8px; }
.topbar-right { display: flex; align-items: center; gap: 12px; }
.topbar-select {
    background: #1e293b;
    color: #f8fafc;
    border: 1px solid #475569;
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 12px;
}
.btn-print {
    background: linear-gradient(135deg, #4f46e5, #4338ca);
    color: #ffffff;
    border: none;
    padding: 7px 18px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(79,70,229,0.4);
}
.btn-print:hover { opacity: 0.95; }

/* ─── A4 PAPER CONTAINER ─────────────────────────────────────────────────── */
.sheet-container {
    width: 210mm;
    min-height: 297mm;
    margin: 20px auto 40px auto;
    background: #ffffff;
    padding: 10mm 12mm 10mm 12mm;
    box-shadow: 0 4px 20px rgba(0,0,0,0.12);
    position: relative;
    display: flex;
    flex-direction: column;
}

@page {
    size: A4 portrait;
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

/* ─── HEADER & UVFIN ─────────────────────────────────────────────────────── */
.top-sub-bar {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    margin-bottom: 4px;
}
.cheque-box {
    font-size: 10pt;
    font-weight: 500;
}
.uvfin-wrap {
    display: flex;
    align-items: center;
    gap: 6px;
}
.uvfin-label {
    font-size: 10.5pt;
    font-weight: bold;
    letter-spacing: 0.5px;
}
.uvfin-container {
    display: inline-flex;
    border: 1px solid #000;
    border-right: none;
}
.uvfin-cell {
    width: 18px;
    height: 19px;
    border-right: 1px solid #000;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 10pt;
    font-weight: bold;
    background: #fff;
}

.header-center {
    position: relative;
    text-align: center;
    margin-bottom: 6px;
    margin-top: 2px;
}
.davv-logo {
    position: absolute;
    left: 0;
    top: 0;
    width: 54px;
    height: auto;
}
.uni-title {
    font-size: 14pt;
    font-weight: bold;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}
.dept-line {
    font-size: 10.5pt;
    margin-top: 2px;
    border-bottom: 1px solid #000;
    display: inline-block;
    padding-bottom: 1px;
    min-width: 420px;
}
.doc-main-title {
    font-size: 11.5pt;
    font-weight: bold;
    text-decoration: underline;
    margin-top: 6px;
    letter-spacing: 0.5px;
}

/* ─── META BOX GRID (2x2) ─────────────────────────────────────────────────── */
.meta-box-table {
    width: 100%;
    border-collapse: collapse;
    border: 1px solid #000;
    margin-top: 6px;
    margin-bottom: 6px;
    font-size: 9.5pt;
}
.meta-box-table td {
    border: 1px solid #000;
    padding: 3px 8px;
    vertical-align: middle;
}

/* ─── ATTENDANCE MATRIX TABLE ────────────────────────────────────────────── */
.matrix-table {
    width: 100%;
    border-collapse: collapse;
    border: 1px solid #000;
    font-size: 9pt;
}
.matrix-table th, .matrix-table td {
    border: 1px solid #000;
    padding: 2px 3px;
    text-align: center;
    vertical-align: middle;
}
.matrix-table th {
    background-color: #fafafa;
    font-weight: 600;
    line-height: 1.2;
}
.col-date {
    width: 78px;
    font-size: 9pt;
    font-weight: 500;
}
.sub-col-hdr {
    font-size: 8.5pt;
    font-weight: 600;
}
.val-cell {
    font-weight: 600;
    font-size: 9.5pt;
}
.empty-cell {
    color: transparent;
}

/* ─── SIGNATURES BLOCK ───────────────────────────────────────────────────── */
.sig-container {
    margin-top: 100px;
    padding-top: 0;
    width: 100%;
}
.sig-row-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    font-size: 10pt;
    font-weight: 600;
    padding: 0 10px;
}
.sig-row-head {
    text-align: right;
    font-size: 10pt;
    font-weight: 600;
    margin-top: 90px;
    padding-right: 25px;
}
</style>
</head>
<body>

<?= renderReportNavBar($activeKey, $facultyId, $month, $year, $faculty['name'], $isAdmin) ?>

<div class="sheet-container">
    <!-- TOP ROW: CHEQUE NO & UVFIN -->
    <div class="top-sub-bar">
        <div class="cheque-box">
            Cheque No. <u>&nbsp;<?= htmlspecialchars($chequeNo ?: '               ') ?>&nbsp;</u>
        </div>
        <div class="uvfin-wrap">
            <span class="uvfin-label">UVFIN</span>
            <?= renderUVFINBoxes($faculty['faculty_enrollment_no'] ?? '', 10) ?>
        </div>
    </div>

    <!-- MAIN HEADER -->
    <div class="header-center">
        <img src="<?= BASE_URL ?>/assets/davv_logo.png" alt="DAVV Logo" class="davv-logo" onerror="this.style.display='none'">
        <div class="uni-title">DEVI AHILYA VISHWAVIDYALYA,<br>INDORE</div>
        <div style="margin-top:2px;">
            Department/School/Centre/ <span class="dept-line"><?= htmlspecialchars($deptName) ?></span>
        </div>
        <div class="doc-main-title">VISITING FACULTY TEACHING ATTENDANCE</div>
    </div>

    <!-- META DETAILS BOX (2x2) -->
    <table class="meta-box-table">
        <tr>
            <td style="width: 50%;">Name: <strong><?= htmlspecialchars($faculty['name']) ?></strong></td>
            <td style="width: 50%;">Designation : Visiting Faculty</td>
        </tr>
        <tr>
            <td>Month and Year - <strong><?= $monthShortStr . ' ' . $year ?></strong></td>
            <td>Semester and Session - <strong><?= htmlspecialchars($sessionStr) ?></strong></td>
        </tr>
    </table>

    <!-- MATRIX ATTENDANCE TABLE -->
    <table class="matrix-table">
        <thead>
            <tr>
                <th rowspan="2" class="col-date">Date</th>
                <?php foreach ($distinctCourses as $cid => $c): ?>
                    <?php 
                    $hasT = $c['has_theory'];
                    $hasP = $c['has_practice'];
                    $colSpan = ($hasT && $hasP) ? 2 : 1;
                    $progLabel = !empty($c['program']) ? htmlspecialchars($c['program']) : (!empty($c['subject_name']) ? htmlspecialchars($c['subject_name']) : 'Subject');
                    ?>
                    <th colspan="<?= $colSpan ?>">
                        Subject Code<br><?= $progLabel ?>
                    </th>
                <?php endforeach; ?>
                <th colspan="2">Total Lectures</th>
            </tr>
            <tr>
                <?php foreach ($distinctCourses as $cid => $c): ?>
                    <?php if ($c['has_theory'] && $c['has_practice']): ?>
                        <th class="sub-col-hdr">Theory</th>
                        <th class="sub-col-hdr">Practice</th>
                    <?php elseif ($c['has_practice']): ?>
                        <th class="sub-col-hdr">Practical</th>
                    <?php else: ?>
                        <th class="sub-col-hdr">Theory</th>
                    <?php endif; ?>
                <?php endforeach; ?>
                <th class="sub-col-hdr" style="width:48px;">Theory</th>
                <th class="sub-col-hdr" style="width:48px;">Practice</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $printedRows = 0;
            if (!empty($dailyLectures)): 
                foreach ($dailyLectures as $dStr => $row): 
                    $printedRows++;
                    $displayD = date('d/m/Y', strtotime($dStr));
            ?>
                <tr>
                    <td class="col-date"><?= $displayD ?></td>
                    <?php foreach ($distinctCourses as $cid => $c): ?>
                        <?php 
                        $cHrs = $row['by_course'][$cid] ?? ['theory' => 0.0, 'practical' => 0.0];
                        $tVal = $cHrs['theory'] > 0 ? (float)$cHrs['theory'] : '';
                        $pVal = $cHrs['practical'] > 0 ? (float)$cHrs['practical'] : '';
                        ?>
                        <?php if ($c['has_theory'] && $c['has_practice']): ?>
                            <td class="val-cell"><?= $tVal ?></td>
                            <td class="val-cell"><?= $pVal ?></td>
                        <?php elseif ($c['has_practice']): ?>
                            <td class="val-cell"><?= $pVal ?></td>
                        <?php else: ?>
                            <td class="val-cell"><?= $tVal ?></td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <!-- ROW TOTALS -->
                    <td class="val-cell"><?= $row['theory'] > 0 ? (float)$row['theory'] : '' ?></td>
                    <td class="val-cell"><?= $row['practical'] > 0 ? (float)$row['practical'] : '' ?></td>
                </tr>
            <?php 
                endforeach; 
            endif; 

            // Pad empty rows to match authentic register height (min 20 rows like Screenshot 2 & 5)
            $targetRows = 22;
            for ($i = $printedRows; $i < $targetRows; $i++):
            ?>
                <tr>
                    <td class="col-date">&nbsp;</td>
                    <?php foreach ($distinctCourses as $cid => $c): ?>
                        <?php if ($c['has_theory'] && $c['has_practice']): ?>
                            <td>&nbsp;</td><td>&nbsp;</td>
                        <?php else: ?>
                            <td>&nbsp;</td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                </tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <!-- FOOTER SIGNATURES -->
    <div class="sig-container">
        <div class="sig-row-top">
            <div>Name &amp; Sign. of Faculty</div>
            <div>Name &amp; Sign. of Coordinator</div>
        </div>
        <div class="sig-row-head">
            Name &amp; Sign. of Head
        </div>
    </div>
</div>

</body>
</html>
