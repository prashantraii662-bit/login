CREATE TABLE IF NOT EXISTS election_eligibility (
    election_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    eligible_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (election_id, user_id),
    CONSTRAINT fk_eligibility_election FOREIGN KEY (election_id)
        REFERENCES elections (id) ON DELETE RESTRICT,
    CONSTRAINT fk_eligibility_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
