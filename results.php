<?php
require_once 'auth.php';
startAppSession();
require_once 'db.php';
requireAuthenticatedUser();
require_once 'includes/navigation.php';
header('Cache-Control: no-store, private');

$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$electionId = filter_input(INPUT_GET, 'election_id', FILTER_VALIDATE_INT);

if ($electionId) {
    $stmt = $conn->prepare(
        'SELECT id, title, description, starts_at, ends_at, status, results_published
         FROM elections WHERE id = ? AND (results_published = 1 OR ? = 1)'
    );
    $stmt->bind_param('ii', $electionId, $isAdmin);
    $stmt->execute();
    $election = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$election) {
        http_response_code(404);
        exit('Results are not available for this election.');
    }

    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM election_eligibility WHERE election_id = ?');
    $stmt->bind_param('i', $electionId);
    $stmt->execute();
    $eligibleCount = (int) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM election_participation WHERE election_id = ?');
    $stmt->bind_param('i', $electionId);
    $stmt->execute();
    $turnoutCount = (int) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT p.id AS position_id, p.name AS position_name,
                c.id AS candidate_id, c.name AS candidate_name,
                COUNT(b.id) AS vote_count
         FROM positions p
         LEFT JOIN candidates c
           ON c.position_id = p.id AND c.election_id = p.election_id AND c.status = 'approved'
         LEFT JOIN ballots b
           ON b.candidate_id = c.id AND b.election_id = c.election_id AND b.position_id = c.position_id
         WHERE p.election_id = ?
         GROUP BY p.id, p.name, p.sort_order, c.id, c.name
         ORDER BY p.sort_order, p.id, vote_count DESC, c.name"
    );
    $stmt->bind_param('i', $electionId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $positions = [];
    foreach ($rows as $row) {
        $positionId = (int) $row['position_id'];
        if (!isset($positions[$positionId])) {
            $positions[$positionId] = [
                'name' => $row['position_name'],
                'candidates' => []
            ];
        }
        if ($row['candidate_id'] !== null) {
            $positions[$positionId]['candidates'][] = [
                'name' => $row['candidate_name'],
                'votes' => (int) $row['vote_count']
            ];
        }
    }
} else {
    $filter = $isAdmin ? '' : 'WHERE results_published = 1';
    $elections = $conn->query(
        "SELECT id, title, starts_at, ends_at, status
         FROM elections $filter ORDER BY ends_at DESC, id DESC"
    )->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Election Results</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; background: #f2f4fa; color: #303849; font-family: Arial,sans-serif; }
        main { max-width: 950px; margin: auto; }
        header,section { background: #fff; border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        .card { border: 1px solid #e1e5ef; border-radius: 8px; padding: 14px; margin: 10px 0; }
        .bar { height: 20px; border-radius: 5px; background: #e5e7eb; overflow: hidden; }
        .fill { height: 100%; background: #586de0; }
        .muted { color: #687386; }
        a.button { display: inline-block; padding: 10px 14px; background: #586de0; color: white; border-radius: 6px; text-decoration: none; }
        @media(max-width:600px) { body { padding:10px; } }
    </style>
    <link rel="stylesheet" href="includes/navigation.css">
</head>
<body>
<?php
$resultsNavigation = requestString($_GET, 'nav') === 'reports' && $isAdmin ? 'reports' : 'results';
$resultsDescription = isset($election)
    ? 'Published election totals, percentages, and turnout.'
    : 'Review results and turnout from elections.';
renderAuthenticatedNavigation('Election Results', $resultsDescription, $resultsNavigation);
?>
<main id="reports">
    <header>
        <?php if (isset($election)): ?><h2><?php echo htmlspecialchars($election['title'], ENT_QUOTES, 'UTF-8'); ?> — Results</h2><?php endif; ?>
        <a class="button" href="<?php echo $isAdmin ? 'admin.php' : 'dashboard.php'; ?>">Back</a>
        <?php if (isset($election)): ?>
            <p><?php echo htmlspecialchars($election['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
            <p class="muted">Status: <?php echo htmlspecialchars($election['status'], ENT_QUOTES, 'UTF-8'); ?>
                · Turnout: <?php echo $eligibleCount ? round($turnoutCount / $eligibleCount * 100, 1) : 0; ?>%
                (<?php echo $turnoutCount; ?> of <?php echo $eligibleCount; ?> eligible voters)</p>
            <?php if (!$election['results_published']): ?><p class="muted">These tallies are visible only to administrators until results are published.</p><?php endif; ?>
        <?php endif; ?>
    </header>

    <?php if (!isset($election)): ?>
        <section>
            <h2><?php echo $elections ? 'Select an election' : 'No results are available yet'; ?></h2>
            <?php foreach ($elections as $item): ?>
                <article class="card">
                    <h3><?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p class="muted"><?php echo htmlspecialchars($item['starts_at'] . ' — ' . $item['ends_at'] . ' · ' . $item['status'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <a class="button" href="results.php?election_id=<?php echo (int) $item['id']; ?>">View results</a>
                </article>
            <?php endforeach; ?>
        </section>
    <?php else: ?>
        <?php foreach ($positions as $position): ?>
            <section>
                <h2><?php echo htmlspecialchars($position['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                <?php if (!$position['candidates']): ?><p class="muted">No candidate totals are available.</p><?php else: ?>
                    <?php
                    $totalVotes = array_sum(array_column($position['candidates'], 'votes'));
                    $highestVotes = max(array_column($position['candidates'], 'votes'));
                    $winners = array_filter($position['candidates'], function ($candidate) use ($highestVotes, $totalVotes) {
                        return $totalVotes > 0 && $candidate['votes'] === $highestVotes;
                    });
                    ?>
                    <?php foreach ($position['candidates'] as $candidate): ?>
                        <?php $percentage = $totalVotes ? round($candidate['votes'] / $totalVotes * 100, 1) : 0; ?>
                        <article class="card">
                            <div><strong><?php echo htmlspecialchars($candidate['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                · <?php echo $candidate['votes']; ?> vote<?php echo $candidate['votes'] === 1 ? '' : 's'; ?>
                                · <?php echo $percentage; ?>%</div>
                            <div class="bar" role="progressbar" aria-valuenow="<?php echo $percentage; ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="fill" style="width: <?php echo $percentage; ?>%"></div>
                            </div>
                            <?php if (in_array($candidate, $winners, true)): ?><strong>Leading / tied</strong><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                    <?php if (!$totalVotes): ?><p class="muted">No votes have been recorded for this position.</p><?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
<?php renderAuthenticatedNavigationEnd(); ?>
</body>
</html>
