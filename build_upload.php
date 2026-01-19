<?php
// build_upload.php

// 1. Read index.html
$index_html = file_get_contents('index.html');
if (!$index_html) die("Failed to read index.html");

// 2. Read upload_legacy.php
$legacy_lines = file('upload_legacy.php');
if (!$legacy_lines) die("Failed to read upload_legacy.php");

// 3. Extract PHP Header (Lines 1-195)
// Note: legacy file lines are 0-indexed in array, so 0 to 194.
$php_header_lines = array_slice($legacy_lines, 0, 195);
$php_header = implode("", $php_header_lines);

// 4. Extract CSS (Lines 213-511 approx) - simplify to component styles
// extracting manually based on known classes
$css_content = "
<style>
/* Extracted from upload_legacy.php */
:root {
    --bg-color: #4b3f3f;
    --surface-color: #554848;
    --surface-border: #665a5a;
    --accent-color: #FAEA05;
    --text-color: #f7f5f5;
    --text-muted: #d0caca;
    --error-color: #ff6b6b;
    --success-color: #FAEA05;
}

/* Hide Framer Content Sections */
#hero, #context, #approach, #validation, #platform, #team, #integration {
    display: none !important;
}

/* Ensure Nav is visible and on top */
.framer-1kjfpsh-container { 
    z-index: 1000 !important; 
}

/* Content Container Styles */
.custom-upload-container {
    width: 100%;
    max-width: 1000px;
    margin: 120px auto 40px auto; /* Push down below nav */
    padding: 20px;
    z-index: 10;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
}

h1 {
    font-size: 3rem;
    font-weight: 400;
    margin-bottom: 15px;
    text-align: center;
    letter-spacing: -1px;
    color: var(--text-color);
}

p.subtitle {
    color: var(--text-muted);
    margin-bottom: 50px;
    text-align: center;
    max-width: 500px;
    line-height: 1.6;
    font-size: 1.05rem;
}

/* Auth Forms */
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
    border-radius: 4px;
    width: 100%;
    max-width: 380px;
    transition: transform 0.2s;
}

.auth-card:hover {
    transform: translateY(-2px);
}

.auth-card h2 {
    margin-top: 0;
    font-size: 1.4rem;
    margin-bottom: 25px;
    font-weight: 400;
    color: var(--text-color);
}

label {
    display: block;
    margin-bottom: 8px;
    font-size: 0.85rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

input[type=\"text\"],
input[type=\"email\"],
input[type=\"password\"],
input[type=\"number\"],
select {
    width: 100%;
    padding: 14px;
    background: #3e3333;
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
    height: 4px;
    width: 0%;
    transition: width 0.3s;
}

.progress-container {
    width: 100%;
    background: #444;
    height: 4px;
    border-radius: 2px;
    margin-top: 20px;
    display: none;
    overflow: hidden;
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

.alert-error {
    background: rgba(255, 107, 107, 0.1);
    border: 1px solid var(--error-color);
    color: var(--error-color);
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
    text-align: center;
}
</style>
";

// 5. Extract Body Content (Lines 529-783 approx)
// Start from <?php if (!isset...
// Find the exact line range for the content
$content_start = 529 - 1; // 0-indexed
$content_end = 783 - 1; // 0-indexed close to </div>
$content_lines = array_slice($legacy_lines, $content_start, $content_end - $content_start + 1);
$body_content = implode("", $content_lines);

// Wrap body content in a container
$wrapper = '<div class="custom-upload-container">' . $body_content . '</div>';

// 6. Assembly

// A. Inject CSS into HEAD
$new_content = str_replace('</head>', $css_content . '</head>', $index_html);

// B. Inject Wrapper before #hero
$target_str = '<div class="framer-zy6jon" data-framer-name="hero" id="hero">';
$new_content_2 = str_replace($target_str, $wrapper . $target_str, $new_content);

if ($new_content_2 === $new_content) {
    die("Error: target string not found for injection.");
}

// C. Prepend PHP Header
$final_output = $php_header . $new_content_2;

// 7. Write to upload.php
$result = file_put_contents('upload.php', $final_output);

if ($result) {
    echo "Success! Created upload.php with " . $result . " bytes.";
} else {
    echo "Failed to write upload.php";
}
?>
