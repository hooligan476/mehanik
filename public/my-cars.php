<?php
// public/my-cars.php 
require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php';

// ID текущего пользователя (если нужно в шаблоне)
$user_id = (int)($_SESSION['user']['id'] ?? 0);

$noPhoto = '/mehanik/assets/no-photo.png';
$uploadsPrefix = '/mehanik/uploads/cars/';
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Автомобили — Mehanik</title>

  <style>
/* --- (стили оставлены без изменений, как в твоём шаблоне) --- */
:root{ --bg:#f6f8fb; --card-bg:#fff; --muted:#6b7280; --accent:#0b57a4; --danger:#ef4444; --ok:#15803d; --pending:#b45309; --glass:rgba(255,255,255,0.6); --radius:10px; }
*{box-sizing:border-box}
html,body{height:100%;margin:0;background:var(--bg);font-family:system-ui, Arial, sans-serif;color:#0f172a}
.page-wrap{max-width:1200px;margin:18px auto;padding:12px}
.topbar-row{display:flex;gap:12px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
.page-title{margin:0;font-size:1.25rem;display:flex;align-items:center;gap:12px;font-weight:700}
.tools{margin-left:auto;display:flex;gap:8px;align-items:center}
.layout{display:grid;grid-template-columns:320px 1fr;gap:18px}
@media(max-width:1000px){.layout{grid-template-columns:1fr}}
.sidebar{background:var(--card-bg);padding:14px;border-radius:12px;box-shadow:0 8px 24px rgba(2,6,23,0.04)}
.form-row{margin-top:10px;display:flex;flex-direction:column;gap:6px}
.form-row label{font-weight:700;color:#334155}
.form-row select,.form-row input{padding:8px;border-radius:8px;border:1px solid #e6eef7;background:linear-gradient(#fff,#fbfdff)}
.controls-row{display:flex;gap:8px;align-items:center;margin-top:12px}
#cars, .cars{display:flex;flex-direction:column;gap:10px;padding:6px 0}
.car-card, .card{display:flex;flex-direction:row;align-items:center;gap:12px;padding:10px;border-radius:12px;background:var(--card-bg);box-shadow:0 6px 18px rgba(2,6,23,0.06);border:1px solid rgba(15,23,42,0.04);transition:transform .12s ease,box-shadow .12s ease;overflow:hidden}
.car-card:hover,.card:hover{transform:translateY(-6px);box-shadow:0 14px 30px rgba(2,6,23,0.10)}
.thumb, .card img{flex:0 0 140px;width:140px;height:84px;border-radius:8px;overflow:hidden;background:#f7f9fc;display:flex;align-items:center;justify-content:center}
.thumb img, .card img{width:auto;height:100%;object-fit:contain;display:block}
.card-body, .product-content{padding:0 6px 0 0;flex:1 1 auto;min-width:0;display:flex;flex-direction:column;gap:4px}
.title{font-size:1rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#0f172a}
.product-sub,.meta{font-size:0.88rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sku-wrap{display:flex;align-items:center;gap:8px;margin-top:4px}
.sku-link{font-weight:600;color:var(--accent);text-decoration:underline;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.btn-copy-sku{padding:4px 8px;border-radius:6px;border:1px solid #e6e9ef;background:#fff;cursor:pointer;font-size:0.9rem}
.price-row{display:flex;justify-content:space-between;align-items:center;gap:12px}
.price{font-weight:800;font-size:0.98rem;color:var(--accent);white-space:nowrap}
.badges{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:4px}
.badge{padding:5px 8px;border-radius:999px;font-weight:700;font-size:0.78rem;color:#fff}
.badge.ok{background:var(--ok)}.badge.rej{background:var(--danger)}.badge.pending{background:var(--pending)}
.badges .meta{background:#f3f5f8;padding:6px 8px;border-radius:8px;color:#334155}
.card-footer{border-top:0;padding:0;margin-left:12px;display:flex;gap:8px;align-items:center;justify-content:flex-end;min-width:170px;flex:0 0 auto}
.actions{display:flex;gap:8px;align-items:center}
.actions a,.actions button{padding:6px 8px;border-radius:8px;border:0;cursor:pointer;font-size:0.88rem}
.btn-view{background:#eef6ff;color:var(--accent);border:1px solid rgba(11,87,164,0.06)}
.no-cars{text-align:center;padding:28px;border-radius:10px;background:var(--card-bg);box-shadow:0 6px 18px rgba(2,6,23,0.04);color:var(--muted)}
@media(max-width:700px){ .car-card,.card{flex-direction:column;align-items:stretch;padding:12px} .thumb{width:100%;height:200px;flex:0 0 auto} .card-footer{margin-left:0;justify-content:flex-start;padding-top:8px} }
.muted{color:var(--muted);padding:12px}
.notice{padding:10px;border-radius:8px;margin-bottom:10px}
.notice.ok{background:#ecfdf5;color:#065f46}
.notice.err{background:#fff1f2;color:#9f1239}
  </style>
</head>
<body>

<?php require_once __DIR__ . '/header.php'; ?>

<div class="page-wrap">
  <div class="topbar-row">
    <h2 class="page-title">Автомобили</h2>
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
      </div>

      <div class="form-row">
        <label for="vehicle_type">Тип ТС</label>
        <select id="vehicle_type"><option value="">Все типы</option></select>
      </div>

      <div class="form-row">
        <label for="vehicle_body">Кузов</label>
        <select id="vehicle_body" disabled><option value="">Сначала выберите тип</option></select>
      </div>

      <div class="form-row">
        <label for="brand_car">Бренд</label>
        <select id="brand_car"><option value="">Все бренды</option></select>
      </div>

      <div class="form-row">
        <label for="model_car">Модель</label>
        <select id="model_car" disabled><option value="">Сначала выберите бренд</option></select>
      </div>

      <div class="form-row" style="flex-direction:row;gap:8px;">
        <div style="flex:1">
          <label for="year_from">Год (от)</label>
          <input id="year_from" type="number" min="1900" max="2050" placeholder="например 2005">
        </div>
        <div style="flex:1">
          <label for="year_to">Год (до)</label>
          <input id="year_to" type="number" min="1900" max="2050" placeholder="например 2020">
        </div>
      </div>

      <div class="form-row">
        <label for="fuel">Тип топлива</label>
        <select id="fuel"><option value="">Любое</option></select>
      </div>

      <div class="form-row">
        <label for="transmission">Коробка передач</label>
        <select id="transmission"><option value="">Любая</option></select>
      </div>

      <div class="form-row" style="flex-direction:row;gap:8px;">
        <div style="flex:1"><label for="price_from">Цена (от)</label><input id="price_from" type="number" min="0"></div>
        <div style="flex:1"><label for="price_to">Цена (до)</label><input id="price_to" type="number" min="0"></div>
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
      <div id="cars" class="grid">
        <!-- карточки подгружаются через JS -->
      </div>
    </section>
  </div>
</div>

<script>
window.currentUserId = <?= json_encode($user_id) ?>;
window.uploadsPrefix = <?= json_encode($uploadsPrefix) ?>;
window.noPhoto = <?= json_encode($noPhoto) ?>;

window.fetchJSON = async function(url, opts = {}){ try{ const resp = await fetch(url, Object.assign({credentials:'same-origin'}, opts)); if (!resp.ok) return null; return await resp.json(); }catch(e){ return null; } };
</script>

<script>
(function(){
  const brandEl = document.getElementById('brand_car');
  const modelEl = document.getElementById('model_car');
  const typeEl = document.getElementById('vehicle_type');
  const bodyEl = document.getElementById('vehicle_body');
  const yearFromEl = document.getElementById('year_from');
  const yearToEl = document.getElementById('year_to');
  const transmissionEl = document.getElementById('transmission');
  const fuelEl = document.getElementById('fuel');
  const priceFromEl = document.getElementById('price_from');
  const priceToEl = document.getElementById('price_to');
  const searchEl = document.getElementById('search');
  const clearBtn = document.getElementById('clearFilters');
  const container = document.getElementById('cars');

  let lookups = {};
  let lookupsLoaded = false;
  let lastItems = [];           // cache last successful result
  let currentRequestId = 0;     // token to ignore stale responses
  let debounceTimer = null;

  function optionValue(obj){
    if (!obj) return '';
    if (typeof obj === 'object') {
      if (obj.id !== undefined && obj.id !== null && obj.id !== '') return String(obj.id);
      if (obj.key !== undefined && obj.key !== null && obj.key !== '') return String(obj.key);
      if (obj.name !== undefined) return String(obj.name);
    }
    return String(obj);
  }
  function optionLabel(obj){
    if (!obj) return '';
    if (typeof obj === 'object') return String(obj.name ?? obj.label ?? obj.value ?? obj.id);
    return String(obj);
  }

  function setSelectOptions(sel, items, placeholder){
    if(!sel) return;
    const prev = sel.value;
    sel.innerHTML = '';
    const o0 = document.createElement('option'); o0.value=''; o0.textContent = placeholder || '—'; sel.appendChild(o0);
    if (!Array.isArray(items) || !items.length) { sel.selectedIndex = 0; return; }
    for(const it of items){
      const val = optionValue(it);
      const lab = optionLabel(it);
      const opt = document.createElement('option');
      opt.value = val;
      opt.textContent = lab;
      if (typeof it === 'object' && it.name) opt.dataset.name = it.name;
      sel.appendChild(opt);
    }
    if (prev && Array.from(sel.options).some(o => o.value === prev)) sel.value = prev;
    else sel.selectedIndex = 0;
  }

  function buildModelsMap(modelsRaw){
    const map = {};
    if (!Array.isArray(modelsRaw)) return map;
    for(const m of modelsRaw){
      const bid = String(m.brand_id ?? m.brand ?? '');
      if (!bid) continue;
      if (!map[bid]) map[bid] = [];
      map[bid].push({ id: m.id ?? m.name, name: m.name ?? m.model ?? m.id });
    }
    for(const k in map){
      const seen = new Set();
      map[k] = map[k].filter(x => { if(seen.has(x.name)) return false; seen.add(x.name); return true; });
    }
    return map;
  }

  function buildBodiesMap(bodiesRaw){
    const map = {};
    if (!Array.isArray(bodiesRaw)) return map;
    for(const b of bodiesRaw){
      const tid = String(b.vehicle_type_id ?? b.type_id ?? b.type ?? '');
      if (!tid) continue;
      if (!map[tid]) map[tid] = [];
      map[tid].push({ id: b.id ?? b.name, name: b.name });
    }
    for(const k in map){
      const seen = new Set();
      map[k] = map[k].filter(x => { if(seen.has(x.name)) return false; seen.add(x.name); return true; });
    }
    return map;
  }

  function mergeLookups(resp){
    if(!resp) return;
    const data = resp.lookups ?? resp;
    lookups.brands = Array.isArray(data.brands) ? data.brands : [];
    lookups.models = Array.isArray(data.models) ? data.models : [];
    lookups.vehicle_types = Array.isArray(data.vehicle_types) ? data.vehicle_types : [];
    lookups.vehicle_bodies = Array.isArray(data.vehicle_bodies) ? data.vehicle_bodies : [];
    lookups.fuel_types = Array.isArray(data.fuel_types) ? data.fuel_types : (Array.isArray(data.fuel) ? data.fuel : []);
    lookups.gearboxes = Array.isArray(data.gearboxes) ? data.gearboxes : [];
    lookups.modelsByBrand = buildModelsMap(lookups.models);
    lookups.bodiesByType = buildBodiesMap(lookups.vehicle_bodies);
    lookupsLoaded = true;
  }

  async function loadLookupsOnce(){
    if (window.carList && window.carList.lookups) mergeLookups(window.carList.lookups);
    if (!lookupsLoaded) {
      try {
        const resp = await fetch('/mehanik/api/cars.php', { credentials: 'same-origin' });
        if (resp && resp.ok) {
          const json = await resp.json();
          mergeLookups(json.lookups ?? json);
          // If server returns cars in top-level, we ignore them here (they will be loaded later)
        }
      } catch(err){
        console.warn('loadLookupsOnce error', err);
      }
    }
    // populate selects
    setSelectOptions(typeEl, lookups.vehicle_types, 'Все типы');
    if (bodyEl) { bodyEl.innerHTML = '<option value="">Сначала выберите тип</option>'; bodyEl.disabled = true; }
    setSelectOptions(brandEl, lookups.brands, 'Все бренды');
    if (modelEl) { modelEl.innerHTML = '<option value="">Сначала выберите бренд</option>'; modelEl.disabled = true; }
    setSelectOptions(fuelEl, lookups.fuel_types, 'Любое');
    if (lookups.gearboxes && lookups.gearboxes.length && transmissionEl) setSelectOptions(transmissionEl, lookups.gearboxes, 'Любая');
  }

  // collect filters and send aliases accepted by API
  function collectFilters(){
    const f = {};
    const setIf = (key, el) => { if(!el) return; const v = String(el.value || '').trim(); if (v !== '') f[key] = v; };

    setIf('vehicle_type', typeEl);
    setIf('vehicle_body', bodyEl);

    // brand/model: API expects 'brand' and 'model'
    setIf('brand', brandEl);
    setIf('model', modelEl);

    setIf('year_from', yearFromEl);
    setIf('year_to', yearToEl);

    // transmission: send both gearbox (older API) and transmission (DB column)
    if (transmissionEl && transmissionEl.value) {
      f.transmission = String(transmissionEl.value).trim();
      f.gearbox = String(transmissionEl.value).trim();
    }

    // fuel: send both variants
    if (fuelEl && fuelEl.value) {
      f.fuel = String(fuelEl.value).trim();
      f.fuel_type = String(fuelEl.value).trim();
    }

    setIf('price_from', priceFromEl);
    setIf('price_to', priceToEl);

    const qv = (searchEl && searchEl.value) ? searchEl.value.trim() : '';
    if (qv) f.q = qv;

    return f;
  }

  function renderCars(items){
    if (!Array.isArray(items)) items = [];
    lastItems = items;
    container.innerHTML = '';
    if (!items.length) {
      container.innerHTML = '<div class="no-cars"><p style="font-weight:700;margin:0 0 8px;">По вашему запросу ничего не найдено</p><p style="margin:0;color:#6b7280">Попробуйте изменить фильтры.</p></div>';
      return;
    }
    const frag = document.createDocumentFragment();
    for (const it of items) {
      const card = document.createElement('article');
      card.className = 'car-card';
      card.dataset.id = String(it.id || '');

      const thumb = document.createElement('div'); thumb.className='thumb';
      const a = document.createElement('a'); a.href = '/mehanik/public/car.php?id='+encodeURIComponent(it.id);
      a.style.display='block'; a.style.width='100%'; a.style.height='100%';
      const img = document.createElement('img'); img.alt = (((it.brand||'') + ' ' + (it.model||'')).trim());
      img.src = (it.photo && (it.photo.indexOf('/')===0 || /^https?:\/\//i.test(it.photo))) ? it.photo : (it.photo ? (window.uploadsPrefix + it.photo) : window.noPhoto);
      a.appendChild(img); thumb.appendChild(a);

      const body = document.createElement('div'); body.className='card-body';
      const top = document.createElement('div'); top.className='price-row';
      const left = document.createElement('div'); left.style.flex='1'; left.style.minWidth='0';
      const title = document.createElement('div'); title.className='title';
      title.textContent = (((it.brand||'') + ' ' + (it.model||'')).trim() + (it.year ? ' '+it.year : ''));
      const meta = document.createElement('div'); meta.className='meta';
      meta.textContent = ((it.transmission ? it.transmission + ' • ' : '') + (it.fuel ? it.fuel : '')).trim() || (it.vin ? 'VIN: '+it.vin : '-');
      left.appendChild(title); left.appendChild(meta);

      const rawVin = (it.vin || it.sku || '').toString();
      const displayVin = rawVin.replace(/^VIN-/i, '').trim();
      if (displayVin) {
        const skuWrap = document.createElement('div'); skuWrap.className='sku-wrap';
        const skuLink = document.createElement('a'); skuLink.className='sku-link'; skuLink.href = '/mehanik/public/car.php?id='+encodeURIComponent(it.id); skuLink.textContent = displayVin;
        skuWrap.appendChild(skuLink);
        const copyBtn = document.createElement('button'); copyBtn.type='button'; copyBtn.className='btn-copy-sku'; copyBtn.textContent='📋';
        copyBtn.addEventListener('click', function(e){ e.preventDefault(); if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(displayVin).then(()=>{ const prev=copyBtn.textContent; copyBtn.textContent='✓'; setTimeout(()=>copyBtn.textContent=prev,1200); }).catch(()=>{}); } });
        skuWrap.appendChild(copyBtn);
        left.appendChild(skuWrap);
      }

      const right = document.createElement('div'); right.style.textAlign='right'; right.style.minWidth='140px';
      const price = document.createElement('div'); price.className='price';
      price.textContent = it.price ? (Number(it.price).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2}) + ' TMT') : '-';
      const idMeta = document.createElement('div'); idMeta.className='meta'; idMeta.style.marginTop='6px'; idMeta.style.fontSize='.85rem'; idMeta.textContent = 'ID: ' + (it.id||'-');
      right.appendChild(price); right.appendChild(idMeta);

      top.appendChild(left); top.appendChild(right);
      body.appendChild(top);

      const badges = document.createElement('div'); badges.className='badges';
      const statusText = (it.status==='active' || it.status==='approved')? 'В продаже' : (String(it.status).toLowerCase().includes('sold')? 'Продан':'На модерации');
      const status = document.createElement('div'); status.className='badge ' + ((it.status==='active' || it.status==='approved')? 'ok' : (String(it.status).toLowerCase().includes('sold')? 'rej':'pending'));
      status.textContent = statusText;
      const mileage = document.createElement('div'); mileage.className='meta'; mileage.style.background='#f3f5f8'; mileage.style.padding='6px 8px'; mileage.style.borderRadius='8px'; mileage.style.color='#334155'; mileage.textContent = 'Пробег: ' + (it.mileage ? (String(it.mileage) + ' км') : '-');
      const added = document.createElement('div'); added.className='meta'; added.style.background='#f3f5f8'; added.style.padding='6px 8px'; added.style.borderRadius='8px'; added.style.color='#334155'; added.textContent = 'Добавлен: ' + (it.created_at ? new Date(it.created_at).toLocaleDateString() : '-');
      badges.appendChild(status); badges.appendChild(mileage); badges.appendChild(added);
      body.appendChild(badges);

      const footer = document.createElement('div'); footer.className='card-footer';
      const actions = document.createElement('div'); actions.className='actions';
      const view = document.createElement('a'); view.className='btn-view'; view.href = '/mehanik/public/car.php?id='+encodeURIComponent(it.id); view.textContent = '👁 Просмотр';
      actions.appendChild(view);
      footer.appendChild(actions);

      card.appendChild(thumb); card.appendChild(body); card.appendChild(footer);
      frag.appendChild(card);
    }
    container.appendChild(frag);
  }

  // apply filters with request token, keep lastItems on failure
  async function applyFilters(){
    if (!lookupsLoaded) {
      // if lookups not ready, still try (do not block)
      console.debug('applyFilters: lookups not loaded yet, still attempting to fetch.');
    }
    const reqId = ++currentRequestId;
    const filters = collectFilters();

    // debugging: show built query params
    console.debug('cars: applying filters', filters);

    // prefer global loader if exists
    if (window.carList && typeof carList.loadCars === 'function') {
      try { await carList.loadCars(filters); return; } catch(e){ console.warn('carList.loadCars failed', e); }
    }

    try {
      const params = new URLSearchParams(filters);
      const url = '/mehanik/api/cars.php?' + params.toString();
      console.debug('cars: requesting', url);
      const resp = await fetch(url, { credentials: 'same-origin' });
      if (reqId !== currentRequestId) { console.debug('cars: stale response ignored'); return; }
      if (!resp.ok) { console.warn('cars fetch not ok', resp.status); return; }
      const json = await resp.json();
      console.debug('cars: server response', json);
      if (reqId !== currentRequestId) return;
      const items = json.cars ?? json.products ?? json.items ?? json;
      if (Array.isArray(items)) renderCars(items);
      else {
        const arr = json.cars ?? json.products ?? json.items;
        if (Array.isArray(arr)) renderCars(arr);
        else {
          console.warn('cars: unexpected payload', json);
          // keep previous list instead of clearing
        }
      }

      // if API returned lookups update local lookups
      if (json.lookups) {
        mergeLookups(json.lookups);
        const curBrand = brandEl ? brandEl.value : '';
        setSelectOptions(brandEl, lookups.brands, 'Все бренды');
        if (curBrand) brandEl.value = curBrand;
        updateModelSelectAfterBrand(curBrand);
      }
    } catch(err){
      console.warn('applyFilters error', err);
    }
  }

  function updateModelSelectAfterBrand(brandVal){
    if (!modelEl) return;
    if (!brandVal) {
      modelEl.innerHTML = '<option value="">Сначала выберите бренд</option>';
      modelEl.disabled = true;
      return;
    }
    const models = lookups.modelsByBrand && lookups.modelsByBrand[brandVal] ? lookups.modelsByBrand[brandVal] : [];
    setSelectOptions(modelEl, models, 'Все модели');
    modelEl.disabled = false;
  }

  function updateBodySelectAfterType(typeVal){
    if (!bodyEl) return;
    if (!typeVal) {
      bodyEl.innerHTML = '<option value="">Сначала выберите тип</option>';
      bodyEl.disabled = true;
      return;
    }
    const list = lookups.bodiesByType && lookups.bodiesByType[typeVal] ? lookups.bodiesByType[typeVal] : [];
    setSelectOptions(bodyEl, list, 'Все кузова');
    bodyEl.disabled = false;
  }

  function scheduleApply(){
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(()=>{ applyFilters(); debounceTimer = null; }, 220);
  }

  // events wiring
  if (typeEl) typeEl.addEventListener('change', function(){ updateBodySelectAfterType(this.value); scheduleApply(); });
  if (brandEl) brandEl.addEventListener('change', function(){ updateModelSelectAfterBrand(this.value); scheduleApply(); });
  if (modelEl) modelEl.addEventListener('change', scheduleApply);
  if (bodyEl) bodyEl.addEventListener('change', scheduleApply);
  [yearFromEl, yearToEl, transmissionEl, fuelEl, priceFromEl, priceToEl].forEach(el => { if (!el) return; el.addEventListener('change', scheduleApply); });
  if (searchEl) searchEl.addEventListener('input', function(){ if (debounceTimer) clearTimeout(debounceTimer); debounceTimer = setTimeout(()=>{ applyFilters(); debounceTimer=null; }, 300); });

  if (clearBtn) clearBtn.addEventListener('click', function(e){
    e.preventDefault();
    [brandEl, modelEl, typeEl, bodyEl, yearFromEl, yearToEl, transmissionEl, fuelEl, priceFromEl, priceToEl, searchEl].forEach(el=>{
      if(!el) return;
      if(el.tagName && el.tagName.toLowerCase()==='select') el.selectedIndex = 0;
      else el.value = '';
    });
    updateModelSelectAfterBrand('');
    updateBodySelectAfterType('');
    scheduleApply();
  });

  (async function init(){
    await loadLookupsOnce();
    // try to pre-populate models/body if URL contains params (optional)
    const urlParams = new URLSearchParams(window.location.search);
    const b = urlParams.get('brand') || urlParams.get('brand_car') || '';
    const t = urlParams.get('vehicle_type') || '';
    if (b) { if (brandEl) { brandEl.value = b; updateModelSelectAfterBrand(b); } }
    if (t) { if (typeEl) { typeEl.value = t; updateBodySelectAfterType(t); } }
    // initial load
    setTimeout(()=>{ applyFilters(); }, 50);
  })();

})();
</script>

</body>
</html>
