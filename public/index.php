<?php
// mehanik/public/index.php

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
  <title>Mehanik — Рекомендации</title>

  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    :root{
      --card-bg:#ffffff;
      --muted:#6b7280;
      --accent:#0b57a4;
      --surface:#f8fafc;
      --input-border:#e6e9ef;
      --shadow: 0 8px 24px rgba(12,17,23,.04);
    }
    body { background:#f5f7fb; font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; color:#0f1724; margin:0; }
    .container { max-width:1280px; margin:0 auto; padding:22px; box-sizing:border-box; }
    .hero { display:flex; gap:20px; align-items:center; justify-content:space-between; margin-bottom:18px; }
    .hero-left { display:flex; flex-direction:column; gap:8px; }
    .title { font-size:1.4rem; font-weight:800; color:#0b1220; }
    .subtitle { color:var(--muted); }
    .hero-actions { display:flex; gap:10px; }
    .btn { display:inline-block; background:var(--accent); color:#fff; padding:9px 14px; border-radius:10px; text-decoration:none; font-weight:700; cursor:pointer; border:0; box-shadow: 0 4px 10px rgba(11,87,164,0.08); }
    .btn-ghost { background:#eef2f6; color:var(--accent); border-radius:10px; padding:8px 12px; font-weight:700; border:1px solid rgba(11,87,164,0.06); cursor:pointer; }

    /* products grid and card styles (kept minimal from previous index) */
    .products { display:grid; grid-template-columns: repeat(auto-fill, minmax(240px,1fr)); gap:16px; align-items:start; padding: 4px; }
    .product-card { background: var(--card-bg); border-radius:12px; box-shadow: 0 6px 20px rgba(8,12,20,0.04); border:1px solid rgba(15,20,30,0.03); overflow:hidden; display:flex; flex-direction:column; min-height:160px; transition: transform .12s ease, box-shadow .12s ease; }
    .product-card:hover { transform: translateY(-6px); box-shadow: 0 12px 30px rgba(10,20,40,0.08); }
    .product-media { width:100%; height:150px; display:block; background:#f3f6fa; object-fit:cover; flex-shrink:0; }
    .product-content { padding:12px; display:flex; flex-direction:column; gap:8px; flex:1 1 auto; }
    .product-title { font-weight:700; font-size:1rem; color:#0b1220; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .product-sub { font-size:0.92rem; color:var(--muted); }
    .product-row { display:flex; justify-content:space-between; align-items:center; gap:8px; margin-top:auto; }
    .price { font-weight:800; font-size:1.05rem; color:var(--accent); }
    .meta { font-size:0.86rem; color:var(--muted); }

    @media (max-width:900px) { .hero { flex-direction:column; align-items:flex-start; } .hero-actions { width:100%; justify-content:flex-start; } }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/header.php'; ?>

<main class="container">
  <section class="hero" aria-label="Навигация">
    <div class="hero-left">
      <div class="title">Рекомендации и новости</div>
      <div class="subtitle">Здесь будут рекомендованные товары, акции и новости. Чтобы перейти к полному каталогу и фильтрам — используйте ссылки справа.</div>
    </div>

    <div class="hero-actions">
      <a class="btn" href="/mehanik/public/my-cars.php">Мои автомобили / Каталог авто</a>
      <a class="btn" href="/mehanik/public/my-products.php">Мои запчасти / Каталог запчастей</a>
      <a class="btn-ghost" href="/mehanik/public/news.php">Новости / Акции</a>
    </div>
  </section>

  <section aria-live="polite">
    <div id="products" class="products">
      <!-- Здесь подгрузятся рекомендованные товары через JS -->
    </div>
  </section>
</main>

<script>
// лёгкий fetchJSON helper (взято из старого index)
window.fetchJSON = async function(url, opts = {}) {
  try {
    const resp = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts));
    if (!resp.ok) {
      console.warn('fetchJSON: non-ok response for', url, resp.status);
      return null;
    }
    return await resp.json();
  } catch (e) {
    console.warn('fetchJSON error for', url, e);
    return null;
  }
};
</script>

<!-- productList.js остаётся подключённым — на следующих страницах (my-cars / my-products) вы будете использовать его для фильтрации. -->
<script src="/mehanik/assets/js/productList.js"></script>

<script>
// Попытка загрузить рекомендованные товары. productList может реализовать loadProducts/ loadRecommendations — гибкая и безопасная инициализация.
(async function(){
  const container = document.getElementById('products');
  function renderFallback(items){
    container.innerHTML = '';
    if (!items || !items.length) {
      container.innerHTML = '<div class="muted">Рекомендации пока отсутствуют.</div>';
      return;
    }
    for (const it of items) {
      const card = document.createElement('article');
      card.className = 'product-card';
      const img = document.createElement('img'); img.className = 'product-media'; img.alt = it.name || 'Изображение'; img.src = it.photo || it.logo || '/mehanik/assets/img/no-image.png';
      const content = document.createElement('div'); content.className = 'product-content';
      const title = document.createElement('div'); title.className = 'product-title'; title.textContent = (it.name || it.title || '—');
      const sub = document.createElement('div'); sub.className = 'product-sub'; sub.textContent = (it.brand_name ? it.brand_name : (it.complex_part_name ? it.complex_part_name : (it.type || '')));
      const row = document.createElement('div'); row.className = 'product-row';
      const price = document.createElement('div'); price.className = 'price'; price.textContent = it.price ? (it.price + ' ₽') : '—';
      const meta = document.createElement('div'); meta.className = 'meta'; meta.textContent = 'ID:' + (it.id || '-');
      row.appendChild(price); row.appendChild(meta);
      content.appendChild(title); content.appendChild(sub); content.appendChild(row);
      card.appendChild(img); card.appendChild(content); container.appendChild(card);
    }
  }

  try {
    if (window.productList && typeof productList.loadProducts === 'function') {
      // пробуем загрузить рекомендации (передаём type=recommendation)
      try {
        await productList.loadProducts({ type: 'recommendation' });
        return;
      } catch(e) { console.warn('productList.loadProducts failed', e); }
    }

    // fallback: сделать fetch к API, ожидая параметр recommendation=1
    const resp = await fetch('/mehanik/api/products.php?recommendation=1', { credentials: 'same-origin' });
    if (resp.ok) {
      const json = await resp.json();
      const items = json.products ?? json.items ?? json;
      renderFallback(Array.isArray(items) ? items : []);
      return;
    }

    renderFallback([]);
  } catch (e) {
    console.warn('recommendations load failed', e);
    renderFallback([]);
  }
})();
</script>

</body>
</html>
