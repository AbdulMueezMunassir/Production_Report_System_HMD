<?php
// analytics.php - Analytics Dashboard with Charts
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$conn = getDB();
$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';

// Get date range for charts
$end_date = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d');
$start_date = isset($_GET['start']) ? $_GET['start'] : date('Y-m-d', strtotime('-30 days'));

// --- Data for charts ---

// 1. Hourly Production Progress - Component Unit (today's data for a specific division, e.g., Trouser)
$today = date('Y-m-d');
$division_id = 2; // Trouser (adjust as needed)
$components = getComponents($conn, $division_id);
$hourly_data_component = [];
if (!empty($components)) {
    $first_component = $components[0]; // Use first component for demo
    $data = getReportData($conn, $division_id, $first_component['id'], $today);
    $hourly_data_component = [];
    for ($h = 1; $h <= 11; $h++) {
        $hourly_data_component[] = $data["hour_$h"] ?? 0;
    }
}

// 2. Hourly Production Progress - Assemble Unit (assembly division)
$assembly_division_id = 4; // Assembly
$assembly_components = getComponents($conn, $assembly_division_id);
$hourly_data_assembly = [];
if (!empty($assembly_components)) {
    $first_assembly = $assembly_components[0];
    $data = getReportData($conn, $assembly_division_id, $first_assembly['id'], $today);
    $hourly_data_assembly = [];
    for ($h = 1; $h <= 11; $h++) {
        $hourly_data_assembly[] = $data["hour_$h"] ?? 0;
    }
}

// 3. Monthly Production Trend (last 30 days)
$monthly_production = [];
$monthly_efficiency = [];
$monthly_dhu = [];
$date_range = [];
for ($i = 0; $i < 30; $i++) {
    $date = date('Y-m-d', strtotime("-$i days", strtotime($end_date)));
    $date_range[] = date('d-M', strtotime($date));
    // Get all reports for this date
    $stmt = $conn->prepare("SELECT SUM(day_total) as total, AVG(acvd_eff) as eff FROM production_reports WHERE report_date = ?");
    $stmt->execute([$date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $monthly_production[] = (float)($row['total'] ?? 0);
    $monthly_efficiency[] = (float)($row['eff'] ?? 0) * 100; // convert to percentage
    // Mock DHU - for demo, random between 1-8%
    $monthly_dhu[] = round(rand(1, 8), 1);
}
// Reverse to have chronological order
$date_range = array_reverse($date_range);
$monthly_production = array_reverse($monthly_production);
$monthly_efficiency = array_reverse($monthly_efficiency);
$monthly_dhu = array_reverse($monthly_dhu);

// 4. Category Distribution (Efficiency by Division)
$category_data = [];
$divisions = getDivisions($conn);
foreach ($divisions as $div) {
    $stats = getDivisionStats($conn, $div['id'], $today, 11);
    $category_data[$div['name']] = $stats['efficiency'];
}

// 5. Production PCS - Progress - Component Unit (like first chart but with more data points)
// We'll reuse the hourly data for the first component
$progress_data = $hourly_data_component;
$progress_labels = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'];
// Pad to 10 if less
while (count($progress_data) < 10) {
    $progress_data[] = 0;
}
$progress_data = array_slice($progress_data, 0, 10);

// 6. Assembly Hourly Progress (like second chart)
$assembly_progress_data = $hourly_data_assembly;
while (count($assembly_progress_data) < 10) {
    $assembly_progress_data[] = 0;
}
$assembly_progress_data = array_slice($assembly_progress_data, 0, 10);

// 7. Category Distribution (pie chart) - we already have $category_data
$pie_labels = array_keys($category_data);
$pie_values = array_values($category_data);
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
        .topbar .logo-mark { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 18px; color: var(--primary-dark); }
        .topbar .logo-mark img { height: 30px; width: auto; display: block; }
        .topnav { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
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

        .container { position: relative; z-index: 5; max-width: 1400px; margin: 0 auto; padding: 20px 30px; }
        
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
        .page-header .controls input[type="date"] {
            padding: 7px 12px;
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            font-family: 'Inter', sans-serif;
            background: rgba(255,255,255,0.7);
            color: var(--text-dark);
        }
        .page-header .controls input[type="date"]:focus { outline: none; border-color: var(--primary); }
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
        
        .chart-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        .chart-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }
        .chart-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.1);
        }
        .chart-card h3 {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--glass-border);
        }
        .chart-card canvas {
            width: 100% !important;
            height: 200px !important;
        }
        .chart-card.full-width {
            grid-column: 1 / -1;
        }
        .chart-card .chart-container {
            position: relative;
            height: 200px;
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
        
        @media (max-width: 1024px) {
            .chart-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 768px) {
            .topbar { padding: 10px 16px; flex-direction: column; align-items: stretch; gap: 8px; }
            .topnav { justify-content: center; }
            .right { justify-content: center; }
            .container { padding: 12px 16px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .page-header .controls { width: 100%; flex-wrap: wrap; }
            .chart-grid { grid-template-columns: 1fr; }
            .chart-card canvas { height: 150px !important; }
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
            <a href="dashboard.php">Divisions</a>
            
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

    <div class="container">
        <div class="page-header">
            <div class="title">
                <h2>📊 Analytics Dashboard</h2>
                <p>Visual insights into production performance, efficiency trends, and DHU analysis</p>
            </div>
            <div class="controls">
                <input type="date" id="startDate" value="<?php echo $start_date; ?>" onchange="updateFilters()">
                <span style="color:var(--steel);">to</span>
                <input type="date" id="endDate" value="<?php echo $end_date; ?>" onchange="updateFilters()">
                <button class="btn btn-primary" onclick="updateFilters()">Apply</button>
                <a href="dashboard.php" class="btn btn-secondary">← Back</a>
            </div>
        </div>

        <div class="chart-grid">
            <!-- Chart 1: Production PCS - Progress - Component Unit -->
            <div class="chart-card">
                <h3>📈 Production PCS - Progress - Component Unit</h3>
                <div class="chart-container">
                    <canvas id="chartComponentProgress"></canvas>
                </div>
            </div>

            <!-- Chart 2: Production - Hourly Progress - Assemble Unit -->
            <div class="chart-card">
                <h3>📈 Production - Hourly Progress - Assemble Unit</h3>
                <div class="chart-container">
                    <canvas id="chartAssemblyProgress"></canvas>
                </div>
            </div>

            <!-- Chart 3: Month Produce PCS - Trend Line -->
            <div class="chart-card full-width">
                <h3>📊 Month Produce PCS - Trend Line</h3>
                <div class="chart-container" style="height:200px;">
                    <canvas id="chartMonthlyProduction"></canvas>
                </div>
            </div>

            <!-- Chart 4: Month Efficiency - Trend Line -->
            <div class="chart-card full-width">
                <h3>📊 Month Efficiency - Trend Line</h3>
                <div class="chart-container" style="height:200px;">
                    <canvas id="chartMonthlyEfficiency"></canvas>
                </div>
            </div>

            <!-- Chart 5: Month D.H.U Trend Line -->
            <div class="chart-card full-width">
                <h3>📊 Month D.H.U Trend Line</h3>
                <div class="chart-container" style="height:200px;">
                    <canvas id="chartMonthlyDHU"></canvas>
                </div>
            </div>

            <!-- Chart 6: Category Distribution (Pie) -->
            <div class="chart-card">
                <h3>🍩 Efficiency by Division</h3>
                <div class="chart-container">
                    <canvas id="chartCategoryDistribution"></canvas>
                </div>
            </div>

            <!-- Chart 7: Category Distribution (Bar) -->
            <div class="chart-card">
                <h3>📊 Efficiency by Division (Bar)</h3>
                <div class="chart-container">
                    <canvas id="chartCategoryBar"></canvas>
                </div>
            </div>
        </div>

        <!-- Back Button Under Charts -->
        <div style="margin-top: 10px; text-align: left;">
            <a href="dashboard.php" class="back-button">
                ← Back to Dashboard
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

        function updateFilters() {
            var start = document.getElementById('startDate').value;
            var end = document.getElementById('endDate').value;
            window.location.href = 'analytics.php?start=' + start + '&end=' + end;
        }

        // Data from PHP
        const progressLabels = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'];
        const componentData = <?php echo json_encode($progress_data); ?>;
        const assemblyData = <?php echo json_encode($assembly_progress_data); ?>;
        const monthlyLabels = <?php echo json_encode($date_range); ?>;
        const monthlyProdData = <?php echo json_encode($monthly_production); ?>;
        const monthlyEffData = <?php echo json_encode($monthly_efficiency); ?>;
        const monthlyDHUData = <?php echo json_encode($monthly_dhu); ?>;
        const pieLabels = <?php echo json_encode($pie_labels); ?>;
        const pieValues = <?php echo json_encode($pie_values); ?>;

        // Chart defaults
        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.font.size = 11;
        Chart.defaults.color = '#6b7a8f';

        // 1. Component Progress Chart
        new Chart(document.getElementById('chartComponentProgress'), {
            type: 'line',
            data: {
                labels: progressLabels,
                datasets: [{
                    label: 'Pcs',
                    data: componentData,
                    borderColor: '#217346',
                    backgroundColor: 'rgba(33, 115, 70, 0.1)',
                    tension: 0.3,
                    fill: true,
                    pointBackgroundColor: '#217346',
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'top' }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });

        // 2. Assembly Progress Chart
        new Chart(document.getElementById('chartAssemblyProgress'), {
            type: 'line',
            data: {
                labels: progressLabels,
                datasets: [{
                    label: 'Pcs',
                    data: assemblyData,
                    borderColor: '#764ba2',
                    backgroundColor: 'rgba(118, 75, 162, 0.1)',
                    tension: 0.3,
                    fill: true,
                    pointBackgroundColor: '#764ba2',
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'top' }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });

        // 3. Monthly Production Trend
        new Chart(document.getElementById('chartMonthlyProduction'), {
            type: 'line',
            data: {
                labels: monthlyLabels,
                datasets: [{
                    label: 'Production (Pcs)',
                    data: monthlyProdData,
                    borderColor: '#217346',
                    backgroundColor: 'rgba(33, 115, 70, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#217346',
                    pointRadius: 2,
                    pointHoverRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'top' }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });

        // 4. Monthly Efficiency Trend
        new Chart(document.getElementById('chartMonthlyEfficiency'), {
            type: 'line',
            data: {
                labels: monthlyLabels,
                datasets: [{
                    label: 'Efficiency (%)',
                    data: monthlyEffData,
                    borderColor: '#f57c00',
                    backgroundColor: 'rgba(245, 124, 0, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#f57c00',
                    pointRadius: 2,
                    pointHoverRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'top' }
                },
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { callback: function(v) { return v + '%'; } } }
                }
            }
        });

        // 5. Monthly DHU Trend
        new Chart(document.getElementById('chartMonthlyDHU'), {
            type: 'line',
            data: {
                labels: monthlyLabels,
                datasets: [{
                    label: 'DHU (%)',
                    data: monthlyDHUData,
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#dc3545',
                    pointRadius: 2,
                    pointHoverRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'top' }
                },
                scales: {
                    y: { beginAtZero: true, max: 10, ticks: { callback: function(v) { return v + '%'; } } }
                }
            }
        });

        // 6. Category Distribution (Pie)
        new Chart(document.getElementById('chartCategoryDistribution'), {
            type: 'doughnut',
            data: {
                labels: pieLabels,
                datasets: [{
                    data: pieValues,
                    backgroundColor: ['#217346', '#764ba2', '#f093fb', '#4facfe', '#43e97b', '#fa709a', '#fee140', '#ff6b6b'],
                    borderWidth: 2,
                    borderColor: 'rgba(255,255,255,0.5)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { padding: 10, usePointStyle: true } }
                },
                cutout: '60%'
            }
        });

        // 7. Category Distribution (Bar)
        new Chart(document.getElementById('chartCategoryBar'), {
            type: 'bar',
            data: {
                labels: pieLabels,
                datasets: [{
                    label: 'Efficiency (%)',
                    data: pieValues,
                    backgroundColor: ['#217346', '#764ba2', '#f093fb', '#4facfe', '#43e97b', '#fa709a', '#fee140', '#ff6b6b'],
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { callback: function(v) { return v + '%'; } } }
                }
            }
        });
    </script>
</body>
</html>