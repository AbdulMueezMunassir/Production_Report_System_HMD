<?php
// analytics_kiosk.php - Kiosk Mode Analytics (Continuous scroll, stacked screens)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$conn = getDB();
$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$selected_date = date('Y-m-d');
$selected_division = isset($_GET['division']) ? (int)$_GET['division'] : 1;
$trend_days = 30;

$valid_divisions = [1, 2, 3, 7];
if (!in_array($selected_division, $valid_divisions)) {
    $selected_division = 1;
}

$division_names = [1 => 'Shirt', 2 => 'Trouser', 3 => 'Coat', 7 => 'Assembly'];
$division_icons = [1 => '👔', 2 => '👖', 3 => '🧥', 7 => '🏭'];

// AUTO-DETECT WORK HOURS
$work_hours = 10;
try {
    $stmt_mh = $conn->prepare("
        SELECT MAX(CASE 
            WHEN hour_11 > 0 THEN 11 WHEN hour_10 > 0 THEN 10
            WHEN hour_9 > 0 THEN 9 WHEN hour_8 > 0 THEN 8
            WHEN hour_7 > 0 THEN 7 WHEN hour_6 > 0 THEN 6
            WHEN hour_5 > 0 THEN 5 WHEN hour_4 > 0 THEN 4
            WHEN hour_3 > 0 THEN 3 WHEN hour_2 > 0 THEN 2
            WHEN hour_1 > 0 THEN 1 ELSE 0 END) as max_h
        FROM production_reports 
        WHERE devition_id = ? AND report_date = ?
        AND unit_id NOT IN (996, 997, 998, 999)
    ");
    $stmt_mh->execute([$selected_division, $selected_date]);
    $r = $stmt_mh->fetch(PDO::FETCH_ASSOC);
    if ($r && $r['max_h'] > 0) $work_hours = (int)$r['max_h'];
} catch (Exception $e) {}
$work_hours = max(1, min(11, $work_hours));

// MATCH OUT
$shirt_match_out_carder = 0;
$trouser_match_out_carder = 0;
try {
    $shirt_components = getComponents($conn, 1);
    $mo1 = calculateMatchOutFixed($conn, 1, $selected_date, $work_hours, $shirt_components);
    $shirt_match_out_carder = (int)($mo1['unit_carder'] ?? 0);
} catch (Exception $e) {}
try {
    $trouser_components = getComponents($conn, 2);
    $mo2 = calculateMatchOutFixed($conn, 2, $selected_date, $work_hours, $trouser_components);
    $trouser_match_out_carder = (int)($mo2['unit_carder'] ?? 0);
} catch (Exception $e) {}

// HELPERS
function computeProfit($data, $division_type, $comp_name = '', $total_assemble_carder = null) {
    $day_total = (float)($data['day_total'] ?? 0);
    $unit_carder = (int)($data['unit_carder'] ?? 0);
    $unit_smv = (float)($data['unit_smv'] ?? 0);
    $plan_hours = (float)($data['plan_hours'] ?? 0);
    $worked_hours = (float)($data['worked_hours'] ?? 0);
    $epm = (float)($data['epm'] ?? 13.2);
    $cu = strtoupper(trim($comp_name));
    if ($division_type === 'assembly') {
        $tac = $total_assemble_carder !== null ? $total_assemble_carder : $unit_carder;
        $mult = 500;
        if ($cu === 'SHIRT MTM') $mult = 1100;
        elseif ($cu === 'TROUSER') $mult = 800;
        elseif ($cu === 'TROUSER MTM') $mult = 1500;
        elseif ($cu === 'COAT') $mult = 2800;
        elseif ($cu === 'COAT MTM') $mult = 3400;
        elseif ($cu === 'KNIT') $mult = 295;
        return ($mult * $day_total) - (7365 * $tac);
    } else {
        if ($plan_hours > 0) return ($epm * ($day_total * $unit_smv)) - (7365 * $unit_carder) * ($worked_hours / $plan_hours);
        return ($epm * ($day_total * $unit_smv)) - (7365 * $unit_carder);
    }
}

function getComponentByName($conn, $division_id, $date, $component_name) {
    $components = getComponents($conn, $division_id);
    foreach ($components as $comp) {
        if ($comp['is_match_out']) continue;
        if (strtoupper(trim($comp['name'])) === strtoupper(trim($component_name))) {
            $data = getReportData($conn, $division_id, $comp['id'], $date);
            for ($h = 1; $h <= 11; $h++) $data["hour_$h"] = (float)($data["hour_$h"] ?? 0);
            return $data;
        }
    }
    return null;
}

function getMatchOutData($conn, $division_id, $date) {
    $mo = getReportData($conn, $division_id, 999, $date);
    for ($h = 1; $h <= 11; $h++) $mo["hour_$h"] = (float)($mo["hour_$h"] ?? 0);
    return $mo;
}

function computeDHU($data) {
    $dt = (float)($data['day_total'] ?? 0);
    return $dt > 0 ? round(($dt / 100) * 5, 1) : 0;
}

function buildColumn($name, $data, $division_type, $total_assemble_carder = null, $work_hours = 10) {
    $hours = [];
    for ($h = 1; $h <= $work_hours; $h++) $hours[] = (float)($data["hour_$h"] ?? 0);
    $unit_carder = (int)($data['unit_carder'] ?? 0);
    $unit_smv = (float)($data['unit_smv'] ?? 0);
    $plan_hours = (float)($data['plan_hours'] ?? 0);
    $worked_hours = (float)($data['worked_hours'] ?? 0);
    $ttl_sam_pc = (float)($data['ttl_sam_pc'] ?? 0);
    $day_total = 0;
    for ($h = 1; $h <= $work_hours; $h++) $day_total += (float)($data["hour_$h"] ?? 0);
    $available_minutes = 0; $acvd_eff = 0;
    if ($division_type === 'assembly') {
        $tac = $total_assemble_carder !== null ? $total_assemble_carder : $unit_carder;
        $available_minutes = $tac * $plan_hours * 60;
        $ern_minutes = $day_total * $ttl_sam_pc;
        if ($available_minutes > 0 && $plan_hours > 0) {
            $denom = $available_minutes * ($worked_hours / $plan_hours);
            $acvd_eff = $denom > 0 ? ($ern_minutes / $denom) : 0;
        }
    } else {
        $available_minutes = $unit_carder * $plan_hours * 60;
        $ern_minutes = $day_total * $unit_smv;
        $denom = 1;
        if ($available_minutes > 0 && $plan_hours > 0) $denom = ($available_minutes / $plan_hours) * $worked_hours;
        $acvd_eff = $denom > 0 ? ($ern_minutes / $denom) : 0;
    }
    return [
        'name' => $name, 'pcs' => $day_total, 'eff' => $acvd_eff * 100,
        'carder' => $unit_carder, 'dhu' => computeDHU($data),
        'hours' => $hours, 'profit' => computeProfit($data, $division_type, $name, $total_assemble_carder),
        'type' => ($division_type === 'assembly') ? 'assembly' : 'component'
    ];
}

function emptyColumn($name, $type = 'component', $work_hours = 10) {
    return ['name'=>$name,'pcs'=>0,'eff'=>0,'carder'=>0,'dhu'=>0,'hours'=>array_fill(0,$work_hours,0),'profit'=>0,'type'=>$type];
}

// BUILD TABLES
$shirt_main = []; $shirt_mtm = [];
$trouser_main = []; $trouser_mtm = [];
$coat_main = []; $coat_mtm = [];
$assembly_main = [];

foreach (['Front', 'Back', 'Collar', 'Sleeve', 'Cuff'] as $name) {
    $cd = getComponentByName($conn, 1, $selected_date, $name);
    $shirt_main[] = $cd ? buildColumn($name, $cd, 'shirt', null, $work_hours) : emptyColumn($name, 'component', $work_hours);
}
$mo_shirt = getMatchOutData($conn, 1, $selected_date);
$mo_col = buildColumn('Match Out', $mo_shirt, 'shirt', null, $work_hours);
$mo_col['type'] = 'match_out'; $mo_col['profit'] = 0;
foreach ($shirt_main as $c) if ($c['type']==='component') $mo_col['profit'] += $c['profit'];
$shirt_main[] = $mo_col;
$asm_shirt = getComponentByName($conn, 7, $selected_date, 'SHIRT');
if ($asm_shirt) {
    $tac = ((int)($asm_shirt['unit_carder'] ?? 0)) + $shirt_match_out_carder;
    $c = buildColumn('Assembly SHIRT', $asm_shirt, 'assembly', $tac, $work_hours);
    $c['type'] = 'assembly'; $shirt_main[] = $c;
} else $shirt_main[] = emptyColumn('Assembly SHIRT', 'assembly', $work_hours);

$shirt_mtm_comp = getComponentByName($conn, 7, $selected_date, 'SHIRT MTM');
if ($shirt_mtm_comp) { $c = buildColumn('SHIRT MTM', $shirt_mtm_comp, 'assembly', null, $work_hours); $c['type']='assembly'; $shirt_mtm[] = $c; }
else $shirt_mtm[] = emptyColumn('SHIRT MTM', 'assembly', $work_hours);

foreach (['Front', 'Back', 'Band'] as $name) {
    $cd = getComponentByName($conn, 2, $selected_date, $name);
    $trouser_main[] = $cd ? buildColumn($name, $cd, 'trouser', null, $work_hours) : emptyColumn($name, 'component', $work_hours);
}
$mo_tr = getMatchOutData($conn, 2, $selected_date);
$mo_tr_col = buildColumn('Match Out', $mo_tr, 'trouser', null, $work_hours);
$mo_tr_col['type'] = 'match_out'; $mo_tr_col['profit'] = 0;
foreach ($trouser_main as $c) if ($c['type']==='component') $mo_tr_col['profit'] += $c['profit'];
$trouser_main[] = $mo_tr_col;
$asm_tr = getComponentByName($conn, 7, $selected_date, 'TROUSER');
if ($asm_tr) {
    $tac = ((int)($asm_tr['unit_carder'] ?? 0)) + $trouser_match_out_carder;
    $c = buildColumn('Assembly TROUSER', $asm_tr, 'assembly', $tac, $work_hours);
    $c['type'] = 'assembly'; $trouser_main[] = $c;
} else $trouser_main[] = emptyColumn('Assembly TROUSER', 'assembly', $work_hours);

$trouser_mtm_comp = getComponentByName($conn, 7, $selected_date, 'TROUSER MTM');
if ($trouser_mtm_comp) { $c = buildColumn('TROUSER MTM', $trouser_mtm_comp, 'assembly', null, $work_hours); $c['type']='assembly'; $trouser_mtm[] = $c; }
else $trouser_mtm[] = emptyColumn('TROUSER MTM', 'assembly', $work_hours);

$coat_comp = getComponentByName($conn, 7, $selected_date, 'COAT');
$coat_main[] = $coat_comp ? buildColumn('COAT', $coat_comp, 'assembly', null, $work_hours) : emptyColumn('COAT', 'assembly', $work_hours);
$coat_mtm_comp = getComponentByName($conn, 7, $selected_date, 'COAT MTM');
$coat_mtm[] = $coat_mtm_comp ? buildColumn('COAT MTM', $coat_mtm_comp, 'assembly', null, $work_hours) : emptyColumn('COAT MTM', 'assembly', $work_hours);

foreach (['SHIRT', 'SHIRT MTM', 'TROUSER', 'TROUSER MTM', 'COAT', 'COAT MTM', 'KNIT'] as $name) {
    $cd = getComponentByName($conn, 7, $selected_date, $name);
    if ($cd) {
        $tac = (int)($cd['unit_carder'] ?? 0);
        if ($name === 'SHIRT') $tac += $shirt_match_out_carder;
        elseif ($name === 'TROUSER') $tac += $trouser_match_out_carder;
        $assembly_main[] = buildColumn($name, $cd, 'assembly', $tac, $work_hours);
    } else $assembly_main[] = emptyColumn($name, 'component', $work_hours);
}

function computeCharts($columns, $work_hours) {
    $production = array_fill(1, $work_hours, 0);
    $dhu = array_fill(1, $work_hours, 0);
    $col_count = 0; $total_pcs_for_dhu = 0;
    foreach ($columns as $col) {
        if ($col['pcs'] > 0 || $col['carder'] > 0) {
            $col_count++;
            for ($h = 0; $h < $work_hours; $h++) $production[$h + 1] += $col['hours'][$h] ?? 0;
        }
        if ($col['pcs'] > 0) {
            $total_pcs_for_dhu += $col['pcs'];
            for ($h = 0; $h < $work_hours; $h++) $dhu[$h + 1] += (($col['hours'][$h] ?? 0) / 100) * 5;
        }
    }
    if ($col_count > 0) for ($h = 1; $h <= $work_hours; $h++) $production[$h] = round($production[$h] / $col_count, 0);
    if ($total_pcs_for_dhu > 0) for ($h = 1; $h <= $work_hours; $h++) $dhu[$h] = round(($dhu[$h] / $total_pcs_for_dhu) * 100, 1);
    return ['production' => array_values($production), 'dhu' => array_values($dhu)];
}

function computeStats($columns) {
    $total_pcs = 0; $eff_sum = 0; $eff_count = 0; $dhu_sum = 0; $dhu_count = 0;
    foreach ($columns as $col) {
        $total_pcs += $col['pcs'];
        if ($col['eff'] > 0) { $eff_sum += $col['eff']; $eff_count++; }
        if ($col['dhu'] > 0) { $dhu_sum += $col['dhu']; $dhu_count++; }
    }
    return [
        'total_pcs' => $total_pcs,
        'avg_eff' => $eff_count > 0 ? round($eff_sum / $eff_count, 1) : 0,
        'avg_dhu' => $dhu_count > 0 ? round($dhu_sum / $dhu_count, 1) : 0
    ];
}

$charts_shirt_main = computeCharts($shirt_main, $work_hours);
$charts_shirt_mtm = computeCharts($shirt_mtm, $work_hours);
$charts_trouser_main = computeCharts($trouser_main, $work_hours);
$charts_trouser_mtm = computeCharts($trouser_mtm, $work_hours);
$charts_coat_main = computeCharts($coat_main, $work_hours);
$charts_coat_mtm = computeCharts($coat_mtm, $work_hours);

$stats_shirt_main = computeStats($shirt_main);
$stats_shirt_mtm = computeStats($shirt_mtm);
$stats_trouser_main = computeStats($trouser_main);
$stats_trouser_mtm = computeStats($trouser_mtm);
$stats_coat_main = computeStats($coat_main);
$stats_coat_mtm = computeStats($coat_mtm);
$stats_assembly = computeStats($assembly_main);

$display_date = date('M d, Y');
$chart_labels = range(1, $work_hours);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kiosk Analytics - Hameedia</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #217346; --primary-dark: #1a5c3a;
            --text: #1a2332; --text-dark: #0d1a2b; --steel: #6b7a8f;
            --border-radius: 12px;
            --shadow: 0 4px 20px rgba(0,0,0,0.08);
            --glass-border: rgba(255,255,255,0.3);
            --glass-bg: rgba(255,255,255,0.15);
            --good: #28a745; --bad: #dc3545; --warning: #ffc107; --amber: #f57c00;
            --dhu-color: #e74c3c;
        }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 50%, #a5d6a7 100%); min-height: 100vh; color: var(--text); }
        
        .kiosk-header {
            position: fixed; top: 0; left: 0; right: 0; z-index: 100;
            background: rgba(255,255,255,0.9); backdrop-filter: blur(20px);
            padding: 12px 24px; display: flex; justify-content: space-between;
            align-items: center; border-bottom: 1px solid var(--glass-border);
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }
        .kiosk-header .logo { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 18px; color: var(--primary-dark); }
        .kiosk-header .logo a { display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit; }
        .kiosk-header .logo-icon { width: 32px; height: 32px; background: var(--primary); color: #fff; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: 700; }
        .kiosk-nav { display: flex; gap: 8px; align-items: center; }
        .kiosk-nav button {
            padding: 8px 20px; border: 2px solid var(--glass-border); border-radius: 10px;
            background: rgba(255,255,255,0.6); color: var(--text-dark); font-weight: 700;
            font-size: 14px; cursor: pointer; transition: all 0.3s; font-family: 'Inter', sans-serif;
            display: flex; align-items: center; gap: 6px;
        }
        .kiosk-nav button:hover { background: rgba(255,255,255,0.9); }
        .kiosk-nav button.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .kiosk-nav .back-link {
            margin-left: 12px;
            padding: 8px 20px;
            border: 2px solid var(--glass-border);
            border-radius: 10px;
            background: rgba(255,255,255,0.6);
            color: var(--text-dark);
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s;
        }
        .kiosk-nav .back-link:hover { background: rgba(255,255,255,0.9); }
        .kiosk-clock { font-size: 14px; font-weight: 600; color: var(--text-dark); display: flex; align-items: center; gap: 8px; }
        .live-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--good); animation: blink 1.5s infinite; }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

        .kiosk-content { padding-top: 80px; padding-bottom: 60px; }
        
        .screen-section {
            padding: 20px 30px;
            margin-bottom: 30px;
            page-break-inside: avoid;
        }
        
        .screen-title {
            font-size: 22px; font-weight: 800; color: var(--text-dark);
            margin-bottom: 16px; padding: 12px 20px; background: rgba(255,255,255,0.4);
            border-radius: 12px; border-left: 6px solid var(--primary);
        }
        
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
        .stat-card {
            background: var(--glass-bg); backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border); border-radius: var(--border-radius);
            padding: 16px; box-shadow: var(--shadow); text-align: center;
        }
        .stat-card .number { font-size: 28px; font-weight: 900; color: var(--primary); }
        .stat-card .number.dhu { color: var(--dhu-color); }
        .stat-card .number.eff { color: var(--amber); }
        .stat-card .label { font-size: 13px; font-weight: 600; color: var(--steel); margin-top: 4px; }

        .dashboard-table {
            width: 100%; border-collapse: collapse; font-size: 12px;
            background: var(--glass-bg); backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border); border-radius: var(--border-radius);
            overflow: hidden; box-shadow: var(--shadow); margin-bottom: 20px;
        }
        .dashboard-table th { background: rgba(255,255,255,0.3); padding: 8px 10px; text-align: center; font-weight: 700; color: var(--text-dark); border: 1px solid var(--glass-border); font-size: 11px; }
        .dashboard-table td { padding: 6px 10px; text-align: center; border: 1px solid var(--glass-border); font-weight: 500; font-size: 11px; }
        .dashboard-table .header-row td { background: rgba(33,115,70,0.15); font-weight: 700; font-size: 12px; }
        .dashboard-table .total-row td { background: rgba(33,150,243,0.08); font-weight: 700; }
        .dashboard-table .loss-row td { background: rgba(220,53,69,0.08); font-weight: 700; }
        .dashboard-table .dhu-col { background: rgba(220,53,69,0.12) !important; color: var(--dhu-color); }
        .dashboard-table .match-col { background: rgba(108,117,125,0.1) !important; }
        .dashboard-table .assembly-col { background: rgba(23,162,184,0.08) !important; }
        .eff-good { color: var(--good); font-weight: 700; }
        .eff-bad { color: var(--bad); font-weight: 700; }
        .eff-avg { color: var(--warning); font-weight: 700; }

        .chart-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .chart-card {
            background: var(--glass-bg); backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border); border-radius: var(--border-radius);
            padding: 16px; box-shadow: var(--shadow);
        }
        .chart-card h4 { font-size: 14px; font-weight: 700; color: var(--text-dark); margin-bottom: 10px; text-align: center; padding-bottom: 6px; border-bottom: 2px solid var(--primary); }
        .chart-card .chart-wrapper { position: relative; height: 220px; }
        .chart-card .chart-wrapper canvas { width: 100% !important; height: 100% !important; }
        .chart-full { grid-column: 1 / -1; }
        .chart-full .chart-wrapper { height: 250px; }

        @media (max-width: 1024px) {
            .chart-grid { grid-template-columns: 1fr; }
            .chart-full { grid-column: 1; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="kiosk-header">
        <div class="logo">
            <span class="logo-icon">H</span>
            <span>HAMEEDIA — Analytics</span>
            <a href="dashboard.php" style="margin-left:16px;padding:6px 16px;border:2px solid var(--glass-border);border-radius:10px;background:rgba(255,255,255,0.6);color:var(--text-dark);font-weight:700;font-size:13px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">← Back</a>
        </div>
        <div class="kiosk-nav">
            <button class="<?php echo $selected_division == 1 ? 'active' : ''; ?>" onclick="switchDivision(1)">👔 Shirt</button>
            <button class="<?php echo $selected_division == 2 ? 'active' : ''; ?>" onclick="switchDivision(2)">👖 Trouser</button>
            <button class="<?php echo $selected_division == 3 ? 'active' : ''; ?>" onclick="switchDivision(3)">🧥 Coat</button>
            <button class="<?php echo $selected_division == 7 ? 'active' : ''; ?>" onclick="switchDivision(7)">🏭 Assembly</button>
        </div>
        <div class="kiosk-clock">
            <span class="live-dot"></span>
            <span id="kiosk-clock"><?php echo date('H:i:s'); ?></span>
            <span>|</span>
            <span><?php echo $display_date; ?></span>
        </div>
    </div>

    <div class="kiosk-content" id="kioskContent">
        
        <?php if ($selected_division == 1): ?>
        <!-- ============================================================ -->
        <!-- SCREEN 1: SHIRT MAIN -->
        <!-- ============================================================ -->
        <div class="screen-section" id="screen-shirt-main">
            <div class="screen-title">👔 SHIRT - MAIN</div>
            
            <div class="stats-row">
                <div class="stat-card"><div class="number"><?php echo number_format($stats_shirt_main['total_pcs']); ?></div><div class="label">Total Production</div></div>
                <div class="stat-card"><div class="number eff"><?php echo $stats_shirt_main['avg_eff']; ?>%</div><div class="label">Avg Efficiency</div></div>
                <div class="stat-card"><div class="number dhu"><?php echo $stats_shirt_main['avg_dhu']; ?>%</div><div class="label">Avg DHU</div></div>
                <div class="stat-card"><div class="number"><?php echo $work_hours; ?></div><div class="label">Work Hours</div></div>
            </div>
            
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="min-width:100px;">DASH BOARD</th>
                        <?php foreach ($shirt_main as $col): 
                            $cls = '';
                            if ($col['type'] == 'match_out') $cls = 'match-col';
                            elseif ($col['type'] == 'assembly') $cls = 'assembly-col';
                        ?>
                        <th colspan="2" class="<?php echo $cls; ?>"><?php echo htmlspecialchars($col['name']); ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2" class="dhu-col">DHU %</th>
                    </tr>
                    <tr><?php foreach ($shirt_main as $col): ?><th>Pcs</th><th>Eff</th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-BUDGET</td>
                        <?php foreach ($shirt_main as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-PRESENT</td>
                        <?php foreach ($shirt_main as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">ABSENTEESM</td>
                        <?php foreach ($shirt_main as $col): ?><td colspan="2">0%</td><?php endforeach; ?><td>—</td></tr>
                    <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                    <tr><td style="font-weight:700;"><?php echo $h; ?></td>
                        <?php foreach ($shirt_main as $col): 
                            $pcs = $col['hours'][$h - 1] ?? 0;
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td><?php echo number_format($pcs, 0); ?></td>
                        <td class="<?php echo $ec; ?>"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td><?php echo number_format($charts_shirt_main['dhu'][$h - 1] ?? 0, 1); ?>%</td>
                    </tr>
                    <?php endfor; ?>
                    <tr class="total-row"><td style="font-weight:700;">Average</td>
                        <?php foreach ($shirt_main as $col): 
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td style="font-weight:700;"><?php echo number_format($col['pcs'], 0); ?></td>
                        <td class="<?php echo $ec; ?>" style="font-weight:700;"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td style="font-weight:700;color:var(--dhu-color);"><?php echo $stats_shirt_main['avg_dhu']; ?>%</td>
                    </tr>
                    <tr class="loss-row"><td style="font-weight:700;">PROFIT</td>
                        <?php foreach ($shirt_main as $col): $p = round($col['profit']); ?>
                        <td colspan="2" style="font-weight:700; color:<?php echo $p >= 0 ? '#28a745' : '#dc3545'; ?>;">LKR <?php echo number_format($p); ?></td>
                        <?php endforeach; ?><td>—</td></tr>
                </tbody>
            </table>
            
            <div class="chart-grid">
                <div class="chart-card"><h4>📈 PRODUCTION - HOURLY</h4><div class="chart-wrapper"><canvas id="c1-shirt-main"></canvas></div></div>
                <div class="chart-card"><h4>📉 D.H.U - HOURLY</h4><div class="chart-wrapper"><canvas id="c2-shirt-main"></canvas></div></div>
            </div>
        </div>
        
        <!-- ============================================================ -->
        <!-- SCREEN 2: SHIRT MTM -->
        <!-- ============================================================ -->
        <div class="screen-section" id="screen-shirt-mtm">
            <div class="screen-title">👔 SHIRT - MTM</div>
            
            <div class="stats-row">
                <div class="stat-card"><div class="number"><?php echo number_format($stats_shirt_mtm['total_pcs']); ?></div><div class="label">Total Production</div></div>
                <div class="stat-card"><div class="number eff"><?php echo $stats_shirt_mtm['avg_eff']; ?>%</div><div class="label">Avg Efficiency</div></div>
                <div class="stat-card"><div class="number dhu"><?php echo $stats_shirt_mtm['avg_dhu']; ?>%</div><div class="label">Avg DHU</div></div>
                <div class="stat-card"><div class="number"><?php echo $work_hours; ?></div><div class="label">Work Hours</div></div>
            </div>
            
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="min-width:100px;">DASH BOARD</th>
                        <?php foreach ($shirt_mtm as $col): ?>
                        <th colspan="2" class="assembly-col"><?php echo htmlspecialchars($col['name']); ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2" class="dhu-col">DHU %</th>
                    </tr>
                    <tr><?php foreach ($shirt_mtm as $col): ?><th>Pcs</th><th>Eff</th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-BUDGET</td>
                        <?php foreach ($shirt_mtm as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-PRESENT</td>
                        <?php foreach ($shirt_mtm as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">ABSENTEESM</td>
                        <?php foreach ($shirt_mtm as $col): ?><td colspan="2">0%</td><?php endforeach; ?><td>—</td></tr>
                    <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                    <tr><td style="font-weight:700;"><?php echo $h; ?></td>
                        <?php foreach ($shirt_mtm as $col): 
                            $pcs = $col['hours'][$h - 1] ?? 0;
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td><?php echo number_format($pcs, 0); ?></td>
                        <td class="<?php echo $ec; ?>"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td><?php echo number_format($charts_shirt_mtm['dhu'][$h - 1] ?? 0, 1); ?>%</td>
                    </tr>
                    <?php endfor; ?>
                    <tr class="total-row"><td style="font-weight:700;">Average</td>
                        <?php foreach ($shirt_mtm as $col): 
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td style="font-weight:700;"><?php echo number_format($col['pcs'], 0); ?></td>
                        <td class="<?php echo $ec; ?>" style="font-weight:700;"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td style="font-weight:700;color:var(--dhu-color);"><?php echo $stats_shirt_mtm['avg_dhu']; ?>%</td>
                    </tr>
                    <tr class="loss-row"><td style="font-weight:700;">PROFIT</td>
                        <?php foreach ($shirt_mtm as $col): $p = round($col['profit']); ?>
                        <td colspan="2" style="font-weight:700; color:<?php echo $p >= 0 ? '#28a745' : '#dc3545'; ?>;">LKR <?php echo number_format($p); ?></td>
                        <?php endforeach; ?><td>—</td></tr>
                </tbody>
            </table>
            
            <div class="chart-grid">
                <div class="chart-card"><h4>📈 PRODUCTION - HOURLY</h4><div class="chart-wrapper"><canvas id="c1-shirt-mtm"></canvas></div></div>
                <div class="chart-card"><h4>📉 D.H.U - HOURLY</h4><div class="chart-wrapper"><canvas id="c2-shirt-mtm"></canvas></div></div>
            </div>
        </div>
        
        <!-- ============================================================ -->
        <!-- SCREEN 3: SHIRT COMPARE -->
        <!-- ============================================================ -->
        <div class="screen-section" id="screen-shirt-compare">
            <div class="screen-title">🔄 SHIRT - COMPARE: MAIN vs MTM</div>
            
            <div class="chart-grid">
                <div class="chart-card"><h4>📈 PRODUCTION - HOURLY (Main vs MTM)</h4><div class="chart-wrapper"><canvas id="cmp-shirt-prod"></canvas></div></div>
                <div class="chart-card"><h4>📉 D.H.U - HOURLY (Main vs MTM)</h4><div class="chart-wrapper"><canvas id="cmp-shirt-dhu"></canvas></div></div>
                <div class="chart-card chart-full"><h4>📊 MONTH PRODUCE PCS - TREND</h4><div class="chart-wrapper"><canvas id="cmp-shirt-trend-prod"></canvas></div></div>
                <div class="chart-card chart-full"><h4>📉 MONTH D.H.U - TREND</h4><div class="chart-wrapper"><canvas id="cmp-shirt-trend-dhu"></canvas></div></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($selected_division == 2): ?>
        <!-- ============================================================ -->
        <!-- TROUSER: 3 Screens stacked -->
        <!-- ============================================================ -->
        <div class="screen-section" id="screen-trouser-main">
            <div class="screen-title">👖 TROUSER - MAIN</div>
            
            <div class="stats-row">
                <div class="stat-card"><div class="number"><?php echo number_format($stats_trouser_main['total_pcs']); ?></div><div class="label">Total Production</div></div>
                <div class="stat-card"><div class="number eff"><?php echo $stats_trouser_main['avg_eff']; ?>%</div><div class="label">Avg Efficiency</div></div>
                <div class="stat-card"><div class="number dhu"><?php echo $stats_trouser_main['avg_dhu']; ?>%</div><div class="label">Avg DHU</div></div>
                <div class="stat-card"><div class="number"><?php echo $work_hours; ?></div><div class="label">Work Hours</div></div>
            </div>
            
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="min-width:100px;">DASH BOARD</th>
                        <?php foreach ($trouser_main as $col): 
                            $cls = '';
                            if ($col['type'] == 'match_out') $cls = 'match-col';
                            elseif ($col['type'] == 'assembly') $cls = 'assembly-col';
                        ?>
                        <th colspan="2" class="<?php echo $cls; ?>"><?php echo htmlspecialchars($col['name']); ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2" class="dhu-col">DHU %</th>
                    </tr>
                    <tr><?php foreach ($trouser_main as $col): ?><th>Pcs</th><th>Eff</th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-BUDGET</td>
                        <?php foreach ($trouser_main as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-PRESENT</td>
                        <?php foreach ($trouser_main as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">ABSENTEESM</td>
                        <?php foreach ($trouser_main as $col): ?><td colspan="2">0%</td><?php endforeach; ?><td>—</td></tr>
                    <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                    <tr><td style="font-weight:700;"><?php echo $h; ?></td>
                        <?php foreach ($trouser_main as $col): 
                            $pcs = $col['hours'][$h - 1] ?? 0;
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td><?php echo number_format($pcs, 0); ?></td>
                        <td class="<?php echo $ec; ?>"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td><?php echo number_format($charts_trouser_main['dhu'][$h - 1] ?? 0, 1); ?>%</td>
                    </tr>
                    <?php endfor; ?>
                    <tr class="total-row"><td style="font-weight:700;">Average</td>
                        <?php foreach ($trouser_main as $col): 
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td style="font-weight:700;"><?php echo number_format($col['pcs'], 0); ?></td>
                        <td class="<?php echo $ec; ?>" style="font-weight:700;"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td style="font-weight:700;color:var(--dhu-color);"><?php echo $stats_trouser_main['avg_dhu']; ?>%</td>
                    </tr>
                    <tr class="loss-row"><td style="font-weight:700;">PROFIT</td>
                        <?php foreach ($trouser_main as $col): $p = round($col['profit']); ?>
                        <td colspan="2" style="font-weight:700; color:<?php echo $p >= 0 ? '#28a745' : '#dc3545'; ?>;">LKR <?php echo number_format($p); ?></td>
                        <?php endforeach; ?><td>—</td></tr>
                </tbody>
            </table>
            
            <div class="chart-grid">
                <div class="chart-card"><h4>📈 PRODUCTION - HOURLY</h4><div class="chart-wrapper"><canvas id="c1-trouser-main"></canvas></div></div>
                <div class="chart-card"><h4>📉 D.H.U - HOURLY</h4><div class="chart-wrapper"><canvas id="c2-trouser-main"></canvas></div></div>
            </div>
        </div>
        
        <div class="screen-section" id="screen-trouser-mtm">
            <div class="screen-title">👖 TROUSER - MTM</div>
            
            <div class="stats-row">
                <div class="stat-card"><div class="number"><?php echo number_format($stats_trouser_mtm['total_pcs']); ?></div><div class="label">Total Production</div></div>
                <div class="stat-card"><div class="number eff"><?php echo $stats_trouser_mtm['avg_eff']; ?>%</div><div class="label">Avg Efficiency</div></div>
                <div class="stat-card"><div class="number dhu"><?php echo $stats_trouser_mtm['avg_dhu']; ?>%</div><div class="label">Avg DHU</div></div>
                <div class="stat-card"><div class="number"><?php echo $work_hours; ?></div><div class="label">Work Hours</div></div>
            </div>
            
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="min-width:100px;">DASH BOARD</th>
                        <?php foreach ($trouser_mtm as $col): ?>
                        <th colspan="2" class="assembly-col"><?php echo htmlspecialchars($col['name']); ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2" class="dhu-col">DHU %</th>
                    </tr>
                    <tr><?php foreach ($trouser_mtm as $col): ?><th>Pcs</th><th>Eff</th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-BUDGET</td>
                        <?php foreach ($trouser_mtm as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-PRESENT</td>
                        <?php foreach ($trouser_mtm as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">ABSENTEESM</td>
                        <?php foreach ($trouser_mtm as $col): ?><td colspan="2">0%</td><?php endforeach; ?><td>—</td></tr>
                    <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                    <tr><td style="font-weight:700;"><?php echo $h; ?></td>
                        <?php foreach ($trouser_mtm as $col): 
                            $pcs = $col['hours'][$h - 1] ?? 0;
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td><?php echo number_format($pcs, 0); ?></td>
                        <td class="<?php echo $ec; ?>"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td><?php echo number_format($charts_trouser_mtm['dhu'][$h - 1] ?? 0, 1); ?>%</td>
                    </tr>
                    <?php endfor; ?>
                    <tr class="total-row"><td style="font-weight:700;">Average</td>
                        <?php foreach ($trouser_mtm as $col): 
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td style="font-weight:700;"><?php echo number_format($col['pcs'], 0); ?></td>
                        <td class="<?php echo $ec; ?>" style="font-weight:700;"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td style="font-weight:700;color:var(--dhu-color);"><?php echo $stats_trouser_mtm['avg_dhu']; ?>%</td>
                    </tr>
                    <tr class="loss-row"><td style="font-weight:700;">PROFIT</td>
                        <?php foreach ($trouser_mtm as $col): $p = round($col['profit']); ?>
                        <td colspan="2" style="font-weight:700; color:<?php echo $p >= 0 ? '#28a745' : '#dc3545'; ?>;">LKR <?php echo number_format($p); ?></td>
                        <?php endforeach; ?><td>—</td></tr>
                </tbody>
            </table>
            
            <div class="chart-grid">
                <div class="chart-card"><h4>📈 PRODUCTION - HOURLY</h4><div class="chart-wrapper"><canvas id="c1-trouser-mtm"></canvas></div></div>
                <div class="chart-card"><h4>📉 D.H.U - HOURLY</h4><div class="chart-wrapper"><canvas id="c2-trouser-mtm"></canvas></div></div>
            </div>
        </div>
        
        <div class="screen-section" id="screen-trouser-compare">
            <div class="screen-title">🔄 TROUSER - COMPARE: MAIN vs MTM</div>
            
            <div class="chart-grid">
                <div class="chart-card"><h4>📈 PRODUCTION - HOURLY (Main vs MTM)</h4><div class="chart-wrapper"><canvas id="cmp-trouser-prod"></canvas></div></div>
                <div class="chart-card"><h4>📉 D.H.U - HOURLY (Main vs MTM)</h4><div class="chart-wrapper"><canvas id="cmp-trouser-dhu"></canvas></div></div>
                <div class="chart-card chart-full"><h4>📊 MONTH PRODUCE PCS - TREND</h4><div class="chart-wrapper"><canvas id="cmp-trouser-trend-prod"></canvas></div></div>
                <div class="chart-card chart-full"><h4>📉 MONTH D.H.U - TREND</h4><div class="chart-wrapper"><canvas id="cmp-trouser-trend-dhu"></canvas></div></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($selected_division == 3): ?>
        <!-- ============================================================ -->
        <!-- SCREEN 1: COAT MAIN -->
        <!-- ============================================================ -->
        <div class="screen-section" id="screen-coat-main">
            <div class="screen-title">🧥 COAT - MAIN</div>
            
            <div class="stats-row">
                <div class="stat-card"><div class="number"><?php echo number_format($stats_coat_main['total_pcs']); ?></div><div class="label">Total Production</div></div>
                <div class="stat-card"><div class="number eff"><?php echo $stats_coat_main['avg_eff']; ?>%</div><div class="label">Avg Efficiency</div></div>
                <div class="stat-card"><div class="number dhu"><?php echo $stats_coat_main['avg_dhu']; ?>%</div><div class="label">Avg DHU</div></div>
                <div class="stat-card"><div class="number"><?php echo $work_hours; ?></div><div class="label">Work Hours</div></div>
            </div>
            
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="min-width:100px;">DASH BOARD</th>
                        <?php foreach ($coat_main as $col): ?>
                        <th colspan="2" class="assembly-col"><?php echo htmlspecialchars($col['name']); ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2" class="dhu-col">DHU %</th>
                    </tr>
                    <tr><?php foreach ($coat_main as $col): ?><th>Pcs</th><th>Eff</th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-BUDGET</td>
                        <?php foreach ($coat_main as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-PRESENT</td>
                        <?php foreach ($coat_main as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">ABSENTEESM</td>
                        <?php foreach ($coat_main as $col): ?><td colspan="2">0%</td><?php endforeach; ?><td>—</td></tr>
                    <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                    <tr><td style="font-weight:700;"><?php echo $h; ?></td>
                        <?php foreach ($coat_main as $col): 
                            $pcs = $col['hours'][$h - 1] ?? 0;
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td><?php echo number_format($pcs, 0); ?></td>
                        <td class="<?php echo $ec; ?>"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td><?php echo number_format($charts_coat_main['dhu'][$h - 1] ?? 0, 1); ?>%</td>
                    </tr>
                    <?php endfor; ?>
                    <tr class="total-row"><td style="font-weight:700;">Average</td>
                        <?php foreach ($coat_main as $col): 
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td style="font-weight:700;"><?php echo number_format($col['pcs'], 0); ?></td>
                        <td class="<?php echo $ec; ?>" style="font-weight:700;"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td style="font-weight:700;color:var(--dhu-color);"><?php echo $stats_coat_main['avg_dhu']; ?>%</td>
                    </tr>
                    <tr class="loss-row"><td style="font-weight:700;">PROFIT</td>
                        <?php foreach ($coat_main as $col): $p = round($col['profit']); ?>
                        <td colspan="2" style="font-weight:700; color:<?php echo $p >= 0 ? '#28a745' : '#dc3545'; ?>;">LKR <?php echo number_format($p); ?></td>
                        <?php endforeach; ?><td>—</td></tr>
                </tbody>
            </table>
            
            <div class="chart-grid">
                <div class="chart-card"><h4>📈 PRODUCTION - HOURLY</h4><div class="chart-wrapper"><canvas id="c1-coat-main"></canvas></div></div>
                <div class="chart-card"><h4>📉 D.H.U - HOURLY</h4><div class="chart-wrapper"><canvas id="c2-coat-main"></canvas></div></div>
            </div>
        </div>
        
        <!-- ============================================================ -->
        <!-- SCREEN 2: COAT MTM -->
        <!-- ============================================================ -->
        <div class="screen-section" id="screen-coat-mtm">
            <div class="screen-title">🧥 COAT - MTM</div>
            
            <div class="stats-row">
                <div class="stat-card"><div class="number"><?php echo number_format($stats_coat_mtm['total_pcs']); ?></div><div class="label">Total Production</div></div>
                <div class="stat-card"><div class="number eff"><?php echo $stats_coat_mtm['avg_eff']; ?>%</div><div class="label">Avg Efficiency</div></div>
                <div class="stat-card"><div class="number dhu"><?php echo $stats_coat_mtm['avg_dhu']; ?>%</div><div class="label">Avg DHU</div></div>
                <div class="stat-card"><div class="number"><?php echo $work_hours; ?></div><div class="label">Work Hours</div></div>
            </div>
            
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="min-width:100px;">DASH BOARD</th>
                        <?php foreach ($coat_mtm as $col): ?>
                        <th colspan="2" class="assembly-col"><?php echo htmlspecialchars($col['name']); ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2" class="dhu-col">DHU %</th>
                    </tr>
                    <tr><?php foreach ($coat_mtm as $col): ?><th>Pcs</th><th>Eff</th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-BUDGET</td>
                        <?php foreach ($coat_mtm as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-PRESENT</td>
                        <?php foreach ($coat_mtm as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">ABSENTEESM</td>
                        <?php foreach ($coat_mtm as $col): ?><td colspan="2">0%</td><?php endforeach; ?><td>—</td></tr>
                    <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                    <tr><td style="font-weight:700;"><?php echo $h; ?></td>
                        <?php foreach ($coat_mtm as $col): 
                            $pcs = $col['hours'][$h - 1] ?? 0;
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td><?php echo number_format($pcs, 0); ?></td>
                        <td class="<?php echo $ec; ?>"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td><?php echo number_format($charts_coat_mtm['dhu'][$h - 1] ?? 0, 1); ?>%</td>
                    </tr>
                    <?php endfor; ?>
                    <tr class="total-row"><td style="font-weight:700;">Average</td>
                        <?php foreach ($coat_mtm as $col): 
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td style="font-weight:700;"><?php echo number_format($col['pcs'], 0); ?></td>
                        <td class="<?php echo $ec; ?>" style="font-weight:700;"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td style="font-weight:700;color:var(--dhu-color);"><?php echo $stats_coat_mtm['avg_dhu']; ?>%</td>
                    </tr>
                    <tr class="loss-row"><td style="font-weight:700;">PROFIT</td>
                        <?php foreach ($coat_mtm as $col): $p = round($col['profit']); ?>
                        <td colspan="2" style="font-weight:700; color:<?php echo $p >= 0 ? '#28a745' : '#dc3545'; ?>;">LKR <?php echo number_format($p); ?></td>
                        <?php endforeach; ?><td>—</td></tr>
                </tbody>
            </table>
            
            <div class="chart-grid">
                <div class="chart-card"><h4>📈 PRODUCTION - HOURLY</h4><div class="chart-wrapper"><canvas id="c1-coat-mtm"></canvas></div></div>
                <div class="chart-card"><h4>📉 D.H.U - HOURLY</h4><div class="chart-wrapper"><canvas id="c2-coat-mtm"></canvas></div></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($selected_division == 7): ?>
        <div class="screen-section">
            <div class="screen-title">🏭 ASSEMBLY</div>
            <div class="stats-row">
                <div class="stat-card"><div class="number"><?php echo number_format($stats_assembly['total_pcs']); ?></div><div class="label">Total Production</div></div>
                <div class="stat-card"><div class="number eff"><?php echo $stats_assembly['avg_eff']; ?>%</div><div class="label">Avg Efficiency</div></div>
                <div class="stat-card"><div class="number dhu"><?php echo $stats_assembly['avg_dhu']; ?>%</div><div class="label">Avg DHU</div></div>
                <div class="stat-card"><div class="number"><?php echo $work_hours; ?></div><div class="label">Work Hours</div></div>
            </div>
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="min-width:100px;">DASH BOARD</th>
                        <?php foreach ($assembly_main as $col): ?>
                        <th colspan="2"><?php echo htmlspecialchars($col['name']); ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2" class="dhu-col">DHU %</th>
                    </tr>
                    <tr><?php foreach ($assembly_main as $col): ?><th>Pcs</th><th>Eff</th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-BUDGET</td>
                        <?php foreach ($assembly_main as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-PRESENT</td>
                        <?php foreach ($assembly_main as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">ABSENTEESM</td>
                        <?php foreach ($assembly_main as $col): ?><td colspan="2">0%</td><?php endforeach; ?><td>—</td></tr>
                    <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                    <tr><td style="font-weight:700;"><?php echo $h; ?></td>
                        <?php foreach ($assembly_main as $col): 
                            $pcs = $col['hours'][$h - 1] ?? 0;
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td><?php echo number_format($pcs, 0); ?></td>
                        <td class="<?php echo $ec; ?>"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td><?php echo number_format(computeCharts($assembly_main, $work_hours)['dhu'][$h - 1] ?? 0, 1); ?>%</td>
                    </tr>
                    <?php endfor; ?>
                    <tr class="total-row"><td style="font-weight:700;">Average</td>
                        <?php foreach ($assembly_main as $col): 
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td style="font-weight:700;"><?php echo number_format($col['pcs'], 0); ?></td>
                        <td class="<?php echo $ec; ?>" style="font-weight:700;"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td style="font-weight:700;color:var(--dhu-color);"><?php echo $stats_assembly['avg_dhu']; ?>%</td>
                    </tr>
                    <tr class="loss-row"><td style="font-weight:700;">PROFIT</td>
                        <?php foreach ($assembly_main as $col): $p = round($col['profit']); ?>
                        <td colspan="2" style="font-weight:700; color:<?php echo $p >= 0 ? '#28a745' : '#dc3545'; ?>;">LKR <?php echo number_format($p); ?></td>
                        <?php endforeach; ?><td>—</td></tr>
                </tbody>
            </table>
            
            <div class="chart-grid">
                <div class="chart-card"><h4>📈 PRODUCTION - HOURLY</h4><div class="chart-wrapper"><canvas id="c1-assembly"></canvas></div></div>
                <div class="chart-card"><h4>📉 D.H.U - HOURLY</h4><div class="chart-wrapper"><canvas id="c2-assembly"></canvas></div></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        function updateClock() {
            const now = new Date();
            document.getElementById('kiosk-clock').textContent = 
                String(now.getHours()).padStart(2, '0') + ':' +
                String(now.getMinutes()).padStart(2, '0') + ':' +
                String(now.getSeconds()).padStart(2, '0');
        }
        updateClock();
        setInterval(updateClock, 1000);

                function switchDivision(id) {
            window.location.href = 'analytics.php?division=' + id;
        }

        const labels = <?php echo json_encode($chart_labels); ?>;
        const shirtMainProd = <?php echo json_encode($charts_shirt_main['production']); ?>;
        const shirtMainDHU = <?php echo json_encode($charts_shirt_main['dhu']); ?>;
        const shirtMtmProd = <?php echo json_encode($charts_shirt_mtm['production']); ?>;
        const shirtMtmDHU = <?php echo json_encode($charts_shirt_mtm['dhu']); ?>;
        const trouserMainProd = <?php echo json_encode($charts_trouser_main['production']); ?>;
        const trouserMainDHU = <?php echo json_encode($charts_trouser_main['dhu']); ?>;
        const trouserMtmProd = <?php echo json_encode($charts_trouser_mtm['production']); ?>;
        const trouserMtmDHU = <?php echo json_encode($charts_trouser_mtm['dhu']); ?>;
        const coatMainProd = <?php echo json_encode($coat_main[0]['hours'] ?? array_fill(0, $work_hours, 0)); ?>;
        const coatMtmProd = <?php echo json_encode($coat_mtm[0]['hours'] ?? array_fill(0, $work_hours, 0)); ?>;

        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.font.size = 11;
        Chart.defaults.color = '#6b7a8f';

        function mkProdChart(id, prod, dhu, label1, label2) {
            const el = document.getElementById(id);
            if (!el) return;
            new Chart(el, {
                type: 'bar',
                data: { labels: labels, datasets: [
                    { label: label1, data: prod, backgroundColor: 'rgba(33,115,70,0.7)', borderColor: 'rgba(33,115,70,1)', borderWidth: 2, borderRadius: 4, order: 1 },
                    { label: label2, data: dhu, type: 'line', borderColor: 'rgba(245,124,0,1)', backgroundColor: 'rgba(245,124,0,0.1)', borderWidth: 2, pointRadius: 4, tension: 0.3, fill: true, yAxisID: 'y1', order: 0 }
                ]},
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, position: 'left' }, y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false } } } }
            });
        }
        function mkDHUChart(id, dhu) {
            const el = document.getElementById(id);
            if (!el) return;
            new Chart(el, {
                type: 'bar',
                data: { labels: labels, datasets: [{ label: 'DHU %', data: dhu, backgroundColor: 'rgba(231,76,60,0.7)', borderColor: 'rgba(231,76,60,1)', borderWidth: 2, borderRadius: 4 }] },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
            });
        }
        function mkCompareProd(id, prod1, prod2) {
            const el = document.getElementById(id);
            if (!el) return;
            new Chart(el, {
                type: 'line',
                data: { labels: labels, datasets: [
                    { label: 'Main', data: prod1, borderColor: 'rgba(33,115,70,1)', backgroundColor: 'rgba(33,115,70,0.15)', fill: true, tension: 0.3, pointRadius: 4, borderWidth: 2 },
                    { label: 'MTM', data: prod2, borderColor: 'rgba(23,162,184,1)', backgroundColor: 'rgba(23,162,184,0.15)', fill: true, tension: 0.3, pointRadius: 4, borderWidth: 2 }
                ]},
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
            });
        }
        function mkCompareDHU(id, dhu1, dhu2) {
            const el = document.getElementById(id);
            if (!el) return;
            new Chart(el, {
                type: 'line',
                data: { labels: labels, datasets: [
                    { label: 'Main DHU', data: dhu1, borderColor: 'rgba(231,76,60,1)', backgroundColor: 'rgba(231,76,60,0.15)', fill: true, tension: 0.3, pointRadius: 4, borderWidth: 2 },
                    { label: 'MTM DHU', data: dhu2, borderColor: 'rgba(245,124,0,1)', backgroundColor: 'rgba(245,124,0,0.15)', fill: true, tension: 0.3, pointRadius: 4, borderWidth: 2 }
                ]},
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
            });
        }

        <?php if ($selected_division == 1): ?>
        mkProdChart('c1-shirt-main', shirtMainProd, shirtMainDHU, 'Pcs', 'Eff %');
        mkDHUChart('c2-shirt-main', shirtMainDHU);
        mkProdChart('c1-shirt-mtm', shirtMtmProd, shirtMtmDHU, 'Pcs', 'Eff %');
        mkDHUChart('c2-shirt-mtm', shirtMtmDHU);
        mkCompareProd('cmp-shirt-prod', shirtMainProd, shirtMtmProd);
        mkCompareDHU('cmp-shirt-dhu', shirtMainDHU, shirtMtmDHU);
        mkCompareProd('cmp-shirt-trend-prod', shirtMainProd, shirtMtmProd);
        mkCompareDHU('cmp-shirt-trend-dhu', shirtMainDHU, shirtMtmDHU);
        <?php elseif ($selected_division == 2): ?>
        mkProdChart('c1-trouser-main', trouserMainProd, trouserMainDHU, 'Pcs', 'Eff %');
        mkDHUChart('c2-trouser-main', trouserMainDHU);
        mkProdChart('c1-trouser-mtm', trouserMtmProd, trouserMtmDHU, 'Pcs', 'Eff %');
        mkDHUChart('c2-trouser-mtm', trouserMtmDHU);
        mkCompareProd('cmp-trouser-prod', trouserMainProd, trouserMtmProd);
        mkCompareDHU('cmp-trouser-dhu', trouserMainDHU, trouserMtmDHU);
        mkCompareProd('cmp-trouser-trend-prod', trouserMainProd, trouserMtmProd);
        mkCompareDHU('cmp-trouser-trend-dhu', trouserMainDHU, trouserMtmDHU);
        <?php elseif ($selected_division == 3): ?>
        mkProdChart('c1-coat-main', <?php echo json_encode($charts_coat_main['production']); ?>, <?php echo json_encode($charts_coat_main['dhu']); ?>, 'Pcs', 'Eff %');
        mkDHUChart('c2-coat-main', <?php echo json_encode($charts_coat_main['dhu']); ?>);
        mkProdChart('c1-coat-mtm', <?php echo json_encode($charts_coat_mtm['production']); ?>, <?php echo json_encode($charts_coat_mtm['dhu']); ?>, 'Pcs', 'Eff %');
        mkDHUChart('c2-coat-mtm', <?php echo json_encode($charts_coat_mtm['dhu']); ?>);
        <?php elseif ($selected_division == 7): 
            $assembly_charts = computeCharts($assembly_main, $work_hours);
        ?>
        mkProdChart('c1-assembly', <?php echo json_encode($assembly_charts['production']); ?>, <?php echo json_encode($assembly_charts['dhu']); ?>, 'Pcs', 'Eff %');
        mkDHUChart('c2-assembly', <?php echo json_encode($assembly_charts['dhu']); ?>);
        <?php endif; ?>

        // ============================================================
        // CONTINUOUS SCROLL (infinite, top → bottom → top)
        // ============================================================
        <?php if (in_array($selected_division, [1, 2, 3, 7])): ?>
        (function() {
            const SCROLL_SPEED = 0.5;
            const PAUSE_AT_BOTTOM = 3000;
            const PAUSE_AT_TOP = 2000;
            
            let scrolling = false;
            let paused = false;
            
            function startScroll() {
                scrolling = true;
                paused = false;
                requestAnimationFrame(step);
            }
            
            function step() {
                if (!scrolling) return;
                if (paused) {
                    requestAnimationFrame(step);
                    return;
                }
                
                const maxScroll = document.documentElement.scrollHeight - window.innerHeight;
                const currentY = window.pageYOffset;
                
                if (currentY >= maxScroll - 1) {
                    paused = true;
                    setTimeout(() => {
                        window.scrollTo(0, 0);
                        setTimeout(() => {
                            paused = false;
                            requestAnimationFrame(step);
                        }, PAUSE_AT_TOP);
                    }, PAUSE_AT_BOTTOM);
                    return;
                }
                
                window.scrollBy(0, SCROLL_SPEED);
                requestAnimationFrame(step);
            }
            
            window.addEventListener('load', () => {
                setTimeout(startScroll, 1500);
            });
            
            ['mousedown', 'wheel', 'touchstart', 'keydown'].forEach(evt => {
                window.addEventListener(evt, () => {
                    paused = true;
                    setTimeout(() => { paused = false; }, 3000);
                }, { passive: true });
            });
        })();
        <?php endif; ?>
    </script>
</body>
</html>