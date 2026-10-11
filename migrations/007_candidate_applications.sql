ALTER TABLE positions
    ADD COLUMN applications_open TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN applications_open_at DATETIME NULL,
    ADD COLUMN applications_close_at DATETIME NULL,
    ADD COLUMN eligibility_requirements TEXT NULL;

CREATE TABLE IF NOT EXISTS candidate_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    election_id INT UNSIGNED NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    statement_of_purpose TEXT NOT NULL,
    qualifications TEXT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    admin_feedback TEXT NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_candidate_application_student_position (user_id, position_id),
    KEY idx_candidate_application_status_created (status, created_at),
    KEY idx_candidate_application_election_position (election_id, position_id),
    CONSTRAINT fk_candidate_application_student FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_candidate_application_position FOREIGN KEY (position_id, election_id)
        REFERENCES positions (id, election_id) ON DELETE RESTRICT,
    CONSTRAINT fk_candidate_application_reviewer FOREIGN KEY (reviewed_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE candidates
    ADD COLUMN application_id BIGINT UNSIGNED NULL,
    ADD UNIQUE KEY uq_candidate_application (application_id),
    ADD CONSTRAINT fk_candidate_application_candidate FOREIGN KEY (application_id)
        REFERENCES candidate_applications (id) ON DELETE SET NULL;
