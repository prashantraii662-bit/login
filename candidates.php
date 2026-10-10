<?php
require_once 'auth.php';
startAppSession();
require_once 'db.php';
requireStudent();
require_once 'includes/navigation.php';

$electionId = filter_input(INPUT_GET, 'election_id', FILTER_VALIDATE_INT);
if (!$electionId) {
    http_response_code(400);
    exit('A valid election is required.');
}

$stmt = $conn->prepare(
    "SELECT id, title, description, starts_at, ends_at, status, results_published
     FROM elections
     WHERE id = ? AND (status IN ('scheduled', 'active') OR results_published = 1)"
);
$stmt->bind_param('i', $electionId);
$stmt->execute();
$election = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$election) {
    http_response_code(404);
    exit('Election not found.');
}

$stmt = $conn->prepare(
    "SELECT p.id, p.name AS position_name, c.name, c.student_id, c.photo_path, c.manifesto
     FROM positions p
     LEFT JOIN candidates c ON c.position_id = p.id AND c.election_id = p.election_id AND c.status = 'approved'
     WHERE p.election_id = ?
     ORDER BY p.sort_order, p.id, c.name, c.id"
);
$stmt->bind_param('i', $electionId);
$stmt->execute();
$candidateRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$positions = [];
foreach ($candidateRows as $row) {
    $positions[$row['id']]['name'] = $row['position_name'];
    if ($row['name'] !== null) {
        $positions[$row['id']]['candidates'][] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candidates - <?php echo htmlspecialchars($election['title'], ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; background: #f2f4fa; color: #303849; font-family: Arial,sans-serif; }
        main { max-width: 950px; margin: auto; }
        header,section { background: white; border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:12px; }
        .candidate { padding:15px; border:1px solid #e1e5ef; border-radius:8px; }
        img { max-width:120px; max-height:120px; object-fit:cover; }
        .muted { color:#687386; }
        a.button { display:inline-block; padding:10px 14px; background:#586de0; color:white; border-radius:6px; text-decoration:none; }
        @media(max-width:600px) { body { padding:10px; } }
    </style>
    <link rel="stylesheet" href="includes/navigation.css">
</head>
<body>
<?php renderAuthenticatedNavigation('Candidates', 'Review the candidates and positions for ' . $election['title'] . '.', 'candidates'); ?>
<main>
    <header>
        <h2><?php echo htmlspecialchars($election['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
        <p><?php echo nl2br(htmlspecialchars($election['description'] ?? '', ENT_QUOTES, 'UTF-8')); ?></p>
        <a class="button" href="dashboard.php">Back to dashboard</a>
        <?php if ($election['status'] === 'active' && strtotime($election['starts_at']) <= time() && strtotime($election['ends_at']) >= time()): ?><a class="button" href="vote.php?election_id=<?php echo $electionId; ?>">Vote in this election</a><?php endif; ?>
    </header>
    <?php foreach ($positions as $position): ?>
        <section>
            <h2><?php echo htmlspecialchars($position['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <?php if (empty($position['candidates'])): ?><p class="muted">No approved candidates for this position.</p><?php else: ?>
                <div class="grid">
                    <?php foreach ($position['candidates'] as $candidate): ?>
                        <article class="candidate">
                            <?php if ($candidate['photo_path']): ?><img src="<?php echo htmlspecialchars($candidate['photo_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="Candidate photo"><?php endif; ?>
                            <h3><?php echo htmlspecialchars($candidate['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                            <p class="muted"><?php echo htmlspecialchars($candidate['student_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                            <p><?php echo nl2br(htmlspecialchars($candidate['manifesto'] ?? '', ENT_QUOTES, 'UTF-8')); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</main>
<?php renderAuthenticatedNavigationEnd(); ?>
</body>
</html>
