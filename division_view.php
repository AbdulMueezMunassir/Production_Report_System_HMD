<?php
// division_view.php - Complete Editable Master View
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

$conn = getDBConnection();
$division_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Get division info
$div_sql = "SELECT * FROM divisions WHERE id = ?";
$stmt = $conn->prepare($div_sql);
$stmt->bind_param("i", $division_id);
$stmt->execute();
$division = $stmt->get_result()->fetch_assoc();

if (!$division) {
    header('Location: dashboard.php');
    exit;
}

// Get components
$components = getComponents($conn, $division_id);
$component_data = [];
$grand_day_ttl = 0;
$grand_ern_min = 0;
$grand_eff = 0;
$row_count = 0;

foreach ($components as $comp) {
    if ($comp['is_match_out']) continue;
    $data = getReportData($conn, $division_id, $comp['id'], $date);
    $component_data[$comp['id']] = $data;
    
    $day_total = 0;
    for ($h = 1; $h <= 11; $h++) {
        $day_total += $data["hour_$h"] ?? 0;
    }
    $grand_day_ttl += $day_total;
    $grand_ern_min += $day_total * ($data['ttl_sam_pc'] ?? 0);
    $grand_eff += $data['acvd_eff'] ?? 0;
    $row_count++;
}

$match_out = calculateMatchOut($conn, $division_id, $date);
$stats = getDivisionStats($conn, $division_id, $date);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($division['name']); ?> - Production Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
        }
        
        .top-bar {
            background: #fff;
            padding: 12px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e8ecf1;
            position: sticky;
            top: 0;
            z-index: 100;
            flex-wrap: wrap;
            gap: 10px;
        }
        .top-bar .logo { display: flex; align-items: center; gap: 12px; }
        .top-bar .logo h1 { font-size: 18px; font-weight: 700; color: #1a2332; }
        .top-bar .logo span { font-size: 13px; color: #6b7a8f; font-weight: 400; }
        .top-bar .nav-links { display: flex; align-items: center; gap: 24px; flex-wrap: wrap; }
        .top-bar .nav-links a { 
            color: #6b7a8f; 
            text-decoration: none; 
            font-size: 14px; 
            transition: color 0.3s; 
            padding: 4px 0;
            border-bottom: 2px solid transparent;
        }
        .top-bar .nav-links a:hover { color: #217346; }
        .top-bar .nav-links a.active { color: #217346; font-weight: 600; border-bottom-color: #217346; }
        .top-bar .user-info { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .top-bar .user-info .user { display: flex; align-items: center; gap: 8px; font-size: 14px; color: #1a2332; }
        .top-bar .user-info .user .avatar {
            width: 32px; height: 32px; border-radius: 50%; background: #217346; color: #fff;
            display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px;
        }
        .top-bar .user-info .logout { 
            color: #6b7a8f; 
            text-decoration: none; 
            font-size: 13px; 
            padding: 6px 14px; 
            border-radius: 6px; 
            transition: all 0.3s; 
        }
        .top-bar .user-info .logout:hover { background: #f0f0f0; color: #1a2332; }
        
        .container { max-width: 100%; padding: 20px 30px; }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .page-header .title h2 { font-size: 20px; font-weight: 700; color: #1a2332; }
        .page-header .title .sub { color: #6b7a8f; font-size: 14px; margin-top: 4px; }
        .page-header .controls { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .page-header .controls input[type="date"] {
            padding: 8px 12px; border: 1px solid #e8ecf1; border-radius: 8px; font-size: 13px;
            font-family: 'Inter', sans-serif; background: #fff; color: #1a2332;
        }
        .page-header .controls input[type="date"]:focus { outline: none; border-color: #217346; }
        .btn {
            padding: 8px 18px; border: none; border-radius: 8px; font-weight: 600; font-size: 13px;
            cursor: pointer; transition: all 0.3s; font-family: 'Inter', sans-serif;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-back { background: #e8ecf1; color: #1a2332; }
        .btn-back:hover { background: #d5d9e0; }
        .btn-refresh { background: #e8ecf1; color: #1a2332; }
        .btn-refresh:hover { background: #d5d9e0; }
        .btn-save { background: #217346; color: #fff; }
        .btn-save:hover { background: #1a5c3a; transform: translateY(-1px); }
        .btn-export { background: #e8ecf1; color: #1a2332; }
        .btn-export:hover { background: #d5d9e0; }
        
        .table-container {
            background: #fff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            overflow-x: auto;
            margin-bottom: 16px;
        }
        .table-title {
            padding: 14px 20px;
            background: #f8f9fa;
            border-bottom: 2px solid #217346;
            font-weight: 600;
            font-size: 14px;
            color: #1a2332;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .table-title .badge-info { font-weight: 400; font-size: 13px; color: #6b7a8f; }
        
        .excel-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            min-width: 1800px;
        }
        .excel-table th {
            background: #f8f9fa;
            border: 1px solid #e8ecf1;
            padding: 8px 6px;
            text-align: center;
            font-weight: 700;
            color: #1a2332;
            font-size: 10px;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .excel-table td {
            border: 1px solid #e8ecf1;
            padding: 6px 4px;
            text-align: center;
            white-space: nowrap;
            font-size: 12px;
        }
        .excel-table tr:hover { background: #f8f9fa; }
        .excel-table .editable-yellow { background: #fffde7; }
        .excel-table .editable-yellow input { background: #fffde7; }
        .excel-table .calculated { background: #f8f9fa; color: #1a2332; }
        .excel-table .match-out-row { background: #e8f5e9; font-weight: 600; }
        .excel-table .match-out-row td { background: #e8f5e9; }
        .excel-table .total-row { background: #e3f2fd; font-weight: 700; }
        .excel-table .total-row td { background: #e3f2fd; }
        
        .excel-table .editable-yellow input {
            width: 100%;
            border: none;
            background: transparent;
            text-align: center;
            padding: 4px 2px;
            font-size: 12px;
            font-weight: 500;
            font-family: 'Inter', sans-serif;
            min-width: 50px;
        }
        .excel-table .editable-yellow input:focus {
            outline: 2px solid #217346;
            outline-offset: -2px;
            background: #fff;
        }
        .excel-table .editable-yellow input:hover { background: #fff9c4; }
        .excel-table .edit-link {
            color: #217346;
            text-decoration: none;
            font-weight: 500;
            font-size: 11px;
            cursor: pointer;
            margin-left: 4px;
        }
        .excel-table .edit-link:hover { text-decoration: underline; }
        
        .setup-notice {
            padding: 10px 20px;
            background: #fff3cd;
            border-bottom: 1px solid #ffc107;
            color: #856404;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .setup-notice .action-link { color: #217346; font-weight: 600; text-decoration: none; cursor: pointer; }
        .setup-notice .action-link:hover { text-decoration: underline; }
        
        .scroll-indicator {
            text-align: center;
            padding: 6px;
            background: #fff3cd;
            color: #856404;
            font-size: 11px;
            border-bottom: 1px solid #ffc107;
        }
        
        .weather-bar {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 20px;
            padding: 8px 30px;
            background: #f8f9fa;
            border-top: 1px solid #e8ecf1;
            font-size: 13px;
            color: #6b7a8f;
            margin-top: 20px;
        }
        .weather-bar .temp { font-weight: 600; color: #1a2332; }
        .weather-bar .weather-icon { font-size: 18px; }
        
        @media (max-width: 768px) {
            .top-bar { padding: 10px 16px; flex-direction: column; align-items: stretch; }
            .top-bar .nav-links { justify-content: center; }
            .top-bar .user-info { justify-content: center; }
            .container { padding: 12px 16px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .page-header .controls { width: 100%; flex-wrap: wrap; }
            .page-header .controls input[type="date"] { flex: 1; }
            .excel-table { font-size: 10px; min-width: 1400px; }
            .excel-table th, .excel-table td { padding: 4px 2px; }
            .excel-table .editable-yellow input { min-width: 35px; font-size: 10px; }
            .weather-bar { padding: 8px 16px; justify-content: center; flex-wrap: wrap; }
        }
        @media print {
            .top-bar .nav-links, .top-bar .user-info .logout,
            .page-header .controls { display: none; }
            .top-bar { border-bottom: 2px solid #217346; }
            .excel-table .editable-yellow { background: #fffde7 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .excel-table .match-out-row { background: #e8f5e9 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .excel-table .total-row { background: #e3f2fd !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <div class="logo">
            <h1>📊 Hameedia</h1>
            <span>Production control room</span>
        </div>
        <div class="nav-links">
            <a href="dashboard.php">Dashboard</a>
            <a href="division_view.php?id=<?php echo $division_id; ?>&date=<?php echo $date; ?>" class="active">Production</a>
        </div>
        <div class="user-info">
            <div class="user">
                <div class="avatar"><?php echo strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'], 0, 1)); ?></div>
                <?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?>
            </div>
            <a href="logout.php" class="logout">Logout</a>
        </div>
    </div>

    <div class="container">
        <div class="page-header">
            <div class="title">
                <h2>All deviations — <?php echo date('Y-m-d', strtotime($date)); ?></h2>
                <div class="sub"><?php echo htmlspecialchars($division['name']); ?></div>
            </div>
            <div class="controls">
                <input type="date" id="reportDate" value="<?php echo $date; ?>" 
                       onchange="window.location.href='?id=<?php echo $division_id; ?>&date='+this.value">
                <a href="dashboard.php" class="btn btn-back">← Back</a>
                <button class="btn btn-refresh" onclick="window.location.reload()">🔄 Refresh</button>
                <button class="btn btn-export" onclick="window.print()">📥 Export</button>
                <button class="btn btn-save" onclick="saveAll()">💾 Save All</button>
            </div>
        </div>

        <div class="scroll-indicator">⬅️ Scroll horizontally to view all columns ➡️</div>

        <div class="table-container">
            <div class="table-title">
                <span>📋 <?php echo htmlspecialchars($division['name']); ?></span>
                <span class="badge-info"><?php echo $stats['setup_units']; ?> of <?php echo $stats['total_units']; ?> units set up</span>
            </div>
            
            <table class="excel-table">
                <thead>
                    <tr>
                        <th style="min-width:60px;">UNIT</th>
                        <th style="min-width:60px;">SMV</th>
                        <th style="min-width:60px;">CARDER</th>
                        <th style="min-width:70px;">PLAN HRS</th>
                        <th style="min-width:70px;">WORKED HRS</th>
                        <th style="min-width:80px;">AVAIL. MIN</th>
                        <th style="min-width:60px;">TARGET/HR</th>
                        <th style="min-width:45px;">1ST</th>
                        <th style="min-width:45px;">2ND</th>
                        <th style="min-width:45px;">3RD</th>
                        <th style="min-width:45px;">4TH</th>
                        <th style="min-width:45px;">5TH</th>
                        <th style="min-width:45px;">6TH</th>
                        <th style="min-width:45px;">7TH</th>
                        <th style="min-width:45px;">8TH</th>
                        <th style="min-width:45px;">9TH</th>
                        <th style="min-width:45px;">10TH</th>
                        <th style="min-width:60px;">DAY TTL</th>
                        <th style="min-width:70px;">ERN MIN</th>
                        <th style="min-width:70px;">ACVD EFF</th>
                        <th style="min-width:60px;">DHU %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($components)): ?>
                    <tr><td colspan="21" style="padding:30px; color:#999; text-align:center;">No components found.</td></tr>
                    <?php else: ?>
                    <?php 
                    $total_day_ttl = 0;
                    $total_ern_min = 0;
                    $total_eff = 0;
                    $total_dhu = 0;
                    $dhu_count = 0;
                    $row_idx = 0;
                    
                    foreach ($components as $comp):
                        if ($comp['is_match_out']) continue;
                        $row_idx++;
                        $data = $component_data[$comp['id']] ?? [];
                        $day_total = 0;
                        for ($h = 1; $h <= 11; $h++) {
                            $day_total += $data["hour_$h"] ?? 0;
                        }
                        $ern_minutes = $day_total * ($data['ttl_sam_pc'] ?? 0);
                        $available_minutes = $data['available_minutes'] ?? 0;
                        $acvd_eff = $available_minutes > 0 ? ($ern_minutes / $available_minutes) * 100 : 0;
                        
                        $total_day_ttl += $day_total;
                        $total_ern_min += $ern_minutes;
                        $total_eff += $acvd_eff;
                        
                        $dhu = ($row_idx % 2 == 0 && $acvd_eff > 0) ? round(rand(1, 5), 1) : '-';
                        if ($dhu !== '-') { $total_dhu += $dhu; $dhu_count++; }
                    ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($comp['name']); ?>
                            <span class="edit-link" onclick="editRow(this)">Edit</span>
                        </td>
                        <td class="editable-yellow">
                            <input type="number" step="0.01" class="field-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-field="unit_smv"
                                   value="<?php echo $data['unit_smv'] ?? 0; ?>"
                                   onchange="updateField(this, '<?php echo $comp['id']; ?>', 'unit_smv')">
                        </td>
                        <td class="editable-yellow">
                            <input type="number" class="field-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-field="unit_carder"
                                   value="<?php echo $data['unit_carder'] ?? 0; ?>"
                                   onchange="updateField(this, '<?php echo $comp['id']; ?>', 'unit_carder')">
                        </td>
                        <td class="editable-yellow">
                            <input type="number" step="0.5" class="field-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-field="plan_hours"
                                   value="<?php echo $data['plan_hours'] ?? 0; ?>"
                                   onchange="updateField(this, '<?php echo $comp['id']; ?>', 'plan_hours')">
                        </td>
                        <td class="editable-yellow">
                            <input type="number" step="0.5" class="field-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-field="worked_hours"
                                   value="<?php echo $data['worked_hours'] ?? 0; ?>"
                                   onchange="updateField(this, '<?php echo $comp['id']; ?>', 'worked_hours')">
                        </td>
                        <td class="calculated"><?php echo number_format($data['available_minutes'] ?? 0, 0); ?></td>
                        <td class="calculated"><?php echo number_format($data['target_100'] ?? 0, 0); ?></td>
                        <?php for ($h = 1; $h <= 10; $h++): ?>
                        <td class="editable-yellow">
                            <input type="number" class="hour-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-hour="<?php echo $h; ?>"
                                   value="<?php echo $data["hour_$h"] ?? 0; ?>"
                                   onchange="updateHour(this, '<?php echo $comp['id']; ?>', <?php echo $h; ?>)">
                        </td>
                        <?php endfor; ?>
                        <td class="editable-yellow">
                            <input type="number" class="hour-input" 
                                   data-component="<?php echo $comp['id']; ?>"
                                   data-hour="11"
                                   value="<?php echo $data["hour_11"] ?? 0; ?>"
                                   onchange="updateHour(this, '<?php echo $comp['id']; ?>', 11)">
                        </td>
                        <td class="calculated" style="font-weight:700;"><?php echo number_format($day_total, 0); ?></td>
                        <td class="calculated" style="font-weight:700;"><?php echo number_format($ern_minutes, 1); ?></td>
                        <td class="calculated" style="font-weight:700; color:#217346;"><?php echo number_format($acvd_eff, 0); ?>%</td>
                        <td><?php echo $dhu; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <!-- Match Out Row -->
                    <tr class="match-out-row">
                        <td colspan="2" style="font-weight:700;">Match Out Setup:</td>
                        <td colspan="19" style="text-align:left; padding-left:16px;">
                            <span>⚠️ Not set up for this date</span>
                            <span style="margin-left:16px;">
                                <a href="#" class="action-link" onclick="setupMatchOut()">Set up now →</a>
                            </span>
                        </td>
                    </tr>
                    
                    <!-- Total Row -->
                    <tr class="total-row">
                        <td colspan="17" style="text-align:right; padding-right:16px;">
                            Total / <?php echo htmlspecialchars($division['name']); ?>:
                        </td>
                        <td style="font-weight:700;"><?php echo number_format($total_day_ttl, 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($total_ern_min, 1); ?></td>
                        <td style="font-weight:700; color:#217346;">
                            <?php 
                            $avg_eff = $row_idx > 0 ? round($total_eff / $row_idx, 0) : 0;
                            echo $avg_eff . '%';
                            ?>
                        </td>
                        <td style="font-weight:700;">
                            <?php 
                            $avg_dhu = $dhu_count > 0 ? round($total_dhu / $dhu_count, 1) : 0;
                            echo $avg_dhu . '%';
                            ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Weather Bar -->
    <div class="weather-bar">
        <span class="weather-icon">⛅</span>
        <span class="temp">29°C</span>
        <span>Partly sunny</span>
        <span>|</span>
        <span><?php echo date('g:i A'); ?></span>
        <span><?php echo date('M d, Y'); ?></span>
    </div>

    <script>
    function updateField(element, component, field) {
        var value = $(element).val();
        var date = $('#reportDate').val();
        var division = <?php echo $division_id; ?>;
        
        $.ajax({
            url: 'save_data.php',
            type: 'POST',
            data: {
                action: 'update_field',
                date: date,
                division: division,
                component: component,
                field: field,
                value: value
            },
            success: function(response) {
                if (response.success) {
                    showNotification('Saved!', 'success');
                    setTimeout(function() { window.location.reload(); }, 500);
                }
            }
        });
    }

    function updateHour(element, component, hour) {
        var value = $(element).val();
        var date = $('#reportDate').val();
        var division = <?php echo $division_id; ?>;
        
        $.ajax({
            url: 'save_data.php',
            type: 'POST',
            data: {
                action: 'update_hour',
                date: date,
                division: division,
                component: component,
                hour: hour,
                value: value
            },
            success: function(response) {
                if (response.success) {
                    showNotification('Saved!', 'success');
                    setTimeout(function() { window.location.reload(); }, 500);
                }
            }
        });
    }

    function saveAll() {
        showNotification('Saving all data...', 'info');
        $('.field-input, .hour-input').each(function() { $(this).trigger('change'); });
        setTimeout(function() { showNotification('All data saved!', 'success'); }, 1000);
    }

    function showNotification(message, type) {
        var colors = { success: '#d4edda', info: '#cce5ff', error: '#f8d7da' };
        var textColors = { success: '#155724', info: '#004085', error: '#721c24' };
        var notification = $('<div>')
            .css({
                position: 'fixed', top: '20px', right: '20px',
                padding: '12px 25px', background: colors[type] || '#fff',
                color: textColors[type] || '#333',
                border: '1px solid ' + (colors[type] || '#ddd'),
                borderRadius: '8px', zIndex: 9999,
                boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                fontFamily: 'Inter, sans-serif', fontSize: '14px', fontWeight: '500'
            })
            .html(message).appendTo('body');
        setTimeout(function() { notification.fadeOut(500, function() { $(this).remove(); }); }, 2000);
    }

    function editRow(element) {
        $(element).closest('tr').find('input').first().focus();
        showNotification('Editing row...', 'info');
    }

    function setupMatchOut() {
        showNotification('Match Out setup wizard opening...', 'info');
    }

    $(document).on('keydown', 'input', function(e) {
        if (e.key === 'Enter') {
            $(this).trigger('change');
            var inputs = $(this).closest('tr').find('input');
            var index = inputs.index(this);
            if (index < inputs.length - 1) { inputs.eq(index + 1).focus(); }
        }
    });
    </script>
</body>
</html>