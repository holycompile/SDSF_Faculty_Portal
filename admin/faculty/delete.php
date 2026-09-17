<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

// Only allow POST requests — never GET (prevents accidental/link deletions)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/admin/faculty/list.php');
    exit;
}

$id = (int)($_POST['faculty_id'] ?? 0);
if (!$id) {
    setFlash('error', 'Invalid faculty ID.');
    header('Location: ' . BASE_URL . '/admin/faculty/list.php');
    exit;
}

// Fetch faculty to confirm they exist and to use their name in the flash message
$stmt = $pdo->prepare("SELECT name, faculty_enrollment_no FROM faculty_members WHERE id = ?");
$stmt->execute([$id]);
$faculty = $stmt->fetch();

if (!$faculty) {
    setFlash('error', 'Faculty member not found or already deleted.');
    header('Location: ' . BASE_URL . '/admin/faculty/list.php');
    exit;
}

require_once ROOT . '/includes/archive_helper.php';

// ARCHIVE & DELETE — safely backup all faculty data into archived_faculty_records before removing from active tables
$adminUser = $_SESSION['admin_username'] ?? 'admin';
$res = archiveFaculty($pdo, $id, $adminUser);

if ($res['success']) {
    setFlash('success',
        'Faculty "' . htmlspecialchars($faculty['name']) . '" (' . htmlspecialchars($faculty['faculty_enrollment_no']) . ') ' .
        'and all their records (' . (int)$res['total_lec'] . ' lectures, ' . (float)$res['total_hrs'] . ' hrs, attendance sheets) have been safely backed up to <strong>Old Records</strong>. You can view or restore them anytime.'
    );
} else {
    setFlash('error', 'Failed to archive and delete faculty member: ' . htmlspecialchars($res['message']));
}

header('Location: ' . BASE_URL . '/admin/faculty/list.php');
exit;
