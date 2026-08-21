<?php
// save_data.php - FIXED with auto-save support
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

error_reporting(0);
ini_set('display_errors', 0);

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
$is_assembly = isset($_POST['is_assembly']) && $_POST['is_assembly'] == '1';

$response = ['success' => false, 'message' => ''];

try {
    // If auto_save, get data from JSON
    if ($action === 'auto_save' && isset($_POST['data'])) {
        $post_data = json_decode($_POST['data'], true);
        if ($post_data) {
            // Get existing data
            $data = getReportData($conn, $division_id, $component_id, $date);
            
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
            
            // Recalculate using Excel formulas
            if ($is_assembly) {
                // Assembly (80% target)
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
                
                if ($save_data['available_minutes'] > 0 && $save_data['worked_hours'] > 0) {
                    $save_data['acvd_eff'] = ($save_data['ern_minutes'] / $save_data['available_minutes']) * ($save_data['plan_hours'] / $save_data['worked_hours']);
                } else {
                    $save_data['acvd_eff'] = 0;
                }
            } else {
                // Shirt/Trouser (90% target)
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
            
            if (saveReportData($conn, $save_data, $work_hours)) {
                $response['success'] = true;
                $response['message'] = 'Saved successfully';
            } else {
                $response['message'] = 'Database save failed.';
            }
        } else {
            $response['message'] = 'Invalid data format';
        }
    } else {
        // Handle individual field updates (backward compatibility)
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
            'worked_hours' => (float)($data['worked_hours'] ?? $work_hours)
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
            $valid_fields = ['ttl_sam_pc', 'unit_smv', 'unit_carder', 'plan_hours', 'worked_hours'];
            if (in_array($field, $valid_fields)) {
                $save_data[$field] = $value;
            }
        }
        
        // Recalculate
        if ($is_assembly) {
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
            
            if ($save_data['available_minutes'] > 0 && $save_data['worked_hours'] > 0) {
                $save_data['acvd_eff'] = ($save_data['ern_minutes'] / $save_data['available_minutes']) * ($save_data['plan_hours'] / $save_data['worked_hours']);
            } else {
                $save_data['acvd_eff'] = 0;
            }
        } else {
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
        
        if (saveReportData($conn, $save_data, $work_hours)) {
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
        }
    }
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
exit;