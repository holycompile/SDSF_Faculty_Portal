<?php
/**
 * API: Delete Attendance Column
 * Permanently removes a specific attendance date column from the cohort
 * physical table AND deletes the associated lecture_entry + student_attendance rows.
 *
 * Method : POST
 * Payload: { course_id, col_name, table_name }
 * Auth   : Faculty session required (must own the course)
 */

define('ROOT', dirname(dirname(__FILE__)));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

// -- Auth
requireFaculty();
$facultyId = getFacultyId() ?? (int)($_SESSION['faculty_id'] ?? 0);
if (!$facultyId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorised.']);
    exit;
}

// -- Input
$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$courseId  = (int)($body['course_id']  ?? 0);
$colName   = trim($body['col_name']    ?? '');
$tableName = trim($body['table_name']  ?? '');

if (!$courseId || !$colName || !$tableName) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters.']);
    exit;
}

// -- Validate column name (only allow att_* pattern to prevent SQL injection)
if (!preg_match('/^att_[a-z0-9_]+$/i', $colName)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid column name.']);
    exit;
}

// -- Validate table name (only allow expected cohort table pattern)
if (!preg_match('/^students_[a-z0-9_]+$/i', $tableName)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid table name.']);
    exit;
}

// -- Verify faculty owns the course
$ownsStmt = $pdo->prepare("
    SELECT c.id, c.program, c.semester, ap.program_name
    FROM courses c
    JOIN faculty_course_assignments fca ON fca.course_id = c.id
    LEFT JOIN academic_programs ap ON ap.id = c.program_id
    WHERE c.id = ? AND fca.faculty_id = ?
    LIMIT 1
");
$ownsStmt->execute([$courseId, $facultyId]);
$course = $ownsStmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to modify this course.']);
    exit;
}

// -- Verify the column actually exists in the target table
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$colName}'")->fetchColumn();
    if (!$colCheck) {
        echo json_encode(['success' => false, 'message' => 'Column does not exist in the table.']);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Could not verify column: ' . $e->getMessage()]);
    exit;
}

// -- Determine the lecture_date encoded in the column name
// Column formats: att_c{id}_YYYY_MM_DD  or  att_c{id}_YYYY_MM_DD_s2  or  att_YYYY_MM_DD
$stripped = preg_replace('/^att_c\d+_/', '', $colName); // remove att_c{id}_
$stripped = preg_replace('/^att_/', '', $stripped);       // remove att_ (legacy)
$stripped = preg_replace('/_s\d+$/', '', $stripped);      // remove _s2 suffix
// $stripped is now YYYY_MM_DD
$dateParts = explode('_', $stripped);
$lectureDate = null;
if (count($dateParts) === 3) {
    $lectureDate = $dateParts[0] . '-' . $dateParts[1] . '-' . $dateParts[2]; // YYYY-MM-DD
}

// -- Delete from DB tables
try {
    // 1. Find lecture entries for this course on that date
    $lectureIds = [];
    if ($lectureDate) {
        $lecStmt = $pdo->prepare("
            SELECT id FROM lecture_entries
            WHERE course_id = ? AND lecture_date = ?
        ");
        $lecStmt->execute([$courseId, $lectureDate]);
        $lectureIds = $lecStmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // 2. Delete student_attendance records for those lectures
    if (!empty($lectureIds)) {
        $placeholders = implode(',', array_fill(0, count($lectureIds), '?'));
        $pdo->prepare("DELETE FROM student_attendance WHERE lecture_id IN ({$placeholders})")
            ->execute($lectureIds);

        // 3. Delete the lecture_entries themselves
        $pdo->prepare("DELETE FROM lecture_entries WHERE id IN ({$placeholders})")
            ->execute($lectureIds);
    }

    // 4. DROP the column from the cohort table (DDL - implicit commit in MySQL)
    $pdo->exec("ALTER TABLE `{$tableName}` DROP COLUMN `{$colName}`");

    echo json_encode([
        'success'          => true,
        'message'          => 'Attendance column and all associated records deleted successfully.',
        'deleted_col'      => $colName,
        'deleted_lectures' => count($lectureIds),
    ]);

} catch (Exception $e) {
    error_log("delete_attendance_column error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
