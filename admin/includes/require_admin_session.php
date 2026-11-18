<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$adminSessionToken = $_SESSION['admin_token'] ?? null;
if (!is_string($adminSessionToken) || $adminSessionToken === '') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/configuration.php';
$adminSessionCheck = $conn->prepare('SELECT id FROM admin_user WHERE token = ? AND status = 1 LIMIT 1');
$adminSessionCheck->bind_param('s', $adminSessionToken);
$adminSessionCheck->execute();
$hasAdminSession = (bool) $adminSessionCheck->get_result()->fetch_assoc();
$adminSessionCheck->close();
unset($adminSessionToken, $adminSessionCheck);

if (!$hasAdminSession) {
    unset($_SESSION['admin_token'], $_SESSION['admin_id']);
    http_response_code(403);
    exit('Forbidden');
}
unset($hasAdminSession);
