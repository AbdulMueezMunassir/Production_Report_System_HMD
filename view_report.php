<?php
// view_report.php - View Single Report with Back to Filters
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
if ($division_name == 'Shirt Assembly') $division_name = 'Assembly';
$is_assembly_division = ($division['type'] === 'assembly');

// Get work hours from URL or default to 10
$work_hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 10;
$work_hours = max(1, min(11, $work_hours));

// ============================================================
// GET ALL DATA INCLUDING SUMMARY ROWS
// ============================================================

// Get all components for this division
$components = getComponents($conn, $division_id);
$component_data = [];
$summary_rows = [];

// Get all reports for this date and division
$stmt = $conn->prepare("SELECT * FROM production_reports WHERE devition_id = ? AND report_date = ? ORDER BY unit_id");
$stmt->execute([$division_id, $date]);
$all_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Separate regular components from summary rows
foreach ($all_reports as $report) {
    $unit_id = $report['unit_id'] ?? 0;
    
    // Check if this is a summary row
    if ($unit_id == 999) {
        $summary_rows['match_out'] = $report;
    } elseif ($unit_id == 998) {
        $summary_rows['dhu'] = $report;
    } elseif ($unit_id == 997) {
        $summary_rows['lean_total'] = $report;
    } elseif ($unit_id == 996) {
        $summary_rows['grand_total'] = $report;
    } else {
        $component_data[$unit_id] = $report;
    }
}

// Process each component with Excel formulas if data exists
foreach ($components as $comp) {
    if ($comp['is_match_out']) continue;
    $comp_id = $comp['id'];
    
    // If data exists in database, use it
    if (isset($component_data[$comp_id])) {
        $data = $component_data[$comp_id];
    } else {
        // Get empty data structure
        $data = getReportData($conn, $division_id, $comp_id, $date);
    }
    
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
    
    // Calculate using exact Excel formulas if no data exists
    if ($data['ttl_sam_pc'] == 0 && $data['unit_smv'] == 0) {
        if ($is_assembly_division) {
            if ($data['unit_smv'] > 0 && $data['unit_carder'] > 0) {
                $data['day_forecast'] = ($data['unit_carder'] * 600 / $data['unit_smv']) * 0.80;
            }
            $data['available_minutes'] = $data['unit_carder'] * $data['plan_hours'] * 60;
            $data['plan_minutes'] = $data['day_forecast'] * $data['unit_smv'];
            $data['plan_eff'] = ($data['available_minutes'] > 0) ? ($data['plan_minutes'] / $data['available_minutes']) : 0;
            $data['target_100'] = ($data['unit_smv'] > 0) ? ($data['unit_carder'] / $data['unit_smv']) * 60 : 0;
        } else {
            if ($data['unit_smv'] > 0 && $data['unit_carder'] > 0) {
                $data['day_forecast'] = ($data['unit_carder'] * 600 / $data['unit_smv']) * 0.90;
            }
            $data['available_minutes'] = $data['unit_carder'] * $data['plan_hours'] * 60;
            $data['plan_minutes'] = $data['day_forecast'] * $data['unit_smv'];
            $data['plan_eff'] = ($data['available_minutes'] > 0) ? ($data['plan_minutes'] / $data['available_minutes']) : 0;
            $data['target_100'] = ($data['unit_smv'] > 0) ? ($data['unit_carder'] / $data['unit_smv']) * 60 : 0;
        }
    }
    
    $component_data[$comp_id] = $data;
}

// Calculate totals for regular components
$total_day_ttl = 0;
$total_ern_min = 0;
$total_eff = 0;
$row_idx = 0;

foreach ($components as $comp) {
    if ($comp['is_match_out']) continue;
    $data = $component_data[$comp['id']] ?? [];
    $total_day_ttl += $data['day_total'] ?? 0;
    $total_ern_min += $data['ern_minutes'] ?? 0;
    $total_eff += $data['acvd_eff'] ?? 0;
    $row_idx++;
}

$avg_eff = $row_idx > 0 ? round(($total_eff / $row_idx) * 100, 1) : 0;

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
        .badge-match-out { background: #6c757d; }
        .badge-dhu { background: #dc3545; }
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
                <span><strong>Components:</strong> <?php echo $row_idx; ?></span>
                <span><strong>Average Efficiency:</strong> <span style="color:var(--primary); font-weight:700;"><?php echo $avg_eff; ?>%</span></span>
                <span><strong>Total Production:</strong> <?php echo number_format($total_day_ttl, 0); ?></span>
            </div>
            <!-- Summary Badges -->
            <div class="summary-badges">
                <?php if (isset($summary_rows['match_out']) && $summary_rows['match_out']['unit_smv'] > 0): ?>
                <span class="badge badge-match-out">✅ Match Out</span>
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
                <?php if (empty($summary_rows) || 
                    (!isset($summary_rows['match_out']) && !isset($summary_rows['dhu']) && 
                     !isset($summary_rows['lean_total']) && !isset($summary_rows['grand_total']))): ?>
                <span style="color:var(--steel);font-size:12px;">No summary data available</span>
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
                        <th style="min-width:65px;">TTl SAM/Pc</th>
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
                        <th style="min-width:55px;">Day Ttl</th>
                        <th style="min-width:65px;">Ern Minutes</th>
                        <th style="min-width:65px;">Acvd Eff</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($components) && empty($summary_rows)): ?>
                    <tr><td colspan="<?php echo 12 + $work_hours + 3; ?>" class="no-data">No data found for this date.</td></tr>
                    <?php else: ?>
                    
                    <!-- ============================================================ -->
                    <!-- REGULAR COMPONENTS -->
                    <!-- ============================================================ -->
                    <?php 
                    $component_displayed = 0;
                    foreach ($components as $comp):
                        if ($comp['is_match_out']) continue;
                        $data = $component_data[$comp['id']] ?? [];
                        $day_total = $data['day_total'] ?? 0;
                        $ern_minutes = $data['ern_minutes'] ?? 0;
                        $acvd_eff = $data['acvd_eff'] ?? 0;
                        $component_displayed++;
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
                    </tr>
                    <?php endforeach; ?>
                    
                    <!-- ============================================================ -->
                    <!-- MATCH OUT ROW (Shirt/Trouser - unit_id: 999) -->
                    <!-- ============================================================ -->
                    <?php if (!$is_assembly_division && isset($summary_rows['match_out']) && $summary_rows['match_out']['unit_smv'] > 0): 
                        $mo = $summary_rows['match_out'];
                        $mo_total = 0;
                        for ($h = 1; $h <= $work_hours; $h++) {
                            $mo_total += $mo["hour_$h"] ?? 0;
                        }
                    ?>
                    <tr class="match-out-row">
                        <td colspan="2" style="font-weight:700;">Match Out</td>
                        <td style="font-weight:700;"><?php echo number_format($mo['ttl_sam_pc'] ?? 0, 4); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($mo['unit_smv'] ?? 0, 2); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($mo['day_forecast'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;"><?php echo $mo['unit_carder'] ?? 0; ?></td>
                        <td style="font-weight:700;"><?php echo number_format($mo['plan_hours'] ?? 0, 1); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($mo['worked_hours'] ?? 0, 1); ?></td>
                        <td class="calculated"><?php echo number_format($mo['available_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated"><?php echo number_format($mo['plan_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated"><?php echo number_format(($mo['plan_eff'] ?? 0) * 100, 1); ?>%</td>
                        <td class="calculated"><?php echo number_format($mo['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                        <td><?php echo number_format($mo["hour_$h"] ?? 0, 0); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:700;"><?php echo number_format($mo['day_total'] ?? 0, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($mo['ern_minutes'] ?? 0, 1); ?></td>
                        <td style="font-weight:700; color:var(--primary);">
                            <?php echo number_format(($mo['acvd_eff'] ?? 0) * 100, 1); ?>%
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <!-- ============================================================ -->
                    <!-- DHU ROW (Shirt/Trouser - unit_id: 998) -->
                    <!-- ============================================================ -->
                    <?php if (!$is_assembly_division && isset($summary_rows['dhu']) && $summary_rows['dhu']['day_total'] > 0): 
                        $dhu = $summary_rows['dhu'];
                        $dhu_avg = 0;
                        for ($h = 1; $h <= $work_hours; $h++) {
                            $dhu_avg += $dhu["hour_$h"] ?? 0;
                        }
                        $dhu_avg = $work_hours > 0 ? round($dhu_avg / $work_hours, 1) : 0;
                    ?>
                    <tr class="dhu-row">
                        <td colspan="<?php echo 11 + $work_hours; ?>" style="text-align:right; padding-right:12px; font-weight:700; color:var(--dhu-red);">
                            DHU %
                        </td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; font-size:14px;">
                            <?php echo number_format($dhu_avg, 1); ?>%
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <!-- ============================================================ -->
                    <!-- TOTAL ROW (Non-Assembly) -->
                    <!-- ============================================================ -->
                    <?php if (!$is_assembly_division && $component_displayed > 0): ?>
                    <tr class="total-row">
                        <td colspan="<?php echo 11 + $work_hours; ?>" style="text-align:right; padding-right:12px; font-weight:700;">
                            Total / <?php echo htmlspecialchars($division_name); ?>:
                        </td>
                        <td style="font-weight:700;"><?php echo number_format($total_day_ttl, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($total_ern_min, 1); ?></td>
                        <td style="font-weight:700; color:var(--primary);">
                            <?php echo number_format($avg_eff, 1); ?>%
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <!-- ============================================================ -->
                    <!-- LEAN TOTAL ROW (Assembly - unit_id: 997) -->
                    <!-- ============================================================ -->
                    <?php if ($is_assembly_division && isset($summary_rows['lean_total']) && $summary_rows['lean_total']['ttl_sam_pc'] > 0): 
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
                    </tr>
                    
                    <!-- Lean Total DHU Row -->
                    <tr class="dhu-row">
                        <td colspan="<?php echo 11 + $work_hours; ?>" style="text-align:right; padding-right:12px; font-weight:700; color:var(--dhu-red);">
                            Lean Total DHU %
                        </td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700;">—</td>
                        <td style="color:var(--dhu-red); font-weight:700; font-size:14px;">
                            <?php 
                            $lt_dhu = 0;
                            if (isset($summary_rows['lean_total']) && $summary_rows['lean_total']['day_total'] > 0) {
                                $lt_dhu_vals = 0;
                                for ($h = 1; $h <= $work_hours; $h++) {
                                    $lt_dhu_vals += $summary_rows['lean_total']["hour_$h"] ?? 0;
                                }
                                $lt_dhu = $work_hours > 0 ? round($lt_dhu_vals / $work_hours, 1) : 0;
                            }
                            echo number_format($lt_dhu, 1); ?>%
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <!-- ============================================================ -->
                    <!-- FACTORY GRAND TOTAL/AVERAGE (Assembly - unit_id: 996) -->
                    <!-- ============================================================ -->
                    <?php if ($is_assembly_division && isset($summary_rows['grand_total']) && $summary_rows['grand_total']['ttl_sam_pc'] > 0): 
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
                    </tr>
                    <?php endif; ?>
                    
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