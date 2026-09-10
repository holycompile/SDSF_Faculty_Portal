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

// DELETE with transaction — cascade removes: faculty_course_assignments, lecture_entries, payment_records
try {
    $pdo->beginTransaction();
    // Clean up payment records explicitly to ensure safety regardless of FK cascade config
    $pdo->prepare("DELETE FROM payment_records WHERE faculty_id = ?")->execute([$id]);
    $del = $pdo->prepare("DELETE FROM faculty_members WHERE id = ?");
    $del->execute([$id]);
    $pdo->commit();

    setFlash('success',
        'Faculty "' . $faculty['name'] . '" (' . $faculty['faculty_enrollment_no'] . ') ' .
        'and all their records (course assignments, lecture entries) have been permanently deleted.'
    );
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    setFlash('error', 'Failed to delete faculty member: ' . $e->getMessage());
}

header('Location: ' . BASE_URL . '/admin/faculty/list.php');
exit;
