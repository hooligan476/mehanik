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

<?php
// подключаем вынесённую шапку (header.php находится в корне проекта mehanik)
require_once __DIR__ . '/header.php';

?>

<main class="layout">
  <aside class="sidebar">
    <h3>Фильтр</h3>
    <label>Бренд</label>
    <select id="brand"><option value="">Все бренды</option></select>

    <label>Модель</label>
    <select id="model"><option value="">Все модели</option></select>

    <label>Год (от)</label>
    <input type="number" id="year_from" placeholder="1998">

    <label>Год (до)</label>
    <input type="number" id="year_to" placeholder="2025">

    <label>Комплексная часть</label>
    <select id="complex_part"><option value="">Все комплексные части</option></select>

    <label>Компонент</label>
    <select id="component"><option value="">Все компоненты</option></select>

    <label>Поиск (название/артикул/ID)</label>
    <input type="text" id="search" placeholder="например: 123 или тормоза">
  </aside>

  <section class="products" id="products"></section>
</main>

<!-- Скрипты -->
<script src="/mehanik/assets/js/productList.js"></script>
<script src="/mehanik/assets/js/main.js"></script>
</body>
</html>
