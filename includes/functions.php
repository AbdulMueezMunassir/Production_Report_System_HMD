<?php
// includes/functions.php
require_once __DIR__ . '/../config/database.php';

function getDivisions($conn) {
    $sql = "SELECT * FROM divisions WHERE is_active = TRUE ORDER BY display_order";
    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }
    $divisions = [];
    while ($row = $result->fetch_assoc()) {
        $divisions[] = $row;
    }
    return $divisions;
}

function getComponents($conn, $division_id) {
    $sql = "SELECT * FROM components WHERE division_id = ? ORDER BY display_order";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param("i", $division_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $components = [];
    while ($row = $result->fetch_assoc()) {
        $components[] = $row;
    }
    return $components;
}

function getReportData($conn, $devition_id, $unit_id, $date) {
    $sql = "SELECT * FROM production_reports 
            WHERE devition_id = ? AND unit_id = ? AND report_date = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param("iis", $devition_id, $unit_id, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc() ?: [];
}

function saveReportData($conn, $data) {
    $check = "SELECT id FROM production_reports 
              WHERE devition_id = ? AND unit_id = ? AND report_date = ?";
    $stmt = $conn->prepare($check);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("iis", $data['devition_id'], $data['unit_id'], $data['report_date']);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = $result->fetch_assoc();
    
    if ($existing) {
        $sql = "UPDATE production_reports SET 
                ttl_sam_pc = ?, unit_smv = ?, day_forecast = ?, unit_carder = ?,
                plan_hours = ?, worked_hours = ?, available_minutes = ?,
                plan_minutes = ?, plan_eff = ?, target_100 = ?,
                hour_1 = ?, hour_2 = ?, hour_3 = ?, hour_4 = ?, hour_5 = ?,
                hour_6 = ?, hour_7 = ?, hour_8 = ?, hour_9 = ?, hour_10 = ?,
                hour_11 = ?, day_total = ?, acvd_eff = ?
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param(
            "dddiidddddddddddddddddi",
            $data['ttl_sam_pc'], $data['unit_smv'], $data['day_forecast'],
            $data['unit_carder'], $data['plan_hours'], $data['worked_hours'],
            $data['available_minutes'], $data['plan_minutes'], $data['plan_eff'],
            $data['target_100'], $data['hour_1'], $data['hour_2'],
            $data['hour_3'], $data['hour_4'], $data['hour_5'],
            $data['hour_6'], $data['hour_7'], $data['hour_8'],
            $data['hour_9'], $data['hour_10'], $data['hour_11'],
            $data['day_total'], $data['acvd_eff'], $existing['id']
        );
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
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param(
            "siidddiidddddddddddddddd",
            $data['report_date'], $data['devition_id'], $data['unit_id'],
            $data['ttl_sam_pc'], $data['unit_smv'], $data['day_forecast'],
            $data['unit_carder'], $data['plan_hours'], $data['worked_hours'],
            $data['available_minutes'], $data['plan_minutes'], $data['plan_eff'],
            $data['target_100'], $data['hour_1'], $data['hour_2'],
            $data['hour_3'], $data['hour_4'], $data['hour_5'],
            $data['hour_6'], $data['hour_7'], $data['hour_8'],
            $data['hour_9'], $data['hour_10'], $data['hour_11'],
            $data['day_total'], $data['acvd_eff']
        );
    }
    
    return $stmt->execute();
}

function calculateMatchOut($conn, $devition_id, $date) {
    $components = getComponents($conn, $devition_id);
    $match_out = [
        'ttl_sam_pc' => 0,
        'unit_smv' => 0,
        'day_forecast' => 0,
        'unit_carder' => 0,
        'plan_hours' => 0,
        'worked_hours' => 0,
        'hours' => array_fill(1, 11, 0)
    ];
    
    $count = 0;
    foreach ($components as $comp) {
        if ($comp['is_match_out']) continue;
        $data = getReportData($conn, $devition_id, $comp['id'], $date);
        if (!empty($data)) {
            $count++;
            $match_out['ttl_sam_pc'] += $data['ttl_sam_pc'] ?? 0;
            $match_out['unit_smv'] += $data['unit_smv'] ?? 0;
            $match_out['day_forecast'] += $data['day_forecast'] ?? 0;
            $match_out['unit_carder'] += $data['unit_carder'] ?? 0;
            $match_out['plan_hours'] += $data['plan_hours'] ?? 0;
            $match_out['worked_hours'] += $data['worked_hours'] ?? 0;
            for ($h = 1; $h <= 11; $h++) {
                $match_out['hours'][$h] += $data["hour_$h"] ?? 0;
            }
        }
    }
    
    if ($count > 0) {
        $match_out['ttl_sam_pc'] = $match_out['ttl_sam_pc'];
        $match_out['unit_smv'] = $match_out['unit_smv'] / $count;
        $match_out['plan_hours'] = $match_out['plan_hours'] / $count;
        $match_out['worked_hours'] = $match_out['worked_hours'] / $count;
        for ($h = 1; $h <= 11; $h++) {
            $match_out['hours'][$h] = round($match_out['hours'][$h] / $count, 0);
        }
    }
    
    return $match_out;
}

function getDivisionStats($conn, $division_id, $date) {
    $components = getComponents($conn, $division_id);
    $total_units = 0;
    $setup_units = 0;
    $total_eff = 0;
    $eff_count = 0;
    $total_day_ttl = 0;
    $total_ern_min = 0;
    $total_available = 0;
    
    foreach ($components as $comp) {
        if ($comp['is_match_out']) continue;
        $total_units++;
        $data = getReportData($conn, $division_id, $comp['id'], $date);
        if (!empty($data) && ($data['ttl_sam_pc'] ?? 0) > 0) {
            $setup_units++;
            if ($data['acvd_eff'] > 0) {
                $total_eff += $data['acvd_eff'];
                $eff_count++;
            }
            $day_total = 0;
            for ($h = 1; $h <= 11; $h++) {
                $day_total += $data["hour_$h"] ?? 0;
            }
            $total_day_ttl += $day_total;
            $total_ern_min += $day_total * ($data['ttl_sam_pc'] ?? 0);
            $total_available += $data['available_minutes'] ?? 0;
        }
    }
    
    return [
        'total_units' => $total_units,
        'setup_units' => $setup_units,
        'efficiency' => $eff_count > 0 ? round($total_eff / $eff_count, 0) : 0,
        'day_total' => $total_day_ttl,
        'ern_minutes' => $total_ern_min,
        'available_minutes' => $total_available,
        'has_data' => $setup_units > 0
    ];
}

function getFactoryEfficiency($conn, $date) {
    $divisions = getDivisions($conn);
    $total_eff = 0;
    $count = 0;
    foreach ($divisions as $div) {
        $stats = getDivisionStats($conn, $div['id'], $date);
        if ($stats['efficiency'] > 0) {
            $total_eff += $stats['efficiency'];
            $count++;
        }
    }
    return $count > 0 ? round($total_eff / $count, 0) : 0;
}
?>