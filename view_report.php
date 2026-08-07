<?php
// view_report.php
require_once 'config/database.php';
require_once 'includes/functions.php';

$id = $_GET['id'] ?? 0;
$conn = getDBConnection();

$sql = "SELECT r.*, c.name as category_name 
        FROM production_reports r 
        LEFT JOIN categories c ON r.category_id = c.id 
        WHERE r.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$report = $result->fetch_assoc();

if (!$report) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Report</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="excel-header">
        <h1>📄 Report Details</h1>
        <div class="header-tabs">
            <a href="index.php">📝 Entry</a>
            <a href="dashboard.php">📈 Dashboard</a>
        </div>
    </div>
    
    <div class="excel-container">
        <div style="padding:20px;">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                <div>
                    <p><strong>Date:</strong> <?php echo date('Y-m-d', strtotime($report['report_date'])); ?></p>
                    <p><strong>Category:</strong> <?php echo htmlspecialchars($report['category_name'] ?? 'N/A'); ?></p>
                    <p><strong>Component:</strong> <?php echo htmlspecialchars($report['component']); ?></p>
                    <p><strong>Devition:</strong> <?php echo htmlspecialchars($report['devition'] ?: 'N/A'); ?></p>
                </div>
                <div>
                    <p><strong>TTl SAM/Pc:</strong> <?php echo number_format($report['ttl_sam_pc'], 2); ?></p>
                    <p><strong>Unit SMV:</strong> <?php echo number_format($report['unit_smv'], 2); ?></p>
                    <p><strong>Unit Carder:</strong> <?php echo $report['unit_carder']; ?></p>
                    <p><strong>Efficiency:</strong> <?php echo number_format($report['acvd_eff'], 1); ?>%</p>
                </div>
            </div>
            
            <h4 style="margin:20px 0 10px;">Hourly Production</h4>
            <table class="excel-table">
                <thead>
                    <tr>
                        <?php for($i=1; $i<=11; $i++): ?>
                        <th>Hour <?php echo $i; ?></th>
                        <?php endfor; ?>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <?php 
                        $total = 0;
                        for($i=1; $i<=11; $i++): 
                            $val = $report["hour_$i"] ?? 0;
                            $total += $val;
                        ?>
                        <td><?php echo number_format($val, 0); ?></td>
                        <?php endfor; ?>
                        <td style="font-weight:700;"><?php echo number_format($total, 0); ?></td>
                    </tr>
                </tbody>
            </table>
            
            <div style="margin-top:20px;">
                <a href="index.php" class="btn btn-secondary" style="display:inline-block; padding:8px 20px; background:#6c757d; color:#fff; text-decoration:none; border-radius:4px;">← Back</a>
                <a href="edit_report.php?id=<?php echo $report['id']; ?>" class="btn btn-primary" style="display:inline-block; padding:8px 20px; background:#217346; color:#fff; text-decoration:none; border-radius:4px;">✏️ Edit</a>
            </div>
        </div>
    </div>
</body>
</html>