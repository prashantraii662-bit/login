<?php
include 'config.php';

// Create database if not exists
$conn->query("CREATE DATABASE IF NOT EXISTS voting_system");
$conn->select_db("voting_system");

// Create users table
$sql0 = "CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100),
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    student_id VARCHAR(50) UNIQUE NOT NULL,
    department VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    has_voted TINYINT(1) DEFAULT 0,
    registration_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_student_id (student_id),
    INDEX idx_email (email)
)";
$conn->query($sql0);

// Create votes table
$sql1 = "CREATE TABLE IF NOT EXISTS votes (
    vote_id INT PRIMARY KEY AUTO_INCREMENT,
    student_id VARCHAR(50) NOT NULL,
    president VARCHAR(100) NOT NULL,
    vice_president VARCHAR(100) NOT NULL,
    secretary VARCHAR(100) NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_student_id FOREIGN KEY (student_id) REFERENCES users(student_id) ON DELETE CASCADE,
    INDEX idx_vote_student (student_id),
    INDEX idx_vote_timestamp (timestamp)
)";
$conn->query($sql1);

// Insert sample users (password: password123 hashed)
$sample_users = [
    ['John', 'Kumar', 'Sharma', 'john.sharma@college.edu', 'CS001', 'Computer Engineering', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36iMxnP2'],
    ['Priya', 'Singh', 'Patel', 'priya.patel@college.edu', 'CS002', 'Computer Engineering', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36iMxnP2'],
    ['Rajesh', 'Kumar', 'Verma', 'rajesh.verma@college.edu', 'CE001', 'Civil Engineering', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36iMxnP2']
];

foreach ($sample_users as $user) {
    $first = $user[0];
    $middle = $user[1];
    $last = $user[2];
    $email = $user[3];
    $student_id = $user[4];
    $dept = $user[5];
    $pass = $user[6];
    $check = $conn->query("SELECT id FROM users WHERE student_id = '$student_id'");
    if ($check->num_rows == 0) {
        $conn->query("INSERT INTO users (first_name, middle_name, last_name, email, student_id, department, password) VALUES ('$first', '$middle', '$last', '$email', '$student_id', '$dept', '$pass')");
    }
}

echo "Database initialized successfully!";
?>
