<?php
// public/my-cars.php — обновлённая версия
require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php';

// Текущий пользователь (используется в JS для управления действиями)
$user_id = (int)($_SESSION['user']['id'] ?? 0);
if (!$user_id) {
    http_response_code(403); echo "Пользователь не в сессии."; exit;
}

$noPhoto = '/mehanik/assets/no-photo.png';
$uploadsPrefix = '/mehanik/uploads/cars/';
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Мои авто — Mehanik</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    .page { max-width:1200px; margin:18px auto; padding:14px; }
    .layout { display:grid; grid-template-columns: 320px 1fr; gap:18px; }
    @media (max-width:1100px){ .layout{grid-template-columns:1fr;} }

    .topbar-row { display:flex; gap:12px; align-items:center; margin-bottom:14px; flex-wrap:wrap; }
    .title { font-size:1.4rem; font-weight:800; margin:0; }
    .tools { margin-left:auto; display:flex; gap:8px; align-items:center; }
    .btn { background:#0b57a4;color:#fff;padding:8px 12px;border-radius:8px;border:0;cursor:pointer;font-weight:700;text-decoration:none; }
    .btn-ghost { background:transparent;border:1px solid #e6eef7;color:#0b57a4;padding:8px 12px;border-radius:8px;text-decoration:none; }

    /* sidebar filters */
    .sidebar { background:#fff;padding:14px;border-radius:12px;box-shadow:0 8px 24px rgba(2,6,23,0.04); }
    .form-row{margin-top:10px;display:flex;flex-direction:column;gap:6px}
    .form-row label{font-weight:700}
    .form-row select,.form-row input{padding:8px;border-radius:8px;border:1px solid #eef3f8}
    .controls-row{display:flex;gap:8px;align-items:center;margin-top:12px}

    /* products */
    .products { display:grid; grid-template-columns: repeat(3,1fr); gap:18px; }
    @media (max-width:992px){ .products{grid-template-columns:repeat(2,1fr);} }
    @media (max-width:640px){ .products{grid-template-columns:1fr;} }

    .card { background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 8px 20px rgba(2,6,23,0.06); display:flex;flex-direction:column; }
    .thumb { height:180px;background:#f5f7fb;display:flex;align-items:center;justify-content:center; }
    .thumb img { max-width:100%; max-height:100%; object-fit:cover; display:block; }
    .card-body{padding:12px;flex:1;display:flex;flex-direction:column;gap:8px}
    .car-title{font-weight:800;margin:0;font-size:1.05rem}
    .meta{color:#6b7280;font-size:0.95rem}
    .price{font-weight:800;color:#0b57a4;font-size:1.05rem}
    .badges{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:auto}
    .badge{padding:6px 10px;border-radius:999px;color:#fff;font-weight:700}
    .badge.ok{background:#15803d} .badge.rej{background:#ef4444} .badge.pending{background:#b45309}
    .card-footer{padding:10px;border-top:1px solid #f1f3f6;display:flex;justify-content:space-between;align-items:center;gap:8px}
    .actions a,.actions button { text-decoration:none;padding:8px 10px;border-radius:8px;background:#eef6ff;color:#0b57a4;font-weight:700;border:1px solid rgba(11,87,164,0.08); cursor:pointer }
    .actions .edit{background:#fff7ed;color:#a16207;border:1px solid rgba(161,98,7,0.08);}
    .actions .del{background:#fff6f6;color:#ef4444;border:1px solid rgba(239,68,68,0.06);}
    .empty{background:#fff;padding:28px;border-radius:10px;text-align:center;box-shadow:0 8px 24px rgba(2,6,23,0.04)}
    .notice{padding:10px;border-radius:8px;margin-bottom:12px}
    .notice.ok{background:#eafaf0;border:1px solid #cfead1;color:#116530}
    .notice.err{background:#fff6f6;border:1px solid #f5c2c2;color:#8a1f1f}
  </style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<div class="page">
  <div class="topbar-row">
    <h1 class="title">Мои объявления — Авто</h1>
    <div class="tools">
      <a href="/mehanik/public/add-car.php" class="btn">➕ Добавить авто</a>
    </div>
  </div>

  <?php if ($msg): ?><div class="notice ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="notice err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <div class="layout">
    <aside class="sidebar" aria-label="Фильтр автомобилей">
      <div style="display:flex;gap:8px;align-items:center;justify-content:space-between">
        <strong>Фильтр</strong>
        <label style="font-weight:700;font-size:.95rem"><input id="onlyMine" type="checkbox" checked> Только мои</label>
      </div>

      <div class="form-row">
        <label for="vehicle_type">Тип ТС</label>
        <select id="vehicle_type"><option value="">Все типы</option></select>
      </div>

      <div class="form-row">
        <label for="vehicle_body">Кузов</label>
        <select id="vehicle_body"><option value="">Все кузова</option></select>
      </div>

      <div class="form-row">
        <label for="brand">Бренд</label>
        <select id="brand"><option value="">Все бренды</option></select>
      </div>

      <div class="form-row">
        <label for="model">Модель</label>
        <select id="model" disabled><option value="">Сначала выберите бренд</option></select>
      </div>

      <div class="form-row" style="flex-direction:row;gap:8px;">
        <div style="flex:1">
          <label for="year_from">Год (от)</label>
          <input type="number" id="year_from" min="1900" max="2050">
        </div>
        <div style="flex:1">
          <label for="year_to">Год (до)</label>
          <input type="number" id="year_to" min="1900" max="2050">
        </div>
      </div>

      <div class="form-row">
        <label for="fuel_type">Тип топлива</label>
        <select id="fuel_type"><option value="">Любое</option></select>
      </div>

      <div class="form-row">
        <label for="gearbox">Коробка передач</label>
        <select id="gearbox"><option value="">Любая</option></select>
      </div>

      <div class="form-row" style="flex-direction:row;gap:8px;">
        <div style="flex:1">
          <label for="price_from">Цена (от)</label>
          <input type="number" id="price_from" min="0">
        </div>
        <div style="flex:1">
          <label for="price_to">Цена (до)</label>
          <input type="number" id="price_to" min="0">
        </div>
      </div>

      <div class="form-row">
        <label for="search">Поиск (название / ID)</label>
        <input id="search" placeholder="например: Тойота или 123">
      </div>

      <div class="controls-row">
        <button id="clearFilters" class="btn-ghost">Сбросить</button>
        <div style="flex:1;color:#6b7280">Фильтры применяются автоматически.</div>
      </div>
    </aside>

    <section aria-live="polite">
      <div id="products" class="products">
        <!-- карточки будут подгружаться через JS -->
      </div>
    </section>
  </div>
</div>

<script>
window.currentUserId = <?= json_encode($user_id) ?>;
window.uploadsPrefix = <?= json_encode($uploadsPrefix) ?>;
window.noPhoto = <?= json_encode($noPhoto) ?>;

// устаревший fetchJSON-помощник
window.fetchJSON = async function(url, opts = {}) {
  try {
    const resp = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts));
    if (!resp.ok) return null;
    return await resp.json();
  } catch (e) { return null; }
};
</script>

<script src="/mehanik/assets/js/productList.js"></script>

<script>
(function(){
  // элементы
  const brandEl = document.getElementById('brand');
  const modelEl = document.getElementById('model');
  const vehicleTypeEl = document.getElementById('vehicle_type');
  const vehicleBodyEl = document.getElementById('vehicle_body');
  const fuelTypeEl = document.getElementById('fuel_type');
  const gearboxEl = document.getElementById('gearbox');
  const yearFromEl = document.getElementById('year_from');
  const yearToEl = document.getElementById('year_to');
  const priceFromEl = document.getElementById('price_from');
  const priceToEl = document.getElementById('price_to');
  const searchEl = document.getElementById('search');
  const onlyMineEl = document.getElementById('onlyMine');
  const clearBtn = document.getElementById('clearFilters');
  const container = document.getElementById('products');

  // локальное хранилище lookup'ов
  let lookups = { brands: [], modelsByBrand: {}, vehicle_types: [], vehicle_bodies: [], fuel_types: [], gearboxes: [] };

  function setSelectOptions(sel, items, placeholderText=''){
    if (!sel) return;
    const prev = sel.value;
    sel.innerHTML = '';
    const o0 = document.createElement('option'); o0.value = ''; o0.textContent = placeholderText || '—'; sel.appendChild(o0);
    if (!items || !items.length) return;
    for (const it of items) {
      const val = (typeof it === 'object') ? (it.id ?? it.value ?? it.key ?? it.name) : it;
      const label = (typeof it === 'object') ? (it.name ?? it.label ?? it.value) : it;
      const opt = document.createElement('option'); opt.value = String(val); opt.textContent = String(label); sel.appendChild(opt);
    }
    if (prev && Array.from(sel.options).some(o=>o.value===prev)) sel.value = prev; else sel.selectedIndex = 0;
  }

  function updateModelOptions(brandKey){
    if (!brandKey) { modelEl.innerHTML = '<option value="">Сначала выберите бренд</option>'; modelEl.disabled = true; return; }
    const models = lookups.modelsByBrand[brandKey] || [];
    setSelectOptions(modelEl, models, 'Все модели'); modelEl.disabled = false;
  }

  function mergeLookups(data){
    if (!data) return;
    if (Array.isArray(data.brands)) lookups.brands = data.brands;
    // models -> modelsByBrand
    lookups.modelsByBrand = {};
    const models = data.models ?? data.model_list ?? [];
    if (Array.isArray(models)){
      for (const m of models){
        const b = String(m.brand_id ?? m.brand ?? '');
        const name = m.name ?? m.model ?? '';
        if (!b || !name) continue;
        if (!lookups.modelsByBrand[b]) lookups.modelsByBrand[b] = [];
        lookups.modelsByBrand[b].push({ id: m.id ?? name, name: name });
      }
      for (const k in lookups.modelsByBrand){
        const seen = new Set(); lookups.modelsByBrand[k] = lookups.modelsByBrand[k].filter(x=>{ if (seen.has(x.name)) return false; seen.add(x.name); return true; });
      }
    }
    if (Array.isArray(data.vehicle_types)) lookups.vehicle_types = data.vehicle_types;
    if (Array.isArray(data.vehicle_bodies)) lookups.vehicle_bodies = data.vehicle_bodies;
    if (Array.isArray(data.fuel_types)) lookups.fuel_types = data.fuel_types;
    if (Array.isArray(data.gearboxes)) lookups.gearboxes = data.gearboxes;
  }

  async function loadLookups(){
    // попытка получить lookups через глобальный productList
    if (window.productList && productList.lookups) mergeLookups(productList.lookups);
    // fallback — API
    if (!lookups.brands.length){
      try{
        const resp = await fetch('/mehanik/api/products.php?type=auto', { credentials:'same-origin' });
        if (resp.ok){ const data = await resp.json(); mergeLookups(data.lookups ?? data); }
      }catch(e){}
    }
    setSelectOptions(brandEl, lookups.brands, 'Все бренды');
    setSelectOptions(vehicleTypeEl, lookups.vehicle_types, 'Все типы');
    setSelectOptions(vehicleBodyEl, lookups.vehicle_bodies, 'Все кузова');
    setSelectOptions(fuelTypeEl, lookups.fuel_types, 'Любое');
    setSelectOptions(gearboxEl, lookups.gearboxes, 'Любая');
    updateModelOptions(brandEl.value);
  }

  function collectFilters(){
    const getVal=v=>v?String(v.value).trim():'';
    const filters = {
      type: 'auto',
      mine: onlyMineEl.checked ? '1' : '0',
      brand: getVal(brandEl), model: getVal(modelEl), vehicle_type: getVal(vehicleTypeEl), vehicle_body: getVal(vehicleBodyEl),
      fuel_type: getVal(fuelTypeEl), gearbox: getVal(gearboxEl), year_from: getVal(yearFromEl), year_to: getVal(yearToEl),
      price_from: getVal(priceFromEl), price_to: getVal(priceToEl), q: getVal(searchEl)
    };
    // remove empty
    Object.keys(filters).forEach(k=>{ if (filters[k]==='') delete filters[k]; });
    return filters;
  }

  async function applyFilters(){
    const filters = collectFilters();
    // prefer productList.loadProducts if available
    if (window.productList && typeof productList.loadProducts === 'function'){
      try{ await productList.loadProducts(filters); return; }catch(e){ console.warn('productList.loadProducts error', e); }
    }

    // fallback fetch
    try{
      const params = new URLSearchParams(filters);
      const resp = await fetch('/mehanik/api/products.php?'+params.toString(), { credentials:'same-origin' });
      if (resp.ok){
        const json = await resp.json();
        const items = json.products ?? json.items ?? json;
        renderProducts(Array.isArray(items)?items:[]);
      }
    }catch(e){ console.warn(e); }
  }

  function renderProducts(items){
    container.innerHTML = '';
    if (!items || !items.length) { container.innerHTML = '<div class="empty"><h3 style="margin:0">По вашему запросу ничего не найдено</h3></div>'; return; }

    for (const it of items){
      const card = document.createElement('article'); card.className = 'card';
      const thumb = document.createElement('div'); thumb.className = 'thumb';
      const a = document.createElement('a'); a.href = '/mehanik/public/car.php?id='+encodeURIComponent(it.id);
      const img = document.createElement('img'); img.alt = it.brand + ' ' + (it.model||'');
      img.src = (it.photo && (it.photo.indexOf('/')===0 || /^https?:\/\//i.test(it.photo))) ? it.photo : (it.photo ? (window.uploadsPrefix + it.photo) : window.noPhoto);
      a.appendChild(img); thumb.appendChild(a);

      const body = document.createElement('div'); body.className = 'card-body';
      const row = document.createElement('div'); row.style.display='flex'; row.style.justifyContent='space-between'; row.style.gap='12px';
      const left = document.createElement('div'); left.style.flex='1';
      const title = document.createElement('div'); title.className='car-title'; title.textContent = (it.brand||'') + ' ' + (it.model||'');
      const meta = document.createElement('div'); meta.className='meta'; meta.textContent = ((it.year)?(it.year+' г. · '):'') + ((it.mileage)?(Number(it.mileage).toLocaleString()+' км · '):'') + (it.body||'-');
      left.appendChild(title); left.appendChild(meta);
      const right = document.createElement('div'); right.style.textAlign='right';
      const price = document.createElement('div'); price.className='price'; price.textContent = (it.price ? (Number(it.price).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits:2}) + ' TMT') : '-');
      const idMeta = document.createElement('div'); idMeta.className='meta'; idMeta.style.marginTop='8px'; idMeta.style.fontSize='.9rem'; idMeta.textContent = 'ID: ' + (it.id||'-');
      right.appendChild(price); right.appendChild(idMeta);
      row.appendChild(left); row.appendChild(right);

      body.appendChild(row);

      const badges = document.createElement('div'); badges.className='badges';
      const status = document.createElement('div'); status.className='badge ' + ((it.status==='approved')? 'ok' : (it.status==='rejected'? 'rej':'pending'));
      status.textContent = (it.status==='approved')? 'Подтверждён' : (it.status==='rejected'? 'Отклонён' : 'На модерации');
      const added = document.createElement('div'); added.className='meta'; added.style.background='#f3f5f8'; added.style.padding='6px 8px'; added.style.borderRadius='8px'; added.style.color='#334155'; added.textContent = 'Добавлен: ' + (it.created_at? new Date(it.created_at).toLocaleDateString() : '-');
      badges.appendChild(status); badges.appendChild(added);
      body.appendChild(badges);

      const footer = document.createElement('div'); footer.className='card-footer';
      const actions = document.createElement('div'); actions.className='actions';
      const view = document.createElement('a'); view.href = '/mehanik/public/car.php?id='+encodeURIComponent(it.id); view.textContent = '👁 Просмотр'; actions.appendChild(view);
      const edit = document.createElement('a'); edit.href = '/mehanik/public/edit-car.php?id='+encodeURIComponent(it.id); edit.className='edit'; edit.textContent = '✏ Редактировать';

      // Показать кнопки редактирования/удаления только если currentUserId === it.user_id
      if (String(it.user_id) === String(window.currentUserId)){
        actions.appendChild(edit);
        const delBtn = document.createElement('button'); delBtn.type='button'; delBtn.className='del'; delBtn.textContent='🗑 Удалить';
        delBtn.addEventListener('click', async function(){
          if (!confirm('Удалить авто «'+ (it.brand||'') + ' ' + (it.model||'') + '»?')) return;
          try{
            const fd = new FormData(); fd.append('id', it.id);
            const resp = await fetch('/mehanik/api/delete-car.php', { method:'POST', credentials:'same-origin', body: fd });
            if (resp.ok){ const j = await resp.json(); if (j && j.success) { alert('Удалено'); applyFilters(); } else { alert(j && j.error ? j.error : 'Ошибка при удалении'); } }
            else { alert('Ошибка сети'); }
          }catch(e){ alert('Ошибка: '+e.message); }
        });
        actions.appendChild(delBtn);
      }

      footer.appendChild(actions);
      // owner badge (hidden for others)
      const ownerWrap = document.createElement('div'); ownerWrap.style.textAlign='right'; ownerWrap.style.fontSize='.85rem'; ownerWrap.style.color='#6b7280'; ownerWrap.textContent = '';
      footer.appendChild(ownerWrap);

      card.appendChild(thumb); card.appendChild(body); card.appendChild(footer);
      container.appendChild(card);
    }
  }

  // события
  brandEl.addEventListener('change', function(){ updateModelOptions(this.value); applyFilters(); });
  modelEl.addEventListener('change', applyFilters);
  [vehicleTypeEl, vehicleBodyEl, fuelTypeEl, gearboxEl, yearFromEl, yearToEl, priceFromEl, priceToEl].forEach(el=>{ if(!el) return; el.addEventListener('change', applyFilters); });
  searchEl.addEventListener('input', (function(){ let t; return function(){ clearTimeout(t); t=setTimeout(()=>applyFilters(),300); }; })());
  onlyMineEl.addEventListener('change', applyFilters);
  clearBtn.addEventListener('click', function(e){ e.preventDefault(); [brandEl,modelEl,vehicleTypeEl,vehicleBodyEl,fuelTypeEl,gearboxEl,yearFromEl,yearToEl,priceFromEl,priceToEl,searchEl].forEach(el=>{ if(!el) return; if(el.tagName.toLowerCase()==='select') el.selectedIndex=0; else el.value=''; }); onlyMineEl.checked=true; updateModelOptions(''); applyFilters(); });

  // инициализация
  (async function init(){
    await loadLookups();
    // по умолчанию - показываем только мои (так как это страница Мои объявления)
    applyFilters();
  })();

})();
</script>

</body>
</html>
