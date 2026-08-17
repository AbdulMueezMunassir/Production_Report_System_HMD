<?php
// install.php - Fixed version with proper foreign key handling
require_once 'config/database.php';

$conn = getDBConnection();

echo "<h2>🔧 Installing Production Report System</h2>";

// Disable foreign key checks
$conn->query("SET FOREIGN_KEY_CHECKS = 0");

// Create users table
$users_table = "CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    role ENUM('admin', 'user') DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
if ($conn->query($users_table)) {
    echo "✅ Users table created<br>";
}

// Create admin user
$check = $conn->query("SELECT id FROM users WHERE username = 'admin'");
if ($check->num_rows === 0) {
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $username, $hash, $full_name, $role);
    $username = 'admin';
    $full_name = 'Administrator';
    $role = 'admin';
    $stmt->execute();
    echo "✅ Admin user created (admin/admin123)<br>";
}

// Create divisions table
$divisions_table = "CREATE TABLE IF NOT EXISTS divisions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL,
    code VARCHAR(20) NOT NULL,
    type ENUM('shirt', 'trouser', 'coat', 'shirt_mtm', 'trouser_mtm', 'coat_mtm', 'assembly') DEFAULT 'shirt',
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
if ($conn->query($divisions_table)) {
    echo "✅ Divisions table created<br>";
}

// Drop existing components table to recreate
$conn->query("DROP TABLE IF EXISTS components");
echo "✅ Dropped existing components table<br>";

// Create components table
$components_table = "CREATE TABLE components (
    id INT PRIMARY KEY AUTO_INCREMENT,
    division_id INT,
    name VARCHAR(50) NOT NULL,
    is_match_out BOOLEAN DEFAULT FALSE,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE CASCADE
)";
if ($conn->query($components_table)) {
    echo "✅ Components table created<br>";
}

// Drop existing production_reports table
$conn->query("DROP TABLE IF EXISTS production_reports");
echo "✅ Dropped existing production_reports table<br>";

// Create production_reports table
$reports_table = "CREATE TABLE production_reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    report_date DATE NOT NULL,
    devition_id INT,
    unit_id INT,
    ttl_sam_pc DECIMAL(10,2) DEFAULT 0,
    unit_smv DECIMAL(10,2) DEFAULT 0,
    day_forecast DECIMAL(10,2) DEFAULT 0,
    unit_carder INT DEFAULT 0,
    plan_hours DECIMAL(5,2) DEFAULT 0,
    worked_hours DECIMAL(5,2) DEFAULT 0,
    available_minutes DECIMAL(10,2) DEFAULT 0,
    plan_minutes DECIMAL(10,2) DEFAULT 0,
    plan_eff DECIMAL(5,2) DEFAULT 0,
    target_100 DECIMAL(10,2) DEFAULT 0,
    hour_1 DECIMAL(10,2) DEFAULT 0,
    hour_2 DECIMAL(10,2) DEFAULT 0,
    hour_3 DECIMAL(10,2) DEFAULT 0,
    hour_4 DECIMAL(10,2) DEFAULT 0,
    hour_5 DECIMAL(10,2) DEFAULT 0,
    hour_6 DECIMAL(10,2) DEFAULT 0,
    hour_7 DECIMAL(10,2) DEFAULT 0,
    hour_8 DECIMAL(10,2) DEFAULT 0,
    hour_9 DECIMAL(10,2) DEFAULT 0,
    hour_10 DECIMAL(10,2) DEFAULT 0,
    hour_11 DECIMAL(10,2) DEFAULT 0,
    day_total DECIMAL(10,2) DEFAULT 0,
    acvd_eff DECIMAL(5,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_date (report_date),
    INDEX idx_devition (devition_id),
    INDEX idx_unit (unit_id)
)";
if ($conn->query($reports_table)) {
    echo "✅ Production reports table created<br>";
}

// Clear existing divisions
$conn->query("DELETE FROM divisions");
echo "✅ Cleared existing divisions<br>";

// Insert divisions
$divisions = [
    ['Shirt', 'SH', 'shirt', 1],
    ['Trouser', 'TR', 'trouser', 2],
    ['Coat', 'CT', 'coat', 3],
    ['Shirt MTM', 'SHM', 'shirt_mtm', 4],
    ['Trouser MTM', 'TRM', 'trouser_mtm', 5],
    ['Coat MTM', 'CTM', 'coat_mtm', 6],
    ['Shirt Assembly', 'SHA', 'assembly', 7],
    ['Trouser Assembly', 'TRA', 'assembly', 8],
    ['Coat Assembly', 'CTA', 'assembly', 9]
];
foreach ($divisions as $div) {
    $stmt = $conn->prepare("INSERT INTO divisions (name, code, type, display_order) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("sssi", $div[0], $div[1], $div[2], $div[3]);
    $stmt->execute();
}
echo "✅ Divisions inserted<br>";

// Insert components for Trouser (division_id = 2)
$components = [
    [2, 'Front', 0, 1],
    [2, 'Back', 0, 2],
    [2, 'Band', 0, 3],
    [2, 'Match Out', 1, 4]
];
foreach ($components as $comp) {
    $stmt = $conn->prepare("INSERT INTO components (division_id, name, is_match_out, display_order) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isii", $comp[0], $comp[1], $comp[2], $comp[3]);
    $stmt->execute();
}
echo "✅ Trouser components inserted<br>";

// Insert components for Shirt (division_id = 1)
$components = [
    [1, 'Front', 0, 1],
    [1, 'Back', 0, 2],
    [1, 'Sleeve', 0, 3],
    [1, 'Collar', 0, 4],
    [1, 'Cuff', 0, 5],
    [1, 'Match Out', 1, 6]
];
foreach ($components as $comp) {
    $stmt = $conn->prepare("INSERT INTO components (division_id, name, is_match_out, display_order) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isii", $comp[0], $comp[1], $comp[2], $comp[3]);
    $stmt->execute();
}
echo "✅ Shirt components inserted<br>";

// Insert components for Coat (division_id = 3)
$components = [
    [3, 'Front', 0, 1],
    [3, 'Back', 0, 2],
    [3, 'Sleeve', 0, 3],
    [3, 'Collar', 0, 4],
    [3, 'Match Out', 1, 5]
];
foreach ($components as $comp) {
    $stmt = $conn->prepare("INSERT INTO components (division_id, name, is_match_out, display_order) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isii", $comp[0], $comp[1], $comp[2], $comp[3]);
    $stmt->execute();
}
echo "✅ Coat components inserted<br>";

// Insert components for Assembly (division_id = 7, 8, 9)
$assembly = [
    [7, 'SHIRT', 0, 1],
    [8, 'TROUSER', 0, 1],
    [9, 'COAT', 0, 1]
];
foreach ($assembly as $comp) {
    $stmt = $conn->prepare("INSERT INTO components (division_id, name, is_match_out, display_order) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isii", $comp[0], $comp[1], $comp[2], $comp[3]);
    $stmt->execute();
}
echo "✅ Assembly components inserted<br>";

// Re-enable foreign key checks
$conn->query("SET FOREIGN_KEY_CHECKS = 1");

echo "<br><h3>✅ Installation Complete!</h3>";
echo "<p>Login credentials:</p>";
echo "<ul>";
echo "<li><strong>Username:</strong> admin</li>";
echo "<li><strong>Password:</strong> admin123</li>";
echo "</ul>";
echo "<br><a href='index.php' style='display:inline-block; padding:10px 30px; background:#217346; color:#fff; text-decoration:none; border-radius:8px;'>Go to Login →</a>";

$conn->close();
?>