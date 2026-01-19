<?php
// Production Settings: Disable error display to users
error_reporting(0);
ini_set('display_errors', 0);

// Set secure session cookie parameters
session_set_cookie_params([
    'secure' => true,        // Ensure the cookie is sent over HTTPS
    'httponly' => true,      // Prevent JavaScript from accessing the session cookie
    'SameSite' => 'Strict',  // Prevent cross-site request forgery (CSRF) attacks
]);

require_once 'auth_config.php';

// Ensure session is started (handled in auth_config.php but good to be safe if moved)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define the final destination directory
if (file_exists('/var/www/brainscoresai/upload/')) {
    $upload_dir = '/var/www/brainscoresai/upload/';
} else {
    $upload_dir = __DIR__ . '/uploads/';
}

// Ensure upload directory exists
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// --- BACKEND LOGIC ---

// 1. Handle AJAX Chunk Upload
if (isset($_GET['action']) && $_GET['action'] === 'chunk_upload') {
    // Basic Auth Check
    if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
         http_response_code(403);
         echo json_encode(['error' => 'Not authenticated']);
         exit;
    }

    // CSRF Check for AJAX
    // We expect the token in the POST data or Headers. For this simple implementation, let's look in POST.
    // Note: In a chunked upload, it might be cleaner to check auth only, but checking CSRF on the initial handshake is better. 
    // Here we'll skip CSRF for the binary chunks for performance/complexity reasons, 
    // relying on the session auth which is Strict SameSite.
    
    $fileName = $_POST['filename'];
    $chunkIndex = (int)$_POST['chunkIndex'];
    $totalChunks = (int)$_POST['totalChunks'];
    
    $fileId = md5($_SESSION['user_email'] . $fileName . $_POST['uploadTimestamp']); 
    $tempDir = $upload_dir . 'temp_' . $fileId;
    
    if (!file_exists($tempDir)) {
        if (!mkdir($tempDir)) {
             echo json_encode(['error' => 'Failed to create temp dir']);
             exit;
        }
    }
    
    $tempFilePath = $tempDir . "/chunk_" . $chunkIndex;
    if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $tempFilePath)) {
        echo json_encode(['error' => 'Failed to move chunk']);
        exit;
    }
    
    $uploadedChunks = count(glob("$tempDir/chunk_*"));
    
    if ($uploadedChunks === $totalChunks) {
        $finalPath = $upload_dir . $fileName;
        
        if (file_exists($finalPath)) {
            $pathInfo = pathinfo($finalPath);
            $finalPath = $upload_dir . $pathInfo['filename'] . '_' . time() . '.' . $pathInfo['extension'];
        }
        
        $outFile = fopen($finalPath, 'wb');
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = $tempDir . "/chunk_" . $i;
            fwrite($outFile, file_get_contents($chunkPath));
            unlink($chunkPath);
        }
        fclose($outFile);
        rmdir($tempDir);
        
        // Metadata & DB
        $uploader_email = $_SESSION['user_email'] ?? 'unknown';
        $subject_id = htmlspecialchars($_POST['subject_id'] ?? '');
        $subject_age = (int)($_POST['subject_age'] ?? 0);
        $subject_sex = htmlspecialchars($_POST['subject_sex'] ?? '');
        $upload_duration = (int)($_POST['duration'] ?? 0);
        $file_size = filesize($finalPath);

        try {
            $pdo = init_db();
            $stmt = $pdo->prepare("INSERT INTO uploads (user_email, filename, file_size, subject_id, subject_age, subject_sex, status, upload_duration_seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$uploader_email, basename($finalPath), $file_size, $subject_id, $subject_age, $subject_sex, 'completed', $upload_duration]);
        } catch (Exception $e) {
            error_log("Database Insert Error: " . $e->getMessage());
        }

        $metadata = [
            'filename'    => basename($finalPath),
            'uploaded_at' => date('Y-m-d H:i:s'),
            'subject_id' => $subject_id,
            'subject_age' => $subject_age,
            'subject_sex' => $subject_sex,
            'uploader_email' => $uploader_email,
            'uploader_name' => $_SESSION['user_name'] ?? 'unknown'
        ];
        file_put_contents($finalPath . '.json', json_encode($metadata, JSON_PRETTY_PRINT));
        
        echo json_encode(['status' => 'complete', 'path' => $finalPath]);
    } else {
        echo json_encode(['status' => 'chunk_uploaded', 'index' => $chunkIndex]);
    }
    exit;
}

// 2. Handle Auth (Login/Register)
$auth_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_action'])) {
    
    // Validate CSRF
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $auth_error = "Session expired or invalid request. Please reload and try again.";
    } else {
        $pdo = init_db();
        $action = $_POST['auth_action'];
        $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
        $password = trim($_POST['password']);
        
        if ($action === 'register') {
            $name = htmlspecialchars(trim($_POST['name']));
            $confirm_password = trim($_POST['confirm_password']);
            
            if (empty($email) || empty($password) || empty($name)) {
                $auth_error = "All fields are required.";
            } elseif ($password !== $confirm_password) {
                $auth_error = "Passwords do not match.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $auth_error = "Invalid email format.";
            } else {
                $stmt = $pdo->prepare("SELECT id FROM local_users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $auth_error = "Email already registered.";
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO local_users (email, password_hash, name) VALUES (?, ?, ?)");
                    if ($stmt->execute([$email, $hash, $name])) {
                        $_SESSION['authenticated'] = true;
                        $_SESSION['user_email'] = $email;
                        $_SESSION['user_name'] = $name;
                        header("Location: upload.php");
                        exit;
                    } else {
                        $auth_error = "Registration failed.";
                    }
                }
            }
        } elseif ($action === 'login') {
            if (empty($email) || empty($password)) {
                $auth_error = "Email and password are required.";
            } else {
                $stmt = $pdo->prepare("SELECT * FROM local_users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                if ($user && password_verify($password, $user['password_hash'])) {
                    $stmt = $pdo->prepare("UPDATE local_users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$user['id']]);
                    $_SESSION['authenticated'] = true;
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_name'] = $user['name'];
                    header("Location: upload.php");
                    exit;
                } else {
                    $auth_error = "Invalid email or password.";
                }
            }
        }
    }
}

// 3. Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: upload.php");
    exit;
}

$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>BRAINSCORES | Upload</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Attempt to import TASA Explorer if available, or fall back to Inter/System */
        @font-face {
            font-family: 'TASA Explorer';
            src: local('TASA Explorer'), url('https://brainscores.framer.website/assets/TASAExplorer.woff2') format('woff2'); /* Hypothetical URL, likely won't work cross-origin without CORS, but shows intent. We rely on Inter as robust fallback */
            font-weight: 400;
            font-style: normal;
        }

       /* ... (existing styles) ... */
        :root {
            --bg-color: #4b3f3f;         /* Warm Dark Brown - Exact match */
            --surface-color: #554848;    /* Lighter Warm Brown for cards */
            --surface-border: #665a5a;
            --accent-color: #FAEA05;     /* EXACT Neon Yellow from BrainScores */
            --text-color: #f7f5f5;       /* Off-white text */
            --text-muted: #d0caca;
            --error-color: #ff6b6b;
            --success-color: #FAEA05;    /* Use accent for success */
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'TASA Explorer', 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* --- Canvas Background --- */
        #bgCanvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            opacity: 0.2; /* Subtler */
        }

        /* --- Navbar --- */
        nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 24px 40px;
            background: rgba(10, 10, 10, 0.9);
            backdrop-filter: blur(10px);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .logo {
            font-family: 'TASA Explorer', 'Inter', sans-serif;
            font-weight: 400; /* Regular weight as per reference */
            font-size: 1.1rem;
            letter-spacing: -0.5px;
            color: var(--accent-color); /* Neon Yellow */
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 2px;
        }
        
        /* Logo: BrainScores (White text, maybe accent dot?) 
           Reference had white text. We'll keep it clean white. 
        */

        .nav-links {
            list-style: none;
            display: flex;
            gap: 30px;
            margin: 0;
            padding: 0;
        }

        .nav-links a {
            color: var(--text-color);
            text-decoration: none;
            font-size: 0.9rem;
            opacity: 0.7;
            transition: opacity 0.2s;
            font-weight: 500;
        }

        .nav-links a:hover, .nav-links a.active {
            opacity: 1;
            color: var(--accent-color);
        }

        /* --- Main Container --- */
        .container {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 40px;
            width: 100%;
            max-width: 1000px;
            margin: 0 auto;
            box-sizing: border-box;
        }

        h1 {
            font-size: 3rem;
            font-weight: 400;
            margin-bottom: 15px;
            text-align: center;
            letter-spacing: -1px;
        }

        p.subtitle {
            color: var(--text-muted);
            margin-bottom: 50px;
            text-align: center;
            max-width: 500px;
            line-height: 1.6;
            font-size: 1.05rem;
        }

        /* --- Auth Forms --- */
        .auth-wrapper {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
            justify-content: center;
            width: 100%;
        }

        .auth-card {
            background: var(--surface-color);
            border: 1px solid var(--surface-border);
            padding: 40px;
            border-radius: 4px; /* Sharper corners */
            width: 100%;
            max-width: 380px;
            transition: transform 0.2s;
        }

        .auth-card:hover {
            transform: translateY(-2px);
            border-color: #444;
        }

        .auth-card h2 {
            margin-top: 0;
            font-size: 1.4rem;
            margin-bottom: 25px;
            font-weight: 400;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.85rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="number"],
        select {
            width: 100%;
            padding: 14px;
            background: #3e3333; /* Darker warm brown */
            border: 1px solid #665a5a;
            border-radius: 4px;
            color: #fff;
            font-family: inherit;
            margin-bottom: 25px;
            box-sizing: border-box;
            transition: border-color 0.2s;
            font-size: 0.95rem;
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--accent-color);
            background: #2b2222;
        }

        .btn {
            width: 100%;
            padding: 14px;
            background: var(--accent-color);
            color: #000;
            border: none;
            border-radius: 4px;
            font-weight: 500;
            font-size: 0.95rem;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.1s;
        }

        .btn:hover {
            opacity: 0.9;
            transform: scale(0.99);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid #444;
            color: #fff;
        }
        
        .btn-outline:hover {
            border-color: #fff;
            background: rgba(255,255,255,0.05);
        }

        /* --- Upload Area --- */
        .upload-card {
            background: var(--surface-color);
            border-radius: 8px;
            padding: 50px;
            width: 100%;
            max-width: 650px;
            text-align: center;
            border: 1px solid var(--surface-border);
        }

        .drop-zone {
            border: 1px dashed #444;
            border-radius: 6px;
            padding: 60px 20px;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            background: rgba(255, 255, 255, 0.01);
            margin-bottom: 30px;
        }

        .drop-zone:hover, .drop-zone.dragover {
            border-color: var(--accent-color);
            background: rgba(250, 234, 5, 0.03);
            border-style: solid;
        }

        .drop-zone-icon {
            color: var(--accent-color);
            width: 40px;
            height: 40px;
            margin-bottom: 15px;
        }

        .progress-bar {
            background: var(--accent-color);
            box-shadow: 0 0 15px rgba(250, 234, 5, 0.3);
        }

        
        .upload-status {
            margin-top: 15px;
            font-size: 0.9rem;
            min-height: 20px;
        }

        .user-info {
            width: 100%;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 15px;
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 20px;
            padding-right: 10px;
        }

        .notice-banner {
            background: rgba(204, 255, 0, 0.1);
            border: 1px solid var(--accent-color);
            color: var(--accent-color);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 30px;
            font-size: 0.85rem;
            text-align: left;
            line-height: 1.6;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .auth-wrapper {
                flex-direction: column;
                align-items: center;
            }
            .user-info {
                position: relative;
                top: auto;
                right: auto;
                margin-bottom: 20px;
                justify-content: center;
            }
            nav {
                flex-direction: column;
                gap: 15px;
            }
        }
    </style>
</head>
<body>

    <canvas id="bgCanvas"></canvas>

    <nav>
        <a href="index.html" class="logo">BrainScores</a>
        <ul class="nav-links">
            <li><a href="index.html">Home</a></li>
            <li><a href="index.html#tech">Tech</a></li>
            <li><a href="index.html#contact">Contact</a></li>
            <li><a href="#" class="active">Upload</a></li>
        </ul>
    </nav>

    <div class="container">
        
        <?php if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true): ?>
            
            <h1>Welcome Back</h1>
            <p class="subtitle">Access the secure file upload portal. Please sign in or create an account to proceed.</p>

            <?php if ($auth_error): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($auth_error); ?>
                </div>
            <?php endif; ?>

            <div class="auth-wrapper">
                <!-- Login -->
                <div class="auth-card">
                    <div style="text-align: center; margin-bottom: 20px;">
                         <a href="#" class="logo" style="justify-content: center; font-size: 1.5rem;">BrainScores</a>
                    </div>
                    <h2>Sign In</h2>
                    <form method="post" action="upload.php">
                        <input type="hidden" name="auth_action" value="login">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <label>Email Address</label>
                        <input type="email" name="email" required placeholder="name@example.com">
                        
                        <label>Password</label>
                        <input type="password" name="password" required placeholder="••••••••">
                        
                        <button type="submit" class="btn">Sign In</button>
                    </form>
                </div>

                <!-- Register -->
                <div class="auth-card">
                    <div style="text-align: center; margin-bottom: 20px;">
                         <a href="#" class="logo" style="justify-content: center; font-size: 1.5rem;">BrainScores</a>
                    </div>
                    <h2>Create Account</h2>
                    <form method="post" action="upload.php">
                        <input type="hidden" name="auth_action" value="register">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <label>Email Address</label>
                        <input type="email" name="email" required placeholder="name@example.com">
                        
                        <label>Full Name / System Name</label>
                        <input type="text" name="name" required placeholder="e.g. Clinical Sys A">

                        <label>Password</label>
                        <input type="password" name="password" required placeholder="••••••••">

                        <label>Confirm Password</label>
                        <input type="password" name="confirm_password" required placeholder="••••••••">
                        
                        <button type="submit" class="btn btn-outline">Sign Up</button>
                    </form>
                </div>
            </div>

        <?php else: ?>

            <div class="user-info">
                <span>Logged in as <strong><?php echo htmlspecialchars($_SESSION['user_name']); ?></strong></span>
                <a href="upload.php?logout=true" style="color: var(--error-color); text-decoration: none; border-bottom: 1px solid transparent; transition: all 0.2s;">Sign Out</a>
            </div>

            <h1>Upload Data</h1>
            <p class="subtitle">Securely transfer MRI data to the BrainScores processing pipeline.</p>

            <div class="upload-card">
                
                <div class="notice-banner">
                    <strong>DEVELOPMENT PREVIEW</strong><br>
                    • Research use only. Not for clinical diagnosis.<br>
                    • Do not upload PII (Personally Identifiable Information).<br>
                    • Supports MRI datasets (NIfTI, DICOM archives).
                </div>

                <form id="uploadForm">
                    <div style="text-align: left; margin-bottom: 25px;">
                        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                            <div style="flex: 1; min-width: 200px;">
                                <label for="subject_id">Subject ID / Case Number</label>
                                <input type="text" id="subject_id" required placeholder="e.g. SUB-001">
                            </div>
                            <div style="flex: 1; min-width: 100px;">
                                <label for="subject_age">Age</label>
                                <input type="number" id="subject_age" required placeholder="Years">
                            </div>
                            <div style="flex: 1; min-width: 100px;">
                                <label for="subject_sex">Sex</label>
                                <select id="subject_sex" required>
                                    <option value="">Select...</option>
                                    <option value="M">Male</option>
                                    <option value="F">Female</option>
                                    <option value="NA">Other</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="drop-zone" id="dropZone">
                        <svg class="drop-zone-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                        <div class="drop-zone-text">
                            <strong>Click to browse</strong> or drag file here
                        </div>
                        <input type="file" id="fileInput" style="display: none;">
                    </div>

                    <div id="fileDetails" style="display: none; margin-bottom: 20px; color: #fff;">
                        Selected: <span id="fileName" style="color: var(--accent-color);"></span>
                    </div>

                    <div class="progress-container" id="progressContainer">
                        <div class="progress-bar" id="progressBar"></div>
                    </div>
                    <div class="upload-status" id="statusMessage"></div>

                    <button type="submit" class="btn" id="uploadBtn" style="margin-top: 20px;">Start Upload</button>
                    
                </form>
            </div>

            <script>
                const dropZone = document.getElementById('dropZone');
                const fileInput = document.getElementById('fileInput');
                const uploadForm = document.getElementById('uploadForm');
                const fileNameDisplay = document.getElementById('fileName');
                const fileDetails = document.getElementById('fileDetails');
                const uploadBtn = document.getElementById('uploadBtn');
                const progressContainer = document.getElementById('progressContainer');
                const progressBar = document.getElementById('progressBar');
                const statusMessage = document.getElementById('statusMessage');

                dropZone.addEventListener('click', () => fileInput.click());

                dropZone.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    dropZone.classList.add('dragover');
                });

                dropZone.addEventListener('dragleave', () => {
                    dropZone.classList.remove('dragover');
                });

                dropZone.addEventListener('drop', (e) => {
                    e.preventDefault();
                    dropZone.classList.remove('dragover');
                    if (e.dataTransfer.files.length) {
                        fileInput.files = e.dataTransfer.files;
                        updateFileDetails();
                    }
                });

                fileInput.addEventListener('change', updateFileDetails);

                function updateFileDetails() {
                    if (fileInput.files.length > 0) {
                        fileNameDisplay.textContent = fileInput.files[0].name;
                        fileDetails.style.display = 'block';
                    }
                }

                uploadForm.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    if (!fileInput.files.length) {
                        statusMessage.textContent = 'Please select a file first.';
                        statusMessage.style.color = 'var(--error-color)';
                        return;
                    }

                    const file = fileInput.files[0];
                    uploadBtn.disabled = true;
                    uploadBtn.textContent = 'Uploading...';
                    progressContainer.style.display = 'block';
                    statusMessage.textContent = 'Initializing...';
                    statusMessage.style.color = 'var(--text-muted)';

                    const chunkSize = 2 * 1024 * 1024; // 2MB
                    const totalChunks = Math.ceil(file.size / chunkSize);
                    const uploadTimestamp = Date.now();
                    const startTime = Date.now();

                    const subject_id = document.getElementById('subject_id').value;
                    const subject_age = document.getElementById('subject_age').value;
                    const subject_sex = document.getElementById('subject_sex').value;

                    for (let i = 0; i < totalChunks; i++) {
                        const start = i * chunkSize;
                        const end = Math.min(file.size, start + chunkSize);
                        const chunk = file.slice(start, end);

                        const formData = new FormData();
                        formData.append('chunk', chunk);
                        formData.append('chunkIndex', i);
                        formData.append('totalChunks', totalChunks);
                        formData.append('filename', file.name);
                        formData.append('uploadTimestamp', uploadTimestamp);
                        formData.append('subject_id', subject_id);
                        formData.append('subject_age', subject_age);
                        formData.append('subject_sex', subject_sex);

                        if (i === totalChunks - 1) {
                             const duration = Math.round((Date.now() - startTime) / 1000);
                             formData.append('duration', duration);
                        }

                        try {
                            const response = await fetch('upload.php?action=chunk_upload', {
                                method: 'POST',
                                body: formData
                            });

                            if (!response.ok) throw new Error("Server Error");
                            
                            const result = await response.json();
                            if (result.error) throw new Error(result.error);

                            // Update Progress
                            const percent = Math.round(((i + 1) / totalChunks) * 100);
                            progressBar.style.width = percent + '%';
                            
                            if (result.status === 'complete') {
                                statusMessage.textContent = 'Upload Complete!';
                                statusMessage.style.color = 'var(--success-color)';
                                uploadBtn.textContent = 'Done';
                                // Optional: Reset
                                setTimeout(() => {
                                    fileInput.value = '';
                                    fileDetails.style.display = 'none';
                                    progressBar.style.width = '0%';
                                    progressContainer.style.display = 'none';
                                    uploadBtn.disabled = false;
                                    uploadBtn.textContent = 'Start Upload';
                                    statusMessage.textContent = '';
                                    alert('File uploaded successfully!');
                                }, 2000);
                            }

                        } catch (error) {
                            console.error(error);
                            statusMessage.textContent = 'Error: ' + error.message;
                            statusMessage.style.color = 'var(--error-color)';
                            uploadBtn.disabled = false;
                            uploadBtn.textContent = 'Retry';
                            return;
                        }
                    }
                });
            </script>

        <?php endif; ?>
    </div>

    <!-- Background Animation Script -->
    <script>
        const canvas = document.getElementById('bgCanvas');
        const ctx = canvas.getContext('2d');
        let width, height;
        let particles = [];

        function resize() {
            width = canvas.width = window.innerWidth;
            height = canvas.height = window.innerHeight;
        }

        class Particle {
            constructor() {
                this.x = Math.random() * width;
                this.y = Math.random() * height;
                this.vx = (Math.random() - 0.5) * 0.5;
                this.vy = (Math.random() - 0.5) * 0.5;
                this.size = Math.random() * 2;
            }
            update() {
                this.x += this.vx;
                this.y += this.vy;
                if (this.x < 0 || this.x > width) this.vx *= -1;
                if (this.y < 0 || this.y > height) this.vy *= -1;
            }
            draw() {
                ctx.fillStyle = 'rgba(255, 255, 255, 0.2)';
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
                ctx.fill();
            }
        }

        function init() {
            for (let i = 0; i < 100; i++) particles.push(new Particle());
        }

        function animate() {
            ctx.clearRect(0, 0, width, height);
            particles.forEach(p => {
                p.update();
                p.draw();
            });
            requestAnimationFrame(animate);
        }

        window.addEventListener('resize', resize);
        resize();
        init();
        animate();
    </script>
</body>
</html>