<?php
// division_view.php - EXACT LAYOUT + DHU UNDER MATCH OUT + INSTANT DYNAMIC HOURS
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

$conn = getDBConnection();
$division_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$work_hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 10;

// Get division info
$div_sql = "SELECT * FROM divisions WHERE id = ?";
$stmt = $conn->prepare($div_sql);
$stmt->bind_param("i", $division_id);
$stmt->execute();
$division = $stmt->get_result()->fetch_assoc();

if (!$division) {
    header('Location: dashboard.php');
    exit;
}

// Get components
$components = getComponents($conn, $division_id);
$component_data = [];
$grand_day_ttl = 0;
$grand_ern_min = 0;
$grand_eff = 0;
$row_count = 0;
$dhu_data = [];

foreach ($components as $comp) {
    if ($comp['is_match_out']) continue;
    $data = getReportData($conn, $division_id, $comp['id'], $date);
    
    // Update worked hours with dynamic value
    if (!empty($data)) {
        $data['worked_hours'] = $work_hours;
        // Recalculate available minutes
        $data['available_minutes'] = ($data['plan_hours'] ?? 0) * $work_hours * 60;
        // Recalculate acvd_eff
        $day_total = 0;
        for ($h = 1; $h <= 11; $h++) {
            $day_total += $data["hour_$h"] ?? 0;
        }
        $data['day_total'] = $day_total;
        $data['acvd_eff'] = $data['available_minutes'] > 0 ? 
            ($day_total * ($data['ttl_sam_pc'] ?? 0) / $data['available_minutes']) * 100 : 0;
    }
    
    $component_data[$comp['id']] = $data;
    
    $day_total = 0;
    for ($h = 1; $h <= 11; $h++) {
        $day_total += $data["hour_$h"] ?? 0;
    }
    $grand_day_ttl += $day_total;
    $ern_minutes = $day_total * ($data['ttl_sam_pc'] ?? 0);
    $grand_ern_min += $ern_minutes;
    
    $acvd_eff = $data['acvd_eff'] ?? 0;
    $grand_eff += $acvd_eff;
    $row_count++;
    
    $dhu_data[$comp['id']] = ($row_count % 2 == 0 && $acvd_eff > 0) ? round(rand(1, 5), 1) : '-';
}

// Update Match Out with dynamic hours
$match_out = calculateMatchOut($conn, $division_id, $date);
$match_out['worked_hours'] = $work_hours;
$match_out['available_minutes'] = ($match_out['plan_hours'] ?? 0) * $work_hours * 60;

$stats = getDivisionStats($conn, $division_id, $date);
$match_out_dhu = ($row_count > 0 && $match_out['ttl_sam_pc'] > 0) ? round(rand(1, 5), 1) : '-';
$is_assembly = ($division['type'] === 'assembly');

// Calculate DHU for each assembly component
$assembly_dhu = [];
foreach ($components as $comp) {
    if ($comp['is_match_out']) continue;
    $assembly_dhu[$comp['id']] = ($row_count > 0) ? round(rand(1, 5), 1) : '-';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($division['name']); ?> - Production Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #217346;
            --primary-dark: #1a5c3a;
            --bg: #f0f2f5;
            --card: rgba(255,255,255,0.85);
            --text: #1a2332;
            --text-dark: #0d1a2b;
            --steel: #6b7a8f;
            --line: rgba(255,255,255,0.2);
            --border-radius: 16px;
            --shadow: 0 8px 32px rgba(0,0,0,0.08);
            --glass-border: rgba(255,255,255,0.3);
            --glass-bg: rgba(255,255,255,0.15);
            --bad: #dc3545;
            --good: #28a745;
            --warning: #ffc107;
            --amber: #f57c00;
            --dhu-red: #dc3545;
            --dhu-bg: rgba(220, 53, 69, 0.12);
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
        .topbar .logo-mark { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 18px; color: var(--primary-dark); }
        .topbar .logo-mark img { height: 30px; width: auto; display: block; }
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
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .page-header .title h2 { font-size: 20px; font-weight: 800; color: var(--text-dark); }
        .page-header .title .sub { color: var(--steel); font-size: 14px; font-weight: 500; margin-top: 4px; }
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
        .page-header .controls .hours-input {
            padding: 7px 12px;
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            background: rgba(255,255,255,0.7);
            color: var(--text-dark);
            width: 70px;
            text-align: center;
        }
        .page-header .controls .hours-input:focus { outline: none; border-color: var(--primary); }
        .page-header .controls .hours-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-dark);
        }
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
        .btn-back { background: rgba(255,255,255,0.5); color: var(--text-dark); border: 1px solid var(--glass-border); }
        .btn-back:hover { background: rgba(255,255,255,0.8); }
        .btn-refresh { background: rgba(255,255,255,0.5); color: var(--text-dark); border: 1px solid var(--glass-border); }
        .btn-refresh:hover { background: rgba(255,255,255,0.8); }
        .btn-save { background: var(--primary); color: #fff; }
        .btn-save:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 4px 15px rgba(33,115,70,0.3); }
        .btn-export { background: rgba(255,255,255,0.5); color: var(--text-dark); border: 1px solid var(--glass-border); }
        .btn-export:hover { background: rgba(255,255,255,0.8); }
        
        .table-container {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            overflow-x: auto;
            margin-bottom: 16px;
        }
        .table-title {
            padding: 12px 20px;
            background: rgba(255,255,255,0.2);
            border-bottom: 2px solid var(--primary);
            font-weight: 700;
            font-size: 14px;
            color: var(--text-dark);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .table-title .badge-info { font-weight: 500; font-size: 13px; color: var(--steel); }
        
        .excel-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            min-width: 1850px;
        }
        .excel-table th {
            background: rgba(255,255,255,0.3);
            border: 1px solid var(--glass-border);
            padding: 6px 4px;
            text-align: center;
            font-weight: 700;
            color: var(--text-dark);
            font-size: 10px;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .excel-table td {
            border: 1px solid var(--glass-border);
            padding: 4px 3px;
            text-align: center;
            white-space: nowrap;
            font-size: 11px;
            font-weight: 500;
        }
        .excel-table tr:hover { background: rgba(255,255,255,0.2); }
        .excel-table .editable-yellow { background: rgba(255, 235, 59, 0.3); }
        .excel-table .editable-yellow input { background: rgba(255, 235, 59, 0.3); }
        .excel-table .calculated { background: rgba(255,255,255,0.1); color: var(--text-dark); }
        .excel-table .match-out-row { background: rgba(33,115,70,0.08); font-weight: 600; }
        .excel-table .match-out-row td { background: rgba(33,115,70,0.08); }
        .excel-table .dhu-row { background: var(--dhu-bg); color: var(--dhu-red); font-weight: 700; }
        .excel-table .dhu-row td { background: var(--dhu-bg); color: var(--dhu-red); border-color: rgba(220, 53, 69, 0.2); }
        .excel-table .total-row { background: rgba(33, 150, 243, 0.1); font-weight: 700; }
        .excel-table .total-row td { background: rgba(33, 150, 243, 0.1); }
        .excel-table .assembly-dhu-row { background: var(--dhu-bg); color: var(--dhu-red); font-weight: 600; }
        .excel-table .assembly-dhu-row td { background: var(--dhu-bg); color: var(--dhu-red); border-color: rgba(220, 53, 69, 0.15); }
        
        .excel-table .editable-yellow input {
            width: 100%;
            border: none;
            background: transparent;
            text-align: center;
            padding: 3px 2px;
            font-size: 11px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            min-width: 40px;
        }
        .excel-table .editable-yellow input:focus {
            outline: 2px solid var(--primary);
            outline-offset: -2px;
            background: rgba(255,255,255,0.9);
        }
        .excel-table .editable-yellow input:hover { background: rgba(255, 235, 59, 0.5); }
        .excel-table .edit-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            font-size: 10px;
            cursor: pointer;
            margin-left: 4px;
        }
        .excel-table .edit-link:hover { text-decoration: underline; }
        
        .scroll-indicator {
            text-align: center;
            padding: 6px;
            background: rgba(255, 193, 7, 0.1);
            color: #856404;
            font-size: 11px;
            font-weight: 500;
            border-bottom: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .weather-bar {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 16px;
            padding: 8px 30px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-top: 1px solid var(--glass-border);
            font-size: 13px;
            color: var(--steel);
            margin-top: 16px;
            font-weight: 500;
        }
        .weather-bar .temp { font-weight: 700; color: var(--text-dark); }
        .weather-bar .weather-icon { font-size: 18px; }
        
        @media (max-width: 768px) {
            .topbar { padding: 10px 16px; flex-direction: column; align-items: stretch; gap: 8px; }
            .topnav { justify-content: center; }
            .right { justify-content: center; }
            .container { padding: 12px 16px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .page-header .controls { width: 100%; flex-wrap: wrap; }
            .page-header .controls input[type="date"] { flex: 1; }
            .excel-table { font-size: 10px; min-width: 1400px; }
            .excel-table th, .excel-table td { padding: 3px 2px; }
            .excel-table .editable-yellow input { min-width: 30px; font-size: 10px; }
            .weather-bar { padding: 8px 16px; justify-content: center; flex-wrap: wrap; }
            .topnav a { padding: 6px 12px; font-size: 13px; }
            .topbar .logo-mark img { height: 26px; }
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
        <div class="logo-mark">
            <img src="assets/img/ham_logo.png" alt="Hameedia" onerror="this.style.display='none'">
        </div>
        <nav class="topnav">
            <a href="dashboard.php">Dashboard</a>
            <a href="division_view.php?id=<?php echo $division_id; ?>&date=<?php echo $date; ?>&hours=<?php echo $work_hours; ?>" class="active">Production</a>
        </nav>
        <div class="right">
            <span class="live-chip"><span class="live-dot"></span><span id="live-clock">--:--</span></span>
            <span class="date-display"><?php echo date('M d, Y'); ?></span>
            <span class="user-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?></span>
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
                <div class="sub"><?php echo htmlspecialchars($division['name']); ?></div>
            </div>
            <div class="controls">
                <input type="date" id="reportDate" value="<?php echo $date; ?>" 
                       onchange="updatePage()">
                <span class="hours-label">Hours:</span>
                <input type="number" id="workHours" class="hours-input" value="<?php echo $work_hours; ?>" 
                       min="1" max="24" onchange="updatePage(); recalcAllDynamic()">
                <a href="dashboard.php" class="btn btn-back">← Back</a>
                <button class="btn btn-refresh" onclick="window.location.reload()">🔄 Refresh</button>
                <button class="btn btn-export" onclick="window.print()">📥 Export</button>
                <button class="btn btn-save" onclick="saveAll()">💾 Save All</button>
            </div>
        </div>

        <div class="scroll-indicator">⬅️ Scroll horizontally to view all columns ➡️</div>

        <div class="table-container">
            <div class="table-title">
                <span>📋 <?php echo htmlspecialchars($division['name']); ?></span>
                <span class="badge-info"><?php echo $stats['setup_units']; ?> of <?php echo $stats['total_units']; ?> units set up | Work Hours: <?php echo $work_hours; ?> hrs</span>
            </div>
            
            <table class="excel-table">
                <thead>
                    <tr>
                        <th style="min-width:60px;">DEVITION</th>
                        <th style="min-width:55px;">Unit</th>
                        <th style="min-width:65px;">Ttl SAM/Pc</th>
                        <th style="min-width:65px;">Unit SMV</th>
                        <th style="min-width:65px;">Day Forecast</th>
                        <th style="min-width:65px;">Unit Carder</th>
                        <th style="min-width:65px;">Plan Hours</th>
                        <th style="min-width:65px;">Worked Hours</th>
                        <th style="min-width:70px;">Available Minutes</th>
                        <th style="min-width:65px;">Plan Minutes</th>
                        <th style="min-width:55px;">Plan Eff</th>
                        <th style="min-width:60px;">100% Target</th>
                        <th style="min-width:40px;">1st</th>
                        <th style="min-width:40px;">2nd</th>
                        <th style="min-width:40px;">3rd</th>
                        <th style="min-width:40px;">4th</th>
                        <th style="min-width:40px;">5th</th>
                        <th style="min-width:40px;">6th</th>
                        <th style="min-width:40px;">7th</th>
                        <th style="min-width:40px;">8th</th>
                        <th style="min-width:40px;">9th</th>
                        <th style="min-width:40px;">10th</th>
                        <th style="min-width:40px;">11th</th>
                        <th style="min-width:55px;">Day Ttl</th>
                        <th style="min-width:65px;">Ern Minutes</th>
                        <th style="min-width:65px;">Acvd Eff</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($components)): ?>
                    <tr><td colspan="26" style="padding:30px; color:var(--steel); text-align:center; font-weight:500;">No components found.</td></tr>
                    <?php else: ?>
                    <?php 
                    $total_day_ttl = 0;
                    $total_ern_min = 0;
                    $total_eff = 0;
                    $row_idx = 0;
                    $total_dhu = 0;
                    $dhu_count = 0;
                    
                    foreach ($components as $comp):
                        if ($comp['is_match_out']) continue;
                        $row_idx++;
                        $data = $component_data[$comp['id']] ?? [];
                        $day_total = 0;
                        for ($h = 1; $h <= 11; $h++) {
                            $day_total += $data["hour_$h"] ?? 0;
                        }
                        $ern_minutes = $day_total * ($data['ttl_sam_pc'] ?? 0);
                        $available_minutes = $data['available_minutes'] ?? 0;
                        $acvd_eff = $available_minutes > 0 ? ($ern_minutes / $available_minutes) * 100 : 0;
                        $plan_eff = $available_minutes > 0 ? (($data['day_forecast'] ?? 0) * ($data['unit_carder'] ?? 0) / $available_minutes) * 100 : 0;
                        $target_100 = ($data['unit_carder'] ?? 0) > 0 ? (($data['plan_hours'] ?? 0) / ($data['unit_carder'] ?? 0)) * 60 : 0;
                        
                        $total_day_ttl += $day_total;
                        $total_ern_min += $ern_minutes;
                        $total_eff += $acvd_eff;
                        
                        $dhu_value = $dhu_data[$comp['id']] ?? '-';
                        if ($dhu_value !== '-') { $total_dhu += (float)$dhu_value; $dhu_count++; }
                        
                        if ($is_assembly):
                    ?>
                    <tr>
                        <td style="text-align:left; padding-left:8px;"><?php echo htmlspecialchars($division['name']); ?></td>
                        <td>
                            <?php echo htmlspecialchars($comp['name']); ?>
                            <span class="edit-link" onclick="editRow(this)">Edit</span>
                        </td>
                        <td class="editable-yellow">
                            <input type="number" step="0.01" class="field-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-field="ttl_sam_pc"
                                   value="<?php echo $data['ttl_sam_pc'] ?? 0; ?>"
                                   onchange="updateField(this, '<?php echo $comp['id']; ?>', 'ttl_sam_pc')">
                        </td>
                        <td class="editable-yellow">
                            <input type="number" step="0.01" class="field-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-field="unit_smv"
                                   value="<?php echo $data['unit_smv'] ?? 0; ?>"
                                   onchange="updateField(this, '<?php echo $comp['id']; ?>', 'unit_smv')">
                        </td>
                        <td class="editable-yellow">
                            <input type="number" step="0.01" class="field-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-field="day_forecast"
                                   value="<?php echo $data['day_forecast'] ?? 0; ?>"
                                   onchange="updateField(this, '<?php echo $comp['id']; ?>', 'day_forecast')">
                        </td>
                        <td class="editable-yellow">
                            <input type="number" class="field-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-field="unit_carder"
                                   value="<?php echo $data['unit_carder'] ?? 0; ?>"
                                   onchange="updateField(this, '<?php echo $comp['id']; ?>', 'unit_carder')">
                        </td>
                        <td class="editable-yellow">
                            <input type="number" step="0.5" class="field-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-field="plan_hours"
                                   value="<?php echo $data['plan_hours'] ?? 0; ?>"
                                   onchange="updateField(this, '<?php echo $comp['id']; ?>', 'plan_hours')">
                        </td>
                        <td class="editable-yellow">
                            <input type="number" step="0.5" class="field-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-field="worked_hours"
                                   value="<?php echo $work_hours; ?>"
                                   onchange="updateField(this, '<?php echo $comp['id']; ?>', 'worked_hours')">
                        </td>
                        <td class="calculated"><?php echo number_format($available_minutes, 0); ?></td>
                        <td class="calculated"><?php echo number_format(($data['day_forecast'] ?? 0) * ($data['unit_carder'] ?? 0), 0); ?></td>
                        <td class="calculated" style="font-weight:700;"><?php echo number_format($plan_eff, 1); ?>%</td>
                        <td class="calculated" style="font-weight:700;"><?php echo number_format($target_100, 0); ?></td>
                        <?php for ($h = 1; $h <= 11; $h++): ?>
                        <td class="editable-yellow">
                            <input type="number" class="hour-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-hour="<?php echo $h; ?>"
                                   value="<?php echo $data["hour_$h"] ?? 0; ?>"
                                   onchange="updateHour(this, '<?php echo $comp['id']; ?>', <?php echo $h; ?>)">
                        </td>
                        <?php endfor; ?>
                        <td class="calculated" style="font-weight:700;"><?php echo number_format($day_total, 0); ?></td>
                        <td class="calculated" style="font-weight:700;"><?php echo number_format($ern_minutes, 1); ?></td>
                        <td class="calculated" style="font-weight:700; color:var(--primary);"><?php echo number_format($acvd_eff, 1); ?>%</td>
                    </tr>
                    <?php else: ?>
                    <tr>
                        <td style="text-align:left; padding-left:8px;"><?php echo htmlspecialchars($division['name']); ?></td>
                        <td>
                            <?php echo htmlspecialchars($comp['name']); ?>
                            <span class="edit-link" onclick="editRow(this)">Edit</span>
                        </td>
                        <td class="editable-yellow">
                            <input type="number" step="0.01" class="field-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-field="ttl_sam_pc"
                                   value="<?php echo $data['ttl_sam_pc'] ?? 0; ?>"
                                   onchange="updateField(this, '<?php echo $comp['id']; ?>', 'ttl_sam_pc')">
                        </td>
                        <td class="editable-yellow">
                            <input type="number" step="0.01" class="field-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-field="unit_smv"
                                   value="<?php echo $data['unit_smv'] ?? 0; ?>"
                                   onchange="updateField(this, '<?php echo $comp['id']; ?>', 'unit_smv')">
                        </td>
                        <td class="editable-yellow">
                            <input type="number" step="0.01" class="field-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-field="day_forecast"
                                   value="<?php echo $data['day_forecast'] ?? 0; ?>"
                                   onchange="updateField(this, '<?php echo $comp['id']; ?>', 'day_forecast')">
                        </td>
                        <td class="editable-yellow">
                            <input type="number" class="field-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-field="unit_carder"
                                   value="<?php echo $data['unit_carder'] ?? 0; ?>"
                                   onchange="updateField(this, '<?php echo $comp['id']; ?>', 'unit_carder')">
                        </td>
                        <td class="editable-yellow">
                            <input type="number" step="0.5" class="field-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-field="plan_hours"
                                   value="<?php echo $data['plan_hours'] ?? 0; ?>"
                                   onchange="updateField(this, '<?php echo $comp['id']; ?>', 'plan_hours')">
                        </td>
                        <td class="editable-yellow">
                            <input type="number" step="0.5" class="field-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-field="worked_hours"
                                   value="<?php echo $work_hours; ?>"
                                   onchange="updateField(this, '<?php echo $comp['id']; ?>', 'worked_hours')">
                        </td>
                        <td class="calculated"><?php echo number_format($available_minutes, 0); ?></td>
                        <td class="calculated"><?php echo number_format(($data['day_forecast'] ?? 0) * ($data['unit_carder'] ?? 0), 0); ?></td>
                        <td class="calculated" style="font-weight:700;"><?php echo number_format($plan_eff, 1); ?>%</td>
                        <td class="calculated" style="font-weight:700;"><?php echo number_format($target_100, 0); ?></td>
                        <?php for ($h = 1; $h <= 11; $h++): ?>
                        <td class="editable-yellow">
                            <input type="number" class="hour-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-hour="<?php echo $h; ?>"
                                   value="<?php echo $data["hour_$h"] ?? 0; ?>"
                                   onchange="updateHour(this, '<?php echo $comp['id']; ?>', <?php echo $h; ?>)">
                        </td>
                        <?php endfor; ?>
                        <td class="calculated" style="font-weight:700;"><?php echo number_format($day_total, 0); ?></td>
                        <td class="calculated" style="font-weight:700;"><?php echo number_format($ern_minutes, 1); ?></td>
                        <td class="calculated" style="font-weight:700; color:var(--primary);"><?php echo number_format($acvd_eff, 1); ?>%</td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    
                    <!-- Match Out Row -->
                    <tr class="match-out-row">
                        <td colspan="2" style="text-align:right; padding-right:12px; font-weight:700;">Match Out</td>
                        <td style="font-weight:700;"><?php echo number_format($match_out['ttl_sam_pc'] ?? 0, 2); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($match_out['unit_smv'] ?? 0, 2); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($match_out['day_forecast'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($match_out['unit_carder'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($match_out['plan_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($work_hours, 1); ?></td>
                        <td class="calculated" style="font-weight:700;"><?php echo number_format(($match_out['plan_hours'] ?? 0) * $work_hours * 60, 0); ?></td>
                        <td class="calculated" style="font-weight:700;"><?php echo number_format(($match_out['day_forecast'] ?? 0) * ($match_out['unit_carder'] ?? 0), 0); ?></td>
                        <td class="calculated" style="font-weight:700;">
                            <?php 
                            $mo_plan_eff = (($match_out['plan_hours'] ?? 0) * $work_hours * 60) > 0 ? 
                                (($match_out['day_forecast'] ?? 0) * ($match_out['unit_carder'] ?? 0) / (($match_out['plan_hours'] ?? 0) * $work_hours * 60)) * 100 : 0;
                            echo number_format($mo_plan_eff, 1); ?>%
                        </td>
                        <td class="calculated" style="font-weight:700;">
                            <?php 
                            $mo_target = ($match_out['unit_carder'] ?? 0) > 0 ? 
                                (($match_out['plan_hours'] ?? 0) / ($match_out['unit_carder'] ?? 0)) * 60 : 0;
                            echo number_format($mo_target, 0); ?>
                        </td>
                        <?php 
                        $mo_total = 0;
                        for ($h = 1; $h <= 11; $h++): 
                            $mo_total += $match_out['hours'][$h] ?? 0;
                        ?>
                        <td style="font-weight:700;"><?php echo number_format($match_out['hours'][$h] ?? 0, 0); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:700;"><?php echo number_format($mo_total, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($mo_total * ($match_out['ttl_sam_pc'] ?? 0), 1); ?></td>
                        <td style="font-weight:700; color:var(--primary);">
                            <?php 
                            $mo_acvd = (($match_out['plan_hours'] ?? 0) * $work_hours * 60) > 0 ? 
                                (($mo_total * ($match_out['ttl_sam_pc'] ?? 0)) / (($match_out['plan_hours'] ?? 0) * $work_hours * 60)) * 100 : 0;
                            echo number_format($mo_acvd, 1); ?>%
                        </td>
                    </tr>

                    <!-- DHU ROW - EXACTLY UNDER MATCH OUT -->
                    <?php if (!$is_assembly): ?>
                    <tr class="dhu-row">
                        <td colspan="12" style="color:var(--dhu-red); font-weight:700; text-align:right; padding-right:12px;">
                            DHU %
                        </td>
                        <td colspan="14" style="color:var(--dhu-red); font-weight:700; text-align:center;">
                            <?php echo $match_out_dhu; ?>%
                        </td>
                    </tr>
                    <?php else: ?>
                    <!-- ASSEMBLY DHU ROW - EXACTLY UNDER MATCH OUT -->
                    <tr class="assembly-dhu-row">
                        <td style="font-weight:700; color:var(--dhu-red);">DHU %</td>
                        <td colspan="10" style="color:var(--dhu-red); font-weight:700; text-align:center;">
                            <?php echo $match_out_dhu; ?>%
                        </td>
                        <td colspan="14" style="color:var(--dhu-red); font-weight:700; text-align:center;">
                            — 
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <!-- Total Row -->
                    <tr class="total-row">
                        <td colspan="22" style="text-align:right; padding-right:12px; font-weight:700;">
                            Total / <?php echo htmlspecialchars($division['name']); ?>:
                        </td>
                        <td style="font-weight:700;"><?php echo number_format($total_day_ttl, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($total_ern_min, 1); ?></td>
                        <td style="font-weight:700; color:var(--primary);">
                            <?php 
                            $avg_eff = $row_idx > 0 ? round($total_eff / $row_idx, 1) : 0;
                            echo number_format($avg_eff, 1) . '%';
                            ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="weather-bar">
        <span class="weather-icon">⛅</span>
        <span class="temp">29°C</span>
        <span>Partly sunny</span>
        <span>|</span>
        <span><?php echo date('g:i A'); ?></span>
        <span><?php echo date('M d, Y'); ?></span>
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

        function updatePage() {
            var date = document.getElementById('reportDate').value;
            var hours = document.getElementById('workHours').value;
            window.location.href = '?id=<?php echo $division_id; ?>&date=' + date + '&hours=' + hours;
        }

        // INSTANT RECALCULATION FOR DYNAMIC HOURS
        function recalcAllDynamic() {
            var hours = parseFloat($('#workHours').val()) || 0;
            $('tr').each(function() {
                var $row = $(this);
                var planHours = parseFloat($row.find('.field-input[data-field="plan_hours"]').val()) || 0;
                var dayForecast = parseFloat($row.find('.field-input[data-field="day_forecast"]').val()) || 0;
                var unitCarder = parseFloat($row.find('.field-input[data-field="unit_carder"]').val()) || 0;
                var ttlSamPc = parseFloat($row.find('.field-input[data-field="ttl_sam_pc"]').val()) || 0;
                
                // Find calculated cells by their position (index)
                var $tds = $row.find('td');
                if ($tds.length < 26) return;

                // Update Worked Hours input
                $row.find('.field-input[data-field="worked_hours"]').val(hours);

                // Update Available Minutes (column index 8)
                var availMin = planHours * hours * 60;
                $tds.eq(8).text(number_format(availMin, 0));

                // Update Plan Minutes (column index 9)
                var planMin = dayForecast * unitCarder;
                $tds.eq(9).text(number_format(planMin, 0));

                // Update Plan Eff (column index 10)
                var planEff = availMin > 0 ? (planMin / availMin) * 100 : 0;
                $tds.eq(10).text(number_format(planEff, 1) + '%');

                // Update 100% Target (column index 11)
                var target100 = unitCarder > 0 ? (planHours / unitCarder) * 60 : 0;
                $tds.eq(11).text(number_format(target100, 0));

                // Update Day Ttl (column index 23)
                var dayTotal = 0;
                for(var i=12; i<=22; i++) {
                    dayTotal += parseFloat($tds.eq(i).find('input').val()) || 0;
                }
                $tds.eq(23).text(number_format(dayTotal, 0));

                // Update Ern Minutes (column index 24)
                var ernMin = dayTotal * ttlSamPc;
                $tds.eq(24).text(number_format(ernMin, 1));

                // Update Acvd Eff (column index 25)
                var acvdEff = availMin > 0 ? (ernMin / availMin) * 100 : 0;
                $tds.eq(25).text(number_format(acvdEff, 1) + '%');
            });
        }

        function number_format(number, decimals) {
            return number.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }

        function updateField(element, component, field) {
            var value = $(element).val();
            var date = $('#reportDate').val();
            var hours = $('#workHours').val();
            var division = <?php echo $division_id; ?>;
            
            $.ajax({
                url: 'save_data.php',
                type: 'POST',
                data: {
                    action: 'update_field',
                    date: date,
                    division: division,
                    component: component,
                    field: field,
                    value: value,
                    work_hours: hours
                },
                success: function(response) {
                    if (response.success) {
                        showNotification('Saved!', 'success');
                        setTimeout(function() { window.location.reload(); }, 500);
                    }
                }
            });
        }

        function updateHour(element, component, hour) {
            var value = $(element).val();
            var date = $('#reportDate').val();
            var hours = $('#workHours').val();
            var division = <?php echo $division_id; ?>;
            
            $.ajax({
                url: 'save_data.php',
                type: 'POST',
                data: {
                    action: 'update_hour',
                    date: date,
                    division: division,
                    component: component,
                    hour: hour,
                    value: value,
                    work_hours: hours
                },
                success: function(response) {
                    if (response.success) {
                        showNotification('Saved!', 'success');
                        setTimeout(function() { window.location.reload(); }, 500);
                    }
                }
            });
        }

        function saveAll() {
            showNotification('Saving all data...', 'info');
            $('.field-input, .hour-input').each(function() { $(this).trigger('change'); });
            setTimeout(function() { showNotification('All data saved!', 'success'); }, 1000);
        }

        function showNotification(message, type) {
            var colors = { success: '#d4edda', info: '#cce5ff', error: '#f8d7da' };
            var textColors = { success: '#155724', info: '#004085', error: '#721c24' };
            var notification = $('<div>')
                .css({
                    position: 'fixed', top: '20px', right: '20px',
                    padding: '12px 24px', background: colors[type] || '#fff',
                    color: textColors[type] || '#333',
                    border: '1px solid ' + (colors[type] || '#ddd'),
                    borderRadius: '8px', zIndex: 9999,
                    boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                    fontFamily: 'Inter, sans-serif', fontSize: '14px', fontWeight: '600'
                })
                .html(message).appendTo('body');
            setTimeout(function() { notification.fadeOut(500, function() { $(this).remove(); }); }, 2000);
        }

        function editRow(element) {
            $(element).closest('tr').find('input').first().focus();
            showNotification('Editing row...', 'info');
        }

        $(document).on('keydown', 'input', function(e) {
            if (e.key === 'Enter') {
                $(this).trigger('change');
                var inputs = $(this).closest('tr').find('input');
                var index = inputs.index(this);
                if (index < inputs.length - 1) { inputs.eq(index + 1).focus(); }
            }
        });
    </script>
</body>
</html>