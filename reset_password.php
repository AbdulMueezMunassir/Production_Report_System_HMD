<?php
// reset_password.php - Reset admin password
require_once 'config/database.php';

$conn = getDBConnection();

echo "<h2>🔧 Reset Admin Password</h2>";

// Delete existing admin
$conn->query("DELETE FROM users WHERE username = 'admin'");

// Create new admin with correct password
$hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
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

echo "<br><a href='index.php' style='display:inline-block; padding:10px 30px; background:#217346; color:#fff; text-decoration:none; border-radius:8px;'>Go to Login →</a>";

$conn->close();
?>