<?php
// Brevo (Sendinblue) API Email Configuration
// Loads local credentials if present; otherwise falls back to environment variables

if (file_exists(__DIR__ . '/mail_config.local.php')) {
    require_once __DIR__ . '/mail_config.local.php';
}

if (!defined('BREVO_API_KEY')) {
    // Real key is set via mail_config.local.php (gitignored) or BREVO_API_KEY environment variable
    define('BREVO_API_KEY', getenv('BREVO_API_KEY') ?: '');
}
if (!defined('BREVO_SENDER_EMAIL')) {
    define('BREVO_SENDER_EMAIL', getenv('BREVO_SENDER_EMAIL') ?: 'joyobratadas.85912@gmail.com');
}
if (!defined('BREVO_SENDER_NAME')) {
    define('BREVO_SENDER_NAME', 'SDSF Faculty Portal — DAVV');
}
