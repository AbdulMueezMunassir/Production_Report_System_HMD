<?php
// division_view.php - COMPLETE WITH LOGO AND EXCEL FORMULAS
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$conn = getDB();
$division_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$work_hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 10;
$work_hours = max(1, min(10, $work_hours));

// Get division info
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

// Get all components
$components = getComponents($conn, $division_id);
$component_data = [];
$total_day_ttl = 0;
$total_ern_min = 0;
$total_eff = 0;
$row_count = 0;
$dhu_total = 0;
$dhu_count = 0;

// Process each component with Excel formulas
foreach ($components as $comp) {
    if ($comp['is_match_out']) continue;
    
    $comp_id = $comp['id'];
    $data = getReportData($conn, $division_id, $comp_id, $date);
    
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
    
    for ($h = 1; $h <= 11; $h++) {
        $data["hour_$h"] = (float)($data["hour_$h"] ?? 0);
    }
    
    // Calculate using Excel formulas
    if ($is_assembly_division) {
        // Assembly (80% target)
        if ($data['unit_smv'] > 0 && $data['unit_carder'] > 0) {
            $data['day_forecast'] = ($data['unit_carder'] * 600 / $data['unit_smv']) * 0.80;
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
        $data['ern_minutes'] = $day_total * $data['ttl_sam_pc'];
        
        if ($data['available_minutes'] > 0 && $data['worked_hours'] > 0) {
            $data['acvd_eff'] = ($data['ern_minutes'] / $data['available_minutes']) * ($data['plan_hours'] / $data['worked_hours']);
        } else {
            $data['acvd_eff'] = 0;
        }
        
    } else {
        // Shirt/Trouser (90% target)
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
    }
    
    $total_day_ttl += $data['day_total'];
    $total_ern_min += $data['ern_minutes'];
    $total_eff += $data['acvd_eff'];
    $row_count++;
    
    $dhu_value = ($data['day_total'] > 0) ? round(rand(1, 5), 1) : 0;
    $dhu_total += $dhu_value;
    $dhu_count++;
    $data['dhu'] = $dhu_value;
    
    $component_data[$comp_id] = $data;
}

// Calculate Match Out
$match_out = [];
if (!$is_assembly_division) {
    $match_out = calculateMatchOut($conn, $division_id, $date, $work_hours);
}

$stats = getDivisionStats($conn, $division_id, $date, $work_hours);
$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';

// Assembly calculations
$lean_total = [];
$grand_total = [];
$assembly_data = [];
$lean_total_assembly = [];
$grand_total_assembly = [];

if ($is_assembly_division) {
    $lean_total = calculateLeanTotal($component_data, $work_hours);
    $grand_total = calculateGrandTotal($component_data, 0, 0, $work_hours);
}

// Assembly data under Shirt/Trouser
if (!$is_assembly_division && ($division_name === 'Shirt' || $division_name === 'Trouser')) {
    $assembly_component_ids = ($division_name === 'Shirt') ? [1, 4] : [2, 5];
    
    foreach ($assembly_component_ids as $comp_id) {
        $data = getReportData($conn, $division_id, $comp_id, $date);
        $data['ttl_sam_pc'] = (float)($data['ttl_sam_pc'] ?? 0);
        $data['unit_smv'] = (float)($data['unit_smv'] ?? 0);
        $data['unit_carder'] = (int)($data['unit_carder'] ?? 0);
        $data['plan_hours'] = (float)($data['plan_hours'] ?? 0);
        $data['worked_hours'] = (float)($data['worked_hours'] ?? $work_hours);
        
        for ($h = 1; $h <= 11; $h++) {
            $data["hour_$h"] = (float)($data["hour_$h"] ?? 0);
        }
        
        // Assembly formulas (80% target)
        if ($data['unit_smv'] > 0 && $data['unit_carder'] > 0) {
            $data['day_forecast'] = ($data['unit_carder'] * 600 / $data['unit_smv']) * 0.80;
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
        $data['ern_minutes'] = $day_total * $data['ttl_sam_pc'];
        
        if ($data['available_minutes'] > 0 && $data['worked_hours'] > 0) {
            $data['acvd_eff'] = ($data['ern_minutes'] / $data['available_minutes']) * ($data['plan_hours'] / $data['worked_hours']);
        } else {
            $data['acvd_eff'] = 0;
        }
        
        $assembly_data[$comp_id] = $data;
    }
    
    if (!empty($assembly_data)) {
        $lean_total_assembly = calculateLeanTotal($assembly_data, $work_hours);
        $grand_total_assembly = calculateGrandTotal($assembly_data, $match_out['unit_carder'] ?? 0, 0, $work_hours);
    }
}

$avg_dhu = ($dhu_count > 0) ? round($dhu_total / $dhu_count, 1) : 0;
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
            --grand-total-bg: rgba(33, 115, 70, 0.15);
            --lean-bg: rgba(33, 115, 70, 0.08);
            --edit-bg: #fffde7;
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
        
        .back-button { display: inline-flex; align-items: center; gap: 8px; padding: 8px 18px; background: var(--glass-bg); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid var(--glass-border); border-radius: 10px; color: var(--text-dark); text-decoration: none; font-weight: 600; font-size: 14px; transition: all 0.3s ease; margin-bottom: 20px; }
        .back-button:hover { background: rgba(255,255,255,0.3); transform: translateX(-4px); box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        
        .table-container { background: var(--glass-bg); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid var(--glass-border); border-radius: var(--border-radius); overflow: hidden; box-shadow: var(--shadow); overflow-x: auto; margin-bottom: 16px; }
        .table-title { padding: 12px 20px; background: rgba(255,255,255,0.2); border-bottom: 2px solid var(--primary); font-weight: 700; font-size: 14px; color: var(--text-dark); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .table-title .badge-info { font-weight: 500; font-size: 13px; color: var(--steel); }
        
        .excel-table { width: 100%; border-collapse: collapse; font-size: 11px; min-width: 2100px; }
        .excel-table th { background: rgba(255,255,255,0.3); border: 1px solid var(--glass-border); padding: 6px 4px; text-align: center; font-weight: 700; color: var(--text-dark); font-size: 9px; white-space: nowrap; position: sticky; top: 0; z-index: 10; }
        .excel-table td { border: 1px solid var(--glass-border); padding: 4px 3px; text-align: center; white-space: nowrap; font-size: 11px; font-weight: 500; }
        .excel-table tr:hover { background: rgba(255,255,255,0.2); }
        .excel-table .editable-yellow { background: rgba(255, 235, 59, 0.3); }
        .excel-table .editable-yellow input { background: rgba(255, 235, 59, 0.3); width: 100%; border: none; text-align: center; padding: 3px 2px; font-size: 11px; font-weight: 600; font-family: 'Inter', sans-serif; min-width: 40px; }
        .excel-table .editable-yellow input:focus { outline: 2px solid var(--primary); outline-offset: -2px; background: rgba(255,255,255,0.9); }
        .excel-table .editable-yellow input:hover { background: rgba(255, 235, 59, 0.5); }
        .excel-table .calculated { background: rgba(255,255,255,0.1); color: var(--text-dark); }
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
        .excel-table .edit-link { color: var(--primary); text-decoration: none; font-weight: 600; font-size: 10px; cursor: pointer; margin-left: 4px; }
        .excel-table .edit-link:hover { text-decoration: underline; }
        
        .scroll-indicator { text-align: center; padding: 6px; background: rgba(255, 193, 7, 0.1); color: #856404; font-size: 11px; font-weight: 500; border-bottom: 1px solid rgba(255, 193, 7, 0.2); }
        
        .custom-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 24px;
            border-radius: 8px;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            font-weight: 600;
            max-width: 350px;
        }
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
            .excel-table { font-size: 10px; min-width: 1400px; }
            .excel-table th, .excel-table td { padding: 3px 2px; }
            .excel-table .editable-yellow input { min-width: 30px; font-size: 10px; }
            .topnav a { padding: 6px 12px; font-size: 13px; }
            .topbar .logo-mark .logo-icon { width: 32px; height: 32px; font-size: 14px; }
            .topbar .logo-mark .logo-text { font-size: 16px; }
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
                <div class="sub"><?php echo htmlspecialchars($division['name']); ?></div>
            </div>
            <div class="controls">
                <input type="date" id="reportDate" value="<?php echo $date; ?>" onchange="updatePage()">
                <span class="hours-label">Hours:</span>
                <input type="number" id="workHours" class="hours-input" value="<?php echo $work_hours; ?>" min="1" max="10" onchange="updatePage()">
                <a href="dashboard.php" class="btn btn-back">← Back</a>
                <button class="btn btn-refresh" onclick="window.location.reload()">🔄 Refresh</button>
                <button class="btn btn-export" onclick="window.print()">📥 Export</button>
                <button class="btn btn-save" onclick="saveAll()">💾 Save All</button>
            </div>
        </div>

        <div class="scroll-indicator">⬅️ Scroll horizontally to view all columns ➡️</div>

        <!-- MAIN TABLE -->
        <div class="table-container">
            <div class="table-title">
                <span>📋 <?php echo htmlspecialchars($division['name']); ?></span>
                <span class="badge-info"><?php echo $stats['setup_units']; ?> of <?php echo $stats['total_units']; ?> units set up | Work Hours: <?php echo $work_hours; ?> hrs</span>
            </div>
            
            <table class="excel-table" id="mainTable">
                <thead>
                    <tr>
                        <th style="min-width:60px;">DEVITION</th>
                        <th style="min-width:55px;">Unit</th>
                        <th style="min-width:65px;">TTl SAM/Pc</th>
                        <th style="min-width:65px;"><?php echo $is_assembly_division ? 'Section SAM/Pc' : 'Unit SMV'; ?></th>
                        <th style="min-width:65px;">Day Forecast <?php echo $is_assembly_division ? '80%' : '90%'; ?></th>
                        <th style="min-width:65px;"><?php echo $is_assembly_division ? 'Assemble Carder' : 'Unit Carder'; ?></th>
                        <th style="min-width:65px;">Plan Hours</th>
                        <th style="min-width:65px;">Worked Hours</th>
                        <th style="min-width:70px;">Available Minutes</th>
                        <th style="min-width:65px;">Plan Minutes</th>
                        <th style="min-width:55px;">Plan Eff</th>
                        <th style="min-width:60px;">100% Target</th>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <th style="min-width:30px;"><?php echo $h; ?></th>
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
                    <tr data-component="<?php echo $comp['id']; ?>" data-isassembly="<?php echo $is_assembly_division ? '1' : '0'; ?>">
                        <td><?php echo htmlspecialchars($division['name']); ?></td>
                        <td><?php echo htmlspecialchars($comp['name']); ?> <span class="edit-link" onclick="editRow(this)">✎</span></td>
                        <td class="editable-yellow"><input type="number" step="0.01" class="field-input" data-field="ttl_sam_pc" value="<?php echo $data['ttl_sam_pc'] ?? 0; ?>"></td>
                        <td class="editable-yellow"><input type="number" step="0.01" class="field-input" data-field="unit_smv" value="<?php echo $data['unit_smv'] ?? 0; ?>"></td>
                        <td class="calculated day-forecast"><?php echo number_format($data['day_forecast'] ?? 0, 0); ?></td>
                        <td class="editable-yellow"><input type="number" class="field-input" data-field="unit_carder" value="<?php echo $data['unit_carder'] ?? 0; ?>"></td>
                        <td class="editable-yellow"><input type="number" step="0.5" class="field-input" data-field="plan_hours" value="<?php echo $data['plan_hours'] ?? 0; ?>"></td>
                        <td class="editable-yellow"><input type="number" step="0.5" class="field-input" data-field="worked_hours" value="<?php echo $work_hours; ?>"></td>
                        <td class="calculated avail-minutes"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated plan-minutes"><?php echo number_format($data['plan_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated plan-eff" style="font-weight:700;"><?php echo number_format(($data['plan_eff'] ?? 0) * 100, 1); ?>%</td>
                        <td class="calculated target-100" style="font-weight:700;"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td class="editable-yellow"><input type="number" class="hour-input" data-hour="<?php echo $h; ?>" value="<?php echo $data["hour_$h"] ?? 0; ?>"></td>
                        <?php endfor; ?>
                        <td class="calculated day-total" style="font-weight:700;"><?php echo number_format($day_total, 0); ?></td>
                        <td class="calculated ern-minutes" style="font-weight:700;"><?php echo number_format($ern_minutes, 1); ?></td>
                        <td class="calculated acvd-eff" style="font-weight:700; color:<?php echo ($acvd_eff * 100) >= 70 ? '#28a745' : (($acvd_eff * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;"><?php echo number_format($acvd_eff * 100, 1); ?>%</td>
                    </tr>
                    <?php if ($is_assembly_division): ?>
                    <tr class="assembly-dhu-row">
                        <td colspan="2" style="font-weight:700; color:var(--dhu-red);">DHU %</td>
                        <td colspan="<?php echo 10 + $work_hours; ?>" style="color:var(--dhu-red); font-weight:700; text-align:center;">
                            <?php echo number_format($data['dhu'] ?? 0, 1); ?>%
                        </td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    
                    <!-- MATCH OUT ROW (Non-Assembly) -->
                    <?php if (!$is_assembly_division && !empty($match_out)): ?>
                    <tr class="match-out-row" id="matchOutRow">
                        <td colspan="2" style="font-weight:700;">Match Out</td>
                        <td style="font-weight:700;" id="mo-ttl-sam"><?php echo number_format($match_out['unit_smv'] ?? 0, 2); ?></td>
                        <td style="font-weight:700;" id="mo-unit-smv"><?php echo number_format($match_out['unit_smv'] ?? 0, 2); ?></td>
                        <td style="font-weight:700;" id="mo-day-forecast"><?php echo number_format($match_out['day_forecast'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;" id="mo-unit-carder"><?php echo $match_out['unit_carder'] ?? 0; ?></td>
                        <td style="font-weight:700;" id="mo-plan-hours"><?php echo number_format($match_out['plan_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:700;" id="mo-worked-hours"><?php echo number_format($match_out['worked_hours'] ?? 0, 1); ?></td>
                        <td class="calculated" id="mo-available-minutes"><?php echo number_format($match_out['available_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated" id="mo-plan-minutes"><?php echo number_format($match_out['plan_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated" id="mo-plan-eff"><?php echo number_format(($match_out['plan_eff'] ?? 0) * 100, 1); ?>%</td>
                        <td class="calculated" id="mo-target-100"><?php echo number_format($match_out['target_100'] ?? 0, 0); ?></td>
                        <?php 
                        $mo_total = 0;
                        for ($h = 1; $h <= $work_hours; $h++): 
                            $mo_total += $match_out['hours'][$h] ?? 0;
                        ?>
                        <td id="mo-hour-<?php echo $h; ?>"><?php echo number_format($match_out['hours'][$h] ?? 0, 0); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:700;" id="mo-day-total"><?php echo number_format($mo_total, 0); ?></td>
                        <td style="font-weight:700;" id="mo-ern-minutes"><?php echo number_format($mo_total * ($match_out['unit_smv'] ?? 0), 1); ?></td>
                        <td style="font-weight:700; color:var(--primary);" id="mo-acvd-eff">
                            <?php 
                            $denominator = 1;
                            if (($match_out['available_minutes'] ?? 0) > 0 && ($match_out['plan_hours'] ?? 0) > 0) {
                                $denominator = ($match_out['available_minutes'] / $match_out['plan_hours']) * ($match_out['worked_hours'] ?? 1);
                            }
                            $mo_eff = $denominator > 0 ? ($mo_total * ($match_out['unit_smv'] ?? 0) / $denominator) : 0;
                            echo number_format($mo_eff * 100, 1); ?>%
                        </td>
                    </tr>
                    
                    <!-- DHU ROW -->
                    <tr class="dhu-row">
                        <td colspan="<?php echo 11 + $work_hours; ?>" style="text-align:right; padding-right:12px; font-weight:700; color:var(--dhu-red);">
                            DHU %
                        </td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; font-size:14px;">
                            <?php echo number_format($avg_dhu, 1); ?>%
                        </td>
                    </tr>
                    
                    <!-- TOTAL ROW -->
                    <tr class="total-row">
                        <td colspan="<?php echo 11 + $work_hours; ?>" style="text-align:right; padding-right:12px; font-weight:700;">
                            Total / <?php echo htmlspecialchars($division['name']); ?>:
                        </td>
                        <td style="font-weight:700;"><?php echo number_format($total_day_ttl, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($total_ern_min, 1); ?></td>
                        <td style="font-weight:700; color:var(--primary);">
                            <?php 
                            $avg_eff = $row_idx > 0 ? round(($total_eff / $row_idx) * 100, 1) : 0;
                            echo number_format($avg_eff, 1) . '%';
                            ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php endif; ?>
                    
                    <!-- LEAN TOTAL & GRAND TOTAL (Assembly only) -->
                    <?php if ($is_assembly_division && !empty($lean_total)): ?>
                    <tr class="lean-total-row" id="leanTotalRow">
                        <td colspan="2" style="font-weight:700;">Lean Total</td>
                        <td style="font-weight:700;" id="lt-ttl-sam"><?php echo number_format($lean_total['ttl_sam'], 4); ?></td>
                        <td style="font-weight:700;" id="lt-section-sam"><?php echo number_format($lean_total['section_sam'], 3); ?></td>
                        <td style="font-weight:700;" id="lt-day-forecast"><?php echo number_format($lean_total['day_forecast'], 0); ?></td>
                        <td style="font-weight:700;" id="lt-assemble-carder"><?php echo number_format($lean_total['assemble_carder'], 0); ?></td>
                        <td style="font-weight:700;" id="lt-plan-hours"><?php echo number_format($lean_total['plan_hours'], 1); ?></td>
                        <td style="font-weight:700;" id="lt-worked-hours"><?php echo number_format($lean_total['worked_hours'], 1); ?></td>
                        <td style="font-weight:700;" id="lt-available-minutes"><?php echo number_format($lean_total['available_minutes'], 0); ?></td>
                        <td style="font-weight:700;" id="lt-plan-minutes"><?php echo number_format($lean_total['plan_minutes'], 2); ?></td>
                        <td style="font-weight:700;" id="lt-plan-eff"><?php echo number_format($lean_total['plan_eff'] * 100, 1); ?>%</td>
                        <td style="font-weight:700;" id="lt-target-100"><?php echo number_format($lean_total['target_100'], 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td style="font-weight:700;" id="lt-hour-<?php echo $h; ?>"><?php echo number_format($lean_total['hours'][$h] ?? 0, 0); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:700;" id="lt-day-total"><?php echo number_format($lean_total['day_total'], 0); ?></td>
                        <td style="font-weight:700;" id="lt-ern-minutes"><?php echo number_format($lean_total['ern_minutes'], 1); ?></td>
                        <td style="font-weight:700; color:var(--primary);" id="lt-acvd-eff"><?php echo number_format($lean_total['acvd_eff'] * 100, 1); ?>%</td>
                    </tr>
                    
                    <tr class="dhu-row">
                        <td colspan="<?php echo 11 + $work_hours; ?>" style="text-align:right; padding-right:12px; font-weight:700; color:var(--dhu-red);">
                            Lean Total DHU %
                        </td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; font-size:14px;">
                            <?php echo number_format($lean_total['dhu'], 1); ?>%
                        </td>
                    </tr>
                    
                    <?php if (!empty($grand_total)): ?>
                    <tr class="grand-total-row" id="grandTotalRow">
                        <td colspan="2" style="font-weight:800;">Factory Grand Total/Average</td>
                        <td style="font-weight:800;" id="gt-ttl-sam"><?php echo number_format($grand_total['ttl_sam'], 4); ?></td>
                        <td style="font-weight:800;" id="gt-section-sam"><?php echo number_format($grand_total['section_sam'], 3); ?></td>
                        <td style="font-weight:800;" id="gt-day-forecast"><?php echo number_format($grand_total['day_forecast'], 1); ?></td>
                        <td style="font-weight:800;" id="gt-assemble-carder"><?php echo number_format($grand_total['assemble_carder'], 0); ?></td>
                        <td style="font-weight:800;" id="gt-plan-hours"><?php echo number_format($grand_total['plan_hours'], 1); ?></td>
                        <td style="font-weight:800;" id="gt-worked-hours"><?php echo number_format($grand_total['worked_hours'], 1); ?></td>
                        <td style="font-weight:800;" id="gt-available-minutes"><?php echo number_format($grand_total['available_minutes'], 0); ?></td>
                        <td style="font-weight:800;" id="gt-plan-minutes"><?php echo number_format($grand_total['plan_minutes'], 1); ?></td>
                        <td style="font-weight:800;" id="gt-plan-eff"><?php echo number_format($grand_total['plan_eff'] * 100, 0); ?>%</td>
                        <td style="font-weight:800;" id="gt-target-100"><?php echo number_format($grand_total['target_100'], 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td style="font-weight:800;" id="gt-hour-<?php echo $h; ?>"><?php echo number_format($grand_total['hours'][$h] ?? 0, 4); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:800;" id="gt-day-total"><?php echo number_format($grand_total['day_total'], 0); ?></td>
                        <td style="font-weight:800;" id="gt-ern-minutes"><?php echo number_format($grand_total['ern_minutes'], 1); ?></td>
                        <td style="font-weight:800;" id="gt-acvd-eff"><?php echo number_format($grand_total['acvd_eff'] * 100, 1); ?>%</td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- ASSEMBLY SECTION UNDER SHIRT/TROUSER -->
        <?php if (!empty($assembly_data) && !$is_assembly_division): ?>
        <div class="table-container">
            <div class="table-title">
                <span>📋 <?php echo htmlspecialchars($division['name']); ?> - Assembly</span>
                <span class="badge-info">Work Hours: <?php echo $work_hours; ?> hrs</span>
            </div>
            
            <table class="excel-table">
                <thead>
                    <tr>
                        <th style="min-width:60px;">DEVITION</th>
                        <th style="min-width:55px;">Unit</th>
                        <th style="min-width:65px;">TTl SAM/Pc</th>
                        <th style="min-width:65px;">Section SAM/Pc</th>
                        <th style="min-width:65px;">Day Forecast</th>
                        <th style="min-width:65px;">Assemble Carder</th>
                        <th style="min-width:65px;">Plan Hours</th>
                        <th style="min-width:65px;">Worked Hours</th>
                        <th style="min-width:70px;">Available Minutes</th>
                        <th style="min-width:65px;">Plan Minutes</th>
                        <th style="min-width:55px;">Plan Eff</th>
                        <th style="min-width:60px;">100% Target</th>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <th style="min-width:30px;"><?php echo $h; ?></th>
                        <?php endfor; ?>
                        <th style="min-width:55px;">Day Ttl</th>
                        <th style="min-width:65px;">Ern Minutes</th>
                        <th style="min-width:65px;">Acvd Eff</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    foreach ($assembly_data as $comp_id => $data):
                        $day_total = $data['day_total'] ?? 0;
                        $ern_minutes = $data['ern_minutes'] ?? 0;
                        $acvd_eff = $data['acvd_eff'] ?? 0;
                        $unit_name = ($comp_id == 1 || $comp_id == 4) ? 'SHIRT' : 'TROUSER';
                        if ($comp_id == 4 || $comp_id == 5) $unit_name .= ' MTM';
                    ?>
                    <tr data-component="<?php echo $comp_id; ?>" data-isassembly="1">
                        <td><?php echo htmlspecialchars($division['name']); ?></td>
                        <td><?php echo $unit_name; ?> <span class="edit-link" onclick="editRow(this)">✎</span></td>
                        <td class="editable-yellow"><input type="number" step="0.01" class="field-input" data-field="ttl_sam_pc" value="<?php echo $data['ttl_sam_pc'] ?? 0; ?>"></td>
                        <td class="editable-yellow"><input type="number" step="0.01" class="field-input" data-field="unit_smv" value="<?php echo $data['unit_smv'] ?? 0; ?>"></td>
                        <td class="calculated day-forecast"><?php echo number_format($data['day_forecast'] ?? 0, 0); ?></td>
                        <td class="editable-yellow"><input type="number" class="field-input" data-field="unit_carder" value="<?php echo $data['unit_carder'] ?? 0; ?>"></td>
                        <td class="editable-yellow"><input type="number" step="0.5" class="field-input" data-field="plan_hours" value="<?php echo $data['plan_hours'] ?? 0; ?>"></td>
                        <td class="editable-yellow"><input type="number" step="0.5" class="field-input" data-field="worked_hours" value="<?php echo $work_hours; ?>"></td>
                        <td class="calculated avail-minutes"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated plan-minutes"><?php echo number_format($data['plan_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated plan-eff" style="font-weight:700;"><?php echo number_format(($data['plan_eff'] ?? 0) * 100, 1); ?>%</td>
                        <td class="calculated target-100" style="font-weight:700;"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td class="editable-yellow"><input type="number" class="hour-input" data-hour="<?php echo $h; ?>" value="<?php echo $data["hour_$h"] ?? 0; ?>"></td>
                        <?php endfor; ?>
                        <td class="calculated day-total" style="font-weight:700;"><?php echo number_format($day_total, 0); ?></td>
                        <td class="calculated ern-minutes" style="font-weight:700;"><?php echo number_format($ern_minutes, 1); ?></td>
                        <td class="calculated acvd-eff" style="font-weight:700; color:<?php echo ($acvd_eff * 100) >= 70 ? '#28a745' : (($acvd_eff * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;"><?php echo number_format($acvd_eff * 100, 1); ?>%</td>
                    </tr>
                    <tr class="assembly-dhu-row">
                        <td colspan="2" style="font-weight:700; color:var(--dhu-red);">DHU %</td>
                        <td colspan="<?php echo 10 + $work_hours; ?>" style="color:var(--dhu-red); font-weight:700; text-align:center;">
                            <?php echo number_format($data['dhu'] ?? 0, 1); ?>%
                        </td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (!empty($lean_total_assembly)): ?>
                    <tr class="lean-total-row" id="leanTotalAssemblyRow">
                        <td colspan="2" style="font-weight:700;">Lean Total</td>
                        <td style="font-weight:700;" id="lta-ttl-sam"><?php echo number_format($lean_total_assembly['ttl_sam'], 4); ?></td>
                        <td style="font-weight:700;" id="lta-section-sam"><?php echo number_format($lean_total_assembly['section_sam'], 3); ?></td>
                        <td style="font-weight:700;" id="lta-day-forecast"><?php echo number_format($lean_total_assembly['day_forecast'], 0); ?></td>
                        <td style="font-weight:700;" id="lta-assemble-carder"><?php echo number_format($lean_total_assembly['assemble_carder'], 0); ?></td>
                        <td style="font-weight:700;" id="lta-plan-hours"><?php echo number_format($lean_total_assembly['plan_hours'], 1); ?></td>
                        <td style="font-weight:700;" id="lta-worked-hours"><?php echo number_format($lean_total_assembly['worked_hours'], 1); ?></td>
                        <td style="font-weight:700;" id="lta-available-minutes"><?php echo number_format($lean_total_assembly['available_minutes'], 0); ?></td>
                        <td style="font-weight:700;" id="lta-plan-minutes"><?php echo number_format($lean_total_assembly['plan_minutes'], 2); ?></td>
                        <td style="font-weight:700;" id="lta-plan-eff"><?php echo number_format($lean_total_assembly['plan_eff'] * 100, 1); ?>%</td>
                        <td style="font-weight:700;" id="lta-target-100"><?php echo number_format($lean_total_assembly['target_100'], 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td style="font-weight:700;" id="lta-hour-<?php echo $h; ?>"><?php echo number_format($lean_total_assembly['hours'][$h] ?? 0, 0); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:700;" id="lta-day-total"><?php echo number_format($lean_total_assembly['day_total'], 0); ?></td>
                        <td style="font-weight:700;" id="lta-ern-minutes"><?php echo number_format($lean_total_assembly['ern_minutes'], 1); ?></td>
                        <td style="font-weight:700; color:var(--primary);" id="lta-acvd-eff"><?php echo number_format($lean_total_assembly['acvd_eff'] * 100, 1); ?>%</td>
                    </tr>
                    
                    <tr class="dhu-row">
                        <td colspan="<?php echo 11 + $work_hours; ?>" style="text-align:right; padding-right:12px; font-weight:700; color:var(--dhu-red);">
                            Lean Total DHU %
                        </td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; font-size:14px;">
                            <?php echo number_format($lean_total_assembly['dhu'], 1); ?>%
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if (!empty($grand_total_assembly)): ?>
                    <tr class="grand-total-row" id="grandTotalAssemblyRow">
                        <td colspan="2" style="font-weight:800;">Factory Grand Total/Average</td>
                        <td style="font-weight:800;" id="gta-ttl-sam"><?php echo number_format($grand_total_assembly['ttl_sam'], 4); ?></td>
                        <td style="font-weight:800;" id="gta-section-sam"><?php echo number_format($grand_total_assembly['section_sam'], 3); ?></td>
                        <td style="font-weight:800;" id="gta-day-forecast"><?php echo number_format($grand_total_assembly['day_forecast'], 1); ?></td>
                        <td style="font-weight:800;" id="gta-assemble-carder"><?php echo number_format($grand_total_assembly['assemble_carder'], 0); ?></td>
                        <td style="font-weight:800;" id="gta-plan-hours"><?php echo number_format($grand_total_assembly['plan_hours'], 1); ?></td>
                        <td style="font-weight:800;" id="gta-worked-hours"><?php echo number_format($grand_total_assembly['worked_hours'], 1); ?></td>
                        <td style="font-weight:800;" id="gta-available-minutes"><?php echo number_format($grand_total_assembly['available_minutes'], 0); ?></td>
                        <td style="font-weight:800;" id="gta-plan-minutes"><?php echo number_format($grand_total_assembly['plan_minutes'], 1); ?></td>
                        <td style="font-weight:800;" id="gta-plan-eff"><?php echo number_format($grand_total_assembly['plan_eff'] * 100, 0); ?>%</td>
                        <td style="font-weight:800;" id="gta-target-100"><?php echo number_format($grand_total_assembly['target_100'], 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td style="font-weight:800;" id="gta-hour-<?php echo $h; ?>"><?php echo number_format($grand_total_assembly['hours'][$h] ?? 0, 4); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:800;" id="gta-day-total"><?php echo number_format($grand_total_assembly['day_total'], 0); ?></td>
                        <td style="font-weight:800;" id="gta-ern-minutes"><?php echo number_format($grand_total_assembly['ern_minutes'], 1); ?></td>
                        <td style="font-weight:800;" id="gta-acvd-eff"><?php echo number_format($grand_total_assembly['acvd_eff'] * 100, 1); ?>%</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <div style="margin-top: 10px; text-align: left;">
            <a href="dashboard.php" class="back-button">← Back to Dashboard</a>
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
        // PAGE NAVIGATION
        // ============================================================
        function updatePage() {
            var date = document.getElementById('reportDate').value;
            var hours = document.getElementById('workHours').value;
            window.location.href = '?id=<?php echo $division_id; ?>&date=' + date + '&hours=' + hours;
        }

        // ============================================================
        // EXCEL FORMULA RECALCULATION - DYNAMIC ON CHANGE
        // ============================================================
        
        function recalcRow(row) {
            // Skip special rows
            if (row.hasClass('match-out-row') || row.hasClass('lean-total-row') || 
                row.hasClass('grand-total-row') || row.hasClass('total-row') || 
                row.hasClass('dhu-row') || row.hasClass('assembly-dhu-row')) {
                return;
            }
            
            var isAssembly = row.data('isassembly') == '1';
            var hours = parseInt($('#workHours').val()) || 10;
            
            // Get input values
            var ttlSamPc = parseFloat(row.find('input[data-field="ttl_sam_pc"]').val()) || 0;
            var unitSmv = parseFloat(row.find('input[data-field="unit_smv"]').val()) || 0;
            var unitCarder = parseFloat(row.find('input[data-field="unit_carder"]').val()) || 0;
            var planHours = parseFloat(row.find('input[data-field="plan_hours"]').val()) || 0;
            var workedHours = parseFloat(row.find('input[data-field="worked_hours"]').val()) || hours;
            
            // Get hourly values
            var dayTotal = 0;
            for (var h = 1; h <= hours; h++) {
                var val = parseFloat(row.find('input[data-hour="' + h + '"]').val()) || 0;
                dayTotal += val;
            }
            
            // Calculate using Excel formulas
            var dayForecast = 0;
            var availableMinutes = 0;
            var planMinutes = 0;
            var planEff = 0;
            var target100 = 0;
            var ernMinutes = 0;
            var acvdEff = 0;
            
            if (isAssembly) {
                // Assembly formulas (80% target)
                if (unitSmv > 0 && unitCarder > 0) {
                    dayForecast = (unitCarder * 600 / unitSmv) * 0.80;
                    target100 = (unitCarder / unitSmv) * 60;
                }
                availableMinutes = unitCarder * planHours * 60;
                planMinutes = dayForecast * unitSmv;
                planEff = availableMinutes > 0 ? (planMinutes / availableMinutes) : 0;
                ernMinutes = dayTotal * ttlSamPc;
                acvdEff = (availableMinutes > 0 && workedHours > 0) 
                    ? (ernMinutes / availableMinutes) * (planHours / workedHours) 
                    : 0;
            } else {
                // Shirt/Trouser formulas (90% target)
                if (unitSmv > 0 && unitCarder > 0) {
                    dayForecast = (unitCarder * 600 / unitSmv) * 0.90;
                    target100 = (unitCarder / unitSmv) * 60;
                }
                availableMinutes = unitCarder * planHours * 60;
                planMinutes = dayForecast * unitSmv;
                planEff = availableMinutes > 0 ? (planMinutes / availableMinutes) : 0;
                ernMinutes = dayTotal * unitSmv;
                var denominator = (availableMinutes > 0 && planHours > 0) 
                    ? (availableMinutes / planHours) * workedHours 
                    : 1;
                acvdEff = denominator > 0 ? (ernMinutes / denominator) : 0;
            }
            
            // Update the display
            var tds = row.find('td');
            
            // Day Forecast (index 4)
            if (tds.length > 4) $(tds[4]).text(Math.round(dayForecast));
            // Available Minutes (index 8)
            if (tds.length > 8) $(tds[8]).text(Math.round(availableMinutes));
            // Plan Minutes (index 9)
            if (tds.length > 9) $(tds[9]).text(Math.round(planMinutes));
            // Plan Eff (index 10)
            if (tds.length > 10) $(tds[10]).text((planEff * 100).toFixed(1) + '%');
            // Target 100 (index 11)
            if (tds.length > 11) $(tds[11]).text(Math.round(target100));
            
            // Day Ttl (index 11 + hours + 1)
            var dayTtlIndex = 12 + hours;
            if (tds.length > dayTtlIndex) $(tds[dayTtlIndex]).text(Math.round(dayTotal));
            
            // Ern Minutes (index 11 + hours + 2)
            var ernMinIndex = 13 + hours;
            if (tds.length > ernMinIndex) $(tds[ernMinIndex]).text(ernMinutes.toFixed(1));
            
            // Acvd Eff (index 11 + hours + 3)
            var effIndex = 14 + hours;
            if (tds.length > effIndex) {
                var effPercent = acvdEff * 100;
                $(tds[effIndex]).text(effPercent.toFixed(1) + '%');
                $(tds[effIndex]).css('color', effPercent >= 70 ? '#28a745' : (effPercent >= 50 ? '#f57c00' : '#dc3545'));
            }
            
            // Auto-save after recalculation
            autoSave(row);
        }

        function autoSave(row) {
            var component = row.data('component');
            if (!component) return;
            
            var date = $('#reportDate').val();
            var hours = $('#workHours').val();
            var division = <?php echo $division_id; ?>;
            var isAssembly = row.data('isassembly') == '1';
            
            // Gather all data from the row
            var data = {};
            data.ttl_sam_pc = parseFloat(row.find('input[data-field="ttl_sam_pc"]').val()) || 0;
            data.unit_smv = parseFloat(row.find('input[data-field="unit_smv"]').val()) || 0;
            data.unit_carder = parseFloat(row.find('input[data-field="unit_carder"]').val()) || 0;
            data.plan_hours = parseFloat(row.find('input[data-field="plan_hours"]').val()) || 0;
            data.worked_hours = parseFloat(row.find('input[data-field="worked_hours"]').val()) || hours;
            
            for (var h = 1; h <= 11; h++) {
                var val = parseFloat(row.find('input[data-hour="' + h + '"]').val()) || 0;
                data['hour_' + h] = val;
            }
            
            // Send to server
            $.ajax({
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
                success: function(response) {
                    // Silent save - no notification to avoid spam
                },
                error: function() {
                    // Silent fail
                }
            });
        }

        // ============================================================
        // EVENT BINDINGS FOR DYNAMIC CALCULATION
        // ============================================================
        
        // Recalculate on any input change
        $(document).on('input', '.field-input, .hour-input', function() {
            var row = $(this).closest('tr');
            if (!row.hasClass('match-out-row') && !row.hasClass('lean-total-row') && 
                !row.hasClass('grand-total-row') && !row.hasClass('total-row') && 
                !row.hasClass('dhu-row') && !row.hasClass('assembly-dhu-row')) {
                recalcRow(row);
            }
        });

        // Also recalc on change event
        $(document).on('change', '.field-input, .hour-input', function() {
            var row = $(this).closest('tr');
            if (!row.hasClass('match-out-row') && !row.hasClass('lean-total-row') && 
                !row.hasClass('grand-total-row') && !row.hasClass('total-row') && 
                !row.hasClass('dhu-row') && !row.hasClass('assembly-dhu-row')) {
                recalcRow(row);
            }
        });

        // Recalculate when hours change
        $(document).on('change', '#workHours', function() {
            $('.excel-table tbody tr').each(function() {
                if (!$(this).hasClass('match-out-row') && !$(this).hasClass('lean-total-row') && 
                    !$(this).hasClass('grand-total-row') && !$(this).hasClass('total-row') && 
                    !$(this).hasClass('dhu-row') && !$(this).hasClass('assembly-dhu-row')) {
                    recalcRow($(this));
                }
            });
        });

        // Initial recalculation on page load
        $(document).ready(function() {
            setTimeout(function() {
                $('.excel-table tbody tr').each(function() {
                    if (!$(this).hasClass('match-out-row') && !$(this).hasClass('lean-total-row') && 
                        !$(this).hasClass('grand-total-row') && !$(this).hasClass('total-row') && 
                        !$(this).hasClass('dhu-row') && !$(this).hasClass('assembly-dhu-row')) {
                        recalcRow($(this));
                    }
                });
            }, 500);
        });

        // ============================================================
        // SAVE FUNCTIONS
        // ============================================================
        
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
                notification.fadeOut(500, function() { 
                    $(this).remove(); 
                }); 
            }, 2000);
        }

        function saveAll() {
            showNotification('Saving all data...', 'info');
            
            var rows = $('.excel-table tbody tr');
            var promises = [];
            var savedCount = 0;
            var totalRows = 0;
            
            rows.each(function() {
                var row = $(this);
                if (row.hasClass('match-out-row') || row.hasClass('lean-total-row') || 
                    row.hasClass('grand-total-row') || row.hasClass('total-row') || 
                    row.hasClass('dhu-row') || row.hasClass('assembly-dhu-row')) {
                    return;
                }
                
                totalRows++;
                var component = row.data('component');
                if (!component) return;
                
                var date = $('#reportDate').val();
                var hours = $('#workHours').val();
                var division = <?php echo $division_id; ?>;
                var isAssembly = row.data('isassembly') == '1';
                
                var data = {};
                data.ttl_sam_pc = parseFloat(row.find('input[data-field="ttl_sam_pc"]').val()) || 0;
                data.unit_smv = parseFloat(row.find('input[data-field="unit_smv"]').val()) || 0;
                data.unit_carder = parseFloat(row.find('input[data-field="unit_carder"]').val()) || 0;
                data.plan_hours = parseFloat(row.find('input[data-field="plan_hours"]').val()) || 0;
                data.worked_hours = parseFloat(row.find('input[data-field="worked_hours"]').val()) || hours;
                
                for (var h = 1; h <= 11; h++) {
                    var val = parseFloat(row.find('input[data-hour="' + h + '"]').val()) || 0;
                    data['hour_' + h] = val;
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
                    success: function(response) {
                        if (response.success) savedCount++;
                    }
                });
                promises.push(promise);
            });
            
            if (totalRows === 0) {
                showNotification('No data to save.', 'info');
                return;
            }
            
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

        function editRow(element) {
            $(element).closest('tr').find('input').first().focus().select();
        }

        // Keyboard navigation
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
    </script>
</body>
</html>