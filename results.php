<?php
session_start();
include 'config.php';

$positions = [
    'President' => 'president',
    'Vice President' => 'vice_president',
    'Secretary' => 'secretary'
];

$results = [];
foreach ($positions as $label => $column) {
    $sql = "SELECT $column AS candidate, COUNT(*) AS votes FROM votes WHERE $column IS NOT NULL AND TRIM($column) <> '' GROUP BY $column ORDER BY votes DESC, candidate ASC";
    $query = $conn->query($sql);
    $results[$label] = $query ? $query->fetch_all(MYSQLI_ASSOC) : [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Election Results</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; }
        .container { max-width: 900px; margin: 20px auto; padding: 20px; }
        .header { background: #2196F3; color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .section { background: white; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .result-item { border-bottom: 1px solid #eee; padding: 12px 0; }
        .result-item:last-child { border-bottom: none; }
        .progress { background: #ddd; height: 24px; border-radius: 4px; overflow: hidden; margin: 6px 0; }
        .progress-bar { background: #4CAF50; height: 100%; text-align: center; line-height: 24px; color: white; font-size: 13px; }
        a { color: #2196F3; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 Election Results</h1>
            <p>Live tally from submitted ballots.</p>
            <a href="<?php echo isLoggedIn() ? 'vote.php' : 'login.php'; ?>" style="color: white;">Back</a>
        </div>

        <?php foreach ($results as $position => $rows): ?>
            <div class="section">
                <h2><?php echo htmlspecialchars($position); ?></h2>
                <?php if (empty($rows)): ?>
                    <p>No votes have been cast for this position yet.</p>
                <?php else:
                    $max_votes = max(array_column($rows, 'votes')) ?: 1;
                    foreach ($rows as $row):
                        $percentage = $max_votes > 0 ? round(($row['votes'] / $max_votes) * 100) : 0;
                ?>
                    <div class="result-item">
                        <strong><?php echo htmlspecialchars($row['candidate']); ?></strong>
                        <div class="progress">
                            <div class="progress-bar" style="width: <?php echo $percentage; ?>%;">
                                <?php echo $row['votes']; ?> vote<?php echo $row['votes'] == 1 ? '' : 's'; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
