<?php
require_once __DIR__.'/../../middleware.php';
require_admin();
$config = require __DIR__.'/../../config.php';
?>

<header class="topbar">
  <div class="brand">Админка</div>
  <nav>
    <a href="<?= $config['base_url'] ?>/admin/index.php">Дашборд</a>
    <a href="<?= $config['base_url'] ?>/admin/users.php">Пользователи</a>
    <a href="<?= $config['base_url'] ?>/admin/products.php">Товары</a>
    <a href="<?= $config['base_url'] ?>/admin/cars.php">Бренды/Модели</a>
    <a href="<?= $config['base_url'] ?>/admin/chats.php">Чаты</a>
    <a href="<?= $config['base_url'] ?>/logout.php">Выйти</a>
  </nav>
</header>
