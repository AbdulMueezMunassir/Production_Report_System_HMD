<?php
// delete_user.php - AJAX Delete User
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$conn = getDBConnection();
$user_id = (int)($_POST['id'] ?? 0);

if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    exit;
}

// Check if trying to delete admin
$check = $conn->prepare("SELECT role FROM users WHERE id = ?");
$check->bind_param("i", $user_id);
$check->execute();
$result = $check->get_result();
$user = $result->fetch_assoc();

if ($user && $user['role'] === 'admin') {
    echo json_encode(['success' => false, 'message' => 'Cannot delete admin user']);
    exit;
}

if (deleteUser($conn, $user_id)) {
    echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to delete user']);
}
?>