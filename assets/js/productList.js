// assets/js/productList.js (обновлённая версия)
(function () {
  const base = '/mehanik';

  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, function (m) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m];
    });
  }

  function buildPhotoUrl(p) {
    if (!p) return base + '/assets/no-photo.png';
    if (p.startsWith('http://') || p.startsWith('https://') || p.startsWith('/')) return p;
    return base + '/uploads/products/' + p;
  }

  function productCard(p) {
    const photo = buildPhotoUrl(p.photo);
    const imgHtml = `<div class="card-photo"><img src="${escapeHtml(photo)}" alt="${escapeHtml(p.name)}"></div>`;
    const price = (p.price || p.price === 0) ? Number(p.price).toFixed(2) : '0.00';
    const brandModel = [p.brand_name ?? p.brand, p.model_name ?? p.model].filter(Boolean).join(' ');
    const sku = p.sku ? ` · ${escapeHtml(p.sku)}` : '';
    const productUrl = `${base}/public/product.php?id=${encodeURIComponent(p.id)}`;
    return `
      <article class="card product-card">
        ${imgHtml}
        <div class="card-body">
          <h4 class="pc-title">${escapeHtml(p.name || '—')}</h4>
          <div class="pc-meta">
            <span class="pc-price">${price} ₽</span>
            <span class="pc-sku">ID: ${escapeHtml(String(p.id))}${sku}</span>
          </div>
          <div class="pc-sub">${escapeHtml(brandModel)}</div>
          <div class="pc-bottom">
            <a class="btn btn-sm" href="${productUrl}">Подробнее</a>
          </div>
        </div>
      </article>
    `;
  }

  function renderProducts(container, items) {
    if (!container) return;
    if (!items || items.length === 0) {
      container.innerHTML = '<div class="muted">Товары не найдены.</div>';
      return;
    }
    container.innerHTML = items.map(productCard).join('');
  }

  // расширенный fillLookups: brands/models/complex_parts/components + vehicle types/bodies/fuel/gearboxes/years
  function fillLookups(lookups) {
    if (!lookups) return;
    const $ = s => document.querySelector(s);

    const prev = {
      brand: $('#brand') ? $('#brand').value : '',
      model: $('#model') ? $('#model').value : '',
      brand_part: $('#brand_part') ? $('#brand_part').value : '',
      model_part: $('#model_part') ? $('#model_part').value : '',
      complex_part: $('#complex_part') ? $('#complex_part').value : '',
      component: $('#component') ? $('#component').value : '',
      fuel_type: $('#fuel_type') ? $('#fuel_type').value : '',
      gearbox: $('#gearbox') ? $('#gearbox').value : '',
      vehicle_type: $('#vehicle_type') ? $('#vehicle_type').value : '',
      vehicle_body: $('#vehicle_body') ? $('#vehicle_body').value : ''
    };

    // brands
    if (Array.isArray(lookups.brands) && $('#brand')) {
      const sel = $('#brand');
      sel.innerHTML = `<option value="">Все бренды</option>` + lookups.brands.map(b => `<option value="${escapeHtml(b.id ?? b.value ?? b.name ?? b)}">${escapeHtml(b.name ?? b)}</option>`).join('');
      if (prev.brand) sel.value = prev.brand;
    }
    if (Array.isArray(lookups.brands) && $('#brand_part')) {
      const sel = $('#brand_part');
      sel.innerHTML = `<option value="">Все бренды</option>` + lookups.brands.map(b => `<option value="${escapeHtml(b.id ?? b.value ?? b.name ?? b)}">${escapeHtml(b.name ?? b)}</option>`).join('');
      if (prev.brand_part) sel.value = prev.brand_part;
    }

    // models: expect lookups.models array with brand_id
    if (Array.isArray(lookups.models) && $('#model')) {
      const sel = $('#model');
      const brandSel = $('#brand');
      const fillModels = (brandId, prevModel) => {
        if (!brandId) { sel.innerHTML = `<option value="">Сначала выберите бренд</option>`; sel.disabled = true; return; }
        const filtered = lookups.models.filter(m => String(m.brand_id) === String(brandId));
        sel.innerHTML = `<option value="">Все модели</option>` + filtered.map(m => `<option value="${escapeHtml(m.id)}">${escapeHtml(m.name)}</option>`).join('');
        sel.disabled = filtered.length === 0;
        if (prevModel && Array.from(sel.options).some(o => o.value === prevModel)) sel.value = prevModel;
      };
      if (brandSel && brandSel.value) fillModels(brandSel.value, prev.model);
      else { sel.innerHTML = `<option value="">Сначала выберите бренд</option>`; sel.disabled = true; }
      if (brandSel) brandSel.addEventListener('change', () => { fillModels(brandSel.value); if (typeof runFilter === 'function') runFilter(); });
    }

    if (Array.isArray(lookups.models) && $('#model_part')) {
      const sel = $('#model_part');
      const brandSel = $('#brand_part');
      const fillModels = (brandId, prevModel) => {
        if (!brandId) { sel.innerHTML = `<option value="">Сначала выберите бренд</option>`; sel.disabled = true; return; }
        const filtered = lookups.models.filter(m => String(m.brand_id) === String(brandId));
        sel.innerHTML = `<option value="">Все модели</option>` + filtered.map(m => `<option value="${escapeHtml(m.id)}">${escapeHtml(m.name)}</option>`).join('');
        sel.disabled = filtered.length === 0;
        if (prevModel && Array.from(sel.options).some(o => o.value === prevModel)) sel.value = prevModel;
      };
      if (brandSel && brandSel.value) fillModels(brandSel.value, prev.model_part);
      else { sel.innerHTML = `<option value="">Сначала выберите бренд</option>`; sel.disabled = true; }
      if (brandSel) brandSel.addEventListener('change', () => { fillModels(brandSel.value); if (typeof runFilter === 'function') runFilter(); });
    }

    // complex parts
    if (Array.isArray(lookups.complex_parts) && $('#complex_part')) {
      const sel = $('#complex_part');
      sel.innerHTML = `<option value="">Все комплексные части</option>` + lookups.complex_parts.map(cp => `<option value="${escapeHtml(cp.id)}">${escapeHtml(cp.name)}</option>`).join('');
      if (prev.complex_part) sel.value = prev.complex_part;
    }
    // components depending on complex_part
    if (Array.isArray(lookups.components) && $('#component')) {
      const sel = $('#component');
      const cpSel = $('#complex_part');
      const fillComps = (cpId, prevComp) => {
        if (!cpId) { sel.innerHTML = `<option value="">Сначала выберите комплексную часть</option>`; sel.disabled = true; return; }
        const filtered = lookups.components.filter(c => String(c.complex_part_id) === String(cpId));
        sel.innerHTML = `<option value="">Все компоненты</option>` + filtered.map(c => `<option value="${escapeHtml(c.id)}">${escapeHtml(c.name)}</option>`).join('');
        sel.disabled = filtered.length === 0;
        if (prevComp && Array.from(sel.options).some(o => o.value === prevComp)) sel.value = prevComp;
      };
      if (cpSel && cpSel.value) fillComps(cpSel.value, prev.component);
      else { sel.innerHTML = `<option value="">Сначала выберите комплексную часть</option>`; sel.disabled = true; }
      if (cpSel) cpSel.addEventListener('change', () => { fillComps(cpSel.value); if (typeof runFilter === 'function') runFilter(); });
    }

    // vehicle types/bodies/fuel/gearboxes
    if (Array.isArray(lookups.vehicle_types) && document.querySelector('#vehicle_type')) {
      const sel = document.querySelector('#vehicle_type');
      sel.innerHTML = `<option value="">Все типы</option>` + lookups.vehicle_types.map(x => `<option value="${escapeHtml(x.id ?? x.key ?? x.name ?? x)}">${escapeHtml(x.name ?? x)}</option>`).join('');
      if (prev.vehicle_type) sel.value = prev.vehicle_type;
    }
    if (Array.isArray(lookups.vehicle_bodies) && document.querySelector('#vehicle_body')) {
      const sel = document.querySelector('#vehicle_body');
      sel.innerHTML = `<option value="">Все кузова</option>` + lookups.vehicle_bodies.map(x => `<option value="${escapeHtml(x.id ?? x.key ?? x.name ?? x)}">${escapeHtml(x.name ?? x)}</option>`).join('');
      if (prev.vehicle_body) sel.value = prev.vehicle_body;
    }
    if (Array.isArray(lookups.fuel_types) && document.querySelector('#fuel_type')) {
      const sel = document.querySelector('#fuel_type');
      sel.innerHTML = `<option value="">Любое</option>` + lookups.fuel_types.map(x => `<option value="${escapeHtml(x.id ?? x.key ?? x.name ?? x)}">${escapeHtml(x.name ?? x)}</option>`).join('');
      if (prev.fuel_type) sel.value = prev.fuel_type;
    }
    if (Array.isArray(lookups.gearboxes) && document.querySelector('#gearbox')) {
      const sel = document.querySelector('#gearbox');
      sel.innerHTML = `<option value="">Любая</option>` + lookups.gearboxes.map(x => `<option value="${escapeHtml(x.id ?? x.key ?? x.name ?? x)}">${escapeHtml(x.name ?? x)}</option>`).join('');
      if (prev.gearbox) sel.value = prev.gearbox;
    }

    // if API returned vehicle_years we could convert to selects — currently year_from/year_to are inputs
    if (Array.isArray(lookups.vehicle_years)) {
      // no-op for now (optionally fill year_from/year_to datalist)
    }
  }

  // small helper: build query params from filters object
  function buildQueryFromFilters(filters) {
    const params = new URLSearchParams();
    if (!filters) return params;
    if (filters instanceof URLSearchParams) {
      for (const [k, v] of filters.entries()) if (v !== null && String(v).trim() !== '') params.append(k, v);
      return params;
    }
    Object.keys(filters).forEach(k => {
      const v = filters[k];
      if (v !== null && v !== undefined && String(v).trim() !== '') params.append(k, String(v).trim());
    });
    return params;
  }

  // heurистика для определения типа товара по полям записи
  function productMatchesType(item, type) {
    if (!type) return true;
    const t = String(type).toLowerCase();
    // check explicit flags / fields
    const fields = [
      item.type, item.kind, item.category, item.product_type, item.item_type,
      item.is_part, item.is_auto, item.is_vehicle
    ];
    // normalize booleans
    for (const f of fields) {
      if (typeof f === 'string') {
        const s = f.toLowerCase();
        if ((t === 'auto' || t === 'авто' || t === 'car' || t === 'vehicle') && (s === 'auto' || s === 'vehicle' || s === 'car')) return true;
        if ((t === 'part' || t === 'запчасть' || t === 'part') && (s === 'part' || s === 'component' || s === 'part')) return true;
      } else if (typeof f === 'boolean') {
        if ((t === 'auto' && (f === true && (item.is_vehicle || item.is_auto))) ) {}
      }
    }
    // check boolean fields explicitly
    if (t === 'part' || t === 'запчасть') {
      // try common flags
      if (item.is_part === true || item.is_part === 1 || String(item.is_part) === '1') return true;
      // check category fields
      const cat = (item.category || item.type || item.kind || '').toString().toLowerCase();
      if (cat.includes('part') || cat.includes('component') || cat.includes('запчаст')) return true;
      return false;
    }
    if (t === 'auto' || t === 'авто' || t === 'vehicle') {
      if (item.is_part === true || item.is_part === 1 || String(item.is_part) === '1') return false;
      const cat = (item.category || item.type || item.kind || '').toString().toLowerCase();
      if (cat.includes('car') || cat.includes('auto') || cat.includes('vehicle') || cat.includes('машин') || cat.includes('авто')) return true;
      // fallback: if item has brand/model/year fields it's likely an auto
      if (item.brand_name || item.model_name || item.year) return true;
      return false;
    }
    return true;
  }

  // loadProducts: поддерживает client-side фильтрацию по type (на случай, если сервер вернул смешанные записи)
  async function loadProducts(filters = {}) {
    const container = document.getElementById('products');
    if (!container) return null;
    try {
      const params = buildQueryFromFilters(filters);
      const url = '/mehanik/api/products.php' + (params.toString() ? ('?' + params.toString()) : '');
      const res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) throw new Error('network');
      const data = await res.json();

      // если есть lookups — подставим (позволит безопасно наполнять селекты)
      if (data && data.lookups) fillLookups(data.lookups);

      // извлечём products
      let products = [];
      if (Array.isArray(data)) products = data;
      else if (data && Array.isArray(data.products)) products = data.products;
      else if (data && data.items && Array.isArray(data.items)) products = data.items;
      else if (data && typeof data === 'object' && data.products && typeof data.products === 'object') products = Object.values(data.products);

      // client-side фильтрация по type (если filters.type задан)
      let t = null;
      if (filters instanceof URLSearchParams) t = filters.get('type');
      else if (filters && typeof filters === 'object') t = filters.type;
      if (t) {
        products = products.filter(it => productMatchesType(it, t));
      }

      renderProducts(container, products);
      return data;
    } catch (err) {
      console.error('Ошибка loadProducts:', err);
      if (container) container.innerHTML = `<div class="muted">Ошибка загрузки товаров</div>`;
      return null;
    }
  }

  window.productList = {
    productCard,
    renderProducts,
    loadProducts,
    fillLookups
  };
})();
