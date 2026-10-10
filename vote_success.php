<?php
require_once 'auth.php';
startAppSession();
// Include database configuration
require_once 'config.php';
require_once 'includes/navigation.php';

// Check if user is logged in
requireStudent();
if (empty($_SESSION['vote_success_election'])) {
    header('Location: vote.php');
    exit();
}
unset($_SESSION['vote_success_election']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vote Success - Online Voting System</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="includes/navigation.css">
</head>
<body>
    <?php renderAuthenticatedNavigation('Ballot submitted', 'Your participation has been recorded for this election.', 'vote'); ?>

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
                <a href="dashboard.php" class="btn btn-secondary btn-large">Return to Dashboard</a>
            </div>

        </div>
    </main>

    <?php renderAuthenticatedNavigationEnd(); ?>
</body>
</html>
