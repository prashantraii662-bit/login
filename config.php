<?php
require_once __DIR__ . '/db.php';

function sanitize($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

function isLoggedIn() {
    return ($_SESSION['role'] ?? '') === 'student' && !empty($_SESSION['user_id']);
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