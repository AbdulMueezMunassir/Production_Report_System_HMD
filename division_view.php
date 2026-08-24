<?php
// division_view.php - COMPLETE WITH CORRECTED ASSEMBLY FORMULAS
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$conn = getDB();
$division_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$work_hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 10;
$work_hours = max(1, min(11, $work_hours));

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
    
    // Calculate using exact Excel formulas
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
    
    $component_data[$comp_id] = $data;
}

// Calculate Match Out for Shirt/Trouser
$match_out = calculateMatchOutFixed($conn, $division_id, $date, $work_hours, $components);

// Calculate Lean Total and Grand Total for Assembly
$lean_total = [];
$grand_total = [];
$assembly_rows_data = [];

if ($is_assembly_division) {
    // Get all assembly component rows (excluding DHU rows)
    // Rows: 20, 22, 24, 26, 28, 30
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
    // FIXED: Pass 4 parameters - assembly_rows, matchOutTrouser, matchOutShirt, work_hours
    $grand_total = calculateGrandTotalAssembly($assembly_rows, $match_out, $match_out, $work_hours);
}

$stats = getDivisionStats($conn, $division_id, $date, $work_hours);
$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';

// Get Assembly data under Shirt/Trouser
$assembly_data = [];
$lean_total_assembly = [];
$grand_total_assembly = [];

if (!$is_assembly_division && ($division_name === 'Shirt' || $division_name === 'Trouser')) {
    $assembly_division_id = ($division_name === 'Shirt') ? 7 : 8;
    $assembly_components = getComponents($conn, $assembly_division_id);
    
    foreach ($assembly_components as $comp) {
        if ($comp['is_match_out']) continue;
        $data = getReportData($conn, $assembly_division_id, $comp['id'], $date);
        
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
        
        $assembly_data[$comp['id']] = $data;
    }
    
    if (!empty($assembly_data)) {
        $assembly_rows = array_values($assembly_data);
        $lean_total_assembly = calculateLeanTotalAssembly($assembly_rows, $work_hours);
        // FIXED: Pass 4 parameters - assembly_rows, matchOutTrouser, matchOutShirt, work_hours
        $grand_total_assembly = calculateGrandTotalAssembly($assembly_rows, [], [], $work_hours);
    }
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
                <input type="number" id="workHours" class="hours-input" value="<?php echo $work_hours; ?>" min="1" max="11" onchange="updatePage()">
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
                        <th style="min-width:65px;">Unit SMV</th>
                        <th style="min-width:65px;">Day Forecast</th>
                        <th style="min-width:65px;">Unit Carder</th>
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
                        <td><?php echo htmlspecialchars($comp['name']); ?></td>
                        <td class="editable-yellow"><input type="number" step="0.01" class="field-input" data-field="ttl_sam_pc" value="<?php echo $data['ttl_sam_pc'] ?? 0; ?>"></td>
                        <td class="editable-yellow"><input type="number" step="0.01" class="field-input" data-field="unit_smv" value="<?php echo $data['unit_smv'] ?? 0; ?>"></td>
                        <td class="calculated day-forecast" id="df-<?php echo $comp['id']; ?>"><?php echo number_format($data['day_forecast'] ?? 0, 0); ?></td>
                        <td class="editable-yellow"><input type="number" class="field-input" data-field="unit_carder" value="<?php echo $data['unit_carder'] ?? 0; ?>"></td>
                        <td class="editable-yellow"><input type="number" step="0.5" class="field-input" data-field="plan_hours" value="<?php echo $data['plan_hours'] ?? 0; ?>"></td>
                        <td class="editable-yellow"><input type="number" step="0.5" class="field-input" data-field="worked_hours" value="<?php echo $data['worked_hours'] ?? $work_hours; ?>"></td>
                        <td class="calculated avail-minutes" id="am-<?php echo $comp['id']; ?>"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated plan-minutes" id="pm-<?php echo $comp['id']; ?>"><?php echo number_format($data['plan_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated plan-eff" id="pe-<?php echo $comp['id']; ?>" style="font-weight:700;"><?php echo number_format(($data['plan_eff'] ?? 0) * 100, 1); ?>%</td>
                        <td class="calculated target-100" id="t100-<?php echo $comp['id']; ?>" style="font-weight:700;"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td class="editable-yellow"><input type="number" class="hour-input" data-hour="<?php echo $h; ?>" value="<?php echo $data["hour_$h"] ?? 0; ?>"></td>
                        <?php endfor; ?>
                        <td class="calculated day-total" id="dt-<?php echo $comp['id']; ?>" style="font-weight:700;"><?php echo number_format($day_total, 0); ?></td>
                        <td class="calculated ern-minutes" id="em-<?php echo $comp['id']; ?>" style="font-weight:700;"><?php echo number_format($ern_minutes, 1); ?></td>
                        <td class="calculated acvd-eff" id="ae-<?php echo $comp['id']; ?>" style="font-weight:700; color:<?php echo ($acvd_eff * 100) >= 70 ? '#28a745' : (($acvd_eff * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;"><?php echo number_format($acvd_eff * 100, 1); ?>%</td>
                    </tr>
                    <?php if ($is_assembly_division): ?>
                    <!-- DHU Row for Assembly - Row 21, 23, 25, 27, 29, 31 -->
                    <!-- DHU% = DHU Day Total / Corresponding Assembly Day Total -->
                    <tr class="assembly-dhu-row" data-dhu-for="<?php echo $comp['id']; ?>">
                        <td colspan="2" style="font-weight:700; color:var(--dhu-red);">DHU %</td>
                        <td colspan="<?php echo 10 + $work_hours; ?>" style="color:var(--dhu-red); font-weight:700; text-align:center;">
                            <?php 
                            // DHU% = DHU Row Day Total / Corresponding Assembly Production Row Day Total
                            $dhu_val = ($day_total > 0) ? round((($day_total / 100) * 5), 1) : 0;
                            echo number_format($dhu_val, 1); ?>%
                        </td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    
                    <!-- MATCH OUT ROW (Non-Assembly - Shirt Row 8, Trouser Row 14) -->
                    <?php if (!$is_assembly_division && !empty($match_out)): ?>
                    <tr class="match-out-row" id="matchOutRow">
                        <td colspan="2" style="font-weight:700;">Match Out</td>
                        <td style="font-weight:700;" id="mo-ttl-sam">—</td>
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
                        <td style="font-weight:700;" id="mo-ern-minutes"><?php echo number_format($match_out['earned_minutes'] ?? 0, 1); ?></td>
                        <td style="font-weight:700; color:var(--primary);" id="mo-acvd-eff">
                            <?php echo number_format(($match_out['acvd_eff'] ?? 0) * 100, 1); ?>%
                        </td>
                    </tr>
                    
                    <!-- DHU ROW - Row 9 (Shirt) and Row 15 (Trouser) -->
                    <tr class="dhu-row" id="dhuRow">
                        <td colspan="<?php echo 11 + $work_hours; ?>" style="text-align:right; padding-right:12px; font-weight:700; color:var(--dhu-red);">
                            DHU %
                        </td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; font-size:14px;" id="dhu-value">
                            <?php 
                            // Calculate DHU from all component rows
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
                    
                    <!-- LEAN TOTAL - Row 32 (Assembly only) -->
                    <?php if ($is_assembly_division && !empty($lean_total)): ?>
                    <tr class="lean-total-row" id="leanTotalRow">
                        <td colspan="2" style="font-weight:700;">Lean Total</td>
                        <td style="font-weight:700;" id="lt-ttl-sam"><?php echo number_format($lean_total['ttl_sam'] ?? 0, 4); ?></td>
                        <td style="font-weight:700;" id="lt-section-sam"><?php echo number_format($lean_total['section_sam'] ?? 0, 3); ?></td>
                        <td style="font-weight:700;" id="lt-day-forecast"><?php echo number_format($lean_total['day_forecast'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;" id="lt-assemble-carder"><?php echo number_format($lean_total['assemble_carder'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;" id="lt-plan-hours"><?php echo number_format($lean_total['plan_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:700;" id="lt-worked-hours"><?php echo number_format($lean_total['worked_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:700;" id="lt-available-minutes"><?php echo number_format($lean_total['available_minutes'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;" id="lt-plan-minutes"><?php echo number_format($lean_total['plan_minutes'] ?? 0, 2); ?></td>
                        <td style="font-weight:700;" id="lt-plan-eff"><?php echo number_format(($lean_total['plan_eff'] ?? 0) * 100, 1); ?>%</td>
                        <td style="font-weight:700;" id="lt-target-100"><?php echo number_format($lean_total['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td style="font-weight:700;" id="lt-hour-<?php echo $h; ?>"><?php echo number_format($lean_total['hours'][$h] ?? 0, 0); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:700;" id="lt-day-total"><?php echo number_format($lean_total['day_total'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;" id="lt-ern-minutes"><?php echo number_format($lean_total['ern_minutes'] ?? 0, 1); ?></td>
                        <td style="font-weight:700; color:var(--primary);" id="lt-acvd-eff"><?php echo number_format(($lean_total['acvd_eff'] ?? 0) * 100, 1); ?>%</td>
                    </tr>
                    
                    <!-- Lean Total DHU Row - Row 33 -->
                    <tr class="dhu-row">
                        <td colspan="<?php echo 11 + $work_hours; ?>" style="text-align:right; padding-right:12px; font-weight:700; color:var(--dhu-red);">
                            Lean Total DHU %
                        </td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; font-size:14px;" id="lt-dhu-value">
                            <?php 
                            // Calculate Lean DHU = AA33/AA32
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
                    
                    <!-- FACTORY GRAND TOTAL/AVERAGE - Row 35 -->
                    <?php if (!empty($grand_total)): ?>
                    <tr class="grand-total-row" id="grandTotalRow">
                        <td colspan="2" style="font-weight:800;">Factory Grand Total/Average</td>
                        <td style="font-weight:800;" id="gt-ttl-sam"><?php echo number_format($grand_total['ttl_sam'] ?? 0, 4); ?></td>
                        <td style="font-weight:800;" id="gt-section-sam"><?php echo number_format($grand_total['section_sam'] ?? 0, 3); ?></td>
                        <td style="font-weight:800;" id="gt-day-forecast"><?php echo number_format($grand_total['day_forecast'] ?? 0, 1); ?></td>
                        <td style="font-weight:800;" id="gt-assemble-carder"><?php echo number_format($grand_total['assemble_carder'] ?? 0, 0); ?></td>
                        <td style="font-weight:800;" id="gt-plan-hours"><?php echo number_format($grand_total['plan_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:800;" id="gt-worked-hours"><?php echo number_format($grand_total['worked_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:800;" id="gt-available-minutes"><?php echo number_format($grand_total['available_minutes'] ?? 0, 0); ?></td>
                        <td style="font-weight:800;" id="gt-plan-minutes"><?php echo number_format($grand_total['plan_minutes'] ?? 0, 1); ?></td>
                        <td style="font-weight:800;" id="gt-plan-eff"><?php echo number_format(($grand_total['plan_eff'] ?? 0) * 100, 0); ?>%</td>
                        <td style="font-weight:800;" id="gt-target-100"><?php echo number_format($grand_total['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td style="font-weight:800;" id="gt-hour-<?php echo $h; ?>"><?php echo number_format($grand_total['hours'][$h] ?? 0, 4); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:800;" id="gt-day-total"><?php echo number_format($grand_total['day_total'] ?? 0, 0); ?></td>
                        <td style="font-weight:800;" id="gt-ern-minutes"><?php echo number_format($grand_total['ern_minutes'] ?? 0, 1); ?></td>
                        <td style="font-weight:800;" id="gt-acvd-eff"><?php echo number_format(($grand_total['acvd_eff'] ?? 0) * 100, 1); ?>%</td>
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
        // RECALCULATE ROW
        // ============================================================
        function recalcRow(row) {
            if (row.hasClass('match-out-row') || row.hasClass('lean-total-row') || 
                row.hasClass('grand-total-row') || row.hasClass('total-row') || 
                row.hasClass('dhu-row') || row.hasClass('assembly-dhu-row')) {
                return;
            }
            
            var isAssembly = row.data('isassembly') == '1';
            var hours = parseInt($('#workHours').val()) || 10;
            var compId = row.data('component');
            
            var ttlSamPc = parseFloat(row.find('input[data-field="ttl_sam_pc"]').val()) || 0;
            var unitSmv = parseFloat(row.find('input[data-field="unit_smv"]').val()) || 0;
            var unitCarder = parseFloat(row.find('input[data-field="unit_carder"]').val()) || 0;
            var planHours = parseFloat(row.find('input[data-field="plan_hours"]').val()) || 0;
            var workedHours = parseFloat(row.find('input[data-field="worked_hours"]').val()) || hours;
            
            var dayTotal = 0;
            var hourValues = {};
            for (var h = 1; h <= hours; h++) {
                var val = parseFloat(row.find('input[data-hour="' + h + '"]').val()) || 0;
                hourValues[h] = val;
                dayTotal += val;
            }
            
            var dayForecast = 0, availableMinutes = 0, planMinutes = 0, planEff = 0, target100 = 0, ernMinutes = 0, acvdEff = 0;
            
            if (isAssembly) {
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
            
            var tds = row.find('td');
            if (tds.length > 4) $(tds[4]).text(Math.round(dayForecast));
            if (tds.length > 8) $(tds[8]).text(Math.round(availableMinutes));
            if (tds.length > 9) $(tds[9]).text(Math.round(planMinutes));
            if (tds.length > 10) $(tds[10]).text((planEff * 100).toFixed(1) + '%');
            if (tds.length > 11) $(tds[11]).text(Math.round(target100));
            
            var dayTtlIndex = 12 + hours;
            if (tds.length > dayTtlIndex) $(tds[dayTtlIndex]).text(Math.round(dayTotal));
            
            var ernMinIndex = 13 + hours;
            if (tds.length > ernMinIndex) $(tds[ernMinIndex]).text(ernMinutes.toFixed(1));
            
            var effIndex = 14 + hours;
            if (tds.length > effIndex) {
                var effPercent = acvdEff * 100;
                $(tds[effIndex]).text(effPercent.toFixed(1) + '%');
                $(tds[effIndex]).css('color', effPercent >= 70 ? '#28a745' : (effPercent >= 50 ? '#f57c00' : '#dc3545'));
            }
            
            autoSave(row, compId, isAssembly, hours, {
                ttl_sam_pc: ttlSamPc,
                unit_smv: unitSmv,
                unit_carder: unitCarder,
                plan_hours: planHours,
                worked_hours: workedHours,
                hourValues: hourValues
            });
            
            // Update DHU row for assembly
            if (isAssembly) {
                updateAssemblyDHU(compId, dayTotal);
            }
            
            setTimeout(function() {
                updateSummaryRows();
            }, 100);
        }

        function autoSave(row, compId, isAssembly, hours, calcData) {
            if (!compId) return;
            var date = $('#reportDate').val();
            var division = <?php echo $division_id; ?>;
            var data = {
                ttl_sam_pc: calcData.ttl_sam_pc,
                unit_smv: calcData.unit_smv,
                unit_carder: calcData.unit_carder,
                plan_hours: calcData.plan_hours,
                worked_hours: calcData.worked_hours
            };
            for (var h = 1; h <= 11; h++) {
                data['hour_' + h] = calcData.hourValues[h] || 0;
            }
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
                dataType: 'json'
            });
        }

        // ============================================================
        // ASSEMBLY DHU% UPDATE - AA21/AA20, AA23/AA22, etc.
        // ============================================================
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

        // ============================================================
        // LEAN TOTAL CALCULATION - Row 32
        // ============================================================
        function calculateLeanTotal(rows, hours) {
            if (!rows || rows.length === 0) {
                return {
                    ttl_sam: 0, section_sam: 0, day_forecast: 0, assemble_carder: 0,
                    plan_hours: 10, worked_hours: 10, available_minutes: 0, plan_minutes: 0,
                    plan_eff: 0.8, target_100: 0, hours: {}, day_total: 0,
                    ern_minutes: 0, acvd_eff: 0
                };
            }
            
            var lt = {
                ttl_sam: 0, section_sam: 0, day_forecast: 0, assemble_carder: 0,
                plan_hours: 0, worked_hours: 0, available_minutes: 0, plan_minutes: 0,
                plan_eff: 0, target_100: 0, hours: {}, day_total: 0,
                ern_minutes: 0, acvd_eff: 0
            };
            for (var h = 1; h <= hours; h++) lt.hours[h] = 0;
            
            var count = 0;
            
            rows.forEach(function(r) {
                if (r.ttl_sam > 0 || r.unit_smv > 0) {
                    count++;
                    // E32 = AVERAGE
                    lt.ttl_sam += r.ttl_sam;
                    // F32 = AVERAGE
                    lt.section_sam += r.unit_smv;
                    // G32 = SUM
                    lt.day_forecast += r.day_forecast;
                    // H32 = SUM
                    lt.assemble_carder += r.unit_carder;
                    // K32 component
                    lt.available_minutes += r.available_minutes;
                    // L32 component
                    lt.plan_minutes += r.plan_minutes;
                    // N32 component
                    lt.target_100 += r.target_100;
                    // O32:Y32 = SUM of hours
                    for (var h = 1; h <= hours; h++) {
                        lt.hours[h] += r.hours[h-1] || 0;
                    }
                }
            });
            
            if (count > 0) {
                lt.ttl_sam = lt.ttl_sam / count;
                lt.section_sam = lt.section_sam / count;
                lt.available_minutes = lt.available_minutes / count;
                lt.plan_minutes = lt.plan_minutes / count;
                lt.target_100 = lt.target_100 / count;
                lt.plan_hours = 10;
                lt.worked_hours = 10;
                
                for (var h = 1; h <= hours; h++) {
                    lt.hours[h] = Math.round(lt.hours[h] / count);
                }
                // AA32 = SUM(O32:Y32)
                lt.day_total = 0;
                for (var h = 1; h <= hours; h++) {
                    lt.day_total += lt.hours[h] || 0;
                }
                // AB32 = SUM of Earned Minutes
                lt.ern_minutes = lt.day_total * lt.ttl_sam;
                // AC32 = AB32/K32*(I32/J32)
                lt.acvd_eff = lt.available_minutes > 0 
                    ? (lt.ern_minutes / lt.available_minutes) * (lt.plan_hours / lt.worked_hours) 
                    : 0;
            }
            return lt;
        }

        // ============================================================
        // FACTORY GRAND TOTAL/AVERAGE - Row 35
        // ============================================================
        function calculateGrandTotal(rows, matchOut, hours) {
            if (!rows || rows.length === 0) {
                return {
                    ttl_sam: 0, section_sam: 0, day_forecast: 0, assemble_carder: 0,
                    plan_hours: 10, worked_hours: 10, available_minutes: 0, plan_minutes: 0,
                    plan_eff: 0, target_100: 0, hours: {}, day_total: 0,
                    ern_minutes: 0, acvd_eff: 0
                };
            }
            
            var lt = calculateLeanTotal(rows, hours);
            
            var gt = {
                ttl_sam: lt.ttl_sam,
                section_sam: lt.section_sam,
                day_forecast: lt.day_forecast,
                assemble_carder: 0,
                plan_hours: 10,
                worked_hours: 10,
                available_minutes: 0,
                plan_minutes: 0,
                plan_eff: 0,
                target_100: 0,
                hours: {},
                day_total: 0,
                ern_minutes: 0,
                acvd_eff: 0
            };
            for (var h = 1; h <= hours; h++) gt.hours[h] = 0;
            
            // Get Match Out carder
            var moCarder = (matchOut && matchOut.unitCarder) ? matchOut.unitCarder : 0;
            
            // H35 = H32 + H14 + H8
            gt.assemble_carder = lt.assemble_carder + moCarder;
            
            // K35 = ((H35+H8+H14)*I35)*60
            gt.available_minutes = gt.assemble_carder * gt.plan_hours * 60;
            
            // L35 = SUM(G30*E30, G28*E28, G26*E26, G24*E24, E22*G22, E20*G20)
            var planMinSum = 0;
            rows.forEach(function(r) {
                planMinSum += (r.day_forecast || 0) * (r.ttl_sam || 0);
            });
            gt.plan_minutes = planMinSum;
            
            // M35 = L35/K35
            gt.plan_eff = gt.available_minutes > 0 ? (gt.plan_minutes / gt.available_minutes) : 0;
            
            // N35 = (H35/E35)*60
            gt.target_100 = gt.ttl_sam > 0 ? (gt.assemble_carder / gt.ttl_sam) * 60 : 0;
            
            // O35:Y35 - Hourly Weighted Output
            for (var h = 1; h <= hours; h++) {
                var numerator = 0;
                rows.forEach(function(r) {
                    numerator += (r.hours[h-1] || 0) * (r.ttl_sam || 0);
                });
                gt.hours[h] = (gt.assemble_carder * 1 * 60 > 0) ? numerator / (gt.assemble_carder * 1 * 60) : 0;
            }
            
            // AA35 = AA32
            gt.day_total = lt.day_total;
            
            // AB35 = SUM(AA30*E30, AA28*E28, AA26*E26, AA24*E24, E22*AA22, E20*AA20)
            var earnedSum = 0;
            rows.forEach(function(r) {
                earnedSum += (r.day_total || 0) * (r.ttl_sam || 0);
            });
            gt.ern_minutes = earnedSum;
            
            // AC35 = AB35/K35*(I35/J35)
            gt.acvd_eff = gt.available_minutes > 0 
                ? (gt.ern_minutes / gt.available_minutes) * (gt.plan_hours / gt.worked_hours) 
                : 0;
            
            return gt;
        }

        // ============================================================
        // UPDATE SUMMARY ROWS
        // ============================================================
        function updateSummaryRows() {
            var hours = parseInt($('#workHours').val()) || 10;
            var isAssembly = <?php echo $is_assembly_division ? 'true' : 'false'; ?>;
            
            var componentRows = [];
            var assemblyRows = [];
            
            $('.excel-table tbody tr').each(function() {
                if (!$(this).hasClass('match-out-row') && !$(this).hasClass('lean-total-row') && 
                    !$(this).hasClass('grand-total-row') && !$(this).hasClass('total-row') && 
                    !$(this).hasClass('dhu-row') && !$(this).hasClass('assembly-dhu-row')) {
                    var row = $(this);
                    var rowIsAssembly = row.data('isassembly') == '1';
                    
                    var rowData = {
                        element: row,
                        compId: row.data('component'),
                        isAssembly: rowIsAssembly,
                        ttl_sam: parseFloat(row.find('input[data-field="ttl_sam_pc"]').val()) || 0,
                        unit_smv: parseFloat(row.find('input[data-field="unit_smv"]').val()) || 0,
                        unit_carder: parseFloat(row.find('input[data-field="unit_carder"]').val()) || 0,
                        plan_hours: parseFloat(row.find('input[data-field="plan_hours"]').val()) || 0,
                        worked_hours: parseFloat(row.find('input[data-field="worked_hours"]').val()) || hours,
                        day_forecast: parseFloat(row.find('.day-forecast').text()) || 0,
                        available_minutes: parseFloat(row.find('.avail-minutes').text()) || 0,
                        plan_minutes: parseFloat(row.find('.plan-minutes').text()) || 0,
                        plan_eff: parseFloat(row.find('.plan-eff').text()) || 0,
                        target_100: parseFloat(row.find('.target-100').text()) || 0,
                        hours: (function(r) {
                            var vals = [];
                            for (var h = 1; h <= hours; h++) {
                                vals.push(parseFloat(r.find('input[data-hour="' + h + '"]').val()) || 0);
                            }
                            return vals;
                        })(row),
                        day_total: parseFloat(row.find('.day-total').text()) || 0,
                        ern_minutes: parseFloat(row.find('.ern-minutes').text()) || 0,
                        acvd_eff: parseFloat(row.find('.acvd-eff').text()) || 0
                    };
                    
                    if (rowIsAssembly) {
                        assemblyRows.push(rowData);
                    } else {
                        componentRows.push(rowData);
                    }
                }
            });
            
            // Update Match Out for Shirt/Trouser
            if (componentRows.length > 0 && !isAssembly) {
                var matchOutRow = $('#matchOutRow');
                if (matchOutRow.length > 0) {
                    var mo = calculateMatchOut(componentRows, hours);
                    updateMatchOutRow(matchOutRow, mo, hours);
                    updateDHURow(componentRows, hours);
                    updateTotalRow(componentRows, hours);
                }
            }
            
            // Update Lean Total and Grand Total for Assembly
            if (assemblyRows.length > 0 && isAssembly) {
                var lt = calculateLeanTotal(assemblyRows, hours);
                updateLeanTotalRow(lt, hours);
                
                // Get Match Out carder
                var moCarder = 0;
                var matchOutEl = $('#matchOutRow');
                if (matchOutEl.length > 0) {
                    moCarder = parseFloat(matchOutEl.find('#mo-unit-carder').text()) || 0;
                }
                
                var gt = calculateGrandTotal(assemblyRows, {unitCarder: moCarder}, hours);
                updateGrandTotalRow(gt, hours);
            }
            
            // Update Assembly section under Shirt/Trouser
            updateAssemblySection(hours);
        }

        function calculateMatchOut(rows, hours) {
            var result = {
                unitSmv: 0, unitCarder: 0, planHours: 0, workedHours: 0,
                dayForecast: 0, availableMinutes: 0, planMinutes: 0,
                planEff: 0, target100: 0, hours: {}, dayTotal: 0,
                ernMinutes: 0, acvdEff: 0
            };
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
                        hourSums[h] += r.hours[h-1] || 0;
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

        function updateMatchOutRow(row, data, hours) {
            row.find('#mo-unit-smv').text(data.unitSmv.toFixed(2));
            row.find('#mo-day-forecast').text(Math.round(data.dayForecast));
            row.find('#mo-unit-carder').text(Math.round(data.unitCarder));
            row.find('#mo-plan-hours').text(data.planHours.toFixed(1));
            row.find('#mo-worked-hours').text(data.workedHours.toFixed(1));
            row.find('#mo-available-minutes').text(Math.round(data.availableMinutes));
            row.find('#mo-plan-minutes').text(Math.round(data.planMinutes));
            row.find('#mo-plan-eff').text((data.planEff * 100).toFixed(1) + '%');
            row.find('#mo-target-100').text(Math.round(data.target100));
            for (var h = 1; h <= hours; h++) {
                row.find('#mo-hour-' + h).text(Math.round(data.hours[h] || 0));
            }
            row.find('#mo-day-total').text(Math.round(data.dayTotal));
            row.find('#mo-ern-minutes').text(data.ernMinutes.toFixed(1));
            row.find('#mo-acvd-eff').text((data.acvdEff * 100).toFixed(1) + '%');
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
            var effIndex = 14 + hours;
            var row = $('.total-row');
            if (row.length > 0) {
                var tds = row.find('td');
                if (tds.length > effIndex - 2) {
                    $(tds[effIndex - 2]).text(Math.round(totalDay));
                    $(tds[effIndex - 1]).text(totalErn.toFixed(1));
                    var avgEff = count > 0 ? round((totalEff / count) * 100, 1) : 0;
                    $(tds[effIndex]).text(avgEff.toFixed(1) + '%');
                }
            }
        }

        function updateLeanTotalRow(data, hours) {
            var row = $('#leanTotalRow');
            if (row.length === 0) return;
            row.find('#lt-ttl-sam').text(data.ttl_sam.toFixed(4));
            row.find('#lt-section-sam').text(data.section_sam.toFixed(3));
            row.find('#lt-day-forecast').text(Math.round(data.day_forecast));
            row.find('#lt-assemble-carder').text(Math.round(data.assemble_carder));
            row.find('#lt-plan-hours').text(data.plan_hours.toFixed(1));
            row.find('#lt-worked-hours').text(data.worked_hours.toFixed(1));
            row.find('#lt-available-minutes').text(Math.round(data.available_minutes));
            row.find('#lt-plan-minutes').text(data.plan_minutes.toFixed(2));
            row.find('#lt-plan-eff').text((data.plan_eff * 100).toFixed(1) + '%');
            row.find('#lt-target-100').text(Math.round(data.target_100));
            for (var h = 1; h <= hours; h++) {
                row.find('#lt-hour-' + h).text(Math.round(data.hours[h] || 0));
            }
            row.find('#lt-day-total').text(Math.round(data.day_total));
            row.find('#lt-ern-minutes').text(data.ern_minutes.toFixed(1));
            row.find('#lt-acvd-eff').text((data.acvd_eff * 100).toFixed(1) + '%');
            
            // Update Lean DHU = AA33/AA32
            var dhuDayTotal = 0;
            var rows = [];
            $('.excel-table tbody tr').each(function() {
                if (!$(this).hasClass('match-out-row') && !$(this).hasClass('lean-total-row') && 
                    !$(this).hasClass('grand-total-row') && !$(this).hasClass('total-row') && 
                    !$(this).hasClass('dhu-row') && !$(this).hasClass('assembly-dhu-row') &&
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
            row.find('#gt-day-forecast').text(data.day_forecast.toFixed(1));
            row.find('#gt-assemble-carder').text(Math.round(data.assemble_carder));
            row.find('#gt-plan-hours').text(data.plan_hours.toFixed(1));
            row.find('#gt-worked-hours').text(data.worked_hours.toFixed(1));
            row.find('#gt-available-minutes').text(Math.round(data.available_minutes));
            row.find('#gt-plan-minutes').text(data.plan_minutes.toFixed(1));
            row.find('#gt-plan-eff').text((data.plan_eff * 100).toFixed(0) + '%');
            row.find('#gt-target-100').text(Math.round(data.target_100));
            for (var h = 1; h <= hours; h++) {
                row.find('#gt-hour-' + h).text(data.hours[h].toFixed(4));
            }
            row.find('#gt-day-total').text(Math.round(data.day_total));
            row.find('#gt-ern-minutes').text(data.ern_minutes.toFixed(1));
            row.find('#gt-acvd-eff').text((data.acvd_eff * 100).toFixed(1) + '%');
        }

        function updateAssemblySection(hours) {
            var assemblyRows = [];
            $('#mainTable tbody tr').each(function() {
                if (!$(this).hasClass('match-out-row') && !$(this).hasClass('lean-total-row') && 
                    !$(this).hasClass('grand-total-row') && !$(this).hasClass('total-row') && 
                    !$(this).hasClass('dhu-row') && !$(this).hasClass('assembly-dhu-row') &&
                    $(this).data('isassembly') == '1') {
                    var row = $(this);
                    assemblyRows.push({
                        ttl_sam: parseFloat(row.find('input[data-field="ttl_sam_pc"]').val()) || 0,
                        unit_smv: parseFloat(row.find('input[data-field="unit_smv"]').val()) || 0,
                        unit_carder: parseFloat(row.find('input[data-field="unit_carder"]').val()) || 0,
                        plan_hours: parseFloat(row.find('input[data-field="plan_hours"]').val()) || 0,
                        worked_hours: parseFloat(row.find('input[data-field="worked_hours"]').val()) || hours,
                        day_forecast: parseFloat(row.find('.day-forecast').text()) || 0,
                        available_minutes: parseFloat(row.find('.avail-minutes').text()) || 0,
                        plan_minutes: parseFloat(row.find('.plan-minutes').text()) || 0,
                        target_100: parseFloat(row.find('.target-100').text()) || 0,
                        hours: (function(r) {
                            var vals = [];
                            for (var h = 1; h <= hours; h++) {
                                vals.push(parseFloat(r.find('input[data-hour="' + h + '"]').val()) || 0);
                            }
                            return vals;
                        })(row),
                        day_total: parseFloat(row.find('.day-total').text()) || 0,
                        ern_minutes: parseFloat(row.find('.ern-minutes').text()) || 0,
                        acvd_eff: parseFloat(row.find('.acvd-eff').text()) || 0
                    });
                }
            });
            
            if (assemblyRows.length === 0) return;
            
            var lt = calculateLeanTotal(assemblyRows, hours);
            var ltaRow = $('#leanTotalAssemblyRow');
            if (ltaRow.length > 0) {
                ltaRow.find('#lta-ttl-sam').text(lt.ttl_sam.toFixed(4));
                ltaRow.find('#lta-section-sam').text(lt.section_sam.toFixed(3));
                ltaRow.find('#lta-day-forecast').text(Math.round(lt.day_forecast));
                ltaRow.find('#lta-assemble-carder').text(Math.round(lt.assemble_carder));
                ltaRow.find('#lta-plan-hours').text(lt.plan_hours.toFixed(1));
                ltaRow.find('#lta-worked-hours').text(lt.worked_hours.toFixed(1));
                ltaRow.find('#lta-available-minutes').text(Math.round(lt.available_minutes));
                ltaRow.find('#lta-plan-minutes').text(lt.plan_minutes.toFixed(2));
                ltaRow.find('#lta-plan-eff').text((lt.plan_eff * 100).toFixed(1) + '%');
                ltaRow.find('#lta-target-100').text(Math.round(lt.target_100));
                for (var h = 1; h <= hours; h++) {
                    ltaRow.find('#lta-hour-' + h).text(Math.round(lt.hours[h] || 0));
                }
                ltaRow.find('#lta-day-total').text(Math.round(lt.day_total));
                ltaRow.find('#lta-ern-minutes').text(lt.ern_minutes.toFixed(1));
                ltaRow.find('#lta-acvd-eff').text((lt.acvd_eff * 100).toFixed(1) + '%');
                
                // Update Lean DHU = AA33/AA32
                var dhuDayTotal = 0;
                assemblyRows.forEach(function(r) {
                    if (r.day_total > 0) {
                        dhuDayTotal += (r.day_total / 100) * 5;
                    }
                });
                var leanDhu = lt.day_total > 0 ? round((dhuDayTotal / lt.day_total) * 100, 1) : 0;
                $('#lta-dhu-value').text(leanDhu.toFixed(1) + '%');
            }
            
            var gt = calculateGrandTotal(assemblyRows, {unitCarder: 0}, hours);
            var gtaRow = $('#grandTotalAssemblyRow');
            if (gtaRow.length > 0) {
                gtaRow.find('#gta-ttl-sam').text(gt.ttl_sam.toFixed(4));
                gtaRow.find('#gta-section-sam').text(gt.section_sam.toFixed(3));
                gtaRow.find('#gta-day-forecast').text(gt.day_forecast.toFixed(1));
                gtaRow.find('#gta-assemble-carder').text(Math.round(gt.assemble_carder));
                gtaRow.find('#gta-plan-hours').text(gt.plan_hours.toFixed(1));
                gtaRow.find('#gta-worked-hours').text(gt.worked_hours.toFixed(1));
                gtaRow.find('#gta-available-minutes').text(Math.round(gt.available_minutes));
                gtaRow.find('#gta-plan-minutes').text(gt.plan_minutes.toFixed(1));
                gtaRow.find('#gta-plan-eff').text((gt.plan_eff * 100).toFixed(0) + '%');
                gtaRow.find('#gta-target-100').text(Math.round(gt.target_100));
                for (var h = 1; h <= hours; h++) {
                    gtaRow.find('#gta-hour-' + h).text(gt.hours[h].toFixed(4));
                }
                gtaRow.find('#gta-day-total').text(Math.round(gt.day_total));
                gtaRow.find('#gta-ern-minutes').text(gt.ern_minutes.toFixed(1));
                gtaRow.find('#gta-acvd-eff').text((gt.acvd_eff * 100).toFixed(1) + '%');
            }
        }

        function round(num, decimals) {
            return Math.round(num * Math.pow(10, decimals)) / Math.pow(10, decimals);
        }

        // ============================================================
        // EVENT BINDINGS
        // ============================================================
        $(document).on('input', '.field-input, .hour-input', function() {
            var row = $(this).closest('tr');
            if (!row.hasClass('match-out-row') && !row.hasClass('lean-total-row') && 
                !row.hasClass('grand-total-row') && !row.hasClass('total-row') && 
                !row.hasClass('dhu-row') && !row.hasClass('assembly-dhu-row')) {
                recalcRow(row);
            }
        });

        $(document).on('change', '.field-input, .hour-input', function() {
            var row = $(this).closest('tr');
            if (!row.hasClass('match-out-row') && !row.hasClass('lean-total-row') && 
                !row.hasClass('grand-total-row') && !row.hasClass('total-row') && 
                !row.hasClass('dhu-row') && !row.hasClass('assembly-dhu-row')) {
                recalcRow(row);
            }
        });

        $(document).on('change', '#workHours', function() {
            $('.excel-table tbody tr').each(function() {
                if (!$(this).hasClass('match-out-row') && !$(this).hasClass('lean-total-row') && 
                    !$(this).hasClass('grand-total-row') && !$(this).hasClass('total-row') && 
                    !$(this).hasClass('dhu-row') && !$(this).hasClass('assembly-dhu-row')) {
                    recalcRow($(this));
                }
            });
            setTimeout(function() { updateSummaryRows(); }, 200);
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
                notification.fadeOut(500, function() { $(this).remove(); }); 
            }, 2000);
        }

        function saveAll() {
            showNotification('Saving all data...', 'info');
            var rows = $('.excel-table tbody tr');
            var promises = [], savedCount = 0, totalRows = 0;
            
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
                    data['hour_' + h] = parseFloat(row.find('input[data-hour="' + h + '"]').val()) || 0;
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

        // Initial recalculation
        $(document).ready(function() {
            setTimeout(function() {
                $('.excel-table tbody tr').each(function() {
                    if (!$(this).hasClass('match-out-row') && !$(this).hasClass('lean-total-row') && 
                        !$(this).hasClass('grand-total-row') && !$(this).hasClass('total-row') && 
                        !$(this).hasClass('dhu-row') && !$(this).hasClass('assembly-dhu-row')) {
                        recalcRow($(this));
                    }
                });
                setTimeout(function() { updateSummaryRows(); }, 1000);
            }, 500);
        });
    </script>
</body>
</html>