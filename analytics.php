<?php
// analytics.php - Complete Analytics Dashboard with Real Data (2-Table Structure)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$conn = getDB();
$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selected_division = isset($_GET['division']) ? (int)$_GET['division'] : 1;
$trend_days = isset($_GET['days']) ? (int)$_GET['days'] : 30;

$valid_divisions = [1, 2, 3, 7];
if (!in_array($selected_division, $valid_divisions)) {
    $selected_division = 1;
}

$work_hours = 11;

$division_names = [1 => 'Shirt', 2 => 'Trouser', 3 => 'Coat', 7 => 'Assembly'];
$division_icons = [1 => '👔', 2 => '👖', 3 => '🧥', 7 => '🏭'];

// ============================================================
// HELPER FUNCTIONS
// ============================================================

function getSingleComponentData($conn, $division_id, $date, $work_hours, $component_name) {
    $components = getComponents($conn, $division_id);
    foreach ($components as $comp) {
        if ($comp['is_match_out']) continue;
        if (strtoupper(trim($comp['name'])) === strtoupper(trim($component_name))) {
            $data = getReportData($conn, $division_id, $comp['id'], $date);
            $data['name'] = $comp['name'];
            $data['component_id'] = $comp['id'];
            
            for ($h = 1; $h <= 11; $h++) {
                $data["hour_$h"] = (float)($data["hour_$h"] ?? 0);
            }
            $data['unit_carder'] = (int)($data['unit_carder'] ?? 0);
            $data['acvd_eff'] = (float)($data['acvd_eff'] ?? 0);
            $data['day_total'] = (float)($data['day_total'] ?? 0);
            $data['profit'] = (float)($data['profit'] ?? 0);
            return $data;
        }
    }
    return null;
}

function getMatchOutData($conn, $division_id, $date, $work_hours) {
    $match_out = getReportData($conn, $division_id, 999, $date);
    for ($h = 1; $h <= 11; $h++) {
        $match_out["hour_$h"] = (float)($match_out["hour_$h"] ?? 0);
    }
    $match_out['unit_carder'] = (int)($match_out['unit_carder'] ?? 0);
    $match_out['acvd_eff'] = (float)($match_out['acvd_eff'] ?? 0);
    $match_out['day_total'] = (float)($match_out['day_total'] ?? 0);
    return $match_out;
}

function computeDHU($data) {
    $day_total = (float)($data['day_total'] ?? 0);
    return $day_total > 0 ? round(($day_total / 100) * 5, 1) : 0;
}

// ============================================================
// BUILD TABLES DATA
// ============================================================

$table1_columns = [];   // Main table columns
$table2_columns = [];   // MTM / secondary table columns

if ($selected_division == 1) {
    // SHIRT MAIN: Front | Back | Collar | Sleeve | Cuff | Match Out | Assembly SHIRT
    $shirt_order = ['Front', 'Back', 'Collar', 'Sleeve', 'Cuff'];
    foreach ($shirt_order as $name) {
        $comp_data = getSingleComponentData($conn, 1, $selected_date, $work_hours, $name);
        if ($comp_data) {
            $table1_columns[] = [
                'name' => $name,
                'pcs' => $comp_data['day_total'],
                'eff' => $comp_data['acvd_eff'] * 100,
                'carder' => $comp_data['unit_carder'],
                'dhu' => computeDHU($comp_data),
                'hours' => array_map(function($h) use ($comp_data) { return $comp_data["hour_$h"] ?? 0; }, range(1, $work_hours)),
                'profit' => $comp_data['profit'],
                'type' => 'component'
            ];
        } else {
            $table1_columns[] = [
                'name' => $name, 'pcs' => 0, 'eff' => 0, 'carder' => 0, 'dhu' => 0,
                'hours' => array_fill(0, $work_hours, 0), 'profit' => 0, 'type' => 'component'
            ];
        }
    }
    
    // Match Out
    $mo_data = getMatchOutData($conn, 1, $selected_date, $work_hours);
    $mo_hourly = [];
    for ($h = 1; $h <= $work_hours; $h++) {
        $mo_hourly[] = $mo_data["hour_$h"] ?? 0;
    }
    $table1_columns[] = [
        'name' => 'Match Out',
        'pcs' => $mo_data['day_total'] ?? 0,
        'eff' => ($mo_data['acvd_eff'] ?? 0) * 100,
        'carder' => $mo_data['unit_carder'] ?? 0,
        'dhu' => computeDHU($mo_data),
        'hours' => $mo_hourly,
        'profit' => $mo_data['profit'] ?? 0,
        'type' => 'match_out'
    ];
    
    // Assembly SHIRT
    $asm_shirt = getSingleComponentData($conn, 7, $selected_date, $work_hours, 'SHIRT');
    if ($asm_shirt) {
        $asm_shirt_hours = [];
        for ($h = 1; $h <= $work_hours; $h++) {
            $asm_shirt_hours[] = $asm_shirt["hour_$h"] ?? 0;
        }
        $table1_columns[] = [
            'name' => 'Assembly SHIRT',
            'pcs' => $asm_shirt['day_total'],
            'eff' => $asm_shirt['acvd_eff'] * 100,
            'carder' => $asm_shirt['unit_carder'],
            'dhu' => computeDHU($asm_shirt),
            'hours' => $asm_shirt_hours,
            'profit' => $asm_shirt['profit'],
            'type' => 'assembly'
        ];
    } else {
        $table1_columns[] = [
            'name' => 'Assembly SHIRT', 'pcs' => 0, 'eff' => 0, 'carder' => 0, 'dhu' => 0,
            'hours' => array_fill(0, $work_hours, 0), 'profit' => 0, 'type' => 'assembly'
        ];
    }
    
    // SHIRT MTM (Table 2)
    $shirt_mtm = getSingleComponentData($conn, 7, $selected_date, $work_hours, 'SHIRT MTM');
    if ($shirt_mtm) {
        $shirt_mtm_hours = [];
        for ($h = 1; $h <= $work_hours; $h++) {
            $shirt_mtm_hours[] = $shirt_mtm["hour_$h"] ?? 0;
        }
        $table2_columns[] = [
            'name' => 'SHIRT MTM',
            'pcs' => $shirt_mtm['day_total'],
            'eff' => $shirt_mtm['acvd_eff'] * 100,
            'carder' => $shirt_mtm['unit_carder'],
            'dhu' => computeDHU($shirt_mtm),
            'hours' => $shirt_mtm_hours,
            'profit' => $shirt_mtm['profit'],
            'type' => 'assembly'
        ];
    } else {
        $table2_columns[] = [
            'name' => 'SHIRT MTM', 'pcs' => 0, 'eff' => 0, 'carder' => 0, 'dhu' => 0,
            'hours' => array_fill(0, $work_hours, 0), 'profit' => 0, 'type' => 'assembly'
        ];
    }
    
} elseif ($selected_division == 2) {
    // TROUSER MAIN: Front | Back | Band | Match Out | Assembly TROUSER
    $trouser_order = ['Front', 'Back', 'Band'];
    foreach ($trouser_order as $name) {
        $comp_data = getSingleComponentData($conn, 2, $selected_date, $work_hours, $name);
        if ($comp_data) {
            $table1_columns[] = [
                'name' => $name,
                'pcs' => $comp_data['day_total'],
                'eff' => $comp_data['acvd_eff'] * 100,
                'carder' => $comp_data['unit_carder'],
                'dhu' => computeDHU($comp_data),
                'hours' => array_map(function($h) use ($comp_data) { return $comp_data["hour_$h"] ?? 0; }, range(1, $work_hours)),
                'profit' => $comp_data['profit'],
                'type' => 'component'
            ];
        } else {
            $table1_columns[] = [
                'name' => $name, 'pcs' => 0, 'eff' => 0, 'carder' => 0, 'dhu' => 0,
                'hours' => array_fill(0, $work_hours, 0), 'profit' => 0, 'type' => 'component'
            ];
        }
    }
    
    // Match Out
    $mo_data = getMatchOutData($conn, 2, $selected_date, $work_hours);
    $mo_hourly = [];
    for ($h = 1; $h <= $work_hours; $h++) {
        $mo_hourly[] = $mo_data["hour_$h"] ?? 0;
    }
    $table1_columns[] = [
        'name' => 'Match Out',
        'pcs' => $mo_data['day_total'] ?? 0,
        'eff' => ($mo_data['acvd_eff'] ?? 0) * 100,
        'carder' => $mo_data['unit_carder'] ?? 0,
        'dhu' => computeDHU($mo_data),
        'hours' => $mo_hourly,
        'profit' => $mo_data['profit'] ?? 0,
        'type' => 'match_out'
    ];
    
    // Assembly TROUSER
    $asm_trouser = getSingleComponentData($conn, 7, $selected_date, $work_hours, 'TROUSER');
    if ($asm_trouser) {
        $asm_trouser_hours = [];
        for ($h = 1; $h <= $work_hours; $h++) {
            $asm_trouser_hours[] = $asm_trouser["hour_$h"] ?? 0;
        }
        $table1_columns[] = [
            'name' => 'Assembly TROUSER',
            'pcs' => $asm_trouser['day_total'],
            'eff' => $asm_trouser['acvd_eff'] * 100,
            'carder' => $asm_trouser['unit_carder'],
            'dhu' => computeDHU($asm_trouser),
            'hours' => $asm_trouser_hours,
            'profit' => $asm_trouser['profit'],
            'type' => 'assembly'
        ];
    } else {
        $table1_columns[] = [
            'name' => 'Assembly TROUSER', 'pcs' => 0, 'eff' => 0, 'carder' => 0, 'dhu' => 0,
            'hours' => array_fill(0, $work_hours, 0), 'profit' => 0, 'type' => 'assembly'
        ];
    }
    
    // TROUSER MTM (Table 2)
    $trouser_mtm = getSingleComponentData($conn, 7, $selected_date, $work_hours, 'TROUSER MTM');
    if ($trouser_mtm) {
        $trouser_mtm_hours = [];
        for ($h = 1; $h <= $work_hours; $h++) {
            $trouser_mtm_hours[] = $trouser_mtm["hour_$h"] ?? 0;
        }
        $table2_columns[] = [
            'name' => 'TROUSER MTM',
            'pcs' => $trouser_mtm['day_total'],
            'eff' => $trouser_mtm['acvd_eff'] * 100,
            'carder' => $trouser_mtm['unit_carder'],
            'dhu' => computeDHU($trouser_mtm),
            'hours' => $trouser_mtm_hours,
            'profit' => $trouser_mtm['profit'],
            'type' => 'assembly'
        ];
    } else {
        $table2_columns[] = [
            'name' => 'TROUSER MTM', 'pcs' => 0, 'eff' => 0, 'carder' => 0, 'dhu' => 0,
            'hours' => array_fill(0, $work_hours, 0), 'profit' => 0, 'type' => 'assembly'
        ];
    }
    
} elseif ($selected_division == 3) {
    // COAT MAIN (from Assembly): COAT
    $coat_data = getSingleComponentData($conn, 7, $selected_date, $work_hours, 'COAT');
    if ($coat_data) {
        $coat_hours = [];
        for ($h = 1; $h <= $work_hours; $h++) {
            $coat_hours[] = $coat_data["hour_$h"] ?? 0;
        }
        $table1_columns[] = [
            'name' => 'COAT',
            'pcs' => $coat_data['day_total'],
            'eff' => $coat_data['acvd_eff'] * 100,
            'carder' => $coat_data['unit_carder'],
            'dhu' => computeDHU($coat_data),
            'hours' => $coat_hours,
            'profit' => $coat_data['profit'],
            'type' => 'assembly'
        ];
    } else {
        $table1_columns[] = [
            'name' => 'COAT', 'pcs' => 0, 'eff' => 0, 'carder' => 0, 'dhu' => 0,
            'hours' => array_fill(0, $work_hours, 0), 'profit' => 0, 'type' => 'assembly'
        ];
    }
    
    // COAT MTM (Table 2)
    $coat_mtm = getSingleComponentData($conn, 7, $selected_date, $work_hours, 'COAT MTM');
    if ($coat_mtm) {
        $coat_mtm_hours = [];
        for ($h = 1; $h <= $work_hours; $h++) {
            $coat_mtm_hours[] = $coat_mtm["hour_$h"] ?? 0;
        }
        $table2_columns[] = [
            'name' => 'COAT MTM',
            'pcs' => $coat_mtm['day_total'],
            'eff' => $coat_mtm['acvd_eff'] * 100,
            'carder' => $coat_mtm['unit_carder'],
            'dhu' => computeDHU($coat_mtm),
            'hours' => $coat_mtm_hours,
            'profit' => $coat_mtm['profit'],
            'type' => 'assembly'
        ];
    } else {
        $table2_columns[] = [
            'name' => 'COAT MTM', 'pcs' => 0, 'eff' => 0, 'carder' => 0, 'dhu' => 0,
            'hours' => array_fill(0, $work_hours, 0), 'profit' => 0, 'type' => 'assembly'
        ];
    }
    
} elseif ($selected_division == 7) {
    // ASSEMBLY: SHIRT | SHIRT MTM | TROUSER | TROUSER MTM | COAT | COAT MTM | KNIT
    $assembly_order = ['SHIRT', 'SHIRT MTM', 'TROUSER', 'TROUSER MTM', 'COAT', 'COAT MTM', 'KNIT'];
    foreach ($assembly_order as $name) {
        $comp_data = getSingleComponentData($conn, 7, $selected_date, $work_hours, $name);
        if ($comp_data) {
            $hours_arr = [];
            for ($h = 1; $h <= $work_hours; $h++) {
                $hours_arr[] = $comp_data["hour_$h"] ?? 0;
            }
            $table1_columns[] = [
                'name' => $name,
                'pcs' => $comp_data['day_total'],
                'eff' => $comp_data['acvd_eff'] * 100,
                'carder' => $comp_data['unit_carder'],
                'dhu' => computeDHU($comp_data),
                'hours' => $hours_arr,
                'profit' => $comp_data['profit'],
                'type' => 'component'
            ];
        } else {
            $table1_columns[] = [
                'name' => $name, 'pcs' => 0, 'eff' => 0, 'carder' => 0, 'dhu' => 0,
                'hours' => array_fill(0, $work_hours, 0), 'profit' => 0, 'type' => 'component'
            ];
        }
    }
}

// ============================================================
// CALCULATE DIVISION AGGREGATES FOR CHARTS
// ============================================================
$division_name = $division_names[$selected_division] ?? 'Division';
$is_assembly = ($selected_division == 7);

// Combine all table columns for charts
$all_columns = array_merge($table1_columns, $table2_columns);

// Hourly production (sum all hours)
$hourly_production = array_fill(1, $work_hours, 0);
$hourly_dhu = array_fill(1, $work_hours, 0);
$hourly_eff_sum = array_fill(1, $work_hours, 0);
$col_count = 0;
$total_pcs = 0;
$total_ern = 0;

foreach ($all_columns as $col) {
    if ($col['pcs'] > 0 || $col['carder'] > 0) {
        $col_count++;
        $total_pcs += $col['pcs'];
        
        for ($h = 0; $h < $work_hours; $h++) {
            $hourly_production[$h + 1] += $col['hours'][$h] ?? 0;
        }
    }
}

// Simple averages per hour
if ($col_count > 0) {
    for ($h = 1; $h <= $work_hours; $h++) {
        $hourly_production[$h] = round($hourly_production[$h] / $col_count, 0);
    }
}

// DHU per hour: average of component DHU weighted by their pcs
$total_pcs_for_dhu = 0;
foreach ($all_columns as $col) {
    $col_pcs = $col['pcs'] ?? 0;
    if ($col_pcs > 0) {
        $total_pcs_for_dhu += $col_pcs;
        for ($h = 0; $h < $work_hours; $h++) {
            $hourly_dhu[$h + 1] += (($col['hours'][$h] ?? 0) / 100) * 5;
        }
    }
}

if ($total_pcs_for_dhu > 0) {
    for ($h = 1; $h <= $work_hours; $h++) {
        $hourly_dhu[$h] = round(($hourly_dhu[$h] / $total_pcs_for_dhu) * 100, 1);
    }
}

// Efficiency per hour: average of component efficiencies
$eff_count = 0;
foreach ($all_columns as $col) {
    if (($col['eff'] ?? 0) > 0) {
        $eff_count++;
        for ($h = 1; $h <= $work_hours; $h++) {
            $hourly_eff_sum[$h] += $col['eff'];
        }
    }
}
if ($eff_count > 0) {
    for ($h = 1; $h <= $work_hours; $h++) {
        $hourly_eff_sum[$h] = round($hourly_eff_sum[$h] / $eff_count, 1);
    }
}

// Overall stats
$total_pcs_display = 0;
$avg_eff_display = 0;
$avg_dhu_display = 0;
$eff_counter = 0;
$dhu_counter = 0;
$dhu_sum = 0;

foreach ($all_columns as $col) {
    if (($col['pcs'] ?? 0) > 0 || ($col['eff'] ?? 0) > 0) {
        $total_pcs_display += $col['pcs'];
        if ($col['eff'] > 0) {
            $avg_eff_display += $col['eff'];
            $eff_counter++;
        }
        if ($col['dhu'] > 0) {
            $dhu_sum += $col['dhu'];
            $dhu_counter++;
        }
    }
}

$avg_eff_display = $eff_counter > 0 ? round($avg_eff_display / $eff_counter, 1) : 0;
$avg_dhu_display = $dhu_counter > 0 ? round($dhu_sum / $dhu_counter, 1) : 0;

// ============================================================
// TREND DATA
// ============================================================
$trend_data = ['production' => [], 'dhu' => []];
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
    $stmt->execute([$selected_division, $trend_days]);
    $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $dates = array_reverse($dates);
    
    foreach ($dates as $d) {
        $stmt2 = $conn->prepare("SELECT SUM(day_total) as total_pcs, AVG(acvd_eff) as avg_eff FROM production_reports WHERE devition_id = ? AND report_date = ? AND unit_id NOT IN (996, 997, 998, 999)");
        $stmt2->execute([$selected_division, $d]);
        $row = $stmt2->fetch(PDO::FETCH_ASSOC);
        
        $trend_data['production'][] = [
            'date' => $d,
            'pcs' => (float)($row['total_pcs'] ?? 0),
            'eff' => (float)($row['avg_eff'] ?? 0) * 100
        ];
        $trend_data['dhu'][] = ['date' => $d, 'dhu' => 0];
    }
} catch (Exception $e) {
    error_log("Trend error: " . $e->getMessage());
}

if (empty($trend_data['production'])) {
    $trend_data['production'] = [['date' => date('Y-m-d'), 'pcs' => 0, 'eff' => 0]];
    $trend_data['dhu'] = [['date' => date('Y-m-d'), 'dhu' => 0]];
}

$display_date = date('M d, Y', strtotime($selected_date));
$has_data = ($total_pcs_display > 0 || $col_count > 0);

// Prepare chart data
$chart_labels = range(1, $work_hours);
$production_data = array_values($hourly_production);
$eff_data = array_values($hourly_eff_sum);
$dhu_chart_data = array_values($hourly_dhu);
$trend_labels = array_map(function($i) { return date('M d', strtotime($i['date'])); }, $trend_data['production']);
$trend_pcs = array_map(function($i) { return $i['pcs']; }, $trend_data['production']);
$trend_eff = array_map(function($i) { return $i['eff']; }, $trend_data['production']);
$trend_dhu_data = array_map(function($i) { return $i['dhu']; }, $trend_data['dhu']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard - Hameedia</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root { --primary: #217346; --primary-dark: #1a5c3a; --text: #1a2332; --text-dark: #0d1a2b; --steel: #6b7a8f; --border-radius: 12px; --shadow: 0 4px 20px rgba(0,0,0,0.08); --glass-border: rgba(255,255,255,0.3); --glass-bg: rgba(255,255,255,0.15); --good: #28a745; --bad: #dc3545; --warning: #ffc107; --amber: #f57c00; --dhu-color: #e74c3c; --dhu-bg: rgba(220,53,69,0.12); --match-bg: rgba(108,117,125,0.1); --assembly-bg: rgba(23,162,184,0.08); }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 50%, #a5d6a7 100%); min-height: 100vh; color: var(--text); position: relative; }
        .bg-shapes { position: fixed; top: 0; left: 0; width: 100%; height: 100%; overflow: hidden; z-index: 0; pointer-events: none; }
        .shape { position: absolute; border-radius: 50%; opacity: 0.08; animation: float 25s infinite ease-in-out; }
        .shape-1 { width: 500px; height: 500px; background: var(--primary); top: -150px; right: -150px; }
        .shape-2 { width: 300px; height: 300px; background: var(--primary); bottom: -100px; left: -100px; animation-delay: -8s; }
        .shape-3 { width: 200px; height: 200px; background: var(--primary); top: 50%; left: 50%; transform: translate(-50%, -50%); animation-delay: -15s; }
        @keyframes float { 0%, 100% { transform: translate(0, 0) scale(1); } 25% { transform: translate(60px, -60px) scale(1.1); } 50% { transform: translate(-40px, 40px) scale(0.9); } 75% { transform: translate(30px, 30px) scale(1.05); } }
        
        .topbar { position: relative; z-index: 10; background: var(--glass-bg); backdrop-filter: blur(20px); border-bottom: 1px solid var(--glass-border); padding: 10px 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .topbar .logo-mark { display: flex; align-items: center; gap: 12px; font-weight: 800; font-size: 20px; color: var(--primary-dark); text-decoration: none; }
        .topbar .logo-mark .logo-icon { font-size: 32px; background: var(--primary); color: #fff; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 10px; font-weight: 700; font-size: 18px; }
        .topnav { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .topnav a { color: var(--steel); text-decoration: none; font-size: 14px; font-weight: 600; padding: 7px 16px; border-radius: 10px; transition: all 0.3s; }
        .topnav a:hover { color: var(--primary); background: rgba(33,115,70,0.08); }
        .topnav a.active { color: #fff; background: var(--primary); box-shadow: 0 4px 15px rgba(33,115,70,0.3); }
        .right { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; font-size: 13px; color: var(--steel); }
        .live-chip { display: flex; align-items: center; gap: 6px; background: rgba(33,115,70,0.1); padding: 4px 12px; border-radius: 20px; font-size: 12px; color: var(--primary); font-weight: 600; }
        .live-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--good); animation: blink 1.5s infinite; }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
        .logout { color: var(--steel); text-decoration: none; padding: 5px 14px; border-radius: 8px; transition: all 0.3s; background: rgba(255,255,255,0.5); font-weight: 600; font-size: 13px; }
        .logout:hover { background: rgba(220,53,69,0.1); color: var(--bad); }
        .user-name { color: var(--text-dark); font-weight: 600; font-size: 13px; }
        .admin-badge { font-size: 9px; background: var(--primary); color: #fff; padding: 2px 8px; border-radius: 10px; font-weight: 600; }

        .analytics-container { position: relative; z-index: 5; max-width: 1600px; margin: 0 auto; padding: 20px 30px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        .page-header .title h2 { font-size: 24px; font-weight: 800; color: var(--text-dark); }
        .page-header .title p { color: var(--steel); font-size: 14px; font-weight: 500; margin-top: 4px; }
        .controls { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .controls input[type="date"] { padding: 7px 12px; border: 1px solid var(--glass-border); border-radius: 8px; font-size: 13px; font-weight: 500; font-family: 'Inter', sans-serif; background: rgba(255,255,255,0.7); color: var(--text-dark); }
        .btn { padding: 7px 16px; border: none; border-radius: 8px; font-weight: 600; font-size: 13px; cursor: pointer; transition: all 0.3s; font-family: 'Inter', sans-serif; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-secondary { background: rgba(255,255,255,0.5); color: var(--text-dark); border: 1px solid var(--glass-border); }

        .division-selector { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; padding: 16px 20px; background: var(--glass-bg); backdrop-filter: blur(20px); border: 1px solid var(--glass-border); border-radius: var(--border-radius); box-shadow: var(--shadow); }
        .division-btn { padding: 10px 28px; border: 2px solid var(--glass-border); border-radius: 10px; background: rgba(255,255,255,0.3); color: var(--text-dark); font-weight: 700; font-size: 15px; cursor: pointer; transition: all 0.3s ease; font-family: 'Inter', sans-serif; display: flex; align-items: center; gap: 8px; }
        .division-btn:hover { background: rgba(255,255,255,0.6); transform: translateY(-2px); }
        .division-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); box-shadow: 0 4px 15px rgba(33,115,70,0.3); }
        .division-btn .icon { font-size: 20px; }

        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
        .stat-card { background: var(--glass-bg); backdrop-filter: blur(20px); border: 1px solid var(--glass-border); border-radius: var(--border-radius); padding: 16px 20px; box-shadow: var(--shadow); text-align: center; transition: all 0.3s ease; }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,0.1); }
        .stat-card .number { font-size: 28px; font-weight: 900; color: var(--primary); }
        .stat-card .number.dhu { color: var(--dhu-color); }
        .stat-card .number.eff { color: var(--amber); }
        .stat-card .label { font-size: 13px; font-weight: 600; color: var(--steel); margin-top: 4px; }

        .no-data-message { text-align: center; padding: 20px; background: rgba(255,255,255,0.1); border-radius: 8px; color: var(--steel); font-weight: 500; margin-bottom: 20px; }

        .table-section-title { font-size: 16px; font-weight: 800; color: var(--text-dark); margin: 20px 0 10px; padding: 8px 16px; background: rgba(255,255,255,0.2); border-radius: 8px; display: inline-block; }
        
        .dashboard-table { width: 100%; border-collapse: collapse; font-size: 12px; background: var(--glass-bg); backdrop-filter: blur(20px); border: 1px solid var(--glass-border); border-radius: var(--border-radius); overflow: hidden; box-shadow: var(--shadow); margin-bottom: 20px; }
        .dashboard-table th { background: rgba(255,255,255,0.3); padding: 8px 10px; text-align: center; font-weight: 700; color: var(--text-dark); border: 1px solid var(--glass-border); font-size: 11px; }
        .dashboard-table td { padding: 6px 10px; text-align: center; border: 1px solid var(--glass-border); font-weight: 500; font-size: 11px; }
        .dashboard-table .header-row td { background: rgba(33,115,70,0.15); font-weight: 700; font-size: 12px; }
        .dashboard-table .dhu-row td { background: var(--dhu-bg); color: var(--dhu-color); font-weight: 600; }
        .dashboard-table .total-row td { background: rgba(33,150,243,0.08); font-weight: 700; }
        .dashboard-table .loss-row td { background: rgba(220,53,69,0.08); font-weight: 700; }
        .dashboard-table .match-col { background: var(--match-bg) !important; }
        .dashboard-table .assembly-col { background: var(--assembly-bg) !important; }
        .dashboard-table .dhu-col { background: var(--dhu-bg) !important; color: var(--dhu-color); }
        .eff-good { color: var(--good); font-weight: 700; }
        .eff-bad { color: var(--bad); font-weight: 700; }
        .eff-avg { color: var(--warning); font-weight: 700; }

        .chart-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .chart-card { background: var(--glass-bg); backdrop-filter: blur(20px); border: 1px solid var(--glass-border); border-radius: var(--border-radius); padding: 16px; box-shadow: var(--shadow); transition: all 0.3s ease; }
        .chart-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,0.1); }
        .chart-card h4 { font-size: 14px; font-weight: 700; color: var(--text-dark); margin-bottom: 10px; text-align: center; padding-bottom: 6px; border-bottom: 2px solid var(--primary); }
        .chart-card .chart-wrapper { position: relative; height: 220px; }
        .chart-card .chart-wrapper canvas { width: 100% !important; height: 100% !important; }
        .chart-full { grid-column: 1 / -1; }
        .chart-full .chart-wrapper { height: 250px; }

        .loss-profit-container { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 16px; margin-bottom: 20px; }
        .loss-profit-card { background: var(--glass-bg); backdrop-filter: blur(20px); border: 1px solid var(--glass-border); border-radius: var(--border-radius); padding: 16px; box-shadow: var(--shadow); text-align: center; transition: all 0.3s ease; }
        .loss-profit-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,0.1); }
        .loss-profit-card .amount { font-size: 24px; font-weight: 900; color: var(--bad); }
        .loss-profit-card .amount.profit { color: var(--good); }
        .loss-profit-card .label { font-size: 12px; font-weight: 600; color: var(--steel); margin-top: 4px; }
        .loss-profit-card .icon { font-size: 28px; margin-bottom: 4px; }

        @media (max-width: 1200px) { .stats-row { grid-template-columns: repeat(2, 1fr); } .loss-profit-container { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 1024px) { .chart-grid { grid-template-columns: 1fr; } .chart-full { grid-column: 1; } }
        @media (max-width: 768px) {
            .topbar { padding: 10px 16px; flex-direction: column; align-items: stretch; gap: 8px; }
            .topnav, .right { justify-content: center; }
            .analytics-container { padding: 12px 16px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .controls { width: 100%; flex-wrap: wrap; }
            .division-selector { flex-wrap: wrap; justify-content: center; }
            .division-btn { padding: 8px 16px; font-size: 13px; flex: 1; min-width: 80px; justify-content: center; }
            .stats-row { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card .number { font-size: 22px; }
            .chart-grid { grid-template-columns: 1fr; }
            .loss-profit-container { grid-template-columns: 1fr 1fr; gap: 10px; }
            .dashboard-table { font-size: 10px; }
            .dashboard-table th, .dashboard-table td { padding: 4px 6px; }
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

        <div class="division-selector">
            <?php foreach ([1, 2, 3, 7] as $div_id): $is_active = ($selected_division == $div_id); ?>
            <button class="division-btn <?php echo $is_active ? 'active' : ''; ?>" onclick="selectDivision(<?php echo $div_id; ?>)">
                <span class="icon"><?php echo $division_icons[$div_id]; ?></span>
                <?php echo $division_names[$div_id]; ?>
            </button>
            <?php endforeach; ?>
        </div>

        <div class="stats-row">
            <div class="stat-card"><div class="number"><?php echo number_format($total_pcs_display); ?></div><div class="label">Total Production (Pcs)</div></div>
            <div class="stat-card"><div class="number eff"><?php echo $avg_eff_display; ?>%</div><div class="label">Average Efficiency</div></div>
            <div class="stat-card"><div class="number dhu"><?php echo $avg_dhu_display; ?>%</div><div class="label">Average DHU</div></div>
            <div class="stat-card"><div class="number"><?php echo $work_hours; ?></div><div class="label">Working Hours</div></div>
        </div>

        <?php if (!$has_data): ?>
        <div class="no-data-message">
            ⚠️ No production data found for <?php echo $division_name; ?> on <?php echo $display_date; ?>.<br>
            Please go to the <a href="division_view.php?id=<?php echo $selected_division; ?>&date=<?php echo $selected_date; ?>" style="color:var(--primary);font-weight:700;">Production Page</a> to enter data.
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- TABLE 1: MAIN -->
        <!-- ============================================================ -->
        <?php if (!empty($table1_columns)): ?>
        <?php if (count($table2_columns) > 0): ?>
        <div class="table-section-title">📋 <?php echo strtoupper($division_name); ?> - MAIN</div>
        <?php endif; ?>
        
        <table class="dashboard-table">
            <thead>
                <tr>
                    <th rowspan="2" style="min-width:100px;">DASH BOARD</th>
                    <?php foreach ($table1_columns as $col): 
                        $cls = '';
                        if ($col['type'] == 'match_out') $cls = 'match-col';
                        elseif ($col['type'] == 'assembly') $cls = 'assembly-col';
                    ?>
                    <th colspan="2" class="<?php echo $cls; ?>"><?php echo htmlspecialchars($col['name']); ?></th>
                    <?php endforeach; ?>
                    <th rowspan="2" class="dhu-col">DHU %</th>
                </tr>
                <tr>
                    <?php foreach ($table1_columns as $col): ?>
                    <th>Pcs</th><th>Eff</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <!-- DIRECTS-BUDGET -->
                <tr class="header-row">
                    <td style="text-align:left;">DIRECTS-BUDGET</td>
                    <?php foreach ($table1_columns as $col): ?>
                    <td colspan="2"><?php echo $col['carder']; ?></td>
                    <?php endforeach; ?>
                    <td>—</td>
                </tr>
                <!-- DIRECTS-PRESENT -->
                <tr class="header-row">
                    <td style="text-align:left;">DIRECTS-PRESENT</td>
                    <?php foreach ($table1_columns as $col): ?>
                    <td colspan="2"><?php echo $col['carder']; ?></td>
                    <?php endforeach; ?>
                    <td>—</td>
                </tr>
                <!-- ABSENTEEISM -->
                <tr class="header-row">
                    <td style="text-align:left;">ABSENTEESM</td>
                    <?php foreach ($table1_columns as $col): ?>
                    <td colspan="2">0%</td>
                    <?php endforeach; ?>
                    <td>—</td>
                </tr>
                <!-- HOURS -->
                <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                <tr>
                    <td style="font-weight:700;"><?php echo $h; ?></td>
                    <?php foreach ($table1_columns as $col): 
                        $pcs = $col['hours'][$h - 1] ?? 0;
                        $eff = $col['eff'];
                        $eff_class = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                    ?>
                    <td><?php echo number_format($pcs, 0); ?></td>
                    <td class="<?php echo $eff_class; ?>"><?php echo number_format($eff, 1); ?>%</td>
                    <?php endforeach; ?>
                    <td><?php echo number_format($hourly_dhu[$h] ?? 0, 1); ?>%</td>
                </tr>
                <?php endfor; ?>
                <!-- AVERAGE -->
                <tr class="total-row">
                    <td style="font-weight:700;">Average</td>
                    <?php foreach ($table1_columns as $col): 
                        $eff = $col['eff'];
                        $eff_class = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                    ?>
                    <td style="font-weight:700;"><?php echo number_format($col['pcs'], 0); ?></td>
                    <td class="<?php echo $eff_class; ?>" style="font-weight:700;"><?php echo number_format($eff, 1); ?>%</td>
                    <?php endforeach; ?>
                    <td style="font-weight:700;color:var(--dhu-color);"><?php echo number_format($avg_dhu_display, 1); ?>%</td>
                </tr>
                <!-- LOSS/PROFIT -->
                <tr class="loss-row">
                    <td style="font-weight:700;">LOSS/PROFIT</td>
                    <?php foreach ($table1_columns as $col): 
                        $loss = round(($col['pcs'] ?? 0) * 0.246, 0);
                    ?>
                    <td colspan="2">LKR <?php echo number_format($loss); ?></td>
                    <?php endforeach; ?>
                    <td>—</td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- TABLE 2: MTM -->
        <!-- ============================================================ -->
        <?php if (!empty($table2_columns)): ?>
        <div class="table-section-title">📋 <?php echo strtoupper($division_name); ?> - MTM</div>
        
        <table class="dashboard-table">
            <thead>
                <tr>
                    <th rowspan="2" style="min-width:100px;">DASH BOARD</th>
                    <?php foreach ($table2_columns as $col): ?>
                    <th colspan="2" class="assembly-col"><?php echo htmlspecialchars($col['name']); ?></th>
                    <?php endforeach; ?>
                    <th rowspan="2" class="dhu-col">DHU %</th>
                </tr>
                <tr>
                    <?php foreach ($table2_columns as $col): ?>
                    <th>Pcs</th><th>Eff</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <tr class="header-row">
                    <td style="text-align:left;">DIRECTS-BUDGET</td>
                    <?php foreach ($table2_columns as $col): ?>
                    <td colspan="2"><?php echo $col['carder']; ?></td>
                    <?php endforeach; ?>
                    <td>—</td>
                </tr>
                <tr class="header-row">
                    <td style="text-align:left;">DIRECTS-PRESENT</td>
                    <?php foreach ($table2_columns as $col): ?>
                    <td colspan="2"><?php echo $col['carder']; ?></td>
                    <?php endforeach; ?>
                    <td>—</td>
                </tr>
                <tr class="header-row">
                    <td style="text-align:left;">ABSENTEESM</td>
                    <?php foreach ($table2_columns as $col): ?>
                    <td colspan="2">0%</td>
                    <?php endforeach; ?>
                    <td>—</td>
                </tr>
                <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                <tr>
                    <td style="font-weight:700;"><?php echo $h; ?></td>
                    <?php foreach ($table2_columns as $col): 
                        $pcs = $col['hours'][$h - 1] ?? 0;
                        $eff = $col['eff'];
                        $eff_class = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                    ?>
                    <td><?php echo number_format($pcs, 0); ?></td>
                    <td class="<?php echo $eff_class; ?>"><?php echo number_format($eff, 1); ?>%</td>
                    <?php endforeach; ?>
                    <td><?php echo number_format(computeDHU($table2_columns[0] ?? []), 1); ?>%</td>
                </tr>
                <?php endfor; ?>
                <tr class="total-row">
                    <td style="font-weight:700;">Average</td>
                    <?php foreach ($table2_columns as $col): 
                        $eff = $col['eff'];
                        $eff_class = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                    ?>
                    <td style="font-weight:700;"><?php echo number_format($col['pcs'], 0); ?></td>
                    <td class="<?php echo $eff_class; ?>" style="font-weight:700;"><?php echo number_format($eff, 1); ?>%</td>
                    <?php endforeach; ?>
                    <td style="font-weight:700;color:var(--dhu-color);"><?php echo number_format(computeDHU($table2_columns[0] ?? []), 1); ?>%</td>
                </tr>
                <tr class="loss-row">
                    <td style="font-weight:700;">LOSS/PROFIT</td>
                    <?php foreach ($table2_columns as $col): 
                        $loss = round(($col['pcs'] ?? 0) * 0.246, 0);
                    ?>
                    <td colspan="2">LKR <?php echo number_format($loss); ?></td>
                    <?php endforeach; ?>
                    <td>—</td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- CHARTS -->
        <!-- ============================================================ -->
        <div class="chart-grid">
            <div class="chart-card">
                <h4>📈 PRODUCTION - HOURLY PROGRESS</h4>
                <div class="chart-wrapper"><canvas id="chartProduction"></canvas></div>
            </div>
            <div class="chart-card">
                <h4>📉 D.H.U - HOURLY PROGRESS</h4>
                <div class="chart-wrapper"><canvas id="chartDHU"></canvas></div>
            </div>
            <div class="chart-card chart-full">
                <h4>📊 MONTH PRODUCE PCS - TREND LINE</h4>
                <div class="chart-wrapper"><canvas id="chartTrend"></canvas></div>
            </div>
            <div class="chart-card chart-full">
                <h4>📉 MONTH D.H.U - TREND LINE</h4>
                <div class="chart-wrapper"><canvas id="chartDHUTrend"></canvas></div>
            </div>
        </div>

        <div class="loss-profit-container">
            <div class="loss-profit-card">
                <div class="icon">📉</div>
                <?php $loss_profit = round($total_pcs_display * 0.246, 0); ?>
                <div class="amount <?php echo $loss_profit > 0 ? 'profit' : ''; ?>">LKR <?php echo number_format(abs($loss_profit)); ?></div>
                <div class="label"><?php echo $loss_profit > 0 ? 'Profit' : 'Loss'; ?></div>
            </div>
            <div class="loss-profit-card"><div class="icon">📊</div><div class="amount" style="color:var(--primary);"><?php echo number_format($total_pcs_display); ?></div><div class="label">Total Production</div></div>
            <div class="loss-profit-card"><div class="icon">🎯</div><div class="amount" style="color:var(--amber);"><?php echo $avg_eff_display; ?>%</div><div class="label">Avg Efficiency</div></div>
            <div class="loss-profit-card"><div class="icon">⚠️</div><div class="amount" style="color:var(--dhu-color);"><?php echo $avg_dhu_display; ?>%</div><div class="label">Avg DHU</div></div>
        </div>
    </div>

    <script>
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

        new Chart(document.getElementById('chartProduction'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Pcs', data: productionData, backgroundColor: 'rgba(33,115,70,0.7)', borderColor: 'rgba(33,115,70,1)', borderWidth: 2, borderRadius: 4, order: 1 },
                    { label: 'Eff %', data: effData, type: 'line', borderColor: 'rgba(245,124,0,1)', backgroundColor: 'rgba(245,124,0,0.1)', borderWidth: 2, pointBackgroundColor: 'rgba(245,124,0,1)', pointRadius: 4, tension: 0.3, fill: true, yAxisID: 'y1', order: 0 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, position: 'left' }, y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false } } } }
        });

        new Chart(document.getElementById('chartDHU'), {
            type: 'bar',
            data: { labels: labels, datasets: [{ label: 'DHU %', data: dhuData, backgroundColor: 'rgba(231,76,60,0.7)', borderColor: 'rgba(231,76,60,1)', borderWidth: 2, borderRadius: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
        });

        new Chart(document.getElementById('chartTrend'), {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [
                    { label: 'Production Pcs', data: trendPcs, borderColor: 'rgba(33,115,70,1)', backgroundColor: 'rgba(33,115,70,0.15)', fill: true, tension: 0.3, pointRadius: 3, order: 1 },
                    { label: 'Efficiency %', data: trendEff, borderColor: 'rgba(245,124,0,1)', backgroundColor: 'rgba(245,124,0,0.1)', borderDash: [5, 5], fill: true, tension: 0.3, pointRadius: 3, yAxisID: 'y1', order: 0 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { x: { ticks: { maxRotation: 45, font: { size: 9 } } }, y: { beginAtZero: true, position: 'left' }, y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false } } } }
        });

        new Chart(document.getElementById('chartDHUTrend'), {
            type: 'line',
            data: { labels: trendLabels, datasets: [{ label: 'DHU %', data: trendDHU, borderColor: 'rgba(231,76,60,1)', backgroundColor: 'rgba(231,76,60,0.15)', fill: true, tension: 0.3, pointRadius: 3 }] },
            options: { responsive: true, maintainAspectRatio: false, scales: { x: { ticks: { maxRotation: 45, font: { size: 9 } } }, y: { beginAtZero: true } } }
        });

        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                Chart.instances && Object.values(Chart.instances).forEach(c => c.resize && c.resize());
            }, 250);
        });
    </script>
</body>
</html>