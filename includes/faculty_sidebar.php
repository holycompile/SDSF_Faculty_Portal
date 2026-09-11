<?php
// Shared faculty sidebar include for SDSF Faculty Portal
// Usage: $active_nav = 'dashboard'; require ROOT.'/includes/faculty_sidebar.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$_faculty_name     = $_SESSION['faculty_name'] ?? 'Faculty Member';
$_faculty_enroll   = $_SESSION['faculty_enrollment_no'] ?? '';
$_faculty_dept     = $_SESSION['faculty_dept'] ?? 'School of Data Science and Forecasting';
$_faculty_initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $_faculty_name), 0, 2));
$_active           = $active_nav ?? 'dashboard';
?>
<style>
*{font-family:'Inter',sans-serif;box-sizing:border-box;}
:root{
    --sw:264px;
    --hh:64px;
    --bg:#f1f5f9;
    --white:#fff;
    --border:#e2e8f0;
    --border-light:#f8fafc;
    --text:#64748b;
    --bright:#0f172a;
    --accent:#1e3a8a;
    --accent-bg:#eff6ff;
    --accent-border:#bfdbfe;
    --gold:#f59e0b;
}
html,body{min-height:100vh;}
body{background:var(--bg);color:var(--text);margin:0;}
.sidebar{
    position:fixed;top:0;left:0;width:var(--sw);height:100vh;
    background:var(--white);border-right:1px solid var(--border);
    display:flex;flex-direction:column;z-index:200;overflow:hidden;
    box-shadow:4px 0 20px rgba(0,0,0,0.04);
}
.sidebar::before{
    content:'';position:absolute;top:0;left:0;right:0;height:3.5px;
    background:linear-gradient(90deg, #1e3a8a, #2563eb, #f59e0b);
}
.sb-logo{
    padding:18px 20px 14px;border-bottom:1px solid var(--border);
    display:flex;align-items:center;gap:12px;
}
.sb-logos-wrap{
    display:flex;align-items:center;gap:8px;flex-shrink:0;
}
.logo-dept{
    height:42px;width:auto;object-fit:contain;
}
.sb-title{color:var(--bright);font-size:13.5px;font-weight:800;line-height:1.2;}
.sb-sub{color:#2563eb;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-top:2px;}

.sb-nav{flex:1;padding:12px 12px;overflow-y:auto;}
.nav-section{font-size:10px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#94a3b8;padding:0 12px;margin:14px 0 5px;}
.nav-section:first-child{margin-top:4px;}
.nav-link{
    display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;
    color:#64748b;font-size:13.5px;font-weight:500;text-decoration:none;margin-bottom:2px;
    transition:all .18s;border:1px solid transparent;position:relative;
}
.nav-link:hover{background:#f8fafc;color:var(--bright);}
.nav-link.active{background:var(--accent-bg);color:#1e3a8a;border-color:var(--accent-border);font-weight:600;}
.nav-link.active::before{
    content:'';position:absolute;left:-1px;top:50%;transform:translateY(-50%);
    width:3px;height:18px;border-radius:0 3px 3px 0;
    background:linear-gradient(180deg,#1e3a8a,#2563eb);
}
.nav-icon{flex-shrink:0;opacity:.7;}
.nav-link.active .nav-icon{opacity:1;color:#1e3a8a;}

.sb-footer{padding:12px;border-top:1px solid var(--border);}
.user-card{
    display:flex;align-items:center;gap:10px;padding:10px 12px;
    border-radius:10px;background:#f8fafc;border:1px solid var(--border);margin-bottom:8px;
}
.av-badge{
    width:34px;height:34px;border-radius:9px;flex-shrink:0;
    background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;
    align-items:center;justify-content:center;color:#fff;font-size:11.5px;font-weight:700;
}
.av-name{color:var(--bright);font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px;}
.av-enroll{color:#2563eb;font-size:11px;font-weight:700;font-family:monospace;}
.logout-link{
    display:flex;align-items:center;gap:9px;width:100%;padding:9px 12px;
    border-radius:10px;background:#fff5f5;border:1px solid #fecaca;
    color:#ef4444;font-size:13px;font-weight:600;text-decoration:none;transition:all .2s;
}
.logout-link:hover{background:#fef2f2;border-color:#fca5a5;color:#dc2626;}

.main{margin-left:var(--sw);min-height:100vh;display:flex;flex-direction:column;}
.topbar{
    height:var(--hh);position:sticky;top:0;z-index:100;
    background:rgba(255,255,255,.94);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
    border-bottom:1px solid var(--border);display:flex;align-items:center;
    justify-content:space-between;padding:0 28px;box-shadow:0 1px 3px rgba(0,0,0,0.03);
}
.tb-left{display:flex;align-items:center;gap:10px;}
.tb-crumb{color:var(--bright);font-size:15px;font-weight:700;}
.tb-sep{color:#cbd5e1;font-size:18px;}
.tb-right{display:flex;align-items:center;gap:14px;}
.tb-date{color:#94a3b8;font-size:13px;font-weight:500;}

.page{padding:28px;flex:1;}
.page-header{margin-bottom:24px;}
.page-header h1{color:var(--bright);font-size:22px;font-weight:800;margin-bottom:4px;letter-spacing:-.02em;}
.page-header p{color:#94a3b8;font-size:14px;}

.card{background:var(--white);border:1px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.03);}
.card-head{padding:18px 22px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;}
.card-title{color:var(--bright);font-size:15px;font-weight:700;}
.card-sub{color:#94a3b8;font-size:12px;margin-top:2px;}
.c-badge{background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;font-size:11px;font-weight:700;padding:3px 11px;border-radius:20px;}

.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s;border:none;font-family:'Inter',sans-serif;}
.btn-primary{background:#2563eb;color:#fff;box-shadow:0 2px 8px rgba(37,99,235,0.25);}
.btn-primary:hover{background:#1d4ed8;box-shadow:0 4px 14px rgba(37,99,235,0.35);transform:translateY(-1px);}
.btn-sm{padding:6px 14px;font-size:13px;border-radius:8px;}
.btn-outline{background:#fff;color:#1e3a8a;border:1.5px solid #bfdbfe;}
.btn-outline:hover{background:#eff6ff;}

.badge{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;}
.badge-green{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;}
.badge-blue{background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;}
.badge-amber{background:#fffbeb;color:#b45309;border:1px solid #fde68a;}
.alert{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:12px;font-size:14px;margin-bottom:20px;}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#16a34a;}
.alert-error{background:#fef2f2;border:1px solid #fecaca;color:#ef4444;}
.alert-warning{background:#fffbeb;border:1px solid #fde68a;color:#b45309;}

@media(max-width:850px){
    .sidebar{display:none;}
    .main{margin-left:0;}
}
</style>

<aside class="sidebar">
    <div class="sb-logo">
        <div class="sb-logos-wrap">
            <img src="<?= BASE_URL ?>/assets/departmentlogo_transparent.png" alt="SDSF" class="logo-dept">
        </div>
        <div>
            <div class="sb-title">SDSF Portal</div>
            <div class="sb-sub">Faculty Panel</div>
        </div>
    </div>

    <nav class="sb-nav">
        <div class="nav-section">Main</div>
        <a href="<?= BASE_URL ?>/faculty/dashboard.php" class="nav-link <?= $_active==='dashboard'?'active':'' ?>">
            <svg class="nav-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>

        <div class="nav-section">Lectures & Teaching</div>
        <a href="<?= BASE_URL ?>/faculty/lecture_entry.php" class="nav-link <?= $_active==='lecture_entry'?'active':'' ?>">
            <svg class="nav-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Log New Lecture
        </a>
        <a href="<?= BASE_URL ?>/faculty/history.php" class="nav-link <?= $_active==='history'?'active':'' ?>">
            <svg class="nav-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Lecture History
        </a>

        <div class="nav-section">Official Documents</div>
        <a href="<?= BASE_URL ?>/admin/reports/annexure_iv.php" target="_blank" class="nav-link">
            <svg class="nav-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            Annexure-IV Claim Bill
        </a>
        <a href="<?= BASE_URL ?>/admin/reports/visiting_faculty_attendance.php" target="_blank" class="nav-link">
            <svg class="nav-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Teaching Attendance
        </a>
        <a href="<?= BASE_URL ?>/admin/reports/detailed_remuneration.php" target="_blank" class="nav-link">
            <svg class="nav-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            Annexure IV-A Detailed
        </a>

        <div class="nav-section">Account & Security</div>
        <a href="<?= BASE_URL ?>/faculty/change_password.php" class="nav-link <?= $_active==='change-password'?'active':'' ?>">
            <svg class="nav-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Change Password
        </a>
    </nav>

    <div class="sb-footer">
        <div class="user-card">
            <div class="av-badge"><?= $_faculty_initials ?></div>
            <div>
                <div class="av-name" title="<?= htmlspecialchars($_faculty_name) ?>"><?= htmlspecialchars($_faculty_name) ?></div>
                <div class="av-enroll"><?= htmlspecialchars($_faculty_enroll) ?></div>
            </div>
        </div>
        <a href="<?= BASE_URL ?>/faculty/logout.php" class="logout-link">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Sign Out
        </a>
    </div>
</aside>
<div class="main">
