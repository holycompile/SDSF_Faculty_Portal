<?php
// Brevo (Sendinblue) API Email Configuration
// Loads local credentials if present; otherwise falls back to environment variables

if (file_exists(__DIR__ . '/mail_config.local.php')) {
    require_once __DIR__ . '/mail_config.local.php';
}

if (!defined('BREVO_API_KEY')) {
    define('BREVO_API_KEY', getenv('BREVO_API_KEY') ?: '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');
}
if (!defined('BREVO_SENDER_EMAIL')) {
    define('BREVO_SENDER_EMAIL', getenv('BREVO_SENDER_EMAIL') ?: 'joyobratadas.85912@gmail.com');
}
if (!defined('BREVO_SENDER_NAME')) {
    define('BREVO_SENDER_NAME', 'SDSF Faculty Portal — DAVV');
}
