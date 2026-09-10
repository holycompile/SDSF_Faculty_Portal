<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$errors = [];
$success = '';

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
        $stmt = $pdo->prepare("INSERT INTO courses (program, semester, subject_name, course_code, class_type) VALUES (?,?,?,?,?)");
        $stmt->execute([$program, $semester, $subject_name, $course_code, $class_type]);
        setFlash('success', "Course \"{$subject_name}\" added successfully!");
        header('Location: ' . BASE_URL . '/admin/courses/list.php');
        exit;
    }
}

$active_nav = 'courses-add';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Course — SDSF Admin</title>
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
        <span class="tb-crumb">Add Course</span>
    </div>
    <div class="tb-right">
        <span class="tb-date"><?= date('d M Y') ?></span>
        <div class="tb-avatar"><?= strtoupper(substr(preg_replace('/\s+/','',$_SESSION['admin_username']),0,2)) ?></div>
    </div>
</header>

<div class="page">
    <div class="page-header fade-up">
        <h1>Add New Course</h1>
        <p>Create a course/subject that can be assigned to visiting faculty members</p>
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
                    <div class="card-title">Course Details</div>
                    <div class="card-sub">Fill in the course information below</div>
                </div>
            </div>
            <div style="padding:28px;">
                <form method="POST" action="">
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">Program / Degree *</label>
                            <input type="text" name="program" class="form-input"
                                placeholder="e.g. M.Sc, MBA, M.Tech AI&DS, B.Sc"
                                value="<?= htmlspecialchars($_POST['program'] ?? '') ?>" required>
                            <div style="font-size:12px;color:#94a3b8;margin-top:5px;">e.g. MBA, M.Sc, M.Tech AI&amp;DS</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Semester</label>
                            <input type="text" name="semester" class="form-input"
                                placeholder="e.g. 1st Semester, 3rd Semester"
                                value="<?= htmlspecialchars($_POST['semester'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Subject / Course Name *</label>
                        <input type="text" name="subject_name" class="form-input"
                            placeholder="e.g. ADMS, Business Analytics, DAA"
                            value="<?= htmlspecialchars($_POST['subject_name'] ?? '') ?>" required>
                    </div>

                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">Course Code</label>
                            <input type="text" name="course_code" class="form-input"
                                placeholder="e.g. FT-110A, DAA-301"
                                value="<?= htmlspecialchars($_POST['course_code'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Class Type *</label>
                            <select name="class_type" class="form-select" required>
                                <option value="">— Select Type —</option>
                                <option value="T" <?= ($_POST['class_type']??'')==='T'?'selected':'' ?>>Theory (T) — ₹800/hour</option>
                                <option value="P" <?= ($_POST['class_type']??'')==='P'?'selected':'' ?>>Practical (P) — ₹400/hour</option>
                            </select>
                        </div>
                    </div>

                    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:14px 16px;margin-bottom:24px;">
                        <div style="font-size:13px;font-weight:600;color:#b45309;margin-bottom:6px;">&#8377; Rate Information</div>
                        <div style="font-size:13px;color:#92400e;">
                            Theory (T) classes are paid at <strong>₹800 per hour</strong>.<br>
                            Practical (P) classes are paid at <strong>₹400 per hour</strong>.<br>
                            The rate is applied automatically when faculty enter lecture hours.
                        </div>
                    </div>

                    <div style="display:flex;gap:12px;">
                        <button type="submit" class="btn btn-primary">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            Save Course
                        </button>
                        <a href="<?= BASE_URL ?>/admin/courses/list.php" class="btn btn-outline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</div>
</body>
</html>
