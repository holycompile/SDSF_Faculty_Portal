<?php
define('ROOT', dirname(dirname(__FILE__)));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireFaculty();

$facultyId   = getFacultyId();
$facultyName = $_SESSION['faculty_name'] ?? 'Faculty Member';

// Handle attendance update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_attendance') {
    $lectureId = (int)($_POST['lecture_id'] ?? 0);
    $attUpdates = $_POST['attendance'] ?? [];

    // Verify lecture belongs to this faculty
    $vStmt = $pdo->prepare("SELECT id, lecture_date FROM lecture_entries WHERE id = ? AND faculty_id = ?");
    $vStmt->execute([$lectureId, $facultyId]);
    $lec = $vStmt->fetch(PDO::FETCH_ASSOC);

    if ($lec && is_array($attUpdates) && !empty($attUpdates)) {
        try {
            $updStmt = $pdo->prepare("
                INSERT INTO student_attendance (lecture_id, student_id, attendance_date, status)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status)
            ");
            foreach ($attUpdates as $stId => $status) {
                $status = ($status === 'absent') ? 'absent' : 'present';
                $updStmt->execute([$lectureId, (int)$stId, $lec['lecture_date'], $status]);
            }
            setFlash('success', 'Attendance record for this lecture has been successfully updated!');
        } catch (PDOException $e) {
            setFlash('error', 'Could not update attendance: ' . $e->getMessage());
        }
    }
    header('Location: ' . BASE_URL . '/faculty/attendance.php');
    exit;
}

// Fetch assigned courses for filter dropdown
$cStmt = $pdo->prepare("
    SELECT c.id, c.subject_name, c.course_code, c.program, c.semester
    FROM courses c
    JOIN faculty_course_assignments fca ON fca.course_id = c.id
    WHERE fca.faculty_id = ?
    ORDER BY c.program, c.semester, c.subject_name
");
$cStmt->execute([$facultyId]);
$assignedCourses = $cStmt->fetchAll(PDO::FETCH_ASSOC);

// Filters
$filterCourse = (int)($_GET['course_id'] ?? 0);
$filterMonth  = (int)($_GET['month'] ?? 0);
$filterYear   = (int)($_GET['year'] ?? 0);

$query = "
    SELECT le.*, c.subject_name, c.course_code, c.program, c.semester, c.batch_year, c.class_type,
           COUNT(sa.id) AS att_total,
           COALESCE(SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END), 0) AS att_present
    FROM lecture_entries le
    JOIN courses c ON c.id = le.course_id
    LEFT JOIN student_attendance sa ON sa.lecture_id = le.id
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

$query .= " GROUP BY le.id, c.subject_name, c.course_code, c.program, c.semester, c.batch_year, c.class_type
            ORDER BY le.lecture_date DESC, le.id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$lectures = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate overall summary metrics
$totalLectures = count($lectures);
$totalMarked = 0;
$totalPresent = 0;
foreach ($lectures as $l) {
    $totalMarked += (int)$l['att_total'];
    $totalPresent += (int)$l['att_present'];
}
$overallRate = ($totalMarked > 0) ? round(($totalPresent / $totalMarked) * 100, 1) : 0;

$active_nav = 'attendance';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Attendance Sheets — Faculty Portal</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/faculty_sidebar.php'; ?>
<style>
.att-toggle-group {
    display: inline-flex;
    background: #f1f5f9;
    padding: 3px;
    border-radius: 8px;
    border: 1.5px solid #cbd5e1;
    gap: 2px;
}
.att-toggle-group input[type="radio"] { display: none; }
.att-toggle-group label {
    padding: 4px 10px;
    font-size: 11.5px;
    font-weight: 700;
    border-radius: 6px;
    cursor: pointer;
    transition: all .15s ease;
    user-select: none;
    color: #64748b;
}
.att-toggle-group input[value="present"]:checked + label {
    background: #16a34a;
    color: #ffffff;
}
.att-toggle-group input[value="absent"]:checked + label {
    background: #dc2626;
    color: #ffffff;
}
.roll-badge {
    width: 30px;
    height: 30px;
    border-radius: 7px;
    background: #eef2ff;
    color: #4338ca;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 800;
    font-family: monospace;
}
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,0.6);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
</style>
</head>
<body>
    <header class="topbar">
        <div class="tb-left">
            <span class="tb-crumb">Student Attendance Sheets</span>
        </div>
        <div class="tb-right">
            <span class="tb-date"><?= date('l, d F Y') ?></span>
            <a href="<?= BASE_URL ?>/faculty/lecture_entry.php" class="btn btn-primary btn-sm">
                + Log Lecture &amp; Mark Attendance
            </a>
        </div>
    </header>

    <div class="page">
        <!-- Header -->
        <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;">
            <div>
                <h1>Daily Student Attendance Records</h1>
                <p>View and inspect all student attendance logs recorded for your assigned courses.</p>
            </div>
            <div>
                <a href="<?= BASE_URL ?>/admin/reports/visiting_faculty_attendance.php" target="_blank" class="btn btn-outline btn-sm">
                    Print Attendance Register &rarr;
                </a>
            </div>
        </div>

        <?php $f = getFlash(); if ($f): ?>
            <div class="alert alert-<?= $f['type'] === 'success' ? 'success' : ($f['type'] === 'warning' ? 'warning' : 'error') ?>">
                <?= $f['message'] ?>
            </div>
        <?php endif; ?>

        <!-- Stats Metric Cards -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;margin-bottom:24px;">
            <div class="card" style="padding:18px 22px;">
                <div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Conducted Lectures</div>
                <div style="font-size:26px;font-weight:800;color:#0f172a;margin-top:4px;"><?= $totalLectures ?></div>
                <div style="font-size:12px;color:#94a3b8;margin-top:2px;">Recorded teaching sessions</div>
            </div>
            <div class="card" style="padding:18px 22px;">
                <div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Total Attendances</div>
                <div style="font-size:26px;font-weight:800;color:#4f46e5;margin-top:4px;"><?= $totalMarked ?></div>
                <div style="font-size:12px;color:#94a3b8;margin-top:2px;"><?= $totalPresent ?> Present &bull; <?= $totalMarked - $totalPresent ?> Absent</div>
            </div>
            <div class="card" style="padding:18px 22px;">
                <div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Overall Attendance Rate</div>
                <div style="font-size:26px;font-weight:800;color:<?= $overallRate >= 75 ? '#16a34a' : '#b45309' ?>;margin-top:4px;"><?= $overallRate ?>%</div>
                <div style="font-size:12px;color:#94a3b8;margin-top:2px;">Across all conducted lectures</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="card" style="padding:16px 20px;margin-bottom:24px;">
            <form method="GET" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;">
                <div style="flex:1;min-width:240px;">
                    <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:6px;">Assigned Subject / Course</label>
                    <select name="course_id" class="form-select" style="font-size:13.5px;padding:8px 12px;">
                        <option value="0">-- All Assigned Courses --</option>
                        <?php foreach ($assignedCourses as $ac): ?>
                            <option value="<?= $ac['id'] ?>" <?= $filterCourse === (int)$ac['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ac['program']) ?> &bull; <?= htmlspecialchars($ac['semester']) ?> &bull; <?= htmlspecialchars($ac['subject_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="width:140px;">
                    <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:6px;">Month</label>
                    <select name="month" class="form-select" style="font-size:13.5px;padding:8px 12px;">
                        <option value="0">All Months</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $filterMonth === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div style="width:120px;">
                    <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:6px;">Year</label>
                    <select name="year" class="form-select" style="font-size:13.5px;padding:8px 12px;">
                        <option value="0">All Years</option>
                        <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                            <option value="<?= $y ?>" <?= $filterYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div>
                    <button type="submit" class="btn btn-primary" style="padding:9px 18px;font-size:13.5px;">Filter</button>
                    <?php if ($filterCourse || $filterMonth || $filterYear): ?>
                        <a href="<?= BASE_URL ?>/faculty/attendance.php" class="btn btn-outline" style="padding:9px 14px;font-size:13.5px;">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Lecture Sessions & Attendance Table -->
        <div class="card">
            <div class="card-head">
                <div>
                    <div class="card-title">Conducted Lecture Sessions &amp; Attendance Sheets</div>
                    <div class="card-sub">Showing <?= count($lectures) ?> session<?= count($lectures) === 1 ? '' : 's' ?></div>
                </div>
            </div>

            <?php if (empty($lectures)): ?>
                <div style="padding:48px 20px;text-align:center;color:#94a3b8;">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 10px;display:block;opacity:.4"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    No lecture sessions found matching your filters.
                    <div style="margin-top:10px;">
                        <a href="<?= BASE_URL ?>/faculty/lecture_entry.php" class="btn btn-primary btn-sm">+ Log New Lecture</a>
                    </div>
                </div>
            <?php else: ?>
                <table class="dt">
                    <thead>
                        <tr>
                            <th style="width:110px;">Date</th>
                            <th>Subject / Course</th>
                            <th style="width:130px;">Program &amp; Sem</th>
                            <th style="width:90px;">Type</th>
                            <th style="width:70px;">Hours</th>
                            <th style="width:150px;">Student Attendance</th>
                            <th style="text-align:right;width:150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lectures as $lec): 
                            $attTot = (int)$lec['att_total'];
                            $attPres = (int)$lec['att_present'];
                            $rate = ($attTot > 0) ? round(($attPres / $attTot) * 100) : 0;
                        ?>
                        <tr>
                            <td style="font-weight:700;color:#0f172a;white-space:nowrap;">
                                <?= date('d M Y', strtotime($lec['lecture_date'])) ?>
                            </td>
                            <td>
                                <div style="font-weight:700;color:#0f172a;"><?= htmlspecialchars($lec['subject_name']) ?></div>
                                <div style="font-size:11.5px;color:#64748b;font-family:monospace;"><?= htmlspecialchars($lec['course_code'] ?? '—') ?></div>
                            </td>
                            <td>
                                <div style="font-size:12.5px;font-weight:600;color:#334155;"><?= htmlspecialchars($lec['program']) ?></div>
                                <span style="font-size:11px;color:#4f46e5;background:#eef2ff;padding:2px 6px;border-radius:4px;font-weight:700;">
                                    <?= htmlspecialchars($lec['semester']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($lec['class_type'] === 'P'): ?>
                                    <span class="badge badge-blue">Practical</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#f1f5f9;color:#334155;">Theory</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:700;color:#0f172a;">
                                <?= number_format($lec['hours'], 1) ?>h
                            </td>
                            <td>
                                <?php if ($attTot > 0): ?>
                                    <span class="badge <?= $rate >= 75 ? 'badge-green' : ($rate >= 50 ? 'badge-amber' : 'badge-red') ?>">
                                        <?= $attPres ?> / <?= $attTot ?> (<?= $rate ?>%)
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background:#f8fafc;color:#94a3b8;border:1px solid #e2e8f0;">No roster saved</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;">
                                <button type="button" class="btn btn-outline btn-sm" style="font-size:12px;padding:4px 10px;"
                                        onclick="openAttendanceSheet(<?= $lec['id'] ?>)">
                                    View / Edit Sheet
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Attendance Sheet Modal -->
    <div id="attModal" class="modal-overlay">
        <div style="background:#fff;border-radius:20px;max-width:620px;width:95%;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(0,0,0,0.22);animation:fadeUp .2s ease both;">
            <div style="padding:20px 24px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <h3 style="font-size:17px;font-weight:800;color:#0f172a;margin:0;" id="modalTitle">Lecture Attendance Sheet</h3>
                    <div style="font-size:12px;color:#64748b;margin-top:2px;" id="modalSubtitle">Loading session details...</div>
                </div>
                <button type="button" onclick="closeAttModal()" style="background:none;border:none;font-size:22px;color:#94a3b8;cursor:pointer;">&times;</button>
            </div>

            <div id="modalLoading" style="padding:40px;text-align:center;color:#94a3b8;">
                Loading attendance records...
            </div>

            <form method="POST" action="<?= BASE_URL ?>/faculty/attendance.php" id="modalForm" style="display:none;flex:1;overflow-y:auto;display:flex;flex-direction:column;">
                <input type="hidden" name="action" value="update_attendance">
                <input type="hidden" name="lecture_id" id="modalLectureId" value="">

                <div style="padding:14px 24px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                    <div style="font-size:12.5px;font-weight:700;color:#0f172a;" id="modalAttSummary">
                        Summary
                    </div>
                    <div style="display:flex;gap:8px;">
                        <button type="button" onclick="modalMarkAll('present')" class="btn btn-outline btn-sm" style="font-size:11.5px;padding:3px 9px;background:#f0fdf4;border-color:#bbf7d0;color:#16a34a;font-weight:700;">
                            ✓ All Present
                        </button>
                        <button type="button" onclick="modalMarkAll('absent')" class="btn btn-outline btn-sm" style="font-size:11.5px;padding:3px 9px;background:#fef2f2;border-color:#fecaca;color:#dc2626;font-weight:700;">
                            ✕ All Absent
                        </button>
                    </div>
                </div>

                <div style="padding:16px 24px;flex:1;overflow-y:auto;">
                    <table class="dt" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width:40px;">#</th>
                                <th style="width:85px;">Roll No</th>
                                <th>Student Name</th>
                                <th style="text-align:center;width:170px;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="modalTbody">
                        </tbody>
                    </table>
                </div>

                <div style="padding:16px 24px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:10px;background:#fff;">
                    <button type="button" class="btn btn-outline" onclick="closeAttModal()">Close</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openAttendanceSheet(lectureId) {
        document.getElementById('attModal').style.display = 'flex';
        document.getElementById('modalLoading').style.display = 'block';
        document.getElementById('modalForm').style.display = 'none';
        document.getElementById('modalLectureId').value = lectureId;

        fetch('<?= BASE_URL ?>/api/get_lecture_attendance.php?lecture_id=' + lectureId)
            .then(res => res.json())
            .then(data => {
                document.getElementById('modalLoading').style.display = 'none';
                if (data.success && data.lecture) {
                    document.getElementById('modalTitle').textContent = `${data.lecture.subject_name} (${data.lecture.course_code || '—'})`;
                    document.getElementById('modalSubtitle').textContent = `${data.lecture.program} • ${data.lecture.semester} • Date: ${data.lecture.formatted_date} (${data.lecture.hours} hrs)`;
                    
                    const tbody = document.getElementById('modalTbody');
                    tbody.innerHTML = '';

                    if (data.students && data.students.length > 0) {
                        data.students.forEach((st, idx) => {
                            const isPresent = (st.status === 'present');
                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td style="color:#94a3b8;font-size:12px;">${idx + 1}</td>
                                <td><span class="roll-badge">${escapeHtml(st.roll_no)}</span></td>
                                <td>
                                    <strong style="color:#0f172a;font-size:13.5px;">${escapeHtml(st.student_name)}</strong>
                                    <div style="font-family:monospace;font-size:11px;color:#64748b;">${escapeHtml(st.enrollment_no || '')}</div>
                                </td>
                                <td style="text-align:center;">
                                    <div class="att-toggle-group">
                                        <input type="radio" name="attendance[${st.student_id}]" id="m_att_${st.student_id}_p" value="present" ${isPresent ? 'checked' : ''} onchange="updateModalSummary()">
                                        <label for="m_att_${st.student_id}_p">Present</label>
                                        <input type="radio" name="attendance[${st.student_id}]" id="m_att_${st.student_id}_a" value="absent" ${!isPresent ? 'checked' : ''} onchange="updateModalSummary()">
                                        <label for="m_att_${st.student_id}_a">Absent</label>
                                    </div>
                                </td>
                            `;
                            tbody.appendChild(tr);
                        });
                        document.getElementById('modalForm').style.display = 'flex';
                        updateModalSummary();
                    } else {
                        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:#94a3b8;">No student attendance was recorded for this lecture.</td></tr>';
                        document.getElementById('modalForm').style.display = 'flex';
                    }
                } else {
                    alert(data.message || 'Could not load lecture attendance.');
                    closeAttModal();
                }
            })
            .catch(err => {
                document.getElementById('modalLoading').style.display = 'none';
                alert('Network error loading attendance sheet: ' + err.message);
                closeAttModal();
            });
    }

    function closeAttModal() {
        document.getElementById('attModal').style.display = 'none';
    }

    function updateModalSummary() {
        const present = document.querySelectorAll('#modalTbody input[value="present"]:checked').length;
        const absent = document.querySelectorAll('#modalTbody input[value="absent"]:checked').length;
        const total = present + absent;
        const pct = total > 0 ? Math.round((present / total) * 100) : 0;
        document.getElementById('modalAttSummary').innerHTML = `<span style="color:#16a34a;font-weight:800;">${present} Present</span> &bull; <span style="color:#dc2626;font-weight:800;">${absent} Absent</span> &bull; <span style="color:#1e3a8a;">${pct}% Attendance</span>`;
    }

    function modalMarkAll(status) {
        const radios = document.querySelectorAll(`#modalTbody input[value="${status}"]`);
        radios.forEach(r => r.checked = true);
        updateModalSummary();
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
    </script>
</body>
</html>
