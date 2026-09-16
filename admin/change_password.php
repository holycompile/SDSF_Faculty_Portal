<?php
define('ROOT', dirname(dirname(__FILE__)));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$adminUser = $_SESSION['admin_username'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPass = $_POST['current_password'] ?? '';
    $newPass     = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if (empty($currentPass) || empty($newPass) || empty($confirmPass)) {
        $error = 'Please fill in all password fields.';
    } elseif (strlen($newPass) < 3) {
        $error = 'New password must be at least 3 characters long.';
    } elseif ($newPass !== $confirmPass) {
        $error = 'New password and confirmation do not match.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM admin WHERE username = ?");
        $stmt->execute([$adminUser]);
        $adm = $stmt->fetch();

        $passOk = false;
        if ($adm) {
            $passOk = ($adm['pass'] === $currentPass || password_verify($currentPass, $adm['pass']));
        }

        if (!$passOk) {
            $error = 'Your current admin password is incorrect.';
        } else {
            $upd = $pdo->prepare("UPDATE admin SET pass = ? WHERE username = ?");
            $upd->execute([$newPass, $adminUser]);

            setFlash('success', 'Admin password changed successfully!');
            header('Location: ' . BASE_URL . '/admin/dashboard.php');
            exit;
        }
    }
}

$active_nav = 'change-password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Change Admin Password — SDSF Admin</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
<style>
.form-card { max-width: 520px; margin: 0 auto; background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; padding: 28px 32px; box-shadow: 0 4px 20px rgba(0,0,0,0.04); }
.form-label { display: block; font-size: 12px; font-weight: 700; text-transform: uppercase; color: #475569; margin-bottom: 6px; }
.form-input { width: 100%; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: 14px; outline: none; }
.form-input:focus { border-color: #4f46e5; }
.btn-save { background: #4f46e5; color: #fff; border: none; border-radius: 10px; padding: 12px 24px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; }
.btn-save:hover { background: #4338ca; }
.alert-err { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 12px 16px; border-radius: 10px; font-size: 13.5px; margin-bottom: 20px; }
.input-wrap { position: relative; display: flex; align-items: center; }
.eye-btn { position: absolute; right: 12px; background: none; border: none; cursor: pointer; color: #94a3b8; padding: 4px; display: flex; align-items: center; justify-content: center; }
.eye-btn:hover { color: #4f46e5; }
@media(max-width: 480px){
    .form-card { padding: 20px 16px; }
    .btn-save { width: 100%; justify-content: center; }
}
</style>
</head>
<body>

<header class="topbar">
    <div class="tb-left">
        <span class="tb-crumb">Change Admin Password</span>
    </div>
    <div class="tb-right">
        <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-outline btn-sm">
            &larr; Back to Dashboard
        </a>
    </div>
</header>

<div class="page">
    <div class="page-header">
        <h1>Update Admin Password</h1>
        <p>Ensure the administrator account credentials remain secure</p>
    </div>

    <div class="form-card">
        <?php if ($error): ?>
            <div class="alert-err">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div style="margin-bottom: 18px;">
                <label class="form-label" for="current_password">Current Password</label>
                <div class="input-wrap">
                    <input type="password" name="current_password" id="current_password" class="form-input" style="padding-right: 42px;" required autofocus>
                    <button type="button" class="eye-btn" onclick="togglePassVisibility('current_password', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>

            <div style="margin-bottom: 18px;">
                <label class="form-label" for="new_password">New Password</label>
                <div class="input-wrap">
                    <input type="password" name="new_password" id="new_password" class="form-input" style="padding-right: 42px;" placeholder="At least 3 characters" required>
                    <button type="button" class="eye-btn" onclick="togglePassVisibility('new_password', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>

            <div style="margin-bottom: 24px;">
                <label class="form-label" for="confirm_password">Confirm New Password</label>
                <div class="input-wrap">
                    <input type="password" name="confirm_password" id="confirm_password" class="form-input" style="padding-right: 42px;" placeholder="Repeat new password" required>
                    <button type="button" class="eye-btn" onclick="togglePassVisibility('confirm_password', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;">
                <button type="submit" class="btn-save">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    <span>Update Password</span>
                </button>
                <a href="<?= BASE_URL ?>/admin/dashboard.php" style="color:#64748b;font-size:13px;text-decoration:none;">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
function togglePassVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    const svg = btn.querySelector('svg');
    if (input.type === 'password') {
        input.type = 'text';
        svg.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
        btn.style.color = '#4f46e5';
    } else {
        input.type = 'password';
        svg.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
        btn.style.color = '#94a3b8';
    }
}
</script>
</body>
</html>
