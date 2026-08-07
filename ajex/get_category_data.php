<?php
// ajax/get_category_data.php
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

$type = $_GET['type'] ?? 'efficiency';
$conn = getDBConnection();

if ($type === 'efficiency') {
    $data = getCategoryEfficiency($conn);
} else {
    $data = getCategoryDistribution($conn);
}

echo json_encode($data);
?>