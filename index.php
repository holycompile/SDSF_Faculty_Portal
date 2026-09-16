<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// If already logged in, redirect to respective dashboard
if (isset($_SESSION['admin_username'])) {
    header('Location: ' . (BASE_URL ?: '') . '/admin/dashboard.php');
    exit;
}
if (isset($_SESSION['faculty_id'])) {
    header('Location: ' . (BASE_URL ?: '') . '/faculty/dashboard.php');
    exit;
}

$activeTab = $_GET['tab'] ?? 'faculty';
if ($activeTab !== 'admin' && $activeTab !== 'faculty') {
    $activeTab = 'faculty';
}

$facultyError = '';
$adminError   = '';
$flash        = getFlash();

// Handle Login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginType = $_POST['login_type'] ?? 'faculty';

    if ($loginType === 'admin') {
        $activeTab = 'admin';
        $username = trim($_POST['username'] ?? '');
        $pass     = $_POST['pass'] ?? '';

        if ($username && $pass) {
            $stmt = $pdo->prepare("SELECT * FROM admin WHERE username = ?");
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            $adminValid = false;
            if ($admin) {
                if ($admin['pass'] === $pass || password_verify($pass, $admin['pass'])) {
                    $adminValid = true;
                }
            }

            if ($admin && $adminValid) {
                session_regenerate_id(true);
                $_SESSION['admin_username'] = $admin['username'];
                header('Location: ' . (BASE_URL ?: '') . '/admin/dashboard.php');
                exit;
            } else {
                $adminError = 'Invalid admin username or password.';
            }
        } else {
            $adminError = 'Please enter both username and password.';
        }
    } else {
        // Faculty Login
        $activeTab = 'faculty';
        $enrollment = strtoupper(trim($_POST['enrollment_no'] ?? ''));
        $password   = $_POST['password'] ?? '';

        if ($enrollment && $password) {
            $stmt = $pdo->prepare("SELECT * FROM faculty_members WHERE faculty_enrollment_no = ? AND status = 'active'");
            $stmt->execute([$enrollment]);
            $faculty = $stmt->fetch();

            $passwordValid = false;
            if ($faculty) {
                if (empty($faculty['password'])) {
                    $passwordValid = ($password === 'hello' || $password === 'SDSF@' . substr($enrollment, -4));
                } else {
                    if (password_verify($password, $faculty['password'])) {
                        $passwordValid = true;
                    } elseif ($password === $faculty['password']) {
                        $passwordValid = true;
                        // Auto-upgrade plain text to secure hash
                        $newHash = password_hash($password, PASSWORD_DEFAULT);
                        $upd = $pdo->prepare("UPDATE faculty_members SET password = ? WHERE id = ?");
                        $upd->execute([$newHash, $faculty['id']]);
                    }
                }
            }

            if ($faculty && $passwordValid) {
                session_regenerate_id(true);
                $_SESSION['faculty_id']            = $faculty['id'];
                $_SESSION['faculty_enrollment_no'] = $faculty['faculty_enrollment_no'];
                $_SESSION['faculty_name']          = $faculty['name'];
                $_SESSION['faculty_dept']          = $faculty['department'];
                header('Location: ' . (BASE_URL ?: '') . '/faculty/dashboard.php');
                exit;
            } else {
                $facultyError = 'Invalid Enrollment Number or Password. Try again or reset via Email OTP.';
            }
        } else {
            $facultyError = 'Please enter both Enrollment Number and Password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SDSF Faculty & Admin Portal — Devi Ahilya Vishwavidyalaya, Indore</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
    <style>
        * { font-family: 'Inter', sans-serif; box-sizing: border-box; }
        body { margin: 0; background: #f1f5f9; color: #1e293b; min-height: 100vh; display: flex; flex-direction: column; }

        /* Very top banner */
        .top-strip {
            background: #111827;
            color: #94a3b8;
            font-size: 11.5px;
            font-weight: 500;
            text-align: center;
            padding: 6px 16px;
            letter-spacing: .02em;
        }

        /* University Header */
        .univ-header {
            background: #ffffff;
            border-bottom: 3.5px solid #f59e0b;
            padding: 12px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .davv-logo {
            height: 64px;
            width: auto;
            object-fit: contain;
        }
        .header-titles {
            display: flex;
            flex-direction: column;
        }
        .title-univ {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: .01em;
            line-height: 1.25;
        }
        .title-dept {
            font-size: 13.5px;
            font-weight: 700;
            color: #1e3a8a;
            letter-spacing: .01em;
            line-height: 1.25;
            margin-top: 2px;
        }
        .title-campus {
            font-size: 11.5px;
            font-weight: 500;
            color: #64748b;
            margin-top: 2px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 18px;
        }
        .dept-logo {
            height: 52px;
            width: auto;
            object-fit: contain;
        }
        .btn-univ {
            background: #1e293b;
            color: #ffffff;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all .2s;
        }
        .btn-univ:hover {
            background: #0f172a;
            box-shadow: 0 4px 12px rgba(15,23,42,0.25);
            transform: translateY(-1px);
        }

        /* Main Content Container */
        .page-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px 60px;
        }

        /* Portal Login Card */
        .portal-card {
            width: 100%;
            max-width: 540px;
            background: #ffffff;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 10px 30px -5px rgba(15,23,42,0.08), 0 0 0 1px rgba(15,23,42,0.05);
        }

        /* Dark Navy Banner */
        .card-banner {
            background: #172033;
            border-bottom: 4px solid #f59e0b;
            padding: 26px 28px 22px;
            text-align: center;
        }
        .workspace-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(245,158,11,0.12);
            border: 1px solid rgba(245,158,11,0.3);
            border-radius: 20px;
            padding: 4px 14px;
            color: #fbbf24;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        .portal-title {
            color: #ffffff;
            font-size: 23px;
            font-weight: 800;
            letter-spacing: .04em;
            margin: 0 0 5px;
            text-transform: uppercase;
        }
        .portal-sub {
            color: #94a3b8;
            font-size: 13px;
            margin: 0;
        }

        /* Card Body */
        .card-body {
            padding: 28px 36px 36px;
        }

        /* Tab Switcher */
        .tab-wrap {
            background: #f1f5f9;
            padding: 4px;
            border-radius: 12px;
            display: flex;
            gap: 4px;
            margin-bottom: 26px;
        }
        .tab-btn {
            flex: 1;
            padding: 10px 14px;
            border-radius: 9px;
            font-size: 13.5px;
            font-weight: 600;
            color: #64748b;
            background: transparent;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all .2s;
        }
        .tab-btn.active {
            background: #ffffff;
            color: #2563eb;
            font-weight: 700;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
        }

        /* Form Titles */
        .form-heading {
            text-align: center;
            margin-bottom: 22px;
        }
        .form-heading h2 {
            font-size: 21px;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 4px;
        }
        .form-heading p {
            font-size: 13.5px;
            color: #64748b;
            margin: 0 0 6px;
        }
        .eligibility-tag {
            display: inline-block;
            color: #2563eb;
            font-size: 12.5px;
            font-weight: 600;
        }

        /* Form Inputs */
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #334155;
            margin-bottom: 7px;
        }
        .form-label span.req {
            color: #ef4444;
        }
        .input-box {
            position: relative;
        }
        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            display: flex;
            pointer-events: none;
        }
        .form-input {
            width: 100%;
            padding: 12px 14px 12px 42px;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            font-size: 14.5px;
            color: #0f172a;
            font-family: 'Inter', sans-serif;
            outline: none;
            transition: all .2s;
        }
        .form-input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3.5px rgba(37,99,235,0.12);
        }
        .form-input::placeholder {
            color: #94a3b8;
            font-size: 13.5px;
        }
        .form-help {
            font-size: 12px;
            color: #64748b;
            margin-top: 6px;
        }
        .eye-toggle-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #94a3b8;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            transition: all .2s;
        }
        .eye-toggle-btn:hover {
            color: #1e293b;
        }

        /* Submit Button */
        .btn-submit {
            width: 100%;
            padding: 13px 20px;
            background: #2563eb;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 14.5px;
            font-weight: 700;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all .2s;
            box-shadow: 0 4px 12px rgba(37,99,235,0.28);
            margin-top: 10px;
        }
        .btn-submit:hover {
            background: #1d4ed8;
            box-shadow: 0 6px 18px rgba(37,99,235,0.38);
            transform: translateY(-1px);
        }

        /* Error Alert */
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #ef4444;
            padding: 11px 14px;
            border-radius: 10px;
            font-size: 13.5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Mobile & Responsive Adjustments */
        @media (max-width: 768px) {
            .univ-header {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
                padding: 12px 16px;
            }
            .header-left {
                gap: 12px;
            }
            .davv-logo {
                height: 48px;
            }
            .title-univ {
                font-size: 13px;
                line-height: 1.2;
            }
            .title-dept {
                font-size: 11.5px;
                line-height: 1.2;
            }
            .title-campus {
                font-size: 10.5px;
            }
            .header-right {
                width: 100%;
                justify-content: flex-end;
            }
            .dept-logo {
                height: 42px;
            }
            .page-content {
                padding: 20px 12px 40px;
            }
            .card-banner {
                padding: 20px 18px 18px;
            }
            .portal-title {
                font-size: 20px;
            }
            .card-body {
                padding: 20px 16px 28px;
            }
            .tab-btn {
                padding: 11px 8px;
                font-size: 12.5px;
                gap: 6px;
            }
            .btn-submit {
                padding: 12px;
            }
        }

        @media (max-width: 480px) {
            .top-strip {
                font-size: 10.5px;
                padding: 5px 10px;
                line-height: 1.35;
            }
            .davv-logo {
                height: 42px;
            }
            .title-univ {
                font-size: 12px;
            }
            .title-dept {
                font-size: 10.5px;
            }
            .title-campus {
                display: none;
            }
            .portal-title {
                font-size: 18px;
            }
            .tab-wrap {
                gap: 2px;
                padding: 3px;
            }
            .tab-btn {
                font-size: 11.5px;
                padding: 10px 4px;
            }
            .tab-btn svg {
                width: 14px;
                height: 14px;
            }
            .form-heading h2 {
                font-size: 19px;
            }
            .form-input {
                font-size: 13.5px;
                padding-left: 38px;
            }
            .input-icon {
                left: 11px;
            }
        }
    </style>
</head>
<body>
    <!-- Top Strip -->
    <div class="top-strip">
        &copy; SDSF &ndash; School of Data Science and Forecasting, DAVV Indore. All Rights Reserved.
    </div>

    <!-- University Header -->
    <header class="univ-header">
        <div class="header-left">
            <img src="<?= BASE_URL ?>/assets/davvLogo.png" alt="DAVV Logo" class="davv-logo">
            <div class="header-titles">
                <div class="title-univ">DEVI AHILYA VISHWAVIDYALAYA, INDORE</div>
                <div class="title-dept">SCHOOL OF DATA SCIENCE AND FORECASTING (SDSF)</div>
                <div class="title-campus">Takshila Campus, Khandwa Road, Indore - 452001 (M.P.)</div>
            </div>
        </div>
        <div class="header-right">
            <img src="<?= BASE_URL ?>/assets/departmentlogo_transparent.png" alt="SDSF Logo" class="dept-logo">
            
        </div>
    </header>

    <!-- Page Content -->
    <main class="page-content">
        <div class="portal-card">
            <!-- Navy Banner -->
            <div class="card-banner">
                <div class="workspace-pill">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                    SDSF WORKSPACE
                </div>
                <h1 class="portal-title">FACULTY PORTAL</h1>
                <p class="portal-sub">Welcome to the School of Data Science and Forecasting Faculty Gateway</p>
            </div>

            <div class="card-body">
                <?php if ($flash): ?>
                    <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:20px;display:flex;align-items:center;gap:10px;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        <span><?= $flash['msg'] ?></span>
                    </div>
                <?php endif; ?>

                <!-- Tab Switcher -->
                <div class="tab-wrap">
                    <button type="button" class="tab-btn <?= $activeTab === 'faculty' ? 'active' : '' ?>" onclick="switchTab('faculty')">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                        Faculty Login
                    </button>
                    <button type="button" class="tab-btn <?= $activeTab === 'admin' ? 'active' : '' ?>" onclick="switchTab('admin')">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Admin Login
                    </button>
                </div>

                <!-- Faculty Login Tab Pane -->
                <div id="pane-faculty" style="display: <?= $activeTab === 'faculty' ? 'block' : 'none' ?>;">
                    <div class="form-heading">
                        <h2>Faculty Portal Log In</h2>
                        <p>Access your lecture log, course assignments and remuneration dashboard</p>
                        <span class="eligibility-tag">Visiting & Regular Faculty Members</span>
                    </div>

                    <?php if ($facultyError): ?>
                        <div class="alert-error">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <span><?= htmlspecialchars($facultyError) ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?= BASE_URL ?>/index.php">
                        <input type="hidden" name="login_type" value="faculty">
                        <div class="form-group">
                            <label class="form-label" for="enrollment_no">ENROLLMENT NUMBER <span class="req">*</span></label>
                            <div class="input-box">
                                <span class="input-icon">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><line x1="15" y1="8" x2="17" y2="8"/><line x1="15" y1="12" x2="17" y2="12"/><line x1="7" y1="16" x2="17" y2="16"/></svg>
                                </span>
                                <input type="text" name="enrollment_no" id="enrollment_no" class="form-input" 
                                       placeholder="E.G. TEST0001, MANI0001..." 
                                       style="text-transform: uppercase;" required autofocus>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="faculty_pass">PASSWORD <span class="req">*</span></label>
                            <div class="input-box">
                                <span class="input-icon">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                </span>
                                <input type="password" name="password" id="faculty_pass" class="form-input" style="padding-right: 44px;" 
                                       placeholder="Enter Faculty Password" required>
                                <button type="button" class="eye-toggle-btn" onclick="togglePassVisibility('faculty_pass', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                            </div>
                        </div>

                        <div style="display:flex;justify-content:flex-end;margin-top:-4px;margin-bottom:14px;">
                            <a href="<?= BASE_URL ?>/faculty/forgot_password.php" style="font-size:12.5px;color:#2563eb;text-decoration:none;font-weight:600;">
                                Forgot Password? Reset via Email OTP &rarr;
                            </a>
                        </div>

                        <button type="submit" class="btn-submit">
                            <span>Log In</span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        </button>
                    </form>
                </div>

                <!-- Admin Login Tab Pane -->
                <div id="pane-admin" style="display: <?= $activeTab === 'admin' ? 'block' : 'none' ?>;">
                    <div class="form-heading">
                        <h2>Admin Portal Log In</h2>
                        <p>Access faculty registration, course allocation & remuneration billing</p>
                        <span class="eligibility-tag" style="color: #4f46e5;">Authorized University Administrators</span>
                    </div>

                    <?php if ($adminError): ?>
                        <div class="alert-error">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <span><?= htmlspecialchars($adminError) ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?= BASE_URL ?>/index.php">
                        <input type="hidden" name="login_type" value="admin">
                        <div class="form-group">
                            <label class="form-label" for="username">USERNAME <span class="req">*</span></label>
                            <div class="input-box">
                                <span class="input-icon">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                </span>
                                <input type="text" name="username" id="username" class="form-input" 
                                       placeholder="Enter Admin Username" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="pass">PASSWORD <span class="req">*</span></label>
                            <div class="input-box">
                                <span class="input-icon">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                </span>
                                <input type="password" name="pass" id="admin_pass" class="form-input" style="padding-right: 44px;" 
                                       placeholder="Enter Admin Password" required>
                                <button type="button" class="eye-toggle-btn" onclick="togglePassVisibility('admin_pass', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                            </div>
                        </div>

                        <div style="display:flex;justify-content:flex-end;margin-top:-4px;margin-bottom:14px;">
                            <a href="<?= BASE_URL ?>/admin/forgot_password.php" style="font-size:12.5px;color:#4f46e5;text-decoration:none;font-weight:600;">
                                Forgot Admin Password? Reset via Email OTP &rarr;
                            </a>
                        </div>

                        <button type="submit" class="btn-submit" style="background: #4f46e5;">
                            <span>Log In</span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script>
    function switchTab(type) {
        const paneFaculty = document.getElementById('pane-faculty');
        const paneAdmin   = document.getElementById('pane-admin');
        const btns = document.querySelectorAll('.tab-btn');

        if (type === 'faculty') {
            paneFaculty.style.display = 'block';
            paneAdmin.style.display = 'none';
            btns[0].classList.add('active');
            btns[1].classList.remove('active');
            const inp = document.getElementById('enrollment_no');
            if (inp) inp.focus();
        } else {
            paneFaculty.style.display = 'none';
            paneAdmin.style.display = 'block';
            btns[0].classList.remove('active');
            btns[1].classList.add('active');
            const inp = document.getElementById('username');
            if (inp) inp.focus();
        }
    }

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
