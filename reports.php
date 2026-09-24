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

// ============================================================
// FILTER HANDLING
// ============================================================
$from_date       = isset($_GET['from']) && !empty($_GET['from']) ? $_GET['from'] : date('Y-m-d');
$to_date         = isset($_GET['to']) && !empty($_GET['to']) ? $_GET['to'] : date('Y-m-d');
$division_filter = isset($_GET['division']) ? $_GET['division'] : 'all';

// Save to session so other pages can pick it up if needed
$_SESSION['report_from']     = $from_date;
$_SESSION['report_to']       = $to_date;
$_SESSION['report_division'] = $division_filter;

// ============================================================
// LOAD DIVISIONS
// ============================================================
$division_display_names = [];
$divisions              = [];
$assembly_division_ids  = []; // To mark "assembly" divisions

try {
    $stmt = $conn->prepare("SELECT id, name, type FROM divisions ORDER BY id ASC");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($results as $div) {
        $is_assembly = (isset($div['type']) && $div['type'] === 'assembly');
        $display_name = $is_assembly ? 'Assembly' : $div['name'];

        $division_display_names[$div['id']] = $display_name;
        $div['display_name'] = $display_name;
        $div['is_assembly']  = $is_assembly;

        if ($is_assembly) {
            $assembly_division_ids[] = (int)$div['id'];
        }

        $divisions[] = $div;
    }
} catch (Exception $e) {
    error_log("Divisions query error: " . $e->getMessage());
}

// ============================================================
// LOAD REPORT ROWS (components)
// ============================================================
$sql = "SELECT 
            DATE(r.report_date) AS report_date, 
            r.devition_id,
            r.acvd_eff,
            r.day_total,
            r.unit_id,
            r.ttl_sam_pc,
            r.unit_smv,
            r.unit_carder,
            r.id AS report_id,
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

$params = [$from_date, $to_date];

if ($division_filter !== 'all' && !empty($division_filter)) {
    $sql .= " AND r.devition_id = ?";
    $params[] = (int)$division_filter;
}

$sql .= " ORDER BY r.report_date DESC, r.devition_id ASC";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($reports)) $reports = [];
} catch (Exception $e) {
    error_log("Reports query error: " . $e->getMessage());
    $reports = [];
}

// ============================================================
// LOAD SUMMARY ROWS (MO / DHU / Lean / Grand)
// ============================================================
$summary_sql = "SELECT 
            DATE(r.report_date) AS report_date, 
            r.devition_id,
            r.unit_id,
            r.day_total,
            r.acvd_eff,
            r.ern_minutes,
            r.unit_carder,
            r.ttl_sam_pc,
            r.unit_smv,
            r.profit
        FROM production_reports r 
        WHERE DATE(r.report_date) BETWEEN ? AND ?
        AND r.unit_id IN (996, 997, 998, 999)";

$summary_params = [$from_date, $to_date];
if ($division_filter !== 'all' && !empty($division_filter)) {
    $summary_sql .= " AND r.devition_id = ?";
    $summary_params[] = (int)$division_filter;
}

try {
    $stmt = $conn->prepare($summary_sql);
    $stmt->execute($summary_params);
    $summary_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($summary_reports)) $summary_reports = [];
} catch (Exception $e) {
    error_log("Summary query error: " . $e->getMessage());
    $summary_reports = [];
}

// ============================================================
// GROUP BY DATE + DIVISION
// ============================================================
$grouped_reports = [];

$initGroup = function ($date, $division_id, $division_name) {
    return [
        'date'              => $date,
        'division_id'       => $division_id,
        'division_name'     => $division_name,
        'components'        => [],
        'summary'           => [
            'match_out'    => null,
            'dhu'          => null,
            'lean_total'   => null,
            'grand_total'  => null,
        ],
        'total_pcs'         => 0,
        'total_ern'         => 0,
        'total_eff_sum'     => 0,   // sum of eff (for weighting)
        'total_day_for_eff' => 0,   // day_total used as weight
        'total_profit'      => 0,
        'eff_count'         => 0,
    ];
};

// Regular components
foreach ($reports as $report) {
    $devition_id  = (int)$report['devition_id'];
    $display_name = $division_display_names[$devition_id] ?? 'Unknown';
    $key          = date('Y-m-d', strtotime($report['report_date'])) . '_' . $devition_id;

    if (!isset($grouped_reports[$key])) {
        $grouped_reports[$key] = $initGroup($report['report_date'], $devition_id, $display_name);
    }

    $grouped_reports[$key]['components'][] = $report;

    $dayTotal = (float)($report['day_total'] ?? 0);
    if ($dayTotal > 0) {
        $grouped_reports[$key]['total_pcs']     += $dayTotal;
        $grouped_reports[$key]['total_ern']     += (float)($report['ern_minutes'] ?? 0);
        $grouped_reports[$key]['total_profit']  += (float)($report['profit'] ?? 0);
        $grouped_reports[$key]['eff_count']++;

        $effVal = (float)($report['acvd_eff'] ?? 0);
        // Support both 0..1 (fraction) and 0..100 (percent)
        if ($effVal > 0 && $effVal <= 1) {
            $effVal *= 100;
        }
        $grouped_reports[$key]['total_eff_sum']     += $effVal;
        $grouped_reports[$key]['total_day_for_eff'] += $dayTotal;
    }
}

// Summary rows
foreach ($summary_reports as $report) {
    $devition_id  = (int)$report['devition_id'];
    $display_name = $division_display_names[$devition_id] ?? 'Unknown';
    $key          = date('Y-m-d', strtotime($report['report_date'])) . '_' . $devition_id;

    if (!isset($grouped_reports[$key])) {
        $grouped_reports[$key] = $initGroup($report['report_date'], $devition_id, $display_name);
    }

    $unit_id = (int)($report['unit_id'] ?? 0);
    if ($unit_id === 999) {
        $grouped_reports[$key]['summary']['match_out'] = $report;
    } elseif ($unit_id === 998) {
        $grouped_reports[$key]['summary']['dhu'] = $report;
    } elseif ($unit_id === 997) {
        $grouped_reports[$key]['summary']['lean_total'] = $report;
    } elseif ($unit_id === 996) {
        $grouped_reports[$key]['summary']['grand_total'] = $report;
    }
}

// Finalize averages
foreach ($grouped_reports as $key => &$group) {
    $group['avg_eff'] = $group['eff_count'] > 0
        ? round($group['total_eff_sum'] / $group['eff_count'], 1)
        : 0;

    $group['avg_ern'] = $group['eff_count'] > 0
        ? round($group['total_ern'] / $group['eff_count'], 1)
        : 0;

    $summary_count = 0;
    if ($group['summary']['match_out'])   $summary_count++;
    if ($group['summary']['dhu'])         $summary_count++;
    if ($group['summary']['lean_total'])  $summary_count++;
    if ($group['summary']['grand_total']) $summary_count++;

    $group['summary_count']   = $summary_count;
    $group['component_count'] = count($group['components']);

    // Mark whether this division is an assembly type
    $group['is_assembly'] = in_array($group['division_id'], $assembly_division_ids, true);
}
unset($group);

// ============================================================
// KPI CALCULATIONS
// ============================================================
$total_groups        = count($grouped_reports);           // date-division groups
$total_components    = 0;
$total_ern_global    = 0;
$total_prod_global   = 0;
$total_profit_global = 0;
$eff_weighted_sum    = 0;
$eff_weight_total    = 0;

foreach ($grouped_reports as $r) {
    $total_components += $r['component_count'];
    $total_prod_global += $r['total_pcs'];
    $total_ern_global += $r['total_ern'];
    $total_profit_global += $r['total_profit'];

    // Weighted average: weight by day_total
    if ($r['total_day_for_eff'] > 0) {
        $eff_weighted_sum += $r['total_eff_sum'];
        $eff_weight_total += $r['eff_count'];
    }
}

$avg_eff = $eff_weight_total > 0 ? round($eff_weighted_sum / $eff_weight_total, 1) : 0;

$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
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
            top: 0; left: 0;
            width: 100%; height: 100%;
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
        .topbar .logo-mark { display: flex; align-items: center; gap: 12px; font-weight: 800; font-size: 20px; color: var(--primary-dark); text-decoration: none; }
        .topbar .logo-mark .logo-icon { background: var(--primary); color: #fff; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 10px; font-weight: 700; font-size: 18px; }
        .topbar .logo-mark .logo-text { letter-spacing: -0.5px; }
        .topbar .logo-mark .logo-text span { color: var(--primary); }
        .topnav { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
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
        .profit-pos { color: var(--good); font-weight: 700; }
        .profit-neg { color: var(--bad); font-weight: 700; }
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

        .filter-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

        .component-count { font-size: 11px; color: var(--steel); font-weight: 500; }
        .summary-badges { display: flex; gap: 4px; flex-wrap: wrap; }
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
        .badge-sm.assembly { background: var(--primary); }

        .assembly-tag {
            display: inline-block;
            font-size: 9px;
            font-weight: 700;
            color: #fff;
            background: var(--primary);
            padding: 1px 6px;
            border-radius: 4px;
            margin-left: 6px;
            vertical-align: middle;
            letter-spacing: 0.3px;
        }

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
            .table-shell th, .table-shell td { padding: 6px 8px; }
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
        <a href="#" class="back-button" onclick="history.back(); return false;">← Back</a>

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
                <input id="rep-from" name="from" type="date" value="<?php echo htmlspecialchars($from_date); ?>">
            </div>
            <div class="field">
                <label>To</label>
                <input id="rep-to" name="to" type="date" value="<?php echo htmlspecialchars($to_date); ?>">
            </div>
            <div class="field">
                <label>Division</label>
                <select id="rep-division" name="division">
                    <option value="all" <?php echo $division_filter === 'all' ? 'selected' : ''; ?>>All divisions</option>
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

        <!-- KPIs -->
        <div class="kpi-row" id="rep-kpis">
            <div class="kpi-card">
                <div class="number"><?php echo $total_groups; ?></div>
                <div class="label">Report Groups</div>
            </div>
            <div class="kpi-card">
                <div class="number"><?php echo $avg_eff; ?>%</div>
                <div class="label">Avg Efficiency</div>
            </div>
            <div class="kpi-card">
                <div class="number"><?php echo number_format($total_prod_global, 0); ?></div>
                <div class="label">Total Production (Pcs)</div>
            </div>
            <div class="kpi-card">
                <div class="number <?php echo $total_profit_global >= 0 ? 'profit-pos' : 'profit-neg'; ?>">
                    <?php echo number_format($total_profit_global, 0); ?>
                </div>
                <div class="label">Total Profit</div>
            </div>
        </div>

        <div class="table-shell">
            <table class="report">
                <thead>
                    <tr>
                        <th style="text-align:left;">Date</th>
                        <th style="text-align:left;">Division</th>
                        <th>Components</th>
                        <th>Achieved Eff</th>
                        <th>Earn Minutes</th>
                        <th>Profit</th>
                        <th>Summary Rows</th>
                        <th style="text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody id="rep-body">
                    <?php if (empty($grouped_reports)): ?>
                    <tr>
                        <td colspan="8" class="no-data">
                            No reports found for the selected date range.<br>
                            Please go to a Division page, enter data, and click "Save All".
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($grouped_reports as $group): 
                        $eff = $group['avg_eff'];
                        $eff_class = $eff >= 70 ? 'eff-good' : ($eff >= 50 ? 'eff-avg' : 'eff-bad');
                        $division_name = htmlspecialchars($group['division_name']);
                        $profit = (float)$group['total_profit'];
                        $profit_class = $profit >= 0 ? 'profit-pos' : 'profit-neg';

                        // Build view URL with current filters
                        $view_url = 'view_report.php?date=' . urlencode($group['date']) 
                                  . '&division=' . urlencode($group['division_id']) 
                                  . '&from=' . urlencode($from_date) 
                                  . '&to=' . urlencode($to_date) 
                                  . '&division_filter=' . urlencode($division_filter);

                        $summary_count   = $group['summary_count'] ?? 0;
                        $component_count = $group['component_count'] ?? 0;
                    ?>
                    <tr>
                        <td><?php echo date('Y-m-d', strtotime($group['date'])); ?></td>
                        <td>
                            <?php echo $division_name; ?>
                            <?php if (!empty($group['is_assembly'])): ?>
                                <span class="assembly-tag">ASSEMBLY</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="component-count"><?php echo $component_count; ?> components</span>
                            <?php if ($summary_count > 0): ?>
                            <span style="color:var(--steel);font-size:11px;"> + <?php echo $summary_count; ?> summary</span>
                            <?php endif; ?>
                        </td>
                        <td class="<?php echo $eff_class; ?>"><?php echo number_format($eff, 1); ?>%</td>
                        <td><?php echo number_format($group['avg_ern'], 1); ?></td>
                        <td class="<?php echo $profit_class; ?>"><?php echo number_format($profit, 0); ?></td>
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