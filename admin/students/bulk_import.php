<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$programs = $pdo->query("SELECT * FROM academic_programs ORDER BY id ASC")->fetchAll();

$semTagsAll = $pdo->query("SELECT program_id, semester_number, year_tag FROM semester_tags")->fetchAll();
$semTagMap = [];
foreach ($semTagsAll as $st) {
    $semTagMap[$st['program_id'] . '_' . $st['semester_number']] = $st['year_tag'];
}

$errors = [];
$successMsg = '';
$preProgramId = (int)($_GET['program_id'] ?? ($programs[0]['id'] ?? 0));
$preSemester = trim($_GET['semester'] ?? '1st Semester');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $programId = (int)($_POST['program_id'] ?? 0);
    $semester  = trim($_POST['current_semester'] ?? '');
    $rawText   = trim($_POST['roster_data'] ?? '');

    if ($programId <= 0) {
        $errors[] = 'Please select a program.';
    }
    if (empty($semester)) {
        $errors[] = 'Please select a semester.';
    }
    if (empty($rawText)) {
        $errors[] = 'Please enter or paste student roster data.';
    }

    if (empty($errors)) {
        $pStmt = $pdo->prepare("SELECT program_name FROM academic_programs WHERE id = ?");
        $pStmt->execute([$programId]);
        $progData = $pStmt->fetch();

        // Look up batch year from semester_tags
        $semNum = (int)filter_var($semester, FILTER_SANITIZE_NUMBER_INT);
        $tagStmt = $pdo->prepare("SELECT year_tag FROM semester_tags WHERE program_id = ? AND semester_number = ?");
        $tagStmt->execute([$programId, $semNum]);
        $batchYear = $tagStmt->fetchColumn() ?: '2025-2027';

        $lines = preg_split('/\r\n|\r|\n/', $rawText);
        $importedCount = 0;
        $skippedCount = 0;

        $ins = $pdo->prepare("
            INSERT INTO students (program_id, batch_year, current_semester, roll_no, enrollment_no, student_name, status)
            VALUES (?, ?, ?, ?, ?, ?, 'active')
        ");

        $dedTbl = getCohortStudentTable($progData['program_name'], $semester);
        $dedIns = null;
        try {
            $chk = $pdo->query("SHOW TABLES LIKE '{$dedTbl}'")->fetchColumn();
            if ($chk) {
                $dedIns = $pdo->prepare("
                    INSERT INTO `{$dedTbl}` (roll_no, student_name, enrollment_no, batch_year, status)
                    VALUES (?, ?, ?, ?, 'active')
                    ON DUPLICATE KEY UPDATE 
                        student_name = VALUES(student_name), 
                        enrollment_no = VALUES(enrollment_no), 
                        batch_year = VALUES(batch_year),
                        status = VALUES(status)
                ");
            }
        } catch (Exception $e) {}

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Split by comma or tab or semicolon
            $parts = preg_split('/[\t,;]+/', $line);
            $parts = array_map('trim', $parts);

            if (count($parts) >= 3) {
                $rollNo = $parts[0];
                $enrollNo = $parts[1];
                $name = $parts[2];
            } elseif (count($parts) === 2) {
                $rollNo = $parts[0];
                $enrollNo = '';
                $name = $parts[1];
            } else {
                $skippedCount++;
                continue;
            }

            if (!empty($name) && !empty($rollNo)) {
                try {
                    $ins->execute([$programId, $batchYear, $semester, $rollNo, $enrollNo, $name]);
                    if ($dedIns) {
                        try {
                            $dedIns->execute([$rollNo, $name, $enrollNo, $batchYear]);
                        } catch (Exception $e) {}
                    }
                    $importedCount++;
                } catch (PDOException $e) {
                    $skippedCount++;
                }
            } else {
                $skippedCount++;
            }
        }

        if ($importedCount > 0) {
            setFlash('success', "Successfully imported <strong>{$importedCount}</strong> students into {$progData['program_name']} ({$semester})!" . ($skippedCount > 0 ? " ({$skippedCount} invalid rows skipped)" : ""));
            header('Location: ' . BASE_URL . '/admin/students/index.php?program=' . urlencode($progData['program_name']) . '&semester=' . urlencode($semester));
            exit;
        } else {
            $errors[] = "No valid student rows could be imported. Please verify the format.";
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
<title>Bulk Import Students — SDSF Admin</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
</head>
<body>
    <header class="topbar">
        <div class="tb-left">
            <span class="tb-crumb">Students</span>
            <span class="tb-sep">/</span>
            <span class="tb-crumb">Bulk Import</span>
        </div>
        <div class="tb-right">
            <a href="<?= BASE_URL ?>/admin/students/index.php" class="btn btn-outline btn-sm">
                &larr; Back to Rosters
            </a>
        </div>
    </header>

    <div class="page">
        <div style="max-width:800px;margin:0 auto;">
            <div class="page-header">
                <h1>Bulk Import Cohort Roster</h1>
                <p>Paste multiple student records directly from Excel, CSV, or text to quickly enroll an entire class.</p>
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
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label" for="program_id">Target Academic Program</label>
                            <select name="program_id" id="program_id" class="form-select" required onchange="updateSemesterDropdown()">
                                <?php foreach ($programs as $p): ?>
                                    <option value="<?= $p['id'] ?>"
                                            data-semesters="<?= $p['total_semesters'] ?>"
                                            <?= ($preProgramId == $p['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['program_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="current_semester">Target Semester</label>
                            <select name="current_semester" id="current_semester" class="form-select" required>
                                <!-- Populated dynamically -->
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:10px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                            <label class="form-label" for="roster_data" style="margin-bottom:0;">Student Roster Data (One row per student)</label>
                            <span style="font-size:12px;color:#4f46e5;font-weight:600;">Format: Roll No, Enrollment No, Full Name</span>
                        </div>
                        <textarea name="roster_data" id="roster_data" class="form-textarea" rows="12" style="font-family:monospace;font-size:13.5px;padding:14px;" placeholder="01, DS220101, Aarav Sharma&#10;02, DS220102, Diya Patel&#10;03, DS220103, Arjun Joshi" required><?= htmlspecialchars($_POST['roster_data'] ?? '') ?></textarea>
                        <div style="font-size:12px;color:#64748b;margin-top:6px;line-height:1.5;">
                            Tip: You can copy 3 columns directly from Excel or Google Sheets (<strong>Roll Number</strong> &bull; <strong>Enrollment Number</strong> &bull; <strong>Student Name</strong>) and paste them here.
                        </div>
                    </div>

                    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:24px;padding-top:20px;border-top:1px solid #f1f5f9;">
                        <a href="<?= BASE_URL ?>/admin/students/index.php" class="btn btn-outline">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            Import Students into Semester
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    const preselectedSemester = <?= json_encode($preSemester) ?>;
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
            if (semStr === preselectedSemester || i === 1) {
                opt.selected = true;
            }
            semSelect.appendChild(opt);
        }
    }

    window.addEventListener('DOMContentLoaded', updateSemesterDropdown);
    </script>
</body>
</html>
