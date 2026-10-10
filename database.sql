-- Legacy filename retained for compatibility. Use database_setup.sql for
-- fresh installs, then apply migrations 001 through 006 in numeric order.
CREATE DATABASE IF NOT EXISTS voting_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE voting_system;

CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    student_id VARCHAR(50) NOT NULL UNIQUE,
    department VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    has_voted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS votes (
    vote_id INT PRIMARY KEY AUTO_INCREMENT,
    student_id VARCHAR(50) NOT NULL,
    president VARCHAR(100) NOT NULL,
    vice_president VARCHAR(100) NOT NULL,
    secretary VARCHAR(100) NOT NULL,
    timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_student_id FOREIGN KEY (student_id)
        REFERENCES users (student_id) ON DELETE CASCADE,
    KEY idx_vote_student (student_id),
    KEY idx_vote_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
