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
    :root{
      --card-bg:#ffffff;
      --muted:#6b7280;
      --accent:#0b57a4;
      --surface:#f8fafc;
      --input-border:#e6e9ef;
    }

    body { background:#f5f7fb; }

    .layout { display: grid; grid-template-columns: 300px 1fr; gap: 24px; padding: 22px; max-width:1200px; margin: 0 auto; box-sizing:border-box; }
    .sidebar { background:var(--card-bg); padding:18px; border-radius:12px; box-shadow:0 8px 24px rgba(12,17,23,.04); border:1px solid rgba(15,20,30,0.02); }
    .sidebar h3 { margin:0 0 8px 0; font-size:1.05rem; color:#111827; }
    .form-row { display:flex; flex-direction:column; gap:6px; margin-top:12px; }
    .form-row label { font-weight:600; font-size:.95rem; color:#0f1724; }
    .form-row select, .form-row input { width:100%; padding:10px 12px; border-radius:10px; border:1px solid var(--input-border); box-sizing:border-box; background:transparent; font-size:14px; color:#111827; }
    .form-row select:focus, .form-row input:focus { outline: none; box-shadow: 0 0 0 4px rgba(11,87,164,0.06); border-color: var(--accent); }

    .controls-row { display:flex; gap:8px; align-items:center; margin-top:14px; flex-wrap:wrap; }
    .btn { display:inline-block; background:var(--accent); color:#fff; padding:9px 14px; border-radius:10px; text-decoration:none; font-weight:700; cursor:pointer; border:0; box-shadow: 0 4px 10px rgba(11,87,164,0.08); }
    .btn-ghost { background:#eef2f6; color:var(--accent); border-radius:10px; padding:8px 12px; font-weight:700; border:1px solid rgba(11,87,164,0.06); cursor:pointer; }
    .hint { font-size:.92rem; color:var(--muted); margin-top:8px; }

    .products { display:grid; grid-template-columns: repeat(auto-fill, minmax(240px,1fr)); gap:16px; }

    /* ---- переключатель (теперь внутри боковой панели под кнопкой) ---- */
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
      transition: all .15s ease;
    }
    .switch-btn .dot { width:10px;height:10px;border-radius:50%; background:transparent; border:1px solid rgba(0,0,0,0.06); display:inline-block; }
    .switch-btn.active { background:var(--accent); color:#fff; border-color:var(--accent); transform:translateY(-1px); }
    .switch-btn.active .dot { background:#fff; border-color:transparent; }

    .switch-hint { font-size:0.85rem; color:var(--muted); margin-top:8px; }

    @media (max-width:900px) {
      .layout { grid-template-columns: 1fr; padding:12px; }
      .sidebar { order:2; }
      .products { order:1; }
    }

    select[disabled] { opacity: 0.6; cursor:not-allowed; }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/header.php'; ?>

<main class="layout">
  <aside class="sidebar" aria-label="Фильтр товаров">
    <h3>Фильтр</h3>

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
        <input type="number" id="year_from" name="year_from" placeholder="1998">
      </div>
      <div style="flex:1">
        <label for="year_to">Год (до)</label>
        <input type="number" id="year_to" name="year_to" placeholder="2025">
      </div>
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
      <label for="search">Поиск (название / артикул / ID)</label>
      <input type="text" id="search" name="search" placeholder="например: 123 или тормоза">
    </div>

    <div class="controls-row">
      <button id="clearFilters" class="btn-ghost">Сбросить</button>
      <div style="flex:1" class="hint">Фильтры применяются автоматически (по изменению полей).</div>
    </div>

    <!-- Переключатель помещён прямо под контролы, в виде компактного блока -->
    <div class="type-block" aria-hidden="false">
      <div style="font-weight:700;color:#0f1724;margin-bottom:6px">Показывать</div>
      <div class="type-switch" role="group" aria-label="Тип каталога">
        <button type="button" class="switch-btn active" data-type="auto" aria-pressed="true" title="Показывать автомобили">
          <span class="dot" aria-hidden="true"></span> Авто
        </button>
        <button type="button" class="switch-btn" data-type="part" aria-pressed="false" title="Показывать запчасти">
          <span class="dot" aria-hidden="true"></span> Запчасть
        </button>
      </div>
      <div class="switch-hint">Можно включить оба режима — тогда будут показаны и авто, и запчасти</div>
    </div>

  </aside>

  <section class="products" id="products" aria-live="polite">
    <!-- карточки товаров подгрузятся через JS -->
  </section>
</main>

<!-- Скрипты: productList.js должен быть подключён первым -->
<script src="/mehanik/assets/js/productList.js"></script>

<script>
(function(){
  function debounce(fn, ms = 300) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(()=>fn(...args), ms); };
  }

  const brandEl = document.getElementById('brand');
  const modelEl = document.getElementById('model');
  const yearFromEl = document.getElementById('year_from');
  const yearToEl = document.getElementById('year_to');
  const complexPartEl = document.getElementById('complex_part');
  const componentEl = document.getElementById('component');
  const searchEl = document.getElementById('search');

  // in-memory lookups
  let lookups = {
    brands: [],
    modelsByBrand: {},
    complex_parts: [],
    componentsByComplex: {}
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
        value = String(it.value ?? it.id ?? it.name ?? '');
        label = String(it.label ?? it.name ?? it.value ?? value);
      } else {
        value = label = String(it);
      }
      const o = document.createElement('option');
      o.value = value;
      o.textContent = label;
      sel.appendChild(o);
    }
    if (prev && Array.from(sel.options).some(o => o.value === prev)) {
      sel.value = prev;
    } else {
      sel.selectedIndex = 0;
    }
  }

  function updateModelOptionsForBrand(brand) {
    if (!modelEl) return;
    if (!brand) {
      modelEl.innerHTML = '<option value="">Сначала выберите бренд</option>';
      modelEl.disabled = true;
      modelEl.value = '';
      return;
    }
    const models = lookups.modelsByBrand[brand] || [];
    if (!models.length) {
      modelEl.innerHTML = '<option value="">Модели не найдены</option>';
      modelEl.disabled = true;
      modelEl.value = '';
      return;
    }
    setSelectOptions(modelEl, models, 'Все модели');
    modelEl.disabled = false;
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
    const brand = getVal(brandEl);
    const model = brand ? getVal(modelEl) : '';  // only pass model if brand set
    const complex = getVal(complexPartEl);
    const component = complex ? getVal(componentEl) : ''; // only pass component if complex selected

    // types from switch buttons
    const switchBtns = document.querySelectorAll('.type-switch .switch-btn');
    const activeTypes = Array.from(switchBtns).filter(s => s.classList.contains('active')).map(s => s.getAttribute('data-type')).filter(Boolean);

    const filters = {
      brand,
      model,
      year_from: getVal(yearFromEl),
      year_to: getVal(yearToEl),
      complex_part: complex,
      component,
      q: getVal(searchEl)
    };

    if (activeTypes.length === 1) filters.type = activeTypes[0];
    else if (activeTypes.length > 1) filters.type = activeTypes.join(',');

    return filters;
  }

  async function applyFilters() {
    const filters = collectFilters();
    if (typeof runFilter === 'function') {
      try { await runFilter(); } catch(e){ console.warn('runFilter error', e); }
      return;
    }
    if (window.productList && typeof productList.loadProducts === 'function') {
      try { await productList.loadProducts(filters); } catch(e){ console.warn('productList.loadProducts error', e); }
      return;
    }
  }

  // merge lookups from various possible shapes (compatible with productList.loadProducts response)
  function mergeLookups(data) {
    if (!data || typeof data !== 'object') return;

    if (Array.isArray(data.brands)) {
      lookups.brands = data.brands.map(b => (typeof b === 'object' ? (b.name ?? b.value ?? '') : String(b))).filter(Boolean);
    } else if (Array.isArray(data.brand_list)) {
      lookups.brands = data.brand_list.map(b => b.name || b).filter(Boolean);
    }

    if (data.modelsByBrand && typeof data.modelsByBrand === 'object') {
      lookups.modelsByBrand = {};
      for (const k of Object.keys(data.modelsByBrand)) {
        const arr = Array.isArray(data.modelsByBrand[k]) ? data.modelsByBrand[k] : [data.modelsByBrand[k]];
        lookups.modelsByBrand[k] = arr.map(m => (typeof m === 'object' ? (m.name ?? m.value ?? '') : String(m))).filter(Boolean);
      }
    } else if (Array.isArray(data.models)) {
      lookups.modelsByBrand = lookups.modelsByBrand || {};
      for (const m of data.models) {
        const brand = (m.brand ?? m.make ?? '') + '';
        const name = (m.name ?? m.model ?? '') + '';
        if (!brand || !name) continue;
        if (!lookups.modelsByBrand[brand]) lookups.modelsByBrand[brand] = [];
        lookups.modelsByBrand[brand].push(name);
      }
      for (const b in lookups.modelsByBrand) {
        lookups.modelsByBrand[b] = Array.from(new Set(lookups.modelsByBrand[b]));
      }
    } else if (Array.isArray(data.model_list)) {
      lookups.modelsByBrand = lookups.modelsByBrand || {};
      for (const m of data.model_list) {
        const brand = (m.brand ?? '') + '';
        const name = (m.model ?? '') + '';
        if (!brand || !name) continue;
        if (!lookups.modelsByBrand[brand]) lookups.modelsByBrand[brand] = [];
        lookups.modelsByBrand[brand].push(name);
      }
    }

    if (Array.isArray(data.complex_parts)) {
      lookups.complex_parts = data.complex_parts.map(x => (typeof x === 'object' ? (x.name ?? x.value ?? '') : String(x))).filter(Boolean);
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
        const complex = (c.complex_part ?? c.group ?? '') + '';
        const name = (c.name ?? c.component ?? '') + '';
        if (!complex || !name) continue;
        if (!lookups.componentsByComplex[complex]) lookups.componentsByComplex[complex] = [];
        lookups.componentsByComplex[complex].push(name);
      }
      for (const k in lookups.componentsByComplex) {
        lookups.componentsByComplex[k] = Array.from(new Set(lookups.componentsByComplex[k]));
      }
    }

    if ((!lookups.brands || !lookups.brands.length) && lookups.modelsByBrand && Object.keys(lookups.modelsByBrand).length) {
      lookups.brands = Object.keys(lookups.modelsByBrand).sort();
    }
  }

  async function loadLookupsSmart() {
    if (typeof loadLookups === 'function') {
      try {
        const res = await loadLookups();
        if (res && typeof res === 'object') mergeLookups(res);
      } catch (e) { console.warn('loadLookups failed', e); }
    }

    if (window.productList && window.productList.lookups) {
      mergeLookups(window.productList.lookups);
    }

    if ((!lookups.brands || !lookups.brands.length) && (!lookups.modelsByBrand || Object.keys(lookups.modelsByBrand).length === 0)) {
      try {
        const resp = await fetch('/mehanik/api/lookups.php', { credentials: 'same-origin' });
        if (resp.ok) {
          const data = await resp.json();
          mergeLookups(data);
        }
      } catch (e) { /* ignore */ }
    }

    // populate UI selects
    setSelectOptions(brandEl, lookups.brands, 'Все бренды');
    if (Array.isArray(lookups.complex_parts) && lookups.complex_parts.length) {
      setSelectOptions(complexPartEl, lookups.complex_parts, 'Все комплексные части');
    } else {
      setSelectOptions(complexPartEl, [], 'Все комплексные части');
    }

    if (!brandEl.value) updateModelOptionsForBrand('');
    else updateModelOptionsForBrand(brandEl.value);

    if (!complexPartEl.value) updateComponentOptionsForComplex('');
    else updateComponentOptionsForComplex(complexPartEl.value);
  }

  // listeners for selects
  if (brandEl) {
    brandEl.addEventListener('change', function(){
      const b = this.value;
      updateModelOptionsForBrand(b);
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

  if (complexPartEl) {
    complexPartEl.addEventListener('change', function(){
      const c = this.value;
      updateComponentOptionsForComplex(c);
      applyFilters();
    });
  }
  if (componentEl) {
    componentEl.addEventListener('change', function(){
      if (!complexPartEl || !complexPartEl.value) componentEl.value = '';
      applyFilters();
    });
  }

  [yearFromEl, yearToEl].forEach(el=>{
    if (!el) return;
    el.addEventListener('change', () => applyFilters());
  });

  if (searchEl) searchEl.addEventListener('input', debounce(()=>applyFilters(), 300));

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
      updateModelOptionsForBrand('');
      updateComponentOptionsForComplex('');
      applyFilters();
    });
  }

  // SWITCHER: toggle behavior (can activate multiple)
  const switchBtns = document.querySelectorAll('.type-switch .switch-btn');
  switchBtns.forEach(btn => {
    btn.setAttribute('aria-pressed', btn.classList.contains('active') ? 'true' : 'false');
    btn.addEventListener('click', (e) => {
      const el = e.currentTarget;
      const willBeActive = !el.classList.contains('active');
      if (willBeActive) el.classList.add('active');
      else el.classList.remove('active');
      el.setAttribute('aria-pressed', willBeActive ? 'true' : 'false');

      // keep dependent selects consistent
      updateModelOptionsForBrand(brandEl ? brandEl.value : '');
      updateComponentOptionsForComplex(complexPartEl ? complexPartEl.value : '');

      // apply filters immediately
      applyFilters();
    });
  });

  // init
  document.addEventListener('DOMContentLoaded', async function(){
    await loadLookupsSmart();

    // initial products load
    try {
      if (window.productList && typeof productList.loadProducts === 'function') {
        await productList.loadProducts();
      } else if (typeof runFilter === 'function') {
        await runFilter();
      }
    } catch (e) { console.warn('Initial products load failed', e); }

    // sync UI -> products
    await applyFilters();
  });

})();
</script>

<!-- main.js (он установит свои слушатели и runFilter, если есть) -->
<script src="/mehanik/assets/js/main.js"></script>
</body>
</html>
