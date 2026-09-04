<?php
// view_report.php - View Single Report - ASSEMBLY VIEW ONLY (Single KNIT row with saved values)
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

$division_name = $division['name'];
// For Assembly division, display as "Assembly"
if ($division_id == 7) {
    $division_name = 'Assembly';
}
$is_assembly_division = ($division_id == 7);

// Get work hours from URL or default to 10
$work_hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 10;
$work_hours = max(1, min(11, $work_hours));

// ============================================================
// GET DATA FOR ASSEMBLY VIEW
// ============================================================
$assembly_shirt_row = null;
$assembly_shirt_mtm_row = null;
$assembly_trouser_row = null;
$assembly_trouser_mtm_row = null;
$assembly_coat_row = null;
$assembly_coat_mtm_row = null;
$assembly_knit_row = null;
$summary_rows = [];

if ($is_assembly_division) {
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
        
        // Recalculate Assembly formulas
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
        
        if ($data['available_minutes'] > 0 && $data['worked_hours'] > 0 && $data['plan_hours'] > 0) {
            $data['acvd_eff'] = ($data['ern_minutes'] / $data['available_minutes']) * ($data['plan_hours'] / $data['worked_hours']);
        } else {
            $data['acvd_eff'] = 0;
        }
        
        $data['profit'] = (500 * $data['day_total']) - (7365 * $data['unit_carder']);
        
        $assembly_all_data[$comp['name']] = $data;
    }
    
    // Get summary rows (Lean Total and Grand Total)
    $stmt = $conn->prepare("SELECT * FROM production_reports WHERE devition_id = ? AND report_date = ? AND unit_id IN (996, 997)");
    $stmt->execute([$division_id, $date]);
    $summary_rows_result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($summary_rows_result as $row) {
        if ($row['unit_id'] == 997) {
            $summary_rows['lean_total'] = $row;
        } elseif ($row['unit_id'] == 996) {
            $summary_rows['grand_total'] = $row;
        }
    }
    
    // Assign data to variables in correct order
    $assembly_shirt_row = isset($assembly_all_data['SHIRT']) ? $assembly_all_data['SHIRT'] : null;
    $assembly_shirt_mtm_row = isset($assembly_all_data['SHIRT MTM']) ? $assembly_all_data['SHIRT MTM'] : null;
    $assembly_trouser_row = isset($assembly_all_data['TROUSER']) ? $assembly_all_data['TROUSER'] : null;
    $assembly_trouser_mtm_row = isset($assembly_all_data['TROUSER MTM']) ? $assembly_all_data['TROUSER MTM'] : null;
    $assembly_coat_row = isset($assembly_all_data['COAT']) ? $assembly_all_data['COAT'] : null;
    $assembly_coat_mtm_row = isset($assembly_all_data['COAT MTM']) ? $assembly_all_data['COAT MTM'] : null;
    $assembly_knit_row = isset($assembly_all_data['KNIT']) ? $assembly_all_data['KNIT'] : null;
    
    // If KNIT doesn't exist in database, create a default empty row
    if ($assembly_knit_row === null) {
        $assembly_knit_row = [
            'ttl_sam_pc' => 0,
            'unit_smv' => 0,
            'unit_carder' => 0,
            'plan_hours' => 0,
            'worked_hours' => $work_hours,
            'day_forecast' => 0,
            'available_minutes' => 0,
            'plan_minutes' => 0,
            'plan_eff' => 0,
            'target_100' => 0,
            'day_total' => 0,
            'ern_minutes' => 0,
            'acvd_eff' => 0,
            'epm' => 13.2,
            'style_epm' => 13.2,
            'profit' => 0
        ];
        for ($h = 1; $h <= 11; $h++) {
            $assembly_knit_row["hour_$h"] = 0;
        }
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

        .container { max-width: 1200px; margin: 0 auto; padding: 20px 30px; }
        
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
        
        .excel-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            min-width: 1400px;
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
        .excel-table .dhu-row { background: var(--dhu-bg); color: var(--dhu-red); font-weight: 700; }
        .excel-table .dhu-row td { background: var(--dhu-bg); color: var(--dhu-red); border-color: rgba(220, 53, 69, 0.2); }
        .excel-table .lean-total-row { background: var(--lean-bg); font-weight: 700; }
        .excel-table .lean-total-row td { background: var(--lean-bg); }
        .excel-table .grand-total-row { background: var(--grand-total-bg); color: #fff; font-weight: 800; }
        .excel-table .grand-total-row td { background: var(--grand-total-bg); color: #fff; border-color: rgba(33,115,70,0.3); }
        .excel-table .calculated { background: rgba(255,255,255,0.1); }
        .excel-table .section-divider td {
            background: rgba(33, 115, 70, 0.1) !important;
            font-weight: 700 !important;
            color: var(--text-dark) !important;
            padding: 8px 4px !important;
            border-top: 2px solid var(--primary) !important;
            border-bottom: 2px solid var(--primary) !important;
        }
        
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
        
        .no-data {
            text-align: center;
            padding: 30px;
            color: var(--steel);
            font-size: 14px;
            font-weight: 500;
        }
        
        @media (max-width: 768px) {
            .topbar { padding: 10px 16px; flex-direction: column; align-items: stretch; gap: 8px; }
            .topnav { justify-content: center; }
            .right { justify-content: center; }
            .container { padding: 12px 16px; }
            .report-header .meta { gap: 12px; }
            .excel-table { font-size: 10px; min-width: 1000px; }
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
            </div>
            <!-- Summary Badges -->
            <div class="summary-badges">
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
                <span>📊 <?php echo htmlspecialchars($division_name); ?> - Production Report</span>
                <span class="badge-info">Work Hours: <?php echo $work_hours; ?> hrs</span>
            </div>
            
            <table class="excel-table">
                <thead>
                    <tr>
                        <th style="min-width:60px;">DEVITION</th>
                        <th style="min-width:55px;">Unit</th>
                        <th style="min-width:65px;">TTl SAM/Pcs</th>
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
                        <th style="min-width:55px;">Day Total</th>
                        <th style="min-width:65px;">Earn Minutes</th>
                        <th style="min-width:65px;">Achieved Eff %</th>
                        <th style="min-width:55px;">Style EPM</th>
                        <th style="min-width:65px;">Profit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($is_assembly_division): ?>
                    
                    <!-- SHIRT Row -->
                    <?php if ($assembly_shirt_row !== null): 
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
                    <?php endif; ?>
                    
                    <!-- SHIRT MTM Row -->
                    <?php if ($assembly_shirt_mtm_row !== null): 
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
                    <?php endif; ?>
                    
                    <!-- TROUSER Row -->
                    <?php if ($assembly_trouser_row !== null): 
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
                    <?php endif; ?>
                    
                    <!-- TROUSER MTM Row -->
                    <?php if ($assembly_trouser_mtm_row !== null): 
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
                    <?php endif; ?>
                    
                    <!-- COAT Row -->
                    <?php if ($assembly_coat_row !== null): 
                        $data = $assembly_coat_row;
                        $day_total = $data['day_total'] ?? 0;
                        $ern_minutes = $data['ern_minutes'] ?? 0;
                        $acvd_eff = $data['acvd_eff'] ?? 0;
                        $profit = $data['profit'] ?? 0;
                        $style_epm = $data['style_epm'] ?? 13.2;
                    ?>
                    <tr>
                        <td>Assembly</td>
                        <td>COAT</td>
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
                    <?php endif; ?>
                    
                    <!-- COAT MTM Row -->
                    <?php if ($assembly_coat_mtm_row !== null): 
                        $data = $assembly_coat_mtm_row;
                        $day_total = $data['day_total'] ?? 0;
                        $ern_minutes = $data['ern_minutes'] ?? 0;
                        $acvd_eff = $data['acvd_eff'] ?? 0;
                        $profit = $data['profit'] ?? 0;
                        $style_epm = $data['style_epm'] ?? 13.2;
                    ?>
                    <tr>
                        <td>Assembly</td>
                        <td>COAT MTM</td>
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
                    <?php endif; ?>
                    
                    <!-- ============================================================ -->
                    <!-- KNIT ROW - UNDER COAT MTM (Shows saved values from database) -->
                    <!-- ============================================================ -->
                    <?php if ($assembly_knit_row !== null): 
                        $data = $assembly_knit_row;
                        $day_total = $data['day_total'] ?? 0;
                        $ern_minutes = $data['ern_minutes'] ?? 0;
                        $acvd_eff = $data['acvd_eff'] ?? 0;
                        $profit = $data['profit'] ?? 0;
                        $style_epm = $data['style_epm'] ?? 13.2;
                    ?>
                    <tr>
                        <td>Assembly</td>
                        <td>KNIT</td>
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
                    <?php endif; ?>
                    
                    <!-- ============================================================ -->
                    <!-- LEAN TOTAL ROW (Assembly - unit_id: 997) -->
                    <!-- ============================================================ -->
                    <?php if (isset($summary_rows['lean_total']) && $summary_rows['lean_total']['ttl_sam_pc'] > 0): 
                        $lt = $summary_rows['lean_total'];
                    ?>
                    <tr class="lean-total-row">
                        <td colspan="2" style="font-weight:700;">Lean Total</td>
                        <td style="font-weight:700;"><?php echo number_format($lt['ttl_sam_pc'] ?? 0, 4); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lt['unit_smv'] ?? 0, 3); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lt['day_forecast'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lt['unit_carder'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lt['plan_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lt['worked_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lt['available_minutes'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lt['plan_minutes'] ?? 0, 2); ?></td>
                        <td style="font-weight:700;"><?php echo number_format(($lt['plan_eff'] ?? 0) * 100, 1); ?>%</td>
                        <td style="font-weight:700;"><?php echo number_format($lt['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td style="font-weight:700;"><?php echo number_format($lt["hour_$h"] ?? 0, 0); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:700;"><?php echo number_format($lt['day_total'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($lt['ern_minutes'] ?? 0, 1); ?></td>
                        <td style="font-weight:700; color:var(--primary);"><?php echo number_format(($lt['acvd_eff'] ?? 0) * 100, 1); ?>%</td>
                        <td>—</td>
                        <td style="font-weight:700;"><?php echo number_format($lt['profit'] ?? 0, 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <!-- ============================================================ -->
                    <!-- FACTORY GRAND TOTAL/AVERAGE (Assembly - unit_id: 996) -->
                    <!-- ============================================================ -->
                    <?php if (isset($summary_rows['grand_total']) && $summary_rows['grand_total']['ttl_sam_pc'] > 0): 
                        $gt = $summary_rows['grand_total'];
                    ?>
                    <tr class="grand-total-row">
                        <td colspan="2" style="font-weight:800;">Factory Grand Total/Average</td>
                        <td style="font-weight:800;"><?php echo number_format($gt['ttl_sam_pc'] ?? 0, 4); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($gt['unit_smv'] ?? 0, 3); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($gt['day_forecast'] ?? 0, 1); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($gt['unit_carder'] ?? 0, 0); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($gt['plan_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($gt['worked_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($gt['available_minutes'] ?? 0, 0); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($gt['plan_minutes'] ?? 0, 1); ?></td>
                        <td style="font-weight:800;"><?php echo number_format(($gt['plan_eff'] ?? 0) * 100, 0); ?>%</td>
                        <td style="font-weight:800;"><?php echo number_format($gt['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td style="font-weight:800;"><?php echo number_format($gt["hour_$h"] ?? 0, 4); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:800;"><?php echo number_format($gt['day_total'] ?? 0, 0); ?></td>
                        <td style="font-weight:800;"><?php echo number_format($gt['ern_minutes'] ?? 0, 1); ?></td>
                        <td style="font-weight:800;"><?php echo number_format(($gt['acvd_eff'] ?? 0) * 100, 1); ?>%</td>
                        <td>—</td>
                        <td style="font-weight:800;"><?php echo number_format($gt['profit'] ?? 0, 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php else: ?>
                    <tr><td colspan="<?php echo 12 + $work_hours + 3; ?>" class="no-data">This view is only for Assembly division.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top: 16px; text-align: left;">
            <a href="reports.php?from=<?php echo $from_date; ?>&to=<?php echo $to_date; ?>&division=<?php echo $division_filter; ?>" class="back-button">
                ← Back to Reports
            </a>
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