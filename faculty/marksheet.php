<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
requireFaculty();

$facultyId = (int)($_SESSION['faculty_id'] ?? 0);
$active_nav = 'marksheet';

$courseSql = "
    SELECT c.id, c.program_id, c.subject_name, c.course_code, c.program, c.semester, c.semester_number, c.class_type
    FROM courses c
    JOIN faculty_course_assignments fca ON fca.course_id = c.id
    WHERE fca.faculty_id = :faculty_id
    ORDER BY c.program, CAST(c.semester_number AS UNSIGNED), c.semester, c.subject_name
";
$courseStmt = $pdo->prepare($courseSql);
$courseStmt->execute([':faculty_id' => $facultyId]);
$courseOptions = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedCourseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : (int)($courseOptions[0]['id'] ?? 0);
if ($selectedCourseId <= 0 && !empty($courseOptions)) {
    $selectedCourseId = (int)$courseOptions[0]['id'];
}

$selectedCourse = null;
foreach ($courseOptions as $course) {
    if ((int)$course['id'] === $selectedCourseId) {
        $selectedCourse = $course;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedCourseId = (int)($_POST['course_id'] ?? $selectedCourseId);
    $studentMarks = $_POST['marks'] ?? [];
    $errors = [];

    foreach ($studentMarks as $studentId => $values) {
        $studentId = (int)$studentId;
        $ct1 = isset($values['class_test_1']) ? trim((string)$values['class_test_1']) : '';
        $ct2 = isset($values['class_test_2']) ? trim((string)$values['class_test_2']) : '';
        $ct3 = isset($values['class_test_3']) ? trim((string)$values['class_test_3']) : '';
        $best = isset($values['best_of_2']) ? trim((string)$values['best_of_2']) : '';
        $final = isset($values['final_sem_marks']) ? trim((string)$values['final_sem_marks']) : '';

        $parsed = [
            'class_test_1' => ($ct1 === '' ? null : (float)$ct1),
            'class_test_2' => ($ct2 === '' ? null : (float)$ct2),
            'class_test_3' => ($ct3 === '' ? null : (float)$ct3),
            'best_of_2' => ($best === '' ? null : (float)$best),
            'final_sem_marks' => ($final === '' ? null : (float)$final),
        ];

        foreach ($parsed as $key => $value) {
            if ($value !== null && (!is_numeric((string)$value) || $value < 0 || $value > 100)) {
                $errors[] = 'Each mark must be between 0 and 100.';
                continue 2;
            }
        }

        if ($parsed['best_of_2'] === null) {
            $scores = array_filter([$parsed['class_test_1'], $parsed['class_test_2'], $parsed['class_test_3']], fn($v) => $v !== null);
            if (!empty($scores)) {
                rsort($scores);
                $bestScore = count($scores) >= 2 ? (array_sum(array_slice($scores, 0, 2)) / 2) : $scores[0];
                $parsed['best_of_2'] = round((float)$bestScore, 2);
            }
        }

        $upsert = $pdo->prepare("
            INSERT INTO student_marksheets (teacher_id, course_id, student_id, class_test_1, class_test_2, class_test_3, best_of_2, final_sem_marks)
            VALUES (:teacher_id, :course_id, :student_id, :ct1, :ct2, :ct3, :best, :final)
            ON DUPLICATE KEY UPDATE
                class_test_1 = VALUES(class_test_1),
                class_test_2 = VALUES(class_test_2),
                class_test_3 = VALUES(class_test_3),
                best_of_2 = VALUES(best_of_2),
                final_sem_marks = VALUES(final_sem_marks),
                updated_at = CURRENT_TIMESTAMP
        ");

        $upsert->execute([
            ':teacher_id' => $facultyId,
            ':course_id' => $selectedCourseId,
            ':student_id' => $studentId,
            ':ct1' => $parsed['class_test_1'],
            ':ct2' => $parsed['class_test_2'],
            ':ct3' => $parsed['class_test_3'],
            ':best' => $parsed['best_of_2'],
            ':final' => $parsed['final_sem_marks'],
        ]);
    }

    if (empty($errors)) {
        setFlash('success', 'Marksheet updated successfully.');
    } else {
        setFlash('error', implode(' ', $errors));
    }

    header('Location: ' . BASE_URL . '/faculty/marksheet.php?course_id=' . $selectedCourseId);
    exit;
}

if (empty($courseOptions)) {
    $selectedCourse = null;
} elseif ($selectedCourse === null) {
    $selectedCourse = $courseOptions[0];
}

$students = [];
if ($selectedCourse !== null) {
    $studentStmt = $pdo->prepare("
        SELECT s.id, s.roll_no, s.student_name, s.enrollment_no, s.current_semester,
               mm.class_test_1, mm.class_test_2, mm.class_test_3, mm.best_of_2, mm.final_sem_marks
        FROM students s
        LEFT JOIN student_marksheets mm
          ON mm.student_id = s.id
         AND mm.teacher_id = :teacher_id
         AND mm.course_id = :course_id_join
        WHERE (
                s.course_id = :course_id_filter
                OR (
                    s.program_id = :program_id
                    AND (s.current_semester = :semester_label OR s.current_semester = :semester_number)
                )
              )
          AND s.status = 'active'
        ORDER BY CAST(s.roll_no AS UNSIGNED), s.roll_no, s.student_name
    ");
    $studentStmt->execute([
        ':teacher_id' => $facultyId,
        ':course_id_join' => (int)$selectedCourse['id'],
        ':course_id_filter' => (int)$selectedCourse['id'],
        ':program_id' => (int)$selectedCourse['program_id'],
        ':semester_label' => (string)$selectedCourse['semester'],
        ':semester_number' => (string)$selectedCourse['semester_number'],
    ]);
    $students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marksheet — Faculty Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
    <?php require_once dirname(__DIR__) . '/includes/faculty_sidebar.php'; ?>
    <style>
        .marksheet-wrap { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; box-shadow: 0 1px 4px rgba(15,23,42,0.04); }
        .marksheet-head { padding: 22px 22px 18px; border-bottom: 1px solid #eef2ff; background: linear-gradient(135deg, #f8fbff, #ffffff); }
        .marksheet-header-grid { display: grid; grid-template-columns: 1.4fr 1fr auto; gap: 16px; align-items: end; }
        .meta-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 14px; }
        .meta-label { color: #64748b; font-size: 11px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; margin-bottom: 4px; }
        .meta-value { color: #0f172a; font-size: 15px; font-weight: 700; }
        .marksheet-table { width: 100%; border-collapse: collapse; }
        .marksheet-table th { padding: 12px 10px; background: #f8fafc; color: #475569; font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; }
        .marksheet-table td { padding: 10px 8px; border-bottom: 1px solid #f1f5f9; font-size: 14px; color: #1e293b; }
        .marksheet-table tbody tr:hover { background: #f8fbff; }
        .roll-pill { display: inline-flex; align-items: center; justify-content: center; min-width: 52px; height: 30px; border-radius: 8px; background: #eef2ff; color: #4338ca; font-weight: 800; font-family: monospace; }
        .marks-input { width: 100%; max-width: 90px; padding: 9px 8px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; font-weight: 600; color: #0f172a; text-align: center; }
        .marks-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.12); }
        @media print {
            .sidebar, .sidebar-overlay, .topbar, .no-print, .page-header, .btn, .sidebar-toggle-btn { display: none !important; }
            .main { margin-left: 0 !important; }
            body { background: #fff; }
            .page { padding: 0 !important; }
            .marksheet-wrap { border: none; box-shadow: none; }
            .marksheet-head { display: none; }
            .print-sheet-header { display: block; text-align: center; margin: 0 0 12px; color: #111827; }
            .print-sheet-header .university { font-size: 12px; }
            .print-sheet-header .department { font-size: 15px; font-weight: 800; letter-spacing: .04em; }
            .print-sheet-header .document-title { font-size: 13px; font-weight: 700; margin-top: 4px; }
            .print-sheet-header .subject-line { font-size: 12px; margin-top: 6px; }
            .marks-input { border: none; background: transparent; padding: 0; max-width: 100%; }
        }
        .print-sheet-header { display: none; }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="tb-left">
            <button type="button" class="sidebar-toggle-btn" onclick="toggleSidebar(true)" aria-label="Open navigation menu" style="display:inline-flex;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
            </button>
            <span class="tb-crumb">Marksheet</span>
        </div>
        <div class="tb-right">
            <span class="tb-date"><?= date('l, d F Y') ?></span>
        </div>
    </header>

    <div class="page">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] ?>">
                <?= htmlspecialchars($flash['msg']) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($courseOptions)): ?>
            <div class="card" style="padding:28px;">
                <div class="card-title" style="margin-bottom:10px;">No assigned subject found</div>
                <div class="card-sub">You do not have any subjects assigned. Please contact the admin to assign a course before accessing the marksheet module.</div>
            </div>
        <?php else: ?>
            <div class="marksheet-wrap">
                <div class="marksheet-head">
                    <div class="marksheet-header-grid">
                        <div>
                            <div style="font-size:11px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#2563eb;margin-bottom:8px;">Faculty Marksheet</div>
                            <h1 style="margin:0;color:#0f172a;font-size:24px;font-weight:800;">
                                <?= htmlspecialchars($selectedCourse['subject_name'] ?? 'Subject') ?>
                            </h1>
                            <div style="margin-top:8px;color:#64748b;font-size:13px;">
                                <?= htmlspecialchars($selectedCourse['program'] ?? '') ?> · Semester <?= htmlspecialchars($selectedCourse['semester'] ?? '') ?> · <?= htmlspecialchars($selectedCourse['course_code'] ?? '') ?>
                            </div>
                        </div>
                        <form method="get" action="<?= BASE_URL ?>/faculty/marksheet.php" style="display:flex;gap:10px;align-items:end;margin:0;">
                            <div style="min-width:260px;">
                                <label for="courseSelect" style="display:block;font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#64748b;margin-bottom:6px;">Select Subject</label>
                                <select id="courseSelect" name="course_id" class="form-select" style="width:100%;padding:11px 12px;border-radius:10px;border:1px solid #cbd5e1;background:#fff;">
                                    <?php foreach ($courseOptions as $course): ?>
                                        <option value="<?= (int)$course['id'] ?>" <?= ((int)$course['id'] === (int)$selectedCourseId) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($course['subject_name']) ?> (<?= htmlspecialchars($course['course_code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary no-print">Load</button>
                        </form>
                        <div class="no-print" style="display:flex;gap:10px;justify-content:flex-end;">
                            <button type="button" class="btn btn-outline" onclick="window.print()">Print / Save as PDF</button>
                        </div>
                    </div>
                </div>

                <div style="padding:20px 22px 10px;">
                    <form method="post" action="<?= BASE_URL ?>/faculty/marksheet.php">
                        <input type="hidden" name="course_id" value="<?= (int)$selectedCourseId ?>">
                        <div class="print-sheet-header">
                            <div class="university">Devi Ahilya Vishwavidyalaya, Indore</div>
                            <div class="department">SCHOOL OF DATA SCIENCE AND FORECASTING</div>
                            <div class="document-title">Student Marksheet</div>
                            <div class="subject-line">
                                Subject: <?= htmlspecialchars($selectedCourse['subject_name'] ?? '') ?>
                                &nbsp;&bull;&nbsp;
                                Code: <?= htmlspecialchars($selectedCourse['course_code'] ?? '') ?>
                                &nbsp;&bull;&nbsp;
                                <?= htmlspecialchars($selectedCourse['semester'] ?? '') ?>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="marksheet-table">
                                <thead>
                                    <tr>
                                        <th style="width:70px;">Roll No.</th>
                                        <th style="width:240px;">Student Name</th>
                                        <th>Class Test 1</th>
                                        <th>Class Test 2</th>
                                        <th>Class Test 3</th>
                                        <th>Best of 2</th>
                                        <th>Final Sem Marks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($students)): ?>
                                        <tr>
                                            <td colspan="7" style="padding:20px;text-align:center;color:#64748b;">No students found for this subject yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($students as $student): ?>
                                            <tr>
                                                <td><span class="roll-pill"><?= htmlspecialchars($student['roll_no'] ?? '') ?></span></td>
                                                <td>
                                                    <div style="font-weight:700; color:#0f172a;">
                                                        <?= htmlspecialchars($student['student_name'] ?? '') ?>
                                                    </div>
                                                    <div style="font-size:11px;color:#64748b;">
                                                        <?= htmlspecialchars($student['enrollment_no'] ?? '') ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <input class="marks-input ct-input" data-row-id="<?= (int)$student['id'] ?>" name="marks[<?= (int)$student['id'] ?>][class_test_1]" value="<?= htmlspecialchars((string)($student['class_test_1'] ?? '')) ?>" inputmode="decimal" type="number" min="0" max="100" step="0.01" aria-label="Class Test 1 for <?= htmlspecialchars($student['student_name'] ?? '') ?>">
                                                </td>
                                                <td>
                                                    <input class="marks-input ct-input" data-row-id="<?= (int)$student['id'] ?>" name="marks[<?= (int)$student['id'] ?>][class_test_2]" value="<?= htmlspecialchars((string)($student['class_test_2'] ?? '')) ?>" inputmode="decimal" type="number" min="0" max="100" step="0.01" aria-label="Class Test 2 for <?= htmlspecialchars($student['student_name'] ?? '') ?>">
                                                </td>
                                                <td>
                                                    <input class="marks-input ct-input" data-row-id="<?= (int)$student['id'] ?>" name="marks[<?= (int)$student['id'] ?>][class_test_3]" value="<?= htmlspecialchars((string)($student['class_test_3'] ?? '')) ?>" inputmode="decimal" type="number" min="0" max="100" step="0.01" aria-label="Class Test 3 for <?= htmlspecialchars($student['student_name'] ?? '') ?>">
                                                </td>
                                                <td>
                                                    <input class="marks-input best-of-2" data-row-id="<?= (int)$student['id'] ?>" name="marks[<?= (int)$student['id'] ?>][best_of_2]" value="<?= htmlspecialchars((string)($student['best_of_2'] ?? '')) ?>" inputmode="decimal" type="number" min="0" max="100" step="0.01" aria-label="Best of 2 for <?= htmlspecialchars($student['student_name'] ?? '') ?>">
                                                </td>
                                                <td>
                                                    <input class="marks-input final-sem" name="marks[<?= (int)$student['id'] ?>][final_sem_marks]" value="<?= htmlspecialchars((string)($student['final_sem_marks'] ?? '')) ?>" inputmode="decimal" type="number" min="0" max="100" step="0.01" aria-label="Final Semester Marks for <?= htmlspecialchars($student['student_name'] ?? '') ?>">
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (!empty($students)): ?>
                            <div class="no-print" style="display:flex;justify-content:flex-end;gap:10px;padding:18px 0 6px;">
                                <button type="button" class="btn btn-outline" onclick="window.print()">Print / Save as PDF</button>
                                <button type="submit" class="btn btn-primary">Save Marksheet</button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const rows = document.querySelectorAll('.ct-input');
            rows.forEach(function (input) {
                input.addEventListener('input', function () {
                    const rowId = input.dataset.rowId;
                    const row = document.querySelectorAll('[data-row-id="' + rowId + '"]');
                    const values = [];
                    row.forEach(function (element) {
                        if (element.classList.contains('ct-input')) {
                            const val = parseFloat(element.value);
                            if (!isNaN(val)) values.push(val);
                        }
                    });
                    if (values.length > 0) {
                        values.sort(function (a, b) { return b - a; });
                        const topTwo = values.slice(0, Math.min(2, values.length));
                        const average = topTwo.reduce(function (sum, val) { return sum + val; }, 0) / topTwo.length;
                        const bestInput = document.querySelector('.best-of-2[data-row-id="' + rowId + '"]');
                        if (bestInput) {
                            bestInput.value = Number.isFinite(average) ? average.toFixed(2) : '';
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>
