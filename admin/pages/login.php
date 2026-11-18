<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$email = $_POST['email'] ?? null;
$password = $_POST['password'] ?? null;
$token = $_POST['token'] ?? null;
if (!is_string($email) || !is_string($password) || !is_string($token)
    || $email === '' || $password === '' || $token === '') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid admin credentials']);
    exit;
}

require_once __DIR__ . '/../includes/configuration.php';
$stmt = $conn->prepare('SELECT email, token, status FROM admin_user WHERE email = ? AND password = ? AND token = ? LIMIT 1');
$passwordHash = md5($password); // Retain legacy compatibility until the admin passwords are reset or migrated.
$stmt->bind_param('sss', $email, $passwordHash, $token);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin || (int) $admin['status'] !== 1) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid admin credentials']);
    exit;
}

session_regenerate_id(true);
$_SESSION['admin_token'] = $admin['token'];
$_SESSION['admin_id'] = base64_encode($admin['email']);
echo json_encode(['status' => 'success', 'message' => 'Login successful']);
