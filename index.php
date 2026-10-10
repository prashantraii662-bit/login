<?php
require_once 'auth.php';
startAppSession();

if (!empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin') {
    header('Location: admin.php');
} elseif (!empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'student') {
    header('Location: dashboard.php');
} else {
    header('Location: index.html');
}
exit();
