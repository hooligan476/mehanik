<?php
session_start();
require_once __DIR__.'/../middleware.php';
require_once __DIR__.'/../db.php';
$config = require __DIR__.'/../config.php';
?>

<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mehanik — Каталог</title>
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
</head>
<body>
<header class="topbar">
  <div class="brand">Mehanik</div>

  <?php if (!empty($_SESSION['user'])): ?>
    <div class="user-info">Вы вошли как: <b><?= htmlspecialchars($_SESSION['user']['name']) ?></b></div>
  <?php endif; ?>

  <nav>
    <?php if (!empty($_SESSION['user'])): ?>
      <a href="<?= $config['base_url'] ?>/add-product.php">Добавить товар</a>
      <a href="<?= $config['base_url'] ?>/my-products.php">Мои товары</a>
      <a href="<?= $config['base_url'] ?>/chat.php">Поддержка</a>
      <?php if ($_SESSION['user']['role']==='admin'): ?>
        <a href="<?= $config['base_url'] ?>/admin/index.php">Админка</a>
      <?php endif; ?>
      <a href="<?= $config['base_url'] ?>/logout.php">Выйти</a>
    <?php else: ?>
      <a href="<?= $config['base_url'] ?>/login.php">Войти</a>
      <a href="<?= $config['base_url'] ?>/register.php">Регистрация</a>
    <?php endif; ?>
  </nav>
</header>

<main class="layout">
  <aside class="sidebar">
    <h3>Фильтр</h3>
    <label>Бренд</label>
    <select id="brand"></select>
    <label>Модель</label>
    <select id="model"></select>
    <label>Год (от)</label>
    <input type="number" id="year_from" placeholder="1998">
    <label>Год (до)</label>
    <input type="number" id="year_to" placeholder="2025">
    <label>Комплексная часть</label>
    <select id="complex_part"></select>
    <label>Компонент</label>
    <select id="component"></select>

    <label>Поиск (название/артикул/ID)</label>
    <input type="text" id="search" placeholder="например: 123 или тормоза">
  </aside>

  <section class="products" id="products"></section>
</main>

<script src="/mehanik/assets/js/productList.js"></script>
<script src="/mehanik/assets/js/main.js"></script>
</body>
</html>
