<?php
// includes/auth.php
require_once __DIR__ . '/../config/database.php';

function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

function loginUser($username, $password) {
    $conn = getDB();
    $stmt = $conn->prepare("SELECT id, username, password, full_name, role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['logged_in'] = true;
        return true;
    }
    return false;
}

function logoutUser() {
    $_SESSION = array();
    session_destroy();
    header('Location: index.php');
    exit;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit;
    }
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function getAllUsers($conn) {
    $stmt = $conn->query("SELECT id, username, full_name, role, created_at FROM users ORDER BY created_at DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function createUser($conn, $username, $password, $full_name, $role = 'user') {
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)");
    return $stmt->execute([$username, $hashed, $full_name, $role]);
}

function deleteUser($conn, $user_id) {
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
    return $stmt->execute([$user_id]);
}

function resetUserPassword($conn, $user_id, $new_password) {
    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    return $stmt->execute([$hashed, $user_id]);
}
?>