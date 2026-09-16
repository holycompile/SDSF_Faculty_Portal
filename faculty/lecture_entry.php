<?php
define('ROOT', dirname(dirname(__FILE__)));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireFaculty();

$facultyId = getFacultyId() ?? (int)($_SESSION['faculty_id'] ?? 0);
$facultyName = $_SESSION['faculty_name'] ?? 'Faculty Member';

// Fetch faculty rates
$fStmt = $pdo->prepare("SELECT theory_rate, practical_rate FROM faculty_members WHERE id = ?");
$fStmt->execute([$facultyId]);
$facultyData = $fStmt->fetch();
$facTheoryRate = (float)($facultyData['theory_rate'] ?? 800.00);
$facPracticalRate = (float)($facultyData['practical_rate'] ?? 400.00);

// Fetch assigned courses with full L-T-P details
$stmt = $pdo->prepare("
    SELECT c.*, ap.program_name AS prog_title, ap.batch_year AS prog_batch
    FROM courses c
    JOIN faculty_course_assignments fca ON fca.course_id = c.id
    LEFT JOIN academic_programs ap ON ap.id = c.program_id
    WHERE fca.faculty_id = ?
    ORDER BY c.program, c.semester_number, c.semester, c.subject_name
");
$stmt->execute([$facultyId]);
$courses = $stmt->fetchAll();

$errors = [];
$preselectedCourseId = (int)($_GET['course_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $courseId    = (int)($_POST['course_id'] ?? 0);
    $lectureDate = trim($_POST['lecture_date'] ?? '');
    $hours       = (float)($_POST['hours'] ?? 0);
    $sessionType = trim($_POST['session_type'] ?? 'T'); // 'T' or 'P'

    // Validate course belongs to faculty
    $matchedCourse = null;
    foreach ($courses as $c) {
        if ($c['id'] == $courseId) {
            $matchedCourse = $c;
            break;
        }
    }

    if (!$matchedCourse) {
        $errors[] = 'Please select a valid assigned course.';
    }
    if (!$lectureDate) {
        $errors[] = 'Please enter the lecture date.';
    } elseif ($lectureDate > date('Y-m-d')) {
        $errors[] = 'Lecture date cannot be in the future.';
    }
    if ($hours <= 0 || $hours > 12) {
        $errors[] = 'Please enter a valid duration between 0.5 and 12 hours.';
    }

    if (empty($errors)) {
        try {
            // Apply this teacher's specific configured rates
            $rate = ($sessionType === 'P') ? $facPracticalRate : $facTheoryRate;
            $amount = round($hours * $rate, 2);

            $pdo->beginTransaction();

            $ins = $pdo->prepare("
                INSERT INTO lecture_entries (faculty_id, course_id, lecture_date, hours, rate_per_hour, amount)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([$facultyId, $courseId, $lectureDate, $hours, $rate, $amount]);
            $lectureId = (int)$pdo->lastInsertId();

            // Record attendance for students submitted with this lecture
            $attendanceData = $_POST['attendance'] ?? [];
            $totalMarked = 0;
            $presentCount = 0;
            if (!empty($attendanceData) && is_array($attendanceData)) {
                $attStmt = $pdo->prepare("
                    INSERT INTO student_attendance (lecture_id, student_id, attendance_date, status)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), attendance_date = VALUES(attendance_date)
                ");
                foreach ($attendanceData as $stId => $stStatus) {
                    $cleanStatus = ($stStatus === 'absent') ? 'absent' : 'present';
                    $attStmt->execute([$lectureId, (int)$stId, $lectureDate, $cleanStatus]);
                    $totalMarked++;
                    if ($cleanStatus === 'present') {
                        $presentCount++;
                    }
                }
            }

            // Calculate monthly total to check 30,000 threshold
            $month = (int)date('m', strtotime($lectureDate));
            $year  = (int)date('Y', strtotime($lectureDate));
            $mStmt = $pdo->prepare("
                SELECT COALESCE(SUM(amount), 0) FROM lecture_entries
                WHERE faculty_id = ? AND MONTH(lecture_date) = ? AND YEAR(lecture_date) = ?
            ");
            $mStmt->execute([$facultyId, $month, $year]);
            $monthTotal = (float)$mStmt->fetchColumn();

            // Commit core lecture entry and student attendance transaction cleanly
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            // Record into dedicated physical cohort table as dynamic date column with 1 (present) and 0 (absent)
            if (!empty($attendanceData) && is_array($attendanceData)) {
                $progName = $matchedCourse['prog_title'] ?? $matchedCourse['program'];
                $semName  = $matchedCourse['semester'];
                try {
                    recordCohortAttendance($pdo, $progName, $semName, $lectureDate, $attendanceData, $lectureId, (int)$courseId);
                } catch (Exception $e) {
                    error_log("Cohort attendance recording error: " . $e->getMessage());
                }
            }

            $attMsg = "";
            if ($totalMarked > 0) {
                $attMsg = " Attendance recorded: {$presentCount}/{$totalMarked} students present.";
            }

            if ($monthTotal > 30000) {
                setFlash('warning', "Lecture session logged (Remuneration: Rs. " . number_format($amount, 2) . ")!{$attMsg} Note: Monthly total is Rs. " . number_format($monthTotal, 2) . ", which exceeds the Rs. 30,000 ceiling.");
            } else {
                setFlash('success', "Lecture session saved successfully! Calculated remuneration: Rs. " . number_format($amount, 2) . ".{$attMsg}");
            }

            header('Location: ' . BASE_URL . '/faculty/dashboard.php');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$active_nav = 'lecture-entry';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Lecture Session — Faculty Portal</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
    <style>
        .page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            font-size: 13.5px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }
        .form-select, .form-input {
            width: 100%;
            padding: 11px 14px;
            border-radius: 10px;
            border: 1.5px solid #cbd5e1;
            background: #fff;
            font-size: 14px;
            color: #0f172a;
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .form-select:focus, .form-input:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79,70,229,0.15);
        }
        .calc-box {
            background: #f8fafc;
            border: 1.5px dashed #6366f1;
            border-radius: 14px;
            padding: 18px 22px;
            margin-top: 10px;
            margin-bottom: 24px;
        }
        .calc-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13.5px;
            padding: 6px 0;
            border-bottom: 1px solid #edf2f7;
        }
        .calc-row:last-child {
            border-bottom: none;
            padding-top: 10px;
            margin-top: 4px;
        }
        .btn-submit {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 14px 24px;
            border-radius: 12px;
            background: linear-gradient(135deg, #4f46e5, #4338ca);
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(79,70,229,0.35);
            transition: all .2s ease;
        }
        .btn-submit:hover {
            opacity: 0.95;
            transform: translateY(-1px);
        }
        .session-toggle {
            display: inline-flex;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            gap: 4px;
        }
        .session-toggle label {
            padding: 8px 18px;
            font-size: 13px;
            font-weight: 700;
            color: #475569;
            border-radius: 8px;
            cursor: pointer;
            transition: all .15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .session-toggle input[type="radio"] {
            display: none;
        }
        .session-toggle input[type="radio"]:checked + label {
            background: #ffffff;
            color: #4f46e5;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }
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
            padding: 5px 12px;
            font-size: 12px;
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
            box-shadow: 0 1px 4px rgba(22,163,74,0.3);
        }
        .att-toggle-group input[value="absent"]:checked + label {
            background: #dc2626;
            color: #ffffff;
            box-shadow: 0 1px 4px rgba(220,38,38,0.3);
        }
        .roll-badge {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: #eef2ff;
            color: #4338ca;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 800;
            font-family: monospace;
        }
        .form-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 640px) {
            .form-row-2 {
                grid-template-columns: 1fr !important;
                gap: 14px;
            }
            .session-toggle {
                flex-direction: column !important;
            }
            .session-toggle label {
                padding: 10px 14px !important;
            }
            .entry-card {
                padding: 18px 14px !important;
            }
        }
    </style>
    <?php require_once ROOT . '/includes/faculty_sidebar.php'; ?>
</head>
<body>
    <header class="topbar">
        <div class="tb-left">
            <span class="tb-crumb">Log Lecture Session</span>
        </div>
        <div class="tb-right">
            <span class="tb-date"><?= date('l, d F Y') ?></span>
            <a href="<?= BASE_URL ?>/faculty/dashboard.php" class="btn btn-outline btn-sm">
                &larr; Back to Dashboard
            </a>
        </div>
    </header>

    <div class="page">
        <div style="max-width: 800px; margin: 0 auto;">
            <div style="margin-bottom: 24px;">
                <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0 0 6px;">Log Conducted Lecture</h1>
                <p style="font-size: 14.5px; color: #64748b; margin: 0;">Select your assigned subject, pick session details, and calculate remuneration automatically.</p>
            </div>

        <?php if (!empty($errors)): ?>
            <div class="alert-error" style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:12px 16px;border-radius:10px;margin-bottom:20px;">
                <ul style="margin: 0; padding-left: 20px;">
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (empty($courses)): ?>
            <div class="card" style="text-align:center;padding:50px 20px;">
                <p style="color:#ef4444;font-weight:600;font-size:16px;">No courses assigned to your account!</p>
                <p style="color:#64748b;font-size:14px;">You cannot log lectures without an assigned course. Please contact the administrator.</p>
                <a href="<?= BASE_URL ?>/faculty/dashboard.php" class="btn btn-outline" style="margin-top:12px;">Return to Dashboard</a>
            </div>
        <?php else: ?>
            <div class="card entry-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:28px;box-shadow:0 4px 20px rgba(0,0,0,0.03);">
                <form method="POST" id="lectureForm">
                    <div class="form-group">
                        <label class="form-label" for="course_id">Course / Subject (From Assigned Courses) *</label>
                        <select name="course_id" id="course_id" class="form-select" required onchange="onCourseSelected()">
                            <option value="">-- Choose Assigned Course --</option>
                            <?php foreach ($courses as $c): 
                                $ltpPattern = $c['ltp_pattern'] ?: ($c['credits'] . '(' . $c['lecture_hours'] . '-' . $c['tutorial_hours'] . '-' . $c['practical_hours'] . ')');
                                $selected = ($preselectedCourseId == $c['id']) ? 'selected' : '';
                                $progLabel = $c['prog_title'] ?? $c['program'];
                                $batchLabel = $c['batch_year'] ?? $c['prog_batch'] ?? '';
                            ?>
                                <option value="<?= $c['id'] ?>" 
                                        data-ltp="<?= htmlspecialchars($ltpPattern) ?>"
                                        data-p-hours="<?= (int)($c['practical_hours'] ?? 0) ?>"
                                        data-l-hours="<?= (int)($c['lecture_hours'] ?? 3) ?>"
                                        data-name="<?= htmlspecialchars($c['subject_name']) ?>"
                                        data-code="<?= htmlspecialchars($c['course_code'] ?? '') ?>"
                                        data-program="<?= htmlspecialchars($progLabel) ?>"
                                        data-semester="<?= htmlspecialchars($c['semester']) ?>"
                                        <?= $selected ?>>
                                    <?= htmlspecialchars($progLabel) ?> &bull; <?= htmlspecialchars($c['semester']) ?> &bull; <?= htmlspecialchars($c['subject_name']) ?> [<?= htmlspecialchars($c['course_code']) ?>] &bull; Credits: <?= htmlspecialchars($ltpPattern) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Session Type Toggle (Theory vs Practical) -->
                    <div class="form-group" id="sessionTypeGroup">
                        <label class="form-label">Conducted Session Type *</label>
                        <div class="session-toggle">
                            <input type="radio" name="session_type" id="type_t" value="T" checked onchange="calculateRemuneration()">
                            <label for="type_t">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                                Theory Lecture (₹<?= number_format($facTheoryRate, 0) ?>/hr)
                            </label>

                            <input type="radio" name="session_type" id="type_p" value="P" onchange="calculateRemuneration()">
                            <label for="type_p">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                                Practical / Lab (₹<?= number_format($facPracticalRate, 0) ?>/hr)
                            </label>
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label" for="lecture_date">Lecture Date *</label>
                            <input type="date" name="lecture_date" id="lecture_date" class="form-input" 
                                   max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="hours">Duration (in Hours) *</label>
                            <input type="number" step="0.5" min="0.5" max="12" name="hours" id="hours" class="form-input" 
                                   value="1.0" required oninput="calculateRemuneration()">
                            <div style="font-size:12px;color:#94a3b8;margin-top:4px;">Standard durations: 1.0, 1.5, 2.0 hrs</div>
                        </div>
                    </div>

                    <!-- Student Attendance Register for Selected Course -->
                    <div id="attendanceSection" style="margin-top:24px;margin-bottom:24px;border:1.5px solid #e2e8f0;border-radius:14px;background:#ffffff;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                        <div style="background:#f8fafc;padding:14px 18px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div style="width:34px;height:34px;border-radius:8px;background:#eef2ff;color:#4f46e5;display:flex;align-items:center;justify-content:center;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                </div>
                                <div>
                                    <div style="font-size:14.5px;font-weight:800;color:#0f172a;">
                                        Student Attendance Register
                                    </div>
                                    <div style="font-size:12px;color:#64748b;" id="attCohortInfo">
                                        Mark attendance for the enrolled cohort
                                    </div>
                                </div>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <button type="button" class="btn btn-outline btn-sm" onclick="markAllAttendance('present')" style="font-size:12px;padding:4px 10px;background:#f0fdf4;border-color:#bbf7d0;color:#16a34a;font-weight:700;">
                                    ✓ All Present
                                </button>
                                <button type="button" class="btn btn-outline btn-sm" onclick="markAllAttendance('absent')" style="font-size:12px;padding:4px 10px;background:#fef2f2;border-color:#fecaca;color:#dc2626;font-weight:700;">
                                    ✕ All Absent
                                </button>
                                <span id="attSummaryBadge" style="font-size:12px;font-weight:700;color:#1e3a8a;background:#eff6ff;padding:4px 10px;border-radius:8px;border:1px solid #bfdbfe;">
                                    0 Present &bull; 0 Absent
                                </span>
                            </div>
                        </div>

                        <!-- Student Rows Container -->
                        <div id="attendanceStudentsContainer" style="padding:16px;">
                            <div id="attLoading" style="text-align:center;padding:24px;color:#94a3b8;font-size:13.5px;">
                                Loading enrolled student roster...
                            </div>
                            <div id="attEmpty" style="display:none;text-align:center;padding:24px;color:#94a3b8;font-size:13.5px;">
                                No enrolled students found for this subject / cohort.
                            </div>
                            <table class="dt" id="attTable" style="display:none;width:100%;">
                                <thead>
                                    <tr>
                                        <th style="width:45px;">#</th>
                                        <th style="width:85px;">Roll No</th>
                                        <th>Student Name</th>
                                        <th style="width:160px;">Enrollment No</th>
                                        <th style="text-align:center;width:180px;">Attendance Status</th>
                                    </tr>
                                </thead>
                                <tbody id="attTbody">
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Live Calculation Preview -->
                    <div class="calc-box">
                        <div style="font-size:12px;font-weight:800;color:#4338ca;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:12px;display:flex;align-items:center;gap:6px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1z"/><line x1="8" y1="8" x2="16" y2="8"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="16" x2="12" y2="16"/></svg>
                            Automated Remuneration Preview
                        </div>
                        <div class="calc-row">
                            <span style="color:#64748b;">Subject Credits (L T P):</span>
                            <span id="previewLtp" style="font-family:monospace;font-weight:800;color:#0f172a;">—</span>
                        </div>
                        <div class="calc-row">
                            <span style="color:#64748b;">Session Type:</span>
                            <span id="previewSessionType" style="font-weight:600;color:#0f172a;">Theory Lecture</span>
                        </div>
                        <div class="calc-row">
                            <span style="color:#64748b;">Configured Hourly Rate:</span>
                            <span id="previewRate" style="font-weight:700;color:#0f172a;">₹<?= number_format($facTheoryRate, 2) ?> / hr</span>
                        </div>
                        <div class="calc-row">
                            <span style="color:#64748b;">Duration:</span>
                            <span id="previewHours" style="font-weight:600;color:#0f172a;">1.0 Hour</span>
                        </div>
                        <div class="calc-row">
                            <span style="font-size:15px;font-weight:800;color:#0f172a;">Total Remuneration:</span>
                            <span id="previewAmount" style="font-size:19px;font-weight:800;color:#047857;">&#8377;<?= number_format($facTheoryRate, 2) ?></span>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit" id="submitBtn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        Save Lecture Session &amp; Submit Attendance
                    </button>
                </form>
            </div>
        <?php endif; ?>
        </div>
    </div>

    <script>
    const theoryRate = <?= $facTheoryRate ?>;
    const practicalRate = <?= $facPracticalRate ?>;

    function calculateRemuneration() {
        const select = document.getElementById('course_id');
        if (!select) return;
        const selectedOption = select.options[select.selectedIndex];
        const hoursInput = document.getElementById('hours');
        const hours = parseFloat(hoursInput.value) || 0;

        const isPractical = document.getElementById('type_p').checked;
        const currentRate = isPractical ? practicalRate : theoryRate;
        const total = hours * currentRate;

        document.getElementById('previewSessionType').textContent = isPractical ? 'Practical / Lab Class' : 'Theory Lecture';
        document.getElementById('previewRate').textContent = '₹' + currentRate.toFixed(2) + ' / hr';
        document.getElementById('previewHours').textContent = hours.toFixed(1) + ' Hours';
        document.getElementById('previewAmount').innerHTML = '&#8377;' + total.toFixed(2);

        if (selectedOption && selectedOption.value) {
            document.getElementById('previewLtp').textContent = selectedOption.getAttribute('data-ltp') || '—';
        } else {
            document.getElementById('previewLtp').textContent = '—';
        }
    }

    function loadCourseStudents(courseId) {
        if (!courseId) return;

        const loading = document.getElementById('attLoading');
        const empty = document.getElementById('attEmpty');
        const table = document.getElementById('attTable');
        const tbody = document.getElementById('attTbody');
        const cohortInfo = document.getElementById('attCohortInfo');

        loading.style.display = 'block';
        empty.style.display = 'none';
        table.style.display = 'none';
        tbody.innerHTML = '';

        fetch('<?= BASE_URL ?>/api/get_students.php?course_id=' + encodeURIComponent(courseId))
            .then(res => res.json())
            .then(data => {
                loading.style.display = 'none';
                if (data.success && data.students && data.students.length > 0) {
                    cohortInfo.textContent = `${data.program_name} • ${data.semester} (${data.batch_year || ''}) • ${data.total_students} Enrolled`;

                    data.students.forEach((st, idx) => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td style="color:#94a3b8;font-size:12px;">${idx + 1}</td>
                            <td><span class="roll-badge">${escapeHtml(st.roll_no)}</span></td>
                            <td><strong style="color:#0f172a;font-size:14px;">${escapeHtml(st.student_name)}</strong></td>
                            <td><span style="font-family:monospace;font-size:12px;color:#475569;background:#f8fafc;padding:2px 7px;border-radius:5px;border:1px solid #e2e8f0;">${escapeHtml(st.enrollment_no || '—')}</span></td>
                            <td style="text-align:center;">
                                <div class="att-toggle-group">
                                    <input type="radio" name="attendance[${st.id}]" id="att_${st.id}_p" value="present" checked onchange="updateAttendanceSummary()">
                                    <label for="att_${st.id}_p">Present</label>
                                    <input type="radio" name="attendance[${st.id}]" id="att_${st.id}_a" value="absent" onchange="updateAttendanceSummary()">
                                    <label for="att_${st.id}_a">Absent</label>
                                </div>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });

                    table.style.display = 'table';
                    updateAttendanceSummary();
                } else {
                    empty.style.display = 'block';
                    cohortInfo.textContent = data.message || 'No enrolled students found.';
                    document.getElementById('attSummaryBadge').textContent = '0 Enrolled';
                }
            })
            .catch(err => {
                loading.style.display = 'none';
                empty.textContent = 'Could not load student roster: ' + err.message;
                empty.style.display = 'block';
            });
    }

    function updateAttendanceSummary() {
        const presentInputs = document.querySelectorAll('input[name^="attendance["][value="present"]:checked');
        const absentInputs = document.querySelectorAll('input[name^="attendance["][value="absent"]:checked');
        const pCount = presentInputs.length;
        const aCount = absentInputs.length;
        document.getElementById('attSummaryBadge').innerHTML = `<span style="color:#16a34a;font-weight:800;">${pCount} Present</span> &bull; <span style="color:#dc2626;font-weight:800;">${aCount} Absent</span>`;
    }

    function markAllAttendance(status) {
        const radios = document.querySelectorAll(`input[name^="attendance["][value="${status}"]`);
        radios.forEach(r => {
            r.checked = true;
        });
        updateAttendanceSummary();
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

    function onCourseSelected() {
        const select = document.getElementById('course_id');
        const selectedOption = select ? select.options[select.selectedIndex] : null;

        if (selectedOption && selectedOption.value) {
            const pHours = parseInt(selectedOption.getAttribute('data-p-hours')) || 0;
            const lHours = parseInt(selectedOption.getAttribute('data-l-hours')) || 0;

            if (pHours > 0 && lHours === 0) {
                document.getElementById('type_p').checked = true;
            } else {
                document.getElementById('type_t').checked = true;
            }
            loadCourseStudents(selectedOption.value);
        }
        calculateRemuneration();
    }

    window.addEventListener('DOMContentLoaded', () => {
        onCourseSelected();
    });
    </script>
</body>
</html>
