<?php
session_start();

// --- Настройка логов и отключение вывода ошибок на экран
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_error.log');

// --- Подключение БД
require_once __DIR__.'/../db.php';

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$pass = $_POST['password'] ?? '';

header('Content-Type: application/json; charset=utf-8');

if (!$name || !$email || strlen($pass) < 6) {
    echo json_encode(['ok'=>false,'error'=>'Заполните все поля (пароль ≥ 6)']);
    exit;
}

// Проверка существующего email
$stmt = $mysqli->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();
if($stmt->num_rows > 0){
    echo json_encode(['ok'=>false,'error'=>'Email уже используется']);
    exit;
}
$stmt->close();

// Хеш пароля
$hash = password_hash($pass, PASSWORD_BCRYPT);

// Генерация кода подтверждения
$code = rand(100000,999999);

// Вставка пользователя в БД
$stmt = $mysqli->prepare("INSERT INTO users(name,email,password_hash,verify_code,status) VALUES(?,?,?,?,0)");
$stmt->bind_param('sssi', $name, $email, $hash, $code);

if($stmt->execute()){
    $_SESSION['pending_email'] = $email;

    // Попытка отправки письма (если PHPMailer подключен)
    if(function_exists('sendVerificationMail')){
        $mailResult = sendVerificationMail($email, $name, $code);
        if($mailResult !== true){
            error_log("Mail send error: ".$mailResult);
        }
    }

    echo json_encode(['ok'=>true]);
} else {
    echo json_encode(['ok'=>false,'error'=>'Ошибка при регистрации']);
}
exit;
