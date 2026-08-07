<?php
// index.php - Login Page with Green Theme
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password';
    } else {
        if (loginUser($username, $password)) {
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production Report - Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background: linear-gradient(135deg, #1a5c3a 0%, #217346 50%, #2d8f4e 100%);
            position: relative;
            overflow: hidden;
        }
        .bg-shapes {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
        }
        .shape {
            position: absolute;
            border-radius: 50%;
            opacity: 0.08;
            animation: float 20s infinite ease-in-out;
        }
        .shape-1 {
            width: 400px;
            height: 400px;
            background: #fff;
            top: -150px;
            right: -150px;
            animation-delay: 0s;
        }
        .shape-2 {
            width: 300px;
            height: 300px;
            background: #fff;
            bottom: -100px;
            left: -100px;
            animation-delay: -5s;
        }
        .shape-3 {
            width: 200px;
            height: 200px;
            background: #fff;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            animation-delay: -10s;
        }
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            25% { transform: translate(50px, -50px) scale(1.1); }
            50% { transform: translate(-30px, 30px) scale(0.9); }
            75% { transform: translate(20px, 20px) scale(1.05); }
        }
        .login-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 440px;
            padding: 20px;
        }
        .glass-container {
            background: rgba(255,255,255,0.12);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 48px 40px 40px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.3), inset 0 1px 0 rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.15);
            position: relative;
            overflow: hidden;
        }
        .glass-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: -50%;
            width: 200%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.05), transparent);
            transform: rotate(25deg);
            animation: shimmer 6s infinite;
        }
        @keyframes shimmer {
            0% { transform: translateX(-50%) rotate(25deg); }
            100% { transform: translateX(50%) rotate(25deg); }
        }
        .glass-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 30px 60px -12px rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.3);
        }
        .logo-section { text-align: center; margin-bottom: 32px; }
        .logo-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.15);
            border-radius: 24px;
            font-size: 40px;
            margin-bottom: 16px;
            border: 1px solid rgba(255,255,255,0.25);
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        .logo-icon:hover {
            transform: scale(1.1) rotate(-5deg);
            background: rgba(255,255,255,0.25);
        }
        .logo-section h1 {
            color: #fff;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.5px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .logo-section .subtitle {
            color: rgba(255,255,255,0.8);
            font-size: 14px;
            margin-top: 4px;
        }
        .error-message {
            background: rgba(255,0,0,0.15);
            border: 1px solid rgba(255,0,0,0.2);
            color: #fff;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.4s ease;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            color: rgba(255,255,255,0.9);
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 6px;
            letter-spacing: 0.3px;
        }
        .input-wrapper {
            position: relative;
            transition: all 0.3s ease;
        }
        .input-wrapper .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,0.5);
            font-size: 18px;
            transition: all 0.3s ease;
        }
        .input-wrapper input {
            width: 100%;
            padding: 14px 16px 14px 48px;
            background: rgba(255,255,255,0.08);
            border: 2px solid rgba(255,255,255,0.15);
            border-radius: 14px;
            color: #fff;
            font-size: 15px;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
        }
        .input-wrapper input::placeholder {
            color: rgba(255,255,255,0.4);
        }
        .input-wrapper input:focus {
            outline: none;
            background: rgba(255,255,255,0.12);
            border-color: rgba(255,255,255,0.4);
            box-shadow: 0 0 0 4px rgba(255,255,255,0.05);
        }
        .input-wrapper input:hover {
            background: rgba(255,255,255,0.12);
            border-color: rgba(255,255,255,0.25);
        }
        .btn-login {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #1a5c3a 0%, #217346 100%);
            border: none;
            border-radius: 14px;
            color: #fff;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
            margin-top: 8px;
            position: relative;
            overflow: hidden;
        }
        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }
        .btn-login:hover::before { left: 100%; }
        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 40px -12px rgba(33, 115, 70, 0.5);
        }
        .btn-login:active { transform: translateY(0px); }
        .login-footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.08);
        }
        .login-footer .default-creds {
            color: rgba(255,255,255,0.5);
            font-size: 12px;
        }
        .login-footer .default-creds strong {
            color: rgba(255,255,255,0.7);
            font-weight: 500;
        }
        .status-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            color: rgba(255,255,255,0.5);
            font-size: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .status-bar .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            display: inline-block;
            background: #4ade80;
            animation: blink 1.5s infinite;
        }
        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        .status-bar .weather-icon {
            display: inline-block;
            animation: weatherPulse 3s infinite;
        }
        @keyframes weatherPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        @media (max-width: 480px) {
            .glass-container { padding: 32px 24px 28px; }
            .logo-section h1 { font-size: 24px; }
            .logo-icon { width: 60px; height: 60px; font-size: 30px; }
            .input-wrapper input { padding: 12px 14px 12px 42px; font-size: 14px; }
        }
    </style>
</head>
<body>
    <div class="bg-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
    </div>

    <div class="login-wrapper">
        <div class="glass-container">
            <div class="logo-section">
                <div class="logo-icon">📊</div>
                <h1>Production Report</h1>
                <p class="subtitle">Hameedia - Hourly Production System</p>
            </div>

            <?php if (!empty($error)): ?>
            <div class="error-message">⚠️ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrapper">
                        <span class="input-icon">👤</span>
                        <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus value="admin">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <span class="input-icon">🔒</span>
                        <input type="password" id="password" name="password" placeholder="Enter your password" required value="admin123">
                    </div>
                </div>

                <button type="submit" class="btn-login">Sign In →</button>
            </form>

            <div class="login-footer">
                <p class="default-creds">Default: <strong>admin</strong> / <strong>admin123</strong></p>
            </div>

            <div class="status-bar">
                <span><span class="dot"></span> System Online</span>
                <span><span class="weather-icon">☀️</span> 31°C</span>
                <span id="currentTime">Loading...</span>
                <span id="currentDate">Loading...</span>
            </div>
        </div>
    </div>

    <script>
        function updateClock() {
            const now = new Date();
            document.getElementById('currentTime').textContent = now.toLocaleTimeString();
            document.getElementById('currentDate').textContent = now.toLocaleDateString();
        }
        updateClock();
        setInterval(updateClock, 1000);
    </script>
</body>
</html>