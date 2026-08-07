<?php
// division_view.php - Main Excel-like View (FIXED)
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
$match_out = calculateMatchOut($conn, $division_id, $date);

// Get division name for display
$division_name = $division['name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($division['name']); ?> - Production Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f2f5;
            padding: 15px;
        }
        .header {
            background: linear-gradient(135deg, #1a5c3a 0%, #217346 100%);
            color: #fff;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .header h1 { font-size: 18px; }
        .header .subtitle { font-size: 13px; opacity: 0.8; }
        .header-controls { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .header-controls input[type="date"] {
            padding: 6px 10px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
        }
        .btn {
            padding: 6px 15px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 12px;
            transition: all 0.3s ease;
        }
        .btn-refresh { background: rgba(255,255,255,0.2); color: #fff; }
        .btn-refresh:hover { background: rgba(255,255,255,0.3); }
        .btn-back { background: rgba(255,255,255,0.15); color: #fff; }
        .btn-back:hover { background: rgba(255,255,255,0.25); }
        .btn-save { background: #fff; color: #217346; }
        .btn-save:hover { background: #f0f0f0; }
        .btn-export { background: rgba(255,255,255,0.2); color: #fff; }
        .btn-export:hover { background: rgba(255,255,255,0.3); }
        
        .container {
            background: #fff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow-x: auto;
        }
        
        .table-title {
            padding: 12px 20px;
            background: #f8f9fa;
            border-bottom: 2px solid #217346;
            font-weight: 600;
            font-size: 14px;
            color: #333;
        }
        .table-title .date-info { font-weight: 400; color: #666; font-size: 13px; }
        
        .excel-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            min-width: 1400px;
        }
        .excel-table th {
            background: #f8f9fa;
            border: 1px solid #d0d0d0;
            padding: 6px 4px;
            text-align: center;
            font-weight: 700;
            color: #333;
            font-size: 10px;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .excel-table td {
            border: 1px solid #d0d0d0;
            padding: 4px 3px;
            text-align: center;
            white-space: nowrap;
        }
        .excel-table tr:hover { background: #f5f5f5; }
        
        .excel-table .header-devition {
            background: #e8f0fe;
            font-weight: 700;
            font-size: 11px;
        }
        .excel-table .match-out-row {
            background: #e8f0fe;
            font-weight: 600;
        }
        .excel-table .match-out-row td { background: #e8f0fe; }
        
        .excel-table .editable-yellow {
            background: #ffff00;
        }
        .excel-table .editable-yellow input {
            background: #ffff00;
        }
        .excel-table .calculated {
            background: #f0f0f0;
            color: #555;
        }
        .excel-table .total-row {
            background: #e8f0fe;
            font-weight: 700;
        }
        
        .excel-table input[type="number"] {
            width: 100%;
            border: none;
            background: transparent;
            text-align: center;
            padding: 2px;
            font-size: 11px;
            font-weight: 500;
            font-family: 'Inter', sans-serif;
            min-width: 50px;
        }
        .excel-table input[type="number"]:focus {
            outline: 2px solid #217346;
            outline-offset: -2px;
            background: #fff;
        }
        .excel-table input[type="number"]:hover {
            background: #fffbe6;
        }
        
        .excel-table .division-name {
            font-weight: 700;
            background: #e8f0fe;
            min-width: 80px;
        }
        
        .scroll-indicator {
            text-align: center;
            padding: 8px;
            background: #fff3cd;
            color: #856404;
            font-size: 12px;
            border-bottom: 1px solid #ffc107;
        }
        .scroll-indicator span { font-weight: 700; }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .header { flex-direction: column; align-items: stretch; }
            .header-controls { justify-content: flex-start; }
            .excel-table { font-size: 10px; min-width: 1200px; }
            .excel-table th, .excel-table td { padding: 3px 2px; }
            .excel-table input[type="number"] { min-width: 35px; font-size: 10px; }
        }
        
        @media print {
            .header-controls { display: none; }
            .header { background: #217346 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .excel-table .editable-yellow { background: #ffff00 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .excel-table .match-out-row { background: #e8f0fe !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>📊 <?php echo htmlspecialchars($division['name']); ?> - Production Report</h1>
            <div class="subtitle">Hameedia Clothing Company | <?php echo date('l, F d, Y', strtotime($date)); ?></div>
        </div>
        <div class="header-controls">
            <a href="dashboard.php" class="btn btn-back">← Back</a>
            <input type="date" id="reportDate" value="<?php echo $date; ?>" 
                   onchange="window.location.href='?id=<?php echo $division_id; ?>&date='+this.value">
            <button class="btn btn-refresh" onclick="window.location.reload()">🔄 Refresh</button>
            <button class="btn btn-export" onclick="window.print()">🖨️ Print</button>
            <button class="btn btn-save" onclick="saveAll()">💾 Save All</button>
        </div>
    </div>

    <div class="scroll-indicator">
        ⬅️ Scroll horizontally to view all columns ➡️
        <span>| 100% Target → Acvd Eff</span>
    </div>

    <div class="container">
        <div class="table-title">
            <?php echo htmlspecialchars($division['name']); ?> - Hourly Production Data
            <span class="date-info">| Date: <?php echo date('Y-m-d', strtotime($date)); ?></span>
        </div>
        <table class="excel-table" id="mainTable">
            <thead>
                <tr>
                    <th style="min-width:60px;">DEVITION</th>
                    <th style="min-width:60px;">Unit</th>
                    <th style="min-width:70px; background:#ffff00;">TTl SAM/Pc</th>
                    <th style="min-width:70px;">Unit SMV</th>
                    <th style="min-width:70px;">Day Forecast</th>
                    <th style="min-width:60px;">Unit Carder</th>
                    <th style="min-width:60px;">Plan Hours</th>
                    <th style="min-width:60px;">Worked Hours</th>
                    <th style="min-width:80px;">Available Minutes</th>
                    <th style="min-width:80px;">Plan Minutes</th>
                    <th style="min-width:70px;">Plan Eff</th>
                    <th style="min-width:70px; background:#ffff00;">100% Target</th>
                    <th style="min-width:55px; background:#ffff00;">1st</th>
                    <th style="min-width:55px; background:#ffff00;">2nd</th>
                    <th style="min-width:55px; background:#ffff00;">3rd</th>
                    <th style="min-width:55px; background:#ffff00;">4th</th>
                    <th style="min-width:55px; background:#ffff00;">5th</th>
                    <th style="min-width:55px; background:#ffff00;">6th</th>
                    <th style="min-width:55px; background:#ffff00;">7th</th>
                    <th style="min-width:55px; background:#ffff00;">8th</th>
                    <th style="min-width:55px; background:#ffff00;">9th</th>
                    <th style="min-width:55px; background:#ffff00;">10th</th>
                    <th style="min-width:55px; background:#ffff00;">11th</th>
                    <th style="min-width:60px;">Day Ttl</th>
                    <th style="min-width:70px;">Acvd Eff</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($components)): ?>
                <tr>
                    <td colspan="25" style="padding:30px; color:#999; text-align:center;">
                        No components found for this division. Please add components in the database.
                    </td>
                </tr>
                <?php else: ?>
                <?php 
                $row_count = 0;
                foreach ($components as $index => $comp):
                    if ($comp['is_match_out']) continue;
                    $row_count++;
                    $data = getReportData($conn, $division_id, $comp['id'], $date);
                    // Calculate day total
                    $day_total = 0;
                    for ($h = 1; $h <= 11; $h++) {
                        $day_total += $data["hour_$h"] ?? 0;
                    }
                    // Calculate acvd_eff
                    $available_minutes = $data['available_minutes'] ?? 0;
                    $acvd_eff = $available_minutes > 0 ? ($day_total * ($data['ttl_sam_pc'] ?? 0) / $available_minutes) * 100 : 0;
                ?>
                <tr>
                    <?php if ($index === 0): ?>
                    <td rowspan="<?php echo count($components) - 1; ?>" class="division-name">
                        <?php echo htmlspecialchars($division['name']); ?>
                    </td>
                    <?php endif; ?>
                    <td><?php echo htmlspecialchars($comp['name']); ?></td>
                    <td class="editable-yellow">
                        <input type="number" step="0.01" class="field-input" 
                               data-component="<?php echo $comp['id']; ?>"
                               data-field="ttl_sam_pc"
                               value="<?php echo $data['ttl_sam_pc'] ?? 0; ?>"
                               onchange="updateField(this, '<?php echo $comp['id']; ?>', 'ttl_sam_pc')">
                    </td>
                    <td class="editable-yellow">
                        <input type="number" step="0.01" class="field-input" 
                               data-component="<?php echo $comp['id']; ?>"
                               data-field="unit_smv"
                               value="<?php echo $data['unit_smv'] ?? 0; ?>"
                               onchange="updateField(this, '<?php echo $comp['id']; ?>', 'unit_smv')">
                    </td>
                    <td class="editable-yellow">
                        <input type="number" step="0.01" class="field-input" 
                               data-component="<?php echo $comp['id']; ?>"
                               data-field="day_forecast"
                               value="<?php echo $data['day_forecast'] ?? 0; ?>"
                               onchange="updateField(this, '<?php echo $comp['id']; ?>', 'day_forecast')">
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
                    <td class="calculated"><?php echo number_format($data['plan_minutes'] ?? 0, 0); ?></td>
                    <td class="calculated"><?php echo number_format($data['plan_eff'] ?? 0, 1); ?>%</td>
                    <td class="editable-yellow">
                        <input type="number" step="0.01" class="field-input" 
                               data-component="<?php echo $comp['id']; ?>"
                               data-field="target_100"
                               value="<?php echo $data['target_100'] ?? 0; ?>"
                               onchange="updateField(this, '<?php echo $comp['id']; ?>', 'target_100')">
                    </td>
                    <?php for ($h = 1; $h <= 11; $h++): ?>
                    <td class="editable-yellow">
                        <input type="number" class="hour-input" 
                               data-component="<?php echo $comp['id']; ?>"
                               data-hour="<?php echo $h; ?>"
                               value="<?php echo $data["hour_$h"] ?? 0; ?>"
                               onchange="updateHour(this, '<?php echo $comp['id']; ?>', <?php echo $h; ?>)">
                    </td>
                    <?php endfor; ?>
                    <td class="calculated" style="font-weight:700;"><?php echo number_format($day_total, 0); ?></td>
                    <td class="calculated" style="font-weight:700; color:#217346;">
                        <?php echo number_format($acvd_eff, 1); ?>%
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <!-- Match Out Row -->
                <?php if (!empty($components)): ?>
                <tr class="match-out-row">
                    <td colspan="2" style="font-weight:700;">Match Out</td>
                    <td class="editable-yellow">
                        <input type="number" step="0.01" class="field-input" 
                               data-component="match_out"
                               data-field="ttl_sam_pc"
                               value="<?php echo $match_out['ttl_sam_pc'] ?? 0; ?>"
                               onchange="updateField(this, 'match_out', 'ttl_sam_pc')">
                    </td>
                    <td><?php echo number_format($match_out['unit_smv'] ?? 0, 2); ?></td>
                    <td><?php echo number_format($match_out['day_forecast'] ?? 0, 0); ?></td>
                    <td><?php echo $match_out['unit_carder'] ?? 0; ?></td>
                    <td><?php echo number_format($match_out['plan_hours'] ?? 0, 1); ?></td>
                    <td><?php echo number_format($match_out['worked_hours'] ?? 0, 1); ?></td>
                    <td class="calculated"><?php echo number_format(($match_out['plan_hours'] ?? 0) * ($match_out['worked_hours'] ?? 0) * 60, 0); ?></td>
                    <td class="calculated"><?php echo number_format(($match_out['day_forecast'] ?? 0) * ($match_out['unit_carder'] ?? 0), 0); ?></td>
                    <td class="calculated">
                        <?php 
                        $mo_available = ($match_out['plan_hours'] ?? 0) * ($match_out['worked_hours'] ?? 0) * 60;
                        $mo_plan_minutes = ($match_out['day_forecast'] ?? 0) * ($match_out['unit_carder'] ?? 0);
                        echo number_format($mo_available > 0 ? ($mo_plan_minutes / $mo_available) * 100 : 0, 1); ?>%
                    </td>
                    <td><?php echo number_format(($match_out['plan_hours'] ?? 0) / (($match_out['unit_carder'] ?? 0) > 0 ? ($match_out['unit_carder'] ?? 0) : 1) * 60, 0); ?></td>
                    <?php 
                    $mo_total = 0;
                    for ($h = 1; $h <= 11; $h++): 
                        $mo_total += $match_out['hours'][$h] ?? 0;
                    ?>
                    <td><?php echo number_format($match_out['hours'][$h] ?? 0, 0); ?></td>
                    <?php endfor; ?>
                    <td style="font-weight:700;"><?php echo number_format($mo_total, 0); ?></td>
                    <td style="font-weight:700; color:#217346;">
                        <?php 
                        $mo_eff = $mo_available > 0 ? ($mo_total * ($match_out['ttl_sam_pc'] ?? 0) / $mo_available) * 100 : 0;
                        echo number_format($mo_eff, 1); ?>%
                    </td>
                </tr>
                <?php endif; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
    // Auto-save functions
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
                }
            }
        });
    }

    function saveAll() {
        showNotification('Saving all data...', 'info');
        $('.field-input, .hour-input').each(function() {
            $(this).trigger('change');
        });
        setTimeout(function() {
            showNotification('All data saved!', 'success');
        }, 1000);
    }

    function showNotification(message, type) {
        var colors = {
            success: '#d4edda',
            info: '#cce5ff',
            error: '#f8d7da'
        };
        var textColors = {
            success: '#155724',
            info: '#004085',
            error: '#721c24'
        };
        
        var notification = $('<div>')
            .css({
                position: 'fixed',
                top: '20px',
                right: '20px',
                padding: '12px 25px',
                background: colors[type] || '#fff',
                color: textColors[type] || '#333',
                border: '1px solid ' + (colors[type] || '#ddd'),
                borderRadius: '8px',
                zIndex: 9999,
                boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                fontFamily: 'Inter, sans-serif',
                fontSize: '14px',
                fontWeight: '500'
            })
            .html(message)
            .appendTo('body');
        
        setTimeout(function() {
            notification.fadeOut(500, function() {
                $(this).remove();
            });
        }, 2000);
    }

    $(document).on('keydown', 'input', function(e) {
        if (e.key === 'Enter') {
            $(this).trigger('change');
            var inputs = $(this).closest('tr').find('input');
            var index = inputs.index(this);
            if (index < inputs.length - 1) {
                inputs.eq(index + 1).focus();
            }
        }
    });

    var saveTimeout;
    $(document).on('input', '.field-input, .hour-input', function() {
        clearTimeout(saveTimeout);
        saveTimeout = setTimeout(function() {
            // Will be triggered by onchange
        }, 500);
    });
    </script>
</body>
</html>