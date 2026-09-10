content = r'''<?php
require_once '../includes/auth.php';
requireAdmin();
require_once '../includes/db.php';
''' + r'''
$''' + r'''adminCount   = (int) $''' + r'''pdo->query("SELECT COUNT(*) FROM admin")->fetchColumn();
$''' + r'''facultyCount = 0;
try {
    $''' + r'''facultyCount = (int) $''' + r'''pdo->query("SELECT COUNT(*) FROM faculty_members")->fetchColumn();
} catch (Exception $''' + r'''e) { $''' + r'''facultyCount = 0; }
$''' + r'''admins = $''' + r'''pdo->query("SELECT * FROM admin")->fetchAll();
$''' + r'''currentAdmin = $''' + r'''_SESSION["admin_username"];
$''' + r'''initials = strtoupper(substr(preg_replace("/\s+/", "", $''' + r'''currentAdmin), 0, 2));
$''' + r'''today = date("l, d M Y");
$''' + r'''greeting = (date("H") < 12 ? "Morning" : (date("H") < 17 ? "Afternoon" : "Evening"));
?>placeholder_html'''

with open(r'C:\xampp\htdocs\SDSF_Faculty_Portal\admin\dashboard.php', 'w', encoding='utf-8') as f:
    f.write(content)
print("Written successfully:", len(content))
