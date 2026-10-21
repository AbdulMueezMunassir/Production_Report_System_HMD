<?php
// config/database.php - PDO Connection
$local_config_path = __DIR__ . '/database.local.php';
$local_config = [];
if (is_file($local_config_path)) {
    $loaded_config = require $local_config_path;
    if (!is_array($loaded_config)) {
        throw new RuntimeException('config/database.local.php must return an array.');
    }
    $local_config = $loaded_config;
}

$database_setting = static function ($environment_name, $config_key, $default) use ($local_config) {
    $environment_value = getenv($environment_name);
    $value = ($environment_value !== false && $environment_value !== '')
        ? $environment_value
        : ($local_config[$config_key] ?? $default);

    if (!is_scalar($value)) {
        throw new RuntimeException('Invalid database configuration value for ' . $config_key . '.');
    }

    return (string)$value;
};

define('DB_HOST', $database_setting('DB_HOST', 'host', 'localhost'));
define('DB_USER', $database_setting('DB_USER', 'user', 'root'));
define('DB_PASS', $database_setting('DB_PASS', 'pass', ''));
define('DB_NAME', $database_setting('DB_NAME', 'name', 'production_db'));

function getDB() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        throw new RuntimeException(
            'Database connection failed. Verify DB_HOST, DB_NAME, DB_USER, and DB_PASS.',
            0,
            $e
        );
    }
}

// For backward compatibility
function getDBConnection() {
    return getDB();
}

if (session_status() === PHP_SESSION_NONE) {
    // Stay logged in for 7 days (smart screen / refresh should never log out)
    $session_lifetime = 60 * 60 * 24 * 7;

    // Own session folder so other XAMPP apps / default cleanup don't delete our sessions
    $session_dir = __DIR__ . '/../sessions';
    if (!is_dir($session_dir)) {
        @mkdir($session_dir, 0700, true);
        @file_put_contents($session_dir . '/.htaccess', "Require all denied\n");
    }
    if (is_dir($session_dir) && is_writable($session_dir)) {
        session_save_path($session_dir);
    }

    ini_set('session.gc_maxlifetime', (string)$session_lifetime);
    session_set_cookie_params([
        'lifetime' => $session_lifetime,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}
?>