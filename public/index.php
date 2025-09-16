<?php
//mehanik/public/index.php

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

    .layout { display: grid; grid-template-columns: 320px 1fr; gap: 24px; padding: 22px; max-width:1280px; margin: 0 auto; box-sizing:border-box; }
    .sidebar { background:var(--card-bg); padding:18px; border-radius:12px; box-shadow:var(--shadow); border:1px solid rgba(15,20,30,0.02); }
    .sidebar h3 { margin:0 0 8px 0; font-size:1.05rem; color:#111827; }
    .form-row { display:flex; flex-direction:column; gap:6px; margin-top:12px; }
    .form-row label { font-weight:600; font-size:.95rem; color:#0f1724; }
    .form-row select, .form-row input { width:100%; padding:10px 12px; border-radius:10px; border:1px solid var(--input-border); box-sizing:border-box; background:transparent; font-size:14px; color:#111827; }
    .form-row select:focus, .form-row input:focus { outline: none; box-shadow: 0 0 0 4px rgba(11,87,164,0.06); border-color: var(--accent); }

    .controls-row { display:flex; gap:8px; align-items:center; margin-top:14px; flex-wrap:wrap; }
    .btn { display:inline-block; background:var(--accent); color:#fff; padding:9px 14px; border-radius:10px; text-decoration:none; font-weight:700; cursor:pointer; border:0; box-shadow: 0 4px 10px rgba(11,87,164,0.08); }
    .btn-ghost { background:#eef2f6; color:var(--accent); border-radius:10px; padding:8px 12px; font-weight:700; border:1px solid rgba(11,87,164,0.06); cursor:pointer; }

    .hint { font-size:.92rem; color:var(--muted); margin-top:8px; }

    /* products grid and card styles */
    .products-wrap { display:block; }
    .products { display:grid; grid-template-columns: repeat(auto-fill, minmax(240px,1fr)); gap:16px; align-items:start; padding: 4px; }

    .product-card {
      background: var(--card-bg);
      border-radius:12px;
      box-shadow: 0 6px 20px rgba(8,12,20,0.04);
      border:1px solid rgba(15,20,30,0.03);
      overflow:hidden;
      display:flex;
      flex-direction:column;
      min-height:160px;
      max-height: 420px;
      transition: transform .12s ease, box-shadow .12s ease;
    }
    .product-card:hover { transform: translateY(-6px); box-shadow: 0 12px 30px rgba(10,20,40,0.08); }

    .product-media {
      width:100%;
      height:150px;
      display:block;
      background:#f3f6fa;
      object-fit:cover;
      flex-shrink:0;
    }
    .product-content {
      padding:12px;
      display:flex;
      flex-direction:column;
      gap:8px;
      flex:1 1 auto;
    }
    .product-title { font-weight:700; font-size:1rem; color:#0b1220; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .product-sub { font-size:0.92rem; color:var(--muted); }
    .product-row { display:flex; justify-content:space-between; align-items:center; gap:8px; margin-top:auto; }
    .price { font-weight:800; font-size:1.05rem; color:var(--accent); }
    .meta { font-size:0.86rem; color:var(--muted); }

    .tags { display:flex; gap:6px; flex-wrap:wrap; margin-top:6px; }
    .tag { background:#f1f5f9; padding:6px 8px; border-radius:8px; font-size:0.8rem; color:#0f1724; border:1px solid rgba(15,20,30,0.03); }

    .type-block { margin-top:12px; padding:10px; border-radius:10px; background:var(--surface); border:1px dashed rgba(2,6,23,0.03); }
    .type-switch { display:flex; gap:8px; align-items:center; justify-content:flex-start; flex-wrap:wrap; }
    .switch-btn {
      border: 1px solid transparent;
      background: #fff;
      padding:8px 12px;
      border-radius:8px;
      cursor:pointer;
      font-weight:700;
      color:#0f1724;
      display:inline-flex;
      gap:8px;
      align-items:center;
      box-shadow: 0 1px 2px rgba(2,6,23,0.03);
      transition: all .12s ease;
    }
    .switch-btn .dot { width:10px;height:10px;border-radius:50%; background:transparent; border:1px solid rgba(0,0,0,0.06); display:inline-block; }
    .switch-btn.active { background:var(--accent); color:#fff; border-color:var(--accent); transform:translateY(-1px); }
    .switch-btn.active .dot { background:#fff; border-color:transparent; }

    .switch-hint { font-size:0.85rem; color:var(--muted); margin-top:8px; }

    .group-hidden { display:none; }

    @media (max-width:1100px) {
      .layout { grid-template-columns: 1fr; padding:12px; }
      .sidebar { order:2; }
      .products-wrap { order:1; }
    }

    select[disabled] { opacity: 0.6; cursor:not-allowed; }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/header.php'; ?>

<main class="layout">
  <aside class="sidebar" aria-label="Фильтр товаров">
    <h3>Фильтр</h3>

    <!-- Переключатель: только один режим активен -->
    <div class="type-block" aria-hidden="false">
      <div style="font-weight:700;color:#0f1724;margin-bottom:8px">Показывать</div>
      <div class="type-switch" role="radiogroup" aria-label="Тип каталога">
        <button type="button" class="switch-btn" data-type="auto" role="radio" aria-checked="true" title="Показывать автомобили">
          <span class="dot" aria-hidden="true"></span> Авто
        </button>
        <button type="button" class="switch-btn" data-type="part" role="radio" aria-checked="false" title="Показывать запчасти">
          <span class="dot" aria-hidden="true"></span> Запчасть
        </button>
      </div>
      <div class="switch-hint">Выберите один режим — фильтры адаптируются под него.</div>
    </div>

    <!-- --- Группа полей для Авто --- -->
    <div id="auto-fields" class="group-hidden">
      <div class="form-row">
        <label for="vehicle_type">Тип ТС</label>
        <select id="vehicle_type" name="vehicle_type"><option value="">Все типы</option></select>
      </div>

      <div class="form-row">
        <label for="vehicle_body">Кузов</label>
        <select id="vehicle_body" name="vehicle_body"><option value="">Все кузова</option></select>
      </div>

      <div class="form-row">
        <label for="brand">Бренд</label>
        <select id="brand" name="brand"><option value="">Все бренды</option></select>
      </div>

      <div class="form-row">
        <label for="model">Модель</label>
        <select id="model" name="model" disabled><option value="">Сначала выберите бренд</option></select>
      </div>

      <div class="form-row" style="flex-direction:row;gap:8px;">
        <div style="flex:1">
          <label for="year_from">Год (от)</label>
          <input type="number" id="year_from" name="year_from" placeholder="1998" min="1900" max="2050">
        </div>
        <div style="flex:1">
          <label for="year_to">Год (до)</label>
          <input type="number" id="year_to" name="year_to" placeholder="2025" min="1900" max="2050">
        </div>
      </div>

      <div class="form-row">
        <label for="fuel_type">Тип топлива</label>
        <select id="fuel_type" name="fuel_type"><option value="">Любое</option></select>
      </div>

      <div class="form-row">
        <label for="gearbox">Коробка передач</label>
        <select id="gearbox" name="gearbox"><option value="">Любая</option></select>
      </div>

      <div class="form-row" style="flex-direction:row;gap:8px;">
        <div style="flex:1">
          <label for="price_from">Цена (от)</label>
          <input type="number" id="price_from" name="price_from" placeholder="0" min="0">
        </div>
        <div style="flex:1">
          <label for="price_to">Цена (до)</label>
          <input type="number" id="price_to" name="price_to" placeholder="100000" min="0">
        </div>
      </div>
    </div>

    <!-- --- Группа полей для Запчастей (тут добавлены бренд/модель для запчастей) --- -->
    <div id="part-fields" class="group-hidden">
      <div class="form-row">
        <label for="brand_part">Бренд</label>
        <select id="brand_part" name="brand_part"><option value="">Все бренды</option></select>
      </div>

      <div class="form-row">
        <label for="model_part">Модель</label>
        <select id="model_part" name="model_part" disabled><option value="">Сначала выберите бренд</option></select>
      </div>

      <div class="form-row">
        <label for="complex_part">Комплексная часть</label>
        <select id="complex_part" name="complex_part"><option value="">Все комплексные части</option></select>
      </div>

      <div class="form-row">
        <label for="component">Компонент</label>
        <select id="component" name="component" disabled><option value="">Сначала выберите комплексную часть</option></select>
      </div>

      <div class="form-row">
        <label for="part_quality">Состояние</label>
        <select id="part_quality" name="part_quality"><option value="">Любое</option><option value="new">Новый</option><option value="used">Б/У</option></select>
      </div>

      <div class="form-row" style="flex-direction:row;gap:8px;">
        <div style="flex:1">
          <label for="part_price_from">Цена (от)</label>
          <input type="number" id="part_price_from" name="part_price_from" placeholder="0" min="0">
        </div>
        <div style="flex:1">
          <label for="part_price_to">Цена (до)</label>
          <input type="number" id="part_price_to" name="part_price_to" placeholder="100000" min="0">
        </div>
      </div>
    </div>

    <div class="form-row">
      <label for="search">Поиск (название / артикул / ID)</label>
      <input type="text" id="search" name="search" placeholder="например: 123 или тормоза">
    </div>

    <div class="controls-row">
      <button id="clearFilters" class="btn-ghost">Сбросить</button>
      <div style="flex:1" class="hint">Фильтры применяются автоматически (по изменению полей).</div>
    </div>

  </aside>

  <section class="products-wrap" aria-live="polite">
    <div class="products" id="products">
      <!-- карточки товаров подгрузятся через JS (productList.js) -->
    </div>
  </section>
</main>


<script>
/* Polyfill / helper: fetchJSON — чтобы старые вызовы не ломались */
window.fetchJSON = async function(url, opts = {}) {
  try {
    const resp = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts));
    if (!resp.ok) {
      // вернуть null при ошибке — старый код должен корректно обработать
      console.warn('fetchJSON: non-ok response for', url, resp.status);
      return null;
    }
    return await resp.json();
  } catch (e) {
    console.warn('fetchJSON error for', url, e);
    return null;
  }
};
console.log('fetchJSON polyfill loaded');
</script>
<!-- Скрипты: productList.js должен быть подключён первым -->
<script src="/mehanik/assets/js/productList.js"></script>

<script>
(function(){
  function debounce(fn, ms = 300) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(()=>fn(...args), ms); };
  }

  // DOM elements
  const typeButtons = Array.from(document.querySelectorAll('.type-switch .switch-btn'));
  const autoFields = document.getElementById('auto-fields');
  const partFields = document.getElementById('part-fields');

  const brandEl = document.getElementById('brand');
  const modelEl = document.getElementById('model');
  const brandPartEl = document.getElementById('brand_part');
  const modelPartEl = document.getElementById('model_part');

  const yearFromEl = document.getElementById('year_from');
  const yearToEl = document.getElementById('year_to');
  const complexPartEl = document.getElementById('complex_part');
  const componentEl = document.getElementById('component');
  const searchEl = document.getElementById('search');

  const vehicleTypeEl = document.getElementById('vehicle_type');
  const vehicleBodyEl = document.getElementById('vehicle_body');
  const fuelTypeEl = document.getElementById('fuel_type');
  const gearboxEl = document.getElementById('gearbox');
  const priceFromEl = document.getElementById('price_from');
  const priceToEl = document.getElementById('price_to');

  const partPriceFromEl = document.getElementById('part_price_from');
  const partPriceToEl = document.getElementById('part_price_to');
  const partQualityEl = document.getElementById('part_quality');

  // in-memory lookups — keep original shapes (brands can be array of {id,name} or strings)
  let lookups = {
    brands: [],              // array (strings or objects {id,name})
    modelsByBrand: {},      // map: brandKey -> [models] where brandKey equals option value used in brand select
    complex_parts: [],
    componentsByComplex: {},
    vehicle_types: [],
    vehicle_bodies: [],
    fuel_types: [],
    gearboxes: []
  };

  function setSelectOptions(sel, items, placeholderText = 'Все') {
    if (!sel) return;
    const prev = sel.value;
    sel.innerHTML = '';
    const opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = placeholderText;
    sel.appendChild(opt0);
    if (!items || !items.length) {
      sel.value = '';
      return;
    }
    for (const it of items) {
      let value, label;
      if (typeof it === 'object') {
        // prefer id/value as value, and name/label as label
        value = String(it.value ?? it.id ?? it.key ?? it.name ?? '');
        label = String(it.label ?? it.name ?? it.value ?? value);
      } else {
        value = label = String(it);
      }
      const o = document.createElement('option');
      o.value = value;
      o.textContent = label;
      sel.appendChild(o);
    }
    // restore previous if still present
    if (prev && Array.from(sel.options).some(o => o.value === prev)) {
      sel.value = prev;
    } else {
      sel.selectedIndex = 0;
    }
  }

  // универсальная функция для обновления моделей в переданный targetModelEl
  function updateModelOptionsForBrand(brandKey, targetModelEl = modelEl) {
    if (!targetModelEl) return;
    if (!brandKey) {
      targetModelEl.innerHTML = '<option value="">Сначала выберите бренд</option>';
      targetModelEl.disabled = true;
      targetModelEl.value = '';
      return;
    }
    const models = lookups.modelsByBrand[brandKey] || [];
    if (!models.length) {
      targetModelEl.innerHTML = '<option value="">Модели не найдены</option>';
      targetModelEl.disabled = true;
      targetModelEl.value = '';
      return;
    }
    setSelectOptions(targetModelEl, models, 'Все модели');
    targetModelEl.disabled = false;
  }

  function updateComponentOptionsForComplex(complex) {
    if (!componentEl) return;
    if (!complex) {
      componentEl.innerHTML = '<option value="">Сначала выберите комплексную часть</option>';
      componentEl.disabled = true;
      componentEl.value = '';
      return;
    }
    const comps = lookups.componentsByComplex[complex] || [];
    if (!comps.length) {
      componentEl.innerHTML = '<option value="">Компоненты не найдены</option>';
      componentEl.disabled = true;
      componentEl.value = '';
      return;
    }
    setSelectOptions(componentEl, comps, 'Все компоненты');
    componentEl.disabled = false;
  }

  function collectFilters() {
    const getVal = el => el ? String(el.value).trim() : '';

    // which type is active? (only one)
    const activeBtn = document.querySelector('.type-switch .switch-btn.active');
    const activeType = activeBtn ? activeBtn.getAttribute('data-type') : 'auto';

    const filters = {
      type: activeType,
      q: getVal(searchEl)
    };

    if (activeType === 'auto') {
      filters.vehicle_type = getVal(vehicleTypeEl);
      filters.vehicle_body = getVal(vehicleBodyEl);
      filters.brand = getVal(brandEl);
      filters.model = (filters.brand ? getVal(modelEl) : '');
      filters.year_from = getVal(yearFromEl);
      filters.year_to = getVal(yearToEl);
      filters.fuel_type = getVal(fuelTypeEl);
      filters.gearbox = getVal(gearboxEl);
      filters.price_from = getVal(priceFromEl);
      filters.price_to = getVal(priceToEl);
    } else { // part
      filters.brand_part = getVal(brandPartEl);
      filters.model_part = (filters.brand_part ? getVal(modelPartEl) : '');
      filters.complex_part = getVal(complexPartEl);
      filters.component = (filters.complex_part ? getVal(componentEl) : '');
      filters.part_quality = getVal(partQualityEl);
      filters.price_from = getVal(partPriceFromEl);
      filters.price_to = getVal(partPriceToEl);
    }

    return filters;
  }

  async function applyFilters() {
    const filters = collectFilters();
    if (typeof runFilter === 'function') {
      try { await runFilter(filters); } catch(e){ console.warn('runFilter error', e); }
      return;
    }
    if (window.productList && typeof productList.loadProducts === 'function') {
      try { await productList.loadProducts(filters); } catch(e){ console.warn('productList.loadProducts error', e); }
      return;
    }
    try {
      const params = new URLSearchParams(filters);
      const resp = await fetch('/mehanik/api/products.php?' + params.toString(), { credentials: 'same-origin' });
      if (resp.ok) {
        const json = await resp.json();
        renderProductsFallback(json.items || []);
      }
    } catch (e) { /* ignore */ }
  }

  function renderProductsFallback(items) {
    const container = document.getElementById('products');
    if (!container) return;
    container.innerHTML = '';
    for (const it of items) {
      const card = document.createElement('article');
      card.className = 'product-card';
      const img = document.createElement('img');
      img.className = 'product-media';
      img.alt = it.name || 'Изображение';
      img.src = it.photo || '/mehanik/assets/img/no-image.png';
      const content = document.createElement('div');
      content.className = 'product-content';
      const title = document.createElement('div');
      title.className = 'product-title';
      title.textContent = (it.name || it.title || '—');
      const sub = document.createElement('div');
      sub.className = 'product-sub';
      sub.textContent = it.brand ? (it.brand + (it.model ? ' ' + it.model : '')) : (it.type || '');
      const tags = document.createElement('div');
      tags.className = 'tags';
      if (it.year) {
        const t = document.createElement('span'); t.className = 'tag'; t.textContent = 'Год: ' + it.year; tags.appendChild(t);
      }
      if (it.quality) {
        const t = document.createElement('span'); t.className = 'tag'; t.textContent = it.quality; tags.appendChild(t);
      }
      const row = document.createElement('div'); row.className = 'product-row';
      const price = document.createElement('div'); price.className = 'price'; price.textContent = it.price ? (it.price + ' ₽') : '—';
      const meta = document.createElement('div'); meta.className = 'meta'; meta.textContent = 'ID:' + (it.id || '-');
      row.appendChild(price); row.appendChild(meta);

      content.appendChild(title);
      content.appendChild(sub);
      content.appendChild(tags);
      content.appendChild(row);

      card.appendChild(img);
      card.appendChild(content);
      container.appendChild(card);
    }
  }

  // merge lookups from various possible shapes — tolerant to many variants
  function mergeLookups(data) {
    if (!data || typeof data !== 'object') return;

    // BRANDS: accept array of strings or array of objects with id/name
    if (Array.isArray(data.brands)) {
      lookups.brands = data.brands;
    } else if (Array.isArray(data.brand_list)) {
      lookups.brands = data.brand_list;
    } else if (Array.isArray(data.makes)) {
      lookups.brands = data.makes;
    }

    // MODELS: various shapes
    // If modelsByBrand provided directly, prefer it (keys may be brand ids or names)
    if (data.modelsByBrand && typeof data.modelsByBrand === 'object') {
      lookups.modelsByBrand = {};
      for (const k of Object.keys(data.modelsByBrand)) {
        const arr = Array.isArray(data.modelsByBrand[k]) ? data.modelsByBrand[k] : [data.modelsByBrand[k]];
        lookups.modelsByBrand[String(k)] = arr.map(m => {
          if (typeof m === 'object') return (m.name ?? m.model ?? m.value ?? m.id ?? '');
          return String(m);
        }).filter(Boolean).map(x => (typeof x === 'string' ? x : x));
      }
    } else if (Array.isArray(data.models)) {
      // models: [{id,brand_id,name}] or [{brand,name}]
      lookups.modelsByBrand = lookups.modelsByBrand || {};
      for (const m of data.models) {
        if (!m) continue;
        const brandKey = String(m.brand_id ?? m.brand ?? m.make ?? m.brand_name ?? m.brandKey ?? '');
        const modelItem = (typeof m === 'object' ? (m.name ?? m.model ?? m.value ?? '') : String(m));
        if (!brandKey || !modelItem) continue;
        if (!lookups.modelsByBrand[brandKey]) lookups.modelsByBrand[brandKey] = [];
        lookups.modelsByBrand[brandKey].push(typeof modelItem === 'object' ? modelItem : modelItem);
      }
      for (const k in lookups.modelsByBrand) {
        lookups.modelsByBrand[k] = Array.from(new Set(lookups.modelsByBrand[k]));
      }
    } else if (Array.isArray(data.model_list)) {
      lookups.modelsByBrand = lookups.modelsByBrand || {};
      for (const m of data.model_list) {
        const brandKey = String(m.brand ?? m.brand_id ?? '');
        const name = String(m.model ?? m.name ?? '');
        if (!brandKey || !name) continue;
        if (!lookups.modelsByBrand[brandKey]) lookups.modelsByBrand[brandKey] = [];
        lookups.modelsByBrand[brandKey].push(name);
      }
    }

    // Complex parts / components
    if (Array.isArray(data.complex_parts)) {
      lookups.complex_parts = data.complex_parts.map(x => (typeof x === 'object' ? (x.name ?? x.value ?? x.id ?? '') : String(x))).filter(Boolean);
    } else if (Array.isArray(data.complex_list)) {
      lookups.complex_parts = data.complex_list.map(x => x.name ?? x).filter(Boolean);
    }

    if (data.componentsByComplex && typeof data.componentsByComplex === 'object') {
      lookups.componentsByComplex = {};
      for (const k of Object.keys(data.componentsByComplex)) {
        const arr = Array.isArray(data.componentsByComplex[k]) ? data.componentsByComplex[k] : [data.componentsByComplex[k]];
        lookups.componentsByComplex[k] = arr.map(c => (typeof c === 'object' ? (c.name ?? c.value ?? '') : String(c))).filter(Boolean);
      }
    } else if (Array.isArray(data.components)) {
      lookups.componentsByComplex = lookups.componentsByComplex || {};
      for (const c of data.components) {
        const complex = String(c.complex_part ?? c.group ?? c.group_name ?? '');
        const name = String(c.name ?? c.component ?? '');
        if (!complex || !name) continue;
        if (!lookups.componentsByComplex[complex]) lookups.componentsByComplex[complex] = [];
        lookups.componentsByComplex[complex].push(name);
      }
      for (const k in lookups.componentsByComplex) {
        lookups.componentsByComplex[k] = Array.from(new Set(lookups.componentsByComplex[k]));
      }
    }

    // Vehicle types / bodies / fuels / gearboxes — accept multiple key names
    const vt = data.vehicle_types ?? data.vehicleTypeList ?? data.types ?? data.types_list;
    if (Array.isArray(vt)) lookups.vehicle_types = vt.map(x => (typeof x === 'object' ? (x.name ?? x.value ?? x.id ?? '') : x)).filter(Boolean);

    const vb = data.vehicle_bodies ?? data.vehicleBodies ?? data.vehicle_bodies_list ?? data.bodies;
    if (Array.isArray(vb)) lookups.vehicle_bodies = vb.map(x => (typeof x === 'object' ? (x.name ?? x.value ?? x.id ?? '') : x)).filter(Boolean);

    const fuels = data.fuel_types ?? data.fuels ?? data.fuel_list ?? data.fuel_types_list;
    if (Array.isArray(fuels)) lookups.fuel_types = fuels.map(x => (typeof x === 'object' ? (x.name ?? x.value ?? x.id ?? '') : x)).filter(Boolean);

    const gbs = data.gearboxes ?? data.transmissions ?? data.gearbox_list ?? data.gearboxes_list;
    if (Array.isArray(gbs)) lookups.gearboxes = gbs.map(x => (typeof x === 'object' ? (x.name ?? x.value ?? x.id ?? '') : x)).filter(Boolean);

    // if brands not present but modelsByBrand has keys, derive brands from keys
    if ((!lookups.brands || !lookups.brands.length) && lookups.modelsByBrand && Object.keys(lookups.modelsByBrand).length) {
      lookups.brands = Object.keys(lookups.modelsByBrand).map(k => ({ id: k, name: k }));
    }
  }

  async function loadLookupsSmart() {
    // prefer loadLookups() hook if present
    if (typeof loadLookups === 'function') {
      try {
        const res = await loadLookups();
        if (res && typeof res === 'object') mergeLookups(res);
      } catch (e) { console.warn('loadLookups failed', e); }
    }

    // productList.lookups
    if (window.productList && window.productList.lookups) {
      mergeLookups(window.productList.lookups);
    }

    // fallback to API
    if ((!lookups.brands || !lookups.brands.length) && (!lookups.modelsByBrand || Object.keys(lookups.modelsByBrand).length === 0)) {
      try {
        const resp = await fetch('/mehanik/api/lookups.php', { credentials: 'same-origin' });
        if (resp.ok) {
          const data = await resp.json();
          mergeLookups(data);
        }
      } catch (e) { /* ignore */ }
    }

    // populate UI selects (both brand selects)
    setSelectOptions(brandEl, lookups.brands, 'Все бренды');
    setSelectOptions(brandPartEl, lookups.brands, 'Все бренды');

    setSelectOptions(vehicleTypeEl, lookups.vehicle_types, 'Все типы');
    setSelectOptions(vehicleBodyEl, lookups.vehicle_bodies, 'Все кузова');
    setSelectOptions(fuelTypeEl, lookups.fuel_types, 'Любое');
    setSelectOptions(gearboxEl, lookups.gearboxes, 'Любая');

    if (Array.isArray(lookups.complex_parts) && lookups.complex_parts.length) {
      setSelectOptions(complexPartEl, lookups.complex_parts, 'Все комплексные части');
    } else {
      setSelectOptions(complexPartEl, [], 'Все комплексные части');
    }

    // try restore models for both brand selects (brand value may be id or name)
    const bVal = brandEl ? brandEl.value : '';
    if (bVal) updateModelOptionsForBrand(bVal, modelEl);
    else updateModelOptionsForBrand('', modelEl);

    const bpVal = brandPartEl ? brandPartEl.value : '';
    if (bpVal) updateModelOptionsForBrand(bpVal, modelPartEl);
    else updateModelOptionsForBrand('', modelPartEl);

    if (!complexPartEl.value) updateComponentOptionsForComplex('');
    else updateComponentOptionsForComplex(complexPartEl.value);
  }

  // listeners for selects
  if (brandEl) {
    brandEl.addEventListener('change', function(){
      updateModelOptionsForBrand(this.value, modelEl);
      applyFilters();
    });
  }
  if (modelEl) {
    modelEl.addEventListener('change', function(){
      if (!brandEl || !brandEl.value) {
        modelEl.value = '';
      }
      applyFilters();
    });
  }

  if (brandPartEl) {
    brandPartEl.addEventListener('change', function(){
      updateModelOptionsForBrand(this.value, modelPartEl);
      applyFilters();
    });
  }
  if (modelPartEl) {
    modelPartEl.addEventListener('change', function(){
      if (!brandPartEl || !brandPartEl.value) {
        modelPartEl.value = '';
      }
      applyFilters();
    });
  }

  if (complexPartEl) {
    complexPartEl.addEventListener('change', function(){
      updateComponentOptionsForComplex(this.value);
      applyFilters();
    });
  }
  if (componentEl) {
    componentEl.addEventListener('change', function(){
      if (!complexPartEl || !complexPartEl.value) componentEl.value = '';
      applyFilters();
    });
  }

  [yearFromEl, yearToEl, priceFromEl, priceToEl, partPriceFromEl, partPriceToEl].forEach(el=>{
    if (!el) return;
    el.addEventListener('change', () => applyFilters());
  });

  [vehicleTypeEl, vehicleBodyEl, fuelTypeEl, gearboxEl, partQualityEl].forEach(el=>{
    if (!el) return;
    el.addEventListener('change', () => applyFilters());
  });

  if (searchEl) searchEl.addEventListener('input', debounce(()=>applyFilters(), 300));

  const clearBtn = document.getElementById('clearFilters');
  if (clearBtn) {
    clearBtn.addEventListener('click', (e) => {
      e.preventDefault();
      const ids = [
        'vehicle_type','vehicle_body','brand','model','year_from','year_to','fuel_type','gearbox','price_from','price_to',
        'brand_part','model_part','complex_part','component','part_quality','part_price_from','part_price_to','search'
      ];
      ids.forEach(id=>{
        const el = document.getElementById(id);
        if (!el) return;
        if (el.tagName.toLowerCase() === 'select') el.selectedIndex = 0;
        else el.value = '';
      });
      updateModelOptionsForBrand('', modelEl);
      updateModelOptionsForBrand('', modelPartEl);
      updateComponentOptionsForComplex('');
      applyFilters();
    });
  }

  // SWITCHER: radio-like behavior (only one active)
  function clearOtherTypeFields(type) {
    if (type === 'auto') {
      // clear part fields
      ['brand_part','model_part','complex_part','component','part_quality','part_price_from','part_price_to'].forEach(id=>{
        const el = document.getElementById(id);
        if (!el) return;
        if (el.tagName.toLowerCase() === 'select') el.selectedIndex = 0;
        else el.value = '';
      });
      updateModelOptionsForBrand('', modelPartEl);
      updateComponentOptionsForComplex('');
    } else {
      // clear auto fields
      ['vehicle_type','vehicle_body','brand','model','year_from','year_to','fuel_type','gearbox','price_from','price_to'].forEach(id=>{
        const el = document.getElementById(id);
        if (!el) return;
        if (el.tagName.toLowerCase() === 'select') el.selectedIndex = 0;
        else el.value = '';
      });
      updateModelOptionsForBrand('', modelEl);
    }
  }

  function setActiveType(type) {
    typeButtons.forEach(btn=>{
      const t = btn.getAttribute('data-type');
      const isActive = (t === type);
      btn.classList.toggle('active', isActive);
      btn.setAttribute('aria-checked', isActive ? 'true' : 'false');
    });
    if (type === 'auto') {
      autoFields.classList.remove('group-hidden');
      partFields.classList.add('group-hidden');
    } else {
      autoFields.classList.add('group-hidden');
      partFields.classList.remove('group-hidden');
    }

    // clear irrelevant fields to avoid cross-filtering
    clearOtherTypeFields(type);

    // update filters
    applyFilters();
  }

  typeButtons.forEach(btn => {
    btn.addEventListener('click', (e) => {
      const type = btn.getAttribute('data-type');
      setActiveType(type);
    });
  });

  // init
  document.addEventListener('DOMContentLoaded', async function(){
    // default to 'auto'
    setActiveType('auto');
    await loadLookupsSmart();

    try {
      if (window.productList && typeof productList.loadProducts === 'function') {
        await productList.loadProducts(collectFilters());
      } else if (typeof runFilter === 'function') {
        await runFilter(collectFilters());
      } else {
        await applyFilters();
      }
    } catch (e) { console.warn('Initial products load failed', e); }
  });

})();
</script>

<!-- main.js (он установит свои слушатели и runFilter, если есть) -->
<script src="/mehanik/assets/js/main.js"></script>
</body>
</html>
