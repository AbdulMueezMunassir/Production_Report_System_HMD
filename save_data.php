<?php
// save_data.php - COMPLETE FIXED VERSION
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);  // Set to 0 in production

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

try {
    $conn = getDB();
} catch (Throwable $e) {
    error_log('Save endpoint database connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database unavailable. Check the hosting database configuration and PHP error log.'
    ]);
    exit;
}
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
            $can_save_direct_staffing = in_array($division_id, [1, 2], true)
                && !$is_assembly
                && $component_id > 0
                && $component_id < 900;
            $direct_on_roll = $can_save_direct_staffing && array_key_exists('direct_on_roll', $post_data)
                ? max(0, (int)$post_data['direct_on_roll'])
                : null;
            $direct_present = $can_save_direct_staffing && array_key_exists('direct_present', $post_data)
                ? max(0, (int)$post_data['direct_present'])
                : null;

            // Build save data
            $save_data = [
                'report_date' => $date,
                'devition_id' => $division_id,
                'unit_id' => $component_id,
                'ttl_sam_pc' => (float)($post_data['ttl_sam_pc'] ?? 0),
                'unit_smv' => (float)($post_data['unit_smv'] ?? 0),
                'unit_carder' => (int)($post_data['unit_carder'] ?? 0),
                'plan_hours' => (float)($post_data['plan_hours'] ?? 0),
                'worked_hours' => (float)($post_data['worked_hours'] ?? $work_hours),
                'epm' => (float)($post_data['epm'] ?? 13.2),
                'style_epm' => (float)($post_data['style_epm'] ?? ($post_data['epm'] ?? 13.2)),
                'profit' => (float)($post_data['profit'] ?? 0)
            ];
            
            // Ensure ALL 11 hours are saved
            for ($h = 1; $h <= 11; $h++) {
                $save_data["hour_$h"] = (float)($post_data["hour_$h"] ?? 0);
            }
            
            // ============================================================
            // DHU UNIT IDs (900-906): Skip recalculation, just save raw values
            // These are Assembly page per-hour DHU rows
            // ============================================================
            $is_dhu_row = ($component_id >= 900 && $component_id <= 906);
            
            if ($is_dhu_row) {
                // For DHU rows, just save hour_1..hour_N as-is. No formulas.
                $save_data['day_forecast'] = 0;
                $save_data['available_minutes'] = 0;
                $save_data['plan_minutes'] = 0;
                $save_data['plan_eff'] = 0;
                $save_data['target_100'] = 0;
                $save_data['day_total'] = 0;
                $save_data['acvd_eff'] = 0;
                $save_data['ern_minutes'] = 0;
                
            } elseif ($component_id == 998) {
                // ============================================================
                // DHU summary row (unit_id = 998) — just save hours as-is
                // ============================================================
                $save_data['day_forecast'] = 0;
                $save_data['available_minutes'] = 0;
                $save_data['plan_minutes'] = 0;
                $save_data['plan_eff'] = 0;
                $save_data['target_100'] = 0;
                $save_data['day_total'] = 0;
                $save_data['acvd_eff'] = 0;
                $save_data['ern_minutes'] = 0;
                
            } else {
                // ============================================================
                // Regular component / summary row — apply formulas
                // ============================================================
                
                if ($is_assembly) {
                    // ASSEMBLY FORMULAS (80% target)
                    if ($save_data['unit_smv'] > 0 && $save_data['unit_carder'] > 0) {
                        $save_data['day_forecast'] = ($save_data['unit_carder'] * 600 / $save_data['unit_smv']) * 0.80;
                    } else {
                        $save_data['day_forecast'] = 0;
                    }
                    
                    // Determine total assemble carder
                    $tac = $save_data['unit_carder'];
                    // Note: Match out carder would need to be computed server-side;
                    // for now, use unit_carder for assembly rows other than SHIRT/TROUSER
                    // (SHIRT/TROUSER rows send their TAC from JS via a hidden field — 
                    // but since we don't have that, we use unit_carder here)
                    
                    // Try to fetch Match Out carder for SHIRT/TROUSER
                    try {
                        $comp_name_stmt = $conn->prepare("SELECT name FROM components WHERE id = ? LIMIT 1");
                        $comp_name_stmt->execute([$component_id]);
                        $comp_name_row = $comp_name_stmt->fetch(PDO::FETCH_ASSOC);
                        $comp_name_upper = $comp_name_row ? strtoupper(trim($comp_name_row['name'])) : '';
                        
                        if ($comp_name_upper === 'SHIRT') {
                            $shirt_mo = calculateMatchOutFixed($conn, 1, $date, $work_hours);
                            $tac = $save_data['unit_carder'] + (int)($shirt_mo['unit_carder'] ?? 0);
                        } elseif ($comp_name_upper === 'TROUSER') {
                            $trouser_mo = calculateMatchOutFixed($conn, 2, $date, $work_hours);
                            $tac = $save_data['unit_carder'] + (int)($trouser_mo['unit_carder'] ?? 0);
                        }
                    } catch (Exception $e) {
                        // ignore, use unit_carder
                    }
                    
                    $save_data['available_minutes'] = $tac * $save_data['plan_hours'] * 60;
                    $save_data['plan_minutes'] = $save_data['day_forecast'] * $save_data['unit_smv'];
                    $save_data['plan_eff'] = ($save_data['available_minutes'] > 0) 
                        ? ($save_data['plan_minutes'] / $save_data['available_minutes']) : 0;
                    $save_data['target_100'] = ($save_data['unit_smv'] > 0) 
                        ? ($save_data['unit_carder'] / $save_data['unit_smv']) * 60 : 0;
                    
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
                    
                    // Profit multipliers
                    $mult = 500;
                    if ($comp_name_upper === 'SHIRT MTM') $mult = 1100;
                    elseif ($comp_name_upper === 'TROUSER') $mult = 800;
                    elseif ($comp_name_upper === 'TROUSER MTM') $mult = 1500;
                    elseif ($comp_name_upper === 'COAT') $mult = 2800;
                    elseif ($comp_name_upper === 'COAT MTM') $mult = 3400;
                    elseif ($comp_name_upper === 'KNIT') $mult = 295;
                    
                    $save_data['profit'] = ($mult * $save_data['day_total']) - (7365 * $tac);
                    
                } else {
                    // SHIRT/TROUSER/COAT FORMULAS (90% target)
                    $calculation_carder = $can_save_direct_staffing
                        ? ($direct_present ?? $save_data['unit_carder'])
                        : $save_data['unit_carder'];
                    if ($save_data['unit_smv'] > 0 && $calculation_carder > 0) {
                        $save_data['day_forecast'] = ($calculation_carder * 600 / $save_data['unit_smv']) * 0.90;
                    } else {
                        $save_data['day_forecast'] = 0;
                    }
                    $save_data['available_minutes'] = $calculation_carder * $save_data['plan_hours'] * 60;
                    $save_data['plan_minutes'] = $save_data['day_forecast'] * $save_data['unit_smv'];
                    $save_data['plan_eff'] = ($save_data['available_minutes'] > 0) 
                        ? ($save_data['plan_minutes'] / $save_data['available_minutes']) : 0;
                    $save_data['target_100'] = ($save_data['unit_smv'] > 0) 
                        ? ($calculation_carder / $save_data['unit_smv']) * 60 : 0;
                    
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
                    
                    if ($save_data['plan_hours'] > 0) {
                        $save_data['profit'] = ($save_data['epm'] * ($save_data['day_total'] * $save_data['unit_smv'])) 
                            - (7365 * $calculation_carder) * ($save_data['worked_hours'] / $save_data['plan_hours']);
                    } else {
                        $save_data['profit'] = ($save_data['epm'] * ($save_data['day_total'] * $save_data['unit_smv'])) 
                            - (7365 * $calculation_carder);
                    }
                }
            }
            
            // Normalize KNIT saves for division 7
            if ($division_id === 7 && $component_id > 0 && !$is_dhu_row) {
                $knit_check = $conn->prepare("SELECT name FROM components WHERE id = ? AND division_id = ? LIMIT 1");
                $knit_check->execute([$component_id, $division_id]);
                $knit_name = $knit_check->fetchColumn();
                
                if ($knit_name !== false && strtoupper(trim($knit_name)) === 'KNIT') {
                    $knit_stmt = $conn->prepare("
                        SELECT c.id FROM components c
                        WHERE c.division_id = 7
                          AND UPPER(TRIM(c.name)) = 'KNIT'
                          AND c.is_match_out = 0
                        ORDER BY CASE WHEN c.id = ? THEN 0 ELSE 1 END, c.display_order ASC, c.id ASC
                        LIMIT 1
                    ");
                    $knit_stmt->execute([$component_id]);
                    $canonical_knit_id = (int)($knit_stmt->fetchColumn() ?: 0);
                    if ($canonical_knit_id > 0) {
                        $component_id = $canonical_knit_id;
                        $save_data['unit_id'] = $canonical_knit_id;
                    }
                }
            }
            
            // DIRECT INSERT/UPDATE
            try {
                $check = $conn->prepare("SELECT id FROM production_reports WHERE devition_id = ? AND unit_id = ? AND report_date = ?");
                $check->execute([$save_data['devition_id'], $save_data['unit_id'], $save_data['report_date']]);
                $existing = $check->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    $sql = "UPDATE production_reports SET 
                            ttl_sam_pc = ?, unit_smv = ?, day_forecast = ?, unit_carder = ?, 
                            direct_on_roll = COALESCE(?, direct_on_roll),
                            direct_present = COALESCE(?, direct_present),
                            plan_hours = ?, worked_hours = ?, available_minutes = ?, 
                            plan_minutes = ?, plan_eff = ?, target_100 = ?, 
                            hour_1 = ?, hour_2 = ?, hour_3 = ?, hour_4 = ?, hour_5 = ?, 
                            hour_6 = ?, hour_7 = ?, hour_8 = ?, hour_9 = ?, hour_10 = ?, hour_11 = ?, 
                            day_total = ?, acvd_eff = ?, ern_minutes = ?, epm = ?, style_epm = ?, profit = ? 
                            WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $result = $stmt->execute([
                        $save_data['ttl_sam_pc'], $save_data['unit_smv'], $save_data['day_forecast'], 
                        $save_data['unit_carder'], $direct_on_roll, $direct_present,
                        $save_data['plan_hours'], $save_data['worked_hours'],
                        $save_data['available_minutes'], $save_data['plan_minutes'], $save_data['plan_eff'], 
                        $save_data['target_100'],
                        $save_data['hour_1'], $save_data['hour_2'], $save_data['hour_3'], $save_data['hour_4'], 
                        $save_data['hour_5'], $save_data['hour_6'], $save_data['hour_7'], $save_data['hour_8'], 
                        $save_data['hour_9'], $save_data['hour_10'], $save_data['hour_11'],
                        $save_data['day_total'], $save_data['acvd_eff'], $save_data['ern_minutes'],
                        $save_data['epm'], $save_data['style_epm'], $save_data['profit'],
                        $existing['id']
                    ]);
                } else {
                    $sql = "INSERT INTO production_reports (
                        report_date, devition_id, unit_id, ttl_sam_pc, unit_smv, 
                        day_forecast, unit_carder, direct_on_roll, direct_present, plan_hours, worked_hours,
                        available_minutes, plan_minutes, plan_eff, target_100, 
                        hour_1, hour_2, hour_3, hour_4, hour_5, hour_6, 
                        hour_7, hour_8, hour_9, hour_10, hour_11, 
                        day_total, acvd_eff, ern_minutes, epm, style_epm, profit
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $result = $stmt->execute([
                        $save_data['report_date'], $save_data['devition_id'], $save_data['unit_id'],
                        $save_data['ttl_sam_pc'], $save_data['unit_smv'], $save_data['day_forecast'], 
                        $save_data['unit_carder'], $direct_on_roll ?? 0, $direct_present ?? 0,
                        $save_data['plan_hours'], $save_data['worked_hours'],
                        $save_data['available_minutes'], $save_data['plan_minutes'], $save_data['plan_eff'], 
                        $save_data['target_100'],
                        $save_data['hour_1'], $save_data['hour_2'], $save_data['hour_3'], $save_data['hour_4'], 
                        $save_data['hour_5'], $save_data['hour_6'], $save_data['hour_7'], $save_data['hour_8'], 
                        $save_data['hour_9'], $save_data['hour_10'], $save_data['hour_11'],
                        $save_data['day_total'], $save_data['acvd_eff'], $save_data['ern_minutes'],
                        $save_data['epm'], $save_data['style_epm'], $save_data['profit']
                    ]);
                }
                
                if ($result) {
                    // Summary rows — only if it's a regular component (not DHU, not summary)
                    $is_summary_row = in_array($component_id, [996, 997, 998, 999]) 
                        || ($component_id >= 900 && $component_id <= 906);
                    
                    if (!$is_summary_row && !$is_dhu_row) {
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
                        'acvd_eff' => number_format($save_data['acvd_eff'] * 100, 1),
                        'profit' => number_format($save_data['profit'], 2)
                    ];
                } else {
                    $response['message'] = 'Database save failed.';
                }
            } catch (Exception $e) {
                $response['message'] = 'Database save failed. Check the installed database schema and PHP error log.';
                error_log('Save error: ' . $e->getMessage());
                http_response_code(500);
            }
        } else {
            $response['message'] = 'Invalid data format';
            http_response_code(400);
        }
    } else {
        $response['message'] = 'Invalid action or missing data';
        http_response_code(400);
    }
} catch (Exception $e) {
    $response['message'] = 'Save failed. Check the PHP error log.';
    error_log('Save error: ' . $e->getMessage());
    http_response_code(500);
}

if (!$response['success'] && http_response_code() === 200) {
    http_response_code(400);
}

echo json_encode($response);
exit;
?>