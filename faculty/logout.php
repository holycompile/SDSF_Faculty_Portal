<?php
require_once dirname(dirname(__FILE__)) . '/includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
session_unset();
session_destroy();
header('Location: ' . (BASE_URL ?: '') . '/faculty_login.php');
exit;
