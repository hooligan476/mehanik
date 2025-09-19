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
      aImg.style.width = '120px';
      aImg.style.height = '80px';
      aImg.style.flex = '0 0 120px';
      const img = document.createElement('img');
      img.className = 'product-media';
      img.alt = item.name || item.title || '';
      img.src = photo;
      aImg.appendChild(img);
      thumb.appendChild(aImg);

      const content = document.createElement('div');
      content.className = 'product-content';

      // title / manufacturer
      const title = document.createElement('div');
      title.className = 'product-title';
      title.textContent = item.name || item.title ||
        (item.brand_name ? (item.brand_name + (item.model_name ? ' ' + item.model_name : '')) : '—');

      const sub = document.createElement('div');
      sub.className = 'product-sub';
      sub.textContent = (item.manufacturer || item.brand_name || item.complex_part_name || item.type || '').toString();

      // SKU / Article: remove leading "SKU-" for display, add copy button and link to product
      const rawSku = (item.sku || item.article || item.code || '').toString();
      const displaySku = rawSku.replace(/^SKU-/i, ''); // only for display

      if (displaySku) {
        const skuWrap = document.createElement('div');
        skuWrap.className = 'product-sku';
        skuWrap.style.display = 'flex';
        skuWrap.style.alignItems = 'center';
        skuWrap.style.gap = '8px';
        skuWrap.style.marginTop = '6px';

        const skuLink = document.createElement('a');
        skuLink.href = item.url || ('/mehanik/public/product.php?id=' + encodeURIComponent(item.id || ''));
        skuLink.textContent = displaySku;
        skuLink.title = 'Перейти к товару';
        skuLink.style.fontWeight = '600';
        skuLink.style.color = 'inherit';
        skuLink.style.textDecoration = 'underline';
        skuWrap.appendChild(skuLink);

        const copyBtn = document.createElement('button');
        copyBtn.type = 'button';
        copyBtn.className = 'btn-copy-sku';
        copyBtn.textContent = '📋';
        copyBtn.title = 'Копировать артикул';
        copyBtn.style.padding = '4px 8px';
        copyBtn.style.borderRadius = '6px';
        copyBtn.style.border = '1px solid #e6e9ef';
        copyBtn.style.background = '#fff';
        copyBtn.style.cursor = 'pointer';
        copyBtn.addEventListener('click', function(e){
          e.preventDefault();
          const text = displaySku;
          if (!text) return;
          // try Clipboard API first
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(()=> {
              const prev = copyBtn.textContent;
              copyBtn.textContent = '✓';
              setTimeout(()=> copyBtn.textContent = prev, 1200);
            }).catch(err => {
              // fallback
              fallbackCopy(text, copyBtn);
            });
          } else {
            fallbackCopy(text, copyBtn);
          }
        });
        skuWrap.appendChild(copyBtn);

        // helper fallback copy
        function fallbackCopy(text, btn){
          try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'absolute';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            const ok = document.execCommand('copy');
            document.body.removeChild(ta);
            if (ok) {
              const prev = btn.textContent;
              btn.textContent = '✓';
              setTimeout(()=> btn.textContent = prev, 1200);
            } else {
              alert('Не удалось скопировать артикул');
            }
          } catch(e) {
            alert('Копирование не поддерживается в этом браузере');
          }
        }

        // append skuWrap after subtitle (so it is visible under manufacturer)
        content.appendChild(title);
        content.appendChild(sub);
        content.appendChild(skuWrap);
      } else {
        // no SKU present, just append title and sub
        content.appendChild(title);
        content.appendChild(sub);
      }

      // tags: brand/model + complex/component + years + quality
      const tags = document.createElement('div');
      tags.className = 'tags';

      // brand / model (prefer fields if present)
      const brand = item.brand || item.brand_name || '';
      const model = item.model || item.model_name || '';
      if (brand || model) {
        const t = document.createElement('span');
        t.className = 'tag';
        t.textContent = (brand ? brand : '') + (brand && model ? ' / ' : '') + (model ? model : '');
        tags.appendChild(t);
      }

      // complex part / component
      const complex = item.complex_part || item.complex_part_name || item.complex_part_label || '';
      const comp = item.component || item.component_name || item.component_label || '';
      if (complex || comp) {
        const t2 = document.createElement('span');
        t2.className = 'tag';
        t2.textContent = (complex ? complex : '') + (complex && comp ? ' / ' : '') + (comp ? comp : '');
        tags.appendChild(t2);
      }

      // years
      if (item.year || item.year_from || item.year_to) {
        const y = item.year || (item.year_from ? (item.year_from + (item.year_to ? '–' + item.year_to : '')) : '');
        if (y) { const t3 = document.createElement('span'); t3.className = 'tag'; t3.textContent = 'Год: ' + y; tags.appendChild(t3); }
      }

      // quality
      const quality = item.quality || item.part_quality || item.condition;
      if (quality) { const tq = document.createElement('span'); tq.className = 'tag'; tq.textContent = quality; tags.appendChild(tq); }

      // price / meta row
      const row = document.createElement('div'); row.className = 'product-row';
      const price = document.createElement('div'); price.className = 'price';
      try {
        if (item.price !== undefined && item.price !== null && item.price !== '') {
          const num = Number(item.price);
          price.textContent = (isFinite(num) ? num.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 2}) : item.price) + ' TMT';
        } else price.textContent = '—';
      } catch(e){ price.textContent = item.price ? String(item.price) : '—'; }
      const meta = document.createElement('div'); meta.className = 'meta'; meta.textContent = 'ID: ' + (item.id || '-');
      row.appendChild(price); row.appendChild(meta);

      // badges / status + added date
      const badges = document.createElement('div'); badges.className = 'badges';
      const statusRaw = String(item.status || (item.state || '')).toLowerCase();
      let sclass = 'pending', slabel = 'На модерации';
      if (statusRaw.indexOf('approve') !== -1 || statusRaw.indexOf('active') !== -1 || statusRaw.indexOf('ok') !== -1) { sclass = 'ok'; slabel = 'Подтверждён'; }
      else if (statusRaw.indexOf('reject') !== -1 || statusRaw.indexOf('отклон') !== -1) { sclass = 'rej'; slabel = 'Отклонён'; }
      const sdiv = document.createElement('div'); sdiv.className = 'badge ' + sclass; sdiv.textContent = slabel;
      badges.appendChild(sdiv);
      const added = document.createElement('div'); added.className = 'meta';
      added.style.background = '#f3f5f8'; added.style.padding = '6px 8px'; added.style.borderRadius = '8px'; added.style.color = '#334155';
      added.textContent = 'Добавлен: ' + (item.created_at ? (new Date(item.created_at).toLocaleDateString()) : '-');
      badges.appendChild(added);

      // assemble content (if sku was appended earlier we already pushed title/sub/sku)
      // but ensure tags/row/badges are appended
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

      // dummy Super / Premium buttons (placeholders for paid highlighting)
      const superBtn = document.createElement('button');
      superBtn.type = 'button';
      superBtn.className = 'btn btn-super';
      superBtn.textContent = '★ Super';
      superBtn.addEventListener('click', function(e){
        e.preventDefault();
        alert('Super: функция заглушка — позже подключим оплату/подсветку товара.');
      });
      actions.appendChild(superBtn);

      const premiumBtn = document.createElement('button');
      premiumBtn.type = 'button';
      premiumBtn.className = 'btn btn-premium';
      premiumBtn.textContent = '✨ Premium';
      premiumBtn.addEventListener('click', function(e){
        e.preventDefault();
        alert('Premium: функция заглушка — позже подключим оплату/выделение.');
      });
      actions.appendChild(premiumBtn);

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
            const isCar = (item.type && String(item.type).toLowerCase().includes('auto')) || (item.complex_part_id == null && item.component_id == null && (item.vehicle_type || item.vehicle_body || item.year));
            const url = isCar ? '/mehanik/api/delete-car.php' : '/mehanik/api/delete-product.php';
            const resp = await fetch(url, { method: 'POST', credentials: 'same-origin', body: fd });
            if (!resp.ok) throw new Error('network error ' + resp.status);
            const j = await resp.json().catch(()=>null);
            if (j && (j.success || j.ok)) {
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

      // owner/meta column (right side) — оставляем пустым для будущих данных
      const ownerWrap = document.createElement('div');
      ownerWrap.style.fontSize = '.85rem';
      ownerWrap.style.color = '#6b7280';
      ownerWrap.textContent = '';
      footer.appendChild(ownerWrap);

      // assemble card
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
