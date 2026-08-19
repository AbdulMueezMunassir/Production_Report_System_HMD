<?php
// includes/functions.php - COMPLETE VERSION (FIXED has_data warning)
// Do NOT use getDB() inside this file unless it is wrapped inside a function call

const DEFAULT_WORKING_HOURS = 10;
const MAX_WORKING_HOURS = 11;
const DEFAULT_TARGET = 0.90;

function normalizeWorkingHours($h) { return max(1, min(MAX_WORKING_HOURS, (int)$h)); }
function safeDivide($n, $d) { return ($d == 0) ? 0.0 : (float)$n / (float)$d; }

// ==============================================================
// EXACT EXCEL FORMULAS
// ==============================================================

// Day Forecast = Carder * Working Hours * 60 * Target / SMV
function calculateDayForecast($carder, $workingHours, $smv, $target = DEFAULT_TARGET) {
    if ($smv <= 0) return 0;
    return ($carder * $workingHours * 60 * $target) / $smv;
}

// Available Minutes = Carder * Plan Hours * 60
function calculateAvailableMinutes($carder, $planHours) {
    return max(0, $carder) * max(0, $planHours) * 60;
}

// Plan Minutes = Day Forecast * SMV
function calculatePlanMinutes($dayForecast, $smv) {
    return $dayForecast * $smv;
}

// Plan Eff = Plan Minutes / Available Minutes
function calculatePlanEfficiency($planMinutes, $availableMinutes) {
    return safeDivide($planMinutes, $availableMinutes);
}

// 100% Target = (Carder / SMV) * 60
function calculateTarget100($carder, $smv) {
    return safeDivide($carder, $smv) * 60;
}

// Day Ttl = SUM(hour_1 to hour_selected)
function calculateDayTotal($data, $workingHours) {
    $total = 0;
    for ($h = 1; $h <= $workingHours; $h++) {
        $total += (float)($data["hour_$h"] ?? 0);
    }
    return $total;
}

// Ern Minutes = Day Ttl * SMV
function calculateEarnedMinutes($dayTotal, $smv) {
    return $dayTotal * $smv;
}

// Acvd Eff = (Ern * Plan) / (Avail * Worked)
function calculateAchievedEfficiency($ernMinutes, $availMinutes, $planHours, $workedHours) {
    $denominator = $availMinutes * $workedHours;
    if ($denominator <= 0) return 0;
    return ($ernMinutes * $planHours) / $denominator;
}

// ==============================================================
// EXACT MATCH OUT FORMULAS
// ==============================================================

// Match Out SMV = SUM(component SMV)
function calculateMatchOutSMV($components, $conn, $division_id, $date) {
    $total = 0;
    foreach ($components as $comp) {
        if ($comp['is_match_out']) continue;
        $stmt = $conn->prepare("SELECT unit_smv FROM production_reports WHERE devition_id = ? AND unit_id = ? AND report_date = ?");
        $stmt->execute([$division_id, $comp['id'], $date]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        $total += (float)($data['unit_smv'] ?? 0);
    }
    return $total;
}

// Match Out Carder = SUM(component Carder)
function calculateMatchOutCarder($components, $conn, $division_id, $date) {
    $total = 0;
    foreach ($components as $comp) {
        if ($comp['is_match_out']) continue;
        $stmt = $conn->prepare("SELECT unit_carder FROM production_reports WHERE devition_id = ? AND unit_id = ? AND report_date = ?");
        $stmt->execute([$division_id, $comp['id'], $date]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        $total += (int)($data['unit_carder'] ?? 0);
    }
    return $total;
}

// Match Out Plan Hours = AVERAGE(component Plan Hours)
function calculateMatchOutPlanHours($components, $conn, $division_id, $date) {
    $hours = [];
    foreach ($components as $comp) {
        if ($comp['is_match_out']) continue;
        $stmt = $conn->prepare("SELECT plan_hours FROM production_reports WHERE devition_id = ? AND unit_id = ? AND report_date = ?");
        $stmt->execute([$division_id, $comp['id'], $date]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (isset($data['plan_hours'])) $hours[] = (float)$data['plan_hours'];
    }
    return !empty($hours) ? array_sum($hours) / count($hours) : 0;
}

// Match Out Worked Hours = AVERAGE(component Worked Hours)
function calculateMatchOutWorkedHours($components, $conn, $division_id, $date) {
    $hours = [];
    foreach ($components as $comp) {
        if ($comp['is_match_out']) continue;
        $stmt = $conn->prepare("SELECT worked_hours FROM production_reports WHERE devition_id = ? AND unit_id = ? AND report_date = ?");
        $stmt->execute([$division_id, $comp['id'], $date]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (isset($data['worked_hours'])) $hours[] = (float)$data['worked_hours'];
    }
    return !empty($hours) ? array_sum($hours) / count($hours) : 0;
}

// Match Out Hourly Average = AVERAGE(component Hourly Values)
function calculateMatchOutAverage($compHours) {
    if (empty($compHours)) return 0;
    $sum = 0; $count = 0;
    foreach ($compHours as $val) { if (is_numeric($val)) { $sum += $val; $count++; } }
    return $count > 0 ? $sum / $count : 0;
}

// ==============================================================
// DATABASE FUNCTIONS (Call getDB() only inside these functions)
// ==============================================================

function getDateSettings($date) {
    require_once __DIR__ . '/../config/database.php';
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM report_date_settings WHERE report_date = ?");
    $stmt->execute([$date]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function saveDateSettings($date, $hours, $target, $userId) {
    require_once __DIR__ . '/../config/database.php';
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO report_date_settings (report_date, working_hours, target_efficiency, created_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE working_hours = VALUES(working_hours), target_efficiency = VALUES(target_efficiency)");
    return $stmt->execute([$date, normalizeWorkingHours($hours), $target, $userId]);
}

function getDivisions($conn) {
    return $conn->query("SELECT * FROM divisions WHERE is_active = TRUE ORDER BY display_order")->fetchAll(PDO::FETCH_ASSOC);
}

function getComponents($conn, $div_id) {
    $stmt = $conn->prepare("SELECT * FROM components WHERE division_id = ? ORDER BY display_order");
    $stmt->execute([$div_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getReportData($conn, $div_id, $unit_id, $date) {
    $stmt = $conn->prepare("SELECT * FROM production_reports WHERE devition_id = ? AND unit_id = ? AND report_date = ?");
    $stmt->execute([$div_id, $unit_id, $date]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function saveReportData($conn, $data, $workingHours, $targetEff) {
    for ($h = 1; $h <= MAX_WORKING_HOURS; $h++) {
        if ($h > $workingHours) $data["hour_$h"] = 0;
    }
    
    $check = $conn->prepare("SELECT id FROM production_reports WHERE devition_id = ? AND unit_id = ? AND report_date = ?");
    $check->execute([$data['devition_id'], $data['unit_id'], $data['report_date']]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $sql = "UPDATE production_reports SET ttl_sam_pc=?, unit_smv=?, day_forecast=?, unit_carder=?, plan_hours=?, worked_hours=?, available_minutes=?, plan_minutes=?, plan_eff=?, target_100=?, hour_1=?, hour_2=?, hour_3=?, hour_4=?, hour_5=?, hour_6=?, hour_7=?, hour_8=?, hour_9=?, hour_10=?, hour_11=?, day_total=?, acvd_eff=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        return $stmt->execute([
            $data['ttl_sam_pc'], $data['unit_smv'], $data['day_forecast'], $data['unit_carder'], $data['plan_hours'], $data['worked_hours'],
            $data['available_minutes'], $data['plan_minutes'], $data['plan_eff'], $data['target_100'],
            $data['hour_1'], $data['hour_2'], $data['hour_3'], $data['hour_4'], $data['hour_5'],
            $data['hour_6'], $data['hour_7'], $data['hour_8'], $data['hour_9'], $data['hour_10'], $data['hour_11'],
            $data['day_total'], $data['acvd_eff'], $existing['id']
        ]);
    } else {
        $sql = "INSERT INTO production_reports (report_date, devition_id, unit_id, ttl_sam_pc, unit_smv, day_forecast, unit_carder, plan_hours, worked_hours, available_minutes, plan_minutes, plan_eff, target_100, hour_1, hour_2, hour_3, hour_4, hour_5, hour_6, hour_7, hour_8, hour_9, hour_10, hour_11, day_total, acvd_eff) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        return $stmt->execute([
            $data['report_date'], $data['devition_id'], $data['unit_id'],
            $data['ttl_sam_pc'], $data['unit_smv'], $data['day_forecast'], $data['unit_carder'], $data['plan_hours'], $data['worked_hours'],
            $data['available_minutes'], $data['plan_minutes'], $data['plan_eff'], $data['target_100'],
            $data['hour_1'], $data['hour_2'], $data['hour_3'], $data['hour_4'], $data['hour_5'],
            $data['hour_6'], $data['hour_7'], $data['hour_8'], $data['hour_9'], $data['hour_10'], $data['hour_11'],
            $data['day_total'], $data['acvd_eff']
        ]);
    }
}

// ==============================================================
// STATISTICS FUNCTIONS (FIXED has_data warning)
// ==============================================================

function getDivisionStats($conn, $div_id, $date, $workingHours) {
    $components = getComponents($conn, $div_id);
    $totalUnits = 0;
    $setupUnits = 0;
    $totalEff = 0;
    $count = 0;
    
    foreach ($components as $comp) {
        if ($comp['is_match_out']) continue;
        $totalUnits++;
        $data = getReportData($conn, $div_id, $comp['id'], $date);
        if (!empty($data) && ($data['ttl_sam_pc'] ?? 0) > 0) {
            $setupUnits++;
            if ($data['acvd_eff'] > 0) {
                $totalEff += $data['acvd_eff'];
                $count++;
            }
        }
    }
    
    return [
        'total_units' => $totalUnits,
        'setup_units' => $setupUnits,
        'efficiency' => $count > 0 ? ($totalEff / $count) : 0,
        'has_data' => $setupUnits > 0 // FIXED: This key was missing
    ];
}

function getFactoryEfficiency($conn, $date, $workingHours) {
    $divisions = getDivisions($conn);
    $totalEff = 0;
    $count = 0;
    foreach ($divisions as $div) {
        $stats = getDivisionStats($conn, $div['id'], $date, $workingHours);
        if ($stats['efficiency'] > 0) {
            $totalEff += $stats['efficiency'];
            $count++;
        }
    }
    return $count > 0 ? round($totalEff / $count, 0) : 0;
}
?>