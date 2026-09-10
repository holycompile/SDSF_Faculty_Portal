<?php
define('ROOT', dirname(dirname(__FILE__)));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$totalFaculty      = (int) $pdo->query("SELECT COUNT(*) FROM faculty_members")->fetchColumn();
$totalCourses      = (int) $pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn();
$totalLectures     = (int) $pdo->query("SELECT COUNT(*) FROM lecture_entries")->fetchColumn();
$totalRemuneration = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM lecture_entries")->fetchColumn();

$recentFaculty = $pdo->query("
    SELECT fm.*, 
        COUNT(DISTINCT fca.course_id) AS course_count,
        COALESCE(SUM(le.amount),0)    AS total_earned
    FROM faculty_members fm
    LEFT JOIN faculty_course_assignments fca ON fca.faculty_id = fm.id
    LEFT JOIN lecture_entries le ON le.faculty_id = fm.id
    GROUP BY fm.id
    ORDER BY fm.created_at DESC LIMIT 6
")->fetchAll();

$flash    = getFlash();
$today    = date('l, d M Y');
$greeting = (date('H') < 12 ? 'Morning' : (date('H') < 17 ? 'Afternoon' : 'Evening'));
$active_nav = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — SDSF Admin Portal</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
<style>
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;}
.s-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:22px;position:relative;overflow:hidden;transition:transform .22s,box-shadow .22s;box-shadow:0 1px 3px rgba(0,0,0,0.04);animation:fadeUp .5s ease both;}
.s-card:hover{transform:translateY(-3px);box-shadow:0 12px 32px rgba(0,0,0,0.1);}
.s-card:nth-child(1){animation-delay:.05s}.s-card:nth-child(2){animation-delay:.1s}.s-card:nth-child(3){animation-delay:.15s}.s-card:nth-child(4){animation-delay:.2s}
.s-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3.5px;border-radius:16px 0 0 16px;}
.s-card.indigo::before{background:linear-gradient(180deg,#4f46e5,#818cf8);}
.s-card.teal::before{background:linear-gradient(180deg,#0d9488,#2dd4bf);}
.s-card.amber::before{background:linear-gradient(180deg,#b45309,#fbbf24);}
.s-card.emerald::before{background:linear-gradient(180deg,#047857,#34d399);}
.s-icon{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;margin-bottom:14px;}
.s-icon.indigo{background:#eef2ff;color:#4f46e5;}.s-icon.teal{background:#f0fdfa;color:#0d9488;}.s-icon.amber{background:#fffbeb;color:#b45309;}.s-icon.emerald{background:#f0fdf4;color:#047857;}
.s-val{font-size:30px;font-weight:800;color:#0f172a;line-height:1;margin-bottom:5px;}
.s-label{font-size:13px;font-weight:500;color:#64748b;margin-bottom:8px;}
.s-tag{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px;}
.s-tag.green{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;}
.s-tag.muted{background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;}
.welcome{background:linear-gradient(135deg,#4f46e5 0%,#6d5ce7 60%,#7c3aed 100%);border-radius:20px;padding:24px 28px;display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;gap:16px;box-shadow:0 8px 28px rgba(79,70,229,0.22);position:relative;overflow:hidden;}
.welcome::before{content:'';position:absolute;top:-40px;right:-40px;width:200px;height:200px;background:rgba(255,255,255,0.07);border-radius:50%;}
.welcome h2{color:#fff;font-size:20px;font-weight:700;margin-bottom:5px;}
.welcome p{color:rgba(255,255,255,.72);font-size:14px;}
.status-chip{display:flex;align-items:center;gap:7px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.2);border-radius:50px;padding:9px 18px;white-space:nowrap;color:#fff;font-size:13px;font-weight:600;position:relative;z-index:1;}
.dot-green{width:7px;height:7px;border-radius:50%;background:#86efac;box-shadow:0 0 8px rgba(134,239,172,.9);animation:blink 2s ease infinite;}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.35}}
.quick-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:24px;}
.q-btn{display:flex;align-items:center;gap:12px;padding:16px 20px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;text-decoration:none;transition:all .2s;box-shadow:0 1px 3px rgba(0,0,0,0.04);}
.q-btn:hover{border-color:#c7d2fe;box-shadow:0 4px 16px rgba(79,70,229,0.12);transform:translateY(-1px);}
.q-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.q-title{color:#0f172a;font-size:14px;font-weight:600;}
.q-sub{color:#94a3b8;font-size:12px;margin-top:2px;}
.mini-av{width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,#4f46e5,#7c3aed);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:700;flex-shrink:0;}
.content-row{display:grid;grid-template-columns:1fr 320px;gap:16px;}
</style>
</head>
<body>
<header class="topbar">
    <div class="tb-left">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
        <span class="tb-sep">/</span><span class="tb-crumb">Dashboard</span>
    </div>
    <div class="tb-right">
        <span class="tb-date"><?= $today ?></span>
        <div class="tb-avatar"><?= strtoupper(substr(preg_replace('/\s+/','',$_SESSION['admin_username']),0,2)) ?></div>
    </div>
</header>
<div class="page">
    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> fade-up"><?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>

    <!-- Welcome -->
    <div class="welcome fade-up">
        <div style="display:flex;align-items:center;gap:18px;">
            <div>
                <h2>Good <?= $greeting ?>, <?= htmlspecialchars($_SESSION['admin_username']) ?> &#128075;</h2>
                <p>SDSF &ndash; School of Data Science and Forecasting &bull; DAVV Administration Console</p>
            </div>
        </div>
        <div class="status-chip"><div class="dot-green"></div>All Systems Operational</div>
    </div>

    <!-- Stats -->
    <div class="stats">
        <div class="s-card indigo">
            <div class="s-icon indigo"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
            <div class="s-val" id="n-faculty">0</div>
            <div class="s-label">Visiting Faculty</div>
            <span class="s-tag muted">Registered</span>
        </div>
        <div class="s-card teal">
            <div class="s-icon teal"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg></div>
            <div class="s-val" id="n-courses">0</div>
            <div class="s-label">Active Courses</div>
            <span class="s-tag muted">In System</span>
        </div>
        <div class="s-card amber">
            <div class="s-icon amber"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></svg></div>
            <div class="s-val" id="n-lectures">0</div>
            <div class="s-label">Lecture Entries</div>
            <span class="s-tag green">&#8593; Logged</span>
        </div>
        <div class="s-card emerald">
            <div class="s-icon emerald"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
            <div class="s-val" style="font-size:22px;padding-top:4px;">&#8377;<?= number_format($totalRemuneration,0) ?></div>
            <div class="s-label">Total Remuneration</div>
            <span class="s-tag green">Payable</span>
        </div>
    </div>

    <!-- Quick actions -->
    <div class="quick-grid fade-up">
        <a href="<?= BASE_URL ?>/admin/faculty/register.php" class="q-btn">
            <div class="q-icon" style="background:#eef2ff;color:#4f46e5;"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg></div>
            <div><div class="q-title">Register Faculty</div><div class="q-sub">Add a new visiting faculty</div></div>
        </a>
        <a href="<?= BASE_URL ?>/admin/courses/add.php" class="q-btn">
            <div class="q-icon" style="background:#f0fdfa;color:#0d9488;"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg></div>
            <div><div class="q-title">Add Course</div><div class="q-sub">Create a new subject/course</div></div>
        </a>
        <a href="<?= BASE_URL ?>/admin/lectures/overview.php" class="q-btn">
            <div class="q-icon" style="background:#fffbeb;color:#b45309;"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></svg></div>
            <div><div class="q-title">Lecture Records</div><div class="q-sub">View all lecture entries</div></div>
        </a>
        <a href="<?= BASE_URL ?>/admin/faculty/list.php" class="q-btn">
            <div class="q-icon" style="background:#f0fdf4;color:#047857;"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
            <div><div class="q-title">View All Faculty</div><div class="q-sub">Browse & manage faculty</div></div>
        </a>
    </div>

    <!-- Recent faculty -->
    <div class="card fade-up">
        <div class="card-head">
            <div><div class="card-title">Recent Faculty Registrations</div><div class="card-sub">Latest registered visiting faculty members</div></div>
            <a href="<?= BASE_URL ?>/admin/faculty/list.php" class="btn btn-outline btn-sm">View All</a>
        </div>
        <?php if (empty($recentFaculty)): ?>
        <div style="padding:40px;text-align:center;color:#94a3b8;">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 12px;display:block;opacity:.4"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            <div style="font-weight:600;margin-bottom:4px;">No faculty registered yet</div>
            <div style="font-size:13px;">Start by <a href="<?= BASE_URL ?>/admin/faculty/register.php" style="color:#4f46e5;">registering a faculty member</a></div>
        </div>
        <?php else: ?>
        <table class="dt">
            <thead><tr><th>Enrollment No.</th><th>Name</th><th>Department</th><th>Courses</th><th>Earned</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($recentFaculty as $f): ?>
            <tr>
                <td><span style="font-family:monospace;font-size:13px;background:#eef2ff;color:#4f46e5;padding:2px 8px;border-radius:6px;"><?= htmlspecialchars($f['faculty_enrollment_no']) ?></span></td>
                <td><div style="display:flex;align-items:center;gap:9px;"><div class="mini-av"><?= strtoupper(substr(preg_replace('/\s+/','',$f['name']),0,2)) ?></div><div style="color:#0f172a;font-weight:500;"><?= htmlspecialchars($f['name']) ?></div></div></td>
                <td><?= htmlspecialchars($f['department'] ?? '—') ?></td>
                <td><span class="badge badge-blue"><?= $f['course_count'] ?> course<?= $f['course_count']!=1?'s':'' ?></span></td>
                <td style="color:#047857;font-weight:600;">&#8377;<?= number_format($f['total_earned'],0) ?></td>
                <td><a href="<?= BASE_URL ?>/admin/faculty/view.php?id=<?= $f['id'] ?>" class="btn btn-outline btn-sm">View</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
</div>
<script>
function animCount(id,target){const el=document.getElementById(id);if(!el)return;if(!target){el.textContent=0;return;}let c=0,s=Math.max(1,Math.ceil(target/25));const t=setInterval(()=>{c=Math.min(c+s,target);el.textContent=c;if(c>=target)clearInterval(t);},30);}
document.addEventListener('DOMContentLoaded',()=>{
    animCount('n-faculty',<?= $totalFaculty ?>);
    animCount('n-courses',<?= $totalCourses ?>);
    animCount('n-lectures',<?= $totalLectures ?>);
});
</script>
</body></html>
