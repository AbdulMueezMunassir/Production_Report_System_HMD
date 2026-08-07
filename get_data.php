<?php
// get_data.php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

requireLogin();

header('Content-Type: application/json');

$conn = getDBConnection();
$date = $_GET['date'] ?? date('Y-m-d');

$data = getReportsByDate($conn, $date);
echo json_encode($data);
?>