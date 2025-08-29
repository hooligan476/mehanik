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

  <link rel="stylesheet" href="/mehanik/assets/css/header.css">
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">

  <style>
    .layout { display: grid; grid-template-columns: 260px 1fr; gap: 20px; padding: 18px; max-width:1200px; margin: 0 auto; }
    .sidebar { background:#fafafa; padding:14px; border-radius:10px; box-shadow:0 6px 18px rgba(0,0,0,.04); }
    .sidebar h3 { margin-top:0; }
    .sidebar label { display:block; margin-top:10px; font-weight:600; font-size:.95rem; }
    .sidebar select, .sidebar input { width:100%; padding:8px 10px; margin-top:6px; border:1px solid #ddd; border-radius:6px; box-sizing:border-box; }
    .products { display:grid; grid-template-columns: repeat(auto-fill, minmax(240px,1fr)); gap:16px; }
    .muted { color:#6b7280; padding: 8px; }
    .btn { display:inline-block; background:#0b57a4; color:#fff; padding:8px 12px; border-radius:8px; text-decoration:none; font-weight:600; cursor:pointer; border:0; }
    .btn-ghost { background:#6b7280; }
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
      <button id="clearFilters" class="btn btn-ghost">Сбросить</button>
      <!-- Кнопка "Применить" убрана — фильтры применяются автоматически -->
    </div>
  </aside>

  <section class="products" id="products" aria-live="polite">
    <!-- карточки товаров подгрузятся через JS -->
  </section>
</main>

<!-- Скрипты: productList.js должен быть подключён первым -->
<script src="/mehanik/assets/js/productList.js"></script>

<script>
/* Автозагрузка lookups и автоприменение фильтров
   Работает либо через runFilter() (если он определён в main.js),
   либо через productList.loadProducts(filters).
*/
(function(){
  // простая debounce реализация, не зависим от main.js
  function debounce(fn, ms = 300) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(()=>fn(...args), ms); };
  }

  // собирает только непустые фильтры
  function collectFilters() {
    const getVal = id => {
      const el = document.getElementById(id);
      return el ? String(el.value).trim() : '';
    };
    return {
      brand: getVal('brand'),
      model: getVal('model'),
      year_from: getVal('year_from'),
      year_to: getVal('year_to'),
      complex_part: getVal('complex_part'),
      component: getVal('component'),
      q: getVal('search')
    };
  }

  // применить фильтр: предпочитаем runFilter, иначе productList.loadProducts
  async function applyFilters() {
    const filters = collectFilters();
    if (typeof runFilter === 'function') {
      // runFilter ожидает данные из DOM сам — просто вызовем
      try { await runFilter(); } catch(e){ console.warn('runFilter error', e); }
    } else if (window.productList && typeof productList.loadProducts === 'function') {
      try { await productList.loadProducts(filters); } catch(e){ console.warn('productList.loadProducts error', e); }
    } else {
      console.warn('Нет runFilter и нет productList.loadProducts');
    }
  }

  // инициализация при загрузке
  document.addEventListener('DOMContentLoaded', async function(){
    // 1) Сначала попытаемся вызвать loadLookups() (если определена) — она подгружает lookups и товары
    if (typeof loadLookups === 'function') {
      try {
        await loadLookups();
      } catch (e) {
        console.warn('loadLookups failed', e);
      }
    } else if (window.productList && typeof productList.loadProducts === 'function') {
      // вызываем без фильтров — покажет все товары и подгрузит lookups
      try { await productList.loadProducts(); } catch(e){ console.warn('productList.loadProducts() init failed', e); }
    }

    // 2) Затем явный вызов applyFilters чтобы убедиться, что отображение синхронизировано
    await applyFilters();

    // 3) навешиваем listeners — изменения селектов и полей будут автоматически применять фильтры
    const idsChange = ['brand','model','year_from','year_to','complex_part','component'];
    idsChange.forEach(id => {
      const el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('change', () => {
        applyFilters();
      });
    });

    // Поиск с debounce
    const search = document.getElementById('search');
    if (search) {
      search.addEventListener('input', debounce(()=>applyFilters(), 300));
    }

    // Сброс фильтров
    const clearBtn = document.getElementById('clearFilters');
    if (clearBtn) {
      clearBtn.addEventListener('click', (e) => {
        e.preventDefault();
        ['brand','model','year_from','year_to','complex_part','component','search'].forEach(id=>{
          const el = document.getElementById(id);
          if (!el) return;
          if (el.tagName.toLowerCase() === 'select') el.selectedIndex = 0;
          else el.value = '';
        });
        applyFilters();
      });
    }
  });
})();
</script>

<!-- main.js (он установит свои слушатели и runFilter, если есть) -->
<script src="/mehanik/assets/js/main.js"></script>
</body>
</html>
