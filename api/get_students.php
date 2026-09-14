<?php
define('ROOT', dirname(dirname(__FILE__)));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isFacultyLoggedIn() && !isAdminLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$courseId = (int)($_GET['course_id'] ?? 0);
$programId = (int)($_GET['program_id'] ?? 0);
$semester = trim($_GET['semester'] ?? '');

try {
    if ($courseId > 0) {
        $cStmt = $pdo->prepare("SELECT id, program_id, program, batch_year, semester, semester_number, subject_name, course_code FROM courses WHERE id = ?");
        $cStmt->execute([$courseId]);
        $course = $cStmt->fetch();

        if (!$course) {
            echo json_encode(['success' => false, 'message' => 'Course not found.']);
            exit;
        }

        $programId = (int)$course['program_id'];
        $semester = $course['semester'];
        $batchYear = $course['batch_year'];
        $progName = $course['program'];
    } elseif ($programId > 0 && !empty($semester)) {
        $pStmt = $pdo->prepare("SELECT id, program_name, batch_year FROM academic_programs WHERE id = ?");
        $pStmt->execute([$programId]);
        $prog = $pStmt->fetch();

        if (!$prog) {
            echo json_encode(['success' => false, 'message' => 'Program not found.']);
            exit;
        }

        $batchYear = $prog['batch_year'];
        $progName = $prog['program_name'];
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters provided.']);
        exit;
    }

    // Query students matching program and semester
    $sStmt = $pdo->prepare("
        SELECT id, roll_no, enrollment_no, student_name, status, batch_year, current_semester
        FROM students
        WHERE program_id = ? AND (current_semester = ? OR current_semester = ?) AND status = 'active'
        ORDER BY CAST(roll_no AS UNSIGNED) ASC, roll_no ASC, student_name ASC
    ");
    // Match either e.g. "1st Semester" or "1"
    $semNum = (int)filter_var($semester, FILTER_SANITIZE_NUMBER_INT);
    $sStmt->execute([$programId, $semester, (string)$semNum]);
    $students = $sStmt->fetchAll();

    echo json_encode([
        'success' => true,
        'program_id' => $programId,
        'program_name' => $progName,
        'semester' => $semester,
        'batch_year' => $batchYear,
        'total_students' => count($students),
        'students' => $students
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
