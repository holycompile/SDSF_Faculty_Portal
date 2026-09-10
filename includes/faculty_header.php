<?php
// Shared header for Faculty Portal matching SDSF DAVV Institutional Branding (Image 2 style)
if (session_status() === PHP_SESSION_NONE) session_start();
$_fName   = $_SESSION['faculty_name'] ?? 'Faculty Member';
$_fEnroll = $_SESSION['faculty_enrollment_no'] ?? '';
$_fDept   = $_SESSION['faculty_dept'] ?? 'School of Data Science and Forecasting';
$_active  = $active_nav ?? 'dashboard';
?>
<style>
    * { font-family: 'Inter', sans-serif; box-sizing: border-box; }
    body { margin: 0; background: #f1f5f9; color: #1e293b; min-height: 100vh; display: flex; flex-direction: column; }

    /* Top Strip */
    .top-strip {
        background: #111827;
        color: #94a3b8;
        font-size: 11.5px;
        font-weight: 500;
        text-align: center;
        padding: 5px 16px;
        letter-spacing: .02em;
    }

    /* Main Institutional Header */
    .univ-header {
        background: #ffffff;
        border-bottom: 3.5px solid #f59e0b;
        padding: 10px 32px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        position: sticky;
        top: 0;
        z-index: 100;
    }
    .header-left {
        display: flex;
        align-items: center;
        gap: 14px;
        text-decoration: none;
    }
    .davv-logo {
        height: 56px;
        width: auto;
        object-fit: contain;
    }
    .header-titles {
        display: flex;
        flex-direction: column;
    }
    .title-univ {
        font-size: 14px;
        font-weight: 800;
        color: #0f172a;
        letter-spacing: .01em;
        line-height: 1.25;
    }
    .title-dept {
        font-size: 12.5px;
        font-weight: 700;
        color: #1e3a8a;
        letter-spacing: .01em;
        line-height: 1.25;
        margin-top: 2px;
    }
    .title-campus {
        font-size: 11px;
        font-weight: 500;
        color: #64748b;
        margin-top: 2px;
    }

    /* Nav Links */
    .nav-links-wrap {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .nav-pill {
        padding: 8px 16px;
        border-radius: 8px;
        font-size: 13.5px;
        font-weight: 600;
        color: #475569;
        text-decoration: none;
        transition: all .18s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .nav-pill:hover {
        background: #f1f5f9;
        color: #1e3a8a;
    }
    .nav-pill.active {
        background: #1e3a8a;
        color: #ffffff;
        box-shadow: 0 2px 6px rgba(30,58,138,0.25);
    }

    /* Header Right */
    .header-right {
        display: flex;
        align-items: center;
        gap: 16px;
    }
    .dept-logo {
        height: 46px;
        width: auto;
        object-fit: contain;
    }
    .faculty-badge {
        text-align: right;
    }
    .f-name {
        font-size: 13.5px;
        font-weight: 700;
        color: #0f172a;
    }
    .f-enroll {
        font-family: monospace;
        font-size: 11.5px;
        font-weight: 700;
        color: #1e3a8a;
        background: #eef2ff;
        padding: 2px 8px;
        border-radius: 5px;
        border: 1px solid #c7d2fe;
    }
    .btn-signout {
        background: #fef2f2;
        color: #dc2626;
        border: 1.5px solid #fecaca;
        padding: 7px 13px;
        border-radius: 8px;
        font-size: 12.5px;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all .2s;
    }
    .btn-signout:hover {
        background: #fee2e2;
        border-color: #fca5a5;
    }

    @media (max-width: 900px) {
        .univ-header { flex-direction: column; gap: 12px; padding: 12px 18px; position: static; }
        .header-left, .nav-links-wrap, .header-right { width: 100%; justify-content: space-between; }
        .title-campus { display: none; }
    }
</style>

<!-- Top Strip -->
<div class="top-strip">
    &copy; SDSF &ndash; School of Data Science and Forecasting, DAVV Indore. All Rights Reserved.
</div>

<!-- Main Institutional Header -->
<header class="univ-header">
    <a href="<?= BASE_URL ?>/faculty/dashboard.php" class="header-left">
        <img src="<?= BASE_URL ?>/assets/davvLogo.png" alt="DAVV Logo" class="davv-logo">
        <div class="header-titles">
            <div class="title-univ">DEVI AHILYA VISHWAVIDYALAYA, INDORE</div>
            <div class="title-dept">SCHOOL OF DATA SCIENCE AND FORECASTING (SDSF)</div>
            <div class="title-campus">Takshila Campus, Khandwa Road, Indore - 452001 (M.P.)</div>
        </div>
    </a>

    <!-- Nav Bar -->
    <nav class="nav-links-wrap">
        <a href="<?= BASE_URL ?>/faculty/dashboard.php" class="nav-pill <?= $_active==='dashboard'?'active':'' ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            Dashboard
        </a>
        <a href="<?= BASE_URL ?>/faculty/lecture_entry.php" class="nav-pill <?= $_active==='lecture_entry'?'active':'' ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Log Lecture
        </a>
        <a href="<?= BASE_URL ?>/faculty/history.php" class="nav-pill <?= $_active==='history'?'active':'' ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Lecture History
        </a>
    </nav>

    <!-- Right info & department emblem -->
    <div class="header-right">
        <img src="<?= BASE_URL ?>/assets/departmentlogo_transparent.png" alt="SDSF Logo" class="dept-logo">
        <div class="faculty-badge">
            <div class="f-name"><?= htmlspecialchars($_fName) ?></div>
            <span class="f-enroll"><?= htmlspecialchars($_fEnroll) ?></span>
        </div>
        <a href="<?= BASE_URL ?>/faculty/logout.php" class="btn-signout" title="Sign Out">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Logout
        </a>
    </div>
</header>
