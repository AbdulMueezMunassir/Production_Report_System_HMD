<?php
// fix_password.php - Fix the admin password
require_once 'config/database.php';

$conn = getDBConnection();

echo "<h2>🔧 Fixing Admin Password</h2>";

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

// Check if admin exists
$check = $conn->query("SELECT id, username, password FROM users WHERE username = 'admin'");
if ($check->num_rows > 0) {
    $row = $check->fetch_assoc();
    echo "Found admin user: ID {$row['id']}<br>";
    
    // Generate new password hash
    $new_password = 'admin123';
    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
    
    // Update the password
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
    $stmt->bind_param("s", $new_hash);
    
    if ($stmt->execute()) {
        echo "✅ Password updated successfully!<br>";
        echo "Username: <strong>admin</strong><br>";
        echo "Password: <strong>admin123</strong><br>";
    } else {
        echo "❌ Error updating password: " . $stmt->error . "<br>";
    }
    $stmt->close();
} else {
    echo "Creating new admin user...<br>";
    $new_hash = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)");
    $username = 'admin';
    $full_name = 'Administrator';
    $role = 'admin';
    $stmt->bind_param("ssss", $username, $new_hash, $full_name, $role);
    if ($stmt->execute()) {
        echo "✅ Admin user created successfully!<br>";
        echo "Username: <strong>admin</strong><br>";
        echo "Password: <strong>admin123</strong><br>";
    }
    $stmt->close();
}

// Test the password
echo "<br><h3>Testing password verification:</h3>";
$test = $conn->query("SELECT password FROM users WHERE username = 'admin'");
if ($test->num_rows > 0) {
    $test_row = $test->fetch_assoc();
    if (password_verify('admin123', $test_row['password'])) {
        echo "✅ Password verification successful!<br>";
    } else {
        echo "❌ Password verification failed. Please try again.<br>";
    }
}

echo "<br><a href='index.php'>Go to Login Page →</a>";
$conn->close();
?>