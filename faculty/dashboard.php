<?php
define('ROOT', dirname(dirname(__FILE__)));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireFaculty();

$facultyId = (int)$_SESSION['faculty_id'];

// Get faculty details
$stmt = $pdo->prepare("SELECT * FROM faculty_members WHERE id = ?");
$stmt->execute([$facultyId]);
$faculty = $stmt->fetch();

if (!$faculty) {
    session_destroy();
    header('Location: ' . BASE_URL . '/faculty_login.php');
    exit;
}

// Get assigned courses
$cStmt = $pdo->prepare("
    SELECT c.* 
    FROM courses c
    JOIN faculty_course_assignments fca ON fca.course_id = c.id
    WHERE fca.faculty_id = ?
    ORDER BY c.program, c.semester, c.subject_name
");
$cStmt->execute([$facultyId]);
$courses = $cStmt->fetchAll();

// Get recent lectures
$lStmt = $pdo->prepare("
    SELECT le.*, c.subject_name, c.course_code, c.program, c.semester, c.class_type AS course_class_type, le.class_type
    FROM lecture_entries le
    JOIN courses c ON c.id = le.course_id
    WHERE le.faculty_id = ?
    ORDER BY le.lecture_date DESC, le.id DESC
    LIMIT 6
");
$lStmt->execute([$facultyId]);
$recentLectures = $lStmt->fetchAll();

// Overall stats
$sStmt = $pdo->prepare("
    SELECT COUNT(*) as total_lectures,
           COALESCE(SUM(hours), 0) as total_hours,
           COALESCE(SUM(amount), 0) as total_earnings
    FROM lecture_entries
    WHERE faculty_id = ?
");
$sStmt->execute([$facultyId]);
$stats = $sStmt->fetch();

// Month-wise earnings breakdown (All-time per month)
$mwStmt = $pdo->prepare("
    SELECT YEAR(lecture_date) as yr, MONTH(lecture_date) as mo,
           COUNT(*) as session_count,
           COALESCE(SUM(hours), 0) as total_hours,
           COALESCE(SUM(CASE WHEN class_type = 'P' THEN hours ELSE 0 END), 0) as practical_hours,
           COALESCE(SUM(CASE WHEN class_type != 'P' THEN hours ELSE 0 END), 0) as theory_hours,
           COALESCE(SUM(amount), 0) as total_amount
    FROM lecture_entries
    WHERE faculty_id = ?
    GROUP BY yr, mo
    ORDER BY yr DESC, mo DESC
");
$mwStmt->execute([$facultyId]);
$monthWiseEarnings = $mwStmt->fetchAll(PDO::FETCH_ASSOC);

// Current month stats
$currentMonth = (int)date('m');
$currentYear  = (int)date('Y');
$mStmt = $pdo->prepare("
    SELECT COALESCE(SUM(hours), 0) as m_hours,
           COALESCE(SUM(amount), 0) as m_amount,
           COUNT(*) as m_count
    FROM lecture_entries
    WHERE faculty_id = ? AND MONTH(lecture_date) = ? AND YEAR(lecture_date) = ?
");
$mStmt->execute([$facultyId, $currentMonth, $currentYear]);
$monthStats = $mStmt->fetch();

// If current month has 0 entries and there are past entries, default to latest active month
$selectedMonth = $currentMonth;
$selectedYear  = $currentYear;
if ((int)$monthStats['m_count'] === 0 && !empty($monthWiseEarnings)) {
    $selectedMonth = (int)$monthWiseEarnings[0]['mo'];
    $selectedYear  = (int)$monthWiseEarnings[0]['yr'];
    $mStmt->execute([$facultyId, $selectedMonth, $selectedYear]);
    $monthStats = $mStmt->fetch();
}
$monthAmount = (float)$monthStats['m_amount'];
$monthHours  = (float)$monthStats['m_hours'];
$selectedMonthLabel = date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear));
$annexureStartYear = max(2026, $selectedYear);
$annexureStartMonth = ($annexureStartYear == 2026) ? max(8, $selectedMonth) : $selectedMonth;

$flash = getFlash();
$active_nav = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Dashboard — SDSF Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
    <?php require_once ROOT . '/includes/faculty_sidebar.php'; ?>
    <style>
        .badge-theory { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 20px; }
        .badge-practical { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 20px; }
        .btn-teal { background: #1e3a8a; color: #fff; padding: 8px 16px; border-radius: 9px; font-size: 13.5px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; border: none; cursor: pointer; transition: all .2s; }
        .btn-teal:hover { background: #172554; }
        .btn-outline-teal { background: #fff; color: #1e3a8a; border: 1.5px solid #bfdbfe; padding: 7px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all .2s; }
        .btn-outline-teal:hover { background: #eff6ff; }
        table.ft { width: 100%; border-collapse: collapse; }
        .ft th { padding: 12px 20px; text-align: left; font-size: 11.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #94a3b8; background: #fafafa; border-bottom: 1px solid #f1f5f9; }
        .ft td { padding: 14px 20px; font-size: 14px; color: #334155; border-bottom: 1px solid #f8fafc; }
        .ft tr:last-child td { border-bottom: none; }
        .ft tr:hover td { background: #f8fafc; }

        @media (max-width: 768px) {
            .welcome-inner { padding: 16px 14px !important; }
            .welcome-title { font-size: 18px !important; }
            .welcome-btns { width: 100% !important; display: flex; flex-wrap: wrap; gap: 8px; }
            .welcome-btns .btn { flex: 1 1 calc(50% - 6px); justify-content: center; font-size: 12px !important; padding: 8px 10px !important; }
            .doc-form-wrap { padding: 14px 16px !important; }
            .doc-form-wrap form > div { width: 100% !important; }
            .doc-form-wrap select { width: 100% !important; }
            .doc-form-wrap .btn { width: 100% !important; justify-content: center; }
            .tb-right { gap: 6px; }
            .tb-right .btn-sm { padding: 6px 10px; font-size: 12px; }
            .btn-text-hide-sm { display: none; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="tb-left">
            <span class="tb-crumb">Faculty Dashboard</span>
        </div>
        <div class="tb-right">
            <span class="tb-date"><?= date('l, d F Y') ?></span>
            <a href="<?= BASE_URL ?>/admin/reports/generate_html_pdf.php?faculty_id=<?= $facultyId ?>&month=<?= $annexureStartMonth ?>&year=<?= $annexureStartYear ?>" target="_blank" class="btn btn-outline btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                <span>PDF <span class="btn-text-hide-sm">Bill</span></span>
            </a>
            <a href="<?= BASE_URL ?>/faculty/lecture_entry.php" class="btn btn-primary btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Log Lecture
            </a>
        </div>
    </header>

    <div class="page">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] ?>">
                <?= htmlspecialchars($flash['msg']) ?>
            </div>
        <?php endif; ?>

        <!-- Welcome Banner -->
        <div class="card" style="margin-bottom:24px;background:linear-gradient(135deg,#eff6ff 0%,#ffffff 60%,#f0fdfa 100%);border-color:#bfdbfe;">
            <div class="welcome-inner" style="padding:24px 28px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;">
                <div style="display:flex;align-items:center;gap:18px;">
                    <div>
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
                            <span style="font-size:11.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#1e3a8a;background:#eff6ff;padding:2px 9px;border-radius:20px;border:1px solid #bfdbfe;">
                                Visiting Faculty Member
                            </span>
                            <span style="font-size:13px;color:#64748b;">
                                <?= htmlspecialchars($faculty['department']) ?>
                            </span>
                        </div>
                        <h1 class="welcome-title" style="font-size:22px;font-weight:800;color:#0f172a;margin:0 0 4px;">Welcome back, <?= htmlspecialchars($faculty['name']) ?>!</h1>
                        <p style="font-size:13.5px;color:#64748b;margin:0;">
                            SDSF Portal &bull; Enrollment No: <strong style="color:#1e3a8a;font-family:monospace;"><?= htmlspecialchars($faculty['faculty_enrollment_no']) ?></strong>
                        </p>
                    </div>
                </div>
                <div class="welcome-btns" style="display:flex;gap:8px;flex-wrap:wrap;">
                    <a href="<?= BASE_URL ?>/admin/reports/annexure_iv.php?faculty_id=<?= $facultyId ?>&month=<?= $annexureStartMonth ?>&year=<?= $annexureStartYear ?>" target="_blank" class="btn btn-outline" style="font-size:13px;padding:8px 14px;" title="Official Annexure-IV Bill">
                        📄 Annexure-IV
                    </a>
                    <a href="<?= BASE_URL ?>/admin/reports/visiting_faculty_attendance.php?faculty_id=<?= $facultyId ?>&month=<?= $annexureStartMonth ?>&year=<?= $annexureStartYear ?>" target="_blank" class="btn btn-outline" style="font-size:13px;padding:8px 14px;" title="Visiting Faculty Teaching Attendance">
                        📊 Attendance
                    </a>
                    <a href="<?= BASE_URL ?>/admin/reports/detailed_remuneration.php?faculty_id=<?= $facultyId ?>&month=<?= $annexureStartMonth ?>&year=<?= $annexureStartYear ?>" target="_blank" class="btn btn-outline" style="font-size:13px;padding:8px 14px;" title="Annexure IV-A Detailed Remuneration">
                        📋 Annexure IV-A
                    </a>
                    <a href="<?= BASE_URL ?>/faculty/lecture_entry.php" class="btn btn-primary" style="padding:10px 18px;font-size:13.5px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Log New Lecture
                    </a>
                </div>
            </div>
        </div>

        <?php if ($monthAmount > 30000): ?>
            <div class="alert alert-warning" style="display:flex;align-items:center;gap:12px;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <div>
                    <strong>Monthly Limit Reached:</strong> Your current calculated remuneration for <?= date('F Y') ?> is <strong>&#8377;<?= number_format($monthAmount, 2) ?></strong>, which exceeds the standard DAVV maximum monthly remuneration ceiling of &#8377;30,000.
                </div>
            </div>
        <?php endif; ?>

        <!-- Stats Grid -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:20px;margin-bottom:28px;">
            <div class="card" style="padding:22px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <span style="font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Assigned Courses</span>
                    <div style="width:36px;height:36px;border-radius:10px;background:#f0fdfa;color:#0d9488;display:flex;align-items:center;justify-content:center;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                    </div>
                </div>
                <div style="font-size:28px;font-weight:800;color:#0f172a;"><?= count($courses) ?></div>
                <div style="font-size:12.5px;color:#64748b;margin-top:2px;">Subjects available to teach</div>
            </div>

            <div class="card" style="padding:22px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <span id="fac-hours-label" style="font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;"><?= htmlspecialchars($selectedMonthLabel) ?> Hours</span>
                    <div style="width:36px;height:36px;border-radius:10px;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                </div>
                <div id="fac-hours-val" style="font-size:28px;font-weight:800;color:#2563eb;"><?= (float)$monthHours ?> hrs</div>
                <div id="fac-hours-sessions" style="font-size:12.5px;color:#64748b;margin-top:2px;"><?= $monthStats['m_count'] ?> sessions this month</div>
            </div>

            <div class="card" style="padding:22px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <span id="fac-amount-label" style="font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;"><?= htmlspecialchars($selectedMonthLabel) ?> Remuneration</span>
                    <div style="width:36px;height:36px;border-radius:10px;background:#f0fdf4;color:#16a34a;display:flex;align-items:center;justify-content:center;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                </div>
                <div id="fac-amount-val" style="font-size:28px;font-weight:800;color:#047857;">&#8377;<?= number_format($monthAmount, 2) ?></div>
                <div id="fac-amount-note" style="font-size:12.5px;color:<?= $monthAmount > 30000 ? '#b45309' : '#64748b' ?>;margin-top:2px;">
                    <?= $monthAmount > 30000 ? 'Exceeds &#8377;30,000 ceiling' : 'Under monthly limit' ?>
                </div>
            </div>

            <div class="card" style="padding:22px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <span style="font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;">All-Time Earnings</span>
                    <div style="width:36px;height:36px;border-radius:10px;background:#faf5ff;color:#7c3aed;display:flex;align-items:center;justify-content:center;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                    </div>
                </div>
                <div style="font-size:28px;font-weight:800;color:#0f172a;">&#8377;<?= number_format((float)$stats['total_earnings'], 2) ?></div>
                <div id="fac-alltime-sub" style="font-size:12.5px;color:#64748b;margin-top:2px;"><?= (float)$stats['total_hours'] ?> total hours conducted</div>
            </div>
        </div>

        <!-- Official Remuneration & Attendance Documents Generator Card -->
        <div class="card" style="margin-bottom:28px;border:1.5px solid #bfdbfe;background:#f8fafc;">
            <div class="card-head" style="background:#ffffff;">
                <div>
                    <h2 style="font-size:16px;font-weight:700;color:#1e3a8a;margin:0;">Official Monthly Documents &amp; Reports</h2>
                    <div style="font-size:12.5px;color:#64748b;margin-top:2px;">Select the billing period and generate any of the 3 official DAVV documents ready for print and submission.</div>
                </div>
                <span class="badge badge-blue">Official DAVV Formats</span>
            </div>
            <div class="doc-form-wrap" style="padding:20px 24px;">
                <form method="GET" target="_blank" style="display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;">
                    <input type="hidden" name="faculty_id" value="<?= $facultyId ?>">
                    <div>
                        <label style="display:block;font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#475569;margin-bottom:6px;">Billing Year</label>
                        <select name="year" id="docYear" class="form-select" onchange="updateDocMonths(); fetchFacDocStats();" style="padding:9px 12px;background:#fff;border:1.5px solid #cbd5e1;border-radius:10px;font-size:14px;color:#0f172a;width:120px;font-family:'Inter',sans-serif;outline:none;">
                            <?php for ($y = 2026; $y <= 2028; $y++): ?>
                                <option value="<?= $y ?>" <?= $y == $selectedYear ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#475569;margin-bottom:6px;">Billing Month</label>
                        <select name="month" id="docMonth" class="form-select" onchange="fetchFacDocStats();" style="padding:9px 12px;background:#fff;border:1.5px solid #cbd5e1;border-radius:10px;font-size:14px;color:#0f172a;width:160px;font-family:'Inter',sans-serif;outline:none;">
                            <?php 
                            $startMonth = ($selectedYear == 2026) ? 8 : 1;
                            for ($m = $startMonth; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $m == $selectedMonth ? 'selected' : '' ?>>
                                    <?= date('F', mktime(0,0,0,$m,1)) ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <button type="submit" formaction="<?= BASE_URL ?>/admin/reports/annexure_iv.php" class="btn btn-primary" style="padding:10px 16px;">
                            📄 Annexure-IV (Bill)
                        </button>
                        <button type="submit" formaction="<?= BASE_URL ?>/admin/reports/visiting_faculty_attendance.php" class="btn btn-outline" style="background:#fff;padding:10px 16px;">
                            📊 Attendance Sheet
                        </button>
                        <button type="submit" formaction="<?= BASE_URL ?>/admin/reports/detailed_remuneration.php" class="btn btn-outline" style="background:#fff;padding:10px 16px;">
                            📋 Annexure IV-A (Detailed)
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Month-Wise Earnings & Hours Summary Table Card -->
        <div class="card" style="margin-bottom:28px;">
            <div class="card-head" style="background:#ffffff;">
                <div>
                    <h2 style="font-size:16px;font-weight:700;color:#0f172a;margin:0;">Month-Wise Earnings &amp; Attendance Summary</h2>
                    <div style="font-size:12.5px;color:#64748b;margin-top:2px;">Auditable breakdown of your monthly teaching hours, theory/practical sessions, and honorarium earnings</div>
                </div>
                <span class="badge badge-green"><?= count($monthWiseEarnings) ?> Active Month<?= count($monthWiseEarnings) !== 1 ? 's' : '' ?></span>
            </div>
            <?php if (empty($monthWiseEarnings)): ?>
                <div style="padding:36px;text-align:center;color:#94a3b8;">
                    No lecture sessions recorded yet. Once logged, all monthly earnings will be itemized here.
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="ft">
                        <thead>
                            <tr>
                                <th>Billing Month</th>
                                <th>Theory Hours</th>
                                <th>Practical Hours</th>
                                <th>Total Hours</th>
                                <th>Sessions</th>
                                <th>Gross Remuneration</th>
                                <th>Monthly Ceiling</th>
                                <th style="text-align:right;">Official DAVV Documents</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthWiseEarnings as $mwe): 
                                $mName = date('F Y', mktime(0, 0, 0, $mwe['mo'], 1, $mwe['yr']));
                                $isExceeded = (float)$mwe['total_amount'] > 30000;
                            ?>
                            <tr>
                                <td style="font-weight:700;color:#0f172a;">
                                    <?= $mName ?>
                                </td>
                                <td style="color:#2563eb;font-weight:600;">
                                    <?= (float)$mwe['theory_hours'] ?> hrs
                                </td>
                                <td style="color:#b45309;font-weight:600;">
                                    <?= (float)$mwe['practical_hours'] ?> hrs
                                </td>
                                <td style="font-weight:700;color:#0f172a;">
                                    <?= (float)$mwe['total_hours'] ?> hrs
                                </td>
                                <td style="color:#64748b;">
                                    <?= (int)$mwe['session_count'] ?> sessions
                                </td>
                                <td style="font-weight:800;color:#047857;font-size:15px;">
                                    &#8377;<?= number_format((float)$mwe['total_amount'], 2) ?>
                                </td>
                                <td>
                                    <?php if ($isExceeded): ?>
                                        <span class="badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;">Exceeds &#8377;30k Limit</span>
                                    <?php else: ?>
                                        <span class="badge badge-green">Within Limit</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <div style="display:inline-flex;gap:6px;justify-content:flex-end;">
                                        <a href="<?= BASE_URL ?>/admin/reports/annexure_iv.php?faculty_id=<?= $facultyId ?>&month=<?= $mwe['mo'] ?>&year=<?= $mwe['yr'] ?>" target="_blank" class="btn btn-outline" style="padding:4px 10px;font-size:12px;" title="Annexure-IV Claim Bill">
                                            📄 Annexure-IV
                                        </a>
                                        <a href="<?= BASE_URL ?>/admin/reports/visiting_faculty_attendance.php?faculty_id=<?= $facultyId ?>&month=<?= $mwe['mo'] ?>&year=<?= $mwe['yr'] ?>" target="_blank" class="btn btn-outline" style="padding:4px 10px;font-size:12px;" title="Teaching Attendance">
                                            📊 Attendance
                                        </a>
                                        <a href="<?= BASE_URL ?>/admin/reports/detailed_remuneration.php?faculty_id=<?= $facultyId ?>&month=<?= $mwe['mo'] ?>&year=<?= $mwe['yr'] ?>" target="_blank" class="btn btn-outline" style="padding:4px 10px;font-size:12px;" title="Annexure IV-A Detailed">
                                            📋 Detailed
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Assigned Courses Card — Course Cards Grid -->
        <div class="card" style="margin-bottom:28px;">
            <div class="card-head">
                <div>
                    <h2 style="font-size:16px;font-weight:700;color:#0f172a;margin:0;">My Assigned Courses</h2>
                    <div style="font-size:12.5px;color:#94a3b8;margin-top:2px;">Click "Log Lecture" on any course to submit a lecture session for that subject</div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:12px;font-weight:700;background:#eef2ff;color:#4f46e5;padding:4px 12px;border-radius:20px;border:1px solid #c7d2fe;"><?= count($courses) ?> Course<?= count($courses) != 1 ? 's' : '' ?></span>
                    <a href="<?= BASE_URL ?>/faculty/lecture_entry.php" class="btn-teal" style="font-size:12.5px;padding:7px 14px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Log Lecture
                    </a>
                </div>
            </div>

            <?php if (empty($courses)): ?>
                <div style="padding:40px;text-align:center;color:#94a3b8;">
                    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 14px;display:block;opacity:.3"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                    No courses have been assigned to your account yet.<br>
                    <span style="font-size:13px;">Please contact the SDSF administrator.</span>
                </div>
            <?php else: ?>
                <div style="padding:20px;display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;">
                    <?php foreach ($courses as $c):
                        $isTheory = ($c['class_type'] === 'T');
                        $rate     = $isTheory ? (float)($faculty['theory_rate'] ?? 800) : (float)($faculty['practical_rate'] ?? 400);
                        $typeLabel= $isTheory ? 'Theory' : 'Practical';
                        $typeColor= $isTheory ? '#2563eb' : '#b45309';
                        $typeBg   = $isTheory ? '#eff6ff' : '#fffbeb';
                        $typeBdr  = $isTheory ? '#bfdbfe' : '#fde68a';
                        $accentClr= $isTheory ? '#4f46e5' : '#7c3aed';
                    ?>
                    <div style="border:1.5px solid #e2e8f0;border-radius:16px;overflow:hidden;transition:box-shadow .2s;background:#fff;"
                         onmouseenter="this.style.boxShadow='0 4px 20px rgba(79,70,229,0.10)';this.style.borderColor='#c7d2fe';"
                         onmouseleave="this.style.boxShadow='none';this.style.borderColor='#e2e8f0';">
                        <!-- Card color accent bar -->
                        <div style="height:4px;background:linear-gradient(90deg,<?= $accentClr ?>,<?= $isTheory ? '#818cf8' : '#a78bfa' ?>);"></div>
                        <div style="padding:18px 20px;">
                            <!-- Program & Semester badge -->
                            <div style="font-size:11px;font-weight:700;color:<?= $accentClr ?>;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">
                                <?= htmlspecialchars($c['program']) ?> &bull; <?= htmlspecialchars($c['semester'] ?? '') ?>
                            </div>
                            <!-- Subject name -->
                            <div style="font-size:15px;font-weight:700;color:#0f172a;margin-bottom:4px;line-height:1.3;">
                                <?= htmlspecialchars($c['subject_name']) ?>
                            </div>
                            <!-- Course code -->
                            <?php if (!empty($c['course_code'])): ?>
                            <div style="font-size:12px;font-family:monospace;color:#64748b;background:#f8fafc;display:inline-block;padding:2px 8px;border-radius:5px;border:1px solid #e2e8f0;margin-bottom:12px;">
                                <?= htmlspecialchars($c['course_code']) ?>
                            </div>
                            <?php endif; ?>
                            <!-- Type & Rate row -->
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-top:10px;margin-bottom:16px;">
                                <span style="font-size:12px;font-weight:700;background:<?= $typeBg ?>;color:<?= $typeColor ?>;border:1px solid <?= $typeBdr ?>;padding:4px 10px;border-radius:20px;">
                                    <?= $typeLabel ?> Class
                                </span>
                                <span style="font-size:15px;font-weight:800;color:#047857;">
                                    &#8377;<?= number_format($rate) ?><span style="font-size:11px;font-weight:500;color:#64748b;">/hr</span>
                                </span>
                            </div>
                            <!-- Log Lecture button -->
                            <a href="<?= BASE_URL ?>/faculty/lecture_entry.php?course_id=<?= $c['id'] ?>"
                               style="display:flex;align-items:center;justify-content:center;gap:7px;width:100%;padding:10px;background:linear-gradient(135deg,<?= $accentClr ?>,<?= $isTheory ? '#818cf8' : '#a78bfa' ?>);color:#fff;border-radius:10px;font-size:13.5px;font-weight:700;text-decoration:none;transition:opacity .18s;box-sizing:border-box;"
                               onmouseenter="this.style.opacity='.88';" onmouseleave="this.style.opacity='1';">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                Log Lecture for this Course
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>


        <div class="card" style="margin-bottom:28px;">
            <div class="card-head">
                <div>
                    <h2 style="font-size:16px;font-weight:700;color:#0f172a;margin:0;">My Assigned Courses</h2>
                    <div style="font-size:12.5px;color:#94a3b8;margin-top:2px;">Click "Log Lecture" on any course to submit a lecture session</div>
                </div>
                <a href="<?= BASE_URL ?>/faculty/lecture_entry.php" class="btn-outline-teal">
                    + Log New Lecture
                </a>
            </div>

            <?php if (empty($courses)): ?>
                <div style="padding:40px;text-align:center;color:#94a3b8;">
                    No courses have been assigned to your account yet. Please contact the SDSF administrator.
                </div>
            <?php else: ?>
                <table class="ft">
                    <thead>
                        <tr>
                            <th>Program</th>
                            <th>Semester</th>
                            <th>Subject Name</th>
                            <th>Course Code</th>
                            <th>Class Type</th>
                            <th>Hourly Rate</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courses as $c): ?>
                        <tr>
                            <td style="font-weight:600;color:#0f172a;"><?= htmlspecialchars($c['program']) ?></td>
                            <td><?= htmlspecialchars($c['semester'] ?? '—') ?></td>
                            <td style="font-weight:600;color:#334155;"><?= htmlspecialchars($c['subject_name']) ?></td>
                            <td>
                                <span style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 8px;border-radius:5px;color:#475569;">
                                    <?= htmlspecialchars($c['course_code'] ?? '—') ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($c['class_type'] === 'T'): ?>
                                    <span class="badge-theory">Theory (T)</span>
                                <?php else: ?>
                                    <span class="badge-practical">Practical (P)</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:700;color:#047857;">
                                &#8377;<?= number_format($c['class_type'] === 'T' ? (float)($faculty['theory_rate'] ?? 800) : (float)($faculty['practical_rate'] ?? 400)) ?> / hr
                            </td>
                            <td style="text-align:right;">
                                <a href="<?= BASE_URL ?>/faculty/lecture_entry.php?course_id=<?= $c['id'] ?>" class="btn-teal" style="padding:6px 14px;font-size:12.5px;">
                                    Log Lecture
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Recent Lectures Table -->
        <div class="card">
            <div class="card-head">
                <div>
                    <h2 style="font-size:16px;font-weight:700;color:#0f172a;margin:0;">Recent Lecture Entries</h2>
                    <div style="font-size:12.5px;color:#94a3b8;margin-top:2px;">Showing your last <?= count($recentLectures) ?> recorded sessions</div>
                </div>
                <a href="<?= BASE_URL ?>/faculty/history.php" class="btn-outline-teal">
                    View Full History &rarr;
                </a>
            </div>

            <?php if (empty($recentLectures)): ?>
                <div style="padding:40px;text-align:center;color:#94a3b8;">
                    No lectures recorded yet. Use the "Log New Lecture" button to record your first class!
                </div>
            <?php else: ?>
                <table class="ft">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Subject & Code</th>
                            <th>Program & Sem</th>
                            <th>Class Type</th>
                            <th>Duration</th>
                            <th>Rate</th>
                            <th>Calculated Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentLectures as $rl): ?>
                        <tr>
                            <td style="font-weight:600;color:#0f172a;">
                                <?= date('d M Y', strtotime($rl['lecture_date'])) ?>
                            </td>
                            <td>
                                <div style="font-weight:600;color:#334155;"><?= htmlspecialchars($rl['subject_name']) ?></div>
                                <div style="font-size:11.5px;font-family:monospace;color:#94a3b8;"><?= htmlspecialchars($rl['course_code'] ?? '') ?></div>
                            </td>
                            <td><?= htmlspecialchars($rl['program']) ?> - <?= htmlspecialchars($rl['semester'] ?? '') ?></td>
                            <td>
                                <?php if ($rl['class_type'] === 'T'): ?>
                                    <span class="badge-theory">Theory</span>
                                <?php else: ?>
                                    <span class="badge-practical">Practical</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:600;color:#0f172a;"><?= (float)$rl['hours'] ?> hrs</td>
                            <td style="color:#64748b;">&#8377;<?= number_format((float)$rl['rate_per_hour'], 0) ?>/hr</td>
                            <td style="font-weight:700;color:#047857;">&#8377;<?= number_format((float)$rl['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
function updateDocMonths() {
    const ySel = document.getElementById('docYear');
    const mSel = document.getElementById('docMonth');
    if (!ySel || !mSel) return;
    
    const year = parseInt(ySel.value, 10);
    const prevVal = parseInt(mSel.value, 10);
    const startM = (year === 2026) ? 8 : 1;
    
    const names = [
        "", "January", "February", "March", "April", "May", "June",
        "July", "August", "September", "October", "November", "December"
    ];
    
    mSel.innerHTML = '';
    for (let m = startM; m <= 12; m++) {
        const opt = document.createElement('option');
        opt.value = m;
        opt.textContent = names[m];
        if (m === prevVal || (prevVal < startM && m === startM)) {
            opt.selected = true;
        }
        mSel.appendChild(opt);
    }
}

const FAC_ID_SELF = <?= (int)$facultyId ?>;
function fetchFacDocStats() {
    const month = parseInt(document.getElementById('docMonth')?.value || 0);
    const year  = parseInt(document.getElementById('docYear')?.value || 0);
    if (!month || !year) return;

    const hoursLabel   = document.getElementById('fac-hours-label');
    const hoursVal     = document.getElementById('fac-hours-val');
    const hoursSess    = document.getElementById('fac-hours-sessions');
    const amountLabel  = document.getElementById('fac-amount-label');
    const amountVal    = document.getElementById('fac-amount-val');
    const amountNote   = document.getElementById('fac-amount-note');
    if (!hoursVal || !amountVal) return;

    hoursVal.style.opacity  = '0.4';
    amountVal.style.opacity = '0.4';

    fetch(`<?= BASE_URL ?>/api/faculty_month_stats.php?faculty_id=${FAC_ID_SELF}&month=${month}&year=${year}`)
        .then(r => r.json())
        .then(data => {
            if (data.error) return;
            const label = data.month_label.toUpperCase();
            if (hoursLabel)  hoursLabel.textContent  = label + ' HOURS';
            if (amountLabel) amountLabel.textContent = label + ' REMUNERATION';
            hoursVal.textContent  = parseFloat(data.hours) + ' hrs';
            if (hoursSess) {
                let detailStr = data.sessions + ' sessions this month';
                if (data.theory_hours > 0 || data.practical_hours > 0) {
                    detailStr += ` (${parseFloat(data.theory_hours)}h Theory &bull; ${parseFloat(data.practical_hours)}h Practical)`;
                }
                hoursSess.innerHTML = detailStr;
            }
            amountVal.innerHTML   = '&#8377;' + parseFloat(data.amount).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            if (amountNote) {
                if (parseFloat(data.amount) > 30000) {
                    amountNote.textContent = 'Exceeds \u20B930,000 ceiling';
                    amountNote.style.color = '#b45309';
                } else {
                    amountNote.textContent = 'Under monthly limit';
                    amountNote.style.color = '#64748b';
                }
            }
            hoursVal.style.opacity  = '1';
            amountVal.style.opacity = '1';
        })
        .catch(() => {
            hoursVal.style.opacity  = '1';
            amountVal.style.opacity = '1';
        });
}
</script>
</body>
</html>
