<?php
session_start();
// Include database configuration
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

// Fetch vote results
$results = [
    'president' => [],
    'vice_president' => [],
    'secretary' => []
];

$query = "SELECT president, vice_president, secretary FROM votes";
$result = $conn->query($query);

while ($row = $result->fetch_assoc()) {
    $results['president'][] = $row['president'];
    $results['vice_president'][] = $row['vice_president'];
    $results['secretary'][] = $row['secretary'];
}

function count_votes($votes) {
    return array_count_values($votes);
}

$president_counts = count_votes($results['president']);
$vice_president_counts = count_votes($results['vice_president']);
$secretary_counts = count_votes($results['secretary']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vote Success - Online Voting System</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <div class="container">
            <h1 style="margin: 0; color: white;">Online Voting System</h1>
            <nav>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <main>
        <div class="content-wrapper" style="text-align: center;">
            <h1>✓ Vote Successfully Submitted!</h1>

            <div class="alert alert-success" style="margin-top: 30px;">
                <h2 style="color: #155724; margin: 0;">Thank You for Voting!</h2>
                <p style="margin-top: 15px; font-size: 1.1em;">
                    Your vote has been securely recorded. You have successfully completed your voting process.
                </p>
            </div>

            <div style="margin-top: 40px; padding: 20px; background: var(--light-bg); border-radius: var(--border-radius);">
                <p><strong>Important Note:</strong></p>
                <p>You cannot vote again as per the system's one-vote-per-student policy for maintaining election integrity.</p>
            </div>

            <div style="margin-top: 30px;">
                <a href="logout.php" class="btn btn-secondary btn-large">Logout</a>
            </div>

            <div style="margin-top: 50px; text-align: left;">
                <h2>Election Results Dashboard</h2>
                
                <h3>President</h3>
                <ul>
                    <?php foreach ($president_counts as $candidate => $count): ?>
                        <li><?php echo htmlspecialchars($candidate); ?>: <?php echo $count; ?> votes</li>
                    <?php endforeach; ?>
                </ul>

                <h3>Vice President</h3>
                <ul>
                    <?php foreach ($vice_president_counts as $candidate => $count): ?>
                        <li><?php echo htmlspecialchars($candidate); ?>: <?php echo $count; ?> votes</li>
                    <?php endforeach; ?>
                </ul>

                <h3>Secretary</h3>
                <ul>
                    <?php foreach ($secretary_counts as $candidate => $count): ?>
                        <li><?php echo htmlspecialchars($candidate); ?>: <?php echo $count; ?> votes</li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </main>

    <footer>
        <p>&copy; 2026 College Online Voting System. All rights reserved.</p>
    </footer>
</body>
</html>

