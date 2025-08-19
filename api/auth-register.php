<?php
require_once __DIR__.'/../db.php';
require_once __DIR__.'/../middleware.php';

header('Content-Type: application/json; charset=utf-8');

// Получаем данные формы
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$pass = $_POST['password'] ?? '';

// Проверка на заполненность
if (!$name || !$email || strlen($pass) < 6) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'Заполните поля (пароль ≥ 6)']);
    exit;
}

// Генерация хэша пароля
$hash = password_hash($pass, PASSWORD_BCRYPT);

// Проверка успешности генерации хэша
if ($hash === false) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Не удалось сгенерировать хэш пароля']);
    exit;
}

// Подготовка запроса к базе
$stmt = $mysqli->prepare('INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)');
$role = 'user';
$stmt->bind_param('ssss', $name, $email, $hash, $role);

// Выполнение запроса
if ($stmt->execute()) {
    $_SESSION['user'] = [
        'id' => $stmt->insert_id,
        'name' => $name,
        'email' => $email,
        'role' => $role
    ];
    echo json_encode(['ok'=>true]);
} else {
    http_response_code(409);
    echo json_encode(['ok'=>false,'error'=>'Email уже используется']);
}
