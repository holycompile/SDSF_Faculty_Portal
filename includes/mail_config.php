<?php
// Brevo (Sendinblue) API Email Configuration
// Loads local credentials if present; otherwise falls back to environment variables

if (file_exists(__DIR__ . '/mail_config.local.php')) {
    require_once __DIR__ . '/mail_config.local.php';
}

if (!function_exists('getMailEnvVar')) {
    function getMailEnvVar(string $key, string $default = ''): string {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
        $val = getenv($key);
        if ($val !== false && $val !== '') return (string)$val;
        return $default;
    }
}

if (!defined('BREVO_API_KEY')) {
    // Real key is set via mail_config.local.php (gitignored) or BREVO_API_KEY environment variable
    define('BREVO_API_KEY', getMailEnvVar('BREVO_API_KEY', ''));
}
if (!defined('BREVO_SENDER_EMAIL')) {
    define('BREVO_SENDER_EMAIL', getMailEnvVar('BREVO_SENDER_EMAIL', 'joyobratadas.85912@gmail.com'));
}
if (!defined('BREVO_SENDER_NAME')) {
    define('BREVO_SENDER_NAME', getMailEnvVar('BREVO_SENDER_NAME', 'SDSF Faculty Portal — DAVV'));
}
