<?php
// debug.php - Server Diagnostics
// Save this as debug.php and upload it to your server.

// 1. Enable Error Reporting immediately
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Server Diagnostics</h1>";

// 2. Check PHP Version
echo "<h2>PHP Version</h2>";
echo "Current PHP Version: " . phpversion() . "<br>";
if (version_compare(phpversion(), '5.6.0', '<')) {
    echo "<strong style='color:red'>WARNING: PHP version is very old. Upgrade recommended.</strong><br>";
} else {
    echo "<strong style='color:green'>OK: PHP version is acceptable.</strong><br>";
}

// 3. Check Required Extensions
echo "<h2>Extensions</h2>";
$required_extensions = ['sqlite3', 'pdo_sqlite', 'curl', 'json', 'mbstring'];
foreach ($required_extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "$ext: <span style='color:green'>Installed</span><br>";
    } else {
        echo "$ext: <span style='color:red'>MISSING</span> - This is required!<br>";
    }
}

// 4. File Permissions & Existence
echo "<h2>Files & Permissions</h2>";
$files = [
    'upload.php',
    'auth_config.php',
    'email_config.php',
    'users.db', // might not exist yet
    'uploads'   // Directory
];

foreach ($files as $file) {
    if (file_exists($file)) {
        echo "$file: <span style='color:green'>Found</span>";
        if (is_writable($file)) {
            echo " [Writable]";
        } else {
            echo " <span style='color:orange'>[Not Writable - might be an issue for DB/uploads]</span>";
        }
        echo "<br>";
    } else {
         if ($file === 'users.db') {
             echo "$file: <span style='color:gray'>Not created yet (Normal if first run)</span><br>";
             // Check if directory is writable for creation
             if (is_writable('.')) {
                 echo "Current Directory (.): <span style='color:green'>Writable (Can create DB)</span><br>";
             } else {
                 echo "Current Directory (.): <span style='color:red'>NOT WRITABLE (Cannot create database!)</span><br>";
             }
         } else {
            echo "$file: <span style='color:red'>MISSING</span><br>";
         }
    }
}

// 5. Check Include Logic (Attempt to load verify syntax)
echo "<h2>Include Test</h2>";
try {
    include 'auth_config.php';
    echo "auth_config.php included successfully.<br>";
} catch (Exception $e) {
    echo "Error including auth_config.php: " . $e->getMessage() . "<br>";
}

try {
    include 'email_config.php';
    echo "email_config.php included successfully.<br>";
} catch (Exception $e) {
    echo "Error including email_config.php: " . $e->getMessage() . "<br>";
}

echo "<hr>";
echo "<h3>Diagnostics Complete.</h3>";
?>
