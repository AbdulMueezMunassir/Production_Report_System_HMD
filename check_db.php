<?php
// check_db.php - Check database structure
require_once 'config/database.php';

$conn = getDB();

echo "<h2>Database Structure Check</h2>";

// Check if production_reports table exists
try {
    $result = $conn->query("SHOW TABLES LIKE 'production_reports'");
    if ($result->rowCount() > 0) {
        echo "✅ production_reports table exists<br><br>";
        
        // Show table structure
        echo "<h3>Table Structure:</h3>";
        $columns = $conn->query("DESCRIBE production_reports");
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        while ($row = $columns->fetch(PDO::FETCH_ASSOC)) {
            echo "<tr>";
            echo "<td>" . $row['Field'] . "</td>";
            echo "<td>" . $row['Type'] . "</td>";
            echo "<td>" . $row['Null'] . "</td>";
            echo "<td>" . $row['Key'] . "</td>";
            echo "<td>" . $row['Default'] . "</td>";
            echo "</tr>";
        }
        echo "</table><br>";
        
        // Check if there's any data
        $count = $conn->query("SELECT COUNT(*) as count FROM production_reports")->fetch(PDO::FETCH_ASSOC);
        echo "Total records: " . $count['count'] . "<br>";
        
        if ($count['count'] > 0) {
            echo "<h3>Sample Data:</h3>";
            $sample = $conn->query("SELECT * FROM production_reports LIMIT 3");
            echo "<table border='1' cellpadding='5'>";
            $first = true;
            while ($row = $sample->fetch(PDO::FETCH_ASSOC)) {
                if ($first) {
                    echo "<tr>";
                    foreach (array_keys($row) as $key) {
                        echo "<th>" . $key . "</th>";
                    }
                    echo "</tr>";
                    $first = false;
                }
                echo "<tr>";
                foreach ($row as $value) {
                    echo "<td>" . htmlspecialchars($value) . "</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
        }
    } else {
        echo "❌ production_reports table does not exist!<br>";
        echo "Please run install.php to create the database tables.";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

echo "<br><a href='install.php'>Run Installer</a> | ";
echo "<a href='test_save.php'>Test Save Again</a>";
?>