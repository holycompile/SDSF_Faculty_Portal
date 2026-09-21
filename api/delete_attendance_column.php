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
// NOTE: helpers.php is intentionally NOT included here to avoid the nested
// function redeclaration fatal error (words() inside numberToWords()).

if (session_status() === PHP_SESSION_NONE) session_start();

// Output buffering: discard any stray PHP warnings/notices so they don't
// corrupt the JSON response.
ob_start();

header('Content-Type: application/json');

// -- Auth
if (!isFacultyLoggedIn()) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorised.']);
    exit;
}
$facultyId = (int)($_SESSION['faculty_id'] ?? 0);

// -- Input
$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$courseId  = (int)($body['course_id']  ?? 0);
$colName   = trim($body['col_name']    ?? '');
$tableName = trim($body['table_name']  ?? '');

if (!$courseId || !$colName || !$tableName) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters.']);
    exit;
}

// -- Validate column name (only allow att_* pattern to prevent SQL injection)
if (!preg_match('/^att_[a-z0-9_]+$/i', $colName)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid column name.']);
    exit;
}

// -- Validate table name (only allow expected cohort table pattern)
if (!preg_match('/^students_[a-z0-9_]+$/i', $tableName)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid table name.']);
    exit;
}

// -- Verify faculty owns the course
try {
    $ownsStmt = $pdo->prepare("
        SELECT c.id FROM courses c
        JOIN faculty_course_assignments fca ON fca.course_id = c.id
        WHERE c.id = ? AND fca.faculty_id = ?
        LIMIT 1
    ");
    $ownsStmt->execute([$courseId, $facultyId]);
    if (!$ownsStmt->fetch()) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have permission to modify this course.']);
        exit;
    }
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    exit;
}

// -- Verify the column actually exists in the target table
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$colName}'")->fetchColumn();
    if (!$colCheck) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Column does not exist in the table.']);
        exit;
    }
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Could not verify column: ' . $e->getMessage()]);
    exit;
}

// -- Determine the lecture_date encoded in the column name
// Formats: att_c{id}_YYYY_MM_DD  |  att_c{id}_YYYY_MM_DD_s2  |  att_YYYY_MM_DD
$stripped = preg_replace('/^att_c\d+_/', '', $colName); // remove att_c{id}_
$stripped = preg_replace('/^att_/', '', $stripped);       // remove att_ (legacy)
$stripped = preg_replace('/_s\d+$/', '', $stripped);      // remove _s2 suffix
// $stripped is now YYYY_MM_DD
$dateParts = explode('_', $stripped);
$lectureDate = (count($dateParts) === 3)
    ? $dateParts[0] . '-' . $dateParts[1] . '-' . $dateParts[2]
    : null;

// -- Delete from DB tables
try {
    $lectureIds = [];

    if ($lectureDate) {
        $lecStmt = $pdo->prepare("
            SELECT id FROM lecture_entries
            WHERE course_id = ? AND lecture_date = ?
        ");
        $lecStmt->execute([$courseId, $lectureDate]);
        $lectureIds = $lecStmt->fetchAll(PDO::FETCH_COLUMN);
    }

    if (!empty($lectureIds)) {
        $placeholders = implode(',', array_fill(0, count($lectureIds), '?'));
        $pdo->prepare("DELETE FROM student_attendance WHERE lecture_id IN ({$placeholders})")
            ->execute($lectureIds);
        $pdo->prepare("DELETE FROM lecture_entries WHERE id IN ({$placeholders})")
            ->execute($lectureIds);
    }

    // DROP column (DDL — implicit commit in MySQL)
    $pdo->exec("ALTER TABLE `{$tableName}` DROP COLUMN `{$colName}`");

    ob_end_clean();
    echo json_encode([
        'success'          => true,
        'message'          => 'Attendance column deleted successfully.',
        'deleted_col'      => $colName,
        'deleted_lectures' => count($lectureIds),
    ]);

} catch (Exception $e) {
    error_log("delete_attendance_column error: " . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
