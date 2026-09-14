<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/admin/students/index.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$returnUrl = $_POST['return_url'] ?? (BASE_URL . '/admin/students/index.php');

if ($id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT student_name FROM students WHERE id = ?");
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();

        $del = $pdo->prepare("DELETE FROM students WHERE id = ?");
        $del->execute([$id]);

        setFlash('success', "Student <strong>" . htmlspecialchars($name ?? 'record') . "</strong> has been removed.");
    } catch (PDOException $e) {
        setFlash('error', "Could not delete student: " . $e->getMessage());
    }
}

header('Location: ' . $returnUrl);
exit;
