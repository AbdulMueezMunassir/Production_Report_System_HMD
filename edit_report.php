<?php
// edit_report.php
require_once 'config/database.php';
require_once 'includes/functions.php';

$id = $_GET['id'] ?? 0;
$conn = getDBConnection();

$sql = "SELECT * FROM production_reports WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$report = $result->fetch_assoc();

if (!$report) {
    header('Location: index.php');
    exit;
}

$categories = getCategories($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Similar to insert but UPDATE
        $sql = "UPDATE production_reports SET 
            report_date=?, devition=?, category_id=?, component=?,
            ttl_sam_pc=?, unit_smv=?, day_forecast=?, unit_carder=?,
            plan_hours=?, worked_hours=?, hour_1=?, hour_2=?, hour_3=?,
            hour_4=?, hour_5=?, hour_6=?, hour_7=?, hour_8=?,
            hour_9=?, hour_10=?, hour_11=?
            WHERE id=?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            'ssisssiidddddddddddddi',
            $_POST['report_date'], $_POST['devition'], $_POST['category_id'],
            $_POST['component'], $_POST['ttl_sam_pc'], $_POST['unit_smv'],
            $_POST['day_forecast'], $_POST['unit_carder'], $_POST['plan_hours'],
            $_POST['worked_hours'], $_POST['hour_1'], $_POST['hour_2'],
            $_POST['hour_3'], $_POST['hour_4'], $_POST['hour_5'],
            $_POST['hour_6'], $_POST['hour_7'], $_POST['hour_8'],
            $_POST['hour_9'], $_POST['hour_10'], $_POST['hour_11'],
            $id
        );
        
        if ($stmt->execute()) {
            setMessage('success', 'Report updated successfully!');
            header('Location: index.php');
            exit;
        }
    } catch (Exception $e) {
        setMessage('danger', 'Error: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Report</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="excel-header">
        <h1>✏️ Edit Report</h1>
        <div class="header-tabs">
            <a href="index.php">📝 Entry</a>
            <a href="dashboard.php">📈 Dashboard</a>
        </div>
    </div>
    
    <div class="excel-container">
        <form method="POST" style="padding:20px;">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                <div>
                    <label>Date:</label>
                    <input type="date" name="report_date" value="<?php echo $report['report_date']; ?>" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px;">
                </div>
                <div>
                    <label>Devition:</label>
                    <input type="text" name="devition" value="<?php echo htmlspecialchars($report['devition']); ?>" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px;">
                </div>
                <div>
                    <label>Category:</label>
                    <select name="category_id" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px;">
                        <?php foreach($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $cat['id'] == $report['category_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Component:</label>
                    <input type="text" name="component" value="<?php echo htmlspecialchars($report['component']); ?>" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px;">
                </div>
                <div>
                    <label>TTl SAM/Pc:</label>
                    <input type="number" step="0.01" name="ttl_sam_pc" value="<?php echo $report['ttl_sam_pc']; ?>" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px;">
                </div>
                <div>
                    <label>Unit SMV:</label>
                    <input type="number" step="0.01" name="unit_smv" value="<?php echo $report['unit_smv']; ?>" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px;">
                </div>
                <div>
                    <label>Day Forecast:</label>
                    <input type="number" step="0.01" name="day_forecast" value="<?php echo $report['day_forecast']; ?>" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px;">
                </div>
                <div>
                    <label>Unit Carder:</label>
                    <input type="number" name="unit_carder" value="<?php echo $report['unit_carder']; ?>" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px;">
                </div>
                <div>
                    <label>Plan Hours:</label>
                    <input type="number" step="0.5" name="plan_hours" value="<?php echo $report['plan_hours']; ?>" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px;">
                </div>
                <div>
                    <label>Worked Hours:</label>
                    <input type="number" step="0.5" name="worked_hours" value="<?php echo $report['worked_hours']; ?>" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px;">
                </div>
            </div>
            
            <h4 style="margin:20px 0 10px;">Hourly Production</h4>
            <div style="display:grid; grid-template-columns:repeat(11, 1fr); gap:10px;">
                <?php for($i=1; $i<=11; $i++): ?>
                <div>
                    <label>Hour <?php echo $i; ?>:</label>
                    <input type="number" name="hour_<?php echo $i; ?>" value="<?php echo $report["hour_$i"] ?? 0; ?>" style="width:100%; padding:6px; border:1px solid #ddd; border-radius:4px;">
                </div>
                <?php endfor; ?>
            </div>
            
            <div style="margin-top:20px;">
                <button type="submit" style="padding:8px 30px; background:#217346; color:#fff; border:none; border-radius:4px; cursor:pointer;">💾 Update</button>
                <a href="view_report.php?id=<?php echo $id; ?>" style="padding:8px 30px; background:#6c757d; color:#fff; text-decoration:none; border-radius:4px; display:inline-block;">Cancel</a>
            </div>
        </form>
    </div>
</body>
</html>