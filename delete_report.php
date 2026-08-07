<?php
// delete_report.php
require_once 'config/database.php';
require_once 'includes/functions.php';

$id = $_GET['id'] ?? 0;
$conn = getDBConnection();

$stmt = $conn->prepare("DELETE FROM production_reports WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    setMessage('success', 'Report deleted successfully.');
} else {
    setMessage('danger', 'Error deleting report.');
}

header('Location: index.php');
exit;
?>