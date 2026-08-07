<?php
// cleanup.php - Clean up database
require_once 'config/database.php';

$conn = getDBConnection();

echo "<h2>🔧 Database Cleanup</h2>";

// 1. Delete empty username users
echo "<h3>1. Deleting empty username users...</h3>";
$conn->query("DELETE FROM users WHERE username = '' OR username IS NULL");
echo "✅ Done<br>";

// 2. Delete duplicate admin users
echo "<h3>2. Cleaning duplicate admin users...</h3>";
$conn->query("DELETE FROM users WHERE username = 'admin' AND id < (SELECT MAX(id) FROM (SELECT id FROM users WHERE username = 'admin') AS t)");
echo "✅ Done<br>";

// 3. Reset admin password
echo "<h3>3. Resetting admin password...</h3>";
$new_hash = password_hash('admin123', PASSWORD_DEFAULT);
$conn->query("UPDATE users SET password = '$new_hash' WHERE username = 'admin'");
echo "✅ Password reset to 'admin123'<br>";

// 4. Show users
echo "<h3>4. Current users:</h3>";
$result = $conn->query("SELECT id, username, full_name, role FROM users");
if ($result->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Role</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['username']}</td>";
        echo "<td>{$row['full_name']}</td>";
        echo "<td>{$row['role']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "No users found. Creating admin...<br>";
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $conn->query("INSERT INTO users (username, password, full_name, role) VALUES ('admin', '$hash', 'Administrator', 'admin')");
    echo "✅ Admin user created<br>";
}

echo "<br><a href='index.php'>Go to Login →</a>";
$conn->close();
?>