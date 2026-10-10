CREATE TABLE IF NOT EXISTS legacy_voter_participation (
    student_id VARCHAR(50) NOT NULL,
    participated_at TIMESTAMP NOT NULL,
    PRIMARY KEY (student_id),
    CONSTRAINT fk_legacy_participation_user FOREIGN KEY (student_id)
        REFERENCES users (student_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO legacy_voter_participation (student_id, participated_at)
SELECT student_id, MIN(timestamp)
FROM votes
GROUP BY student_id;

ALTER TABLE votes
    DROP FOREIGN KEY fk_student_id,
    DROP INDEX idx_vote_student,
    DROP COLUMN student_id;
