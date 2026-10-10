<?php
require_once 'auth.php';
startAppSession();
require_once 'db.php';
requireStudent();
require_once 'includes/navigation.php';

$userId = (int) $_SESSION['user_id'];
$stmt = $conn->prepare('SELECT first_name, middle_name, last_name, student_id, department, email, password FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$profile) {
    destroyAppSession();
    header('Location: login.php');
    exit();
}

$dashboardErrors = [];
$dashboardSuccess = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Your session expired. Refresh the page and try again.');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_notification_read') {
        $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$notificationId) {
            http_response_code(400);
            exit('Invalid notification.');
        }
        $stmt = $conn->prepare('UPDATE notifications SET read_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND read_at IS NULL');
        $stmt->bind_param('ii', $notificationId, $userId);
        $stmt->execute();
        $stmt->close();
        header('Location: dashboard.php');
        exit();
    } elseif ($action === 'update_profile') {
        $firstName = trim(requestString($_POST, 'first_name'));
        $middleName = trim(requestString($_POST, 'middle_name'));
        $lastName = trim(requestString($_POST, 'last_name'));
        $email = trim(requestString($_POST, 'email'));
        $department = trim(requestString($_POST, 'department'));
        $departments = ['Computer Engineering', 'Civil Engineering', 'Electrical Engineering', 'Mechanical Engineering'];
        if ($firstName === '' || strlen($firstName) > 100
            || strlen($middleName) > 100 || $lastName === '' || strlen($lastName) > 100
            || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150
            || !in_array($department, $departments, true)) {
            $dashboardErrors[] = 'Enter a valid name, email address, and department.';
        } else {
            try {
                $stmt = $conn->prepare('UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, email = ?, department = ? WHERE id = ? AND role = \'student\'');
                $stmt->bind_param('sssssi', $firstName, $middleName, $lastName, $email, $department, $userId);
                $stmt->execute();
                $stmt->close();
                $_SESSION['user_name'] = trim($firstName . ' ' . $lastName);
                $dashboardSuccess = 'Profile updated successfully.';
            } catch (mysqli_sql_exception $exception) {
                if ($exception->getCode() === 1062) {
                    $dashboardErrors[] = 'That email address is already in use.';
                } else {
                    error_log('Student profile update failed: ' . $exception->getMessage());
                    $dashboardErrors[] = 'Your profile could not be updated. Please try again.';
                }
            }
        }
    } elseif ($action === 'change_password') {
        $currentPassword = requestString($_POST, 'current_password');
        $newPassword = requestString($_POST, 'new_password');
        $confirmPassword = requestString($_POST, 'confirm_password');
        if (!password_verify($currentPassword, $profile['password'])) {
            $dashboardErrors[] = 'Your current password is incorrect.';
        } elseif (strlen($newPassword) < 8) {
            $dashboardErrors[] = 'Your new password must be at least 8 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $dashboardErrors[] = 'The new passwords do not match.';
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ? AND role = \'student\'');
            $stmt->bind_param('si', $passwordHash, $userId);
            $stmt->execute();
            $stmt->close();
            session_regenerate_id(true);
            $dashboardSuccess = 'Password changed successfully.';
        }
    } else {
        http_response_code(400);
        exit('Invalid profile action.');
    }

    $stmt = $conn->prepare('SELECT first_name, middle_name, last_name, student_id, department, email, password FROM users WHERE id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$search = trim(requestString($_GET, 'q'));
$searchLike = '%' . $search . '%';
$stmt = $conn->prepare(
    'SELECT e.id, e.title, e.description, e.starts_at, e.ends_at, e.status, e.results_published,
        EXISTS(SELECT 1 FROM election_participation ep WHERE ep.election_id = e.id AND ep.user_id = ?) AS has_voted,
        (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.id AND c.status = \'approved\') AS candidate_count
     FROM elections e
     WHERE (? = \'\' OR e.title LIKE ? OR e.description LIKE ?)
     ORDER BY e.starts_at DESC, e.id DESC'
);
$stmt->bind_param('isss', $userId, $search, $searchLike, $searchLike);
$stmt->execute();
$elections = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$activeElections = [];
$upcomingElections = [];
$completedElections = [];
foreach ($elections as $election) {
    $startsAt = strtotime($election['starts_at']);
    $endsAt = strtotime($election['ends_at']);
    if ($election['status'] === 'active' && $startsAt <= time() && $endsAt >= time()) {
        $activeElections[] = $election;
    } elseif ($election['status'] === 'scheduled' && $startsAt > time()) {
        $upcomingElections[] = $election;
    } elseif ($election['status'] === 'closed' || (in_array($election['status'], ['scheduled', 'active'], true) && $endsAt < time())) {
        $completedElections[] = $election;
    }
}

$stmt = $conn->prepare(
    'SELECT id, election_id, title, message, created_at, read_at
     FROM notifications WHERE user_id = ? ORDER BY read_at IS NULL DESC, created_at DESC LIMIT 20'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - College Voting System</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; background: linear-gradient(135deg,#667eea,#764ba2); color: #303849; font-family: 'Segoe UI',Arial,sans-serif; }
        main { max-width: 1050px; margin: auto; }
        header, section { background: #fff; border-radius: 12px; padding: 22px; margin-bottom: 18px; box-shadow: 0 4px 16px rgba(0,0,0,.12); }
        header { display: flex; justify-content: space-between; align-items: center; }
        h1,h2,h3 { margin-top: 0; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(260px,1fr)); gap: 14px; }
        .card { border: 1px solid #e1e5ef; border-radius: 9px; padding: 16px; }
        .muted { color: #687386; }
        label { display: block; margin-bottom: 5px; }
        input, select { width: 100%; padding: 10px; border: 1px solid #cbd1df; border-radius: 6px; font: inherit; }
        button,.button { display: inline-block; padding: 10px 14px; border: 0; border-radius: 6px; background: #586de0; color: white; text-decoration: none; cursor: pointer; }
        .notice { padding: 12px; border-left: 4px solid #667eea; background: #f2f4ff; margin: 10px 0; }
        .error { padding: 12px; border-left: 4px solid #b42318; background: #fff0f0; margin: 10px 0; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap: 14px; }
        @media(max-width:600px) { body { padding: 10px; } header { align-items: flex-start; flex-direction: column; gap: 10px; } }
    </style>
    <link rel="stylesheet" href="includes/navigation.css">
</head>
<body>
<?php
$activeNavigation = requestString($_GET, 'nav');
if (!in_array($activeNavigation, ['dashboard', 'elections', 'candidates', 'vote', 'notifications', 'profile'], true)) {
    $activeNavigation = 'dashboard';
}
$navigationTitles = [
    'dashboard' => ['Student Dashboard', 'Your elections, participation, and account at a glance.'],
    'elections' => ['Elections', 'Browse upcoming, active, and completed college elections.'],
    'candidates' => ['Candidates', 'Review candidates and manifestos before making your choice.'],
    'vote' => ['Cast Vote', 'Select your choices for an active election.'],
    'notifications' => ['Notifications', 'Election updates and important account notices.'],
    'profile' => ['Profile', 'Manage your student details and password.']
];
renderAuthenticatedNavigation($navigationTitles[$activeNavigation][0], $navigationTitles[$activeNavigation][1], $activeNavigation);
?>
<main>
    <?php if ($dashboardSuccess): ?><div class="notice"><?php echo htmlspecialchars($dashboardSuccess, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php foreach ($dashboardErrors as $dashboardError): ?><div class="error"><?php echo htmlspecialchars($dashboardError, ENT_QUOTES, 'UTF-8'); ?></div><?php endforeach; ?>
    <section id="profile">
        <h2>My profile</h2>
        <p><strong>Student ID:</strong> <?php echo htmlspecialchars($profile['student_id'], ENT_QUOTES, 'UTF-8'); ?></p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="update_profile">
            <div class="form-grid">
                <div><label for="first_name">First name</label><input id="first_name" name="first_name" maxlength="50" required value="<?php echo htmlspecialchars($profile['first_name'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div><label for="middle_name">Middle name</label><input id="middle_name" name="middle_name" maxlength="50" value="<?php echo htmlspecialchars($profile['middle_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div><label for="last_name">Last name</label><input id="last_name" name="last_name" maxlength="50" required value="<?php echo htmlspecialchars($profile['last_name'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div><label for="email">Email</label><input id="email" name="email" type="email" maxlength="100" required value="<?php echo htmlspecialchars($profile['email'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div><label for="department">Department</label><select id="department" name="department" required>
                    <?php foreach (['Computer Engineering', 'Civil Engineering', 'Electrical Engineering', 'Mechanical Engineering'] as $department): ?>
                        <option value="<?php echo htmlspecialchars($department, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $profile['department'] === $department ? 'selected' : ''; ?>><?php echo htmlspecialchars($department, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select></div>
            </div>
            <button type="submit">Save profile</button>
        </form>
    </section>

    <section>
        <h2>Change password</h2>
        <form method="POST" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="change_password">
            <div><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
            <div><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" minlength="8" autocomplete="new-password" required></div>
            <div><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required></div>
            <div style="align-self:end"><button type="submit">Change password</button></div>
        </form>
    </section>

    <section id="notifications">
        <h2>Election notifications</h2>
        <?php if (!$notifications): ?><p class="muted">No notifications yet.</p><?php endif; ?>
        <?php foreach ($notifications as $notification): ?>
            <article class="notice">
                <strong><?php echo htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <p><?php echo nl2br(htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8')); ?></p>
                <small class="muted"><?php echo htmlspecialchars($notification['created_at'], ENT_QUOTES, 'UTF-8'); ?></small>
                <?php if (!$notification['read_at']): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="mark_notification_read">
                        <input type="hidden" name="notification_id" value="<?php echo (int) $notification['id']; ?>">
                        <button type="submit">Mark as read</button>
                    </form>
                <?php endif; ?>
                <?php if ($notification['election_id']): ?><a href="candidates.php?election_id=<?php echo (int) $notification['election_id']; ?>">View election</a><?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>

    <section>
        <form method="GET">
            <label for="q"><strong>Search elections</strong></label>
            <input id="q" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Title or description">
            <button type="submit">Search</button>
        </form>
    </section>

    <?php
    $sections = [
        'Active elections' => $activeElections,
        'Upcoming elections' => $upcomingElections,
        'Completed elections' => $completedElections
    ];
    foreach ($sections as $heading => $items):
    ?>
        <section<?php echo $heading === 'Active elections' ? ' id="elections"' : ''; ?>>
            <h2><?php echo htmlspecialchars($heading, ENT_QUOTES, 'UTF-8'); ?></h2>
            <?php if (!$items): ?><p class="muted">No elections in this category.</p><?php endif; ?>
            <div class="grid">
                <?php foreach ($items as $election): ?>
                    <article class="card">
                        <h3><?php echo htmlspecialchars($election['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p><?php echo nl2br(htmlspecialchars($election['description'] ?? '', ENT_QUOTES, 'UTF-8')); ?></p>
                        <p class="muted">Opens: <?php echo htmlspecialchars($election['starts_at'], ENT_QUOTES, 'UTF-8'); ?><br>Closes: <?php echo htmlspecialchars($election['ends_at'], ENT_QUOTES, 'UTF-8'); ?><br>Status: <?php echo htmlspecialchars($election['status'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php if (in_array($election['status'], ['scheduled', 'active'], true) || $election['results_published']): ?><a class="button" href="candidates.php?election_id=<?php echo (int) $election['id']; ?>">View candidates</a><?php endif; ?>
                        <?php if ($heading === 'Active elections' && !$election['has_voted']): ?><a class="button" href="vote.php?election_id=<?php echo (int) $election['id']; ?>">Vote</a><?php endif; ?>
                        <?php if ($election['has_voted']): ?><p class="muted">Ballot submitted for this election.</p><?php endif; ?>
                        <?php if ($election['results_published']): ?><a class="button" href="results.php?election_id=<?php echo (int) $election['id']; ?>">Published results</a><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</main>
<?php renderAuthenticatedNavigationEnd(); ?>
</body>
</html>
