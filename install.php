<?php
// install.php - Run this once to install the database
require_once 'config/database.php';

$conn = getDBConnection();

echo "<h2>🔧 Installing Production Report System</h2>";

// Read and execute SQL file
$sql = file_get_contents('install.sql');

if ($conn->multi_query($sql)) {
    do {
        // Store first result set
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->next_result());
    
    echo "✅ Database installed successfully!<br>";
    echo "✅ Tables created successfully!<br>";
    echo "✅ Admin user created: <strong>admin</strong> / <strong>admin123</strong><br>";
} else {
    echo "❌ Error installing: " . $conn->error . "<br>";
}

echo "<br><a href='index.php'>Go to Login →</a>";
$conn->close();
?>