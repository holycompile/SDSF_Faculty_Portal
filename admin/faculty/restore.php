<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
require_once ROOT . '/includes/archive_helper.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/admin/faculty/archives.php');
    exit;
}

$archiveId = (int)($_POST['archive_id'] ?? 0);
if (!$archiveId) {
    setFlash('error', 'Invalid archive record ID.');
    header('Location: ' . BASE_URL . '/admin/faculty/archives.php');
    exit;
}

$res = restoreFaculty($pdo, $archiveId);

if ($res['success']) {
    setFlash('success',
        'Faculty member "' . htmlspecialchars($res['name']) . '" (' . htmlspecialchars($res['enrollment']) . ') ' .
        'has been successfully restored to active status! All courses, lecture records, student attendance, and login access are active.'
    );
    header('Location: ' . BASE_URL . '/admin/faculty/view.php?id=' . (int)$res['faculty_id']);
    exit;
} else {
    setFlash('error', 'Restoration failed: ' . htmlspecialchars($res['message']));
    header('Location: ' . BASE_URL . '/admin/faculty/archives.php');
    exit;
}
