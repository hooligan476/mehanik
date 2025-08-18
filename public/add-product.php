<?php require_once __DIR__.'/../middleware.php'; require_auth(); $config = require __DIR__.'/../config.php'; ?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Добавить товар</title>
<link rel="stylesheet" href="/mehanik/assets/css/style.css"></head><body>
<div class="container">
  <h2>Добавление товара</h2>
  <form id="addProductForm" enctype="multipart/form-data">
    <input type="text" name="name" placeholder="Название" required>
    <input type="text" name="sku" placeholder="ID/Артикул (необязательно)">
    <input type="text" name="manufacturer" placeholder="Производитель">
    <select name="quality"><option>New</option><option>Used</option></select>
    <input type="number" name="availability" placeholder="Наличие" value="1">
    <input type="number" step="0.01" name="price" placeholder="Цена" required>

    <label>Бренд</label><select name="brand_id" id="ap_brand"></select>
    <label>Модель</label><select name="model_id" id="ap_model"></select>
    <label>Годы</label>
    <div class="row">
      <input type="number" name="year_from" placeholder="от">
      <input type="number" name="year_to" placeholder="до">
    </div>
    <label>Комплексная часть</label><select name="complex_part_id" id="ap_cpart"></select>
    <label>Компонент</label><select name="component_id" id="ap_comp"></select>

    <textarea name="description" placeholder="Описание"></textarea>
    <input type="file" name="photo" accept="image/*">

    <button type="submit">Сохранить</button>
  </form>
</div>
<script src="/mehanik/assets/js/productList.js"></script>
<script src="/mehanik/assets/js/main.js"></script>
