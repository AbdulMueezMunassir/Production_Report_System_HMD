<?php
// ajax/get_hourly_data.php
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

$date = $_GET['date'] ?? date('Y-m-d');
$conn = getDBConnection();

$hourly_data = getHourlyData($conn, $date);

echo json_encode($hourly_data);
?>