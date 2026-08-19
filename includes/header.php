<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - Production Report</title>
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>assets/css/style.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="<?php echo SITE_URL; ?>assets/js/custom.js"></script>
</head>
<body>
    <div class="app-container">
        <?php if (isLoggedIn()): ?>
        <nav class="sidebar">
            <div class="sidebar-header">
                <h2>HAMEEDIA</h2>
                <p>Production System</p>
            </div>
            <ul class="nav-links">
                <li><a href="<?php echo SITE_URL; ?>dashboard.php">Dashboard</a></li>
                <li class="dropdown-header">Devotions</li>
                <?php 
                // Note: You need to implement getDevotionTypes() in functions.php to list all devitions
                // For now, we'll hardcode the 4 main divisions
                $devs = getDivisions(getDBConnection());
                foreach ($devs as $dev): 
                ?>
                <li><a href="<?php echo SITE_URL; ?>division_view.php?id=<?php echo $dev['id']; ?>"><?php echo $dev['name']; ?></a></li>
                <?php endforeach; ?>
                <li><a href="<?php echo SITE_URL; ?>reports.php">Reports</a></li>
                <?php if (isAdmin()): ?>
                <li><a href="<?php echo SITE_URL; ?>users.php">Users</a></li>
                <?php endif; ?>
                <li><a href="<?php echo SITE_URL; ?>logout.php">Logout</a></li>
            </ul>
        </nav>
        <div class="main-content">
            <header class="topbar">
                <div class="topbar-left">
                    <span class="user-name">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                </div>
                <div class="topbar-right">
                    <span class="date-display"><?php echo date('d-M-Y'); ?></span>
                </div>
            </header>
            <div class="content-wrapper">
        <?php endif; ?>