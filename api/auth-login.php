<?php
session_start();
require_once __DIR__.'/../db.php';

$email = trim($_POST['email'] ?? '');
$pass  = $_POST['password'] ?? '';

header('Content-Type: application/json; charset=utf-8');

if(!$email || !$pass){
    echo json_encode(['ok'=>false,'error'=>'Заполните все поля']);
    exit;
}

$stmt = $mysqli->prepare(
    'SELECT id,name,email,password_hash,role,status FROM users WHERE email=? LIMIT 1'
);
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if ($user && password_verify($pass, $user['password_hash'])) {
    if ($user['status'] == 0) {
        echo json_encode(['ok'=>false,'error'=>'Аккаунт не подтверждён']);
        exit;
    }

    $_SESSION['user'] = [
        'id'=>$user['id'],
        'name'=>$user['name'],
        'email'=>$user['email'],
        'role'=>$user['role']
    ];
    echo json_encode(['ok'=>true]);
} else {
    echo json_encode(['ok'=>false,'error'=>'Неверный email или пароль']);
}
exit;
