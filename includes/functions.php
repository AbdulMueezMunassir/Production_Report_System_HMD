<?php
// includes/functions.php - COMPLETE VERSION WITH ASSEMBLY FORMULAS
require_once __DIR__ . '/../config/database.php';

// Constants
const DEFAULT_WORKING_HOURS = 11;
const MAX_WORKING_HOURS = 11;
const DEFAULT_TARGET = 0.90;

function normalizeWorkingHours($h) { 
    return max(1, min(MAX_WORKING_HOURS, (int)$h)); 
}

function safeDivide($n, $d) { 
    return ($d == 0) ? 0.0 : (float)$n / (float)$d; 
}

// ==============================================================
// EXACT EXCEL FORMULAS
// ==============================================================

// Day Forecast = Carder * Working Hours * 60 * Target / SMV
function calculateDayForecast($carder, $workingHours, $smv, $target = DEFAULT_TARGET) {
    if ($smv <= 0) return 0;
    return ($carder * $workingHours * 60 * $target) / $smv;
}

// Available Minutes = Plan Hours * Worked Hours * 60
function calculateAvailableMinutes($planHours, $workedHours) {
    return max(0, $planHours) * max(0, $workedHours) * 60;
}

// Plan Minutes = Day Forecast * SMV
function calculatePlanMinutes($dayForecast, $smv) {
    return $dayForecast * $smv;
}

// Plan Eff = Plan Minutes / Available Minutes
function calculatePlanEfficiency($planMinutes, $availableMinutes) {
    return safeDivide($planMinutes, $availableMinutes);
}

// 100% Target = (Plan Hours / Unit Carder) * 60
function calculateTarget100($planHours, $unitCarder) {
    return safeDivide($planHours, $unitCarder) * 60;
}

// Day Ttl = SUM(hour_1 to hour_working)
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

// Acvd Eff = (Ern Minutes / Available Minutes) * 100
function calculateAchievedEfficiency($ernMinutes, $availableMinutes) {
    return safeDivide($ernMinutes, $availableMinutes) * 100;
}

// ==============================================================
// ASSEMBLY FORMULAS
// ==============================================================

// Assembly Target = (Working Hours * 600 / Manpower) * 80%
function calculateAssemblyTarget($workingHours, $manpower) {
    if ($manpower <= 0) return 0;
    return ($workingHours * 600 / $manpower) * 0.80;
}

// Assembly Available Minutes = Working Hours * Efficiency * 60
function calculateAssemblyAvailable($workingHours, $efficiency) {
    return $workingHours * $efficiency * 60;
}

// Assembly Target Minutes = Target * Manpower
function calculateAssemblyTargetMinutes($target, $manpower) {
    return $target * $manpower;
}

// Assembly Capacity Efficiency = Target Minutes / Available Minutes
function calculateAssemblyCapacityEff($targetMinutes, $availableMinutes) {
    return safeDivide($targetMinutes, $availableMinutes);
}

// Assembly Target Per Hour = (Working Hours / Manpower) * 60
function calculateAssemblyTargetPerHour($workingHours, $manpower) {
    return safeDivide($workingHours, $manpower) * 60;
}

// Assembly Efficiency = (Total Standard Minutes / Available Minutes) * (Efficiency / Worked Hours)
function calculateAssemblyEfficiency($totalStdMinutes, $availableMinutes, $efficiency, $workedHours) {
    if ($availableMinutes <= 0 || $workedHours <= 0) return 0;
    return ($totalStdMinutes / $availableMinutes) * ($efficiency / $workedHours);
}

// ==============================================================
// DATABASE FUNCTIONS
// ==============================================================

function getDivisions($conn) {
    $stmt = $conn->query("SELECT * FROM divisions WHERE is_active = TRUE ORDER BY display_order");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

function saveReportData($conn, $data, $workingHours) {
    for ($h = 1; $h <= MAX_WORKING_HOURS; $h++) {
        if ($h > $workingHours) $data["hour_$h"] = 0;
    }
    
    $check = $conn->prepare("SELECT id FROM production_reports WHERE devition_id = ? AND unit_id = ? AND report_date = ?");
    $check->execute([$data['devition_id'], $data['unit_id'], $data['report_date']]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $sql = "UPDATE production_reports SET 
                ttl_sam_pc=?, unit_smv=?, day_forecast=?, unit_carder=?, 
                plan_hours=?, worked_hours=?, available_minutes=?, 
                plan_minutes=?, plan_eff=?, target_100=?, 
                hour_1=?, hour_2=?, hour_3=?, hour_4=?, hour_5=?, 
                hour_6=?, hour_7=?, hour_8=?, hour_9=?, hour_10=?, hour_11=?, 
                day_total=?, acvd_eff=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        return $stmt->execute([
            $data['ttl_sam_pc'], $data['unit_smv'], $data['day_forecast'], 
            $data['unit_carder'], $data['plan_hours'], $data['worked_hours'],
            $data['available_minutes'], $data['plan_minutes'], $data['plan_eff'], 
            $data['target_100'],
            $data['hour_1'], $data['hour_2'], $data['hour_3'], $data['hour_4'], 
            $data['hour_5'], $data['hour_6'], $data['hour_7'], $data['hour_8'], 
            $data['hour_9'], $data['hour_10'], $data['hour_11'],
            $data['day_total'], $data['acvd_eff'], $existing['id']
        ]);
    } else {
        $sql = "INSERT INTO production_reports (
            report_date, devition_id, unit_id, ttl_sam_pc, unit_smv, 
            day_forecast, unit_carder, plan_hours, worked_hours, 
            available_minutes, plan_minutes, plan_eff, target_100, 
            hour_1, hour_2, hour_3, hour_4, hour_5, hour_6, 
            hour_7, hour_8, hour_9, hour_10, hour_11, 
            day_total, acvd_eff
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        return $stmt->execute([
            $data['report_date'], $data['devition_id'], $data['unit_id'],
            $data['ttl_sam_pc'], $data['unit_smv'], $data['day_forecast'], 
            $data['unit_carder'], $data['plan_hours'], $data['worked_hours'],
            $data['available_minutes'], $data['plan_minutes'], $data['plan_eff'], 
            $data['target_100'],
            $data['hour_1'], $data['hour_2'], $data['hour_3'], $data['hour_4'], 
            $data['hour_5'], $data['hour_6'], $data['hour_7'], $data['hour_8'], 
            $data['hour_9'], $data['hour_10'], $data['hour_11'],
            $data['day_total'], $data['acvd_eff']
        ]);
    }
}

// ==============================================================
// STATISTICS FUNCTIONS
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
        'efficiency' => $count > 0 ? round($totalEff / $count, 0) : 0,
        'has_data' => $setupUnits > 0
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

function getReportsList($conn, $date = null, $division_id = null) {
    $sql = "SELECT r.*, d.name as division_name 
            FROM production_reports r 
            JOIN divisions d ON r.devition_id = d.id 
            WHERE 1=1";
    $params = [];
    
    if ($date) {
        $sql .= " AND r.report_date = ?";
        $params[] = $date;
    }
    if ($division_id && $division_id !== 'all') {
        $sql .= " AND r.devition_id = ?";
        $params[] = $division_id;
    }
    
    $sql .= " ORDER BY r.report_date DESC, r.id DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>