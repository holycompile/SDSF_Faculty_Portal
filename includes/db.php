<?php
// Set default timezone to Indian Standard Time (Asia/Kolkata)
date_default_timezone_set('Asia/Kolkata');

// Helper to reliably read environment variables across Apache/Docker/Railway/Local
function getEnvVar(array $keys, string $default = ''): string {
    foreach ($keys as $key) {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
        $val = getenv($key);
        if ($val !== false && $val !== '') return (string)$val;
    }
    return $default;
}

// Database configuration (supports Railway, Render, and Local XAMPP)
define('DB_HOST', getEnvVar(['MYSQLHOST', 'DB_HOST'], 'localhost'));
define('DB_PORT', getEnvVar(['MYSQLPORT', 'DB_PORT'], '3306'));
define('DB_NAME', getEnvVar(['MYSQLDATABASE', 'DB_NAME'], 'sdsf_faculty_portal'));
define('DB_USER', getEnvVar(['MYSQLUSER', 'DB_USER'], 'root'));
define('DB_PASS', getEnvVar(['MYSQLPASSWORD', 'DB_PASS'], ''));

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
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
    $hostInfo = htmlspecialchars(DB_HOST) . ':' . htmlspecialchars(DB_PORT);
    $dbInfo = htmlspecialchars(DB_NAME);
    die('<div style="font-family:system-ui,sans-serif;background:#0f172a;color:#f87171;padding:32px;border-radius:16px;max-width:650px;margin:40px auto;box-shadow:0 20px 40px rgba(0,0,0,0.3);">' .
        '<h2 style="margin:0 0 10px;color:#ef4444;font-size:20px;">Database Connection Failed</h2>' .
        '<p style="color:#cbd5e1;font-size:14px;line-height:1.6;margin:0 0 16px;"><strong>Target:</strong> ' . $hostInfo . ' / Database: <code>' . $dbInfo . '</code><br>' .
        '<strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>' .
        '<div style="background:#1e293b;border-radius:10px;padding:14px;color:#94a3b8;font-size:13px;line-height:1.5;">' .
        '💡 <strong>On Railway:</strong> Make sure <code>MYSQLHOST</code>, <code>MYSQLUSER</code>, <code>MYSQLPASSWORD</code>, <code>MYSQLPORT</code>, and <code>MYSQLDATABASE</code> are added to your service Variables.<br>' .
        '💡 <strong>On XAMPP:</strong> Ensure MySQL is running on port 3306 and database <code>sdsf_faculty_portal</code> exists.' .
        '</div></div>');
}
