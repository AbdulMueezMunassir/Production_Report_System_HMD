<?php
// config/config.php
require_once __DIR__ . '/database.php';

// Application Configuration
define('SITE_NAME', 'HAMEEDIA');
$site_url = getenv('SITE_URL');
if ($site_url === false || $site_url === '') {
    $script_directory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $site_url = rtrim($script_directory, '/') . '/';
    if ($site_url === '/') {
        $site_url = '/';
    }
}
define('SITE_URL', rtrim($site_url, '/') . '/');
define('TIMEZONE', 'Asia/Colombo');

// Set Timezone
date_default_timezone_set(TIMEZONE);

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');