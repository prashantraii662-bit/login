<?php
function renderAuthenticatedNavigation($pageTitle, $description, $activeItem) {
    $isAdmin = ($_SESSION['role'] ?? '') === 'admin';
    $userName = (string) ($_SESSION['user_name'] ?? 'User');
    $initials = '';
    foreach (preg_split('/\s+/', trim($userName)) as $namePart) {
        if ($namePart !== '') {
            $initials .= strtoupper(substr($namePart, 0, 1));
        }
    }
    $initials = substr($initials, 0, 2);
    $logoutUrl = $isAdmin ? 'admin_logout.php' : 'logout.php';
    $notificationsUrl = $isAdmin
        ? 'admin.php?nav=notifications#admin-notifications'
        : 'dashboard.php?nav=notifications#notifications';
    $stmt = $GLOBALS['conn']->prepare('SELECT COUNT(*) AS unread_count FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $userId = (int) $_SESSION['user_id'];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $unreadCount = (int) $stmt->get_result()->fetch_assoc()['unread_count'];
    $stmt->close();

    if ($isAdmin) {
        $navigationItems = [
            ['group' => 'ADMIN PANEL', 'key' => 'admin-dashboard', 'label' => 'Dashboard', 'icon' => '▦', 'url' => 'admin.php'],
            ['group' => 'ADMIN PANEL', 'key' => 'elections', 'label' => 'Elections', 'icon' => '◷', 'url' => 'admin.php?nav=elections#elections'],
            ['group' => 'ADMIN PANEL', 'key' => 'positions', 'label' => 'Positions', 'icon' => '▤', 'url' => 'admin.php?nav=positions#positions'],
            ['group' => 'ADMIN PANEL', 'key' => 'applications', 'label' => 'Applications', 'icon' => '▣', 'url' => 'admin_candidate_applications.php'],
            ['group' => 'ADMIN PANEL', 'key' => 'candidates', 'label' => 'Candidates', 'icon' => '◇', 'url' => 'admin.php?nav=candidates#candidates'],
            ['group' => 'ADMIN PANEL', 'key' => 'students', 'label' => 'Students', 'icon' => '♙', 'url' => 'admin.php?nav=students#student-management'],
            ['group' => 'ADMIN PANEL', 'key' => 'voting', 'label' => 'Voting', 'icon' => '✓', 'url' => 'admin.php?nav=voting#voting'],
            ['group' => 'ADMIN PANEL', 'key' => 'results', 'label' => 'Results', 'icon' => '▤', 'url' => 'results.php'],
            ['group' => 'System', 'key' => 'notifications', 'label' => 'Notifications', 'icon' => '♧', 'url' => $notificationsUrl],
            ['group' => 'System', 'key' => 'audit', 'label' => 'Audit logs', 'icon' => '≡', 'url' => 'admin.php?nav=audit#audit-logs'],
            ['group' => 'System', 'key' => 'settings', 'label' => 'Settings', 'icon' => '⚙', 'url' => 'admin.php?nav=settings#settings']
        ];
    } else {
        $navigationItems = [
            ['group' => 'Main', 'key' => 'dashboard', 'label' => 'Dashboard', 'icon' => '▦', 'url' => 'dashboard.php'],
            ['group' => 'Elections', 'key' => 'elections', 'label' => 'Elections', 'icon' => '◷', 'url' => 'dashboard.php?nav=elections#elections'],
            ['group' => 'Elections', 'key' => 'candidate-applications', 'label' => 'Applications', 'icon' => '▣', 'url' => 'candidate_applications.php'],
            ['group' => 'Elections', 'key' => 'candidates', 'label' => 'Candidates', 'icon' => '◇', 'url' => 'dashboard.php?nav=candidates#elections'],
            ['group' => 'Elections', 'key' => 'vote', 'label' => 'Vote', 'icon' => '✓', 'url' => 'dashboard.php?nav=vote#elections'],
            ['group' => 'Account', 'key' => 'results', 'label' => 'Results', 'icon' => '▤', 'url' => 'results.php'],
            ['group' => 'Account', 'key' => 'profile', 'label' => 'Profile', 'icon' => '♙', 'url' => 'dashboard.php?nav=profile#profile'],
            ['group' => 'Account', 'key' => 'notifications', 'label' => 'Notifications', 'icon' => '♧', 'url' => 'dashboard.php?nav=notifications#notifications']
        ];
    }

    echo '<div class="app-backdrop" data-nav-close></div>';
    echo '<aside class="app-sidebar' . ($isAdmin ? ' is-admin-sidebar' : '') . '" id="app-sidebar" aria-label="Main navigation">';
    echo '<a class="app-brand" href="' . ($isAdmin ? 'admin.php' : 'dashboard.php') . '"><span class="app-brand-mark" aria-hidden="true">V</span><span class="app-brand-copy"><strong>CampusVote</strong><small>College elections</small></span></a>';
    echo '<nav class="app-nav">';
    $currentGroup = '';
    foreach ($navigationItems as $item) {
        if ($item['group'] !== $currentGroup) {
            $currentGroup = $item['group'];
            echo '<div class="app-nav-label">' . htmlspecialchars($currentGroup, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        $isActive = $activeItem === $item['key'];
        echo '<a class="app-nav-link' . ($isActive ? ' is-active' : '') . '" href="' . htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') . '"' . ($isActive ? ' aria-current="page"' : '') . '>';
        echo '<span class="app-nav-icon" aria-hidden="true">' . htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') . '</span>';
        echo '<span>' . htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') . '</span></a>';
    }
    echo '</nav><div class="app-sidebar-bottom"><a class="app-nav-link app-logout" href="' . htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8') . '"><span class="app-nav-icon" aria-hidden="true">↪</span><span>Logout</span></a></div></aside>';

    echo '<div class="app-shell"><div class="app-topbar">';
    echo '<button class="app-menu-toggle" type="button" aria-label="Collapse navigation sidebar" aria-controls="app-sidebar" aria-expanded="true" data-nav-toggle><span></span><span></span><span></span></button>';
    echo '<div class="app-heading"><h1>' . htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . '</h1><p>' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p></div>';
    echo '<a class="app-notification-link" href="' . htmlspecialchars($notificationsUrl, ENT_QUOTES, 'UTF-8') . '" aria-label="Notifications, ' . $unreadCount . ' unread"><span aria-hidden="true">♧</span>';
    if ($unreadCount > 0) {
        echo '<span class="app-notification-count">' . $unreadCount . '</span>';
    }
    echo '</a>';
    echo '<div class="app-user"><span class="app-avatar" aria-hidden="true">' . htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') . '</span><span class="app-user-copy"><strong>' . htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') . '</strong><small>' . ($isAdmin ? 'Administrator' : 'Student') . '</small></span><a class="app-user-logout" href="' . htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8') . '">Logout</a></div>';
    echo '</div><div class="app-page-content">';
}

function renderAuthenticatedNavigationEnd() {
    echo '</div></div><script src="includes/navigation.js" defer></script>';
}
