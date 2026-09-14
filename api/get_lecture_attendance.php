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

$lectureId = (int)($_GET['lecture_id'] ?? 0);

if ($lectureId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid lecture ID.']);
    exit;
}

try {
    // Get lecture info
    $stmt = $pdo->prepare("
        SELECT le.*, c.subject_name, c.course_code, c.program, c.semester, c.batch_year,
               fm.name AS faculty_name, fm.emp_code
        FROM lecture_entries le
        JOIN courses c ON c.id = le.course_id
        JOIN faculty_members fm ON fm.id = le.faculty_id
        WHERE le.id = ?
    ");
    $stmt->execute([$lectureId]);
    $lecture = $stmt->fetch();

    if (!$lecture) {
        echo json_encode(['success' => false, 'message' => 'Lecture session not found.']);
        exit;
    }

    // Faculty can only view their own lectures unless admin
    if (isFacultyLoggedIn() && !isAdminLoggedIn()) {
        $facId = (int)$_SESSION['faculty_id'];
        if ($lecture['faculty_id'] != $facId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied to this lecture record.']);
            exit;
        }
    }

    // Fetch attendance list
    $attStmt = $pdo->prepare("
        SELECT sa.id, sa.status, sa.attendance_date,
               s.id AS student_id, s.roll_no, s.enrollment_no, s.student_name
        FROM student_attendance sa
        JOIN students s ON s.id = sa.student_id
        WHERE sa.lecture_id = ?
        ORDER BY CAST(s.roll_no AS UNSIGNED) ASC, s.roll_no ASC, s.student_name ASC
    ");
    $attStmt->execute([$lectureId]);
    $records = $attStmt->fetchAll();

    $presentCount = 0;
    $absentCount = 0;
    foreach ($records as $r) {
        if ($r['status'] === 'present') {
            $presentCount++;
        } else {
            $absentCount++;
        }
    }
    $totalCount = count($records);

    echo json_encode([
        'success' => true,
        'lecture' => [
            'id' => $lecture['id'],
            'lecture_date' => $lecture['lecture_date'],
            'formatted_date' => date('d M Y', strtotime($lecture['lecture_date'])),
            'hours' => $lecture['hours'],
            'subject_name' => $lecture['subject_name'],
            'course_code' => $lecture['course_code'],
            'program' => $lecture['program'],
            'semester' => $lecture['semester'],
            'batch_year' => $lecture['batch_year'],
            'faculty_name' => $lecture['faculty_name']
        ],
        'summary' => [
            'total' => $totalCount,
            'present' => $presentCount,
            'absent' => $absentCount,
            'rate' => $totalCount > 0 ? round(($presentCount / $totalCount) * 100, 1) : 0
        ],
        'students' => $records
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
