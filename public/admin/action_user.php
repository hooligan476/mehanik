<?php
// htdocs/mehanik/public/admin/action_user.php
session_start();

// Проверка доступа: только админ
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'admin') {
    header('Location: /mehanik/public/login.php');
    exit;
}

// DB настройки (лучше вынести в отдельный файл)
$dbHost = '127.0.0.1';
$dbName = 'mehanik';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("DB error: " . $e->getMessage());
}

// Базовый путь для public
$basePublic = '/mehanik/public';

// Обработка POST-запроса
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;
    $action = $_POST['action'] ?? '';

    if (!$id) {
        header("Location: {$basePublic}/admin/users.php?err=Нет ID");
        exit;
    }

    if ($action === 'approve') {
        $stmt = $pdo->prepare("UPDATE users SET status = 'approved' WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: {$basePublic}/admin/users.php?msg=Пользователь подтверждён");
        exit;
    }

    if ($action === 'reject') {
        // УДАЛЕНИЕ пользователя
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: {$basePublic}/admin/users.php?msg=Пользователь удалён");
        exit;
    }

    if ($action === 'set_pending') {
        $stmt = $pdo->prepare("UPDATE users SET status = 'pending' WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: {$basePublic}/admin/users.php?msg=Статус изменён на pending");
        exit;
    }

    // если действие неизвестно
    header("Location: {$basePublic}/admin/users.php?err=Неизвестное действие");
    exit;
}
