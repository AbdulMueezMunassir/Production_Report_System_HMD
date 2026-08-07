<?php
// test_login_simple.php - Test login directly
session_start();
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'production_db';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🔧 Login Test</h2>";

// Test admin login
$username = 'admin';
$password = 'admin123';

echo "Testing login with: $username / $password<br><br>";

$stmt = $conn->prepare("SELECT id, username, password, full_name, role FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if ($user) {
    echo "✅ User found: {$user['username']} (ID: {$user['id']})<br>";
    echo "Password hash: " . substr($user['password'], 0, 30) . "...<br>";
    
    if (password_verify($password, $user['password'])) {
        echo "✅ Password is CORRECT!<br>";
        echo "Login would be successful!<br>";
        
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['logged_in'] = true;
        
        echo "✅ Session set successfully!<br>";
        echo "<br><a href='dashboard.php'>Go to Dashboard →</a>";
    } else {
        echo "❌ Password is INCORRECT!<br>";
        echo "Please reset the password using reset_password.php";
    }
} else {
    echo "❌ User not found!<br>";
    echo "Please create admin user using reset_password.php";
}

$conn->close();
?>