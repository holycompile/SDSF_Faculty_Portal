<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

// Filters
$filterFaculty = (int)($_GET['faculty_id'] ?? 0);
$filterCourse  = (int)($_GET['course_id'] ?? 0);
$filterMonth   = (int)($_GET['month'] ?? 0);
$filterYear    = (int)($_GET['year'] ?? 0);

// All faculty for selector dropdown
$allFaculty = $pdo->query("SELECT id, name, faculty_enrollment_no, department FROM faculty_members WHERE status='active' ORDER BY name")->fetchAll();

// Selected faculty details
$selectedFaculty = null;
if ($filterFaculty > 0) {
    $fStmt = $pdo->prepare("SELECT * FROM faculty_members WHERE id = ?");
    $fStmt->execute([$filterFaculty]);
    $selectedFaculty = $fStmt->fetch();
}

// Per-course stats for card display
$courseCards = [];
if ($filterFaculty > 0) {
    $cardStmt = $pdo->prepare("
        SELECT c.id, c.subject_name, c.course_code, c.program, c.semester, c.class_type,
               COUNT(le.id) AS session_count,
               COALESCE(SUM(le.hours), 0) AS total_hours,
               COALESCE(SUM(le.amount), 0) AS total_amount
        FROM courses c
        JOIN faculty_course_assignments fca ON fca.course_id = c.id AND fca.faculty_id = ?
        LEFT JOIN lecture_entries le ON le.course_id = c.id AND le.faculty_id = ?
        GROUP BY c.id, c.subject_name, c.course_code, c.program, c.semester, c.class_type
        ORDER BY c.subject_name
    ");
    $cardStmt->execute([$filterFaculty, $filterFaculty]);
    $courseCards = $cardStmt->fetchAll();
}

// Lecture entries (when faculty selected, optionally filtered by course)
$lectures = [];
$totalHours = $totalAmount = $totalCount = 0;
if ($filterFaculty > 0) {
    $query = "
        SELECT le.*,
               f.name AS faculty_name, f.faculty_enrollment_no,
               c.subject_name, c.course_code, c.program, c.semester, c.class_type
        FROM lecture_entries le
        JOIN faculty_members f ON f.id = le.faculty_id
        JOIN courses c ON c.id = le.course_id
        WHERE le.faculty_id = ?
    ";
    $params = [$filterFaculty];

    if ($filterCourse > 0) {
        $query .= " AND le.course_id = ?";
        $params[] = $filterCourse;
    }
    if ($filterMonth > 0) {
        $query .= " AND MONTH(le.lecture_date) = ?";
        $params[] = $filterMonth;
    }
    if ($filterYear > 0) {
        $query .= " AND YEAR(le.lecture_date) = ?";
        $params[] = $filterYear;
    }

    $query .= " ORDER BY le.lecture_date DESC, le.id DESC";
    $lStmt = $pdo->prepare($query);
    $lStmt->execute($params);
    $lectures = $lStmt->fetchAll();

    $totalHours  = array_sum(array_column($lectures, 'hours'));
    $totalAmount = array_sum(array_column($lectures, 'amount'));
    $totalCount  = count($lectures);
}

$active_nav = 'lectures-course-view';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course-wise Lecture View — SDSF Admin</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
    <style>
        /* Faculty Selector */
        .faculty-selector { background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:20px 24px; margin-bottom:24px; display:flex; align-items:flex-end; gap:16px; flex-wrap:wrap; }
        .form-label { display:block; font-size:11.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#475569; margin-bottom:6px; }
        .form-select { padding:10px 14px; background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:10px; color:#0f172a; font-size:14px; font-family:'Inter',sans-serif; outline:none; transition:border-color .2s; }
        .form-select:focus { background:#fff; border-color:#4f46e5; }
        /* Faculty Banner */
        .faculty-banner { background:linear-gradient(135deg,#4f46e5 0%,#6d5ce7 100%); border-radius:16px; padding:20px 24px; margin-bottom:24px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px; }
        .faculty-avatar { width:46px; height:46px; border-radius:12px; background:rgba(255,255,255,0.2); display:flex; align-items:center; justify-content:center; color:#fff; font-size:16px; font-weight:800; flex-shrink:0; }
        /* Course Cards */
        .course-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:16px; padding:20px; text-decoration:none; display:block; transition:all 0.22s ease; color:inherit; }
        .course-card:hover { border-color:#4f46e5; box-shadow:0 8px 28px rgba(79,70,229,.13); transform:translateY(-2px); }
        .course-card--active { border-color:#4f46e5 !important; background:linear-gradient(135deg,#eef2ff 0%,#fff 100%) !important; box-shadow:0 4px 20px rgba(79,70,229,.15); }
        /* Metric Cards */
        .metric-card { background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:20px 22px; }
        /* Date Filter */
        .date-filter { background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:16px 24px; margin-bottom:24px; display:flex; align-items:flex-end; gap:16px; flex-wrap:wrap; }
        /* Table */
        table.lt { width:100%; border-collapse:collapse; }
        .lt th { padding:12px 20px; text-align:left; font-size:11px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:#94a3b8; background:#fafafa; border-bottom:1px solid #f1f5f9; }
        .lt td { padding:14px 20px; font-size:14px; color:#334155; border-bottom:1px solid #f8fafc; }
        .lt tr:last-child td { border-bottom:none; }
        .lt tr:hover td { background:#fafafe; }
        .badge-theory { background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; font-size:11px; font-weight:600; padding:3px 9px; border-radius:20px; }
        .badge-practical { background:#fffbeb; color:#b45309; border:1px solid #fde68a; font-size:11px; font-weight:600; padding:3px 9px; border-radius:20px; }
        .btn-purple { background:linear-gradient(135deg,#4f46e5,#6d5ce7); color:#fff; padding:9px 18px; border-radius:10px; font-size:13.5px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:6px; border:none; cursor:pointer; box-shadow:0 2px 10px rgba(79,70,229,.25); }
        .btn-purple:hover { box-shadow:0 4px 18px rgba(79,70,229,.4); transform:translateY(-1px); }
        .btn-outline-purple { background:#fff; color:#4f46e5; border:1.5px solid #c7d2fe; padding:8px 16px; border-radius:10px; font-size:13.5px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
        .btn-outline-purple:hover { background:#eef2ff; }
    </style>
    <?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
</head>
<body>
    <header class="topbar">
        <div class="tb-left">
            <a href="<?= BASE_URL ?>/admin/dashboard.php" style="color:#94a3b8;text-decoration:none;">Dashboard</a>
            <span class="tb-sep">/</span>
            <span class="tb-crumb">Course-wise View</span>
            <?php if ($selectedFaculty): ?>
            <span class="tb-sep">/</span>
            <span class="tb-crumb"><?= htmlspecialchars($selectedFaculty['name']) ?></span>
            <?php endif; ?>
        </div>
        <div class="tb-right">
            <?php if ($filterFaculty > 0): ?>
            <a href="<?= BASE_URL ?>/admin/reports/generate_html_pdf.php?faculty_id=<?= $filterFaculty ?>&month=<?= $filterMonth ?: date('m') ?>&year=<?= $filterYear ?: date('Y') ?>" target="_blank" class="btn btn-primary btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                Export PDF
            </a>
            <?php endif; ?>
        </div>
    </header>

    <div class="page">
        <div class="page-header fade-up">
            <h1>Course-wise Lecture View</h1>
            <p>Select a faculty member to see their assigned courses and lecture history</p>
        </div>

        <!-- Faculty Selector -->
        <div class="faculty-selector fade-up">
            <div style="flex:1;min-width:220px;">
                <label class="form-label">Select Faculty Member</label>
                <select id="faculty-select" name="faculty_id" class="form-select" style="width:100%;" onchange="this.form.submit()">
                    <option value="0">— Choose a Teacher —</option>
                    <?php foreach ($allFaculty as $fac): ?>
                        <option value="<?= $fac['id'] ?>" <?= $filterFaculty == $fac['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($fac['name']) ?> (<?= htmlspecialchars($fac['faculty_enrollment_no']) ?>)
                            <?= $fac['department'] ? ' · ' . htmlspecialchars($fac['department']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($filterFaculty > 0): ?>
            <a href="<?= BASE_URL ?>/admin/lectures/course_view.php" class="btn-outline-purple">&#8592; Clear Selection</a>
            <?php endif; ?>
        </div>
        <!-- Auto-submit form wrapper (hidden) -->
        <script>
            document.getElementById('faculty-select').closest('.faculty-selector').innerHTML = '<form method="GET" id="faculty-form" style="display:contents;">' + document.getElementById('faculty-select').closest('.faculty-selector').innerHTML + '</form>';
        </script>

        <?php if (!$filterFaculty): ?>
        <!-- Empty State -->
        <div style="text-align:center;padding:80px 20px;color:#94a3b8;">
            <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="margin:0 auto 16px;display:block;opacity:.35;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <div style="font-size:16px;font-weight:700;color:#334155;margin-bottom:6px;">Select a Faculty Member</div>
            <p style="font-size:14px;max-width:360px;margin:0 auto;">Choose a teacher from the dropdown above to see their assigned courses and lecture history as cards.</p>
        </div>

        <?php else: ?>

        <!-- Faculty Banner -->
        <div class="faculty-banner fade-up">
            <div style="display:flex;align-items:center;gap:16px;">
                <div class="faculty-avatar"><?= strtoupper(substr(preg_replace('/\s+/', '', $selectedFaculty['name']), 0, 2)) ?></div>
                <div>
                    <div style="font-size:18px;font-weight:800;color:#fff;margin-bottom:2px;"><?= htmlspecialchars($selectedFaculty['name']) ?></div>
                    <div style="font-size:13px;color:rgba(255,255,255,0.75);">
                        <?= htmlspecialchars($selectedFaculty['faculty_enrollment_no']) ?>
                        <?= $selectedFaculty['department'] ? ' &bull; ' . htmlspecialchars($selectedFaculty['department']) : '' ?>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <span style="background:rgba(255,255,255,0.15);color:#fff;font-size:12px;font-weight:600;padding:5px 14px;border-radius:20px;border:1px solid rgba(255,255,255,0.2);">
                    <?= count($courseCards) ?> Courses Assigned
                </span>
                <span style="background:rgba(255,255,255,0.15);color:#fff;font-size:12px;font-weight:600;padding:5px 14px;border-radius:20px;border:1px solid rgba(255,255,255,0.2);">
                    <?= (float)array_sum(array_column($courseCards,'total_hours')) ?> Total Hours
                </span>
            </div>
        </div>

        <!-- Course Cards -->
        <div style="margin-bottom:28px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
                <div>
                    <h2 style="font-size:15px;font-weight:700;color:#0f172a;margin:0 0 3px;">Assigned Courses</h2>
                    <p style="font-size:13px;color:#94a3b8;margin:0;">Click a course card to view its lecture history</p>
                </div>
                <?php if ($filterCourse > 0): ?>
                <a href="<?= BASE_URL ?>/admin/lectures/course_view.php?faculty_id=<?= $filterFaculty ?><?= $filterMonth ? '&month='.$filterMonth : '' ?><?= $filterYear ? '&year='.$filterYear : '' ?>" class="btn-outline-purple">&#8592; All Courses</a>
                <?php endif; ?>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;">
                <?php foreach ($courseCards as $cc):
                    $isActive = ($filterCourse == $cc['id']);
                    $qp = ['faculty_id' => $filterFaculty, 'course_id' => $cc['id']];
                    if ($filterMonth) $qp['month'] = $filterMonth;
                    if ($filterYear)  $qp['year']  = $filterYear;
                    $cardUrl = BASE_URL . '/admin/lectures/course_view.php?' . http_build_query($qp);
                ?>
                <a href="<?= $cardUrl ?>" id="cv-card-<?= $cc['id'] ?>" class="course-card <?= $isActive ? 'course-card--active' : '' ?>">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                        <?php if ($cc['class_type'] === 'T'): ?>
                            <span class="badge-theory">Theory</span>
                        <?php else: ?>
                            <span class="badge-practical">Practical</span>
                        <?php endif; ?>
                        <span style="font-family:monospace;font-size:11px;background:#f1f5f9;padding:2px 8px;border-radius:6px;color:#64748b;"><?= htmlspecialchars($cc['course_code'] ?? '') ?></span>
                    </div>
                    <div style="font-size:15px;font-weight:700;color:#0f172a;margin-bottom:3px;line-height:1.35;"><?= htmlspecialchars($cc['subject_name']) ?></div>
                    <div style="font-size:12px;color:#64748b;margin-bottom:14px;"><?= htmlspecialchars($cc['program']) ?> &bull; <?= htmlspecialchars($cc['semester'] ?? '') ?></div>
                    <div style="border-top:1px solid #f1f5f9;padding-top:12px;display:grid;grid-template-columns:repeat(3,1fr);gap:6px;">
                        <div>
                            <div style="font-size:10.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Sessions</div>
                            <div style="font-size:17px;font-weight:800;color:#0f172a;"><?= (int)$cc['session_count'] ?></div>
                        </div>
                        <div>
                            <div style="font-size:10.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Hours</div>
                            <div style="font-size:17px;font-weight:800;color:#4f46e5;"><?= (float)$cc['total_hours'] ?></div>
                        </div>
                        <div>
                            <div style="font-size:10.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Earned</div>
                            <div style="font-size:14px;font-weight:800;color:#047857;">&#8377;<?= number_format((float)$cc['total_amount'], 0) ?></div>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
                <?php if (empty($courseCards)): ?>
                    <div style="grid-column:1/-1;padding:40px;text-align:center;color:#94a3b8;background:#fafafa;border-radius:16px;border:1.5px dashed #e2e8f0;">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 8px;display:block;opacity:.4"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                        No courses assigned to this faculty member yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Date Filter -->
        <form method="GET" class="date-filter">
            <input type="hidden" name="faculty_id" value="<?= $filterFaculty ?>">
            <?php if ($filterCourse > 0): ?><input type="hidden" name="course_id" value="<?= $filterCourse ?>"><?php endif; ?>
            <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:0;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span style="font-size:13px;font-weight:600;color:#64748b;">Filter by Date</span>
            </div>
            <div style="width:150px;">
                <label class="form-label">Month</label>
                <select name="month" class="form-select" style="width:100%;">
                    <option value="0">All Months</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $filterMonth == $m ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div style="width:120px;">
                <label class="form-label">Year</label>
                <select name="year" class="form-select" style="width:100%;">
                    <option value="0">All Years</option>
                    <?php for ($y = 2024; $y <= 2028; $y++): ?>
                        <option value="<?= $y ?>" <?= $filterYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn-purple">Apply</button>
                <a href="<?= BASE_URL ?>/admin/lectures/course_view.php?faculty_id=<?= $filterFaculty ?><?= $filterCourse ? '&course_id='.$filterCourse : '' ?>" class="btn-outline-purple">Reset</a>
            </div>
        </form>

        <!-- Summary Metrics -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;">
            <div class="metric-card fade-up">
                <div style="font-size:11.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;">Sessions in View</div>
                <div style="font-size:26px;font-weight:800;color:#0f172a;margin-top:4px;"><?= $totalCount ?></div>
            </div>
            <div class="metric-card fade-up">
                <div style="font-size:11.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;">Total Hours</div>
                <div style="font-size:26px;font-weight:800;color:#4f46e5;margin-top:4px;"><?= (float)$totalHours ?> hrs</div>
            </div>
            <div class="metric-card fade-up">
                <div style="font-size:11.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;">Accrued Remuneration</div>
                <div style="font-size:26px;font-weight:800;color:#047857;margin-top:4px;">&#8377;<?= number_format($totalAmount, 2) ?></div>
            </div>
        </div>

        <!-- Lecture History Table -->
        <div class="card fade-up">
            <div class="card-head">
                <div>
                    <div class="card-title">
                        <?= $filterCourse > 0 ? 'Lecture History for Selected Course' : 'All Lecture History' ?>
                    </div>
                    <div class="card-sub">Showing <?= $totalCount ?> lecture sessions</div>
                </div>
                <span class="c-badge"><?= $totalCount ?> Sessions</span>
            </div>

            <?php if (empty($lectures)): ?>
                <div style="padding:50px;text-align:center;color:#94a3b8;">
                    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 12px;display:block;opacity:.4"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></svg>
                    <div style="font-weight:600;color:#334155;margin-bottom:4px;">No lecture records found</div>
                    <p style="font-size:13.5px;color:#94a3b8;">
                        <?= $filterCourse > 0 ? 'No lectures logged for this course yet.' : 'This faculty member has not logged any lectures yet.' ?>
                    </p>
                </div>
            <?php else: ?>
                <table class="lt">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Subject &amp; Code</th>
                            <th>Program &amp; Sem</th>
                            <th>Type</th>
                            <th>Duration</th>
                            <th>Rate/hr</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lectures as $l): ?>
                        <tr>
                            <td style="font-weight:600;color:#0f172a;white-space:nowrap;"><?= date('d M Y', strtotime($l['lecture_date'])) ?></td>
                            <td>
                                <div style="font-weight:600;color:#334155;"><?= htmlspecialchars($l['subject_name']) ?></div>
                                <span style="font-family:monospace;font-size:11.5px;background:#f1f5f9;padding:1px 6px;border-radius:4px;color:#475569;"><?= htmlspecialchars($l['course_code'] ?? '—') ?></span>
                            </td>
                            <td style="font-size:13px;color:#475569;"><?= htmlspecialchars($l['program']) ?> &bull; <?= htmlspecialchars($l['semester'] ?? '') ?></td>
                            <td>
                                <?php if ($l['class_type'] === 'T'): ?>
                                    <span class="badge-theory">Theory</span>
                                <?php else: ?>
                                    <span class="badge-practical">Practical</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:600;color:#0f172a;"><?= (float)$l['hours'] ?> hrs</td>
                            <td style="color:#64748b;">&#8377;<?= number_format((float)$l['rate_per_hour'], 0) ?>/hr</td>
                            <td style="font-weight:700;color:#047857;">&#8377;<?= number_format((float)$l['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php endif; // end if $filterFaculty ?>
    </div>
</body>
</html>
