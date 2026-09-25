<?php
require_once __DIR__ . '/report_bootstrap.php';
$activeKey = 'detailed';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Detailed Remuneration Sheet (Annexure IV-A) — <?= htmlspecialchars($faculty['name']) ?> (<?= $monthShortStr ?> <?= $year ?>)</title>
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

/* ─── A4 CONTAINER ───────────────────────────────────────────────────────── */
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

/* ─── HEADER ELEMENTS ────────────────────────────────────────────────────── */
.annexure-header-tag {
    text-align: right;
    font-size: 12pt;
    font-weight: bold;
    letter-spacing: 0.5px;
    margin-bottom: 2px;
}
.header-wrapper {
    position: relative;
    text-align: center;
    margin-bottom: 8px;
}
.davv-logo {
    position: absolute;
    left: 0;
    top: 0;
    width: 56px;
    height: auto;
}
.uni-title {
    font-size: 14.5pt;
    font-weight: bold;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}
.dept-line {
    font-size: 10.5pt;
    margin-top: 2px;
}
.dept-val {
    border-bottom: 1px solid #000;
    display: inline-block;
    min-width: 380px;
    font-weight: 500;
}
.doc-title {
    font-size: 11.5pt;
    font-weight: bold;
    text-decoration: underline;
    margin-top: 6px;
}
.doc-subtitle {
    font-size: 9.5pt;
    font-weight: 500;
    margin-top: 1px;
}

/* ─── UVFIN & META ───────────────────────────────────────────────────────── */
.uvfin-line {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 12px;
    margin-top: 6px;
    margin-bottom: 6px;
}
.uvfin-label {
    font-size: 10.5pt;
    font-weight: bold;
}
.uvfin-container {
    display: inline-flex;
    border: 1px solid #000;
    border-right: none;
}
.uvfin-cell {
    width: 19px;
    height: 20px;
    border-right: 1px solid #000;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 10.5pt;
    font-weight: bold;
    background: #fff;
}

.meta-row {
    margin-bottom: 3px;
    font-size: 10pt;
}
.dotted-fill {
    font-weight: bold;
    color: #000;
}

/* ─── REMUNERATION TABLE ─────────────────────────────────────────────────── */
.remun-table {
    width: 100%;
    border-collapse: collapse;
    border: 1px solid #000;
    margin-top: 6px;
    font-size: 9.5pt;
}
.remun-table th, .remun-table td {
    border: 1px solid #000;
    padding: 2px 4px;
    vertical-align: middle;
}
.remun-table th {
    background-color: #fafafa;
    text-align: center;
    font-weight: 600;
}
.tc { text-align: center; }
.tl { text-align: left; }
.tr { text-align: right; }
.fw-bold { font-weight: bold; }

.subject-col-text {
    font-size: 9pt;
    text-transform: uppercase;
    font-weight: 500;
    padding-left: 4px;
}

/* ─── SUMMARY BLOCK ──────────────────────────────────────────────────────── */
.summary-row td {
    font-weight: 600;
    padding: 3px 5px;
}
.certify-text {
    font-size: 9.5pt;
    margin-top: 8px;
    margin-bottom: 18px;
    line-height: 1.3;
}

/* ─── SIGNATURES ─────────────────────────────────────────────────────────── */
.footer-sigs {
    margin-top: auto;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    padding-top:10px;
    font-size: 10pt;
}
.sig-block {
    text-align: center;
    min-width: 220px;
    padding-top: -10px;
}
</style>
</head>
<body>

<?= renderReportNavBar($activeKey, $facultyId, $month, $year, $faculty['name'], $isAdmin) ?>

<div class="sheet-container">
    <!-- TOP RIGHT ANNEXURE -->
    <div class="annexure-header-tag">ANNEXURE IV-A</div>

    <!-- MAIN HEADER -->
    <div class="header-wrapper">
        <img src="<?= BASE_URL ?>/assets/davv_logo.png" alt="DAVV Logo" class="davv-logo" onerror="this.style.display='none'">
        <div class="uni-title">DEVI AHILYA VISHWAVIDYALYA, INDORE</div>
        <div class="dept-line">
            Department/School/Centre/ <span class="dept-val"><?= htmlspecialchars($deptName) ?></span>
        </div>
        <div class="doc-title">DETAILED REMUNERATION SHEET</div>
        <div class="doc-subtitle">(Date with Duration)</div>
    </div>

    <!-- UVFIN -->
    <div class="uvfin-line">
        <span class="uvfin-label">UVFIN (Unified Visiting Faculty ID No.)</span>
        <?= renderUVFINBoxes($faculty['faculty_enrollment_no'] ?? '', 10) ?>
    </div>

    <!-- META FIELDS -->
    <div class="meta-row">
        Name of the Visiting Faculty: <span class="dotted-fill"><?= strtoupper(htmlspecialchars($faculty['name'])) ?></span>
    </div>
    <div class="meta-row" style="display:flex;justify-content:flex-start;gap:20px;">
        <div>Month: <span class="dotted-fill"><?= $monthShortStr ?></span></div>
        <div>Year: <span class="dotted-fill"><?= $year ?></span></div>
        <div>Date of Submission: <span class="dotted-fill"><?= !empty($displaySubmissionDate) ? $displaySubmissionDate : '..................' ?></span></div>
    </div>
    <div class="meta-row">
        Program Covered: <span class="dotted-fill"><?= htmlspecialchars($programsCoveredStr) ?></span>
    </div>
    <div class="meta-row">
        Page Number of Attendance Register: <span class="dotted-fill"><?= htmlspecialchars($attendanceRegPage) ?></span>
    </div>

    <!-- CHRONOLOGICAL DETAILED TABLE -->
    <table class="remun-table">
        <thead>
            <tr>
                <th rowspan="2" style="width: 12%;">Date</th>
                <th rowspan="2" style="width: 8%;">Day</th>
                <th rowspan="2" style="width: 38%;">Subjects</th>
                <th colspan="4" style="width: 42%;">Duration</th>
            </tr>
            <tr>
                <th style="width: 10%;">Theory<br>(hr)</th>
                <th style="width: 10%;">Tutorial<br>(hr)</th>
                <th style="width: 11%;">Practical<br>(hr)</th>
                <th style="width: 11%;">Others<br>(hr)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $printedDays = 0;
            if (!empty($dailyLectures)): 
                foreach ($dailyLectures as $dStr => $row): 
                    $printedDays++;
                    $formattedDate = date('d-m-Y', strtotime($dStr));
                    $subjName = implode(', ', $row['subjects']);
                    $tVal = $row['theory'] > 0 ? (float)$row['theory'] : '';
                    $tutVal = $row['tutorial'] > 0 ? (float)$row['tutorial'] : '';
                    $pVal = $row['practical'] > 0 ? (float)$row['practical'] : '';
                    $dailyTotalVal = $row['total'] > 0 ? (float)$row['total'] : '';
            ?>
                <tr>
                    <td class="tc"><?= $formattedDate ?></td>
                    <td class="tc"><?= $row['day'] ?></td>
                    <td class="tl subject-col-text"><?= htmlspecialchars($subjName) ?></td>
                    <td class="tc"><?= $tVal ?></td>
                    <td class="tc"><?= $tutVal ?></td>
                    <td class="tc"><?= $pVal ?></td>
                    <td class="tc fw-bold"><?= $dailyTotalVal ?></td>
                </tr>
            <?php 
                endforeach; 
            endif; 

            // Pad blank rows to match authentic sheet height (at least 20 rows like Screenshot 3 & 4)
            $minSheetRows = 20;
            for ($i = $printedDays; $i < $minSheetRows; $i++):
            ?>
                <tr>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                </tr>
            <?php endfor; ?>

            <!-- SUMMARY & REMUNERATION CALCULATIONS (EXACT MATCH TO SCREENSHOT 4) -->
            <tr class="summary-row" style="background:#fafafa;">
                <td colspan="3" class="tr fw-bold" style="padding-right:12px;">Total Classes</td>
                <td class="tc fw-bold"><?= sprintf('%02d', $totalTheoryHours) ?></td>
                <td class="tc fw-bold"><?= sprintf('%01d', $totalTutorialHours) ?></td>
                <td class="tc fw-bold"><?= sprintf('%02d', $totalPracticalHours) ?></td>
                <td class="tc fw-bold"><?= sprintf('%02d', $grandTotalHours) ?></td>
            </tr>
            <tr class="summary-row">
                <td colspan="3" class="tl">Remuneration (Theory)</td>
                <td colspan="4" class="tc fw-bold">
                    <?= sprintf('%02d', $totalTheoryHours) ?> * <?= (int)$theoryRate ?> = <?= number_format($theoryAmount, 0) ?>
                </td>
            </tr>
            <tr class="summary-row">
                <td colspan="3" class="tl">Remuneration (Practical)</td>
                <td colspan="4" class="tc fw-bold">
                    <?= sprintf('%02d', $totalPracticalHours) ?> * <?= (int)$practicalRate ?> = <?= number_format($practicalAmount, 0) ?>
                </td>
            </tr>
            <tr class="summary-row">
                <td colspan="3" class="tl">Remuneration (Others)</td>
                <td colspan="4" class="tc">&nbsp;</td>
            </tr>
            <tr class="summary-row" style="background:#f8fafc;">
                <td colspan="3" class="tl fw-bold" style="font-size:10.5pt;">Total Remuneration:</td>
                <td colspan="4" class="tc fw-bold" style="font-size:10.5pt;">
                    <?= number_format($grandTotalAmount, 0) ?>/-
                </td>
            </tr>
        </tbody>
    </table>

    <!-- CERTIFICATION TEXT -->
    <div class="certify-text">
        This is certified that above classes were engaged as per teaching assignment (Time Table) given.
    </div>

    <!-- FOOTER SIGNATURES -->
    <div class="footer-sigs">
        <div class="sig-block" style="text-align:left;">
            <div style="height:35px;"></div>
            Signature with Name of Visiting Faculty:
        </div>
        <div class="sig-block" style="text-align:right;">
            <div style="height:35px;"></div>
            <strong>Head</strong><br>
            (Name and seal)
        </div>
    </div>
</div>

</body>
</html>
