<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

define('VOTING_DB_SETUP', true);
require_once __DIR__ . '/config.php';

$stmt = $conn->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
$databaseName = DB_NAME;
$stmt->bind_param('s', $databaseName);
$stmt->execute();
$databaseExists = $stmt->get_result()->num_rows > 0;
$stmt->close();

if ($databaseExists) {
    fwrite(
        STDERR,
        "Refusing to initialize an existing voting_system database. Use the ordered migrations in README.md.\n"
    );
    exit(1);
}

$conn->query('CREATE DATABASE `voting_system` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$conn->select_db(DB_NAME);

$conn->query(
    "CREATE TABLE users (
        id INT PRIMARY KEY AUTO_INCREMENT,
        first_name VARCHAR(100) NOT NULL,
        middle_name VARCHAR(100) NULL,
        last_name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        student_id VARCHAR(50) NOT NULL UNIQUE,
        department VARCHAR(100) NOT NULL,
        password VARCHAR(255) NOT NULL,
        has_voted TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_student_id (student_id),
        KEY idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$conn->query(
    "CREATE TABLE votes (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

foreach ([
    '001_auth_roles.sql',
    '002_elections_and_secret_ballots.sql',
    '003_management_audit_notifications.sql',
    '004_election_eligibility.sql',
    '005_anonymize_legacy_ballots.sql',
    '006_student_account_status.sql',
] as $migrationFile) {
    $migrationPath = __DIR__ . '/migrations/' . $migrationFile;
    $migrationSql = file_get_contents($migrationPath);
    if ($migrationSql === false) {
        throw new RuntimeException('Could not read migration: ' . $migrationFile);
    }

    foreach (preg_split('/;\s*(?:\r?\n|$)/', $migrationSql) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $conn->query($statement);
        }
    }
}

echo "Fresh voting_system database initialized. No sample accounts were created.\n";
