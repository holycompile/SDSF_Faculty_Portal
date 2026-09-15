<?php
// Usage: $active_nav = 'dashboard'; require ROOT.'/includes/admin_sidebar.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$_admin_user     = $_SESSION['admin_username'] ?? 'Admin';
$_admin_initials = strtoupper(substr(preg_replace('/\s+/', '', $_admin_user), 0, 2));
$_active         = $active_nav ?? '';

function _navLink(string $href, string $icon_path, string $label, string $active_key, string $_active): string {
    $cls = ($_active === $active_key) ? 'nav-link active' : 'nav-link';
    return "<a href=\"{$href}\" class=\"{$cls}\">{$icon_path}{$label}</a>";
}
?>
<style>
*{font-family:'Inter',sans-serif;box-sizing:border-box;}
:root{--sw:264px;--hh:64px;--bg:#f1f5f9;--white:#fff;--border:#e2e8f0;--border-light:#f1f5f9;--text:#64748b;--bright:#0f172a;--accent:#4f46e5;--accent-bg:#eef2ff;--accent-border:#c7d2fe;}
html,body{min-height:100vh;}
body{background:var(--bg);color:var(--text);margin:0;}
.sidebar{position:fixed;top:0;left:0;width:var(--sw);height:100vh;background:var(--white);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:200;overflow:hidden;box-shadow:4px 0 20px rgba(0,0,0,0.04);}
.sidebar::before{content:'';position:absolute;top:0;left:0;right:0;height:3.5px;background:linear-gradient(90deg,#1e3a8a,#4f46e5,#f59e0b);}
.sb-logo{padding:16px 18px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;}
.sb-logos-wrap{display:flex;align-items:center;gap:8px;flex-shrink:0;}
.logo-dept{height:42px;width:auto;object-fit:contain;}
.sb-title{color:var(--bright);font-size:13.5px;font-weight:800;line-height:1.2;}
.sb-sub{color:#4f46e5;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-top:2px;}
.sb-nav{flex:1;padding:10px 12px;overflow-y:auto;}
.nav-section{font-size:10px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#94a3b8;padding:0 12px;margin:14px 0 5px;}
.nav-section:first-child{margin-top:4px;}
.nav-link{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;color:#64748b;font-size:13.5px;font-weight:500;text-decoration:none;margin-bottom:2px;transition:all .18s;border:1px solid transparent;}
.nav-link:hover{background:#f8fafc;color:var(--bright);}
.nav-link.active{background:var(--accent-bg);color:var(--accent);border-color:var(--accent-border);}
.nav-link.active::before{content:'';position:absolute;left:-1px;top:50%;transform:translateY(-50%);width:3px;height:18px;border-radius:0 3px 3px 0;background:linear-gradient(180deg,#4f46e5,#7c3aed);}
.nav-link{position:relative;}
.nav-icon{flex-shrink:0;opacity:.65;}
.nav-link.active .nav-icon{opacity:1;}
.nav-sub{display:flex;flex-direction:column;gap:2px;margin-left:2px;}
.nav-sub .nav-link{font-size:13px;padding:8px 12px 8px 34px;}
.sb-footer{padding:12px;border-top:1px solid var(--border-light);}
.admin-card{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;background:#f8fafc;border:1px solid var(--border);margin-bottom:8px;}
.av-badge{width:34px;height:34px;border-radius:9px;flex-shrink:0;background:linear-gradient(135deg,#4f46e5,#7c3aed);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:700;}
.av-name{color:var(--bright);font-size:13px;font-weight:600;}
.av-role{color:#6366f1;font-size:11px;font-weight:500;}
.logout-link{display:flex;align-items:center;gap:9px;width:100%;padding:10px 12px;border-radius:10px;background:#fff5f5;border:1px solid #fecaca;color:#ef4444;font-size:13px;font-weight:500;text-decoration:none;transition:all .2s;}
.logout-link:hover{background:#fef2f2;border-color:#fca5a5;color:#dc2626;}
.main{margin-left:var(--sw);min-height:100vh;display:flex;flex-direction:column;}
.topbar{height:var(--hh);position:sticky;top:0;z-index:100;background:rgba(255,255,255,.92);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 28px;box-shadow:0 1px 3px rgba(0,0,0,0.04);}
.tb-left{display:flex;align-items:center;gap:10px;}
.tb-crumb{color:var(--bright);font-size:15px;font-weight:600;}
.tb-sep{color:#cbd5e1;font-size:18px;}
.tb-right{display:flex;align-items:center;gap:10px;}
.tb-date{color:#94a3b8;font-size:13px;}
.tb-icon-btn{width:36px;height:36px;border-radius:9px;background:#f8fafc;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;cursor:pointer;color:#64748b;transition:all .2s;text-decoration:none;}
.tb-icon-btn:hover{background:#f1f5f9;color:var(--bright);}
.tb-avatar{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#4f46e5,#7c3aed);display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(79,70,229,0.3);}
.page{padding:28px;flex:1;}
.page-header{margin-bottom:24px;}
.page-header h1{color:var(--bright);font-size:22px;font-weight:800;margin-bottom:4px;letter-spacing:-.02em;}
.page-header p{color:#94a3b8;font-size:14px;}
.card{background:var(--white);border:1px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.04);}
.card-head{padding:18px 22px;border-bottom:1px solid var(--border-light);display:flex;align-items:center;justify-content:space-between;}
.card-title{color:var(--bright);font-size:15px;font-weight:600;}
.card-sub{color:#94a3b8;font-size:12px;margin-top:3px;}
.c-badge{background:var(--accent-bg);border:1px solid var(--accent-border);color:var(--accent);font-size:11px;font-weight:700;padding:3px 11px;border-radius:20px;}
table.dt{width:100%;border-collapse:collapse;}
.dt th{padding:11px 20px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;border-bottom:1px solid var(--border-light);background:#fafafa;}
.dt td{padding:14px 20px;font-size:14px;color:#334155;border-bottom:1px solid #f8fafc;transition:background .15s;}
.dt tr:last-child td{border-bottom:none;}
.dt tr:hover td{background:#fafafe;}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s;border:none;font-family:'Inter',sans-serif;}
.btn-primary{background:linear-gradient(135deg,#4f46e5,#6d5ce7);color:#fff;box-shadow:0 2px 10px rgba(79,70,229,0.25);}
.btn-primary:hover{box-shadow:0 4px 18px rgba(79,70,229,0.4);transform:translateY(-1px);}
.btn-sm{padding:6px 14px;font-size:13px;border-radius:8px;}
.btn-outline{background:#fff;color:#4f46e5;border:1.5px solid #c7d2fe;}
.btn-outline:hover{background:#eef2ff;}
.btn-danger{background:#fef2f2;color:#ef4444;border:1.5px solid #fecaca;}
.btn-danger:hover{background:#fff5f5;}
.badge{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;}
.badge-green{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;}
.badge-red{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;}
.badge-blue{background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;}
.badge-amber{background:#fffbeb;color:#b45309;border:1px solid #fde68a;}
.form-group{margin-bottom:18px;}
.form-label{display:block;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#475569;margin-bottom:7px;}
.form-input,.form-select,.form-textarea{width:100%;padding:11px 14px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:11px;color:var(--bright);font-size:14.5px;font-family:'Inter',sans-serif;transition:all .2s;outline:none;}
.form-input:focus,.form-select:focus,.form-textarea:focus{background:#fff;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,0.1);}
.form-input::placeholder,.form-textarea::placeholder{color:#94a3b8;}
.form-textarea{resize:vertical;min-height:80px;}
.section-title{font-size:13px;font-weight:700;color:#4f46e5;text-transform:uppercase;letter-spacing:.08em;margin-bottom:16px;padding-bottom:8px;border-bottom:2px solid #eef2ff;}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;}
.alert{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:12px;font-size:14px;margin-bottom:20px;}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#16a34a;}
.alert-error{background:#fef2f2;border:1px solid #fecaca;color:#ef4444;}
.alert-info{background:#eff6ff;border:1px solid #bfdbfe;color:#2563eb;}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.fade-up{animation:fadeUp .4s ease both;}
</style>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<aside class="sidebar">
    <div class="sb-logo">
        <div class="sb-logos-wrap">
            <img src="<?= BASE_URL ?>/assets/departmentlogo_transparent.png" alt="SDSF" class="logo-dept">
        </div>
        <div>
            <div class="sb-title">SDSF Portal</div>
            <div class="sb-sub">Admin Panel</div>
        </div>
    </div>

    <nav class="sb-nav">
        <div class="nav-section">Main</div>
        <a href="<?= BASE_URL ?>/admin/dashboard.php" class="nav-link <?= $_active==='dashboard'?'active':'' ?>">
            <svg class="nav-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>

        <div class="nav-section">Faculty</div>
        <a href="<?= BASE_URL ?>/admin/faculty/list.php" class="nav-link <?= $_active==='faculty-list'?'active':'' ?>">
            <svg class="nav-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            All Faculty
        </a>
        <a href="<?= BASE_URL ?>/admin/faculty/register.php" class="nav-link <?= $_active==='faculty-register'?'active':'' ?>">
            <svg class="nav-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
            Register Faculty
        </a>

        <div class="nav-section">Courses &amp; Curriculum</div>
        <a href="<?= BASE_URL ?>/admin/courses/list.php" class="nav-link <?= $_active==='courses-list'?'active':'' ?>">
            <svg class="nav-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
            Course Curriculum
        </a>
        <a href="<?= BASE_URL ?>/admin/courses/programs.php" class="nav-link <?= $_active==='courses-programs'?'active':'' ?>">
            <svg class="nav-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
            Programs &amp; Batches
        </a>
        <a href="<?= BASE_URL ?>/admin/courses/add.php" class="nav-link <?= $_active==='courses-add'?'active':'' ?>">
            <svg class="nav-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
            Add Subject
        </a>
        <div class="nav-section">Students &amp; Attendance</div>
        <a href="<?= BASE_URL ?>/admin/students/index.php" class="nav-link <?= $_active==='students-list'?'active':'' ?>">
            <svg class="nav-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Student Rosters
        </a>
        <a href="<?= BASE_URL ?>/admin/students/attendance.php" class="nav-link <?= $_active==='students-attendance'?'active':'' ?>">
            <svg class="nav-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            Attendance Logs
        </a>

        <div class="nav-section">Records</div>
        <a href="<?= BASE_URL ?>/admin/lectures/overview.php" class="nav-link <?= $_active==='lectures'?'active':'' ?>">
            <svg class="nav-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></svg>
            Lecture Records
        </a>
        <a href="<?= BASE_URL ?>/admin/lectures/course_view.php" class="nav-link <?= $_active==='lectures-course-view'?'active':'' ?>">
            <svg class="nav-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            Course-wise View
        </a>

        <div class="nav-section">Account & Security</div>
        <a href="<?= BASE_URL ?>/admin/change_password.php" class="nav-link <?= $_active==='change-password'?'active':'' ?>">
            <svg class="nav-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Change Password
        </a>
    </nav>

    <div class="sb-footer">
        <div class="admin-card">
            <div class="av-badge"><?= $_admin_initials ?></div>
            <div>
                <div class="av-name"><?= htmlspecialchars($_admin_user) ?></div>
                <div class="av-role">Administrator</div>
            </div>
        </div>
        <a href="<?= BASE_URL ?>/admin/logout.php" class="logout-link">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Sign Out
        </a>
    </div>
</aside>
<div class="main">
