<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_POST['ajax']) && $_POST['ajax'] == '1');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
        exit;
    }
    header('Location: ' . BASE_URL . '/admin/courses/list.php');
    exit;
}

$programId = (int)($_POST['program_id'] ?? 0);
$semNum    = (int)($_POST['semester_number'] ?? 0);
$yearTag   = trim($_POST['year_tag'] ?? '');
$returnUrl = $_POST['return_url'] ?? (BASE_URL . '/admin/courses/list.php');

if ($programId <= 0 || $semNum <= 0) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid program or semester number.']);
        exit;
    }
    setFlash('error', 'Invalid program or semester.');
    header('Location: ' . $returnUrl);
    exit;
}

$suf = 'th';
if ($semNum === 1) $suf = 'st';
elseif ($semNum === 2) $suf = 'nd';
elseif ($semNum === 3) $suf = 'rd';
$semName = "{$semNum}{$suf} Semester";

try {
    // 1. Update or insert into semester_tags
    $stmt = $pdo->prepare("
        INSERT INTO semester_tags (program_id, semester_number, semester_name, year_tag)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE year_tag = VALUES(year_tag), semester_name = VALUES(semester_name)
    ");
    $stmt->execute([$programId, $semNum, $semName, $yearTag]);

    // 2. Synchronize batch_year in courses for this semester
    $cStmt = $pdo->prepare("
        UPDATE courses 
        SET batch_year = ? 
        WHERE program_id = ? AND (semester_number = ? OR semester = ?)
    ");
    $cStmt->execute([$yearTag, $programId, $semNum, $semName]);

    // 3. Synchronize batch_year in students enrolled in this semester
    $sStmt = $pdo->prepare("
        UPDATE students 
        SET batch_year = ? 
        WHERE program_id = ? AND (current_semester = ? OR current_semester = ?)
    ");
    $sStmt->execute([$yearTag, $programId, $semName, (string)$semNum]);

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Semester tag updated successfully.',
            'program_id' => $programId,
            'semester_number' => $semNum,
            'semester_name' => $semName,
            'year_tag' => $yearTag
        ]);
        exit;
    }

    setFlash('success', "Year tag for <strong>{$semName}</strong> updated to <strong>" . htmlspecialchars($yearTag) . "</strong>!");
    header('Location: ' . $returnUrl);
    exit;
} catch (PDOException $e) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
    setFlash('error', 'Failed to update semester tag: ' . $e->getMessage());
    header('Location: ' . $returnUrl);
    exit;
}
