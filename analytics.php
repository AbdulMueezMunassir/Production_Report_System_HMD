<?php
// analytics.php - Kiosk Mode Analytics (Continuous scroll, stacked screens)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$conn = getDB();
$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';

// Kiosk always shows today. Use shared helper for consistency / future-proofing.
$selected_date = normalizeReportDate($_GET['date'] ?? null);
$selected_division = isset($_GET['division']) ? (int)$_GET['division'] : 1;
$trend_days = 30;

$valid_divisions = [1, 2, 3, 7];
if (!in_array($selected_division, $valid_divisions)) {
    $selected_division = 1;
}

$division_names = [1 => 'Shirt', 2 => 'Trouser', 3 => 'Coat', 7 => 'Assembly'];
$division_icons = [1 => '👔', 2 => '👖', 3 => '🧥', 7 => '🏭'];

// AUTO-DETECT WORK HOURS — use shared helper (same logic as division_view.php)
$work_hours = detectWorkHours($conn, $selected_division, $selected_date, 10);
$work_hours = max(1, min(11, $work_hours));

// MATCH OUT
$shirt_match_out_carder = 0;
$trouser_match_out_carder = 0;
try {
    $shirt_components = getComponents($conn, 1);
    $mo1 = calculateMatchOutFixed($conn, 1, $selected_date, $work_hours, $shirt_components);
    $shirt_match_out_carder = (int)($mo1['direct_present'] ?? 0);
} catch (Exception $e) {}
try {
    $trouser_components = getComponents($conn, 2);
    $mo2 = calculateMatchOutFixed($conn, 2, $selected_date, $work_hours, $trouser_components);
    $trouser_match_out_carder = (int)($mo2['direct_present'] ?? 0);
} catch (Exception $e) {}

// ============================================================
// DHU — Load from DB
// Regular divisions: unit_id = 998, hour_1 (single daily DHU)
// Assembly: per-hour DHU per row stored in unit_ids 900-906
// ============================================================
$dhu_saved = getReportData($conn, $selected_division, 998, $selected_date);
$dhu_manual_value = (float)($dhu_saved['hour_1'] ?? 0);
$lean_total_dhu_value = (float)($dhu_saved['hour_8'] ?? 0);

// Assembly per-hour DHU per row
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
    $saved = getReportData($conn, 7, $dhu_unit_id, $selected_date);
    $arr = [];
    for ($h = 1; $h <= 11; $h++) {
        $arr[$h] = (float)($saved["hour_$h"] ?? 0);
    }
    $assembly_row_dhu_hours[$row_name] = $arr;
}

// ============================================================
// HELPERS
// ============================================================
function computeProfit($data, $division_type, $comp_name = '', $total_assemble_carder = null) {
    $day_total = (float)($data['day_total'] ?? 0);
    $unit_carder = (int)($data['unit_carder'] ?? 0);
    $calculation_carder = in_array($division_type, ['shirt', 'trouser'], true)
        ? (int)($data['direct_present'] ?? 0)
        : $unit_carder;
    $unit_smv = (float)($data['unit_smv'] ?? 0);
    $plan_hours = (float)($data['plan_hours'] ?? 0);
    $worked_hours = (float)($data['worked_hours'] ?? 0);
    $epm = (float)($data['epm'] ?? 13.2);
    $cu = strtoupper(trim($comp_name));
    if ($division_type === 'assembly') {
        $tac = $total_assemble_carder !== null ? $total_assemble_carder : $unit_carder;
        $mult = 500;
        if ($cu === 'SHIRT MTM') $mult = 1100;
        elseif ($cu === 'TROUSER') $mult = 800;
        elseif ($cu === 'TROUSER MTM') $mult = 1500;
        elseif ($cu === 'COAT') $mult = 2800;
        elseif ($cu === 'COAT MTM') $mult = 3400;
        elseif ($cu === 'KNIT') $mult = 295;
        return ($mult * $day_total) - (7365 * $tac);
    } else {
        if ($plan_hours > 0) return ($epm * ($day_total * $unit_smv)) - (7365 * $calculation_carder) * ($worked_hours / $plan_hours);
        return ($epm * ($day_total * $unit_smv)) - (7365 * $calculation_carder);
    }
}

function getComponentByName($conn, $division_id, $date, $component_name) {
    $components = getComponents($conn, $division_id);
    foreach ($components as $comp) {
        if ($comp['is_match_out']) continue;
        if (strtoupper(trim($comp['name'])) === strtoupper(trim($component_name))) {
            $data = getReportData($conn, $division_id, $comp['id'], $date);
            for ($h = 1; $h <= 11; $h++) $data["hour_$h"] = (float)($data["hour_$h"] ?? 0);
            return $data;
        }
    }
    return null;
}

function getMatchOutData($conn, $division_id, $date, $work_hours = 10) {
    $mo = getReportData($conn, $division_id, 999, $date);
    if (in_array((int)$division_id, [1, 2], true)) {
        $components = getComponents($conn, $division_id);
        $calculated_match_out = calculateMatchOutFixed($conn, $division_id, $date, $work_hours, $components);
        $mo['direct_on_roll'] = (int)($calculated_match_out['direct_on_roll'] ?? 0);
        $mo['direct_present'] = (int)($calculated_match_out['direct_present'] ?? 0);
    }
    for ($h = 1; $h <= 11; $h++) $mo["hour_$h"] = (float)($mo["hour_$h"] ?? 0);
    return $mo;
}

function computeDHU($data) {
    $dt = (float)($data['day_total'] ?? 0);
    return $dt > 0 ? round(($dt / 100) * 5, 1) : 0;
}

function calculateDhuRate($defect_count, $production_pcs) {
    $defect_count = (float)$defect_count;
    $production_pcs = (float)$production_pcs;
    $total_pcs = $production_pcs + $defect_count;
    return $total_pcs > 0 ? ($defect_count / $total_pcs) * 100 : 0;
}

function calculateDhuRateHours($defect_hours, $production_hours, $work_hours) {
    $rates = [];
    for ($h = 1; $h <= $work_hours; $h++) {
        $rates[$h] = calculateDhuRate(
            $defect_hours[$h] ?? 0,
            $production_hours[$h - 1] ?? 0
        );
    }
    return $rates;
}

function calculateAbsenteeism($direct_on_roll, $direct_present) {
    $direct_on_roll = (float)$direct_on_roll;
    if ($direct_on_roll <= 0) return 0;
    return (($direct_on_roll - (float)$direct_present) * 100) / $direct_on_roll;
}

function buildColumn($name, $data, $division_type, $total_assemble_carder = null, $work_hours = 10, $dhu_override = null) {
    $hours = [];
    $hour_eff = [];
    $hour_efficiency_numerator = [];
    $hour_efficiency_denominator = [];
    for ($h = 1; $h <= $work_hours; $h++) $hours[] = (float)($data["hour_$h"] ?? 0);
    $unit_carder = (int)($data['unit_carder'] ?? 0);
    $direct_on_roll = (int)($data['direct_on_roll'] ?? 0);
    $direct_present = (int)($data['direct_present'] ?? 0);
    $calculation_carder = in_array($division_type, ['shirt', 'trouser'], true)
        ? $direct_present
        : $unit_carder;
    $unit_smv = (float)($data['unit_smv'] ?? 0);
    $worked_hours = (float)($data['worked_hours'] ?? $work_hours);
    $ttl_sam_pc = (float)($data['ttl_sam_pc'] ?? 0);
    $day_total = 0;
    for ($h = 1; $h <= $work_hours; $h++) $day_total += (float)($data["hour_$h"] ?? 0);
    $acvd_eff = 0;
    if ($division_type === 'assembly') {
        $tac = $total_assemble_carder !== null ? $total_assemble_carder : $unit_carder;
        $ern_minutes = $day_total * $ttl_sam_pc;
        $daily_available_minutes = $tac * $worked_hours * 60;
    } else {
        $ern_minutes = $day_total * $unit_smv;
        $daily_available_minutes = $calculation_carder * $worked_hours * 60;
    }
    $acvd_eff = $daily_available_minutes > 0 ? ($ern_minutes / $daily_available_minutes) : 0;
    $hourly_sam = $division_type === 'assembly' ? $ttl_sam_pc : $unit_smv;
    $hourly_carder = $division_type === 'assembly'
        ? ($total_assemble_carder !== null ? $total_assemble_carder : $unit_carder)
        : $calculation_carder;
    $hourly_target = $hourly_sam > 0
        ? ($hourly_carder * 60) / $hourly_sam
        : 0;
    for ($h = 0; $h < $work_hours; $h++) {
        $hour_efficiency_numerator[$h] = $hours[$h] * $hourly_sam;
        $hour_efficiency_denominator[$h] = $hourly_carder * 60;
        $hour_eff[$h] = $hourly_target > 0
            ? ($hours[$h] / $hourly_target) * 100
            : 0;
    }
    $dhu_val = ($dhu_override !== null) ? $dhu_override : computeDHU($data);
    return [
        'name' => $name, 'pcs' => $day_total, 'eff' => $acvd_eff * 100,
        'efficiency_numerator' => $ern_minutes, 'efficiency_denominator' => $daily_available_minutes,
        'sam_efficiency_numerator' => $ern_minutes, 'sam_efficiency_denominator' => $daily_available_minutes,
        'carder' => $unit_carder, 'direct_on_roll' => $direct_on_roll,
        'direct_present' => $direct_present, 'dhu' => $dhu_val,
        'hours' => $hours, 'hour_eff' => $hour_eff,
        'hour_efficiency_numerator' => $hour_efficiency_numerator,
        'hour_efficiency_denominator' => $hour_efficiency_denominator,
        'profit' => computeProfit($data, $division_type, $name, $total_assemble_carder),
        'type' => ($division_type === 'assembly') ? 'assembly' : 'component'
    ];
}

function emptyColumn($name, $type = 'component', $work_hours = 10) {
    return [
        'name' => $name, 'pcs' => 0, 'eff' => 0, 'efficiency_numerator' => 0,
        'efficiency_denominator' => 0, 'sam_efficiency_numerator' => 0,
        'sam_efficiency_denominator' => 0, 'carder' => 0, 'direct_on_roll' => 0,
        'direct_present' => 0, 'dhu' => 0, 'hours' => array_fill(0, $work_hours, 0),
        'hour_eff' => array_fill(0, $work_hours, 0),
        'hour_efficiency_numerator' => array_fill(0, $work_hours, 0),
        'hour_efficiency_denominator' => array_fill(0, $work_hours, 0),
        'profit' => 0, 'type' => $type
    ];
}

// ============================================================
// BUILD TABLES
// ============================================================
$shirt_main = []; $shirt_mtm = [];
$trouser_main = []; $trouser_mtm = [];
$coat_main = []; $coat_mtm = [];
$assembly_main = [];

foreach (['Front', 'Back', 'Collar', 'Sleeve', 'Cuff'] as $name) {
    $cd = getComponentByName($conn, 1, $selected_date, $name);
    $shirt_main[] = $cd ? buildColumn($name, $cd, 'shirt', null, $work_hours, $dhu_manual_value) : emptyColumn($name, 'component', $work_hours);
}
$mo_shirt = getMatchOutData($conn, 1, $selected_date, $work_hours);
$mo_col = buildColumn('Match Out', $mo_shirt, 'shirt', null, $work_hours, $dhu_manual_value);
$mo_col['type'] = 'match_out'; $mo_col['profit'] = 0;
foreach ($shirt_main as $c) if ($c['type']==='component') $mo_col['profit'] += $c['profit'];
$shirt_main[] = $mo_col;
$asm_shirt = getComponentByName($conn, 7, $selected_date, 'SHIRT');
if ($asm_shirt) {
    $tac = ((int)($asm_shirt['unit_carder'] ?? 0)) + $shirt_match_out_carder;
    $c = buildColumn('Assembly SHIRT', $asm_shirt, 'assembly', $tac, $work_hours, $assembly_row_dhu_hours['SHIRT'][1] ?? 0);
    $c['type'] = 'assembly'; $shirt_main[] = $c;
} else $shirt_main[] = emptyColumn('Assembly SHIRT', 'assembly', $work_hours);

$shirt_mtm_comp = getComponentByName($conn, 7, $selected_date, 'SHIRT MTM');
if ($shirt_mtm_comp) { $c = buildColumn('SHIRT MTM', $shirt_mtm_comp, 'assembly', null, $work_hours, $assembly_row_dhu_hours['SHIRT MTM'][1] ?? 0); $c['type']='assembly'; $shirt_mtm[] = $c; }
else $shirt_mtm[] = emptyColumn('SHIRT MTM', 'assembly', $work_hours);

foreach (['Front', 'Back', 'Band'] as $name) {
    $cd = getComponentByName($conn, 2, $selected_date, $name);
    $trouser_main[] = $cd ? buildColumn($name, $cd, 'trouser', null, $work_hours, $dhu_manual_value) : emptyColumn($name, 'component', $work_hours);
}
$mo_tr = getMatchOutData($conn, 2, $selected_date, $work_hours);
$mo_tr_col = buildColumn('Match Out', $mo_tr, 'trouser', null, $work_hours, $dhu_manual_value);
$mo_tr_col['type'] = 'match_out'; $mo_tr_col['profit'] = 0;
foreach ($trouser_main as $c) if ($c['type']==='component') $mo_tr_col['profit'] += $c['profit'];
$trouser_main[] = $mo_tr_col;
$asm_tr = getComponentByName($conn, 7, $selected_date, 'TROUSER');
if ($asm_tr) {
    $tac = ((int)($asm_tr['unit_carder'] ?? 0)) + $trouser_match_out_carder;
    $c = buildColumn('Assembly TROUSER', $asm_tr, 'assembly', $tac, $work_hours, $assembly_row_dhu_hours['TROUSER'][1] ?? 0);
    $c['type'] = 'assembly'; $trouser_main[] = $c;
} else $trouser_main[] = emptyColumn('Assembly TROUSER', 'assembly', $work_hours);

$trouser_mtm_comp = getComponentByName($conn, 7, $selected_date, 'TROUSER MTM');
if ($trouser_mtm_comp) { $c = buildColumn('TROUSER MTM', $trouser_mtm_comp, 'assembly', null, $work_hours, $assembly_row_dhu_hours['TROUSER MTM'][1] ?? 0); $c['type']='assembly'; $trouser_mtm[] = $c; }
else $trouser_mtm[] = emptyColumn('TROUSER MTM', 'assembly', $work_hours);

$coat_comp = getComponentByName($conn, 7, $selected_date, 'COAT');
$coat_main[] = $coat_comp ? buildColumn('COAT', $coat_comp, 'assembly', null, $work_hours, $assembly_row_dhu_hours['COAT'][1] ?? 0) : emptyColumn('COAT', 'assembly', $work_hours);
$coat_mtm_comp = getComponentByName($conn, 7, $selected_date, 'COAT MTM');
$coat_mtm[] = $coat_mtm_comp ? buildColumn('COAT MTM', $coat_mtm_comp, 'assembly', null, $work_hours, $assembly_row_dhu_hours['COAT MTM'][1] ?? 0) : emptyColumn('COAT MTM', 'assembly', $work_hours);

foreach (['SHIRT', 'SHIRT MTM', 'TROUSER', 'TROUSER MTM', 'COAT', 'COAT MTM', 'KNIT'] as $name) {
    $cd = getComponentByName($conn, 7, $selected_date, $name);
    if ($cd) {
        $tac = (int)($cd['unit_carder'] ?? 0);
        if ($name === 'SHIRT') $tac += $shirt_match_out_carder;
        elseif ($name === 'TROUSER') $tac += $trouser_match_out_carder;
        $row_dhu = $assembly_row_dhu_hours[$name][1] ?? 0;
        $assembly_main[] = buildColumn($name, $cd, 'assembly', $tac, $work_hours, $row_dhu);
    } else $assembly_main[] = emptyColumn($name, 'assembly', $work_hours);
}

// KNIT — compute once, reuse in analytics and chart calculations.
$knit_data = getComponentByName($conn, 7, $selected_date, 'KNIT');
$knit_col = $knit_data
    ? buildColumn('KNIT', $knit_data, 'assembly', null, $work_hours, $assembly_row_dhu_hours['KNIT'][1] ?? 0)
    : emptyColumn('KNIT', 'assembly', $work_hours);

function computeCharts($columns, $work_hours, $dhu_override_hours = null) {
    $production = array_fill(1, $work_hours, 0);
    $dhu = array_fill(1, $work_hours, 0);
    $efficiency = array_fill(1, $work_hours, 0);
    $hour_efficiency_numerator = array_fill(1, $work_hours, 0);
    $hour_efficiency_denominator = array_fill(1, $work_hours, 0);
    $assembly_columns = [];
    $match_out_columns = [];
    $component_columns = [];
    foreach ($columns as $col) {
        if ($col['type'] === 'assembly') $assembly_columns[] = $col;
        elseif ($col['type'] === 'match_out') $match_out_columns[] = $col;
        elseif ($col['type'] === 'component') $component_columns[] = $col;
    }
    $output_columns = array_filter($assembly_columns, function($col) { return $col['pcs'] > 0 || $col['carder'] > 0; });
    if (empty($output_columns)) {
        $output_columns = array_filter($match_out_columns, function($col) { return $col['pcs'] > 0; });
    }
    $sum_output_columns = !empty($output_columns);
    if (!$sum_output_columns) {
        $output_columns = array_filter($component_columns, function($col) { return $col['pcs'] > 0 || $col['carder'] > 0; });
    }
    $output_count = count($output_columns);
    foreach ($output_columns as $col) {
        for ($h = 0; $h < $work_hours; $h++) {
            $production[$h + 1] += (float)($col['hours'][$h] ?? 0);
            $hour_efficiency_numerator[$h + 1] += (float)($col['hour_efficiency_numerator'][$h] ?? 0);
            $hour_efficiency_denominator[$h + 1] += (float)($col['hour_efficiency_denominator'][$h] ?? 0);
        }
    }
    for ($h = 1; $h <= $work_hours; $h++) {
        if (!$sum_output_columns && $output_count > 0) {
            $production[$h] /= $output_count;
        }
        $production[$h] = round($production[$h], 0);
        $efficiency[$h] = $hour_efficiency_denominator[$h] > 0
            ? round(($hour_efficiency_numerator[$h] / $hour_efficiency_denominator[$h]) * 100, 1)
            : 0;
    }
    
    // DHU — use override (manual values) if provided
    if (is_array($dhu_override_hours)) {
        for ($h = 1; $h <= $work_hours; $h++) {
            $dhu[$h] = (float)($dhu_override_hours[$h] ?? 0);
        }
    } else {
        // Legacy: weighted average of pcs × 5%
        $total_pcs_for_dhu = 0;
        foreach ($columns as $col) {
            if ($col['pcs'] > 0) {
                $total_pcs_for_dhu += $col['pcs'];
                for ($h = 0; $h < $work_hours; $h++) {
                    $dhu[$h + 1] += (($col['hours'][$h] ?? 0) / 100) * 5;
                }
            }
        }
        if ($total_pcs_for_dhu > 0) {
            for ($h = 1; $h <= $work_hours; $h++) {
                $dhu[$h] = round(($dhu[$h] / $total_pcs_for_dhu) * 100, 1);
            }
        }
    }
    
    return [
        'production' => array_values($production),
        'efficiency' => array_values($efficiency),
        'dhu' => array_values($dhu)
    ];
}

function computeStats($columns) {
    $assembly_columns = array_filter($columns, function($col) { return $col['type'] === 'assembly'; });
    $match_out_columns = array_filter($columns, function($col) { return $col['type'] === 'match_out'; });
    $component_columns = array_filter($columns, function($col) { return $col['type'] === 'component'; });
    $production_columns = array_filter($assembly_columns, function($col) {
        return $col['pcs'] > 0 || $col['carder'] > 0;
    });
    if (empty($production_columns)) {
        $production_columns = array_filter($match_out_columns, function($col) { return $col['pcs'] > 0; });
    }
    $sum_production_columns = !empty($production_columns);
    if (!$sum_production_columns) {
        $production_columns = array_filter($component_columns, function($col) {
            return $col['pcs'] > 0 || $col['carder'] > 0;
        });
    }
    $production_column_count = count($production_columns);
    $total_pcs = 0;
    $efficiency_numerator = 0;
    $efficiency_denominator = 0;
    $dhu_sum = 0;
    $dhu_count = 0;
    foreach ($production_columns as $col) {
        $total_pcs += (float)$col['pcs'];
        $efficiency_numerator += (float)($col['sam_efficiency_numerator'] ?? 0);
        $efficiency_denominator += (float)($col['sam_efficiency_denominator'] ?? 0);
    }
    if (!$sum_production_columns && $production_column_count > 0) {
        $total_pcs /= $production_column_count;
    }
    foreach ($columns as $col) {
        if ($col['dhu'] > 0) { $dhu_sum += $col['dhu']; $dhu_count++; }
    }
    return [
        'total_pcs' => $total_pcs,
        'avg_eff' => $efficiency_denominator > 0 ? round(($efficiency_numerator / $efficiency_denominator) * 100, 1) : 0,
        'avg_dhu' => $dhu_count > 0 ? round($dhu_sum / $dhu_count, 1) : 0
    ];
}

// ============================================================
// BLOCK 1: CHART DATA — with manual DHU overrides
// ============================================================

// Assembly defect inputs are counts; report DHU as defects / (production + defects).
$shirt_mtm_dhu_rates = calculateDhuRateHours($assembly_row_dhu_hours['SHIRT MTM'], $shirt_mtm[0]['hours'] ?? [], $work_hours);
$trouser_mtm_dhu_rates = calculateDhuRateHours($assembly_row_dhu_hours['TROUSER MTM'], $trouser_mtm[0]['hours'] ?? [], $work_hours);
$shirt_main_assembly = $shirt_main[count($shirt_main) - 1];
$trouser_main_assembly = $trouser_main[count($trouser_main) - 1];
$shirt_main_dhu_eff = dhuEff($shirt_main_assembly['pcs'], $assembly_row_dhu_hours['SHIRT'], $work_hours);
$trouser_main_dhu_eff = dhuEff($trouser_main_assembly['pcs'], $assembly_row_dhu_hours['TROUSER'], $work_hours);
$shirt_main_dhu_rates = calculateDhuRateHours($assembly_row_dhu_hours['SHIRT'], $shirt_main_assembly['hours'], $work_hours);
$trouser_main_dhu_rates = calculateDhuRateHours($assembly_row_dhu_hours['TROUSER'], $trouser_main_assembly['hours'], $work_hours);
if ($shirt_main_assembly['pcs'] <= 0 && $shirt_main_dhu_eff['total'] <= 0) {
    $shirt_main_dhu_rates = array_fill(1, $work_hours, $dhu_manual_value);
    $shirt_main_dhu_eff['eff'] = $dhu_manual_value;
}
if ($trouser_main_assembly['pcs'] <= 0 && $trouser_main_dhu_eff['total'] <= 0) {
    $trouser_main_dhu_rates = array_fill(1, $work_hours, $dhu_manual_value);
    $trouser_main_dhu_eff['eff'] = $dhu_manual_value;
}
$coat_main_dhu_rates = calculateDhuRateHours($assembly_row_dhu_hours['COAT'], $coat_main[0]['hours'] ?? [], $work_hours);
$coat_mtm_dhu_rates = calculateDhuRateHours($assembly_row_dhu_hours['COAT MTM'], $coat_mtm[0]['hours'] ?? [], $work_hours);
$knit_dhu_rates = calculateDhuRateHours($assembly_row_dhu_hours['KNIT'], $knit_col['hours'] ?? [], $work_hours);

// Pre-compute assembly-wide hourly DHU and the weighted daily rate.
$assembly_total_defects = 0;
$assembly_total_pcs = 0;
$assembly_hourly_dhu_avg = [];
for ($h = 1; $h <= $work_hours; $h++) {
    $hour_defects = 0;
    $hour_pcs = 0;
    foreach ($assembly_main as $col) {
        $row_name = strtoupper(trim($col['name']));
        $hour_defects += (float)($assembly_row_dhu_hours[$row_name][$h] ?? 0);
        $hour_pcs += (float)($col['hours'][$h - 1] ?? 0);
    }
    $assembly_total_defects += $hour_defects;
    $assembly_total_pcs += $hour_pcs;
    $assembly_hourly_dhu_avg[$h] = round(calculateDhuRate($hour_defects, $hour_pcs), 1);
}
$assembly_avg_dhu = round(calculateDhuRate($assembly_total_defects, $assembly_total_pcs), 1);

// Shirt and Trouser main use Assembly line DHU when available, with the division value as fallback.
$charts_shirt_main = computeCharts($shirt_main, $work_hours, $shirt_main_dhu_rates);

// Shirt MTM — use SHIRT MTM's per-hour DHU
$charts_shirt_mtm = computeCharts($shirt_mtm, $work_hours, $shirt_mtm_dhu_rates);

$charts_trouser_main = computeCharts($trouser_main, $work_hours, $trouser_main_dhu_rates);

// Trouser MTM — TROUSER MTM per-hour DHU
$charts_trouser_mtm = computeCharts($trouser_mtm, $work_hours, $trouser_mtm_dhu_rates);

// Coat main — COAT per-hour DHU
$charts_coat_main = computeCharts($coat_main, $work_hours, $coat_main_dhu_rates);

// Coat MTM — COAT MTM per-hour DHU
$charts_coat_mtm = computeCharts($coat_mtm, $work_hours, $coat_mtm_dhu_rates);

$stats_shirt_main = computeStats($shirt_main);
$stats_shirt_mtm = computeStats($shirt_mtm);
$stats_trouser_main = computeStats($trouser_main);
$stats_trouser_mtm = computeStats($trouser_mtm);
$stats_coat_main = computeStats($coat_main);
$stats_coat_mtm = computeStats($coat_mtm);
$stats_assembly = computeStats($assembly_main);

// KPI card value = TTI SAM/Pcs from the Assembly table row (e.g. 29)
function kpiSamValue($row) {
    $v = (float)($row['ttl_sam_pc'] ?? 0);
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
}
$kpi_sam_shirt_main   = kpiSamValue($asm_shirt ?? []);
$kpi_sam_shirt_mtm    = kpiSamValue($shirt_mtm_comp ?? []);
$kpi_sam_trouser_main = kpiSamValue($asm_tr ?? []);
$kpi_sam_trouser_mtm  = kpiSamValue($trouser_mtm_comp ?? []);

// Pre-compute assembly charts using per-hour DHU rates.
$assembly_dhu_chart = [];
for ($h = 1; $h <= $work_hours; $h++) {
    $assembly_dhu_chart[$h] = $assembly_hourly_dhu_avg[$h] ?? 0;
}
$assembly_charts = computeCharts($assembly_main, $work_hours, $assembly_dhu_chart);

$knit_charts = computeCharts([$knit_col], $work_hours, $knit_dhu_rates);
$knit_stats = computeStats([$knit_col]);

// ============================================================
// DHU rate = defects / (production + defects) * 100.
// ============================================================
function dhuEff($dayTotal, $dhuHours, $work_hours) {
    $sum = 0;
    for ($h = 1; $h <= $work_hours; $h++) $sum += (float)($dhuHours[$h] ?? 0);
    return ['total' => round($sum, 1), 'eff' => round(calculateDhuRate($sum, $dayTotal), 1)];
}
$shirt_mtm_dhu_eff   = dhuEff($shirt_mtm[0]['pcs'] ?? 0,   $assembly_row_dhu_hours['SHIRT MTM'] ?? [],   $work_hours);
$trouser_mtm_dhu_eff = dhuEff($trouser_mtm[0]['pcs'] ?? 0, $assembly_row_dhu_hours['TROUSER MTM'] ?? [], $work_hours);
$coat_main_dhu_eff   = dhuEff($coat_main[0]['pcs'] ?? 0,   $assembly_row_dhu_hours['COAT'] ?? [],        $work_hours);
$coat_mtm_dhu_eff    = dhuEff($coat_mtm[0]['pcs'] ?? 0,    $assembly_row_dhu_hours['COAT MTM'] ?? [],    $work_hours);
$knit_dhu_eff        = dhuEff($knit_col['pcs'] ?? 0,       $assembly_row_dhu_hours['KNIT'] ?? [],        $work_hours);
$shirt_mtm_dhu_avg = $shirt_mtm_dhu_eff['eff'];
$trouser_mtm_dhu_avg = $trouser_mtm_dhu_eff['eff'];
$coat_main_dhu_avg = $coat_main_dhu_eff['eff'];
$coat_mtm_dhu_avg = $coat_mtm_dhu_eff['eff'];
$knit_dhu_avg = $knit_dhu_eff['eff'];

$assembly_dhu_eff = [
    'total' => round($assembly_total_defects, 1),
    'eff' => round(calculateDhuRate($assembly_total_defects, $assembly_total_pcs), 1)
];

$display_date = date('M d, Y');
$chart_labels = range(1, $work_hours);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kiosk Analytics - Hameedia</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #217346; --primary-dark: #1a5c3a;
            --text: #1a2332; --text-dark: #0d1a2b; --steel: #6b7a8f;
            --border-radius: 12px;
            --shadow: 0 4px 20px rgba(0,0,0,0.08);
            --glass-border: rgba(255,255,255,0.3);
            --glass-bg: rgba(255,255,255,0.15);
            --good: #28a745; --bad: #dc3545; --warning: #ffc107; --amber: #f57c00;
            --dhu-color: #e74c3c;
        }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 50%, #a5d6a7 100%); min-height: 100vh; color: var(--text); }
        
        .kiosk-header {
            position: fixed; top: 0; left: 0; right: 0; z-index: 100;
            background: var(--glass-bg);
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            padding: 10px 30px; display: flex; justify-content: space-between;
            align-items: center; border-bottom: 1px solid var(--glass-border);
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }
        .kiosk-header .logo { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 18px; color: var(--primary-dark); }
        .kiosk-header .logo > span { color: #111; font: 900 21px "Times New Roman", Times, serif; letter-spacing: .3px; }
        .kiosk-header .logo a { display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit; }
        .kiosk-header .logo-icon { width: 40px; height: 40px; border-radius: 10px; object-fit: cover; }
        .kiosk-nav { display: flex; gap: 8px; align-items: center; }
        .kiosk-nav button {
            padding: 7px 16px; border: none; border-radius: 10px;
            background: transparent; color: var(--steel); font-weight: 600;
            font-size: 14px; cursor: pointer; transition: all 0.3s; font-family: 'Inter', sans-serif;
            display: flex; align-items: center; gap: 6px;
        }
        .kiosk-nav button:hover { color: var(--primary); background: rgba(33, 115, 70, 0.08); }
        .kiosk-nav button.active { background: var(--primary); color: #fff; box-shadow: 0 4px 15px rgba(33, 115, 70, 0.3); }
        .kiosk-nav button:hover { background: rgba(255,255,255,0.9); }
        .kiosk-nav button.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .kiosk-nav .back-link {
            margin-left: 12px;
            padding: 8px 20px;
            border: 2px solid var(--glass-border);
            border-radius: 10px;
            background: rgba(255,255,255,0.6);
            color: var(--text-dark);
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s;
        }
        .kiosk-nav .back-link:hover { background: rgba(255,255,255,0.9); }
        .kiosk-clock { font-size: 14px; font-weight: 600; color: var(--text-dark); display: flex; align-items: center; gap: 8px; }
        .live-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--good); animation: blink 1.5s infinite; }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

        .kiosk-content { padding-top: 80px; padding-bottom: 60px; }
        
        .screen-section {
            padding: 20px 30px;
            margin-bottom: 30px;
            page-break-inside: avoid;
        }
        
        .screen-title {
            font-size: 22px; font-weight: 800; color: var(--text-dark);
            margin-bottom: 16px; padding: 12px 20px; background: rgba(255,255,255,0.4);
            border-radius: 12px; border-left: 6px solid var(--primary);
        }
        
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
        .stat-card {
            background: var(--glass-bg); backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border); border-radius: var(--border-radius);
            padding: 16px; box-shadow: var(--shadow); text-align: center;
        }
        .stat-card .number { font-size: 28px; font-weight: 900; color: var(--primary); }
        .stat-card .number.dhu { color: var(--dhu-color); }
        .stat-card .number.eff { color: var(--amber); }
        .stat-card .label { font-size: 13px; font-weight: 600; color: var(--steel); margin-top: 4px; }

        .dashboard-table {
            width: 100%; border-collapse: collapse; font-size: 12px;
            background: var(--glass-bg); backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border); border-radius: var(--border-radius);
            overflow: hidden; box-shadow: var(--shadow); margin-bottom: 20px;
        }
        .dashboard-table th { background: rgba(255,255,255,0.3); padding: 8px 10px; text-align: center; font-weight: 700; color: var(--text-dark); border: 1px solid var(--glass-border); font-size: 11px; }
        .dashboard-table td { padding: 6px 10px; text-align: center; border: 1px solid var(--glass-border); font-weight: 500; font-size: 11px; }
        .dashboard-table .header-row td { background: rgba(33,115,70,0.15); font-weight: 700; font-size: 12px; }
        .dashboard-table .total-row td { background: rgba(33,150,243,0.08); font-weight: 700; }
        .dashboard-table .loss-row td { background: rgba(220,53,69,0.08); font-weight: 700; }
        .dashboard-table .dhu-col { background: rgba(220,53,69,0.12) !important; color: var(--dhu-color); }
        .dashboard-table .match-col { background: rgba(108,117,125,0.1) !important; }
        .dashboard-table .assembly-col { background: rgba(23,162,184,0.08) !important; }
        .eff-good { color: var(--good); font-weight: 700; }
        .eff-bad { color: var(--bad); font-weight: 700; }
        .eff-avg { color: var(--warning); font-weight: 700; }

        .chart-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .chart-card {
            background: var(--glass-bg); backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border); border-radius: var(--border-radius);
            padding: 16px; box-shadow: var(--shadow);
        }
        .chart-card h4 { font-size: 14px; font-weight: 700; color: var(--text-dark); margin-bottom: 10px; text-align: center; padding-bottom: 6px; border-bottom: 2px solid var(--primary); }
        .chart-card .chart-wrapper { position: relative; height: 220px; }
        .chart-card .chart-wrapper canvas { width: 100% !important; height: 100% !important; }
        .chart-full { grid-column: 1 / -1; }
        .chart-full .chart-wrapper { height: 250px; }

                @media (max-width: 1024px) {
            .chart-grid { grid-template-columns: 1fr; }
            .chart-full { grid-column: 1; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 768px) {
            .kiosk-header {
                position: sticky; top: 0;
                flex-wrap: wrap; justify-content: center;
                gap: 8px; padding: 10px 12px;
            }
            .kiosk-header .logo { font-size: 16px; flex-wrap: wrap; justify-content: center; }
            .kiosk-nav { flex-wrap: wrap; justify-content: center; gap: 4px; }
            .kiosk-nav button { padding: 6px 12px; font-size: 13px; }
            .kiosk-clock { font-size: 13px; flex-wrap: wrap; justify-content: center; }
            .kiosk-content { padding-top: 12px; }
            .screen-section { padding: 12px; }
            .screen-title { font-size: 17px; padding: 10px 14px; }
            .stat-card .number { font-size: 22px; }
            .dashboard-table { display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; white-space: nowrap; }
        }

        @media (max-width: 480px) {
            .stats-row { grid-template-columns: 1fr 1fr; gap: 8px; }
            .chart-card .chart-wrapper { height: 200px; }
        }

        @media (max-width: 768px) {
            .kiosk-header {
                position: sticky; top: 0;
                flex-wrap: wrap; justify-content: center;
                gap: 8px; padding: 10px 12px;
            }
            .kiosk-header .logo { font-size: 16px; flex-wrap: wrap; justify-content: center; }
            .kiosk-nav { flex-wrap: wrap; justify-content: center; gap: 4px; }
            .kiosk-nav button { padding: 6px 12px; font-size: 13px; }
            .kiosk-clock { font-size: 13px; flex-wrap: wrap; justify-content: center; }
            .kiosk-content { padding-top: 12px; }
            .screen-section { padding: 12px; }
            .screen-title { font-size: 17px; padding: 10px 14px; }
            .stat-card .number { font-size: 22px; }
            .dashboard-table { display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; white-space: nowrap; }
        }

        @media (max-width: 480px) {
            .stats-row { grid-template-columns: 1fr 1fr; gap: 8px; }
            .chart-card .chart-wrapper { height: 200px; }
        }
            .stats-row:has(.dhu-eff-card) { grid-template-columns: repeat(5, 1fr); }
        @media (max-width: 1024px) { .stats-row:has(.dhu-eff-card) { grid-template-columns: repeat(2, 1fr); } }
    </style>
</head>
<body>
    <div class="kiosk-header">
        <div class="logo">
            <img src="assets/images/logo.png" alt="H" class="logo-icon" style="object-fit:cover;">
            <span>HAMEEDIA — Analytics</span>
            <a href="dashboard.php"
   style="margin-left:16px;padding:6px 16px;border:2px solid var(--glass-border);border-radius:10px;background:#4caf50;color:var(--text-dark);font-weight:700;font-size:13px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:background 0.2s ease, transform 0.15s ease;"
   onmouseover="this.style.background='#3d8b40'; this.style.transform='translateY(-1px)';"
   onmouseout="this.style.background='#4caf50'; this.style.transform='translateY(0)';">
   ← Back
</a>
        </div>
        <div class="kiosk-nav">
            <button class="<?php echo $selected_division == 1 ? 'active' : ''; ?>" onclick="switchDivision(1)">👔 Shirt</button>
            <button class="<?php echo $selected_division == 2 ? 'active' : ''; ?>" onclick="switchDivision(2)">👖 Trouser</button>
            <button class="<?php echo $selected_division == 3 ? 'active' : ''; ?>" onclick="switchDivision(3)">🧥 Coat</button>
            <button class="<?php echo $selected_division == 7 ? 'active' : ''; ?>" onclick="switchDivision(7)">🏭 Assembly</button>
        </div>
        <div class="kiosk-clock">
            <span class="live-dot"></span>
            <span id="kiosk-clock"><?php echo date('g:i A'); ?></span>
            <span>|</span>
            <span><?php echo $display_date; ?></span>
        </div>
    </div>

    <div class="kiosk-content" id="kioskContent">
        
        <?php if ($selected_division == 1): ?>
        <!-- ============================================================ -->
        <!-- SCREEN 1: SHIRT MAIN -->
        <!-- ============================================================ -->
        <div class="screen-section" id="screen-shirt-main">
            <div class="screen-title">👔 SHIRT - MAIN</div>
            
            <div class="stats-row">
                <div class="stat-card"><div class="number"><?php echo number_format($stats_shirt_main['total_pcs']); ?></div><div class="label">Total Production</div></div>
                <div class="stat-card"><div class="number eff"><?php echo $kpi_sam_shirt_main; ?></div><div class="label">Avg SAM Efficiency</div></div>
                <div class="stat-card"><div class="number dhu"><?php echo number_format($shirt_main_dhu_eff['eff'], 1); ?>%</div><div class="label">Avg DHU</div></div>
                <div class="stat-card"><div class="number"><?php echo $work_hours; ?></div><div class="label">Work Hours</div></div>
            </div>
            
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="min-width:100px;">DASH BOARD</th>
                        <?php foreach ($shirt_main as $col): 
                            $cls = '';
                            if ($col['type'] == 'match_out') $cls = 'match-col';
                            elseif ($col['type'] == 'assembly') $cls = 'assembly-col';
                        ?>
                        <th colspan="2" class="<?php echo $cls; ?>"><?php echo htmlspecialchars($col['name']); ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2" class="dhu-col">DHU %</th>
                    </tr>
                    <tr><?php foreach ($shirt_main as $col): ?><th>Pcs</th><th>Eff</th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-BUDGET</td>
                        <?php foreach ($shirt_main as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-ON ROLL</td>
                        <?php foreach ($shirt_main as $col): ?><td colspan="2"><?php echo $col['type'] === 'component' ? number_format($col['direct_on_roll']) : '—'; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-PRESENT</td>
                        <?php foreach ($shirt_main as $col): ?><td colspan="2"><?php echo $col['type'] === 'component' ? number_format($col['direct_present']) : '—'; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">ABSENTEESM</td>
                        <?php foreach ($shirt_main as $col): ?><td colspan="2"><?php echo $col['type'] === 'component' ? number_format(calculateAbsenteeism($col['direct_on_roll'], $col['direct_present']), 1) . '%' : '—'; ?></td><?php endforeach; ?><td>—</td></tr>
                    <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                    <tr><td style="font-weight:700;"><?php echo $h; ?></td>
                        <?php foreach ($shirt_main as $col): 
                            $pcs = $col['hours'][$h - 1] ?? 0;
                            $eff = $col['hour_eff'][$h - 1] ?? 0;
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td><?php echo number_format($pcs, 0); ?></td>
                        <td class="<?php echo $ec; ?>"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td><?php echo number_format($shirt_main_dhu_rates[$h] ?? 0, 1); ?>%</td>
                    </tr>
                    <?php endfor; ?>
                    <tr class="total-row"><td style="font-weight:700;">Average</td>
                        <?php foreach ($shirt_main as $col): 
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td style="font-weight:700;"><?php echo number_format($col['pcs'], 0); ?></td>
                        <td class="<?php echo $ec; ?>" style="font-weight:700;"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td style="font-weight:700;color:var(--dhu-color);"><?php echo number_format($shirt_main_dhu_eff['eff'], 1); ?>%</td>
                    </tr>
                    <tr class="loss-row"><td style="font-weight:700;">PROFIT</td>
                        <?php foreach ($shirt_main as $col): $p = round($col['profit']); ?>
                        <td colspan="2" style="font-weight:700; color:<?php echo $p >= 0 ? '#28a745' : '#dc3545'; ?>;">LKR <?php echo number_format($p); ?></td>
                        <?php endforeach; ?><td>—</td></tr>
                </tbody>
            </table>
            
            <div class="chart-grid">
                <div class="chart-card"><h4>📈 PRODUCTION - HOURLY</h4><div class="chart-wrapper"><canvas id="c1-shirt-main"></canvas></div></div>
                <div class="chart-card"><h4>📉 D.H.U - HOURLY</h4><div class="chart-wrapper"><canvas id="c2-shirt-main"></canvas></div></div>
            </div>
        </div>
        
        <!-- ============================================================ -->
        <!-- SCREEN 2: SHIRT MTM -->
        <!-- ============================================================ -->
        <div class="screen-section" id="screen-shirt-mtm">
            <div class="screen-title">👔 SHIRT - MTM</div>
            
            <div class="stats-row">
                <div class="stat-card"><div class="number"><?php echo number_format($stats_shirt_mtm['total_pcs']); ?></div><div class="label">Total Production</div></div>
                <div class="stat-card"><div class="number eff"><?php echo $kpi_sam_shirt_mtm; ?></div><div class="label">Avg SAM Efficiency</div></div>
                <div class="stat-card"><div class="number dhu"><?php echo number_format($shirt_mtm_dhu_avg, 1); ?>%</div><div class="label">Avg DHU</div></div>
                <div class="stat-card dhu-eff-card"><div class="number dhu"><?php echo number_format($shirt_mtm_dhu_eff['total'], 0); ?></div><div class="label">Total Defects</div></div>
                <div class="stat-card"><div class="number"><?php echo $work_hours; ?></div><div class="label">Work Hours</div></div>
            </div>
            
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="min-width:100px;">DASH BOARD</th>
                        <?php foreach ($shirt_mtm as $col): ?>
                        <th colspan="2" class="assembly-col"><?php echo htmlspecialchars($col['name']); ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2" class="dhu-col">DHU %</th>
                    </tr>
                    <tr><?php foreach ($shirt_mtm as $col): ?><th>Pcs</th><th>Eff</th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-BUDGET</td>
                        <?php foreach ($shirt_mtm as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-PRESENT</td>
                        <?php foreach ($shirt_mtm as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">ABSENTEESM</td>
                        <?php foreach ($shirt_mtm as $col): ?><td colspan="2">0%</td><?php endforeach; ?><td>—</td></tr>
                    <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                    <tr><td style="font-weight:700;"><?php echo $h; ?></td>
                        <?php foreach ($shirt_mtm as $col): 
                            $pcs = $col['hours'][$h - 1] ?? 0;
                            $eff = $col['hour_eff'][$h - 1] ?? 0;
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td><?php echo number_format($pcs, 0); ?></td>
                        <td class="<?php echo $ec; ?>"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td><?php echo number_format($shirt_mtm_dhu_rates[$h] ?? 0, 1); ?>%</td>
                    </tr>
                    <?php endfor; ?>
                    <tr class="total-row"><td style="font-weight:700;">Average</td>
                        <?php foreach ($shirt_mtm as $col): 
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td style="font-weight:700;"><?php echo number_format($col['pcs'], 0); ?></td>
                        <td class="<?php echo $ec; ?>" style="font-weight:700;"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td style="font-weight:700;color:var(--dhu-color);"><?php echo number_format($shirt_mtm_dhu_avg, 1); ?>%</td>
                    </tr>
                    <tr class="loss-row"><td style="font-weight:700;">PROFIT</td>
                        <?php foreach ($shirt_mtm as $col): $p = round($col['profit']); ?>
                        <td colspan="2" style="font-weight:700; color:<?php echo $p >= 0 ? '#28a745' : '#dc3545'; ?>;">LKR <?php echo number_format($p); ?></td>
                        <?php endforeach; ?><td>—</td></tr>
                </tbody>
            </table>
            
            <div class="chart-grid">
                <div class="chart-card"><h4>📈 PRODUCTION - HOURLY</h4><div class="chart-wrapper"><canvas id="c1-shirt-mtm"></canvas></div></div>
                <div class="chart-card"><h4>📉 D.H.U - HOURLY</h4><div class="chart-wrapper"><canvas id="c2-shirt-mtm"></canvas></div></div>
            </div>
        </div>
        
        <!-- ============================================================ -->
        <!-- SCREEN 3: SHIRT COMPARE -->
        <!-- ============================================================ -->
        <div class="screen-section" id="screen-shirt-compare">
            <div class="screen-title">🔄 SHIRT - COMPARE: MAIN vs MTM</div>
            
            <div class="chart-grid">
                <div class="chart-card"><h4>📈 PRODUCTION - HOURLY (Main vs MTM)</h4><div class="chart-wrapper"><canvas id="cmp-shirt-prod"></canvas></div></div>
                <div class="chart-card"><h4>⚡ EFFICIENCY - HOURLY (Main vs MTM)</h4><div class="chart-wrapper"><canvas id="cmp-shirt-eff"></canvas></div></div>
                <div class="chart-card"><h4>📉 D.H.U - HOURLY (Main vs MTM)</h4><div class="chart-wrapper"><canvas id="cmp-shirt-dhu"></canvas></div></div>
                <div class="chart-card chart-full"><h4>📊 MONTH PRODUCE PCS - TREND</h4><div class="chart-wrapper"><canvas id="cmp-shirt-trend-prod"></canvas></div></div>
                <div class="chart-card chart-full"><h4>📉 MONTH D.H.U - TREND</h4><div class="chart-wrapper"><canvas id="cmp-shirt-trend-dhu"></canvas></div></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($selected_division == 2): ?>
        <!-- ============================================================ -->
        <!-- TROUSER: 3 Screens stacked -->
        <!-- ============================================================ -->
        <div class="screen-section" id="screen-trouser-main">
            <div class="screen-title">👖 TROUSER - MAIN</div>
            
            <div class="stats-row">
                <div class="stat-card"><div class="number"><?php echo number_format($stats_trouser_main['total_pcs']); ?></div><div class="label">Total Production</div></div>
                <div class="stat-card"><div class="number eff"><?php echo $kpi_sam_trouser_main; ?></div><div class="label">Avg SAM Efficiency</div></div>
                <div class="stat-card"><div class="number dhu"><?php echo number_format($trouser_main_dhu_eff['eff'], 1); ?>%</div><div class="label">Avg DHU</div></div>
                <div class="stat-card"><div class="number"><?php echo $work_hours; ?></div><div class="label">Work Hours</div></div>
            </div>
            
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="min-width:100px;">DASH BOARD</th>
                        <?php foreach ($trouser_main as $col): 
                            $cls = '';
                            if ($col['type'] == 'match_out') $cls = 'match-col';
                            elseif ($col['type'] == 'assembly') $cls = 'assembly-col';
                        ?>
                        <th colspan="2" class="<?php echo $cls; ?>"><?php echo htmlspecialchars($col['name']); ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2" class="dhu-col">DHU %</th>
                    </tr>
                    <tr><?php foreach ($trouser_main as $col): ?><th>Pcs</th><th>Eff</th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-BUDGET</td>
                        <?php foreach ($trouser_main as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-ON ROLL</td>
                        <?php foreach ($trouser_main as $col): ?><td colspan="2"><?php echo $col['type'] === 'component' ? number_format($col['direct_on_roll']) : '—'; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-PRESENT</td>
                        <?php foreach ($trouser_main as $col): ?><td colspan="2"><?php echo $col['type'] === 'component' ? number_format($col['direct_present']) : '—'; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">ABSENTEESM</td>
                        <?php foreach ($trouser_main as $col): ?><td colspan="2"><?php echo $col['type'] === 'component' ? number_format(calculateAbsenteeism($col['direct_on_roll'], $col['direct_present']), 1) . '%' : '—'; ?></td><?php endforeach; ?><td>—</td></tr>
                    <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                    <tr><td style="font-weight:700;"><?php echo $h; ?></td>
                        <?php foreach ($trouser_main as $col): 
                            $pcs = $col['hours'][$h - 1] ?? 0;
                            $eff = $col['hour_eff'][$h - 1] ?? 0;
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td><?php echo number_format($pcs, 0); ?></td>
                        <td class="<?php echo $ec; ?>"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td><?php echo number_format($trouser_main_dhu_rates[$h] ?? 0, 1); ?>%</td>
                    </tr>
                    <?php endfor; ?>
                    <tr class="total-row"><td style="font-weight:700;">Average</td>
                        <?php foreach ($trouser_main as $col): 
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td style="font-weight:700;"><?php echo number_format($col['pcs'], 0); ?></td>
                        <td class="<?php echo $ec; ?>" style="font-weight:700;"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td style="font-weight:700;color:var(--dhu-color);"><?php echo number_format($trouser_main_dhu_eff['eff'], 1); ?>%</td>
                    </tr>
                    <tr class="loss-row"><td style="font-weight:700;">PROFIT</td>
                        <?php foreach ($trouser_main as $col): $p = round($col['profit']); ?>
                        <td colspan="2" style="font-weight:700; color:<?php echo $p >= 0 ? '#28a745' : '#dc3545'; ?>;">LKR <?php echo number_format($p); ?></td>
                        <?php endforeach; ?><td>—</td></tr>
                </tbody>
            </table>
            
            <div class="chart-grid">
                <div class="chart-card"><h4>📈 PRODUCTION - HOURLY</h4><div class="chart-wrapper"><canvas id="c1-trouser-main"></canvas></div></div>
                <div class="chart-card"><h4>📉 D.H.U - HOURLY</h4><div class="chart-wrapper"><canvas id="c2-trouser-main"></canvas></div></div>
            </div>
        </div>
        
        <div class="screen-section" id="screen-trouser-mtm">
            <div class="screen-title">👖 TROUSER - MTM</div>
            
            <div class="stats-row">
                <div class="stat-card"><div class="number"><?php echo number_format($stats_trouser_mtm['total_pcs']); ?></div><div class="label">Total Production</div></div>
                <div class="stat-card"><div class="number eff"><?php echo $kpi_sam_trouser_mtm; ?></div><div class="label">Avg SAM Efficiency</div></div>
                <div class="stat-card"><div class="number dhu"><?php echo number_format($trouser_mtm_dhu_avg, 1); ?>%</div><div class="label">Avg DHU</div></div>
                <div class="stat-card dhu-eff-card"><div class="number dhu"><?php echo number_format($trouser_mtm_dhu_eff['total'], 0); ?></div><div class="label">Total Defects</div></div>
                <div class="stat-card"><div class="number"><?php echo $work_hours; ?></div><div class="label">Work Hours</div></div>
            </div>
            
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="min-width:100px;">DASH BOARD</th>
                        <?php foreach ($trouser_mtm as $col): ?>
                        <th colspan="2" class="assembly-col"><?php echo htmlspecialchars($col['name']); ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2" class="dhu-col">DHU %</th>
                    </tr>
                    <tr><?php foreach ($trouser_mtm as $col): ?><th>Pcs</th><th>Eff</th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-BUDGET</td>
                        <?php foreach ($trouser_mtm as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-PRESENT</td>
                        <?php foreach ($trouser_mtm as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">ABSENTEESM</td>
                        <?php foreach ($trouser_mtm as $col): ?><td colspan="2">0%</td><?php endforeach; ?><td>—</td></tr>
                    <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                    <tr><td style="font-weight:700;"><?php echo $h; ?></td>
                        <?php foreach ($trouser_mtm as $col): 
                            $pcs = $col['hours'][$h - 1] ?? 0;
                            $eff = $col['hour_eff'][$h - 1] ?? 0;
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td><?php echo number_format($pcs, 0); ?></td>
                        <td class="<?php echo $ec; ?>"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td><?php echo number_format($trouser_mtm_dhu_rates[$h] ?? 0, 1); ?>%</td>
                    </tr>
                    <?php endfor; ?>
                    <tr class="total-row"><td style="font-weight:700;">Average</td>
                        <?php foreach ($trouser_mtm as $col): 
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td style="font-weight:700;"><?php echo number_format($col['pcs'], 0); ?></td>
                        <td class="<?php echo $ec; ?>" style="font-weight:700;"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td style="font-weight:700;color:var(--dhu-color);"><?php echo number_format($trouser_mtm_dhu_avg, 1); ?>%</td>
                    </tr>
                    <tr class="loss-row"><td style="font-weight:700;">PROFIT</td>
                        <?php foreach ($trouser_mtm as $col): $p = round($col['profit']); ?>
                        <td colspan="2" style="font-weight:700; color:<?php echo $p >= 0 ? '#28a745' : '#dc3545'; ?>;">LKR <?php echo number_format($p); ?></td>
                        <?php endforeach; ?><td>—</td></tr>
                </tbody>
            </table>
            
            <div class="chart-grid">
                <div class="chart-card"><h4>📈 PRODUCTION - HOURLY</h4><div class="chart-wrapper"><canvas id="c1-trouser-mtm"></canvas></div></div>
                <div class="chart-card"><h4>📉 D.H.U - HOURLY</h4><div class="chart-wrapper"><canvas id="c2-trouser-mtm"></canvas></div></div>
            </div>
        </div>
        
        <div class="screen-section" id="screen-trouser-compare">
            <div class="screen-title">🔄 TROUSER - COMPARE: MAIN vs MTM</div>
            
            <div class="chart-grid">
                <div class="chart-card"><h4>📈 PRODUCTION - HOURLY (Main vs MTM)</h4><div class="chart-wrapper"><canvas id="cmp-trouser-prod"></canvas></div></div>
                <div class="chart-card"><h4>⚡ EFFICIENCY - HOURLY (Main vs MTM)</h4><div class="chart-wrapper"><canvas id="cmp-trouser-eff"></canvas></div></div>
                <div class="chart-card"><h4>📉 D.H.U - HOURLY (Main vs MTM)</h4><div class="chart-wrapper"><canvas id="cmp-trouser-dhu"></canvas></div></div>
                <div class="chart-card chart-full"><h4>📊 MONTH PRODUCE PCS - TREND</h4><div class="chart-wrapper"><canvas id="cmp-trouser-trend-prod"></canvas></div></div>
                <div class="chart-card chart-full"><h4>📉 MONTH D.H.U - TREND</h4><div class="chart-wrapper"><canvas id="cmp-trouser-trend-dhu"></canvas></div></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($selected_division == 3): ?>
        <!-- ============================================================ -->
        <!-- SCREEN 1: COAT MAIN -->
        <!-- ============================================================ -->
        <div class="screen-section" id="screen-coat-main">
            <div class="screen-title">🧥 COAT - MAIN</div>
            
            <div class="stats-row">
                <div class="stat-card"><div class="number"><?php echo number_format($stats_coat_main['total_pcs']); ?></div><div class="label">Total Production</div></div>
                <div class="stat-card"><div class="number eff"><?php echo $stats_coat_main['avg_eff']; ?>%</div><div class="label">Avg SAM Efficiency</div></div>
                <div class="stat-card"><div class="number dhu"><?php echo number_format($coat_main_dhu_avg, 1); ?>%</div><div class="label">Avg DHU</div></div>
                <div class="stat-card dhu-eff-card"><div class="number dhu"><?php echo number_format($coat_main_dhu_eff['total'], 0); ?></div><div class="label">Total Defects</div></div>
                <div class="stat-card"><div class="number"><?php echo $work_hours; ?></div><div class="label">Work Hours</div></div>
            </div>
            
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="min-width:100px;">DASH BOARD</th>
                        <?php foreach ($coat_main as $col): ?>
                        <th colspan="2" class="assembly-col"><?php echo htmlspecialchars($col['name']); ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2" class="dhu-col">DHU %</th>
                    </tr>
                    <tr><?php foreach ($coat_main as $col): ?><th>Pcs</th><th>Eff</th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-BUDGET</td>
                        <?php foreach ($coat_main as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-PRESENT</td>
                        <?php foreach ($coat_main as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">ABSENTEESM</td>
                        <?php foreach ($coat_main as $col): ?><td colspan="2">0%</td><?php endforeach; ?><td>—</td></tr>
                    <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                    <tr><td style="font-weight:700;"><?php echo $h; ?></td>
                        <?php foreach ($coat_main as $col): 
                            $pcs = $col['hours'][$h - 1] ?? 0;
                            $eff = $col['hour_eff'][$h - 1] ?? 0;
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td><?php echo number_format($pcs, 0); ?></td>
                        <td class="<?php echo $ec; ?>"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td><?php echo number_format($coat_main_dhu_rates[$h] ?? 0, 1); ?>%</td>
                    </tr>
                    <?php endfor; ?>
                    <tr class="total-row"><td style="font-weight:700;">Average</td>
                        <?php foreach ($coat_main as $col): 
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td style="font-weight:700;"><?php echo number_format($col['pcs'], 0); ?></td>
                        <td class="<?php echo $ec; ?>" style="font-weight:700;"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td style="font-weight:700;color:var(--dhu-color);"><?php echo number_format($coat_main_dhu_avg, 1); ?>%</td>
                    </tr>
                    <tr class="loss-row"><td style="font-weight:700;">PROFIT</td>
                        <?php foreach ($coat_main as $col): $p = round($col['profit']); ?>
                        <td colspan="2" style="font-weight:700; color:<?php echo $p >= 0 ? '#28a745' : '#dc3545'; ?>;">LKR <?php echo number_format($p); ?></td>
                        <?php endforeach; ?><td>—</td></tr>
                </tbody>
            </table>
            
            <div class="chart-grid">
                <div class="chart-card"><h4>📈 PRODUCTION - HOURLY</h4><div class="chart-wrapper"><canvas id="c1-coat-main"></canvas></div></div>
                <div class="chart-card"><h4>📉 D.H.U - HOURLY</h4><div class="chart-wrapper"><canvas id="c2-coat-main"></canvas></div></div>
            </div>
        </div>
        
        <!-- ============================================================ -->
        <!-- SCREEN 2: COAT MTM -->
        <!-- ============================================================ -->
        <div class="screen-section" id="screen-coat-mtm">
            <div class="screen-title">🧥 COAT - MTM</div>
            
            <div class="stats-row">
                <div class="stat-card"><div class="number"><?php echo number_format($stats_coat_mtm['total_pcs']); ?></div><div class="label">Total Production</div></div>
                <div class="stat-card"><div class="number eff"><?php echo $stats_coat_mtm['avg_eff']; ?>%</div><div class="label">Avg SAM Efficiency</div></div>
                <div class="stat-card"><div class="number dhu"><?php echo number_format($coat_mtm_dhu_avg, 1); ?>%</div><div class="label">Avg DHU</div></div>
                <div class="stat-card dhu-eff-card"><div class="number dhu"><?php echo number_format($coat_mtm_dhu_eff['total'], 0); ?></div><div class="label">Total Defects</div></div>
                <div class="stat-card"><div class="number"><?php echo $work_hours; ?></div><div class="label">Work Hours</div></div>
            </div>
            
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="min-width:100px;">DASH BOARD</th>
                        <?php foreach ($coat_mtm as $col): ?>
                        <th colspan="2" class="assembly-col"><?php echo htmlspecialchars($col['name']); ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2" class="dhu-col">DHU %</th>
                    </tr>
                    <tr><?php foreach ($coat_mtm as $col): ?><th>Pcs</th><th>Eff</th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-BUDGET</td>
                        <?php foreach ($coat_mtm as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-PRESENT</td>
                        <?php foreach ($coat_mtm as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">ABSENTEESM</td>
                        <?php foreach ($coat_mtm as $col): ?><td colspan="2">0%</td><?php endforeach; ?><td>—</td></tr>
                    <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                    <tr><td style="font-weight:700;"><?php echo $h; ?></td>
                        <?php foreach ($coat_mtm as $col): 
                            $pcs = $col['hours'][$h - 1] ?? 0;
                            $eff = $col['hour_eff'][$h - 1] ?? 0;
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td><?php echo number_format($pcs, 0); ?></td>
                        <td class="<?php echo $ec; ?>"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td><?php echo number_format($coat_mtm_dhu_rates[$h] ?? 0, 1); ?>%</td>
                    </tr>
                    <?php endfor; ?>
                    <tr class="total-row"><td style="font-weight:700;">Average</td>
                        <?php foreach ($coat_mtm as $col): 
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td style="font-weight:700;"><?php echo number_format($col['pcs'], 0); ?></td>
                        <td class="<?php echo $ec; ?>" style="font-weight:700;"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <td style="font-weight:700;color:var(--dhu-color);"><?php echo number_format($coat_mtm_dhu_avg, 1); ?>%</td>
                    </tr>
                    <tr class="loss-row"><td style="font-weight:700;">PROFIT</td>
                        <?php foreach ($coat_mtm as $col): $p = round($col['profit']); ?>
                        <td colspan="2" style="font-weight:700; color:<?php echo $p >= 0 ? '#28a745' : '#dc3545'; ?>;">LKR <?php echo number_format($p); ?></td>
                        <?php endforeach; ?><td>—</td></tr>
                </tbody>
            </table>
            
            <div class="chart-grid">
                <div class="chart-card"><h4>📈 PRODUCTION - HOURLY</h4><div class="chart-wrapper"><canvas id="c1-coat-mtm"></canvas></div></div>
                <div class="chart-card"><h4>📉 D.H.U - HOURLY</h4><div class="chart-wrapper"><canvas id="c2-coat-mtm"></canvas></div></div>
            </div>
        </div>
        
        <!-- ============================================================ -->
        <!-- SCREEN 3: KNIT -->
        <!-- ============================================================ -->
        <div class="screen-section" id="screen-knit">
            <div class="screen-title">🧶 KNIT</div>
            
            <div class="stats-row">
                <div class="stat-card"><div class="number"><?php echo number_format($knit_stats['total_pcs']); ?></div><div class="label">Total Production</div></div>
                <div class="stat-card"><div class="number eff"><?php echo $knit_stats['avg_eff']; ?>%</div><div class="label">Avg SAM Efficiency</div></div>
                <div class="stat-card"><div class="number dhu"><?php echo number_format($knit_dhu_avg, 1); ?>%</div><div class="label">Avg DHU</div></div>
                <div class="stat-card dhu-eff-card"><div class="number dhu"><?php echo number_format($knit_dhu_eff['total'], 0); ?></div><div class="label">Total Defects</div></div>
                <div class="stat-card"><div class="number"><?php echo $work_hours; ?></div><div class="label">Work Hours</div></div>
            </div>
            
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="min-width:100px;">DASH BOARD</th>
                        <th colspan="2" class="assembly-col">KNIT</th>
                        <th rowspan="2" class="dhu-col">DHU %</th>
                    </tr>
                    <tr><th>Pcs</th><th>Eff</th></tr>
                </thead>
                <tbody>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-BUDGET</td>
                        <td colspan="2"><?php echo $knit_col['carder']; ?></td><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-PRESENT</td>
                        <td colspan="2"><?php echo $knit_col['carder']; ?></td><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">ABSENTEESM</td>
                        <td colspan="2">0%</td><td>—</td></tr>
                    <?php for ($h = 1; $h <= $work_hours; $h++): 
                        $pcs = $knit_col['hours'][$h - 1] ?? 0;
                        $eff = $knit_col['hour_eff'][$h - 1] ?? 0;
                        $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                    ?>
                    <tr><td style="font-weight:700;"><?php echo $h; ?></td>
                        <td><?php echo number_format($pcs, 0); ?></td>
                        <td class="<?php echo $ec; ?>"><?php echo number_format($eff, 1); ?>%</td>
                        <td><?php echo number_format($knit_dhu_rates[$h] ?? 0, 1); ?>%</td>
                    </tr>
                    <?php endfor; ?>
                    <tr class="total-row"><td style="font-weight:700;">Average</td>
                        <td style="font-weight:700;"><?php echo number_format($knit_col['pcs'], 0); ?></td>
                        <td style="font-weight:700;"><?php echo number_format($knit_col['eff'], 1); ?>%</td>
                        <td style="font-weight:700;color:var(--dhu-color);"><?php echo number_format($knit_dhu_avg, 1); ?>%</td>
                    </tr>
                    <tr class="loss-row"><td style="font-weight:700;">PROFIT</td>
                        <?php $kp = round($knit_col['profit']); ?>
                        <td colspan="2" style="font-weight:700; color:<?php echo $kp >= 0 ? '#28a745' : '#dc3545'; ?>;">LKR <?php echo number_format($kp); ?></td>
                        <td>—</td></tr>
                </tbody>
            </table>
            
            <div class="chart-grid">
                <div class="chart-card"><h4>📈 PRODUCTION - HOURLY</h4><div class="chart-wrapper"><canvas id="c1-knit"></canvas></div></div>
                <div class="chart-card"><h4>📉 D.H.U - HOURLY</h4><div class="chart-wrapper"><canvas id="c2-knit"></canvas></div></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($selected_division == 7): ?>
        <div class="screen-section">
            <div class="screen-title">🏭 ASSEMBLY</div>
            <div class="stats-row">
                <div class="stat-card"><div class="number"><?php echo number_format($stats_assembly['total_pcs']); ?></div><div class="label">Total Production</div></div>
                <div class="stat-card"><div class="number eff"><?php echo $stats_assembly['avg_eff']; ?>%</div><div class="label">Avg SAM Efficiency</div></div>
                <!-- BLOCK 4: Avg DHU stat card (per-hour average across all 7 rows) -->
                <div class="stat-card"><div class="number dhu"><?php echo number_format($assembly_avg_dhu, 1); ?>%</div><div class="label">Avg DHU</div></div>
                <div class="stat-card dhu-eff-card"><div class="number dhu"><?php echo number_format($assembly_dhu_eff['total'], 0); ?></div><div class="label">Total Defects</div></div>
                <div class="stat-card"><div class="number"><?php echo $work_hours; ?></div><div class="label">Work Hours</div></div>
            </div>
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="min-width:100px;">DASH BOARD</th>
                        <?php foreach ($assembly_main as $col): ?>
                        <th colspan="2"><?php echo htmlspecialchars($col['name']); ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2" class="dhu-col">DHU %</th>
                    </tr>
                    <tr><?php foreach ($assembly_main as $col): ?><th>Pcs</th><th>Eff</th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-BUDGET</td>
                        <?php foreach ($assembly_main as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">DIRECTS-PRESENT</td>
                        <?php foreach ($assembly_main as $col): ?><td colspan="2"><?php echo $col['carder']; ?></td><?php endforeach; ?><td>—</td></tr>
                    <tr class="header-row"><td style="text-align:left;">ABSENTEESM</td>
                        <?php foreach ($assembly_main as $col): ?><td colspan="2">0%</td><?php endforeach; ?><td>—</td></tr>
                    <?php for ($h = 1; $h <= $work_hours; $h++): ?>
                    <tr><td style="font-weight:700;"><?php echo $h; ?></td>
                        <?php foreach ($assembly_main as $col): 
                            $pcs = $col['hours'][$h - 1] ?? 0;
                            $eff = $col['hour_eff'][$h - 1] ?? 0;
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td><?php echo number_format($pcs, 0); ?></td>
                        <td class="<?php echo $ec; ?>"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <!-- BLOCK 2: Assembly DHU column (per-hour average across all 7 rows) -->
                        <td><?php echo number_format($assembly_hourly_dhu_avg[$h] ?? 0, 1); ?>%</td>
                    </tr>
                    <?php endfor; ?>
                    <tr class="total-row"><td style="font-weight:700;">Average</td>
                        <?php foreach ($assembly_main as $col): 
                            $eff = $col['eff'];
                            $ec = $eff >= 90 ? 'eff-good' : ($eff >= 70 ? 'eff-avg' : 'eff-bad');
                        ?>
                        <td style="font-weight:700;"><?php echo number_format($col['pcs'], 0); ?></td>
                        <td class="<?php echo $ec; ?>" style="font-weight:700;"><?php echo number_format($eff, 1); ?>%</td>
                        <?php endforeach; ?>
                        <!-- BLOCK 3: Assembly Average DHU (overall average excluding zeros) -->
                        <td style="font-weight:700;color:var(--dhu-color);"><?php echo number_format($assembly_avg_dhu, 1); ?>%</td>
                    </tr>
                    <tr class="loss-row"><td style="font-weight:700;">PROFIT</td>
                        <?php foreach ($assembly_main as $col): $p = round($col['profit']); ?>
                        <td colspan="2" style="font-weight:700; color:<?php echo $p >= 0 ? '#28a745' : '#dc3545'; ?>;">LKR <?php echo number_format($p); ?></td>
                        <?php endforeach; ?><td>—</td></tr>
                </tbody>
            </table>
            
            <div class="chart-grid">
                <div class="chart-card"><h4>📈 PRODUCTION - HOURLY</h4><div class="chart-wrapper"><canvas id="c1-assembly"></canvas></div></div>
                <div class="chart-card"><h4>📉 D.H.U - HOURLY</h4><div class="chart-wrapper"><canvas id="c2-assembly"></canvas></div></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        function updateClock() {
            const now = new Date();
                        let hours = now.getHours();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            const minutes = String(now.getMinutes()).padStart(2, '0');
            document.getElementById('kiosk-clock').textContent = hours + ':' + minutes + ' ' + ampm;
        }
        updateClock();
        setInterval(updateClock, 60000);

        function switchDivision(id) {
            window.location.href = 'analytics.php?division=' + id;
        }

        const labels = <?php echo json_encode($chart_labels); ?>;
        const shirtMainProd = <?php echo json_encode($charts_shirt_main['production']); ?>;
        const shirtMainEff = <?php echo json_encode($charts_shirt_main['efficiency']); ?>;
        const shirtMainDHU = <?php echo json_encode($charts_shirt_main['dhu']); ?>;
        const shirtMtmProd = <?php echo json_encode($charts_shirt_mtm['production']); ?>;
        const shirtMtmEff = <?php echo json_encode($charts_shirt_mtm['efficiency']); ?>;
        const shirtMtmDHU = <?php echo json_encode($charts_shirt_mtm['dhu']); ?>;
        const trouserMainProd = <?php echo json_encode($charts_trouser_main['production']); ?>;
        const trouserMainEff = <?php echo json_encode($charts_trouser_main['efficiency']); ?>;
        const trouserMainDHU = <?php echo json_encode($charts_trouser_main['dhu']); ?>;
        const trouserMtmProd = <?php echo json_encode($charts_trouser_mtm['production']); ?>;
        const trouserMtmEff = <?php echo json_encode($charts_trouser_mtm['efficiency']); ?>;
        const trouserMtmDHU = <?php echo json_encode($charts_trouser_mtm['dhu']); ?>;

        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.font.size = 11;
        Chart.defaults.color = '#6b7a8f';

        function mkProdChart(id, prod, efficiency, label1) {
            const el = document.getElementById(id);
            if (!el) return;
            new Chart(el, {
                type: 'bar',
                data: { labels: labels, datasets: [
                    { label: label1, data: prod, backgroundColor: 'rgba(33,115,70,0.7)', borderColor: 'rgba(33,115,70,1)', borderWidth: 2, borderRadius: 4, order: 1 },
                    { label: 'Eff %', data: efficiency, type: 'line', borderColor: 'rgba(245,124,0,1)', backgroundColor: 'rgba(245,124,0,0.1)', borderWidth: 2, pointRadius: 4, tension: 0.3, fill: true, yAxisID: 'y1', order: 0 }
                ]},
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, position: 'left' }, y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { callback: function(value) { return value + '%'; } } } } }
            });
        }
        function mkDHUChart(id, dhu) {
            const el = document.getElementById(id);
            if (!el) return;
            new Chart(el, {
                type: 'bar',
                data: { labels: labels, datasets: [{ label: 'DHU %', data: dhu, backgroundColor: 'rgba(231,76,60,0.7)', borderColor: 'rgba(231,76,60,1)', borderWidth: 2, borderRadius: 4 }] },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
            });
        }
        function mkCompareProd(id, prod1, prod2) {
            const el = document.getElementById(id);
            if (!el) return;
            new Chart(el, {
                type: 'line',
                data: { labels: labels, datasets: [
                    { label: 'Main', data: prod1, borderColor: 'rgba(33,115,70,1)', backgroundColor: 'rgba(33,115,70,0.15)', fill: true, tension: 0.3, pointRadius: 4, borderWidth: 2 },
                    { label: 'MTM', data: prod2, borderColor: 'rgba(23,162,184,1)', backgroundColor: 'rgba(23,162,184,0.15)', fill: true, tension: 0.3, pointRadius: 4, borderWidth: 2 }
                ]},
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
            });
        }
        function mkCompareEff(id, eff1, eff2) {
            const el = document.getElementById(id);
            if (!el) return;
            new Chart(el, {
                type: 'line',
                data: { labels: labels, datasets: [
                    { label: 'Main Eff %', data: eff1, borderColor: 'rgba(33,115,70,1)', backgroundColor: 'rgba(33,115,70,0.15)', fill: true, tension: 0.3, pointRadius: 4, borderWidth: 2 },
                    { label: 'MTM Eff %', data: eff2, borderColor: 'rgba(23,162,184,1)', backgroundColor: 'rgba(23,162,184,0.15)', fill: true, tension: 0.3, pointRadius: 4, borderWidth: 2 }
                ]},
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { callback: function(value) { return value + '%'; } } } } }
            });
        }
        function mkCompareDHU(id, dhu1, dhu2) {
            const el = document.getElementById(id);
            if (!el) return;
            new Chart(el, {
                type: 'line',
                data: { labels: labels, datasets: [
                    { label: 'Main DHU', data: dhu1, borderColor: 'rgba(231,76,60,1)', backgroundColor: 'rgba(231,76,60,0.15)', fill: true, tension: 0.3, pointRadius: 4, borderWidth: 2 },
                    { label: 'MTM DHU', data: dhu2, borderColor: 'rgba(245,124,0,1)', backgroundColor: 'rgba(245,124,0,0.15)', fill: true, tension: 0.3, pointRadius: 4, borderWidth: 2 }
                ]},
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
            });
        }

        <?php if ($selected_division == 1): ?>
        mkProdChart('c1-shirt-main', shirtMainProd, shirtMainEff, 'Pcs');
        mkDHUChart('c2-shirt-main', shirtMainDHU);
        mkProdChart('c1-shirt-mtm', shirtMtmProd, shirtMtmEff, 'Pcs');
        mkDHUChart('c2-shirt-mtm', shirtMtmDHU);
        mkCompareProd('cmp-shirt-prod', shirtMainProd, shirtMtmProd);
        mkCompareEff('cmp-shirt-eff', shirtMainEff, shirtMtmEff);
        mkCompareDHU('cmp-shirt-dhu', shirtMainDHU, shirtMtmDHU);
        mkCompareProd('cmp-shirt-trend-prod', shirtMainProd, shirtMtmProd);
        mkCompareDHU('cmp-shirt-trend-dhu', shirtMainDHU, shirtMtmDHU);
        <?php elseif ($selected_division == 2): ?>
        mkProdChart('c1-trouser-main', trouserMainProd, trouserMainEff, 'Pcs');
        mkDHUChart('c2-trouser-main', trouserMainDHU);
        mkProdChart('c1-trouser-mtm', trouserMtmProd, trouserMtmEff, 'Pcs');
        mkDHUChart('c2-trouser-mtm', trouserMtmDHU);
        mkCompareProd('cmp-trouser-prod', trouserMainProd, trouserMtmProd);
        mkCompareEff('cmp-trouser-eff', trouserMainEff, trouserMtmEff);
        mkCompareDHU('cmp-trouser-dhu', trouserMainDHU, trouserMtmDHU);
        mkCompareProd('cmp-trouser-trend-prod', trouserMainProd, trouserMtmProd);
        mkCompareDHU('cmp-trouser-trend-dhu', trouserMainDHU, trouserMtmDHU);
        <?php elseif ($selected_division == 3): ?>
        mkProdChart('c1-coat-main', <?php echo json_encode($charts_coat_main['production']); ?>, <?php echo json_encode($charts_coat_main['efficiency']); ?>, 'Pcs');
        mkDHUChart('c2-coat-main', <?php echo json_encode($charts_coat_main['dhu']); ?>);
        mkProdChart('c1-coat-mtm', <?php echo json_encode($charts_coat_mtm['production']); ?>, <?php echo json_encode($charts_coat_mtm['efficiency']); ?>, 'Pcs');
        mkDHUChart('c2-coat-mtm', <?php echo json_encode($charts_coat_mtm['dhu']); ?>);
        mkProdChart('c1-knit', <?php echo json_encode($knit_charts['production']); ?>, <?php echo json_encode($knit_charts['efficiency']); ?>, 'Pcs');
        mkDHUChart('c2-knit', <?php echo json_encode($knit_charts['dhu']); ?>);
        <?php elseif ($selected_division == 7): ?>
        mkProdChart('c1-assembly', <?php echo json_encode($assembly_charts['production']); ?>, <?php echo json_encode($assembly_charts['efficiency']); ?>, 'Pcs');
                mkDHUChart('c2-assembly', <?php echo json_encode($assembly_charts['dhu']); ?>);

        // Shirt / Trouser MAIN: DHU removed (card, column, DHU chart)
        ['screen-shirt-main', 'screen-trouser-main'].forEach(function(id) {
            var sec = document.getElementById(id);
            if (!sec) return;
            sec.querySelectorAll('.stat-card').forEach(function(c) {
                if (c.querySelector('.number.dhu')) c.remove();
            });
            var sr = sec.querySelector('.stats-row');
            if (sr) sr.style.gridTemplateColumns = 'repeat(3, 1fr)';
            sec.querySelectorAll('.dashboard-table thead tr:first-child, .dashboard-table tbody tr').forEach(function(tr) {
                if (tr.lastElementChild) tr.lastElementChild.remove();
            });
            var dhuChart = sec.querySelector('canvas[id^="c2-"]');
            if (dhuChart && dhuChart.closest('.chart-card')) dhuChart.closest('.chart-card').remove();
        });
        <?php endif; ?>

        // ============================================================
        // CONTINUOUS SCROLL (infinite, top → bottom → top)
        // ============================================================
        <?php if (in_array($selected_division, [1, 2, 3, 7])): ?>
        (function() {
            // ============================================================
            // SCROLL SETTINGS — Adjust these to change speed
            // PIXELS_PER_SECOND:
            //   10  = very slow
            //   20  = slow (recommended)
            //   30  = medium
            //   50  = fast
            // ============================================================
            const PIXELS_PER_SECOND = 20;   // ← Change this to control speed
            const PAUSE_AT_BOTTOM = 3000;   // ms to pause at bottom
            const PAUSE_AT_TOP = 2000;      // ms to pause at top before scrolling again
            
            let scrolling = false;
            let paused = false;
            let lastTime = 0;
            let accumulator = 0;
            
            function startScroll() {
                scrolling = true;
                paused = false;
                lastTime = performance.now();
                requestAnimationFrame(step);
            }
            
            function step(currentTime) {
                if (!scrolling) return;
                if (paused) {
                    lastTime = currentTime;
                    requestAnimationFrame(step);
                    return;
                }
                
                const deltaMs = currentTime - lastTime;
                lastTime = currentTime;
                
                // Calculate pixels to move this frame
                accumulator += (PIXELS_PER_SECOND * deltaMs) / 1000;
                
                // Only scroll when accumulated >= 1 pixel (browsers round down)
                if (accumulator >= 1) {
                    const pixelsToMove = Math.floor(accumulator);
                    accumulator -= pixelsToMove;
                    
                    const maxScroll = document.documentElement.scrollHeight - window.innerHeight;
                    const currentY = window.pageYOffset;
                    
                    if (currentY >= maxScroll - 1) {
                        // Reached bottom — pause, jump to top
                        paused = true;
                        accumulator = 0;
                        setTimeout(() => {
                            window.scrollTo(0, 0);
                            setTimeout(() => {
                                paused = false;
                                lastTime = performance.now();
                                requestAnimationFrame(step);
                            }, PAUSE_AT_TOP);
                        }, PAUSE_AT_BOTTOM);
                        return;
                    }
                    
                    window.scrollBy(0, pixelsToMove);
                }
                
                requestAnimationFrame(step);
            }
            
            window.addEventListener('load', () => {
                setTimeout(startScroll, 1500);
            });
            
            // Pause on user interaction
            ['mousedown', 'wheel', 'touchstart', 'keydown'].forEach(evt => {
                window.addEventListener(evt, () => {
                    paused = true;
                    setTimeout(() => { paused = false; lastTime = performance.now(); }, 3000);
                }, { passive: true });
            });
        })();
        <?php endif; ?>
    </script>
        <script>
        // LIVE UPDATE (smart screen): every 10s check data_version.php,
        // reload silently only when data changed, and keep the scroll position.
        (function () {
            var PAGE_DATE  = '<?php echo $selected_date; ?>';
            var FOLLOW_TODAY = <?php echo isset($_GET['date']) ? 'false' : 'true'; ?>;
            var POLL_MS = 10000;
            var lastVersion = null;
            var KEY = 'kioskScrollY';

            function safeGet() { try { return sessionStorage.getItem(KEY); } catch (e) { return null; } }
            function safeSet(v) { try { sessionStorage.setItem(KEY, v); } catch (e) {} }
            function safeDel() { try { sessionStorage.removeItem(KEY); } catch (e) {} }

            var saved = safeGet();
            if (saved !== null) {
                safeDel();
                var y = parseInt(saved, 10) || 0;
                window.addEventListener('load', function () { window.scrollTo(0, y); });
            }

            function liveReload() {
                safeSet(String(window.pageYOffset || 0));
                window.location.reload();
            }

            function checkVersion() {
                fetch('data_version.php?date=' + encodeURIComponent(PAGE_DATE) + '&_=' + Date.now(), {
                    cache: 'no-store',
                    credentials: 'same-origin'
                })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (!d || !d.success) return;
                    if (FOLLOW_TODAY && d.today && d.today !== PAGE_DATE) { liveReload(); return; }
                    if (lastVersion === null) { lastVersion = d.version; return; }
                    if (d.version !== lastVersion) { liveReload(); }
                })
                .catch(function () {});
            }

            checkVersion();
            setInterval(checkVersion, POLL_MS);

            // Fallback hard refresh every hour (keeps scroll position)
            setTimeout(liveReload, 3600000);
        })();
    </script>
</body>
</html>