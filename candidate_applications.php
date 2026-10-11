<?php
require_once 'auth.php';
startAppSession();
require_once 'db.php';
requireStudent();
require_once 'includes/navigation.php';

$userId = (int) $_SESSION['user_id'];
$errors = [];
$success = $_SESSION['candidate_application_flash'] ?? '';
unset($_SESSION['candidate_application_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } else {
        $positionId = filter_var($_POST['position_id'] ?? null, FILTER_VALIDATE_INT);
        $statement = trim(requestString($_POST, 'statement_of_purpose'));
        $qualifications = trim(requestString($_POST, 'qualifications'));
        if (!$positionId || strlen($statement) < 20 || strlen($statement) > 10000
            || strlen($qualifications) > 10000) {
            $errors[] = 'Enter a statement of purpose between 20 and 10,000 characters. Qualifications are optional and must be no longer than 10,000 characters.';
        } else {
            try {
                $conn->begin_transaction();
                $positionStmt = $conn->prepare(
                    "SELECT p.id, p.name AS position_name, e.id AS election_id, e.title AS election_title
                     FROM positions p
                     JOIN elections e ON e.id = p.election_id
                     JOIN users u ON u.id = ?
                     WHERE p.id = ? AND p.applications_open = 1
                       AND (p.applications_open_at IS NULL OR p.applications_open_at <= CURRENT_TIMESTAMP)
                       AND (p.applications_close_at IS NULL OR p.applications_close_at >= CURRENT_TIMESTAMP)
                       AND e.status IN ('draft', 'scheduled') AND e.starts_at > CURRENT_TIMESTAMP
                       AND u.role = 'student' AND u.is_verified = 1 AND u.is_active = 1
                     FOR UPDATE"
                );
                $positionStmt->bind_param('ii', $userId, $positionId);
                $positionStmt->execute();
                $position = $positionStmt->get_result()->fetch_assoc();
                $positionStmt->close();
                if (!$position) {
                    throw new RuntimeException('This position is not accepting applications, the deadline has passed, or your account is not eligible to apply.');
                }

                $applicationStmt = $conn->prepare(
                    "INSERT INTO candidate_applications
                        (user_id, election_id, position_id, statement_of_purpose, qualifications)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $applicationStmt->bind_param(
                    'iiiss',
                    $userId,
                    $position['election_id'],
                    $positionId,
                    $statement,
                    $qualifications
                );
                $applicationStmt->execute();
                $applicationId = $conn->insert_id;
                $applicationStmt->close();

                $notificationTitle = 'Candidate application submitted';
                $notificationMessage = 'Your application for ' . $position['position_name']
                    . ' in ' . $position['election_title'] . ' is awaiting administrator review.';
                $notificationStmt = $conn->prepare(
                    'INSERT INTO notifications (user_id, election_id, title, message) VALUES (?, ?, ?, ?)'
                );
                $notificationStmt->bind_param(
                    'iiss',
                    $userId,
                    $position['election_id'],
                    $notificationTitle,
                    $notificationMessage
                );
                $notificationStmt->execute();
                $notificationStmt->close();

                $adminNotificationTitle = 'New candidate application';
                $adminNotificationMessage = $_SESSION['user_name'] . ' applied for '
                    . $position['position_name'] . ' in ' . $position['election_title'] . '.';
                $adminNotificationStmt = $conn->prepare(
                    "INSERT INTO notifications (user_id, election_id, title, message)
                     SELECT id, ?, ?, ? FROM users WHERE role = 'admin' AND is_active = 1"
                );
                $adminNotificationStmt->bind_param(
                    'iss',
                    $position['election_id'],
                    $adminNotificationTitle,
                    $adminNotificationMessage
                );
                $adminNotificationStmt->execute();
                $adminNotificationStmt->close();
                $conn->commit();

                $_SESSION['candidate_application_flash'] = 'Your application has been submitted successfully. It is now awaiting admin review.';
                header('Location: candidate_applications.php');
                exit();
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                if ($exception->getCode() === 1062) {
                    $errors[] = 'You have already applied for this position. Duplicate applications are not allowed.';
                } else {
                    error_log('Candidate application submission failed: ' . $exception->getMessage());
                    $errors[] = 'Your application could not be submitted. Please try again.';
                }
            } catch (RuntimeException $exception) {
                $conn->rollback();
                $errors[] = $exception->getMessage();
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Candidate application submission failed: ' . $exception->getMessage());
                $errors[] = 'Your application could not be submitted. Please try again.';
            }
        }
    }
}

$positionStmt = $conn->prepare(
    "SELECT p.id, p.name AS position_name, p.description, p.eligibility_requirements,
            p.applications_open_at, p.applications_close_at, e.id AS election_id,
            e.title AS election_title,
            ca.id AS application_id, ca.status AS application_status
     FROM positions p
     JOIN elections e ON e.id = p.election_id
     LEFT JOIN candidate_applications ca ON ca.position_id = p.id AND ca.user_id = ?
     WHERE p.applications_open = 1
       AND (p.applications_open_at IS NULL OR p.applications_open_at <= CURRENT_TIMESTAMP)
       AND (p.applications_close_at IS NULL OR p.applications_close_at >= CURRENT_TIMESTAMP)
       AND e.status IN ('draft', 'scheduled') AND e.starts_at > CURRENT_TIMESTAMP
     ORDER BY COALESCE(p.applications_close_at, '9999-12-31'), e.starts_at, p.sort_order, p.id"
);
$positionStmt->bind_param('i', $userId);
$positionStmt->execute();
$openPositions = $positionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$positionStmt->close();

$applicationStmt = $conn->prepare(
    'SELECT ca.id, ca.statement_of_purpose, ca.qualifications, ca.status, ca.admin_feedback,
            ca.created_at, ca.reviewed_at, e.title AS election_title, p.name AS position_name
     FROM candidate_applications ca
     JOIN elections e ON e.id = ca.election_id
     JOIN positions p ON p.id = ca.position_id AND p.election_id = ca.election_id
     WHERE ca.user_id = ?
     ORDER BY ca.created_at DESC, ca.id DESC'
);
$applicationStmt->bind_param('i', $userId);
$applicationStmt->execute();
$applications = $applicationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$applicationStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candidate Applications - College Voting System</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; background: #f2f4fa; color: #303849; font-family: Arial, sans-serif; }
        main { max-width: 1050px; margin: auto; }
        section { margin-bottom: 18px; padding: 20px; border-radius: 10px; background: #fff; box-shadow: 0 2px 10px rgba(20,30,60,.08); }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 14px; }
        .card { padding: 16px; border: 1px solid #e1e5ef; border-radius: 9px; }
        label { display: block; margin: 12px 0 5px; font-weight: 600; }
        textarea { width: 100%; min-height: 100px; padding: 10px; border: 1px solid #cbd1df; border-radius: 6px; font: inherit; resize: vertical; }
        button, .button { display: inline-block; margin-top: 10px; padding: 10px 14px; border: 0; border-radius: 6px; background: #586de0; color: #fff; text-decoration: none; cursor: pointer; }
        .muted { color: #687386; }
        .notice, .error { margin: 10px 0; padding: 12px; border-radius: 6px; }
        .notice { background: #edf8f0; color: #236b35; }
        .error { background: #fff0f0; color: #942e2e; }
        .status { display: inline-block; padding: 4px 9px; border-radius: 20px; background: #fff5db; color: #815d13; font-size: 12px; font-weight: 700; text-transform: capitalize; }
        .status.approved { background: #e9f7ef; color: #21794b; }
        .status.rejected { background: #fff0ed; color: #a63e36; }
        details { margin-top: 12px; }
        summary { color: #4d5fc8; cursor: pointer; font-weight: 650; }
        @media(max-width: 600px) { body { padding: 10px; } section { padding: 15px; } }
    </style>
    <link rel="stylesheet" href="includes/navigation.css">
</head>
<body>
<?php renderAuthenticatedNavigation('Candidate Applications', 'Apply for an open election position and track your application.', 'candidate-applications'); ?>
<main>
    <?php if ($success): ?><p class="notice"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php foreach ($errors as $error): ?><p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endforeach; ?>

    <section id="open-positions">
        <h2>Positions accepting applications</h2>
        <p class="muted">Only active, verified student accounts can apply. Applications are linked to your signed-in account.</p>
        <?php if (!$openPositions): ?><p class="muted">There are no positions currently accepting applications.</p><?php endif; ?>
        <div class="grid">
            <?php foreach ($openPositions as $position): ?>
                <article class="card">
                    <h3><?php echo htmlspecialchars($position['position_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p><strong><?php echo htmlspecialchars($position['election_title'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
                    <?php if ($position['description']): ?><p><?php echo nl2br(htmlspecialchars($position['description'], ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
                    <?php if ($position['eligibility_requirements']): ?>
                        <p><strong>Eligibility:</strong> <?php echo nl2br(htmlspecialchars($position['eligibility_requirements'], ENT_QUOTES, 'UTF-8')); ?></p>
                    <?php else: ?><p class="muted">Eligibility: Verified students may apply.</p><?php endif; ?>
                    <?php if ($position['applications_close_at']): ?><p class="muted">Application deadline: <?php echo htmlspecialchars($position['applications_close_at'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                    <?php if ($position['application_id']): ?>
                        <p class="status <?php echo htmlspecialchars($position['application_status'], ENT_QUOTES, 'UTF-8'); ?>">Already applied: <?php echo htmlspecialchars($position['application_status'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php else: ?>
                        <details>
                            <summary>Apply for this position</summary>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="position_id" value="<?php echo (int) $position['id']; ?>">
                                <p class="muted">Applying as <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Student', ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($_SESSION['student_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>)</p>
                                <label for="statement-<?php echo (int) $position['id']; ?>">Statement of purpose (20–10,000 characters)</label>
                                <textarea id="statement-<?php echo (int) $position['id']; ?>" name="statement_of_purpose" minlength="20" maxlength="10000" required></textarea>
                                <label for="qualifications-<?php echo (int) $position['id']; ?>">Experience and qualifications (optional)</label>
                                <textarea id="qualifications-<?php echo (int) $position['id']; ?>" name="qualifications" maxlength="10000"></textarea>
                                <p class="muted">You can submit only one application for this position. Review your answers before submitting.</p>
                                <button type="submit">Submit application</button>
                            </form>
                        </details>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section id="application-history">
        <h2>My application history</h2>
        <?php if (!$applications): ?><p class="muted">You have not submitted any candidate applications.</p><?php endif; ?>
        <div class="grid">
            <?php foreach ($applications as $application): ?>
                <article class="card">
                    <h3><?php echo htmlspecialchars($application['position_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p><?php echo htmlspecialchars($application['election_title'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <p>Applied: <?php echo htmlspecialchars($application['created_at'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <p>Status: <span class="status <?php echo htmlspecialchars($application['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($application['status'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                    <?php if ($application['status'] === 'pending'): ?>
                        <p class="muted">Your application is awaiting administrator review.</p>
                    <?php elseif ($application['status'] === 'approved'): ?>
                        <p class="notice">Your application has been approved. You are now an official candidate for this position.</p>
                    <?php else: ?>
                        <p class="muted">Your application was not accepted.</p>
                    <?php endif; ?>
                    <?php if ($application['admin_feedback']): ?><p><strong>Administrator feedback:</strong> <?php echo nl2br(htmlspecialchars($application['admin_feedback'], ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
                    <details>
                        <summary>View submitted application</summary>
                        <p><strong>Statement of purpose</strong><br><?php echo nl2br(htmlspecialchars($application['statement_of_purpose'], ENT_QUOTES, 'UTF-8')); ?></p>
                        <?php if ($application['qualifications']): ?><p><strong>Experience and qualifications</strong><br><?php echo nl2br(htmlspecialchars($application['qualifications'], ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
                    </details>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>
<?php renderAuthenticatedNavigationEnd(); ?>
</body>
</html>
