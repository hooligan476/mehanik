(function(window){
  'use strict';

  const carList = {
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

    // merge lookups returned from API
    fillLookups(data){
      if(!data) return;
      this.lookups = Object.assign(this.lookups||{}, data);
      if (typeof this.onLookups === 'function') {
        try { this.onLookups(this.lookups); } catch(e){ console.warn(e); }
      }
    },

    // helper: set options into select element
    setSelectOptions(sel, items, placeholder){
      if (!sel) return;
      const prev = sel.value;
      sel.innerHTML = '';
      const o0 = document.createElement('option'); o0.value = ''; o0.textContent = placeholder || '—'; sel.appendChild(o0);
      if (!items || !items.length) return;
      for (const it of items) {
        const val = (typeof it === 'object') ? (it.id ?? it.key ?? it.value ?? it.name) : it;
        const label = (typeof it === 'object') ? (it.name ?? it.label ?? it.value) : it;
        const opt = document.createElement('option'); opt.value = String(val); opt.textContent = String(label); sel.appendChild(opt);
      }
      if (prev && Array.from(sel.options).some(o=>o.value===prev)) sel.value = prev;
    },

    // render one car card (no edit/delete buttons)
    _renderCard(item){
      const ctx = this._ctx();
      const card = document.createElement('article');
      card.className = 'card';

      // thumb
      const thumb = document.createElement('div'); thumb.className = 'thumb';
      const a = document.createElement('a'); a.href = '/mehanik/public/car.php?id='+encodeURIComponent(item.id);
      const img = document.createElement('img');
      let photo = item.photo || '';
      if (!photo) photo = ctx.noPhoto;
      else if (!(photo.startsWith('/') || /^https?:\/\//i.test(photo))) photo = ctx.uploadsPrefix + photo;
      img.src = photo; img.alt = (item.brand || '') + ' ' + (item.model || '');
      a.appendChild(img); thumb.appendChild(a);

      // body
      const body = document.createElement('div'); body.className = 'card-body';
      const row = document.createElement('div'); row.style.display='flex'; row.style.justifyContent='space-between'; row.style.gap='12px';
      const left = document.createElement('div'); left.style.flex='1';
      const title = document.createElement('div'); title.className='car-title'; title.textContent = (item.brand||'') + ' ' + (item.model||'');
      const meta = document.createElement('div'); meta.className='meta';
      meta.textContent = ((item.year)?(item.year+' г. · '):'') + ((item.mileage)?(Number(item.mileage).toLocaleString()+' км · '):'') + (item.body||'-');
      left.appendChild(title); left.appendChild(meta);
      const right = document.createElement('div'); right.style.textAlign='right';
      const price = document.createElement('div'); price.className = 'price'; price.textContent = (item.price ? (Number(item.price).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits:2}) + ' TMT') : '-');
      const idMeta = document.createElement('div'); idMeta.className='meta'; idMeta.style.marginTop='8px'; idMeta.style.fontSize='.9rem'; idMeta.textContent = 'ID: ' + (item.id||'-');
      right.appendChild(price); right.appendChild(idMeta);
      row.appendChild(left); row.appendChild(right);
      body.appendChild(row);

      // SKU
      const skuRaw = (item.sku || item.article || item.code || '') + '';
      const skuWrap = document.createElement('div'); skuWrap.style.marginTop='6px';
      if (skuRaw && skuRaw.trim() !== '') {
        const skuRow = document.createElement('div'); skuRow.className = 'sku-row';
        const skuLink = document.createElement('a'); skuLink.className = 'sku-text'; skuLink.href = '/mehanik/public/car.php?id='+encodeURIComponent(item.id); skuLink.textContent = skuRaw;
        skuLink.title = 'Перейти к объявлению';
        const copyBtn = document.createElement('button'); copyBtn.type='button'; copyBtn.className='sku-copy'; copyBtn.textContent='📋';
        copyBtn.addEventListener('click', function(ev){
          ev.preventDefault();
          const text = skuRow.querySelector('.sku-text').textContent.trim();
          if (!text) return;
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(()=> {
              const prev = copyBtn.textContent; copyBtn.textContent = '✓'; setTimeout(()=> copyBtn.textContent = prev, 1200);
            }).catch(()=> fallbackCopy(text, copyBtn));
          } else fallbackCopy(text, copyBtn);
        });
        skuRow.appendChild(skuLink); skuRow.appendChild(copyBtn); skuWrap.appendChild(skuRow);
      } else {
        const emptySku = document.createElement('div'); emptySku.className='meta'; emptySku.textContent = 'Артикул: —'; skuWrap.appendChild(emptySku);
      }
      body.appendChild(skuWrap);

      // badges
      const badges = document.createElement('div'); badges.className = 'badges';
      const status = document.createElement('div'); status.className = 'badge ' + ((item.status==='approved')? 'ok' : (item.status==='rejected'? 'rej':'pending'));
      status.textContent = (item.status==='approved')? 'Подтверждён' : (item.status==='rejected'? 'Отклонён' : 'На модерации');
      const added = document.createElement('div'); added.className='meta'; added.style.background='#f3f5f8'; added.style.padding='6px 8px'; added.style.borderRadius='8px'; added.style.color='#334155'; added.textContent = 'Добавлен: ' + (item.created_at? new Date(item.created_at).toLocaleDateString() : '-');
      badges.appendChild(status); badges.appendChild(added);
      body.appendChild(badges);

      // footer with only view button — NO edit/delete
      const footer = document.createElement('div'); footer.className='card-footer';
      const actions = document.createElement('div'); actions.className='actions';
      const view = document.createElement('a'); view.href = '/mehanik/public/car.php?id='+encodeURIComponent(item.id); view.textContent = '👁 Просмотр'; actions.appendChild(view);
      footer.appendChild(actions);
      footer.appendChild(document.createElement('div')); // placeholder ownerWrap
      card.appendChild(thumb); card.appendChild(body); card.appendChild(footer);

      // fallbackCopy helper
      function fallbackCopy(text, btn){
        try {
          const ta = document.createElement('textarea'); ta.value = text; ta.setAttribute('readonly',''); ta.style.position='absolute'; ta.style.left='-9999px'; document.body.appendChild(ta); ta.select(); const ok = document.execCommand('copy'); document.body.removeChild(ta);
          if (ok) { const prev = btn.textContent; btn.textContent='✓'; setTimeout(()=>btn.textContent=prev,1200); } else alert('Не удалось скопировать артикул');
        } catch(e){ alert('Копирование не поддерживается'); }
      }

      return card;
    },

    // load lookups & initialize selects — used by pages that include selects with ids:
    // brand, model, vehicle_type, body_type, fuel_type, gearbox
    async initLookups(){
      // try to get lookups from API
      try {
        const resp = await fetch('/mehanik/api/products.php?type=auto', { credentials:'same-origin' });
        if (resp.ok) {
          const json = await resp.json();
          if (json.lookups) this.fillLookups(json.lookups);
          else if (json.lookups === undefined) this.fillLookups(json); // fallback shape
        }
      } catch(e){ /* ignore */ }

      // now populate selects if present
      const brandEl = document.getElementById('brand');
      const modelEl = document.getElementById('model');
      const vehicleTypeEl = document.getElementById('vehicle_type');
      const bodyEl = document.getElementById('body_type');
      const fuelEl = document.getElementById('fuel_type');
      const gearboxEl = document.getElementById('gearbox');

      // brands
      if (brandEl && Array.isArray(this.lookups.brands)) this.setSelectOptions(brandEl, this.lookups.brands, 'Все бренды');
      // vehicle types
      if (vehicleTypeEl && Array.isArray(this.lookups.vehicle_types)) this.setSelectOptions(vehicleTypeEl, this.lookups.vehicle_types, 'Все типы');
      // vehicle bodies can be keyed object or array
      if (bodyEl) {
        if (Array.isArray(this.lookups.vehicle_bodies)) { /* leave empty until type chosen */ this.setSelectOptions(bodyEl, [], 'Сначала выберите тип'); bodyEl.disabled = true; }
        else if (this.lookups.vehicle_bodies && typeof this.lookups.vehicle_bodies === 'object') { bodyEl.innerHTML = '<option value="">Сначала выберите тип</option>'; bodyEl.disabled = true; }
      }
      if (fuelEl && Array.isArray(this.lookups.fuel_types)) this.setSelectOptions(fuelEl, this.lookups.fuel_types, 'Любое');
      if (gearboxEl && Array.isArray(this.lookups.gearboxes)) this.setSelectOptions(gearboxEl, this.lookups.gearboxes, 'Любая');

      // hook model loading when brand changes (if brand exist)
      if (brandEl) brandEl.addEventListener('change', () => this._loadModelsFor(brandEl.value, modelEl));
      if (vehicleTypeEl) vehicleTypeEl.addEventListener('change', () => this._populateBodiesFor(vehicleTypeEl.value, bodyEl));
    },

    async _loadModelsFor(brandId, modelEl){
      if (!modelEl) modelEl = document.getElementById('model');
      if (!modelEl) return;
      modelEl.innerHTML = '<option value="">Загрузка...</option>'; modelEl.disabled = true;
      if (!brandId) { modelEl.innerHTML = '<option value="">— выберите модель —</option>'; modelEl.disabled = true; return; }
      try {
        const res = await fetch('/mehanik/api/get-models.php?brand_id=' + encodeURIComponent(brandId), { credentials:'same-origin' });
        if (!res.ok) throw new Error('network');
        const data = await res.json();
        this.setSelectOptions(modelEl, Array.isArray(data)?data:[], '— выберите модель —');
        modelEl.disabled = false;
      } catch(e){
        modelEl.innerHTML = '<option value="">— выберите модель —</option>'; modelEl.disabled = false;
      }
    },

    _populateBodiesFor(typeValue, bodyEl){
      if (!bodyEl) bodyEl = document.getElementById('body_type');
      if (!bodyEl) return;
      bodyEl.innerHTML = '<option value="">— выберите кузов —</option>';
      if (!typeValue) { bodyEl.disabled = true; return; }
      const map = this.lookups.vehicle_bodies || {};
      let items = [];
      if (Array.isArray(map)) items = map;
      else items = (map[typeValue] || []);
      if (!items || !items.length) {
        // try fetch fallback
        fetch('/mehanik/api/get-bodies.php?vehicle_type=' + encodeURIComponent(typeValue), { credentials:'same-origin' })
          .then(r => r.ok ? r.json() : Promise.reject('no'))
          .then(data => { this.setSelectOptions(bodyEl, Array.isArray(data)?data:[], '— выберите кузов —'); bodyEl.disabled = false; })
          .catch(()=>{ bodyEl.disabled = false; });
        return;
      }
      this.setSelectOptions(bodyEl, items, '— выберите кузов —'); bodyEl.disabled = false;
    },

    // build query and fetch; DO NOT clear container until response returned
    async loadProducts(filters = {}){
      this.lastFilters = Object.assign({}, filters);
      // ensure type=auto
      const f = Object.assign({ type: 'auto' }, filters);
      // if onlyMine behaviour: add user_id only when requested by caller (they pass user_id param)
      const qs = new URLSearchParams();
      for (const k in f) {
        if (f[k] === null || typeof f[k] === 'undefined' || String(f[k]).trim() === '') continue;
        qs.set(k, String(f[k]));
      }
      const url = '/mehanik/api/products.php' + (qs.toString() ? ('?' + qs.toString()) : '');
      const container = document.getElementById('products');
      // keep current content until we know new data (prevents flicker)
      try {
        const resp = await fetch(url, { credentials:'same-origin' });
        if (!resp.ok) { console.warn('carList: non-ok', resp.status); this._showNoResults(container); return []; }
        const json = await resp.json();
        const items = json.products ?? json.items ?? [];
        if (json.lookups) this.fillLookups(json.lookups);
        this.lastProducts = items;

        // render
        if (!container) return items;
        container.innerHTML = '';
        if (!items || !items.length) { this._showNoResults(container); return items; }
        for (const it of items) {
          const node = this._renderCard(it);
          container.appendChild(node);
        }
        return items;
      } catch (e) {
        console.warn('carList.loadProducts error', e);
        // do not clear existing server-side items on error — leave them as-is
        return [];
      }
    },

    _showNoResults(container){
      if (!container) return;
      container.innerHTML = '<div class="empty"><h3 style="margin:0">По вашему запросу ничего не найдено</h3></div>';
    }
  };

  window.carList = carList;
})(window);
