<?php
// test_login.php - Test login directly
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

echo "<h2>🔧 Login Test</h2>";

// Test 1: Check database connection
$conn = getDBConnection();
echo "<h3>1. Database Connection:</h3>";
if ($conn) {
    echo "✅ Connected to database<br>";
}

// Test 2: Check users table
echo "<h3>2. Users Table:</h3>";
$result = $conn->query("SHOW TABLES LIKE 'users'");
if ($result->num_rows > 0) {
    echo "✅ Users table exists<br>";
} else {
    echo "❌ Users table does not exist<br>";
}

// Test 3: Check admin user
echo "<h3>3. Admin User:</h3>";
$result = $conn->query("SELECT id, username, password, full_name FROM users WHERE username = 'admin'");
if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    echo "✅ Admin user found: ID {$user['id']}<br>";
    echo "Username: {$user['username']}<br>";
    echo "Password hash: " . substr($user['password'], 0, 30) . "...<br>";
    
    // Test password
    if (password_verify('admin123', $user['password'])) {
        echo "✅ Password 'admin123' is correct<br>";
    } else {
        echo "❌ Password 'admin123' is NOT correct<br>";
        // Fix it
        $new_hash = password_hash('admin123', PASSWORD_DEFAULT);
        $conn->query("UPDATE users SET password = '$new_hash' WHERE username = 'admin'");
        echo "🔄 Password has been reset to 'admin123'<br>";
    }
} else {
    echo "❌ Admin user not found<br>";
    // Create admin
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $conn->query("INSERT INTO users (username, password, full_name, role) VALUES ('admin', '$hash', 'Administrator', 'admin')");
    echo "✅ Admin user created<br>";
}

// Test 4: Try login function
echo "<h3>4. Testing login function:</h3>";
$result = loginUser('admin', 'admin123');
if ($result) {
    echo "✅ Login successful!<br>";
    echo "Session: <pre>";
    print_r($_SESSION);
    echo "</pre>";
    echo "<br><a href='dashboard.php'>Go to Dashboard →</a>";
} else {
    echo "❌ Login failed<br>";
    echo "Check error logs for details<br>";
}

echo "<br><br><a href='index.php'>Go to Login Page →</a>";
?>