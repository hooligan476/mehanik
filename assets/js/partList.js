(function(window){
  'use strict';

  const partList = {
    lookups: {},
    lastFilters: null,
    lastProducts: [],

    _ctx(){
      return {
        currentUserId: (typeof window.currentUserId !== 'undefined') ? String(window.currentUserId) : null,
        uploadsPrefix: (typeof window.uploadsPrefix !== 'undefined') ? window.uploadsPrefix : '/mehanik/uploads/',
        noPhoto: (typeof window.noPhoto !== 'undefined') ? window.noPhoto : '/mehanik/assets/no-photo.png'
      };
    },

    fillLookups(data){ if(!data) return; this.lookups = Object.assign(this.lookups||{}, data); },

    // Render a product/part card (no edit/delete)
    _renderCard(item){
      const ctx = this._ctx();
      const wrapper = document.createElement('article'); wrapper.className = 'product-card';
      const thumb = document.createElement('div'); thumb.className = 'product-media product-media--wrap';
      const a = document.createElement('a'); a.href = item.url || ('/mehanik/public/product.php?id=' + encodeURIComponent(item.id || ''));
      const img = document.createElement('img'); let photo = item.photo || item.logo || '';
      if (!photo) photo = ctx.noPhoto; else if (!(photo.startsWith('/') || /^https?:\/\//i.test(photo))) photo = ctx.uploadsPrefix + photo;
      img.src = photo; img.alt = item.name || item.title || ''; a.appendChild(img); thumb.appendChild(a);

      const content = document.createElement('div'); content.className = 'product-content';
      const title = document.createElement('div'); title.className = 'product-title'; title.textContent = item.name || item.title || '—';
      const sub = document.createElement('div'); sub.className = 'product-sub'; sub.textContent = item.manufacturer || item.brand_name || '';
      content.appendChild(title); content.appendChild(sub);

      // SKU
      const rawSku = (item.sku || item.article || item.code || '') + '';
      if (rawSku.trim() !== '') {
        const skuWrap = document.createElement('div'); skuWrap.className = 'product-sku';
        const skuLabel = document.createElement('span'); skuLabel.className='sku-label'; skuLabel.textContent='Артикул:'; skuLabel.style.fontWeight='600';
        const skuLink = document.createElement('a'); skuLink.href = item.url || ('/mehanik/public/product.php?id=' + encodeURIComponent(item.id || '')); skuLink.textContent = rawSku; skuLink.style.fontWeight='600'; skuLink.style.textDecoration='underline';
        skuWrap.appendChild(skuLabel); skuWrap.appendChild(skuLink);
        content.appendChild(skuWrap);
      }

      // price/meta
      const row = document.createElement('div'); row.className = 'product-row';
      const price = document.createElement('div'); price.className='price'; price.textContent = (item.price ? Number(item.price).toLocaleString() + ' TMT' : '—');
      const meta = document.createElement('div'); meta.className='meta'; meta.textContent = 'ID: ' + (item.id || '-');
      row.appendChild(price); row.appendChild(meta);
      content.appendChild(row);

      // badges
      const badges = document.createElement('div'); badges.className='badges';
      const statusRaw = String(item.status || '').toLowerCase();
      let sclass='pending', slabel='На модерации';
      if (statusRaw.indexOf('approve') !== -1 || statusRaw.indexOf('active') !== -1) { sclass='ok'; slabel='Подтверждён'; }
      else if (statusRaw.indexOf('reject') !== -1) { sclass='rej'; slabel='Отклонён'; }
      const sdiv = document.createElement('div'); sdiv.className='badge ' + sclass; sdiv.textContent = slabel;
      badges.appendChild(sdiv);
      const added = document.createElement('div'); added.className='meta'; added.style.background='#f3f5f8'; added.style.padding='6px 8px'; added.style.borderRadius='8px'; added.style.color='#334155';
      added.textContent = 'Добавлен: ' + (item.created_at ? new Date(item.created_at).toLocaleDateString() : '-');
      badges.appendChild(added);
      content.appendChild(badges);

      // footer only view
      const footer = document.createElement('div'); footer.className='card-footer';
      const actions = document.createElement('div'); actions.className='actions';
      const view = document.createElement('a'); view.href = item.url || ('/mehanik/public/product.php?id=' + encodeURIComponent(item.id || '')); view.className='btn btn-view'; view.textContent='👁 Просмотр';
      actions.appendChild(view); footer.appendChild(actions);
      wrapper.appendChild(thumb); wrapper.appendChild(content); wrapper.appendChild(footer);
      return wrapper;
    },

    async loadProducts(filters = {}){
      this.lastFilters = Object.assign({}, filters);
      const f = Object.assign({ type: 'part' }, filters);
      const qs = new URLSearchParams();
      for (const k in f) {
        if (f[k] === null || typeof f[k] === 'undefined' || String(f[k]).trim() === '') continue;
        qs.set(k, String(f[k]));
      }
      const url = '/mehanik/api/products.php' + (qs.toString() ? ('?' + qs.toString()) : '');
      const container = document.getElementById('products');
      try {
        const resp = await fetch(url, { credentials:'same-origin' });
        if (!resp.ok) { console.warn('partList: non-ok', resp.status); if (container) container.innerHTML = '<div class="muted">Товары не найдены.</div>'; return []; }
        const json = await resp.json();
        const items = json.products ?? json.items ?? [];
        if (json.lookups) this.fillLookups(json.lookups);
        this.lastProducts = items;

        if (!container) return items;
        container.innerHTML = '';
        if (!items || !items.length) { container.innerHTML = '<div class="empty"><h3 style="margin:0">По вашему запросу ничего не найдено</h3></div>'; return items; }
        for (const it of items) container.appendChild(this._renderCard(it));
        return items;
      } catch (e) {
        console.warn('partList.loadProducts error', e);
        return [];
      }
    }
  };

  window.partList = partList;
})(window);
