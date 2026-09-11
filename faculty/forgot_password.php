<?php
define('ROOT', dirname(dirname(__FILE__)));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
require_once ROOT . '/includes/mailer.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$step = 1;
$error = '';
$success = '';
$identifier = trim($_GET['id'] ?? ($_POST['identifier'] ?? ''));

// STEP 1: REQUEST OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_request_otp'])) {
    $identifier = strtoupper(trim($_POST['identifier'] ?? ''));
    if (empty($identifier)) {
        $error = 'Please enter your Faculty Enrollment Number or registered Email.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM faculty_members WHERE (faculty_enrollment_no = ? OR email = ?) AND status = 'active' LIMIT 1");
        $stmt->execute([$identifier, $identifier]);
        $faculty = $stmt->fetch();

        if (!$faculty) {
            $error = 'No active faculty account found matching that Enrollment Number or Email.';
        } elseif (empty($faculty['email'])) {
            $error = 'No email address registered for this faculty member. Please contact the SDSF Admin.';
        } else {
            // Invalidate existing unused OTPs
            $inv = $pdo->prepare("UPDATE password_reset_otps SET is_used = 1 WHERE user_type = 'faculty' AND identifier = ?");
            $inv->execute([$faculty['faculty_enrollment_no']]);

            // Generate 6-digit OTP
            $otp = (string)random_int(100000, 999999);
            $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

            $ins = $pdo->prepare("
                INSERT INTO password_reset_otps (user_type, identifier, email, otp, expires_at)
                VALUES ('faculty', ?, ?, ?, ?)
            ");
            $ins->execute([$faculty['faculty_enrollment_no'], $faculty['email'], $otp, $expiresAt]);

            // Send Email via Brevo
            $mailResult = sendOtpEmail($faculty['email'], $faculty['name'], $otp, 'Faculty');
            if ($mailResult['success']) {
                $step = 2;
                $maskedEmail = preg_replace('/(?<=..).(?=.*@)/', '*', $faculty['email']);
                $success = "A 6-digit verification OTP has been dispatched to <strong>{$maskedEmail}</strong>. Valid for 10 minutes.";
                $identifier = $faculty['faculty_enrollment_no'];
            } else {
                $error = 'Failed to send OTP email: ' . ($mailResult['error'] ?? 'Brevo service unavailable.');
            }
        }
    }
}

// STEP 2: VERIFY OTP AND RESET PASSWORD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_reset_password'])) {
    $step = 2;
    $identifier   = strtoupper(trim($_POST['identifier'] ?? ''));
    $otp          = trim($_POST['otp'] ?? '');
    $newPassword  = $_POST['new_password'] ?? '';
    $confirmPass  = $_POST['confirm_password'] ?? '';

    if (empty($otp)) {
        $error = 'Please enter the 6-digit OTP code.';
    } elseif (strlen($newPassword) < 4) {
        $error = 'New password must be at least 4 characters long.';
    } elseif ($newPassword !== $confirmPass) {
        $error = 'New password and confirmation do not match.';
    } else {
        // Verify OTP
        $stmt = $pdo->prepare("
            SELECT * FROM password_reset_otps 
            WHERE user_type = 'faculty' AND identifier = ? AND otp = ? AND is_used = 0 AND expires_at > NOW()
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$identifier, $otp]);
        $otpRecord = $stmt->fetch();

        if (!$otpRecord) {
            $error = 'Invalid or expired OTP. Please check the code or request a new one.';
        } else {
            // Mark OTP as used
            $updOtp = $pdo->prepare("UPDATE password_reset_otps SET is_used = 1 WHERE id = ?");
            $updOtp->execute([$otpRecord['id']]);

            // Update faculty password
            $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
            $updFaculty = $pdo->prepare("UPDATE faculty_members SET password = ?, is_password_changed = 1 WHERE faculty_enrollment_no = ?");
            $updFaculty->execute([$hashed, $identifier]);

            setFlash('success', 'Your password has been updated successfully! You can now log in with your new password.');
            header('Location: ' . BASE_URL . '/index.php?tab=faculty');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Faculty Password Reset — SDSF Portal</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<style>
body { background: #0f172a; font-family: 'Inter', sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
.reset-card { width: 100%; max-width: 460px; background: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,0.3); }
.card-header { background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: #fff; padding: 30px 24px; text-align: center; }
.card-body { padding: 32px 28px; }
.form-label { display: block; font-size: 12px; font-weight: 700; text-transform: uppercase; color: #475569; margin-bottom: 6px; }
.form-input { width: 100%; padding: 11px 14px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: 14px; outline: none; transition: border-color .15s; }
.form-input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }
.btn-submit { width: 100%; padding: 12px; border: none; border-radius: 10px; background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff; font-size: 14px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; margin-top: 16px; }
.alert-box { padding: 12px 16px; border-radius: 10px; font-size: 13.5px; margin-bottom: 20px; line-height: 1.4; }
.alert-err { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; }
.alert-ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }.input-wrap { position: relative; display: flex; align-items: center; }
.eye-btn { position: absolute; right: 12px; background: none; border: none; cursor: pointer; color: #94a3b8; padding: 4px; display: flex; align-items: center; justify-content: center; }
.eye-btn:hover { color: #2563eb; }
</style>
</head>
<body>

<div class="reset-card">
    <div class="card-header">
        <div style="font-size: 11px; letter-spacing: 1px; text-transform: uppercase; color: #bfdbfe; font-weight: 700; margin-bottom: 4px;">SDSF Faculty Gateway</div>
        <h1 style="font-size: 20px; font-weight: 800; margin: 0;">Faculty Password Reset</h1>
        <p style="font-size: 13px; color: #e0f2fe; margin-top: 4px;">Verified Email OTP Authentication</p>
    </div>

    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert-box alert-err">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert-box alert-ok">
                <?= $success ?>
            </div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <!-- STEP 1: ENTER ENROLLMENT NO OR EMAIL -->
            <form method="POST">
                <input type="hidden" name="action_request_otp" value="1">
                <div style="margin-bottom: 18px;">
                    <label class="form-label" for="identifier">Faculty Enrollment No. or Email</label>
                    <input type="text" name="identifier" id="identifier" class="form-input" 
                           placeholder="e.g. TEST0001 or your registered email" 
                           value="<?= htmlspecialchars($identifier) ?>" required autofocus>
                    <div style="font-size: 12px; color: #64748b; margin-top: 5px;">
                        A 6-digit One-Time Password (OTP) will be dispatched to your registered email address via Brevo.
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <span>Send Verification OTP</span>
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </button>

                <div style="text-align: center; margin-top: 20px;">
                    <a href="<?= BASE_URL ?>/index.php?tab=faculty" style="color: #64748b; font-size: 13px; text-decoration: none; font-weight: 600;">
                        &larr; Return to Faculty Login
                    </a>
                </div>
            </form>
        <?php else: ?>
            <!-- STEP 2: ENTER OTP & NEW PASSWORD -->
            <form method="POST">
                <input type="hidden" name="action_reset_password" value="1">
                <input type="hidden" name="identifier" value="<?= htmlspecialchars($identifier) ?>">

                <div style="margin-bottom: 16px;">
                    <label class="form-label" for="otp">Enter 6-Digit OTP Code</label>
                    <input type="text" name="otp" id="otp" class="form-input" 
                           placeholder="Enter 6-digit code" maxlength="6" 
                           style="letter-spacing: 4px; font-weight: 800; font-family: monospace; font-size: 18px; text-align: center;" required autofocus>
                </div>

                <div style="margin-bottom: 16px;">
                    <label class="form-label" for="new_password">New Password</label>
                    <div class="input-wrap">
                        <input type="password" name="new_password" id="new_password" class="form-input" style="padding-right: 42px;"
                               placeholder="At least 4 characters" required>
                        <button type="button" class="eye-btn" onclick="togglePassVisibility('new_password', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <div style="margin-bottom: 18px;">
                    <label class="form-label" for="confirm_password">Confirm New Password</label>
                    <div class="input-wrap">
                        <input type="password" name="confirm_password" id="confirm_password" class="form-input" style="padding-right: 42px;"
                               placeholder="Re-enter new password" required>
                        <button type="button" class="eye-btn" onclick="togglePassVisibility('confirm_password', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <span>Reset &amp; Save Password</span>
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                </button>

                <div style="text-align: center; margin-top: 20px; display: flex; justify-content: space-between; font-size: 12.5px;">
                    <a href="<?= BASE_URL ?>/faculty/forgot_password.php?id=<?= urlencode($identifier) ?>" style="color: #2563eb; text-decoration: none; font-weight: 600;">
                        &larr; Resend OTP Code
                    </a>
                    <a href="<?= BASE_URL ?>/index.php?tab=faculty" style="color: #64748b; text-decoration: none; font-weight: 600;">
                        Cancel
                    </a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
function togglePassVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    const svg = btn.querySelector('svg');
    if (input.type === 'password') {
        input.type = 'text';
        svg.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
        btn.style.color = '#2563eb';
    } else {
        input.type = 'password';
        svg.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
        btn.style.color = '#94a3b8';
    }
}
</script>
</body>
</html>
