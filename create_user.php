<?php
// create_user.php - AJAX Create User
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$conn = getDBConnection();
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$full_name = trim($_POST['full_name'] ?? '');
$role = $_POST['role'] ?? 'user';

if (empty($username) || empty($password) || empty($full_name)) {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit;
}

// Check if username exists
$check = $conn->prepare("SELECT id FROM users WHERE username = ?");
$check->bind_param("s", $username);
$check->execute();
$result = $check->get_result();
if ($result->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Username already exists']);
    exit;
}

if (createUser($conn, $username, $password, $full_name, $role)) {
    echo json_encode(['success' => true, 'message' => 'User created successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to create user']);
}
?>