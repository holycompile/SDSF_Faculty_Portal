<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

// Only allow POST requests — never GET
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/admin/courses/list.php');
    exit;
}

$id = (int)($_POST['course_id'] ?? 0);
if (!$id) {
    setFlash('error', 'Invalid course ID.');
    header('Location: ' . BASE_URL . '/admin/courses/list.php');
    exit;
}

// Fetch course to confirm existence
$stmt = $pdo->prepare("SELECT subject_name, course_code FROM courses WHERE id = ?");
$stmt->execute([$id]);
$course = $stmt->fetch();

if (!$course) {
    setFlash('error', 'Course not found or already deleted.');
    header('Location: ' . BASE_URL . '/admin/courses/list.php');
    exit;
}

// Check if lectures have been logged for this course
$lecCheck = $pdo->prepare("SELECT COUNT(*) FROM lecture_entries WHERE course_id = ?");
$lecCheck->execute([$id]);
$lectureCount = (int)$lecCheck->fetchColumn();

if ($lectureCount > 0) {
    setFlash('error', "Cannot delete \"{$course['subject_name']}\" because {$lectureCount} lecture session(s) are recorded under it. Delete or reassign those sessions first.");
    header('Location: ' . BASE_URL . '/admin/courses/list.php');
    exit;
}

try {
    $pdo->beginTransaction();
    // Remove faculty course assignments
    $pdo->prepare("DELETE FROM faculty_course_assignments WHERE course_id = ?")->execute([$id]);
    // Delete the course
    $del = $pdo->prepare("DELETE FROM courses WHERE id = ?");
    $del->execute([$id]);
    $pdo->commit();

    setFlash('success', "Course \"{$course['subject_name']}\" deleted successfully.");
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    setFlash('error', 'Failed to delete course: ' . $e->getMessage());
}

header('Location: ' . BASE_URL . '/admin/courses/list.php');
exit;
