<?php
session_start();
require_once 'auth_config.php';

// Admin Password Configuration
$admin_password = 'micamica';

// Handle Login
if (isset($_POST['admin_password'])) {
    if ($_POST['admin_password'] === $admin_password) {
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

// Fetch Users
$stmt_users = $pdo->query("SELECT * FROM local_users ORDER BY created_at DESC");
$users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

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

    <h2>Registered Users</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
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
</div>

</body>
</html>
