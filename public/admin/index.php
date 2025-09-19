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
    :root{
      --bg: #f6f7fb;
      --card-bg: #ffffff;
      --muted: #6b7280;
      --accent: #1f6feb;
      --accent-2: #16a34a;
      --danger: #ef4444;
      --glass: rgba(255,255,255,0.7);
      --radius: 12px;
    }
    body{font-family:Inter, system-ui, Arial, sans-serif;background:var(--bg);margin:0;color:#0f172a}
    .wrap{max-width:1200px;margin:28px auto;padding:20px}
    h2{margin:0 0 18px}
    .h-section{display:grid;grid-template-columns:360px 1fr;gap:18px;margin-bottom:18px;align-items:stretch}
    @media(max-width:980px){ .h-section{grid-template-columns:1fr; } }

    .metric-card{
      background:var(--card-bg);
      padding:18px;border-radius:var(--radius);
      box-shadow:0 6px 20px rgba(2,6,23,0.04);
      display:flex;flex-direction:column;gap:8px;justify-content:center;
    }
    .metric-card h3{margin:0;font-size:14px;color:#334155;font-weight:700}
    .metric-big{font-size:34px;font-weight:800;color:var(--accent);letter-spacing:-0.02em}
    .metric-sub{color:var(--muted);font-size:13px}
    .metric-extra{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px}
    .pill{background:#f3f4f6;padding:6px 10px;border-radius:999px;font-weight:700;color:#111;font-size:13px}

    .chart-card{background:transparent;padding:0;border-radius:var(--radius);display:flex;flex-direction:column;gap:8px}
    .chart-wrap{background:var(--card-bg);padding:12px;border-radius:var(--radius);box-shadow:0 6px 20px rgba(2,6,23,0.04)}
    .chart-title{font-weight:700;color:#334155;margin-bottom:8px;font-size:14px}
    /* New: chart flex layout: canvas + side summary */
    .chart-flex{display:flex;gap:12px;align-items:flex-start}
    .chart-canvas{flex:1;min-width:0}
    .chart-side{width:160px;flex:0 0 160px;background:#fff;border-radius:10px;padding:10px;box-shadow:0 6px 18px rgba(2,6,23,0.04);display:flex;flex-direction:column;gap:8px;align-items:flex-start}
    .chart-side .s-title{font-weight:700;color:#334155;font-size:13px}
    .chart-side .s-val{font-weight:800;font-size:18px;color:#111}
    canvas{width:100% !important;height:260px !important}

    .muted{color:var(--muted)}
    .summary-row{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-top:10px;color:var(--muted);font-size:13px;justify-content:flex-start}
    .small-canvas{height:120px !important}
    .no-data{display:flex;align-items:center;justify-content:center;height:220px;background:linear-gradient(180deg,#fff,#fbfdff);border-radius:10px;color:var(--muted);font-weight:600}
  </style>
</head>
<body>
<?php require_once __DIR__.'/header.php'; ?>

<div class="wrap">
  <h2>📊 Админка — Дашборд</h2>

  <!-- USERS -->
  <div class="h-section" id="section-users">
    <div class="metric-card">
      <h3>Пользователи</h3>
      <div class="metric-big" id="users_count">—</div>
      <div class="metric-sub" id="users_sub">Всего пользователей</div>
      <div class="metric-extra" id="users_roles_pills"></div>
      <div class="summary-row" id="users_summary">Загрузка...</div>
    </div>
    <div class="chart-card">
      <div class="chart-wrap">
        <div class="chart-title">Добавления / Удаления пользователей (количество)</div>
        <div class="chart-flex">
          <div class="chart-canvas"><canvas id="usersChart"></canvas></div>
          <div class="chart-side" id="users_side">
            <div class="s-title">Итоги (30 дней)</div>
            <div>Добавлено: <div class="s-val" id="users_added_total">—</div></div>
            <div>Удалено: <div class="s-val" id="users_deleted_total">—</div></div>
            <div>Чистый прирост: <div class="s-val" id="users_net">—</div></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- PRODUCTS -->
  <div class="h-section" id="section-products">
    <div class="metric-card">
      <h3>Товары</h3>
      <div class="metric-big" id="products_count">—</div>
      <div class="metric-sub" id="products_sub">Всего объявлений</div>
      <div class="metric-extra" id="products_status_pills"></div>
      <div class="summary-row" id="products_summary">Загрузка...</div>
    </div>
    <div class="chart-card">
      <div class="chart-wrap">
        <div class="chart-title">Добавления / Удаления объявлений (количество)</div>
        <div class="chart-flex">
          <div class="chart-canvas"><canvas id="productsChart"></canvas></div>
          <div class="chart-side" id="products_side">
            <div class="s-title">Итоги (30 дней)</div>
            <div>Добавлено: <div class="s-val" id="products_added_total">—</div></div>
            <div>Удалено: <div class="s-val" id="products_deleted_total">—</div></div>
            <div>Чистый прирост: <div class="s-val" id="products_net">—</div></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- CARS -->
  <div class="h-section" id="section-cars">
    <div class="metric-card">
      <h3>Машины на продаже</h3>
      <div class="metric-big" id="cars_count">—</div>
      <div class="metric-sub" id="cars_sub">Всего объявлений</div>
      <div class="metric-extra" id="cars_status_pills"></div>
      <div class="summary-row" id="cars_summary">Загрузка...</div>
    </div>
    <div class="chart-card">
      <div class="chart-wrap">
        <div class="chart-title">Добавления / Удаления машин (количество)</div>
        <div class="chart-flex">
          <div class="chart-canvas"><canvas id="carsChart"></canvas></div>
          <div class="chart-side" id="cars_side">
            <div class="s-title">Итоги (30 дней)</div>
            <div>Добавлено: <div class="s-val" id="cars_added_total">—</div></div>
            <div>Удалено: <div class="s-val" id="cars_deleted_total">—</div></div>
            <div>Чистый прирост: <div class="s-val" id="cars_net">—</div></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- SERVICES -->
  <div class="h-section" id="section-services">
    <div class="metric-card">
      <h3>Сервисы / Услуги</h3>
      <div class="metric-big" id="services_count">—</div>
      <div class="metric-sub" id="services_sub">Всего объявлений</div>
      <div class="metric-extra" id="services_status_pills"></div>
      <div class="summary-row" id="services_summary">Загрузка...</div>
    </div>
    <div class="chart-card">
      <div class="chart-wrap">
        <div class="chart-title">Добавления / Удаления сервисов (количество)</div>
        <div class="chart-flex">
          <div class="chart-canvas"><canvas id="servicesChart"></canvas></div>
          <div class="chart-side" id="services_side">
            <div class="s-title">Итоги (30 дней)</div>
            <div>Добавлено: <div class="s-val" id="services_added_total">—</div></div>
            <div>Удалено: <div class="s-val" id="services_deleted_total">—</div></div>
            <div>Чистый прирост: <div class="s-val" id="services_net">—</div></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- CHATS -->
  <div class="h-section" id="section-chats">
    <div class="metric-card">
      <h3>Чаты</h3>
      <div class="metric-big" id="chats_count">—</div>
      <div class="metric-sub" id="chats_sub">Открытые / закрытые</div>
      <div class="metric-extra">
        <div class="pill" id="chats_open">Открытые: —</div>
        <div class="pill" id="chats_closed">Закрытые: —</div>
      </div>
      <div class="summary-row" id="chats_summary">Загрузка...</div>
    </div>
    <div class="chart-card">
      <div class="chart-wrap">
        <div class="chart-title">Активность чатов / сообщений (количество)</div>
        <div class="chart-flex">
          <div class="chart-canvas"><canvas id="chatsChart" class="small-canvas"></canvas></div>
          <div class="chart-side" id="chats_side">
            <div class="s-title">Итоги (30 дней)</div>
            <div>Сообщений: <div class="s-val" id="messages_added_total">—</div></div>
            <div>Удалено сообщ.: <div class="s-val" id="messages_deleted_total">—</div></div>
            <div>Среднее время ответа: <div class="s-val" id="chats_avg">—</div></div>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<script>
(async function(){
  const apiUrl = '/mehanik/api/admin-stats.php';
  let d = null;
  try {
    const res = await fetch(apiUrl, { credentials: 'same-origin' });
    if (!res.ok) {
      console.error('API admin-stats error', res.status);
      document.querySelectorAll('.summary-row').forEach(el=>el.textContent = 'Ошибка загрузки данных');
      return;
    }
    d = await res.json();
  } catch (e) {
    console.error('Fetch error', e);
    document.querySelectorAll('.summary-row').forEach(el=>el.textContent = 'Ошибка загрузки данных');
    return;
  }

  // helpers
  function hasSeries(key){
    return Array.isArray(d[key]) && d[key].length && typeof d[key][0].date !== 'undefined' && typeof d[key][0].count !== 'undefined';
  }
  function seriesToLabelsData(series){
    return {
      labels: series.map(x=>x.date),
      data: series.map(x=>Number(x.count||0))
    };
  }
  function mergeSeriesByLabels(labels, seriesMap) {
    // labels: array of dates; seriesMap: {name: seriesArray}
    const out = {};
    labels.forEach(lbl => out[lbl] = []);
    for (const name in seriesMap) {
      const s = seriesMap[name];
      const map = {};
      s.forEach(it => map[it.date] = Number(it.count||0));
      labels.forEach(lbl => out[lbl].push(map[lbl] ?? 0));
    }
    return out;
  }
  function createBar(ctxEl, labels, datasets, opts = {}) {
    if (!ctxEl) return null;
    return new Chart(ctxEl, {
      type: 'bar',
      data: { labels, datasets },
      options: { plugins:{ legend:{ display:true, position:'top' } }, scales:{ x:{ stacked:false }, y:{ beginAtZero:true, precision:0 } }, maintainAspectRatio:false }
    });
  }

  // ---------- USERS ----------
  (function(){
    const total = Number(d.users_total ?? d.users ?? 0);
    document.getElementById('users_count').textContent = total;
    document.getElementById('users_sub').textContent = 'Всего пользователей';

    // roles pills
    const roles = d.users_by_role || [];
    const pillsWrap = document.getElementById('users_roles_pills');
    pillsWrap.innerHTML = '';
    if (Array.isArray(roles) && roles.length) {
      roles.forEach(r => {
        const lbl = r.role ?? r.key ?? 'role';
        const cnt = r.count ?? 0;
        const p = document.createElement('div'); p.className='pill'; p.textContent = `${lbl}: ${cnt}`; pillsWrap.appendChild(p);
      });
      document.getElementById('users_summary').textContent = 'Роли пользователей';
    } else {
      pillsWrap.innerHTML = '<div class="muted">Нет данных по ролям</div>';
      document.getElementById('users_summary').textContent = '—';
    }

    const addedKey = 'users_by_date';
    const deletedKey = 'users_deleted_by_date';
    const addedTotal = Number(d.users_added_total ?? 0);
    const deletedTotal = Number(d.users_deleted_total ?? 0);
    document.getElementById('users_added_total').textContent = addedTotal;
    document.getElementById('users_deleted_total').textContent = deletedTotal;
    document.getElementById('users_net').textContent = (addedTotal - deletedTotal);

    const ctx = document.getElementById('usersChart').getContext('2d');
    if (hasSeries(addedKey) || hasSeries(deletedKey)) {
      // choose labels from added (preferred) else from deleted
      const labels = hasSeries(addedKey) ? d[addedKey].map(x=>x.date) : d[deletedKey].map(x=>x.date);
      const addedData = hasSeries(addedKey) ? d[addedKey].map(x=>Number(x.count||0)) : labels.map(()=>0);
      const deletedData = hasSeries(deletedKey) ? d[deletedKey].map(x=>Number(x.count||0)) : labels.map(()=>0);

      createBar(ctx, labels, [
        { label: 'Добавления', data: addedData, backgroundColor: 'rgba(31,111,235,0.85)' },
        { label: 'Удаления', data: deletedData, backgroundColor: 'rgba(239,68,68,0.85)' }
      ]);
    } else {
      document.getElementById('usersChart').parentElement.innerHTML = '<div class="no-data">Нет временных данных по пользователям</div>';
    }
  })();

  // ---------- PRODUCTS ----------
  (function(){
    const total = Number(d.products_total ?? d.products ?? 0);
    document.getElementById('products_count').textContent = total;
    document.getElementById('products_sub').textContent = 'Всего объявлений';

    const statuses = d.products_by_status || [];
    const pillsWrap = document.getElementById('products_status_pills'); pillsWrap.innerHTML = '';
    if (Array.isArray(statuses) && statuses.length) {
      statuses.forEach(s => {
        const lbl = s.status ?? s.key ?? 'status';
        const cnt = s.count ?? 0;
        const p = document.createElement('div'); p.className='pill'; p.textContent = `${lbl}: ${cnt}`; pillsWrap.appendChild(p);
      });
      document.getElementById('products_summary').textContent = 'Распределение по статусам';
    } else {
      pillsWrap.innerHTML = '<div class="muted">Нет данных по статусам</div>';
      document.getElementById('products_summary').textContent = '—';
    }

    const addedKey = 'products_by_date';
    const deletedKey = 'products_deleted_by_date';
    const addedTotal = Number(d.products_added_total ?? 0);
    const deletedTotal = Number(d.products_deleted_total ?? 0);
    document.getElementById('products_added_total').textContent = addedTotal;
    document.getElementById('products_deleted_total').textContent = deletedTotal;
    document.getElementById('products_net').textContent = (addedTotal - deletedTotal);

    const ctx = document.getElementById('productsChart').getContext('2d');
    if (hasSeries(addedKey) || hasSeries(deletedKey)) {
      const labels = hasSeries(addedKey) ? d[addedKey].map(x=>x.date) : d[deletedKey].map(x=>x.date);
      const addedData = hasSeries(addedKey) ? d[addedKey].map(x=>Number(x.count||0)) : labels.map(()=>0);
      const deletedData = hasSeries(deletedKey) ? d[deletedKey].map(x=>Number(x.count||0)) : labels.map(()=>0);

      createBar(ctx, labels, [
        { label: 'Добавления', data: addedData, backgroundColor: 'rgba(31,111,235,0.85)' },
        { label: 'Удаления', data: deletedData, backgroundColor: 'rgba(239,68,68,0.85)' }
      ]);
    } else if (Array.isArray(d.products_by_brand) && d.products_by_brand.length) {
      const labels = d.products_by_brand.slice(0,20).map(x=>x.brand);
      const values = d.products_by_brand.slice(0,20).map(x=>Number(x.count||0));
      createBar(ctx, labels, [{ label: 'По брендам', data: values, backgroundColor: 'rgba(31,111,235,0.85)' }]);
    } else {
      document.getElementById('productsChart').parentElement.innerHTML = '<div class="no-data">Нет данных по товарам</div>';
    }
  })();

  // ---------- CARS ----------
  (function(){
    const total = Number(d.cars_total ?? 0);
    document.getElementById('cars_count').textContent = total;
    document.getElementById('cars_sub').textContent = 'Всего объявлений';

    const statuses = d.cars_by_status || [];
    const pillsWrap = document.getElementById('cars_status_pills'); pillsWrap.innerHTML = '';
    if (Array.isArray(statuses) && statuses.length) {
      statuses.forEach(s => {
        const lbl = s.status ?? s.key ?? 'status';
        const cnt = s.count ?? 0;
        const p = document.createElement('div'); p.className='pill'; p.textContent = `${lbl}: ${cnt}`; pillsWrap.appendChild(p);
      });
      document.getElementById('cars_summary').textContent = 'Распределение по статусам';
    } else {
      pillsWrap.innerHTML = '<div class="muted">Нет данных по статусам машин</div>';
      document.getElementById('cars_summary').textContent = '—';
    }

    const addedKey = 'cars_by_date';
    const deletedKey = 'cars_deleted_by_date';
    const addedTotal = Number(d.cars_added_total ?? 0);
    const deletedTotal = Number(d.cars_deleted_total ?? 0);
    document.getElementById('cars_added_total').textContent = addedTotal;
    document.getElementById('cars_deleted_total').textContent = deletedTotal;
    document.getElementById('cars_net').textContent = (addedTotal - deletedTotal);

    const ctx = document.getElementById('carsChart').getContext('2d');
    if (hasSeries(addedKey) || hasSeries(deletedKey)) {
      const labels = hasSeries(addedKey) ? d[addedKey].map(x=>x.date) : d[deletedKey].map(x=>x.date);
      const addedData = hasSeries(addedKey) ? d[addedKey].map(x=>Number(x.count||0)) : labels.map(()=>0);
      const deletedData = hasSeries(deletedKey) ? d[deletedKey].map(x=>Number(x.count||0)) : labels.map(()=>0);
      createBar(ctx, labels, [
        { label: 'Добавления', data: addedData, backgroundColor: 'rgba(16,185,129,0.85)' },
        { label: 'Удаления', data: deletedData, backgroundColor: 'rgba(239,68,68,0.85)' }
      ]);
    } else {
      document.getElementById('carsChart').parentElement.innerHTML = '<div class="no-data">Нет данных по машинам</div>';
    }
  })();

  // ---------- SERVICES ----------
  (function(){
    const total = Number(d.services_total ?? 0);
    document.getElementById('services_count').textContent = total;
    document.getElementById('services_sub').textContent = 'Всего объявлений';

    const statuses = d.services_by_status || [];
    const pillsWrap = document.getElementById('services_status_pills'); pillsWrap.innerHTML = '';
    if (Array.isArray(statuses) && statuses.length) {
      statuses.forEach(s => {
        const lbl = s.status ?? s.key ?? 'status';
        const cnt = s.count ?? 0;
        const p = document.createElement('div'); p.className='pill'; p.textContent = `${lbl}: ${cnt}`; pillsWrap.appendChild(p);
      });
      document.getElementById('services_summary').textContent = 'Распределение по статусам';
    } else {
      pillsWrap.innerHTML = '<div class="muted">Нет данных по статусам сервисов</div>';
      document.getElementById('services_summary').textContent = '—';
    }

    const addedKey = 'services_by_date';
    const deletedKey = 'services_deleted_by_date';
    const addedTotal = Number(d.services_added_total ?? 0);
    const deletedTotal = Number(d.services_deleted_total ?? 0);
    document.getElementById('services_added_total').textContent = addedTotal;
    document.getElementById('services_deleted_total').textContent = deletedTotal;
    document.getElementById('services_net').textContent = (addedTotal - deletedTotal);

    const ctx = document.getElementById('servicesChart').getContext('2d');
    if (hasSeries(addedKey) || hasSeries(deletedKey)) {
      const labels = hasSeries(addedKey) ? d[addedKey].map(x=>x.date) : d[deletedKey].map(x=>x.date);
      const addedData = hasSeries(addedKey) ? d[addedKey].map(x=>Number(x.count||0)) : labels.map(()=>0);
      const deletedData = hasSeries(deletedKey) ? d[deletedKey].map(x=>Number(x.count||0)) : labels.map(()=>0);
      createBar(ctx, labels, [
        { label: 'Добавления', data: addedData, backgroundColor: 'rgba(99,102,241,0.85)' },
        { label: 'Удаления', data: deletedData, backgroundColor: 'rgba(239,68,68,0.85)' }
      ]);
    } else {
      document.getElementById('servicesChart').parentElement.innerHTML = '<div class="no-data">Нет данных по сервисам</div>';
    }
  })();

  // ---------- CHATS / MESSAGES ----------
  (function(){
    const open = Number(d.open_chats ?? 0);
    const closed = Number(d.closed_chats ?? 0);
    const total = open + closed;
    document.getElementById('chats_count').textContent = total;
    document.getElementById('chats_open').textContent = 'Открытые: ' + open;
    document.getElementById('chats_closed').textContent = 'Закрытые: ' + closed;
    document.getElementById('chats_sub').textContent = 'Чатов всего';
    document.getElementById('chats_avg').textContent = d.chats_avg_response ? (d.chats_avg_response + ' сек') : '—';

    // messages series
    const addedKey = 'messages_by_date';
    const deletedKey = 'messages_deleted_by_date';
    document.getElementById('messages_added_total').textContent = Number(d.messages_added_total ?? 0);
    document.getElementById('messages_deleted_total').textContent = Number(d.messages_deleted_total ?? 0);

    const ctx = document.getElementById('chatsChart').getContext('2d');
    if (hasSeries('chats_by_date')) {
      const labels = d.chats_by_date.map(x=>x.date);
      const data = d.chats_by_date.map(x=>Number(x.count||0));
      createBar(ctx, labels, [{ label: 'Чаты', data, backgroundColor: 'rgba(96,165,250,0.85)' }]);
    } else if (hasSeries(addedKey) || hasSeries(deletedKey)) {
      const labels = hasSeries(addedKey) ? d[addedKey].map(x=>x.date) : d[deletedKey].map(x=>x.date);
      const addedData = hasSeries(addedKey) ? d[addedKey].map(x=>Number(x.count||0)) : labels.map(()=>0);
      const deletedData = hasSeries(deletedKey) ? d[deletedKey].map(x=>Number(x.count||0)) : labels.map(()=>0);
      createBar(ctx, labels, [
        { label: 'Сообщения', data: addedData, backgroundColor: 'rgba(96,165,250,0.85)' },
        { label: 'Удалённые сообщения', data: deletedData, backgroundColor: 'rgba(239,68,68,0.85)' }
      ]);
    } else {
      // fallback: show open/closed bars
      createBar(ctx, ['Открытые','Закрытые'], [{ label: 'Чаты', data: [open, closed], backgroundColor: 'rgba(96,165,250,0.85)' }]);
    }
  })();

})();
</script>
</body>
</html>
