<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
requireLogin();

$conn = getDB();
$today = date('Y-m-d');
$default_from = date('Y-m-01');
$from_input = $_GET['from'] ?? $default_from;
$to_input = $_GET['to'] ?? $today;
$from_date = normalizeReportDate(is_string($from_input) && $from_input !== '' ? $from_input : $default_from);
$to_date = normalizeReportDate(is_string($to_input) && $to_input !== '' ? $to_input : $today);
if ($from_date > $to_date) {
    $from_date = $to_date;
}
$division_filter = isset($_GET['division']) && is_string($_GET['division'])
    ? $_GET['division']
    : 'all';

$divisions = [];
try {
    $stmt = $conn->query("SELECT id, name, type FROM divisions ORDER BY id ASC");
    $divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Monthly summary divisions query error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load divisions for the monthly summary.');
}

$valid_divisions = array_map(function ($division) {
    return (string)$division['id'];
}, $divisions);
if ($division_filter !== 'all' && !in_array($division_filter, $valid_divisions, true)) {
    $division_filter = 'all';
}

$division_names = [];
foreach ($divisions as $division) {
    $name = $division['name'];
    if (($division['type'] ?? '') === 'assembly') {
        $name = 'Assembly - ' . $name;
    }
    $division_names[(int)$division['id']] = $name;
}

$product_rows = [
    'SHIRT' => 'Shirt',
    'SHIRT MTM' => 'Shirt MTM',
    'TROUSER' => 'Trouser',
    'TROUSER MTM' => 'Trouser MTM',
    'COAT' => 'Coat',
    'COAT MTM' => 'Coat MTM',
    'KNIT' => 'Knit',
];
$category_by_division_type = [
    'shirt' => 'SHIRT',
    'shirt_mtm' => 'SHIRT MTM',
    'trouser' => 'TROUSER',
    'trouser_mtm' => 'TROUSER MTM',
    'coat' => 'COAT',
    'coat_mtm' => 'COAT MTM',
];
$selected_category = null;
if ($division_filter !== 'all') {
    foreach ($divisions as $division) {
        if ((string)$division['id'] === $division_filter) {
            $selected_category = $category_by_division_type[$division['type'] ?? ''] ?? null;
            break;
        }
    }
}

$month_data = [];
$date_cursor = new DateTimeImmutable($from_date);
$last_date = new DateTimeImmutable($to_date);
while ($date_cursor <= $last_date) {
    $month_key = $date_cursor->format('Y-m');
    if (!isset($month_data[$month_key])) {
        $month_data[$month_key] = ['dates' => [], 'products' => []];
        foreach ($product_rows as $product_key => $product_label) {
            $month_data[$month_key]['products'][$product_key] = [
                'name' => $product_label,
                'days' => [],
            ];
        }
    }
    $date_cursor = $date_cursor->modify('+1 day');
}

$sql = "
    SELECT
        DATE(r.report_date) AS report_date,
        UPPER(TRIM(c.name)) AS product_key,
        r.day_total,
        r.ern_minutes,
        r.available_minutes,
        r.plan_hours,
        r.worked_hours,
        r.unit_carder,
        r.ttl_sam_pc,
        r.profit
    FROM production_reports r
    INNER JOIN components c
        ON c.id = r.unit_id AND c.division_id = r.devition_id
    INNER JOIN divisions d
        ON d.id = r.devition_id AND d.type = 'assembly'
    WHERE DATE(r.report_date) BETWEEN ? AND ?
      AND UPPER(TRIM(c.name)) IN ('SHIRT', 'SHIRT MTM', 'TROUSER', 'TROUSER MTM', 'COAT', 'COAT MTM', 'KNIT')
";
$params = [$from_date, $to_date];
$sql .= ' ORDER BY r.report_date ASC, r.devition_id ASC';

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Monthly summary report query error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load monthly production data.');
}

// Month columns represent dates with production; retain all assembly rows for
// those dates so available minutes and labor costs are included in totals.
$worked_dates_by_month = [];
foreach ($reports as $report) {
    $date = date('Y-m-d', strtotime($report['report_date']));
    $month_key = substr($date, 0, 7);
    $product_key = strtoupper(trim($report['product_key']));
    if (!isset($product_rows[$product_key])
        || ($selected_category !== null && $product_key !== $selected_category)
        || (int)date('w', strtotime($date)) === 0
        || (float)($report['day_total'] ?? 0) <= 0) {
        continue;
    }
    $worked_dates_by_month[$month_key][$date] = true;
}

foreach ($reports as $report) {
    $date = date('Y-m-d', strtotime($report['report_date']));
    $month_key = substr($date, 0, 7);
    $product_key = strtoupper(trim($report['product_key']));
    if (!isset($product_rows[$product_key])
        || ($selected_category !== null && $product_key !== $selected_category)
        || (int)date('w', strtotime($date)) === 0
        || !isset($worked_dates_by_month[$month_key][$date])) {
        continue;
    }
    $pcs = (float)($report['day_total'] ?? 0);
    $profit = (float)($report['profit'] ?? 0);
    $earned_minutes = (float)($report['ern_minutes'] ?? 0);

    $sam_per_piece = (float)($report['ttl_sam_pc'] ?? 0);
    if ($earned_minutes <= 0 && $pcs > 0 && $sam_per_piece > 0) {
        $earned_minutes = $pcs * $sam_per_piece;
    }

    $available_minutes = (float)($report['available_minutes'] ?? 0);
    $plan_hours = (float)($report['plan_hours'] ?? 0);
    $worked_hours = (float)($report['worked_hours'] ?? 0);
    if ($available_minutes > 0 && $plan_hours > 0) {
        $available_minutes = ($available_minutes / $plan_hours) * $worked_hours;
    } elseif ($available_minutes <= 0 && $worked_hours > 0) {
        $available_minutes = (float)($report['unit_carder'] ?? 0) * $worked_hours * 60;
    }
    if (!in_array($date, $month_data[$month_key]['dates'], true)) {
        $month_data[$month_key]['dates'][] = $date;
    }
    if (!isset($month_data[$month_key]['products'][$product_key]['days'][$date])) {
        $month_data[$month_key]['products'][$product_key]['days'][$date] = [
            'pcs' => 0,
            'earned_minutes' => 0,
            'available_minutes' => 0,
            'profit' => 0,
        ];
    }
    $daily = &$month_data[$month_key]['products'][$product_key]['days'][$date];
    $daily['pcs'] += $pcs;
    $daily['earned_minutes'] += $earned_minutes;
    $daily['available_minutes'] += $available_minutes;
    $daily['profit'] += $profit;
    unset($daily);
}

$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Summary - Hameedia</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        :root { --primary: #217346; --dark: #1a2332; --steel: #6b7a8f; --glass: rgba(255,255,255,.18); --border: rgba(255,255,255,.35); --bad: #dc3545; }
        body { margin: 0; min-height: 100vh; color: var(--dark); font: 14px Inter, sans-serif; background: linear-gradient(135deg,#e8f5e9 0%,#c8e6c9 50%,#a5d6a7 100%); }
        .topbar { display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; padding:12px 30px; background:var(--glass); border-bottom:1px solid var(--border); }
        .brand { color:#1a5c3a; font-size:19px; font-weight:800; text-decoration:none; }
        nav { display:flex; gap:8px; }
        nav a,.user { color:var(--steel); text-decoration:none; font-weight:600; }
        nav a { padding:8px 14px; border-radius:9px; }
        nav a.active { background:var(--primary); color:white; }
        .user { font-size:12px; }
        main { max-width:1600px; margin:auto; padding:26px 30px 50px; }
        .heading { margin-bottom:18px; }
        h1 { margin:0 0 5px; font-size:23px; }
        .muted { color:var(--steel); }
        .filters { display:flex; align-items:end; gap:12px; flex-wrap:wrap; padding:16px; margin-bottom:20px; border:1px solid var(--border); border-radius:14px; background:var(--glass); }
        .field { display:grid; gap:5px; color:var(--steel); font-size:12px; font-weight:600; }
        input,select,button,.button { min-height:38px; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:rgba(255,255,255,.72); color:var(--dark); font:600 13px Inter,sans-serif; }
        button,.button { display:inline-flex; align-items:center; justify-content:center; cursor:pointer; text-decoration:none; }
        .primary { background:var(--primary); color:#fff; border-color:var(--primary); }
        .month-card { margin:22px 0; overflow:hidden; border:1px solid var(--border); border-radius:14px; background:var(--glass); box-shadow:0 6px 24px rgba(0,0,0,.07); }
        .month-title { padding:14px 18px; border-bottom:2px solid var(--primary); font-size:17px; font-weight:800; }
        .table-scroll { overflow:auto; max-height:72vh; }
        table { border-collapse:collapse; min-width:100%; width:max-content; font-size:11px; }
        th,td { min-width:72px; padding:8px 7px; border:1px solid rgba(255,255,255,.5); text-align:right; white-space:nowrap; }
        thead th { position:sticky; z-index:2; top:0; background:#e5f2e7; color:#314052; text-align:center; }
        thead tr:nth-child(2) th { top:34px; }
        .day-band-a { background-color:rgba(255,255,255,.2); }
        .day-band-b { background-color:rgba(33,115,70,.1); }
        thead .day-band-a { background-color:#e5f2e7; }
        thead .day-band-b { background-color:#d7eadb; }
        .day-end { border-right:2px solid rgba(33,115,70,.55); }
        .month-total-start { border-left:3px solid var(--primary); }
        .total-row td.day-band-a { background-color:rgba(33,115,70,.17); }
        .total-row td.day-band-b { background-color:rgba(33,115,70,.25); }
        th:first-child,td:first-child { position:sticky; z-index:1; left:0; min-width:155px; text-align:left; background:#e6f3e8; font-weight:700; }
        thead th:first-child { z-index:3; }
        .total-row td { background:rgba(33,115,70,.17); font-weight:800; }
        .negative { color:var(--bad); }
        .empty { padding:28px; text-align:center; color:var(--steel); }
        @media print { .topbar,.filters { display:none; } main { max-width:none; padding:0; } .table-scroll { max-height:none; overflow:visible; } .month-card { break-inside:avoid; } }
        @media (max-width:720px) {
            .topbar { justify-content:center; padding:12px 16px; }
            nav { flex-wrap:wrap; justify-content:center; }
            main { padding:20px 14px 36px; }
            .filters { align-items:stretch; }
            .field { flex:1 1 100%; }
            .field input,.field select { width:100%; }
            .filters button,.filters .button { flex:1 1 auto; }
            .month-title { padding:12px 14px; }
            th:first-child,td:first-child { min-width:135px; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <a class="brand" href="dashboard.php">HAMEEDIA</a>
        <nav>
            <a href="dashboard.php">Dashboard</a>
            <a class="active" href="reports.php">Reports</a>
            <a href="analytics.php">Analytics</a>
        </nav>
        <span class="user"><?php echo htmlspecialchars($current_user); ?></span>
    </header>
    <main>
        <div class="heading">
            <h1>Monthly Production Summary</h1>
            <div class="muted">Daily production and monthly totals from saved reports. Each month is shown separately.</div>
        </div>
        <form class="filters" method="get">
            <label class="field">From
                <input id="from-date" type="date" name="from" value="<?php echo htmlspecialchars($from_date); ?>">
            </label>
            <label class="field">To
                <input id="to-date" type="date" name="to" value="<?php echo htmlspecialchars($to_date); ?>">
            </label>
            <label class="field">Division
                <select id="division" name="division">
                    <option value="all" <?php echo $division_filter === 'all' ? 'selected' : ''; ?>>All divisions</option>
                    <?php foreach ($divisions as $division): ?>
                    <option value="<?php echo (int)$division['id']; ?>" <?php echo $division_filter === (string)$division['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($division_names[(int)$division['id']]); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="primary" type="submit">Apply</button>
            <button type="button" onclick="showToday()">Today</button>
            <a class="button" href="reports.php?from=<?php echo urlencode($from_date); ?>&amp;to=<?php echo urlencode($to_date); ?>&amp;division=<?php echo urlencode($division_filter); ?>">Back to Reports</a>
        </form>

        <?php foreach ($month_data as $month_key => $month): ?>
        <section class="month-card">
            <div class="month-title"><?php echo htmlspecialchars(date('F Y', strtotime($month_key . '-01'))); ?></div>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th rowspan="2">Product</th>
                            <?php foreach ($month['dates'] as $day_index => $date):
                                $day_band_class = $day_index % 2 === 0 ? 'day-band-a' : 'day-band-b';
                            ?>
                            <th colspan="4" class="<?php echo $day_band_class; ?> day-end"><?php echo htmlspecialchars(date('j M', strtotime($date))); ?></th>
                            <?php endforeach; ?>
                            <th colspan="4" class="month-total-start">Month Total / Average</th>
                        </tr>
                        <tr>
                            <?php foreach ($month['dates'] as $day_index => $_):
                                $day_band_class = $day_index % 2 === 0 ? 'day-band-a' : 'day-band-b';
                            ?>
                            <th class="<?php echo $day_band_class; ?>">Pcs</th>
                            <th class="<?php echo $day_band_class; ?>">Earn Min</th>
                            <th class="<?php echo $day_band_class; ?>">Eff %</th>
                            <th class="<?php echo $day_band_class; ?> day-end">Profit</th>
                            <?php endforeach; ?>
                            <th class="month-total-start">Pcs</th><th>Earn Min</th><th>Eff %</th><th>Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        sort($month['dates']);
                        if (empty($month['dates'])):
                        ?>
                        <tr><td colspan="5" class="empty">No production reports entered for this month.</td></tr>
                        <?php
                        else:
                        $month_totals = ['days' => [], 'pcs' => 0, 'earned_minutes' => 0, 'available_minutes' => 0, 'profit' => 0];
                        foreach ($product_rows as $product_key => $product_label):
                            $product = $month['products'][$product_key];
                            $row_totals = ['pcs' => 0, 'earned_minutes' => 0, 'available_minutes' => 0, 'profit' => 0];
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($product['name']); ?></td>
                            <?php foreach ($month['dates'] as $day_index => $date):
                                $day_band_class = $day_index % 2 === 0 ? 'day-band-a' : 'day-band-b';
                                $day = $month['products'][$product_key]['days'][$date] ?? null;
                                if ($day) {
                                    foreach (['pcs', 'earned_minutes', 'available_minutes', 'profit'] as $key) {
                                        $row_totals[$key] += $day[$key];
                                        if (!isset($month_totals['days'][$date])) {
                                            $month_totals['days'][$date] = ['pcs' => 0, 'earned_minutes' => 0, 'available_minutes' => 0, 'profit' => 0];
                                        }
                                        $month_totals['days'][$date][$key] += $day[$key];
                                    }
                                }
                                $eff = $day && $day['available_minutes'] > 0
                                    ? ($day['earned_minutes'] / $day['available_minutes']) * 100
                                    : 0;
                            ?>
                            <td class="<?php echo $day_band_class; ?>"><?php echo $day ? number_format($day['pcs'], 0) : '—'; ?></td>
                            <td class="<?php echo $day_band_class; ?>"><?php echo $day ? number_format($day['earned_minutes'], 1) : '—'; ?></td>
                            <td class="<?php echo $day_band_class; ?>"><?php echo $day && $day['available_minutes'] > 0 ? number_format($eff, 1) . '%' : '—'; ?></td>
                            <td class="<?php echo $day_band_class; ?> day-end <?php echo $day && $day['profit'] < 0 ? 'negative' : ''; ?>"><?php echo $day ? number_format($day['profit'], 0) : '—'; ?></td>
                            <?php endforeach;
                            $month_totals['pcs'] += $row_totals['pcs'];
                            $month_totals['earned_minutes'] += $row_totals['earned_minutes'];
                            $month_totals['available_minutes'] += $row_totals['available_minutes'];
                            // Profit is stored per assembly row as production value minus labor cost.
                            $month_totals['profit'] += $row_totals['profit'];
                            $row_eff = $row_totals['available_minutes'] > 0
                                ? ($row_totals['earned_minutes'] / $row_totals['available_minutes']) * 100
                                : 0;
                        ?>
                        <td><?php echo number_format($row_totals['pcs'], 0); ?></td>
                        <td><?php echo number_format($row_totals['earned_minutes'], 1); ?></td>
                        <td><?php echo $row_totals['available_minutes'] > 0 ? number_format($row_eff, 1) . '%' : '—'; ?></td>
                        <td class="<?php echo $row_totals['profit'] < 0 ? 'negative' : ''; ?>"><?php echo number_format($row_totals['profit'], 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php
                            $month_eff = $month_totals['available_minutes'] > 0
                                ? ($month_totals['earned_minutes'] / $month_totals['available_minutes']) * 100
                                : 0;
                        ?>
                        <tr class="total-row">
                            <td>All selected products</td>
                            <?php foreach ($month['dates'] as $day_index => $date):
                                $day_band_class = $day_index % 2 === 0 ? 'day-band-a' : 'day-band-b';
                                $day = $month_totals['days'][$date] ?? null;
                                $day_eff = $day && $day['available_minutes'] > 0
                                    ? ($day['earned_minutes'] / $day['available_minutes']) * 100
                                    : 0;
                            ?>
                            <td class="<?php echo $day_band_class; ?>"><?php echo number_format($day['pcs'] ?? 0, 0); ?></td>
                            <td class="<?php echo $day_band_class; ?>"><?php echo number_format($day['earned_minutes'] ?? 0, 1); ?></td>
                            <td class="<?php echo $day_band_class; ?>"><?php echo $day && $day['available_minutes'] > 0 ? number_format($day_eff, 1) . '%' : '—'; ?></td>
                            <td class="<?php echo $day_band_class; ?> day-end <?php echo ($day['profit'] ?? 0) < 0 ? 'negative' : ''; ?>"><?php echo number_format($day['profit'] ?? 0, 0); ?></td>
                            <?php endforeach; ?>
                            <td class="month-total-start"><?php echo number_format($month_totals['pcs'], 0); ?></td>
                            <td><?php echo number_format($month_totals['earned_minutes'], 1); ?></td>
                            <td title="Total earned minutes divided by total assembly available minutes"><?php echo $month_totals['available_minutes'] > 0 ? number_format($month_eff, 1) . '%' : '—'; ?></td>
                            <td class="<?php echo $month_totals['profit'] < 0 ? 'negative' : ''; ?>"><?php echo number_format($month_totals['profit'], 0); ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endforeach; ?>
    </main>
    <script>
        function localToday() {
            var now = new Date();
            return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
        }
        function showToday() {
            var today = localToday();
            document.getElementById('from-date').value = today;
            document.getElementById('to-date').value = today;
            document.querySelector('.filters').submit();
        }
    </script>
</body>
</html>
