<?php
// save_report.php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$reportId = $input['report_id'] ?? 0;
$data = $input['data'] ?? [];

if (!$reportId) {
    echo json_encode(['success' => false, 'message' => 'Invalid report ID']);
    exit;
}

$pdo = getDB();

try {
    $pdo->beginTransaction();

    // Get report details
    $stmt = $pdo->prepare("SELECT r.*, ds.working_hours, ds.target_efficiency 
                           FROM reports r 
                           LEFT JOIN report_date_settings ds ON r.report_date = ds.report_date 
                           WHERE r.id = ?");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch();

    if (!$report) {
        throw new Exception("Report not found");
    }

    if ($report['status'] === 'finalized') {
        throw new Exception("Cannot edit finalized report");
    }

    $workingHours = $report['working_hours'];
    $targetEfficiency = $report['target_efficiency'];

    // Process each row
    foreach ($data['rows'] as $rowData) {
        $rowId = $rowData['row_id'];
        
        // Update base fields
        $ttlSamPc = floatval($rowData['ttl_sam_pc'] ?? 0);
        $unitSmv = floatval($rowData['unit_smv'] ?? 0);
        $unitCarder = floatval($rowData['unit_carder'] ?? 0);
        $planHours = floatval($rowData['plan_hours'] ?? 0);
        $workedHours = floatval($rowData['worked_hours'] ?? 0);
        
        // Calculate derived fields (SERVER-SIDE AUTHORITATIVE)
        $dayForecast = calculateDayForecast($unitCarder, $workingHours, $unitSmv, $targetEfficiency);
        $availableMinutes = calculateAvailableMinutes($unitCarder, $planHours);
        $planMinutes = calculatePlanMinutes($dayForecast, $unitSmv);
        $planEfficiency = calculatePlanEfficiency($planMinutes, $availableMinutes);
        $target100 = calculateTarget100($unitCarder, $unitSmv);
        
        $hourly = [];
        $dayTotal = 0;
        if (isset($rowData['hours'])) {
            for ($h = 1; $h <= $workingHours; $h++) {
                $qty = floatval($rowData['hours'][$h] ?? 0);
                $hourly[$h] = $qty;
                $dayTotal += $qty;
            }
        }
        
        $earnedMinutes = calculateEarnedMinutes($dayTotal, $unitSmv);
        $achievedEfficiency = calculateAchievedEfficiency($earnedMinutes, $unitCarder, $workedHours);
        
        // Update report_rows
        $stmt = $pdo->prepare("UPDATE report_rows SET 
                               ttl_sam_pc = ?, unit_smv = ?, day_forecast = ?, unit_carder = ?, 
                               plan_hours = ?, worked_hours = ?, available_minutes = ?, plan_minutes = ?, 
                               plan_efficiency = ?, target_100 = ?, day_total = ?, earned_minutes = ?, 
                               achieved_efficiency = ? WHERE id = ?");
        $stmt->execute([
            $ttlSamPc, $unitSmv, $dayForecast, $unitCarder,
            $planHours, $workedHours, $availableMinutes, $planMinutes,
            $planEfficiency, $target100, $dayTotal, $earnedMinutes,
            $achievedEfficiency, $rowId
        ]);
        
        // Update hourly_production
        for ($h = 1; $h <= $workingHours; $h++) {
            $qty = floatval($rowData['hours'][$h] ?? 0);
            $stmt = $pdo->prepare("INSERT INTO hourly_production (report_row_id, hour_number, quantity) 
                                   VALUES (?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)");
            $stmt->execute([$rowId, $h, $qty]);
        }
    }

    if ($action === 'finalize') {
        $stmt = $pdo->prepare("UPDATE reports SET status = 'finalized', finalized_at = NOW() WHERE id = ?");
        $stmt->execute([$reportId]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Report saved successfully']);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}