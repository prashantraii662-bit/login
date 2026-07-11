-- College Online Voting System Database Schema
-- =============================================

-- Create database
CREATE DATABASE IF NOT EXISTS voting_system;
USE voting_system;

-- Users table - stores student information
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50),
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    student_id VARCHAR(20) NOT NULL UNIQUE,
    department VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    has_voted INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Votes table - stores voting records
CREATE TABLE IF NOT EXISTS votes (
    vote_id INT PRIMARY KEY AUTO_INCREMENT,
    student_id VARCHAR(20) NOT NULL,
    president VARCHAR(100) NOT NULL,
    vice_president VARCHAR(100) NOT NULL,
    secretary VARCHAR(100) NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(student_id) ON DELETE CASCADE
);

-- Create indexes for better performance
CREATE INDEX idx_student_id ON users(student_id);
CREATE INDEX idx_email ON users(email);
CREATE INDEX idx_votes_student ON votes(student_id);
