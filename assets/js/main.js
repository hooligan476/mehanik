// public/assets/js/main.js (updated — uses productList.js as primary loader)
(function(){
  'use strict';

  // --- utilities ---
  function debounce(fn, ms = 300) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(()=>fn(...args), ms); };
  }
  function qs(sel) { return document.querySelector(sel); }
  function qsa(sel) { return Array.from(document.querySelectorAll(sel)); }

  // fetchJSON polyfill
  if (!window.fetchJSON) {
    window.fetchJSON = async function(url, opts = {}) {
      try {
        const resp = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts));
        if (!resp.ok) return null;
        return await resp.json();
      } catch (e) { console.warn('fetchJSON error', e); return null; }
    };
  }

  // --- DOM elements ---
  const brandEl = qs('#brand');
  const modelEl = qs('#model');
  const brandPartEl = qs('#brand_part');
  const modelPartEl = qs('#model_part');

  const vehicleTypeEl = qs('#vehicle_type');
  const vehicleBodyEl = qs('#vehicle_body');
  const fuelTypeEl = qs('#fuel_type');
  const gearboxEl = qs('#gearbox');

  const yearFromEl = qs('#year_from');
  const yearToEl = qs('#year_to');

  const complexPartEl = qs('#complex_part');
  const componentEl = qs('#component');

  const priceFromEl = qs('#price_from');
  const priceToEl = qs('#price_to');
  const partPriceFromEl = qs('#part_price_from');
  const partPriceToEl = qs('#part_price_to');

  const partQualityEl = qs('#part_quality');
  const searchEl = qs('#search');
  const clearBtn = qs('#clearFilters');

  const typeButtons = qsa('.type-switch .switch-btn');
  const autoFields = qs('#auto-fields');
  const partFields = qs('#part-fields');

  const productsContainer = qs('#products');

  // --- lookups cache (local UI convenience) ---
  const lookups = {
    brands: [], models: [], modelsByBrand: {}, complex_parts: [], components: [],
    vehicle_types: [], vehicle_bodies: [], fuel_types: [], gearboxes: [], vehicle_years: []
  };

  // --- helpers for selects ---
  function setSelectOptions(sel, items, placeholder = 'Все') {
    if (!sel) return;
    const prev = sel.value;
    sel.innerHTML = `<option value="">${placeholder}</option>`;
    if (!Array.isArray(items) || items.length === 0) { sel.selectedIndex = 0; return; }
    for (const it of items) {
      const val = (typeof it === 'object') ? (it.id ?? it.value ?? it.key ?? it.name ?? '') : it;
      const label = (typeof it === 'object') ? (it.name ?? it.label ?? String(val)) : String(it);
      const o = document.createElement('option');
      o.value = String(val ?? '');
      o.textContent = String(label);
      sel.appendChild(o);
    }
    if (prev && Array.from(sel.options).some(o => o.value === prev)) sel.value = prev;
  }

  function updateModelOptionsForBrand(brandValue, targetModelEl = modelEl) {
    if (!targetModelEl) return;
    if (!brandValue) {
      targetModelEl.innerHTML = '<option value="">Сначала выберите бренд</option>';
      targetModelEl.disabled = true;
      targetModelEl.value = '';
      return;
    }
    const candidates = lookups.modelsByBrand[String(brandValue)] || [];
    if (!candidates.length) {
      const fallback = (lookups.models || []).filter(m => String(m.brand_id ?? m.brand ?? m.make ?? '') === String(brandValue));
      if (fallback.length) candidates.push(...fallback);
    }
    if (!candidates.length) {
      targetModelEl.innerHTML = '<option value="">Модели не найдены</option>';
      targetModelEl.disabled = true;
      targetModelEl.value = '';
      return;
    }
    targetModelEl.innerHTML = '<option value="">Все модели</option>' + candidates.map(m=>{
      const v = m.id ?? m.value ?? m.model ?? m.name;
      const label = m.name ?? m.model ?? String(v);
      return `<option value="${String(v)}">${label}</option>`;
    }).join('');
    targetModelEl.disabled = false;
  }

  function updateComponentOptionsForComplex(complexValue) {
    if (!componentEl) return;
    if (!complexValue) {
      componentEl.innerHTML = '<option value="">Сначала выберите комплексную часть</option>';
      componentEl.disabled = true;
      componentEl.value = '';
      return;
    }
    const comps = (lookups.components || []).filter(c => String(c.complex_part_id ?? c.group ?? '') === String(complexValue));
    if (!comps.length) {
      componentEl.innerHTML = '<option value="">Компоненты не найдены</option>';
      componentEl.disabled = true;
      componentEl.value = '';
      return;
    }
    componentEl.innerHTML = '<option value="">Все компоненты</option>' + comps.map(c=>`<option value="${String(c.id)}">${c.name}</option>`).join('');
    componentEl.disabled = false;
  }

  // --- load lookups (use productList if possible) ---
  async function loadLookups() {
    // If productList already has lookups, use them.
    if (window.productList && window.productList.lookups && Object.keys(window.productList.lookups).length) {
      const lu = window.productList.lookups;
      mergeAndPopulateLookups(lu);
      return lu;
    }

    // Otherwise, ask productList to load once (it will populate lookups from API).
    if (window.productList && typeof window.productList.loadProducts === 'function') {
      try {
        // Request a lightweight load; fetch will return products + lookups.
        await window.productList.loadProducts({ type: 'auto', _limit: 1 });
        if (window.productList.lookups) {
          mergeAndPopulateLookups(window.productList.lookups);
          return window.productList.lookups;
        }
      } catch (e) {
        console.warn('productList.loadProducts for lookups failed', e);
      }
    }

    // Last-resort: try previous endpoints (legacy)
    const tryUrls = ['/mehanik/api/lookups.php', '/mehanik/api/products.php'];
    for (const url of tryUrls) {
      try {
        const resp = await fetch(url, { credentials: 'same-origin' });
        if (!resp.ok) continue;
        const json = await resp.json();
        const data = json.lookups ?? json;
        if (data) {
          mergeAndPopulateLookups(data);
          return data;
        }
      } catch (e) { /* ignore */ }
    }
    throw new Error('lookups not found');
  }

  function mergeAndPopulateLookups(data) {
    // normalize into local lookups object
    lookups.brands = Array.isArray(data.brands) ? data.brands : (Array.isArray(data.brand_list) ? data.brand_list : []);
    lookups.models = Array.isArray(data.models) ? data.models : (Array.isArray(data.model_list) ? data.model_list : []);
    lookups.complex_parts = Array.isArray(data.complex_parts) ? data.complex_parts : (Array.isArray(data.complex_list) ? data.complex_list : []);
    lookups.components = Array.isArray(data.components) ? data.components : [];
    lookups.vehicle_types = Array.isArray(data.vehicle_types) ? data.vehicle_types : [];
    lookups.vehicle_bodies = Array.isArray(data.vehicle_bodies) ? data.vehicle_bodies : [];
    lookups.fuel_types = Array.isArray(data.fuel_types) ? data.fuel_types : [];
    lookups.gearboxes = Array.isArray(data.gearboxes) ? data.gearboxes : [];
    lookups.vehicle_years = Array.isArray(data.vehicle_years) ? data.vehicle_years : [];

    // build modelsByBrand
    lookups.modelsByBrand = {};
    if (Array.isArray(lookups.models)) {
      for (const m of lookups.models) {
        const brandKey = String(m.brand_id ?? m.brand ?? m.make ?? m.brand_key ?? '');
        if (!brandKey) continue;
        if (!lookups.modelsByBrand[brandKey]) lookups.modelsByBrand[brandKey] = [];
        lookups.modelsByBrand[brandKey].push(m);
      }
    }

    // populate selects
    if (brandEl) setSelectOptions(brandEl, lookups.brands, 'Все бренды');
    if (brandPartEl) setSelectOptions(brandPartEl, lookups.brands, 'Все бренды');
    if (vehicleTypeEl) setSelectOptions(vehicleTypeEl, lookups.vehicle_types, 'Все типы');
    if (vehicleBodyEl) setSelectOptions(vehicleBodyEl, lookups.vehicle_bodies, 'Все кузова');
    if (fuelTypeEl) setSelectOptions(fuelTypeEl, lookups.fuel_types, 'Любое');
    if (gearboxEl) setSelectOptions(gearboxEl, lookups.gearboxes, 'Любая');
    if (complexPartEl) setSelectOptions(complexPartEl, lookups.complex_parts, 'Все комплексные части');

    // init dependent selects
    if (brandEl && modelEl) {
      if (brandEl.value) updateModelOptionsForBrand(brandEl.value, modelEl);
      else { modelEl.innerHTML = '<option value=\"\">Сначала выберите бренд</option>'; modelEl.disabled = true; }
    }
    if (brandPartEl && modelPartEl) {
      if (brandPartEl.value) updateModelOptionsForBrand(brandPartEl.value, modelPartEl);
      else { modelPartEl.innerHTML = '<option value=\"\">Сначала выберите бренд</option>'; modelPartEl.disabled = true; }
    }
    if (complexPartEl && componentEl) {
      if (complexPartEl.value) updateComponentOptionsForComplex(complexPartEl.value);
      else { componentEl.innerHTML = '<option value=\"\">Сначала выберите комплексную часть</option>'; componentEl.disabled = true; }
    }
  }

  // --- collect filters ---
  function collectFilters() {
    const getVal = el => el ? String(el.value || '').trim() : '';
    const activeBtn = document.querySelector('.type-switch .switch-btn.active');
    const type = activeBtn ? activeBtn.getAttribute('data-type') : 'auto';
    const filters = { type, q: getVal(searchEl) };

    if (type === 'auto') {
      filters.vehicle_type = getVal(vehicleTypeEl);
      filters.vehicle_body = getVal(vehicleBodyEl);
      filters.brand = getVal(brandEl);
      filters.model = filters.brand ? getVal(modelEl) : '';
      filters.year_from = getVal(yearFromEl);
      filters.year_to = getVal(yearToEl);
      filters.fuel_type = getVal(fuelTypeEl);
      filters.gearbox = getVal(gearboxEl);
      filters.price_from = getVal(priceFromEl);
      filters.price_to = getVal(priceToEl);
    } else {
      filters.brand_part = getVal(brandPartEl);
      filters.model_part = filters.brand_part ? getVal(modelPartEl) : '';
      filters.complex_part = getVal(complexPartEl);
      filters.component = filters.complex_part ? getVal(componentEl) : '';
      filters.part_quality = getVal(partQualityEl);
      filters.price_from = getVal(partPriceFromEl);
      filters.price_to = getVal(partPriceToEl);
    }

    return filters;
  }

  // --- fallback renderer (keeps previous UI) ---
  function renderProductsFallback(items) {
    const container = productsContainer;
    if (!container) return;
    container.innerHTML = '';
    if (!items || items.length === 0) {
      container.innerHTML = '<div class=\"muted\">Товары не найдены.</div>';
      return;
    }
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
      title.textContent = it.name || it.title || '—';
      const sub = document.createElement('div');
      sub.className = 'product-sub';
      sub.textContent = it.brand_name ? (it.brand_name + (it.model_name ? ' ' + it.model_name : '')) : (it.type || '');
      const tags = document.createElement('div');
      tags.className = 'tags';
      if (it.year || it.year_from || it.year_to) {
        const y = it.year || (it.year_from ? (it.year_from + (it.year_to ? '–' + it.year_to : '')) : '—');
        const t = document.createElement('span'); t.className = 'tag'; t.textContent = 'Год: ' + y; tags.appendChild(t);
      }
      if (it.quality || it.part_quality || it.condition) {
        const t = document.createElement('span'); t.className = 'tag'; t.textContent = it.quality ?? it.part_quality ?? it.condition; tags.appendChild(t);
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

  // --- main loader: uses productList SDK when available ---
  async function runFilter(filtersOverride) {
    const filters = filtersOverride || collectFilters();

    // prefer productList.loadProducts
    if (window.productList && typeof window.productList.loadProducts === 'function') {
      try {
        const items = await window.productList.loadProducts(filters);
        // productList already calls onLoad / render hooks if page provided them.
        // If nothing rendered on page side, fall back to our renderer.
        if (Array.isArray(items) && items.length) {
          // if page hasn't provided a custom renderer, productList will not render.
          // We check presence of a known hook; if none — use fallback.
          const hasPageRenderer = (typeof productList.onLoad === 'function') || (typeof window.renderProducts === 'function');
          if (!hasPageRenderer) renderProductsFallback(items);
        } else {
          // empty list: render empty fallback
          renderProductsFallback([]);
        }
        return;
      } catch (e) {
        console.warn('productList.loadProducts failed, falling back to legacy fetch', e);
      }
    }

    // fallback legacy behaviour (should rarely happen)
    try {
      // choose legacy endpoint based on type (kept for backwards compatibility)
      let endpoint = '/mehanik/api/products.php';
      if (filters.type === 'auto') endpoint = '/mehanik/api/products_cars.php';
      else if (filters.type === 'part') endpoint = '/mehanik/api/products_parts.php';

      const params = new URLSearchParams();
      for (const k of Object.keys(filters)) {
        if (filters[k] !== null && filters[k] !== undefined && String(filters[k]).trim() !== '') params.append(k, filters[k]);
      }
      const resp = await fetch(endpoint + (params.toString() ? ('?' + params.toString()) : ''), { credentials: 'same-origin' });
      if (!resp.ok) throw new Error('network response not ok: ' + resp.status);
      const json = await resp.json();

      // merge lookups if provided
      if (json && json.lookups) {
        mergeAndPopulateLookups(json.lookups);
      }

      let items = [];
      if (json && Array.isArray(json.products)) items = json.products;
      else if (Array.isArray(json)) items = json;
      else if (json && Array.isArray(json.items)) items = json.items;

      renderProductsFallback(items);
    } catch (err) {
      console.error('runFilter fallback error:', err);
      if (productsContainer) productsContainer.innerHTML = '<div class="muted">Ошибка загрузки товаров</div>';
    }
  }

  // expose for external usage
  window.runFilter = runFilter;
  window.loadLookups = loadLookups;

  // --- UI listeners wiring ---
  function attachListeners() {
    if (brandEl && modelEl) brandEl.addEventListener('change', function(){ updateModelOptionsForBrand(this.value, modelEl); runFilter(); });
    if (brandPartEl && modelPartEl) brandPartEl.addEventListener('change', function(){ updateModelOptionsForBrand(this.value, modelPartEl); runFilter(); });

    if (modelEl) modelEl.addEventListener('change', () => runFilter());
    if (modelPartEl) modelPartEl.addEventListener('change', () => runFilter());

    if (complexPartEl && componentEl) {
      complexPartEl.addEventListener('change', function(){ updateComponentOptionsForComplex(this.value); runFilter(); });
      componentEl.addEventListener('change', () => runFilter());
    }

    const changeOn = [yearFromEl, yearToEl, priceFromEl, priceToEl, partPriceFromEl, partPriceToEl, vehicleTypeEl, vehicleBodyEl, fuelTypeEl, gearboxEl, partQualityEl];
    changeOn.forEach(el => { if (el) el.addEventListener('change', () => runFilter()); });

    if (searchEl) searchEl.addEventListener('input', debounce(()=>runFilter(), 300));

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
        runFilter();
      });
    }

    function setActiveType(type) {
      typeButtons.forEach(btn=>{
        const t = btn.getAttribute('data-type');
        const isActive = (t === type);
        btn.classList.toggle('active', isActive);
        btn.setAttribute('aria-checked', isActive ? 'true' : 'false');
      });
      if (type === 'auto') {
        if (autoFields) autoFields.classList.remove('group-hidden');
        if (partFields) partFields.classList.add('group-hidden');
      } else {
        if (autoFields) autoFields.classList.add('group-hidden');
        if (partFields) partFields.classList.remove('group-hidden');
      }
      runFilter();
    }

    typeButtons.forEach(btn => {
      btn.addEventListener('click', () => {
        const t = btn.getAttribute('data-type');
        setActiveType(t);
      });
    });

    // ensure initial active type if none
    const hasActive = document.querySelector('.type-switch .switch-btn.active');
    if (!hasActive) {
      const autoBtn = document.querySelector('.type-switch .switch-btn[data-type="auto"]');
      if (autoBtn) autoBtn.classList.add('active');
    }
  }

  // --- init ---
  document.addEventListener('DOMContentLoaded', async function(){
    try {
      attachListeners();
      try { await loadLookups(); } catch (e) { console.warn('loadLookups failed', e); }
      try { await runFilter(collectFilters()); } catch (e) { console.warn('initial runFilter failed', e); }
    } catch (e) {
      console.error('main.js init error', e);
    }
  });

})();
