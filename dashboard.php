<?php
// dashboard.php - Division Selection
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

$conn = getDBConnection();
$divisions = getDivisions($conn);

// Group divisions by type
$grouped = [
    'shirt' => [],
    'trouser' => [],
    'coat' => [],
    'shirt_mtm' => [],
    'trouser_mtm' => [],
    'coat_mtm' => [],
    'assembly' => []
];

foreach ($divisions as $div) {
    if (isset($grouped[$div['type']])) {
        $grouped[$div['type']][] = $div;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Division - Hameedia</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #1a5c3a 0%, #217346 100%);
            color: #fff;
            padding: 20px 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .header h1 { font-size: 24px; }
        .header .subtitle { font-size: 14px; opacity: 0.8; margin-top: 4px; }
        .header .user-info { font-size: 14px; }
        .header .user-info a { color: #fff; text-decoration: none; opacity: 0.7; margin-left: 15px; }
        .header .user-info a:hover { opacity: 1; }
        
        .container { max-width: 1200px; margin: 0 auto; }
        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid #217346;
        }
        .division-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .division-card {
            background: #fff;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            cursor: pointer;
            border: 2px solid transparent;
            text-decoration: none;
            display: block;
            color: #333;
        }
        .division-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
            border-color: #217346;
        }
        .division-card .icon {
            font-size: 40px;
            margin-bottom: 12px;
        }
        .division-card .name {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .division-card .code {
            font-size: 13px;
            color: #999;
        }
        .division-card .badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 8px;
        }
        .badge-shirt { background: #e3f2fd; color: #1976d2; }
        .badge-trouser { background: #e8f5e9; color: #388e3c; }
        .badge-coat { background: #fff3e0; color: #f57c00; }
        .badge-mtm { background: #f3e5f5; color: #7b1fa2; }
        .badge-assembly { background: #fce4ec; color: #c62828; }
        
        .logout-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: #fff;
            padding: 8px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .logout-btn:hover { background: rgba(255,255,255,0.3); }
        
        @media (max-width: 768px) {
            .header { flex-direction: column; align-items: flex-start; }
            .division-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>📊 Production Report System</h1>
            <div class="subtitle">Hameedia Clothing Company - Select Division</div>
        </div>
        <div class="user-info">
            👤 <?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?>
            <a href="logout.php">🚪 Logout</a>
        </div>
    </div>

    <div class="container">
        <!-- Shirt Division -->
        <?php if (!empty($grouped['shirt']) || !empty($grouped['shirt_mtm'])): ?>
        <div class="section-title">👔 Shirt Division</div>
        <div class="division-grid">
            <?php foreach ($grouped['shirt'] as $div): ?>
            <a href="division_view.php?id=<?php echo $div['id']; ?>" class="division-card">
                <div class="icon">👔</div>
                <div class="name"><?php echo htmlspecialchars($div['name']); ?></div>
                <div class="code">Code: <?php echo htmlspecialchars($div['code']); ?></div>
                <span class="badge badge-shirt">Ready-made</span>
            </a>
            <?php endforeach; ?>
            <?php foreach ($grouped['shirt_mtm'] as $div): ?>
            <a href="division_view.php?id=<?php echo $div['id']; ?>" class="division-card">
                <div class="icon">✂️</div>
                <div class="name"><?php echo htmlspecialchars($div['name']); ?></div>
                <div class="code">Code: <?php echo htmlspecialchars($div['code']); ?></div>
                <span class="badge badge-mtm">Made to Measure</span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Trouser Division -->
        <?php if (!empty($grouped['trouser']) || !empty($grouped['trouser_mtm'])): ?>
        <div class="section-title">👖 Trouser Division</div>
        <div class="division-grid">
            <?php foreach ($grouped['trouser'] as $div): ?>
            <a href="division_view.php?id=<?php echo $div['id']; ?>" class="division-card">
                <div class="icon">👖</div>
                <div class="name"><?php echo htmlspecialchars($div['name']); ?></div>
                <div class="code">Code: <?php echo htmlspecialchars($div['code']); ?></div>
                <span class="badge badge-trouser">Ready-made</span>
            </a>
            <?php endforeach; ?>
            <?php foreach ($grouped['trouser_mtm'] as $div): ?>
            <a href="division_view.php?id=<?php echo $div['id']; ?>" class="division-card">
                <div class="icon">✂️</div>
                <div class="name"><?php echo htmlspecialchars($div['name']); ?></div>
                <div class="code">Code: <?php echo htmlspecialchars($div['code']); ?></div>
                <span class="badge badge-mtm">Made to Measure</span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Coat Division -->
        <?php if (!empty($grouped['coat']) || !empty($grouped['coat_mtm'])): ?>
        <div class="section-title">🧥 Coat Division</div>
        <div class="division-grid">
            <?php foreach ($grouped['coat'] as $div): ?>
            <a href="division_view.php?id=<?php echo $div['id']; ?>" class="division-card">
                <div class="icon">🧥</div>
                <div class="name"><?php echo htmlspecialchars($div['name']); ?></div>
                <div class="code">Code: <?php echo htmlspecialchars($div['code']); ?></div>
                <span class="badge badge-coat">Ready-made</span>
            </a>
            <?php endforeach; ?>
            <?php foreach ($grouped['coat_mtm'] as $div): ?>
            <a href="division_view.php?id=<?php echo $div['id']; ?>" class="division-card">
                <div class="icon">✂️</div>
                <div class="name"><?php echo htmlspecialchars($div['name']); ?></div>
                <div class="code">Code: <?php echo htmlspecialchars($div['code']); ?></div>
                <span class="badge badge-mtm">Made to Measure</span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Assembly Division -->
        <?php if (!empty($grouped['assembly'])): ?>
        <div class="section-title">🏭 Assembly Division</div>
        <div class="division-grid">
            <?php foreach ($grouped['assembly'] as $div): ?>
            <a href="division_view.php?id=<?php echo $div['id']; ?>" class="division-card">
                <div class="icon">🏭</div>
                <div class="name"><?php echo htmlspecialchars($div['name']); ?></div>
                <div class="code">Code: <?php echo htmlspecialchars($div['code']); ?></div>
                <span class="badge badge-assembly">Assembly</span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>