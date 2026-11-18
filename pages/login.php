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
if (!is_string($email) || !is_string($password) || trim($email) === '' || $password === '') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email or password']);
    exit;
}
$email = strtolower(trim($email));

require_once __DIR__ . '/../includes/configuration.php';
$stmt = $conn->prepare('SELECT email, token, password, status FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$hash = (string) ($user['password'] ?? '');
// Existing accounts can still have MD5 hashes; migrate them before public launch.
$valid = password_verify($password, $hash)
    || (strlen($hash) === 32 && hash_equals($hash, md5($password)));
if (!$user || !$valid) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email or password']);
    exit;
}

if ((int) $user['status'] !== 1) {
    echo json_encode(['status' => 'error', 'message' => 'Your account is not active']);
    exit;
}

session_regenerate_id(true);
$_SESSION['user_token'] = $user['token'];
$_SESSION['user_id'] = base64_encode($user['email']);
echo json_encode(['status' => 'success', 'message' => 'Login successful']);
