<?php
session_start();
require_once 'db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}

// Get voting statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_votes,
        (SELECT COUNT(*) FROM users WHERE has_voted = 1) as voted_users,
        (SELECT COUNT(*) FROM users) as total_users
    FROM votes
";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Get results for each position
$president_query = "SELECT president as candidate, COUNT(*) as votes FROM votes GROUP BY president ORDER BY votes DESC";
$vice_president_query = "SELECT vice_president as candidate, COUNT(*) as votes FROM votes GROUP BY vice_president ORDER BY votes DESC";
$secretary_query = "SELECT secretary as candidate, COUNT(*) as votes FROM votes GROUP BY secretary ORDER BY votes DESC";

$president_results = $conn->query($president_query)->fetch_all(MYSQLI_ASSOC);
$vice_president_results = $conn->query($vice_president_query)->fetch_all(MYSQLI_ASSOC);
$secretary_results = $conn->query($secretary_query)->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Online Voting System</title>
    <style>
        body { font-family: Arial; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 2px solid #667eea; padding-bottom: 20px; }
        h1 { color: #333; margin: 0; }
        .logout-btn { padding: 10px 20px; background: #dc3545; color: white; text-decoration: none; border-radius: 5px; font-weight: bold; }
        .logout-btn:hover { background: #c82333; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-box { background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #667eea; }
        .stat-number { font-size: 28px; font-weight: bold; color: #667eea; }
        .stat-label { color: #666; font-size: 14px; margin-top: 5px; }
        .results { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; margin-top: 30px; }
        .position-result { background: #f8f9fa; padding: 20px; border-radius: 8px; border-top: 3px solid #667eea; }
        .position-result h3 { color: #333; margin-top: 0; }
        .candidate-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd; }
        .candidate-row:last-child { border-bottom: none; }
        .candidate-name { font-weight: bold; }
        .candidate-votes { color: #667eea; font-weight: bold; }
        .progress-bar { width: 100%; height: 8px; background: #ddd; border-radius: 4px; margin-top: 5px; overflow: hidden; }
        .progress-fill { height: 100%; background: #667eea; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>📊 Admin Dashboard</h1>
            <a href="admin_logout.php" class="logout-btn">Logout</a>
        </header>

        <div class="stats">
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['total_votes'] ?? 0; ?></div>
                <div class="stat-label">Total Votes Cast</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['voted_users'] ?? 0; ?></div>
                <div class="stat-label">Users Who Voted</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['total_users'] ?? 0; ?></div>
                <div class="stat-label">Total Registered Users</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo round(($stats['voted_users'] / max($stats['total_users'], 1)) * 100, 1); ?>%</div>
                <div class="stat-label">Voter Turnout</div>
            </div>
        </div>

        <h2 style="color: #333; margin-top: 40px;">Voting Results</h2>
        <div class="results">
            <!-- President Results -->
            <div class="position-result">
                <h3>🎯 President</h3>
                <?php if (!empty($president_results)): ?>
                    <?php 
                    $max_votes = $president_results[0]['votes'] ?? 1;
                    foreach ($president_results as $result): 
                    ?>
                        <div class="candidate-row">
                            <div class="candidate-name"><?php echo htmlspecialchars($result['candidate']); ?></div>
                            <div class="candidate-votes"><?php echo $result['votes']; ?></div>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo ($result['votes'] / max($max_votes, 1)) * 100; ?>%"></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color: #999;">No votes yet</p>
                <?php endif; ?>
            </div>

            <!-- Vice President Results -->
            <div class="position-result">
                <h3>📜 Vice President</h3>
                <?php if (!empty($vice_president_results)): ?>
                    <?php 
                    $max_votes = $vice_president_results[0]['votes'] ?? 1;
                    foreach ($vice_president_results as $result): 
                    ?>
                        <div class="candidate-row">
                            <div class="candidate-name"><?php echo htmlspecialchars($result['candidate']); ?></div>
                            <div class="candidate-votes"><?php echo $result['votes']; ?></div>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo ($result['votes'] / max($max_votes, 1)) * 100; ?>%"></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color: #999;">No votes yet</p>
                <?php endif; ?>
            </div>

            <!-- Secretary Results -->
            <div class="position-result">
                <h3>✍️ Secretary</h3>
                <?php if (!empty($secretary_results)): ?>
                    <?php 
                    $max_votes = $secretary_results[0]['votes'] ?? 1;
                    foreach ($secretary_results as $result): 
                    ?>
                        <div class="candidate-row">
                            <div class="candidate-name"><?php echo htmlspecialchars($result['candidate']); ?></div>
                            <div class="candidate-votes"><?php echo $result['votes']; ?></div>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo ($result['votes'] / max($max_votes, 1)) * 100; ?>%"></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color: #999;">No votes yet</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
