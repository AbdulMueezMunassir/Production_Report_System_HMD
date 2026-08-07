<?php
// test_login.php - Debug login script
require_once 'config/database.php';

$conn = getDBConnection();

echo "<h2>Login System Debug</h2>";

// Check if users table exists
$table_check = $conn->query("SHOW TABLES LIKE 'users'");
if ($table_check->num_rows === 0) {
    echo "❌ Users table does not exist. Creating...<br>";
    $create = "CREATE TABLE users (
        id INT PRIMARY KEY AUTO_INCREMENT,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        full_name VARCHAR(100),
        role ENUM('admin', 'user') DEFAULT 'user',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    if ($conn->query($create)) {
        echo "✅ Users table created.<br>";
    }
}

// List all users
$result = $conn->query("SELECT id, username, password, full_name, role FROM users");
echo "<h3>Users in database:</h3>";
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "ID: {$row['id']}, Username: {$row['username']}, Role: {$row['role']}<br>";
    }
} else {
    echo "No users found. Creating admin...<br>";
    $hashed = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $username, $hashed, $full_name, $role);
    $username = 'admin';
    $full_name = 'Administrator';
    $role = 'admin';
    if ($stmt->execute()) {
        echo "✅ Admin user created.<br>";
    }
}

echo "<br><a href='index.php'>Go to Login →</a>";
?>