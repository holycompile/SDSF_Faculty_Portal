<?php
require_once __DIR__ . '/report_bootstrap.php';
$activeKey = 'annexure_iv';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Annexure-IV — <?= htmlspecialchars($faculty['name']) ?> (<?= $monthShortStr ?> <?= $year ?>)</title>
<style>
/* ─── RESET & COMMON ──────────────────────────────────────────────────────── */
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    background-color: #f1f5f9;
    color: #000000;
    font-family: "Times New Roman", Times, serif;
    font-size: 11pt;
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

/* ─── ANNEXURE IV SPECIFIC STYLES ────────────────────────────────────────── */
.annexure-label {
    text-align: right;
    font-size: 13pt;
    font-weight: bold;
    letter-spacing: 0.5px;
    margin-bottom: 2px;
}
.header-wrap {
    position: relative;
    text-align: center;
    margin-bottom: 6px;
}
.davv-logo {
    position: absolute;
    left: 0;
    top: -2px;
    width: 58px;
    height: auto;
}
.uni-title {
    font-size: 15pt;
    font-weight: bold;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}
.dept-line {
    font-size: 11pt;
    margin-top: 2px;
}
.reg-line {
    font-size: 10.5pt;
    margin-top: 4px;
}
.bill-title {
    font-size: 12pt;
    font-weight: bold;
    text-decoration: underline;
    margin-top: 6px;
}

/* UVFIN BOXES */
.uvfin-row {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 5px;
    margin-bottom: 8px;
}
.uvfin-title {
    font-size: 10.5pt;
    font-weight: 500;
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

/* META FIELDS */
.meta-field-row {
    margin-bottom: 3px;
    font-size: 10.5pt;
}
.underline-fill {
    display: inline-block;
    border-bottom: 1px solid #000;
    padding: 0 4px;
    font-weight: 600;
}

/* SUMMARY TABLE */
.doc-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 6px;
    font-size: 10pt;
}
.doc-table th, .doc-table td {
    border: 1px solid #000;
    padding: 3px 5px;
    vertical-align: middle;
}
.doc-table th {
    font-weight: 600;
    text-align: center;
    background-color: #fcfcfc;
}
.text-center { text-align: center; }
.text-right { text-align: right; }
.text-left { text-align: left; }

.table-footnote {
    font-size: 8pt;
    margin-top: 2px;
    font-style: italic;
}
.totals-summary-line {
    font-size: 10pt;
    margin-top: 4px;
    font-weight: bold;
}

/* NOTE SECTION */
.notes-container {
    font-size: 9.5pt;
    margin-top: 6px;
    line-height: 1.25;
}
.notes-container ol {
    list-style-type: upper-alpha;
    padding-left: 24px;
}
.notes-container li {
    margin-bottom: 1px;
}

/* UNDERTAKING */
.undertaking-section {
    margin-top: 6px;
    border-top: 1px solid #000;
    padding-top: 4px;
    text-align: center;
}
.undertaking-title {
    font-size: 11pt;
    font-weight: bold;
    text-decoration: underline;
    margin-bottom: 3px;
}
.undertaking-body {
    font-size: 9.5pt;
    text-align: justify;
    line-height: 1.25;
}

/* BANK BOX & SIGNATURES */
.bottom-grid {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-top: 8px;
    gap: 15px;
}
.bank-box {
    width: 48%;
    border: 1px solid #000;
    padding: 6px 8px;
    font-size: 9pt;
    line-height: 1.35;
}
.bank-box-line {
    margin-bottom: 2px;
    display: flex;
    justify-content: space-between;
}
.bank-box-line span.label {
    font-weight: 500;
}
.bank-box-line span.val {
    font-weight: 600;
    border-bottom: 1px dotted #555;
    flex: 1;
    margin-left: 6px;
    text-align: left;
    padding-left: 4px;
}
.sigs-right {
    width: 48%;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    height: 130px;
    font-size: 10pt;
    text-align: center;
}
.sig-name {
    margin-top: 25px;
    font-weight: 600;
}
.final-footer-line {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    margin-top: 12px;
    font-size: 10pt;
    font-weight: bold;
}
</style>
</head>
<body>

<?= renderReportNavBar($activeKey, $facultyId, $month, $year, $faculty['name'], $isAdmin) ?>

<div class="sheet-container">
    <!-- TOP RIGHT ANNEXURE -->
    <div class="annexure-label">ANNEXURE -IV</div>

    <!-- CENTER HEADER -->
    <div class="header-wrap">
        <img src="<?= BASE_URL ?>/assets/davv_logo.png" alt="DAVV Logo" class="davv-logo" onerror="this.style.display='none'">
        <div class="uni-title">DEVI AHILYA VISHWAVIDYALYA, INDORE</div>
        <div class="dept-line">
            Department/School/Centre: <span class="underline-fill" style="min-width:340px;"><?= htmlspecialchars($deptName) ?></span>
        </div>
        <div class="reg-line">
            Page No. of Attendance Register: <span class="underline-fill" style="min-width:90px;"><?= htmlspecialchars($attendanceRegPage) ?></span>
            &nbsp;&nbsp;&nbsp;&nbsp;
            S.No.: <span class="underline-fill" style="min-width:70px;"><?= !empty($existingSnapshot['id']) ? sprintf('%02d', $existingSnapshot['id']) : '01' ?></span>
        </div>
        <div class="bill-title">Bill for Claiming Remuneration/Honorarium for Visiting Faculty</div>
    </div>

    <!-- UVFIN ROW -->
    <div class="uvfin-row">
        <span class="uvfin-title">UVFIN (Unified Visiting Faculty ID No.)</span>
        <?= renderUVFINBoxes($faculty['faculty_enrollment_no'] ?? '', 10) ?>
    </div>

    <!-- FACULTY DETAILS -->
    <div class="meta-field-row">
        Name: <span class="underline-fill" style="min-width: 580px;"><?= htmlspecialchars($faculty['name']) ?></span>
    </div>
    <div class="meta-field-row">
        Address: <span class="underline-fill" style="min-width: 565px;"><?= htmlspecialchars($faculty['address'] ?? 'Indore, M.P.') ?></span>
    </div>
    <div class="meta-field-row" style="display:flex;justify-content:space-between;">
        <div>Mob No.: <span class="underline-fill" style="min-width: 170px;"><?= htmlspecialchars($faculty['phone'] ?? '—') ?></span></div>
        <div>Qualification: <span class="underline-fill" style="min-width: 250px;"><?= htmlspecialchars($faculty['qualification'] ?? '—') ?></span></div>
    </div>
    <div class="meta-field-row" style="display:flex;justify-content:space-between;">
        <div>Month: <span class="underline-fill" style="min-width: 75px;"><?= $monthShortStr ?></span></div>
        <div>Year: <span class="underline-fill" style="min-width: 60px;"><?= $year ?></span></div>
        <div>Date of Submission: <span class="underline-fill" style="min-width: 95px;"><?= $displaySubmissionDate ?></span></div>
        <div>Theory/Practical: <span class="underline-fill" style="min-width: 50px;"><?= $grandTotalHours ?></span> hrs per week</div>
    </div>

    <!-- MAIN SUMMARY TABLE -->
    <table class="doc-table">
        <thead>
            <tr>
                <th style="width: 14%;">Program</th>
                <th style="width: 11%;">Semester</th>
                <th style="width: 21%;">Subject</th>
                <th style="width: 27%;">Dates with Duration (Hrs.)</th>
                <th style="width: 9%;">Total Hrs.</th>
                <th style="width: 8%;">Rate</th>
                <th style="width: 10%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $rowCount = 0;
            if (!empty($groupedByCourse)): 
                foreach ($groupedByCourse as $c): 
                    $rowCount++;
                    $datesFormatted = implode(', ', $c['dates']);
            ?>
                <tr>
                    <td class="text-center"><?= htmlspecialchars($c['program']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($c['semester']) ?></td>
                    <td class="text-left"><strong><?= htmlspecialchars($c['subject_name']) ?></strong></td>
                    <td class="text-center" style="font-size:8.5pt;"><?= htmlspecialchars($datesFormatted) ?></td>
                    <td class="text-center font-bold"><strong><?= (float)$c['total_hours'] ?></strong></td>
                    <td class="text-center"><?= (int)$c['rate'] ?></td>
                    <td class="text-right"><strong><?= number_format($c['total_amount'], 2) ?></strong></td>
                </tr>
            <?php 
                endforeach; 
            endif; 
            
            // Pad empty rows to match standard printed bill format
            $minRows = 6;
            for ($i = $rowCount; $i < $minRows; $i++):
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
        </tbody>
    </table>

    <div class="table-footnote">*Total amount should not exceed the maximum limit of remuneration for a month.</div>
    <div class="totals-summary-line">
        Total Hours: <u>&nbsp;<?= (float)$grandTotalHours ?>&nbsp;</u>
        &nbsp;&nbsp;&nbsp;&nbsp;
        Total Amount: <u>&nbsp;Rs. <?= number_format($grandTotalAmount, 2) ?>/-&nbsp;</u>
        &nbsp;&nbsp;&nbsp;&nbsp;
        (Amount in Words: <u>&nbsp;<?= numberToWords($grandTotalAmount) ?>&nbsp;</u>)
    </div>

    <!-- NOTES SECTION -->
    <div class="notes-container">
        <strong>Note:</strong>
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
        <div class="undertaking-body">
            I was directed and permitted by the Head to engage the above Classes. For this I have submitted this bill. I therefore, request you to deduct <u>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</u> % against Income Tax Returns from my payment. Further, I certify that total amount received per month doesn't exceed the maximum permissible limit of remuneration of any amount paid by D.A.V.V. which is Rs. 30,000/- at present.
        </div>
    </div>

    <!-- BANK DETAILS & SIGNATURES -->
    <div class="bottom-grid">
        <div class="bank-box">
            <div class="bank-box-line">
                <span class="label">Pan Card No.:</span>
                <span class="val"><?= htmlspecialchars($faculty['pan_no'] ?? '') ?></span>
            </div>
            <div class="bank-box-line">
                <span class="label">A/c No.:</span>
                <span class="val"><?= htmlspecialchars($faculty['account_no'] ?? '') ?></span>
            </div>
            <div class="bank-box-line">
                <span class="label">Bank Name:</span>
                <span class="val"><?= htmlspecialchars($faculty['bank_name'] ?? 'State Bank of India') ?></span>
            </div>
            <div style="font-size:8pt;font-style:italic;margin:1px 0 2px 0;color:#333;">(State bank of India Compulsory)</div>
            <div class="bank-box-line">
                <span class="label">IFSC Code:</span>
                <span class="val"><?= htmlspecialchars($faculty['ifsc_code'] ?? '') ?></span>
            </div>
            <div class="bank-box-line">
                <span class="label">Aadhaar No.:</span>
                <span class="val"><?= htmlspecialchars($faculty['aadhaar_no'] ?? '') ?></span>
            </div>
        </div>

        <div class="sigs-right">
            <div class="sig-name">
                <div style="height:25px;"></div>
                Name &amp; Signature of Visiting Faculty
            </div>
            <div>
                <div style="height:20px;"></div>
                Verified by Coordinator (Name &amp; Signature)<br>
                Date: <u><?= $displaySubmissionDate ?></u>
            </div>
        </div>
    </div>

    <!-- FINAL FOOTER LINE -->
    <div class="final-footer-line">
        <div>Received Payments of Rs. <u>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</u></div>
        <div style="text-align:right;">
            <div style="height:20px;"></div>
            Signature Director/Head (Name &amp; Seal)
        </div>
    </div>
</div>

</body>
</html>
