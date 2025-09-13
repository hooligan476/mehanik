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

// Берём session_version (если колонка есть), иначе вернёт NULL — далее приведём к 0
$stmt = $mysqli->prepare(
    'SELECT id,name,email,password_hash,role,status, COALESCE(session_version, 0) AS session_version FROM users WHERE email=? LIMIT 1'
);
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if ($user && password_verify($pass, $user['password_hash'])) {
    if ($user['status'] == 0) {
        echo json_encode(['ok'=>false,'error'=>'Аккаунт не подтверждён']);
        exit;
    }

    // безопасность: регенерируем id сессии после логина
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        // session_version гарантированно int
        'session_version' => isset($user['session_version']) ? (int)$user['session_version'] : 0
    ];

    echo json_encode(['ok'=>true]);
} else {
    echo json_encode(['ok'=>false,'error'=>'Неверный email или пароль']);
}
exit;
