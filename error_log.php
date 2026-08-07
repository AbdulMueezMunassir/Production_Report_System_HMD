<?php
// error_log.php - Check PHP error log
echo "<h2>📋 PHP Error Log</h2>";
$log_file = ini_get('error_log');
if ($log_file && file_exists($log_file)) {
    echo "<p>Error log location: <code>$log_file</code></p>";
    echo "<pre style='background:#f5f5f5; padding:15px; overflow:auto; max-height:500px;'>";
    $lines = file($log_file);
    $last_lines = array_slice($lines, -50);
    foreach ($last_lines as $line) {
        if (strpos($line, 'Pruction_Reports') !== false || strpos($line, 'production') !== false) {
            echo htmlspecialchars($line);
        }
    }
    echo "</pre>";
} else {
    echo "<p>Error log not found or not accessible.</p>";
}
?>