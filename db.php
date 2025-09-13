<?php
// db.php — создаёт $mysqli и $conn для совместимости
$config = require __DIR__ . '/config.php';

// Создаём соединение с MySQL
$mysqli = new mysqli(
    $config['db']['host'],
    $config['db']['user'],
    $config['db']['pass'],
    $config['db']['name'],
    $config['db']['port'] ?? 3306
);

// Проверка подключения
if ($mysqli->connect_errno) {
    die('DB connection failed: ' . $mysqli->connect_error);
}

// Устанавливаем кодировку
$mysqli->set_charset($config['db']['charset'] ?? 'utf8mb4');

// Для совместимости с register.php
$conn = $mysqli;

