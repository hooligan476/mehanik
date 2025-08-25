<?php
// public/header.php

// Если сессия ещё не запущена — запускаем
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Проектный корень — папка выше public (например C:\xampp\htdocs\mehanik)
$projectRoot = dirname(__DIR__);

// Попытка подключить config.php и db.php из корня проекта
$configPath = $projectRoot . '/config.php';
$dbPath = $projectRoot . '/db.php';

if (file_exists($configPath)) {
    $config = require $configPath;
} else {
    // fallback
    $config = ['base_url' => '/mehanik'];
}

// Подключаем db.php если он есть. В твоём проекте db.php должен создать $pdo.
if (file_exists($dbPath)) {
    require_once $dbPath;
}

// Если в сессии есть id пользователя и есть $pdo — подтягиваем свежие данные из БД
if (!empty($_SESSION['user']['id']) && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT id, name, phone, role, created_at, verify_code, status FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => (int)$_SESSION['user']['id']]);
        $fresh = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fresh) {
            $_SESSION['user'] = $fresh;
        }
    } catch (Exception $e) {
        // silently ignore DB errors for the header
    }
}

// Админский номер для приёма SMS — поменяй если нужно
if (!defined('ADMIN_PHONE_FOR_VERIFY')) {
    define('ADMIN_PHONE_FOR_VERIFY', '+99363722023');
}

$user = $_SESSION['user'] ?? null;
$base = rtrim($config['base_url'] ?? '/mehanik', '/');
?>
<header class="topbar">
  <div class="brand">Mehanik</div>

  <?php if (!empty($user) && ($user['status'] ?? '') === 'active'): ?>
    <div class="user-info">Вы вошли как: <b><?= htmlspecialchars($user['name']) ?></b></div>
  <?php elseif (!empty($user) && ($user['status'] ?? '') === 'pending'): ?>
    <div class="user-info"><b>ОЖИДАНИЕ ПОДТВЕРЖДЕНИЯ</b><br>
      Для подтверждения отправьте с номера <strong><?= htmlspecialchars($user['phone']) ?></strong>
      код <strong><?= htmlspecialchars($user['verify_code']) ?></strong> на <strong><?= ADMIN_PHONE_FOR_VERIFY ?></strong>
    </div>
  <?php elseif (!empty($user) && ($user['status'] ?? '') === 'rejected'): ?>
    <div class="user-info"><b>Код подтверждения неверен.</b></div>
  <?php endif; ?>

  <nav>
    <?php if (!empty($user)): ?>
      <a href="<?= $base ?>/add-product.php">Добавить товар</a>
      <a href="<?= $base ?>/my-products.php">Мои товары</a>
      <a href="<?= $base ?>/chat.php">Поддержка</a>
      <?php if (($user['role'] ?? '') === 'admin'): ?>
        <a href="<?= $base ?>/admin/index.php">Админка</a>
      <?php endif; ?>
      <a href="<?= $base ?>/logout.php">Выйти</a>
    <?php else: ?>
      <a href="<?= $base ?>/login.php">Войти</a>
      <a href="<?= $base ?>/register.php">Регистрация</a>
    <?php endif; ?>
  </nav>
</header>
