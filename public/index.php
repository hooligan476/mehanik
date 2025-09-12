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

    /* ---- переключатель Авто | Запчасть (пустышка) ---- */
    .switch-wrap { max-width:1200px; margin: 12px auto; padding: 0 18px; box-sizing: border-box; }
    .type-switch { display:inline-flex; gap:8px; background: transparent; padding: 6px; border-radius: 8px; align-items:center; }
    .switch-btn {
      border: 1px solid #d1d5db;
      background: #fff;
      padding: 8px 14px;
      border-radius: 8px;
      cursor: pointer;
      font-weight: 600;
      color: #111827;
      box-shadow: 0 1px 2px rgba(2,6,23,0.04);
    }
    .switch-btn.active {
      background: #0b57a4;
      color: #fff;
      border-color: #0b57a4;
    }
    /* небольшой responsive */
    @media (max-width:480px){
      .type-switch { gap:6px; }
      .switch-btn { padding:7px 10px; font-size:14px; }
    }

    /* Disabled-select appearance */
    select[disabled] { opacity: 0.6; cursor: not-allowed; }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/header.php'; ?>

<!-- Переключатель типа каталога (Авто | Запчасть) — пустышка -->
<div class="switch-wrap" aria-hidden="false">
  <div class="type-switch" role="tablist" aria-label="Тип каталога">
    <button type="button" class="switch-btn active" data-type="auto" role="tab" aria-selected="true">Авто</button>
    <button type="button" class="switch-btn" data-type="part" role="tab" aria-selected="false">Запчасть</button>
  </div>
</div>

<main class="layout">
  <aside class="sidebar" aria-label="Фильтр товаров">
    <h3>Фильтр</h3>

    <label for="brand">Бренд</label>
    <select id="brand" name="brand"><option value="">Все бренды</option></select>

    <label for="model">Модель</label>
    <select id="model" name="model" disabled><option value="">Сначала выберите бренд</option></select>

    <label for="year_from">Год (от)</label>
    <input type="number" id="year_from" name="year_from" placeholder="1998">

    <label for="year_to">Год (до)</label>
    <input type="number" id="year_to" name="year_to" placeholder="2025">

    <label for="complex_part">Комплексная часть</label>
    <select id="complex_part" name="complex_part"><option value="">Все комплексные части</option></select>

    <label for="component">Компонент</label>
    <select id="component" name="component" disabled><option value="">Сначала выберите комплексную часть</option></select>

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
    brands: [],              // ['Toyota', 'BMW', ...]
    modelsByBrand: {},       // { 'Toyota': ['Corolla','Camry'], ... }
    complex_parts: [],       // ['Двигатель', 'Тормоза', ...]
    componentsByComplex: {}  // { 'Двигатель': ['Фильтр','Ремень'], ... }
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
      // leave only placeholder
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
    // restore prev value if still present
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
    return {
      brand,
      model,
      year_from: getVal(yearFromEl),
      year_to: getVal(yearToEl),
      complex_part: complex,
      component,
      q: getVal(searchEl)
    };
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
    // nothing to do otherwise
  }

  // merge lookups from various possible shapes
  function mergeLookups(data) {
    if (!data || typeof data !== 'object') return;

    // brands
    if (Array.isArray(data.brands)) {
      lookups.brands = data.brands.map(b => (typeof b === 'object' ? (b.name ?? b.value ?? '') : String(b))).filter(Boolean);
    } else if (Array.isArray(data.brand_list)) {
      lookups.brands = data.brand_list.map(b => b.name || b).filter(Boolean);
    }

    // modelsByBrand preferred shape
    if (data.modelsByBrand && typeof data.modelsByBrand === 'object') {
      lookups.modelsByBrand = {};
      for (const k of Object.keys(data.modelsByBrand)) {
        const arr = Array.isArray(data.modelsByBrand[k]) ? data.modelsByBrand[k] : [data.modelsByBrand[k]];
        lookups.modelsByBrand[k] = arr.map(m => (typeof m === 'object' ? (m.name ?? m.value ?? '') : String(m))).filter(Boolean);
      }
    } else if (Array.isArray(data.models)) {
      // models: [{brand, name}] or [{make, model}]
      lookups.modelsByBrand = lookups.modelsByBrand || {};
      for (const m of data.models) {
        const brand = (m.brand ?? m.make ?? '') + '';
        const name = (m.name ?? m.model ?? '') + '';
        if (!brand || !name) continue;
        if (!lookups.modelsByBrand[brand]) lookups.modelsByBrand[brand] = [];
        lookups.modelsByBrand[brand].push(name);
      }
      // unique
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

    // complex_parts
    if (Array.isArray(data.complex_parts)) {
      lookups.complex_parts = data.complex_parts.map(x => (typeof x === 'object' ? (x.name ?? x.value ?? '') : String(x))).filter(Boolean);
    } else if (Array.isArray(data.complex_list)) {
      lookups.complex_parts = data.complex_list.map(x => x.name ?? x).filter(Boolean);
    }

    // components by complex part
    if (data.componentsByComplex && typeof data.componentsByComplex === 'object') {
      lookups.componentsByComplex = {};
      for (const k of Object.keys(data.componentsByComplex)) {
        const arr = Array.isArray(data.componentsByComplex[k]) ? data.componentsByComplex[k] : [data.componentsByComplex[k]];
        lookups.componentsByComplex[k] = arr.map(c => (typeof c === 'object' ? (c.name ?? c.value ?? '') : String(c))).filter(Boolean);
      }
    } else if (Array.isArray(data.components)) {
      // components: [{complex_part, name}] or [{group,component}]
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

    // derive brands if missing but modelsByBrand keys exist
    if ((!lookups.brands || !lookups.brands.length) && lookups.modelsByBrand && Object.keys(lookups.modelsByBrand).length) {
      lookups.brands = Object.keys(lookups.modelsByBrand).sort();
    }
  }

  async function loadLookupsSmart() {
    // 1) try global loadLookups()
    if (typeof loadLookups === 'function') {
      try {
        const res = await loadLookups();
        if (res && typeof res === 'object') {
          mergeLookups(res);
        }
      } catch (e) { console.warn('loadLookups failed', e); }
    }

    // 2) try productList.lookups
    if (window.productList && window.productList.lookups) {
      mergeLookups(window.productList.lookups);
    }

    // 3) try fetch endpoint
    if ((!lookups.brands || !lookups.brands.length) && (!lookups.modelsByBrand || Object.keys(lookups.modelsByBrand).length === 0)) {
      try {
        const resp = await fetch('/mehanik/api/lookups.php', { credentials: 'same-origin' });
        if (resp.ok) {
          const data = await resp.json();
          mergeLookups(data);
        }
      } catch (e) { /* ignore */ }
    }

    // populate UI
    setSelectOptions(brandEl, lookups.brands, 'Все бренды');
    if (Array.isArray(lookups.complex_parts) && lookups.complex_parts.length) {
      setSelectOptions(complexPartEl, lookups.complex_parts, 'Все комплексные части');
    } else {
      // leave placeholder
      setSelectOptions(complexPartEl, [], 'Все комплексные части');
    }

    // initial model/component state
    if (!brandEl.value) updateModelOptionsForBrand('');
    else updateModelOptionsForBrand(brandEl.value);

    if (!complexPartEl.value) updateComponentOptionsForComplex('');
    else updateComponentOptionsForComplex(complexPartEl.value);
  }

  // listeners
  if (brandEl) {
    brandEl.addEventListener('change', function(){
      const b = this.value;
      updateModelOptionsForBrand(b);
      // applying filters
      applyFilters();
    });
  }
  if (modelEl) {
    modelEl.addEventListener('change', function(){
      // if brand not selected, clear model
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

  // switcher UI only
  const switchBtns = document.querySelectorAll('.switch-btn');
  switchBtns.forEach(btn => {
    btn.addEventListener('click', (e) => {
      switchBtns.forEach(b => {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
      });
      e.currentTarget.classList.add('active');
      e.currentTarget.setAttribute('aria-selected', 'true');
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

    // sync filters (ensures UI state -> products)
    await applyFilters();
  });

})();
</script>

<!-- main.js (он установит свои слушатели и runFilter, если есть) -->
<script src="/mehanik/assets/js/main.js"></script>
</body>
</html>
