// /mehanik/assets/js/productList.js
(function(window){
  'use strict';

  const productList = {
    lookups: {},
    lastFilters: null,
    lastProducts: [],

    fillLookups(data){
      if(!data) return;
      this.lookups = Object.assign(this.lookups||{}, data);
      if (typeof this.onLookups === 'function') {
        try { this.onLookups(this.lookups); } catch(e){ console.warn(e); }
      }
    },

    // helper: safe values for images / user
    _getCtx(){
      return {
        currentUserId: (typeof window.currentUserId !== 'undefined') ? String(window.currentUserId) : null,
        uploadsPrefix: (typeof window.uploadsPrefix !== 'undefined') ? window.uploadsPrefix : '/mehanik/uploads/',
        noPhoto: (typeof window.noPhoto !== 'undefined') ? window.noPhoto : '/mehanik/assets/no-photo.png'
      };
    },

    // render single card element (matches styles in your CSS)
    _renderCard(item){
      const ctx = this._getCtx();
      const card = document.createElement('article');
      card.className = 'product-card';

      // image URL resolution
      let photo = item.photo || item.logo || '';
      if (!photo) photo = ctx.noPhoto;
      else if (!(photo.startsWith('/') || /^https?:\/\//i.test(photo))) photo = ctx.uploadsPrefix + photo;

      const thumb = document.createElement('div');
      thumb.className = 'product-media product-media--wrap';
      const aImg = document.createElement('a');
      aImg.href = item.url || ('/mehanik/public/product.php?id=' + encodeURIComponent(item.id || ''));
      aImg.style.display = 'block';
      aImg.style.width = '100%';
      aImg.style.height = '100%';
      const img = document.createElement('img');
      img.className = 'product-media';
      img.alt = item.name || item.title || '';
      img.src = photo;
      aImg.appendChild(img);
      thumb.appendChild(aImg);

      const content = document.createElement('div');
      content.className = 'product-content';
      // title / sub
      const title = document.createElement('div');
      title.className = 'product-title';
      title.textContent = item.name || item.title || (item.brand_name ? (item.brand_name + (item.model_name ? ' ' + item.model_name : '')) : '—');
      const sub = document.createElement('div');
      sub.className = 'product-sub';
      sub.textContent = (item.manufacturer || item.brand_name || item.complex_part_name || item.type || '').toString();

      // tags
      const tags = document.createElement('div');
      tags.className = 'tags';
      if (item.year || item.year_from || item.year_to) {
        const y = item.year || (item.year_from ? (item.year_from + (item.year_to ? '–' + item.year_to : '')) : '');
        if (y) { const t = document.createElement('span'); t.className = 'tag'; t.textContent = 'Год: ' + y; tags.appendChild(t); }
      }
      const quality = item.quality || item.part_quality || item.condition;
      if (quality) { const t = document.createElement('span'); t.className = 'tag'; t.textContent = quality; tags.appendChild(t); }

      // price / meta row
      const row = document.createElement('div'); row.className = 'product-row';
      const price = document.createElement('div'); price.className = 'price';
      // format price nicely
      try {
        if (item.price !== undefined && item.price !== null && item.price !== '') {
          const num = Number(item.price);
          price.textContent = (isFinite(num) ? num.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 2}) : item.price) + ' TMT';
        } else price.textContent = '—';
      } catch(e){ price.textContent = item.price ? String(item.price) : '—'; }
      const meta = document.createElement('div'); meta.className = 'meta'; meta.textContent = 'ID: ' + (item.id || '-');
      row.appendChild(price); row.appendChild(meta);

      // badges / status
      const badges = document.createElement('div'); badges.className = 'badges';
      const statusRaw = String(item.status || (item.state || '')).toLowerCase();
      let sclass = 'pending', slabel = 'На модерации';
      if (statusRaw.indexOf('approve') !== -1 || statusRaw.indexOf('active') !== -1 || statusRaw.indexOf('ok') !== -1) { sclass = 'ok'; slabel = 'Подтверждён'; }
      else if (statusRaw.indexOf('reject') !== -1 || statusRaw.indexOf('отклон') !== -1) { sclass = 'rej'; slabel = 'Отклонён'; }
      const sdiv = document.createElement('div'); sdiv.className = 'badge ' + sclass; sdiv.textContent = slabel;
      badges.appendChild(sdiv);
      const added = document.createElement('div'); added.className = 'meta'; added.style.background = '#f3f5f8'; added.style.padding = '6px 8px'; added.style.borderRadius = '8px'; added.style.color = '#334155';
      added.textContent = 'Добавлен: ' + (item.created_at ? (new Date(item.created_at).toLocaleDateString()) : '-');
      badges.appendChild(added);

      // compose content
      content.appendChild(title);
      content.appendChild(sub);
      content.appendChild(tags);
      content.appendChild(row);
      content.appendChild(badges);

      // footer actions
      const footer = document.createElement('div');
      footer.className = 'card-footer';
      const actions = document.createElement('div');
      actions.className = 'actions';

      const view = document.createElement('a');
      view.href = item.url || ('/mehanik/public/product.php?id=' + encodeURIComponent(item.id || ''));
      view.className = 'btn btn-view';
      view.textContent = '👁 Просмотр';
      actions.appendChild(view);

      // show edit/delete only for owner (if currentUserId known and matches)
      if (ctx.currentUserId && String(item.user_id || item.owner_id || '') === String(ctx.currentUserId)) {
        const edit = document.createElement('a');
        edit.href = item.edit_url || ( (item.type && String(item.type).toLowerCase().includes('auto')) ? ('/mehanik/public/edit-car.php?id=' + encodeURIComponent(item.id)) : ('/mehanik/public/edit-product.php?id=' + encodeURIComponent(item.id)) );
        edit.className = 'edit';
        edit.textContent = '✏ Редактировать';
        actions.appendChild(edit);

        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'del';
        del.textContent = '🗑 Удалить';
        del.addEventListener('click', async function(){
          const name = item.name || item.title || (item.brand_name ? item.brand_name + (item.model_name ? ' ' + item.model_name : '') : '');
          if (!confirm('Удалить «' + name.replace(/'/g,"\\'") + '» ?')) return;
          try {
            const fd = new FormData();
            fd.append('id', item.id);
            // determine delete endpoint
            const isCar = (item.type && String(item.type).toLowerCase().includes('auto')) || (item.complex_part_id == null && item.component_id == null && (item.vehicle_type || item.vehicle_body || item.year));
            const url = isCar ? '/mehanik/api/delete-car.php' : '/mehanik/api/delete-product.php';
            const resp = await fetch(url, { method: 'POST', credentials: 'same-origin', body: fd });
            if (!resp.ok) throw new Error('network error ' + resp.status);
            const j = await resp.json().catch(()=>null);
            if (j && (j.success || j.ok)) {
              // reload current list
              await productList.loadProducts(productList.lastFilters || {});
            } else {
              alert((j && (j.error || j.message)) ? (j.error||j.message) : 'Ошибка при удалении');
            }
          } catch (err) {
            alert('Ошибка: ' + (err.message || err));
          }
        });
        actions.appendChild(del);
      }

      footer.appendChild(actions);
      // owner/meta column (right side)
      const ownerWrap = document.createElement('div');
      ownerWrap.style.fontSize = '.85rem';
      ownerWrap.style.color = '#6b7280';
      ownerWrap.textContent = ''; // reserved (you may fill owner name or extra meta)
      footer.appendChild(ownerWrap);

      // assemble card (use same structure as your PHP cards)
      card.appendChild(thumb);
      card.appendChild(content);
      card.appendChild(footer);
      return card;
    },

    // main public method
    async loadProducts(filters = {}) {
      this.lastFilters = Object.assign({}, filters);
      const qs = new URLSearchParams();
      for (const k in filters) {
        if (filters[k] === null || typeof filters[k] === 'undefined' || String(filters[k]).trim() === '') continue;
        qs.set(k, String(filters[k]));
      }
      const url = '/mehanik/api/products.php' + (qs.toString() ? ('?' + qs.toString()) : '');
      try {
        const resp = await fetch(url, { credentials: 'same-origin' });
        if (!resp.ok) { console.warn('productList: non-ok', resp.status); return []; }
        const json = await resp.json();
        const items = json.products ?? json.items ?? [];
        if (json.lookups) this.fillLookups(json.lookups);
        this.lastProducts = items;

        // if page provides onLoad hook, prefer it
        if (typeof this.onLoad === 'function') {
          try { await this.onLoad(items, filters, json); } catch(e){ console.warn('onLoad error', e); }
          return items;
        }

        // if global renderProducts exists, call it (legacy)
        if (typeof window.renderProducts === 'function') {
          try { window.renderProducts(items, filters, json); return items; } catch(e){ console.warn('renderProducts error', e); }
        }

        // default: render nice cards into #products
        const container = document.getElementById('products');
        if (!container) return items;
        container.innerHTML = '';
        if (!items || !items.length) {
          container.innerHTML = '<div class="muted">Товары не найдены.</div>';
          return items;
        }
        for (const it of items) {
          const node = this._renderCard(it);
          container.appendChild(node);
        }
        return items;
      } catch (e) {
        console.warn('productList.loadProducts error', e);
        return [];
      }
    }
  };

  window.productList = productList;
})(window);
