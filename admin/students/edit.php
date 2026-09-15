<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . BASE_URL . '/admin/students/index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student) {
    setFlash('error', 'Student not found.');
    header('Location: ' . BASE_URL . '/admin/students/index.php');
    exit;
}

$programs = $pdo->query("SELECT * FROM academic_programs ORDER BY id ASC")->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $programId   = (int)($_POST['program_id'] ?? 0);
    $semester    = trim($_POST['current_semester'] ?? '');
    $rollNo      = trim($_POST['roll_no'] ?? '');
    $enrollNo    = trim($_POST['enrollment_no'] ?? '');
    $name        = trim($_POST['student_name'] ?? '');
    $status      = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if ($programId <= 0) {
        $errors[] = 'Please select a valid academic program.';
    }
    if (empty($semester)) {
        $errors[] = 'Please select the student semester.';
    }
    if (empty($rollNo)) {
        $errors[] = 'Please provide a roll number.';
    }
    if (empty($name)) {
        $errors[] = 'Please provide the student name.';
    }

    if (empty($errors)) {
        $pStmt = $pdo->prepare("SELECT program_name FROM academic_programs WHERE id = ?");
        $pStmt->execute([$programId]);
        $progData = $pStmt->fetch();

        // Look up batch year from semester_tags
        $semNum = (int)filter_var($semester, FILTER_SANITIZE_NUMBER_INT);
        $tagStmt = $pdo->prepare("SELECT year_tag FROM semester_tags WHERE program_id = ? AND semester_number = ?");
        $tagStmt->execute([$programId, $semNum]);
        $batchYear = $tagStmt->fetchColumn() ?: ($student['batch_year'] ?? '2025-2027');

        try {
            $upd = $pdo->prepare("
                UPDATE students 
                SET program_id = ?, batch_year = ?, current_semester = ?, roll_no = ?, enrollment_no = ?, student_name = ?, status = ?
                WHERE id = ?
            ");
            $upd->execute([$programId, $batchYear, $semester, $rollNo, $enrollNo, $name, $status, $id]);

            // Also update dedicated physical table
            $dedTbl = getCohortStudentTable($progData['program_name'], $semester);
            try {
                $dUpd = $pdo->prepare("
                    UPDATE `{$dedTbl}`
                    SET student_name = ?, roll_no = ?, enrollment_no = ?, batch_year = ?, status = ?
                    WHERE roll_no = ?
                ");
                $dUpd->execute([$name, $rollNo, $enrollNo, $batchYear, $status, $student['roll_no']]);
            } catch (Exception $e) {}

            setFlash('success', "Student <strong>" . htmlspecialchars($name) . "</strong> updated successfully!");
            header('Location: ' . BASE_URL . '/admin/students/index.php?program=' . urlencode($progData['program_name']) . '&semester=' . urlencode($semester));
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Failed to update student: ' . $e->getMessage();
        }
    }
}

$active_nav = 'students-list';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Student — SDSF Admin</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
</head>
<body>
    <header class="topbar">
        <div class="tb-left">
            <span class="tb-crumb">Students</span>
            <span class="tb-sep">/</span>
            <span class="tb-crumb">Edit Student</span>
        </div>
        <div class="tb-right">
            <a href="<?= BASE_URL ?>/admin/students/index.php" class="btn btn-outline btn-sm">
                &larr; Back to Rosters
            </a>
        </div>
    </header>

    <div class="page">
        <div style="max-width:680px;margin:0 auto;">
            <div class="page-header">
                <h1>Edit Student Record</h1>
                <p>Update enrollment details, semester level, and profile information.</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error fade-up">
                    <ul style="margin:0;padding-left:20px;">
                        <?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="card fade-up">
                <form method="POST" style="padding:28px;">
                    <div class="form-group">
                        <label class="form-label" for="program_id">Academic Program</label>
                        <select name="program_id" id="program_id" class="form-select" required onchange="updateSemesterDropdown()">
                            <?php foreach ($programs as $p): ?>
                                <option value="<?= $p['id'] ?>"
                                        data-semesters="<?= $p['total_semesters'] ?>"
                                        <?= ($student['program_id'] == $p['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['program_name']) ?> (<?= $p['total_semesters'] ?> Semesters)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label" for="current_semester">Semester</label>
                            <select name="current_semester" id="current_semester" class="form-select" required>
                                <!-- Injected dynamically -->
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="status">Enrollment Status</label>
                            <select name="status" id="status" class="form-select">
                                <option value="active" <?= $student['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $student['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="student_name">Student Full Name</label>
                        <input type="text" name="student_name" id="student_name" class="form-input" required value="<?= htmlspecialchars($student['student_name']) ?>">
                    </div>

                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label" for="roll_no">Class Roll Number</label>
                            <input type="text" name="roll_no" id="roll_no" class="form-input" required value="<?= htmlspecialchars($student['roll_no']) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="enrollment_no">University Enrollment No</label>
                            <input type="text" name="enrollment_no" id="enrollment_no" class="form-input" value="<?= htmlspecialchars($student['enrollment_no']) ?>">
                        </div>
                    </div>

                    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:24px;padding-top:20px;border-top:1px solid #f1f5f9;">
                        <a href="<?= BASE_URL ?>/admin/students/index.php" class="btn btn-outline">Cancel</a>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    const currentStudentSem = <?= json_encode($student['current_semester']) ?>;
    const semTagMap = <?= json_encode($semTagMap) ?>;

    function updateSemesterDropdown() {
        const progSelect = document.getElementById('program_id');
        const semSelect = document.getElementById('current_semester');
        const selectedOpt = progSelect.options[progSelect.selectedIndex];
        const totalSems = parseInt(selectedOpt.getAttribute('data-semesters')) || 4;
        const progId = selectedOpt.value;

        semSelect.innerHTML = '';
        for (let i = 1; i <= totalSems; i++) {
            let suf = 'th';
            if (i === 1) suf = 'st';
            else if (i === 2) suf = 'nd';
            else if (i === 3) suf = 'rd';
            const semStr = `${i}${suf} Semester`;

            const tag = semTagMap[progId + '_' + i] || '';
            const opt = document.createElement('option');
            opt.value = semStr;
            opt.textContent = semStr + (tag ? ' (' + tag + ')' : '');
            if (semStr === currentStudentSem) {
                opt.selected = true;
            }
            semSelect.appendChild(opt);
        }
    }

    window.addEventListener('DOMContentLoaded', updateSemesterDropdown);
    </script>
</body>
</html>
