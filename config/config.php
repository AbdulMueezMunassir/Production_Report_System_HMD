<?php
// config/config.php
session_start();

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'production_db');

// Application Configuration
define('SITE_NAME', 'HAMEEDIA');
define('SITE_URL', 'http://localhost/Pruction_Reports/');
define('TIMEZONE', 'Asia/Colombo');

// Set Timezone
date_default_timezone_set(TIMEZONE);

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);