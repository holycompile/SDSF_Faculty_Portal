<?php
/**
 * API: Delete Attendance Column
 */

// Start session FIRST — before any other include — so the cookie is read properly
if (session_status() === PHP_SESSION_NONE) session_start();

// Suppress HTML error output; catch fatals via shutdown function
ini_set('display_errors', '0');
error_reporting(E_ALL);

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level()) ob_end_clean();
        if (!headers_sent()) header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'PHP Fatal: ' . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')'
        ]);
    }
});

ob_start();

define('ROOT', dirname(dirname(__FILE__)));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
// helpers.php intentionally excluded — contains nested words() function that
// causes "Cannot redeclare" fatal on Railway persistent workers.

header('Content-Type: application/json');

// -- Auth check using raw session (avoids any session_start() re-call inside isFacultyLoggedIn)
$facultyId = isset($_SESSION['faculty_id']) ? (int)$_SESSION['faculty_id'] : 0;
if ($facultyId <= 0) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorised. Session may have expired — please refresh the page.']);
    exit;
}

// -- Input
$body      = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

$courseId  = (int)($body['course_id']  ?? 0);
$colName   = trim($body['col_name']    ?? '');
$tableName = trim($body['table_name']  ?? '');

if (!$courseId || $colName === '' || $tableName === '') {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters.']);
    exit;
}

// -- Validate to prevent SQL injection
if (!preg_match('/^att_[a-z0-9_]+$/i', $colName)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid column name.']);
    exit;
}
if (!preg_match('/^students_[a-z0-9_]+$/i', $tableName)) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid table name.']);
    exit;
}

// -- Verify faculty owns this course
try {
    $ownsStmt = $pdo->prepare("
        SELECT c.id FROM courses c
        JOIN faculty_course_assignments fca ON fca.course_id = c.id
        WHERE c.id = ? AND fca.faculty_id = ?
        LIMIT 1
    ");
    $ownsStmt->execute([$courseId, $facultyId]);
    if (!$ownsStmt->fetchColumn()) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied for this course.']);
        exit;
    }
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ownership check error: ' . $e->getMessage()]);
    exit;
}

// -- Verify the column exists
try {
    $colExists = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$colName}'")->fetchColumn();
    if (!$colExists) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Column not found in table.']);
        exit;
    }
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Column verify error: ' . $e->getMessage()]);
    exit;
}

// -- Decode lecture_date from column name
// Formats: att_c{id}_YYYY_MM_DD  |  att_c{id}_YYYY_MM_DD_s2  |  att_YYYY_MM_DD
$stripped    = preg_replace('/^att_c\d+_/', '', $colName);
$stripped    = preg_replace('/^att_/', '', $stripped);
$stripped    = preg_replace('/_s\d+$/', '', $stripped);
$parts       = explode('_', $stripped);
$lectureDate = (count($parts) === 3) ? "{$parts[0]}-{$parts[1]}-{$parts[2]}" : null;

// -- Delete DB records then DROP the physical column
try {
    $deletedLectures = 0;

    if ($lectureDate) {
        $lecStmt = $pdo->prepare("SELECT id FROM lecture_entries WHERE course_id = ? AND lecture_date = ?");
        $lecStmt->execute([$courseId, $lectureDate]);
        $lectureIds = $lecStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($lectureIds)) {
            $ph = implode(',', array_fill(0, count($lectureIds), '?'));
            $pdo->prepare("DELETE FROM student_attendance WHERE lecture_id IN ({$ph})")->execute($lectureIds);
            $pdo->prepare("DELETE FROM lecture_entries WHERE id IN ({$ph})")->execute($lectureIds);
            $deletedLectures = count($lectureIds);
        }
    }

    // DROP column — DDL causes implicit commit in MySQL, run after all DML
    $pdo->exec("ALTER TABLE `{$tableName}` DROP COLUMN `{$colName}`");

    ob_end_clean();
    echo json_encode([
        'success'          => true,
        'message'          => 'Attendance column deleted successfully.',
        'deleted_col'      => $colName,
        'deleted_lectures' => $deletedLectures,
    ]);

} catch (Exception $e) {
    error_log('delete_attendance_column error: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
