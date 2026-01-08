<?php
// Database configuration
define('DB_FILE', __DIR__ . '/users.db');

// Create the database and table if they don't exist
function init_db() {
    try {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Table for local email/password authentication
        $pdo->exec("CREATE TABLE IF NOT EXISTS local_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            name TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for upload history
        $pdo->exec("CREATE TABLE IF NOT EXISTS uploads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_email TEXT NOT NULL,
            filename TEXT NOT NULL,
            file_size INTEGER,
            subject_id TEXT,
            subject_age INTEGER,
            subject_sex TEXT,
            uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            status TEXT DEFAULT 'completed',
            upload_duration_seconds INTEGER
        )");
        
        return $pdo;
    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}
?>
