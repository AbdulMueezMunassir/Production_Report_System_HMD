<?php
// save_data.php - COMPLETE FIXED VERSION WITH SUMMARY ROWS
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$conn = getDB();
$action = $_POST['action'] ?? '';
$date = $_POST['date'] ?? date('Y-m-d');
$division_id = (int)($_POST['division'] ?? 0);
$component_id = (int)($_POST['component'] ?? 0);
$work_hours = (float)($_POST['work_hours'] ?? 10);
$is_assembly = isset($_POST['is_assembly']) && $_POST['is_assembly'] == '1';

$response = ['success' => false, 'message' => ''];

try {
    if ($action === 'auto_save' && isset($_POST['data'])) {
        $post_data = json_decode($_POST['data'], true);
        if ($post_data) {
            $save_data = [
                'report_date' => $date,
                'devition_id' => $division_id,
                'unit_id' => $component_id,
                'ttl_sam_pc' => (float)($post_data['ttl_sam_pc'] ?? 0),
                'unit_smv' => (float)($post_data['unit_smv'] ?? 0),
                'unit_carder' => (int)($post_data['unit_carder'] ?? 0),
                'plan_hours' => (float)($post_data['plan_hours'] ?? 0),
                'worked_hours' => (float)($post_data['worked_hours'] ?? $work_hours)
            ];
            
            for ($h = 1; $h <= 11; $h++) {
                $save_data["hour_$h"] = (float)($post_data["hour_$h"] ?? 0);
            }
            
            if ($is_assembly) {
                // ASSEMBLY FORMULAS (80% target)
                if ($save_data['unit_smv'] > 0 && $save_data['unit_carder'] > 0) {
                    $save_data['day_forecast'] = ($save_data['unit_carder'] * 600 / $save_data['unit_smv']) * 0.80;
                } else {
                    $save_data['day_forecast'] = 0;
                }
                $save_data['available_minutes'] = $save_data['unit_carder'] * $save_data['plan_hours'] * 60;
                $save_data['plan_minutes'] = $save_data['day_forecast'] * $save_data['unit_smv'];
                $save_data['plan_eff'] = ($save_data['available_minutes'] > 0) ? ($save_data['plan_minutes'] / $save_data['available_minutes']) : 0;
                $save_data['target_100'] = ($save_data['unit_smv'] > 0) ? ($save_data['unit_carder'] / $save_data['unit_smv']) * 60 : 0;
                
                $day_total = 0;
                for ($h = 1; $h <= $work_hours; $h++) {
                    $day_total += $save_data["hour_$h"];
                }
                $save_data['day_total'] = $day_total;
                $save_data['ern_minutes'] = $day_total * $save_data['ttl_sam_pc'];
                
                if ($save_data['available_minutes'] > 0 && $save_data['worked_hours'] > 0 && $save_data['plan_hours'] > 0) {
                    $save_data['acvd_eff'] = ($save_data['ern_minutes'] / $save_data['available_minutes']) * ($save_data['plan_hours'] / $save_data['worked_hours']);
                } else {
                    $save_data['acvd_eff'] = 0;
                }
            } else {
                // SHIRT/TROUSER FORMULAS (90% target)
                if ($save_data['unit_smv'] > 0 && $save_data['unit_carder'] > 0) {
                    $save_data['day_forecast'] = ($save_data['unit_carder'] * 600 / $save_data['unit_smv']) * 0.90;
                } else {
                    $save_data['day_forecast'] = 0;
                }
                $save_data['available_minutes'] = $save_data['unit_carder'] * $save_data['plan_hours'] * 60;
                $save_data['plan_minutes'] = $save_data['day_forecast'] * $save_data['unit_smv'];
                $save_data['plan_eff'] = ($save_data['available_minutes'] > 0) ? ($save_data['plan_minutes'] / $save_data['available_minutes']) : 0;
                $save_data['target_100'] = ($save_data['unit_smv'] > 0) ? ($save_data['unit_carder'] / $save_data['unit_smv']) * 60 : 0;
                
                $day_total = 0;
                for ($h = 1; $h <= $work_hours; $h++) {
                    $day_total += $save_data["hour_$h"];
                }
                $save_data['day_total'] = $day_total;
                $save_data['ern_minutes'] = $day_total * $save_data['unit_smv'];
                
                $denominator = 1;
                if ($save_data['available_minutes'] > 0 && $save_data['plan_hours'] > 0) {
                    $denominator = ($save_data['available_minutes'] / $save_data['plan_hours']) * $save_data['worked_hours'];
                }
                $save_data['acvd_eff'] = ($denominator > 0) ? ($save_data['ern_minutes'] / $denominator) : 0;
            }
            
            // Save the main row
            try {
                $check = $conn->prepare("SELECT id FROM production_reports WHERE devition_id = ? AND unit_id = ? AND report_date = ?");
                $check->execute([$save_data['devition_id'], $save_data['unit_id'], $save_data['report_date']]);
                $existing = $check->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    $sql = "UPDATE production_reports SET 
                            ttl_sam_pc = ?, unit_smv = ?, day_forecast = ?, unit_carder = ?, 
                            plan_hours = ?, worked_hours = ?, available_minutes = ?, 
                            plan_minutes = ?, plan_eff = ?, target_100 = ?, 
                            hour_1 = ?, hour_2 = ?, hour_3 = ?, hour_4 = ?, hour_5 = ?, 
                            hour_6 = ?, hour_7 = ?, hour_8 = ?, hour_9 = ?, hour_10 = ?, hour_11 = ?, 
                            day_total = ?, acvd_eff = ?, ern_minutes = ? 
                            WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $result = $stmt->execute([
                        $save_data['ttl_sam_pc'], $save_data['unit_smv'], $save_data['day_forecast'], 
                        $save_data['unit_carder'], $save_data['plan_hours'], $save_data['worked_hours'],
                        $save_data['available_minutes'], $save_data['plan_minutes'], $save_data['plan_eff'], 
                        $save_data['target_100'],
                        $save_data['hour_1'], $save_data['hour_2'], $save_data['hour_3'], $save_data['hour_4'], 
                        $save_data['hour_5'], $save_data['hour_6'], $save_data['hour_7'], $save_data['hour_8'], 
                        $save_data['hour_9'], $save_data['hour_10'], $save_data['hour_11'],
                        $save_data['day_total'], $save_data['acvd_eff'], $save_data['ern_minutes'], 
                        $existing['id']
                    ]);
                } else {
                    $sql = "INSERT INTO production_reports (
                        report_date, devition_id, unit_id, ttl_sam_pc, unit_smv, 
                        day_forecast, unit_carder, plan_hours, worked_hours, 
                        available_minutes, plan_minutes, plan_eff, target_100, 
                        hour_1, hour_2, hour_3, hour_4, hour_5, hour_6, 
                        hour_7, hour_8, hour_9, hour_10, hour_11, 
                        day_total, acvd_eff, ern_minutes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $result = $stmt->execute([
                        $save_data['report_date'], $save_data['devition_id'], $save_data['unit_id'],
                        $save_data['ttl_sam_pc'], $save_data['unit_smv'], $save_data['day_forecast'], 
                        $save_data['unit_carder'], $save_data['plan_hours'], $save_data['worked_hours'],
                        $save_data['available_minutes'], $save_data['plan_minutes'], $save_data['plan_eff'], 
                        $save_data['target_100'],
                        $save_data['hour_1'], $save_data['hour_2'], $save_data['hour_3'], $save_data['hour_4'], 
                        $save_data['hour_5'], $save_data['hour_6'], $save_data['hour_7'], $save_data['hour_8'], 
                        $save_data['hour_9'], $save_data['hour_10'], $save_data['hour_11'],
                        $save_data['day_total'], $save_data['acvd_eff'], $save_data['ern_minutes']
                    ]);
                }
                
                if ($result) {
                    // After saving the main row, save summary rows
                    // Check if this is a regular component (not summary row)
                    $is_summary_row = in_array($component_id, [996, 997, 998, 999]);
                    
                    if (!$is_summary_row) {
                        // Save Match Out, DHU, Lean Total, Factory Grand Total
                        saveAllSummaryRows($conn, $division_id, $date, $work_hours, $is_assembly);
                    }
                    
                    $response['success'] = true;
                    $response['message'] = 'Saved successfully';
                    $response['data'] = [
                        'day_forecast' => number_format($save_data['day_forecast'], 0),
                        'available_minutes' => number_format($save_data['available_minutes'], 0),
                        'plan_minutes' => number_format($save_data['plan_minutes'], 0),
                        'plan_eff' => number_format($save_data['plan_eff'] * 100, 1),
                        'target_100' => number_format($save_data['target_100'], 0),
                        'day_total' => number_format($save_data['day_total'], 0),
                        'ern_minutes' => number_format($save_data['ern_minutes'], 1),
                        'acvd_eff' => number_format($save_data['acvd_eff'] * 100, 1)
                    ];
                } else {
                    $response['message'] = 'Database save failed.';
                }
            } catch (Exception $e) {
                $response['message'] = 'Database error: ' . $e->getMessage();
                error_log('Save error: ' . $e->getMessage());
            }
        } else {
            $response['message'] = 'Invalid data format';
        }
    } else {
        $response['message'] = 'Invalid action or missing data';
    }
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log('Save error: ' . $e->getMessage());
}

echo json_encode($response);
exit;
?>