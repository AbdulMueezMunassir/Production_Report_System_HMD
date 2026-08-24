<?php
// analytics.php - Analytics Dashboard with Vertical Marquee/Slider (4 Screens)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$conn = getDB();
$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';

$today = date('Y-m-d');
$work_hours = 11;

// Get divisions
$divisions = [];
try {
    $stmt = $conn->prepare("SELECT * FROM divisions WHERE id IN (1, 2, 3, 4, 5, 6, 7) ORDER BY FIELD(id, 1, 2, 3, 4, 5, 6, 7)");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $divisions = $results;
} catch (Exception $e) {
    $divisions = array();
}

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

// Get hourly data for each division
$shirt_data = getDivisionChartData($conn, 1, $today, $work_hours);
$shirt_mtm_data = getDivisionChartData($conn, 4, $today, $work_hours);
$trouser_data = getDivisionChartData($conn, 2, $today, $work_hours);
$trouser_mtm_data = getDivisionChartData($conn, 5, $today, $work_hours);
$coat_data = getDivisionChartData($conn, 3, $today, $work_hours);
$coat_mtm_data = getDivisionChartData($conn, 6, $today, $work_hours);
$assembly_data = getDivisionChartData($conn, 7, $today, $work_hours);

// If no data for today, try to get data from the most recent date
$hasData = (array_sum($shirt_data) > 0 || array_sum($trouser_data) > 0 || array_sum($assembly_data) > 0);

if (!$hasData) {
    try {
        $check = $conn->query("SELECT report_date FROM production_reports WHERE devition_id IN (1, 2, 3, 4, 5, 6, 7) ORDER BY report_date DESC LIMIT 1");
        $last_date = $check->fetch(PDO::FETCH_ASSOC);
        if ($last_date) {
            $last_date = $last_date['report_date'];
            $shirt_data = getDivisionChartData($conn, 1, $last_date, $work_hours);
            $shirt_mtm_data = getDivisionChartData($conn, 4, $last_date, $work_hours);
            $trouser_data = getDivisionChartData($conn, 2, $last_date, $work_hours);
            $trouser_mtm_data = getDivisionChartData($conn, 5, $last_date, $work_hours);
            $coat_data = getDivisionChartData($conn, 3, $last_date, $work_hours);
            $coat_mtm_data = getDivisionChartData($conn, 6, $last_date, $work_hours);
            $assembly_data = getDivisionChartData($conn, 7, $last_date, $work_hours);
        }
    } catch (Exception $e) {}
}

// If still no data, use sample data
if (array_sum($shirt_data) == 0) {
    $shirt_data = [85, 120, 95, 110, 78, 90, 105, 88, 92, 78];
    $shirt_mtm_data = [45, 60, 55, 70, 48, 52, 65, 58, 62, 48];
    $trouser_data = [70, 95, 80, 90, 65, 75, 85, 72, 78, 65];
    $trouser_mtm_data = [35, 50, 40, 55, 38, 42, 55, 48, 52, 38];
    $coat_data = [50, 70, 55, 65, 45, 55, 60, 52, 58, 45];
    $coat_mtm_data = [25, 40, 30, 45, 28, 32, 45, 38, 42, 28];
    $assembly_data = [120, 180, 150, 200, 140, 160, 190, 170, 175, 140];
}
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
            overflow: hidden;
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
            height: calc(100vh - 80px);
            overflow: hidden;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
            flex-shrink: 0;
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

        /* Vertical Slider Container */
        .slider-container {
            position: relative;
            width: 100%;
            height: calc(100vh - 200px);
            overflow: hidden;
            border-radius: var(--border-radius);
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            box-shadow: var(--shadow);
        }
        
        .slides-wrapper {
            display: flex;
            flex-direction: column;
            width: 100%;
            height: 400%;
            transition: transform 0.8s cubic-bezier(0.4, 0, 0.2, 1);
            transform: translateY(0);
        }
        
        .slide {
            width: 100%;
            height: 25%;
            padding: 30px;
            overflow-y: auto;
            flex-shrink: 0;
        }
        
        .slide h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary);
        }
        
        .slide .chart-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            height: calc(100% - 60px);
        }
        
        .slide .chart-card {
            background: rgba(255,255,255,0.4);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
        }
        
        .slide .chart-card h4 {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 10px;
            text-align: center;
        }
        
        .slide .chart-card .chart-container {
            flex: 1;
            position: relative;
            min-height: 180px;
        }
        
        .slide .chart-card canvas {
            width: 100% !important;
            height: 100% !important;
        }

        /* Assembly slide - full width chart */
        .slide-assembly .chart-grid {
            grid-template-columns: 1fr;
        }
        .slide-assembly .chart-card {
            grid-column: 1 / -1;
        }
        .slide-assembly .chart-container {
            min-height: 250px;
        }

        /* Navigation Controls - Vertical */
        .slider-nav {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            flex-direction: column;
            gap: 12px;
            z-index: 20;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            padding: 12px 10px;
            border-radius: 30px;
            border: 1px solid var(--glass-border);
        }
        
        .slider-nav .dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: rgba(255,255,255,0.4);
            cursor: pointer;
            transition: all 0.3s;
            border: none;
        }
        
        .slider-nav .dot.active {
            background: var(--primary);
            transform: scale(1.3);
        }
        
        .slider-nav .dot:hover {
            background: var(--primary-dark);
            transform: scale(1.1);
        }
        
        .slider-arrows {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            width: 100%;
            display: flex;
            justify-content: space-between;
            padding: 0 10px;
            z-index: 15;
            pointer-events: none;
            top: 50%;
            transform: translate(-50%, -50%);
        }
        
        .slider-arrows button {
            pointer-events: auto;
            background: rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            font-size: 18px;
            cursor: pointer;
            transition: all 0.3s;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .slider-arrows button:hover {
            background: rgba(255,255,255,0.6);
            transform: scale(1.05);
        }
        
        .slider-arrows button:active {
            transform: scale(0.95);
        }
        
        .slide-indicator {
            position: absolute;
            top: 20px;
            right: 30px;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-dark);
            border: 1px solid var(--glass-border);
            z-index: 20;
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
            flex-shrink: 0;
        }
        .back-button:hover {
            background: rgba(255,255,255,0.3);
            transform: translateX(-4px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .slide::-webkit-scrollbar {
            width: 4px;
        }
        .slide::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
            border-radius: 4px;
        }
        .slide::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 4px;
        }

        @media (max-width: 1024px) {
            .slide .chart-grid {
                grid-template-columns: 1fr;
                height: auto;
            }
            .slide {
                padding: 20px;
                overflow-y: auto;
            }
            .slider-container {
                height: calc(100vh - 250px);
            }
            .slide-assembly .chart-container {
                min-height: 200px;
            }
            .slider-arrows button {
                width: 32px;
                height: 32px;
                font-size: 14px;
            }
        }
        @media (max-width: 768px) {
            .topbar { padding: 10px 16px; flex-direction: column; align-items: stretch; gap: 8px; }
            .topnav { justify-content: center; }
            .right { justify-content: center; }
            .analytics-container { padding: 12px 16px; height: calc(100vh - 120px); }
            .page-header { flex-direction: column; align-items: flex-start; }
            .page-header .controls { width: 100%; flex-wrap: wrap; }
            .slide { padding: 12px; }
            .slide .chart-card { padding: 12px; }
            .slider-arrows { display: none; }
            .slider-nav { right: 10px; padding: 8px 6px; gap: 8px; }
            .slider-nav .dot { width: 10px; height: 10px; }
            .slide-indicator { font-size: 11px; padding: 4px 10px; top: 12px; right: 16px; }
            .topnav a { padding: 6px 12px; font-size: 13px; }
        }
        @media (max-width: 480px) {
            .slide-assembly .chart-container { min-height: 180px; }
            .slide .chart-card .chart-container { min-height: 140px; }
            .slider-nav { right: 6px; padding: 6px 4px; gap: 6px; }
            .slider-nav .dot { width: 8px; height: 8px; }
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
                <p>Visual insights into production performance by division</p>
            </div>
            <div class="controls">
                <a href="dashboard.php" class="btn btn-secondary">← Back</a>
            </div>
        </div>

        <div class="slider-container" id="sliderContainer">
            <div class="slide-indicator" id="slideIndicator">1 / 4</div>
            
            <div class="slider-arrows">
                <button id="prevSlide" onclick="changeSlide(-1)">▲</button>
                <button id="nextSlide" onclick="changeSlide(1)">▼</button>
            </div>

            <div class="slides-wrapper" id="slidesWrapper">
                <!-- ====== SLIDE 1: Shirt & Shirt MTM ====== -->
                <div class="slide">
                    <h3>👔 Shirt &amp; Shirt MTM - Hourly Production</h3>
                    <div class="chart-grid">
                        <div class="chart-card">
                            <h4>Shirt Devition</h4>
                            <div class="chart-container">
                                <canvas id="chartShirt"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <h4>Shirt MTM</h4>
                            <div class="chart-container">
                                <canvas id="chartShirtMTM"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ====== SLIDE 2: Trouser & Trouser MTM ====== -->
                <div class="slide">
                    <h3>👖 Trouser &amp; Trouser MTM - Hourly Production</h3>
                    <div class="chart-grid">
                        <div class="chart-card">
                            <h4>Trouser Devition</h4>
                            <div class="chart-container">
                                <canvas id="chartTrouser"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <h4>Trouser MTM</h4>
                            <div class="chart-container">
                                <canvas id="chartTrouserMTM"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ====== SLIDE 3: Coat & Coat MTM ====== -->
                <div class="slide">
                    <h3>🧥 Coat &amp; Coat MTM - Hourly Production</h3>
                    <div class="chart-grid">
                        <div class="chart-card">
                            <h4>Coat Devition</h4>
                            <div class="chart-container">
                                <canvas id="chartCoat"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <h4>Coat MTM</h4>
                            <div class="chart-container">
                                <canvas id="chartCoatMTM"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ====== SLIDE 4: Assembly ====== -->
                <div class="slide slide-assembly">
                    <h3>🏭 Assembly - Hourly Production</h3>
                    <div class="chart-grid">
                        <div class="chart-card">
                            <h4>Assembly Unit</h4>
                            <div class="chart-container">
                                <canvas id="chartAssembly"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="slider-nav" id="sliderNav">
                <button class="dot active" data-index="0" onclick="goToSlide(0)"></button>
                <button class="dot" data-index="1" onclick="goToSlide(1)"></button>
                <button class="dot" data-index="2" onclick="goToSlide(2)"></button>
                <button class="dot" data-index="3" onclick="goToSlide(3)"></button>
            </div>
        </div>
    </div>

    <script>
        // ============================================================
        // SLIDER FUNCTIONS - Vertical (Up/Down)
        // ============================================================
        let currentSlide = 0;
        const totalSlides = 4;

        function updateSlide() {
            const wrapper = document.getElementById('slidesWrapper');
            // Move up: translateY(-25% * currentSlide)
            wrapper.style.transform = `translateY(-${currentSlide * 25}%)`;
            
            document.getElementById('slideIndicator').textContent = `${currentSlide + 1} / ${totalSlides}`;
            
            document.querySelectorAll('.dot').forEach((dot, index) => {
                dot.classList.toggle('active', index === currentSlide);
            });
        }

        function changeSlide(direction) {
            currentSlide = (currentSlide + direction + totalSlides) % totalSlides;
            updateSlide();
            resetAutoSlide();
        }

        function goToSlide(index) {
            currentSlide = index;
            updateSlide();
            resetAutoSlide();
        }

        // Keyboard navigation (Up/Down arrows)
        document.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowDown') changeSlide(1);
            if (e.key === 'ArrowUp') changeSlide(-1);
        });

        // Touch support for mobile (vertical swipe)
        let touchStartY = 0;
        let touchEndY = 0;

        document.getElementById('sliderContainer').addEventListener('touchstart', function(e) {
            touchStartY = e.changedTouches[0].screenY;
        }, {passive: true});

        document.getElementById('sliderContainer').addEventListener('touchend', function(e) {
            touchEndY = e.changedTouches[0].screenY;
            const diff = touchStartY - touchEndY;
            if (Math.abs(diff) > 50) {
                if (diff > 0) changeSlide(1); // Swipe up = next slide
                else changeSlide(-1); // Swipe down = previous slide
            }
        }, {passive: true});

        // Mouse wheel support
        let wheelTimeout = false;
        document.getElementById('sliderContainer').addEventListener('wheel', function(e) {
            e.preventDefault();
            if (wheelTimeout) return;
            wheelTimeout = true;
            setTimeout(() => { wheelTimeout = false; }, 800);
            
            if (e.deltaY > 0) {
                changeSlide(1);
            } else {
                changeSlide(-1);
            }
        }, {passive: false});

        // ============================================================
        // AUTO-SLIDE (Up/Down)
        // ============================================================
        let autoSlideInterval;

        function startAutoSlide() {
            autoSlideInterval = setInterval(() => changeSlide(1), 8000);
        }

        function resetAutoSlide() {
            clearInterval(autoSlideInterval);
            startAutoSlide();
        }

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
        // CHART DATA
        // ============================================================
        const chartLabels = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'];
        
        const shirtData = <?php echo json_encode($shirt_data); ?>;
        const shirtMTMData = <?php echo json_encode($shirt_mtm_data); ?>;
        const trouserData = <?php echo json_encode($trouser_data); ?>;
        const trouserMTMData = <?php echo json_encode($trouser_mtm_data); ?>;
        const coatData = <?php echo json_encode($coat_data); ?>;
        const coatMTMData = <?php echo json_encode($coat_mtm_data); ?>;
        const assemblyData = <?php echo json_encode($assembly_data); ?>;

        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.font.size = 11;
        Chart.defaults.color = '#6b7a8f';

        function createBarChart(id, data, label, color) {
            const ctx = document.getElementById(id);
            if (!ctx) return null;
            
            return new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: chartLabels,
                    datasets: [{
                        label: label,
                        data: data,
                        backgroundColor: color,
                        borderColor: color,
                        borderWidth: 2,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) { return value; }
                            }
                        }
                    }
                }
            });
        }

        // Chart Colors
        const colors = {
            shirt: 'rgba(33, 115, 70, 0.8)',
            shirtMTM: 'rgba(46, 148, 104, 0.8)',
            trouser: 'rgba(227, 167, 48, 0.8)',
            trouserMTM: 'rgba(245, 124, 0, 0.8)',
            coat: 'rgba(118, 75, 162, 0.8)',
            coatMTM: 'rgba(156, 39, 176, 0.8)',
            assembly: 'rgba(79, 172, 254, 0.8)'
        };

        // Create all bar charts
        createBarChart('chartShirt', shirtData, 'Pcs', colors.shirt);
        createBarChart('chartShirtMTM', shirtMTMData, 'Pcs', colors.shirtMTM);
        createBarChart('chartTrouser', trouserData, 'Pcs', colors.trouser);
        createBarChart('chartTrouserMTM', trouserMTMData, 'Pcs', colors.trouserMTM);
        createBarChart('chartCoat', coatData, 'Pcs', colors.coat);
        createBarChart('chartCoatMTM', coatMTMData, 'Pcs', colors.coatMTM);
        
        // Assembly chart - line chart
        new Chart(document.getElementById('chartAssembly'), {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: 'Pcs',
                    data: assemblyData,
                    borderColor: colors.assembly,
                    backgroundColor: 'rgba(79, 172, 254, 0.15)',
                    tension: 0.3,
                    fill: true,
                    pointBackgroundColor: colors.assembly,
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

        // ============================================================
        // HANDLE WINDOW RESIZE
        // ============================================================
        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                Chart.instances.forEach(chart => chart.resize());
            }, 250);
        });

        // ============================================================
        // START AUTO-SLIDE
        // ============================================================
        startAutoSlide();

        // Reset timer on manual navigation
        document.querySelectorAll('.dot, #prevSlide, #nextSlide').forEach(el => {
            el.addEventListener('click', function() {
                resetAutoSlide();
            });
        });

        // Initial slide position
        updateSlide();
    </script>
</body>
</html>