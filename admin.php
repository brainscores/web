<?php
session_start();
require_once 'auth_config.php';

// Admin Password Configuration
// Hash for Admin Password
$admin_password_hash = '$2y$12$FLy0jXy1oSYUvVo1G8.aqeJ2sGY3tG3yGnVDmY0ez38d4r5C1H8ly';

// Handle Login
if (isset($_POST['admin_password'])) {
    if (password_verify($_POST['admin_password'], $admin_password_hash)) {
        $_SESSION['admin_dashboard_auth'] = true;
    } else {
        $error = "Invalid admin password.";
    }
}

// Handle Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_dashboard_auth']);
    header("Location: admin.php");
    exit;
}

// Check Authentication
if (!isset($_SESSION['admin_dashboard_auth']) || $_SESSION['admin_dashboard_auth'] !== true) {
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Admin Login - BRAINSCORES</title>
    <link rel="stylesheet" href="assets/base.css">
    <style>
        body { font-family: 'Hind', sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; background-color: #f4f4f4; margin: 0; }
        .login-box { background: white; padding: 30px; border-radius: 5px; box-shadow: 0 0 10px rgba(0,0,0,0.1); width: 300px; text-align: center; }
        input[type="password"] { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; box-sizing: border-box; }
        input[type="submit"] { background: #333; color: white; border: none; padding: 10px 20px; cursor: pointer; width: 100%; }
        input[type="submit"]:hover { background: #555; }
        .error { color: red; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>Admin Login</h2>
        <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
        <form method="post">
            <input type="password" name="admin_password" placeholder="Enter Admin Password" required autofocus>
            <input type="submit" value="Login">
        </form>
        <p style="margin-top: 20px;"><a href="index.html">Back to Home</a></p>
    </div>
</body>
</html>
<?php
    exit;
}

$pdo = init_db();

// Handle Cleanup Action
$cleanup_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cleanup_server') {
    try {
        // 1. Clean DB
        $pdo->exec("DELETE FROM local_users");
        $pdo->exec("DELETE FROM uploads");
        $pdo->exec("DELETE FROM sqlite_sequence WHERE name='local_users'");
        $pdo->exec("DELETE FROM sqlite_sequence WHERE name='uploads'");
        $pdo->exec("VACUUM");

        // 2. Clean Uploads Folder
        if (file_exists('/var/www/brainscoresai/upload/')) {
            $upload_dir = '/var/www/brainscoresai/upload/';
        } else {
            $upload_dir = __DIR__ . '/uploads/';
        }
        
        $deleted_files = 0;
        if (file_exists($upload_dir)) {
            $files = glob($upload_dir . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                    $deleted_files++;
                }
            }
        }
        
        $cleanup_message = "Cleanup successful! Database cleared and $deleted_files files deleted.";
    } catch (Exception $e) {
        $cleanup_message = "Error during cleanup: " . $e->getMessage();
    }
}

// Handle Add Invite Code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_invite_code') {
    $new_code = trim($_POST['new_code']);
    if (!empty($new_code)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO invite_codes (code) VALUES (?)");
            $stmt->execute([$new_code]);
            $cleanup_message = "Invite code '$new_code' added successfully.";
        } catch (Exception $e) {
            $cleanup_message = "Error adding invite code: " . $e->getMessage();
        }
    } else {
        $cleanup_message = "Invite code cannot be empty.";
    }
}

// Fetch Users
$stmt_users = $pdo->query("SELECT * FROM local_users ORDER BY created_at DESC");
$users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

// Fetch Invite Codes
$stmt_codes = $pdo->query("SELECT * FROM invite_codes ORDER BY created_at DESC");
$invite_codes = $stmt_codes->fetchAll(PDO::FETCH_ASSOC);

// Fetch Uploads
$stmt_uploads = $pdo->query("SELECT * FROM uploads ORDER BY uploaded_at DESC");
$uploads = $stmt_uploads->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>BRAINSCORES - Admin Dashboard</title>
    <link rel="stylesheet" href="assets/base.css">
    <link rel="stylesheet" href="fixed-navigation-bar.css">
    <link href='https://fonts.googleapis.com/css?family=Hind:400,500,600,300,700' rel='stylesheet' type='text/css'>
    <style>
        body { background-color: #f4f4f4; }
        .admin-container {
            max-width: 1200px;
            margin: 100px auto;
            padding: 20px;
            background: white;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1, h2 { color: #333; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f8f8f8; font-weight: 600; }
        tr:hover { background-color: #f5f5f5; }
        .badge {
            padding: 5px 10px;
            border-radius: 4px;
            background: #e7f3fe;
            color: #31708f;
            font-size: 0.85em;
        }
    </style>
</head>
<body>

<nav class="fixed-nav-bar">
  <div id="menu" class="menu">
    <a class="sitename" href="index.html">BRAINSCORES</a>
    <ul class="menu-items">
      <li><a href="upload.php">UPLOAD</a></li>
      <li><a href="admin.php?logout=true">LOGOUT</a></li>
    </ul>
  </div>
</nav>

<div class="admin-container">
    <h1>Admin Dashboard</h1>
    <p>Logged in as Administrator</p>
    
    <?php if (!empty($cleanup_message)): ?>
        <div style="background: #e8f5e9; border: 1px solid #c8e6c9; color: #2e7d32; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
            <?php echo htmlspecialchars($cleanup_message); ?>
        </div>
    <?php endif; ?>

    <h2>Invite Codes</h2>
    <div style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #eee; border-radius: 4px;">
        <form method="post" style="display: flex; gap: 10px; align-items: center;">
            <input type="hidden" name="action" value="add_invite_code">
            <input type="text" name="new_code" placeholder="Enter new invite code" required style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; flex-grow: 1; max-width: 300px;">
            <input type="submit" value="Add Code" style="background: #333; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">
        </form>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Code</th>
                <th>Status</th>
                <th>Created At</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($invite_codes as $code): ?>
            <tr>
                <td><?php echo $code['id']; ?></td>
                <td><span class="badge"><?php echo htmlspecialchars($code['code']); ?></span></td>
                <td><?php echo $code['is_active'] ? 'Active' : 'Inactive'; ?></td>
                <td><?php echo $code['created_at']; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Registered Users</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Invite Code Used</th>
                <th>Created At</th>
                <th>Last Login</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
            <tr>
                <td><?php echo $user['id']; ?></td>
                <td><?php echo htmlspecialchars($user['name']); ?></td>
                <td><?php echo htmlspecialchars($user['email']); ?></td>
                <td><?php echo htmlspecialchars($user['invite_code_used'] ?? ''); ?></td>
                <td><?php echo $user['created_at']; ?></td>
                <td><?php echo $user['last_login']; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Upload History</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Uploader</th>
                <th>Filename</th>
                <th>Size</th>
                <th>Subject ID</th>
                <th>Age</th>
                <th>Sex</th>
                <th>Uploaded At</th>
                <th>Duration (s)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($uploads as $upload): ?>
            <tr>
                <td><?php echo $upload['id']; ?></td>
                <td><?php echo htmlspecialchars($upload['user_email']); ?></td>
                <td><?php echo htmlspecialchars($upload['filename']); ?></td>
                <td><?php echo isset($upload['file_size']) ? number_format($upload['file_size'] / 1024 / 1024, 2) . ' MB' : 'N/A'; ?></td>
                <td><?php echo htmlspecialchars($upload['subject_id']); ?></td>
                <td><?php echo htmlspecialchars($upload['subject_age']); ?></td>
                <td><?php echo htmlspecialchars($upload['subject_sex']); ?></td>
                <td><?php echo $upload['uploaded_at']; ?></td>
                <td><?php echo isset($upload['upload_duration_seconds']) ? $upload['upload_duration_seconds'] : 'N/A'; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="margin-top: 50px; padding: 20px; border: 1px solid #ffcdd2; background-color: #ffebee; border-radius: 5px;">
        <h2 style="color: #c62828; margin-top: 0;">Danger Zone</h2>
        <p>This action will delete <strong>ALL</strong> users and <strong>ALL</strong> uploaded files. This cannot be undone.</p>
        <form method="post" onsubmit="return confirm('ARE YOU SURE? This will wipe all data and files permanently.');">
            <input type="hidden" name="action" value="cleanup_server">
            <input type="submit" value="Reset Server & Delete All Data" style="background-color: #c62828; color: white; border: none; padding: 10px 20px; cursor: pointer;">
        </form>
    </div>
</div>

</body>
</html>
