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
$preProgramName = trim($_GET['program'] ?? '');
$preSemester    = trim($_GET['semester'] ?? '');
$preSemNum = 0;
if ($preSemester) {
    preg_match('/\d+/', $preSemester, $sm);
    $preSemNum = isset($sm[0]) ? (int)$sm[0] : 0;
}
$backUrl = BASE_URL . '/admin/courses/list.php' . ($preProgramName ? '?program=' . urlencode($preProgramName) . ($preSemNum ? '&sem=' . $preSemNum . '#sem-' . $preSemNum : '') : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $program_id      = (int)($_POST['program_id']      ?? 0);
    $semester        = trim($_POST['semester']        ?? '');
    $subject_name    = trim($_POST['subject_name']    ?? '');
    $course_code     = trim($_POST['course_code']     ?? '');
    $credits         = max(0, (int)($_POST['credits']         ?? 4));
    $lecture_hours   = max(0, (int)($_POST['lecture_hours']   ?? 3));
    $tutorial_hours  = max(0, (int)($_POST['tutorial_hours']  ?? 0));
    $practical_hours = max(0, (int)($_POST['practical_hours'] ?? 2));
    $ltp_pattern     = "{$credits}({$lecture_hours}-{$tutorial_hours}-{$practical_hours})";
    $class_type      = ($practical_hours > 0 && $lecture_hours == 0) ? 'P' : 'T';

    // Lookup program details
    $matchedProg = null;
    foreach ($programs as $p) {
        if ((int)$p['id'] === $program_id) {
            $matchedProg = $p;
            break;
        }
    }

    if (!$matchedProg) {
        $errors[] = 'Please select a valid academic program.';
    }
    if (!$semester) {
        $errors[] = 'Semester is required.';
    }
    if (!$subject_name) {
        $errors[] = 'Subject name is required.';
    }

    if (empty($errors)) {
        try {
            // Extract integer semester number
            preg_match('/\d+/', $semester, $m);
            $semNumber = isset($m[0]) ? (int)$m[0] : 1;

            $semTagStmt = $pdo->prepare("SELECT year_tag FROM semester_tags WHERE program_id = ? AND semester_number = ?");
            $semTagStmt->execute([$matchedProg['id'], $semNumber]);
            $courseBatchYear = $semTagStmt->fetchColumn() ?: ($matchedProg['batch_year'] ?? '');

            $stmt = $pdo->prepare("
                INSERT INTO courses 
                    (program_id, program, batch_year, semester, semester_number, subject_name, course_code, credits, lecture_hours, tutorial_hours, practical_hours, ltp_pattern, class_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $matchedProg['id'],
                $matchedProg['program_name'],
                $courseBatchYear,
                $semester,
                $semNumber,
                $subject_name,
                $course_code,
                $credits,
                $lecture_hours,
                $tutorial_hours,
                $practical_hours,
                $ltp_pattern,
                $class_type
            ]);

            setFlash('success', "Subject \"{$subject_name}\" with Credits {$ltp_pattern} added to {$matchedProg['program_name']} ({$semester}) successfully!");
            header('Location: ' . BASE_URL . '/admin/courses/list.php?program=' . urlencode($matchedProg['program_name']) . '&sem=' . $semNumber . '#sem-' . $semNumber);
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Failed to add subject: ' . $e->getMessage();
        }
    }
}

$active_nav = 'courses-add';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Subject — SDSF Admin</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
</head>
<body>
<header class="topbar">
    <div class="tb-left">
        <a href="<?= BASE_URL ?>/admin/dashboard.php" style="color:#94a3b8;text-decoration:none;">Dashboard</a>
        <span class="tb-sep">/</span>
        <a href="<?= $backUrl ?>" style="color:#94a3b8;text-decoration:none;">Courses</a>
        <span class="tb-sep">/</span>
        <span class="tb-crumb">Add Subject</span>
    </div>
    <div class="tb-right">
        <a href="<?= $backUrl ?>" class="btn btn-outline btn-sm">&larr; Back to Courses</a>
    </div>
</header>

<div class="page">
    <div class="page-header fade-up">
        <h1>Add New Curriculum Subject</h1>
        <p>Create a subject/paper under a specific academic program and designated semester</p>
    </div>

    <?php foreach ($errors as $e): ?>
    <div class="alert alert-error fade-up">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <?= htmlspecialchars($e) ?>
    </div>
    <?php endforeach; ?>

    <div style="max-width:720px;">
        <div class="card fade-up">
            <div class="card-head">
                <div>
                    <div class="card-title">Subject &amp; Course Information</div>
                    <div class="card-sub">Configure the academic program, semester, and course ID</div>
                </div>
            </div>
            <div style="padding:28px;">
                <form method="POST" action="">
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">Academic Program &amp; Batch *</label>
                            <select name="program_id" id="programSelect" class="form-select" required onchange="updateSemesterDropdown()">
                                <?php foreach ($programs as $prg): ?>
                                    <?php 
                                    $isSel = false;
                                    if (!empty($_POST['program_id'])) {
                                        $isSel = ((int)$_POST['program_id'] === (int)$prg['id']);
                                    } elseif ($preProgramName) {
                                        $isSel = (strcasecmp($prg['program_name'], $preProgramName) === 0);
                                    }
                                    ?>
                                    <option value="<?= $prg['id'] ?>" 
                                            data-semesters="<?= $prg['total_semesters'] ?>" 
                                            <?= $isSel ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($prg['program_name']) ?> (<?= $prg['total_semesters'] ?> Semesters)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div style="font-size:12px;color:#94a3b8;margin-top:5px;">Select which degree course this subject belongs to</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Semester *</label>
                            <select name="semester" id="semesterSelect" class="form-select" required>
                                <!-- Populated dynamically by JS based on selected program -->
                            </select>
                            <div style="font-size:12px;color:#94a3b8;margin-top:5px;" id="semHelper">
                                Semester within this program
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Subject / Paper Name *</label>
                        <input type="text" name="subject_name" class="form-input"
                            placeholder="e.g. Advanced Data Structures, Machine Learning, Business Analytics"
                            value="<?= htmlspecialchars($_POST['subject_name'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Course Code / ID</label>
                        <input type="text" name="course_code" class="form-input"
                            placeholder="e.g. MT-101, BDA-201, FT-110A"
                            value="<?= htmlspecialchars($_POST['course_code'] ?? '') ?>"
                            style="font-family:monospace;font-weight:700;">
                        <div style="font-size:12px;color:#94a3b8;margin-top:5px;">Official course paper code</div>
                    </div>

                    <!-- Credits (L T P) Configuration Section -->
                    <div style="background:#f8fafc;border:1.5px solid #cbd5e1;border-radius:14px;padding:20px;margin-bottom:24px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                            <div>
                                <label class="form-label" style="margin:0;font-size:14px;font-weight:800;color:#0f172a;">Credits &amp; Structure (L T P) *</label>
                                <div style="font-size:12px;color:#64748b;margin-top:2px;">Breakdown: Lecture (L) &bull; Theory/Tutorial (T) &bull; Practical (P)</div>
                            </div>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <span style="font-size:11.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Live Preview:</span>
                                <span id="ltpPreviewBadge" style="font-family:'Segoe UI Mono', SFMono-Regular, Consolas, monospace;font-size:18px;font-weight:800;color:#0f172a;background:#ffffff;border:2px solid #4f46e5;padding:4px 14px;border-radius:8px;letter-spacing:0.8px;box-shadow:0 2px 8px rgba(79,70,229,0.18);">
                                    4(3-0-2)
                                </span>
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:12px;">
                            <div class="form-group" style="margin:0;">
                                <label class="form-label" style="font-size:12px;font-weight:700;color:#334155;">Total Credits</label>
                                <input type="number" name="credits" id="inputCredits" class="form-input" min="0" max="30"
                                    value="<?= htmlspecialchars($_POST['credits'] ?? '4') ?>" required oninput="updateLtpPreview()">
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label class="form-label" style="font-size:12px;font-weight:700;color:#334155;">Lecture (L)</label>
                                <input type="number" name="lecture_hours" id="inputL" class="form-input" min="0" max="30"
                                    value="<?= htmlspecialchars($_POST['lecture_hours'] ?? '3') ?>" required oninput="updateLtpPreview()">
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label class="form-label" style="font-size:12px;font-weight:700;color:#334155;">Theory / Tutorial (T)</label>
                                <input type="number" name="tutorial_hours" id="inputT" class="form-input" min="0" max="30"
                                    value="<?= htmlspecialchars($_POST['tutorial_hours'] ?? '0') ?>" required oninput="updateLtpPreview()">
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label class="form-label" style="font-size:12px;font-weight:700;color:#334155;">Practical (P)</label>
                                <input type="number" name="practical_hours" id="inputP" class="form-input" min="0" max="30"
                                    value="<?= htmlspecialchars($_POST['practical_hours'] ?? '2') ?>" required oninput="updateLtpPreview()">
                            </div>
                        </div>
                    </div>

                    <div style="display:flex;gap:12px;margin-top:10px;">
                        <button type="submit" class="btn btn-primary">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            Save Subject
                        </button>
                        <a href="<?= $backUrl ?>" class="btn btn-outline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
var targetPreSemester = '<?= addslashes($preSemester) ?>';
var semTagMap = <?= json_encode($semTagMap) ?>;

function updateSemesterDropdown() {
    var progSelect = document.getElementById('programSelect');
    var selectedOpt = progSelect.options[progSelect.selectedIndex];
    var semSelect = document.getElementById('semesterSelect');
    var totalSem = parseInt(selectedOpt.getAttribute('data-semesters')) || 4;
    var progId = selectedOpt.value;

    semSelect.innerHTML = '';
    for (var i = 1; i <= totalSem; i++) {
        var suffix = 'th';
        if (i === 1) suffix = 'st';
        else if (i === 2) suffix = 'nd';
        else if (i === 3) suffix = 'rd';

        var semName = i + suffix + ' Semester';
        var tag = semTagMap[progId + '_' + i] || '';
        var opt = document.createElement('option');
        opt.value = semName;
        opt.textContent = semName + (tag ? ' (' + tag + ')' : '');
        if (targetPreSemester && targetPreSemester.toLowerCase() === semName.toLowerCase()) {
            opt.selected = true;
        }
        semSelect.appendChild(opt);
    }

    document.getElementById('semHelper').textContent = 'Program total: ' + totalSem + ' Semesters';
}

function updateLtpPreview() {
    var c = parseInt(document.getElementById('inputCredits').value) || 0;
    var l = parseInt(document.getElementById('inputL').value) || 0;
    var t = parseInt(document.getElementById('inputT').value) || 0;
    var p = parseInt(document.getElementById('inputP').value) || 0;
    var pattern = c + '(' + l + '-' + t + '-' + p + ')';
    var badge = document.getElementById('ltpPreviewBadge');
    if (badge) {
        badge.textContent = pattern;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updateSemesterDropdown();
    updateLtpPreview();
});
</script>
</body>
</html>
