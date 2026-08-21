<?php
// users.php - Users Management Page - WITH LOGO
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

// Only admin can access this page
if (!isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$conn = getDB();
$users = getAllUsers($conn);
$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users - Hameedia</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #217346;
            --primary-dark: #1a5c3a;
            --bg: #f0f2f5;
            --card: rgba(255,255,255,0.85);
            --text: #1a2332;
            --text-dark: #0d1a2b;
            --steel: #6b7a8f;
            --line: rgba(255,255,255,0.2);
            --border-radius: 16px;
            --shadow: 0 8px 32px rgba(0,0,0,0.08);
            --glass-border: rgba(255,255,255,0.3);
            --glass-bg: rgba(255,255,255,0.15);
            --bad: #dc3545;
            --good: #28a745;
            --amber: #f57c00;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 50%, #a5d6a7 100%);
            min-height: 100vh;
            color: var(--text);
            position: relative;
        }
        .bg-shapes {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
            pointer-events: none;
        }
        .shape {
            position: absolute;
            border-radius: 50%;
            opacity: 0.08;
            animation: float 25s infinite ease-in-out;
        }
        .shape-1 { width: 500px; height: 500px; background: var(--primary); top: -150px; right: -150px; }
        .shape-2 { width: 300px; height: 300px; background: var(--primary); bottom: -100px; left: -100px; animation-delay: -8s; }
        .shape-3 { width: 200px; height: 200px; background: var(--primary); top: 50%; left: 50%; transform: translate(-50%, -50%); animation-delay: -15s; }
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            25% { transform: translate(60px, -60px) scale(1.1); }
            50% { transform: translate(-40px, 40px) scale(0.9); }
            75% { transform: translate(30px, 30px) scale(1.05); }
        }
        
        .topbar {
            position: relative;
            z-index: 10;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--glass-border);
            padding: 10px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }
        .topbar .logo-mark { 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            font-weight: 800; 
            font-size: 20px; 
            color: var(--primary-dark);
            text-decoration: none;
        }
        .topbar .logo-mark .logo-icon { 
            font-size: 32px;
            background: var(--primary);
            color: #fff;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            font-weight: 700;
            font-size: 18px;
        }
        .topbar .logo-mark .logo-text {
            letter-spacing: -0.5px;
        }
        .topbar .logo-mark .logo-text span {
            color: var(--primary);
        }
        .topnav { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .topnav a {
            color: var(--steel);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            padding: 7px 16px;
            border-radius: 10px;
            transition: all 0.3s;
            background: transparent;
        }
        .topnav a:hover { color: var(--primary); background: rgba(33, 115, 70, 0.08); }
        .topnav a.active { color: #fff; background: var(--primary); box-shadow: 0 4px 15px rgba(33, 115, 70, 0.3); }
        .right { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; font-size: 13px; color: var(--steel); }
        .live-chip { display: flex; align-items: center; gap: 6px; background: rgba(33, 115, 70, 0.1); padding: 4px 12px; border-radius: 20px; font-size: 12px; color: var(--primary); font-weight: 600; }
        .live-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--good); animation: blink 1.5s infinite; }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
        .logout { color: var(--steel); text-decoration: none; padding: 5px 14px; border-radius: 8px; transition: all 0.3s; background: rgba(255,255,255,0.5); font-weight: 600; font-size: 13px; }
        .logout:hover { background: rgba(220, 53, 69, 0.1); color: var(--bad); }
        .date-display { color: var(--text-dark); font-size: 13px; font-weight: 600; }
        .user-name { color: var(--text-dark); font-weight: 600; font-size: 13px; }
        .admin-badge { font-size: 9px; background: var(--primary); color: #fff; padding: 2px 8px; border-radius: 10px; font-weight: 600; }

        .wrap { position: relative; z-index: 5; max-width: 1200px; margin: 0 auto; padding: 30px; }
        
        .users-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
        .users-head h2 { font-size: 24px; font-weight: 800; color: var(--text-dark); }
        .users-head p { color: var(--steel); font-size: 14px; font-weight: 500; margin-top: 4px; }
        
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 18px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 10px;
            color: var(--text-dark);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }
        .back-button:hover {
            background: rgba(255,255,255,0.3);
            transform: translateX(-4px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .add-user-form {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr auto;
            gap: 12px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 24px;
            align-items: end;
            transition: all 0.3s ease;
        }
        .add-user-form:hover { box-shadow: 0 12px 40px rgba(0,0,0,0.1); }
        .add-user-form .field { display: flex; flex-direction: column; gap: 4px; }
        .add-user-form .field label { font-size: 13px; font-weight: 700; color: var(--steel); }
        .add-user-form input, .add-user-form select {
            padding: 8px 10px;
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            font-family: 'Inter', sans-serif;
            width: 100%;
            background: rgba(255,255,255,0.7);
        }
        .add-user-form input:focus, .add-user-form select:focus { outline: none; border-color: var(--primary); }
        
        .btn-amber {
            padding: 8px 24px;
            background: var(--amber);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
            height: 40px;
        }
        .btn-amber:hover { background: #e65100; transform: translateY(-2px); box-shadow: 0 4px 15px rgba(245, 124, 0, 0.3); }
        .btn-ghost {
            padding: 8px 20px;
            background: rgba(255,255,255,0.3);
            color: var(--steel);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
        }
        .btn-ghost:hover { background: rgba(255,255,255,0.5); }
        .btn-danger {
            padding: 4px 12px;
            background: var(--bad);
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
        }
        .btn-danger:hover { background: #c82333; transform: scale(1.05); }
        .btn-outline-sm {
            padding: 4px 12px;
            background: rgba(255,255,255,0.3);
            color: var(--primary);
            border: 1px solid var(--primary);
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
        }
        .btn-outline-sm:hover { background: var(--primary); color: #fff; }
        
        .users-table {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            width: 100%;
        }
        .users-table table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .users-table th {
            background: rgba(255,255,255,0.3);
            padding: 12px 16px;
            text-align: left;
            font-weight: 700;
            color: var(--steel);
            border-bottom: 1px solid var(--glass-border);
            font-size: 13px;
        }
        .users-table td {
            padding: 10px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            font-weight: 500;
        }
        .users-table tr:hover { background: rgba(255,255,255,0.2); }
        .role-badge {
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .role-admin { background: rgba(33,115,70,0.15); color: var(--primary); }
        .role-user { background: rgba(0,0,0,0.05); color: var(--steel); }
        .status-active { color: var(--good); font-weight: 600; }
        .password-hidden { font-family: 'Courier New', monospace; color: var(--steel); letter-spacing: 2px; font-weight: 600; }
        .actions { display: flex; gap: 6px; }
        
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 24px;
            border-radius: 8px;
            color: #fff;
            font-weight: 600;
            font-size: 14px;
            z-index: 9999;
            opacity: 0;
            transform: translateY(-20px);
            transition: all 0.3s;
        }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast.success { background: var(--good); }
        .toast.error { background: var(--bad); }
        
        .modal-layer {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal-layer.show { display: flex; }
        .modal-box {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 32px;
            border-radius: 16px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            border: 1px solid var(--glass-border);
        }
        .modal-box h3 { font-size: 20px; font-weight: 800; margin-bottom: 8px; color: var(--text-dark); }
        .modal-box .sub { color: var(--steel); font-size: 14px; font-weight: 500; margin-bottom: 20px; }
        .modal-box .field { margin-bottom: 16px; }
        .modal-box .field label { display: block; font-size: 13px; font-weight: 700; color: var(--steel); margin-bottom: 4px; }
        .modal-box .field input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            font-family: 'Inter', sans-serif;
            background: rgba(255,255,255,0.7);
        }
        .modal-box .field input:focus { outline: none; border-color: var(--primary); }
        .modal-actions { display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px; }
        
        @media (max-width: 768px) {
            .topbar { padding: 10px 16px; flex-direction: column; align-items: stretch; gap: 8px; }
            .topnav { justify-content: center; }
            .right { justify-content: center; }
            .wrap { padding: 16px; }
            .add-user-form { grid-template-columns: 1fr; }
            .add-user-form .field { width: 100%; }
            .add-user-form .btn-amber { width: 100%; }
            .users-table { overflow-x: auto; }
            .topnav a { padding: 6px 12px; font-size: 13px; }
        }
    </style>
</head>
<body>
    <div class="bg-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
    </div>

    <div class="topbar">
        <a href="dashboard.php" class="logo-mark">
            <span class="logo-icon">H</span>
            <span class="logo-text">HAMEEDIA</span>
        </a>
        <nav class="topnav">
            <a href="dashboard.php">Dashboard</a>
            <a href="reports.php">Reports</a>
            <a href="analytics.php">Analytics</a>
            <a href="users.php" class="active">Users</a>
        </nav>
        <div class="right">
            <span class="live-chip"><span class="live-dot"></span><span id="live-clock">--:--</span></span>
            <span class="date-display"><?php echo date('M d, Y'); ?></span>
            <span class="user-name"><?php echo htmlspecialchars($current_user); ?></span>
            <?php if (isAdmin()): ?>
            <span class="admin-badge">Admin</span>
            <?php endif; ?>
            <a href="logout.php" class="logout">Sign out</a>
        </div>
    </div>

    <div class="wrap">
        <a href="#" class="back-button" onclick="history.back(); return false;">
            ← Back
        </a>

        <div class="users-head">
            <div>
                <h2>Users</h2>
                <p>Who can sign in and set up master data.</p>
            </div>
        </div>

        <div class="add-user-form">
            <div class="field">
                <label>Full name</label>
                <input id="user-name" type="text" placeholder="e.g. Ishara Fonseka">
            </div>
            <div class="field">
                <label>Username</label>
                <input id="user-username" type="text" placeholder="e.g. i.fonseka">
            </div>
            <div class="field">
                <label>Password</label>
                <div style="position:relative;">
                    <input id="user-password" type="password" placeholder="Min 6 characters" style="width:100%;padding:8px 34px 8px 10px;border:1px solid var(--glass-border);border-radius:8px;font-size:14px;font-weight:500;font-family:'Inter';background:rgba(255,255,255,0.7);">
                    <button type="button" onclick="togglePasswordVisibility('user-password',this)" 
                        style="position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--steel);font-size:12px;font-weight:600;">Show</button>
                </div>
            </div>
            <div class="field">
                <label>Role</label>
                <select id="user-role">
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <button class="btn-amber" onclick="addUser()">Add user</button>
        </div>
        <div id="user-form-error" style="display:none;color:var(--bad);font-size:13px;font-weight:600;margin:-10px 0 16px;"></div>

        <div class="users-table">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Password</th>
                        <th>Status</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="users-body">
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></td>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td><span class="role-badge <?php echo $user['role'] === 'admin' ? 'role-admin' : 'role-user'; ?>"><?php echo ucfirst($user['role']); ?></span></td>
                        <td><span class="password-hidden">••••••••</span></td>
                        <td><span class="status-active">Active</span></td>
                        <td style="text-align:right;">
                            <div class="actions" style="justify-content:flex-end;">
                                <button class="btn-outline-sm" onclick="openPasswordModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')">Set password</button>
                                <?php if ($user['role'] !== 'admin'): ?>
                                <button class="btn-danger" onclick="deleteUser(<?php echo $user['id']; ?>)">Delete</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Password Reset Modal -->
    <div class="modal-layer" id="password-modal">
        <div class="modal-box">
            <h3 id="pw-modal-title">Set password</h3>
            <p class="sub">The user will use this the next time they sign in.</p>
            <div class="field">
                <label>New password</label>
                <div style="position:relative;">
                    <input id="pw-modal-input" type="password" placeholder="Min 6 characters" style="width:100%;padding:10px 34px 10px 12px;border:1px solid var(--glass-border);border-radius:8px;font-size:14px;font-weight:500;font-family:'Inter';background:rgba(255,255,255,0.7);">
                    <button type="button" onclick="togglePasswordVisibility('pw-modal-input',this)" 
                        style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--steel);font-size:12px;font-weight:600;">Show</button>
                </div>
            </div>
            <div id="pw-modal-error" style="display:none;color:var(--bad);font-size:13px;font-weight:600;margin-top:-8px;">Enter at least 6 characters.</div>
            <div class="modal-actions">
                <button class="btn-ghost" onclick="closePasswordModal()">Cancel</button>
                <button class="btn-amber" onclick="savePassword()">Save password</button>
            </div>
        </div>
    </div>

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

        let currentUserId = null;

        function togglePasswordVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                btn.textContent = 'Hide';
            } else {
                input.type = 'password';
                btn.textContent = 'Show';
            }
        }

        function showToast(message, type) {
            const toast = document.getElementById('toast');
            if (!toast) {
                const newToast = document.createElement('div');
                newToast.id = 'toast';
                newToast.className = 'toast ' + type;
                newToast.textContent = message;
                document.body.appendChild(newToast);
                setTimeout(() => { newToast.className = 'toast'; }, 3000);
            } else {
                toast.textContent = message;
                toast.className = 'toast ' + type + ' show';
                setTimeout(() => { toast.className = 'toast'; }, 3000);
            }
        }

        function addUser() {
            const name = document.getElementById('user-name').value;
            const username = document.getElementById('user-username').value;
            const password = document.getElementById('user-password').value;
            const role = document.getElementById('user-role').value;
            const errorDiv = document.getElementById('user-form-error');

            if (!name || !username || !password) {
                errorDiv.style.display = 'block';
                errorDiv.textContent = 'All fields are required';
                return;
            }
            if (password.length < 6) {
                errorDiv.style.display = 'block';
                errorDiv.textContent = 'Password must be at least 6 characters';
                return;
            }
            errorDiv.style.display = 'none';

            fetch('users.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=add_user&full_name=' + encodeURIComponent(name) + 
                      '&username=' + encodeURIComponent(username) + 
                      '&password=' + encodeURIComponent(password) + 
                      '&role=' + encodeURIComponent(role)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    errorDiv.style.display = 'block';
                    errorDiv.textContent = data.message;
                }
            })
            .catch(err => {
                showToast('Error: ' + err.message, 'error');
            });
        }

        function deleteUser(id) {
            if (!confirm('Are you sure you want to delete this user?')) return;

            fetch('users.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=delete_user&user_id=' + id
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(err => {
                showToast('Error: ' + err.message, 'error');
            });
        }

        function openPasswordModal(userId, username) {
            currentUserId = userId;
            document.getElementById('pw-modal-title').textContent = 'Set password for ' + username;
            document.getElementById('pw-modal-input').value = '';
            document.getElementById('pw-modal-error').style.display = 'none';
            document.getElementById('password-modal').classList.add('show');
        }

        function closePasswordModal() {
            document.getElementById('password-modal').classList.remove('show');
            currentUserId = null;
        }

        function savePassword() {
            const password = document.getElementById('pw-modal-input').value;
            const errorDiv = document.getElementById('pw-modal-error');

            if (!password || password.length < 6) {
                errorDiv.style.display = 'block';
                errorDiv.textContent = 'Password must be at least 6 characters';
                return;
            }
            errorDiv.style.display = 'none';

            fetch('users.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=reset_password&user_id=' + currentUserId + 
                      '&password=' + encodeURIComponent(password)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    closePasswordModal();
                } else {
                    errorDiv.style.display = 'block';
                    errorDiv.textContent = data.message;
                }
            })
            .catch(err => {
                showToast('Error: ' + err.message, 'error');
            });
        }

        document.getElementById('password-modal').addEventListener('click', function(e) {
            if (e.target === this) closePasswordModal();
        });
    </script>
</body>
</html>