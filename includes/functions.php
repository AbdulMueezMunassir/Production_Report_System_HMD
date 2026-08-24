<?php
// includes/functions.php - COMPLETE WITH ALL FUNCTIONS
require_once __DIR__ . '/../config/database.php';

const TARGET_90 = 0.90;
const TARGET_80 = 0.80;

function safeDivide($n, $d) { 
    return ($d == 0) ? 0.0 : (float)$n / (float)$d; 
}

// ============================================================
// DETAIL ROW FORMULAS (Shirt/Trouser - 90% target)
// ============================================================
function calcDayForecast90($carder, $unitSmv) {
    if ($unitSmv <= 0 || $carder <= 0) return 0;
    return ($carder * 600 / $unitSmv) * TARGET_90;
}

function calcAvailableMinutes($carder, $planHours) {
    return $carder * $planHours * 60;
}

function calcPlanMinutes($dayForecast, $unitSmv) {
    return $dayForecast * $unitSmv;
}

function calcPlanEff($planMinutes, $availableMinutes) {
    return safeDivide($planMinutes, $availableMinutes);
}

function calcTarget100($carder, $unitSmv) {
    if ($unitSmv <= 0) return 0;
    return ($carder / $unitSmv) * 60;
}

function calcDayTotal($hoursData, $workHours) {
    $total = 0;
    for ($h = 1; $h <= $workHours; $h++) {
        $total += (float)($hoursData["hour_$h"] ?? 0);
    }
    return $total;
}

function calcEarnedMinutes($dayTotal, $unitSmv) {
    return $dayTotal * $unitSmv;
}

function calcAchievedEff90($earnedMinutes, $availableMinutes, $planHours, $workedHours) {
    if ($availableMinutes <= 0 || $planHours <= 0 || $workedHours <= 0) return 0;
    $denominator = ($availableMinutes / $planHours) * $workedHours;
    return safeDivide($earnedMinutes, $denominator);
}

// ============================================================
// ASSEMBLY ROW FORMULAS (80% target) - Rows 20-31
// ============================================================
function calcDayForecast80($carder, $sectionSmv) {
    if ($sectionSmv <= 0 || $carder <= 0) return 0;
    return ($carder * 600 / $sectionSmv) * TARGET_80;
}



function calcAssemblyEarnedMinutes($dayTotal, $ttlSamPc) {
    return $dayTotal * $ttlSamPc;
}

function calcAssemblyAchievedEff80($earnedMinutes, $availableMinutes, $planHours, $workedHours) {
    if ($availableMinutes <= 0 || $workedHours <= 0) return 0;
    return ($earnedMinutes / $availableMinutes) * ($planHours / $workedHours);
}

// ============================================================
// MATCH OUT FORMULAS - SHIRT (Row 8) & TROUSER (Row 14) ONLY
// ============================================================
function calculateMatchOutFixed($conn, $division_id, $date, $work_hours, $components = null) {
    if ($components === null) {
        $components = getComponents($conn, $division_id);
    }
    if (!is_array($components) || empty($components)) {
        return [];
    }
    
    $match = [
        'unit_smv' => 0,
        'unit_carder' => 0,
        'plan_hours' => 0,
        'worked_hours' => 0,
        'day_forecast' => 0,
        'available_minutes' => 0,
        'plan_minutes' => 0,
        'plan_eff' => 0,
        'target_100' => 0,
        'hours' => array_fill(1, $work_hours, 0),
        'day_total' => 0,
        'earned_minutes' => 0,
        'acvd_eff' => 0
    ];
    $count = 0;
    $hourSums = array_fill(1, $work_hours, 0);
    $planHoursSum = 0;
    $workedHoursSum = 0;

    foreach ($components as $comp) {
        if ($comp['is_match_out']) continue;
        $data = getReportData($conn, $division_id, $comp['id'], $date);
        if (!empty($data) && ($data['unit_smv'] ?? 0) > 0) {
            $count++;
            $match['unit_smv'] += (float)$data['unit_smv'];
            $match['unit_carder'] += (int)$data['unit_carder'];
            $planHoursSum += (float)$data['plan_hours'];
            $workedHoursSum += (float)$data['worked_hours'];
            for ($h = 1; $h <= $work_hours; $h++) {
                $hourSums[$h] += (float)($data["hour_$h"] ?? 0);
            }
        }
    }

    if ($count > 0) {
        $match['plan_hours'] = $planHoursSum / $count;
        $match['worked_hours'] = $workedHoursSum / $count;
        
        if ($match['unit_smv'] > 0 && $match['unit_carder'] > 0) {
            $match['day_forecast'] = calcDayForecast90($match['unit_carder'], $match['unit_smv']);
        }
        
        $match['available_minutes'] = calcAvailableMinutes($match['unit_carder'], $match['plan_hours']);
        $match['plan_minutes'] = calcPlanMinutes($match['day_forecast'], $match['unit_smv']);
        $match['plan_eff'] = calcPlanEff($match['plan_minutes'], $match['available_minutes']);
        $match['target_100'] = calcTarget100($match['unit_carder'], $match['unit_smv']);
        
        for ($h = 1; $h <= $work_hours; $h++) {
            $match['hours'][$h] = round($hourSums[$h] / $count, 0);
        }
        
        $match['day_total'] = array_sum($match['hours']);
        $match['earned_minutes'] = calcEarnedMinutes($match['day_total'], $match['unit_smv']);
        $match['acvd_eff'] = calcAchievedEff90(
            $match['earned_minutes'],
            $match['available_minutes'],
            $match['plan_hours'],
            $match['worked_hours']
        );
    }
    
    return $match;
}

// ============================================================
// ASSEMBLY LEAN TOTAL CALCULATION - Row 32
// ============================================================
function calculateLeanTotalAssembly($assemblyRows, $work_hours) {
    if (!is_array($assemblyRows) || empty($assemblyRows)) {
        return [
            'ttl_sam' => 0,
            'section_sam' => 0,
            'day_forecast' => 0,
            'assemble_carder' => 0,
            'plan_hours' => 10,
            'worked_hours' => 10,
            'available_minutes' => 0,
            'plan_minutes' => 0,
            'plan_eff' => 0.8,
            'target_100' => 0,
            'hours' => array_fill(1, $work_hours, 0),
            'day_total' => 0,
            'ern_minutes' => 0,
            'acvd_eff' => 0,
            'dhu' => 0
        ];
    }
    
    $lt = [
        'ttl_sam' => 0,
        'section_sam' => 0,
        'day_forecast' => 0,
        'assemble_carder' => 0,
        'plan_hours' => 10,
        'worked_hours' => 10,
        'available_minutes' => 0,
        'plan_minutes' => 0,
        'plan_eff' => 0.8,
        'target_100' => 0,
        'hours' => array_fill(1, $work_hours, 0),
        'day_total' => 0,
        'ern_minutes' => 0,
        'acvd_eff' => 0,
        'dhu' => 0
    ];
    $count = 0;
    $dhuTotal = 0;
    
    foreach ($assemblyRows as $row) {
        if (isset($row['ttl_sam_pc']) && $row['ttl_sam_pc'] > 0) {
            $count++;
            $lt['ttl_sam'] += $row['ttl_sam_pc'];
            $lt['section_sam'] += $row['unit_smv'];
            $lt['day_forecast'] += $row['day_forecast'];
            $lt['assemble_carder'] += $row['unit_carder'];
            $lt['available_minutes'] += $row['available_minutes'];
            $lt['plan_minutes'] += $row['plan_minutes'];
            $lt['target_100'] += $row['target_100'];
            for ($h = 1; $h <= $work_hours; $h++) {
                $lt['hours'][$h] += $row["hour_$h"] ?? 0;
            }
            if (($row['day_total'] ?? 0) > 0) {
                $dhuTotal += (($row['day_total'] / 100) * 5);
            }
        }
    }
    
    if ($count > 0) {
        $lt['ttl_sam'] = $lt['ttl_sam'] / $count;
        $lt['section_sam'] = $lt['section_sam'] / $count;
        $lt['available_minutes'] = $lt['available_minutes'] / $count;
        $lt['plan_minutes'] = $lt['plan_minutes'] / $count;
        $lt['target_100'] = $lt['target_100'] / $count;
        $lt['plan_hours'] = 10;
        $lt['worked_hours'] = 10;
        
        for ($h = 1; $h <= $work_hours; $h++) {
            $lt['hours'][$h] = round($lt['hours'][$h] / $count, 0);
        }
        $lt['day_total'] = array_sum($lt['hours']);
        $lt['ern_minutes'] = $lt['day_total'] * $lt['ttl_sam'];
        $lt['acvd_eff'] = ($lt['available_minutes'] > 0) ? ($lt['ern_minutes'] / $lt['available_minutes']) * ($lt['plan_hours'] / $lt['worked_hours']) : 0;
        $lt['dhu'] = ($lt['day_total'] > 0) ? round(($dhuTotal / $lt['day_total']) * 100, 1) : 0;
    }
    
    return $lt;
}

// ============================================================
// FACTORY GRAND TOTAL/AVERAGE - Row 35
// ============================================================
function calculateGrandTotalAssembly($assemblyRows, $matchOutTrouser, $matchOutShirt, $work_hours) {
    if (!is_array($assemblyRows) || empty($assemblyRows)) {
        return [
            'ttl_sam' => 0,
            'section_sam' => 0,
            'day_forecast' => 0,
            'assemble_carder' => 0,
            'plan_hours' => 10,
            'worked_hours' => 10,
            'available_minutes' => 0,
            'plan_minutes' => 0,
            'plan_eff' => 0,
            'target_100' => 0,
            'hours' => array_fill(1, $work_hours, 0),
            'day_total' => 0,
            'ern_minutes' => 0,
            'acvd_eff' => 0
        ];
    }
    
    $lt = calculateLeanTotalAssembly($assemblyRows, $work_hours);
    
    $shirtCarder = (!empty($matchOutShirt) && isset($matchOutShirt['unit_carder'])) ? $matchOutShirt['unit_carder'] : 0;
    $trouserCarder = (!empty($matchOutTrouser) && isset($matchOutTrouser['unit_carder'])) ? $matchOutTrouser['unit_carder'] : 0;
    
    $gt = [
        'ttl_sam' => $lt['ttl_sam'],
        'section_sam' => $lt['section_sam'],
        'day_forecast' => $lt['day_forecast'],
        'assemble_carder' => 0,
        'plan_hours' => 10,
        'worked_hours' => 10,
        'available_minutes' => 0,
        'plan_minutes' => 0,
        'plan_eff' => 0,
        'target_100' => 0,
        'hours' => array_fill(1, $work_hours, 0),
        'day_total' => 0,
        'ern_minutes' => 0,
        'acvd_eff' => 0
    ];
    
    $gt['assemble_carder'] = $lt['assemble_carder'] + $trouserCarder + $shirtCarder;
    $gt['available_minutes'] = (($gt['assemble_carder'] + $trouserCarder + $shirtCarder) * $gt['plan_hours']) * 60;
    
    $planMinSum = 0;
    foreach ($assemblyRows as $row) {
        $planMinSum += ($row['day_forecast'] ?? 0) * ($row['ttl_sam_pc'] ?? 0);
    }
    $gt['plan_minutes'] = $planMinSum;
    
    $gt['plan_eff'] = ($gt['available_minutes'] > 0) ? ($gt['plan_minutes'] / $gt['available_minutes']) : 0;
    $gt['target_100'] = ($gt['ttl_sam'] > 0) ? ($gt['assemble_carder'] / $gt['ttl_sam']) * 60 : 0;
    
    for ($h = 1; $h <= $work_hours; $h++) {
        $numerator = 0;
        foreach ($assemblyRows as $row) {
            $numerator += ($row["hour_$h"] ?? 0) * ($row['ttl_sam_pc'] ?? 0);
        }
        $gt['hours'][$h] = ($gt['assemble_carder'] * 1 * 60 > 0) ? $numerator / ($gt['assemble_carder'] * 1 * 60) : 0;
    }
    
    $gt['day_total'] = $lt['day_total'];
    
    $earnedSum = 0;
    foreach ($assemblyRows as $row) {
        $earnedSum += ($row['day_total'] ?? 0) * ($row['ttl_sam_pc'] ?? 0);
    }
    $gt['ern_minutes'] = $earnedSum;
    
    $gt['acvd_eff'] = ($gt['available_minutes'] > 0) ? ($gt['ern_minutes'] / $gt['available_minutes']) * ($gt['plan_hours'] / $gt['worked_hours']) : 0;
    
    return $gt;
}

// ============================================================
// DATABASE HELPERS
// ============================================================
function getDivisions($conn) {
    try {
        $stmt = $conn->query("SELECT * FROM divisions WHERE is_active = TRUE ORDER BY display_order");
        if ($stmt) {
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($result) ? $result : array();
        }
        return array();
    } catch (Exception $e) {
        return array();
    }
}

function getComponents($conn, $div_id) {
    try {
        $stmt = $conn->prepare("SELECT * FROM components WHERE division_id = ? ORDER BY display_order");
        $stmt->execute([$div_id]);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($result) ? $result : array();
    } catch (Exception $e) {
        return array();
    }
}

function getReportData($conn, $div_id, $unit_id, $date) {
    try {
        $stmt = $conn->prepare("SELECT * FROM production_reports WHERE devition_id = ? AND unit_id = ? AND report_date = ?");
        $stmt->execute([$div_id, $unit_id, $date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            return [
                'ttl_sam_pc' => 0,
                'unit_smv' => 0,
                'day_forecast' => 0,
                'unit_carder' => 0,
                'plan_hours' => 0,
                'worked_hours' => 0,
                'available_minutes' => 0,
                'plan_minutes' => 0,
                'plan_eff' => 0,
                'target_100' => 0,
                'day_total' => 0,
                'acvd_eff' => 0,
                'ern_minutes' => 0,
                'hour_1' => 0,
                'hour_2' => 0,
                'hour_3' => 0,
                'hour_4' => 0,
                'hour_5' => 0,
                'hour_6' => 0,
                'hour_7' => 0,
                'hour_8' => 0,
                'hour_9' => 0,
                'hour_10' => 0,
                'hour_11' => 0
            ];
        }
        return $result;
    } catch (Exception $e) {
        return [
            'ttl_sam_pc' => 0,
            'unit_smv' => 0,
            'day_forecast' => 0,
            'unit_carder' => 0,
            'plan_hours' => 0,
            'worked_hours' => 0,
            'available_minutes' => 0,
            'plan_minutes' => 0,
            'plan_eff' => 0,
            'target_100' => 0,
            'day_total' => 0,
            'acvd_eff' => 0,
            'ern_minutes' => 0,
            'hour_1' => 0,
            'hour_2' => 0,
            'hour_3' => 0,
            'hour_4' => 0,
            'hour_5' => 0,
            'hour_6' => 0,
            'hour_7' => 0,
            'hour_8' => 0,
            'hour_9' => 0,
            'hour_10' => 0,
            'hour_11' => 0
        ];
    }
}

function saveReportData($conn, $data, $workingHours) {
    for ($h = 1; $h <= 11; $h++) {
        if ($h > $workingHours) $data["hour_$h"] = 0;
    }
    
    try {
        $check = $conn->prepare("SELECT id FROM production_reports WHERE devition_id = ? AND unit_id = ? AND report_date = ?");
        $check->execute([$data['devition_id'], $data['unit_id'], $data['report_date']]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $sql = "UPDATE production_reports SET 
                    ttl_sam_pc = ?, 
                    unit_smv = ?, 
                    day_forecast = ?, 
                    unit_carder = ?, 
                    plan_hours = ?, 
                    worked_hours = ?, 
                    available_minutes = ?, 
                    plan_minutes = ?, 
                    plan_eff = ?, 
                    target_100 = ?, 
                    hour_1 = ?, 
                    hour_2 = ?, 
                    hour_3 = ?, 
                    hour_4 = ?, 
                    hour_5 = ?, 
                    hour_6 = ?, 
                    hour_7 = ?, 
                    hour_8 = ?, 
                    hour_9 = ?, 
                    hour_10 = ?, 
                    hour_11 = ?, 
                    day_total = ?, 
                    acvd_eff = ?, 
                    ern_minutes = ? 
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            return $stmt->execute([
                $data['ttl_sam_pc'], 
                $data['unit_smv'], 
                $data['day_forecast'], 
                $data['unit_carder'], 
                $data['plan_hours'], 
                $data['worked_hours'],
                $data['available_minutes'], 
                $data['plan_minutes'], 
                $data['plan_eff'], 
                $data['target_100'],
                $data['hour_1'], 
                $data['hour_2'], 
                $data['hour_3'], 
                $data['hour_4'], 
                $data['hour_5'], 
                $data['hour_6'], 
                $data['hour_7'], 
                $data['hour_8'], 
                $data['hour_9'], 
                $data['hour_10'], 
                $data['hour_11'],
                $data['day_total'], 
                $data['acvd_eff'], 
                $data['ern_minutes'], 
                $existing['id']
            ]);
        } else {
            $sql = "INSERT INTO production_reports (
                report_date, 
                devition_id, 
                unit_id, 
                ttl_sam_pc, 
                unit_smv, 
                day_forecast, 
                unit_carder, 
                plan_hours, 
                worked_hours, 
                available_minutes, 
                plan_minutes, 
                plan_eff, 
                target_100, 
                hour_1, 
                hour_2, 
                hour_3, 
                hour_4, 
                hour_5, 
                hour_6, 
                hour_7, 
                hour_8, 
                hour_9, 
                hour_10, 
                hour_11, 
                day_total, 
                acvd_eff, 
                ern_minutes
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )";
            $stmt = $conn->prepare($sql);
            return $stmt->execute([
                $data['report_date'], 
                $data['devition_id'], 
                $data['unit_id'],
                $data['ttl_sam_pc'], 
                $data['unit_smv'], 
                $data['day_forecast'], 
                $data['unit_carder'], 
                $data['plan_hours'], 
                $data['worked_hours'],
                $data['available_minutes'], 
                $data['plan_minutes'], 
                $data['plan_eff'], 
                $data['target_100'],
                $data['hour_1'], 
                $data['hour_2'], 
                $data['hour_3'], 
                $data['hour_4'], 
                $data['hour_5'], 
                $data['hour_6'], 
                $data['hour_7'], 
                $data['hour_8'], 
                $data['hour_9'], 
                $data['hour_10'], 
                $data['hour_11'],
                $data['day_total'], 
                $data['acvd_eff'], 
                $data['ern_minutes']
            ]);
        }
    } catch (Exception $e) {
        error_log('Save error: ' . $e->getMessage());
        return false;
    }
}

function getDivisionStats($conn, $div_id, $date, $workingHours) {
    $components = getComponents($conn, $div_id);
    if (!is_array($components) || empty($components)) {
        return ['total_units' => 0, 'setup_units' => 0, 'efficiency' => 0, 'has_data' => false];
    }
    
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
            if (isset($data['acvd_eff']) && $data['acvd_eff'] > 0) {
                $totalEff += $data['acvd_eff'];
                $count++;
            }
        }
    }
    
    return [
        'total_units' => $totalUnits,
        'setup_units' => $setupUnits,
        'efficiency' => $count > 0 ? round(($totalEff / $count) * 100, 0) : 0,
        'has_data' => $setupUnits > 0
    ];
}

function getFactoryEfficiency($conn, $date, $workingHours) {
    $divisions = getDivisions($conn);
    if (!is_array($divisions) || empty($divisions)) return 0;
    
    $totalEff = 0;
    $count = 0;
    foreach ($divisions as $div) {
        if ($div['name'] === 'Coat') continue;
        $stats = getDivisionStats($conn, $div['id'], $date, $workingHours);
        if ($stats['efficiency'] > 0) {
            $totalEff += $stats['efficiency'];
            $count++;
        }
    }
    return $count > 0 ? round($totalEff / $count, 0) : 0;
}

function getReportsList($conn, $date = null, $division_id = null) {
    try {
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
        
        $sql .= " AND d.name != 'Coat'";
        $sql .= " ORDER BY r.report_date DESC, r.id DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($result) ? $result : [];
    } catch (Exception $e) {
        return [];
    }
}
?>