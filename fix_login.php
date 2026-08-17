<?php
// fix_login.php - Completely fix login issue
require_once 'config/database.php';

$conn = getDBConnection();

echo "<h2>🔧 Fixing Login Issue</h2>";

// First, let's see what's in the database
echo "<h3>Current users in database:</h3>";
$result = $conn->query("SELECT id, username, password, full_name, role FROM users");
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "ID: {$row['id']}, Username: {$row['username']}, Role: {$row['role']}<br>";
        echo "Password hash: " . substr($row['password'], 0, 30) . "...<br>";
    }
} else {
    echo "No users found<br>";
}

// Delete all existing users
$conn->query("DELETE FROM users");
echo "<br>✅ Deleted all existing users<br>";

// Create new admin with proper password hash
$password = 'admin123';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "New password hash: " . substr($hash, 0, 30) . "...<br>";

$stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)");
$username = 'admin';
$full_name = 'Administrator';
$role = 'admin';
$stmt->bind_param("ssss", $username, $hash, $full_name, $role);

if ($stmt->execute()) {
    echo "✅ Admin user created successfully!<br>";
    echo "Username: <strong>admin</strong><br>";
    echo "Password: <strong>admin123</strong><br>";
} else {
    echo "❌ Error: " . $stmt->error . "<br>";
}

// Verify the password works
echo "<br><h3>Verifying password:</h3>";
$test = $conn->query("SELECT password FROM users WHERE username = 'admin'");
if ($test->num_rows > 0) {
    $row = $test->fetch_assoc();
    if (password_verify('admin123', $row['password'])) {
        echo "✅ Password verification successful!<br>";
    } else {
        echo "❌ Password verification failed<br>";
    }
}

echo "<br><a href='index.php' style='display:inline-block; padding:10px 30px; background:#217346; color:#fff; text-decoration:none; border-radius:8px;'>Go to Login →</a>";

$conn->close();
?>