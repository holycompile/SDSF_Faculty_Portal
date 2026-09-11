<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setFlash('error', 'Invalid faculty ID.');
    header('Location: ' . BASE_URL . '/admin/faculty/list.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM faculty_members WHERE id = ?");
$stmt->execute([$id]);
$faculty = $stmt->fetch();

if (!$faculty) {
    setFlash('error', 'Faculty member not found.');
    header('Location: ' . BASE_URL . '/admin/faculty/list.php');
    exit;
}

// Assigned courses
$cStmt = $pdo->prepare("
    SELECT c.* 
    FROM courses c
    JOIN faculty_course_assignments fca ON fca.course_id = c.id
    WHERE fca.faculty_id = ?
    ORDER BY c.program, c.semester, c.subject_name
");
$cStmt->execute([$id]);
$assignedCourses = $cStmt->fetchAll();

// Lecture entries
$lStmt = $pdo->prepare("
    SELECT le.*, c.subject_name, c.course_code, c.program, c.semester, c.class_type
    FROM lecture_entries le
    JOIN courses c ON c.id = le.course_id
    WHERE le.faculty_id = ?
    ORDER BY le.lecture_date DESC, le.id DESC
");
$lStmt->execute([$id]);
$lectures = $lStmt->fetchAll();

// Metrics
$totalHours = array_sum(array_column($lectures, 'hours'));
$totalEarned = array_sum(array_column($lectures, 'amount'));

// Current month metrics
$currentMonth = (int)date('m');
$currentYear  = (int)date('Y');
$mStmt = $pdo->prepare("
    SELECT COALESCE(SUM(hours), 0) as m_hours, COALESCE(SUM(amount), 0) as m_amount
    FROM lecture_entries
    WHERE faculty_id = ? AND MONTH(lecture_date) = ? AND YEAR(lecture_date) = ?
");
$mStmt->execute([$id, $currentMonth, $currentYear]);
$monthMetrics = $mStmt->fetch();
$monthHours   = (float)$monthMetrics['m_hours'];
$monthAmount  = (float)$monthMetrics['m_amount'];

$flash = getFlash();
$active_nav = 'faculty-list';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($faculty['name']) ?> — Faculty Profile</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
    <style>
        .report-dropdown { position: relative; display: inline-block; }
        .report-menu { display: none; position: absolute; right: 0; top: calc(100% + 6px); background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); min-width: 250px; z-index: 1000; padding: 6px; }
        .report-menu.show { display: block; }
        .report-menu a { display: flex; align-items: center; gap: 9px; padding: 10px 14px; border-radius: 8px; color: #1e293b; text-decoration: none; font-size: 13px; font-weight: 500; transition: background 0.15s; }
        .report-menu a:hover { background: #f1f5f9; color: #4f46e5; }
    </style>
    <?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
</head>
<body>
    <header class="topbar">
        <div class="tb-left">
            <a href="<?= BASE_URL ?>/admin/dashboard.php" style="color:#94a3b8;text-decoration:none;">Dashboard</a>
            <span class="tb-sep">/</span>
            <a href="<?= BASE_URL ?>/admin/faculty/list.php" style="color:#94a3b8;text-decoration:none;">Faculty</a>
            <span class="tb-sep">/</span>
            <span class="tb-crumb"><?= htmlspecialchars($faculty['name']) ?></span>
        </div>
        <div class="tb-right" style="display:flex;align-items:center;gap:10px;">
            <a href="<?= BASE_URL ?>/admin/faculty/list.php" class="btn btn-outline btn-sm">
                &larr; Back to Faculty List
            </a>

            <!-- Official Reports Dropdown (compact, never wraps) -->
            <div class="report-dropdown">
                <button type="button" class="btn btn-primary btn-sm" onclick="event.stopPropagation();document.getElementById('reportMenu').classList.toggle('show');" style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                    <span>Official Reports</span>
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div id="reportMenu" class="report-menu">
                    <a href="<?= BASE_URL ?>/admin/reports/annexure_iv.php?faculty_id=<?= $faculty['id'] ?>&month=<?= $currentMonth ?>&year=<?= $currentYear ?>" target="_blank">
                        📄 Annexure-IV (Claim Bill)
                    </a>
                    <a href="<?= BASE_URL ?>/admin/reports/visiting_faculty_attendance.php?faculty_id=<?= $faculty['id'] ?>&month=<?= $currentMonth ?>&year=<?= $currentYear ?>" target="_blank">
                        📊 Teaching Attendance Sheet
                    </a>
                    <a href="<?= BASE_URL ?>/admin/reports/detailed_remuneration.php?faculty_id=<?= $faculty['id'] ?>&month=<?= $currentMonth ?>&year=<?= $currentYear ?>" target="_blank">
                        📋 Annexure IV-A (Detailed Sheet)
                    </a>
                </div>
            </div>

            <button class="btn btn-danger btn-sm"
                onclick="confirmDelete(<?= $faculty['id'] ?>, '<?= htmlspecialchars(addslashes($faculty['name']), ENT_QUOTES) ?>', '<?= htmlspecialchars($faculty['faculty_enrollment_no'], ENT_QUOTES) ?>')"
            >
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                Delete Faculty
            </button>
        </div>

    </header>

    <div class="page">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] ?> fade-up">
                <?= htmlspecialchars($flash['msg']) ?>
            </div>
        <?php endif; ?>

        <!-- Faculty Hero Card -->
        <div class="card fade-up" style="margin-bottom:24px;background:linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);">
            <div style="padding:28px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;">
                <div style="display:flex;align-items:center;gap:20px;">
                    <div style="width:68px;height:68px;border-radius:18px;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;font-size:24px;font-weight:800;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 20px rgba(79,70,229,0.3);">
                        <?= strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $faculty['name']), 0, 2)) ?>
                    </div>
                    <div>
                        <div style="display:flex;align-items:center;gap:12px;margin-bottom:4px;">
                            <h1 style="font-size:22px;font-weight:800;color:#0f172a;margin:0;"><?= htmlspecialchars($faculty['name']) ?></h1>
                            <span style="font-family:monospace;font-size:13px;font-weight:700;background:#eef2ff;color:#4f46e5;padding:4px 10px;border-radius:6px;border:1px solid #c7d2fe;">
                                <?= htmlspecialchars($faculty['faculty_enrollment_no']) ?>
                            </span>
                            <span class="badge badge-green">Active Visiting Faculty</span>
                        </div>
                        <p style="color:#64748b;font-size:14px;margin:0;">
                            <?= htmlspecialchars($faculty['qualification']) ?> &bull; <?= htmlspecialchars($faculty['department']) ?>
                        </p>
                    </div>
                </div>
                <div style="display:flex;gap:20px;">
                    <div style="background:#fff;border:1px solid #e2e8f0;padding:12px 20px;border-radius:12px;text-align:right;">
                        <div style="font-size:11.5px;color:#94a3b8;font-weight:600;text-transform:uppercase;">Total Remuneration</div>
                        <div style="font-size:20px;font-weight:800;color:#047857;">&#8377;<?= number_format($totalEarned, 2) ?></div>
                        <div style="font-size:12px;color:#64748b;"><?= (float)$totalHours ?> total hours</div>
                    </div>
                    <div style="background:#fff;border:1px solid #e2e8f0;padding:12px 20px;border-radius:12px;text-align:right;">
                        <div style="font-size:11.5px;color:#94a3b8;font-weight:600;text-transform:uppercase;"><?= date('F Y') ?></div>
                        <div style="font-size:20px;font-weight:800;color:#4f46e5;">&#8377;<?= number_format($monthAmount, 2) ?></div>
                        <div style="font-size:12px;color:#64748b;"><?= (float)$monthHours ?> hrs this month</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Official Documents & Remuneration Reports Generator Card -->
        <div class="card fade-up" style="margin-bottom:24px;border:1.5px solid #bfdbfe;background:#f8fafc;">
            <div class="card-head" style="background:#ffffff;">
                <div>
                    <h2 style="font-size:16px;font-weight:700;color:#1e3a8a;margin:0;">Official Monthly Documents &amp; Remuneration Reports</h2>
                    <div style="font-size:12.5px;color:#64748b;margin-top:2px;">Select any month and year to generate and print official DAVV documents for this faculty member.</div>
                </div>
                <span class="badge badge-blue">Official DAVV Formats</span>
            </div>
            <div style="padding:20px 24px;">
                <form method="GET" target="_blank" style="display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;">
                    <input type="hidden" name="faculty_id" value="<?= $faculty['id'] ?>">
                    <div>
                        <label style="display:block;font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#475569;margin-bottom:6px;">Billing Month</label>
                        <select name="month" class="form-select" style="padding:9px 12px;background:#fff;border:1.5px solid #cbd5e1;border-radius:10px;font-size:14px;color:#0f172a;width:160px;font-family:'Inter',sans-serif;outline:none;">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $m == $currentMonth ? 'selected' : '' ?>>
                                    <?= date('F', mktime(0,0,0,$m,1)) ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#475569;margin-bottom:6px;">Billing Year</label>
                        <select name="year" class="form-select" style="padding:9px 12px;background:#fff;border:1.5px solid #cbd5e1;border-radius:10px;font-size:14px;color:#0f172a;width:120px;font-family:'Inter',sans-serif;outline:none;">
                            <?php for ($y = 2024; $y <= 2028; $y++): ?>
                                <option value="<?= $y ?>" <?= $y == $currentYear ? 'selected' : '' ?>><?= $y ?></option>
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

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;">
            <!-- Personal & Contact Info -->
            <div class="card fade-up">
                <div class="card-head">
                    <div class="card-title">Personal & Contact Details</div>
                </div>
                <div style="padding:22px;">
                    <div style="display:grid;grid-template-columns:120px 1fr;gap:12px;font-size:14px;">
                        <span style="color:#94a3b8;font-weight:500;">Phone:</span>
                        <span style="color:#0f172a;font-weight:600;"><?= htmlspecialchars($faculty['phone']) ?></span>

                        <span style="color:#94a3b8;font-weight:500;">Email:</span>
                        <span style="color:#0f172a;font-weight:600;"><?= htmlspecialchars($faculty['email'] ?: '—') ?></span>

                        <span style="color:#94a3b8;font-weight:500;">Department:</span>
                        <span style="color:#0f172a;font-weight:600;"><?= htmlspecialchars($faculty['department']) ?></span>

                        <span style="color:#94a3b8;font-weight:500;">Qualification:</span>
                        <span style="color:#0f172a;font-weight:600;"><?= htmlspecialchars($faculty['qualification']) ?></span>

                        <span style="color:#94a3b8;font-weight:500;">Address:</span>
                        <span style="color:#0f172a;"><?= nl2br(htmlspecialchars($faculty['address'] ?: '—')) ?></span>

                        <span style="color:#94a3b8;font-weight:500;">Registered:</span>
                        <span style="color:#64748b;"><?= date('d M Y, h:i A', strtotime($faculty['created_at'])) ?></span>
                    </div>
                </div>
            </div>

            <!-- Banking & Compliance Info (For Annexure-IV) -->
            <div class="card fade-up">
                <div class="card-head">
                    <div class="card-title">Banking & Compliance Info (Annexure-IV)</div>
                </div>
                <div style="padding:22px;">
                    <div style="display:grid;grid-template-columns:130px 1fr;gap:12px;font-size:14px;">
                        <span style="color:#94a3b8;font-weight:500;">Bank Name:</span>
                        <span style="color:#0f172a;font-weight:600;"><?= htmlspecialchars($faculty['bank_name'] ?: '—') ?></span>

                        <span style="color:#94a3b8;font-weight:500;">Account No:</span>
                        <span style="font-family:monospace;font-weight:700;color:#0f172a;"><?= htmlspecialchars($faculty['account_no'] ?: '—') ?></span>

                        <span style="color:#94a3b8;font-weight:500;">IFSC Code:</span>
                        <span style="font-family:monospace;font-weight:700;color:#4f46e5;"><?= htmlspecialchars($faculty['ifsc_code'] ?: '—') ?></span>

                        <span style="color:#94a3b8;font-weight:500;">PAN Card No:</span>
                        <span style="font-family:monospace;font-weight:700;color:#0f172a;"><?= htmlspecialchars($faculty['pan_no'] ?: '—') ?></span>

                        <span style="color:#94a3b8;font-weight:500;">Aadhaar No:</span>
                        <span style="font-family:monospace;font-weight:700;color:#0f172a;"><?= htmlspecialchars($faculty['aadhaar_no'] ?: '—') ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bill Generation & Remuneration PDF Card -->
        <div class="card fade-up" style="margin-bottom:24px;border:1.5px solid #c7d2fe;">
            <div class="card-head" style="background:#f8fafc;">
                <div>
                    <div class="card-title" style="color:#4338ca;">DAVV SDSF Remuneration & Attendance Bill Generator</div>
                    <div class="card-sub">Generate a single unified PDF containing official Annexure-IV Remuneration Bill and Teaching Attendance Sheet</div>
                </div>
                <span class="badge badge-blue">Official DAVV Unified Report</span>
            </div>
            <div style="padding:22px;">
                <form action="<?= BASE_URL ?>/admin/reports/generate_html_pdf.php" method="GET" target="_blank" style="display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;">
                    <input type="hidden" name="faculty_id" value="<?= $faculty['id'] ?>">
                    <div>
                        <label class="form-label">Billing Month</label>
                        <select name="month" class="form-select" style="width:160px;">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $m == $currentMonth ? 'selected' : '' ?>>
                                    <?= date('F', mktime(0,0,0,$m,1)) ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Billing Year</label>
                        <select name="year" class="form-select" style="width:120px;">
                            <?php for ($y = 2024; $y <= 2028; $y++): ?>
                                <option value="<?= $y ?>" <?= $y == $currentYear ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Date of Submission</label>
                        <input type="date" name="submission_date" class="form-input" style="width:160px;" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary" style="padding:10px 22px;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                            Download Remuneration & Attendance Bill (PDF)
                        </button>
                    </div>
                </form>

                <?php if ($monthAmount > 30000): ?>
                    <div style="margin-top:16px;padding:10px 14px;border-radius:10px;background:#fffbeb;border:1px solid #fde68a;display:flex;align-items:center;gap:10px;font-size:13.5px;color:#b45309;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <strong>Notice:</strong> Current monthly total (&#8377;<?= number_format($monthAmount, 2) ?>) exceeds the standard &#8377;30,000 monthly ceiling recommended for visiting faculty.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Assigned Courses Table -->
        <div class="card fade-up" style="margin-bottom:24px;">
            <div class="card-head">
                <div>
                    <div class="card-title">Assigned Courses & Subjects</div>
                    <div class="card-sub">Courses this faculty member is authorized to take lectures for</div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="c-badge"><?= count($assignedCourses) ?> Courses</span>
                    <a href="<?= BASE_URL ?>/admin/faculty/manage_courses.php?id=<?= $faculty['id'] ?>" class="btn btn-primary btn-sm">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Manage Courses
                    </a>
                </div>
            </div>
            <?php if (empty($assignedCourses)): ?>
                <div style="padding:30px;text-align:center;color:#94a3b8;font-size:14px;">No courses currently assigned to this faculty member.</div>
            <?php else: ?>
                <table class="dt">
                    <thead>
                        <tr>
                            <th>Program</th>
                            <th>Semester</th>
                            <th>Subject Name</th>
                            <th>Course Code</th>
                            <th>Class Type</th>
                            <th>Prescribed Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignedCourses as $ac): ?>
                        <tr>
                            <td style="font-weight:600;color:#0f172a;"><?= htmlspecialchars($ac['program']) ?></td>
                            <td><?= htmlspecialchars($ac['semester'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($ac['subject_name']) ?></td>
                            <td><span style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 8px;border-radius:5px;"><?= htmlspecialchars($ac['course_code'] ?? '—') ?></span></td>
                            <td>
                                <?php if ($ac['class_type'] === 'T'): ?>
                                    <span class="badge badge-blue">Theory (T)</span>
                                <?php else: ?>
                                    <span class="badge badge-amber">Practical (P)</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:700;color:#047857;">&#8377;<?= $ac['class_type'] === 'T' ? 800 : 400 ?> / hour</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Lecture History Table -->
        <div class="card fade-up">
            <div class="card-head">
                <div>
                    <div class="card-title">Conducted Lecture History</div>
                    <div class="card-sub">All lecture sessions entered by this faculty member</div>
                </div>
                <span class="c-badge"><?= count($lectures) ?> Entries</span>
            </div>
            <?php if (empty($lectures)): ?>
                <div style="padding:40px;text-align:center;color:#94a3b8;font-size:14px;">
                    No lectures logged yet by this faculty member.
                </div>
            <?php else: ?>
                <table class="dt">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Subject / Course</th>
                            <th>Program & Sem</th>
                            <th>Class Type</th>
                            <th>Duration (Hours)</th>
                            <th>Rate/hr</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lectures as $l): ?>
                        <tr>
                            <td style="font-weight:600;color:#0f172a;">
                                <?= date('d M Y', strtotime($l['lecture_date'])) ?>
                            </td>
                            <td>
                                <div style="font-weight:600;color:#334155;"><?= htmlspecialchars($l['subject_name']) ?></div>
                                <div style="font-size:11.5px;color:#94a3b8;font-family:monospace;"><?= htmlspecialchars($l['course_code'] ?? '') ?></div>
                            </td>
                            <td><?= htmlspecialchars($l['program']) ?> - <?= htmlspecialchars($l['semester'] ?? '') ?></td>
                            <td>
                                <?php if ($l['class_type'] === 'T'): ?>
                                    <span class="badge badge-blue">Theory</span>
                                <?php else: ?>
                                    <span class="badge badge-amber">Practical</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:600;color:#0f172a;"><?= (float)$l['hours'] ?> hrs</td>
                            <td style="color:#64748b;">&#8377;<?= number_format((float)$l['rate_per_hour'], 2) ?></td>
                            <td style="font-weight:700;color:#047857;">&#8377;<?= number_format((float)$l['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,0.6);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:20px;padding:36px 32px;max-width:440px;width:90%;box-shadow:0 24px 64px rgba(0,0,0,0.22);animation:fadeUp .25s ease both;">
        <div style="width:64px;height:64px;border-radius:18px;background:#fef2f2;border:2px solid #fecaca;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <h2 style="font-size:19px;font-weight:800;color:#0f172a;text-align:center;margin:0 0 8px;">Delete Faculty Member?</h2>
        <p style="font-size:13.5px;color:#64748b;text-align:center;margin:0 0 18px;line-height:1.6;">You are about to permanently delete:</p>
        <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:18px;text-align:center;">
            <div id="modal-name" style="font-size:16px;font-weight:800;color:#0f172a;"></div>
            <div id="modal-enroll" style="font-size:12px;font-family:monospace;font-weight:700;color:#4f46e5;background:#eef2ff;display:inline-block;padding:2px 10px;border-radius:6px;margin-top:6px;"></div>
        </div>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:24px;display:flex;gap:10px;align-items:flex-start;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="2" style="flex-shrink:0;margin-top:1px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <span style="font-size:12.5px;color:#92400e;line-height:1.55;">
                This will <strong>permanently delete</strong> all their course assignments, lecture entries, and payment records.
                <strong>This action cannot be undone.</strong>
            </span>
        </div>
        <div style="display:flex;gap:12px;">
            <button onclick="closeDeleteModal()"
                style="flex:1;padding:12px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;font-size:14px;font-weight:600;color:#475569;cursor:pointer;font-family:'Inter',sans-serif;transition:background .16s;"
                onmouseenter="this.style.background='#f8fafc';" onmouseleave="this.style.background='#fff';">
                Cancel
            </button>
            <form method="POST" action="<?= BASE_URL ?>/admin/faculty/delete.php" style="flex:1;margin:0;">
                <input type="hidden" name="faculty_id" id="modal-faculty-id">
                <button type="submit"
                    style="width:100%;padding:12px;border-radius:10px;border:none;background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;font-size:14px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;box-shadow:0 2px 10px rgba(220,38,38,0.28);transition:all .18s;"
                    onmouseenter="this.style.boxShadow='0 4px 18px rgba(220,38,38,0.45)';"
                    onmouseleave="this.style.boxShadow='0 2px 10px rgba(220,38,38,0.28)';">
                    Yes, Delete Permanently
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function confirmDelete(id, name, enroll) {
    document.getElementById('modal-faculty-id').value = id;
    document.getElementById('modal-name').textContent = name;
    document.getElementById('modal-enroll').textContent = enroll;
    document.getElementById('deleteModal').style.display = 'flex';
}
function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDeleteModal();
});
window.addEventListener('click', function(e) {
    const rm = document.getElementById('reportMenu');
    if (rm && !e.target.closest('.report-dropdown')) {
        rm.classList.remove('show');
    }
});
</script>
</body>
</html>
