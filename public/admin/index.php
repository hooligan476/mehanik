<?php
// mehanik/public/admin/index.php
require_once __DIR__.'/../../middleware.php';
require_admin();
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Админка — Дашборд</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    body { font-family: Arial, sans-serif; background:#f6f7fb; margin:0; padding:0; }
    .wrap { max-width:1200px; margin:28px auto; padding:20px; }
    .top { display:flex; gap:18px; margin-bottom:22px; align-items:stretch; }
    .card {
      flex:1;
      background:#fff;
      padding:18px;
      border-radius:10px;
      box-shadow:0 4px 14px rgba(18,24,40,0.05);
      text-align:center;
    }
    .card h3 { margin:0 0 8px; font-size:15px; color:#555; }
    .card .big { font-size:28px; font-weight:700; color:#1f6feb; }
    .charts { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
    canvas { background:#fff; border-radius:8px; padding:14px; box-shadow:0 4px 14px rgba(18,24,40,0.04); }
    .small-row { display:flex; gap:12px; margin-top:10px; color:#666; font-size:13px; justify-content:center; }
    .info { margin-top:14px; color:#666; font-size:13px; text-align:center; }
    @media (max-width:900px) {
      .charts { grid-template-columns:1fr; }
      .top { flex-direction:column; }
    }
  </style>
</head>
<body>
<?php require_once __DIR__.'/header.php'; ?>

<div class="wrap">
  <h2 style="margin-bottom:14px;">📊 Админка — Дашборд</h2>

  <div class="top">
    <div class="card">
      <h3>Пользователи</h3>
      <div id="users_count" class="big">—</div>
      <div class="small-row"><div id="users_change">—</div></div>
    </div>

    <div class="card">
      <h3>Товары</h3>
      <div id="products_count" class="big">—</div>
      <div class="small-row"><div id="products_top_brand">—</div></div>
    </div>

    <div class="card">
      <h3>Чаты</h3>
      <div id="chats_count" class="big">—</div>
      <div class="small-row"><div id="open_closed">—</div></div>
    </div>
  </div>

  <div class="charts">
    <div>
      <canvas id="usersChart" height="240"></canvas>
      <div class="info">Регистрации пользователей за последние 30 дней</div>
    </div>

    <div>
      <canvas id="productsChart" height="240"></canvas>
      <div class="info">Распределение товаров по брендам (топ 20)</div>
    </div>

    <div style="grid-column: 1 / -1;">
      <canvas id="chatsChart" height="140"></canvas>
      <div class="info">Открытые / закрытые чаты</div>
    </div>
  </div>
</div>

<script>
(async function(){
  const apiUrl = '/mehanik/api/admin-stats.php';
  const res = await fetch(apiUrl, {credentials: 'same-origin'});
  if (!res.ok) {
    console.error('Ошибка API', res.status);
    return;
  }
  const d = await res.json();

  // карточки
  document.getElementById('users_count').textContent = d.users ?? 0;
  document.getElementById('products_count').textContent = d.products ?? 0;
  document.getElementById('chats_count').textContent = (d.open_chats ?? 0) + (d.closed_chats ?? 0);

  // вспомогательная инфа
  document.getElementById('open_closed').textContent = `Открытые: ${d.open_chats ?? 0} · Закрытые: ${d.closed_chats ?? 0}`;
  if (d.products_by_brand && d.products_by_brand.length) {
    document.getElementById('products_top_brand').textContent = `Топ: ${d.products_by_brand[0].brand} (${d.products_by_brand[0].count})`;
  } else {
    document.getElementById('products_top_brand').textContent = 'Нет данных';
  }

  // usersChart (линия)
  const usersByDate = d.users_by_date || [];
  const uLabels = usersByDate.map(x=>x.date);
  const uData = usersByDate.map(x=>x.count);
  new Chart(document.getElementById('usersChart'), {
    type: 'line',
    data: {
      labels: uLabels,
      datasets: [{
        label: 'Регистрации',
        data: uData,
        borderWidth: 2,
        tension: 0.3,
        fill: true,
        backgroundColor: 'rgba(31,111,235,0.12)',
        borderColor: 'rgb(31,111,235)'
      }]
    },
    options: {
      plugins: { legend: { display:false } },
      scales: {
        x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } },
        y: { beginAtZero:true, precision:0 }
      }
    }
  });

  // productsChart (bar)
  const p = d.products_by_brand || [];
  const pLabels = p.map(x=>x.brand);
  const pData = p.map(x=>x.count);
  new Chart(document.getElementById('productsChart'), {
    type: 'bar',
    data: {
      labels: pLabels,
      datasets: [{
        label: 'Товары',
        data: pData,
        borderRadius: 6,
        borderSkipped: false
      }]
    },
    options: {
      plugins: { legend: { display:false } },
      scales: {
        x: { ticks: { autoSkip: false, maxRotation:45 } },
        y: { beginAtZero:true, precision:0 }
      }
    }
  });

  // chatsChart (doughnut)
  new Chart(document.getElementById('chatsChart'), {
    type: 'doughnut',
    data: {
      labels: ['Открытые','Закрытые'],
      datasets: [{ data: [d.open_chats || 0, d.closed_chats || 0] }]
    },
    options: { plugins: { legend: { position: 'right' } } }
  });

})();
</script>
</body>
</html>
