-- ================================================
-- COLLEGE ONLINE VOTING SYSTEM - DATABASE SETUP
-- ================================================

-- Create database
CREATE DATABASE IF NOT EXISTS voting_system;
USE voting_system;

-- ================================================
-- TABLE 1: Users (Registration & Authentication)
-- ================================================
CREATE TABLE IF NOT EXISTS users (
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
    
    -- Indexes for faster queries
    INDEX idx_student_id (student_id),
    INDEX idx_email (email)
);

-- ================================================
-- TABLE 2: Votes (Voting Records)
-- ================================================
CREATE TABLE IF NOT EXISTS votes (
    vote_id INT PRIMARY KEY AUTO_INCREMENT,
    student_id VARCHAR(50) NOT NULL,
    president VARCHAR(100) NOT NULL,
    vice_president VARCHAR(100) NOT NULL,
    secretary VARCHAR(100) NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Foreign key relationship
    CONSTRAINT fk_student_id FOREIGN KEY (student_id) 
        REFERENCES users(student_id) ON DELETE CASCADE,
    
    -- Indexes for faster queries
    INDEX idx_vote_student (student_id),
    INDEX idx_vote_timestamp (timestamp)
);

-- ================================================
-- Sample Data (Optional - for testing)
-- ================================================

-- Insert sample users (password hashed with password_hash('password123', PASSWORD_DEFAULT))
-- You can uncomment these lines to add test data

/*
INSERT INTO users (first_name, middle_name, last_name, email, student_id, department, password, has_voted) 
VALUES 
('John', 'Kumar', 'Sharma', 'john.sharma@college.edu', 'CS001', 'Computer Engineering', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36iMxnP2', 0),
('Priya', 'Singh', 'Patel', 'priya.patel@college.edu', 'CS002', 'Computer Engineering', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36iMxnP2', 0),
('Rajesh', 'Kumar', 'Verma', 'rajesh.verma@college.edu', 'CE001', 'Civil Engineering', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36iMxnP2', 0);
*/

-- ================================================
-- End of Database Setup
-- ================================================
