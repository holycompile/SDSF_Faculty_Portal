<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT.'/includes/auth.php';
require_once ROOT.'/includes/db.php';
require_once ROOT.'/includes/helpers.php';
if(session_status()===PHP_SESSION_NONE)session_start();
requireAdmin();
$flash = getFlash();
$courses = $pdo->query("SELECT c.*, COUNT(fca.id) AS assigned_count FROM courses c LEFT JOIN faculty_course_assignments fca ON fca.course_id=c.id GROUP BY c.id ORDER BY c.program,c.semester,c.subject_name")->fetchAll();
$active_nav='courses-list';
?><!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Courses — SDSF Admin</title>
<link rel="stylesheet" href="<?=BASE_URL?>/tailwind/output.css">
<?php require_once ROOT.'/includes/admin_sidebar.php';?></head><body>
<header class="topbar">
    <div class="tb-left"><a href="<?=BASE_URL?>/admin/dashboard.php" style="color:#94a3b8;text-decoration:none;">Dashboard</a><span class="tb-sep">/</span><span class="tb-crumb">Courses</span></div>
    <div class="tb-right"><a href="<?=BASE_URL?>/admin/courses/add.php" class="btn btn-primary btn-sm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add Course</a></div>
</header>
<div class="page">
<div class="page-header fade-up"><h1>Course Management</h1><p>All subjects available for faculty assignment (<?=count($courses)?> total)</p></div>
<?php if($flash):?><div class="alert alert-<?=$flash['type']?> fade-up"><?=htmlspecialchars($flash['msg'])?></div><?php endif;?>
<div class="card fade-up">
<div class="card-head"><div><div class="card-title">All Courses</div><div class="card-sub">Manage and assign courses to faculty members</div></div><span class="c-badge"><?=count($courses)?></span></div>
<?php if(empty($courses)):?>
<div style="padding:50px;text-align:center;color:#94a3b8;"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 12px;display:block;opacity:.4"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg><div style="font-weight:600;margin-bottom:4px;">No courses yet</div><a href="<?=BASE_URL?>/admin/courses/add.php" style="color:#4f46e5;font-size:14px;">Add the first course</a></div>
<?php else:?>
<table class="dt"><thead><tr><th>#</th><th>Program</th><th>Semester</th><th>Subject</th><th>Code</th><th>Type</th><th>Rate</th><th>Assigned To</th></tr></thead><tbody>
<?php foreach($courses as $i=>$c):?>
<tr>
<td style="color:#cbd5e1;font-size:12px;"><?=$i+1?></td>
<td style="font-weight:600;color:#0f172a;"><?=htmlspecialchars($c['program'])?></td>
<td><?=htmlspecialchars($c['semester']??'—')?></td>
<td><?=htmlspecialchars($c['subject_name'])?></td>
<td><span style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 8px;border-radius:5px;"><?=htmlspecialchars($c['course_code']??'—')?></span></td>
<td><?php if($c['class_type']==='T'):?><span class="badge badge-blue">Theory</span><?php else:?><span class="badge badge-amber">Practical</span><?php endif;?></td>
<td style="font-weight:600;color:#047857;">&#8377;<?=$c['class_type']==='T'?800:400?>/hr</td>
<td><span class="badge badge-green"><?=$c['assigned_count']?> faculty</span></td>
</tr>
<?php endforeach;?>
</tbody></table><?php endif;?>
</div></div></div></body></html>
