<?php
require_once 'auth.php';
startAppSession();
require_once 'db.php';
requireAdmin();

$actorId = (int) $_SESSION['user_id'];
$auditStmt = $conn->prepare(
    "INSERT INTO admin_audit_log (actor_user_id, action, entity_type, entity_id)
     VALUES (?, 'admin_logout', 'user', ?)"
);
$auditStmt->bind_param('ii', $actorId, $actorId);
$auditStmt->execute();
$auditStmt->close();

destroyAppSession();
header('Location: login.php');
exit();
?>
