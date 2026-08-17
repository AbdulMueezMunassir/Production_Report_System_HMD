<?php
// save_data.php - AJAX Save Handler (Dynamic Hours Fixed)
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$conn = getDBConnection();
$action = $_POST['action'] ?? '';
$date = $_POST['date'] ?? date('Y-m-d');
$division_id = (int)($_POST['division'] ?? 0);
$component_id = $_POST['component'] ?? 0;
$work_hours = (float)($_POST['work_hours'] ?? 10); // RECEIVE DYNAMIC HOURS

$response = ['success' => false, 'message' => ''];

try {
    if ($action === 'update_hour') {
        $hour = (int)($_POST['hour'] ?? 1);
        $value = (float)($_POST['value'] ?? 0);
        $field = "hour_$hour";
        
        $data = getReportData($conn, $division_id, $component_id, $date);
        $save_data = [
            'report_date' => $date,
            'devition_id' => $division_id,
            'unit_id' => $component_id,
            'ttl_sam_pc' => (float)($data['ttl_sam_pc'] ?? 0),
            'unit_smv' => (float)($data['unit_smv'] ?? 0),
            'day_forecast' => (float)($data['day_forecast'] ?? 0),
            'unit_carder' => (int)($data['unit_carder'] ?? 0),
            'plan_hours' => (float)($data['plan_hours'] ?? 0),
            'worked_hours' => $work_hours, // USE DYNAMIC VALUE
            'available_minutes' => (float)($data['available_minutes'] ?? 0),
            'plan_minutes' => (float)($data['plan_minutes'] ?? 0),
            'plan_eff' => (float)($data['plan_eff'] ?? 0),
            'target_100' => (float)($data['target_100'] ?? 0),
            'hour_1' => (float)($data['hour_1'] ?? 0),
            'hour_2' => (float)($data['hour_2'] ?? 0),
            'hour_3' => (float)($data['hour_3'] ?? 0),
            'hour_4' => (float)($data['hour_4'] ?? 0),
            'hour_5' => (float)($data['hour_5'] ?? 0),
            'hour_6' => (float)($data['hour_6'] ?? 0),
            'hour_7' => (float)($data['hour_7'] ?? 0),
            'hour_8' => (float)($data['hour_8'] ?? 0),
            'hour_9' => (float)($data['hour_9'] ?? 0),
            'hour_10' => (float)($data['hour_10'] ?? 0),
            'hour_11' => (float)($data['hour_11'] ?? 0),
            'day_total' => (float)($data['day_total'] ?? 0),
            'acvd_eff' => (float)($data['acvd_eff'] ?? 0)
        ];
        
        $save_data[$field] = $value;
        
        // Recalculate with NEW WORK HOURS
        $ttl_sam_pc = $save_data['ttl_sam_pc'];
        $plan_hours = $save_data['plan_hours'];
        $unit_carder = $save_data['unit_carder'];
        $day_forecast = $save_data['day_forecast'];
        
        $save_data['available_minutes'] = $plan_hours * $work_hours * 60;
        $save_data['plan_minutes'] = $day_forecast * $unit_carder;
        $save_data['plan_eff'] = $save_data['available_minutes'] > 0 ? 
            ($save_data['plan_minutes'] / $save_data['available_minutes']) * 100 : 0;
        $save_data['target_100'] = $unit_carder > 0 ? ($plan_hours / $unit_carder) * 60 : 0;
        
        $day_total = 0;
        for ($h = 1; $h <= 11; $h++) {
            $day_total += $save_data["hour_$h"];
        }
        $save_data['day_total'] = $day_total;
        $save_data['acvd_eff'] = $save_data['available_minutes'] > 0 ? 
            ($day_total * $ttl_sam_pc / $save_data['available_minutes']) * 100 : 0;
        
        if (saveReportData($conn, $save_data)) {
            $response['success'] = true;
            $response['message'] = 'Saved successfully';
        }
    } elseif ($action === 'update_field') {
        $field = $_POST['field'] ?? '';
        $value = (float)($_POST['value'] ?? 0);
        
        $data = getReportData($conn, $division_id, $component_id, $date);
        $save_data = [
            'report_date' => $date,
            'devition_id' => $division_id,
            'unit_id' => $component_id,
            'ttl_sam_pc' => (float)($data['ttl_sam_pc'] ?? 0),
            'unit_smv' => (float)($data['unit_smv'] ?? 0),
            'day_forecast' => (float)($data['day_forecast'] ?? 0),
            'unit_carder' => (int)($data['unit_carder'] ?? 0),
            'plan_hours' => (float)($data['plan_hours'] ?? 0),
            'worked_hours' => $work_hours, // USE DYNAMIC VALUE
            'available_minutes' => (float)($data['available_minutes'] ?? 0),
            'plan_minutes' => (float)($data['plan_minutes'] ?? 0),
            'plan_eff' => (float)($data['plan_eff'] ?? 0),
            'target_100' => (float)($data['target_100'] ?? 0),
            'hour_1' => (float)($data['hour_1'] ?? 0),
            'hour_2' => (float)($data['hour_2'] ?? 0),
            'hour_3' => (float)($data['hour_3'] ?? 0),
            'hour_4' => (float)($data['hour_4'] ?? 0),
            'hour_5' => (float)($data['hour_5'] ?? 0),
            'hour_6' => (float)($data['hour_6'] ?? 0),
            'hour_7' => (float)($data['hour_7'] ?? 0),
            'hour_8' => (float)($data['hour_8'] ?? 0),
            'hour_9' => (float)($data['hour_9'] ?? 0),
            'hour_10' => (float)($data['hour_10'] ?? 0),
            'hour_11' => (float)($data['hour_11'] ?? 0),
            'day_total' => (float)($data['day_total'] ?? 0),
            'acvd_eff' => (float)($data['acvd_eff'] ?? 0)
        ];
        
        $save_data[$field] = $value;
        
        // Recalculate derived fields using NEW WORK HOURS
        $plan_hours = $save_data['plan_hours'];
        $unit_carder = $save_data['unit_carder'];
        $day_forecast = $save_data['day_forecast'];
        $ttl_sam_pc = $save_data['ttl_sam_pc'];
        
        $save_data['available_minutes'] = ($plan_hours * $work_hours) * 60;
        $save_data['plan_minutes'] = $day_forecast * $unit_carder;
        $save_data['plan_eff'] = $save_data['available_minutes'] > 0 ? 
            ($save_data['plan_minutes'] / $save_data['available_minutes']) * 100 : 0;
        $save_data['target_100'] = $unit_carder > 0 ? ($plan_hours / $unit_carder) * 60 : 0;
        
        $day_total = 0;
        for ($h = 1; $h <= 11; $h++) {
            $day_total += $save_data["hour_$h"];
        }
        $save_data['day_total'] = $day_total;
        $save_data['acvd_eff'] = $save_data['available_minutes'] > 0 ? 
            ($day_total * $ttl_sam_pc / $save_data['available_minutes']) * 100 : 0;
        
        if (saveReportData($conn, $save_data)) {
            $response['success'] = true;
            $response['message'] = 'Saved successfully';
        }
    }
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
?>