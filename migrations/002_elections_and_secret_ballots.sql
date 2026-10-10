CREATE TABLE IF NOT EXISTS elections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    status ENUM('draft', 'scheduled', 'active', 'closed') NOT NULL DEFAULT 'draft',
    results_published TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_election_window (status, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS positions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    election_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_position_election_name (election_id, name),
    UNIQUE KEY uq_position_id_election (id, election_id),
    CONSTRAINT fk_position_election FOREIGN KEY (election_id)
        REFERENCES elections (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS candidates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    election_id INT UNSIGNED NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    student_id VARCHAR(50) NULL,
    name VARCHAR(150) NOT NULL,
    photo_path VARCHAR(255) NULL,
    manifesto TEXT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_candidate_id_election_position (id, election_id, position_id),
    KEY idx_candidate_position_status (election_id, position_id, status),
    CONSTRAINT fk_candidate_position FOREIGN KEY (position_id, election_id)
        REFERENCES positions (id, election_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS election_participation (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    election_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    cast_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_election_participant (election_id, user_id),
    KEY idx_participation_election (election_id),
    CONSTRAINT fk_participation_election FOREIGN KEY (election_id)
        REFERENCES elections (id) ON DELETE RESTRICT,
    CONSTRAINT fk_participation_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ballots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    election_id INT UNSIGNED NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    candidate_id INT UNSIGNED NOT NULL,
    cast_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ballot_result (election_id, position_id, candidate_id),
    CONSTRAINT fk_ballot_position FOREIGN KEY (position_id, election_id)
        REFERENCES positions (id, election_id) ON DELETE RESTRICT,
    CONSTRAINT fk_ballot_candidate FOREIGN KEY (candidate_id, election_id, position_id)
        REFERENCES candidates (id, election_id, position_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
