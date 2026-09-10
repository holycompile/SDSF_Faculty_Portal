<?php
require_once __DIR__ . '/includes/auth.php';
header('Location: ' . (BASE_URL ?: '') . '/index.php?tab=admin');
exit;
