<?php
// reports.php - WITH DEBUGGING ENABLED
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$conn = getDB();

$from_date = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-7 days'));
$to_date = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');
$division_filter = isset($_GET['division']) ? $_GET['division'] : 'all';

$divisions = getDivisions($conn);
$reports = [];

$sql = "SELECT r.*, d.name as division_name 
        FROM production_reports r 
        JOIN divisions d ON r.devition_id = d.id 
        WHERE r.report_date BETWEEN ? AND ?";

$params = [$from_date, $to_date];

if ($division_filter !== 'all') {
    $sql .= " AND d.id = ?";
    $params[] = (int)$division_filter;
}

$sql .= " ORDER BY r.report_date DESC, r.id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<h1>Reports</h1>
<p style="color:var(--steel); margin-bottom:20px;">Trend of achieved efficiency across your report dates.</p>

<div class="filter-row" style="display:flex; gap:15px; align-items:flex-end; background:#fff; padding:20px; border-radius:8px; margin-bottom:20px; flex-wrap:wrap;">
    <div class="field">
        <label style="font-weight:600;font-size:13px;">From</label>
        <input id="rep-from" type="date" value="<?php echo $from_date; ?>" style="padding:8px 12px; border:1px solid #ddd; border-radius:4px; font-size:14px;">
    </div>
    <div class="field">
        <label style="font-weight:600;font-size:13px;">To</label>
        <input id="rep-to" type="date" value="<?php echo $to_date; ?>" style="padding:8px 12px; border:1px solid #ddd; border-radius:4px; font-size:14px;">
    </div>
    <div class="field">
        <label style="font-weight:600;font-size:13px;">Devition</label>
        <select id="rep-division" style="padding:8px 12px; border:1px solid #ddd; border-radius:4px; font-size:14px;">
            <option value="all" <?php echo $division_filter === 'all' ? 'selected' : ''; ?>>All devitions</option>
            <?php foreach ($divisions as $div): ?>
            <option value="<?php echo $div['id']; ?>" <?php echo $division_filter == $div['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($div['name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="btn-outline" onclick="applyFilters()">Apply</button>
    <button class="btn-outline" onclick="viewAllRecords()">View all records</button>
</div>

<div class="kpi-row" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px,1fr)); gap:16px; margin-bottom:20px;">
    <div class="kpi-card" style="background:#fff; padding:20px; border-radius:8px; text-align:center; box-shadow:0 2px 4px rgba(0,0,0,0.05);">
        <div class="number" style="font-size:30px; font-weight:900; color:#217346;"><?php echo count($reports); ?></div>
        <div class="label" style="font-size:13px; font-weight:600; color:#6b7a8f; margin-top:4px;">Total Reports</div>
    </div>
    <?php 
    $avg_eff = 0;
    $total_prod = 0;
    foreach($reports as $r) { 
        $avg_eff += $r['acvd_eff'] ?? 0; 
        $total_prod += $r['day_total'] ?? 0; 
    }
    $avg_eff = count($reports) > 0 ? round($avg_eff / count($reports), 1) : 0;
    ?>
    <div class="kpi-card" style="background:#fff; padding:20px; border-radius:8px; text-align:center; box-shadow:0 2px 4px rgba(0,0,0,0.05);">
        <div class="number" style="font-size:30px; font-weight:900; color:#217346;"><?php echo $avg_eff; ?>%</div>
        <div class="label" style="font-size:13px; font-weight:600; color:#6b7a8f; margin-top:4px;">Avg Efficiency</div>
    </div>
    <div class="kpi-card" style="background:#fff; padding:20px; border-radius:8px; text-align:center; box-shadow:0 2px 4px rgba(0,0,0,0.05);">
        <div class="number" style="font-size:30px; font-weight:900; color:#217346;"><?php echo number_format($total_prod, 0); ?></div>
        <div class="label" style="font-size:13px; font-weight:600; color:#6b7a8f; margin-top:4px;">Total Production</div>
    </div>
    <div class="kpi-card" style="background:#fff; padding:20px; border-radius:8px; text-align:center; box-shadow:0 2px 4px rgba(0,0,0,0.05);">
        <div class="number" style="font-size:30px; font-weight:900; color:#217346;"><?php echo count($divisions); ?></div>
        <div class="label" style="font-size:13px; font-weight:600; color:#6b7a8f; margin-top:4px;">Divisions Active</div>
    </div>
</div>

<div class="table-shell" style="background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 2px 4px rgba(0,0,0,0.05);">
    <table class="report" style="width:100%; border-collapse:collapse; font-size:14px;">
        <thead>
            <tr>
                <th style="background:rgba(255,255,255,0.3); padding:12px 16px; text-align:left; font-weight:700; color:#6b7a8f; border-bottom:1px solid #ddd;">Date</th>
                <th style="background:rgba(255,255,255,0.3); padding:12px 16px; text-align:left; font-weight:700; color:#6b7a8f; border-bottom:1px solid #ddd;">Devition</th>
                <th style="background:rgba(255,255,255,0.3); padding:12px 16px; text-align:left; font-weight:700; color:#6b7a8f; border-bottom:1px solid #ddd;">Day Total</th>
                <th style="background:rgba(255,255,255,0.3); padding:12px 16px; text-align:left; font-weight:700; color:#6b7a8f; border-bottom:1px solid #ddd;">Achieved eff</th>
                <th style="background:rgba(255,255,255,0.3); padding:12px 16px; text-align:left; font-weight:700; color:#6b7a8f; border-bottom:1px solid #ddd;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($reports)): ?>
            <tr><td colspan="5" style="text-align:center; padding:40px; color:#6b7a8f; font-weight:500;">No reports found. Please add data in a Devition and click "Save All".</td></tr>
            <?php else: ?>
            <?php foreach ($reports as $report): 
                $eff = $report['acvd_eff'] ?? 0;
                $eff_display = $eff * 100;
            ?>
            <tr>
                <td style="padding:10px 16px; border-bottom:1px solid #eee;"><?php echo date('Y-m-d', strtotime($report['report_date'])); ?></td>
                <td style="padding:10px 16px; border-bottom:1px solid #eee;"><?php echo htmlspecialchars($report['division_name']); ?></td>
                <td style="padding:10px 16px; border-bottom:1px solid #eee;"><?php echo number_format($report['day_total'] ?? 0, 0); ?></td>
                <td style="padding:10px 16px; border-bottom:1px solid #eee; font-weight:700; color:#217346;"><?php echo number_format($eff_display, 1); ?>%</td>
                <td style="padding:10px 16px; border-bottom:1px solid #eee;"><a href="view_report.php?id=<?php echo $report['id']; ?>" style="padding:4px 12px; background:#217346; color:#fff; text-decoration:none; border-radius:4px; font-size:12px;">View</a></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
<script>
function updateClock() {
    const now = new Date();
    let hours = now.getHours();
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12 || 12;
    const minutes = String(now.getMinutes()).padStart(2, '0');
    document.getElementById('live-clock').textContent = hours + ':' + minutes + ' ' + ampm;
}
updateClock();
setInterval(updateClock, 60000);

function applyFilters() {
    var from = document.getElementById('rep-from').value;
    var to = document.getElementById('rep-to').value;
    var division = document.getElementById('rep-division').value;
    window.location.href = 'reports.php?from=' + from + '&to=' + to + '&division=' + division;
}

function viewAllRecords() {
    window.location.href = 'reports.php';
}
</script>