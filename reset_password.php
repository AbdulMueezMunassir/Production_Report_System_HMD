<?php
// reset_password.php
require_once 'config/database.php';

$conn = getDBConnection();

echo "<h2>🔧 Reset Admin Password</h2>";

$new_hash = password_hash('admin123', PASSWORD_DEFAULT);
$stmt = $conn->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
$stmt->bind_param("s", $new_hash);

if ($stmt->execute()) {
    echo "✅ Password reset successfully!<br>";
    echo "Username: <strong>admin</strong><br>";
    echo "Password: <strong>admin123</strong><br>";
} else {
    echo "❌ Error: " . $stmt->error;
}

echo "<br><br><a href='index.php'>Go to Login →</a>";
$conn->close();
?>