<?php
// division_view.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$conn = getDB();
$division_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$work_hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 10;
$work_hours = max(1, min(11, $work_hours));

$div_sql = "SELECT * FROM divisions WHERE id = ?";
$stmt = $conn->prepare($div_sql);
$stmt->execute([$division_id]);
$division = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$division) {
    header('Location: dashboard.php');
    exit;
}

$is_assembly_division = ($division['type'] === 'assembly');
$division_name = $division['name'];

if ($is_assembly_division) {
    $division_name = 'Assembly';
}

$components = getComponents($conn, $division_id);
$component_data = [];

$assembly_order = ['SHIRT', 'SHIRT MTM', 'TROUSER', 'TROUSER MTM', 'COAT', 'COAT MTM', 'KNIT'];

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

// Process each component
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

// Calculate Match Out (aggregate from components) — but now editable
$match_out = calculateMatchOutFixed($conn, $division_id, $date, $work_hours, $components);

// Load Match Out saved data (unit_id = 999)
$match_out_saved = getReportData($conn, $division_id, 999, $date);
$match_out_hours = [];
for ($h = 1; $h <= 11; $h++) {
    $match_out_hours[$h] = (float)($match_out_saved["hour_$h"] ?? 0);
}
// If no saved hours yet, use the calculated ones as default
$has_saved_mo_hours = false;
for ($h = 1; $h <= $work_hours; $h++) {
    if ($match_out_hours[$h] > 0) { $has_saved_mo_hours = true; break; }
}
if (!$has_saved_mo_hours && !empty($match_out['hours'])) {
    for ($h = 1; $h <= $work_hours; $h++) {
        $match_out_hours[$h] = $match_out['hours'][$h] ?? 0;
    }
}

// ============================================================
// ASSEMBLY DATA
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

if ($assembly_knit_row === null && $is_assembly_division) {
    $assembly_knit_row = [
        'ttl_sam_pc' => 0, 'unit_smv' => 0, 'unit_carder' => 0,
        'plan_hours' => 0, 'worked_hours' => $work_hours,
        'day_forecast' => 0, 'available_minutes' => 0,
        'plan_minutes' => 0, 'plan_eff' => 0, 'target_100' => 0,
        'day_total' => 0, 'ern_minutes' => 0, 'acvd_eff' => 0,
        'epm' => 13.2, 'style_epm' => 13.2, 'profit' => 0,
        'total_assemble_carder' => 0
    ];
    for ($h = 1; $h <= 11; $h++) {
        $assembly_knit_row["hour_$h"] = 0;
    }
}

$lean_total = [];
$grand_total = [];
$assembly_rows_data = [];

if ($is_assembly_division) {
    $assembly_rows = [];
    foreach ($components as $comp) {
        if ($comp['is_match_out']) continue;
        $data = $component_data[$comp['id']] ?? [];
        if ($data['ttl_sam_pc'] > 0 || $data['unit_smv'] > 0) {
            $assembly_rows[] = $data;
        }
    }
    $assembly_rows_data = $assembly_rows;
    $lean_total = calculateLeanTotalAssembly($assembly_rows, $work_hours);
    $grand_total = calculateGrandTotalAssembly($assembly_rows, $trouser_match_out, $shirt_match_out, $work_hours);
}

$stats = getDivisionStats($conn, $division_id, $date, $work_hours);
$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';

$knit_comp_id = 0;
if ($is_assembly_division) {
    foreach ($assembly_components as $comp) {
        if ($comp['name'] === 'KNIT') {
            $knit_comp_id = $comp['id'];
            break;
        }
    }
    if ($knit_comp_id == 0) {
        try {
            $stmt = $conn->prepare("INSERT INTO components (division_id, name, is_match_out, display_order) VALUES (?, ?, ?, ?)");
            $stmt->execute([7, 'KNIT', 0, 7]);
            $knit_comp_id = $conn->lastInsertId();
            $assembly_components = getComponents($conn, $assembly_division_id);
        } catch (Exception $e) {
            $knit_comp_id = 0;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($division_name); ?> - Production Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #217346; --primary-dark: #1a5c3a; --bg: #f0f2f5; --card: rgba(255,255,255,0.85);
            --text: #1a2332; --text-dark: #0d1a2b; --steel: #6b7a8f; --line: rgba(255,255,255,0.2);
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

        .container { position: relative; z-index: 5; max-width: 100%; padding: 20px 30px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        .page-header .title h2 { font-size: 20px; font-weight: 800; color: var(--text-dark); }
        .page-header .title .sub { color: var(--steel); font-size: 14px; font-weight: 500; margin-top: 4px; }
        .page-header .controls { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .page-header .controls input[type="date"] { padding: 7px 12px; border: 1px solid var(--glass-border); border-radius: 8px; font-size: 13px; font-weight: 500; font-family: 'Inter', sans-serif; background: rgba(255,255,255,0.7); color: var(--text-dark); }
        .page-header .controls input[type="date"]:focus { outline: none; border-color: var(--primary); }
        .page-header .controls .hours-input { padding: 7px 12px; border: 1px solid var(--glass-border); border-radius: 8px; font-size: 13px; font-weight: 600; font-family: 'Inter', sans-serif; background: rgba(255,255,255,0.7); color: var(--text-dark); width: 70px; text-align: center; }
        .page-header .controls .hours-input:focus { outline: none; border-color: var(--primary); }
        .page-header .controls .hours-label { font-size: 13px; font-weight: 600; color: var(--text-dark); }
        .btn { padding: 7px 16px; border: none; border-radius: 8px; font-weight: 600; font-size: 13px; cursor: pointer; transition: all 0.3s; font-family: 'Inter', sans-serif; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-back { background: rgba(255,255,255,0.5); color: var(--text-dark); border: 1px solid var(--glass-border); }
        .btn-refresh { background: rgba(255,255,255,0.5); color: var(--text-dark); border: 1px solid var(--glass-border); }
        .btn-save { background: var(--primary); color: #fff; }
        .btn-save:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 4px 15px rgba(33,115,70,0.3); }
        .btn-export { background: rgba(255,255,255,0.5); color: var(--text-dark); border: 1px solid var(--glass-border); }
        
        .back-button { display: inline-flex; align-items: center; gap: 8px; padding: 8px 18px; background: var(--glass-bg); backdrop-filter: blur(20px); border: 1px solid var(--glass-border); border-radius: 10px; color: var(--text-dark); text-decoration: none; font-weight: 600; font-size: 14px; transition: all 0.3s ease; margin-bottom: 20px; }
        .back-button:hover { background: rgba(255,255,255,0.3); transform: translateX(-4px); box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        
        .table-container { background: var(--glass-bg); backdrop-filter: blur(20px); border: 1px solid var(--glass-border); border-radius: var(--border-radius); overflow: hidden; box-shadow: var(--shadow); overflow-x: auto; margin-bottom: 16px; }
        .table-title { padding: 12px 20px; background: rgba(255,255,255,0.2); border-bottom: 2px solid var(--primary); font-weight: 700; font-size: 14px; color: var(--text-dark); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .table-title .badge-info { font-weight: 500; font-size: 13px; color: var(--steel); }
        
        .excel-table { width: 100%; border-collapse: collapse; font-size: 11px; min-width: 2000px; }
        .excel-table th { background: rgba(255,255,255,0.3); border: 1px solid var(--glass-border); padding: 6px 4px; text-align: center; font-weight: 700; color: var(--text-dark); font-size: 9px; white-space: nowrap; position: sticky; top: 0; z-index: 10; }
        .excel-table td { border: 1px solid var(--glass-border); padding: 4px 3px; text-align: center; white-space: nowrap; font-size: 11px; font-weight: 500; }
        .excel-table tr:hover { background: rgba(255,255,255,0.2); }
        .excel-table .editable-yellow { background: rgba(255, 235, 59, 0.3); }
        .excel-table .editable-yellow input { background: rgba(255, 235, 59, 0.3); width: 100%; border: none; text-align: center; padding: 3px 2px; font-size: 11px; font-weight: 600; font-family: 'Inter', sans-serif; min-width: 40px; }
        .excel-table .editable-yellow input:focus { outline: 2px solid var(--primary); outline-offset: -2px; background: rgba(255,255,255,0.9); }
        .excel-table .editable-yellow input:hover { background: rgba(255, 235, 59, 0.5); }
        .excel-table .calculated { background: rgba(255,255,255,0.1); color: var(--text-dark); }
        .excel-table .total-carder-col { background: rgba(23, 162, 184, 0.1); color: #17a2b8; font-weight: 700; }
        .excel-table .readonly-cell { background: rgba(200, 200, 200, 0.15); }
        .excel-table .readonly-cell input { background: rgba(200, 200, 200, 0.15); cursor: not-allowed; }
        .excel-table .match-out-row { background: rgba(33,115,70,0.08); font-weight: 600; }
        .excel-table .match-out-row td { background: rgba(33,115,70,0.08); }
        .excel-table .match-out-row .editable-yellow { background: rgba(255, 235, 59, 0.3); }
        .excel-table .match-out-row .editable-yellow input { background: rgba(255, 235, 59, 0.3); }
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
        
        .scroll-indicator { text-align: center; padding: 6px; background: rgba(255, 193, 7, 0.1); color: #856404; font-size: 11px; font-weight: 500; border-bottom: 1px solid rgba(255, 193, 7, 0.2); }
        
        .custom-notification { position: fixed; top: 20px; right: 20px; padding: 12px 24px; border-radius: 8px; z-index: 9999; box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-family: 'Inter', sans-serif; font-size: 14px; font-weight: 600; max-width: 350px; }
        .custom-notification.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .custom-notification.info { background: #cce5ff; color: #004085; border: 1px solid #b8daff; }
        .custom-notification.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        @media (max-width: 768px) {
            .topbar { padding: 10px 16px; flex-direction: column; align-items: stretch; gap: 8px; }
            .topnav { justify-content: center; }
            .right { justify-content: center; }
            .container { padding: 12px 16px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .page-header .controls { width: 100%; flex-wrap: wrap; }
            .page-header .controls input[type="date"] { flex: 1; }
            .excel-table { font-size: 10px; min-width: 1500px; }
            .excel-table th, .excel-table td { padding: 3px 2px; }
            .excel-table .editable-yellow input { min-width: 30px; font-size: 10px; }
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
            <a href="division_view.php?id=<?php echo $division_id; ?>&date=<?php echo $date; ?>&hours=<?php echo $work_hours; ?>" class="active">Production</a>
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
        <div class="page-header">
            <div class="title">
                <h2>All deviations — <?php echo date('Y-m-d', strtotime($date)); ?></h2>
                <div class="sub"><?php echo htmlspecialchars($division_name); ?></div>
            </div>
            <div class="controls">
                <input type="date" id="reportDate" value="<?php echo $date; ?>" onchange="updatePage()">
                <span class="hours-label">Hours:</span>
                <input type="number" id="workHours" class="hours-input" value="<?php echo $work_hours; ?>" min="1" max="11" onchange="updatePage()">
                <a href="dashboard.php" class="btn btn-back">← Back</a>
                <button class="btn btn-refresh" onclick="window.location.reload()">🔄 Refresh</button>
                <button class="btn btn-export" onclick="window.print()">📥 Export</button>
                <button class="btn btn-save" onclick="saveAll()">💾 Save All</button>
            </div>
        </div>

        <div class="scroll-indicator">⬅️ Scroll horizontally to view all columns ➡️</div>

        <div class="table-container">
            <div class="table-title">
                <span>📋 <?php echo htmlspecialchars($division_name); ?></span>
                <span class="badge-info"><?php echo $stats['setup_units']; ?> of <?php echo $stats['total_units']; ?> units set up | Work Hours: <?php echo $work_hours; ?> hrs</span>
            </div>
            
            <table class="excel-table" id="mainTable">
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
                    <tr><td colspan="<?php echo 11 + $work_hours + ($is_assembly_division ? 1 : 0); ?>" style="padding:30px; color:var(--steel); text-align:center; font-weight:500;">No components found.</td></tr>
                    <?php else: ?>
                    
                    <?php 
                    $total_day_ttl = 0;
                    $total_ern_min = 0;
                    $total_eff = 0;
                    $row_idx = 0;
                    
                    if ($is_assembly_division) {
                        usort($components, function($a, $b) use ($assembly_order) {
                            $posA = array_search($a['name'], $assembly_order);
                            $posB = array_search($b['name'], $assembly_order);
                            if ($posA === false) $posA = 999;
                            if ($posB === false) $posB = 999;
                            return $posA - $posB;
                        });
                    }
                    
                    foreach ($components as $comp):
                        if ($comp['is_match_out'] || ($is_assembly_division && $comp['name'] === 'KNIT')) continue;
                        $row_idx++;
                        $data = $component_data[$comp['id']] ?? [];
                        
                        $day_total = $data['day_total'] ?? 0;
                        $ern_minutes = $data['ern_minutes'] ?? 0;
                        $acvd_eff = $data['acvd_eff'] ?? 0;
                        $profit = $data['profit'] ?? 0;
                        $epm = $data['epm'] ?? 13.2;
                        $total_assemble_carder = $data['total_assemble_carder'] ?? 0;
                        
                        $comp_name_upper = strtoupper(trim($comp['name']));
                        $is_readonly_smv = ($is_assembly_division && ($comp_name_upper === 'SHIRT' || $comp_name_upper === 'TROUSER'));
                        
                        $total_day_ttl += $day_total;
                        $total_ern_min += $ern_minutes;
                        $total_eff += $acvd_eff;
                    ?>
                    <tr data-component="<?php echo $comp['id']; ?>" 
                        data-isassembly="<?php echo $is_assembly_division ? '1' : '0'; ?>" 
                        data-compname="<?php echo htmlspecialchars($comp['name']); ?>">
                        <td><?php echo htmlspecialchars($division_name); ?></td>
                        <td><?php echo htmlspecialchars($comp['name']); ?></td>
                        <td class="editable-yellow"><input type="number" step="0.01" class="field-input" data-field="ttl_sam_pc" value="<?php echo $data['ttl_sam_pc'] ?? 0; ?>"></td>
                        <td class="<?php echo $is_readonly_smv ? 'readonly-cell' : 'editable-yellow'; ?>">
                            <input type="number" step="0.01" 
                                   class="field-input <?php echo $is_readonly_smv ? 'readonly-smv' : ''; ?>" 
                                   data-field="unit_smv" 
                                   value="<?php echo number_format($data['unit_smv'] ?? 0, 2, '.', ''); ?>"
                                   <?php echo $is_readonly_smv ? 'readonly' : ''; ?>>
                        </td>
                        <td class="editable-yellow"><input type="number" class="field-input" data-field="unit_carder" value="<?php echo $data['unit_carder'] ?? 0; ?>"></td>
                        <?php if ($is_assembly_division): ?>
                        <td class="calculated total-carder-col total-assemble-carder"><?php echo $total_assemble_carder; ?></td>
                        <?php endif; ?>
                        <td class="editable-yellow"><input type="number" step="0.5" class="field-input" data-field="plan_hours" value="<?php echo $data['plan_hours'] ?? 0; ?>"></td>
                        <td class="editable-yellow"><input type="number" step="0.5" class="field-input" data-field="worked_hours" value="<?php echo $data['worked_hours'] ?? $work_hours; ?>"></td>
                        <td class="calculated avail-minutes" id="am-<?php echo $comp['id']; ?>"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated target-100" id="t100-<?php echo $comp['id']; ?>" style="font-weight:700;"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td class="editable-yellow"><input type="number" class="hour-input" data-hour="<?php echo $h; ?>" value="<?php echo $data["hour_$h"] ?? 0; ?>"></td>
                        <?php endfor; ?>
                        <td class="calculated day-total" id="dt-<?php echo $comp['id']; ?>" style="font-weight:700;"><?php echo number_format($day_total, 0); ?></td>
                        <td class="calculated ern-minutes" id="em-<?php echo $comp['id']; ?>" style="font-weight:700;"><?php echo round($ern_minutes); ?></td>
                        <td class="calculated acvd-eff" id="ae-<?php echo $comp['id']; ?>" style="font-weight:700; color:<?php echo ($acvd_eff * 100) >= 70 ? '#28a745' : (($acvd_eff * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;"><?php echo round($acvd_eff * 100); ?>%</td>
                        <td class="editable-yellow"><input type="number" step="0.1" class="epm-input" data-field="epm" value="<?php echo number_format($epm, 1); ?>"></td>
                        <td class="calculated profit-value" id="profit-<?php echo $comp['id']; ?>" style="font-weight:700; color:<?php echo $profit >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo round($profit); ?></td>
                    </tr>
                    <?php if ($is_assembly_division): ?>
                    <tr class="assembly-dhu-row" data-dhu-for="<?php echo $comp['id']; ?>">
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
                            $knit_data = [
                                'ttl_sam_pc' => 0, 'unit_smv' => 0, 'unit_carder' => 0,
                                'plan_hours' => 0, 'worked_hours' => $work_hours,
                                'day_forecast' => 0, 'available_minutes' => 0,
                                'plan_minutes' => 0, 'plan_eff' => 0, 'target_100' => 0,
                                'day_total' => 0, 'ern_minutes' => 0, 'acvd_eff' => 0,
                                'epm' => 13.2, 'style_epm' => 13.2, 'profit' => 0,
                                'total_assemble_carder' => 0
                            ];
                            for ($h = 1; $h <= 11; $h++) {
                                $knit_data["hour_$h"] = 0;
                            }
                        }
                        
                        $day_total = $knit_data['day_total'] ?? 0;
                        $ern_minutes = $knit_data['ern_minutes'] ?? 0;
                        $acvd_eff = $knit_data['acvd_eff'] ?? 0;
                        $profit = $knit_data['profit'] ?? 0;
                        $style_epm = $knit_data['style_epm'] ?? 13.2;
                        $knit_tac = $knit_data['unit_carder'] ?? 0;
                    ?>
                    <tr data-component="<?php echo $knit_comp_id; ?>" data-isassembly="1" data-compname="KNIT">
                        <td>Assembly</td>
                        <td>KNIT</td>
                        <td class="editable-yellow"><input type="number" step="0.01" class="field-input" data-field="ttl_sam_pc" value="<?php echo $knit_data['ttl_sam_pc'] ?? 0; ?>"></td>
                        <td class="editable-yellow"><input type="number" step="0.01" class="field-input" data-field="unit_smv" value="<?php echo number_format($knit_data['unit_smv'] ?? 0, 2, '.', ''); ?>"></td>
                        <td class="editable-yellow"><input type="number" class="field-input" data-field="unit_carder" value="<?php echo $knit_data['unit_carder'] ?? 0; ?>"></td>
                        <td class="calculated total-carder-col total-assemble-carder"><?php echo $knit_tac; ?></td>
                        <td class="editable-yellow"><input type="number" step="0.5" class="field-input" data-field="plan_hours" value="<?php echo $knit_data['plan_hours'] ?? 0; ?>"></td>
                        <td class="editable-yellow"><input type="number" step="0.5" class="field-input" data-field="worked_hours" value="<?php echo $knit_data['worked_hours'] ?? $work_hours; ?>"></td>
                        <td class="calculated avail-minutes"><?php echo number_format($knit_data['available_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated target-100" style="font-weight:700;"><?php echo number_format($knit_data['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td class="editable-yellow"><input type="number" class="hour-input" data-hour="<?php echo $h; ?>" value="<?php echo $knit_data["hour_$h"] ?? 0; ?>"></td>
                        <?php endfor; ?>
                        <td class="calculated day-total" style="font-weight:700;"><?php echo number_format($day_total, 0); ?></td>
                        <td class="calculated ern-minutes" style="font-weight:700;"><?php echo round($ern_minutes); ?></td>
                        <td class="calculated acvd-eff" style="font-weight:700; color:<?php echo ($acvd_eff * 100) >= 70 ? '#28a745' : (($acvd_eff * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;"><?php echo round($acvd_eff * 100); ?>%</td>
                        <td class="editable-yellow"><input type="number" step="0.1" class="style-epm-input" data-field="style_epm" value="<?php echo number_format($style_epm, 1); ?>"></td>
                        <td class="calculated profit-value" style="font-weight:700; color:<?php echo $profit >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo round($profit); ?></td>
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
                    
                    <!-- MATCH OUT ROW (EDITABLE) -->
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
                        
                        // ============================================================
                        // MATCH OUT PROFIT = SUM OF ALL COMPONENT PROFITS ABOVE
                        // Using a NEW variable name to bypass any cached values
                        // ============================================================
                        $match_out_summed_profit = 0;
                        foreach ($components as $c) {
                            if ($c['is_match_out']) continue;
                            if (isset($component_data[$c['id']]['profit'])) {
                                $match_out_summed_profit += (float)$component_data[$c['id']]['profit'];
                            }
                        }
                        $mo_profit = $match_out_summed_profit;
                    ?>
                    <tr class="match-out-row" id="matchOutRow" data-component="999" data-isassembly="0" data-compname="MATCH OUT">
                        <td colspan="2" style="font-weight:700;">Match Out</td>
                        <td style="font-weight:700;" id="mo-ttl-sam"><?php echo number_format($match_out['ttl_sam'] ?? 0, 4); ?></td>
                        <td style="font-weight:700;" id="mo-unit-smv"><?php echo number_format($mo_unit_smv, 2); ?></td>
                        <td style="font-weight:700;" id="mo-unit-carder"><?php echo $mo_unit_carder; ?></td>
                        <td style="font-weight:700;" id="mo-plan-hours"><?php echo number_format($mo_plan_hours, 1); ?></td>
                        <td style="font-weight:700;" id="mo-worked-hours"><?php echo number_format($mo_worked_hours, 1); ?></td>
                        <td class="calculated" id="mo-available-minutes"><?php echo number_format($mo_available_minutes, 0); ?></td>
                        <td class="calculated" id="mo-target-100"><?php echo number_format($match_out['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td class="editable-yellow"><input type="number" class="hour-input mo-hour-input" data-hour="<?php echo $h; ?>" value="<?php echo $match_out_hours[$h] ?? 0; ?>"></td>
                        <?php endfor; ?>
                        <td style="font-weight:700;" id="mo-day-total"><?php echo number_format($mo_total, 0); ?></td>
                        <td style="font-weight:700;" id="mo-ern-minutes"><?php echo round($mo_ern_minutes); ?></td>
                        <td style="font-weight:700; color:var(--primary);" id="mo-acvd-eff"><?php echo round($mo_acvd_eff * 100); ?>%</td>
                        <td class="editable-yellow"><input type="number" step="0.1" class="epm-input" data-field="epm" value="13.2" id="mo-epm"></td>
                        <td class="calculated" id="mo-profit" style="font-weight:700; color:<?php echo $mo_profit >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo round($mo_profit); ?></td>
                        <!-- DEBUG mo_profit = <?php echo $mo_profit; ?> | count component_data = <?php echo count($component_data); ?> | summed = <?php echo $match_out_summed_profit ?? 'N/A'; ?> -->
                    </tr>
                    
                    <tr class="dhu-row" id="dhuRow">
                        <td colspan="<?php echo 10 + $work_hours; ?>" style="text-align:right; padding-right:12px; font-weight:700; color:var(--dhu-red);">
                            DHU %
                        </td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; font-size:14px;" id="dhu-value">
                            <?php 
                            $dhu_total = 0;
                            $total_day = 0;
                            foreach ($components as $comp) {
                                if ($comp['is_match_out']) continue;
                                $d = $component_data[$comp['id']] ?? [];
                                if (($d['day_total'] ?? 0) > 0) {
                                    $dhu_total += (($d['day_total'] / 100) * 5);
                                    $total_day += $d['day_total'];
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
                        
                        <?php if (!empty($assembly_shirt_row)): 
                            $data = $assembly_shirt_row;
                            $comp_id = 0;
                            foreach ($assembly_components as $comp) {
                                if ($comp['name'] === 'SHIRT') { $comp_id = $comp['id']; break; }
                            }
                        ?>
                        <tr data-component="<?php echo $comp_id; ?>" data-isassembly="1" data-compname="SHIRT">
                            <td>Assembly</td>
                            <td>SHIRT</td>
                            <td class="editable-yellow"><input type="number" step="0.01" class="field-input" data-field="ttl_sam_pc" value="<?php echo $data['ttl_sam_pc'] ?? 0; ?>"></td>
                            <td class="readonly-cell"><input type="number" step="0.01" class="field-input readonly-smv" data-field="unit_smv" value="<?php echo number_format($data['unit_smv'] ?? 0, 2, '.', ''); ?>" readonly></td>
                            <td class="editable-yellow"><input type="number" class="field-input" data-field="unit_carder" value="<?php echo $data['unit_carder'] ?? 0; ?>"></td>
                            <td class="calculated avail-minutes"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                            <td class="calculated target-100" style="font-weight:700;"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                            <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                            <td class="editable-yellow"><input type="number" class="hour-input" data-hour="<?php echo $h; ?>" value="<?php echo $data["hour_$h"] ?? 0; ?>"></td>
                            <?php endfor; ?>
                            <td class="calculated day-total" style="font-weight:700;"><?php echo number_format($data['day_total'] ?? 0, 0); ?></td>
                            <td class="calculated ern-minutes" style="font-weight:700;"><?php echo round($data['ern_minutes'] ?? 0); ?></td>
                            <td class="calculated acvd-eff" style="font-weight:700; color:<?php echo (($data['acvd_eff'] ?? 0) * 100) >= 70 ? '#28a745' : ((($data['acvd_eff'] ?? 0) * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;"><?php echo round(($data['acvd_eff'] ?? 0) * 100); ?>%</td>
                            <td class="editable-yellow"><input type="number" step="0.1" class="style-epm-input" data-field="style_epm" value="<?php echo number_format($data['style_epm'] ?? 13.2, 1); ?>"></td>
                            <td class="calculated profit-value" style="font-weight:700; color:<?php echo ($data['profit'] ?? 0) >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo round($data['profit'] ?? 0); ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php if (!empty($assembly_shirt_mtm_row)): 
                            $data = $assembly_shirt_mtm_row;
                            $comp_id = 0;
                            foreach ($assembly_components as $comp) {
                                if ($comp['name'] === 'SHIRT MTM') { $comp_id = $comp['id']; break; }
                            }
                        ?>
                        <tr data-component="<?php echo $comp_id; ?>" data-isassembly="1" data-compname="SHIRT MTM">
                            <td>Assembly</td>
                            <td>SHIRT MTM</td>
                            <td class="editable-yellow"><input type="number" step="0.01" class="field-input" data-field="ttl_sam_pc" value="<?php echo $data['ttl_sam_pc'] ?? 0; ?>"></td>
                            <td class="editable-yellow"><input type="number" step="0.01" class="field-input" data-field="unit_smv" value="<?php echo number_format($data['unit_smv'] ?? 0, 2, '.', ''); ?>"></td>
                            <td class="editable-yellow"><input type="number" class="field-input" data-field="unit_carder" value="<?php echo $data['unit_carder'] ?? 0; ?>"></td>
                            <td class="calculated avail-minutes"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                            <td class="calculated target-100" style="font-weight:700;"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                            <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                            <td class="editable-yellow"><input type="number" class="hour-input" data-hour="<?php echo $h; ?>" value="<?php echo $data["hour_$h"] ?? 0; ?>"></td>
                            <?php endfor; ?>
                            <td class="calculated day-total" style="font-weight:700;"><?php echo number_format($data['day_total'] ?? 0, 0); ?></td>
                            <td class="calculated ern-minutes" style="font-weight:700;"><?php echo round($data['ern_minutes'] ?? 0); ?></td>
                            <td class="calculated acvd-eff" style="font-weight:700; color:<?php echo (($data['acvd_eff'] ?? 0) * 100) >= 70 ? '#28a745' : ((($data['acvd_eff'] ?? 0) * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;"><?php echo round(($data['acvd_eff'] ?? 0) * 100); ?>%</td>
                            <td class="editable-yellow"><input type="number" step="0.1" class="style-epm-input" data-field="style_epm" value="<?php echo number_format($data['style_epm'] ?? 13.2, 1); ?>"></td>
                            <td class="calculated profit-value" style="font-weight:700; color:<?php echo ($data['profit'] ?? 0) >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo round($data['profit'] ?? 0); ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php endif; ?>
                        
                        <?php if ($division_name === 'Trouser'): ?>
                        <tr class="section-divider">
                            <td colspan="<?php echo 11 + $work_hours; ?>" style="text-align:center; font-weight:700; color:var(--primary);">─── ASSEMBLY ───</td>
                        </tr>
                        
                        <?php if (!empty($assembly_trouser_row)): 
                            $data = $assembly_trouser_row;
                            $comp_id = 0;
                            foreach ($assembly_components as $comp) {
                                if ($comp['name'] === 'TROUSER') { $comp_id = $comp['id']; break; }
                            }
                        ?>
                        <tr data-component="<?php echo $comp_id; ?>" data-isassembly="1" data-compname="TROUSER">
                            <td>Assembly</td>
                            <td>TROUSER</td>
                            <td class="editable-yellow"><input type="number" step="0.01" class="field-input" data-field="ttl_sam_pc" value="<?php echo $data['ttl_sam_pc'] ?? 0; ?>"></td>
                            <td class="readonly-cell"><input type="number" step="0.01" class="field-input readonly-smv" data-field="unit_smv" value="<?php echo number_format($data['unit_smv'] ?? 0, 2, '.', ''); ?>" readonly></td>
                            <td class="editable-yellow"><input type="number" class="field-input" data-field="unit_carder" value="<?php echo $data['unit_carder'] ?? 0; ?>"></td>
                            <td class="calculated avail-minutes"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                            <td class="calculated target-100" style="font-weight:700;"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                            <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                            <td class="editable-yellow"><input type="number" class="hour-input" data-hour="<?php echo $h; ?>" value="<?php echo $data["hour_$h"] ?? 0; ?>"></td>
                            <?php endfor; ?>
                            <td class="calculated day-total" style="font-weight:700;"><?php echo number_format($data['day_total'] ?? 0, 0); ?></td>
                            <td class="calculated ern-minutes" style="font-weight:700;"><?php echo round($data['ern_minutes'] ?? 0); ?></td>
                            <td class="calculated acvd-eff" style="font-weight:700; color:<?php echo (($data['acvd_eff'] ?? 0) * 100) >= 70 ? '#28a745' : ((($data['acvd_eff'] ?? 0) * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;"><?php echo round(($data['acvd_eff'] ?? 0) * 100); ?>%</td>
                            <td class="editable-yellow"><input type="number" step="0.1" class="style-epm-input" data-field="style_epm" value="<?php echo number_format($data['style_epm'] ?? 13.2, 1); ?>"></td>
                            <td class="calculated profit-value" style="font-weight:700; color:<?php echo ($data['profit'] ?? 0) >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo round($data['profit'] ?? 0); ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php if (!empty($assembly_trouser_mtm_row)): 
                            $data = $assembly_trouser_mtm_row;
                            $comp_id = 0;
                            foreach ($assembly_components as $comp) {
                                if ($comp['name'] === 'TROUSER MTM') { $comp_id = $comp['id']; break; }
                            }
                        ?>
                        <tr data-component="<?php echo $comp_id; ?>" data-isassembly="1" data-compname="TROUSER MTM">
                            <td>Assembly</td>
                            <td>TROUSER MTM</td>
                            <td class="editable-yellow"><input type="number" step="0.01" class="field-input" data-field="ttl_sam_pc" value="<?php echo $data['ttl_sam_pc'] ?? 0; ?>"></td>
                            <td class="editable-yellow"><input type="number" step="0.01" class="field-input" data-field="unit_smv" value="<?php echo number_format($data['unit_smv'] ?? 0, 2, '.', ''); ?>"></td>
                            <td class="editable-yellow"><input type="number" class="field-input" data-field="unit_carder" value="<?php echo $data['unit_carder'] ?? 0; ?>"></td>
                            <td class="calculated avail-minutes"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                            <td class="calculated target-100" style="font-weight:700;"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                            <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                            <td class="editable-yellow"><input type="number" class="hour-input" data-hour="<?php echo $h; ?>" value="<?php echo $data["hour_$h"] ?? 0; ?>"></td>
                            <?php endfor; ?>
                            <td class="calculated day-total" style="font-weight:700;"><?php echo number_format($data['day_total'] ?? 0, 0); ?></td>
                            <td class="calculated ern-minutes" style="font-weight:700;"><?php echo round($data['ern_minutes'] ?? 0); ?></td>
                            <td class="calculated acvd-eff" style="font-weight:700; color:<?php echo (($data['acvd_eff'] ?? 0) * 100) >= 70 ? '#28a745' : ((($data['acvd_eff'] ?? 0) * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;"><?php echo round(($data['acvd_eff'] ?? 0) * 100); ?>%</td>
                            <td class="editable-yellow"><input type="number" step="0.1" class="style-epm-input" data-field="style_epm" value="<?php echo number_format($data['style_epm'] ?? 13.2, 1); ?>"></td>
                            <td class="calculated profit-value" style="font-weight:700; color:<?php echo ($data['profit'] ?? 0) >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo round($data['profit'] ?? 0); ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php endif; ?>
                        
                    <?php endif; ?>
                    
                    <?php endif; ?>
                    
                    <!-- LEAN TOTAL -->
                    <?php if ($is_assembly_division && !empty($lean_total)): ?>
                    <tr class="lean-total-row" id="leanTotalRow">
                        <td colspan="2" style="font-weight:700;">Lean Total</td>
                        <td style="font-weight:700;" id="lt-ttl-sam"><?php echo number_format($lean_total['ttl_sam'] ?? 0, 4); ?></td>
                        <td style="font-weight:700;" id="lt-section-sam"><?php echo number_format($lean_total['section_sam'] ?? 0, 3); ?></td>
                        <td style="font-weight:700;" id="lt-assemble-carder"><?php echo number_format($lean_total['assemble_carder'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;" id="lt-total-assemble-carder">—</td>
                        <td style="font-weight:700;" id="lt-plan-hours"><?php echo number_format($lean_total['plan_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:700;" id="lt-worked-hours"><?php echo number_format($lean_total['worked_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:700;" id="lt-available-minutes"><?php echo number_format($lean_total['available_minutes'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;" id="lt-target-100"><?php echo number_format($lean_total['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td style="font-weight:700;" id="lt-hour-<?php echo $h; ?>"><?php echo number_format($lean_total['hours'][$h] ?? 0, 0); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:700;" id="lt-day-total"><?php echo number_format($lean_total['day_total'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;" id="lt-ern-minutes"><?php echo round($lean_total['ern_minutes'] ?? 0); ?></td>
                        <td style="font-weight:700; color:var(--primary);" id="lt-acvd-eff"><?php echo round(($lean_total['acvd_eff'] ?? 0) * 100); ?>%</td>
                        <td>—</td>
                        <td style="font-weight:700;" id="lt-profit"><?php echo round((500 * ($lean_total['day_total'] ?? 0)) - (7365 * (($lean_total['assemble_carder'] ?? 0) + ($shirt_match_out['unit_carder'] ?? 0)))); ?></td>
                    </tr>
                    
                    <tr class="dhu-row">
                        <td colspan="<?php echo 10 + $work_hours; ?>" style="text-align:right; padding-right:12px; font-weight:700; color:var(--dhu-red);">
                            Lean Total DHU %
                        </td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; font-size:14px;" id="lt-dhu-value">
                            <?php 
                            $dhu_day_total = 0;
                            foreach ($components as $comp) {
                                if ($comp['is_match_out']) continue;
                                $d = $component_data[$comp['id']] ?? [];
                                if (($d['day_total'] ?? 0) > 0) {
                                    $dhu_day_total += (($d['day_total'] / 100) * 5);
                                }
                            }
                            $lean_dhu = ($lean_total['day_total'] > 0) ? round(($dhu_day_total / $lean_total['day_total']) * 100, 1) : 0;
                            echo number_format($lean_dhu, 1); ?>%
                        </td>
                    </tr>
                    
                    <?php if (!empty($grand_total)): ?>
                    <tr class="grand-total-row" id="grandTotalRow">
                        <td colspan="2" style="font-weight:800;">Factory Grand Total/Average</td>
                        <td style="font-weight:800;" id="gt-ttl-sam"><?php echo number_format($grand_total['ttl_sam'] ?? 0, 4); ?></td>
                        <td style="font-weight:800;" id="gt-section-sam"><?php echo number_format($grand_total['section_sam'] ?? 0, 3); ?></td>
                        <td style="font-weight:800;" id="gt-assemble-carder"><?php echo number_format($grand_total['assemble_carder'] ?? 0, 0); ?></td>
                        <td style="font-weight:800;" id="gt-total-assemble-carder">—</td>
                        <td style="font-weight:800;" id="gt-plan-hours"><?php echo number_format($grand_total['plan_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:800;" id="gt-worked-hours"><?php echo number_format($grand_total['worked_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:800;" id="gt-available-minutes"><?php echo number_format($grand_total['available_minutes'] ?? 0, 0); ?></td>
                        <td style="font-weight:800;" id="gt-target-100"><?php echo number_format($grand_total['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td style="font-weight:800;" id="gt-hour-<?php echo $h; ?>"><?php echo number_format($grand_total['hours'][$h] ?? 0, 4); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:800;" id="gt-day-total"><?php echo number_format($grand_total['day_total'] ?? 0, 0); ?></td>
                        <td style="font-weight:800;" id="gt-ern-minutes"><?php echo round($grand_total['ern_minutes'] ?? 0); ?></td>
                        <td style="font-weight:800;" id="gt-acvd-eff"><?php echo round(($grand_total['acvd_eff'] ?? 0) * 100); ?>%</td>
                        <td>—</td>
                        <td style="font-weight:800;" id="gt-profit"><?php echo round((500 * ($grand_total['day_total'] ?? 0)) - (7365 * (($grand_total['assemble_carder'] ?? 0) + 0))); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div style="margin-top: 10px; text-align: left;">
            <a href="dashboard.php" class="back-button">← Back to Dashboard</a>
        </div>
    </div>

    <script>
        var SHIRT_MATCH_OUT_CARDER = <?php echo (int)$shirt_match_out_carder; ?>;
        var TROUSER_MATCH_OUT_CARDER = <?php echo (int)$trouser_match_out_carder; ?>;
        var SHIRT_MATCH_OUT_SMV = <?php echo (float)$shirt_match_out_smv; ?>;
        var TROUSER_MATCH_OUT_SMV = <?php echo (float)$trouser_match_out_smv; ?>;

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

        function updatePage() {
            var date = document.getElementById('reportDate').value;
            var hours = document.getElementById('workHours').value;
            window.location.href = '?id=<?php echo $division_id; ?>&date=' + date + '&hours=' + hours;
        }

        function rnd(val) {
            return Math.round(val);
        }

        // ============================================================
        // DEBOUNCED AUTO-SAVE
        // ============================================================
        var saveTimeouts = {};
        
        function scheduleAutoSave(key, fn) {
            if (saveTimeouts[key]) {
                clearTimeout(saveTimeouts[key]);
            }
            saveTimeouts[key] = setTimeout(function() {
                fn();
                delete saveTimeouts[key];
            }, 400); // 400ms after user stops typing
        }
        
        // Visual "saving" indicator
        function showSavingIndicator() {
            var el = $('#save-indicator');
            if (el.length === 0) {
                $('<div id="save-indicator" style="position:fixed;bottom:20px;right:20px;padding:8px 16px;background:rgba(33,115,70,0.9);color:#fff;border-radius:8px;font-size:13px;font-weight:600;z-index:9999;box-shadow:0 4px 15px rgba(0,0,0,0.2);">💾 Saving...</div>').appendTo('body');
            } else {
                el.stop(true, true).fadeIn(150);
            }
        }
        
        function hideSavingIndicator() {
            setTimeout(function() {
                $('#save-indicator').fadeOut(300);
            }, 200);
        }

        // ============================================================
        // FIXED: sumComponentProfits() - now EXCLUDES assembly rows
        // ============================================================
        function sumComponentProfits() {
            var total = 0;
            $('#mainTable tbody tr').each(function() {
                var r = $(this);
                // Skip special rows
                if (r.hasClass('match-out-row') || r.hasClass('dhu-row') || 
                    r.hasClass('total-row') || r.hasClass('lean-total-row') || 
                    r.hasClass('grand-total-row') || r.hasClass('assembly-dhu-row') || 
                    r.hasClass('section-divider')) {
                    return;
                }
                // Skip assembly rows (they are NOT part of this division's components)
                if (String(r.attr('data-isassembly')) === '1') {
                    return;
                }
                total += parseFloat(r.find('.profit-value').text()) || 0;
            });
            return total;
        }

        function updateMatchOutProfit() {
            var matchOutRow = $('#matchOutRow');
            if (matchOutRow.length === 0) return;
            var moProfit = sumComponentProfits();
            matchOutRow.find('#mo-profit').text(rnd(moProfit));
            matchOutRow.find('#mo-profit').css('color', moProfit >= 0 ? '#28a745' : '#dc3545');
        }

        function recalcMatchOutRow() {
            var row = $('#matchOutRow');
            if (row.length === 0) return;
            
            var hours = parseInt($('#workHours').val()) || 10;
            var dayTotal = 0;
            var hourValues = {};
            for (var h = 1; h <= hours; h++) {
                var val = parseFloat(row.find('.mo-hour-input[data-hour="' + h + '"]').val()) || 0;
                hourValues[h] = val;
                dayTotal += val;
            }
            
            var unitSmv = parseFloat(row.find('#mo-unit-smv').text().replace(/,/g, '')) || 0;
            var unitCarder = parseFloat(row.find('#mo-unit-carder').text()) || 0;
            var planHours = parseFloat(row.find('#mo-plan-hours').text()) || 0;
            var workedHours = parseFloat(row.find('#mo-worked-hours').text()) || 0;
            
            var ernMinutes = dayTotal * unitSmv;
            var availableMinutes = unitCarder * planHours * 60;
            
            var denominator = 1;
            if (availableMinutes > 0 && planHours > 0) {
                denominator = (availableMinutes / planHours) * workedHours;
            }
            var acvdEff = denominator > 0 ? (ernMinutes / denominator) : 0;
            
            var profit = sumComponentProfits();
            
            row.find('#mo-day-total').text(Math.round(dayTotal));
            row.find('#mo-ern-minutes').text(rnd(ernMinutes));
            row.find('#mo-acvd-eff').text(rnd(acvdEff * 100) + '%');
            row.find('#mo-profit').text(rnd(profit));
            row.find('#mo-profit').css('color', profit >= 0 ? '#28a745' : '#dc3545');
            
            var date = $('#reportDate').val();
            var division = <?php echo $division_id; ?>;
            var data = {
                ttl_sam_pc: parseFloat(row.find('#mo-ttl-sam').text()) || 0,
                unit_smv: unitSmv,
                unit_carder: unitCarder,
                plan_hours: planHours,
                worked_hours: workedHours,
                epm: parseFloat(row.find('#mo-epm').val()) || 13.2,
                profit: profit
            };
            for (var h = 1; h <= 11; h++) {
                data['hour_' + h] = hourValues[h] || 0;
            }
            
            scheduleAutoSave('save_matchout', function() {
                showSavingIndicator();
                $.ajax({
                    url: 'save_data.php',
                    type: 'POST',
                    data: {
                        action: 'auto_save',
                        date: date,
                        division: division,
                        component: 999,
                        data: JSON.stringify(data),
                        work_hours: hours,
                        is_assembly: '0'
                    },
                    dataType: 'json',
                    success: function() { hideSavingIndicator(); },
                    error: function() { hideSavingIndicator(); }
                });
            });
            
            setTimeout(function() { updateSummaryRows(); }, 100);
        }

        function recalcRow(row) {
            if (row.hasClass('lean-total-row') || row.hasClass('grand-total-row') || 
                row.hasClass('total-row') || row.hasClass('dhu-row') || 
                row.hasClass('assembly-dhu-row') || row.hasClass('section-divider')) {
                return;
            }
            
            if (row.hasClass('match-out-row')) {
                recalcMatchOutRow();
                return;
            }
            
            var isAssembly = row.data('isassembly') == '1';
            var hours = parseInt($('#workHours').val()) || 10;
            var compId = row.data('component');
            var compName = (row.attr('data-compname') || '').toUpperCase().trim();
            
            var ttlSamPc = parseFloat(row.find('input[data-field="ttl_sam_pc"]').val()) || 0;
            var unitSmv = parseFloat(row.find('input[data-field="unit_smv"]').val()) || 0;
            var unitCarder = parseFloat(row.find('input[data-field="unit_carder"]').val()) || 0;
            var planHours = parseFloat(row.find('input[data-field="plan_hours"]').val()) || 0;
            var workedHours = parseFloat(row.find('input[data-field="worked_hours"]').val()) || hours;
            var epm = parseFloat(row.find('input[data-field="epm"]').val()) || 13.2;
            var styleEpm = parseFloat(row.find('input[data-field="style_epm"]').val()) || 13.2;
            
            var dayTotal = 0;
            var hourValues = {};
            for (var h = 1; h <= hours; h++) {
                var val = parseFloat(row.find('input[data-hour="' + h + '"]').val()) || 0;
                hourValues[h] = val;
                dayTotal += val;
            }
            
            var dayForecast = 0, availableMinutes = 0, planMinutes = 0, planEff = 0, target100 = 0, ernMinutes = 0, acvdEff = 0, profit = 0;
            var totalAssembleCarder = 0;
            var isShirtOrTrouser = false;
            
            if (isAssembly) {
                isShirtOrTrouser = (compName === 'SHIRT' || compName === 'TROUSER');
                
                if (isShirtOrTrouser) {
                    var matchOutSmv = (compName === 'SHIRT') ? SHIRT_MATCH_OUT_SMV : TROUSER_MATCH_OUT_SMV;
                    if (matchOutSmv > 0) {
                        var newSmv = ttlSamPc - matchOutSmv;
                        if (newSmv < 0) newSmv = 0;
                        unitSmv = newSmv;
                        row.find('input[data-field="unit_smv"]').val(unitSmv.toFixed(2));
                    }
                    
                    var matchOutCarder = (compName === 'SHIRT') ? SHIRT_MATCH_OUT_CARDER : TROUSER_MATCH_OUT_CARDER;
                    totalAssembleCarder = unitCarder + matchOutCarder;
                } else {
                    totalAssembleCarder = unitCarder;
                }
                
                row.find('.total-assemble-carder').text(totalAssembleCarder);
                
                if (unitSmv > 0 && unitCarder > 0) {
                    dayForecast = (unitCarder * 600 / unitSmv) * 0.80;
                }
                planMinutes = dayForecast * unitSmv;
                planEff = (unitCarder * planHours * 60 > 0) ? (planMinutes / (unitCarder * planHours * 60)) : 0;
                ernMinutes = dayTotal * ttlSamPc;
                
                if (isShirtOrTrouser) {
                    target100 = (unitSmv > 0) ? (unitCarder / unitSmv) * 60 : 0;
                } else {
                    target100 = (ttlSamPc > 0) ? (unitCarder / ttlSamPc) * 60 : 0;
                }
                
                availableMinutes = totalAssembleCarder * planHours * 60;
                if (availableMinutes > 0 && planHours > 0) {
                    var denominator = availableMinutes * (workedHours / planHours);
                    acvdEff = (denominator > 0) ? (ernMinutes / denominator) : 0;
                } else {
                    acvdEff = 0;
                }
                
                var profitMultiplier = 500;
                if (compName === 'SHIRT MTM') profitMultiplier = 1100;
                else if (compName === 'TROUSER') profitMultiplier = 800;
                else if (compName === 'TROUSER MTM') profitMultiplier = 1500;
                else if (compName === 'COAT') profitMultiplier = 2800;
                else if (compName === 'COAT MTM') profitMultiplier = 3400;
                else if (compName === 'KNIT') profitMultiplier = 295;
                
                profit = (profitMultiplier * dayTotal) - (7365 * totalAssembleCarder);
                
            } else {
                if (unitSmv > 0 && unitCarder > 0) {
                    dayForecast = (unitCarder * 600 / unitSmv) * 0.90;
                    target100 = (unitCarder / unitSmv) * 60;
                }
                availableMinutes = unitCarder * planHours * 60;
                planMinutes = dayForecast * unitSmv;
                planEff = availableMinutes > 0 ? (planMinutes / availableMinutes) : 0;
                ernMinutes = dayTotal * unitSmv;
                var denom2 = (availableMinutes > 0 && planHours > 0) 
                    ? (availableMinutes / planHours) * workedHours 
                    : 1;
                acvdEff = denom2 > 0 ? (ernMinutes / denom2) : 0;
                if (planHours > 0) {
                    profit = (epm * (dayTotal * unitSmv)) - (7365 * unitCarder) * (workedHours / planHours);
                } else {
                    profit = (epm * (dayTotal * unitSmv)) - (7365 * unitCarder);
                }
            }
            
            row.find('.avail-minutes').text(Math.round(availableMinutes));
            row.find('.target-100').text(Math.round(target100));
            row.find('.day-total').text(Math.round(dayTotal));
            row.find('.ern-minutes').text(rnd(ernMinutes));
            
            var effPercent = acvdEff * 100;
            row.find('.acvd-eff').text(rnd(effPercent) + '%');
            row.find('.acvd-eff').css('color', effPercent >= 70 ? '#28a745' : (effPercent >= 50 ? '#f57c00' : '#dc3545'));
            
            row.find('.profit-value').text(rnd(profit));
            row.find('.profit-value').css('color', profit >= 0 ? '#28a745' : '#dc3545');
            
            var epmValue = isAssembly ? styleEpm : epm;
            
            if (compId && compId > 0) {
                autoSave(row, compId, isAssembly, hours, {
                    ttl_sam_pc: ttlSamPc,
                    unit_smv: unitSmv,
                    unit_carder: unitCarder,
                    plan_hours: planHours,
                    worked_hours: workedHours,
                    hourValues: hourValues,
                    epm: epmValue,
                    profit: profit
                });
            }
            
            if (isAssembly) {
                updateAssemblyDHU(compId, dayTotal);
            } else {
                // Update Match Out profit whenever any component changes
                updateMatchOutProfit();
            }
            
            setTimeout(function() { updateSummaryRows(); }, 100);
        }

        function autoSave(row, compId, isAssembly, hours, calcData) {
            if (!compId || compId <= 0) return;
            
            var date = $('#reportDate').val();
            var division = <?php echo $division_id; ?>;
            var data = {
                ttl_sam_pc: calcData.ttl_sam_pc,
                unit_smv: calcData.unit_smv,
                unit_carder: calcData.unit_carder,
                plan_hours: calcData.plan_hours,
                worked_hours: calcData.worked_hours,
                epm: calcData.epm || 13.2,
                profit: calcData.profit || 0
            };
            for (var h = 1; h <= 11; h++) {
                data['hour_' + h] = calcData.hourValues[h] || 0;
            }
            
            // Debounce by component ID (so rapid typing only fires once)
            var saveKey = 'save_comp_' + compId;
            scheduleAutoSave(saveKey, function() {
                showSavingIndicator();
                $.ajax({
                    url: 'save_data.php',
                    type: 'POST',
                    data: {
                        action: 'auto_save',
                        date: date,
                        division: division,
                        component: compId,
                        data: JSON.stringify(data),
                        work_hours: hours,
                        is_assembly: isAssembly ? '1' : '0'
                    },
                    dataType: 'json',
                    success: function() {
                        hideSavingIndicator();
                    },
                    error: function() {
                        hideSavingIndicator();
                    }
                });
            });
        }

        function updateAssemblyDHU(compId, dayTotal) {
            var dhuRow = $('tr[data-dhu-for="' + compId + '"]');
            if (dhuRow.length > 0) {
                var dhuVal = dayTotal > 0 ? round((dayTotal / 100) * 5, 1) : 0;
                var tds = dhuRow.find('td');
                if (tds.length > 2) {
                    $(tds[2]).text(dhuVal.toFixed(1) + '%');
                }
            }
        }

        $(document).on('change input', '.epm-input, .style-epm-input', function() {
            var row = $(this).closest('tr');
            if (!row.hasClass('lean-total-row') && !row.hasClass('grand-total-row') && 
                !row.hasClass('total-row') && !row.hasClass('dhu-row') && 
                !row.hasClass('assembly-dhu-row') && !row.hasClass('section-divider')) {
                recalcRow(row);
            }
        });

        function calculateLeanTotal(rows, hours) {
            if (!rows || rows.length === 0) {
                return { ttl_sam: 0, section_sam: 0, day_forecast: 0, assemble_carder: 0, plan_hours: 10, worked_hours: 10, available_minutes: 0, plan_minutes: 0, plan_eff: 0.8, target_100: 0, hours: {}, day_total: 0, ern_minutes: 0, acvd_eff: 0 };
            }
            
            var lt = { ttl_sam: 0, section_sam: 0, day_forecast: 0, assemble_carder: 0, plan_hours: 10, worked_hours: 10, available_minutes: 0, plan_minutes: 0, plan_eff: 0.8, target_100: 0, hours: {}, day_total: 0, ern_minutes: 0, acvd_eff: 0 };
            for (var h = 1; h <= hours; h++) lt.hours[h] = 0;
            
            var count = 0;
            rows.forEach(function(r) {
                if (r.ttl_sam_pc > 0 || r.unit_smv > 0) {
                    count++;
                    lt.ttl_sam += r.ttl_sam_pc;
                    lt.section_sam += r.unit_smv;
                    lt.day_forecast += r.day_forecast;
                    lt.assemble_carder += r.unit_carder;
                    lt.available_minutes += r.available_minutes;
                    lt.plan_minutes += r.plan_minutes;
                    lt.target_100 += r.target_100;
                    for (var h = 1; h <= hours; h++) {
                        lt.hours[h] += r["hour_" + h] || 0;
                    }
                    lt.ern_minutes += r.ern_minutes || 0;
                }
            });
            
            if (count > 0) {
                lt.ttl_sam = lt.ttl_sam / count;
                lt.section_sam = lt.section_sam / count;
                lt.target_100 = lt.target_100 / count;
                lt.day_total = 0;
                for (var h = 1; h <= hours; h++) {
                    lt.day_total += lt.hours[h] || 0;
                }
                lt.acvd_eff = lt.available_minutes > 0 
                    ? (lt.ern_minutes / lt.available_minutes) * (lt.plan_hours / lt.worked_hours) 
                    : 0;
            }
            return lt;
        }

        function calculateGrandTotal(rows, matchOut, hours) {
            if (!rows || rows.length === 0) {
                return { ttl_sam: 0, section_sam: 0, day_forecast: 0, assemble_carder: 0, plan_hours: 10, worked_hours: 10, available_minutes: 0, plan_minutes: 0, plan_eff: 0, target_100: 0, hours: {}, day_total: 0, ern_minutes: 0, acvd_eff: 0 };
            }
            
            var lt = calculateLeanTotal(rows, hours);
            var gt = { ttl_sam: lt.ttl_sam, section_sam: lt.section_sam, day_forecast: lt.day_forecast, assemble_carder: 0, plan_hours: 10, worked_hours: 10, available_minutes: 0, plan_minutes: 0, plan_eff: 0, target_100: 0, hours: {}, day_total: 0, ern_minutes: 0, acvd_eff: 0 };
            for (var h = 1; h <= hours; h++) gt.hours[h] = 0;
            
            gt.assemble_carder = lt.assemble_carder + TROUSER_MATCH_OUT_CARDER + SHIRT_MATCH_OUT_CARDER;
            gt.available_minutes = gt.assemble_carder * gt.plan_hours * 60;
            
            var planMinSum = 0;
            rows.forEach(function(r) {
                planMinSum += (r.day_forecast || 0) * (r.ttl_sam_pc || 0);
            });
            gt.plan_minutes = planMinSum;
            gt.plan_eff = gt.available_minutes > 0 ? (gt.plan_minutes / gt.available_minutes) : 0;
            gt.target_100 = gt.ttl_sam > 0 ? (gt.assemble_carder / gt.ttl_sam) * 60 : 0;
            
            for (var h = 1; h <= hours; h++) {
                var numerator = 0;
                rows.forEach(function(r) {
                    numerator += (r["hour_" + h] || 0) * (r.ttl_sam_pc || 0);
                });
                gt.hours[h] = (gt.assemble_carder * 60 > 0) ? numerator / (gt.assemble_carder * 60) : 0;
            }
            
            gt.day_total = lt.day_total;
            var earnedSum = 0;
            rows.forEach(function(r) {
                earnedSum += (r.day_total || 0) * (r.ttl_sam_pc || 0);
            });
            gt.ern_minutes = earnedSum;
            gt.acvd_eff = gt.available_minutes > 0 
                ? (gt.ern_minutes / gt.available_minutes) * (gt.plan_hours / gt.worked_hours) 
                : 0;
            
            return gt;
        }

        function updateSummaryRows() {
            var hours = parseInt($('#workHours').val()) || 10;
            var isAssembly = <?php echo $is_assembly_division ? 'true' : 'false'; ?>;
            
            var componentRows = [];
            var assemblyRows = [];
            
            $('.excel-table tbody tr').each(function() {
                if (!$(this).hasClass('match-out-row') && !$(this).hasClass('lean-total-row') && 
                    !$(this).hasClass('grand-total-row') && !$(this).hasClass('total-row') && 
                    !$(this).hasClass('dhu-row') && !$(this).hasClass('assembly-dhu-row') &&
                    !$(this).hasClass('section-divider')) {
                    var row = $(this);
                    var rowIsAssembly = row.data('isassembly') == '1';
                    
                    var rowData = {
                        element: row,
                        compId: row.data('component'),
                        isAssembly: rowIsAssembly,
                        ttl_sam_pc: parseFloat(row.find('input[data-field="ttl_sam_pc"]').val()) || 0,
                        unit_smv: parseFloat(row.find('input[data-field="unit_smv"]').val()) || 0,
                        unit_carder: parseFloat(row.find('input[data-field="unit_carder"]').val()) || 0,
                        plan_hours: parseFloat(row.find('input[data-field="plan_hours"]').val()) || 0,
                        worked_hours: parseFloat(row.find('input[data-field="worked_hours"]').val()) || hours,
                        day_forecast: parseFloat(row.find('.day-forecast').text()) || 0,
                        available_minutes: parseFloat(row.find('.avail-minutes').text()) || 0,
                        plan_minutes: parseFloat(row.find('.plan-minutes').text()) || 0,
                        plan_eff: parseFloat(row.find('.plan-eff').text()) || 0,
                        target_100: parseFloat(row.find('.target-100').text()) || 0,
                        day_total: parseFloat(row.find('.day-total').text()) || 0,
                        ern_minutes: parseFloat(row.find('.ern-minutes').text()) || 0,
                        acvd_eff: parseFloat(row.find('.acvd-eff').text()) || 0,
                        epm: parseFloat(row.find('input[data-field="epm"]').val()) || 13.2,
                        style_epm: parseFloat(row.find('input[data-field="style_epm"]').val()) || 13.2,
                        profit: parseFloat(row.find('.profit-value').text()) || 0
                    };
                    
                    for (var h = 1; h <= 11; h++) {
                        rowData['hour_' + h] = parseFloat(row.find('input[data-hour="' + h + '"]').val()) || 0;
                    }
                    
                    if (rowIsAssembly) {
                        assemblyRows.push(rowData);
                    } else {
                        componentRows.push(rowData);
                    }
                }
            });
            
            if (componentRows.length > 0 && !isAssembly) {
                var matchOutRow = $('#matchOutRow');
                if (matchOutRow.length > 0) {
                    var mo = calculateMatchOut(componentRows, hours);
                    updateMatchOutRowDisplay(matchOutRow, mo, hours);
                    updateDHURow(componentRows, hours);
                    updateTotalRow(componentRows, hours);
                }
            }
            
            if (assemblyRows.length > 0 && isAssembly) {
                var lt = calculateLeanTotal(assemblyRows, hours);
                updateLeanTotalRow(lt, hours);
                var gt = calculateGrandTotal(assemblyRows, null, hours);
                updateGrandTotalRow(gt, hours);
            }
        }

        function calculateMatchOut(rows, hours) {
            var result = { unitSmv: 0, unitCarder: 0, planHours: 0, workedHours: 0, dayForecast: 0, availableMinutes: 0, planMinutes: 0, planEff: 0, target100: 0, hours: {}, dayTotal: 0, ernMinutes: 0, acvdEff: 0 };
            var count = 0, hourSums = {}, planHoursSum = 0, workedHoursSum = 0;
            for (var h = 1; h <= hours; h++) hourSums[h] = 0;
            
            rows.forEach(function(r) {
                if (r.unit_smv > 0) {
                    count++;
                    result.unitSmv += r.unit_smv;
                    result.unitCarder += r.unit_carder;
                    planHoursSum += r.plan_hours;
                    workedHoursSum += r.worked_hours;
                    for (var h = 1; h <= hours; h++) {
                        hourSums[h] += r["hour_" + h] || 0;
                    }
                }
            });
            
            if (count > 0) {
                result.planHours = planHoursSum / count;
                result.workedHours = workedHoursSum / count;
                if (result.unitSmv > 0 && result.unitCarder > 0) {
                    result.dayForecast = (result.unitCarder * 600 / result.unitSmv) * 0.90;
                }
                result.availableMinutes = result.unitCarder * result.planHours * 60;
                result.planMinutes = result.dayForecast * result.unitSmv;
                result.planEff = result.availableMinutes > 0 ? (result.planMinutes / result.availableMinutes) : 0;
                result.target100 = result.unitSmv > 0 ? (result.unitCarder / result.unitSmv) * 60 : 0;
                for (var h = 1; h <= hours; h++) {
                    result.hours[h] = Math.round(hourSums[h] / count);
                }
                result.dayTotal = 0;
                for (var h = 1; h <= hours; h++) {
                    result.dayTotal += result.hours[h] || 0;
                }
                result.ernMinutes = result.dayTotal * result.unitSmv;
                var denominator = (result.availableMinutes > 0 && result.planHours > 0) 
                    ? (result.availableMinutes / result.planHours) * result.workedHours : 1;
                result.acvdEff = denominator > 0 ? (result.ernMinutes / denominator) : 0;
            }
            return result;
        }

        function updateMatchOutRowDisplay(row, data, hours) {
            row.find('#mo-unit-smv').text(data.unitSmv.toFixed(2));
            row.find('#mo-unit-carder').text(Math.round(data.unitCarder));
            row.find('#mo-plan-hours').text(data.planHours.toFixed(1));
            row.find('#mo-worked-hours').text(data.workedHours.toFixed(1));
            row.find('#mo-available-minutes').text(Math.round(data.availableMinutes));
            row.find('#mo-target-100').text(Math.round(data.target100));
            
            var moProfit = sumComponentProfits();
            row.find('#mo-profit').text(rnd(moProfit));
            row.find('#mo-profit').css('color', moProfit >= 0 ? '#28a745' : '#dc3545');
        }

        function updateDHURow(rows, hours) {
            var dhuTotal = 0, dayTotal = 0;
            rows.forEach(function(r) {
                if (r.day_total > 0) {
                    dhuTotal += (r.day_total / 100) * 5;
                    dayTotal += r.day_total;
                }
            });
            var dhuAvg = dayTotal > 0 ? round((dhuTotal / dayTotal) * 100, 1) : 0;
            $('#dhu-value').text(dhuAvg.toFixed(1) + '%');
        }

        function updateTotalRow(rows, hours) {
            var totalDay = 0, totalErn = 0, totalEff = 0, count = 0;
            rows.forEach(function(r) {
                totalDay += r.day_total;
                totalErn += r.ern_minutes;
                if (r.acvd_eff > 0) { totalEff += r.acvd_eff; count++; }
            });
            
            var row = $('.total-row');
            if (row.length > 0) {
                var tds = row.find('td');
                var dayTtlIdx = tds.length - 5;
                if (tds.length > dayTtlIdx) {
                    $(tds[dayTtlIdx]).text(Math.round(totalDay));
                    $(tds[dayTtlIdx + 1]).text(rnd(totalErn));
                    var avgEff = count > 0 ? round((totalEff / count)) : 0;
                    $(tds[dayTtlIdx + 2]).text(avgEff + '%');
                }
            }
        }

        function updateLeanTotalRow(data, hours) {
            var row = $('#leanTotalRow');
            if (row.length === 0) return;
            row.find('#lt-ttl-sam').text(data.ttl_sam.toFixed(4));
            row.find('#lt-section-sam').text(data.section_sam.toFixed(3));
            row.find('#lt-assemble-carder').text(Math.round(data.assemble_carder));
            row.find('#lt-plan-hours').text(data.plan_hours.toFixed(1));
            row.find('#lt-worked-hours').text(data.worked_hours.toFixed(1));
            row.find('#lt-available-minutes').text(Math.round(data.available_minutes));
            row.find('#lt-target-100').text(Math.round(data.target_100));
            for (var h = 1; h <= hours; h++) {
                row.find('#lt-hour-' + h).text(Math.round(data.hours[h] || 0));
            }
            row.find('#lt-day-total').text(Math.round(data.day_total));
            row.find('#lt-ern-minutes').text(rnd(data.ern_minutes));
            row.find('#lt-acvd-eff').text(rnd(data.acvd_eff * 100) + '%');
            
            var ltProfit = (500 * data.day_total) - (7365 * (data.assemble_carder + SHIRT_MATCH_OUT_CARDER));
            row.find('#lt-profit').text(rnd(ltProfit));
            row.find('#lt-profit').css('color', ltProfit >= 0 ? '#28a745' : '#dc3545');
            
            var dhuDayTotal = 0;
            $('.excel-table tbody tr').each(function() {
                if (!$(this).hasClass('match-out-row') && !$(this).hasClass('lean-total-row') && 
                    !$(this).hasClass('grand-total-row') && !$(this).hasClass('total-row') && 
                    !$(this).hasClass('dhu-row') && !$(this).hasClass('assembly-dhu-row') &&
                    !$(this).hasClass('section-divider') &&
                    $(this).data('isassembly') == '1') {
                    var dayTotal = parseFloat($(this).find('.day-total').text()) || 0;
                    if (dayTotal > 0) {
                        dhuDayTotal += (dayTotal / 100) * 5;
                    }
                }
            });
            var leanDhu = data.day_total > 0 ? round((dhuDayTotal / data.day_total) * 100, 1) : 0;
            $('#lt-dhu-value').text(leanDhu.toFixed(1) + '%');
        }

        function updateGrandTotalRow(data, hours) {
            var row = $('#grandTotalRow');
            if (row.length === 0) return;
            row.find('#gt-ttl-sam').text(data.ttl_sam.toFixed(4));
            row.find('#gt-section-sam').text(data.section_sam.toFixed(3));
            row.find('#gt-assemble-carder').text(Math.round(data.assemble_carder));
            row.find('#gt-plan-hours').text(data.plan_hours.toFixed(1));
            row.find('#gt-worked-hours').text(data.worked_hours.toFixed(1));
            row.find('#gt-available-minutes').text(Math.round(data.available_minutes));
            row.find('#gt-target-100').text(Math.round(data.target_100));
            for (var h = 1; h <= hours; h++) {
                row.find('#gt-hour-' + h).text(data.hours[h].toFixed(4));
            }
            row.find('#gt-day-total').text(Math.round(data.day_total));
            row.find('#gt-ern-minutes').text(rnd(data.ern_minutes));
            row.find('#gt-acvd-eff').text(rnd(data.acvd_eff * 100) + '%');
            
            var gtProfit = (500 * data.day_total) - (7365 * data.assemble_carder);
            row.find('#gt-profit').text(rnd(gtProfit));
            row.find('#gt-profit').css('color', gtProfit >= 0 ? '#28a745' : '#dc3545');
        }

        function round(num, decimals) {
            return Math.round(num * Math.pow(10, decimals)) / Math.pow(10, decimals);
        }

        $(document).on('input change', '.field-input, .hour-input', function() {
            var row = $(this).closest('tr');
            if (!row.hasClass('lean-total-row') && !row.hasClass('grand-total-row') && 
                !row.hasClass('total-row') && !row.hasClass('dhu-row') && 
                !row.hasClass('assembly-dhu-row') && !row.hasClass('section-divider')) {
                recalcRow(row);
            }
        });

        $(document).on('change', '#workHours', function() {
            $('.excel-table tbody tr').each(function() {
                if (!$(this).hasClass('lean-total-row') && !$(this).hasClass('grand-total-row') && 
                    !$(this).hasClass('total-row') && !$(this).hasClass('dhu-row') && 
                    !$(this).hasClass('assembly-dhu-row') && !$(this).hasClass('section-divider')) {
                    recalcRow($(this));
                }
            });
            setTimeout(function() { updateSummaryRows(); }, 200);
        });

        function showNotification(message, type) {
            var colors = { success: '#d4edda', info: '#cce5ff', error: '#f8d7da' };
            var textColors = { success: '#155724', info: '#004085', error: '#721c24' };
            $('.custom-notification').remove();
            var notification = $('<div class="custom-notification">')
                .css({
                    position: 'fixed', top: '20px', right: '20px',
                    padding: '12px 24px', background: colors[type] || '#fff',
                    color: textColors[type] || '#333',
                    border: '1px solid ' + (colors[type] || '#ddd'),
                    borderRadius: '8px', zIndex: 9999,
                    boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                    fontFamily: 'Inter, sans-serif', fontSize: '14px', fontWeight: '600',
                    maxWidth: '350px'
                })
                .html(message).appendTo('body');
            setTimeout(function() { 
                notification.fadeOut(500, function() { $(this).remove(); }); 
            }, 2000);
        }

        function saveAll() {
            showNotification('Saving all data...', 'info');
            var rows = $('.excel-table tbody tr');
            var promises = [], savedCount = 0, totalRows = 0;
            
            rows.each(function() {
                var row = $(this);
                if (row.hasClass('lean-total-row') || row.hasClass('grand-total-row') || 
                    row.hasClass('total-row') || row.hasClass('dhu-row') || 
                    row.hasClass('assembly-dhu-row') || row.hasClass('section-divider')) {
                    return;
                }
                totalRows++;
                var component = row.data('component');
                if (!component || component <= 0) return;
                var date = $('#reportDate').val();
                var hours = $('#workHours').val();
                var division = <?php echo $division_id; ?>;
                var isAssembly = row.data('isassembly') == '1';
                var data = {};
                
                if (row.hasClass('match-out-row')) {
                    data.ttl_sam_pc = parseFloat(row.find('#mo-ttl-sam').text()) || 0;
                    data.unit_smv = parseFloat(row.find('#mo-unit-smv').text()) || 0;
                    data.unit_carder = parseFloat(row.find('#mo-unit-carder').text()) || 0;
                    data.plan_hours = parseFloat(row.find('#mo-plan-hours').text()) || 0;
                    data.worked_hours = parseFloat(row.find('#mo-worked-hours').text()) || hours;
                    data.epm = parseFloat(row.find('#mo-epm').val()) || 13.2;
                    data.profit = parseFloat(row.find('#mo-profit').text()) || 0;
                    for (var h = 1; h <= 11; h++) {
                        data['hour_' + h] = parseFloat(row.find('.mo-hour-input[data-hour="' + h + '"]').val()) || 0;
                    }
                } else {
                    data.ttl_sam_pc = parseFloat(row.find('input[data-field="ttl_sam_pc"]').val()) || 0;
                    data.unit_smv = parseFloat(row.find('input[data-field="unit_smv"]').val()) || 0;
                    data.unit_carder = parseFloat(row.find('input[data-field="unit_carder"]').val()) || 0;
                    data.plan_hours = parseFloat(row.find('input[data-field="plan_hours"]').val()) || 0;
                    data.worked_hours = parseFloat(row.find('input[data-field="worked_hours"]').val()) || hours;
                    data.epm = parseFloat(row.find('input[data-field="epm"]').val()) || 13.2;
                    data.style_epm = parseFloat(row.find('input[data-field="style_epm"]').val()) || 13.2;
                    data.profit = parseFloat(row.find('.profit-value').text()) || 0;
                    for (var h = 1; h <= 11; h++) {
                        data['hour_' + h] = parseFloat(row.find('input[data-hour="' + h + '"]').val()) || 0;
                    }
                }
                
                var promise = $.ajax({
                    url: 'save_data.php',
                    type: 'POST',
                    data: {
                        action: 'auto_save',
                        date: date,
                        division: division,
                        component: component,
                        data: JSON.stringify(data),
                        work_hours: hours,
                        is_assembly: isAssembly ? '1' : '0'
                    },
                    dataType: 'json',
                    success: function(response) { if (response.success) savedCount++; }
                });
                promises.push(promise);
            });
            
            if (totalRows === 0) { showNotification('No data to save.', 'info'); return; }
            
            $.when.apply($, promises).done(function() {
                if (savedCount === totalRows) {
                    showNotification('All ' + totalRows + ' rows saved successfully!', 'success');
                } else {
                    showNotification('Saved ' + savedCount + ' of ' + totalRows + ' rows.', 'info');
                }
            }).fail(function() {
                showNotification('Some data failed to save. Please try again.', 'error');
            });
        }

        $(document).on('keydown', 'input', function(e) {
            if (e.key === 'Enter') {
                var inputs = $(this).closest('tr').find('input');
                var index = inputs.index(this);
                if (index < inputs.length - 1) { 
                    inputs.eq(index + 1).focus().select(); 
                }
                e.preventDefault();
            }
        });

        // ============================================================
        // FLUSH PENDING SAVES HELPER
        // ============================================================
        function flushAllSaves() {
            $('.excel-table tbody tr').each(function() {
                var r = $(this);
                if (r.hasClass('lean-total-row') || r.hasClass('grand-total-row') || 
                    r.hasClass('total-row') || r.hasClass('dhu-row') || 
                    r.hasClass('assembly-dhu-row') || r.hasClass('section-divider')) {
                    return;
                }
                var compId = r.data('component');
                if (!compId || compId <= 0) return;
                
                var date = $('#reportDate').val();
                var hours = $('#workHours').val();
                var division = <?php echo $division_id; ?>;
                var isAssembly = r.data('isassembly') == '1';
                var data = {};
                
                if (r.hasClass('match-out-row')) {
                    data.ttl_sam_pc = parseFloat(r.find('#mo-ttl-sam').text()) || 0;
                    data.unit_smv = parseFloat(r.find('#mo-unit-smv').text()) || 0;
                    data.unit_carder = parseFloat(r.find('#mo-unit-carder').text()) || 0;
                    data.plan_hours = parseFloat(r.find('#mo-plan-hours').text()) || 0;
                    data.worked_hours = parseFloat(r.find('#mo-worked-hours').text()) || hours;
                    data.epm = parseFloat(r.find('#mo-epm').val()) || 13.2;
                    data.profit = parseFloat(r.find('#mo-profit').text()) || 0;
                    for (var h = 1; h <= 11; h++) {
                        data['hour_' + h] = parseFloat(r.find('.mo-hour-input[data-hour="' + h + '"]').val()) || 0;
                    }
                } else {
                    data.ttl_sam_pc = parseFloat(r.find('input[data-field="ttl_sam_pc"]').val()) || 0;
                    data.unit_smv = parseFloat(r.find('input[data-field="unit_smv"]').val()) || 0;
                    data.unit_carder = parseFloat(r.find('input[data-field="unit_carder"]').val()) || 0;
                    data.plan_hours = parseFloat(r.find('input[data-field="plan_hours"]').val()) || 0;
                    data.worked_hours = parseFloat(r.find('input[data-field="worked_hours"]').val()) || hours;
                    data.epm = parseFloat(r.find('input[data-field="epm"]').val()) || 13.2;
                    data.style_epm = parseFloat(r.find('input[data-field="style_epm"]').val()) || 13.2;
                    data.profit = parseFloat(r.find('.profit-value').text()) || 0;
                    for (var h = 1; h <= 11; h++) {
                        data['hour_' + h] = parseFloat(r.find('input[data-hour="' + h + '"]').val()) || 0;
                    }
                }
                
                var formData = new FormData();
                formData.append('action', 'auto_save');
                formData.append('date', date);
                formData.append('division', division);
                formData.append('component', compId);
                formData.append('data', JSON.stringify(data));
                formData.append('work_hours', hours);
                formData.append('is_assembly', isAssembly ? '1' : '0');
                
                if (navigator.sendBeacon) {
                    navigator.sendBeacon('save_data.php', formData);
                } else {
                    $.ajax({
                        url: 'save_data.php',
                        type: 'POST',
                        data: {
                            action: 'auto_save',
                            date: date,
                            division: division,
                            component: compId,
                            data: JSON.stringify(data),
                            work_hours: hours,
                            is_assembly: isAssembly ? '1' : '0'
                        },
                        async: false
                    });
                }
            });
        }

        $(document).ready(function() {
            setTimeout(function() {
                $('.excel-table tbody tr').each(function() {
                    if (!$(this).hasClass('lean-total-row') && !$(this).hasClass('grand-total-row') && 
                        !$(this).hasClass('total-row') && !$(this).hasClass('dhu-row') && 
                        !$(this).hasClass('assembly-dhu-row') && !$(this).hasClass('section-divider')) {
                        recalcRow($(this));
                    }
                });
                setTimeout(function() { updateSummaryRows(); }, 1000);
            }, 500);

            // ============================================================
            // FORCE Match Out profit = sum of component profits (on page load)
            // Now uses the fixed sumComponentProfits() that excludes assembly rows
            // ============================================================
            setTimeout(function() {
                var matchOutRow = $('#matchOutRow');
                if (matchOutRow.length > 0) {
                    var moProfit = sumComponentProfits();
                    matchOutRow.find('#mo-profit').text(Math.round(moProfit));
                    matchOutRow.find('#mo-profit').css('color', moProfit >= 0 ? '#28a745' : '#dc3545');
                }
            }, 800);

            // ============================================================
            // FLUSH PENDING SAVES BEFORE LEAVING THE PAGE
            // ============================================================
            $(window).on('beforeunload', function() {
                // Cancel all scheduled saves and fire them immediately
                for (var key in saveTimeouts) {
                    if (saveTimeouts.hasOwnProperty(key)) {
                        clearTimeout(saveTimeouts[key]);
                    }
                }
                // Trigger a final save of all rows
                flushAllSaves();
            });

            // ============================================================
            // Save when user switches tabs or minimizes (mobile-friendly)
            // ============================================================
            document.addEventListener('visibilitychange', function() {
                if (document.visibilityState === 'hidden') {
                    // Trigger a silent save of all rows
                    flushAllSaves();
                }
            });
        });
    </script>
</body>
</html>