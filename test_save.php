<?php
// test_save.php - Test if data is being saved with detailed error reporting
require_once 'config/database.php';
require_once 'includes/functions.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$conn = getDB();

echo "<h2>Test Database Save</h2>";

// Check if table exists
try {
    $result = $conn->query("SHOW TABLES LIKE 'production_reports'");
    if ($result->rowCount() == 0) {
        echo "❌ production_reports table does not exist!<br>";
        echo "Please run the SQL above to create it.<br>";
        exit;
    }
    echo "✅ production_reports table exists<br>";
} catch (Exception $e) {
    echo "Error checking table: " . $e->getMessage() . "<br>";
    exit;
}

// Check if divisions exist
try {
    $divs = $conn->query("SELECT id, name FROM divisions");
    echo "<h3>Available Divisions:</h3>";
    while ($row = $divs->fetch(PDO::FETCH_ASSOC)) {
        echo "ID: " . $row['id'] . " - " . $row['name'] . "<br>";
    }
} catch (Exception $e) {
    echo "Error getting divisions: " . $e->getMessage() . "<br>";
}

// Test data with correct values
$test_data = [
    'report_date' => date('Y-m-d'),
    'devition_id' => 2, // Trouser
    'unit_id' => 1, // Front
    'ttl_sam_pc' => 5.50,
    'unit_smv' => 2.97,
    'day_forecast' => 100,
    'unit_carder' => 10,
    'plan_hours' => 10,
    'worked_hours' => 10,
    'available_minutes' => 6000,
    'plan_minutes' => 297,
    'plan_eff' => 0.05,
    'target_100' => 202,
    'hour_1' => 30,
    'hour_2' => 40,
    'hour_3' => 50,
    'hour_4' => 60,
    'hour_5' => 70,
    'hour_6' => 80,
    'hour_7' => 90,
    'hour_8' => 100,
    'hour_9' => 110,
    'hour_10' => 120,
    'hour_11' => 0,
    'day_total' => 850,
    'acvd_eff' => 0.85,
    'ern_minutes' => 2524.50
];

echo "<h3>Attempting to save test data:</h3>";
echo "<pre>";
print_r($test_data);
echo "</pre>";

// Try direct insert first to check if table structure is correct
try {
    echo "<h3>Testing direct INSERT:</h3>";
    $sql = "INSERT INTO production_reports (
        report_date, devition_id, unit_id, ttl_sam_pc, unit_smv, 
        day_forecast, unit_carder, plan_hours, worked_hours, 
        available_minutes, plan_minutes, plan_eff, target_100,
        hour_1, hour_2, hour_3, hour_4, hour_5, hour_6, 
        hour_7, hour_8, hour_9, hour_10, hour_11,
        day_total, acvd_eff, ern_minutes
    ) VALUES (
        :report_date, :devition_id, :unit_id, :ttl_sam_pc, :unit_smv,
        :day_forecast, :unit_carder, :plan_hours, :worked_hours,
        :available_minutes, :plan_minutes, :plan_eff, :target_100,
        :hour_1, :hour_2, :hour_3, :hour_4, :hour_5, :hour_6,
        :hour_7, :hour_8, :hour_9, :hour_10, :hour_11,
        :day_total, :acvd_eff, :ern_minutes
    )";
    
    $stmt = $conn->prepare($sql);
    $result = $stmt->execute($test_data);
    
    if ($result) {
        $id = $conn->lastInsertId();
        echo "✅ Direct INSERT successful! ID: " . $id . "<br>";
    } else {
        echo "❌ Direct INSERT failed.<br>";
        print_r($stmt->errorInfo());
    }
} catch (Exception $e) {
    echo "❌ Direct INSERT error: " . $e->getMessage() . "<br>";
}

// Try using the saveReportData function
echo "<h3>Testing saveReportData function:</h3>";
try {
    $result = saveReportData($conn, $test_data, 10);
    if ($result) {
        echo "✅ saveReportData successful!<br>";
    } else {
        echo "❌ saveReportData failed.<br>";
    }
} catch (Exception $e) {
    echo "❌ saveReportData error: " . $e->getMessage() . "<br>";
}

// Check if data exists
echo "<h3>Checking existing data:</h3>";
try {
    $stmt = $conn->prepare("SELECT * FROM production_reports WHERE report_date = ? AND devition_id = ?");
    $stmt->execute([date('Y-m-d'), 2]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($reports)) {
        echo "✅ Found " . count($reports) . " records for today.<br>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr>";
        foreach (array_keys($reports[0]) as $key) {
            echo "<th>" . $key . "</th>";
        }
        echo "</tr>";
        foreach ($reports as $report) {
            echo "<tr>";
            foreach ($report as $value) {
                echo "<td>" . htmlspecialchars($value) . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "❌ No records found for today.<br>";
    }
} catch (Exception $e) {
    echo "Error checking data: " . $e->getMessage() . "<br>";
}

echo "<br><a href='reports.php'>Go to Reports →</a> | ";
echo "<a href='check_db.php'>Check Database →</a>";
?>