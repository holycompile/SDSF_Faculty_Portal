<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setFlash('error', 'Invalid course ID.');
    header('Location: ' . BASE_URL . '/admin/courses/list.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
$stmt->execute([$id]);
$course = $stmt->fetch();

if (!$course) {
    setFlash('error', 'Course not found.');
    header('Location: ' . BASE_URL . '/admin/courses/list.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $program      = trim($_POST['program']      ?? '');
    $semester     = trim($_POST['semester']     ?? '');
    $subject_name = trim($_POST['subject_name'] ?? '');
    $course_code  = trim($_POST['course_code']  ?? '');
    $class_type   = $_POST['class_type'] ?? '';

    if (!$program)      $errors[] = 'Program is required.';
    if (!$subject_name) $errors[] = 'Subject name is required.';
    if (!in_array($class_type, ['T','P'])) $errors[] = 'Class type must be Theory or Practical.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $upd = $pdo->prepare("
                UPDATE courses 
                SET program = ?, semester = ?, subject_name = ?, course_code = ?, class_type = ?
                WHERE id = ?
            ");
            $upd->execute([$program, $semester, $subject_name, $course_code, $class_type, $id]);

            // Synchronize course name & code in faculty_course_assignments
            $updFca = $pdo->prepare("
                UPDATE faculty_course_assignments 
                SET course_name = ?, course_code = ?
                WHERE course_id = ?
            ");
            $updFca->execute([$subject_name, $course_code, $id]);

            $pdo->commit();
            setFlash('success', "Course \"{$subject_name}\" updated successfully!");
            header('Location: ' . BASE_URL . '/admin/courses/list.php');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Failed to update course: ' . $e->getMessage();
        }
    }
}

$active_nav = 'courses-list';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Course — SDSF Admin</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
</head>
<body>
<header class="topbar">
    <div class="tb-left">
        <a href="<?= BASE_URL ?>/admin/dashboard.php" style="color:#94a3b8;text-decoration:none;">Dashboard</a>
        <span class="tb-sep">/</span>
        <a href="<?= BASE_URL ?>/admin/courses/list.php" style="color:#94a3b8;text-decoration:none;">Courses</a>
        <span class="tb-sep">/</span>
        <span class="tb-crumb">Edit Course</span>
    </div>
    <div class="tb-right">
        <span class="tb-date"><?= date('d M Y') ?></span>
        <div class="tb-avatar"><?= strtoupper(substr(preg_replace('/\s+/','',$_SESSION['admin_username']),0,2)) ?></div>
    </div>
</header>

<div class="page">
    <div class="page-header fade-up">
        <h1>Edit Course</h1>
        <p>Update details for <?= htmlspecialchars($course['subject_name']) ?> (<?= htmlspecialchars($course['course_code'] ?: 'No Code') ?>)</p>
    </div>

    <?php foreach ($errors as $e): ?>
    <div class="alert alert-error fade-up">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <?= htmlspecialchars($e) ?>
    </div>
    <?php endforeach; ?>

    <div style="max-width:680px;">
        <div class="card fade-up">
            <div class="card-head">
                <div>
                    <div class="card-title">Course Information</div>
                    <div class="card-sub">Edit the fields below and save changes</div>
                </div>
                <a href="<?= BASE_URL ?>/admin/courses/list.php" class="btn btn-outline btn-sm">&larr; Back to Courses</a>
            </div>
            <div style="padding:28px;">
                <form method="POST" action="">
                    <?php
                    $curSem = $_POST['semester'] ?? ($course['semester'] ?? '1st Semester');
                    $semList = [
                        '1st Semester', '2nd Semester', '3rd Semester',
                        '4th Semester', '5th Semester', '6th Semester',
                        '7th Semester', '8th Semester', '9th Semester'
                    ];
                    ?>
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">Program / Degree *</label>
                            <input type="text" name="program" class="form-input"
                                placeholder="e.g. M.Tech AI&DS"
                                value="<?= htmlspecialchars($_POST['program'] ?? $course['program']) ?>" required>
                            <div style="font-size:12px;color:#94a3b8;margin-top:5px;">Program: <strong>M.Tech AI&amp;DS</strong></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Semester *</label>
                            <select name="semester" class="form-select" required>
                                <?php foreach ($semList as $s): ?>
                                    <option value="<?= htmlspecialchars($s) ?>" <?= $curSem === $s ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div style="font-size:12px;color:#94a3b8;margin-top:5px;">Choose which semester this subject is taught in</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Subject / Course Name *</label>
                        <input type="text" name="subject_name" class="form-input"
                            placeholder="e.g. ADMS, Business Analytics, DAA"
                            value="<?= htmlspecialchars($_POST['subject_name'] ?? $course['subject_name']) ?>" required>
                    </div>

                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">Course Code</label>
                            <input type="text" name="course_code" class="form-input"
                                placeholder="e.g. ADMS-101, MBA-BA-201"
                                value="<?= htmlspecialchars($_POST['course_code'] ?? ($course['course_code'] ?? '')) ?>"
                                style="font-family:monospace;">
                            <div style="font-size:12px;color:#94a3b8;margin-top:5px;">Optional university course identifier</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Class Type &amp; Remuneration Rate *</label>
                            <?php $selectedType = $_POST['class_type'] ?? $course['class_type']; ?>
                            <select name="class_type" class="form-select" required>
                                <option value="T" <?= $selectedType === 'T' ? 'selected' : '' ?>>Theory (T) — &#8377;800 / hour</option>
                                <option value="P" <?= $selectedType === 'P' ? 'selected' : '' ?>>Practical (P) — &#8377;400 / hour</option>
                            </select>
                        </div>
                    </div>

                    <div style="display:flex;gap:12px;margin-top:24px;">
                        <button type="submit" class="btn btn-primary" style="padding:11px 28px;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            Save Changes
                        </button>
                        <a href="<?= BASE_URL ?>/admin/courses/list.php" class="btn btn-outline" style="padding:11px 22px;">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</div>
</body>
</html>
