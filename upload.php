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


session_start();
require_once 'auth_config.php';

// Define the final destination directory
if (file_exists('/var/www/brainscoresai/upload/')) {
    $upload_dir = '/var/www/brainscoresai/upload/';
} else {
    // Fallback for local development
    $upload_dir = __DIR__ . '/uploads/';
}

// Check if the destination directory exists and is writable
if (!file_exists($upload_dir)) {
    die("Error: The upload directory ($upload_dir) does not exist.");
}
if (!is_writable($upload_dir)) {
    die("Error: The upload directory ($upload_dir) is not writable. Please check its permissions.");
}

// Handle Chunked Upload (AJAX)
// Use 'action' query param to distinguish from normal page loads
if (isset($_GET['action']) && $_GET['action'] === 'chunk_upload') {
    // Basic Auth Check for AJAX
    if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
         http_response_code(403);
         echo json_encode(['error' => 'Not authenticated']);
         exit;
    }

    $fileName = $_POST['filename']; // Original filename
    $chunkIndex = (int)$_POST['chunkIndex'];
    $totalChunks = (int)$_POST['totalChunks'];
    
    // Create a temp directory for this file upload
    // using a unique ID (session ID + filename) to avoid collisions
    $fileId = md5($_SESSION['user_email'] . $fileName . $_POST['uploadTimestamp']); 
    $tempDir = $upload_dir . 'temp_' . $fileId;
    
    if (!file_exists($tempDir)) {
        if (!mkdir($tempDir)) {
             echo json_encode(['error' => 'Failed to create temp dir']);
             exit;
        }
    }
    
    // Move the uploaded chunk to the temp dir
    $tempFilePath = $tempDir . "/chunk_" . $chunkIndex;
    if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $tempFilePath)) {
        echo json_encode(['error' => 'Failed to move chunk']);
        exit;
    }
    
    // Check if all chunks are uploaded
    // Count files in tempDir
    $uploadedChunks = count(glob("$tempDir/chunk_*"));
    
    if ($uploadedChunks === $totalChunks) {
        // All chunks received, combine them
        $finalPath = $upload_dir . $fileName;
        
        // Handle duplicate filenames
        if (file_exists($finalPath)) {
            $pathInfo = pathinfo($finalPath);
            $finalPath = $upload_dir . $pathInfo['filename'] . '_' . time() . '.' . $pathInfo['extension'];
        }
        
        $outFile = fopen($finalPath, 'wb');
        
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = $tempDir . "/chunk_" . $i;
            $chunkData = file_get_contents($chunkPath);
            fwrite($outFile, $chunkData);
            unlink($chunkPath); // Delete chunk after merging
        }
        fclose($outFile);
        rmdir($tempDir); // Remove temp dir
        
        // Save Metadata (passed in the last chunk or separately)
        $uploader_email = $_SESSION['user_email'] ?? 'unknown';
        $subject_id = $_POST['subject_id'] ?? '';
        $subject_age = $_POST['subject_age'] ?? 0;
        $subject_sex = $_POST['subject_sex'] ?? '';
        $upload_duration = $_POST['duration'] ?? 0;
        $file_size = filesize($finalPath);

         // Save to Database
         try {
            $pdo = init_db();
            $stmt = $pdo->prepare("INSERT INTO uploads (user_email, filename, file_size, subject_id, subject_age, subject_sex, status, upload_duration_seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$uploader_email, basename($finalPath), $file_size, $subject_id, $subject_age, $subject_sex, 'completed', $upload_duration]);
        } catch (Exception $e) {
            error_log("Database Insert Error: " . $e->getMessage());
        }

        // Create JSON Sidecar (Legacy support)
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

// Handle Local Authentication (Sign Up / Sign In)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_action'])) {
    $pdo = init_db();
    $action = $_POST['auth_action'];
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    if ($action === 'register') {
        $name = trim($_POST['name']);
        if (empty($email) || empty($password) || empty($name)) {
             $error = "All fields are required for registration.";
        } else {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT id FROM local_users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = "Email already registered.";
            } else {
                // Register new user
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO local_users (email, password_hash, name) VALUES (?, ?, ?)");
                if ($stmt->execute([$email, $hash, $name])) {
                    $_SESSION['authenticated'] = true;
                    $_SESSION['user_email'] = $email;
                    $_SESSION['user_name'] = $name;
                    // Redirect to avoid resubmission
                    header("Location: upload.php");
                    exit;
                } else {
                    $error = "Registration failed.";
                }
            }
        }
    } elseif ($action === 'login') {
        if (empty($email) || empty($password)) {
            $error = "Email and password are required.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM local_users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Update last login
                $stmt = $pdo->prepare("UPDATE local_users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$user['id']]);

                $_SESSION['authenticated'] = true;
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_name'] = $user['name'];
                header("Location: upload.php");
                exit;
            } else {
                $error = "Invalid email or password.";
            }
        }
    }
}

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: upload.php");
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>BRAINSCORES Augmented Radiology - Upload Files</title>
  <meta name="description" content="BRAINSCORES">
  <meta name="author" content="boris@bic.mni.mcgill.ca">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!--[if lt IE 9]>
    <script src="//cdnjs.cloudflare.com/ajax/libs/html5shiv/3.7.2/html5shiv.min.js"></script>
    <script src="//cdnjs.cloudflare.com/ajax/libs/respond.js/1.4.2/respond.min.js"></script>
  <![endif]-->
  <link rel="stylesheet" href="assets/base.css">
  <link rel="stylesheet" href="fixed-navigation-bar.css">
  <link href='https://fonts.googleapis.com/css?family=Hind:400,500,600,300,700' rel='stylesheet' type='text/css'>
  <link rel="shortcut icon" href="http://sixrevisions.com/favicon.ico">
  <style>
    /* Additional inline styles for the upload section */
    #upload {
      padding: 40px;
      background: #f9f9f9;
      margin: 20px auto;
      max-width: 800px;
      border-radius: 5px;
      box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }
    #upload h1 {
      font-size: 2em;
      margin-bottom: 20px;
    }
    #upload form {
      margin-bottom: 20px;
    }
    #upload label {
      font-weight: bold;
    }
    #upload input[type="file"],
    #upload input[type="password"],
    #upload input[type="submit"] {
      display: block;
      margin-top: 10px;
      padding: 8px;
      font-size: 1em;
    }
    #upload .upload-result {
      margin: 20px 0;
      padding: 10px;
      background: #e7f3fe;
      border: 1px solid #b3d4fc;
      border-radius: 3px;
    }
    #upload .success {
      color: green;
    }
    #upload .error,
    #upload .warning {
      color: red;
    }
    .auth-container {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
    }
    .auth-box {
        flex: 1;
        min-width: 300px;
        border: 1px solid #ddd;
        padding: 20px;
        border-radius: 5px;
        background: white;
    }
    .auth-box h2 {
        margin-top: 0;
    }
  </style>
</head>
<body>

<!-- Navigation (same as in index.html) -->
<nav class="fixed-nav-bar">
  <div id="menu" class="menu">
    <a class="sitename" href="index.html">BRAINSCORES</a>
    <a class="show" href="#menu">Menu</a>
    <a class="hide" href="#hidemenu">Menu</a>
    <ul class="menu-items">
      <li><a href="index.html#about">ABOUT</a></li>
      <li><a href="index.html#status">STATUS</a></li>
      <li><a href="index.html#tech">TECH</a></li>
      <li><a href="index.html#team">TEAM</a></li>
      <li><a href="index.html#clinical">CLINICAL</a></li>
      <li><a href="index.html#partners">PARTNERS</a></li>
      <li><a href="index.html#contact">CONTACT</a></li>
      <li><a href="upload.php">UPLOAD</a></li>
    </ul>
  </div>
</nav>

<!-- Upload Section -->
<section class="some-related-articles" id="upload">
  <h1 id="current">Upload Files</h1>

  <div class="banner-warning" style="background-color: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 20px; margin-bottom: 25px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
    <strong style="display: block; margin-bottom: 10px; font-size: 1.1em;">NOTICE: DEVELOPMENT PREVIEW</strong>
    <ul style="margin: 0; padding-left: 20px; line-height: 1.6;">
        <li><strong>Status:</strong> This software is currently under active development.</li>
        <li><strong>Usage:</strong> Strictly for research purposes only. Not for clinical checks.</li>
        <li><strong>Security:</strong> Data transmission is not encrypted. Do not upload PII (Personally Identifiable Information).</li>
        <li><strong>Limit:</strong> Please upload files one at a time.</li>
    </ul>
  </div>

  <?php
  // If not authenticated, show the login form
  if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
      if (isset($error)) {
          echo "<p style='color:red;'>$error</p>";
      }
      ?>
      <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>

      <div class="auth-container">
          <!-- Login Form -->
          <div class="auth-box">
              <h2>Login</h2>
              <form method="post" action="upload.php">
                  <input type="hidden" name="auth_action" value="login">
                  
                  <label>Email:</label>
                  <input type="email" name="email" required style="width: 100%; box-sizing: border-box;">
                  
                  <label>Password:</label>
                  <input type="password" name="password" required style="width: 100%; box-sizing: border-box;">
                  
                  <input type="submit" value="Sign In">
              </form>
          </div>

          <!-- Register Form -->
          <div class="auth-box">
              <h2>Sign Up</h2>
              <form method="post" action="upload.php">
                  <input type="hidden" name="auth_action" value="register">
                  
                  <label>Email:</label>
                  <input type="email" name="email" required style="width: 100%; box-sizing: border-box;">

                  <label>Name (User/System):</label>
                  <input type="text" name="name" required style="width: 100%; box-sizing: border-box;">
                  
                  <label>Password:</label>
                  <input type="password" name="password" required style="width: 100%; box-sizing: border-box;">
                  
                  <input type="submit" value="Sign Up">
              </form>
          </div>
      </div>
      <?php
  } else {
      // If the user is authenticated, check for file upload
      echo "<div style='text-align: right; margin-bottom: 20px;'>Logged in as: <strong>" . htmlspecialchars($_SESSION['user_email']) . "</strong> (" . htmlspecialchars($_SESSION['user_name']) . ") <a href='upload.php?logout=true' style='color: white; background-color: #d9534f; padding: 5px 10px; text-decoration: none; border-radius: 3px; font-size: 0.8em;'>Sign Out</a></div>";

      if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['userfile'])) {
          echo "<div class='upload-result'>";
          // Debug info: print the file upload array
          echo "<pre>";
          print_r($_FILES);
          echo "</pre>";

          $filename = basename($_FILES['userfile']['name']);
          $target_file = $upload_dir . $filename;

          // Warn if file exists (optional)
          if (file_exists($target_file)) {
              echo "<p class='warning'>Warning: The file already exists and will be overwritten.</p>";
          }

          // Attempt to move the uploaded file to the final destination
          if (move_uploaded_file($_FILES['userfile']['tmp_name'], $target_file)) {
              // Get current user email and details
              $uploader_email = $_SESSION['user_email'] ?? 'unknown';
              $subject_id = $_POST['user_system'] ?? '';
              $subject_age = $_POST['subject_age'] ?? 0;
              $subject_sex = $_POST['subject_sex'] ?? '';

              // Save to Database
              try {
                  $pdo = init_db();
                  $stmt = $pdo->prepare("INSERT INTO uploads (user_email, filename, subject_id, subject_age, subject_sex) VALUES (?, ?, ?, ?, ?)");
                  $stmt->execute([$uploader_email, $filename, $subject_id, $subject_age, $subject_sex]);
              } catch (Exception $e) {
                  error_log("Database Insert Error: " . $e->getMessage());
              }

              // Save metadata to a sidecar JSON file
              $metadata = [
                  'filename'    => $filename,
                  'uploaded_at' => date('Y-m-d H:i:s'),
                  'user_system' => $_POST['user_system'] ?? '',
                  'subject_age' => $_POST['subject_age'] ?? '',
                  'subject_sex' => $_POST['subject_sex'] ?? '',
                  'uploader_email' => $_SESSION['user_email'] ?? 'unknown',
                  'uploader_name' => $_SESSION['user_name'] ?? 'unknown'
              ];
              $json_path = $target_file . '.json';
              if (file_put_contents($json_path, json_encode($metadata, JSON_PRETTY_PRINT))) {
                 error_log("Metadata saved to $json_path");
              }

              echo "<p class='success'>Success: The file '" . htmlspecialchars($filename) . "' was uploaded successfully.</p>";
          } else {
              echo "<p class='error'>Error: Failed to move the uploaded file.</p>";
              echo "<p>Temporary file location: " . $_FILES['userfile']['tmp_name'] . "</p>";
              echo "<p>Target file: " . $target_file . "</p>";
              echo "<p>Upload error code: " . $_FILES['userfile']['error'] . "</p>";
              error_log("move_uploaded_file failed: from " . $_FILES['userfile']['tmp_name'] . " to " . $target_file);
          }
          echo "</div>";
      }
      ?>
      <!-- File Upload Form (Replaced with Chunked Uploader) -->
      <form id="uploadForm" onsubmit="startUpload(event)">
          
          <div style="margin-bottom: 15px;">
              <label for="user_system">Subject ID / Case Number:</label>
              <input type="text" name="user_system" id="user_system" required placeholder="Enter Subject ID" style="display: block; margin-top: 5px; padding: 8px; font-size: 1em; width: 100%; max-width: 300px;">
          </div>

          <div style="margin-bottom: 15px;">
              <label for="subject_age">Subject Age:</label>
              <input type="number" step="1" name="subject_age" id="subject_age" required placeholder="Age (Integer)" style="display: block; margin-top: 5px; padding: 8px; font-size: 1em; width: 100%; max-width: 300px;">
          </div>

          <div style="margin-bottom: 15px;">
              <label for="subject_sex">Subject Sex:</label>
              <select name="subject_sex" id="subject_sex" required style="display: block; margin-top: 5px; padding: 8px; font-size: 1em; width: 100%; max-width: 300px;">
                  <option value="">Select Sex...</option>
                  <option value="M">Male</option>
                  <option value="F">Female</option>
                  <option value="NA">NA</option>
              </select>
          </div>

          <label for="userfile">Choose a file to upload:</label>
          <input name="userfile" type="file" id="userfile" required>
          
          <!-- Progress Bar -->
          <div id="progressContainer" style="display:none; margin-top: 20px; width: 100%; max-width: 400px; background-color: #ddd; border-radius: 4px;">
              <div id="progressBar" style="width: 0%; height: 20px; background-color: #4CAF50; border-radius: 4px; text-align: center; color: white; line-height: 20px;">0%</div>
          </div>
          <p id="statusMessage" style="margin-top: 10px;"></p>

          <input type="submit" value="Upload File" id="submitBtn">
      </form>

      <script>
      async function startUpload(event) {
          event.preventDefault();
          
          const fileInput = document.getElementById('userfile');
          const file = fileInput.files[0];
          if (!file) {
              alert("Please select a file.");
              return;
          }

          // Disable form
          const submitBtn = document.getElementById('submitBtn');
          submitBtn.disabled = true;
          submitBtn.value = "Uploading...";
          
          const statusMessage = document.getElementById('statusMessage');
          const progressContainer = document.getElementById('progressContainer');
          const progressBar = document.getElementById('progressBar');
          
          progressContainer.style.display = 'block';
          
          const chunkSize = 2 * 1024 * 1024; // 2MB
          const totalChunks = Math.ceil(file.size / chunkSize);
          const uploadTimestamp = Date.now();
          const startTime = Date.now();
          
          const subject_id = document.getElementById('user_system').value;
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
              
              // Send metadata with every chunk (or just the last one, but sending with all avoids state issues)
              formData.append('subject_id', subject_id);
              formData.append('subject_age', subject_age);
              formData.append('subject_sex', subject_sex);
              
              // On last chunk, send duration
              if (i === totalChunks - 1) {
                   const duration = Math.round((Date.now() - startTime) / 1000);
                   formData.append('duration', duration);
              }

              try {
                  const response = await fetch('upload.php?action=chunk_upload', {
                      method: 'POST',
                      body: formData
                  });
                  
                  if (!response.ok) {
                      throw new Error("Server Error");
                  }
                  
                  const result = await response.json();
                  if (result.error) {
                      throw new Error(result.error);
                  }
                  
                  // Update Progress
                  const percent = Math.round(((i + 1) / totalChunks) * 100);
                  progressBar.style.width = percent + '%';
                  progressBar.textContent = percent + '%';
                  
                  if (result.status === 'complete') {
                      statusMessage.innerHTML = "<span class='success'>Success: File uploaded completely!</span>";
                      submitBtn.value = "Upload Complete";
                  }
                  
              } catch (error) {
                  console.error(error);
                  statusMessage.innerHTML = "<span class='error'>Error: " + error.message + ". Please retry.</span>";
                  submitBtn.disabled = false;
                  submitBtn.value = "Retry Upload";
                  return; // Stop upload loop
              }
          }
      }
      </script>

      <?php
  }
  ?>

</section>

</body>
</html>