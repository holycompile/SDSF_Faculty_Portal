<?php
if (!defined('BASE_URL')) {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($scriptName, '/SDSF_Faculty_Portal') === 0 || strpos($requestUri, '/SDSF_Faculty_Portal') === 0) {
        define('BASE_URL', '/SDSF_Faculty_Portal');
    } else {
        define('BASE_URL', '');
    }
}
define('THEORY_RATE',    800);
define('PRACTICAL_RATE', 400);

function requireAdmin() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['admin_username'])) {
        header('Location: ' . BASE_URL . '/admin_login.php');
        exit;
    }
}

function requireFaculty() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['faculty_id'])) {
        header('Location: ' . BASE_URL . '/faculty_login.php');
        exit;
    }
}

function isAdminLoggedIn(): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return !empty($_SESSION['admin_username']);
}

function isFacultyLoggedIn(): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return !empty($_SESSION['faculty_id']);
}

function getFacultyId(): ?int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return isset($_SESSION['faculty_id']) ? (int)$_SESSION['faculty_id'] : null;
}

function getRate(string $classType, ?float $customTheory = null, ?float $customPractical = null): float {
    if ($classType === 'T') {
        return ($customTheory !== null && $customTheory > 0) ? $customTheory : (float)THEORY_RATE;
    } else {
        return ($customPractical !== null && $customPractical > 0) ? $customPractical : (float)PRACTICAL_RATE;
    }
}

function calcAmount(float $hours, string $classType, ?float $customTheory = null, ?float $customPractical = null): float {
    return $hours * getRate($classType, $customTheory, $customPractical);
}
