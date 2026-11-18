<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$token_user = $_SESSION['user_token'] ?? null;
if (!is_string($token_user) || $token_user === '') {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/configuration.php';
$stmt = $conn->prepare('SELECT id, balance, email, name, token, mobile, image FROM users WHERE token = ? AND status = 1 LIMIT 1');
$stmt->bind_param('s', $token_user);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    unset($_SESSION['user_token'], $_SESSION['user_id']);
    header('Location: index.php');
    exit;
}

$id = $row['id'];
$balance = $row['balance'];
$email = $row['email'];
$name = $row['name'];
$token = $row['token'];
$phone = $row['mobile'];
$user_image = $row['image'] ?: 'assets/images/faces/face8.jpg';
