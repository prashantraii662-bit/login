<?php
/**
 * Database Connection Handler
 * ===========================
 * Handles all database connections using MySQLi prepared statements
 * Ensures secure database interaction
 */

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');          // XAMPP default user
define('DB_PASSWORD', '');          // XAMPP default password (empty)
define('DB_NAME', 'voting_system');

// Create connection using MySQLi
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die('Database Connection Failed: ' . $conn->connect_error);
}

// Set charset to utf8
$conn->set_charset('utf8');

// Display errors in development (remove in production)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

?>
