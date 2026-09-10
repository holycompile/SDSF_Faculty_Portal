<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'sdsf_faculty_portal');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die('<div style="font-family:monospace;background:#0f172a;color:#f87171;padding:24px;border-radius:12px;margin:20px;"><strong>Database Connection Failed</strong><br><br>' . htmlspecialchars($e->getMessage()) . '<br><br><small>Check XAMPP MySQL is running and database <strong>' . DB_NAME . '</strong> exists.</small></div>');
}
