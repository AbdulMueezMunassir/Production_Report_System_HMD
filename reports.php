<?php
// reports.php - FINAL FIXED - Shows Shirt, Trouser, Assembly correctly
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$conn = getDB();

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ALWAYS use GET parameters if provided; otherwise default to today
// Do NOT fall back to session values unless GET is explicitly given
$from_date = isset($_GET['from']) && !empty($_GET['from']) ? $_GET['from'] : date('Y-m-d');
$to_date   = isset($_GET['to']) && !empty($_GET['to']) ? $_GET['to'] : date('Y-m-d');
$division_filter = isset($_GET['division']) ? $_GET['division'] : 'all';

// Save only if GET parameters were actually used (so session stays in sync)
if (isset($_GET['from']) || isset($_GET['to']) || isset($_GET['division'])) {
    $_SESSION['report_from'] = $from_date;
    $_SESSION['report_to'] = $to_date;
    $_SESSION['report_division'] = $division_filter;
} else {
    // If no GET params, we still want to set session to today for consistency
    $_SESSION['report_from'] = $from_date;
    $_SESSION['report_to'] = $to_date;
    $_SESSION['report_division'] = $division_filter;
}

// ============================================================
// DIVISION NAMES - Loaded dynamically from the divisions table
// ============================================================
$division_display_names = [];

// Get divisions for the filter dropdown
$divisions = [];
try {
    $stmt = $conn->prepare("SELECT id, name, type FROM divisions ORDER BY id ASC");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $divisions = [];
    foreach ($results as $div) {
        // For any division that is type 'assembly', display as "Assembly"
        if (isset($div['type']) && $div['type'] === 'assembly') {
            $display_name = 'Assembly';
        } else {
            $display_name = $div['name'];
        }
        $division_display_names[$div['id']] = $display_name;
        $div['display_name'] = $display_name;
        $divisions[] = $div;
    }
} catch (Exception $e) {
    error_log("Divisions query error: " . $e->getMessage());
    $divisions = array();
}

// Build the query - Get ALL reports
$sql = "SELECT 
            DATE(r.report_date) AS report_date, 
            r.devition_id,
            r.acvd_eff,
            r.day_total,
            r.unit_id,
            r.ttl_sam_pc,
            r.unit_smv,
            r.unit_carder,
            r.id as report_id,
            r.ern_minutes,
            r.plan_hours,
            r.worked_hours,
            r.available_minutes,
            r.plan_minutes,
            r.plan_eff,
            r.target_100,
            r.epm,
            r.style_epm,
            r.profit,
            r.hour_1, r.hour_2, r.hour_3, r.hour_4, r.hour_5,
            r.hour_6, r.hour_7, r.hour_8, r.hour_9, r.hour_10, r.hour_11
        FROM production_reports r 
        WHERE DATE(r.report_date) BETWEEN ? AND ?
        AND r.unit_id NOT IN (996, 997, 998, 999)";

$params = array($from_date, $to_date);

if ($division_filter !== 'all' && !empty($division_filter)) {
    $sql .= " AND r.devition_id = ?";
    $params[] = (int)$division_filter;
}

$sql .= " ORDER BY r.report_date DESC, r.devition_id ASC";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($reports)) $reports = array();
} catch (Exception $e) {
    error_log("Reports query error: " . $e->getMessage());
    $reports = array();
}

// Get summary rows separately (MO, DHU, Lean Total, Grand Total)
$summary_sql = "SELECT 
            DATE(r.report_date) AS report_date, 
            r.devition_id,
            r.unit_id,
            r.day_total,
            r.acvd_eff,
            r.ern_minutes,
            r.unit_carder,
            r.ttl_sam_pc,
            r.unit_smv
        FROM production_reports r 
        WHERE DATE(r.report_date) BETWEEN ? AND ?
        AND r.unit_id IN (996, 997, 998, 999)";

$summary_params = array($from_date, $to_date);
if ($division_filter !== 'all' && !empty($division_filter)) {
    $summary_sql .= " AND r.devition_id = ?";
    $summary_params[] = (int)$division_filter;
}

try {
    $stmt = $conn->prepare($summary_sql);
    $stmt->execute($summary_params);
    $summary_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($summary_reports)) $summary_reports = array();
} catch (Exception $e) {
    error_log("Summary query error: " . $e->getMessage());
    $summary_reports = array();
}

// Group reports by date and division
$grouped_reports = [];

// First, add all regular components
foreach ($reports as $report) {
    $devition_id = (int)$report['devition_id'];
    $display_name = isset($division_display_names[$devition_id]) ? $division_display_names[$devition_id] : 'Unknown';
    
    $key = date('Y-m-d', strtotime($report['report_date'])) . '_' . $devition_id;
    
    if (!isset($grouped_reports[$key])) {
        $grouped_reports[$key] = [
            'date' => $report['report_date'],
            'division_id' => $devition_id,
            'division_name' => $display_name,
            'components' => [],
            'summary' => [
                'match_out' => null,
                'dhu' => null,
                'lean_total' => null,
                'grand_total' => null
            ],
            'total_pcs' => 0,
            'total_ern' => 0,
            'total_eff' => 0,
            'eff_count' => 0
        ];
    }
    
    $grouped_reports[$key]['components'][] = $report;
    if ($report['day_total'] > 0) {
        $grouped_reports[$key]['total_pcs'] += $report['day_total'];
        $grouped_reports[$key]['total_ern'] += $report['ern_minutes'] ?? 0;
        if ($report['acvd_eff'] > 0) {
            $grouped_reports[$key]['total_eff'] += $report['acvd_eff'] * 100;
            $grouped_reports[$key]['eff_count']++;
        }
    }
}

// Then, add summary rows
foreach ($summary_reports as $report) {
    $devition_id = (int)$report['devition_id'];
    $display_name = isset($division_display_names[$devition_id]) ? $division_display_names[$devition_id] : 'Unknown';
    
    $key = date('Y-m-d', strtotime($report['report_date'])) . '_' . $devition_id;
    
    if (!isset($grouped_reports[$key])) {
        $grouped_reports[$key] = [
            'date' => $report['report_date'],
            'division_id' => $devition_id,
            'division_name' => $display_name,
            'components' => [],
            'summary' => [
                'match_out' => null,
                'dhu' => null,
                'lean_total' => null,
                'grand_total' => null
            ],
            'total_pcs' => 0,
            'total_ern' => 0,
            'total_eff' => 0,
            'eff_count' => 0
        ];
    }
    
    $unit_id = $report['unit_id'] ?? 0;
    if ($unit_id == 999) {
        $grouped_reports[$key]['summary']['match_out'] = $report;
    } elseif ($unit_id == 998) {
        $grouped_reports[$key]['summary']['dhu'] = $report;
    } elseif ($unit_id == 997) {
        $grouped_reports[$key]['summary']['lean_total'] = $report;
    } elseif ($unit_id == 996) {
        $grouped_reports[$key]['summary']['grand_total'] = $report;
    }
}

// Calculate summary for each group
foreach ($grouped_reports as $key => &$group) {
    $group['avg_eff'] = $group['eff_count'] > 0 ? round($group['total_eff'] / $group['eff_count'], 1) : 0;
    $group['avg_ern'] = $group['eff_count'] > 0 ? round($group['total_ern'] / $group['eff_count'], 1) : 0;
    
    $summary_count = 0;
    if ($group['summary']['match_out']) $summary_count++;
    if ($group['summary']['dhu']) $summary_count++;
    if ($group['summary']['lean_total']) $summary_count++;
    if ($group['summary']['grand_total']) $summary_count++;
    $group['summary_count'] = $summary_count;
    $group['component_count'] = count($group['components']);
}
unset($group);

// Calculate stats
$total_reports = count($grouped_reports);
$avg_eff = 0;
$total_prod = 0;
foreach ($grouped_reports as $r) {
    $avg_eff += $r['avg_eff'];
    $total_prod += $r['total_pcs'];
}
$avg_eff = $total_reports > 0 ? round($avg_eff / $total_reports, 1) : 0;

$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';

// Debug: Check if Assembly has data (any division with type 'assembly')
$assembly_count = 0;
foreach ($grouped_reports as $group) {
    foreach ($divisions as $div) {
        if ($div['id'] == $group['division_id'] && isset($div['type']) && $div['type'] === 'assembly') {
            $assembly_count++;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Hameedia</title>
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
            --amber: #f57c00;
            --dhu-color: #e74c3c;
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
        .topbar .logo-mark .logo-text span {
            color: var(--primary);
        }
        .topnav { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .topnav a {
            color: var(--steel);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            padding: 7px 16px;
            border-radius: 10px;
            transition: all 0.3s;
            background: transparent;
        }
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

        .wrap { position: relative; z-index: 5; max-width: 1400px; margin: 0 auto; padding: 30px; }
        
        .dash-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
        .dash-head h2 { font-size: 24px; font-weight: 800; color: var(--text-dark); }
        .dash-head p { color: var(--steel); font-size: 14px; font-weight: 500; margin-top: 4px; }
        
        .filter-row {
            display: flex;
            gap: 12px;
            align-items: flex-end;
            flex-wrap: wrap;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            padding: 16px 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 24px;
        }
        .filter-row .field { display: flex; flex-direction: column; gap: 4px; }
        .filter-row .field label { font-size: 12px; font-weight: 700; color: var(--steel); }
        .filter-row input, .filter-row select {
            padding: 8px 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            font-family: 'Inter', sans-serif;
            background: rgba(255,255,255,0.7);
            min-width: 140px;
        }
        .filter-row input:focus, .filter-row select:focus { outline: none; border-color: var(--primary); }
        
        .btn-apply {
            padding: 8px 20px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
            white-space: nowrap;
        }
        .btn-apply:hover { background: var(--primary-dark); transform: translateY(-2px); box-shadow: 0 4px 15px rgba(33,115,70,0.3); }
        
        .btn-outline {
            padding: 8px 20px;
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            background: rgba(255,255,255,0.5);
            color: var(--text-dark);
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
        }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); background: rgba(33,115,70,0.08); }
        
        .btn-view-action {
            padding: 4px 14px;
            background: var(--amber);
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
            text-decoration: none;
            display: inline-block;
        }
        .btn-view-action:hover { background: #e65100; transform: scale(1.05); }
        
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .kpi-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            padding: 16px 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            text-align: center;
            transition: all 0.3s ease;
        }
        .kpi-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,0.1); }
        .kpi-card .number { font-size: 30px; font-weight: 900; color: var(--primary); }
        .kpi-card .label { font-size: 13px; font-weight: 600; color: var(--steel); margin-top: 4px; }
        
        .table-shell {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .table-shell table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .table-shell th {
            background: rgba(255,255,255,0.3);
            padding: 10px 12px;
            text-align: left;
            font-weight: 700;
            color: var(--steel);
            border-bottom: 1px solid var(--glass-border);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .table-shell td {
            padding: 8px 12px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            font-weight: 500;
            font-size: 13px;
        }
        .table-shell tr:hover { background: rgba(255,255,255,0.2); }
        
        .eff-good { color: var(--good); font-weight: 700; }
        .eff-bad { color: var(--bad); font-weight: 700; }
        .eff-avg { color: var(--warning); font-weight: 700; }
        .no-data { text-align: center; padding: 40px; color: var(--steel); font-weight: 500; }
        
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
            margin-bottom: 20px;
        }
        .back-button:hover {
            background: rgba(255,255,255,0.3);
            transform: translateX(-4px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        
        
        
        
        .filter-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .component-count {
            font-size: 11px;
            color: var(--steel);
            font-weight: 500;
        }
        .summary-badges {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        .summary-badges .badge-sm {
            font-size: 9px;
            padding: 1px 6px;
            border-radius: 3px;
            color: #fff;
            font-weight: 600;
        }
        .badge-sm.match-out { background: #6c757d; }
        .badge-sm.dhu { background: var(--dhu-color); }
        .badge-sm.lean { background: #17a2b8; }
        .badge-sm.grand { background: #6f42c1; }
        
        @media (max-width: 768px) {
            .topbar { padding: 10px 16px; flex-direction: column; align-items: stretch; gap: 8px; }
            .topnav { justify-content: center; }
            .right { justify-content: center; }
            .wrap { padding: 16px; }
            .filter-row { flex-direction: column; align-items: stretch; }
            .filter-row input, .filter-row select { min-width: unset; }
            .filter-actions { flex-direction: row; flex-wrap: wrap; width: 100%; }
            .filter-actions button { flex: 1; min-width: 80px; }
            .kpi-row { grid-template-columns: 1fr 1fr; }
            .topnav a { padding: 6px 12px; font-size: 13px; }
            .table-shell { overflow-x: auto; }
            .table-shell table { font-size: 12px; }
            .table-shell th,
            .table-shell td { padding: 6px 8px; }
        }
        @media (max-width: 480px) { .kpi-row { grid-template-columns: 1fr; } }
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

    <div class="wrap">
        <a href="#" class="back-button" onclick="history.back(); return false;">
            ← Back
        </a>

        <div class="dash-head">
            <div>
                <h2>Reports</h2>
                <p>Trend of achieved efficiency across your report dates.</p>
            </div>
        </div>

        <!-- Filter Row -->
        <form class="filter-row" method="GET" action="">
            <div class="field">
                <label>From</label>
                <input id="rep-from" name="from" type="date" value="<?php echo $from_date; ?>">
            </div>
            <div class="field">
                <label>To</label>
                <input id="rep-to" name="to" type="date" value="<?php echo $to_date; ?>">
            </div>
            <div class="field">
                <label>Devition</label>
                <select id="rep-division" name="division">
                    <option value="all" <?php echo $division_filter === 'all' ? 'selected' : ''; ?>>All devitions</option>
                    <?php if (!empty($divisions)): ?>
                    <?php foreach ($divisions as $div): ?>
                    <option value="<?php echo $div['id']; ?>" <?php echo $division_filter == $div['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($div['display_name']); ?>
                    </option>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-apply">Apply</button>
                <button type="button" class="btn-outline" onclick="viewAllRecords()">View All</button>
                <button type="button" class="btn-outline" onclick="viewToday()">Today</button>
            </div>
        </form>

        

        <div class="kpi-row" id="rep-kpis">
            <div class="kpi-card"><div class="number"><?php echo $total_reports; ?></div><div class="label">Total Reports</div></div>
            <div class="kpi-card"><div class="number"><?php echo $avg_eff; ?>%</div><div class="label">Avg Efficiency</div></div>
            <div class="kpi-card"><div class="number"><?php echo number_format($total_prod, 0); ?></div><div class="label">Total Production</div></div>
            <div class="kpi-card"><div class="number"><?php echo count($divisions); ?></div><div class="label">Divisions Active</div></div>
        </div>

        <div class="table-shell">
            <table class="report">
                <thead>
                    <tr>
                        <th style="text-align:left;">Date</th>
                        <th style="text-align:left;">Devition</th>
                        <th>Components</th>
                        <th>Achieved Eff</th>
                        <th>Earn Minutes</th>
                        <th>Summary Rows</th>
                        <th style="text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody id="rep-body">
                    <?php if (empty($grouped_reports)): ?>
                    <tr><td colspan="7" class="no-data">
                        No reports found for the selected date range. 
                        Please go to a Devition page, enter data, and click "Save All".
                    </td></tr>
                    <?php else: ?>
                    <?php foreach ($grouped_reports as $group): 
                        $eff = $group['avg_eff'];
                        $eff_class = $eff >= 70 ? 'eff-good' : ($eff >= 50 ? 'eff-avg' : 'eff-bad');
                        $division_name = htmlspecialchars($group['division_name']);
                        
                        // Build view URL with current filters
                        $view_url = 'view_report.php?date=' . $group['date'] . '&division=' . $group['division_id'] . '&from=' . $from_date . '&to=' . $to_date . '&division_filter=' . $division_filter;
                        
                        $summary_count = $group['summary_count'] ?? 0;
                        $component_count = $group['component_count'] ?? 0;
                    ?>
                    <tr>
                        <td><?php echo date('Y-m-d', strtotime($group['date'])); ?></td>
                        <td><?php echo $division_name; ?></td>
                        <td>
                            <span class="component-count"><?php echo $component_count; ?> components</span>
                            <?php if ($summary_count > 0): ?>
                            <span style="color:var(--steel);font-size:11px;"> + <?php echo $summary_count; ?> summary</span>
                            <?php endif; ?>
                        </td>
                        <td class="<?php echo $eff_class; ?>"><?php echo number_format($eff, 1); ?>%</td>
                        <td><?php echo number_format($group['avg_ern'], 1); ?></td>
                        <td>
                            <div class="summary-badges">
                                <?php if ($group['summary']['match_out']): ?>
                                <span class="badge-sm match-out">MO</span>
                                <?php endif; ?>
                                <?php if ($group['summary']['dhu']): ?>
                                <span class="badge-sm dhu">DHU</span>
                                <?php endif; ?>
                                <?php if ($group['summary']['lean_total']): ?>
                                <span class="badge-sm lean">LT</span>
                                <?php endif; ?>
                                <?php if ($group['summary']['grand_total']): ?>
                                <span class="badge-sm grand">GT</span>
                                <?php endif; ?>
                                <?php if ($summary_count == 0): ?>
                                <span style="color:var(--steel);font-size:11px;">—</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="text-align:center;">
                            <a href="<?php echo $view_url; ?>" class="btn-view-action">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
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

        function viewAllRecords() {
            var today = new Date().toISOString().split('T')[0];
            document.getElementById('rep-from').value = '2020-01-01';
            document.getElementById('rep-to').value = today;
            document.getElementById('rep-division').value = 'all';
            document.querySelector('.filter-row').submit();
        }

        function viewToday() {
            var today = new Date().toISOString().split('T')[0];
            document.getElementById('rep-from').value = today;
            document.getElementById('rep-to').value = today;
            document.getElementById('rep-division').value = 'all';
            document.querySelector('.filter-row').submit();
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                var active = document.activeElement;
                if (active && (active.id === 'rep-from' || active.id === 'rep-to' || active.id === 'rep-division')) {
                    document.querySelector('.filter-row').submit();
                }
            }
        });
    </script>
</body>
</html>