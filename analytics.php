<?php
// analytics.php - Complete Analytics Dashboard with Real Data
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$conn = getDB();
$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get filters from URL
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selected_division = isset($_GET['division']) ? (int)$_GET['division'] : 1;
$trend_days = isset($_GET['days']) ? (int)$_GET['days'] : 30;

// Validate division
$valid_divisions = [1, 2, 3, 7];
if (!in_array($selected_division, $valid_divisions)) {
    $selected_division = 1;
}

$work_hours = 11;

// Division mapping
$division_names = [
    1 => 'Shirt',
    2 => 'Trouser', 
    3 => 'Coat',
    7 => 'Assembly'
];

$division_icons = [
    1 => '👔',
    2 => '👖',
    3 => '🧥',
    7 => '🏭'
];

// ============================================================
// FUNCTIONS TO GET REAL DATA FROM DATABASE
// ============================================================

/**
 * Get all components for a division with their report data for a specific date
 */
function getDivisionReportData($conn, $division_id, $date, $work_hours) {
    $components = getComponents($conn, $division_id);
    $result = [];
    
    foreach ($components as $comp) {
        if ($comp['is_match_out']) continue;
        
        $data = getReportData($conn, $division_id, $comp['id'], $date);
        
        // Ensure all fields exist
        $data['ttl_sam_pc'] = (float)($data['ttl_sam_pc'] ?? 0);
        $data['unit_smv'] = (float)($data['unit_smv'] ?? 0);
        $data['unit_carder'] = (int)($data['unit_carder'] ?? 0);
        $data['plan_hours'] = (float)($data['plan_hours'] ?? 0);
        $data['worked_hours'] = (float)($data['worked_hours'] ?? $work_hours);
        $data['day_forecast'] = (float)($data['day_forecast'] ?? 0);
        $data['available_minutes'] = (float)($data['available_minutes'] ?? 0);
        $data['plan_minutes'] = (float)($data['plan_minutes'] ?? 0);
        $data['plan_eff'] = (float)($data['plan_eff'] ?? 0);
        $data['target_100'] = (float)($data['target_100'] ?? 0);
        $data['day_total'] = (float)($data['day_total'] ?? 0);
        $data['ern_minutes'] = (float)($data['ern_minutes'] ?? 0);
        $data['acvd_eff'] = (float)($data['acvd_eff'] ?? 0);
        $data['epm'] = (float)($data['epm'] ?? 13.2);
        $data['profit'] = (float)($data['profit'] ?? 0);
        
        for ($h = 1; $h <= 11; $h++) {
            $data["hour_$h"] = (float)($data["hour_$h"] ?? 0);
        }
        
        $result[$comp['id']] = [
            'id' => $comp['id'],
            'name' => $comp['name'],
            'data' => $data
        ];
    }
    
    return $result;
}

/**
 * Get hourly data averaged across all components for a division
 */
function getDivisionHourlyData($conn, $division_id, $date, $work_hours) {
    $components = getDivisionReportData($conn, $division_id, $date, $work_hours);
    
    $hourly_production = array_fill(1, $work_hours, 0);
    $hourly_eff = array_fill(1, $work_hours, 0);
    $hourly_dhu = array_fill(1, $work_hours, 0);
    $component_count = 0;
    
    foreach ($components as $comp) {
        $data = $comp['data'];
        if ($data['ttl_sam_pc'] > 0 || $data['unit_smv'] > 0) {
            $component_count++;
            for ($h = 1; $h <= $work_hours; $h++) {
                $hourly_production[$h] += (float)($data["hour_$h"] ?? 0);
                $hourly_eff[$h] += (float)($data['acvd_eff'] ?? 0) * 100;
                $hourly_dhu[$h] += (float)($data["hour_$h"] ?? 0) > 0 ? ((float)($data["hour_$h"] ?? 0) / 100) * 5 : 0;
            }
        }
    }
    
    if ($component_count > 0) {
        for ($h = 1; $h <= $work_hours; $h++) {
            $hourly_production[$h] = round($hourly_production[$h] / $component_count, 0);
            $hourly_eff[$h] = round($hourly_eff[$h] / $component_count, 1);
            $hourly_dhu[$h] = round($hourly_dhu[$h] / $component_count, 1);
        }
    }
    
    return [
        'production' => $hourly_production,
        'eff' => $hourly_eff,
        'dhu' => $hourly_dhu,
        'component_count' => $component_count
    ];
}

/**
 * Get trend data for the last N days
 */
function getDivisionTrendData($conn, $division_id, $days) {
    $trend_data = [];
    $dhu_trend = [];
    
    try {
        $stmt = $conn->prepare("
            SELECT DISTINCT report_date 
            FROM production_reports 
            WHERE devition_id = ? 
            AND unit_id NOT IN (996, 997, 998, 999)
            AND day_total > 0
            ORDER BY report_date DESC 
            LIMIT ?
        ");
        $stmt->execute([$division_id, $days]);
        $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $dates = array_reverse($dates);
        
        foreach ($dates as $date) {
            $hourly = getDivisionHourlyData($conn, $division_id, $date, 10);
            
            $total_pcs = array_sum($hourly['production']);
            $avg_eff = count($hourly['eff']) > 0 ? round(array_sum($hourly['eff']) / count($hourly['eff']), 1) : 0;
            $avg_dhu = count($hourly['dhu']) > 0 ? round(array_sum($hourly['dhu']) / count($hourly['dhu']), 1) : 0;
            
            $trend_data[] = [
                'date' => $date,
                'pcs' => $total_pcs,
                'eff' => $avg_eff
            ];
            $dhu_trend[] = [
                'date' => $date,
                'dhu' => $avg_dhu
            ];
        }
        
        if (empty($trend_data)) {
            return [
                'production' => [['date' => date('Y-m-d'), 'pcs' => 0, 'eff' => 0]],
                'dhu' => [['date' => date('Y-m-d'), 'dhu' => 0]]
            ];
        }
        
    } catch (Exception $e) {
        error_log("Trend data error: " . $e->getMessage());
        return [
            'production' => [['date' => date('Y-m-d'), 'pcs' => 0, 'eff' => 0]],
            'dhu' => [['date' => date('Y-m-d'), 'dhu' => 0]]
        ];
    }
    
    return ['production' => $trend_data, 'dhu' => $dhu_trend];
}

/**
 * Get summary rows (Match Out, DHU, Lean Total, Grand Total)
 */
function getSummaryRows($conn, $division_id, $date) {
    $summary = [
        'match_out' => null,
        'dhu' => null,
        'lean_total' => null,
        'grand_total' => null
    ];
    
    try {
        $stmt = $conn->prepare("
            SELECT * FROM production_reports 
            WHERE devition_id = ? AND report_date = ? 
            AND unit_id IN (996, 997, 998, 999)
        ");
        $stmt->execute([$division_id, $date]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($results as $row) {
            if ($row['unit_id'] == 999) {
                $summary['match_out'] = $row;
            } elseif ($row['unit_id'] == 998) {
                $summary['dhu'] = $row;
            } elseif ($row['unit_id'] == 997) {
                $summary['lean_total'] = $row;
            } elseif ($row['unit_id'] == 996) {
                $summary['grand_total'] = $row;
            }
        }
    } catch (Exception $e) {
        error_log("Summary rows error: " . $e->getMessage());
    }
    
    return $summary;
}

/**
 * Get Assembly data for Shirt/Trouser/Coat pages
 */
function getAssemblyData($conn, $date, $work_hours) {
    $assembly_data = [];
    $assembly_division_id = 7;
    $assembly_components = getComponents($conn, $assembly_division_id);
    
    foreach ($assembly_components as $comp) {
        if ($comp['is_match_out']) continue;
        $data = getReportData($conn, $assembly_division_id, $comp['id'], $date);
        
        $data['ttl_sam_pc'] = (float)($data['ttl_sam_pc'] ?? 0);
        $data['unit_smv'] = (float)($data['unit_smv'] ?? 0);
        $data['unit_carder'] = (int)($data['unit_carder'] ?? 0);
        $data['plan_hours'] = (float)($data['plan_hours'] ?? 0);
        $data['worked_hours'] = (float)($data['worked_hours'] ?? $work_hours);
        $data['day_forecast'] = (float)($data['day_forecast'] ?? 0);
        $data['available_minutes'] = (float)($data['available_minutes'] ?? 0);
        $data['plan_minutes'] = (float)($data['plan_minutes'] ?? 0);
        $data['plan_eff'] = (float)($data['plan_eff'] ?? 0);
        $data['target_100'] = (float)($data['target_100'] ?? 0);
        $data['day_total'] = (float)($data['day_total'] ?? 0);
        $data['ern_minutes'] = (float)($data['ern_minutes'] ?? 0);
        $data['acvd_eff'] = (float)($data['acvd_eff'] ?? 0);
        $data['epm'] = (float)($data['epm'] ?? 13.2);
        $data['profit'] = (float)($data['profit'] ?? 0);
        
        for ($h = 1; $h <= 11; $h++) {
            $data["hour_$h"] = (float)($data["hour_$h"] ?? 0);
        }
        
        $assembly_data[$comp['name']] = $data;
    }
    
    return $assembly_data;
}

// ============================================================
// GET REAL DATA
// ============================================================

$division_name = $division_names[$selected_division] ?? 'Division';
$is_assembly = ($selected_division == 7);

// Get hourly data
$hourly_data = getDivisionHourlyData($conn, $selected_division, $selected_date, $work_hours);

// Get component data for table
$component_data = getDivisionReportData($conn, $selected_division, $selected_date, $work_hours);

// Get summary rows (Match Out, DHU, etc.)
$summary_rows = getSummaryRows($conn, $selected_division, $selected_date);

// Get trend data
$trend_data = getDivisionTrendData($conn, $selected_division, $trend_days);

// Get Assembly data for Shirt/Trouser/Coat pages
$assembly_data = [];
if ($selected_division == 1 || $selected_division == 2 || $selected_division == 3) {
    $assembly_data = getAssemblyData($conn, $selected_date, $work_hours);
}

// Calculate stats
$total_pcs = array_sum($hourly_data['production']);
$avg_eff = count($hourly_data['eff']) > 0 ? round(array_sum($hourly_data['eff']) / count($hourly_data['eff']), 1) : 0;
$avg_dhu = count($hourly_data['dhu']) > 0 ? round(array_sum($hourly_data['dhu']) / count($hourly_data['dhu']), 1) : 0;

// ============================================================
// BUILD COLUMN LIST WITH CORRECT ORDER
// ============================================================

$columns = [];

if ($selected_division == 1) {
    // SHIRT: Front → Back → Collar → Sleeve → Cuff → Match Out → Assembly SHIRT MTM → DHU
    
    // 1. Main components
    $shirt_order = ['Front', 'Back', 'Collar', 'Sleeve', 'Cuff'];
    foreach ($shirt_order as $name) {
        $found = false;
        foreach ($component_data as $comp) {
            if ($comp['name'] === $name) {
                $columns[] = [
                    'name' => $name,
                    'data' => $comp['data'],
                    'type' => 'component'
                ];
                $found = true;
                break;
            }
        }
        if (!$found) {
            $columns[] = [
                'name' => $name,
                'data' => ['unit_carder' => 0, 'acvd_eff' => 0, 'day_total' => 0],
                'type' => 'component'
            ];
        }
    }
    
    // 2. Match Out
    if (isset($summary_rows['match_out']) && ($summary_rows['match_out']['unit_smv'] ?? 0) > 0) {
        $columns[] = [
            'name' => 'Match Out',
            'data' => $summary_rows['match_out'],
            'type' => 'match_out'
        ];
    } else {
        $columns[] = [
            'name' => 'Match Out',
            'data' => ['unit_carder' => 0, 'acvd_eff' => 0, 'day_total' => 0],
            'type' => 'match_out'
        ];
    }
    
    // 3. Assembly SHIRT MTM
    if (isset($assembly_data['SHIRT MTM']) && $assembly_data['SHIRT MTM']['ttl_sam_pc'] > 0) {
        $columns[] = [
            'name' => 'SHIRT MTM',
            'data' => $assembly_data['SHIRT MTM'],
            'type' => 'assembly'
        ];
    } else {
        $columns[] = [
            'name' => 'SHIRT MTM',
            'data' => ['unit_carder' => 0, 'acvd_eff' => 0, 'day_total' => 0],
            'type' => 'assembly'
        ];
    }
    
    // 4. DHU
    if (isset($summary_rows['dhu']) && ($summary_rows['dhu']['day_total'] ?? 0) > 0) {
        $columns[] = [
            'name' => 'DHU',
            'data' => $summary_rows['dhu'],
            'type' => 'dhu'
        ];
    } else {
        $columns[] = [
            'name' => 'DHU',
            'data' => ['unit_carder' => 0, 'acvd_eff' => 0, 'day_total' => 0],
            'type' => 'dhu'
        ];
    }
    
} elseif ($selected_division == 2) {
    // TROUSER: Front → Back → Band → Match Out → Assembly TROUSER MTM → DHU
    
    // 1. Main components
    $trouser_order = ['Front', 'Back', 'Band'];
    foreach ($trouser_order as $name) {
        $found = false;
        foreach ($component_data as $comp) {
            if ($comp['name'] === $name) {
                $columns[] = [
                    'name' => $name,
                    'data' => $comp['data'],
                    'type' => 'component'
                ];
                $found = true;
                break;
            }
        }
        if (!$found) {
            $columns[] = [
                'name' => $name,
                'data' => ['unit_carder' => 0, 'acvd_eff' => 0, 'day_total' => 0],
                'type' => 'component'
            ];
        }
    }
    
    // 2. Match Out
    if (isset($summary_rows['match_out']) && ($summary_rows['match_out']['unit_smv'] ?? 0) > 0) {
        $columns[] = [
            'name' => 'Match Out',
            'data' => $summary_rows['match_out'],
            'type' => 'match_out'
        ];
    } else {
        $columns[] = [
            'name' => 'Match Out',
            'data' => ['unit_carder' => 0, 'acvd_eff' => 0, 'day_total' => 0],
            'type' => 'match_out'
        ];
    }
    
    // 3. Assembly TROUSER MTM
    if (isset($assembly_data['TROUSER MTM']) && $assembly_data['TROUSER MTM']['ttl_sam_pc'] > 0) {
        $columns[] = [
            'name' => 'TROUSER MTM',
            'data' => $assembly_data['TROUSER MTM'],
            'type' => 'assembly'
        ];
    } else {
        $columns[] = [
            'name' => 'TROUSER MTM',
            'data' => ['unit_carder' => 0, 'acvd_eff' => 0, 'day_total' => 0],
            'type' => 'assembly'
        ];
    }
    
    // 4. DHU
    if (isset($summary_rows['dhu']) && ($summary_rows['dhu']['day_total'] ?? 0) > 0) {
        $columns[] = [
            'name' => 'DHU',
            'data' => $summary_rows['dhu'],
            'type' => 'dhu'
        ];
    } else {
        $columns[] = [
            'name' => 'DHU',
            'data' => ['unit_carder' => 0, 'acvd_eff' => 0, 'day_total' => 0],
            'type' => 'dhu'
        ];
    }
    
} elseif ($selected_division == 3) {
    // COAT: ONLY SHOW COAT MTM from Assembly division
    
    // Get COAT MTM from Assembly data
    if (isset($assembly_data['COAT MTM']) && $assembly_data['COAT MTM']['ttl_sam_pc'] > 0) {
        $columns[] = [
            'name' => 'COAT MTM',
            'data' => $assembly_data['COAT MTM'],
            'type' => 'assembly'
        ];
    } else {
        // Try to get COAT MTM directly from Assembly division (ID 7)
        $assembly_components = getComponents($conn, 7);
        $found = false;
        foreach ($assembly_components as $comp) {
            if ($comp['name'] === 'COAT MTM' && !$comp['is_match_out']) {
                $coat_mtm_data = getReportData($conn, 7, $comp['id'], $selected_date);
                if ($coat_mtm_data && ($coat_mtm_data['ttl_sam_pc'] ?? 0) > 0) {
                    $columns[] = [
                        'name' => 'COAT MTM',
                        'data' => $coat_mtm_data,
                        'type' => 'assembly'
                    ];
                    $found = true;
                }
                break;
            }
        }
        // If COAT MTM not found, show empty column
        if (!$found) {
            $columns[] = [
                'name' => 'COAT MTM',
                'data' => ['unit_carder' => 0, 'acvd_eff' => 0, 'day_total' => 0],
                'type' => 'assembly'
            ];
        }
    }
    
} elseif ($selected_division == 7) {
    // ASSEMBLY: SHIRT → SHIRT MTM → TROUSER → TROUSER MTM → COAT → COAT MTM → KNIT
    $assembly_order = ['SHIRT', 'SHIRT MTM', 'TROUSER', 'TROUSER MTM', 'COAT', 'COAT MTM', 'KNIT'];
    $seen_knit = false;
    
    foreach ($assembly_order as $name) {
        foreach ($component_data as $comp) {
            if ($comp['name'] === $name) {
                if ($name === 'KNIT') {
                    if (!$seen_knit) {
                        $columns[] = [
                            'name' => $name,
                            'data' => $comp['data'],
                            'type' => 'component'
                        ];
                        $seen_knit = true;
                    }
                } else {
                    $columns[] = [
                        'name' => $name,
                        'data' => $comp['data'],
                        'type' => 'component'
                    ];
                }
                break;
            }
        }
    }
}

// If no data, use placeholder columns
if (empty($columns)) {
    $columns[] = ['name' => 'No Data', 'data' => ['unit_carder' => 0, 'acvd_eff' => 0, 'day_total' => 0], 'type' => 'component'];
}

// Check if we have data for today
$has_data = ($total_pcs > 0 || $hourly_data['component_count'] > 0);
$display_date = date('M d, Y', strtotime($selected_date));

// Check if summary rows exist for badges
$has_match_out = isset($summary_rows['match_out']) && ($summary_rows['match_out']['unit_smv'] ?? 0) > 0;
$has_dhu = isset($summary_rows['dhu']) && ($summary_rows['dhu']['day_total'] ?? 0) > 0;
$has_lean_total = isset($summary_rows['lean_total']) && ($summary_rows['lean_total']['ttl_sam_pc'] ?? 0) > 0;
$has_grand_total = isset($summary_rows['grand_total']) && ($summary_rows['grand_total']['ttl_sam_pc'] ?? 0) > 0;

// Prepare chart data
$chart_labels = range(1, $work_hours);
$production_data = array_values($hourly_data['production']);
$eff_data = array_values($hourly_data['eff']);
$dhu_chart_data = array_values($hourly_data['dhu']);

// Trend chart data
$trend_labels = array_map(function($item) { 
    return date('M d', strtotime($item['date'])); 
}, $trend_data['production']);
$trend_pcs = array_map(function($item) { 
    return $item['pcs']; 
}, $trend_data['production']);
$trend_eff = array_map(function($item) { 
    return $item['eff']; 
}, $trend_data['production']);
$trend_dhu_data = array_map(function($item) { 
    return $item['dhu']; 
}, $trend_data['dhu']);

// Get column names for headers
$column_names = array_map(function($col) { return $col['name']; }, $columns);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard - Hameedia</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #217346;
            --primary-dark: #1a5c3a;
            --bg: #f0f2f5;
            --text: #1a2332;
            --text-dark: #0d1a2b;
            --steel: #6b7a8f;
            --line: rgba(255,255,255,0.2);
            --border-radius: 12px;
            --shadow: 0 4px 20px rgba(0,0,0,0.08);
            --glass-border: rgba(255,255,255,0.3);
            --glass-bg: rgba(255,255,255,0.15);
            --good: #28a745;
            --bad: #dc3545;
            --warning: #ffc107;
            --amber: #f57c00;
            --dhu-color: #e74c3c;
            --dhu-bg: rgba(220, 53, 69, 0.12);
            --match-bg: rgba(108, 117, 125, 0.1);
            --assembly-bg: rgba(23, 162, 184, 0.08);
        }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 50%, #a5d6a7 100%);
            min-height: 100vh;
            color: var(--text);
            position: relative;
        }
        .bg-shapes {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
            pointer-events: none;
        }
        .shape {
            position: absolute;
            border-radius: 50%;
            opacity: 0.08;
            animation: float 25s infinite ease-in-out;
        }
        .shape-1 { width: 500px; height: 500px; background: var(--primary); top: -150px; right: -150px; }
        .shape-2 { width: 300px; height: 300px; background: var(--primary); bottom: -100px; left: -100px; animation-delay: -8s; }
        .shape-3 { width: 200px; height: 200px; background: var(--primary); top: 50%; left: 50%; transform: translate(-50%, -50%); animation-delay: -15s; }
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            25% { transform: translate(60px, -60px) scale(1.1); }
            50% { transform: translate(-40px, 40px) scale(0.9); }
            75% { transform: translate(30px, 30px) scale(1.05); }
        }
        
        .topbar {
            position: relative;
            z-index: 10;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--glass-border);
            padding: 10px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }
        .topbar .logo-mark { 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            font-weight: 800; 
            font-size: 20px; 
            color: var(--primary-dark);
            text-decoration: none;
        }
        .topbar .logo-mark .logo-icon { 
            font-size: 32px;
            background: var(--primary);
            color: #fff;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            font-weight: 700;
            font-size: 18px;
        }
        .topbar .logo-mark .logo-text {
            letter-spacing: -0.5px;
        }
        .topbar .logo-mark .logo-text span {
            color: var(--primary);
        }
        .topnav { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .topnav a {
            color: var(--steel);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            padding: 7px 16px;
            border-radius: 10px;
            transition: all 0.3s;
            background: transparent;
        }
        .topnav a:hover { color: var(--primary); background: rgba(33, 115, 70, 0.08); }
        .topnav a.active { color: #fff; background: var(--primary); box-shadow: 0 4px 15px rgba(33, 115, 70, 0.3); }
        .right { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; font-size: 13px; color: var(--steel); }
        .live-chip { display: flex; align-items: center; gap: 6px; background: rgba(33, 115, 70, 0.1); padding: 4px 12px; border-radius: 20px; font-size: 12px; color: var(--primary); font-weight: 600; }
        .live-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--good); animation: blink 1.5s infinite; }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
        .logout { color: var(--steel); text-decoration: none; padding: 5px 14px; border-radius: 8px; transition: all 0.3s; background: rgba(255,255,255,0.5); font-weight: 600; font-size: 13px; }
        .logout:hover { background: rgba(220, 53, 69, 0.1); color: var(--bad); }
        .date-display { color: var(--text-dark); font-size: 13px; font-weight: 600; }
        .user-name { color: var(--text-dark); font-weight: 600; font-size: 13px; }
        .admin-badge { font-size: 9px; background: var(--primary); color: #fff; padding: 2px 8px; border-radius: 10px; font-weight: 600; }

        .analytics-container {
            position: relative;
            z-index: 5;
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px 30px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .page-header .title h2 { font-size: 24px; font-weight: 800; color: var(--text-dark); }
        .page-header .title p { color: var(--steel); font-size: 14px; font-weight: 500; margin-top: 4px; }
        .page-header .controls { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .page-header .controls input[type="date"] { 
            padding: 7px 12px; 
            border: 1px solid var(--glass-border); 
            border-radius: 8px; 
            font-size: 13px; 
            font-weight: 500; 
            font-family: 'Inter', sans-serif; 
            background: rgba(255,255,255,0.7); 
            color: var(--text-dark);
        }
        .page-header .controls input[type="date"]:focus { outline: none; border-color: var(--primary); }
        .btn {
            padding: 7px 16px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 4px 15px rgba(33,115,70,0.3); }
        .btn-secondary { background: rgba(255,255,255,0.5); color: var(--text-dark); border: 1px solid var(--glass-border); }
        .btn-secondary:hover { background: rgba(255,255,255,0.8); }

        .division-selector {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            padding: 16px 20px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }
        .division-btn {
            padding: 10px 28px;
            border: 2px solid var(--glass-border);
            border-radius: 10px;
            background: rgba(255,255,255,0.3);
            color: var(--text-dark);
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .division-btn:hover { background: rgba(255,255,255,0.6); transform: translateY(-2px); }
        .division-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); box-shadow: 0 4px 15px rgba(33,115,70,0.3); }
        .division-btn .icon { font-size: 20px; }

        .summary-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .summary-badges .badge {
            padding: 4px 14px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
            color: #fff;
        }
        .badge-match-out { background: #6c757d; }
        .badge-dhu { background: #dc3545; }
        .badge-lean { background: #17a2b8; }
        .badge-grand { background: #6f42c1; }
        .badge-no-data { background: #6c757d; opacity: 0.5; }

        .no-data-message {
            text-align: center;
            padding: 20px;
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
            color: var(--steel);
            font-weight: 500;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: 16px 20px;
            box-shadow: var(--shadow);
            text-align: center;
            transition: all 0.3s ease;
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,0.1); }
        .stat-card .number {
            font-size: 28px;
            font-weight: 900;
            color: var(--primary);
        }
        .stat-card .number.dhu { color: var(--dhu-color); }
        .stat-card .number.eff { color: var(--amber); }
        .stat-card .label {
            font-size: 13px;
            font-weight: 600;
            color: var(--steel);
            margin-top: 4px;
        }

        .dashboard-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }
        .dashboard-table th {
            background: rgba(255,255,255,0.3);
            padding: 8px 10px;
            text-align: center;
            font-weight: 700;
            color: var(--text-dark);
            border: 1px solid var(--glass-border);
            font-size: 11px;
        }
        .dashboard-table td {
            padding: 6px 10px;
            text-align: center;
            border: 1px solid var(--glass-border);
            font-weight: 500;
            font-size: 11px;
        }
        .dashboard-table .header-row td {
            background: rgba(33, 115, 70, 0.15);
            font-weight: 700;
            font-size: 12px;
        }
        .dashboard-table .match-row td {
            background: var(--match-bg);
            font-weight: 600;
        }
        .dashboard-table .dhu-row td {
            background: var(--dhu-bg);
            color: var(--dhu-color);
            font-weight: 600;
        }
        .dashboard-table .assembly-row td {
            background: var(--assembly-bg);
            font-weight: 600;
        }
        .dashboard-table .total-row td {
            background: rgba(33, 150, 243, 0.08);
            font-weight: 700;
        }
        .dashboard-table .lean-total td {
            background: rgba(23, 162, 184, 0.08);
            font-weight: 700;
        }
        .dashboard-table .grand-total td {
            background: rgba(111, 66, 193, 0.08);
            font-weight: 700;
        }
        .dashboard-table .loss-row td {
            background: rgba(220, 53, 69, 0.08);
            font-weight: 700;
        }
        .dashboard-table .component-name {
            font-weight: 700;
            color: var(--text-dark);
            text-align: left;
        }
        .dashboard-table .eff-good { color: var(--good); font-weight: 700; }
        .dashboard-table .eff-bad { color: var(--bad); font-weight: 700; }
        .dashboard-table .eff-avg { color: var(--warning); font-weight: 700; }

        .chart-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .chart-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: 16px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }
        .chart-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,0.1); }
        .chart-card h4 {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 10px;
            text-align: center;
            padding-bottom: 6px;
            border-bottom: 2px solid var(--primary);
        }
        .chart-card .chart-wrapper {
            position: relative;
            height: 220px;
        }
        .chart-card .chart-wrapper canvas {
            width: 100% !important;
            height: 100% !important;
        }
        .chart-full { grid-column: 1 / -1; }
        .chart-full .chart-wrapper { height: 250px; }

        .loss-profit-container {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }
        .loss-profit-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: 16px;
            box-shadow: var(--shadow);
            text-align: center;
            transition: all 0.3s ease;
        }
        .loss-profit-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,0.1); }
        .loss-profit-card .amount {
            font-size: 24px;
            font-weight: 900;
            color: var(--bad);
        }
        .loss-profit-card .amount.profit { color: var(--good); }
        .loss-profit-card .label {
            font-size: 12px;
            font-weight: 600;
            color: var(--steel);
            margin-top: 4px;
        }
        .loss-profit-card .icon { font-size: 28px; margin-bottom: 4px; }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 18px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 10px;
            color: var(--text-dark);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        .back-button:hover {
            background: rgba(255,255,255,0.3);
            transform: translateX(-4px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        @media (max-width: 1200px) {
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .loss-profit-container { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 1024px) {
            .chart-grid { grid-template-columns: 1fr; }
            .chart-full { grid-column: 1; }
        }
        @media (max-width: 768px) {
            .topbar { padding: 10px 16px; flex-direction: column; align-items: stretch; gap: 8px; }
            .topnav { justify-content: center; }
            .right { justify-content: center; }
            .analytics-container { padding: 12px 16px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .page-header .controls { width: 100%; flex-wrap: wrap; }
            .division-selector { flex-direction: row; flex-wrap: wrap; justify-content: center; }
            .division-btn { padding: 8px 16px; font-size: 13px; flex: 1; min-width: 80px; justify-content: center; }
            .stats-row { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card { padding: 12px 16px; }
            .stat-card .number { font-size: 22px; }
            .chart-card { padding: 12px; }
            .chart-card .chart-wrapper { height: 180px; }
            .loss-profit-container { grid-template-columns: 1fr 1fr; gap: 10px; }
            .topnav a { padding: 6px 12px; font-size: 13px; }
            .dashboard-table { font-size: 10px; }
            .dashboard-table th,
            .dashboard-table td { padding: 4px 6px; }
        }
        @media (max-width: 480px) {
            .division-btn { font-size: 12px; padding: 6px 12px; min-width: 60px; }
            .stats-row { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card .number { font-size: 18px; }
            .chart-card .chart-wrapper { height: 150px; }
            .loss-profit-container { grid-template-columns: 1fr 1fr; gap: 8px; }
            .loss-profit-card .amount { font-size: 18px; }
        }
    </style>
</head>
<body>
    <div class="bg-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
    </div>

    <div class="topbar">
        <a href="dashboard.php" class="logo-mark">
            <span class="logo-icon">H</span>
            <span class="logo-text">HAMEEDIA</span>
        </a>
        <nav class="topnav">
            <a href="dashboard.php">Dashboard</a>
            <a href="reports.php">Reports</a>
            <?php if (isAdmin()): ?>
            <a href="analytics.php" class="active">Analytics</a>
            <a href="users.php">Users</a>
            <?php endif; ?>
        </nav>
        <div class="right">
            <span class="live-chip"><span class="live-dot"></span><span id="live-clock">--:--</span></span>
            <span class="date-display"><?php echo date('M d, Y'); ?></span>
            <span class="user-name"><?php echo htmlspecialchars($current_user); ?></span>
            <?php if (isAdmin()): ?>
            <span class="admin-badge">Admin</span>
            <?php endif; ?>
            <a href="logout.php" class="logout">Sign out</a>
        </div>
    </div>

    <div class="analytics-container">
        <div class="page-header">
            <div class="title">
                <h2>📊 Analytics Dashboard</h2>
                <p><?php echo $division_name; ?> - <?php echo $display_date; ?></p>
            </div>
            <div class="controls">
                <input type="date" id="analyticsDate" value="<?php echo $selected_date; ?>" onchange="updateAnalytics()">
                <a href="dashboard.php" class="btn btn-secondary">← Back</a>
            </div>
        </div>

        <!-- Division Selector -->
        <div class="division-selector">
            <?php foreach ([1, 2, 3, 7] as $div_id): 
                $is_active = ($selected_division == $div_id);
            ?>
            <button class="division-btn <?php echo $is_active ? 'active' : ''; ?>" 
                    onclick="selectDivision(<?php echo $div_id; ?>)">
                <span class="icon"><?php echo $division_icons[$div_id]; ?></span>
                <?php echo $division_names[$div_id]; ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Summary Badges -->
        <div class="summary-badges">
            <?php if ($has_match_out): ?>
            <span class="badge badge-match-out">✅ Match Out</span>
            <?php endif; ?>
            <?php if ($has_dhu): ?>
            <span class="badge badge-dhu">✅ DHU</span>
            <?php endif; ?>
            <?php if ($has_lean_total): ?>
            <span class="badge badge-lean">✅ Lean Total</span>
            <?php endif; ?>
            <?php if ($has_grand_total): ?>
            <span class="badge badge-grand">✅ Factory Grand Total</span>
            <?php endif; ?>
            <?php if (!$has_match_out && !$has_dhu && !$has_lean_total && !$has_grand_total): ?>
            <span class="badge badge-no-data">No summary data available</span>
            <?php endif; ?>
        </div>

        <!-- Stats Cards -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="number"><?php echo number_format($total_pcs); ?></div>
                <div class="label">Total Production (Pcs)</div>
            </div>
            <div class="stat-card">
                <div class="number eff"><?php echo $avg_eff; ?>%</div>
                <div class="label">Average Efficiency</div>
            </div>
            <div class="stat-card">
                <div class="number dhu"><?php echo $avg_dhu; ?>%</div>
                <div class="label">Average DHU</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $work_hours; ?></div>
                <div class="label">Working Hours</div>
            </div>
        </div>

        <!-- No Data Message -->
        <?php if (!$has_data): ?>
        <div class="no-data-message">
            ⚠️ No production data found for <?php echo $division_name; ?> on <?php echo $display_date; ?>.<br>
            Please go to the <a href="division_view.php?id=<?php echo $selected_division; ?>&date=<?php echo $selected_date; ?>" style="color:var(--primary);font-weight:700;">Production Page</a> to enter data.
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- DASHBOARD TABLE -->
        <!-- ============================================================ -->
        <table class="dashboard-table">
            <thead>
                <tr>
                    <th rowspan="2" style="min-width:100px;">DASH BOARD</th>
                    <?php foreach ($columns as $col): 
                        $col_class = '';
                        if ($col['type'] == 'match_out') $col_class = 'style="background:var(--match-bg);"';
                        elseif ($col['type'] == 'dhu') $col_class = 'style="background:var(--dhu-bg);color:var(--dhu-color);"';
                        elseif ($col['type'] == 'assembly') $col_class = 'style="background:var(--assembly-bg);"';
                    ?>
                    <th colspan="2" <?php echo $col_class; ?>><?php echo htmlspecialchars($col['name']); ?></th>
                    <?php endforeach; ?>
                    <th rowspan="2">DHU %</th>
                </tr>
                <tr>
                    <?php foreach ($columns as $col): ?>
                    <th>Pcs</th><th>Eff</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($columns)): ?>
                
                <!-- Budget Row -->
                <tr class="header-row">
                    <td style="text-align:left;">DIRECTS-BUDGET</td>
                    <?php foreach ($columns as $col): ?>
                    <td colspan="2"><?php echo $col['data']['unit_carder'] ?? 0; ?></td>
                    <?php endforeach; ?>
                    <td>—</td>
                </tr>
                
                <!-- Present Row -->
                <tr class="header-row">
                    <td style="text-align:left;">DIRECTS-PRESENT</td>
                    <?php foreach ($columns as $col): ?>
                    <td colspan="2"><?php echo $col['data']['unit_carder'] ?? 0; ?></td>
                    <?php endforeach; ?>
                    <td>—</td>
                </tr>
                
                <!-- Absenteeism Row -->
                <tr class="header-row">
                    <td style="text-align:left;">ABSENTEESM</td>
                    <?php foreach ($columns as $col): 
                        $budget = $col['data']['unit_carder'] ?? 0;
                        $present = $col['data']['unit_carder'] ?? 0;
                        $absenteeism = $budget > 0 ? round((($budget - $present) / $budget) * 100, 0) : 0;
                    ?>
                    <td colspan="2"><?php echo $absenteeism; ?>%</td>
                    <?php endforeach; ?>
                    <td>—</td>
                </tr>
                
                <!-- Hourly Production Rows -->
                <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                <tr>
                    <td style="font-weight:700;"><?php echo $h; ?></td>
                    <?php foreach ($columns as $col): 
                        $pcs = (float)($col['data']["hour_$h"] ?? 0);
                        $eff = (float)($col['data']['acvd_eff'] ?? 0) * 100;
                        $eff_class = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                    ?>
                    <td><?php echo number_format($pcs, 0); ?></td>
                    <td class="<?php echo $eff_class; ?>"><?php echo number_format($eff, 1); ?>%</td>
                    <?php endforeach; ?>
                    <td><?php echo number_format($hourly_data['dhu'][$h] ?? 0, 1); ?>%</td>
                </tr>
                <?php endfor; ?>
                
                <!-- Average Row -->
                <tr class="total-row">
                    <td style="font-weight:700;">Average</td>
                    <?php foreach ($columns as $col): 
                        $total_pcs_col = 0;
                        $total_eff_col = 0;
                        for ($h = 1; $h <= $work_hours; $h++) {
                            $total_pcs_col += (float)($col['data']["hour_$h"] ?? 0);
                            $total_eff_col += (float)($col['data']['acvd_eff'] ?? 0) * 100;
                        }
                        $avg_eff_col = $work_hours > 0 ? round($total_eff_col / $work_hours, 1) : 0;
                        $eff_class = $avg_eff_col >= 90 ? 'eff-good' : ($avg_eff_col >= 70 ? 'eff-avg' : 'eff-bad');
                    ?>
                    <td style="font-weight:700;"><?php echo number_format($total_pcs_col, 0); ?></td>
                    <td class="<?php echo $eff_class; ?>" style="font-weight:700;"><?php echo number_format($avg_eff_col, 1); ?>%</td>
                    <?php endforeach; ?>
                    <td style="font-weight:700;color:var(--dhu-color);"><?php echo number_format($avg_dhu, 1); ?>%</td>
                </tr>
                
                <!-- Loss/Profit Row -->
                <tr class="loss-row">
                    <td style="font-weight:700;">LOSS/PROFIT</td>
                    <?php foreach ($columns as $col): 
                        $total = 0;
                        for ($h = 1; $h <= $work_hours; $h++) {
                            $total += (float)($col['data']["hour_$h"] ?? 0);
                        }
                        $loss = round($total * 0.246, 0);
                    ?>
                    <td colspan="2">LKR <?php echo number_format($loss); ?></td>
                    <?php endforeach; ?>
                    <td>—</td>
                </tr>
                
                <!-- Lean Total Row (Assembly only) -->
                <?php if ($is_assembly && $has_lean_total): 
                    $lt = $summary_rows['lean_total'];
                ?>
                <tr class="lean-total">
                    <td style="text-align:left;font-weight:700;">Lean Total</td>
                    <td colspan="<?php echo count($columns) * 2; ?>" style="text-align:center;font-weight:700;">
                        SAM: <?php echo number_format($lt['ttl_sam_pc'] ?? 0, 4); ?> | 
                        Carder: <?php echo $lt['unit_carder'] ?? 0; ?> | 
                        Day Ttl: <?php echo number_format($lt['day_total'] ?? 0, 0); ?>
                    </td>
                    <td>—</td>
                </tr>
                <?php endif; ?>
                
                <!-- Factory Grand Total Row (Assembly only) -->
                <?php if ($is_assembly && $has_grand_total): 
                    $gt = $summary_rows['grand_total'];
                ?>
                <tr class="grand-total">
                    <td style="text-align:left;font-weight:700;">Factory Grand Total/Average</td>
                    <td colspan="<?php echo count($columns) * 2; ?>" style="text-align:center;font-weight:700;">
                        SAM: <?php echo number_format($gt['ttl_sam_pc'] ?? 0, 4); ?> | 
                        Carder: <?php echo $gt['unit_carder'] ?? 0; ?> | 
                        Day Ttl: <?php echo number_format($gt['day_total'] ?? 0, 0); ?>
                    </td>
                    <td>—</td>
                </tr>
                <?php endif; ?>
                
                <?php else: ?>
                <tr>
                    <td colspan="<?php echo 3 + (count($columns) * 2); ?>" style="text-align:center;padding:20px;color:var(--steel);">
                        No data available for this division on <?php echo $display_date; ?>.
                        <br>Please go to <a href="division_view.php?id=<?php echo $selected_division; ?>&date=<?php echo $selected_date; ?>" style="color:var(--primary);font-weight:700;">Production Page</a> to enter data.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- ============================================================ -->
        <!-- CHARTS SECTION -->
        <!-- ============================================================ -->
        <div class="chart-grid">
            <div class="chart-card">
                <h4>📈 PRODUCTION - HOURLY PROGRESS</h4>
                <div class="chart-wrapper">
                    <canvas id="chartProduction"></canvas>
                </div>
            </div>

            <div class="chart-card">
                <h4>📉 D.H.U - HOURLY PROGRESS</h4>
                <div class="chart-wrapper">
                    <canvas id="chartDHU"></canvas>
                </div>
            </div>

            <div class="chart-card chart-full">
                <h4>📊 MONTH PRODUCE PCS - TREND LINE</h4>
                <div class="chart-wrapper">
                    <canvas id="chartTrend"></canvas>
                </div>
            </div>

            <div class="chart-card chart-full">
                <h4>📉 MONTH D.H.U - TREND LINE</h4>
                <div class="chart-wrapper">
                    <canvas id="chartDHUTrend"></canvas>
                </div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- LOSS / PROFIT CARDS -->
        <!-- ============================================================ -->
        <div class="loss-profit-container">
            <div class="loss-profit-card">
                <div class="icon">📉</div>
                <?php 
                $loss_profit = round($total_pcs * 0.246, 0);
                ?>
                <div class="amount <?php echo $loss_profit > 0 ? 'profit' : ''; ?>">
                    LKR <?php echo number_format(abs($loss_profit)); ?>
                </div>
                <div class="label"><?php echo $loss_profit > 0 ? 'Profit' : 'Loss'; ?></div>
            </div>
            <div class="loss-profit-card">
                <div class="icon">📊</div>
                <div class="amount" style="color:var(--primary);"><?php echo number_format($total_pcs); ?></div>
                <div class="label">Total Production</div>
            </div>
            <div class="loss-profit-card">
                <div class="icon">🎯</div>
                <div class="amount" style="color:var(--amber);"><?php echo $avg_eff; ?>%</div>
                <div class="label">Avg Efficiency</div>
            </div>
            <div class="loss-profit-card">
                <div class="icon">⚠️</div>
                <div class="amount" style="color:var(--dhu-color);"><?php echo $avg_dhu; ?>%</div>
                <div class="label">Avg DHU</div>
            </div>
        </div>
    </div>

    <script>
        // ============================================================
        // CLOCK FUNCTION
        // ============================================================
        function updateClock() {
            const now = new Date();
            let hours = now.getHours();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            const minutes = String(now.getMinutes()).padStart(2, '0');
            document.getElementById('live-clock').textContent = hours + ':' + minutes + ' ' + ampm;
        }
        updateClock();
        setInterval(updateClock, 60000);

        // ============================================================
        // DIVISION SELECTOR
        // ============================================================
        function selectDivision(divisionId) {
            var date = document.getElementById('analyticsDate').value;
            var url = new URL(window.location.href);
            url.searchParams.set('division', divisionId);
            url.searchParams.set('date', date);
            window.location.href = url.toString();
        }

        function updateAnalytics() {
            var date = document.getElementById('analyticsDate').value;
            var url = new URL(window.location.href);
            url.searchParams.set('date', date);
            window.location.href = url.toString();
        }

        // ============================================================
        // CHART DATA
        // ============================================================
        const labels = <?php echo json_encode($chart_labels); ?>;
        const productionData = <?php echo json_encode($production_data); ?>;
        const effData = <?php echo json_encode($eff_data); ?>;
        const dhuData = <?php echo json_encode($dhu_chart_data); ?>;
        
        const trendLabels = <?php echo json_encode($trend_labels); ?>;
        const trendPcs = <?php echo json_encode($trend_pcs); ?>;
        const trendEff = <?php echo json_encode($trend_eff); ?>;
        const trendDHU = <?php echo json_encode($trend_dhu_data); ?>;

        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.font.size = 11;
        Chart.defaults.color = '#6b7a8f';

        // ============================================================
        // CHART 1: Production - Hourly Progress
        // ============================================================
        function createProductionChart() {
            const ctx = document.getElementById('chartProduction');
            if (!ctx) return null;

            return new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Pcs',
                            data: productionData,
                            backgroundColor: 'rgba(33, 115, 70, 0.7)',
                            borderColor: 'rgba(33, 115, 70, 1)',
                            borderWidth: 2,
                            borderRadius: 4,
                            order: 1
                        },
                        {
                            label: 'Eff %',
                            data: effData,
                            type: 'line',
                            borderColor: 'rgba(245, 124, 0, 1)',
                            backgroundColor: 'rgba(245, 124, 0, 0.1)',
                            borderWidth: 2,
                            pointBackgroundColor: 'rgba(245, 124, 0, 1)',
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            tension: 0.3,
                            fill: true,
                            yAxisID: 'y1',
                            order: 0
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            display: true, 
                            position: 'top',
                            labels: { usePointStyle: true, padding: 10 }
                        }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true,
                            position: 'left',
                            title: { display: true, text: 'Pcs' },
                            ticks: { callback: function(value) { return value; } }
                        },
                        y1: {
                            beginAtZero: true,
                            position: 'right',
                            title: { display: true, text: '%' },
                            grid: { drawOnChartArea: false },
                            ticks: { callback: function(value) { return value + '%'; } }
                        }
                    }
                }
            });
        }

        // ============================================================
        // CHART 2: DHU - Hourly Progress
        // ============================================================
        function createDHUChart() {
            const ctx = document.getElementById('chartDHU');
            if (!ctx) return null;

            return new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'DHU %',
                        data: dhuData,
                        backgroundColor: 'rgba(231, 76, 60, 0.7)',
                        borderColor: 'rgba(231, 76, 60, 1)',
                        borderWidth: 2,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            display: true, 
                            position: 'top',
                            labels: { usePointStyle: true, padding: 10 }
                        }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true,
                            title: { display: true, text: '%' },
                            ticks: { callback: function(value) { return value + '%'; } }
                        }
                    }
                }
            });
        }

        // ============================================================
        // CHART 3: Month Production Trend
        // ============================================================
        function createTrendChart() {
            const ctx = document.getElementById('chartTrend');
            if (!ctx) return null;

            return new Chart(ctx, {
                type: 'line',
                data: {
                    labels: trendLabels,
                    datasets: [
                        {
                            label: 'Production Pcs',
                            data: trendPcs,
                            borderColor: 'rgba(33, 115, 70, 1)',
                            backgroundColor: 'rgba(33, 115, 70, 0.15)',
                            fill: true,
                            tension: 0.3,
                            pointBackgroundColor: 'rgba(33, 115, 70, 1)',
                            pointRadius: 3,
                            pointHoverRadius: 5,
                            order: 1
                        },
                        {
                            label: 'Efficiency %',
                            data: trendEff,
                            borderColor: 'rgba(245, 124, 0, 1)',
                            backgroundColor: 'rgba(245, 124, 0, 0.1)',
                            borderDash: [5, 5],
                            fill: true,
                            tension: 0.3,
                            pointBackgroundColor: 'rgba(245, 124, 0, 1)',
                            pointRadius: 3,
                            pointHoverRadius: 5,
                            yAxisID: 'y1',
                            order: 0
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            display: true, 
                            position: 'top',
                            labels: { usePointStyle: true, padding: 10 }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed.y !== null) {
                                        if (context.dataset.label === 'Efficiency %') {
                                            label += context.parsed.y + '%';
                                        } else {
                                            label += context.parsed.y;
                                        }
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            ticks: { 
                                maxRotation: 45,
                                minRotation: 30,
                                font: { size: 9 }
                            }
                        },
                        y: { 
                            beginAtZero: true,
                            position: 'left',
                            title: { display: true, text: 'Pcs' },
                            ticks: { callback: function(value) { return value; } }
                        },
                        y1: {
                            beginAtZero: true,
                            position: 'right',
                            title: { display: true, text: '%' },
                            grid: { drawOnChartArea: false },
                            ticks: { callback: function(value) { return value + '%'; } }
                        }
                    }
                }
            });
        }

        // ============================================================
        // CHART 4: Month DHU Trend
        // ============================================================
        function createDHUTrendChart() {
            const ctx = document.getElementById('chartDHUTrend');
            if (!ctx) return null;

            return new Chart(ctx, {
                type: 'line',
                data: {
                    labels: trendLabels,
                    datasets: [{
                        label: 'DHU %',
                        data: trendDHU,
                        borderColor: 'rgba(231, 76, 60, 1)',
                        backgroundColor: 'rgba(231, 76, 60, 0.15)',
                        fill: true,
                        tension: 0.3,
                        pointBackgroundColor: 'rgba(231, 76, 60, 1)',
                        pointRadius: 3,
                        pointHoverRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            display: true, 
                            position: 'top',
                            labels: { usePointStyle: true, padding: 10 }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'DHU: ' + context.parsed.y + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            ticks: { 
                                maxRotation: 45,
                                minRotation: 30,
                                font: { size: 9 }
                            }
                        },
                        y: { 
                            beginAtZero: true,
                            title: { display: true, text: '%' },
                            ticks: { callback: function(value) { return value + '%'; } }
                        }
                    }
                }
            });
        }

        // ============================================================
        // CREATE ALL CHARTS
        // ============================================================
        let productionChart = createProductionChart();
        let dhuChart = createDHUChart();
        let trendChart = createTrendChart();
        let dhuTrendChart = createDHUTrendChart();

        // ============================================================
        // HANDLE WINDOW RESIZE
        // ============================================================
        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                if (productionChart) productionChart.resize();
                if (dhuChart) dhuChart.resize();
                if (trendChart) trendChart.resize();
                if (dhuTrendChart) dhuTrendChart.resize();
            }, 250);
        });
    </script>
</body>
</html>