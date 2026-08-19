<?php
// view_report.php - View Saved Reports
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$id = $_GET['id'] ?? 0;
$conn = getDB();

$stmt = $conn->prepare("SELECT r.*, d.name as division_name 
                        FROM production_reports r 
                        JOIN divisions d ON r.devition_id = d.id 
                        WHERE r.id = ?");
$stmt->execute([$id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    header('Location: reports.php');
    exit;
}

$components = getComponents($conn, $report['devition_id']);
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<h1>HOURLY PRODUCTION AND EFFICIENCY REPORT - HAMEEDIA</h1>
<div class="report-meta">
    <span>DATE: <?php echo date('d-M-Y', strtotime($report['report_date'])); ?></span>
    <span>DEVOTION: <?php echo strtoupper($report['division_name']); ?></span>
    <span>REPORTING HOURS: <?php echo $report['worked_hours']; ?></span>
</div>

<div class="report-wrapper">
    <table class="report-table">
        <thead>
            <tr>
                <th>DEVITION</th>
                <th>Unit</th>
                <th>Ttl SAM/Pc</th>
                <th>Unit SMV</th>
                <th>Day Forecast</th>
                <th>Unit Carder</th>
                <th>Plan Hours</th>
                <th>Worked Hours</th>
                <th>Available Minutes</th>
                <th>Plan Minutes</th>
                <th>Plan Eff</th>
                <th>100% Target</th>
                <?php for ($h = 1; $h <= $report['worked_hours']; $h++): ?>
                <th><?php echo $h; ?>st Hour</th>
                <?php endfor; ?>
                <th>Day Ttl</th>
                <th>Ern Minutes</th>
                <th>Acvd Eff</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($components as $comp): 
                if ($comp['is_match_out']) continue;
                $rowData = getReportData($conn, $report['devition_id'], $comp['id'], $report['report_date']);
            ?>
            <tr>
                <td><?php echo $report['division_name']; ?></td>
                <td><?php echo $comp['name']; ?></td>
                <td><?php echo number_format($rowData['ttl_sam_pc'] ?? 0, 2); ?></td>
                <td><?php echo number_format($rowData['unit_smv'] ?? 0, 2); ?></td>
                <td><?php echo number_format($rowData['day_forecast'] ?? 0, 0); ?></td>
                <td><?php echo number_format($rowData['unit_carder'] ?? 0, 0); ?></td>
                <td><?php echo number_format($rowData['plan_hours'] ?? 0, 1); ?></td>
                <td><?php echo number_format($rowData['worked_hours'] ?? 0, 1); ?></td>
                <td><?php echo number_format($rowData['available_minutes'] ?? 0, 0); ?></td>
                <td><?php echo number_format($rowData['plan_minutes'] ?? 0, 0); ?></td>
                <td><?php echo number_format(($rowData['plan_eff'] ?? 0) * 100, 1); ?>%</td>
                <td><?php echo number_format($rowData['target_100'] ?? 0, 0); ?></td>
                <?php for ($h = 1; $h <= $report['worked_hours']; $h++): ?>
                <td><?php echo number_format($rowData["hour_$h"] ?? 0, 0); ?></td>
                <?php endfor; ?>
                <td style="font-weight:700;"><?php echo number_format($rowData['day_total'] ?? 0, 0); ?></td>
                <td style="font-weight:700;"><?php echo number_format($rowData['acvd_eff'] ?? 0, 1); ?></td>
                <td style="font-weight:700;"><?php echo number_format(($rowData['acvd_eff'] ?? 0) * 100, 1); ?>%</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="report-actions">
    <button class="btn-secondary" onclick="window.print()">Print Report</button>
    <a href="reports.php" class="btn-secondary" style="display:inline-block; padding:10px 20px; background:#6c757d; color:#fff; text-decoration:none; border-radius:4px;">Back to Reports</a>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>