<?php
// division_view.php - FINAL VERSION
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$conn = getDB();
$division_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

$settings = getDateSettings($date);
if (!$settings) {
    saveDateSettings($date, 10, 0.90, $_SESSION['user_id']);
    $settings = getDateSettings($date);
}
$work_hours = $settings['working_hours'];
$targetEfficiency = $settings['target_efficiency'];

$div_sql = "SELECT * FROM divisions WHERE id = ?";
$stmt = $conn->prepare($div_sql);
$stmt->execute([$division_id]);
$division = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$division) { header('Location: dashboard.php'); exit; }

$is_assembly = ($division['type'] === 'assembly');
$components = getComponents($conn, $division_id);
$component_data = [];

foreach ($components as $comp) {
    if ($comp['is_match_out']) continue;
    $data = getReportData($conn, $division_id, $comp['id'], $date);
    if (!empty($data)) {
        $data['worked_hours'] = $work_hours;
        $data['day_forecast'] = calculateDayForecast($data['unit_carder'], $work_hours, $data['unit_smv'], $targetEfficiency);
        $data['available_minutes'] = calculateAvailableMinutes($data['unit_carder'], $data['plan_hours']);
        $data['plan_minutes'] = calculatePlanMinutes($data['day_forecast'], $data['unit_smv']);
        $data['plan_eff'] = calculatePlanEfficiency($data['plan_minutes'], $data['available_minutes']);
        $data['target_100'] = calculateTarget100($data['unit_carder'], $data['unit_smv']);
        $day_total = calculateDayTotal($data, $work_hours);
        $data['day_total'] = $day_total;
        $data['ern_minutes'] = calculateEarnedMinutes($data['day_total'], $data['unit_smv']);
        $data['acvd_eff'] = calculateAchievedEfficiency($data['ern_minutes'], $data['available_minutes'], $data['plan_hours'], $data['worked_hours']);
    }
    $component_data[$comp['id']] = $data;
}

$stats = getDivisionStats($conn, $division_id, $date, $work_hours);
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
        .bg-shapes { position: fixed; top: 0; left: 0; width: 100%; height: 100%; overflow: hidden; z-index: 0; pointer-events: none; }
        .shape { position: absolute; border-radius: 50%; opacity: 0.08; animation: float 25s infinite ease-in-out; }
        .shape-1 { width: 500px; height: 500px; background: var(--primary); top: -150px; right: -150px; }
        .shape-2 { width: 300px; height: 300px; background: var(--primary); bottom: -100px; left: -100px; animation-delay: -8s; }
        .shape-3 { width: 200px; height: 200px; background: var(--primary); top: 50%; left: 50%; transform: translate(-50%, -50%); animation-delay: -15s; }
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            25% { transform: translate(60px, -60px) scale(1.1); }
            50% { transform: translate(-40px, 40px) scale(0.9); }
            75% { transform: translate(30px, 30px) scale(1.05); }
        }
        .topbar { position: relative; z-index: 10; background: var(--glass-bg); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border-bottom: 1px solid var(--glass-border); padding: 10px 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
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
        .btn-back:hover { background: rgba(255,255,255,0.8); }
        .btn-refresh { background: rgba(255,255,255,0.5); color: var(--text-dark); border: 1px solid var(--glass-border); }
        .btn-refresh:hover { background: rgba(255,255,255,0.8); }
        .btn-save { background: var(--primary); color: #fff; }
        .btn-save:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 4px 15px rgba(33,115,70,0.3); }
        .btn-export { background: rgba(255,255,255,0.5); color: var(--text-dark); border: 1px solid var(--glass-border); }
        .btn-export:hover { background: rgba(255,255,255,0.8); }
        
        .table-container { background: var(--glass-bg); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid var(--glass-border); border-radius: var(--border-radius); overflow: hidden; box-shadow: var(--shadow); overflow-x: auto; margin-bottom: 16px; }
        .table-title { padding: 12px 20px; background: rgba(255,255,255,0.2); border-bottom: 2px solid var(--primary); font-weight: 700; font-size: 14px; color: var(--text-dark); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .table-title .badge-info { font-weight: 500; font-size: 13px; color: var(--steel); }
        
        .excel-table { width: 100%; border-collapse: collapse; font-size: 11px; min-width: 1850px; }
        .excel-table th { background: rgba(255,255,255,0.3); border: 1px solid var(--glass-border); padding: 6px 4px; text-align: center; font-weight: 700; color: var(--text-dark); font-size: 10px; white-space: nowrap; position: sticky; top: 0; z-index: 10; }
        .excel-table td { border: 1px solid var(--glass-border); padding: 4px 3px; text-align: center; white-space: nowrap; font-size: 11px; font-weight: 500; }
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
        
        .excel-table .editable-yellow input { width: 100%; border: none; background: transparent; text-align: center; padding: 3px 2px; font-size: 11px; font-weight: 600; font-family: 'Inter', sans-serif; min-width: 40px; }
        .excel-table .editable-yellow input:focus { outline: 2px solid var(--primary); outline-offset: -2px; background: rgba(255,255,255,0.9); }
        .excel-table .editable-yellow input:hover { background: rgba(255, 235, 59, 0.5); }
        .excel-table .edit-link { color: var(--primary); text-decoration: none; font-weight: 600; font-size: 10px; cursor: pointer; margin-left: 4px; }
        .excel-table .edit-link:hover { text-decoration: underline; }
        
        .scroll-indicator { text-align: center; padding: 6px; background: rgba(255, 193, 7, 0.1); color: #856404; font-size: 11px; font-weight: 500; border-bottom: 1px solid rgba(255, 193, 7, 0.2); }
        
        .weather-bar { display: flex; justify-content: flex-end; align-items: center; gap: 16px; padding: 8px 30px; background: var(--glass-bg); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border-top: 1px solid var(--glass-border); font-size: 13px; color: var(--steel); margin-top: 16px; font-weight: 500; }
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
            <a href="division_view.php?id=<?php echo $division_id; ?>&date=<?php echo $date; ?>" class="active">Production</a>
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
                        <th style="min-width:65px;">Day Forecast 90%</th>
                        <th style="min-width:65px;">Unit Carder</th>
                        <th style="min-width:65px;">Plan Hours</th>
                        <th style="min-width:65px;">Worked Hours</th>
                        <th style="min-width:70px;">Available Minutes</th>
                        <th style="min-width:65px;">Plan Minutes</th>
                        <th style="min-width:55px;">Plan Eff</th>
                        <th style="min-width:60px;">100% Target</th>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <th style="min-width:40px;"><?php echo $h; ?>st</th>
                        <?php endfor; ?>
                        <th style="min-width:55px;">Day Ttl</th>
                        <th style="min-width:65px;">Ern Minutes</th>
                        <th style="min-width:65px;">Acvd Eff</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($components)): ?>
                    <tr><td colspan="<?php echo 12 + $work_hours + 3; ?>" style="padding:30px; color:var(--steel); text-align:center; font-weight:500;">No components found.</td></tr>
                    <?php else: ?>
                    
                    <?php if (!$is_assembly): ?>
                    <!-- COMPONENT ROWS -->
                    <?php 
                    $total_day_ttl = 0;
                    $total_ern_min = 0;
                    $total_eff = 0;
                    $row_idx = 0;
                    
                    foreach ($components as $comp):
                        if ($comp['is_match_out']) continue;
                        $row_idx++;
                        $data = $component_data[$comp['id']] ?? [];
                        
                        $day_total = $data['day_total'] ?? 0;
                        $ern_minutes = $data['ern_minutes'] ?? 0;
                        $acvd_eff = $data['acvd_eff'] ?? 0;
                        
                        $total_day_ttl += $day_total;
                        $total_ern_min += $ern_minutes;
                        $total_eff += $acvd_eff;
                    ?>
                    <tr class="component-row" data-row-id="<?php echo $comp['id']; ?>">
                        <td style="text-align:left; padding-left:8px;"><?php echo htmlspecialchars($division['name']); ?></td>
                        <td><?php echo htmlspecialchars($comp['name']); ?> <span class="edit-link" onclick="editRow(this)">Edit</span></td>
                        <td class="editable-yellow"><input type="number" step="0.01" class="field-input" oninput="recalcAll(this)" data-component="<?php echo $comp['id']; ?>" data-field="ttl_sam_pc" value="<?php echo $data['ttl_sam_pc'] ?? 0; ?>" onchange="updateField(this, '<?php echo $comp['id']; ?>', 'ttl_sam_pc')"></td>
                        <td class="editable-yellow"><input type="number" step="0.01" class="field-input" oninput="recalcAll(this)" data-component="<?php echo $comp['id']; ?>" data-field="unit_smv" value="<?php echo $data['unit_smv'] ?? 0; ?>" onchange="updateField(this, '<?php echo $comp['id']; ?>', 'unit_smv')"></td>
                        <td class="calculated"><?php echo number_format($data['day_forecast'] ?? 0, 0); ?></td>
                        <td class="editable-yellow"><input type="number" class="field-input" oninput="recalcAll(this)" data-component="<?php echo $comp['id']; ?>" data-field="unit_carder" value="<?php echo $data['unit_carder'] ?? 0; ?>" onchange="updateField(this, '<?php echo $comp['id']; ?>', 'unit_carder')"></td>
                        <td class="editable-yellow"><input type="number" step="0.5" class="field-input" oninput="recalcAll(this)" data-component="<?php echo $comp['id']; ?>" data-field="plan_hours" value="<?php echo $data['plan_hours'] ?? 0; ?>" onchange="updateField(this, '<?php echo $comp['id']; ?>', 'plan_hours')"></td>
                        <td class="editable-yellow"><input type="number" step="0.5" class="field-input" data-component="<?php echo $comp['id']; ?>" data-field="worked_hours" value="<?php echo $work_hours; ?>" onchange="updateField(this, '<?php echo $comp['id']; ?>', 'worked_hours')"></td>
                        <td class="calculated"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated"><?php echo number_format($data['plan_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated" style="font-weight:700;"><?php echo number_format(($data['plan_eff'] ?? 0) * 100, 1); ?>%</td>
                        <td class="calculated" style="font-weight:700;"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td class="editable-yellow"><input type="number" class="hour-input" oninput="recalcAll(this)" data-component="<?php echo $comp['id']; ?>" data-hour="<?php echo $h; ?>" value="<?php echo $data["hour_$h"] ?? 0; ?>" onchange="updateHour(this, '<?php echo $comp['id']; ?>', <?php echo $h; ?>)"></td>
                        <?php endfor; ?>
                        <td class="calculated" style="font-weight:700;"><?php echo number_format($day_total, 0); ?></td>
                        <td class="calculated" style="font-weight:700;"><?php echo number_format($ern_minutes, 1); ?></td>
                        <td class="calculated" style="font-weight:700; color:var(--primary);"><?php echo number_format($acvd_eff * 100, 1); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <!-- MATCH OUT ROW -->
                    <tr class="match-out-row" id="match-out-row">
                        <td colspan="2" style="text-align:right; padding-right:12px; font-weight:700;">Match Out</td>
                        <td style="font-weight:700;">—</td>
                        <td class="match-out-smv" style="font-weight:700;">0.00</td>
                        <td class="match-out-forecast" style="font-weight:700;">0</td>
                        <td class="match-out-carder" style="font-weight:700;">0</td>
                        <td class="match-out-plan" style="font-weight:700;">0.0</td>
                        <td class="match-out-worked" style="font-weight:700;">0.0</td>
                        <td class="match-out-avail" style="font-weight:700;">0</td>
                        <td class="match-out-planmin" style="font-weight:700;">0</td>
                        <td class="match-out-planeff" style="font-weight:700;">0.0%</td>
                        <td class="match-out-target" style="font-weight:700;">0</td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td class="match-out-hour-<?php echo $h; ?>" style="font-weight:700;">0</td>
                        <?php endfor; ?>
                        <td class="match-out-daytotal" style="font-weight:700;">0</td>
                        <td class="match-out-ern" style="font-weight:700;">0.0</td>
                        <td class="match-out-acvd" style="font-weight:700; color:var(--primary);">0.0%</td>
                    </tr>

                    <!-- DHU ROW -->
                    <tr class="dhu-row">
                        <td colspan="2" style="color:var(--dhu-red); font-weight:700; text-align:right; padding-right:12px;">DHU</td>
                        <td style="color:var(--dhu-red); font-weight:700; text-align:center;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; text-align:center;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; text-align:center;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; text-align:center;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; text-align:center;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; text-align:center;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; text-align:center;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; text-align:center;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; text-align:center;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; text-align:center;">—</td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td class="editable-yellow" style="background:rgba(255,0,0,0.1);"><input type="number" class="dhu-hour-input" oninput="recalcAll(this)" data-hour="<?php echo $h; ?>" value="0" style="color:var(--dhu-red);"></td>
                        <?php endfor; ?>
                        <td class="dhu-daytotal" style="color:var(--dhu-red); font-weight:700;">0</td>
                        <td style="color:var(--dhu-red); font-weight:700; text-align:center;">—</td>
                        <td class="dhu-percent" style="color:var(--dhu-red); font-weight:700;">0.0%</td>
                    </tr>
                    
                    <!-- Total Row -->
                    <tr class="total-row">
                        <td colspan="<?php echo 11 + $work_hours; ?>" style="text-align:right; padding-right:12px; font-weight:700;">
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
                    
                    <?php else: ?>
                    <!-- ASSEMBLY SECTION PLACEHOLDER -->
                    <tr><td colspan="<?php echo 12 + $work_hours + 3; ?>" style="padding:30px; color:var(--steel); text-align:center; font-weight:500;">Assemble section layout will be added when you provide the assemble formulas.</td></tr>
                    <?php endif; ?>
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

        function recalcAll(element) {
            recalcComponentRows();
            recalcMatchOut();
            recalcDHU();
        }

        function recalcComponentRows() {
            var hours = parseInt($('#workHours').val()) || 10;
            var targetEff = 0.90;

            $('.component-row').each(function() {
                var $row = $(this);
                var ttlSamPc = parseFloat($row.find('[data-field="ttl_sam_pc"]').val()) || 0;
                var unitSmv = parseFloat($row.find('[data-field="unit_smv"]').val()) || 0;
                var unitCarder = parseFloat($row.find('[data-field="unit_carder"]').val()) || 0;
                var planHours = parseFloat($row.find('[data-field="plan_hours"]').val()) || 0;
                var workedHours = parseFloat($row.find('[data-field="worked_hours"]').val()) || 0;

                var dayForecast = unitSmv > 0 ? (unitCarder * hours * 60 * targetEff) / unitSmv : 0;
                var availMin = unitCarder * planHours * 60;
                var planMin = dayForecast * unitSmv;
                var planEff = availMin > 0 ? (planMin / availMin) * 100 : 0;
                var target100 = unitSmv > 0 ? (unitCarder / unitSmv) * 60 : 0;

                var dayTotal = 0;
                for (var h = 1; h <= hours; h++) {
                    var val = parseFloat($row.find('.hour-input[data-hour="'+h+'"]').val()) || 0;
                    dayTotal += val;
                }

                var ernMin = dayTotal * unitSmv;
                var denominator = availMin * workedHours;
                var acvdEff = denominator > 0 ? (ernMin * planHours) / denominator : 0;

                var $tds = $row.find('td');
                if ($tds.length >= 25) {
                    $tds.eq(4).text(number_format(dayForecast, 0));
                    $tds.eq(8).text(number_format(availMin, 0));
                    $tds.eq(9).text(number_format(planMin, 0));
                    $tds.eq(10).text(number_format(planEff, 1) + '%');
                    $tds.eq(11).text(number_format(target100, 0));
                    $tds.eq(22).text(number_format(dayTotal, 0));
                    $tds.eq(23).text(number_format(ernMin, 1));
                    $tds.eq(24).text(number_format(acvdEff * 100, 1) + '%');
                }
            });
        }

        function recalcMatchOut() {
            var hours = parseInt($('#workHours').val()) || 10;
            var targetEff = 0.90;
            var $rows = $('.component-row');

            var smvSum = 0, carderSum = 0, planSum = 0, workedSum = 0;
            var count = $rows.length;
            var hourlySums = Array(hours+1).fill(0);

            $rows.each(function() {
                var $row = $(this);
                smvSum += parseFloat($row.find('[data-field="unit_smv"]').val()) || 0;
                carderSum += parseFloat($row.find('[data-field="unit_carder"]').val()) || 0;
                planSum += parseFloat($row.find('[data-field="plan_hours"]').val()) || 0;
                workedSum += parseFloat($row.find('[data-field="worked_hours"]').val()) || 0;
                for (var h = 1; h <= hours; h++) {
                    hourlySums[h] += parseFloat($row.find('.hour-input[data-hour="'+h+'"]').val()) || 0;
                }
            });

            var avgPlan = count > 0 ? planSum / count : 0;
            var avgWorked = count > 0 ? workedSum / count : 0;
            var hourlyAvgs = Array(hours+1).fill(0);
            for (var h = 1; h <= hours; h++) {
                hourlyAvgs[h] = count > 0 ? hourlySums[h] / count : 0;
            }

            var moDayForecast = smvSum > 0 ? (carderSum * hours * 60 * targetEff) / smvSum : 0;
            var moAvail = carderSum * avgPlan * 60;
            var moPlanMin = moDayForecast * smvSum;
            var moPlanEff = moAvail > 0 ? (moPlanMin / moAvail) * 100 : 0;
            var moTarget = smvSum > 0 ? (carderSum / smvSum) * 60 : 0;
            var moDayTotal = 0;
            for (var h = 1; h <= hours; h++) {
                moDayTotal += hourlyAvgs[h];
            }
            var moErn = moDayTotal * smvSum;
            var denominator = moAvail * avgWorked;
            var moAcvd = denominator > 0 ? (moErn * avgPlan) / denominator : 0;

            var $moRow = $('#match-out-row');
            $moRow.find('.match-out-smv').text(number_format(smvSum, 2));
            $moRow.find('.match-out-forecast').text(number_format(moDayForecast, 0));
            $moRow.find('.match-out-carder').text(number_format(carderSum, 0));
            $moRow.find('.match-out-plan').text(number_format(avgPlan, 1));
            $moRow.find('.match-out-worked').text(number_format(avgWorked, 1));
            $moRow.find('.match-out-avail').text(number_format(moAvail, 0));
            $moRow.find('.match-out-planmin').text(number_format(moPlanMin, 0));
            $moRow.find('.match-out-planeff').text(number_format(moPlanEff, 1) + '%');
            $moRow.find('.match-out-target').text(number_format(moTarget, 0));
            for (var h = 1; h <= hours; h++) {
                $moRow.find('.match-out-hour-'+h).text(number_format(hourlyAvgs[h], 0));
            }
            $moRow.find('.match-out-daytotal').text(number_format(moDayTotal, 0));
            $moRow.find('.match-out-ern').text(number_format(moErn, 1));
            $moRow.find('.match-out-acvd').text(number_format(moAcvd * 100, 1) + '%');
        }

        function recalcDHU() {
            var hours = parseInt($('#workHours').val()) || 10;
            var dhuTotal = 0;
            for (var h = 1; h <= hours; h++) {
                dhuTotal += parseFloat($('.dhu-hour-input[data-hour="'+h+'"]').val()) || 0;
            }
            var moDayTotal = parseFloat($('#match-out-row .match-out-daytotal').text().replace(/,/g, '')) || 0;
            var dhuPercent = moDayTotal > 0 ? (dhuTotal / moDayTotal) * 100 : 0;

            $('.dhu-daytotal').text(number_format(dhuTotal, 0));
            $('.dhu-percent').text(number_format(dhuPercent, 1) + '%');
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
                    }
                }
            });
        }

        function saveAll() {
            showNotification('Saving all data...', 'info');
            $('.field-input, .hour-input, .dhu-hour-input').each(function() { $(this).trigger('change'); });
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

        $(document).ready(function() {
            recalcAll();
        });
    </script>
</body>
</html>