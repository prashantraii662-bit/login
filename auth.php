<?php
function startAppSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    $cookie = session_get_cookie_params();
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

function requestString($source, $key) {
    $value = $source[$key] ?? '';
    return is_string($value) ? $value : '';
}

function requireStudent() {
    startAppSession();
    if (($_SESSION['role'] ?? '') !== 'student' || empty($_SESSION['user_id']) || !userRoleIsCurrent('student')) {
        destroyAppSession();
        header('Location: login.php');
        exit();
    }
}

function requireAdmin() {
    startAppSession();
    if (($_SESSION['role'] ?? '') !== 'admin' || empty($_SESSION['user_id']) || !userRoleIsCurrent('admin')) {
        destroyAppSession();
        header('Location: admin_login.php');
        exit();
    }
}

function requireAuthenticatedUser() {
    startAppSession();
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, ['student', 'admin'], true) || empty($_SESSION['user_id']) || !userRoleIsCurrent($role)) {
        destroyAppSession();
        header('Location: login.php');
        exit();
    }
}

function userRoleIsCurrent($role) {
    global $conn;

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection is required for authorization.');
    }

    $stmt = $conn->prepare('SELECT role, is_verified, is_active FROM users WHERE id = ?');
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $user
        && $user['role'] === $role
        && (int) $user['is_verified'] === 1
        && (int) $user['is_active'] === 1
        && (int) ($_SESSION['is_verified'] ?? 0) === 1;
}

function establishUserSession($user, $role) {
    startAppSession();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['student_id'] = $user['student_id'];
    $_SESSION['user_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
    $_SESSION['role'] = $role;
    $_SESSION['is_verified'] = (int) ($user['is_verified'] ?? 0);
}

function csrfToken() {
    startAppSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    startAppSession();
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function destroyAppSession() {
    startAppSession();
    $_SESSION = [];
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => 'Lax'
    ]);
    session_destroy();
}
