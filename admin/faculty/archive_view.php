<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
require_once ROOT . '/includes/archive_helper.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$archiveId = (int)($_GET['id'] ?? 0);
if (!$archiveId) {
    setFlash('error', 'Invalid archive ID.');
    header('Location: ' . BASE_URL . '/admin/faculty/archives.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM archived_faculty_records WHERE id = ?");
$stmt->execute([$archiveId]);
$arch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$arch) {
    setFlash('error', 'Archived faculty record not found.');
    header('Location: ' . BASE_URL . '/admin/faculty/archives.php');
    exit;
}

$facultyData    = json_decode($arch['faculty_data_json'] ?? '{}', true);
$coursesData    = json_decode($arch['courses_data_json'] ?? '[]', true);
$lecturesData   = json_decode($arch['lectures_data_json'] ?? '[]', true);
$attendanceData = json_decode($arch['attendance_data_json'] ?? '[]', true);
$reportsData    = json_decode($arch['reports_data_json'] ?? '[]', true);
$paymentsData   = json_decode($arch['payments_data_json'] ?? '[]', true);

// Group attendance records by lecture_id
$attendanceByLecture = [];
foreach ($attendanceData as $att) {
    $lid = (int)($att['lecture_id'] ?? 0);
    if (!isset($attendanceByLecture[$lid])) {
        $attendanceByLecture[$lid] = [
            'total' => 0,
            'present' => 0,
            'absent' => 0,
            'students' => []
        ];
    }
    $attendanceByLecture[$lid]['total']++;
    if (($att['status'] ?? '') === 'present') {
        $attendanceByLecture[$lid]['present']++;
    } else {
        $attendanceByLecture[$lid]['absent']++;
    }
    $attendanceByLecture[$lid]['students'][] = $att;
}

$isRestored = ($arch['status'] === 'restored');
$initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $arch['name']), 0, 2)) ?: 'FC';

$active_nav = 'old-records';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Archived Dossier: <?= htmlspecialchars($arch['name']) ?> (<?= htmlspecialchars($arch['faculty_enrollment_no']) ?>) — SDSF Admin</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
<style>
.section-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    margin-bottom: 24px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.section-head {
    padding: 16px 22px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
.section-title {
    font-size: 15px;
    font-weight: 800;
    color: #0f172a;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.info-row {
    display: flex;
    padding: 9px 0;
    border-bottom: 1px solid #f1f5f9;
    font-size: 13.5px;
}
.info-row:last-child { border-bottom: none; }
.info-label {
    width: 140px;
    color: #64748b;
    font-weight: 600;
    flex-shrink: 0;
}
.info-value {
    color: #0f172a;
    font-weight: 600;
    flex: 1;
}
.stat-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    padding: 4px 12px;
    font-size: 12px;
    font-weight: 700;
    color: #334155;
}
.restore-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: rgba(15,23,42,0.65);
    backdrop-filter: blur(5px);
    align-items: center;
    justify-content: center;
    padding: 20px;
}
@media print {
    .sidebar, .topbar, .no-print { display: none !important; }
    .main { margin-left: 0 !important; width: 100% !important; }
    .page { padding: 0 !important; }
    .section-box { box-shadow: none !important; border: 1px solid #ccc !important; }
}
</style>
</head>
<body>
    <header class="topbar">
        <div class="tb-left">
            <span class="tb-crumb">Records</span>
            <span class="tb-sep">/</span>
            <a href="<?= BASE_URL ?>/admin/faculty/archives.php" class="tb-crumb" style="text-decoration:none;color:inherit;">Old Records</a>
            <span class="tb-sep">/</span>
            <span class="tb-crumb"><?= htmlspecialchars($arch['name']) ?></span>
        </div>
        <div class="tb-right">
            <a href="<?= BASE_URL ?>/admin/faculty/archives.php" class="btn btn-outline btn-sm">
                &larr; Back to Old Records
            </a>
            <?php if (!$isRestored): ?>
                <button type="button" class="btn btn-primary btn-sm" style="background:linear-gradient(135deg,#059669,#047857);" onclick="openRestoreModal()">
                    ✓ Restore This Faculty
                </button>
            <?php endif; ?>
        </div>
    </header>

    <div class="page">
        <!-- Banner Header -->
        <div class="card fade-up" style="margin-bottom:24px;background:linear-gradient(135deg, #1e1b4b, #312e81);color:#fff;border:none;padding:26px 30px;position:relative;overflow:hidden;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;position:relative;z-index:2;">
                <div style="display:flex;align-items:center;gap:18px;">
                    <div style="width:68px;height:68px;border-radius:18px;background:linear-gradient(135deg, #6366f1, #818cf8);color:#fff;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:800;border:3px solid rgba(255,255,255,0.2);">
                        <?= htmlspecialchars($initials) ?>
                    </div>
                    <div>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <h1 style="font-size:24px;font-weight:800;color:#fff;margin:0;">
                                <?= htmlspecialchars($arch['name']) ?>
                            </h1>
                            <code style="background:rgba(255,255,255,0.15);color:#c7d2fe;padding:3px 10px;border-radius:8px;font-size:13px;font-weight:700;border:1px solid rgba(255,255,255,0.2);">
                                <?= htmlspecialchars($arch['faculty_enrollment_no']) ?>
                            </code>
                            <?php if ($isRestored): ?>
                                <span style="background:#065f46;color:#a7f3d0;padding:3px 10px;border-radius:8px;font-size:12px;font-weight:700;border:1px solid #047857;">
                                    Restored Active
                                </span>
                            <?php else: ?>
                                <span style="background:rgba(239,68,68,0.25);color:#fca5a5;padding:3px 10px;border-radius:8px;font-size:12px;font-weight:700;border:1px solid rgba(239,68,68,0.4);">
                                    Archived Backup
                                </span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:13px;color:#c7d2fe;margin-top:6px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                            <span>Department: <strong><?= htmlspecialchars($arch['department'] ?: 'SDSF') ?></strong></span>
                            &bull;
                            <span>Archived on: <strong><?= date('d M Y, h:i A', strtotime($arch['archived_at'])) ?></strong></span>
                            &bull;
                            <span>Archived by: <strong><?= htmlspecialchars($arch['archived_by'] ?: 'Admin') ?></strong></span>
                        </div>
                    </div>
                </div>

                <!-- Header Actions -->
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button type="button" class="btn btn-outline btn-sm no-print" onclick="window.print()" style="background:rgba(255,255,255,0.12);color:#fff;border-color:rgba(255,255,255,0.3);">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                        Print / Save Dossier PDF
                    </button>
                    <?php if (!$isRestored): ?>
                        <button type="button" class="btn btn-sm" style="background:#10b981;color:#fff;font-weight:800;" onclick="openRestoreModal()">
                            ✓ Restore Faculty
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Metric Summary Cards -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;margin-bottom:24px;" class="fade-up">
            <div class="card" style="padding:18px 22px;">
                <div style="font-size:11.5px;font-weight:700;color:#64748b;text-transform:uppercase;">Assigned Subjects</div>
                <div style="font-size:24px;font-weight:800;color:#0f172a;margin-top:4px;"><?= count($coursesData) ?></div>
                <div style="font-size:12px;color:#94a3b8;margin-top:2px;">Curriculum mappings</div>
            </div>
            <div class="card" style="padding:18px 22px;">
                <div style="font-size:11.5px;font-weight:700;color:#64748b;text-transform:uppercase;">Preserved Lectures</div>
                <div style="font-size:24px;font-weight:800;color:#4f46e5;margin-top:4px;"><?= (int)$arch['total_lectures'] ?></div>
                <div style="font-size:12px;color:#94a3b8;margin-top:2px;"><?= number_format((float)$arch['total_hours'], 1) ?> total hours</div>
            </div>
            <div class="card" style="padding:18px 22px;">
                <div style="font-size:11.5px;font-weight:700;color:#64748b;text-transform:uppercase;">Student Attendances</div>
                <div style="font-size:24px;font-weight:800;color:#0284c7;margin-top:4px;"><?= count($attendanceData) ?></div>
                <div style="font-size:12px;color:#94a3b8;margin-top:2px;">Individual student check-ins</div>
            </div>
            <div class="card" style="padding:18px 22px;">
                <div style="font-size:11.5px;font-weight:700;color:#64748b;text-transform:uppercase;">Historical Claims</div>
                <div style="font-size:24px;font-weight:800;color:#047857;margin-top:4px;">&#8377;<?= number_format((float)$arch['total_amount'], 2) ?></div>
                <div style="font-size:12px;color:#94a3b8;margin-top:2px;"><?= count($reportsData) ?> monthly submissions</div>
            </div>
        </div>

        <!-- Section 1: Profile & Banking Information -->
        <div class="grid-2 fade-up" style="margin-bottom:24px;">
            <!-- Personal & Contact -->
            <div class="section-box" style="margin-bottom:0;">
                <div class="section-head">
                    <h3 class="section-title">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2.2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Personal &amp; Contact Particulars
                    </h3>
                </div>
                <div style="padding:16px 22px;">
                    <div class="info-row">
                        <span class="info-label">Full Name:</span>
                        <span class="info-value"><?= htmlspecialchars($arch['name']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Enrollment Code:</span>
                        <span class="info-value"><code style="color:#4f46e5;font-weight:700;"><?= htmlspecialchars($arch['faculty_enrollment_no']) ?></code></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Qualification:</span>
                        <span class="info-value"><?= htmlspecialchars($arch['qualification'] ?: '—') ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Email Address:</span>
                        <span class="info-value"><?= htmlspecialchars($arch['email'] ?: '—') ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Phone Number:</span>
                        <span class="info-value"><?= htmlspecialchars($arch['phone'] ?: '—') ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Postal Address:</span>
                        <span class="info-value"><?= htmlspecialchars($arch['address'] ?: '—') ?></span>
                    </div>
                </div>
            </div>

            <!-- Banking & Rates -->
            <div class="section-box" style="margin-bottom:0;">
                <div class="section-head">
                    <h3 class="section-title">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#047857" stroke-width="2.2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                        Banking, Tax &amp; Honorarium Rates
                    </h3>
                </div>
                <div style="padding:16px 22px;">
                    <div class="info-row">
                        <span class="info-label">PAN Number:</span>
                        <span class="info-value"><code style="font-weight:700;"><?= htmlspecialchars($arch['pan_no'] ?: '—') ?></code></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Aadhaar Number:</span>
                        <span class="info-value"><code style="font-weight:700;"><?= htmlspecialchars($arch['aadhaar_no'] ?: '—') ?></code></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Bank Name:</span>
                        <span class="info-value"><?= htmlspecialchars($arch['bank_name'] ?: '—') ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Account Number:</span>
                        <span class="info-value"><code style="font-weight:700;"><?= htmlspecialchars($arch['account_no'] ?: '—') ?></code></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">IFSC Code:</span>
                        <span class="info-value"><code style="font-weight:700;"><?= htmlspecialchars($arch['ifsc_code'] ?: '—') ?></code></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Prescribed Rates:</span>
                        <span class="info-value">
                            Theory: <strong>&#8377;<?= (float)$arch['theory_rate'] ?>/hr</strong> &bull; Practical: <strong>&#8377;<?= (float)$arch['practical_rate'] ?>/hr</strong>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 2: Assigned Subjects Table -->
        <div class="section-box fade-up">
            <div class="section-head">
                <h3 class="section-title">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2.2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                    Preserved Assigned Courses &amp; Curriculum Mapping
                </h3>
                <span class="stat-pill"><?= count($coursesData) ?> Subjects Mapped</span>
            </div>
            <?php if (empty($coursesData)): ?>
                <div style="padding:28px;text-align:center;color:#94a3b8;">No course assignments were recorded for this faculty member.</div>
            <?php else: ?>
                <table class="dt">
                    <thead>
                        <tr>
                            <th>Program</th>
                            <th>Semester</th>
                            <th>Subject Name</th>
                            <th>Course Code</th>
                            <th>Class Type</th>
                            <th style="text-align:right;">Export Attendance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($coursesData as $c): ?>
                        <tr>
                            <td style="font-weight:700;color:#0f172a;"><?= htmlspecialchars($c['program']) ?></td>
                            <td><span style="font-size:12px;font-weight:700;color:#4f46e5;background:#eef2ff;padding:2px 8px;border-radius:4px;"><?= htmlspecialchars($c['semester'] ?? '—') ?></span></td>
                            <td style="font-weight:600;"><?= htmlspecialchars($c['subject_name'] ?? ($c['course_name'] ?? '—')) ?></td>
                            <td><code style="font-size:12px;"><?= htmlspecialchars($c['course_code'] ?? '—') ?></code></td>
                            <td>
                                <?php if (($c['class_type'] ?? 'T') === 'P'): ?>
                                    <span class="badge badge-amber">Practical (P)</span>
                                <?php else: ?>
                                    <span class="badge badge-blue">Theory (T)</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;">
                                <a href="<?= BASE_URL ?>/admin/reports/student_attendance_pdf.php?course_id=<?= (int)$c['course_id'] ?>&semester=<?= urlencode($c['semester'] ?? '') ?>"
                                   target="_blank" class="btn btn-outline btn-sm" style="font-size:11.5px;padding:4px 10px;">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    Download PDF
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Section 3: Conducted Lecture Ledger -->
        <div class="section-box fade-up">
            <div class="section-head">
                <h3 class="section-title">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2.2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Preserved Conducted Lecture History Logs
                </h3>
                <span class="stat-pill"><?= count($lecturesData) ?> Sessions &bull; <?= number_format((float)$arch['total_hours'], 1) ?> Hours</span>
            </div>
            <?php if (empty($lecturesData)): ?>
                <div style="padding:28px;text-align:center;color:#94a3b8;">No lecture sessions recorded in this backup.</div>
            <?php else: ?>
                <table class="dt">
                    <thead>
                        <tr>
                            <th style="width:110px;">Date</th>
                            <th>Subject &amp; Code</th>
                            <th>Program &amp; Sem</th>
                            <th>Type</th>
                            <th>Duration</th>
                            <th>Hourly Rate</th>
                            <th>Amount</th>
                            <th>Student Attendance</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lecturesData as $lec): 
                            $lid = (int)$lec['id'];
                            $attInfo = $attendanceByLecture[$lid] ?? null;
                        ?>
                        <tr>
                            <td style="font-weight:700;color:#0f172a;white-space:nowrap;">
                                <?= date('d M Y', strtotime($lec['lecture_date'])) ?>
                            </td>
                            <td>
                                <div style="font-weight:700;color:#0f172a;"><?= htmlspecialchars($lec['subject_name'] ?? '—') ?></div>
                                <code style="font-size:11px;color:#64748b;"><?= htmlspecialchars($lec['course_code'] ?? '—') ?></code>
                            </td>
                            <td>
                                <div style="font-size:12.5px;font-weight:600;"><?= htmlspecialchars($lec['program'] ?? '—') ?></div>
                                <span style="font-size:11px;color:#4f46e5;font-weight:700;"><?= htmlspecialchars($lec['semester'] ?? '—') ?></span>
                            </td>
                            <td>
                                <?php if (($lec['class_type'] ?? 'T') === 'P'): ?>
                                    <span class="badge badge-amber">Practical</span>
                                <?php else: ?>
                                    <span class="badge badge-blue">Theory</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:700;color:#0f172a;"><?= number_format((float)$lec['hours'], 1) ?>h</td>
                            <td style="color:#64748b;">&#8377;<?= number_format((float)$lec['rate_per_hour'], 0) ?></td>
                            <td style="font-weight:800;color:#047857;">&#8377;<?= number_format((float)$lec['amount'], 2) ?></td>
                            <td>
                                <?php if ($attInfo && $attInfo['total'] > 0): 
                                    $pct = round(($attInfo['present'] / $attInfo['total']) * 100);
                                ?>
                                    <span class="badge <?= $pct >= 75 ? 'badge-green' : ($pct >= 50 ? 'badge-yellow' : 'badge-red') ?>" style="font-size:11px;">
                                        <?= $attInfo['present'] ?> / <?= $attInfo['total'] ?> (<?= $pct ?>%)
                                    </span>
                                <?php else: ?>
                                    <span style="font-size:11.5px;color:#94a3b8;font-style:italic;">None recorded</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;">
                                <?php if ($attInfo && $attInfo['total'] > 0): ?>
                                    <a href="<?= BASE_URL ?>/admin/reports/student_attendance_pdf.php?archive_id=<?= $arch['id'] ?>&lecture_id=<?= $lid ?>"
                                       target="_blank" class="btn btn-outline btn-sm" style="font-size:11.5px;padding:3px 8px;">
                                        PDF Sheet
                                    </a>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Section 4: Remuneration Claims & Monthly Submissions -->
        <?php if (!empty($reportsData)): ?>
        <div class="section-box fade-up">
            <div class="section-head">
                <h3 class="section-title">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#047857" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    Preserved Monthly Claim Bills &amp; Audit Submissions
                </h3>
                <span class="stat-pill"><?= count($reportsData) ?> Monthly Statements</span>
            </div>
            <table class="dt">
                <thead>
                    <tr>
                        <th>Claim Period</th>
                        <th>Submission Date</th>
                        <th>Register Reference</th>
                        <th>Cheque No</th>
                        <th>Theory Hours</th>
                        <th>Practical Hours</th>
                        <th>Total Amount</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reportsData as $rep): ?>
                    <tr>
                        <td style="font-weight:700;color:#0f172a;">
                            <?= date('F Y', mktime(0,0,0, (int)$rep['month'], 1, (int)$rep['year'])) ?>
                        </td>
                        <td><?= date('d M Y', strtotime($rep['submission_date'])) ?></td>
                        <td><code style="font-size:11.5px;"><?= htmlspecialchars($rep['attendance_register_page'] ?? '—') ?></code></td>
                        <td><code style="font-size:11.5px;"><?= htmlspecialchars($rep['cheque_no'] ?? 'Pending') ?></code></td>
                        <td><?= (float)$rep['theory_hours'] ?>h</td>
                        <td><?= (float)$rep['practical_hours'] ?>h</td>
                        <td style="font-weight:800;color:#047857;">&#8377;<?= number_format((float)$rep['total_amount'], 2) ?></td>
                        <td style="text-align:right;">
                            <a href="<?= BASE_URL ?>/admin/reports/annexure_iv.php?faculty_id=<?= (int)$arch['original_faculty_id'] ?>&month=<?= (int)$rep['month'] ?>&year=<?= (int)$rep['year'] ?>&archive_id=<?= $arch['id'] ?>"
                               target="_blank" class="btn btn-outline btn-sm" style="font-size:11.5px;padding:4px 10px;">
                                Annexure-IV &rarr;
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div>

    <!-- Restore Confirmation Modal -->
    <div id="restoreModal" class="restore-modal-overlay">
        <div style="background:#fff;border-radius:20px;padding:34px 30px;max-width:480px;width:95%;box-shadow:0 24px 60px rgba(0,0,0,0.22);animation:fadeUp .25s ease both;">
            <div style="width:58px;height:58px;border-radius:16px;background:#ecfdf5;border:2px solid #a7f3d0;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
            </div>
            <h2 style="font-size:19px;font-weight:800;color:#0f172a;text-align:center;margin:0 0 8px;">Restore Faculty Member?</h2>
            <p style="font-size:13.5px;color:#64748b;text-align:center;margin:0 0 18px;line-height:1.55;">
                You are about to restore this faculty member back into the active SDSF directory:
            </p>
            <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:18px;text-align:center;">
                <div style="font-size:16px;font-weight:800;color:#0f172a;"><?= htmlspecialchars($arch['name']) ?></div>
                <div style="font-size:12px;font-family:monospace;font-weight:700;color:#4f46e5;background:#eef2ff;display:inline-block;padding:2px 10px;border-radius:6px;margin-top:6px;">
                    <?= htmlspecialchars($arch['faculty_enrollment_no']) ?>
                </div>
            </div>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 16px;margin-bottom:24px;display:flex;gap:10px;align-items:flex-start;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.2" style="flex-shrink:0;margin-top:1px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <div style="font-size:12.5px;color:#166534;line-height:1.5;">
                    Restoring will reinstate all <strong><?= count($coursesData) ?> assigned courses</strong>, <strong><?= (int)$arch['total_lectures'] ?> lecture records</strong>, <strong>student attendances</strong>, and login access. The teacher can immediately log in using their original password.
                </div>
            </div>
            <div style="display:flex;gap:12px;">
                <button type="button" onclick="closeRestoreModal()"
                    style="flex:1;padding:12px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;font-size:14px;font-weight:600;color:#475569;cursor:pointer;">
                    Cancel
                </button>
                <form method="POST" action="<?= BASE_URL ?>/admin/faculty/restore.php" style="flex:1;margin:0;">
                    <input type="hidden" name="archive_id" value="<?= $arch['id'] ?>">
                    <button type="submit"
                        style="width:100%;padding:12px;border-radius:10px;border:none;background:linear-gradient(135deg,#059669,#047857);color:#fff;font-size:14px;font-weight:700;cursor:pointer;box-shadow:0 2px 10px rgba(5,150,105,0.28);">
                        Yes, Restore Faculty
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
    function openRestoreModal() {
        document.getElementById('restoreModal').style.display = 'flex';
    }
    function closeRestoreModal() {
        document.getElementById('restoreModal').style.display = 'none';
    }
    document.getElementById('restoreModal').addEventListener('click', function(e) {
        if (e.target === this) closeRestoreModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeRestoreModal();
    });
    </script>
</body>
</html>
