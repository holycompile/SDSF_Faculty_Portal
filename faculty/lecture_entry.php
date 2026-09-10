<?php
define('ROOT', dirname(dirname(__FILE__)));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireFaculty();

$facultyId = (int)$_SESSION['faculty_id'];

// Get assigned courses
$stmt = $pdo->prepare("
    SELECT c.* 
    FROM courses c
    JOIN faculty_course_assignments fca ON fca.course_id = c.id
    WHERE fca.faculty_id = ?
    ORDER BY c.program, c.semester, c.subject_name
");
$stmt->execute([$facultyId]);
$courses = $stmt->fetchAll();

$errors = [];
$preselectedCourseId = (int)($_GET['course_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $courseId = (int)($_POST['course_id'] ?? 0);
    $lectureDate = trim($_POST['lecture_date'] ?? '');
    $hours = (float)($_POST['hours'] ?? 0);

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
    } elseif (strtotime($lectureDate) > time()) {
        $errors[] = 'Lecture date cannot be in the future.';
    }
    if ($hours <= 0 || $hours > 12) {
        $errors[] = 'Please enter a valid duration between 0.5 and 12 hours.';
    }

    if (empty($errors)) {
        // Business rules for rates: Theory = 800, Practical = 400
        $rate = ($matchedCourse['class_type'] === 'T') ? THEORY_RATE : PRACTICAL_RATE;
        $amount = round($hours * $rate, 2);

        $ins = $pdo->prepare("
            INSERT INTO lecture_entries (faculty_id, course_id, lecture_date, hours, rate_per_hour, amount)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$facultyId, $courseId, $lectureDate, $hours, $rate, $amount]);

        // Calculate monthly total to check 30,000 threshold
        $month = (int)date('m', strtotime($lectureDate));
        $year  = (int)date('Y', strtotime($lectureDate));
        $mStmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM lecture_entries
            WHERE faculty_id = ? AND MONTH(lecture_date) = ? AND YEAR(lecture_date) = ?
        ");
        $mStmt->execute([$facultyId, $month, $year]);
        $monthTotal = (float)$mStmt->fetchColumn();

        if ($monthTotal > 30000) {
            setFlash('warning', "Lecture logged successfully (Earned: Rs. {$amount})! Note: Your total remuneration for " . date('F Y', strtotime($lectureDate)) . " is now Rs. " . number_format($monthTotal, 2) . ", which exceeds the Rs. 30,000 monthly ceiling.");
        } else {
            setFlash('success', "Lecture session logged successfully! Calculated remuneration: Rs. " . number_format($amount, 2));
        }

        header('Location: ' . BASE_URL . '/faculty/dashboard.php');
        exit;
    }
}

$active_nav = 'lecture_entry';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Lecture — SDSF Faculty Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
    <style>
        .card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 20px; padding: 36px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.03), 0 2px 4px -2px rgba(0,0,0,0.03); }
        .form-group { margin-bottom: 22px; }
        .form-label { display: block; font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #475569; margin-bottom: 8px; }
        .form-input, .form-select { width: 100%; padding: 12px 16px; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px; color: #0f172a; font-size: 15px; font-family: 'Inter', sans-serif; transition: all .2s; outline: none; }
        .form-input:focus, .form-select:focus { background: #fff; border-color: #1e3a8a; box-shadow: 0 0 0 3px rgba(30,58,138,.12); }
        .calc-box { background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 16px; padding: 20px 24px; margin: 24px 0; }
        .calc-row { display: flex; align-items: center; justify-content: space-between; font-size: 14px; margin-bottom: 8px; }
        .calc-row:last-child { margin-bottom: 0; padding-top: 10px; border-top: 1px dashed #bfdbfe; }
        .btn-submit { width: 100%; padding: 14px; background: #1e3a8a; border: none; border-radius: 12px; color: #fff; font-size: 15px; font-weight: 700; font-family: 'Inter', sans-serif; cursor: pointer; transition: all .2s; box-shadow: 0 4px 14px rgba(30,58,138,0.3); }
        .btn-submit:hover { background: #172554; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(30,58,138,0.4); }
        .alert-error { background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 14px 18px; margin-bottom: 24px; color: #ef4444; font-size: 14px; }
        .btn-outline-teal { background: #fff; color: #1e3a8a; border: 1.5px solid #bfdbfe; padding: 7px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    </style>
    <?php require_once ROOT . '/includes/faculty_sidebar.php'; ?>
</head>
<body>
    <header class="topbar">
        <div class="tb-left">
            <span class="tb-crumb">Log Conducted Lecture</span>
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
            <div style="margin-bottom: 28px;">
                <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0 0 6px;">Log Conducted Lecture</h1>
                <p style="font-size: 14.5px; color: #64748b; margin: 0;">Submit session details for automated remuneration billing under SDSF DAVV regulations.</p>
            </div>

        <?php if (!empty($errors)): ?>
            <div class="alert-error">
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
                <a href="<?= BASE_URL ?>/faculty/dashboard.php" class="btn-outline-teal" style="margin-top:12px;">Return to Dashboard</a>
            </div>
        <?php else: ?>
            <div class="card">
                <form method="POST" id="lectureForm">
                    <div class="form-group">
                        <label class="form-label" for="course_id">Course / Subject (From Assigned Courses)</label>
                        <select name="course_id" id="course_id" class="form-select" required onchange="calculateRemuneration()">
                            <option value="">-- Choose Assigned Course --</option>
                            <?php foreach ($courses as $c): 
                                $rateVal = ($c['class_type'] === 'T') ? 800 : 400;
                                $typeTxt = ($c['class_type'] === 'T') ? 'Theory (Rs. 800/hr)' : 'Practical (Rs. 400/hr)';
                                $selected = ($preselectedCourseId == $c['id']) ? 'selected' : '';
                            ?>
                                <option value="<?= $c['id'] ?>" 
                                        data-type="<?= $c['class_type'] ?>" 
                                        data-rate="<?= $rateVal ?>"
                                        data-name="<?= htmlspecialchars($c['subject_name']) ?>"
                                        <?= $selected ?>>
                                    <?= htmlspecialchars($c['program']) ?> (Sem <?= htmlspecialchars($c['semester']) ?>) &bull; <?= htmlspecialchars($c['subject_name']) ?> [<?= htmlspecialchars($c['course_code']) ?>] — <?= $typeTxt ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                        <div class="form-group">
                            <label class="form-label" for="lecture_date">Lecture Date</label>
                            <input type="date" name="lecture_date" id="lecture_date" class="form-input" 
                                   max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="hours">Duration (in Hours)</label>
                            <input type="number" step="0.5" min="0.5" max="12" name="hours" id="hours" class="form-input" 
                                   value="1.0" required oninput="calculateRemuneration()">
                            <div style="font-size:12px;color:#94a3b8;margin-top:4px;">e.g. 1.0, 1.5, 2.0 hrs</div>
                        </div>
                    </div>

                    <!-- Live Calculation Preview -->
                    <div class="calc-box">
                        <div style="font-size:12px;font-weight:700;color:#0d9488;text-transform:uppercase;margin-bottom:12px;display:flex;align-items:center;gap:6px;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1z"/><line x1="8" y1="8" x2="16" y2="8"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="16" x2="12" y2="16"/></svg>
                            Automated Remuneration Calculation
                        </div>
                        <div class="calc-row">
                            <span style="color:#64748b;">Class Category:</span>
                            <span id="previewCategory" style="font-weight:600;color:#0f172a;">Please select course</span>
                        </div>
                        <div class="calc-row">
                            <span style="color:#64748b;">Prescribed Hourly Rate:</span>
                            <span id="previewRate" style="font-weight:600;color:#0f172a;">—</span>
                        </div>
                        <div class="calc-row">
                            <span style="color:#64748b;">Duration:</span>
                            <span id="previewHours" style="font-weight:600;color:#0f172a;">1.0 Hour</span>
                        </div>
                        <div class="calc-row">
                            <span style="font-size:15px;font-weight:700;color:#0f172a;">Calculated Amount Payable:</span>
                            <span id="previewAmount" style="font-size:18px;font-weight:800;color:#047857;">&#8377;0.00</span>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">
                        Submit & Save Lecture Session
                    </button>
                </form>
            </div>
        <?php endif; ?>
        </div>
    </div>
</div>

    <script>
    function calculateRemuneration() {
        const select = document.getElementById('course_id');
        const selectedOption = select.options[select.selectedIndex];
        const hoursInput = document.getElementById('hours');
        const hours = parseFloat(hoursInput.value) || 0;

        if (!selectedOption || !selectedOption.value) {
            document.getElementById('previewCategory').textContent = 'Please select course';
            document.getElementById('previewRate').textContent = '—';
            document.getElementById('previewAmount').innerHTML = '&#8377;0.00';
            return;
        }

        const type = selectedOption.getAttribute('data-type');
        const rate = parseFloat(selectedOption.getAttribute('data-rate')) || 0;
        const total = hours * rate;

        document.getElementById('previewCategory').textContent = (type === 'T') ? 'Theory Class (T)' : 'Practical / Lab Class (P)';
        document.getElementById('previewRate').textContent = 'Rs. ' + rate.toFixed(2) + ' / hr';
        document.getElementById('previewHours').textContent = hours.toFixed(1) + ' Hours';
        document.getElementById('previewAmount').innerHTML = '&#8377;' + total.toFixed(2);
    }

    // Run on page load in case course is preselected
    window.addEventListener('DOMContentLoaded', calculateRemuneration);
    </script>
</body>
</html>
