<?php
// setup.php - Run this once to create admin user
require_once 'config/database.php';

$conn = getDBConnection();

// First, check if users table exists
$table_check = $conn->query("SHOW TABLES LIKE 'users'");
if ($table_check->num_rows === 0) {
    // Create users table
    $create_table = "CREATE TABLE users (
        id INT PRIMARY KEY AUTO_INCREMENT,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        full_name VARCHAR(100),
        role ENUM('admin', 'user') DEFAULT 'user',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($create_table);
    echo "✅ Users table created.<br>";
}

// Check if admin exists
$check = $conn->query("SELECT id FROM users WHERE username = 'admin'");
if ($check->num_rows === 0) {
    // Create admin user with proper password hash
    $username = 'admin';
    $password = password_hash('admin123', PASSWORD_DEFAULT);
    $full_name = 'Administrator';
    $role = 'admin';
    
    $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $username, $password, $full_name, $role);
    
    if ($stmt->execute()) {
        echo "✅ Admin user created successfully!<br>";
        echo "Username: <strong>admin</strong><br>";
        echo "Password: <strong>admin123</strong><br>";
    } else {
        echo "❌ Error: " . $stmt->error . "<br>";
    }
    $stmt->close();
} else {
    // Update admin password
    $password = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
    $stmt->bind_param("s", $password);
    if ($stmt->execute()) {
        echo "✅ Admin password reset successfully!<br>";
        echo "Username: <strong>admin</strong><br>";
        echo "Password: <strong>admin123</strong><br>";
    }
    $stmt->close();
}

$conn->close();

echo "<br><a href='index.php'>Go to Login Page →</a>";
?>