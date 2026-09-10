<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$errors   = [];
$allCourses = $pdo->query("SELECT * FROM courses ORDER BY program, semester, subject_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name         = trim($_POST['name']         ?? '');
    $email        = trim($_POST['email']        ?? '');
    $phone        = trim($_POST['phone']        ?? '');
    $address      = trim($_POST['address']      ?? '');
    $qualification= trim($_POST['qualification']?? '');
    $department   = trim($_POST['department']   ?? '');
    $pan_no       = trim($_POST['pan_no']       ?? '');
    $account_no   = trim($_POST['account_no']   ?? '');
    $bank_name    = trim($_POST['bank_name']    ?? '');
    $ifsc_code    = trim($_POST['ifsc_code']    ?? '');
    $aadhaar_no   = trim($_POST['aadhaar_no']   ?? '');
    $course_ids   = $_POST['course_ids']        ?? [];

    if (!$name) $errors[] = 'Faculty name is required.';
    if (!$phone) $errors[] = 'Mobile number is required.';
    if (!$qualification) $errors[] = 'Qualification is required.';
    if (!$department) $errors[] = 'Department is required.';
    if (empty($course_ids)) $errors[] = 'Please assign at least one course.';

    if (empty($errors)) {
        $enrollment_no = generateEnrollmentNo($name, $pdo);

        $stmt = $pdo->prepare("INSERT INTO faculty_members
            (faculty_enrollment_no, name, email, phone, address, qualification, department,
             pan_no, account_no, bank_name, ifsc_code, aadhaar_no)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$enrollment_no, $name, $email, $phone, $address, $qualification,
                        $department, $pan_no, $account_no, $bank_name, $ifsc_code, $aadhaar_no]);

        $faculty_id = (int) $pdo->lastInsertId();

        // Fetch course details for readable columns
        $courseMap = [];
        foreach ($allCourses as $ac) {
            $courseMap[$ac['id']] = $ac;
        }

        $assign = $pdo->prepare("
            INSERT IGNORE INTO faculty_course_assignments
                (faculty_id, faculty_name, faculty_enrollment_no, course_id, course_name, course_code)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($course_ids as $cid) {
            $cid = (int)$cid;
            $cName = $courseMap[$cid]['subject_name'] ?? '';
            $cCode = $courseMap[$cid]['course_code'] ?? '';
            $assign->execute([$faculty_id, $name, $enrollment_no, $cid, $cName, $cCode]);
        }

        setFlash('success', "Faculty \"{$name}\" registered successfully! Enrollment No: {$enrollment_no}");
        header('Location: ' . BASE_URL . '/admin/faculty/view.php?id=' . $faculty_id);
        exit;
    }
}

$active_nav = 'faculty-register';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register Faculty — SDSF Admin</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
<style>
.course-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
.course-item { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border-radius: 10px; border: 1.5px solid #e2e8f0; background: #f8fafc; cursor: pointer; transition: all .18s; }
.course-item:has(input:checked) { border-color: #c7d2fe; background: #eef2ff; }
.course-item:hover { border-color: #a5b4fc; }
.course-item input[type=checkbox] { margin-top: 2px; accent-color: #4f46e5; width: 16px; height: 16px; flex-shrink: 0; cursor: pointer; }
.course-prog { font-size: 12px; font-weight: 700; color: #4f46e5; }
.course-sub  { font-size: 13.5px; font-weight: 600; color: #0f172a; margin: 1px 0; }
.course-meta { font-size: 11.5px; color: #64748b; }
</style>
</head>
<body>
<header class="topbar">
    <div class="tb-left">
        <a href="<?= BASE_URL ?>/admin/dashboard.php" style="color:#94a3b8;text-decoration:none;">Dashboard</a>
        <span class="tb-sep">/</span>
        <a href="<?= BASE_URL ?>/admin/faculty/list.php" style="color:#94a3b8;text-decoration:none;">Faculty</a>
        <span class="tb-sep">/</span>
        <span class="tb-crumb">Register Faculty</span>
    </div>
    <div class="tb-right">
        <span class="tb-date"><?= date('d M Y') ?></span>
        <div class="tb-avatar"><?= strtoupper(substr(preg_replace('/\s+/','',$_SESSION['admin_username']),0,2)) ?></div>
    </div>
</header>

<div class="page">
    <div class="page-header fade-up">
        <h1>Register Visiting Faculty</h1>
        <p>Fill in the faculty details and assign courses. Enrollment number is auto-generated.</p>
    </div>

    <?php foreach ($errors as $e): ?>
    <div class="alert alert-error fade-up">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <?= htmlspecialchars($e) ?>
    </div>
    <?php endforeach; ?>

    <form method="POST" action="">
        <!-- Personal Information -->
        <div class="card fade-up" style="margin-bottom:20px;">
            <div class="card-head">
                <div><div class="card-title">Personal Information</div><div class="card-sub">Basic faculty details</div></div>
            </div>
            <div style="padding:28px;">
                <div style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:10px;padding:12px 16px;margin-bottom:24px;font-size:13px;color:#4338ca;">
                    &#128272; Enrollment number will be auto-generated after saving (e.g. <strong>RITI0001</strong>)
                </div>
                <div class="section-title">Basic Details</div>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="name" class="form-input" placeholder="e.g. Ritika Verma" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mobile Number *</label>
                        <input type="tel" name="phone" class="form-input" placeholder="e.g. 9399064826" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required>
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-input" placeholder="faculty@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Qualification *</label>
                        <input type="text" name="qualification" class="form-input" placeholder="e.g. M.Tech, Ph.D, MBA" value="<?= htmlspecialchars($_POST['qualification'] ?? '') ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Department / School *</label>
                    <input type="text" name="department" class="form-input" placeholder="e.g. SDSF, IIPS, School of Commerce" value="<?= htmlspecialchars($_POST['department'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-textarea" placeholder="Full address including city, pin code"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Banking Details -->
        <div class="card fade-up" style="margin-bottom:20px;">
            <div class="card-head">
                <div><div class="card-title">Banking Details</div><div class="card-sub">Required for remuneration PDF generation</div></div>
            </div>
            <div style="padding:28px;">
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">PAN Card No.</label>
                        <input type="text" name="pan_no" class="form-input" style="text-transform:uppercase;" placeholder="e.g. BVJPV3268E" value="<?= htmlspecialchars($_POST['pan_no'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Aadhaar No.</label>
                        <input type="text" name="aadhaar_no" class="form-input" placeholder="12-digit Aadhaar number" value="<?= htmlspecialchars($_POST['aadhaar_no'] ?? '') ?>">
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Bank Account No.</label>
                        <input type="text" name="account_no" class="form-input" placeholder="e.g. 09112011018668" value="<?= htmlspecialchars($_POST['account_no'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Bank Name</label>
                        <input type="text" name="bank_name" class="form-input" placeholder="e.g. State Bank of India" value="<?= htmlspecialchars($_POST['bank_name'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">IFSC Code</label>
                    <input type="text" name="ifsc_code" class="form-input" style="text-transform:uppercase;" placeholder="e.g. SBIN0020522" value="<?= htmlspecialchars($_POST['ifsc_code'] ?? '') ?>">
                </div>
            </div>
        </div>

        <!-- Course Assignment -->
        <div class="card fade-up" style="margin-bottom:24px;">
            <div class="card-head">
                <div><div class="card-title">Assign Courses</div><div class="card-sub">Select all courses this faculty member will teach</div></div>
                <span class="c-badge" id="selected-count">0 selected</span>
            </div>
            <div style="padding:28px;">
                <?php if (empty($allCourses)): ?>
                <div style="text-align:center;padding:30px;color:#94a3b8;">
                    No courses available. <a href="<?= BASE_URL ?>/admin/courses/add.php" style="color:#4f46e5;">Add courses first.</a>
                </div>
                <?php else: ?>
                <div class="course-grid">
                    <?php foreach ($allCourses as $course): ?>
                    <label class="course-item">
                        <input type="checkbox" name="course_ids[]" value="<?= $course['id'] ?>"
                            <?= in_array($course['id'], $_POST['course_ids'] ?? []) ? 'checked' : '' ?>
                            onchange="updateCount()">
                        <div>
                            <div class="course-prog"><?= htmlspecialchars($course['program']) ?> <?= htmlspecialchars($course['semester'] ?? '') ?></div>
                            <div class="course-sub"><?= htmlspecialchars($course['subject_name']) ?></div>
                            <div class="course-meta">
                                <?= $course['course_code'] ? htmlspecialchars($course['course_code']).' · ' : '' ?>
                                <?= $course['class_type'] === 'T' ? '<span style="color:#2563eb;font-weight:600;">Theory</span> ₹800/hr' : '<span style="color:#b45309;font-weight:600;">Practical</span> ₹400/hr' ?>
                            </div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div style="display:flex;gap:12px;">
            <button type="submit" class="btn btn-primary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Register Faculty
            </button>
            <a href="<?= BASE_URL ?>/admin/faculty/list.php" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>
</div>

<script>
function updateCount() {
    const checked = document.querySelectorAll('input[name="course_ids[]"]:checked').length;
    document.getElementById('selected-count').textContent = checked + ' selected';
}
document.addEventListener('DOMContentLoaded', updateCount);
</script>
</body>
</html>
