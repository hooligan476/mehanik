<?php
require_once __DIR__.'/../db.php';
require_once __DIR__.'/../middleware.php';

header('Content-Type: application/json; charset=utf-8');

$email = $_POST['email'] ?? '';
$pass  = $_POST['password'] ?? '';

$stmt = $mysqli->prepare('SELECT id,name,email,password_hash,role FROM users WHERE email=? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if ($user && password_verify($pass, $user['password_hash'])) {
    $_SESSION['user'] = [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role']
    ];
    echo json_encode(['ok' => true]);
} else {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Неверный email или пароль']);
}
