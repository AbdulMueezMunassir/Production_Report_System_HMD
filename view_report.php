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

// For Assembly division, display as "Assembly"
if ($is_assembly_division) {
    $division_name = 'Assembly';
}

// Get work hours from URL or default to 10
$work_hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 10;
$work_hours = max(1, min(11, $work_hours));

// ============================================================
// GET ALL COMPONENTS FOR THIS DIVISION
// ============================================================
$components = getComponents($conn, $division_id);
$component_data = [];

// Define the correct order for each division
$shirt_order = ['Front', 'Back', 'Collar', 'Sleeve', 'Cuff'];
$assembly_order = ['SHIRT', 'SHIRT MTM', 'TROUSER', 'TROUSER MTM', 'COAT', 'COAT MTM', 'KNIT'];

// Process each component
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
    $data['epm'] = (float)($data['epm'] ?? 13.2);
    $data['style_epm'] = (float)($data['style_epm'] ?? 13.2);
    $data['profit'] = (float)($data['profit'] ?? 0);
    
    for ($h = 1; $h <= 11; $h++) {
        $data["hour_$h"] = (float)($data["hour_$h"] ?? 0);
    }
    
    $component_data[$comp_id] = $data;
}

// Calculate Match Out
$match_out = calculateMatchOutFixed($conn, $division_id, $date, $work_hours, $components);

// ============================================================
// GET ASSEMBLY DATA (for Shirt/Trouser pages)
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

// Collect all assembly data
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
    
    $assembly_all_data[$comp['name']] = $data;
}

// Assign data to variables
$assembly_shirt_row = isset($assembly_all_data['SHIRT']) ? $assembly_all_data['SHIRT'] : null;
$assembly_shirt_mtm_row = isset($assembly_all_data['SHIRT MTM']) ? $assembly_all_data['SHIRT MTM'] : null;
$assembly_trouser_row = isset($assembly_all_data['TROUSER']) ? $assembly_all_data['TROUSER'] : null;
$assembly_trouser_mtm_row = isset($assembly_all_data['TROUSER MTM']) ? $assembly_all_data['TROUSER MTM'] : null;
$assembly_coat_row = isset($assembly_all_data['COAT']) ? $assembly_all_data['COAT'] : null;
$assembly_coat_mtm_row = isset($assembly_all_data['COAT MTM']) ? $assembly_all_data['COAT MTM'] : null;
$assembly_knit_row = isset($assembly_all_data['KNIT']) ? $assembly_all_data['KNIT'] : null;

// Get KNIT component ID for Assembly
$knit_comp_id = 0;
if ($is_assembly_division) {
    foreach ($assembly_components as $comp) {
        if ($comp['name'] === 'KNIT') {
            $knit_comp_id = $comp['id'];
            break;
        }
    }
}

// ============================================================
// GET SUMMARY ROWS (MO, DHU, Lean Total, Grand Total)
// ============================================================
$summary_rows = [];
$stmt = $conn->prepare("SELECT * FROM production_reports WHERE devition_id = ? AND report_date = ? AND unit_id IN (996, 997, 998, 999)");
$stmt->execute([$division_id, $date]);
$summary_rows_result = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($summary_rows_result as $row) {
    if ($row['unit_id'] == 999) {
        $summary_rows['match_out'] = $row;
    } elseif ($row['unit_id'] == 998) {
        $summary_rows['dhu'] = $row;
    } elseif ($row['unit_id'] == 997) {
        $summary_rows['lean_total'] = $row;
    } elseif ($row['unit_id'] == 996) {
        $summary_rows['grand_total'] = $row;
    }
}

// Get Shirt and Trouser Match Out for Assembly
$shirt_match_out = [];
$trouser_match_out = [];
if ($is_assembly_division || $division_name === 'Shirt' || $division_name === 'Trouser') {
    try {
        $shirt_components = getComponents($conn, 1);
        $shirt_match_out = calculateMatchOutFixed($conn, 1, $date, $work_hours, $shirt_components);
    } catch (Exception $e) {
        $shirt_match_out = [];
    }
    try {
        $trouser_components = getComponents($conn, 2);
        $trouser_match_out = calculateMatchOutFixed($conn, 2, $date, $work_hours, $trouser_components);
    } catch (Exception $e) {
        $trouser_match_out = [];
    }
}

// Calculate Lean Total and Grand Total for Assembly
$lean_total = [];
$grand_total = [];
if ($is_assembly_division) {
    $assembly_rows = [];
    foreach ($components as $comp) {
        if ($comp['is_match_out']) continue;
        $data = $component_data[$comp['id']] ?? [];
        if ($data['ttl_sam_pc'] > 0 || $data['unit_smv'] > 0) {
            $assembly_rows[] = $data;
        }
    }
    $lean_total = calculateLeanTotalAssembly($assembly_rows, $work_hours);
    $grand_total = calculateGrandTotalAssembly($assembly_rows, $trouser_match_out, $shirt_match_out, $work_hours);
}

$stats = getDivisionStats($conn, $division_id, $date, $work_hours);
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
            --primary: #217346;
            --primary-dark: #1a5c3a;
            --bg: #f0f2f5;
            --text: #1a2332;
            --text-dark: #0d1a2b;
            --steel: #6b7a8f;
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

        .container { max-width: 100%; margin: 0 auto; padding: 20px 30px; }
        
        .report-header {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: 20px 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
        }
        .report-header h2 { font-size: 22px; font-weight: 800; color: var(--text-dark); }
        .report-header .meta {
            display: flex;
            gap: 24px;
            margin-top: 8px;
            flex-wrap: wrap;
        }
        .report-header .meta span { color: var(--steel); font-size: 14px; font-weight: 500; }
        .report-header .meta strong { color: var(--text-dark); font-weight: 700; }
        .report-header .summary-badges {
            display: flex;
            gap: 8px;
            margin-top: 8px;
            flex-wrap: wrap;
        }
        .report-header .summary-badges .badge {
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            color: #fff;
        }
        .badge-mo { background: #6c757d; }
        .badge-dhu { background: var(--dhu-red); }
        .badge-lean { background: #17a2b8; }
        .badge-grand { background: #6f42c1; }
        
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
            min-width: 2300px;
        }
        .excel-table th {
            background: rgba(255,255,255,0.3);
            border: 1px solid var(--glass-border);
            padding: 6px 4px;
            text-align: center;
            font-weight: 700;
            color: var(--text-dark);
            font-size: 9px;
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
        .excel-table .calculated { background: rgba(255,255,255,0.08); }
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
        .excel-table .section-divider td {
            background: rgba(33, 115, 70, 0.1) !important;
            font-weight: 700 !important;
            color: var(--text-dark) !important;
            padding: 8px 4px !important;
            border-top: 2px solid var(--primary) !important;
            border-bottom: 2px solid var(--primary) !important;
        }
        .excel-table .value-good { color: var(--good); font-weight: 700; }
        .excel-table .value-bad { color: var(--bad); font-weight: 700; }
        .excel-table .value-avg { color: var(--warning); font-weight: 700; }
        
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 18px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
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
        
        .no-data { text-align: center; padding: 30px; color: var(--steel); font-size: 14px; font-weight: 500; }
        .print-hide { display: inline; }
        
        @media print {
            .topbar, .weather-bar, .back-button, .page-header .controls { display: none !important; }
            .container { padding: 0 !important; }
            .report-header { box-shadow: none !important; border: 1px solid #ddd !important; }
            .table-container { box-shadow: none !important; border: 1px solid #ddd !important; }
            .excel-table th { background: #f0f0f0 !important; }
            .excel-table td { border-color: #ccc !important; }
        }
        
        @media (max-width: 768px) {
            .topbar { padding: 10px 16px; flex-direction: column; align-items: stretch; gap: 8px; }
            .topnav { justify-content: center; }
            .right { justify-content: center; }
            .container { padding: 12px 16px; }
            .report-header .meta { gap: 12px; }
            .excel-table { font-size: 10px; min-width: 1500px; }
            .topnav a { padding: 6px 12px; font-size: 13px; }
            .weather-bar { padding: 8px 16px; justify-content: center; flex-wrap: wrap; }
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
            <h2>📋 <?php echo htmlspecialchars($division_name); ?> - Detail Report</h2>
            <div class="meta">
                <span><strong>Date:</strong> <?php echo date('Y-m-d', strtotime($date)); ?></span>
                <span><strong>Working Hours:</strong> <?php echo $work_hours; ?> hrs</span>
                <span><strong>Components:</strong> <?php echo count($component_data); ?></span>
                <span><strong>Status:</strong> <span style="color:var(--good);">Saved</span></span>
            </div>
            <div class="summary-badges">
                <?php if (isset($summary_rows['match_out']) && $summary_rows['match_out']['unit_smv'] > 0): ?>
                <span class="badge badge-mo">✅ Match Out</span>
                <?php endif; ?>
                <?php if (isset($summary_rows['dhu']) && $summary_rows['dhu']['day_total'] > 0): ?>
                <span class="badge badge-dhu">✅ DHU</span>
                <?php endif; ?>
                <?php if (isset($summary_rows['lean_total']) && $summary_rows['lean_total']['ttl_sam_pc'] > 0): ?>
                <span class="badge badge-lean">✅ Lean Total</span>
                <?php endif; ?>
                <?php if (isset($summary_rows['grand_total']) && $summary_rows['grand_total']['ttl_sam_pc'] > 0): ?>
                <span class="badge badge-grand">✅ Factory Grand Total</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-container">
            <div class="table-title">
                <span>📊 <?php echo htmlspecialchars($division_name); ?> - Production Report (Read-Only)</span>
                <span class="badge-info">Work Hours: <?php echo $work_hours; ?> hrs | Saved: <?php echo date('Y-m-d', strtotime($date)); ?></span>
            </div>
            
            <table class="excel-table">
                <thead>
                    <tr>
                        <th style="min-width:60px;">DEVITION</th>
                        <th style="min-width:55px;">Unit</th>
                        <th style="min-width:65px;">TTl SAM/Pcs</th>
                        <th style="min-width:65px;"><?php echo $is_assembly_division ? 'Section SAM/Pc' : 'Unit SMV'; ?></th>
                        <th style="min-width:65px;">Day Forecast</th>
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
                        <th style="min-width:55px;">Day Total</th>
                        <th style="min-width:65px;">Earn Minutes</th>
                        <th style="min-width:65px;">Achieved Eff %</th>
                        <th style="min-width:55px;"><?php echo $is_assembly_division ? 'Style EPM' : 'EPM'; ?></th>
                        <th style="min-width:65px;">Profit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($components)): ?>
                    <tr><td colspan="<?php echo 14 + $work_hours + 3; ?>" class="no-data">No components found for this division.</td></tr>
                    <?php else: ?>
                    
                    <?php 
                    $total_day_ttl = 0;
                    $total_ern_min = 0;
                    $total_eff = 0;
                    $row_idx = 0;
                    
                    // Sort components by the defined order
                    if ($is_assembly_division) {
                        usort($components, function($a, $b) use ($assembly_order) {
                            $posA = array_search($a['name'], $assembly_order);
                            $posB = array_search($b['name'], $assembly_order);
                            if ($posA === false) $posA = 999;
                            if ($posB === false) $posB = 999;
                            return $posA - $posB;
                        });
                    } elseif ($division_id == 1) {
                        // Shirt division - custom order
                        usort($components, function($a, $b) use ($shirt_order) {
                            $posA = array_search($a['name'], $shirt_order);
                            $posB = array_search($b['name'], $shirt_order);
                            if ($posA === false) $posA = 999;
                            if ($posB === false) $posB = 999;
                            return $posA - $posB;
                        });
                    }
                    
                    // Display all components EXCEPT KNIT (displayed separately)
                    foreach ($components as $comp):
                        if ($comp['is_match_out'] || ($is_assembly_division && $comp['name'] === 'KNIT')) continue;
                        $row_idx++;
                        $data = $component_data[$comp['id']] ?? [];
                        
                        $day_total = $data['day_total'] ?? 0;
                        $ern_minutes = $data['ern_minutes'] ?? 0;
                        $acvd_eff = $data['acvd_eff'] ?? 0;
                        $profit = $data['profit'] ?? 0;
                        $epm = $data['epm'] ?? 13.2;
                        
                        $total_day_ttl += $day_total;
                        $total_ern_min += $ern_minutes;
                        $total_eff += $acvd_eff;
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($division_name); ?></td>
                        <td><?php echo htmlspecialchars($comp['name']); ?></td>
                        <td><?php echo number_format($data['ttl_sam_pc'] ?? 0, 2); ?></td>
                        <td><?php echo number_format($data['unit_smv'] ?? 0, 2); ?></td>
                        <td class="calculated"><?php echo number_format($data['day_forecast'] ?? 0, 0); ?></td>
                        <td><?php echo $data['unit_carder'] ?? 0; ?></td>
                        <td><?php echo number_format($data['plan_hours'] ?? 0, 1); ?></td>
                        <td><?php echo number_format($data['worked_hours'] ?? 0, 1); ?></td>
                        <td class="calculated"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated"><?php echo number_format($data['plan_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated"><?php echo number_format(($data['plan_eff'] ?? 0) * 100, 1); ?>%</td>
                        <td class="calculated"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td><?php echo number_format($data["hour_$h"] ?? 0, 0); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:700;"><?php echo number_format($day_total, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($ern_minutes, 1); ?></td>
                        <td style="font-weight:700; color:<?php echo ($acvd_eff * 100) >= 70 ? '#28a745' : (($acvd_eff * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;">
                            <?php echo number_format($acvd_eff * 100, 1); ?>%
                        </td>
                        <td><?php echo number_format($epm, 1); ?></td>
                        <td style="font-weight:700; color:<?php echo $profit >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo number_format($profit, 2); ?></td>
                    </tr>
                    <?php if ($is_assembly_division): ?>
                    <tr class="assembly-dhu-row">
                        <td colspan="2" style="font-weight:700; color:var(--dhu-red);">DHU %</td>
                        <td colspan="<?php echo 12 + $work_hours; ?>" style="color:var(--dhu-red); font-weight:700; text-align:center;">
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
                    
                    <!-- ============================================================ -->
                    <!-- SINGLE KNIT ROW - ONLY FOR ASSEMBLY DIVISION -->
                    <!-- ============================================================ -->
                    <?php if ($is_assembly_division && $knit_comp_id > 0): 
                        $knit_data = getReportData($conn, $assembly_division_id, $knit_comp_id, $date);
                        if (empty($knit_data) || !isset($knit_data['ttl_sam_pc'])) {
                            $knit_data = [
                                'ttl_sam_pc' => 0, 'unit_smv' => 0, 'unit_carder' => 0,
                                'plan_hours' => 0, 'worked_hours' => $work_hours,
                                'day_forecast' => 0, 'available_minutes' => 0,
                                'plan_minutes' => 0, 'plan_eff' => 0, 'target_100' => 0,
                                'day_total' => 0, 'ern_minutes' => 0, 'acvd_eff' => 0,
                                'epm' => 13.2, 'style_epm' => 13.2, 'profit' => 0
                            ];
                            for ($h = 1; $h <= 11; $h++) $knit_data["hour_$h"] = 0;
                        }
                        $day_total = $knit_data['day_total'] ?? 0;
                        $ern_minutes = $knit_data['ern_minutes'] ?? 0;
                        $acvd_eff = $knit_data['acvd_eff'] ?? 0;
                        $profit = $knit_data['profit'] ?? 0;
                        $style_epm = $knit_data['style_epm'] ?? 13.2;
                    ?>
                    <tr>
                        <td>Assembly</td>
                        <td>KNIT</td>
                        <td><?php echo number_format($knit_data['ttl_sam_pc'] ?? 0, 2); ?></td>
                        <td><?php echo number_format($knit_data['unit_smv'] ?? 0, 2); ?></td>
                        <td class="calculated"><?php echo number_format($knit_data['day_forecast'] ?? 0, 0); ?></td>
                        <td><?php echo $knit_data['unit_carder'] ?? 0; ?></td>
                        <td><?php echo number_format($knit_data['plan_hours'] ?? 0, 1); ?></td>
                        <td><?php echo number_format($knit_data['worked_hours'] ?? 0, 1); ?></td>
                        <td class="calculated"><?php echo number_format($knit_data['available_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated"><?php echo number_format($knit_data['plan_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated"><?php echo number_format(($knit_data['plan_eff'] ?? 0) * 100, 1); ?>%</td>
                        <td class="calculated"><?php echo number_format($knit_data['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td><?php echo number_format($knit_data["hour_$h"] ?? 0, 0); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:700;"><?php echo number_format($day_total, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($ern_minutes, 1); ?></td>
                        <td style="font-weight:700; color:<?php echo ($acvd_eff * 100) >= 70 ? '#28a745' : (($acvd_eff * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;">
                            <?php echo number_format($acvd_eff * 100, 1); ?>%
                        </td>
                        <td><?php echo number_format($style_epm, 1); ?></td>
                        <td style="font-weight:700; color:<?php echo $profit >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo number_format($profit, 2); ?></td>
                    </tr>
                    <tr class="assembly-dhu-row">
                        <td colspan="2" style="font-weight:700; color:var(--dhu-red);">DHU %</td>
                        <td colspan="<?php echo 12 + $work_hours; ?>" style="color:var(--dhu-red); font-weight:700; text-align:center;">
                            <?php 
                            $dhu_val = ($day_total > 0) ? round(($day_total / 100) * 5, 1) : 0;
                            echo number_format($dhu_val, 1); ?>%
                        </td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                    </tr>
                    <?php endif; ?>
                    
                    <!-- ============================================================ -->
                    <!-- MATCH OUT ROW (Non-Assembly) -->
                    <!-- ============================================================ -->
                    <?php if (!$is_assembly_division && !empty($match_out) && $match_out['unit_smv'] > 0): 
                        $mo_total = 0;
                        for ($h = 1; $h <= $work_hours; $h++) {
                            $mo_total += $match_out['hours'][$h] ?? 0;
                        }
                    ?>
                    <tr class="match-out-row">
                        <td colspan="2" style="font-weight:700;">Match Out</td>
                        <td style="font-weight:700;"><?php echo number_format($match_out['ttl_sam'] ?? 0, 4); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($match_out['unit_smv'] ?? 0, 2); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($match_out['day_forecast'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;"><?php echo $match_out['unit_carder'] ?? 0; ?></td>
                        <td style="font-weight:700;"><?php echo number_format($match_out['plan_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($match_out['worked_hours'] ?? 0, 1); ?></td>
                        <td class="calculated"><?php echo number_format($match_out['available_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated"><?php echo number_format($match_out['plan_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated"><?php echo number_format(($match_out['plan_eff'] ?? 0) * 100, 1); ?>%</td>
                        <td class="calculated"><?php echo number_format($match_out['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td><?php echo number_format($match_out['hours'][$h] ?? 0, 0); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:700;"><?php echo number_format($mo_total, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($match_out['earned_minutes'] ?? 0, 1); ?></td>
                        <td style="font-weight:700; color:var(--primary);"><?php echo number_format(($match_out['acvd_eff'] ?? 0) * 100, 1); ?>%</td>
                        <td>13.2</td>
                        <td style="font-weight:700;"><?php echo number_format(0, 2); ?></td>
                    </tr>
                    
                    <!-- DHU Row (Non-Assembly) -->
                    <tr class="dhu-row">
                        <td colspan="<?php echo 13 + $work_hours; ?>" style="text-align:right; padding-right:12px; font-weight:700; color:var(--dhu-red);">
                            DHU %
                        </td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; font-size:14px;">
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
                    
                    <!-- Total Row (Non-Assembly) -->
                    <tr class="total-row">
                        <td colspan="<?php echo 13 + $work_hours; ?>" style="text-align:right; padding-right:12px; font-weight:700;">
                            Total / <?php echo htmlspecialchars($division_name); ?>:
                        </td>
                        <td style="font-weight:700;"><?php echo number_format($total_day_ttl, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($total_ern_min, 1); ?></td>
                        <td style="font-weight:700; color:var(--primary);">
                            <?php 
                            $avg_eff = $row_idx > 0 ? round(($total_eff / $row_idx) * 100, 1) : 0;
                            echo number_format($avg_eff, 1) . '%';
                            ?>
                        </td>
                        <td>—</td>
                        <td style="font-weight:700;">—</td>
                    </tr>
                    <?php endif; ?>
                    
                    <!-- ============================================================ -->
                    <!-- ASSEMBLY ROWS UNDER SHIRT/TROUSER -->
                    <!-- ============================================================ -->
                    <?php if (!$is_assembly_division): ?>
                    
                        <?php if ($division_name === 'Shirt'): ?>
                        
                        <tr class="section-divider">
                            <td colspan="<?php echo 14 + $work_hours + 3; ?>" style="text-align:center; font-weight:700; color:var(--primary);">
                                ─── ASSEMBLY ───
                            </td>
                        </tr>
                        
                        <!-- SHIRT Assembly -->
                        <?php if (!empty($assembly_shirt_row)): 
                            $data = $assembly_shirt_row;
                            $day_total = $data['day_total'] ?? 0;
                            $ern_minutes = $data['ern_minutes'] ?? 0;
                            $acvd_eff = $data['acvd_eff'] ?? 0;
                            $profit = $data['profit'] ?? 0;
                            $style_epm = $data['style_epm'] ?? 13.2;
                        ?>
                        <tr>
                            <td>Assembly</td>
                            <td>SHIRT</td>
                            <td><?php echo number_format($data['ttl_sam_pc'] ?? 0, 2); ?></td>
                            <td><?php echo number_format($data['unit_smv'] ?? 0, 2); ?></td>
                            <td class="calculated"><?php echo number_format($data['day_forecast'] ?? 0, 0); ?></td>
                            <td><?php echo $data['unit_carder'] ?? 0; ?></td>
                            <td><?php echo number_format($data['plan_hours'] ?? 0, 1); ?></td>
                            <td><?php echo number_format($data['worked_hours'] ?? 0, 1); ?></td>
                            <td class="calculated"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                            <td class="calculated"><?php echo number_format($data['plan_minutes'] ?? 0, 0); ?></td>
                            <td class="calculated"><?php echo number_format(($data['plan_eff'] ?? 0) * 100, 1); ?>%</td>
                            <td class="calculated"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                            <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                            <td><?php echo number_format($data["hour_$h"] ?? 0, 0); ?></td>
                            <?php endfor; ?>
                            <td style="font-weight:700;"><?php echo number_format($day_total, 0); ?></td>
                            <td style="font-weight:700;"><?php echo number_format($ern_minutes, 1); ?></td>
                            <td style="font-weight:700; color:<?php echo ($acvd_eff * 100) >= 70 ? '#28a745' : (($acvd_eff * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;">
                                <?php echo number_format($acvd_eff * 100, 1); ?>%
                            </td>
                            <td><?php echo number_format($style_epm, 1); ?></td>
                            <td style="font-weight:700; color:<?php echo $profit >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo number_format($profit, 2); ?></td>
                        </tr>
                        <tr class="assembly-dhu-row">
                            <td colspan="2" style="font-weight:700; color:var(--dhu-red);">DHU %</td>
                            <td colspan="<?php echo 12 + $work_hours; ?>" style="color:var(--dhu-red); font-weight:700; text-align:center;">
                                <?php 
                                $dhu_val = ($day_total > 0) ? round(($day_total / 100) * 5, 1) : 0;
                                echo number_format($dhu_val, 1); ?>%
                            </td>
                            <td style="color:var(--dhu-red); font-weight:700;">—</td>
                            <td style="color:var(--dhu-red); font-weight:700;">—</td>
                            <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        </tr>
                        <?php endif; ?>
                        
                        <!-- SHIRT MTM Assembly -->
                        <?php if (!empty($assembly_shirt_mtm_row)): 
                            $data = $assembly_shirt_mtm_row;
                            $day_total = $data['day_total'] ?? 0;
                            $ern_minutes = $data['ern_minutes'] ?? 0;
                            $acvd_eff = $data['acvd_eff'] ?? 0;
                            $profit = $data['profit'] ?? 0;
                            $style_epm = $data['style_epm'] ?? 13.2;
                        ?>
                        <tr>
                            <td>Assembly</td>
                            <td>SHIRT MTM</td>
                            <td><?php echo number_format($data['ttl_sam_pc'] ?? 0, 2); ?></td>
                            <td><?php echo number_format($data['unit_smv'] ?? 0, 2); ?></td>
                            <td class="calculated"><?php echo number_format($data['day_forecast'] ?? 0, 0); ?></td>
                            <td><?php echo $data['unit_carder'] ?? 0; ?></td>
                            <td><?php echo number_format($data['plan_hours'] ?? 0, 1); ?></td>
                            <td><?php echo number_format($data['worked_hours'] ?? 0, 1); ?></td>
                            <td class="calculated"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                            <td class="calculated"><?php echo number_format($data['plan_minutes'] ?? 0, 0); ?></td>
                            <td class="calculated"><?php echo number_format(($data['plan_eff'] ?? 0) * 100, 1); ?>%</td>
                            <td class="calculated"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                            <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                            <td><?php echo number_format($data["hour_$h"] ?? 0, 0); ?></td>
                            <?php endfor; ?>
                            <td style="font-weight:700;"><?php echo number_format($day_total, 0); ?></td>
                            <td style="font-weight:700;"><?php echo number_format($ern_minutes, 1); ?></td>
                            <td style="font-weight:700; color:<?php echo ($acvd_eff * 100) >= 70 ? '#28a745' : (($acvd_eff * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;">
                                <?php echo number_format($acvd_eff * 100, 1); ?>%
                            </td>
                            <td><?php echo number_format($style_epm, 1); ?></td>
                            <td style="font-weight:700; color:<?php echo $profit >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo number_format($profit, 2); ?></td>
                        </tr>
                        <tr class="assembly-dhu-row">
                            <td colspan="2" style="font-weight:700; color:var(--dhu-red);">DHU %</td>
                            <td colspan="<?php echo 12 + $work_hours; ?>" style="color:var(--dhu-red); font-weight:700; text-align:center;">
                                <?php 
                                $dhu_val = ($day_total > 0) ? round(($day_total / 100) * 5, 1) : 0;
                                echo number_format($dhu_val, 1); ?>%
                            </td>
                            <td style="color:var(--dhu-red); font-weight:700;">—</td>
                            <td style="color:var(--dhu-red); font-weight:700;">—</td>
                            <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php endif; ?>
                        
                        <?php if ($division_name === 'Trouser'): ?>
                        
                        <tr class="section-divider">
                            <td colspan="<?php echo 14 + $work_hours + 3; ?>" style="text-align:center; font-weight:700; color:var(--primary);">
                                ─── ASSEMBLY ───
                            </td>
                        </tr>
                        
                        <!-- TROUSER Assembly -->
                        <?php if (!empty($assembly_trouser_row)): 
                            $data = $assembly_trouser_row;
                            $day_total = $data['day_total'] ?? 0;
                            $ern_minutes = $data['ern_minutes'] ?? 0;
                            $acvd_eff = $data['acvd_eff'] ?? 0;
                            $profit = $data['profit'] ?? 0;
                            $style_epm = $data['style_epm'] ?? 13.2;
                        ?>
                        <tr>
                            <td>Assembly</td>
                            <td>TROUSER</td>
                            <td><?php echo number_format($data['ttl_sam_pc'] ?? 0, 2); ?></td>
                            <td><?php echo number_format($data['unit_smv'] ?? 0, 2); ?></td>
                            <td class="calculated"><?php echo number_format($data['day_forecast'] ?? 0, 0); ?></td>
                            <td><?php echo $data['unit_carder'] ?? 0; ?></td>
                            <td><?php echo number_format($data['plan_hours'] ?? 0, 1); ?></td>
                            <td><?php echo number_format($data['worked_hours'] ?? 0, 1); ?></td>
                            <td class="calculated"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                            <td class="calculated"><?php echo number_format($data['plan_minutes'] ?? 0, 0); ?></td>
                            <td class="calculated"><?php echo number_format(($data['plan_eff'] ?? 0) * 100, 1); ?>%</td>
                            <td class="calculated"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                            <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                            <td><?php echo number_format($data["hour_$h"] ?? 0, 0); ?></td>
                            <?php endfor; ?>
                            <td style="font-weight:700;"><?php echo number_format($day_total, 0); ?></td>
                            <td style="font-weight:700;"><?php echo number_format($ern_minutes, 1); ?></td>
                            <td style="font-weight:700; color:<?php echo ($acvd_eff * 100) >= 70 ? '#28a745' : (($acvd_eff * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;">
                                <?php echo number_format($acvd_eff * 100, 1); ?>%
                            </td>
                            <td><?php echo number_format($style_epm, 1); ?></td>
                            <td style="font-weight:700; color:<?php echo $profit >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo number_format($profit, 2); ?></td>
                        </tr>
                        <tr class="assembly-dhu-row">
                            <td colspan="2" style="font-weight:700; color:var(--dhu-red);">DHU %</td>
                            <td colspan="<?php echo 12 + $work_hours; ?>" style="color:var(--dhu-red); font-weight:700; text-align:center;">
                                <?php 
                                $dhu_val = ($day_total > 0) ? round(($day_total / 100) * 5, 1) : 0;
                                echo number_format($dhu_val, 1); ?>%
                            </td>
                            <td style="color:var(--dhu-red); font-weight:700;">—</td>
                            <td style="color:var(--dhu-red); font-weight:700;">—</td>
                            <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        </tr>
                        <?php endif; ?>
                        
                        <!-- TROUSER MTM Assembly -->
                        <?php if (!empty($assembly_trouser_mtm_row)): 
                            $data = $assembly_trouser_mtm_row;
                            $day_total = $data['day_total'] ?? 0;
                            $ern_minutes = $data['ern_minutes'] ?? 0;
                            $acvd_eff = $data['acvd_eff'] ?? 0;
                            $profit = $data['profit'] ?? 0;
                            $style_epm = $data['style_epm'] ?? 13.2;
                        ?>
                        <tr>
                            <td>Assembly</td>
                            <td>TROUSER MTM</td>
                            <td><?php echo number_format($data['ttl_sam_pc'] ?? 0, 2); ?></td>
                            <td><?php echo number_format($data['unit_smv'] ?? 0, 2); ?></td>
                            <td class="calculated"><?php echo number_format($data['day_forecast'] ?? 0, 0); ?></td>
                            <td><?php echo $data['unit_carder'] ?? 0; ?></td>
                            <td><?php echo number_format($data['plan_hours'] ?? 0, 1); ?></td>
                            <td><?php echo number_format($data['worked_hours'] ?? 0, 1); ?></td>
                            <td class="calculated"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                            <td class="calculated"><?php echo number_format($data['plan_minutes'] ?? 0, 0); ?></td>
                            <td class="calculated"><?php echo number_format(($data['plan_eff'] ?? 0) * 100, 1); ?>%</td>
                            <td class="calculated"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                            <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                            <td><?php echo number_format($data["hour_$h"] ?? 0, 0); ?></td>
                            <?php endfor; ?>
                            <td style="font-weight:700;"><?php echo number_format($day_total, 0); ?></td>
                            <td style="font-weight:700;"><?php echo number_format($ern_minutes, 1); ?></td>
                            <td style="font-weight:700; color:<?php echo ($acvd_eff * 100) >= 70 ? '#28a745' : (($acvd_eff * 100) >= 50 ? '#f57c00' : '#dc3545'); ?>;">
                                <?php echo number_format($acvd_eff * 100, 1); ?>%
                            </td>
                            <td><?php echo number_format($style_epm, 1); ?></td>
                            <td style="font-weight:700; color:<?php echo $profit >= 0 ? '#28a745' : '#dc3545'; ?>;"><?php echo number_format($profit, 2); ?></td>
                        </tr>
                        <tr class="assembly-dhu-row">
                            <td colspan="2" style="font-weight:700; color:var(--dhu-red);">DHU %</td>
                            <td colspan="<?php echo 12 + $work_hours; ?>" style="color:var(--dhu-red); font-weight:700; text-align:center;">
                                <?php 
                                $dhu_val = ($day_total > 0) ? round(($day_total / 100) * 5, 1) : 0;
                                echo number_format($dhu_val, 1); ?>%
                            </td>
                            <td style="color:var(--dhu-red); font-weight:700;">—</td>
                            <td style="color:var(--dhu-red); font-weight:700;">—</td>
                            <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php endif; ?>
                        
                    <?php endif; ?>
                    
                    <!-- ============================================================ -->
                    <!-- LEAN TOTAL - Assembly only -->
                    <!-- ============================================================ -->
                    <?php if ($is_assembly_division && !empty($lean_total) && $lean_total['ttl_sam'] > 0): ?>
                    <tr class="lean-total-row">
                        <td colspan="2" style="font-weight:700;">Lean Total</td>
                        <td style="font-weight:700;"><?php echo number_format($lean_total['ttl_sam'] ?? 0, 4); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lean_total['section_sam'] ?? 0, 3); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lean_total['day_forecast'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lean_total['assemble_carder'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lean_total['plan_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lean_total['worked_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lean_total['available_minutes'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lean_total['plan_minutes'] ?? 0, 2); ?></td>
                        <td style="font-weight:700;"><?php echo number_format(($lean_total['plan_eff'] ?? 0) * 100, 1); ?>%</td>
                        <td style="font-weight:700;"><?php echo number_format($lean_total['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td style="font-weight:700;"><?php echo number_format($lean_total['hours'][$h] ?? 0, 0); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:700;"><?php echo number_format($lean_total['day_total'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lean_total['ern_minutes'] ?? 0, 1); ?></td>
                        <td style="font-weight:700; color:var(--primary);"><?php echo number_format(($lean_total['acvd_eff'] ?? 0) * 100, 1); ?>%</td>
                        <td>—</td>
                        <td style="font-weight:700;"><?php echo number_format((500 * ($lean_total['day_total'] ?? 0)) - (7365 * (($lean_total['assemble_carder'] ?? 0) + ($shirt_match_out['unit_carder'] ?? 0))), 2); ?></td>
                    </tr>
                    
                    <tr class="dhu-row">
                        <td colspan="<?php echo 13 + $work_hours; ?>" style="text-align:right; padding-right:12px; font-weight:700; color:var(--dhu-red);">
                            Lean Total DHU %
                        </td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; font-size:14px;">
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
                    
                    <!-- ============================================================ -->
                    <!-- FACTORY GRAND TOTAL/AVERAGE - Assembly only -->
                    <!-- ============================================================ -->
                    <?php if (!empty($grand_total) && $grand_total['ttl_sam'] > 0): ?>
                    <tr class="grand-total-row">
                        <td colspan="2" style="font-weight:800;">Factory Grand Total/Average</td>
                        <td style="font-weight:800;"><?php echo number_format($grand_total['ttl_sam'] ?? 0, 4); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($grand_total['section_sam'] ?? 0, 3); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($grand_total['day_forecast'] ?? 0, 1); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($grand_total['assemble_carder'] ?? 0, 0); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($grand_total['plan_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($grand_total['worked_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($grand_total['available_minutes'] ?? 0, 0); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($grand_total['plan_minutes'] ?? 0, 1); ?></td>
                        <td style="font-weight:800;"><?php echo number_format(($grand_total['plan_eff'] ?? 0) * 100, 0); ?>%</td>
                        <td style="font-weight:800;"><?php echo number_format($grand_total['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td style="font-weight:800;"><?php echo number_format($grand_total['hours'][$h] ?? 0, 4); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:800;"><?php echo number_format($grand_total['day_total'] ?? 0, 0); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($grand_total['ern_minutes'] ?? 0, 1); ?></td>
                        <td style="font-weight:800;"><?php echo number_format(($grand_total['acvd_eff'] ?? 0) * 100, 1); ?>%</td>
                        <td>—</td>
                        <td style="font-weight:800;"><?php echo number_format((500 * ($grand_total['day_total'] ?? 0)) - (7365 * (($grand_total['assemble_carder'] ?? 0) + 0)), 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top: 16px; text-align: left; display: flex; gap: 12px; flex-wrap: wrap;">
            <a href="reports.php?from=<?php echo $from_date; ?>&to=<?php echo $to_date; ?>&division=<?php echo $division_filter; ?>" class="back-button">
                ← Back to Reports
            </a>
            <button class="back-button" onclick="window.print()" style="cursor:pointer;">
                🖨️ Print Report
            </button>
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
    </script>
</body>
</html>