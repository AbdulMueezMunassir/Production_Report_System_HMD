<?php
// export_view_report_csv.php - Download a single report as CSV
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$conn = getDB();

$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$division_id = isset($_GET['division']) ? (int)$_GET['division'] : 0;
$work_hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 10;
$work_hours = max(1, min(11, $work_hours));

// Get division info
$stmt = $conn->prepare("SELECT * FROM divisions WHERE id = ?");
$stmt->execute([$division_id]);
$division = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$division) {
    header('Location: reports.php');
    exit;
}

$is_assembly_division = ($division['type'] === 'assembly');
$division_name = $is_assembly_division ? 'Assembly' : $division['name'];

// ============================================================
// LOAD COMPONENTS (deduplicated)
// ============================================================
$components_raw = getComponents($conn, $division_id);
$seen_names = [];
$components = [];
foreach ($components_raw as $c) {
    $key = strtoupper(trim($c['name']));
    if (!isset($seen_names[$key])) {
        $seen_names[$key] = true;
        $components[] = $c;
    }
}

$component_data = [];
foreach ($components as $comp) {
    if ($comp['is_match_out']) continue;
    $data = getReportData($conn, $division_id, $comp['id'], $date);
    $data['name'] = $comp['name'];
    $component_data[$comp['id']] = $data;
}

// ============================================================
// MATCH OUT
// ============================================================
$match_out_saved = getReportData($conn, $division_id, 999, $date);

// ============================================================
// DHU — Load manual values
// ============================================================
$dhu_saved = getReportData($conn, $division_id, 998, $date);
$dhu_manual = (float)($dhu_saved['hour_1'] ?? 0);
$lean_total_dhu = (float)($dhu_saved['hour_8'] ?? 0);

// Assembly per-row DHU (units 900-906)
$assembly_dhu_units = [
    'SHIRT'       => 900,
    'SHIRT MTM'   => 901,
    'TROUSER'     => 902,
    'TROUSER MTM' => 903,
    'COAT'        => 904,
    'COAT MTM'    => 905,
    'KNIT'        => 906,
];

$assembly_row_dhu_hours = [];
foreach ($assembly_dhu_units as $row_name => $dhu_unit_id) {
    $saved = getReportData($conn, 7, $dhu_unit_id, $date);
    $arr = [];
    for ($h = 1; $h <= 11; $h++) {
        $arr[$h] = (float)($saved["hour_$h"] ?? 0);
    }
    $assembly_row_dhu_hours[$row_name] = $arr;
}

// ============================================================
// OUTPUT CSV
// ============================================================
$filename = $division_name . '_report_' . $date . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM

// Title rows
fputcsv($output, [$division_name . ' - Production Report']);
fputcsv($output, ['Date', $date, 'Working Hours', $work_hours]);
fputcsv($output, []);

// Column headers
$headers = ['DEVITION', 'Unit', 'TTl SAM/Pcs', 'Unit SMV', 'Unit Carder'];
if ($is_assembly_division) {
    $headers[] = 'Total Assemble Carder';
}
$headers[] = 'Plan Hours';
$headers[] = 'Worked Hours';
$headers[] = 'Available Minutes';
$headers[] = '100% Target';
for ($h = 1; $h <= $work_hours; $h++) $headers[] = "H$h";
$headers[] = 'Day Total';
$headers[] = 'Earn Minutes';
$headers[] = 'Achieved Eff %';
$headers[] = 'EPM';
$headers[] = 'Profit';
fputcsv($output, $headers);

// ============================================================
// COMPONENT ROWS
// ============================================================
foreach ($component_data as $comp_id => $data) {
    $day_total = 0;
    for ($h = 1; $h <= $work_hours; $h++) $day_total += (float)($data["hour_$h"] ?? 0);
    
    $unit_smv = (float)($data['unit_smv'] ?? 0);
    $unit_carder = (int)($data['unit_carder'] ?? 0);
    $plan_hours = (float)($data['plan_hours'] ?? 0);
    $worked_hours = (float)($data['worked_hours'] ?? 0);
    $ttl_sam_pc = (float)($data['ttl_sam_pc'] ?? 0);
    
    if ($is_assembly_division) {
        $tac = (int)($data['total_assemble_carder'] ?? $unit_carder);
        $available_minutes = $tac * $plan_hours * 60;
        $ern_minutes = $day_total * $ttl_sam_pc;
        $acvd_eff = 0;
        if ($available_minutes > 0 && $plan_hours > 0) {
            $denom = $available_minutes * ($worked_hours / $plan_hours);
            $acvd_eff = $denom > 0 ? ($ern_minutes / $denom) : 0;
        }
    } else {
        $available_minutes = $unit_carder * $plan_hours * 60;
        $ern_minutes = $day_total * $unit_smv;
        $denom = 1;
        if ($available_minutes > 0 && $plan_hours > 0) {
            $denom = ($available_minutes / $plan_hours) * $worked_hours;
        }
        $acvd_eff = $denom > 0 ? ($ern_minutes / $denom) : 0;
    }
    
    $target_100 = $unit_smv > 0 ? ($unit_carder / $unit_smv) * 60 : 0;
    $epm = (float)($data['epm'] ?? 13.2);
    $profit = (float)($data['profit'] ?? 0);
    
    $row = [$division_name, $data['name'], $ttl_sam_pc, $unit_smv, $unit_carder];
    if ($is_assembly_division) {
        $row[] = (int)($data['total_assemble_carder'] ?? 0);
    }
    $row[] = $plan_hours;
    $row[] = $worked_hours;
    $row[] = round($available_minutes);
    $row[] = round($target_100);
    for ($h = 1; $h <= $work_hours; $h++) {
        $row[] = (float)($data["hour_$h"] ?? 0);
    }
    $row[] = round($day_total);
    $row[] = round($ern_minutes);
    $row[] = round($acvd_eff * 100) . '%';
    $row[] = $epm;
    $row[] = round($profit);
    fputcsv($output, $row);
    
    // DHU sub-row for Assembly
    if ($is_assembly_division) {
        $comp_name_u = strtoupper(trim($data['name'] ?? ''));
        if (isset($assembly_row_dhu_hours[$comp_name_u])) {
            $asm_dhu_hours = $assembly_row_dhu_hours[$comp_name_u];
            $dhu_row = ['', 'DHU %', '', '', ''];
            if ($is_assembly_division) $dhu_row[] = '';
            $dhu_row[] = ''; // Plan Hours
            $dhu_row[] = ''; // Worked Hours
            $dhu_row[] = ''; // Available Minutes
            $dhu_row[] = ''; // 100% Target
            for ($h = 1; $h <= $work_hours; $h++) {
                $dhu_row[] = number_format($asm_dhu_hours[$h] ?? 0, 1) . '%';
            }
            $dhu_row[] = ''; // Day Total
            $dhu_row[] = ''; // Earn Minutes
            $dhu_row[] = ''; // Achieved Eff %
            $dhu_row[] = ''; // EPM
            $dhu_row[] = ''; // Profit
            fputcsv($output, $dhu_row);
        }
    }
}

// ============================================================
// MATCH OUT ROW (non-Assembly)
// ============================================================
if (!$is_assembly_division) {
    $mo_total = 0;
    for ($h = 1; $h <= $work_hours; $h++) $mo_total += (float)($match_out_saved["hour_$h"] ?? 0);
    
    $mo_unit_smv = (float)($match_out_saved['unit_smv'] ?? 0);
    $mo_unit_carder = (int)($match_out_saved['unit_carder'] ?? 0);
    $mo_plan_hours = (float)($match_out_saved['plan_hours'] ?? 0);
    $mo_worked_hours = (float)($match_out_saved['worked_hours'] ?? 0);
    $mo_ern_minutes = $mo_total * $mo_unit_smv;
    $mo_available = $mo_unit_carder * $mo_plan_hours * 60;
    $mo_denom = 1;
    if ($mo_available > 0 && $mo_plan_hours > 0) {
        $mo_denom = ($mo_available / $mo_plan_hours) * $mo_worked_hours;
    }
    $mo_eff = $mo_denom > 0 ? ($mo_ern_minutes / $mo_denom) : 0;
    
    $mo_profit = 0;
    foreach ($component_data as $cd) $mo_profit += (float)($cd['profit'] ?? 0);
    
    $row = ['', 'Match Out', (float)($match_out_saved['ttl_sam_pc'] ?? 0), $mo_unit_smv, $mo_unit_carder];
    if ($is_assembly_division) $row[] = 0;
    $row[] = $mo_plan_hours;
    $row[] = $mo_worked_hours;
    $row[] = round($mo_available);
    $row[] = 0;
    for ($h = 1; $h <= $work_hours; $h++) $row[] = (float)($match_out_saved["hour_$h"] ?? 0);
    $row[] = round($mo_total);
    $row[] = round($mo_ern_minutes);
    $row[] = round($mo_eff * 100) . '%';
    $row[] = 13.2;
    $row[] = round($mo_profit);
    fputcsv($output, $row);
    
    // DHU row (single daily DHU)
    $dhu_row = ['', 'DHU %', '', '', ''];
    if ($is_assembly_division) $dhu_row[] = '';
    $dhu_row[] = ''; // Plan Hours
    $dhu_row[] = ''; // Worked Hours
    $dhu_row[] = ''; // Available Minutes
    $dhu_row[] = ''; // 100% Target
    for ($h = 1; $h <= $work_hours; $h++) $dhu_row[] = '';
    $dhu_row[] = ''; // Day Total
    $dhu_row[] = ''; // Earn Minutes
    $dhu_row[] = ''; // Achieved Eff %
    $dhu_row[] = ''; // EPM
    $dhu_row[] = number_format($dhu_manual, 1) . '%'; // Profit column → show DHU
    fputcsv($output, $dhu_row);
}

// ============================================================
// ASSEMBLY SECTION (for Shirt/Trouser pages)
// ============================================================
if (!$is_assembly_division && in_array($division_id, [1, 2, 3])) {
    $assembly_components = getComponents($conn, 7);
    $assembly_rows_to_show = [];
    
    if ($division_id == 1) {
        $assembly_rows_to_show = ['SHIRT', 'SHIRT MTM'];
    } elseif ($division_id == 2) {
        $assembly_rows_to_show = ['TROUSER', 'TROUSER MTM'];
    } elseif ($division_id == 3) {
        $assembly_rows_to_show = ['COAT', 'COAT MTM', 'KNIT'];
    }
    
    fputcsv($output, []);
    fputcsv($output, ['--- ASSEMBLY ---']);
    fputcsv($output, $headers);
    
    foreach ($assembly_rows_to_show as $asm_name) {
        foreach ($assembly_components as $ac) {
            if (strtoupper(trim($ac['name'])) === $asm_name) {
                $asm_data = getReportData($conn, 7, $ac['id'], $date);
                $day_total = 0;
                for ($h = 1; $h <= $work_hours; $h++) $day_total += (float)($asm_data["hour_$h"] ?? 0);
                
                $row = ['Assembly', $asm_name,
                    (float)($asm_data['ttl_sam_pc'] ?? 0),
                    (float)($asm_data['unit_smv'] ?? 0),
                    (int)($asm_data['unit_carder'] ?? 0)
                ];
                if ($is_assembly_division) {
                    $row[] = (int)($asm_data['total_assemble_carder'] ?? $asm_data['unit_carder'] ?? 0);
                }
                $row[] = (float)($asm_data['plan_hours'] ?? 0);
                $row[] = (float)($asm_data['worked_hours'] ?? 0);
                $row[] = round((float)($asm_data['available_minutes'] ?? 0));
                $row[] = round((float)($asm_data['target_100'] ?? 0));
                for ($h = 1; $h <= $work_hours; $h++) $row[] = (float)($asm_data["hour_$h"] ?? 0);
                $row[] = round($day_total);
                $row[] = round((float)($asm_data['ern_minutes'] ?? 0));
                $row[] = round((float)($asm_data['acvd_eff'] ?? 0) * 100) . '%';
                $row[] = (float)($asm_data['style_epm'] ?? 13.2);
                $row[] = round((float)($asm_data['profit'] ?? 0));
                fputcsv($output, $row);
                
                // DHU sub-row
                if (isset($assembly_row_dhu_hours[$asm_name])) {
                    $asm_dhu_hours = $assembly_row_dhu_hours[$asm_name];
                    $dhu_row = ['', 'DHU %', '', '', ''];
                    if ($is_assembly_division) $dhu_row[] = '';
                    $dhu_row[] = ''; // Plan Hours
                    $dhu_row[] = ''; // Worked Hours
                    $dhu_row[] = ''; // Available Minutes
                    $dhu_row[] = ''; // 100% Target
                    for ($h = 1; $h <= $work_hours; $h++) {
                        $dhu_row[] = number_format($asm_dhu_hours[$h] ?? 0, 1) . '%';
                    }
                    $dhu_row[] = ''; // Day Total
                    $dhu_row[] = ''; // Earn Minutes
                    $dhu_row[] = ''; // Achieved Eff %
                    $dhu_row[] = ''; // EPM
                    $dhu_row[] = ''; // Profit
                    fputcsv($output, $dhu_row);
                }
                
                break;
            }
        }
    }
}

// ============================================================
// LEAN TOTAL DHU ROW (Assembly only)
// ============================================================
if ($is_assembly_division) {
    fputcsv($output, []);
    $lean_row = ['Lean Total DHU %', ''];
    for ($i = 0; $i < 8; $i++) $lean_row[] = '';
    for ($h = 1; $h <= $work_hours; $h++) $lean_row[] = '';
    $lean_row[] = '';
    $lean_row[] = '';
    $lean_row[] = '';
    $lean_row[] = '';
    $lean_row[] = number_format($lean_total_dhu, 1) . '%';
    fputcsv($output, $lean_row);
}

fclose($output);
exit;
?>