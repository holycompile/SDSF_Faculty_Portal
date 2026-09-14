<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

setFlash('warning', 'Student Roster and Attendance modules are currently disabled.');
header('Location: ' . BASE_URL . '/admin/courses/list.php');
exit;
$facultyMembers = $pdo->query("SELECT id, name, emp_code FROM faculty_members ORDER BY name ASC")->fetchAll();

$filterProgram = (int)($_GET['program_id'] ?? 0);
$filterFaculty = (int)($_GET['faculty_id'] ?? 0);
$filterMonth   = (int)($_GET['month'] ?? 0);
$filterYear    = (int)($_GET['year'] ?? 0);

$query = "
    SELECT le.*, c.subject_name, c.course_code, c.program, c.semester, c.batch_year, c.class_type,
           c.program_id, fm.name AS faculty_name, fm.emp_code,
           COUNT(sa.id) AS att_total,
           COALESCE(SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END), 0) AS att_present
    FROM lecture_entries le
    JOIN courses c ON c.id = le.course_id
    JOIN faculty_members fm ON fm.id = le.faculty_id
    LEFT JOIN student_attendance sa ON sa.lecture_id = le.id
    WHERE 1=1
";
$params = [];

if ($filterProgram > 0) {
    $query .= " AND c.program_id = ?";
    $params[] = $filterProgram;
}
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

$query .= " GROUP BY le.id, c.subject_name, c.course_code, c.program, c.semester, c.batch_year, c.class_type, c.program_id, fm.name, fm.emp_code
           ORDER BY le.lecture_date DESC, le.id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$lectures = $stmt->fetchAll();

// Overall metrics
$totalSessions = count($lectures);
$totalStudentsMarked = 0;
$totalPresent = 0;
foreach ($lectures as $l) {
    $totalStudentsMarked += (int)$l['att_total'];
    $totalPresent += (int)$l['att_present'];
}
$avgRate = ($totalStudentsMarked > 0) ? round(($totalPresent / $totalStudentsMarked) * 100, 1) : 0;

$active_nav = 'students-attendance';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Attendance Logs — SDSF Admin</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
</head>
<body>
    <header class="topbar">
        <div class="tb-left">
            <span class="tb-crumb">Students</span>
            <span class="tb-sep">/</span>
            <span class="tb-crumb">Attendance Logs</span>
        </div>
        <div class="tb-right">
            <span class="tb-date"><?= date('l, d F Y') ?></span>
            <a href="<?= BASE_URL ?>/admin/students/index.php" class="btn btn-outline btn-sm">
                Student Rosters &rarr;
            </a>
        </div>
    </header>

    <div class="page">
        <div class="page-header">
            <h1>Student Attendance Logs</h1>
            <p>Comprehensive record of all student attendances marked by faculty members during lecture sessions.</p>
        </div>

        <!-- Metric Summary Cards -->
        <div class="grid-3 fade-up" style="margin-bottom:24px;">
            <div class="card" style="padding:20px;">
                <div style="font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Sessions Conducted</div>
                <div style="font-size:26px;font-weight:800;color:#0f172a;margin-top:4px;"><?= $totalSessions ?></div>
                <div style="font-size:12.5px;color:#64748b;margin-top:4px;">Across all filtered lectures</div>
            </div>

            <div class="card" style="padding:20px;">
                <div style="font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Average Attendance Rate</div>
                <div style="font-size:26px;font-weight:800;color:#047857;margin-top:4px;"><?= $avgRate ?>%</div>
                <div style="font-size:12.5px;color:#64748b;margin-top:4px;"><?= $totalPresent ?> / <?= $totalStudentsMarked ?> attendances</div>
            </div>

            <div class="card" style="padding:20px;">
                <div style="font-size:12px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Total Student Records</div>
                <div style="font-size:26px;font-weight:800;color:#4f46e5;margin-top:4px;"><?= $totalStudentsMarked ?></div>
                <div style="font-size:12.5px;color:#64748b;margin-top:4px;">Student-session check-ins</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="card fade-up" style="margin-bottom:24px;padding:18px 22px;">
            <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:14px;">
                <div style="flex:1;min-width:180px;">
                    <label class="form-label" for="program_id">Academic Program</label>
                    <select name="program_id" id="program_id" class="form-select">
                        <option value="">All Programs</option>
                        <?php foreach ($programs as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= ($filterProgram == $p['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['program_name']) ?> (<?= htmlspecialchars($p['batch_year']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="flex:1;min-width:180px;">
                    <label class="form-label" for="faculty_id">Faculty Teacher</label>
                    <select name="faculty_id" id="faculty_id" class="form-select">
                        <option value="">All Faculty Members</option>
                        <?php foreach ($facultyMembers as $fm): ?>
                            <option value="<?= $fm['id'] ?>" <?= ($filterFaculty == $fm['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fm['name']) ?> (<?= htmlspecialchars($fm['emp_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="width:130px;">
                    <label class="form-label" for="month">Month</label>
                    <select name="month" id="month" class="form-select">
                        <option value="">All Months</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= ($filterMonth == $m) ? 'selected' : '' ?>>
                                <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div style="width:110px;">
                    <label class="form-label" for="year">Year</label>
                    <select name="year" id="year" class="form-select">
                        <option value="">All Years</option>
                        <?php for ($y = date('Y'); $y >= 2022; $y--): ?>
                            <option value="<?= $y ?>" <?= ($filterYear == $y) ? 'selected' : '' ?>>
                                <?= $y ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div style="display:flex;gap:8px;">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="<?= BASE_URL ?>/admin/students/attendance.php" class="btn btn-outline">Reset</a>
                </div>
            </form>
        </div>

        <!-- Lectures Attendance Table -->
        <div class="card fade-up">
            <div class="card-head">
                <div>
                    <div class="card-title">Recorded Lecture Sessions with Attendance</div>
                    <div class="card-sub">Showing <?= count($lectures) ?> session<?= count($lectures) === 1 ? '' : 's' ?></div>
                </div>
            </div>

            <?php if (empty($lectures)): ?>
                <div style="padding:60px 20px;text-align:center;color:#94a3b8;">
                    No lecture sessions match your selected filter criteria.
                </div>
            <?php else: ?>
                <table class="dt">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Faculty Teacher</th>
                            <th>Subject &amp; Code</th>
                            <th>Program &amp; Semester</th>
                            <th>Class Type</th>
                            <th>Duration</th>
                            <th>Attendance</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lectures as $l): 
                            $tot = (int)$l['att_total'];
                            $pres = (int)$l['att_present'];
                            $pct = ($tot > 0) ? round(($pres / $tot) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td style="font-weight:700;color:#0f172a;white-space:nowrap;">
                                <?= date('d M Y', strtotime($l['lecture_date'])) ?>
                            </td>
                            <td>
                                <div style="font-weight:700;color:#0f172a;"><?= htmlspecialchars($l['faculty_name']) ?></div>
                                <div style="font-size:11.5px;color:#64748b;"><?= htmlspecialchars($l['emp_code']) ?></div>
                            </td>
                            <td>
                                <div style="font-weight:600;color:#334155;"><?= htmlspecialchars($l['subject_name']) ?></div>
                                <div style="font-size:11.5px;font-family:monospace;color:#94a3b8;"><?= htmlspecialchars($l['course_code']) ?></div>
                            </td>
                            <td>
                                <div style="font-size:13px;font-weight:600;color:#475569;"><?= htmlspecialchars($l['program']) ?></div>
                                <div style="font-size:11.5px;color:#6366f1;font-weight:700;">Sem <?= htmlspecialchars($l['semester']) ?> &bull; <?= htmlspecialchars($l['batch_year'] ?? '') ?></div>
                            </td>
                            <td>
                                <?php if ($l['class_type'] === 'T'): ?>
                                    <span class="badge badge-blue">Theory</span>
                                <?php else: ?>
                                    <span class="badge badge-amber">Practical</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:600;"><?= (float)$l['hours'] ?> hrs</td>
                            <td>
                                <?php if ($tot > 0): ?>
                                    <div style="display:inline-flex;align-items:center;gap:6px;">
                                        <span class="badge badge-green" style="font-size:12px;padding:3px 8px;">
                                            <?= $pres ?> / <?= $tot ?> Present (<?= $pct ?>%)
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <span style="font-size:12px;color:#94a3b8;font-style:italic;">No students marked</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;">
                                <?php if ($tot > 0): ?>
                                    <button type="button" class="btn btn-outline btn-sm" onclick="viewAttendance(<?= $l['id'] ?>)">
                                        View Sheet &rarr;
                                    </button>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Attendance Sheet Modal -->
<div id="attModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:20px;max-width:650px;width:100%;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);overflow:hidden;">
        <div style="padding:20px 24px;border-bottom:1.5px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;">
            <div>
                <h3 style="font-size:16px;font-weight:800;color:#0f172a;margin:0;" id="modalSubject">Attendance Sheet</h3>
                <div style="font-size:12.5px;color:#64748b;margin-top:2px;" id="modalSubtitle">Details</div>
            </div>
            <button type="button" onclick="closeAttModal()" style="background:transparent;border:none;cursor:pointer;color:#64748b;padding:4px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <div style="padding:14px 24px;background:#eef2ff;border-bottom:1px solid #e0e7ff;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
            <div style="display:flex;gap:10px;font-size:13px;font-weight:600;">
                <span style="color:#475569;">Total: <strong id="modalTotal" style="color:#0f172a;">0</strong></span>
                <span style="color:#047857;">&#10003; Present: <strong id="modalPresent">0</strong></span>
                <span style="color:#e11d48;">&#10007; Absent: <strong id="modalAbsent">0</strong></span>
            </div>
            <div style="font-size:13px;font-weight:700;color:#4338ca;" id="modalRate">0% Attendance</div>
        </div>

        <div style="flex:1;overflow-y:auto;padding:16px 24px;" id="modalContent">
            <!-- Student Rows Injected Here -->
        </div>

        <div style="padding:14px 24px;border-top:1.5px solid #f1f5f9;text-align:right;background:#f8fafc;">
            <button type="button" class="btn btn-outline" onclick="closeAttModal()" style="padding:8px 18px;">Close Sheet</button>
        </div>
    </div>
</div>

<script>
function viewAttendance(lectureId) {
    const modal = document.getElementById('attModal');
    const content = document.getElementById('modalContent');
    content.innerHTML = '<div style="padding:30px;text-align:center;color:#64748b;">Loading student attendance sheet...</div>';
    modal.style.display = 'flex';

    fetch(`<?= BASE_URL ?>/api/get_lecture_attendance.php?lecture_id=${lectureId}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                content.innerHTML = `<div style="color:#ef4444;padding:20px;text-align:center;">${data.message || 'Error loading attendance.'}</div>`;
                return;
            }

            document.getElementById('modalSubject').textContent = `${data.lecture.subject_name} (${data.lecture.course_code})`;
            document.getElementById('modalSubtitle').textContent = `${data.lecture.faculty_name} &bull; ${data.lecture.program} &bull; ${data.lecture.semester} &bull; ${data.lecture.formatted_date}`;
            document.getElementById('modalTotal').textContent = data.summary.total;
            document.getElementById('modalPresent').textContent = data.summary.present;
            document.getElementById('modalAbsent').textContent = data.summary.absent;
            document.getElementById('modalRate').textContent = `${data.summary.rate}% Attendance Rate`;

            if (data.students.length === 0) {
                content.innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8;">No student attendance was recorded for this session.</div>';
                return;
            }

            let html = `
                <table style="width:100%;border-collapse:collapse;font-size:13.5px;">
                    <thead>
                        <tr style="border-bottom:1.5px solid #e2e8f0;color:#64748b;font-size:11px;text-transform:uppercase;text-align:left;">
                            <th style="padding:8px 12px;width:60px;">Roll</th>
                            <th style="padding:8px 12px;width:130px;">Enrollment</th>
                            <th style="padding:8px 12px;">Student Name</th>
                            <th style="padding:8px 12px;text-align:right;width:100px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            data.students.forEach(st => {
                const isPres = st.status === 'present';
                const badgeStyle = isPres 
                    ? 'background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;' 
                    : 'background:#fff1f2;color:#e11d48;border:1px solid #fecdd3;';
                const badgeText = isPres ? '&#10003; Present' : '&#10007; Absent';

                html += `
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:10px 12px;font-weight:700;color:#4338ca;">${st.roll_no}</td>
                        <td style="padding:10px 12px;font-family:monospace;color:#64748b;font-size:12px;">${st.enrollment_no}</td>
                        <td style="padding:10px 12px;font-weight:600;color:#0f172a;">${st.student_name}</td>
                        <td style="padding:10px 12px;text-align:right;">
                            <span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:11.5px;font-weight:700;${badgeStyle}">
                                ${badgeText}
                            </span>
                        </td>
                    </tr>
                `;
            });

            html += `</tbody></table>`;
            content.innerHTML = html;
        })
        .catch(err => {
            content.innerHTML = `<div style="color:#ef4444;padding:20px;text-align:center;">Failed to load attendance: ${err.message}</div>`;
        });
}

function closeAttModal() {
    document.getElementById('attModal').style.display = 'none';
}

window.addEventListener('click', e => {
    const modal = document.getElementById('attModal');
    if (e.target === modal) {
        closeAttModal();
    }
});
</script>
</body>
</html>
