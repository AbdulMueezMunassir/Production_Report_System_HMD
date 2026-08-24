<?php
// reports.php - ONLY UNIQUE SAVED REPORTS (No duplicates)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$conn = getDB();

// Get date range - default to today if not set
$from_date = isset($_GET['from']) && !empty($_GET['from']) ? $_GET['from'] : date('Y-m-d');
$to_date = isset($_GET['to']) && !empty($_GET['to']) ? $_GET['to'] : date('Y-m-d');
$division_filter = isset($_GET['division']) ? $_GET['division'] : 'all';

// Define the three main divisions with their display names
$main_divisions = [
    1 => 'Shirt',
    2 => 'Trouser',
    7 => 'Assembly'
];

// Get ONLY the three main divisions
$divisions = [];
try {
    $stmt = $conn->prepare("SELECT * FROM divisions WHERE id IN (1, 2, 7) ORDER BY FIELD(id, 1, 2, 7)");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Filter and rename - only keep IDs 1, 2, 7
    $divisions = [];
    foreach ($results as $div) {
        if (isset($main_divisions[$div['id']])) {
            $div['name'] = $main_divisions[$div['id']];
            $divisions[] = $div;
        }
    }
} catch (Exception $e) {
    $divisions = array();
}

// Build the query - ONLY for main three divisions
// Group by devition_id, unit_id, report_date to avoid duplicates
$sql = "SELECT r.*, d.name as division_name 
        FROM production_reports r 
        JOIN divisions d ON r.devition_id = d.id 
        WHERE r.report_date BETWEEN ? AND ?
        AND d.id IN (1, 2, 7)
        AND r.day_total > 0
        GROUP BY r.devition_id, r.unit_id, r.report_date
        ORDER BY r.report_date DESC, r.id DESC";
$params = array($from_date, $to_date);

if ($division_filter !== 'all' && !empty($division_filter)) {
    $sql = "SELECT r.*, d.name as division_name 
            FROM production_reports r 
            JOIN divisions d ON r.devition_id = d.id 
            WHERE r.report_date BETWEEN ? AND ?
            AND d.id = ?
            AND r.day_total > 0
            GROUP BY r.devition_id, r.unit_id, r.report_date
            ORDER BY r.report_date DESC, r.id DESC";
    $params = array($from_date, $to_date, (int)$division_filter);
}

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($reports)) $reports = array();
} catch (Exception $e) {
    error_log("Reports query error: " . $e->getMessage());
    $reports = array();
}

// Calculate stats
$total_reports = count($reports);
$avg_eff = 0;
$total_prod = 0;
foreach ($reports as $r) {
    $avg_eff += $r['acvd_eff'] ?? 0;
    $total_prod += $r['day_total'] ?? 0;
}
$avg_eff = $total_reports > 0 ? round(($avg_eff / $total_reports) * 100, 1) : 0;

$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';

// Debug: Get total unique records in database for main divisions
$debug_total = 0;
try {
    $check = $conn->query("SELECT COUNT(DISTINCT CONCAT(devition_id, '-', unit_id, '-', report_date)) as count FROM production_reports WHERE devition_id IN (1, 2, 7) AND day_total > 0");
    $debug_total = $check->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
} catch (Exception $e) {
    $debug_total = 0;
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
            font-size: 14px;
        }
        .table-shell th {
            background: rgba(255,255,255,0.3);
            padding: 12px 16px;
            text-align: left;
            font-weight: 700;
            color: var(--steel);
            border-bottom: 1px solid var(--glass-border);
            font-size: 13px;
        }
        .table-shell td {
            padding: 10px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            font-weight: 500;
        }
        .table-shell tr:hover { background: rgba(255,255,255,0.2); }
        .eff-good { color: var(--good); font-weight: 700; }
        .eff-bad { color: var(--bad); font-weight: 700; }
        .eff-avg { color: var(--warning); font-weight: 700; }
        .no-data { text-align: center; padding: 40px; color: var(--steel); font-weight: 500; }
        .btn-view {
            padding: 4px 12px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-block;
        }
        .btn-view:hover { background: var(--primary-dark); }
        
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
        
        .debug-info {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid rgba(255, 193, 7, 0.3);
            padding: 8px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 13px;
            color: #856404;
        }
        .debug-success {
            background: rgba(40, 167, 69, 0.1);
            border: 1px solid rgba(40, 167, 69, 0.3);
            padding: 8px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 13px;
            color: #155724;
        }
        .debug-success strong { color: #155724; }
        
        @media (max-width: 768px) {
            .topbar { padding: 10px 16px; flex-direction: column; align-items: stretch; gap: 8px; }
            .topnav { justify-content: center; }
            .right { justify-content: center; }
            .wrap { padding: 16px; }
            .filter-row { flex-direction: column; align-items: stretch; }
            .filter-row input, .filter-row select { min-width: unset; }
            .kpi-row { grid-template-columns: 1fr 1fr; }
            .topnav a { padding: 6px 12px; font-size: 13px; }
            .weather-bar { padding: 8px 16px; justify-content: center; flex-wrap: wrap; }
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

        <?php if ($debug_total > 0 && $total_reports == 0): ?>
        <div class="debug-info">
            ⚠️ There are <strong><?php echo $debug_total; ?></strong> total unique records in the database, but none match your current filter. 
            Try clicking "View All Records" or adjust your date range.
        </div>
        <?php endif; ?>

        <?php if ($total_reports > 0): ?>
        <div class="debug-success">
            ✅ Found <strong><?php echo $total_reports; ?></strong> unique reports matching your filter.
        </div>
        <?php endif; ?>

        <div class="filter-row">
            <div class="field"><label>From</label><input id="rep-from" type="date" value="<?php echo $from_date; ?>"></div>
            <div class="field"><label>To</label><input id="rep-to" type="date" value="<?php echo $to_date; ?>"></div>
            <div class="field">
                <label>Devition</label>
                <select id="rep-division">
                    <option value="all" <?php echo $division_filter === 'all' ? 'selected' : ''; ?>>All devitions</option>
                    <?php if (!empty($divisions)): ?>
                    <?php foreach ($divisions as $div): ?>
                    <option value="<?php echo $div['id']; ?>" <?php echo $division_filter == $div['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($div['name']); ?>
                    </option>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <button class="btn-outline" onclick="applyFilters()">Apply</button>
            <button class="btn-outline" onclick="viewAllRecords()">View All Records</button>
            <button class="btn-outline" onclick="viewToday()">View Today</button>
        </div>

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
                        <th style="text-align:left;">Component</th>
                        <th>Day Total</th>
                        <th>Achieved Eff</th>
                        <th style="text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody id="rep-body">
                    <?php if (empty($reports)): ?>
                    <tr><td colspan="6" class="no-data">
                        <?php if ($debug_total > 0): ?>
                            No reports match your current filter. 
                            <br><small>Total unique records in database: <?php echo $debug_total; ?></small>
                            <br><small>Try clicking "View All Records" or adjusting the date range.</small>
                        <?php else: ?>
                            No reports found. Please add data in a Devition and click "Save All".
                        <?php endif; ?>
                    </td></tr>
                    <?php else: ?>
                    <?php 
                    $displayed = array();
                    foreach ($reports as $report): 
                        // Skip duplicates
                        $key = $report['devition_id'] . '-' . $report['unit_id'] . '-' . $report['report_date'];
                        if (in_array($key, $displayed)) continue;
                        $displayed[] = $key;
                        
                        $eff = ($report['acvd_eff'] ?? 0) * 100;
                        $eff_class = $eff >= 70 ? 'eff-good' : ($eff >= 50 ? 'eff-avg' : 'eff-bad');
                        // Get component name
                        $component_name = 'Unit #' . ($report['unit_id'] ?? 'N/A');
                        try {
                            $comp_stmt = $conn->prepare("SELECT name FROM components WHERE id = ?");
                            $comp_stmt->execute([$report['unit_id']]);
                            $comp = $comp_stmt->fetch(PDO::FETCH_ASSOC);
                            if ($comp) {
                                $component_name = $comp['name'];
                            }
                        } catch (Exception $e) {
                            // Ignore
                        }
                        
                        // Format division name
                        $div_name = $report['division_name'] ?? 'Unknown';
                        if ($div_name == 'Shirt Assembly') $div_name = 'Assembly';
                    ?>
                    <tr>
                        <td><?php echo date('Y-m-d', strtotime($report['report_date'])); ?></td>
                        <td><?php echo htmlspecialchars($div_name); ?></td>
                        <td><?php echo htmlspecialchars($component_name); ?></td>
                        <td><?php echo number_format($report['day_total'] ?? 0, 0); ?></td>
                        <td class="<?php echo $eff_class; ?>"><?php echo number_format($eff, 1); ?>%</td>
                        <td style="text-align:center;">
                            <a href="view_report.php?id=<?php echo $report['id']; ?>" class="btn-view">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
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

        function applyFilters() {
            var from = document.getElementById('rep-from').value;
            var to = document.getElementById('rep-to').value;
            var division = document.getElementById('rep-division').value;
            window.location.href = 'reports.php?from=' + from + '&to=' + to + '&division=' + division;
        }

        function viewAllRecords() {
            var today = new Date().toISOString().split('T')[0];
            var from = '2020-01-01';
            window.location.href = 'reports.php?from=' + from + '&to=' + today + '&division=all';
        }

        function viewToday() {
            var today = new Date().toISOString().split('T')[0];
            window.location.href = 'reports.php?from=' + today + '&to=' + today + '&division=all';
        }

        // Set default date to today if empty
        document.addEventListener('DOMContentLoaded', function() {
            var today = new Date().toISOString().split('T')[0];
            var fromInput = document.getElementById('rep-from');
            var toInput = document.getElementById('rep-to');
            
            if (!fromInput.value) {
                fromInput.value = today;
            }
            if (!toInput.value) {
                toInput.value = today;
            }
        });
    </script>
</body>
</html>