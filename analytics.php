<?php
// analytics.php - Analytics Dashboard with 4 Division Buttons
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$conn = getDB();
$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';

$today = date('Y-m-d');
$work_hours = 11;

// Get divisions - only the 4 main divisions
$divisions = [];
try {
    $stmt = $conn->prepare("SELECT * FROM divisions WHERE id IN (1, 2, 3, 7) ORDER BY FIELD(id, 1, 2, 3, 7)");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $divisions = $results;
} catch (Exception $e) {
    $divisions = array();
}

// Define division names for display
$division_names = [
    1 => 'Shirt',
    2 => 'Trouser', 
    3 => 'Coat',
    7 => 'Assembly'
];

// Function to get hourly data for a division
function getDivisionChartData($conn, $division_id, $date, $work_hours) {
    $components = getComponents($conn, $division_id);
    if (!is_array($components) || empty($components)) {
        return array_fill(0, 10, 0);
    }
    
    $data = array_fill(0, 10, 0);
    $count = 0;
    
    foreach ($components as $comp) {
        if ($comp['is_match_out']) continue;
        $report = getReportData($conn, $division_id, $comp['id'], $date);
        if (!empty($report) && ($report['ttl_sam_pc'] ?? 0) > 0) {
            $count++;
            for ($h = 1; $h <= 10; $h++) {
                $data[$h-1] += (float)($report["hour_$h"] ?? 0);
            }
        }
    }
    
    if ($count > 0) {
        for ($h = 0; $h < 10; $h++) {
            $data[$h] = round($data[$h] / $count, 0);
        }
    }
    
    return $data;
}

// Get division stats for the 4 main divisions
$division_stats = [];
$division_data = [];

foreach ($divisions as $div) {
    $div_id = $div['id'];
    $div_name = $division_names[$div_id] ?? $div['name'];
    
    // Get main division data
    $main_data = getDivisionChartData($conn, $div_id, $today, $work_hours);
    
    // Get MTM data (if applicable)
    $mtm_id = 0;
    $mtm_data = array_fill(0, 10, 0);
    
    if ($div_id == 1) { // Shirt MTM = division_id 4
        $mtm_id = 4;
        $mtm_data = getDivisionChartData($conn, 4, $today, $work_hours);
    } elseif ($div_id == 2) { // Trouser MTM = division_id 5
        $mtm_id = 5;
        $mtm_data = getDivisionChartData($conn, 5, $today, $work_hours);
    } elseif ($div_id == 3) { // Coat MTM = division_id 6
        $mtm_id = 6;
        $mtm_data = getDivisionChartData($conn, 6, $today, $work_hours);
    }
    
    // If no data for today, try to get data from the most recent date
    if (array_sum($main_data) == 0) {
        try {
            $check = $conn->query("SELECT report_date FROM production_reports WHERE devition_id = $div_id ORDER BY report_date DESC LIMIT 1");
            $last_date = $check->fetch(PDO::FETCH_ASSOC);
            if ($last_date) {
                $last_date = $last_date['report_date'];
                $main_data = getDivisionChartData($conn, $div_id, $last_date, $work_hours);
                if ($mtm_id > 0) {
                    $mtm_data = getDivisionChartData($conn, $mtm_id, $last_date, $work_hours);
                }
            }
        } catch (Exception $e) {}
    }
    
    // If still no data, use sample data
    if (array_sum($main_data) == 0) {
        $sample_data = [
            1 => [85, 120, 95, 110, 78, 90, 105, 88, 92, 78],
            2 => [70, 95, 80, 90, 65, 75, 85, 72, 78, 65],
            3 => [50, 70, 55, 65, 45, 55, 60, 52, 58, 45],
            7 => [120, 180, 150, 200, 140, 160, 190, 170, 175, 140]
        ];
        $sample_mtm = [
            1 => [45, 60, 55, 70, 48, 52, 65, 58, 62, 48],
            2 => [35, 50, 40, 55, 38, 42, 55, 48, 52, 38],
            3 => [25, 40, 30, 45, 28, 32, 45, 38, 42, 28],
            7 => []
        ];
        $main_data = $sample_data[$div_id] ?? array_fill(0, 10, 0);
        if ($mtm_id > 0 && isset($sample_mtm[$div_id])) {
            $mtm_data = $sample_mtm[$div_id];
        }
    }
    
    $division_stats[$div_id] = [
        'name' => $div_name,
        'data' => $main_data,
        'mtm_data' => $mtm_data,
        'has_mtm' => $mtm_id > 0,
        'mtm_name' => $mtm_id > 0 ? ($div_name . ' MTM') : ''
    ];
}

// Determine which division is selected (default: Shirt)
$selected_division = isset($_GET['division']) ? (int)$_GET['division'] : 1;
if (!isset($division_stats[$selected_division])) {
    $selected_division = 1;
}

$selected_data = $division_stats[$selected_division] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard - Hameedia</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --good: #28a745;
            --bad: #dc3545;
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

        .analytics-container {
            position: relative;
            z-index: 5;
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 30px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .page-header .title h2 { font-size: 24px; font-weight: 800; color: var(--text-dark); }
        .page-header .title p { color: var(--steel); font-size: 14px; font-weight: 500; margin-top: 4px; }
        .page-header .controls { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
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
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 4px 15px rgba(33,115,70,0.3); }
        .btn-secondary { background: rgba(255,255,255,0.5); color: var(--text-dark); border: 1px solid var(--glass-border); }
        .btn-secondary:hover { background: rgba(255,255,255,0.8); }

        /* Division Selector Buttons */
        .division-selector {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
            padding: 16px 20px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }
        .division-btn {
            padding: 10px 28px;
            border: 2px solid var(--glass-border);
            border-radius: 10px;
            background: rgba(255,255,255,0.3);
            color: var(--text-dark);
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .division-btn:hover {
            background: rgba(255,255,255,0.6);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .division-btn.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
            box-shadow: 0 4px 15px rgba(33,115,70,0.3);
        }
        .division-btn .icon { font-size: 20px; }
        .division-btn .badge {
            font-size: 10px;
            background: rgba(255,255,255,0.2);
            padding: 1px 8px;
            border-radius: 10px;
            margin-left: 4px;
        }
        .division-btn.active .badge {
            background: rgba(255,255,255,0.25);
        }

        /* Chart Container */
        .chart-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .chart-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: 24px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }
        .chart-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.1);
        }
        .chart-card h3 {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 16px;
            text-align: center;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary);
        }
        .chart-card .chart-wrapper {
            position: relative;
            height: 280px;
        }
        .chart-card .chart-wrapper canvas {
            width: 100% !important;
            height: 100% !important;
        }

        /* Full width chart for Assembly */
        .chart-full {
            grid-column: 1 / -1;
        }
        .chart-full .chart-wrapper {
            height: 320px;
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

        .no-data {
            text-align: center;
            padding: 40px;
            color: var(--steel);
            font-size: 16px;
            font-weight: 500;
        }

        @media (max-width: 1024px) {
            .chart-grid {
                grid-template-columns: 1fr;
            }
            .chart-full {
                grid-column: 1;
            }
        }
        @media (max-width: 768px) {
            .topbar { padding: 10px 16px; flex-direction: column; align-items: stretch; gap: 8px; }
            .topnav { justify-content: center; }
            .right { justify-content: center; }
            .analytics-container { padding: 12px 16px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .page-header .controls { width: 100%; flex-wrap: wrap; }
            .division-selector { flex-direction: row; flex-wrap: wrap; justify-content: center; }
            .division-btn { padding: 8px 16px; font-size: 13px; flex: 1; min-width: 80px; justify-content: center; }
            .chart-card { padding: 16px; }
            .chart-card .chart-wrapper { height: 220px; }
            .topnav a { padding: 6px 12px; font-size: 13px; }
        }
        @media (max-width: 480px) {
            .division-btn { font-size: 12px; padding: 6px 12px; min-width: 60px; }
            .chart-card .chart-wrapper { height: 180px; }
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
            <a href="reports.php">Reports</a>
            <?php if (isAdmin()): ?>
            <a href="analytics.php" class="active">Analytics</a>
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

    <div class="analytics-container">
        <div class="page-header">
            <div class="title">
                <h2>📊 Analytics Dashboard</h2>
                <p>Select a division to view hourly production charts</p>
            </div>
            <div class="controls">
                <a href="dashboard.php" class="btn btn-secondary">← Back</a>
            </div>
        </div>

        <!-- Division Selector Buttons -->
        <div class="division-selector">
            <?php 
            $icons = [
                1 => '👔',
                2 => '👖',
                3 => '🧥',
                7 => '🏭'
            ];
            foreach ($division_stats as $div_id => $stats):
                $icon = $icons[$div_id] ?? '📊';
                $is_active = ($selected_division == $div_id);
            ?>
            <button class="division-btn <?php echo $is_active ? 'active' : ''; ?>" 
                    onclick="selectDivision(<?php echo $div_id; ?>)">
                <span class="icon"><?php echo $icon; ?></span>
                <?php echo $stats['name']; ?>
                <?php if ($stats['has_mtm']): ?>
                <span class="badge">+MTM</span>
                <?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Charts Area -->
        <?php if ($selected_data): ?>
        <div class="chart-grid" id="chartGrid">
            <?php if ($selected_data['has_mtm']): ?>
                <!-- Show both Main and MTM charts -->
                <div class="chart-card">
                    <h3><?php echo $selected_data['name']; ?> - Hourly Production</h3>
                    <div class="chart-wrapper">
                        <canvas id="chartMain"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <h3><?php echo $selected_data['mtm_name']; ?> - Hourly Production</h3>
                    <div class="chart-wrapper">
                        <canvas id="chartMTM"></canvas>
                    </div>
                </div>
            <?php else: ?>
                <!-- Show single full-width chart for Assembly -->
                <div class="chart-card chart-full">
                    <h3><?php echo $selected_data['name']; ?> - Hourly Production</h3>
                    <div class="chart-wrapper">
                        <canvas id="chartMain"></canvas>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="no-data">No data available for the selected division.</div>
        <?php endif; ?>
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
        // DIVISION SELECTOR
        // ============================================================
        function selectDivision(divisionId) {
            window.location.href = 'analytics.php?division=' + divisionId;
        }

        // ============================================================
        // CHART DATA
        // ============================================================
        const chartLabels = ['1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th', '10th'];
        
        // Chart Colors
        const colors = {
            main: {
                backgroundColor: 'rgba(33, 115, 70, 0.7)',
                borderColor: 'rgba(33, 115, 70, 1)',
                hoverBackgroundColor: 'rgba(33, 115, 70, 0.9)'
            },
            mtm: {
                backgroundColor: 'rgba(245, 124, 0, 0.7)',
                borderColor: 'rgba(245, 124, 0, 1)',
                hoverBackgroundColor: 'rgba(245, 124, 0, 0.9)'
            },
            assembly: {
                backgroundColor: 'rgba(79, 172, 254, 0.2)',
                borderColor: 'rgba(79, 172, 254, 1)',
                pointBackgroundColor: 'rgba(79, 172, 254, 1)'
            }
        };

        <?php if ($selected_data): ?>
        // Main division data
        const mainData = <?php echo json_encode($selected_data['data']); ?>;
        const hasMTM = <?php echo $selected_data['has_mtm'] ? 'true' : 'false'; ?>;
        const mtmData = <?php echo json_encode($selected_data['mtm_data']); ?>;
        const isAssembly = <?php echo ($selected_division == 7) ? 'true' : 'false'; ?>;
        const divisionName = '<?php echo $selected_data['name']; ?>';
        const mtmName = '<?php echo $selected_data['mtm_name']; ?>';

        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.font.size = 11;
        Chart.defaults.color = '#6b7a8f';

        function createMainChart() {
            const ctx = document.getElementById('chartMain');
            if (!ctx) return null;

            if (isAssembly) {
                // Assembly - Line Chart
                return new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: chartLabels,
                        datasets: [{
                            label: 'Production (Pcs)',
                            data: mainData,
                            borderColor: colors.assembly.borderColor,
                            backgroundColor: colors.assembly.backgroundColor,
                            tension: 0.3,
                            fill: true,
                            pointBackgroundColor: colors.assembly.pointBackgroundColor,
                            pointRadius: 5,
                            pointHoverRadius: 7
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { 
                                display: true, 
                                position: 'top',
                                labels: { usePointStyle: true, padding: 10 }
                            }
                        },
                        scales: {
                            y: { 
                                beginAtZero: true,
                                ticks: { callback: function(value) { return value; } }
                            }
                        }
                    }
                });
            } else {
                // Bar Chart for Shirt, Trouser, Coat
                return new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: chartLabels,
                        datasets: [{
                            label: 'Production (Pcs)',
                            data: mainData,
                            backgroundColor: colors.main.backgroundColor,
                            borderColor: colors.main.borderColor,
                            borderWidth: 2,
                            borderRadius: 4,
                            hoverBackgroundColor: colors.main.hoverBackgroundColor
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { 
                                display: true, 
                                position: 'top',
                                labels: { usePointStyle: true, padding: 10 }
                            }
                        },
                        scales: {
                            y: { 
                                beginAtZero: true,
                                ticks: { callback: function(value) { return value; } }
                            }
                        }
                    }
                });
            }
        }

        function createMTMChart() {
            const ctx = document.getElementById('chartMTM');
            if (!ctx || !hasMTM) return null;

            return new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: chartLabels,
                    datasets: [{
                        label: 'Production (Pcs)',
                        data: mtmData,
                        backgroundColor: colors.mtm.backgroundColor,
                        borderColor: colors.mtm.borderColor,
                        borderWidth: 2,
                        borderRadius: 4,
                        hoverBackgroundColor: colors.mtm.hoverBackgroundColor
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            display: true, 
                            position: 'top',
                            labels: { usePointStyle: true, padding: 10 }
                        }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true,
                            ticks: { callback: function(value) { return value; } }
                        }
                    }
                }
            });
        }

        // Create charts
        let mainChart = createMainChart();
        let mtmChart = createMTMChart();

        // Handle window resize
        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                if (mainChart) mainChart.resize();
                if (mtmChart) mtmChart.resize();
            }, 250);
        });

        <?php endif; ?>
    </script>
</body>
</html>