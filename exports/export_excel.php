<?php
// exports/export_excel.php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();

$conn = getDBConnection();
$division_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

$division = $conn->query("SELECT * FROM divisions WHERE id = $division_id")->fetch_assoc();
$components = getComponents($conn, $division_id);

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="production_' . $division['code'] . '_' . $date . '.xls"');

echo '<html><head><meta charset="UTF-8"></head><body>';
echo '<h2>' . $division['name'] . ' - Production Report</h2>';
echo '<p>Date: ' . $date . '</p>';

echo '<table border="1" cellpadding="5">';
echo '<tr style="background:#217346; color:#fff;">';
echo '<th>DEVITION</th><th>Unit</th><th>TTl SAM/Pc</th><th>Unit SMV</th>';
echo '<th>Day Forecast</th><th>Unit Carder</th><th>Plan Hours</th><th>Worked Hours</th>';
echo '<th>Available Minutes</th><th>Plan Minutes</th><th>Plan Eff</th><th>100% Target</th>';
for ($h = 1; $h <= 11; $h++) echo '<th>' . $h . 'st</th>';
echo '<th>Day Total</th><th>Acvd Eff</th>';
echo '</tr>';

foreach ($components as $comp) {
    if ($comp['is_match_out']) continue;
    $data = getReportData($conn, $division_id, $comp['id'], $date);
    $day_total = 0;
    for ($h = 1; $h <= 11; $h++) $day_total += $data["hour_$h"] ?? 0;
    
    echo '<tr>';
    echo '<td>' . $division['name'] . '</td>';
    echo '<td>' . $comp['name'] . '</td>';
    echo '<td>' . number_format($data['ttl_sam_pc'] ?? 0, 2) . '</td>';
    echo '<td>' . number_format($data['unit_smv'] ?? 0, 2) . '</td>';
    echo '<td>' . number_format($data['day_forecast'] ?? 0, 0) . '</td>';
    echo '<td>' . ($data['unit_carder'] ?? 0) . '</td>';
    echo '<td>' . number_format($data['plan_hours'] ?? 0, 1) . '</td>';
    echo '<td>' . number_format($data['worked_hours'] ?? 0, 1) . '</td>';
    echo '<td>' . number_format($data['available_minutes'] ?? 0, 0) . '</td>';
    echo '<td>' . number_format($data['plan_minutes'] ?? 0, 0) . '</td>';
    echo '<td>' . number_format($data['plan_eff'] ?? 0, 1) . '%</td>';
    echo '<td>' . number_format($data['target_100'] ?? 0, 0) . '</td>';
    for ($h = 1; $h <= 11; $h++) {
        echo '<td>' . number_format($data["hour_$h"] ?? 0, 0) . '</td>';
    }
    echo '<td style="font-weight:700;">' . number_format($day_total, 0) . '</td>';
    $acvd_eff = ($data['available_minutes'] ?? 0) > 0 ? 
        ($day_total * ($data['ttl_sam_pc'] ?? 0) / ($data['available_minutes'] ?? 0)) * 100 : 0;
    echo '<td style="font-weight:700; color:#217346;">' . number_format($acvd_eff, 1) . '%</td>';
    echo '</tr>';
}

echo '</table></body></html>';
exit;
?>