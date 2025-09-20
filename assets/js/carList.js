// /mehanik/assets/js/carList.js
(function(window){
  'use strict';

  const carList = {
    lookups: {},
    lastProducts: [],

    _ctx(){
      return {
        currentUserId: (typeof window.currentUserId !== 'undefined') ? String(window.currentUserId) : null,
        uploadsPrefix: (typeof window.uploadsPrefix !== 'undefined') ? window.uploadsPrefix : '/mehanik/uploads/',
        noPhoto: (typeof window.noPhoto !== 'undefined') ? window.noPhoto : '/mehanik/assets/no-photo.png'
      };
    },

    setSelectOptions(sel, items, placeholder){
      if (!sel) return;
      const prev = sel.value;
      sel.innerHTML = '';
      const o0 = document.createElement('option'); o0.value = ''; o0.textContent = placeholder || '—'; sel.appendChild(o0);
      if (!items || !items.length) return;
      for (const it of items){
        // If object with id — use id as value; otherwise use name/text
        const val = (typeof it === 'object') ? (typeof it.id !== 'undefined' ? it.id : (it.key ?? it.name ?? it.value)) : it;
        const label = (typeof it === 'object') ? (it.name ?? it.label ?? it.value) : it;
        const opt = document.createElement('option');
        opt.value = String(val);
        opt.textContent = String(label);
        sel.appendChild(opt);
      }
      if (prev && Array.from(sel.options).some(o=>o.value===prev)) sel.value = prev; else sel.selectedIndex = 0;
    },

    mergeLookups(data){
      if (!data) return;
      this.lookups = Object.assign(this.lookups||{}, data);
    },

    async loadLookups(){
      // try API lookups
      try {
        const resp = await fetch('/mehanik/api/cars.php?type=auto', { credentials: 'same-origin' });
        if (resp.ok) {
          const json = await resp.json();
          if (json.lookups) this.mergeLookups(json.lookups);
        }
      } catch(e){ /* ignore */ }

      // fallback: try serverItems
      if ((!this.lookups.brands || !this.lookups.brands.length) && window.serverItems && window.serverItems.length) {
        const brands = new Map();
        const models = [];
        window.serverItems.forEach(it => {
          if (it.brand) brands.set(it.brand, { id: it.brand, name: it.brand });
          if (it.model) models.push({ id: it.model, name: it.model, brand_id: it.brand || '' });
        });
        this.lookups.brands = Array.from(brands.values());
        this.lookups.models = models;
      }

      // populate selects
      const brandEl = document.getElementById('brand');
      const modelEl = document.getElementById('model');
      const vehicleTypeEl = document.getElementById('vehicle_type');
      const vehicleBodyEl = document.getElementById('vehicle_body');
      const fuelEl = document.getElementById('fuel_type');
      const gearEl = document.getElementById('gearbox');

      this.setSelectOptions(brandEl, this.lookups.brands || [], 'Все бренды');
      this.setSelectOptions(vehicleTypeEl, this.lookups.vehicle_types || [], 'Все типы');

      // vehicle bodies default disabled until type chosen
      if (vehicleBodyEl) { vehicleBodyEl.innerHTML = '<option value="">Сначала выберите тип</option>'; vehicleBodyEl.disabled = true; }

      this.setSelectOptions(fuelEl, this.lookups.fuel_types || [], 'Любое');
      this.setSelectOptions(gearEl, this.lookups.gearboxes || [], 'Любая');

      // build modelsByBrand map
      const models = this.lookups.models || [];
      this.modelsByBrand = {};
      models.forEach(m => {
        const key = String(m.brand_id ?? m.brand ?? '');
        if (!this.modelsByBrand[key]) this.modelsByBrand[key] = [];
        this.modelsByBrand[key].push({ id: m.id ?? m.name, name: m.name ?? m.model });
      });
    },

    updateModelOptions(brandKey){
      const modelEl = document.getElementById('model');
      if (!modelEl) return;
      if (!brandKey) { modelEl.innerHTML = '<option value="">Сначала выберите бренд</option>'; modelEl.disabled = true; return; }
      const models = this.modelsByBrand[brandKey] || [];
      this.setSelectOptions(modelEl, models, 'Все модели');
      modelEl.disabled = false;
    },

    collectFilters(){
      const getVal = el => {
        if (!el) return '';
        const v = String(el.value || '').trim();
        return v;
      };
      const brandEl = document.getElementById('brand');
      const modelEl = document.getElementById('model');
      const vehicleTypeEl = document.getElementById('vehicle_type');
      const vehicleBodyEl = document.getElementById('vehicle_body');
      const fuelEl = document.getElementById('fuel_type');
      const gearEl = document.getElementById('gearbox');
      const yearFromEl = document.getElementById('year_from');
      const yearToEl = document.getElementById('year_to');
      const priceFromEl = document.getElementById('price_from');
      const priceToEl = document.getElementById('price_to');
      const searchEl = document.getElementById('search');
      const onlyMineEl = document.getElementById('onlyMine');

      const filters = { type: 'auto' };

      const brandVal = getVal(brandEl);
      if (brandVal !== '') filters.brand = brandVal;

      const modelVal = getVal(modelEl);
      if (modelVal !== '') filters.model = modelVal;

      const vt = getVal(vehicleTypeEl);
      if (vt !== '') filters.vehicle_type = vt;

      const vb = getVal(vehicleBodyEl);
      if (vb !== '') filters.vehicle_body = vb;

      const fu = getVal(fuelEl);
      if (fu !== '') filters.fuel_type = fu;

      const gr = getVal(gearEl);
      if (gr !== '') filters.gearbox = gr;

      const yf = getVal(yearFromEl);
      if (yf !== '') filters.year_from = yf;
      const yt = getVal(yearToEl);
      if (yt !== '') filters.year_to = yt;

      const pf = getVal(priceFromEl);
      if (pf !== '') filters.price_from = pf;
      const pt = getVal(priceToEl);
      if (pt !== '') filters.price_to = pt;

      const q = getVal(searchEl);
      if (q !== '') filters.q = q;

      if (onlyMineEl && onlyMineEl.checked) filters.mine = '1';

      return filters;
    },

    async loadProducts(filters = {}){
      const qs = new URLSearchParams();
      for (const k in filters) {
        if (filters[k] === null || typeof filters[k] === 'undefined' || String(filters[k]).trim() === '') continue;
        qs.set(k, String(filters[k]));
      }
      const url = '/mehanik/api/cars.php' + (qs.toString() ? ('?' + qs.toString()) : '');
      const container = document.getElementById('products');
      try {
        const resp = await fetch(url, { credentials: 'same-origin' });
        if (!resp.ok) { console.warn('carList: non-ok', resp.status); if (container) container.innerHTML = '<div class="empty">Ошибка сети</div>'; return []; }
        const json = await resp.json();
        const items = json.products ?? json.items ?? [];
        if (json.lookups) this.mergeLookups(json.lookups);
        this.lastProducts = items;
        if (!container) return items;
        container.innerHTML = '';
        if (!items || !items.length) { container.innerHTML = '<div class="empty"><h3 style="margin:0">По вашему запросу ничего не найдено</h3></div>'; return items; }

        const frag = document.createDocumentFragment();
        const ctx = this._ctx();

        for (const it of items) {
          const card = document.createElement('article'); card.className = 'card';
          const thumb = document.createElement('div'); thumb.className = 'thumb';
          const a = document.createElement('a'); a.href = '/mehanik/public/car.php?id='+encodeURIComponent(it.id);
          const img = document.createElement('img'); img.alt = (it.brand || '') + ' ' + (it.model || '');
          let photo = it.photo || '';
          if (!photo) photo = ctx.noPhoto;
          else if (photo.startsWith('/') || /^https?:\/\//i.test(photo)) photo = photo;
          else if (photo.indexOf('uploads/') === 0) photo = '/' + photo.replace(/^\/+/,'');
          else photo = (ctx.uploadsPrefix || '/mehanik/uploads/').replace(/\/$/,'') + '/' + photo.replace(/^\/+/,'');
          img.src = photo;
          a.appendChild(img); thumb.appendChild(a);

          const body = document.createElement('div'); body.className = 'card-body';
          const row = document.createElement('div'); row.style.display='flex'; row.style.justifyContent='space-between'; row.style.gap='12px';
          const left = document.createElement('div'); left.style.flex='1';
          const title = document.createElement('div'); title.className = 'car-title'; title.textContent = (it.brand||'') + ' ' + (it.model||'');
          const meta = document.createElement('div'); meta.className = 'meta';
          meta.textContent = ((it.year)?(it.year+' г. · '):'') + ((it.mileage)?(Number(it.mileage).toLocaleString()+' км · '):'') + (it.body||'-');
          left.appendChild(title); left.appendChild(meta);
          const right = document.createElement('div'); right.style.textAlign='right';
          const price = document.createElement('div'); price.className = 'price'; price.textContent = (it.price ? (Number(it.price).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits:2}) + ' TMT') : '-');
          const idMeta = document.createElement('div'); idMeta.className='meta'; idMeta.style.marginTop='8px'; idMeta.style.fontSize='.9rem'; idMeta.textContent = 'ID: ' + (it.id||'-');
          right.appendChild(price); right.appendChild(idMeta);
          row.appendChild(left); row.appendChild(right);

          body.appendChild(row);

          // SKU
          const skuRaw = (it.sku || it.article || it.code || '') + '';
          const skuWrap = document.createElement('div'); skuWrap.style.marginTop='6px';
          if (skuRaw && skuRaw.trim() !== '') {
            const skuRow = document.createElement('div'); skuRow.className = 'sku-row';
            const skuLink = document.createElement('a'); skuLink.className = 'sku-text'; skuLink.href = '/mehanik/public/car.php?id='+encodeURIComponent(it.id); skuLink.textContent = skuRaw.replace(/^SKU-/i,'').trim();
            const copyBtn = document.createElement('button'); copyBtn.type='button'; copyBtn.className='sku-copy'; copyBtn.textContent='📋';
            copyBtn.addEventListener('click', function(ev){ ev.preventDefault(); if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(skuRaw).then(()=>{ const prev = copyBtn.textContent; copyBtn.textContent='✓'; setTimeout(()=>copyBtn.textContent=prev,1200); }).catch(()=>{}); } });
            skuRow.appendChild(skuLink); skuRow.appendChild(copyBtn); skuWrap.appendChild(skuRow);
          } else {
            const emptySku = document.createElement('div'); emptySku.className='meta'; emptySku.textContent = 'Артикул: —'; skuWrap.appendChild(emptySku);
          }
          body.appendChild(skuWrap);

          const badges = document.createElement('div'); badges.className = 'badges';
          const status = document.createElement('div'); status.className = 'badge ' + ((it.status==='approved')? 'ok' : (it.status==='rejected'? 'rej':'pending'));
          status.textContent = (it.status==='approved')? 'Подтверждён' : (it.status==='rejected'? 'Отклонён' : 'На модерации');
          const added = document.createElement('div'); added.className='meta'; added.style.background='#f3f5f8'; added.style.padding='6px 8px'; added.style.borderRadius='8px'; added.style.color='#334155'; added.textContent = 'Добавлен: ' + (it.created_at? new Date(it.created_at).toLocaleDateString() : '-');
          badges.appendChild(status); badges.appendChild(added);
          body.appendChild(badges);

          const footer = document.createElement('div'); footer.className='card-footer';
          const actions = document.createElement('div'); actions.className='actions';
          const view = document.createElement('a'); view.href = '/mehanik/public/car.php?id='+encodeURIComponent(it.id); view.textContent = '👁 Просмотр';
          actions.appendChild(view);
          footer.appendChild(actions);
          footer.appendChild(document.createElement('div'));
          card.appendChild(thumb); card.appendChild(body); card.appendChild(footer);
          frag.appendChild(card);
        }

        container.appendChild(frag);
        return items;
      } catch (e) {
        console.warn('carList.loadProducts error', e);
        return [];
      }
    }
  };

  // expose
  window.carList = carList;

  // auto-init when included directly on page (optional)
  document.addEventListener('DOMContentLoaded', async function(){
    // safe guards: get elements
    const brandEl = document.getElementById('brand');
    const modelEl = document.getElementById('model');
    const vehicleTypeEl = document.getElementById('vehicle_type');
    const vehicleBodyEl = document.getElementById('vehicle_body');
    const fuelTypeEl = document.getElementById('fuel_type');
    const gearboxEl = document.getElementById('gearbox');
    const yearFromEl = document.getElementById('year_from');
    const yearToEl = document.getElementById('year_to');
    const priceFromEl = document.getElementById('price_from');
    const priceToEl = document.getElementById('price_to');
    const searchEl = document.getElementById('search');
    const onlyMineEl = document.getElementById('onlyMine');
    const clearBtn = document.getElementById('clearFilters');

    await carList.loadLookups();

    // wire events
    if (brandEl) brandEl.addEventListener('change', function(){ carList.updateModelOptions(this.value); carList.loadProducts(carList.collectFilters()); });
    if (modelEl) modelEl.addEventListener('change', () => carList.loadProducts(carList.collectFilters()));
    if (vehicleTypeEl) vehicleTypeEl.addEventListener('change', function(){ if (document.getElementById('vehicle_body')) { document.getElementById('vehicle_body').disabled = false; } carList.loadProducts(carList.collectFilters()); });
    [vehicleBodyEl, fuelTypeEl, gearboxEl, yearFromEl, yearToEl, priceFromEl, priceToEl].forEach(el=>{ if(!el) return; el.addEventListener('change', ()=>carList.loadProducts(carList.collectFilters())); });
    if (searchEl) searchEl.addEventListener('input', (function(){ let t; return function(){ clearTimeout(t); t=setTimeout(()=>carList.loadProducts(carList.collectFilters()),300); }; })());
    if (onlyMineEl) onlyMineEl.addEventListener('change', ()=>carList.loadProducts(carList.collectFilters()));
    if (clearBtn) clearBtn.addEventListener('click', function(e){ e.preventDefault(); const sel = [brandEl,modelEl,vehicleTypeEl,document.getElementById('vehicle_body'),fuelTypeEl,gearboxEl]; sel.forEach(s=>{ if(!s) return; if(s.tagName && s.tagName.toLowerCase()==='select') s.selectedIndex=0; else s.value=''; }); if (onlyMineEl) onlyMineEl.checked = true; carList.loadProducts(carList.collectFilters()); });

    // if server rendered items present — don't clobber; else fetch
    if (!window.serverItems || !window.serverItems.length) {
      carList.loadProducts(carList.collectFilters());
    }
  });

})(window);
