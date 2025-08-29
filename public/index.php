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

  <!-- header styles (navbar) -->
  <link rel="stylesheet" href="/mehanik/assets/css/header.css">
  <!-- main site styles (cards, layout, sidebar) -->
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">

  <style>
    /* Доп. стили конкретно для каталога (если нет в style.css) */
    .layout { display: grid; grid-template-columns: 260px 1fr; gap: 20px; padding: 18px; max-width:1200px; margin: 0 auto; }
    .sidebar { background:#fafafa; padding:14px; border-radius:10px; box-shadow:0 6px 18px rgba(0,0,0,.04); }
    .sidebar h3 { margin-top:0; }
    .sidebar label { display:block; margin-top:10px; font-weight:600; font-size:0.95rem; }
    .sidebar select, .sidebar input { width:100%; padding:8px 10px; margin-top:6px; border:1px solid #ddd; border-radius:6px; box-sizing:border-box; }
    .products { display:grid; grid-template-columns: repeat(auto-fill, minmax(240px,1fr)); gap:16px; }
    .muted { color:#6b7280; padding: 8px; }

    /* если карточки ещё не определены в style.css — базовые правила */
    .product-card { background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 6px 18px rgba(0,0,0,.05); display:flex; flex-direction:column; }
    .product-card .card-photo { height:160px; display:flex; align-items:center; justify-content:center; background:#f6f7fb; }
    .product-card .card-photo img { max-width:100%; max-height:100%; object-fit:contain; }
    .product-card .card-body { padding:12px; display:flex; flex-direction:column; gap:8px; flex:1; }
    .pc-title { margin:0; font-size:1rem; font-weight:700; }
    .pc-meta { display:flex; justify-content:space-between; font-weight:700; }
    .pc-desc { color:#374151; font-size:0.9rem; margin:8px 0 0; flex:1; }
    .btn { display:inline-block; background:#0b57a4; color:#fff; padding:8px 12px; border-radius:8px; text-decoration:none; font-weight:600; }
    @media (max-width:900px) {
      .layout { grid-template-columns: 1fr; padding:12px; }
      .sidebar { order:2; }
      .products { order:1; }
    }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/header.php'; ?>

<main class="layout">
  <aside class="sidebar" aria-label="Фильтр товаров">
    <h3>Фильтр</h3>

    <label for="brand">Бренд</label>
    <select id="brand" name="brand"><option value="">Все бренды</option></select>

    <label for="model">Модель</label>
    <select id="model" name="model"><option value="">Все модели</option></select>

    <label for="year_from">Год (от)</label>
    <input type="number" id="year_from" name="year_from" placeholder="1998">

    <label for="year_to">Год (до)</label>
    <input type="number" id="year_to" name="year_to" placeholder="2025">

    <label for="complex_part">Комплексная часть</label>
    <select id="complex_part" name="complex_part"><option value="">Все комплексные части</option></select>

    <label for="component">Компонент</label>
    <select id="component" name="component"><option value="">Все компоненты</option></select>

    <label for="search">Поиск (название / артикул / ID)</label>
    <input type="text" id="search" name="search" placeholder="например: 123 или тормоза">

    <div style="margin-top:10px; display:flex; gap:8px;">
      <button id="clearFilters" class="btn" style="background:#6b7280;">Сбросить</button>
      <button id="applyFilters" class="btn" style="background:#0b57a4;">Применить</button>
    </div>
  </aside>

  <section class="products" id="products" aria-live="polite">
    <!-- карточки товаров подгрузятся через JS -->
  </section>
</main>

<!-- Скрипты: productList.js должен быть подключён первым -->
<script src="/mehanik/assets/js/productList.js"></script>

<!-- Заборная обёртка для совместимости: main.js ожидает функцию loadLookups() -->
<script>
  // Если определён productList.loadProducts — используем его для подгрузки lookups
  window.loadLookups = async function() {
    if (window.productList && typeof window.productList.loadProducts === 'function') {
      // вызов без параметров вернёт lookups и отобразит первые товары
      return await window.productList.loadProducts();
    }
    return null;
  };

  // Вспомогательная функция: собирает текущие фильтры из DOM и возвращает объект
  window.collectFilters = function() {
    const get = id => document.getElementById(id) ? document.getElementById(id).value : '';
    return {
      brand: get('brand'),
      model: get('model'),
      year_from: get('year_from'),
      year_to: get('year_to'),
      complex_part: get('complex_part'),
      component: get('component'),
      q: get('search')
    };
  };

  // Подключаем кнопки Clear/Apply, чтобы пользователю удобнее было
  document.addEventListener('DOMContentLoaded', () => {
    const clearBtn = document.getElementById('clearFilters');
    const applyBtn = document.getElementById('applyFilters');

    if (clearBtn) {
      clearBtn.addEventListener('click', (e) => {
        e.preventDefault();
        ['brand','model','year_from','year_to','complex_part','component','search'].forEach(id=>{
          const el = document.getElementById(id);
          if (el) {
            if (el.tagName.toLowerCase() === 'select') el.selectedIndex = 0;
            else el.value = '';
          }
        });
        // триггерим фильтр
        if (typeof runFilter === 'function') runFilter();
        else if (window.productList && window.productList.loadProducts) productList.loadProducts(collectFilters());
      });
    }

    if (applyBtn) {
      applyBtn.addEventListener('click', (e) => {
        e.preventDefault();
        if (typeof runFilter === 'function') runFilter();
        else if (window.productList && window.productList.loadProducts) productList.loadProducts(collectFilters());
      });
    }
  });
</script>

<!-- main.js (в нём определён runFilter() и обработчики) -->
 
<script src="/mehanik/assets/js/main.js"></script>
</body>
</html>
