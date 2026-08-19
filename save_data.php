<?php
// save_data.php - Saves data to database
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$conn = getDB();
$action = $_POST['action'] ?? '';
$date = $_POST['date'] ?? date('Y-m-d');
$division_id = (int)($_POST['division'] ?? 0);
$component_id = $_POST['component'] ?? 0;
$work_hours = (float)($_POST['work_hours'] ?? 10);

$response = ['success' => false, 'message' => ''];

try {
    $data = getReportData($conn, $division_id, $component_id, $date);
    
    if ($action === 'update_hour' || $action === 'update_field') {
        $save_data = [
            'report_date' => $date,
            'devition_id' => $division_id,
            'unit_id' => $component_id,
            'ttl_sam_pc' => (float)($data['ttl_sam_pc'] ?? 0),
            'unit_smv' => (float)($data['unit_smv'] ?? 0),
            'day_forecast' => (float)($data['day_forecast'] ?? 0),
            'unit_carder' => (int)($data['unit_carder'] ?? 0),
            'plan_hours' => (float)($data['plan_hours'] ?? 0),
            'worked_hours' => (float)($data['worked_hours'] ?? 0)
        ];
        
        for ($h = 1; $h <= 11; $h++) {
            $save_data["hour_$h"] = (float)($data["hour_$h"] ?? 0);
        }

        if ($action === 'update_hour') {
            $hour = (int)($_POST['hour'] ?? 1);
            $value = (float)($_POST['value'] ?? 0);
            $save_data["hour_$hour"] = $value;
        } elseif ($action === 'update_field') {
            $field = $_POST['field'] ?? '';
            $value = (float)($_POST['value'] ?? 0);
            $save_data[$field] = $value;
        }

        // Server authoritative recalculation
        $targetEfficiency = 0.90;
        $save_data['day_forecast'] = calculateDayForecast($save_data['unit_carder'], $work_hours, $save_data['unit_smv'], $targetEfficiency);
        $save_data['available_minutes'] = calculateAvailableMinutes($save_data['unit_carder'], $save_data['plan_hours']);
        $save_data['plan_minutes'] = calculatePlanMinutes($save_data['day_forecast'], $save_data['unit_smv']);
        $save_data['plan_eff'] = calculatePlanEfficiency($save_data['plan_minutes'], $save_data['available_minutes']);
        $save_data['target_100'] = calculateTarget100($save_data['unit_carder'], $save_data['unit_smv']);
        $save_data['day_total'] = calculateDayTotal($save_data, $work_hours);
        $save_data['acvd_eff'] = calculateAchievedEfficiency(
            calculateEarnedMinutes($save_data['day_total'], $save_data['unit_smv']),
            $save_data['available_minutes'],
            $save_data['plan_hours'],
            $save_data['worked_hours']
        );
        
        if (saveReportData($conn, $save_data, $work_hours, $targetEfficiency)) {
            $response['success'] = true;
            $response['message'] = 'Saved successfully';
        } else {
            $response['message'] = 'Database save failed.';
        }
    }
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
?>