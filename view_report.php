<?php
// view_report.php - View Single Report (READ-ONLY)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$conn = getDB();

// Get parameters from URL
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$division_id = isset($_GET['division']) ? (int)$_GET['division'] : 0;

// Get filter parameters for back button
$from_date = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d');
$to_date = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');
$division_filter = isset($_GET['division_filter']) ? $_GET['division_filter'] : 'all';

// If no division ID, try to get from report ID
if ($division_id == 0 && isset($_GET['id'])) {
    $report_id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT devition_id, report_date FROM production_reports WHERE id = ?");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($report) {
        $division_id = $report['devition_id'];
        $date = $report['report_date'];
    }
}

// Get division info
$div_sql = "SELECT * FROM divisions WHERE id = ?";
$stmt = $conn->prepare($div_sql);
$stmt->execute([$division_id]);
$division = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$division) {
    header('Location: reports.php');
    exit;
}

$is_assembly_division = ($division['type'] === 'assembly');
$division_name = $division['name'];

if ($is_assembly_division) {
    $division_name = 'Assembly';
}

$work_hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 10;
$work_hours = max(1, min(11, $work_hours));

// ============================================================
// MATCH OUT DATA FOR ASSEMBLY
// ============================================================
$shirt_match_out = [];
$trouser_match_out = [];
$shirt_match_out_carder = 0;
$shirt_match_out_smv = 0;
$trouser_match_out_carder = 0;
$trouser_match_out_smv = 0;

if ($is_assembly_division) {
    try {
        $shirt_components = getComponents($conn, 1);
        $shirt_match_out = calculateMatchOutFixed($conn, 1, $date, $work_hours, $shirt_components);
        $shirt_match_out_carder = (int)($shirt_match_out['unit_carder'] ?? 0);
        $shirt_match_out_smv = (float)($shirt_match_out['unit_smv'] ?? 0);
    } catch (Exception $e) {
        $shirt_match_out = []; $shirt_match_out_carder = 0; $shirt_match_out_smv = 0;
    }
    try {
        $trouser_components = getComponents($conn, 2);
        $trouser_match_out = calculateMatchOutFixed($conn, 2, $date, $work_hours, $trouser_components);
        $trouser_match_out_carder = (int)($trouser_match_out['unit_carder'] ?? 0);
        $trouser_match_out_smv = (float)($trouser_match_out['unit_smv'] ?? 0);
    } catch (Exception $e) {
        $trouser_match_out = []; $trouser_match_out_carder = 0; $trouser_match_out_smv = 0;
    }
}

// ============================================================
// PROCESS COMPONENTS
// ============================================================
$components = getComponents($conn, $division_id);
$component_data = [];

foreach ($components as $comp) {
    if ($comp['is_match_out']) continue;
    
    $comp_id = $comp['id'];
    $data = getReportData($conn, $division_id, $comp_id, $date);
    
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
    $data['style_epm'] = (float)($data['style_epm'] ?? 13.2);
    $data['profit'] = (float)($data['profit'] ?? 0);
    
    for ($h = 1; $h <= 11; $h++) {
        $data["hour_$h"] = (float)($data["hour_$h"] ?? 0);
    }
    
    // Recalculate using current formulas
    if ($is_assembly_division) {
        $comp_name = strtoupper(trim($comp['name']));
        $total_assemble_carder = 0;
        
        if ($comp_name === 'SHIRT') {
            if ($shirt_match_out_smv > 0) {
                $data['unit_smv'] = $data['ttl_sam_pc'] - $shirt_match_out_smv;
                if ($data['unit_smv'] < 0) $data['unit_smv'] = 0;
            }
            $total_assemble_carder = $data['unit_carder'] + $shirt_match_out_carder;
        } elseif ($comp_name === 'TROUSER') {
            if ($trouser_match_out_smv > 0) {
                $data['unit_smv'] = $data['ttl_sam_pc'] - $trouser_match_out_smv;
                if ($data['unit_smv'] < 0) $data['unit_smv'] = 0;
            }
            $total_assemble_carder = $data['unit_carder'] + $trouser_match_out_carder;
        } else {
            $total_assemble_carder = $data['unit_carder'];
        }
        
        $data['total_assemble_carder'] = $total_assemble_carder;
        
        if ($data['unit_smv'] > 0 && $data['unit_carder'] > 0) {
            $data['day_forecast'] = ($data['unit_carder'] * 600 / $data['unit_smv']) * 0.80;
        } else {
            $data['day_forecast'] = 0;
        }
        $data['plan_minutes'] = $data['day_forecast'] * $data['unit_smv'];
        $data['plan_eff'] = ($data['unit_carder'] * $data['plan_hours'] * 60 > 0) 
            ? ($data['plan_minutes'] / ($data['unit_carder'] * $data['plan_hours'] * 60)) : 0;
        
        if ($comp_name === 'SHIRT' || $comp_name === 'TROUSER') {
            $data['target_100'] = ($data['unit_smv'] > 0) ? ($data['unit_carder'] / $data['unit_smv']) * 60 : 0;
        } else {
            $data['target_100'] = ($data['ttl_sam_pc'] > 0) ? ($data['unit_carder'] / $data['ttl_sam_pc']) * 60 : 0;
        }
        
        $day_total = 0;
        for ($h = 1; $h <= $work_hours; $h++) {
            $day_total += $data["hour_$h"];
        }
        $data['day_total'] = $day_total;
        $data['ern_minutes'] = $day_total * $data['ttl_sam_pc'];
        
        // Assembly Achieved Eff % (ALL rows)
        $data['available_minutes'] = $total_assemble_carder * $data['plan_hours'] * 60;
        
        if ($data['available_minutes'] > 0 && $data['plan_hours'] > 0) {
            $denominator = $data['available_minutes'] * ($data['worked_hours'] / $data['plan_hours']);
            $data['acvd_eff'] = ($denominator > 0) ? ($data['ern_minutes'] / $denominator) : 0;
        } else {
            $data['acvd_eff'] = 0;
        }
        
        $profit_multiplier = 500;
        if ($comp_name === 'SHIRT MTM') $profit_multiplier = 1100;
        elseif ($comp_name === 'TROUSER') $profit_multiplier = 800;
        elseif ($comp_name === 'TROUSER MTM') $profit_multiplier = 1500;
        elseif ($comp_name === 'COAT') $profit_multiplier = 2800;
        elseif ($comp_name === 'COAT MTM') $profit_multiplier = 3400;
        elseif ($comp_name === 'KNIT') $profit_multiplier = 295;
        
        $data['profit'] = ($profit_multiplier * $data['day_total']) - (7365 * $total_assemble_carder);
        
    } else {
        // SHIRT/TROUSER/COAT (90% target)
        if ($data['unit_smv'] > 0 && $data['unit_carder'] > 0) {
            $data['day_forecast'] = ($data['unit_carder'] * 600 / $data['unit_smv']) * 0.90;
        } else {
            $data['day_forecast'] = 0;
        }
        $data['available_minutes'] = $data['unit_carder'] * $data['plan_hours'] * 60;
        $data['plan_minutes'] = $data['day_forecast'] * $data['unit_smv'];
        $data['plan_eff'] = ($data['available_minutes'] > 0) ? ($data['plan_minutes'] / $data['available_minutes']) : 0;
        $data['target_100'] = ($data['unit_smv'] > 0) ? ($data['unit_carder'] / $data['unit_smv']) * 60 : 0;
        
        $day_total = 0;
        for ($h = 1; $h <= $work_hours; $h++) {
            $day_total += $data["hour_$h"];
        }
        $data['day_total'] = $day_total;
        $data['ern_minutes'] = $day_total * $data['unit_smv'];
        
        $denominator = 1;
        if ($data['available_minutes'] > 0 && $data['plan_hours'] > 0) {
            $denominator = ($data['available_minutes'] / $data['plan_hours']) * $data['worked_hours'];
        }
        $data['acvd_eff'] = ($denominator > 0) ? ($data['ern_minutes'] / $denominator) : 0;
        
        if ($data['plan_hours'] > 0) {
            $data['profit'] = ($data['epm'] * ($data['day_total'] * $data['unit_smv'])) - (7365 * $data['unit_carder']) * ($data['worked_hours'] / $data['plan_hours']);
        } else {
            $data['profit'] = ($data['epm'] * ($data['day_total'] * $data['unit_smv'])) - (7365 * $data['unit_carder']);
        }
    }
    
    $component_data[$comp_id] = $data;
}

// ============================================================
// MATCH OUT DATA (Shirt/Trouser/Coat) - READ ONLY
// ============================================================
$match_out = calculateMatchOutFixed($conn, $division_id, $date, $work_hours, $components);
$match_out_saved = getReportData($conn, $division_id, 999, $date);
$match_out_hours = [];
for ($h = 1; $h <= 11; $h++) {
    $match_out_hours[$h] = (float)($match_out_saved["hour_$h"] ?? 0);
}
$has_saved_mo_hours = false;
for ($h = 1; $h <= $work_hours; $h++) {
    if ($match_out_hours[$h] > 0) { $has_saved_mo_hours = true; break; }
}
if (!$has_saved_mo_hours && !empty($match_out['hours'])) {
    for ($h = 1; $h <= $work_hours; $h++) {
        $match_out_hours[$h] = $match_out['hours'][$h] ?? 0;
    }
}

// Match Out Profit = Sum of all component profits
$mo_profit_sum = 0;
foreach ($component_data as $cd) {
    $mo_profit_sum += ($cd['profit'] ?? 0);
}

// ============================================================
// ASSEMBLY DATA (for Shirt/Trouser pages)
// ============================================================
$assembly_shirt_row = null;
$assembly_shirt_mtm_row = null;
$assembly_trouser_row = null;
$assembly_trouser_mtm_row = null;
$assembly_coat_row = null;
$assembly_coat_mtm_row = null;
$assembly_knit_row = null;

$assembly_division_id = 7;
$assembly_components = getComponents($conn, $assembly_division_id);

$assembly_all_data = [];
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
    $data['style_epm'] = (float)($data['style_epm'] ?? 13.2);
    $data['profit'] = (float)($data['profit'] ?? 0);
    
    for ($h = 1; $h <= 11; $h++) {
        $data["hour_$h"] = (float)($data["hour_$h"] ?? 0);
    }
    
    $comp_name = strtoupper(trim($comp['name']));
    $total_assemble_carder = 0;
    
    if ($comp_name === 'SHIRT') {
        if ($shirt_match_out_smv > 0) {
            $data['unit_smv'] = $data['ttl_sam_pc'] - $shirt_match_out_smv;
            if ($data['unit_smv'] < 0) $data['unit_smv'] = 0;
        }
        $total_assemble_carder = $data['unit_carder'] + $shirt_match_out_carder;
    } elseif ($comp_name === 'TROUSER') {
        if ($trouser_match_out_smv > 0) {
            $data['unit_smv'] = $data['ttl_sam_pc'] - $trouser_match_out_smv;
            if ($data['unit_smv'] < 0) $data['unit_smv'] = 0;
        }
        $total_assemble_carder = $data['unit_carder'] + $trouser_match_out_carder;
    } else {
        $total_assemble_carder = $data['unit_carder'];
    }
    
    $data['total_assemble_carder'] = $total_assemble_carder;
    
    if ($data['unit_smv'] > 0 && $data['unit_carder'] > 0) {
        $data['day_forecast'] = ($data['unit_carder'] * 600 / $data['unit_smv']) * 0.80;
    } else {
        $data['day_forecast'] = 0;
    }
    $data['plan_minutes'] = $data['day_forecast'] * $data['unit_smv'];
    $data['plan_eff'] = ($data['unit_carder'] * $data['plan_hours'] * 60 > 0) 
        ? ($data['plan_minutes'] / ($data['unit_carder'] * $data['plan_hours'] * 60)) : 0;
    
    if ($comp_name === 'SHIRT' || $comp_name === 'TROUSER') {
        $data['target_100'] = ($data['unit_smv'] > 0) ? ($data['unit_carder'] / $data['unit_smv']) * 60 : 0;
    } else {
        $data['target_100'] = ($data['ttl_sam_pc'] > 0) ? ($data['unit_carder'] / $data['ttl_sam_pc']) * 60 : 0;
    }
    
    $day_total = 0;
    for ($h = 1; $h <= $work_hours; $h++) {
        $day_total += $data["hour_$h"];
    }
    $data['day_total'] = $day_total;
    $data['ern_minutes'] = $day_total * $data['ttl_sam_pc'];
    
    $data['available_minutes'] = $total_assemble_carder * $data['plan_hours'] * 60;
    if ($data['available_minutes'] > 0 && $data['plan_hours'] > 0) {
        $denominator = $data['available_minutes'] * ($data['worked_hours'] / $data['plan_hours']);
        $data['acvd_eff'] = ($denominator > 0) ? ($data['ern_minutes'] / $denominator) : 0;
    } else {
        $data['acvd_eff'] = 0;
    }
    
    $profit_multiplier = 500;
    if ($comp_name === 'SHIRT MTM') $profit_multiplier = 1100;
    elseif ($comp_name === 'TROUSER') $profit_multiplier = 800;
    elseif ($comp_name === 'TROUSER MTM') $profit_multiplier = 1500;
    elseif ($comp_name === 'COAT') $profit_multiplier = 2800;
    elseif ($comp_name === 'COAT MTM') $profit_multiplier = 3400;
    elseif ($comp_name === 'KNIT') $profit_multiplier = 295;
    
    $data['profit'] = ($profit_multiplier * $data['day_total']) - (7365 * $total_assemble_carder);
    
    $assembly_all_data[$comp['name']] = $data;
}

$assembly_shirt_row = isset($assembly_all_data['SHIRT']) ? $assembly_all_data['SHIRT'] : null;
$assembly_shirt_mtm_row = isset($assembly_all_data['SHIRT MTM']) ? $assembly_all_data['SHIRT MTM'] : null;
$assembly_trouser_row = isset($assembly_all_data['TROUSER']) ? $assembly_all_data['TROUSER'] : null;
$assembly_trouser_mtm_row = isset($assembly_all_data['TROUSER MTM']) ? $assembly_all_data['TROUSER MTM'] : null;
$assembly_coat_row = isset($assembly_all_data['COAT']) ? $assembly_all_data['COAT'] : null;
$assembly_coat_mtm_row = isset($assembly_all_data['COAT MTM']) ? $assembly_all_data['COAT MTM'] : null;
$assembly_knit_row = isset($assembly_all_data['KNIT']) ? $assembly_all_data['KNIT'] : null;

// Get KNIT component ID
$knit_comp_id = 0;
if ($is_assembly_division) {
    foreach ($assembly_components as $comp) {
        if ($comp['name'] === 'KNIT') {
            $knit_comp_id = $comp['id'];
            break;
        }
    }
}

// Summary rows for assembly
$summary_rows = [];
if ($is_assembly_division) {
    $stmt = $conn->prepare("SELECT * FROM production_reports WHERE devition_id = ? AND report_date = ? AND unit_id IN (996, 997)");
    $stmt->execute([$division_id, $date]);
    $summary_rows_result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($summary_rows_result as $row) {
        if ($row['unit_id'] == 997) $summary_rows['lean_total'] = $row;
        elseif ($row['unit_id'] == 996) $summary_rows['grand_total'] = $row;
    }
}

$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($division_name); ?> - Report View</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #217346; --primary-dark: #1a5c3a; --bg: #f0f2f5;
            --text: #1a2332; --text-dark: #0d1a2b; --steel: #6b7a8f;
            --border-radius: 16px; --shadow: 0 8px 32px rgba(0,0,0,0.08);
            --glass-border: rgba(255,255,255,0.3); --glass-bg: rgba(255,255,255,0.15);
            --bad: #dc3545; --good: #28a745; --warning: #ffc107; --amber: #f57c00;
            --dhu-red: #dc3545; --dhu-bg: rgba(220, 53, 69, 0.12);
            --grand-total-bg: rgba(33, 115, 70, 0.15); --lean-bg: rgba(33, 115, 70, 0.08);
        }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 50%, #a5d6a7 100%); min-height: 100vh; color: var(--text); position: relative; }
        .bg-shapes { position: fixed; top: 0; left: 0; width: 100%; height: 100%; overflow: hidden; z-index: 0; pointer-events: none; }
        .shape { position: absolute; border-radius: 50%; opacity: 0.08; animation: float 25s infinite ease-in-out; }
        .shape-1 { width: 500px; height: 500px; background: var(--primary); top: -150px; right: -150px; }
        .shape-2 { width: 300px; height: 300px; background: var(--primary); bottom: -100px; left: -100px; animation-delay: -8s; }
        .shape-3 { width: 200px; height: 200px; background: var(--primary); top: 50%; left: 50%; transform: translate(-50%, -50%); animation-delay: -15s; }
        @keyframes float { 0%, 100% { transform: translate(0, 0) scale(1); } 25% { transform: translate(60px, -60px) scale(1.1); } 50% { transform: translate(-40px, 40px) scale(0.9); } 75% { transform: translate(30px, 30px) scale(1.05); } }
        
        .topbar { position: relative; z-index: 10; background: var(--glass-bg); backdrop-filter: blur(20px); border-bottom: 1px solid var(--glass-border); padding: 10px 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        .topbar .logo-mark { display: flex; align-items: center; gap: 12px; font-weight: 800; font-size: 20px; color: var(--primary-dark); text-decoration: none; }
        .topbar .logo-mark .logo-icon { font-size: 32px; background: var(--primary); color: #fff; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 10px; font-weight: 700; font-size: 18px; }
        .topnav { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .topnav a { color: var(--steel); text-decoration: none; font-size: 14px; font-weight: 600; padding: 7px 16px; border-radius: 10px; transition: all 0.3s; background: transparent; }
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

        .container { max-width: 100%; margin: 0 auto; padding: 20px 30px; }
        
        .report-header {
            background: var(--glass-bg); backdrop-filter: blur(20px); border: 1px solid var(--glass-border);
            border-radius: var(--border-radius); padding: 20px 24px; margin-bottom: 24px; box-shadow: var(--shadow);
        }
        .report-header h2 { font-size: 22px; font-weight: 800; color: var(--text-dark); }
        .report-header .meta { display: flex; gap: 24px; margin-top: 8px; flex-wrap: wrap; }
        .report-header .meta span { color: var(--steel); font-size: 14px; font-weight: 500; }
        .report-header .meta strong { color: var(--text-dark); font-weight: 700; }
        
        .table-container {
            background: var(--glass-bg); backdrop-filter: blur(20px); border: 1px solid var(--glass-border);
            border-radius: var(--border-radius); overflow: hidden; box-shadow: var(--shadow);
            overflow-x: auto; margin-bottom: 16px;
        }
        .table-title {
            padding: 12px 20px; background: rgba(255,255,255,0.2); border-bottom: 2px solid var(--primary);
            font-weight: 700; font-size: 14px; color: var(--text-dark);
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;
        }
        .table-title .badge-info { font-weight: 500; font-size: 13px; color: var(--steel); }
        
        .excel-table { width: 100%; border-collapse: collapse; font-size: 11px; min-width: 2000px; }
        .excel-table th { background: rgba(255,255,255,0.3); border: 1px solid var(--glass-border); padding: 6px 4px; text-align: center; font-weight: 700; color: var(--text-dark); font-size: 9px; white-space: nowrap; position: sticky; top: 0; z-index: 10; }
        .excel-table td { border: 1px solid var(--glass-border); padding: 4px 3px; text-align: center; white-space: nowrap; font-size: 11px; font-weight: 500; }
        .excel-table tr:hover { background: rgba(255,255,255,0.2); }
        .excel-table .calculated { background: rgba(255,255,255,0.1); color: var(--text-dark); }
        .excel-table .total-carder-col { background: rgba(23, 162, 184, 0.1); color: #17a2b8; font-weight: 700; }
        .excel-table .match-out-row { background: rgba(33,115,70,0.08); font-weight: 600; }
        .excel-table .match-out-row td { background: rgba(33,115,70,0.08); }
        .excel-table .dhu-row { background: var(--dhu-bg); color: var(--dhu-red); font-weight: 700; }
        .excel-table .dhu-row td { background: var(--dhu-bg); color: var(--dhu-red); border-color: rgba(220, 53, 69, 0.2); }
        .excel-table .total-row { background: rgba(33, 150, 243, 0.1); font-weight: 700; }
        .excel-table .total-row td { background: rgba(33, 150, 243, 0.1); }
        .excel-table .lean-total-row { background: var(--lean-bg); font-weight: 700; }
        .excel-table .lean-total-row td { background: var(--lean-bg); }
        .excel-table .grand-total-row { background: var(--grand-total-bg); color: #fff; font-weight: 800; }
        .excel-table .grand-total-row td { background: var(--grand-total-bg); color: #fff; border-color: rgba(33,115,70,0.3); }
        .excel-table .assembly-dhu-row { background: var(--dhu-bg); color: var(--dhu-red); font-weight: 600; }
        .excel-table .assembly-dhu-row td { background: var(--dhu-bg); color: var(--dhu-red); border-color: rgba(220, 53, 69, 0.15); }
        .excel-table .section-divider td { background: rgba(33, 115, 70, 0.1) !important; font-weight: 700 !important; color: var(--text-dark) !important; padding: 8px 4px !important; border-top: 2px solid var(--primary) !important; border-bottom: 2px solid var(--primary) !important; }
        
        .back-button {
            display: inline-flex; align-items: center; gap: 8px; padding: 8px 18px;
            background: var(--glass-bg); backdrop-filter: blur(20px); border: 1px solid var(--glass-border);
            border-radius: 10px; color: var(--text-dark); text-decoration: none;
            font-weight: 600; font-size: 14px; transition: all 0.3s ease;
        }
        .back-button:hover { background: rgba(255,255,255,0.3); transform: translateX(-4px); box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        
        .no-data { text-align: center; padding: 30px; color: var(--steel); font-size: 14px; font-weight: 500; }
        
        @media print {
            .topbar, .back-button { display: none !important; }
            .container { padding: 0 !important; }
            .report-header, .table-container { box-shadow: none !important; }
        }
        
        @media (max-width: 768px) {
            .topbar { padding: 10px 16px; flex-direction: column; align-items: stretch; gap: 8px; }
            .topnav { justify-content: center; }
            .right { justify-content: center; }
            .container { padding: 12px 16px; }
            .excel-table { font-size: 10px; min-width: 1500px; }
            .topnav a { padding: 6px 12px; font-size: 13px; }
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
            <a href="reports.php" class="active">Reports</a>
            <?php if (isAdmin()): ?>
            <a href="analytics.php">Analytics</a>
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

    <div class="container">
        <div class="report-header">
            <h2>📋 <?php echo htmlspecialchars($division_name); ?> - Detail Report (Read-Only)</h2>
            <div class="meta">
                <span><strong>Date:</strong> <?php echo date('Y-m-d', strtotime($date)); ?></span>
                <span><strong>Working Hours:</strong> <?php echo $work_hours; ?> hrs</span>
                <span><strong>Components:</strong> <?php echo count($component_data); ?></span>
                <span><strong>Status:</strong> <span style="color:var(--good);">Saved</span></span>
            </div>
        </div>

        <div class="table-container">
            <div class="table-title">
                <span>📊 <?php echo htmlspecialchars($division_name); ?> - Production Report</span>
                <span class="badge-info">Work Hours: <?php echo $work_hours; ?> hrs | Date: <?php echo date('Y-m-d', strtotime($date)); ?></span>
            </div>
            
            <table class="excel-table">
                <thead>
                    <tr>
                        <th style="min-width:60px;">DEVITION</th>
                        <th style="min-width:55px;">Unit</th>
                        <th style="min-width:65px;">TTl SAM/Pcs</th>
                        <th style="min-width:65px;"><?php echo $is_assembly_division ? 'Section SAM/Pc' : 'Unit SMV'; ?></th>
                        <th style="min-width:65px;"><?php echo $is_assembly_division ? 'Assemble Carder' : 'Unit Carder'; ?></th>
                        <?php if ($is_assembly_division): ?>
                        <th style="min-width:75px;">Total Assemble Carder</th>
                        <?php endif; ?>
                        <th style="min-width:65px;">Plan Hours</th>
                        <th style="min-width:65px;">Worked Hours</th>
                        <th style="min-width:70px;">Available Minutes</th>
                        <th style="min-width:60px;">100% Target</th>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <th style="min-width:30px;"><?php echo $h; ?></th>
                        <?php endfor; ?>
                        <th style="min-width:55px;">Day Total</th>
                        <th style="min-width:65px;">Earn Minutes</th>
                        <th style="min-width:65px;">Achieved Eff %</th>
                        <th style="min-width:55px;"><?php echo $is_assembly_division ? 'Style EPM' : 'EPM'; ?></th>
                        <th style="min-width:65px;">Profit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($components)): ?>
                    <tr><td colspan="<?php echo 11 + $work_hours + ($is_assembly_division ? 1 : 0); ?>" class="no-data">No components found for this division.</td></tr>
                    <?php else: ?>
                    
                    <?php 
                    $assembly_order = ['SHIRT', 'SHIRT MTM', 'TROUSER', 'TROUSER MTM', 'COAT', 'COAT MTM', 'KNIT'];
                    
                    $sorted_components = $components;
                    if ($is_assembly_division) {
                        usort($sorted_components, function($a, $b) use ($assembly_order) {
                            $posA = array_search($a['name'], $assembly_order);
                            $posB = array_search($b['name'], $assembly_order);
                            if ($posA === false) $posA = 999;
                            if ($posB === false) $posB = 999;
                            return $posA - $posB;
                        });
                    }
                    
                    $total_day_ttl = 0;
                    $total_ern_min = 0;
                    $total_eff = 0;
                    $row_idx = 0;
                    
                    foreach ($sorted_components as $comp):
                        if ($comp['is_match_out'] || ($is_assembly_division && $comp['name'] === 'KNIT')) continue;
                        $row_idx++;
                        $data = $component_data[$comp['id']] ?? [];
                        
                        $day_total = $data['day_total'] ?? 0;
                        $ern_minutes = $data['ern_minutes'] ?? 0;
                        $acvd_eff = $data['acvd_eff'] ?? 0;
                        $profit = $data['profit'] ?? 0;
                        $epm = $data['epm'] ?? 13.2;
                        $total_assemble_carder = $data['total_assemble_carder'] ?? 0;
                        
                        $total_day_ttl += $day_total;
                        $total_ern_min += $ern_minutes;
                        $total_eff += $acvd_eff;
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($division_name); ?></td>
                        <td><?php echo htmlspecialchars($comp['name']); ?></td>
                        <td><?php echo number_format($data['ttl_sam_pc'] ?? 0, 2); ?></td>
                        <td><?php echo number_format($data['unit_smv'] ?? 0, 2); ?></td>
                        <td><?php echo $data['unit_carder'] ?? 0; ?></td>
                        <?php if ($is_assembly_division): ?>
                        <td class="total-carder-col"><?php echo $total_assemble_carder; ?></td>
                        <?php endif; ?>
                        <td><?php echo number_format($data['plan_hours'] ?? 0, 1); ?></td>
                        <td><?php echo number_format($data['worked_hours'] ?? 0, 1); ?></td>
                        <td class="calculated"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated" style="font-weight:700;"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td><?php echo number_format($data["hour_$h"] ?? 0, 0); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:700;"><?php echo number_format($day_total, 0); ?></td>
                        <td style="font-weight:700;"><?php echo round($ern_minutes); ?></td>
                        <td style="font-weight:700; color:<?php echo ($acvd_eff * 100) >= 70 ? '#28a745' : (($acvd_eff * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;"><?php echo round($acvd_eff * 100); ?>%</td>
                        <td><?php echo number_format($epm, 1); ?></td>
                        <td style="font-weight:700; color:<?php echo $profit >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo round($profit); ?></td>
                    </tr>
                    <?php if ($is_assembly_division): ?>
                    <tr class="assembly-dhu-row">
                        <td colspan="2" style="font-weight:700; color:var(--dhu-red);">DHU %</td>
                        <td colspan="<?php echo 9 + $work_hours + 1; ?>" style="color:var(--dhu-red); font-weight:700; text-align:center;">
                            <?php 
                            $dhu_val = ($day_total > 0) ? round((($day_total / 100) * 5), 1) : 0;
                            echo number_format($dhu_val, 1); ?>%
                        </td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    
                    <!-- KNIT ROW -->
                    <?php if ($is_assembly_division && $knit_comp_id > 0): 
                        $knit_data = getReportData($conn, $assembly_division_id, $knit_comp_id, $date);
                        if (empty($knit_data) || !isset($knit_data['ttl_sam_pc'])) {
                            $knit_data = ['ttl_sam_pc'=>0,'unit_smv'=>0,'unit_carder'=>0,'plan_hours'=>0,'worked_hours'=>$work_hours,'day_forecast'=>0,'available_minutes'=>0,'plan_minutes'=>0,'plan_eff'=>0,'target_100'=>0,'day_total'=>0,'ern_minutes'=>0,'acvd_eff'=>0,'epm'=>13.2,'style_epm'=>13.2,'profit'=>0,'total_assemble_carder'=>0];
                            for ($h = 1; $h <= 11; $h++) $knit_data["hour_$h"] = 0;
                        }
                        $day_total = $knit_data['day_total'] ?? 0;
                        $ern_minutes = $knit_data['ern_minutes'] ?? 0;
                        $acvd_eff = $knit_data['acvd_eff'] ?? 0;
                        $profit = $knit_data['profit'] ?? 0;
                        $style_epm = $knit_data['style_epm'] ?? 13.2;
                        $knit_tac = $knit_data['unit_carder'] ?? 0;
                    ?>
                    <tr>
                        <td>Assembly</td>
                        <td>KNIT</td>
                        <td><?php echo number_format($knit_data['ttl_sam_pc'] ?? 0, 2); ?></td>
                        <td><?php echo number_format($knit_data['unit_smv'] ?? 0, 2); ?></td>
                        <td><?php echo $knit_data['unit_carder'] ?? 0; ?></td>
                        <td class="total-carder-col"><?php echo $knit_tac; ?></td>
                        <td><?php echo number_format($knit_data['plan_hours'] ?? 0, 1); ?></td>
                        <td><?php echo number_format($knit_data['worked_hours'] ?? 0, 1); ?></td>
                        <td class="calculated"><?php echo number_format($knit_data['available_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated" style="font-weight:700;"><?php echo number_format($knit_data['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td><?php echo number_format($knit_data["hour_$h"] ?? 0, 0); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:700;"><?php echo number_format($day_total, 0); ?></td>
                        <td style="font-weight:700;"><?php echo round($ern_minutes); ?></td>
                        <td style="font-weight:700; color:<?php echo ($acvd_eff * 100) >= 70 ? '#28a745' : (($acvd_eff * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;"><?php echo round($acvd_eff * 100); ?>%</td>
                        <td><?php echo number_format($style_epm, 1); ?></td>
                        <td style="font-weight:700; color:<?php echo $profit >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo round($profit); ?></td>
                    </tr>
                    <tr class="assembly-dhu-row">
                        <td colspan="2" style="font-weight:700; color:var(--dhu-red);">DHU %</td>
                        <td colspan="<?php echo 9 + $work_hours + 1; ?>" style="color:var(--dhu-red); font-weight:700; text-align:center;">
                            <?php 
                            $dhu_val = ($day_total > 0) ? round(($day_total / 100) * 5, 1) : 0;
                            echo number_format($dhu_val, 1); ?>%
                        </td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                    </tr>
                    <?php endif; ?>
                    
                    <!-- MATCH OUT ROW (Non-Assembly) -->
                    <?php if (!$is_assembly_division && !empty($match_out)): 
                        $mo_total = 0;
                        for ($h = 1; $h <= $work_hours; $h++) {
                            $mo_total += $match_out_hours[$h] ?? 0;
                        }
                        $mo_unit_smv = (float)($match_out['unit_smv'] ?? 0);
                        $mo_unit_carder = (int)($match_out['unit_carder'] ?? 0);
                        $mo_plan_hours = (float)($match_out['plan_hours'] ?? 0);
                        $mo_worked_hours = (float)($match_out['worked_hours'] ?? 0);
                        $mo_ern_minutes = $mo_total * $mo_unit_smv;
                        $mo_available_minutes = $mo_unit_carder * $mo_plan_hours * 60;
                        $mo_denominator = 1;
                        if ($mo_available_minutes > 0 && $mo_plan_hours > 0) {
                            $mo_denominator = ($mo_available_minutes / $mo_plan_hours) * $mo_worked_hours;
                        }
                        $mo_acvd_eff = ($mo_denominator > 0) ? ($mo_ern_minutes / $mo_denominator) : 0;
                    ?>
                    <tr class="match-out-row">
                        <td colspan="2" style="font-weight:700;">Match Out</td>
                        <td style="font-weight:700;"><?php echo number_format($match_out['ttl_sam'] ?? 0, 4); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($mo_unit_smv, 2); ?></td>
                        <td style="font-weight:700;"><?php echo $mo_unit_carder; ?></td>
                        <td style="font-weight:700;"><?php echo number_format($mo_plan_hours, 1); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($mo_worked_hours, 1); ?></td>
                        <td class="calculated"><?php echo number_format($mo_available_minutes, 0); ?></td>
                        <td class="calculated" style="font-weight:700;"><?php echo number_format($match_out['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td><?php echo number_format($match_out_hours[$h] ?? 0, 0); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:700;"><?php echo number_format($mo_total, 0); ?></td>
                        <td style="font-weight:700;"><?php echo round($mo_ern_minutes); ?></td>
                        <td style="font-weight:700; color:var(--primary);"><?php echo round($mo_acvd_eff * 100); ?>%</td>
                        <td>13.2</td>
                        <td style="font-weight:700; color:<?php echo $mo_profit_sum >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo round($mo_profit_sum); ?></td>
                    </tr>
                    
                    <tr class="dhu-row">
                        <td colspan="<?php echo 10 + $work_hours; ?>" style="text-align:right; padding-right:12px; font-weight:700; color:var(--dhu-red);">
                            DHU %
                        </td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; font-size:14px;">
                            <?php 
                            $dhu_total = 0;
                            $total_day = 0;
                            foreach ($component_data as $cd) {
                                if (($cd['day_total'] ?? 0) > 0) {
                                    $dhu_total += (($cd['day_total'] / 100) * 5);
                                    $total_day += $cd['day_total'];
                                }
                            }
                            $dhu_avg = ($total_day > 0) ? round(($dhu_total / $total_day) * 100, 1) : 0;
                            echo number_format($dhu_avg, 1); ?>%
                        </td>
                    </tr>
                    
                    <tr class="total-row">
                        <td colspan="<?php echo 10 + $work_hours; ?>" style="text-align:right; padding-right:12px; font-weight:700;">
                            Total / <?php echo htmlspecialchars($division_name); ?>:
                        </td>
                        <td style="font-weight:700;"><?php echo number_format($total_day_ttl, 0); ?></td>
                        <td style="font-weight:700;"><?php echo round($total_ern_min); ?></td>
                        <td style="font-weight:700; color:var(--primary);">
                            <?php 
                            $avg_eff = $row_idx > 0 ? round(($total_eff / $row_idx) * 100) : 0;
                            echo $avg_eff . '%';
                            ?>
                        </td>
                        <td>—</td>
                        <td style="font-weight:700;">—</td>
                    </tr>
                    <?php endif; ?>
                    
                    <!-- ASSEMBLY ROWS UNDER SHIRT/TROUSER -->
                    <?php if (!$is_assembly_division): ?>
                    
                        <?php if ($division_name === 'Shirt'): ?>
                        <tr class="section-divider">
                            <td colspan="<?php echo 11 + $work_hours; ?>" style="text-align:center; font-weight:700; color:var(--primary);">─── ASSEMBLY ───</td>
                        </tr>
                        
                        <?php if (!empty($assembly_shirt_row)): $data = $assembly_shirt_row; ?>
                        <tr>
                            <td>Assembly</td>
                            <td>SHIRT</td>
                            <td><?php echo number_format($data['ttl_sam_pc'] ?? 0, 2); ?></td>
                            <td><?php echo number_format($data['unit_smv'] ?? 0, 2); ?></td>
                            <td><?php echo $data['unit_carder'] ?? 0; ?></td>
                            <td class="calculated"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                            <td class="calculated" style="font-weight:700;"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                            <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                            <td><?php echo number_format($data["hour_$h"] ?? 0, 0); ?></td>
                            <?php endfor; ?>
                            <td style="font-weight:700;"><?php echo number_format($data['day_total'] ?? 0, 0); ?></td>
                            <td style="font-weight:700;"><?php echo round($data['ern_minutes'] ?? 0); ?></td>
                            <td style="font-weight:700; color:<?php echo (($data['acvd_eff'] ?? 0) * 100) >= 70 ? '#28a745' : ((($data['acvd_eff'] ?? 0) * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;"><?php echo round(($data['acvd_eff'] ?? 0) * 100); ?>%</td>
                            <td><?php echo number_format($data['style_epm'] ?? 13.2, 1); ?></td>
                            <td style="font-weight:700; color:<?php echo ($data['profit'] ?? 0) >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo round($data['profit'] ?? 0); ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php if (!empty($assembly_shirt_mtm_row)): $data = $assembly_shirt_mtm_row; ?>
                        <tr>
                            <td>Assembly</td>
                            <td>SHIRT MTM</td>
                            <td><?php echo number_format($data['ttl_sam_pc'] ?? 0, 2); ?></td>
                            <td><?php echo number_format($data['unit_smv'] ?? 0, 2); ?></td>
                            <td><?php echo $data['unit_carder'] ?? 0; ?></td>
                            <td class="calculated"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                            <td class="calculated" style="font-weight:700;"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                            <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                            <td><?php echo number_format($data["hour_$h"] ?? 0, 0); ?></td>
                            <?php endfor; ?>
                            <td style="font-weight:700;"><?php echo number_format($data['day_total'] ?? 0, 0); ?></td>
                            <td style="font-weight:700;"><?php echo round($data['ern_minutes'] ?? 0); ?></td>
                            <td style="font-weight:700; color:<?php echo (($data['acvd_eff'] ?? 0) * 100) >= 70 ? '#28a745' : ((($data['acvd_eff'] ?? 0) * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;"><?php echo round(($data['acvd_eff'] ?? 0) * 100); ?>%</td>
                            <td><?php echo number_format($data['style_epm'] ?? 13.2, 1); ?></td>
                            <td style="font-weight:700; color:<?php echo ($data['profit'] ?? 0) >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo round($data['profit'] ?? 0); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php if ($division_name === 'Trouser'): ?>
                        <tr class="section-divider">
                            <td colspan="<?php echo 11 + $work_hours; ?>" style="text-align:center; font-weight:700; color:var(--primary);">─── ASSEMBLY ───</td>
                        </tr>
                        
                        <?php if (!empty($assembly_trouser_row)): $data = $assembly_trouser_row; ?>
                        <tr>
                            <td>Assembly</td>
                            <td>TROUSER</td>
                            <td><?php echo number_format($data['ttl_sam_pc'] ?? 0, 2); ?></td>
                            <td><?php echo number_format($data['unit_smv'] ?? 0, 2); ?></td>
                            <td><?php echo $data['unit_carder'] ?? 0; ?></td>
                            <td class="calculated"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                            <td class="calculated" style="font-weight:700;"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                            <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                            <td><?php echo number_format($data["hour_$h"] ?? 0, 0); ?></td>
                            <?php endfor; ?>
                            <td style="font-weight:700;"><?php echo number_format($data['day_total'] ?? 0, 0); ?></td>
                            <td style="font-weight:700;"><?php echo round($data['ern_minutes'] ?? 0); ?></td>
                            <td style="font-weight:700; color:<?php echo (($data['acvd_eff'] ?? 0) * 100) >= 70 ? '#28a745' : ((($data['acvd_eff'] ?? 0) * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;"><?php echo round(($data['acvd_eff'] ?? 0) * 100); ?>%</td>
                            <td><?php echo number_format($data['style_epm'] ?? 13.2, 1); ?></td>
                            <td style="font-weight:700; color:<?php echo ($data['profit'] ?? 0) >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo round($data['profit'] ?? 0); ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php if (!empty($assembly_trouser_mtm_row)): $data = $assembly_trouser_mtm_row; ?>
                        <tr>
                            <td>Assembly</td>
                            <td>TROUSER MTM</td>
                            <td><?php echo number_format($data['ttl_sam_pc'] ?? 0, 2); ?></td>
                            <td><?php echo number_format($data['unit_smv'] ?? 0, 2); ?></td>
                            <td><?php echo $data['unit_carder'] ?? 0; ?></td>
                            <td class="calculated"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                            <td class="calculated" style="font-weight:700;"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                            <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                            <td><?php echo number_format($data["hour_$h"] ?? 0, 0); ?></td>
                            <?php endfor; ?>
                            <td style="font-weight:700;"><?php echo number_format($data['day_total'] ?? 0, 0); ?></td>
                            <td style="font-weight:700;"><?php echo round($data['ern_minutes'] ?? 0); ?></td>
                            <td style="font-weight:700; color:<?php echo (($data['acvd_eff'] ?? 0) * 100) >= 70 ? '#28a745' : ((($data['acvd_eff'] ?? 0) * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;"><?php echo round(($data['acvd_eff'] ?? 0) * 100); ?>%</td>
                            <td><?php echo number_format($data['style_epm'] ?? 13.2, 1); ?></td>
                            <td style="font-weight:700; color:<?php echo ($data['profit'] ?? 0) >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo round($data['profit'] ?? 0); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php endif; ?>
                        
                    <?php endif; ?>
                    
                    <!-- LEAN TOTAL -->
                    <?php if ($is_assembly_division && isset($summary_rows['lean_total']) && ($summary_rows['lean_total']['ttl_sam_pc'] ?? 0) > 0): 
                        $lt = $summary_rows['lean_total'];
                    ?>
                    <tr class="lean-total-row">
                        <td colspan="2" style="font-weight:700;">Lean Total</td>
                        <td style="font-weight:700;"><?php echo number_format($lt['ttl_sam_pc'] ?? 0, 4); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lt['unit_smv'] ?? 0, 3); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lt['unit_carder'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;">—</td>
                        <td style="font-weight:700;"><?php echo number_format($lt['plan_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lt['worked_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lt['available_minutes'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lt['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td style="font-weight:700;"><?php echo number_format($lt["hour_$h"] ?? 0, 0); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:700;"><?php echo number_format($lt['day_total'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;"><?php echo round($lt['ern_minutes'] ?? 0); ?></td>
                        <td style="font-weight:700; color:var(--primary);"><?php echo round(($lt['acvd_eff'] ?? 0) * 100); ?>%</td>
                        <td>—</td>
                        <td style="font-weight:700;"><?php echo round((500 * ($lt['day_total'] ?? 0)) - (7365 * (($lt['unit_carder'] ?? 0) + ($shirt_match_out['unit_carder'] ?? 0)))); ?></td>
                    </tr>
                    
                    <tr class="dhu-row">
                        <td colspan="<?php echo 10 + $work_hours; ?>" style="text-align:right; padding-right:12px; font-weight:700; color:var(--dhu-red);">Lean Total DHU %</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; font-size:14px;">
                            <?php 
                            $dhu_day_total = 0;
                            foreach ($component_data as $cd) {
                                if (($cd['day_total'] ?? 0) > 0) {
                                    $dhu_day_total += (($cd['day_total'] / 100) * 5);
                                }
                            }
                            $lean_dhu = ($lt['day_total'] > 0) ? round(($dhu_day_total / $lt['day_total']) * 100, 1) : 0;
                            echo number_format($lean_dhu, 1); ?>%
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <!-- GRAND TOTAL -->
                    <?php if ($is_assembly_division && isset($summary_rows['grand_total']) && ($summary_rows['grand_total']['ttl_sam_pc'] ?? 0) > 0): 
                        $gt = $summary_rows['grand_total'];
                    ?>
                    <tr class="grand-total-row">
                        <td colspan="2" style="font-weight:800;">Factory Grand Total/Average</td>
                        <td style="font-weight:800;"><?php echo number_format($gt['ttl_sam_pc'] ?? 0, 4); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($gt['unit_smv'] ?? 0, 3); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($gt['unit_carder'] ?? 0, 0); ?></td>
                        <td style="font-weight:800;">—</td>
                        <td style="font-weight:800;"><?php echo number_format($gt['plan_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($gt['worked_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($gt['available_minutes'] ?? 0, 0); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($gt['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td style="font-weight:800;"><?php echo number_format($gt["hour_$h"] ?? 0, 4); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:800;"><?php echo number_format($gt['day_total'] ?? 0, 0); ?></td>
                        <td style="font-weight:800;"><?php echo round($gt['ern_minutes'] ?? 0); ?></td>
                        <td style="font-weight:800;"><?php echo round(($gt['acvd_eff'] ?? 0) * 100); ?>%</td>
                        <td>—</td>
                        <td style="font-weight:800;"><?php echo round((500 * ($gt['day_total'] ?? 0)) - (7365 * (($gt['unit_carder'] ?? 0) + 0))); ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top: 16px; display: flex; gap: 12px; flex-wrap: wrap;">
            <a href="reports.php?from=<?php echo $from_date; ?>&to=<?php echo $to_date; ?>&division=<?php echo $division_filter; ?>" class="back-button">
                ← Back to Reports
            </a>
            <button class="back-button" onclick="window.print()" style="cursor:pointer;">
                🖨️ Print Report
            </button>
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
    </script>
</body>
</html>