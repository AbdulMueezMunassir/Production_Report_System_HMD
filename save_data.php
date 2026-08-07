<?php
// save_data.php - AJAX Save Handler (FIXED)
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
$division_id = $_POST['division'] ?? 0;
$component_id = $_POST['component'] ?? 0;

$response = ['success' => false, 'message' => ''];

try {
    if ($action === 'update_field') {
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? 0;
        
        // Get existing data
        $data = getReportData($conn, $division_id, $component_id, $date);
        
        // Prepare data for save
        $save_data = [
            'report_date' => $date,
            'devition_id' => $division_id,
            'unit_id' => $component_id,
            'ttl_sam_pc' => $data['ttl_sam_pc'] ?? 0,
            'unit_smv' => $data['unit_smv'] ?? 0,
            'day_forecast' => $data['day_forecast'] ?? 0,
            'unit_carder' => $data['unit_carder'] ?? 0,
            'plan_hours' => $data['plan_hours'] ?? 0,
            'worked_hours' => $data['worked_hours'] ?? 0,
            'available_minutes' => $data['available_minutes'] ?? 0,
            'plan_minutes' => $data['plan_minutes'] ?? 0,
            'plan_eff' => $data['plan_eff'] ?? 0,
            'target_100' => $data['target_100'] ?? 0,
            'hour_1' => $data['hour_1'] ?? 0,
            'hour_2' => $data['hour_2'] ?? 0,
            'hour_3' => $data['hour_3'] ?? 0,
            'hour_4' => $data['hour_4'] ?? 0,
            'hour_5' => $data['hour_5'] ?? 0,
            'hour_6' => $data['hour_6'] ?? 0,
            'hour_7' => $data['hour_7'] ?? 0,
            'hour_8' => $data['hour_8'] ?? 0,
            'hour_9' => $data['hour_9'] ?? 0,
            'hour_10' => $data['hour_10'] ?? 0,
            'hour_11' => $data['hour_11'] ?? 0,
            'day_total' => $data['day_total'] ?? 0,
            'acvd_eff' => $data['acvd_eff'] ?? 0
        ];
        
        // Update the specific field
        $save_data[$field] = $value;
        
        // Recalculate derived fields
        $plan_hours = $save_data['plan_hours'];
        $worked_hours = $save_data['worked_hours'];
        $unit_carder = $save_data['unit_carder'];
        $day_forecast = $save_data['day_forecast'];
        $ttl_sam_pc = $save_data['ttl_sam_pc'];
        
        $save_data['available_minutes'] = ($plan_hours * $worked_hours) * 60;
        $save_data['plan_minutes'] = $day_forecast * $unit_carder;
        $save_data['plan_eff'] = $save_data['available_minutes'] > 0 ? 
            ($save_data['plan_minutes'] / $save_data['available_minutes']) * 100 : 0;
        $save_data['target_100'] = $unit_carder > 0 ? ($plan_hours / $unit_carder) * 60 : 0;
        
        // Calculate day total
        $day_total = 0;
        for ($h = 1; $h <= 11; $h++) {
            $day_total += $save_data["hour_$h"];
        }
        $save_data['day_total'] = $day_total;
        
        // Calculate acvd_eff
        $save_data['acvd_eff'] = $save_data['available_minutes'] > 0 ? 
            ($day_total * $ttl_sam_pc / $save_data['available_minutes']) * 100 : 0;
        
        // Save
        if (saveReportData($conn, $save_data)) {
            $response['success'] = true;
            $response['message'] = 'Saved successfully';
        }
        
    } elseif ($action === 'update_hour') {
        $hour = $_POST['hour'] ?? 1;
        $value = $_POST['value'] ?? 0;
        $field = "hour_$hour";
        
        // Same logic as above but for hours
        $data = getReportData($conn, $division_id, $component_id, $date);
        $save_data = [
            'report_date' => $date,
            'devition_id' => $division_id,
            'unit_id' => $component_id,
            'ttl_sam_pc' => $data['ttl_sam_pc'] ?? 0,
            'unit_smv' => $data['unit_smv'] ?? 0,
            'day_forecast' => $data['day_forecast'] ?? 0,
            'unit_carder' => $data['unit_carder'] ?? 0,
            'plan_hours' => $data['plan_hours'] ?? 0,
            'worked_hours' => $data['worked_hours'] ?? 0,
            'available_minutes' => $data['available_minutes'] ?? 0,
            'plan_minutes' => $data['plan_minutes'] ?? 0,
            'plan_eff' => $data['plan_eff'] ?? 0,
            'target_100' => $data['target_100'] ?? 0,
            'hour_1' => $data['hour_1'] ?? 0,
            'hour_2' => $data['hour_2'] ?? 0,
            'hour_3' => $data['hour_3'] ?? 0,
            'hour_4' => $data['hour_4'] ?? 0,
            'hour_5' => $data['hour_5'] ?? 0,
            'hour_6' => $data['hour_6'] ?? 0,
            'hour_7' => $data['hour_7'] ?? 0,
            'hour_8' => $data['hour_8'] ?? 0,
            'hour_9' => $data['hour_9'] ?? 0,
            'hour_10' => $data['hour_10'] ?? 0,
            'hour_11' => $data['hour_11'] ?? 0,
            'day_total' => $data['day_total'] ?? 0,
            'acvd_eff' => $data['acvd_eff'] ?? 0
        ];
        
        $save_data[$field] = $value;
        
        // Recalculate
        $ttl_sam_pc = $save_data['ttl_sam_pc'];
        $available_minutes = $save_data['available_minutes'];
        
        $day_total = 0;
        for ($h = 1; $h <= 11; $h++) {
            $day_total += $save_data["hour_$h"];
        }
        $save_data['day_total'] = $day_total;
        $save_data['acvd_eff'] = $available_minutes > 0 ? 
            ($day_total * $ttl_sam_pc / $available_minutes) * 100 : 0;
        
        if (saveReportData($conn, $save_data)) {
            $response['success'] = true;
            $response['message'] = 'Saved successfully';
        }
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>