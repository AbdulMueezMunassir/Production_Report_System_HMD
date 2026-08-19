<?php
// view_report.php - View Saved Reports - FIXED
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$id = $_GET['id'] ?? 0;
$conn = getDB();

$stmt = $conn->prepare("SELECT r.*, d.name as division_name 
                        FROM production_reports r 
                        JOIN divisions d ON r.devition_id = d.id 
                        WHERE r.id = ?");
$stmt->execute([$id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    header('Location: reports.php');
    exit;
}

// Get all components for this division
$components = getComponents($conn, $report['devition_id']);
$component_data = [];
foreach ($components as $comp) {
    if ($comp['is_match_out']) continue;
    $data = getReportData($conn, $report['devition_id'], $comp['id'], $report['report_date']);
    $component_data[$comp['id']] = $data;
}

// Get current user
$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
$work_hours = $report['worked_hours'] ?? 10;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Report - Hameedia</title>
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
            --good: #28a745;
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
        .logout:hover { background: rgba(220, 53, 69, 0.1); color: #dc3545; }
        .date-display { color: var(--text-dark); font-size: 13px; font-weight: 600; }
        .user-name { color: var(--text-dark); font-weight: 600; font-size: 13px; }
        .admin-badge { font-size: 9px; background: var(--primary); color: #fff; padding: 2px 8px; border-radius: 10px; font-weight: 600; }

        .wrap { position: relative; z-index: 5; max-width: 1400px; margin: 0 auto; padding: 30px; }
        
        .report-header {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: 24px 30px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
        }
        .report-header h1 { font-size: 22px; font-weight: 800; color: var(--text-dark); }
        .report-header .meta {
            display: flex;
            gap: 30px;
            margin-top: 12px;
            flex-wrap: wrap;
        }
        .report-header .meta span { color: var(--steel); font-weight: 500; font-size: 14px; }
        .report-header .meta strong { color: var(--text-dark); font-weight: 700; }
        
        .table-container {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            overflow-x: auto;
            margin-bottom: 20px;
        }
        .excel-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            min-width: 1200px;
        }
        .excel-table th {
            background: rgba(255,255,255,0.3);
            border: 1px solid var(--glass-border);
            padding: 8px 6px;
            text-align: center;
            font-weight: 700;
            color: var(--text-dark);
            font-size: 10px;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .excel-table td {
            border: 1px solid var(--glass-border);
            padding: 6px 4px;
            text-align: center;
            white-space: nowrap;
            font-size: 12px;
            font-weight: 500;
        }
        .excel-table tr:hover { background: rgba(255,255,255,0.2); }
        .excel-table .calculated { background: rgba(255,255,255,0.1); color: var(--text-dark); }
        .excel-table .match-out-row { background: rgba(33,115,70,0.08); font-weight: 600; }
        .excel-table .match-out-row td { background: rgba(33,115,70,0.08); }
        
        .report-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        .btn {
            padding: 8px 20px;
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
        
        @media (max-width: 768px) {
            .topbar { padding: 10px 16px; flex-direction: column; align-items: stretch; gap: 8px; }
            .topnav { justify-content: center; }
            .right { justify-content: center; }
            .wrap { padding: 16px; }
            .report-header .meta { gap: 15px; }
            .excel-table { font-size: 10px; min-width: 900px; }
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
            <a href="dashboard.php">Dashboard</a>
            <a href="reports.php">Reports</a>
            <?php if (isAdmin()): ?>
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
        <div class="report-header">
            <h1>📋 Hourly Production and Efficiency Report - HAMEEDIA</h1>
            <div class="meta">
                <span><strong>Date:</strong> <?php echo date('Y-m-d', strtotime($report['report_date'])); ?></span>
                <span><strong>Devition:</strong> <?php echo htmlspecialchars($report['division_name']); ?></span>
                <span><strong>Reporting Hours:</strong> <?php echo $work_hours; ?> hrs</span>
                <span><strong>Status:</strong> <span style="color:var(--good);">Finalized</span></span>
            </div>
        </div>

        <div class="table-container">
            <table class="excel-table">
                <thead>
                    <tr>
                        <th style="min-width:60px;">DEVITION</th>
                        <th style="min-width:60px;">Unit</th>
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
                        <th style="min-width:40px;"><?php echo $h; ?>st</th>
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
                    $row_count = 0;
                    
                    foreach ($components as $comp):
                        if ($comp['is_match_out']) continue;
                        $row_count++;
                        $data = $component_data[$comp['id']] ?? [];
                        $day_total = $data['day_total'] ?? 0;
                        $ern_minutes = $day_total * ($data['ttl_sam_pc'] ?? 0);
                        $acvd_eff = ($data['acvd_eff'] ?? 0) * 100;
                        
                        $total_day_ttl += $day_total;
                        $total_ern_min += $ern_minutes;
                        $total_eff += $acvd_eff;
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($report['division_name']); ?></td>
                        <td><?php echo htmlspecialchars($comp['name']); ?></td>
                        <td><?php echo number_format($data['ttl_sam_pc'] ?? 0, 2); ?></td>
                        <td><?php echo number_format($data['unit_smv'] ?? 0, 2); ?></td>
                        <td><?php echo number_format($data['day_forecast'] ?? 0, 0); ?></td>
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
                        <td style="font-weight:700; color:var(--primary);"><?php echo number_format($acvd_eff, 1); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <!-- Total Row -->
                    <tr style="background:rgba(33, 150, 243, 0.1); font-weight:700;">
                        <td colspan="<?php echo 11 + $work_hours; ?>" style="text-align:right; padding-right:12px;">
                            Total / <?php echo htmlspecialchars($report['division_name']); ?>:
                        </td>
                        <td style="font-weight:700;"><?php echo number_format($total_day_ttl, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($total_ern_min, 1); ?></td>
                        <td style="font-weight:700; color:var(--primary);">
                            <?php 
                            $avg_eff = $row_count > 0 ? round($total_eff / $row_count, 1) : 0;
                            echo number_format($avg_eff, 1) . '%';
                            ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="report-actions">
            <button class="btn btn-primary" onclick="window.print()">🖨️ Print Report</button>
            <a href="reports.php" class="btn btn-secondary">← Back to Reports</a>
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
    </script>
</body>
</html>