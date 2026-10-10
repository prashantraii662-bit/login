<?php
require_once 'auth.php';
startAppSession();
require_once 'db.php';
requireStudent();
require_once 'includes/navigation.php';

$errors = [];
$elections = [];
$positions = [];
$candidatesByPosition = [];
$alreadyParticipated = false;
$requestedElectionId = requestString($_GET, 'election_id');
if ($requestedElectionId === '') {
    $requestedElectionId = requestString($_POST, 'election_id');
}
$selectedElectionId = filter_var($requestedElectionId, FILTER_VALIDATE_INT);
$selectedElectionId = $selectedElectionId ? (int) $selectedElectionId : 0;

$electionQuery = $conn->query(
    "SELECT id, title, description, starts_at, ends_at
     FROM elections
     WHERE status = 'active'
       AND CURRENT_TIMESTAMP BETWEEN starts_at AND ends_at
     ORDER BY starts_at, id"
);
$elections = $electionQuery->fetch_all(MYSQLI_ASSOC);

$election = null;
foreach ($elections as $availableElection) {
    if ($selectedElectionId === 0 || (int) $availableElection['id'] === $selectedElectionId) {
        $election = $availableElection;
        $selectedElectionId = (int) $availableElection['id'];
        break;
    }
}

if ($election) {
    $stmt = $conn->prepare('SELECT id, name, description FROM positions WHERE election_id = ? ORDER BY sort_order, id');
    $stmt->bind_param('i', $selectedElectionId);
    $stmt->execute();
    $positions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $candidateStmt = $conn->prepare(
        "SELECT id, student_id, name, photo_path, manifesto
         FROM candidates
         WHERE election_id = ? AND position_id = ? AND status = 'approved'
         ORDER BY name, id"
    );
    foreach ($positions as $position) {
        $positionId = (int) $position['id'];
        $candidateStmt->bind_param('ii', $selectedElectionId, $positionId);
        $candidateStmt->execute();
        $candidatesByPosition[$positionId] = $candidateStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $candidateStmt->close();

    $participationStmt = $conn->prepare(
        'SELECT id FROM election_participation WHERE election_id = ? AND user_id = ?'
    );
    $participationStmt->bind_param('ii', $selectedElectionId, $_SESSION['user_id']);
    $participationStmt->execute();
    $alreadyParticipated = (bool) $participationStmt->get_result()->fetch_assoc();
    $participationStmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Refresh the page and submit your ballot again.';
    } elseif (!$election || !$positions) {
        $errors[] = 'This election is not accepting votes.';
    } else {
        $selections = $_POST['selection'] ?? [];
        $positionIds = array_map(function ($position) {
            return (string) $position['id'];
        }, $positions);

        if (!is_array($selections) || count($selections) !== count($positionIds)
            || array_diff(array_keys($selections), $positionIds)
            || array_diff($positionIds, array_keys($selections))) {
            $errors[] = 'Select exactly one approved candidate for every position.';
        } else {
            foreach ($positions as $position) {
                $positionId = (int) $position['id'];
                $candidateId = filter_var($selections[(string) $positionId], FILTER_VALIDATE_INT);
                $validCandidate = false;
                foreach ($candidatesByPosition[$positionId] as $candidate) {
                    if ((int) $candidate['id'] === $candidateId) {
                        $validCandidate = true;
                        break;
                    }
                }
                if (!$validCandidate) {
                    $errors[] = 'One or more selections are not valid for this election.';
                    break;
                }
            }
        }

        if (!$errors) {
            try {
                $conn->begin_transaction();

                $userStmt = $conn->prepare(
                    "SELECT id FROM users
                     WHERE id = ? AND role = 'student' AND is_verified = 1 AND is_active = 1
                       AND EXISTS (
                           SELECT 1 FROM election_eligibility ee
                           WHERE ee.election_id = ? AND ee.user_id = users.id
                       )
                     FOR UPDATE"
                );
                $userStmt->bind_param('ii', $_SESSION['user_id'], $selectedElectionId);
                $userStmt->execute();
                $eligibleUser = $userStmt->get_result()->fetch_assoc();
                $userStmt->close();

                if (!$eligibleUser) {
                    throw new RuntimeException('The account is not currently eligible to vote.');
                }

                $activeStmt = $conn->prepare(
                    "SELECT id FROM elections
                     WHERE id = ? AND status = 'active'
                       AND CURRENT_TIMESTAMP BETWEEN starts_at AND ends_at
                     FOR UPDATE"
                );
                $activeStmt->bind_param('i', $selectedElectionId);
                $activeStmt->execute();
                $activeElection = $activeStmt->get_result()->fetch_assoc();
                $activeStmt->close();

                if (!$activeElection) {
                    throw new RuntimeException('Voting for this election is not open.');
                }

                $participationStmt = $conn->prepare(
                    'INSERT INTO election_participation (election_id, user_id) VALUES (?, ?)'
                );
                $participationStmt->bind_param('ii', $selectedElectionId, $_SESSION['user_id']);
                $participationStmt->execute();
                $participationStmt->close();

                $ballotStmt = $conn->prepare(
                    'INSERT INTO ballots (election_id, position_id, candidate_id) VALUES (?, ?, ?)'
                );
                foreach ($positions as $position) {
                    $positionId = (int) $position['id'];
                    $candidateId = (int) $selections[(string) $positionId];
                    $ballotStmt->bind_param('iii', $selectedElectionId, $positionId, $candidateId);
                    $ballotStmt->execute();
                }
                $ballotStmt->close();
                $conn->commit();

                $_SESSION['vote_success_election'] = $selectedElectionId;
                header('Location: vote_success.php');
                exit();
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                if ((int) $exception->getCode() === 1062) {
                    $errors[] = 'A ballot has already been submitted for this election.';
                } else {
                    error_log('Vote submission database error: ' . $exception->getMessage());
                    $errors[] = 'The ballot could not be recorded. Please try again.';
                }
            } catch (RuntimeException $exception) {
                $conn->rollback();
                $errors[] = $exception->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Active Elections - College Voting System</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; min-height: 100vh; font-family: 'Segoe UI', Tahoma, sans-serif; color: #333; background: linear-gradient(135deg, #667eea, #764ba2); }
        .container { max-width: 760px; margin: 0 auto; }
        .header, .content { background: #fff; padding: 24px; box-shadow: 0 5px 20px rgba(0,0,0,.1); }
        .header { display: flex; justify-content: space-between; align-items: center; border-radius: 14px 14px 0 0; }
        .content { border-radius: 0 0 14px 14px; }
        h1, h2, h3 { margin-top: 0; }
        .election-card, .position { border: 1px solid #e2e5ef; border-radius: 10px; padding: 18px; margin: 16px 0; }
        .candidate { display: block; padding: 12px; margin: 8px 0; border-radius: 7px; background: #f7f8fc; }
        .candidate input { margin-right: 10px; }
        .error { padding: 12px; margin-bottom: 14px; border: 1px solid #f2b9b9; border-radius: 7px; background: #fff0f0; color: #9d2020; }
        .muted { color: #656d7d; }
        button, .link-button { display: inline-block; padding: 12px 18px; border: 0; border-radius: 7px; background: #667eea; color: #fff; text-decoration: none; font-weight: 600; cursor: pointer; }
        .logout { background: #e74c3c; }
        @media (max-width: 560px) { .header { align-items: flex-start; flex-direction: column; gap: 12px; } body { padding: 10px; } .header, .content { padding: 18px; } }
    </style>
    <link rel="stylesheet" href="includes/navigation.css">
</head>
<body>
<?php renderAuthenticatedNavigation('Cast Vote', $election ? 'Make your selections for ' . $election['title'] . '.' : 'Choose an available active election.', 'vote'); ?>
    <main class="container">
        <section class="content">
            <?php foreach ($errors as $error): ?>
                <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endforeach; ?>

            <?php if (!$elections): ?>
                <div class="election-card">
                    <h2>No active elections</h2>
                    <p class="muted">There are no elections currently open for voting. Check back when an election is scheduled.</p>
                </div>
            <?php elseif (!$election): ?>
                <div class="election-card">
                    <h2>Choose an active election</h2>
                    <?php foreach ($elections as $availableElection): ?>
                        <p><a href="vote.php?election_id=<?php echo (int) $availableElection['id']; ?>"><?php echo htmlspecialchars($availableElection['title'], ENT_QUOTES, 'UTF-8'); ?></a></p>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($alreadyParticipated): ?>
                <div class="election-card">
                    <h2><?php echo htmlspecialchars($election['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <p class="muted">A ballot has already been submitted for this election. Thank you for participating.</p>
                </div>
            <?php elseif (!$positions): ?>
                <div class="election-card">
                    <h2><?php echo htmlspecialchars($election['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <p class="muted">This election has no voting positions configured yet.</p>
                </div>
            <?php else: ?>
                <div class="election-card">
                    <h2><?php echo htmlspecialchars($election['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <p><?php echo nl2br(htmlspecialchars($election['description'] ?? '', ENT_QUOTES, 'UTF-8')); ?></p>
                    <p class="muted">Voting closes <?php echo htmlspecialchars($election['ends_at'], ENT_QUOTES, 'UTF-8'); ?>. Your selections are submitted as an anonymous ballot.</p>
                </div>
                <form method="POST" action="vote.php?election_id=<?php echo $selectedElectionId; ?>" onsubmit="return confirm('Submit your ballot? Your selections cannot be changed.');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="election_id" value="<?php echo $selectedElectionId; ?>">
                    <?php foreach ($positions as $position): ?>
                        <?php $positionId = (int) $position['id']; ?>
                        <fieldset class="position">
                            <legend><h3><?php echo htmlspecialchars($position['name'], ENT_QUOTES, 'UTF-8'); ?></h3></legend>
                            <?php if (empty($candidatesByPosition[$positionId])): ?>
                                <p class="muted">No approved candidates are available for this position.</p>
                            <?php else: ?>
                                <?php foreach ($candidatesByPosition[$positionId] as $candidate): ?>
                                    <label class="candidate">
                                        <input type="radio" name="selection[<?php echo $positionId; ?>]" value="<?php echo (int) $candidate['id']; ?>" required>
                                        <strong><?php echo htmlspecialchars($candidate['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <?php if (!empty($candidate['student_id'])): ?>
                                            <span class="muted"> · <?php echo htmlspecialchars($candidate['student_id'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($candidate['manifesto'])): ?>
                                            <p><?php echo nl2br(htmlspecialchars($candidate['manifesto'], ENT_QUOTES, 'UTF-8')); ?></p>
                                        <?php endif; ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </fieldset>
                    <?php endforeach; ?>
                    <button type="submit">Review and submit ballot</button>
                </form>
            <?php endif; ?>
        </section>
    </main>
    <?php renderAuthenticatedNavigationEnd(); ?>
    </body>
</html>
