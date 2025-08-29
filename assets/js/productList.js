// assets/js/productList.js
// Универсальный рендерер карточек + загрузчик товаров и lookups из /mehanik/api/products.php

(function () {
  const base = '/mehanik';

  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, function (m) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m];
    });
  }

  function buildPhotoUrl(p) {
    if (!p) return base + '/assets/no-photo.png';
    // если абсолютный или начинается с / — возьмём как есть
    if (p.startsWith('http://') || p.startsWith('https://') || p.startsWith('/')) return p;
    // иначе считаем, что в БД хранится имя файла
    return base + '/uploads/products/' + p;
  }

  function productCard(p) {
    const photo = buildPhotoUrl(p.photo);
    const imgHtml = `<div class="card-photo"><img src="${escapeHtml(photo)}" alt="${escapeHtml(p.name)}"></div>`;
    const price = (p.price || p.price === 0) ? Number(p.price).toFixed(2) : '0.00';
    const availability = p.availability ?? 0;
    const brandModel = [p.brand_name, p.model_name].filter(Boolean).join(' ');
    const sku = p.sku ? ` · ${escapeHtml(p.sku)}` : '';
    const desc = p.description ? escapeHtml(String(p.description)).slice(0, 140) + (p.description.length > 140 ? '…' : '') : '';
    const productUrl = `${base}/public/product.php?id=${encodeURIComponent(p.id)}`;

    return `
      <article class="card product-card">
        ${imgHtml}
        <div class="card-body">
          <h4 class="pc-title">${escapeHtml(p.name || '—')}</h4>
          <div class="pc-meta">
            <span class="pc-price">${price} TMT</span>
            <span class="pc-sku">ID: ${escapeHtml(String(p.id))}${sku}</span>
          </div>
          <div class="pc-sub">${escapeHtml(brandModel)}</div>
          <p class="pc-desc">${desc}</p>
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

  // Заполняет селекты lookups: brands/models/complex_parts/components
  function fillLookups(lookups) {
    if (!lookups) return;

    const $ = s => document.querySelector(s);
    const prev = {
      brand: $('#brand') ? $('#brand').value : '',
      model: $('#model') ? $('#model').value : '',
      complex_part: $('#complex_part') ? $('#complex_part').value : '',
      component: $('#component') ? $('#component').value : ''
    };

    // brands
    if (Array.isArray(lookups.brands) && document.querySelector('#brand')) {
      const sel = document.querySelector('#brand');
      sel.innerHTML = `<option value="">Все бренды</option>` +
        lookups.brands.map(b => `<option value="${escapeHtml(b.id)}">${escapeHtml(b.name)}</option>`).join('');
      if (prev.brand) sel.value = prev.brand;
    }

    // models (with brand relation if provided)
    if (Array.isArray(lookups.models) && document.querySelector('#model')) {
      const sel = document.querySelector('#model');
      const fillModels = (brandId) => {
        sel.innerHTML = `<option value="">Все модели</option>` +
          lookups.models
            .filter(m => !brandId || String(m.brand_id) === String(brandId))
            .map(m => `<option value="${escapeHtml(m.id)}">${escapeHtml(m.name)}</option>`).join('');
        if (prev.model) sel.value = prev.model;
      };
      const brandSel = document.querySelector('#brand');
      fillModels(brandSel ? brandSel.value : null);
      if (brandSel) {
        brandSel.addEventListener('change', () => fillModels(brandSel.value));
      }
    }

    // complex parts
    if (Array.isArray(lookups.complex_parts) && document.querySelector('#complex_part')) {
      const sel = document.querySelector('#complex_part');
      sel.innerHTML = `<option value="">Все комплексные части</option>` +
        lookups.complex_parts.map(cp => `<option value="${escapeHtml(cp.id)}">${escapeHtml(cp.name)}</option>`).join('');
      if (prev.complex_part) sel.value = prev.complex_part;
    }

    // components (depend on complex_part)
    if (Array.isArray(lookups.components) && document.querySelector('#component')) {
      const sel = document.querySelector('#component');
      const fillComps = (cpId) => {
        sel.innerHTML = `<option value="">Все компоненты</option>` +
          lookups.components
            .filter(c => !cpId || String(c.complex_part_id) === String(cpId))
            .map(c => `<option value="${escapeHtml(c.id)}">${escapeHtml(c.name)}</option>`).join('');
        if (prev.component) sel.value = prev.component;
      };
      const cpSel = document.querySelector('#complex_part');
      fillComps(cpSel ? cpSel.value : null);
      if (cpSel) cpSel.addEventListener('change', () => fillComps(cpSel.value));
    }
  }

  // Загружает товары + lookups. filters - объект {brand, model, ...} или URLSearchParams
  async function loadProducts(filters = {}) {
    const container = document.getElementById('products');
    if (!container) return null;

    let qs;
    if (filters instanceof URLSearchParams) qs = filters.toString();
    else qs = new URLSearchParams(filters).toString();

    try {
      const url = '/mehanik/api/products.php' + (qs ? ('?' + qs) : '');
      const res = await fetch(url);
      if (!res.ok) throw new Error('network');
      const data = await res.json();

      // data может быть: { ok, products, lookups } или просто array / object
      let products = [];
      if (Array.isArray(data)) {
        products = data;
      } else if (data && Array.isArray(data.products)) {
        products = data.products;
      } else if (data && data.products && typeof data.products === 'object') {
        // иногда products может быть объект с ключами — превратим в массив
        products = Object.values(data.products);
      }

      // если есть lookups — подставим в селекты
      if (data && data.lookups) {
        fillLookups(data.lookups);
      }

      renderProducts(container, products);
      return data;
    } catch (err) {
      console.error('Ошибка loadProducts:', err);
      container.innerHTML = `<div class="muted">Ошибка загрузки товаров</div>`;
      return null;
    }
  }

  // экспорт в глобальную область
  window.productList = {
    productCard,
    renderProducts,
    loadProducts,
    fillLookups
  };
})();
