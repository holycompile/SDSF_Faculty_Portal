<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setFlash('error', 'Invalid faculty ID specified.');
    header('Location: ' . BASE_URL . '/admin/faculty/list.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM faculty_members WHERE id = ?");
$stmt->execute([$id]);
$faculty = $stmt->fetch();

if (!$faculty) {
    setFlash('error', 'Faculty member not found.');
    header('Location: ' . BASE_URL . '/admin/faculty/list.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enrollment_no = strtoupper(trim($_POST['faculty_enrollment_no'] ?? ''));
    $name          = trim($_POST['name']          ?? '');
    $email         = trim($_POST['email']         ?? '');
    $phone         = trim($_POST['phone']         ?? '');
    $address       = trim($_POST['address']       ?? '');
    $qualification = trim($_POST['qualification'] ?? '');
    $department    = trim($_POST['department']    ?? '');
    $pan_no        = strtoupper(trim($_POST['pan_no'] ?? ''));
    $account_no    = trim($_POST['account_no']    ?? '');
    $bank_name     = trim($_POST['bank_name']     ?? '');
    $ifsc_code     = strtoupper(trim($_POST['ifsc_code'] ?? ''));
    $aadhaar_no    = trim($_POST['aadhaar_no']    ?? '');
    $theory_rate   = (float)($_POST['theory_rate']   ?? 800.00);
    $practical_rate= (float)($_POST['practical_rate']?? 400.00);
    $status        = in_array($_POST['status'] ?? 'active', ['active', 'inactive']) ? $_POST['status'] : 'active';

    if ($theory_rate <= 0)    $theory_rate = 800.00;
    if ($practical_rate <= 0) $practical_rate = 400.00;

    if (!$enrollment_no) {
        $errors[] = 'Faculty enrollment number is required.';
    } elseif (!preg_match('/^[A-Z0-9_-]{3,20}$/', $enrollment_no)) {
        $errors[] = 'Enrollment number must be 3 to 20 alphanumeric characters (e.g. RIYA0001).';
    } else {
        // Uniqueness check across all other faculty members
        $chk = $pdo->prepare("SELECT COUNT(*) FROM faculty_members WHERE faculty_enrollment_no = ? AND id != ?");
        $chk->execute([$enrollment_no, $id]);
        if ((int)$chk->fetchColumn() > 0) {
            $errors[] = "Enrollment number '{$enrollment_no}' is already assigned to another faculty member.";
        }
    }

    if (!$name) $errors[] = 'Faculty name is required.';
    if (!$phone) $errors[] = 'Mobile number is required.';
    if (!$qualification) $errors[] = 'Qualification is required.';
    if (!$department) $errors[] = 'Department is required.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $oldEnrollmentNo = $faculty['faculty_enrollment_no'];
            $oldName = $faculty['name'];

            // 1. Update faculty master record
            $upd = $pdo->prepare("
                UPDATE faculty_members SET
                    faculty_enrollment_no = ?,
                    name = ?,
                    email = ?,
                    phone = ?,
                    address = ?,
                    qualification = ?,
                    department = ?,
                    theory_rate = ?,
                    practical_rate = ?,
                    pan_no = ?,
                    account_no = ?,
                    bank_name = ?,
                    ifsc_code = ?,
                    aadhaar_no = ?,
                    status = ?
                WHERE id = ?
            ");
            $upd->execute([
                $enrollment_no, $name, $email, $phone, $address, $qualification, $department,
                $theory_rate, $practical_rate, $pan_no, $account_no, $bank_name, $ifsc_code, $aadhaar_no,
                $status, $id
            ]);

            // 2. Cascade update to assigned courses
            if ($enrollment_no !== $oldEnrollmentNo || $name !== $oldName) {
                $updAssign = $pdo->prepare("
                    UPDATE faculty_course_assignments 
                    SET faculty_enrollment_no = ?, faculty_name = ?
                    WHERE faculty_id = ?
                ");
                $updAssign->execute([$enrollment_no, $name, $id]);
            }

            // 3. Keep default initial password synchronized if password was never modified by user
            if ($enrollment_no !== $oldEnrollmentNo && (int)$faculty['is_password_changed'] === 0) {
                $newDefaultPass = 'SDSF@' . substr($enrollment_no, -4);
                $updPass = $pdo->prepare("UPDATE faculty_members SET password = ? WHERE id = ?");
                $updPass->execute([$newDefaultPass, $id]);
            }

            // 4. Cascade active password reset tokens if any
            if ($enrollment_no !== $oldEnrollmentNo) {
                $updOtp = $pdo->prepare("UPDATE password_reset_otps SET identifier = ? WHERE identifier = ? AND user_type = 'faculty'");
                $updOtp->execute([$enrollment_no, $oldEnrollmentNo]);
            }

            $pdo->commit();

            setFlash('success', "Faculty profile updated successfully! Enrollment No: <strong>{$enrollment_no}</strong>");
            header('Location: ' . BASE_URL . '/admin/faculty/view.php?id=' . $id);
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Database error updating faculty: ' . $e->getMessage();
        }
    }
}

$active_nav = 'faculty-list';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Faculty Member — <?= htmlspecialchars($faculty['name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
    <?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
    <style>
        .page-header { margin-bottom: 24px; }
        .page-header h1 { font-size: 24px; font-weight: 800; color: #0f172a; margin: 0 0 6px; }
        .page-header p { font-size: 14px; color: #64748b; margin: 0; }

        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; overflow: hidden; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
        .card-head { padding: 18px 24px; border-bottom: 1px solid #f1f5f9; background: #fafafa; display: flex; align-items: center; justify-content: space-between; }
        .card-title { font-size: 15px; font-weight: 700; color: #0f172a; }
        .card-sub { font-size: 12.5px; color: #64748b; margin-top: 2px; }

        .form-group { margin-bottom: 18px; }
        .form-label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px; }
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #cbd5e1;
            border-radius: 9px;
            font-size: 14px;
            font-family: inherit;
            color: #0f172a;
            background: #fff;
            box-sizing: border-box;
            transition: all .2s ease;
            outline: none;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79,70,229,0.12);
        }
        .form-textarea { resize: vertical; min-height: 80px; }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }

        .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 10px; padding: 12px 16px; margin-bottom: 20px; font-size: 13.5px; }
        .alert-error ul { margin: 0; padding-left: 20px; }

        @media (max-width: 640px) {
            .grid-2, .grid-3 { grid-template-columns: 1fr !important; gap: 12px !important; }
            .card { padding: 0 !important; }
            .card-head { padding: 14px 16px !important; }
            .card > div:last-child { padding: 18px 16px !important; }
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
        <a href="<?= BASE_URL ?>/admin/faculty/view.php?id=<?= $faculty['id'] ?>" style="color:#94a3b8;text-decoration:none;"><?= htmlspecialchars($faculty['name']) ?></a>
        <span class="tb-sep">/</span>
        <span class="tb-crumb">Edit Profile</span>
    </div>
    <div class="tb-right">
        <span class="tb-date"><?= date('d M Y') ?></span>
        <a href="<?= BASE_URL ?>/admin/faculty/view.php?id=<?= $faculty['id'] ?>" class="btn btn-outline btn-sm">
            &larr; Cancel &amp; Back
        </a>
    </div>
</header>

<div class="page">
    <div class="page-header">
        <h1>Edit Faculty Profile &amp; Enrollment</h1>
        <p>Update faculty member details, edit institutional enrollment code, customize hourly rates, and manage status.</p>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert-error">
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <!-- Institutional Identification Section -->
        <div class="card" style="border-left:4px solid #4f46e5;">
            <div class="card-head">
                <div>
                    <div class="card-title" style="display:flex;align-items:center;gap:8px;">
                        <span>Institutional Enrollment &amp; Identification</span>
                        <span class="badge badge-blue">System Unique Key</span>
                    </div>
                    <div class="card-sub">The official faculty enrollment number used for teacher login, session attendance, and billing reports</div>
                </div>
            </div>
            <div style="padding:24px 28px;">
                <div class="form-group" style="max-width:480px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                        <label class="form-label" style="margin-bottom:0;color:#1e3a8a;font-weight:700;">
                            Faculty Enrollment Number *
                        </label>
                        <button type="button" onclick="suggestEnrollmentNo()" style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;border-radius:6px;padding:3px 10px;font-size:11.5px;font-weight:700;cursor:pointer;">
                            ⚡ Auto-Generate From Name
                        </button>
                    </div>
                    <input type="text" name="faculty_enrollment_no" id="faculty_enrollment_no" class="form-input"
                           value="<?= htmlspecialchars($_POST['faculty_enrollment_no'] ?? $faculty['faculty_enrollment_no']) ?>"
                           maxlength="20"
                           required
                           style="font-family:monospace;font-size:15px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:#1e3a8a;background:#fafafa;">
                    <div style="font-size:12px;color:#64748b;margin-top:6px;">
                        💡 Changing this enrollment number will automatically cascade to assigned courses, lecture records, and authentication credentials.
                    </div>
                </div>
            </div>
        </div>

        <!-- Personal Information -->
        <div class="card">
            <div class="card-head">
                <div>
                    <div class="card-title">Personal &amp; Academic Information</div>
                    <div class="card-sub">Basic contact details, degree qualifications, and university department</div>
                </div>
            </div>
            <div style="padding:24px 28px;">
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="name" id="faculty_name" class="form-input" value="<?= htmlspecialchars($_POST['name'] ?? $faculty['name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mobile Number *</label>
                        <input type="tel" name="phone" class="form-input" value="<?= htmlspecialchars($_POST['phone'] ?? $faculty['phone']) ?>" required>
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-input" value="<?= htmlspecialchars($_POST['email'] ?? $faculty['email']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Qualification *</label>
                        <input type="text" name="qualification" class="form-input" value="<?= htmlspecialchars($_POST['qualification'] ?? $faculty['qualification']) ?>" required>
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Department / School *</label>
                        <input type="text" name="department" class="form-input" value="<?= htmlspecialchars($_POST['department'] ?? $faculty['department']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Account Status *</label>
                        <select name="status" class="form-select">
                            <option value="active" <?= ($_POST['status'] ?? $faculty['status']) === 'active' ? 'selected' : '' ?>>Active (Can Login &amp; Log Lectures)</option>
                            <option value="inactive" <?= ($_POST['status'] ?? $faculty['status']) === 'inactive' ? 'selected' : '' ?>>Inactive (Suspended / Inactive)</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Postal Address</label>
                    <textarea name="address" class="form-textarea"><?= htmlspecialchars($_POST['address'] ?? $faculty['address']) ?></textarea>
                </div>
            </div>
        </div>

        <!-- Remuneration Rates Configuration -->
        <div class="card" style="border-left:4px solid #059669;">
            <div class="card-head">
                <div>
                    <div class="card-title">Remuneration Rates (Honorarium)</div>
                    <div class="card-sub">Hourly remuneration rates applied to this teacher for theory lectures and lab sessions</div>
                </div>
            </div>
            <div style="padding:24px 28px;">
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Theory Class Rate (&#8377; per hour) *</label>
                        <div style="position:relative;">
                            <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#64748b;font-weight:700;">&#8377;</span>
                            <input type="number" step="1" min="1" name="theory_rate" class="form-input"
                                   style="padding-left:32px;font-weight:700;color:#1e293b;"
                                   value="<?= htmlspecialchars($_POST['theory_rate'] ?? $faculty['theory_rate']) ?>" required>
                        </div>
                        <div style="font-size:12px;color:#64748b;margin-top:5px;">Standard DAVV rate: <strong>&#8377;800 / hr</strong></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Practical / Lab Class Rate (&#8377; per hour) *</label>
                        <div style="position:relative;">
                            <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#64748b;font-weight:700;">&#8377;</span>
                            <input type="number" step="1" min="1" name="practical_rate" class="form-input"
                                   style="padding-left:32px;font-weight:700;color:#1e293b;"
                                   value="<?= htmlspecialchars($_POST['practical_rate'] ?? $faculty['practical_rate']) ?>" required>
                        </div>
                        <div style="font-size:12px;color:#64748b;margin-top:5px;">Standard DAVV rate: <strong>&#8377;400 / hr</strong></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Banking Details -->
        <div class="card">
            <div class="card-head">
                <div>
                    <div class="card-title">Banking &amp; Statutory Details</div>
                    <div class="card-sub">Required for remuneration Annexure-IV PDF generation and university treasury disbursement</div>
                </div>
            </div>
            <div style="padding:24px 28px;">
                <div class="grid-3">
                    <div class="form-group">
                        <label class="form-label">PAN Number</label>
                        <input type="text" name="pan_no" class="form-input" maxlength="10" placeholder="e.g. ABCDE1234F" value="<?= htmlspecialchars($_POST['pan_no'] ?? $faculty['pan_no']) ?>" style="text-transform:uppercase;">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Bank Account Number</label>
                        <input type="text" name="account_no" class="form-input" placeholder="e.g. 100023456789" value="<?= htmlspecialchars($_POST['account_no'] ?? $faculty['account_no']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Bank Name &amp; Branch</label>
                        <input type="text" name="bank_name" class="form-input" placeholder="e.g. SBI DAVV Campus" value="<?= htmlspecialchars($_POST['bank_name'] ?? $faculty['bank_name']) ?>">
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">IFSC Code</label>
                        <input type="text" name="ifsc_code" class="form-input" maxlength="11" placeholder="e.g. SBIN0030336" value="<?= htmlspecialchars($_POST['ifsc_code'] ?? $faculty['ifsc_code']) ?>" style="text-transform:uppercase;">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Aadhaar Number</label>
                        <input type="text" name="aadhaar_no" class="form-input" maxlength="12" placeholder="12-digit UID" value="<?= htmlspecialchars($_POST['aadhaar_no'] ?? $faculty['aadhaar_no']) ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Save Actions -->
        <div style="display:flex;gap:12px;margin-top:24px;margin-bottom:40px;">
            <button type="submit" class="btn btn-primary" style="padding:11px 24px;font-size:14px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Save Changes &amp; Update Enrollment
            </button>
            <a href="<?= BASE_URL ?>/admin/faculty/view.php?id=<?= $faculty['id'] ?>" class="btn btn-outline" style="padding:11px 20px;">Cancel</a>
        </div>
    </form>
</div>

<script>
function suggestEnrollmentNo() {
    const nameInput = document.getElementById('faculty_name');
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
</script>
</body>
</html>
