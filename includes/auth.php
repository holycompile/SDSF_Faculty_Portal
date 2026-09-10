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

function getRate(string $classType): int {
    return ($classType === 'T') ? THEORY_RATE : PRACTICAL_RATE;
}

function calcAmount(float $hours, string $classType): float {
    return $hours * getRate($classType);
}
