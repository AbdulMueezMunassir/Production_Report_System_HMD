<?php
// setup_db.php - Run this ONCE to create the database and tables (Fixed for MariaDB)
require_once 'config/database.php';

$pdo = getDB();

echo "<h2>🔧 Installing HAMEEDIA Production Database</h2>";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(100),
            role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
            status TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    echo "✅ Users table created.<br>";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS report_date_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_date DATE UNIQUE NOT NULL,
            working_hours INT NOT NULL DEFAULT 10,
            target_efficiency DECIMAL(5,4) NOT NULL DEFAULT 0.9000,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        )
    ");
    echo "✅ Report Date Settings table created.<br>";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS devotion_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            slug VARCHAR(50) UNIQUE NOT NULL,
            type ENUM('garment', 'assembly') DEFAULT 'garment',
            status TINYINT(1) DEFAULT 1,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "✅ Devotion Types table created.<br>";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS devotion_components (
            id INT AUTO_INCREMENT PRIMARY KEY,
            devotion_id INT NOT NULL,
            component_name VARCHAR(100) NOT NULL,
            component_code VARCHAR(50),
            is_match_out TINYINT(1) DEFAULT 0,
            is_dhu TINYINT(1) DEFAULT 0,
            sort_order INT DEFAULT 0,
            status TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (devotion_id) REFERENCES devotion_types(id) ON DELETE CASCADE
        )
    ");
    echo "✅ Devotion Components table created.<br>";

    // FIXED: Removed the unsupported 'WHERE status = 'draft'' constraint
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_date DATE NOT NULL,
            devotion_id INT NOT NULL,
            created_by INT NOT NULL,
            status ENUM('draft', 'finalized') DEFAULT 'draft',
            reporting_hours INT NOT NULL,
            target_efficiency DECIMAL(5,4) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            finalized_at TIMESTAMP NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_date (report_date),
            INDEX idx_devotion (devotion_id),
            INDEX idx_status (status),
            UNIQUE KEY unique_draft (report_date, devotion_id, status),
            FOREIGN KEY (devotion_id) REFERENCES devotion_types(id),
            FOREIGN KEY (created_by) REFERENCES users(id)
        )
    ");
    echo "✅ Reports table created.<br>";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS report_rows (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL,
            component_id INT NOT NULL,
            row_type ENUM('component', 'match_out', 'dhu', 'total') DEFAULT 'component',
            ttl_sam_pc DECIMAL(10,4) DEFAULT 0.0000,
            unit_smv DECIMAL(10,4) DEFAULT 0.0000,
            day_forecast DECIMAL(10,2) DEFAULT 0.00,
            unit_carder INT DEFAULT 0,
            plan_hours DECIMAL(5,2) DEFAULT 0.00,
            worked_hours DECIMAL(5,2) DEFAULT 0.00,
            available_minutes DECIMAL(10,2) DEFAULT 0.00,
            plan_minutes DECIMAL(10,2) DEFAULT 0.00,
            plan_efficiency DECIMAL(5,4) DEFAULT 0.0000,
            target_100 DECIMAL(10,2) DEFAULT 0.00,
            day_total DECIMAL(10,2) DEFAULT 0.00,
            earned_minutes DECIMAL(10,2) DEFAULT 0.00,
            achieved_efficiency DECIMAL(5,4) DEFAULT 0.0000,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_report (report_id),
            FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
            FOREIGN KEY (component_id) REFERENCES devotion_components(id)
        )
    ");
    echo "✅ Report Rows table created.<br>";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS hourly_production (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_row_id INT NOT NULL,
            hour_number INT NOT NULL,
            quantity DECIMAL(10,2) DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_row_hour (report_row_id, hour_number),
            FOREIGN KEY (report_row_id) REFERENCES report_rows(id) ON DELETE CASCADE
        )
    ");
    echo "✅ Hourly Production table created.<br>";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dhu_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL,
            report_row_id INT,
            hour_number INT,
            dhu_quantity DECIMAL(10,2) DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_report (report_id),
            FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE
        )
    ");
    echo "✅ DHU Records table created.<br>";

    // Seed Devotion Data
    $pdo->exec("
        INSERT IGNORE INTO devotion_types (name, slug, type, sort_order) VALUES
        ('Shirt', 'shirt', 'garment', 1),
        ('Trouser', 'trouser', 'garment', 2),
        ('Coat', 'coat', 'garment', 3),
        ('Shirt MTM', 'shirt_mtm', 'garment', 4),
        ('Trouser MTM', 'trouser_mtm', 'garment', 5),
        ('Coat MTM', 'coat_mtm', 'garment', 6),
        ('Assemble', 'assemble', 'assembly', 7);
    ");
    echo "✅ Devotion Types seeded.<br>";

    // Seed Admin User
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT IGNORE INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)");
    $stmt->execute(['admin', $hash, 'Administrator', 'admin']);
    echo "✅ Admin user created (username: admin, password: admin123).<br>";

    echo "<h3 style='color:green;'>✅ Installation Complete!</h3>";
    echo "<p>Go to <a href='login.php'>Login Page</a> and login with <strong>admin / admin123</strong></p>";
} catch (PDOException $e) {
    die("❌ Error: " . $e->getMessage());
}
?>