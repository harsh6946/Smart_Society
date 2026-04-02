<?php
session_start();

if (isset($_SESSION['admin_id'])) {
    require_once __DIR__ . '/../include/dbconfig.php';
    $logStmt = $conn->prepare("INSERT INTO tbl_audit_log (admin_id, action, entity_type, entity_id, ip_address) VALUES (?, 'logout', 'admin', ?, ?)");
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $logStmt->bind_param('iis', $_SESSION['admin_id'], $_SESSION['admin_id'], $ip);
    $logStmt->execute();
    $logStmt->close();
    $conn->close();
}

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

header('Location: index');
exit;
