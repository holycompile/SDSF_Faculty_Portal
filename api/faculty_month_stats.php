<?php
/**
 * AJAX endpoint: get month stats for a faculty member
 * Returns JSON: { hours, amount, sessions, month_label }
 */
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

// Must be admin OR the faculty member themselves
$isAdmin   = !empty($_SESSION['admin_username']);
$isFaculty = !empty($_SESSION['faculty_id']);

if (!$isAdmin && !$isFaculty) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$facultyId = (int)($_GET['faculty_id'] ?? 0);
$month     = (int)($_GET['month'] ?? 0);
$year      = (int)($_GET['year'] ?? 0);

// Faculty can only query themselves
if ($isFaculty && !$isAdmin) {
    $facultyId = (int)$_SESSION['faculty_id'];
}

if (!$facultyId || $month < 1 || $month > 12 || $year < 2020 || $year > 2035) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(hours), 0) as m_hours,
           COALESCE(SUM(amount), 0) as m_amount,
           COUNT(*) as m_count
    FROM lecture_entries
    WHERE faculty_id = ? AND MONTH(lecture_date) = ? AND YEAR(lecture_date) = ?
");
$stmt->execute([$facultyId, $month, $year]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

echo json_encode([
    'hours'       => (float)$row['m_hours'],
    'amount'      => (float)$row['m_amount'],
    'sessions'    => (int)$row['m_count'],
    'month_label' => ($monthNames[$month] ?? '') . ' ' . $year,
]);
