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

// Collect IDs for deletion (supports both single 'id' and bulk 'ids')
$ids = [];
$rawIds = $_POST['ids'] ?? [];

if (is_array($rawIds)) {
    foreach ($rawIds as $v) {
        $vInt = (int)$v;
        if ($vInt > 0) $ids[] = $vInt;
    }
} elseif (is_string($rawIds) && trim($rawIds) !== '') {
    foreach (explode(',', $rawIds) as $v) {
        $vInt = (int)trim($v);
        if ($vInt > 0) $ids[] = $vInt;
    }
}

$singleId = (int)($_POST['id'] ?? 0);
if ($singleId > 0 && !in_array($singleId, $ids)) {
    $ids[] = $singleId;
}

$ids = array_values(array_unique($ids));
$returnUrl = $_POST['return_url'] ?? (BASE_URL . '/admin/students/index.php');

if (!empty($ids)) {
    try {
        $inClause = implode(',', array_map('intval', $ids));
        $stmt = $pdo->query("
            SELECT s.id, s.student_name, s.roll_no, s.current_semester, ap.program_name
            FROM students s
            JOIN academic_programs ap ON ap.id = s.program_id
            WHERE s.id IN ({$inClause})
        ");
        $studentsToDelete = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Delete from corresponding dedicated cohort physical tables
        foreach ($studentsToDelete as $stData) {
            $dedTbl = getCohortStudentTable($stData['program_name'], $stData['current_semester']);
            try {
                $pdo->exec("DELETE FROM `{$dedTbl}` WHERE roll_no = " . $pdo->quote($stData['roll_no']));
            } catch (Exception $e) {}
        }

        // Delete from main students table (cascades to student_attendance)
        $del = $pdo->exec("DELETE FROM students WHERE id IN ({$inClause})");

        $count = count($studentsToDelete);
        if ($count === 1) {
            $name = $studentsToDelete[0]['student_name'] ?? 'record';
            setFlash('success', "Student <strong>" . htmlspecialchars($name) . "</strong> has been removed.");
        } else {
            setFlash('success', "Successfully removed <strong>{$count}</strong> selected students and updated cohort rosters.");
        }
    } catch (PDOException $e) {
        setFlash('error', "Could not delete selected student(s): " . $e->getMessage());
    }
} else {
    setFlash('error', "No valid students were selected for deletion.");
}

header('Location: ' . $returnUrl);
exit;
