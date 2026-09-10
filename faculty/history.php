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

// Filter parameters
$filterCourse = (int)($_GET['course_id'] ?? 0);
$filterMonth  = (int)($_GET['month'] ?? 0);
$filterYear   = (int)($_GET['year'] ?? 0);

$query = "
    SELECT le.*, c.subject_name, c.course_code, c.program, c.semester, c.class_type
    FROM lecture_entries le
    JOIN courses c ON c.id = le.course_id
    WHERE le.faculty_id = ?
";
$params = [$facultyId];

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

// Get all courses assigned to this faculty for filter
$cStmt = $pdo->prepare("
    SELECT c.* FROM courses c
    JOIN faculty_course_assignments fca ON fca.course_id = c.id
    WHERE fca.faculty_id = ?
    ORDER BY c.subject_name
");
$cStmt->execute([$facultyId]);
$assignedCourses = $cStmt->fetchAll();

// Calculated metrics for filtered data
$totalHours  = array_sum(array_column($lectures, 'hours'));
$totalAmount = array_sum(array_column($lectures, 'amount'));
$totalCount  = count($lectures);
?>
<?php $active_nav = 'history'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecture History — SDSF Faculty Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
    <style>
        .card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; }
        .card-head { padding: 18px 24px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
        .form-label { display: block; font-size: 11.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #475569; margin-bottom: 6px; }
        .form-select { padding: 9px 12px; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; color: #0f172a; font-size: 14px; font-family: 'Inter', sans-serif; outline: none; }
        .form-select:focus { background: #fff; border-color: #1e3a8a; }
        .badge-theory { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 20px; }
        .badge-practical { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 20px; }
        .btn-teal { background: #1e3a8a; color: #fff; padding: 8px 16px; border-radius: 9px; font-size: 13.5px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; border: none; cursor: pointer; }
        .btn-teal:hover { background: #172554; }
        .btn-outline-teal { background: #fff; color: #1e3a8a; border: 1.5px solid #bfdbfe; padding: 7px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        table.ft { width: 100%; border-collapse: collapse; }
        .ft th { padding: 12px 20px; text-align: left; font-size: 11.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #94a3b8; background: #fafafa; border-bottom: 1px solid #f1f5f9; }
        .ft td { padding: 14px 20px; font-size: 14px; color: #334155; border-bottom: 1px solid #f8fafc; }
        .ft tr:last-child td { border-bottom: none; }
        .ft tr:hover td { background: #fcfefe; }
    </style>
    <?php require_once ROOT . '/includes/faculty_sidebar.php'; ?>
</head>
<body>
    <header class="topbar">
        <div class="tb-left">
            <span class="tb-crumb">Lecture History</span>
        </div>
        <div class="tb-right">
            <span class="tb-date"><?= date('l, d F Y') ?></span>
            <a href="<?= BASE_URL ?>/admin/reports/generate_html_pdf.php?faculty_id=<?= $facultyId ?>&month=<?= $filterMonth ?: date('m') ?>&year=<?= $filterYear ?: date('Y') ?>" target="_blank" class="btn btn-outline btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                Download Bill (PDF)
            </a>
            <a href="<?= BASE_URL ?>/faculty/lecture_entry.php" class="btn btn-primary btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Log Lecture
            </a>
        </div>
    </header>

    <div class="page">
        <div style="margin-bottom: 24px; display:flex; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; gap:16px;">
            <div>
                <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0 0 4px;">My Lecture History</h1>
                <p style="font-size: 14.5px; color: #64748b; margin: 0;">Comprehensive record of all lectures delivered and remuneration accrued.</p>
            </div>
            <div>
                <a href="<?= BASE_URL ?>/admin/reports/generate_html_pdf.php?faculty_id=<?= $facultyId ?>&month=<?= $filterMonth ?: date('m') ?>&year=<?= $filterYear ?: date('Y') ?>" target="_blank" class="btn btn-primary" style="padding:9px 18px;font-size:13.5px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                    Download Remuneration & Attendance PDF
                </a>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card" style="margin-bottom: 24px; padding: 20px 24px;">
            <form method="GET" style="display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;">
                <div style="flex:1;min-width:200px;">
                    <label class="form-label">Filter by Course</label>
                    <select name="course_id" class="form-select" style="width:100%;">
                        <option value="0">All Assigned Courses</option>
                        <?php foreach ($assignedCourses as $ac): ?>
                            <option value="<?= $ac['id'] ?>" <?= $filterCourse == $ac['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ac['subject_name']) ?> (<?= htmlspecialchars($ac['course_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="width:150px;">
                    <label class="form-label">Month</label>
                    <select name="month" class="form-select" style="width:100%;">
                        <option value="0">All Months</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $filterMonth == $m ? 'selected' : '' ?>>
                                <?= date('F', mktime(0,0,0,$m,1)) ?>
                            </option>
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
                    <button type="submit" class="btn-teal">Apply Filter</button>
                    <a href="<?= BASE_URL ?>/faculty/history.php" class="btn-outline-teal">Reset</a>
                </div>
            </form>
        </div>

        <!-- Summary Metric Cards -->
        <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:16px;margin-bottom:24px;">
            <div class="card" style="padding:18px 22px;">
                <div style="font-size:11.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Sessions in View</div>
                <div style="font-size:24px;font-weight:800;color:#0f172a;margin-top:2px;"><?= $totalCount ?></div>
            </div>
            <div class="card" style="padding:18px 22px;">
                <div style="font-size:11.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Total Hours</div>
                <div style="font-size:24px;font-weight:800;color:#0d9488;margin-top:2px;"><?= (float)$totalHours ?> hrs</div>
            </div>
            <div class="card" style="padding:18px 22px;">
                <div style="font-size:11.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Accrued Remuneration</div>
                <div style="font-size:24px;font-weight:800;color:#047857;margin-top:2px;">&#8377;<?= number_format($totalAmount, 2) ?></div>
            </div>
        </div>

        <!-- Lectures List -->
        <div class="card">
            <div class="card-head">
                <div>
                    <h2 style="font-size:16px;font-weight:700;color:#0f172a;margin:0;">Lecture Log Details</h2>
                    <div style="font-size:12.5px;color:#94a3b8;margin-top:2px;">Showing <?= $totalCount ?> entries</div>
                </div>
            </div>

            <?php if (empty($lectures)): ?>
                <div style="padding:50px;text-align:center;color:#94a3b8;">
                    No lecture records match your selected criteria.
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
                            <th>Hourly Rate</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lectures as $l): ?>
                        <tr>
                            <td style="font-weight:600;color:#0f172a;white-space:nowrap;">
                                <?= date('d M Y', strtotime($l['lecture_date'])) ?>
                            </td>
                            <td>
                                <div style="font-weight:600;color:#334155;"><?= htmlspecialchars($l['subject_name']) ?></div>
                                <div style="font-size:11.5px;font-family:monospace;color:#94a3b8;"><?= htmlspecialchars($l['course_code'] ?? '') ?></div>
                            </td>
                            <td><?= htmlspecialchars($l['program']) ?> &bull; Sem <?= htmlspecialchars($l['semester'] ?? '') ?></td>
                            <td>
                                <?php if ($l['class_type'] === 'T'): ?>
                                    <span class="badge-theory">Theory (T)</span>
                                <?php else: ?>
                                    <span class="badge-practical">Practical (P)</span>
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
</div>
</body>
</html>
