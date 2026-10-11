<?php
require_once 'auth.php';
startAppSession();
require_once 'db.php';
requireAdmin();
require_once 'includes/navigation.php';

function auditCandidateApplication($conn, $action, $applicationId, $details = '') {
    $adminId = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare(
        "INSERT INTO admin_audit_log (actor_user_id, action, entity_type, entity_id, details)
         VALUES (?, ?, 'candidate_application', ?, ?)"
    );
    $stmt->bind_param('isis', $adminId, $action, $applicationId, $details);
    $stmt->execute();
    $stmt->close();
}

function bindCandidateApplicationFilters($statement, $types, &$parameters) {
    $bindings = [$types];
    foreach ($parameters as &$parameter) {
        $bindings[] = &$parameter;
    }
    call_user_func_array([$statement, 'bind_param'], $bindings);
}

$errors = [];
$success = $_SESSION['admin_application_flash'] ?? '';
unset($_SESSION['admin_application_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } else {
        $applicationId = filter_var($_POST['application_id'] ?? null, FILTER_VALIDATE_INT);
        $decision = requestString($_POST, 'decision');
        $feedback = trim(requestString($_POST, 'admin_feedback'));
        if (!$applicationId || !in_array($decision, ['approve', 'reject'], true)
            || strlen($feedback) > 10000 || ($decision === 'reject' && $feedback === '')) {
            $errors[] = $decision === 'reject'
                ? 'Select an application and provide a rejection reason (up to 10,000 characters).'
                : 'Select a valid application and keep optional feedback within 10,000 characters.';
        } else {
            try {
                $conn->begin_transaction();
                $applicationStmt = $conn->prepare(
                    'SELECT ca.id, ca.user_id, ca.election_id, ca.position_id,
                            ca.statement_of_purpose, ca.qualifications, ca.status,
                            u.student_id, u.first_name, u.last_name, u.is_verified, u.is_active,
                            e.title AS election_title, e.status AS election_status,
                            e.starts_at, p.name AS position_name
                     FROM candidate_applications ca
                     JOIN users u ON u.id = ca.user_id
                     JOIN elections e ON e.id = ca.election_id
                     JOIN positions p ON p.id = ca.position_id AND p.election_id = ca.election_id
                     WHERE ca.id = ? FOR UPDATE'
                );
                $applicationStmt->bind_param('i', $applicationId);
                $applicationStmt->execute();
                $application = $applicationStmt->get_result()->fetch_assoc();
                $applicationStmt->close();
                if (!$application || $application['status'] !== 'pending') {
                    throw new RuntimeException('This application no longer exists or has already been reviewed.');
                }

                $adminId = (int) $_SESSION['user_id'];
                if ($decision === 'approve') {
                    if ((int) $application['is_verified'] !== 1 || (int) $application['is_active'] !== 1) {
                        throw new RuntimeException('This student is no longer eligible. The application cannot be approved.');
                    }
                    if (!in_array($application['election_status'], ['draft', 'scheduled'], true)
                        || strtotime($application['starts_at']) <= time()) {
                        throw new RuntimeException('Applications can only be approved before the election starts.');
                    }

                    $studentName = trim($application['first_name'] . ' ' . $application['last_name']);
                    $manifesto = "Statement of purpose:\n" . $application['statement_of_purpose'];
                    if ($application['qualifications'] !== null && trim($application['qualifications']) !== '') {
                        $manifesto .= "\n\nExperience and qualifications:\n" . $application['qualifications'];
                    }
                    $candidateStmt = $conn->prepare(
                        'SELECT id, application_id FROM candidates
                         WHERE election_id = ? AND position_id = ? AND student_id = ? FOR UPDATE'
                    );
                    $candidateStmt->bind_param(
                        'iis',
                        $application['election_id'],
                        $application['position_id'],
                        $application['student_id']
                    );
                    $candidateStmt->execute();
                    $existingCandidate = $candidateStmt->get_result()->fetch_assoc();
                    $candidateStmt->close();

                    if ($existingCandidate) {
                        if ($existingCandidate['application_id'] !== null
                            && (int) $existingCandidate['application_id'] !== (int) $applicationId) {
                            throw new RuntimeException('This student already has an official candidate record linked to another application.');
                        }
                        $candidateId = (int) $existingCandidate['id'];
                        $linkStmt = $conn->prepare(
                            "UPDATE candidates SET application_id = ?, status = 'approved'
                             WHERE id = ? AND (application_id IS NULL OR application_id = ?)"
                        );
                        $linkStmt->bind_param('iii', $applicationId, $candidateId, $applicationId);
                        $linkStmt->execute();
                        $linkStmt->close();
                    } else {
                        $candidateStmt = $conn->prepare(
                            "INSERT INTO candidates
                                (election_id, position_id, student_id, name, manifesto, status, application_id)
                             VALUES (?, ?, ?, ?, ?, 'approved', ?)"
                        );
                        $candidateStmt->bind_param(
                            'iisssi',
                            $application['election_id'],
                            $application['position_id'],
                            $application['student_id'],
                            $studentName,
                            $manifesto,
                            $applicationId
                        );
                        $candidateStmt->execute();
                        $candidateId = $conn->insert_id;
                        $candidateStmt->close();
                    }

                    $status = 'approved';
                    $auditAction = 'candidate_application_approved';
                    $notificationTitle = 'Candidate application approved';
                    $notificationMessage = 'Your application for ' . $application['position_name']
                        . ' in ' . $application['election_title'] . ' was approved. You are now an official candidate.';
                    $flashMessage = 'Application approved and linked to official candidate #' . $candidateId . '.';
                } else {
                    $status = 'rejected';
                    $auditAction = 'candidate_application_rejected';
                    $notificationTitle = 'Candidate application not accepted';
                    $notificationMessage = 'Your application for ' . $application['position_name']
                        . ' in ' . $application['election_title'] . ' was not accepted. Review the feedback in Candidate Applications.';
                    $flashMessage = 'Application rejected. The student can review your feedback in Candidate Applications.';
                }

                $reviewStmt = $conn->prepare(
                    'UPDATE candidate_applications
                     SET status = ?, admin_feedback = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP
                     WHERE id = ? AND status = \'pending\''
                );
                $reviewStmt->bind_param('ssii', $status, $feedback, $adminId, $applicationId);
                $reviewStmt->execute();
                if ($reviewStmt->affected_rows !== 1) {
                    $reviewStmt->close();
                    throw new RuntimeException('The application was already reviewed. Refresh the list and try again.');
                }
                $reviewStmt->close();

                auditCandidateApplication($conn, $auditAction, $applicationId, $feedback);
                $notificationStmt = $conn->prepare(
                    'INSERT INTO notifications (user_id, election_id, title, message) VALUES (?, ?, ?, ?)'
                );
                $notificationStmt->bind_param(
                    'iiss',
                    $application['user_id'],
                    $application['election_id'],
                    $notificationTitle,
                    $notificationMessage
                );
                $notificationStmt->execute();
                $notificationStmt->close();
                $conn->commit();
                $_SESSION['admin_application_flash'] = $flashMessage;
                header('Location: admin_candidate_applications.php');
                exit();
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                if ($exception->getCode() === 1062) {
                    $errors[] = 'An official candidate record already exists for this application. No duplicate was created.';
                } else {
                    error_log('Candidate application review failed: ' . $exception->getMessage());
                    $errors[] = 'The application decision could not be saved. No partial changes were kept.';
                }
            } catch (RuntimeException $exception) {
                $conn->rollback();
                $errors[] = $exception->getMessage();
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Candidate application review failed: ' . $exception->getMessage());
                $errors[] = 'The application decision could not be saved. No partial changes were kept.';
            }
        }
    }
}

$search = trim(requestString($_GET, 'q'));
$electionId = filter_var($_GET['election_id'] ?? 0, FILTER_VALIDATE_INT);
$electionId = $electionId ? (int) $electionId : 0;
$positionId = filter_var($_GET['position_id'] ?? 0, FILTER_VALIDATE_INT);
$positionId = $positionId ? (int) $positionId : 0;
$status = requestString($_GET, 'status');
if (!in_array($status, ['all', 'pending', 'approved', 'rejected'], true)) {
    $status = 'all';
}
$dateFrom = requestString($_GET, 'date_from');
$dateTo = requestString($_GET, 'date_to');
$validDate = function ($value) {
    if ($value === '') {
        return true;
    }
    $date = DateTime::createFromFormat('Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    return $date && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))
        && $date->format('Y-m-d') === $value;
};
if (!$validDate($dateFrom)) {
    $dateFrom = '';
}
if (!$validDate($dateTo)) {
    $dateTo = '';
}
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $page && $page > 0 ? (int) $page : 1;
$perPage = 10;
$searchLike = '%' . $search . '%';
$conditions = [];
$parameters = [];
$types = '';
if ($search !== '') {
    $conditions[] = "(CONCAT_WS(' ', u.first_name, NULLIF(u.middle_name, ''), u.last_name) LIKE ?
        OR u.student_id LIKE ? OR u.email LIKE ?)";
    $parameters[] = $searchLike;
    $parameters[] = $searchLike;
    $parameters[] = $searchLike;
    $types .= 'sss';
}
if ($electionId) {
    $conditions[] = 'ca.election_id = ?';
    $parameters[] = $electionId;
    $types .= 'i';
}
if ($positionId) {
    $conditions[] = 'ca.position_id = ?';
    $parameters[] = $positionId;
    $types .= 'i';
}
if ($status !== 'all') {
    $conditions[] = 'ca.status = ?';
    $parameters[] = $status;
    $types .= 's';
}
if ($dateFrom !== '') {
    $conditions[] = 'ca.created_at >= ?';
    $parameters[] = $dateFrom . ' 00:00:00';
    $types .= 's';
}
if ($dateTo !== '') {
    $conditions[] = 'ca.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $parameters[] = $dateTo . ' 00:00:00';
    $types .= 's';
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$countStmt = $conn->prepare(
    'SELECT COUNT(*) AS total FROM candidate_applications ca JOIN users u ON u.id = ca.user_id ' . $where
);
if ($types !== '') {
    bindCandidateApplicationFilters($countStmt, $types, $parameters);
}
$countStmt->execute();
$total = (int) $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$applicationStmt = $conn->prepare(
    'SELECT ca.id, ca.statement_of_purpose, ca.qualifications, ca.status, ca.admin_feedback,
            ca.created_at, ca.reviewed_at, ca.reviewed_by,
            u.first_name, u.middle_name, u.last_name, u.student_id, u.email,
            e.id AS election_id, e.title AS election_title,
            p.id AS position_id, p.name AS position_name,
            reviewer.first_name AS reviewer_first_name, reviewer.last_name AS reviewer_last_name
     FROM candidate_applications ca
     JOIN users u ON u.id = ca.user_id
     JOIN elections e ON e.id = ca.election_id
     JOIN positions p ON p.id = ca.position_id AND p.election_id = ca.election_id
     LEFT JOIN users reviewer ON reviewer.id = ca.reviewed_by
     ' . $where . '
     ORDER BY (ca.status = \'pending\') DESC, ca.created_at DESC, ca.id DESC
     LIMIT ? OFFSET ?'
);
$listTypes = $types . 'ii';
$listParameters = $parameters;
$listParameters[] = $perPage;
$listParameters[] = $offset;
bindCandidateApplicationFilters($applicationStmt, $listTypes, $listParameters);
$applicationStmt->execute();
$applications = $applicationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$applicationStmt->close();

$summary = $conn->query(
    "SELECT COUNT(*) AS total,
            SUM(status = 'pending') AS pending,
            SUM(status = 'approved') AS approved,
            SUM(status = 'rejected') AS rejected
     FROM candidate_applications"
)->fetch_assoc();
$elections = $conn->query('SELECT id, title FROM elections ORDER BY starts_at DESC, id DESC')->fetch_all(MYSQLI_ASSOC);
$positions = $conn->query(
    'SELECT p.id, p.name, e.title AS election_title
     FROM positions p JOIN elections e ON e.id = p.election_id
     ORDER BY e.starts_at DESC, p.sort_order, p.id'
)->fetch_all(MYSQLI_ASSOC);
$paginationQuery = $_GET;
unset($paginationQuery['page']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candidate Applications - Admin</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 22px; background: #f2f4fa; color: #283044; font-family: Arial, sans-serif; }
        main { max-width: 1250px; margin: auto; }
        section, .application-card { margin-bottom: 18px; padding: 20px; border-radius: 10px; background: #fff; box-shadow: 0 2px 10px rgba(20,30,60,.08); }
        .stats, .filters, .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; }
        .stat { padding: 16px; border-radius: 9px; background: #fff; box-shadow: 0 2px 10px rgba(20,30,60,.08); }
        .stat strong { display: block; color: #586de0; font-size: 1.6em; }
        .filters { align-items: end; }
        label { display: block; margin: 8px 0 5px; font-weight: 600; }
        input, select, textarea { width: 100%; padding: 10px; border: 1px solid #cbd1df; border-radius: 6px; font: inherit; }
        textarea { min-height: 78px; resize: vertical; }
        button, .button { display: inline-block; margin: 5px 5px 5px 0; padding: 10px 14px; border: 0; border-radius: 6px; background: #586de0; color: #fff; font-weight: 600; text-decoration: none; cursor: pointer; }
        button.danger { background: #c43b4b; }
        .muted { color: #697386; }
        .notice, .error { margin: 10px 0; padding: 12px; border-radius: 7px; }
        .notice { background: #e9f5ec; color: #236b35; }
        .error { background: #fff0f0; color: #942e2e; }
        .status { display: inline-block; padding: 5px 9px; border-radius: 20px; background: #fff5db; color: #815d13; font-size: 12px; font-weight: 700; text-transform: capitalize; }
        .status.approved { background: #e9f7ef; color: #21794b; }
        .status.rejected { background: #fff0ed; color: #a63e36; }
        .application-card header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; }
        .application-card h3 { margin: 0; }
        .application-card details { margin: 12px 0; }
        summary { color: #4d5fc8; cursor: pointer; font-weight: 650; }
        .application-form { max-width: 650px; margin-top: 10px; padding-top: 12px; border-top: 1px solid #e8eaf0; }
        .pagination { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 18px; }
        @media(max-width: 600px) { body { padding: 10px; } section, .application-card { padding: 15px; } }
    </style>
    <link rel="stylesheet" href="includes/navigation.css">
</head>
<body>
<?php renderAuthenticatedNavigation('Candidate Applications', 'Review student applications separately from official candidates.', 'applications'); ?>
<main>
    <?php if ($success): ?><p class="notice"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php foreach ($errors as $error): ?><p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endforeach; ?>

    <div class="stats">
        <div class="stat"><strong><?php echo (int) ($summary['total'] ?? 0); ?></strong>Total applications</div>
        <div class="stat"><strong><?php echo (int) ($summary['pending'] ?? 0); ?></strong>Pending</div>
        <div class="stat"><strong><?php echo (int) ($summary['approved'] ?? 0); ?></strong>Approved</div>
        <div class="stat"><strong><?php echo (int) ($summary['rejected'] ?? 0); ?></strong>Rejected</div>
    </div>

    <section>
        <h2>Filter applications</h2>
        <form method="GET" class="filters">
            <div><label for="q">Search student</label><input id="q" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Name, student ID, or email"></div>
            <div><label for="election_id">Election</label><select id="election_id" name="election_id"><option value="0">All elections</option><?php foreach ($elections as $option): ?><option value="<?php echo (int) $option['id']; ?>" <?php echo $electionId === (int) $option['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($option['title'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
            <div><label for="position_id">Position</label><select id="position_id" name="position_id"><option value="0">All positions</option><?php foreach ($positions as $option): ?><option value="<?php echo (int) $option['id']; ?>" <?php echo $positionId === (int) $option['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($option['election_title'] . ' — ' . $option['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
            <div><label for="status">Status</label><select id="status" name="status"><?php foreach (['all' => 'All statuses', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $status === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
            <div><label for="date_from">Applied from</label><input id="date_from" name="date_from" type="date" value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?>"></div>
            <div><label for="date_to">Applied through</label><input id="date_to" name="date_to" type="date" value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?>"></div>
            <div><button type="submit">Apply filters</button> <a class="button" href="admin_candidate_applications.php">Clear</a></div>
        </form>
    </section>

    <section>
        <h2>Applications (<?php echo $total; ?>)</h2>
        <?php if (!$applications): ?><p class="muted">No applications match these filters yet. Once a student applies, the application will appear here for review.</p><?php endif; ?>
        <?php foreach ($applications as $application): ?>
            <article class="application-card">
                <header>
                    <h3><?php echo htmlspecialchars(trim($application['first_name'] . ' ' . ($application['middle_name'] ?? '') . ' ' . $application['last_name']), ENT_QUOTES, 'UTF-8'); ?> <span class="muted">(<?php echo htmlspecialchars($application['student_id'], ENT_QUOTES, 'UTF-8'); ?>)</span></h3>
                    <span class="status <?php echo htmlspecialchars($application['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($application['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                </header>
                <p><strong><?php echo htmlspecialchars($application['position_name'], ENT_QUOTES, 'UTF-8'); ?></strong> · <?php echo htmlspecialchars($application['election_title'], ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="muted">Submitted <?php echo htmlspecialchars($application['created_at'], ENT_QUOTES, 'UTF-8'); ?> · <a href="mailto:<?php echo htmlspecialchars($application['email'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($application['email'], ENT_QUOTES, 'UTF-8'); ?></a></p>
                <details>
                    <summary>View application details</summary>
                    <p><strong>Statement of purpose</strong><br><?php echo nl2br(htmlspecialchars($application['statement_of_purpose'], ENT_QUOTES, 'UTF-8')); ?></p>
                    <p><strong>Experience and qualifications</strong><br><?php echo $application['qualifications'] ? nl2br(htmlspecialchars($application['qualifications'], ENT_QUOTES, 'UTF-8')) : 'Not provided'; ?></p>
                    <?php if ($application['admin_feedback']): ?><p><strong>Previous feedback</strong><br><?php echo nl2br(htmlspecialchars($application['admin_feedback'], ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
                    <?php if ($application['reviewed_at']): ?><p class="muted">Reviewed <?php echo htmlspecialchars($application['reviewed_at'], ENT_QUOTES, 'UTF-8'); ?><?php if ($application['reviewer_first_name']): ?> by <?php echo htmlspecialchars(trim($application['reviewer_first_name'] . ' ' . $application['reviewer_last_name']), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></p><?php endif; ?>
                </details>
                <?php if ($application['status'] === 'pending'): ?>
                    <form method="POST" class="application-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="application_id" value="<?php echo (int) $application['id']; ?>">
                        <label for="feedback-<?php echo (int) $application['id']; ?>">Feedback (required for rejection, optional for approval)</label>
                        <textarea id="feedback-<?php echo (int) $application['id']; ?>" name="admin_feedback" maxlength="10000"></textarea>
                        <button type="submit" name="decision" value="approve" onclick="return confirm('Approve this application and add the student as an official candidate?');">Approve application</button>
                        <button type="submit" name="decision" value="reject" class="danger" onclick="return this.form.admin_feedback.value.trim() !== '' && confirm('Reject this application? The feedback field is required.');">Reject application</button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if ($total > 0): ?>
            <nav class="pagination" aria-label="Application pages">
                <?php if ($page > 1): $paginationQuery['page'] = $page - 1; ?><a class="button" href="admin_candidate_applications.php?<?php echo htmlspecialchars(http_build_query($paginationQuery), ENT_QUOTES, 'UTF-8'); ?>">Previous</a><?php else: ?><span></span><?php endif; ?>
                <span>Page <?php echo $page; ?> of <?php echo $pages; ?></span>
                <?php if ($page < $pages): $paginationQuery['page'] = $page + 1; ?><a class="button" href="admin_candidate_applications.php?<?php echo htmlspecialchars(http_build_query($paginationQuery), ENT_QUOTES, 'UTF-8'); ?>">Next</a><?php else: ?><span></span><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>
<?php renderAuthenticatedNavigationEnd(); ?>
</body>
</html>
