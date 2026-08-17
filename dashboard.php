<?php
// dashboard.php - Complete Dashboard with Glass Morphism
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

$conn = getDBConnection();
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Get only 4 main divisions
$divisions = getDivisions($conn);
$division_stats = [];

foreach ($divisions as $div) {
    $division_stats[$div['id']] = getDivisionStats($conn, $div['id'], $date);
}

// Get factory efficiency
$factory_eff = getFactoryEfficiency($conn, $date);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hameedia - Production Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #217346;
            --primary-dark: #1a5c3a;
            --primary-light: #e8f5e9;
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
            gap: 10px; 
            font-weight: 700; 
            font-size: 18px; 
            color: var(--primary-dark);
        }
        .topbar .logo-mark img { 
            height: 30px; 
            width: auto;
            display: block;
            image-rendering: auto;
            -ms-interpolation-mode: bicubic;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
        }
        .topbar .logo-mark span { 
            font-size: 18px;
            font-weight: 800;
            color: var(--text-dark);
        }
        .topnav { 
            display: flex; 
            align-items: center; 
            gap: 6px; 
            flex-wrap: wrap; 
        }
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
        .topnav a:hover { 
            color: var(--primary); 
            background: rgba(33, 115, 70, 0.08);
        }
        .topnav a.active { 
            color: #fff;
            background: var(--primary);
            box-shadow: 0 4px 15px rgba(33, 115, 70, 0.3);
        }
        .right { 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            flex-wrap: wrap; 
            font-size: 13px; 
            color: var(--steel); 
        }
        .live-chip { 
            display: flex; 
            align-items: center; 
            gap: 6px; 
            background: rgba(33, 115, 70, 0.1); 
            padding: 4px 12px; 
            border-radius: 20px; 
            font-size: 12px; 
            color: var(--primary); 
            font-weight: 600; 
        }
        .live-dot { 
            width: 6px; 
            height: 6px; 
            border-radius: 50%; 
            background: var(--good); 
            animation: blink 1.5s infinite; 
        }
        @keyframes blink { 
            0%, 100% { opacity: 1; } 
            50% { opacity: 0.3; } 
        }
        .logout { 
            color: var(--steel); 
            text-decoration: none; 
            padding: 5px 14px; 
            border-radius: 8px; 
            transition: all 0.3s; 
            background: rgba(255,255,255,0.5);
            font-weight: 600;
            font-size: 13px;
        }
        .logout:hover { 
            background: rgba(220, 53, 69, 0.1); 
            color: var(--bad);
        }
        .date-display { 
            color: var(--text-dark); 
            font-size: 13px; 
            font-weight: 600;
        }
        .user-name {
            color: var(--text-dark);
            font-weight: 600;
            font-size: 13px;
        }
        .admin-badge {
            font-size: 9px;
            background: var(--primary);
            color: #fff;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 600;
        }
        
        .container { 
            position: relative;
            z-index: 5;
            max-width: 1200px; 
            margin: 0 auto; 
            padding: 30px; 
        }
        
        .factory-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: 24px 28px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: var(--shadow);
            transition: all 0.4s ease;
        }
        .factory-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.12);
            background: rgba(255,255,255,0.2);
        }
        .factory-card .left { display: flex; align-items: center; gap: 16px; }
        .factory-card .left .icon { 
            font-size: 32px; 
            background: rgba(255,255,255,0.2);
            padding: 10px;
            border-radius: 14px;
        }
        .factory-card .left .info h3 { 
            color: var(--steel); 
            font-size: 13px; 
            font-weight: 500; 
        }
        .factory-card .left .info .value { 
            color: var(--text-dark); 
            font-size: 34px; 
            font-weight: 900; 
        }
        .factory-card .right { display: flex; gap: 24px; flex-wrap: wrap; }
        .factory-card .right .stat { text-align: center; }
        .factory-card .right .stat .label { 
            color: var(--steel); 
            font-size: 11px; 
            font-weight: 500; 
        }
        .factory-card .right .stat .number { 
            color: var(--text-dark); 
            font-size: 20px; 
            font-weight: 800; 
        }
        
        .section-title { 
            font-size: 20px; 
            font-weight: 800; 
            color: var(--text-dark); 
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-title .sub { 
            font-weight: 500; 
            color: var(--steel); 
            font-size: 14px; 
        }
        
        .division-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 30px;
        }
        .division-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: 20px 16px;
            box-shadow: var(--shadow);
            transition: all 0.4s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .division-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(33,115,70,0.05), transparent);
            opacity: 0;
            transition: opacity 0.4s ease;
        }
        .division-card:hover { 
            transform: translateY(-6px) scale(1.02); 
            box-shadow: 0 16px 48px rgba(0,0,0,0.12);
            border-color: rgba(33,115,70,0.3);
            background: rgba(255,255,255,0.25);
        }
        .division-card:hover::before { opacity: 1; }
        .division-card .icon { 
            font-size: 38px; 
            margin-bottom: 8px;
            display: block;
            transition: transform 0.4s ease;
        }
        .division-card:hover .icon { transform: scale(1.1) rotate(-5deg); }
        .division-card .name { 
            font-size: 18px; 
            font-weight: 800; 
            color: var(--text-dark); 
            margin-bottom: 4px;
        }
        .division-card .units-info { 
            color: var(--steel); 
            font-size: 13px; 
            font-weight: 500;
            margin-bottom: 8px; 
        }
        .division-card .units-info strong { 
            color: var(--text-dark); 
            font-weight: 700;
        }
        .division-card .efficiency { 
            display: flex; 
            align-items: baseline; 
            justify-content: center;
            gap: 6px; 
        }
        .division-card .efficiency .value { 
            font-size: 30px; 
            font-weight: 900; 
            color: var(--primary); 
        }
        .division-card .efficiency .label { 
            color: var(--steel); 
            font-size: 12px; 
            font-weight: 500;
        }
        .division-card .efficiency .no-data { 
            color: #b0b8c4; 
            font-size: 20px; 
            font-weight: 700;
        }
        .division-card .progress-bar { 
            width: 100%; 
            height: 5px; 
            background: rgba(0,0,0,0.06); 
            border-radius: 4px; 
            margin-top: 12px; 
            overflow: hidden; 
        }
        .division-card .progress-bar .fill { 
            height: 100%; 
            border-radius: 4px; 
            background: linear-gradient(90deg, var(--primary), #2d8f4e); 
            transition: width 0.8s ease; 
        }
        .division-card .status-badge {
            font-size: 10px;
            padding: 2px 14px;
            border-radius: 20px;
            font-weight: 600;
            display: inline-block;
            margin-top: 8px;
            transition: all 0.3s ease;
        }
        .status-active { background: rgba(33,115,70,0.15); color: var(--primary); }
        .status-inactive { background: rgba(0,0,0,0.05); color: #999; }
        
        @media (max-width: 1024px) { .division-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 768px) {
            .topbar { padding: 10px 16px; flex-direction: column; align-items: stretch; gap: 8px; }
            .topnav { justify-content: center; }
            .right { justify-content: center; }
            .container { padding: 16px; }
            .factory-card { flex-direction: column; text-align: center; padding: 18px; }
            .factory-card .right { justify-content: center; }
            .division-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
            .division-card { padding: 16px 12px; }
            .division-card .icon { font-size: 30px; }
            .division-card .efficiency .value { font-size: 24px; }
            .topnav a { padding: 6px 12px; font-size: 13px; }
            .topbar .logo-mark img { height: 26px; }
        }
        @media (max-width: 480px) {
            .division-grid { grid-template-columns: 1fr; }
            .factory-card .left { flex-direction: column; text-align: center; }
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
            <a href="dashboard.php" class="active">Dashboard</a>
            <a href="reports.php">Reports</a>
            <?php if (isAdmin()): ?>
            <a href="users.php">Users</a>
            <?php endif; ?>
        </nav>
        <div class="right">
            <span class="live-chip">
                <span class="live-dot"></span>
                <span id="live-clock">--:--</span>
            </span>
            <span class="date-display"><?php echo date('M d, Y'); ?></span>
            <span class="user-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?></span>
            <?php if (isAdmin()): ?>
            <span class="admin-badge">Admin</span>
            <?php endif; ?>
            <a href="logout.php" class="logout">Sign out</a>
        </div>
    </div>

    <div class="container">
        <div class="factory-card">
            <div class="left">
                <div class="icon">🏭</div>
                <div class="info">
                    <h3>Factory-wide achieved efficiency today</h3>
                    <div class="value"><?php echo $factory_eff; ?>%</div>
                </div>
            </div>
            <div class="right">
                <?php foreach ($divisions as $div): 
                    $stats = $division_stats[$div['id']] ?? ['efficiency' => 0];
                ?>
                <div class="stat">
                    <div class="label"><?php echo htmlspecialchars($div['name']); ?></div>
                    <div class="number"><?php echo $stats['efficiency'] > 0 ? $stats['efficiency'] . '%' : '-'; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="section-title">
            Select a deviation
            <span class="sub">Live efficiency for the selected report date</span>
        </div>
        <div class="division-grid">
            <?php 
            $icons = [
                'Shirt' => '👔',
                'Trouser' => '👖',
                'Coat' => '🧥',
                'Assembly' => '🏭'
            ];
            foreach ($divisions as $div): 
                $stats = $division_stats[$div['id']] ?? ['total_units' => 0, 'setup_units' => 0, 'efficiency' => 0, 'has_data' => false];
                $eff_percent = min($stats['efficiency'], 100);
                $icon = $icons[$div['name']] ?? '📋';
                $status_class = $stats['has_data'] ? 'status-active' : 'status-inactive';
                $status_text = $stats['has_data'] ? 'Active' : 'Inactive';
            ?>
            <a href="division_view.php?id=<?php echo $div['id']; ?>&date=<?php echo $date; ?>" class="division-card">
                <span class="icon"><?php echo $icon; ?></span>
                <div class="name"><?php echo htmlspecialchars($div['name']); ?></div>
                <div class="units-info">
                    <strong><?php echo $stats['setup_units']; ?></strong> of <?php echo $stats['total_units']; ?> units set up
                </div>
                <div class="efficiency">
                    <?php if ($stats['efficiency'] > 0): ?>
                        <span class="value"><?php echo $stats['efficiency']; ?>%</span>
                        <span class="label">achieved eff.</span>
                    <?php else: ?>
                        <span class="no-data">—</span>
                        <span class="label">achieved eff.</span>
                    <?php endif; ?>
                </div>
                <div class="progress-bar">
                    <div class="fill" style="width: <?php echo $eff_percent; ?>%;"></div>
                </div>
                <div class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></div>
            </a>
            <?php endforeach; ?>
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