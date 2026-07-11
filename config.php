<?php
// Database configuration for the voting application
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "voting_system";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

function sanitize($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

function isLoggedIn() {
    return isset($_SESSION['student_id']);
}

function hasVoted($student_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT has_voted FROM users WHERE student_id = ?");
    $stmt->bind_param('s', $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $has_voted = false;
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $has_voted = ($row['has_voted'] == 1);
    }
    $stmt->close();
    return $has_voted;
}
?>