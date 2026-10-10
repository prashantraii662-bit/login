-- Apply once to voting_system. Existing accounts retain student access;
-- new registrations explicitly start unverified in register.php.
ALTER TABLE users
    ADD COLUMN role ENUM('student', 'admin') NOT NULL DEFAULT 'student',
    ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 1;
