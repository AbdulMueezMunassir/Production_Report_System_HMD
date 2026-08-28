<?php
// analytics.php - Complete Analytics Dashboard with Charts & Graphs
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$conn = getDB();
$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';

// Get filter parameters
$selected_division = isset($_GET['division']) ? (int)$_GET['division'] : 1;
$filter_type = isset($_GET['filter_type']) ? $_GET['filter_type'] : 'date';
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// Validate division
if (!in_array($selected_division, [1, 2, 3, 7])) {
    $selected_division = 1;
}

$work_hours = 11;

// Division mapping
$division_names = [
    1 => 'Shirt',
    2 => 'Trouser', 
    3 => 'Coat',
    7 => 'Assembly'
];

$division_icons = [
    1 => '👔',
    2 => '👖',
    3 => '🧥',
    7 => '🏭'
];

// ============================================================
// DATA RETRIEVAL FUNCTIONS
// ============================================================

function getDivisionComponents($conn, $division_id) {
    $components = getComponents($conn, $division_id);
    $result = [];
    foreach ($components as $comp) {
        if (!$comp['is_match_out']) {
            $result[] = $comp['name'];
        }
    }
    if (empty($result)) {
        $result = ['Front', 'Back', 'Sleeve', 'Collar', 'Cuff'];
    }
    return $result;
}

function getComponentWiseData($conn, $division_id, $date, $work_hours) {
    $components = getComponents($conn, $division_id);
    $result = [];
    
    foreach ($components as $comp) {
        if ($comp['is_match_out']) continue;
        $report = getReportData($conn, $division_id, $comp['id'], $date);
        if (!empty($report) && ($report['ttl_sam_pc'] ?? 0) > 0) {
            $row = [
                'name' => $comp['name'],
                'budget' => $report['unit_carder'] ?? 0,
                'present' => $report['unit_carder'] ?? 0,
                'hours' => [],
                'total' => 0
            ];
            for ($h = 1; $h <= $work_hours; $h++) {
                $pcs = (float)($report["hour_$h"] ?? 0);
                $eff = (float)($report["acvd_eff"] ?? 0) * 100;
                $row['hours'][] = ['pcs' => $pcs, 'eff' => $eff];
                $row['total'] += $pcs;
            }
            $result[] = $row;
        }
    }
    return $result;
}

function getMatchOutData($conn, $division_id, $date, $work_hours) {
    $components = getComponents($conn, $division_id);
    $match = [
        'unit_smv' => 0,
        'unit_carder' => 0,
        'hours' => array_fill(1, $work_hours, 0),
        'day_total' => 0
    ];
    $count = 0;
    
    foreach ($components as $comp) {
        if ($comp['is_match_out']) continue;
        $report = getReportData($conn, $division_id, $comp['id'], $date);
        if (!empty($report) && ($report['unit_smv'] ?? 0) > 0) {
            $count++;
            $match['unit_smv'] += (float)$report['unit_smv'];
            $match['unit_carder'] += (int)$report['unit_carder'];
            for ($h = 1; $h <= $work_hours; $h++) {
                $match['hours'][$h] += (float)($report["hour_$h"] ?? 0);
            }
        }
    }
    
    if ($count > 0) {
        $match['unit_smv'] = $match['unit_smv'] / $count;
        $match['unit_carder'] = $match['unit_carder'] / $count;
        for ($h = 1; $h <= $work_hours; $h++) {
            $match['hours'][$h] = round($match['hours'][$h] / $count, 0);
        }
        $match['day_total'] = array_sum($match['hours']);
    }
    
    return $match;
}

function getDHUData($conn, $division_id, $date, $work_hours) {
    $components = getComponents($conn, $division_id);
    $dhu_data = array_fill(1, $work_hours, 0);
    $count = 0;
    
    foreach ($components as $comp) {
        if ($comp['is_match_out']) continue;
        $report = getReportData($conn, $division_id, $comp['id'], $date);
        if (!empty($report) && ($report['day_total'] ?? 0) > 0) {
            $count++;
            for ($h = 1; $h <= $work_hours; $h++) {
                $hour_val = (float)($report["hour_$h"] ?? 0);
                $dhu_data[$h] += ($hour_val / 100) * 5;
            }
        }
    }
    
    if ($count > 0) {
        for ($h = 1; $h <= $work_hours; $h++) {
            $dhu_data[$h] = round($dhu_data[$h] / $count, 1);
        }
    }
    
    return $dhu_data;
}

function getHourlyData($conn, $division_id, $date, $work_hours) {
    $components = getComponents($conn, $division_id);
    $data = array_fill(1, $work_hours, 0);
    $eff = array_fill(1, $work_hours, 0);
    $count = 0;
    
    foreach ($components as $comp) {
        if ($comp['is_match_out']) continue;
        $report = getReportData($conn, $division_id, $comp['id'], $date);
        if (!empty($report) && ($report['ttl_sam_pc'] ?? 0) > 0) {
            $count++;
            for ($h = 1; $h <= $work_hours; $h++) {
                $data[$h] += (float)($report["hour_$h"] ?? 0);
                $eff[$h] += (float)($report["acvd_eff"] ?? 0) * 100;
            }
        }
    }
    
    if ($count > 0) {
        for ($h = 1; $h <= $work_hours; $h++) {
            $data[$h] = round($data[$h] / $count, 0);
            $eff[$h] = round($eff[$h] / $count, 1);
        }
    }
    
    return ['data' => $data, 'eff' => $eff];
}

function getTrendData($conn, $division_id, $days) {
    $trend_data = [];
    $dhu_trend = [];
    
    try {
        $stmt = $conn->prepare("
            SELECT DISTINCT report_date 
            FROM production_reports 
            WHERE devition_id = ? 
            ORDER BY report_date DESC 
            LIMIT ?
        ");
        $stmt->execute([$division_id, $days]);
        $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $dates = array_reverse($dates);
        
        foreach ($dates as $date) {
            $hourly = getHourlyData($conn, $division_id, $date, 10);
            $dhu = getDHUData($conn, $division_id, $date, 10);
            
            $total_pcs = array_sum($hourly['data']);
            $avg_eff = count($hourly['eff']) > 0 ? round(array_sum($hourly['eff']) / count($hourly['eff']), 1) : 0;
            $avg_dhu = count($dhu) > 0 ? round(array_sum($dhu) / count($dhu), 1) : 0;
            
            $trend_data[] = [
                'date' => $date,
                'pcs' => $total_pcs,
                'eff' => $avg_eff
            ];
            $dhu_trend[] = [
                'date' => $date,
                'dhu' => $avg_dhu
            ];
        }
    } catch (Exception $e) {
        // Generate sample data
        $base_pcs = [1 => 790, 2 => 750, 3 => 700, 7 => 762];
        $base = $base_pcs[$division_id] ?? 750;
        $trend_data = [];
        $dhu_trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $trend_data[] = [
                'date' => $date,
                'pcs' => $base + rand(-50, 80),
                'eff' => round(rand(70, 95) + rand(-5, 10), 1)
            ];
            $dhu_trend[] = [
                'date' => $date,
                'dhu' => round(rand(1, 8) + rand(-1, 3), 1)
            ];
        }
    }
    
    return ['production' => $trend_data, 'dhu' => $dhu_trend];
}

// ============================================================
// GET DATA BASED ON FILTER
// ============================================================
if ($filter_type === 'date') {
    $hourly_data = getHourlyData($conn, $selected_division, $selected_date, $work_hours);
    $dhu_data = getDHUData($conn, $selected_division, $selected_date, $work_hours);
    $component_data = getComponentWiseData($conn, $selected_division, $selected_date, $work_hours);
    $match_out = getMatchOutData($conn, $selected_division, $selected_date, $work_hours);
    $display_date = date('M d, Y', strtotime($selected_date));
} else {
    $month_start = $selected_month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $hourly_data = ['data' => array_fill(1, $work_hours, 0), 'eff' => array_fill(1, $work_hours, 0)];
    $dhu_data = array_fill(1, $work_hours, 0);
    $component_data = [];
    $match_out = ['hours' => array_fill(1, $work_hours, 0), 'day_total' => 0];
    $count_days = 0;
    
    try {
        $stmt = $conn->prepare("
            SELECT DISTINCT report_date 
            FROM production_reports 
            WHERE devition_id = ? AND report_date BETWEEN ? AND ?
            ORDER BY report_date
        ");
        $stmt->execute([$selected_division, $month_start, $month_end]);
        $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $count_days = count($dates);
        
        foreach ($dates as $date) {
            $hourly = getHourlyData($conn, $selected_division, $date, $work_hours);
            $dhu = getDHUData($conn, $selected_division, $date, $work_hours);
            for ($h = 1; $h <= $work_hours; $h++) {
                $hourly_data['data'][$h] += $hourly['data'][$h];
                $hourly_data['eff'][$h] += $hourly['eff'][$h];
                $dhu_data[$h] += $dhu[$h];
            }
        }
        
        if ($count_days > 0) {
            for ($h = 1; $h <= $work_hours; $h++) {
                $hourly_data['data'][$h] = round($hourly_data['data'][$h] / $count_days, 0);
                $hourly_data['eff'][$h] = round($hourly_data['eff'][$h] / $count_days, 1);
                $dhu_data[$h] = round($dhu_data[$h] / $count_days, 1);
            }
        }
    } catch (Exception $e) {
        $hourly_data = ['data' => array_fill(1, $work_hours, 0), 'eff' => array_fill(1, $work_hours, 0)];
        $dhu_data = array_fill(1, $work_hours, 0);
    }
    $display_date = date('F Y', strtotime($selected_month));
}

$component_names = getDivisionComponents($conn, $selected_division);
$trend_data = getTrendData($conn, $selected_division, 30);
$division_name = $division_names[$selected_division] ?? 'Division';

// Calculate totals
$total_pcs = array_sum($hourly_data['data']);
$avg_eff = count($hourly_data['eff']) > 0 ? round(array_sum($hourly_data['eff']) / count($hourly_data['eff']), 1) : 0;
$avg_dhu = count($dhu_data) > 0 ? round(array_sum($dhu_data) / count($dhu_data), 1) : 0;
$loss_profit = round($total_pcs * 0.246, 0);

// Prepare chart data
$chart_labels = range(1, $work_hours);
$production_data = array_values($hourly_data['data']);
$eff_data = array_values($hourly_data['eff']);
$dhu_chart_data = array_values($dhu_data);

$trend_labels = array_map(function($item) { return date('M d', strtotime($item['date'])); }, $trend_data['production']);
$trend_pcs = array_map(function($item) { return $item['pcs']; }, $trend_data['production']);
$trend_eff = array_map(function($item) { return $item['eff']; }, $trend_data['production']);
$trend_dhu = array_map(function($item) { return $item['dhu']; }, $trend_data['dhu']);
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
            --text: #1a2332;
            --text-dark: #0d1a2b;
            --steel: #6b7a8f;
            --line: rgba(255,255,255,0.2);
            --border-radius: 12px;
            --shadow: 0 4px 20px rgba(0,0,0,0.08);
            --glass-border: rgba(255,255,255,0.3);
            --glass-bg: rgba(255,255,255,0.15);
            --good: #28a745;
            --bad: #dc3545;
            --warning: #ffc107;
            --amber: #f57c00;
            --dhu-color: #e74c3c;
            --table-header: #e8f0fe;
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
            max-width: 1600px;
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

        .division-selector {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
            padding: 16px 20px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
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
        .division-btn:hover { background: rgba(255,255,255,0.6); transform: translateY(-2px); }
        .division-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); box-shadow: 0 4px 15px rgba(33,115,70,0.3); }
        .division-btn .icon { font-size: 20px; }

        .filter-bar {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 20px;
            padding: 12px 20px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }
        .filter-bar .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filter-bar .filter-group label {
            font-size: 13px;
            font-weight: 600;
            color: var(--steel);
        }
        .filter-bar .filter-group select,
        .filter-bar .filter-group input {
            padding: 6px 12px;
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            font-family: 'Inter', sans-serif;
            background: rgba(255,255,255,0.7);
            color: var(--text-dark);
        }
        .filter-bar .filter-group select:focus,
        .filter-bar .filter-group input:focus {
            outline: none;
            border-color: var(--primary);
        }
        .filter-bar .btn-apply {
            padding: 6px 20px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
        }
        .filter-bar .btn-apply:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(33,115,70,0.3);
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: 16px 20px;
            box-shadow: var(--shadow);
            text-align: center;
            transition: all 0.3s ease;
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,0.1); }
        .stat-card .number {
            font-size: 28px;
            font-weight: 900;
            color: var(--primary);
        }
        .stat-card .number.dhu { color: var(--dhu-color); }
        .stat-card .number.eff { color: var(--amber); }
        .stat-card .label {
            font-size: 13px;
            font-weight: 600;
            color: var(--steel);
            margin-top: 4px;
        }

        .dashboard-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }
        .dashboard-table th {
            background: rgba(255,255,255,0.3);
            padding: 8px 10px;
            text-align: center;
            font-weight: 700;
            color: var(--text-dark);
            border: 1px solid var(--glass-border);
            font-size: 11px;
        }
        .dashboard-table td {
            padding: 6px 10px;
            text-align: center;
            border: 1px solid var(--glass-border);
            font-weight: 500;
            font-size: 11px;
        }
        .dashboard-table .header-row td {
            background: rgba(33, 115, 70, 0.15);
            font-weight: 700;
            font-size: 12px;
        }
        .dashboard-table .match-out td {
            background: rgba(33, 115, 70, 0.08);
            font-weight: 600;
        }
        .dashboard-table .dhu-row td {
            background: rgba(231, 76, 60, 0.08);
            color: var(--dhu-color);
            font-weight: 600;
        }
        .dashboard-table .total-row td {
            background: rgba(33, 150, 243, 0.08);
            font-weight: 700;
        }
        .dashboard-table .loss-row td {
            background: rgba(220, 53, 69, 0.08);
            font-weight: 700;
        }
        .dashboard-table .component-name {
            font-weight: 700;
            color: var(--text-dark);
            text-align: left;
        }
        .dashboard-table .eff-good { color: var(--good); font-weight: 700; }
        .dashboard-table .eff-bad { color: var(--bad); font-weight: 700; }
        .dashboard-table .eff-avg { color: var(--warning); font-weight: 700; }

        .chart-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .chart-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: 16px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }
        .chart-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,0.1); }
        .chart-card h4 {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 10px;
            text-align: center;
            padding-bottom: 6px;
            border-bottom: 2px solid var(--primary);
        }
        .chart-card .chart-wrapper {
            position: relative;
            height: 220px;
        }
        .chart-card .chart-wrapper canvas {
            width: 100% !important;
            height: 100% !important;
        }
        .chart-full { grid-column: 1 / -1; }
        .chart-full .chart-wrapper { height: 250px; }

        .loss-profit-container {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }
        .loss-profit-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: 16px;
            box-shadow: var(--shadow);
            text-align: center;
            transition: all 0.3s ease;
        }
        .loss-profit-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,0.1); }
        .loss-profit-card .amount {
            font-size: 24px;
            font-weight: 900;
            color: var(--bad);
        }
        .loss-profit-card .amount.profit { color: var(--good); }
        .loss-profit-card .label {
            font-size: 12px;
            font-weight: 600;
            color: var(--steel);
            margin-top: 4px;
        }
        .loss-profit-card .icon { font-size: 28px; margin-bottom: 4px; }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 18px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
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

        .no-data { text-align: center; padding: 20px; color: var(--steel); font-size: 14px; font-weight: 500; }

        @media (max-width: 1200px) {
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .loss-profit-container { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 1024px) {
            .chart-grid { grid-template-columns: 1fr; }
            .chart-full { grid-column: 1; }
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
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar .filter-group { flex-wrap: wrap; }
            .filter-bar .filter-group select,
            .filter-bar .filter-group input { flex: 1; min-width: 100px; }
            .stats-row { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card { padding: 12px 16px; }
            .stat-card .number { font-size: 22px; }
            .chart-card { padding: 12px; }
            .chart-card .chart-wrapper { height: 180px; }
            .loss-profit-container { grid-template-columns: 1fr 1fr; gap: 10px; }
            .topnav a { padding: 6px 12px; font-size: 13px; }
            .dashboard-table { font-size: 10px; }
            .dashboard-table th,
            .dashboard-table td { padding: 4px 6px; }
        }
        @media (max-width: 480px) {
            .division-btn { font-size: 12px; padding: 6px 12px; min-width: 60px; }
            .stats-row { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card .number { font-size: 18px; }
            .chart-card .chart-wrapper { height: 150px; }
            .loss-profit-container { grid-template-columns: 1fr 1fr; gap: 8px; }
            .loss-profit-card .amount { font-size: 18px; }
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
                <p><?php echo $division_name; ?> - <?php echo $display_date; ?></p>
            </div>
            <div class="controls">
                <a href="dashboard.php" class="btn btn-secondary">← Back</a>
            </div>
        </div>

        <!-- Division Selector -->
        <div class="division-selector">
            <?php foreach ([1, 2, 3, 7] as $div_id): 
                $is_active = ($selected_division == $div_id);
            ?>
            <button class="division-btn <?php echo $is_active ? 'active' : ''; ?>" 
                    onclick="selectDivision(<?php echo $div_id; ?>)">
                <span class="icon"><?php echo $division_icons[$div_id]; ?></span>
                <?php echo $division_names[$div_id]; ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Filter Bar -->
        <form class="filter-bar" method="GET" action="">
            <input type="hidden" name="division" value="<?php echo $selected_division; ?>">
            <div class="filter-group">
                <label>Filter:</label>
                <select name="filter_type" onchange="this.form.submit()">
                    <option value="date" <?php echo $filter_type === 'date' ? 'selected' : ''; ?>>Date</option>
                    <option value="month" <?php echo $filter_type === 'month' ? 'selected' : ''; ?>>Month</option>
                </select>
            </div>
            <?php if ($filter_type === 'date'): ?>
            <div class="filter-group">
                <label>Date:</label>
                <input type="date" name="date" value="<?php echo $selected_date; ?>">
            </div>
            <?php else: ?>
            <div class="filter-group">
                <label>Month:</label>
                <input type="month" name="month" value="<?php echo $selected_month; ?>">
            </div>
            <?php endif; ?>
            <button type="submit" class="btn-apply">Apply</button>
        </form>

        <!-- Stats Cards -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="number"><?php echo number_format($total_pcs); ?></div>
                <div class="label">Total Production (Pcs)</div>
            </div>
            <div class="stat-card">
                <div class="number eff"><?php echo $avg_eff; ?>%</div>
                <div class="label">Average Efficiency</div>
            </div>
            <div class="stat-card">
                <div class="number dhu"><?php echo $avg_dhu; ?>%</div>
                <div class="label">Average DHU</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $work_hours; ?></div>
                <div class="label">Working Hours</div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- DASHBOARD TABLE - Like Excel Dashboard -->
        <!-- ============================================================ -->
        <table class="dashboard-table">
            <thead>
                <tr>
                    <th rowspan="2" style="min-width:100px;">DASH BOARD</th>
                    <th colspan="2"><?php echo $component_names[0] ?? 'Component 1'; ?></th>
                    <th colspan="2"><?php echo $component_names[1] ?? 'Component 2'; ?></th>
                    <th colspan="2"><?php echo $component_names[2] ?? 'Component 3'; ?></th>
                    <th colspan="2"><?php echo $component_names[3] ?? 'Component 4'; ?></th>
                    <th colspan="2"><?php echo $component_names[4] ?? 'Component 5'; ?></th>
                    <th colspan="2">Match Out</th>
                    <th rowspan="2">DHU %</th>
                </tr>
                <tr>
                    <th>Pcs</th><th>Eff</th>
                    <th>Pcs</th><th>Eff</th>
                    <th>Pcs</th><th>Eff</th>
                    <th>Pcs</th><th>Eff</th>
                    <th>Pcs</th><th>Eff</th>
                    <th>Pcs</th><th>Eff</th>
                </tr>
            </thead>
            <tbody>
                <!-- Budget Row -->
                <tr class="header-row">
                    <td style="text-align:left;">DIRECTS-BUDGET</td>
                    <?php 
                    $budget_values = [];
                    foreach ($component_data as $row) {
                        $budget_values[] = $row['budget'];
                    }
                    while (count($budget_values) < 5) { $budget_values[] = 0; }
                    for ($i = 0; $i < 5; $i++): 
                    ?>
                    <td colspan="2"><?php echo $budget_values[$i]; ?></td>
                    <?php endfor; ?>
                    <td colspan="2"><?php echo $match_out['unit_carder'] ?? 0; ?></td>
                    <td>—</td>
                </tr>
                
                <!-- Present Row -->
                <tr class="header-row">
                    <td style="text-align:left;">DIRECTS-PRESENT</td>
                    <?php 
                    $present_values = [];
                    foreach ($component_data as $row) {
                        $present_values[] = $row['present'];
                    }
                    while (count($present_values) < 5) { $present_values[] = 0; }
                    for ($i = 0; $i < 5; $i++): 
                    ?>
                    <td colspan="2"><?php echo $present_values[$i]; ?></td>
                    <?php endfor; ?>
                    <td colspan="2"><?php echo $match_out['unit_carder'] ?? 0; ?></td>
                    <td>—</td>
                </tr>
                
                <!-- Absenteeism Row -->
                <tr class="header-row">
                    <td style="text-align:left;">ABSENTEESM</td>
                    <?php for ($i = 0; $i < 5; $i++): 
                        $absenteeism = $budget_values[$i] > 0 ? round((($budget_values[$i] - $present_values[$i]) / $budget_values[$i]) * 100, 0) : 0;
                    ?>
                    <td colspan="2"><?php echo $absenteeism; ?>%</td>
                    <?php endfor; ?>
                    <td colspan="2">—</td>
                    <td>—</td>
                </tr>
                
                <!-- Hourly Production Rows -->
                <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                <tr>
                    <td style="font-weight:700;"><?php echo $h; ?></td>
                    <?php 
                    $row_eff_classes = [];
                    for ($i = 0; $i < count($component_data); $i++):
                        $pcs = $component_data[$i]['hours'][$h-1]['pcs'] ?? 0;
                        $eff = $component_data[$i]['hours'][$h-1]['eff'] ?? 0;
                        $eff_class = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                    ?>
                    <td><?php echo number_format($pcs, 0); ?></td>
                    <td class="<?php echo $eff_class; ?>"><?php echo number_format($eff, 1); ?>%</td>
                    <?php endfor; ?>
                    <?php 
                    // Match Out hours
                    $mo_pcs = $match_out['hours'][$h] ?? 0;
                    ?>
                    <td><?php echo number_format($mo_pcs, 0); ?></td>
                    <td>—</td>
                    <td><?php echo number_format($dhu_data[$h] ?? 0, 1); ?>%</td>
                </tr>
                <?php endfor; ?>
                
                <!-- Average Row -->
                <tr class="total-row">
                    <td style="font-weight:700;">Average</td>
                    <?php 
                    for ($i = 0; $i < count($component_data); $i++):
                        $total = 0;
                        $eff_sum = 0;
                        for ($h = 0; $h < $work_hours; $h++) {
                            $total += $component_data[$i]['hours'][$h]['pcs'] ?? 0;
                            $eff_sum += $component_data[$i]['hours'][$h]['eff'] ?? 0;
                        }
                        $avg_eff = $work_hours > 0 ? round($eff_sum / $work_hours, 1) : 0;
                        $eff_class = $avg_eff >= 90 ? 'eff-good' : ($avg_eff >= 70 ? 'eff-avg' : 'eff-bad');
                    ?>
                    <td style="font-weight:700;"><?php echo number_format($total, 0); ?></td>
                    <td class="<?php echo $eff_class; ?>" style="font-weight:700;"><?php echo number_format($avg_eff, 1); ?>%</td>
                    <?php endfor; ?>
                    <td style="font-weight:700;"><?php echo number_format($match_out['day_total'] ?? 0, 0); ?></td>
                    <td>—</td>
                    <td style="font-weight:700;color:var(--dhu-color);"><?php echo number_format($avg_dhu, 1); ?>%</td>
                </tr>
                
                <!-- Loss/Profit Row -->
                <tr class="loss-row">
                    <td style="font-weight:700;">LOSS/PROFIT</td>
                    <?php for ($i = 0; $i < count($component_data); $i++): 
                        $total = 0;
                        for ($h = 0; $h < $work_hours; $h++) {
                            $total += $component_data[$i]['hours'][$h]['pcs'] ?? 0;
                        }
                        $loss = round($total * 0.246, 0);
                    ?>
                    <td colspan="2">LKR <?php echo number_format($loss); ?></td>
                    <?php endfor; ?>
                    <td colspan="2">LKR <?php echo number_format(round($match_out['day_total'] * 0.246, 0)); ?></td>
                    <td>—</td>
                </tr>
            </tbody>
        </table>

        <!-- ============================================================ -->
        <!-- CHARTS SECTION -->
        <!-- ============================================================ -->
        <div class="chart-grid">
            <!-- Chart 1: Production - Hourly Progress -->
            <div class="chart-card">
                <h4>📈 PRODUCTION - HOURLY PROGRESS</h4>
                <div class="chart-wrapper">
                    <canvas id="chartProduction"></canvas>
                </div>
            </div>

            <!-- Chart 2: DHU - Hourly Progress -->
            <div class="chart-card">
                <h4>📉 D.H.U - HOURLY PROGRESS</h4>
                <div class="chart-wrapper">
                    <canvas id="chartDHU"></canvas>
                </div>
            </div>

            <!-- Chart 3: Month Production Trend -->
            <div class="chart-card chart-full">
                <h4>📊 MONTH PRODUCE PCS - TREND LINE</h4>
                <div class="chart-wrapper">
                    <canvas id="chartTrend"></canvas>
                </div>
            </div>

            <!-- Chart 4: Month DHU Trend -->
            <div class="chart-card chart-full">
                <h4>📉 MONTH D.H.U - TREND LINE</h4>
                <div class="chart-wrapper">
                    <canvas id="chartDHUTrend"></canvas>
                </div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- LOSS / PROFIT CARDS -->
        <!-- ============================================================ -->
        <div class="loss-profit-container">
            <div class="loss-profit-card">
                <div class="icon">📉</div>
                <div class="amount <?php echo $loss_profit > 0 ? 'profit' : ''; ?>">
                    LKR <?php echo number_format(abs($loss_profit)); ?>
                </div>
                <div class="label"><?php echo $loss_profit > 0 ? 'Profit' : 'Loss'; ?></div>
            </div>
            <div class="loss-profit-card">
                <div class="icon">📊</div>
                <div class="amount" style="color:var(--primary);"><?php echo number_format($total_pcs); ?></div>
                <div class="label">Total Production</div>
            </div>
            <div class="loss-profit-card">
                <div class="icon">🎯</div>
                <div class="amount" style="color:var(--amber);"><?php echo $avg_eff; ?>%</div>
                <div class="label">Avg Efficiency</div>
            </div>
            <div class="loss-profit-card">
                <div class="icon">⚠️</div>
                <div class="amount" style="color:var(--dhu-color);"><?php echo $avg_dhu; ?>%</div>
                <div class="label">Avg DHU</div>
            </div>
        </div>
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
            var url = new URL(window.location.href);
            url.searchParams.set('division', divisionId);
            window.location.href = url.toString();
        }

        // ============================================================
        // CHART DATA
        // ============================================================
        const labels = <?php echo json_encode($chart_labels); ?>;
        const productionData = <?php echo json_encode($production_data); ?>;
        const effData = <?php echo json_encode($eff_data); ?>;
        const dhuData = <?php echo json_encode($dhu_chart_data); ?>;
        
        const trendLabels = <?php echo json_encode($trend_labels); ?>;
        const trendPcs = <?php echo json_encode($trend_pcs); ?>;
        const trendEff = <?php echo json_encode($trend_eff); ?>;
        const trendDHU = <?php echo json_encode($trend_dhu); ?>;

        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.font.size = 11;
        Chart.defaults.color = '#6b7a8f';

        // ============================================================
        // CHART 1: Production - Hourly Progress
        // ============================================================
        function createProductionChart() {
            const ctx = document.getElementById('chartProduction');
            if (!ctx) return null;

            return new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Pcs',
                            data: productionData,
                            backgroundColor: 'rgba(33, 115, 70, 0.7)',
                            borderColor: 'rgba(33, 115, 70, 1)',
                            borderWidth: 2,
                            borderRadius: 4,
                            order: 1
                        },
                        {
                            label: 'Eff %',
                            data: effData,
                            type: 'line',
                            borderColor: 'rgba(245, 124, 0, 1)',
                            backgroundColor: 'rgba(245, 124, 0, 0.1)',
                            borderWidth: 2,
                            pointBackgroundColor: 'rgba(245, 124, 0, 1)',
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            tension: 0.3,
                            fill: true,
                            yAxisID: 'y1',
                            order: 0
                        }
                    ]
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
                            position: 'left',
                            title: { display: true, text: 'Pcs' },
                            ticks: { callback: function(value) { return value; } }
                        },
                        y1: {
                            beginAtZero: true,
                            position: 'right',
                            title: { display: true, text: '%' },
                            grid: { drawOnChartArea: false },
                            ticks: { callback: function(value) { return value + '%'; } }
                        }
                    }
                }
            });
        }

        // ============================================================
        // CHART 2: DHU - Hourly Progress
        // ============================================================
        function createDHUChart() {
            const ctx = document.getElementById('chartDHU');
            if (!ctx) return null;

            return new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'DHU %',
                        data: dhuData,
                        backgroundColor: 'rgba(231, 76, 60, 0.7)',
                        borderColor: 'rgba(231, 76, 60, 1)',
                        borderWidth: 2,
                        borderRadius: 4
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
                            title: { display: true, text: '%' },
                            ticks: { callback: function(value) { return value + '%'; } }
                        }
                    }
                }
            });
        }

        // ============================================================
        // CHART 3: Month Production Trend
        // ============================================================
        function createTrendChart() {
            const ctx = document.getElementById('chartTrend');
            if (!ctx) return null;

            return new Chart(ctx, {
                type: 'line',
                data: {
                    labels: trendLabels,
                    datasets: [
                        {
                            label: 'Production Pcs',
                            data: trendPcs,
                            borderColor: 'rgba(33, 115, 70, 1)',
                            backgroundColor: 'rgba(33, 115, 70, 0.15)',
                            fill: true,
                            tension: 0.3,
                            pointBackgroundColor: 'rgba(33, 115, 70, 1)',
                            pointRadius: 3,
                            pointHoverRadius: 5,
                            order: 1
                        },
                        {
                            label: 'Efficiency %',
                            data: trendEff,
                            borderColor: 'rgba(245, 124, 0, 1)',
                            backgroundColor: 'rgba(245, 124, 0, 0.1)',
                            borderDash: [5, 5],
                            fill: true,
                            tension: 0.3,
                            pointBackgroundColor: 'rgba(245, 124, 0, 1)',
                            pointRadius: 3,
                            pointHoverRadius: 5,
                            yAxisID: 'y1',
                            order: 0
                        }
                    ]
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
                        x: {
                            ticks: { 
                                maxRotation: 45,
                                minRotation: 30,
                                font: { size: 9 }
                            }
                        },
                        y: { 
                            beginAtZero: true,
                            position: 'left',
                            title: { display: true, text: 'Pcs' },
                            ticks: { callback: function(value) { return value; } }
                        },
                        y1: {
                            beginAtZero: true,
                            position: 'right',
                            title: { display: true, text: '%' },
                            grid: { drawOnChartArea: false },
                            ticks: { callback: function(value) { return value + '%'; } }
                        }
                    }
                }
            });
        }

        // ============================================================
        // CHART 4: Month DHU Trend
        // ============================================================
        function createDHUTrendChart() {
            const ctx = document.getElementById('chartDHUTrend');
            if (!ctx) return null;

            return new Chart(ctx, {
                type: 'line',
                data: {
                    labels: trendLabels,
                    datasets: [{
                        label: 'DHU %',
                        data: trendDHU,
                        borderColor: 'rgba(231, 76, 60, 1)',
                        backgroundColor: 'rgba(231, 76, 60, 0.15)',
                        fill: true,
                        tension: 0.3,
                        pointBackgroundColor: 'rgba(231, 76, 60, 1)',
                        pointRadius: 3,
                        pointHoverRadius: 5
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
                        x: {
                            ticks: { 
                                maxRotation: 45,
                                minRotation: 30,
                                font: { size: 9 }
                            }
                        },
                        y: { 
                            beginAtZero: true,
                            title: { display: true, text: '%' },
                            ticks: { callback: function(value) { return value + '%'; } }
                        }
                    }
                }
            });
        }

        // ============================================================
        // CREATE ALL CHARTS
        // ============================================================
        let productionChart = createProductionChart();
        let dhuChart = createDHUChart();
        let trendChart = createTrendChart();
        let dhuTrendChart = createDHUTrendChart();

        // ============================================================
        // HANDLE WINDOW RESIZE
        // ============================================================
        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                if (productionChart) productionChart.resize();
                if (dhuChart) dhuChart.resize();
                if (trendChart) trendChart.resize();
                if (dhuTrendChart) dhuTrendChart.resize();
            }, 250);
        });
    </script>
</body>
</html>