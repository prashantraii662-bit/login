<?php
require_once 'auth.php';
startAppSession();
require_once 'db.php';
requireStudent();
require_once 'includes/navigation.php';
header('Cache-Control: no-store, private');

$electionId = filter_input(INPUT_GET, 'election_id', FILTER_VALIDATE_INT);
if (!$electionId || $electionId < 1) {
    http_response_code(400);
    exit('A valid election is required.');
}

$electionStmt = $conn->prepare(
    'SELECT id, title, description, starts_at, ends_at, status
     FROM elections WHERE id = ?'
);
$electionStmt->bind_param('i', $electionId);
$electionStmt->execute();
$election = $electionStmt->get_result()->fetch_assoc();
$electionStmt->close();
if (!$election) {
    http_response_code(404);
    exit('Election not found.');
}

$positionStmt = $conn->prepare(
    'SELECT id, name, description
     FROM positions
     WHERE election_id = ?
     ORDER BY sort_order, id'
);
$positionStmt->bind_param('i', $electionId);
$positionStmt->execute();
$positions = $positionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$positionStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Election Positions - <?php echo htmlspecialchars($election['title'], ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; background: #f2f4fa; color: #303849; font-family: Arial, sans-serif; }
        main { max-width: 950px; margin: auto; }
        header, section { margin-bottom: 16px; padding: 20px; border-radius: 10px; background: #fff; }
        .position { margin: 10px 0; padding: 15px; border: 1px solid #e1e5ef; border-radius: 8px; }
        .muted { color: #687386; }
        a.button { display: inline-block; padding: 10px 14px; border-radius: 6px; background: #586de0; color: #fff; text-decoration: none; }
        @media(max-width: 600px) { body { padding: 10px; } }
    </style>
    <link rel="stylesheet" href="includes/navigation.css">
</head>
<body>
<?php renderAuthenticatedNavigation('Election Positions', 'Review the positions in ' . $election['title'] . '.', 'elections'); ?>
<main>
    <header>
        <h2><?php echo htmlspecialchars($election['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
        <?php if ($election['description']): ?><p><?php echo nl2br(htmlspecialchars($election['description'], ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
        <p class="muted">
            Status: <?php echo htmlspecialchars($election['status'], ENT_QUOTES, 'UTF-8'); ?><br>
            Opens: <?php echo htmlspecialchars($election['starts_at'], ENT_QUOTES, 'UTF-8'); ?><br>
            Closes: <?php echo htmlspecialchars($election['ends_at'], ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <a class="button" href="dashboard.php?nav=elections#elections">Back to Elections</a>
    </header>
    <section>
        <h2>Positions</h2>
        <?php if (!$positions): ?><p class="muted">No positions have been added to this election yet.</p><?php endif; ?>
        <?php foreach ($positions as $position): ?>
            <article class="position">
                <h3><?php echo htmlspecialchars($position['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                <?php if ($position['description']): ?><p><?php echo nl2br(htmlspecialchars($position['description'], ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
</main>
<?php renderAuthenticatedNavigationEnd(); ?>
</body>
</html>
