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
        $stmt = $pdo->prepare("
            SELECT s.student_name, s.roll_no, s.current_semester, ap.program_name
            FROM students s
            JOIN academic_programs ap ON ap.id = s.program_id
            WHERE s.id = ?
        ");
        $stmt->execute([$id]);
        $stData = $stmt->fetch(PDO::FETCH_ASSOC);
        $name = $stData['student_name'] ?? 'record';

        if ($stData) {
            $dedTbl = getCohortStudentTable($stData['program_name'], $stData['current_semester']);
            try {
                $pdo->exec("DELETE FROM `{$dedTbl}` WHERE roll_no = " . $pdo->quote($stData['roll_no']));
            } catch (Exception $e) {}
        }

        $del = $pdo->prepare("DELETE FROM students WHERE id = ?");
        $del->execute([$id]);

        setFlash('success', "Student <strong>" . htmlspecialchars($name) . "</strong> has been removed.");
    } catch (PDOException $e) {
        setFlash('error', "Could not delete student: " . $e->getMessage());
    }
}

header('Location: ' . $returnUrl);
exit;
