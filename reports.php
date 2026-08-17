<?php
// reports.php - Reports Page with Glass Morphism & Logo
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

$conn = getDBConnection();

// Get filter parameters
$from_date = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-7 days'));
$to_date = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');
$division_filter = isset($_GET['division']) ? $_GET['division'] : 'all';

// Get all divisions for filter
$divisions = getDivisions($conn);

// Get report data
$reports = [];
$chart_data = [];

// Build query
$sql = "SELECT r.*, d.name as division_name 
        FROM production_reports r 
        JOIN divisions d ON r.devition_id = d.id 
        WHERE r.report_date BETWEEN ? AND ?";

$params = [$from_date, $to_date];
$types = "ss";

if ($division_filter !== 'all') {
    $sql .= " AND d.id = ?";
    $params[] = (int)$division_filter;
    $types .= "i";
}

$sql .= " ORDER BY r.report_date DESC, r.id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $reports[] = $row;
}

// Prepare chart data
$chart_query = "SELECT r.report_date, AVG(r.acvd_eff) as avg_eff, SUM(r.day_total) as total_prod
                FROM production_reports r 
                JOIN divisions d ON r.devition_id = d.id 
                WHERE r.report_date BETWEEN ? AND ?";

$chart_params = [$from_date, $to_date];
$chart_types = "ss";

if ($division_filter !== 'all') {
    $chart_query .= " AND d.id = ?";
    $chart_params[] = (int)$division_filter;
    $chart_types .= "i";
}

$chart_query .= " GROUP BY r.report_date ORDER BY r.report_date";

$stmt = $conn->prepare($chart_query);
$stmt->bind_param($chart_types, ...$chart_params);
$stmt->execute();
$chart_result = $stmt->get_result();

while ($row = $chart_result->fetch_assoc()) {
    $chart_data[] = $row;
}

// Calculate KPIs
$total_reports = count($reports);
$avg_efficiency = 0;
$total_production = 0;
$total_divisions = 0;

$division_stats = [];
foreach ($reports as $report) {
    $total_production += $report['day_total'] ?? 0;
    $avg_efficiency += $report['acvd_eff'] ?? 0;
    $div_name = $report['division_name'];
    if (!isset($division_stats[$div_name])) {
        $division_stats[$div_name] = ['count' => 0, 'eff' => 0, 'prod' => 0];
    }
    $division_stats[$div_name]['count']++;
    $division_stats[$div_name]['eff'] += $report['acvd_eff'] ?? 0;
    $division_stats[$div_name]['prod'] += $report['day_total'] ?? 0;
}

$avg_efficiency = count($reports) > 0 ? round($avg_efficiency / count($reports), 1) : 0;
$total_divisions = count($division_stats);
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
            padding: 12px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }
        .topbar .logo-mark { display: flex; align-items: center; gap: 12px; font-weight: 700; font-size: 20px; color: var(--primary-dark); }
        .topbar .logo-mark img { height: 40px; width: auto; display: block; }
        .topnav { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .topnav a {
            color: var(--steel);
            text-decoration: none;
            font-size: 15px;
            font-weight: 600;
            padding: 8px 18px;
            border-radius: 10px;
            transition: all 0.3s;
            background: transparent;
        }
        .topnav a:hover { color: var(--primary); background: rgba(33, 115, 70, 0.08); }
        .topnav a.active { color: #fff; background: var(--primary); box-shadow: 0 4px 15px rgba(33, 115, 70, 0.3); }
        .right { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; font-size: 14px; color: var(--steel); }
        .live-chip { display: flex; align-items: center; gap: 6px; background: rgba(33, 115, 70, 0.1); padding: 4px 14px; border-radius: 20px; font-size: 13px; color: var(--primary); font-weight: 600; }
        .live-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--good); animation: blink 1.5s infinite; }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
        .logout { color: var(--steel); text-decoration: none; padding: 6px 16px; border-radius: 8px; transition: all 0.3s; background: rgba(255,255,255,0.5); font-weight: 600; }
        .logout:hover { background: rgba(220, 53, 69, 0.1); color: var(--bad); }
        .date-display { color: var(--text-dark); font-size: 14px; font-weight: 600; }
        .user-name { color: var(--text-dark); font-weight: 600; font-size: 14px; }
        .admin-badge { font-size: 10px; background: var(--primary); color: #fff; padding: 2px 10px; border-radius: 10px; font-weight: 600; }

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
        .kpi-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.1);
        }
        .kpi-card .number { font-size: 30px; font-weight: 900; color: var(--primary); }
        .kpi-card .label { font-size: 13px; font-weight: 600; color: var(--steel); margin-top: 4px; }
        
        .trend-shell {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 24px;
        }
        .trend-shell h3 { font-size: 17px; font-weight: 700; color: var(--text-dark); margin-bottom: 16px; }
        .trend-shell svg { width: 100%; height: 180px; }
        
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
        
        @media (max-width: 768px) {
            .topbar { padding: 12px 16px; flex-direction: column; align-items: stretch; gap: 10px; }
            .topnav { justify-content: center; }
            .right { justify-content: center; }
            .wrap { padding: 16px; }
            .filter-row { flex-direction: column; align-items: stretch; }
            .filter-row input, .filter-row select { min-width: unset; }
            .kpi-row { grid-template-columns: 1fr 1fr; }
            .topnav a { padding: 6px 12px; font-size: 13px; }
            .topbar .logo-mark img { height: 30px; }
        }
        @media (max-width: 480px) {
            .kpi-row { grid-template-columns: 1fr; }
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
            <a href="dashboard.php">Dashboard</a>
            <a href="reports.php" class="active">Reports</a>
            <?php if (isAdmin()): ?>
            <a href="users.php">Users</a>
            <?php endif; ?>
        </nav>
        <div class="right">
            <span class="live-chip"><span class="live-dot"></span><span id="live-clock">--:--</span></span>
            <span class="date-display"><?php echo date('M d, Y'); ?></span>
            <span class="user-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?></span>
            <?php if (isAdmin()): ?>
            <span class="admin-badge">Admin</span>
            <?php endif; ?>
            <a href="logout.php" class="logout">Sign out</a>
        </div>
    </div>

    <div class="wrap">
        <section id="screen-reports">
            <div class="dash-head">
                <div>
                    <h2>Reports</h2>
                    <p>Trend of achieved efficiency across your report dates.</p>
                </div>
            </div>

            <div class="filter-row">
                <div class="field"><label>From</label><input id="rep-from" type="date" value="<?php echo $from_date; ?>"></div>
                <div class="field"><label>To</label><input id="rep-to" type="date" value="<?php echo $to_date; ?>"></div>
                <div class="field">
                    <label>Devition</label>
                    <select id="rep-division">
                        <option value="all" <?php echo $division_filter === 'all' ? 'selected' : ''; ?>>All devitions</option>
                        <?php foreach ($divisions as $div): ?>
                        <option value="<?php echo $div['id']; ?>" <?php echo $division_filter == $div['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($div['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn-outline" onclick="applyFilters()">Apply</button>
                <button class="btn-outline" onclick="viewAllRecords()">View all records</button>
            </div>

            <div class="kpi-row" id="rep-kpis">
                <div class="kpi-card"><div class="number"><?php echo $total_reports; ?></div><div class="label">Total Reports</div></div>
                <div class="kpi-card"><div class="number"><?php echo $avg_efficiency; ?>%</div><div class="label">Avg Efficiency</div></div>
                <div class="kpi-card"><div class="number"><?php echo number_format($total_production, 0); ?></div><div class="label">Total Production</div></div>
                <div class="kpi-card"><div class="number"><?php echo $total_divisions; ?></div><div class="label">Divisions Active</div></div>
            </div>

            <div class="trend-shell">
                <h3 id="rep-chart-title">Achieved efficiency by day</h3>
                <svg id="rep-chart" width="100%" height="180" viewBox="0 0 640 190" preserveAspectRatio="none"></svg>
                <div id="rep-legend" style="display:flex;gap:16px;margin-top:10px;flex-wrap:wrap;"></div>
            </div>

            <div class="table-shell">
                <table class="report">
                    <thead><tr><th style="text-align:left;">Date</th><th style="text-align:left;">Devition</th><th>Day Total</th><th>Achieved eff</th></tr></thead>
                    <tbody id="rep-body">
                        <?php if (empty($reports)): ?>
                        <tr><td colspan="4" class="no-data">No reports found for the selected filters.</td></tr>
                        <?php else: ?>
                        <?php foreach ($reports as $report): 
                            $eff = $report['acvd_eff'] ?? 0;
                            $eff_class = $eff >= 70 ? 'eff-good' : ($eff >= 50 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <tr>
                            <td><?php echo date('Y-m-d', strtotime($report['report_date'])); ?></td>
                            <td><?php echo htmlspecialchars($report['division_name']); ?></td>
                            <td><?php echo number_format($report['day_total'] ?? 0, 0); ?></td>
                            <td class="<?php echo $eff_class; ?>"><?php echo number_format($eff, 1); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
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
            window.location.href = 'reports.php';
        }

        const chartData = <?php echo json_encode($chart_data); ?>;
        
        function renderChart() {
            const svg = document.getElementById('rep-chart');
            if (!svg || chartData.length === 0) {
                document.getElementById('rep-legend').innerHTML = '<span style="color:var(--steel);font-weight:500;">No data to display</span>';
                return;
            }

            const width = 640;
            const height = 190;
            const padding = { top: 20, bottom: 30, left: 40, right: 20 };
            const chartWidth = width - padding.left - padding.right;
            const chartHeight = height - padding.top - padding.bottom;

            const maxEff = Math.max(100, ...chartData.map(d => parseFloat(d.avg_eff) || 0));
            const minEff = 0;

            const labels = chartData.map(d => d.report_date);
            const values = chartData.map(d => parseFloat(d.avg_eff) || 0);

            const xScale = chartWidth / (labels.length - 1 || 1);
            const yScale = chartHeight / (maxEff - minEff);

            let path = '';
            let points = '';

            values.forEach((val, i) => {
                const x = padding.left + i * xScale;
                const y = padding.top + chartHeight - (val - minEff) * yScale;
                if (i === 0) { path = `M ${x} ${y}`; } else { path += ` L ${x} ${y}`; }
                points += `${x},${y} `;
            });

            svg.innerHTML = `
                <line x1="${padding.left}" y1="${padding.top}" x2="${padding.left + chartWidth}" y2="${padding.top}" stroke="rgba(255,255,255,0.2)" stroke-width="1"/>
                <line x1="${padding.left}" y1="${padding.top + chartHeight/2}" x2="${padding.left + chartWidth}" y2="${padding.top + chartHeight/2}" stroke="rgba(255,255,255,0.2)" stroke-width="1" stroke-dasharray="4"/>
                <line x1="${padding.left}" y1="${padding.top + chartHeight}" x2="${padding.left + chartWidth}" y2="${padding.top + chartHeight}" stroke="rgba(255,255,255,0.2)" stroke-width="1"/>
                <polygon points="${points} ${padding.left + (labels.length - 1) * xScale},${padding.top + chartHeight} ${padding.left},${padding.top + chartHeight}" fill="rgba(33, 115, 70, 0.1)"/>
                <polyline points="${points}" fill="none" stroke="#217346" stroke-width="2.5"/>
                ${values.map((val, i) => {
                    const x = padding.left + i * xScale;
                    const y = padding.top + chartHeight - (val - minEff) * yScale;
                    return `<circle cx="${x}" cy="${y}" r="4" fill="#217346" stroke="#fff" stroke-width="2"/>`;
                }).join('')}
                ${labels.map((label, i) => {
                    const x = padding.left + i * xScale;
                    return `<text x="${x}" y="${padding.top + chartHeight + 20}" text-anchor="${i === 0 ? 'start' : i === labels.length - 1 ? 'end' : 'middle'}" font-size="10" fill="#6b7a8f" font-weight="500">${label}</text>`;
                }).join('')}
                <text x="${padding.left - 8}" y="${padding.top}" text-anchor="end" font-size="10" fill="#6b7a8f" font-weight="600">${maxEff}%</text>
                <text x="${padding.left - 8}" y="${padding.top + chartHeight/2}" text-anchor="end" font-size="10" fill="#6b7a8f" font-weight="600">${Math.round(maxEff/2)}%</text>
                <text x="${padding.left - 8}" y="${padding.top + chartHeight}" text-anchor="end" font-size="10" fill="#6b7a8f" font-weight="600">0%</text>
            `;

            document.getElementById('rep-legend').innerHTML = `
                <span style="display:flex;align-items:center;gap:6px;font-size:13px;color:var(--steel);font-weight:500;">
                    <span style="display:inline-block;width:20px;height:2px;background:#217346;"></span> Avg Efficiency
                </span>
                <span style="display:flex;align-items:center;gap:6px;font-size:13px;color:var(--steel);font-weight:500;">
                    <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#217346;border:2px solid #fff;"></span> Daily Value
                </span>
            `;
        }

        renderChart();
    </script>
</body>
</html>