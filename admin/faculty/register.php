<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$errors = [];
$allCourses = $pdo->query("SELECT * FROM courses ORDER BY program_id ASC, program ASC, semester_number ASC, semester ASC, subject_name ASC")->fetchAll();

// Group courses by program (with batch year) then by semester
$coursesByProgram = [];
foreach ($allCourses as $c) {
    $pKey = trim($c['program'] ?? 'General');
    $bYear = trim($c['batch_year'] ?? '');
    $sem = trim($c['semester'] ?? 'Other');
    if ($bYear) {
        $sem .= " ({$bYear})";
    }
    if (!isset($coursesByProgram[$pKey])) {
        $coursesByProgram[$pKey] = [];
    }
    if (!isset($coursesByProgram[$pKey][$sem])) {
        $coursesByProgram[$pKey][$sem] = [];
    }
    $coursesByProgram[$pKey][$sem][] = $c;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name          = trim($_POST['name']          ?? '');
    $email         = trim($_POST['email']         ?? '');
    $phone         = trim($_POST['phone']         ?? '');
    $address       = trim($_POST['address']       ?? '');
    $qualification = trim($_POST['qualification'] ?? '');
    $department    = trim($_POST['department']    ?? '');
    $pan_no        = trim($_POST['pan_no']        ?? '');
    $account_no    = trim($_POST['account_no']    ?? '');
    $bank_name     = trim($_POST['bank_name']     ?? '');
    $ifsc_code     = trim($_POST['ifsc_code']     ?? '');
    $aadhaar_no    = trim($_POST['aadhaar_no']    ?? '');
    $theory_rate   = (float)($_POST['theory_rate']   ?? 800.00);
    $practical_rate= (float)($_POST['practical_rate']?? 400.00);
    $course_ids    = $_POST['course_ids']         ?? [];

    if ($theory_rate <= 0)    $theory_rate = 800.00;
    if ($practical_rate <= 0) $practical_rate = 400.00;

    if (!$name) $errors[] = 'Faculty name is required.';
    if (!$phone) $errors[] = 'Mobile number is required.';
    if (!$qualification) $errors[] = 'Qualification is required.';
    if (!$department) $errors[] = 'Department is required.';
    if (empty($course_ids)) $errors[] = 'Please assign at least one course.';

    $customEnrollmentNo = strtoupper(trim($_POST['faculty_enrollment_no'] ?? ''));
    if ($customEnrollmentNo !== '') {
        if (!preg_match('/^[A-Z0-9_-]{3,20}$/', $customEnrollmentNo)) {
            $errors[] = 'Enrollment number must be 3 to 20 alphanumeric characters (letters, numbers, hyphens, underscores).';
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM faculty_members WHERE faculty_enrollment_no = ?");
            $chk->execute([$customEnrollmentNo]);
            if ((int)$chk->fetchColumn() > 0) {
                $errors[] = "Enrollment number '{$customEnrollmentNo}' is already registered to another faculty member.";
            }
        }
    }

    if (empty($errors)) {
        try {
            $enrollment_no = ($customEnrollmentNo !== '') ? $customEnrollmentNo : generateEnrollmentNo($name, $pdo);
            $initial_password = 'SDSF@' . substr($enrollment_no, -4);

            $stmt = $pdo->prepare("INSERT INTO faculty_members
                (faculty_enrollment_no, name, email, phone, address, qualification, department,
                 pan_no, account_no, bank_name, ifsc_code, aadhaar_no, theory_rate, practical_rate, password, is_password_changed)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)");
            $stmt->execute([$enrollment_no, $name, $email, $phone, $address, $qualification,
                            $department, $pan_no, $account_no, $bank_name, $ifsc_code, $aadhaar_no, $theory_rate, $practical_rate, $initial_password]);

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

            setFlash('success', "Faculty \"{$name}\" registered successfully! Enrollment No: <strong>{$enrollment_no}</strong> | Initial Password: <strong>{$initial_password}</strong> | Theory: ₹{$theory_rate}/hr, Practical: ₹{$practical_rate}/hr");
            header('Location: ' . BASE_URL . '/admin/faculty/view.php?id=' . $faculty_id);
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Failed to register faculty: ' . $e->getMessage();
        }
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
.course-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
.course-item { display: flex; align-items: flex-start; gap: 10px; padding: 13px 15px; border-radius: 12px; border: 1.5px solid #e2e8f0; background: #f8fafc; cursor: pointer; transition: all .18s; }
.course-item:has(input:checked) { border-color: #c7d2fe; background: #eef2ff; }
.course-item:hover { border-color: #a5b4fc; background: #f5f3ff; }
.course-item input[type=checkbox] { margin-top: 2px; accent-color: #4f46e5; width: 16px; height: 16px; flex-shrink: 0; cursor: pointer; }
.course-prog { font-size: 11px; font-weight: 700; color: #4f46e5; text-transform: uppercase; letter-spacing: .04em; }
.course-sub  { font-size: 13.5px; font-weight: 600; color: #0f172a; margin: 3px 0 2px; }
.course-meta { font-size: 12px; color: #64748b; }
.sem-block-header {
    background: #f1f5f9;
    border-radius: 8px;
    padding: 8px 14px;
    margin: 18px 0 10px;
    font-size: 12.5px;
    font-weight: 700;
    color: #334155;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.sem-block-header:first-child { margin-top: 0; }
@media (max-width: 640px) {
    .course-grid { grid-template-columns: 1fr !important; }
    .card { padding: 18px 14px !important; }
}
</style>
</head>
<body>
<header class="topbar">
    <div class="tb-left">
        <a href="<?= BASE_URL ?>/admin/dashboard.php" style="color:#94a3b8;text-decoration:none;">Dashboard</a>
        <span class="tb-sep">/</span>
        <a href="<?= BASE_URL ?>/admin/faculty/list.php" style="color:#94a3b8;text-decoration:none;">Faculty</a>
        <span class="tb-sep">/</span>
        <span class="tb-crumb">Register New Faculty</span>
    </div>
    <div class="tb-right">
        <span class="tb-date"><?= date('d M Y') ?></span>
        <div class="tb-avatar"><?= strtoupper(substr(preg_replace('/\s+/','',$_SESSION['admin_username']),0,2)) ?></div>
    </div>
</header>

<div class="page">
    <div class="page-header fade-up">
        <h1>Register Visiting Faculty</h1>
        <p>Onboard a new visiting teacher, configure customized remuneration rates, and assign teaching subjects</p>
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
                <div><div class="card-title">Personal Information</div><div class="card-sub">Basic contact and profile details</div></div>
            </div>
            <div style="padding:28px;">
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
                <div class="form-group" style="background:#f8fafc;border:1.5px dashed #cbd5e1;border-radius:12px;padding:16px 20px;margin-bottom:20px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;flex-wrap:wrap;gap:8px;">
                        <label class="form-label" style="margin-bottom:0;color:#1e3a8a;font-weight:700;display:flex;align-items:center;gap:6px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <span>Faculty Enrollment Number</span>
                            <span style="font-size:11.5px;color:#64748b;font-weight:500;">(Manual entry or auto-generated)</span>
                        </label>
                        <button type="button" onclick="suggestEnrollmentNo()" style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;border-radius:6px;padding:3px 10px;font-size:11.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:4px;">
                            ⚡ Auto-Generate From Name
                        </button>
                    </div>
                    <div style="position:relative;">
                        <input type="text" name="faculty_enrollment_no" id="faculty_enrollment_no" class="form-input" 
                               placeholder="e.g. RITI0001 (Leave blank to generate automatically)" 
                               value="<?= htmlspecialchars($_POST['faculty_enrollment_no'] ?? '') ?>" 
                               maxlength="20"
                               style="font-family:monospace;font-size:14.5px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:#1e3a8a;background:#ffffff;">
                    </div>
                    <div style="font-size:12px;color:#64748b;margin-top:6px;">
                        Admin can manually enter an institutional enrollment number, or leave blank to automatically generate sequential code.
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Department / School *</label>
                    <input type="text" name="department" class="form-input" placeholder="e.g. SDSF, IIPS, School of Commerce" value="<?= htmlspecialchars($_POST['department'] ?? 'School of Data Science & Forecasting') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-textarea" placeholder="Full address including city, pin code"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Remuneration Rates Configuration -->
        <div class="card fade-up" style="margin-bottom:20px;border-left:4px solid #4f46e5;">
            <div class="card-head">
                <div>
                    <div class="card-title" style="display:flex;align-items:center;gap:8px;">
                        <span>Remuneration Rates (Honorarium)</span>
                        <span class="badge badge-blue">Per Teacher Customization</span>
                    </div>
                    <div class="card-sub">Define the specific hourly payment amount for this visiting faculty teacher</div>
                </div>
            </div>
            <div style="padding:28px;">
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Theory Class Rate (&#8377; per hour) *</label>
                        <div style="position:relative;">
                            <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#64748b;font-weight:700;">&#8377;</span>
                            <input type="number" step="1" min="1" name="theory_rate" class="form-input"
                                   id="input-theory-rate"
                                   style="padding-left:32px;font-weight:700;color:#1e293b;"
                                   value="<?= htmlspecialchars($_POST['theory_rate'] ?? '800') ?>" required
                                   oninput="updateRateBadges()">
                        </div>
                        <div style="font-size:12px;color:#64748b;margin-top:5px;">Standard DAVV default: <strong>&#8377;800 / hour</strong></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Practical / Lab Class Rate (&#8377; per hour) *</label>
                        <div style="position:relative;">
                            <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#64748b;font-weight:700;">&#8377;</span>
                            <input type="number" step="1" min="1" name="practical_rate" class="form-input"
                                   id="input-practical-rate"
                                   style="padding-left:32px;font-weight:700;color:#1e293b;"
                                   value="<?= htmlspecialchars($_POST['practical_rate'] ?? '400') ?>" required
                                   oninput="updateRateBadges()">
                        </div>
                        <div style="font-size:12px;color:#64748b;margin-top:5px;">Standard DAVV default: <strong>&#8377;400 / hour</strong></div>
                    </div>
                </div>
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 16px;font-size:12.5px;color:#166534;display:flex;align-items:center;gap:10px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <span>These customized rates will be automatically used to compute this teacher's honorarium upon lecture logging and in the official Annexure-IV claim bill.</span>
                </div>
            </div>
        </div>

        <!-- Banking Details -->
        <div class="card fade-up" style="margin-bottom:20px;">
            <div class="card-head">
                <div><div class="card-title">Banking &amp; Statutory Details</div><div class="card-sub">Required for remuneration Annexure-IV PDF generation and treasury processing</div></div>
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

        <!-- Course Assignment (Grouped by Semester) -->
        <div class="card fade-up" style="margin-bottom:24px;">
            <div class="card-head">
                <div>
                    <div class="card-title">Assign Courses / Subjects</div>
                    <div class="card-sub">Select all subjects across M.Tech AI&amp;DS semesters this faculty member will teach</div>
                </div>
                <span class="c-badge" id="selected-count">0 selected</span>
            </div>
            <div style="padding:24px 28px;">
                <?php if (empty($allCourses)): ?>
                <div style="text-align:center;padding:30px;color:#94a3b8;">
                    No courses available. <a href="<?= BASE_URL ?>/admin/courses/add.php" style="color:#4f46e5;">Add courses first.</a>
                </div>
                <?php else: ?>
                    <?php foreach ($coursesByProgram as $progTitle => $semestersList): ?>
                        <div style="background:linear-gradient(135deg,#eef2ff 0%,#f8fafc 100%);border:1.5px solid #c7d2fe;border-radius:12px;padding:12px 18px;margin:24px 0 12px;display:flex;align-items:center;justify-content:space-between;">
                            <div style="display:flex;align-items:center;gap:10px;">
                                <span style="font-size:16px;">🎓</span>
                                <span style="font-size:14.5px;font-weight:800;color:#1e293b;"><?= htmlspecialchars($progTitle) ?></span>
                            </div>
                            <span class="badge badge-blue"><?= array_sum(array_map('count', $semestersList)) ?> subjects</span>
                        </div>

                        <?php foreach ($semestersList as $semTitle => $sCourses): ?>
                            <div class="sem-block-header">
                                <span><?= htmlspecialchars($semTitle) ?></span>
                                <span style="font-size:11.5px;color:#64748b;font-weight:500;"><?= count($sCourses) ?> subject<?= count($sCourses) === 1 ? '' : 's' ?></span>
                            </div>
                            <div class="course-grid" style="margin-bottom:16px;">
                                <?php foreach ($sCourses as $course): ?>
                                <label class="course-item">
                                    <input type="checkbox" name="course_ids[]" value="<?= $course['id'] ?>"
                                        <?= in_array($course['id'], $_POST['course_ids'] ?? []) ? 'checked' : '' ?>
                                        onchange="updateCount()">
                                    <div style="flex:1;">
                                        <div class="course-prog"><?= htmlspecialchars($course['program']) ?> &bull; <?= htmlspecialchars($course['semester'] ?? '') ?></div>
                                        <div class="course-sub"><?= htmlspecialchars($course['subject_name']) ?></div>
                                        <div class="course-meta">
                                            <?= $course['course_code'] ? '<span style="font-family:monospace;font-weight:600;">' . htmlspecialchars($course['course_code']) . '</span> &bull; ' : '' ?>
                                            <?php if ($course['class_type'] === 'T'): ?>
                                                <span style="color:#2563eb;font-weight:600;">Theory Class</span> &bull; <span class="rate-badge-theory">&#8377;800/hr</span>
                                            <?php else: ?>
                                                <span style="color:#b45309;font-weight:600;">Practical / Lab</span> &bull; <span class="rate-badge-practical">&#8377;400/hr</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
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
function suggestEnrollmentNo() {
    const nameInput = document.querySelector('input[name="name"]');
    const enrollInput = document.getElementById('faculty_enrollment_no');
    if (!nameInput || !enrollInput) return;
    const nameVal = (nameInput.value || '').trim();
    if (!nameVal) {
        alert('Please enter the faculty member name first.');
        nameInput.focus();
        return;
    }
    const clean = nameVal.replace(/[^A-Za-z]/g, '').toUpperCase();
    const prefix = (clean.length >= 4 ? clean.substring(0, 4) : clean.padEnd(4, 'X'));
    enrollInput.value = prefix + '0001';
}

function updateCount() {
    const checked = document.querySelectorAll('input[name="course_ids[]"]:checked').length;
    document.getElementById('selected-count').textContent = checked + ' selected';
}

function updateRateBadges() {
    var tRate = document.getElementById('input-theory-rate').value || '800';
    var pRate = document.getElementById('input-practical-rate').value || '400';

    document.querySelectorAll('.rate-badge-theory').forEach(function(el) {
        el.textContent = '₹' + tRate + '/hr';
    });
    document.querySelectorAll('.rate-badge-practical').forEach(function(el) {
        el.textContent = '₹' + pRate + '/hr';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    updateCount();
    updateRateBadges();
});
</script>
</body>
</html>
