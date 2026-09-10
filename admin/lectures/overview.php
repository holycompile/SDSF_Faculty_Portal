<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

// Filters
$filterFaculty = (int)($_GET['faculty_id'] ?? 0);
$filterMonth   = (int)($_GET['month'] ?? 0);
$filterYear    = (int)($_GET['year'] ?? 0);

$query = "
    SELECT le.*, 
           f.name AS faculty_name, f.faculty_enrollment_no, f.department,
           c.subject_name, c.course_code, c.program, c.semester, c.class_type
    FROM lecture_entries le
    JOIN faculty_members f ON f.id = le.faculty_id
    JOIN courses c ON c.id = le.course_id
    WHERE 1=1
";
$params = [];

if ($filterFaculty > 0) {
    $query .= " AND le.faculty_id = ?";
    $params[] = $filterFaculty;
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

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$lectures = $stmt->fetchAll();

// All faculty for filter dropdown
$allFaculty = $pdo->query("SELECT id, name, faculty_enrollment_no FROM faculty_members ORDER BY name")->fetchAll();

// Summary stats of current filtered result
$totalHours  = array_sum(array_column($lectures, 'hours'));
$totalAmount = array_sum(array_column($lectures, 'amount'));
$totalCount  = count($lectures);

$active_nav = 'lectures';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecture Records — SDSF Admin</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
    <?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
</head>
<body>
    <header class="topbar">
        <div class="tb-left">
            <a href="<?= BASE_URL ?>/admin/dashboard.php" style="color:#94a3b8;text-decoration:none;">Dashboard</a>
            <span class="tb-sep">/</span>
            <span class="tb-crumb">Lecture Records</span>
        </div>
        <div class="tb-right">
            <?php if ($filterFaculty > 0): ?>
                <a href="<?= BASE_URL ?>/admin/reports/generate_html_pdf.php?faculty_id=<?= $filterFaculty ?>&month=<?= $filterMonth ?: date('m') ?>&year=<?= $filterYear ?: date('Y') ?>" target="_blank" class="btn btn-primary btn-sm">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                    Export Annexure-IV PDF
                </a>
            <?php endif; ?>
        </div>
    </header>

    <div class="page">
        <div class="page-header fade-up">
            <h1>Conducted Lecture Records</h1>
            <p>Comprehensive log of all visiting faculty classes, durations, and calculated remuneration</p>
        </div>

        <!-- Filter Card -->
        <div class="card fade-up" style="margin-bottom:24px;padding:20px 24px;">
            <form method="GET" style="display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;">
                <div style="flex:1;min-width:200px;">
                    <label class="form-label">Filter by Faculty</label>
                    <select name="faculty_id" class="form-select">
                        <option value="0">All Faculty Members</option>
                        <?php foreach ($allFaculty as $fac): ?>
                            <option value="<?= $fac['id'] ?>" <?= $filterFaculty == $fac['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fac['name']) ?> (<?= htmlspecialchars($fac['faculty_enrollment_no']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="width:160px;">
                    <label class="form-label">Month</label>
                    <select name="month" class="form-select">
                        <option value="0">All Months</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $filterMonth == $m ? 'selected' : '' ?>>
                                <?= date('F', mktime(0,0,0,$m,1)) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div style="width:130px;">
                    <label class="form-label">Year</label>
                    <select name="year" class="form-select">
                        <option value="0">All Years</option>
                        <?php for ($y = 2024; $y <= 2028; $y++): ?>
                            <option value="<?= $y ?>" <?= $filterYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div style="display:flex;gap:10px;">
                    <button type="submit" class="btn btn-primary">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        Filter Records
                    </button>
                    <a href="<?= BASE_URL ?>/admin/lectures/overview.php" class="btn btn-outline">
                        Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Metrics Summary -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;margin-bottom:24px;">
            <div class="card fade-up" style="padding:20px;">
                <div style="font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Lectures Logged</div>
                <div style="font-size:26px;font-weight:800;color:#0f172a;margin-top:4px;"><?= number_format($totalCount) ?></div>
                <div style="font-size:12px;color:#64748b;margin-top:2px;">Individual sessions</div>
            </div>
            <div class="card fade-up" style="padding:20px;">
                <div style="font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Total Hours Taught</div>
                <div style="font-size:26px;font-weight:800;color:#4f46e5;margin-top:4px;"><?= (float)$totalHours ?> hrs</div>
                <div style="font-size:12px;color:#64748b;margin-top:2px;">Cumulated duration</div>
            </div>
            <div class="card fade-up" style="padding:20px;">
                <div style="font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Total Remuneration Payable</div>
                <div style="font-size:26px;font-weight:800;color:#047857;margin-top:4px;">&#8377;<?= number_format($totalAmount, 2) ?></div>
                <div style="font-size:12px;color:#64748b;margin-top:2px;">At &#8377;800 (Theory) / &#8377;400 (Practical)</div>
            </div>
        </div>

        <!-- Lectures Table -->
        <div class="card fade-up">
            <div class="card-head">
                <div>
                    <div class="card-title">Conducted Lectures Detailed View</div>
                    <div class="card-sub">Showing <?= count($lectures) ?> lecture sessions</div>
                </div>
                <span class="c-badge"><?= count($lectures) ?> Sessions</span>
            </div>

            <?php if (empty($lectures)): ?>
                <div style="padding:50px;text-align:center;color:#94a3b8;">
                    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 12px;display:block;opacity:.4">
                        <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/>
                    </svg>
                    <div style="font-weight:600;margin-bottom:4px;color:#334155;">No lecture records match your filter criteria</div>
                    <p style="font-size:13.5px;color:#94a3b8;">Try changing the faculty filter or date period.</p>
                </div>
            <?php else: ?>
                <table class="dt">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Faculty Member</th>
                            <th>Subject & Course Code</th>
                            <th>Program & Sem</th>
                            <th>Type</th>
                            <th>Duration</th>
                            <th>Rate</th>
                            <th>Total Remuneration</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lectures as $l): ?>
                        <tr>
                            <td style="font-weight:600;color:#0f172a;white-space:nowrap;">
                                <?= date('d M Y', strtotime($l['lecture_date'])) ?>
                            </td>
                            <td>
                                <a href="<?= BASE_URL ?>/admin/faculty/view.php?id=<?= $l['faculty_id'] ?>" style="font-weight:600;color:#0f172a;text-decoration:none;" class="hover:underline">
                                    <?= htmlspecialchars($l['faculty_name']) ?>
                                </a>
                                <div style="font-size:11.5px;font-family:monospace;color:#4f46e5;">
                                    <?= htmlspecialchars($l['faculty_enrollment_no']) ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight:600;color:#334155;"><?= htmlspecialchars($l['subject_name']) ?></div>
                                <span style="font-family:monospace;font-size:11.5px;background:#f1f5f9;padding:1px 6px;border-radius:4px;color:#475569;">
                                    <?= htmlspecialchars($l['course_code'] ?? '—') ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-size:13px;color:#475569;">
                                    <?= htmlspecialchars($l['program']) ?> &bull; <?= htmlspecialchars($l['semester'] ?? '') ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($l['class_type'] === 'T'): ?>
                                    <span class="badge badge-blue">Theory (T)</span>
                                <?php else: ?>
                                    <span class="badge badge-amber">Practical (P)</span>
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
    </div>
</body>
</html>
