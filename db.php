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

// Raise database errors explicitly instead of continuing with false query results.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$databaseSetupMode = defined('VOTING_DB_SETUP') && VOTING_DB_SETUP;
$conn = $databaseSetupMode
    ? new mysqli(DB_HOST, DB_USER, DB_PASSWORD)
    : new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die('Database Connection Failed: ' . $conn->connect_error);
}

// Use utf8mb4 for names and candidate manifestos.
$conn->set_charset('utf8mb4');

if (!$databaseSetupMode) {
    $timezoneResult = $conn->query(
        "SELECT IF(@@session.time_zone = 'SYSTEM', @@system_time_zone, @@session.time_zone) AS timezone"
    )->fetch_assoc();
    $databaseTimezone = $timezoneResult['timezone'];
    if (!date_default_timezone_set($databaseTimezone)) {
        throw new RuntimeException('The database timezone is not supported by PHP: ' . $databaseTimezone);
    }
}

?>
