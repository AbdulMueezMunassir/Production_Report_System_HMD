<?php
// data_version.php - Lightweight "did anything change?" endpoint for the live analytics screen.
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Release the PHP session lock so polling never blocks save requests
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $conn = getDB();
    $date = normalizeReportDate($_GET['date'] ?? null);

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS row_count,
               COALESCE(MAX(updated_at), '') AS last_update,
               COALESCE(SUM(day_total), 0) AS sum_total,
               COALESCE(SUM(hour_1 + hour_2 + hour_3 + hour_4 + hour_5 + hour_6 +
                            hour_7 + hour_8 + hour_9 + hour_10 + hour_11), 0) AS sum_hours,
               COALESCE(SUM(unit_carder + direct_present + direct_on_roll), 0) AS sum_staff
        FROM production_reports
        WHERE report_date = ?
    ");
    $stmt->execute([$date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'today'   => date('Y-m-d'),
        'date'    => $date,
        'version' => md5(json_encode($row))
    ]);
} catch (Throwable $e) {
    error_log('data_version.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Version check failed']);
}