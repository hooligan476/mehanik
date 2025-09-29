<?php
// public/my-products.php
// Внимание: здесь НЕ присутствует параметр `mine` и ничего, что с ним связано.
require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php';

// ID текущего пользователя (если нужен в шаблоне)
$user_id = (int)($_SESSION['user']['id'] ?? 0);

$noPhoto = '/mehanik/assets/no-photo.png';
$uploadsPrefix = '/mehanik/uploads/products/';
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Товары — Mehanik</title>

  <style>
:root{
  --bg: #f6f8fb;
  --card-bg: #ffffff;
  --muted: #6b7280;
  --accent: #0b57a4;
  --danger: #ef4444;
  --ok: #15803d;
  --pending: #b45309;
  --glass: rgba(255,255,255,0.6);
  --radius: 10px;
}
*{box-sizing:border-box}
html,body{height:100%;margin:0;background:var(--bg);font-family:system-ui, Arial, sans-serif;color:#0f172a}
.page-wrap{max-width:1200px;margin:18px auto;padding:12px}
.topbar-row{display:flex;gap:12px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
.page-title{margin:0;font-size:1.25rem;display:flex;align-items:center;gap:12px;font-weight:700}
.tools{margin-left:auto;display:flex;gap:8px;align-items:center}

/* Layout */
.layout{display:grid;grid-template-columns:280px 1fr;gap:18px}
@media(max-width:1000px){.layout{grid-template-columns:1fr}}

/* Sidebar */
.sidebar{background:var(--card-bg);padding:14px;border-radius:12px;box-shadow:0 8px 24px rgba(2,6,23,0.04)}
.form-row{margin-top:10px;display:flex;flex-direction:column;gap:6px}
.form-row label{font-weight:700;color:#334155}
.form-row select,.form-row input{padding:8px;border-radius:8px;border:1px solid #e6eef7;background:linear-gradient(#fff,#fbfdff)}
.controls-row{display:flex;gap:8px;align-items:center;margin-top:12px}

/* Products list — vertical list */
#products, .products{display:flex;flex-direction:column;gap:10px;padding:6px 0}

/* Card */
.prod-card, .card{display:flex;flex-direction:row;align-items:center;gap:12px;padding:10px;border-radius:12px;background:var(--card-bg);box-shadow:0 6px 18px rgba(2,6,23,0.06);border:1px solid rgba(15,23,42,0.04);transition:transform .12s ease,box-shadow .12s ease;overflow:hidden}
.prod-card:hover,.card:hover{transform:translateY(-6px);box-shadow:0 14px 30px rgba(2,6,23,0.10)}

/* Thumbnail */
.thumb, .card img{flex:0 0 96px;width:96px;height:64px;border-radius:8px;overflow:hidden;background:#f7f9fc;display:flex;align-items:center;justify-content:center}
.thumb img, .card img{width:auto;height:100%;object-fit:contain;display:block}

/* Main content */
.card-body, .product-content{padding:0 6px 0 0;flex:1 1 auto;min-width:0;display:flex;flex-direction:column;gap:6px}
.title{font-size:1rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#0f172a}
.product-sub,.meta{font-size:0.88rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* New: title row and manufacturer */
.title-row{display:flex;align-items:center;justify-content:space-between;gap:8px}
.product-manufacturer{font-size:0.9rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px;text-align:right;font-weight:600}

/* SKU link / copy */
.sku-wrap{display:flex;align-items:center;gap:8px;margin-top:4px}
.sku-link{font-weight:600;color:var(--accent);text-decoration:underline;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.btn-copy-sku{padding:4px 8px;border-radius:6px;border:1px solid #e6e9ef;background:#fff;cursor:pointer;font-size:0.9rem}

/* price column */
.price-row{display:flex;justify-content:space-between;align-items:center;gap:12px}
.price{font-weight:800;font-size:0.98rem;color:var(--accent);white-space:nowrap}

/* compact badges */
.badges{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:4px}
.badge{padding:5px 8px;border-radius:999px;font-weight:700;font-size:0.78rem;color:#fff}
.badge.ok{background:var(--ok)}.badge.rej{background:var(--danger)}.badge.pending{background:var(--pending)}
.badges .meta{background:#f3f5f8;padding:6px 8px;border-radius:8px;color:#334155}

/* Footer / actions */
.card-footer{border-top:0;padding:0;margin-left:12px;display:flex;gap:8px;align-items:center;justify-content:flex-end;min-width:150px;flex:0 0 auto}
.actions{display:flex;gap:8px;align-items:center}
.actions a,.actions button{padding:6px 8px;border-radius:8px;border:0;cursor:pointer;font-size:0.88rem}
.btn-view{background:#eef6ff;color:var(--accent);border:1px solid rgba(11,87,164,0.06)}

/* No-products placeholder */
.no-products{text-align:center;padding:28px;border-radius:10px;background:var(--card-bg);box-shadow:0 6px 18px rgba(2,6,23,0.04);color:var(--muted)}

/* Small screens: stack card vertically */
@media(max-width:700px){
  .prod-card,.card{flex-direction:column;align-items:stretch;padding:12px}
  .thumb{width:100%;height:140px;flex:0 0 auto}
  .card-footer{margin-left:0;justify-content:flex-start;padding-top:8px}
}

/* Utility helpers */
.muted{color:var(--muted);padding:12px}
.notice{padding:10px;border-radius:8px;margin-bottom:10px}
.notice.ok{background:#ecfdf5;color:#065f46}
.notice.err{background:#fff1f2;color:#9f1239}
</style>
</head>
<body>

<?php require_once __DIR__ . '/header.php'; ?>

<div class="page-wrap">
  <div class="topbar-row">
    <h2 class="page-title">Товары</h2>
    <div class="tools">
      <a href="/mehanik/public/add-product.php" class="btn">➕ Добавить товар</a>
    </div>
  </div>

  <?php if ($msg): ?><div class="notice ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="notice err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <div class="layout">
    <aside class="sidebar" aria-label="Фильтр запчастей">
      <div style="display:flex;gap:8px;align-items:center;justify-content:space-between">
        <strong>Фильтр</strong>
      </div>

      <div class="form-row">
        <label for="brand_part">Бренд</label>
        <select id="brand_part"><option value="">Все бренды</option></select>
      </div>

      <div class="form-row">
        <label for="model_part">Модель</label>
        <select id="model_part" disabled><option value="">Сначала выберите бренд</option></select>
      </div>

      <div class="form-row">
        <label for="complex_part">Комплексная часть</label>
        <select id="complex_part"><option value="">Все комплексные части</option></select>
      </div>

      <div class="form-row">
        <label for="component">Компонент</label>
        <select id="component" disabled><option value="">Сначала выберите комплексную часть</option></select>
      </div>

      <div class="form-row">
        <label for="part_quality">Состояние</label>
        <select id="part_quality"><option value="">Любое</option><option value="new">Новый</option><option value="used">Б/У</option></select>
      </div>

      <div class="form-row" style="flex-direction:row;gap:8px;">
        <div style="flex:1">
          <label for="part_year_from">Год (от)</label>
          <input id="part_year_from" type="number" min="1900" max="2050" placeholder="например 2000">
        </div>
        <div style="flex:1">
          <label for="part_year_to">Год (до)</label>
          <input id="part_year_to" type="number" min="1900" max="2050" placeholder="например 2020">
        </div>
      </div>

      <div class="form-row" style="flex-direction:row;gap:8px;">
        <div style="flex:1"><label for="part_price_from">Цена (от)</label><input id="part_price_from" type="number" min="0"></div>
        <div style="flex:1"><label for="part_price_to">Цена (до)</label><input id="part_price_to" type="number" min="0"></div>
      </div>

      <div class="form-row">
        <label for="search">Поиск (название / артикул)</label>
        <input id="search" placeholder="например: тормоз или 123">
      </div>

      <div class="controls-row">
        <button id="clearFilters" class="btn-ghost">Сбросить</button>
        <div style="flex:1;color:#6b7280">Фильтры применяются автоматически.</div>
      </div>
    </aside>

    <section aria-live="polite">
      <div id="products" class="grid">
        <!-- карточки подгружаются через JS -->
      </div>
    </section>
  </div>
</div>

<script>
window.currentUserId = <?= json_encode($user_id) ?>;
window.uploadsPrefix = <?= json_encode($uploadsPrefix) ?>;
window.noPhoto = <?= json_encode($noPhoto) ?>;

window.fetchJSON = async function(url, opts = {}){ try{ const resp = await fetch(url, Object.assign({credentials:'same-origin'}, opts)); if (!resp.ok) return null; return await resp.json(); }catch(e){ return null; } };
</script>

<script>
(function(){
  const brandEl = document.getElementById('brand_part');
  const modelEl = document.getElementById('model_part');
  const complexEl = document.getElementById('complex_part');
  const componentEl = document.getElementById('component');
  const partQualityEl = document.getElementById('part_quality');
  const priceFromEl = document.getElementById('part_price_from');
  const priceToEl = document.getElementById('part_price_to');
  const searchEl = document.getElementById('search');
  const clearBtn = document.getElementById('clearFilters');
  const container = document.getElementById('products');

  let lookups = { brands: [], modelsByBrand: {}, complex_parts: [], componentsByComplex: {} };

  function setSelectOptions(sel, items, placeholder=''){
    if(!sel) return;
    const prev = sel.value;
    sel.innerHTML='';
    const o0 = document.createElement('option'); o0.value=''; o0.textContent = placeholder||'—'; sel.appendChild(o0);
    if(!items||!items.length) return;
    for(const it of items){
      const val = (typeof it==='object')?(it.id ?? it.value ?? it.key ?? it.name):it;
      const label = (typeof it==='object')?(it.name ?? it.label ?? it.value):it;
      const opt = document.createElement('option');
      opt.value = String(val);
      opt.textContent = String(label);
      sel.appendChild(opt);
    }
    if(prev && Array.from(sel.options).some(o=>o.value===prev)) sel.value=prev; else sel.selectedIndex=0;
  }

  function updateModelOptions(brandKey){
    if(!brandKey){
      modelEl.innerHTML = '<option value="">Сначала выберите бренд</option>';
      modelEl.disabled = true;
      return;
    }
    const models = lookups.modelsByBrand[brandKey] || [];
    setSelectOptions(modelEl, models, 'Все модели');
    modelEl.disabled=false;
  }

  function updateComponentOptions(complexKey){
    if(!complexKey){
      componentEl.innerHTML = '<option value="">Сначала выберите комплексную часть</option>';
      componentEl.disabled=true;
      return;
    }
    const comps = lookups.componentsByComplex[complexKey] || [];
    setSelectOptions(componentEl, comps, 'Все компоненты');
    componentEl.disabled=false;
  }

  function mergeLookups(data){
    if(!data) return;
    if(Array.isArray(data.brands)) lookups.brands = data.brands;
    lookups.modelsByBrand = {};
    const models = data.models ?? data.model_list ?? [];
    if(Array.isArray(models)){
      for(const m of models){
        const b = String(m.brand_id ?? m.brand ?? '');
        const name = m.name ?? m.model ?? '';
        if(!b||!name) continue;
        if(!lookups.modelsByBrand[b]) lookups.modelsByBrand[b]=[];
        lookups.modelsByBrand[b].push({ id: m.id ?? name, name: name });
      }
      for(const k in lookups.modelsByBrand){
        const seen = new Set();
        lookups.modelsByBrand[k] = lookups.modelsByBrand[k].filter(x=>{ if(seen.has(x.name)) return false; seen.add(x.name); return true; });
      }
    }
    if(Array.isArray(data.complex_parts)) lookups.complex_parts = data.complex_parts;
    lookups.componentsByComplex = {};
    if(Array.isArray(data.components)){
      for(const c of data.components){
        const key = String(c.complex_part_id ?? c.group ?? '');
        const label = (c.name ?? c.component ?? '').toString();
        if(!key||!label) continue;
        if(!lookups.componentsByComplex[key]) lookups.componentsByComplex[key]=[];
        lookups.componentsByComplex[key].push({ id: c.id ?? label, name: label });
      }
      for(const k in lookups.componentsByComplex){
        const seen=new Set();
        lookups.componentsByComplex[k]=lookups.componentsByComplex[k].filter(x=>{ if(seen.has(x.name)) return false; seen.add(x.name); return true; });
      }
    }
  }

  async function loadLookups(){
    if(window.productList && productList.lookups) mergeLookups(productList.lookups);
    if(!lookups.brands.length || Object.keys(lookups.modelsByBrand).length===0){
      try{
        const resp = await fetch('/mehanik/api/products.php?type=part', { credentials:'same-origin' });
        if(resp.ok){
          const data = await resp.json();
          mergeLookups(data.lookups ?? data);
          if(window.productList && typeof productList.fillLookups === 'function') productList.fillLookups(data.lookups ?? data);
        }
      }catch(e){}
    }
    setSelectOptions(brandEl, lookups.brands, 'Все бренды');
    updateModelOptions(brandEl.value);
    setSelectOptions(complexEl, lookups.complex_parts, 'Все комплексные части');
    updateComponentOptions(complexEl.value);
  }

  function collectFilters(){
    const getVal = v => v?String(v.value).trim():'';
    const filters = {
      type: 'part',
      brand_part: getVal(brandEl),
      model_part: getVal(modelEl),
      complex_part: getVal(complexEl),
      component: getVal(componentEl),
      part_quality: getVal(partQualityEl),
      price_from: getVal(priceFromEl),
      price_to: getVal(priceToEl),
      q: getVal(searchEl)
    };
    Object.keys(filters).forEach(k=>{ if(filters[k]==='') delete filters[k]; });
    return filters;
  }

  async function applyFilters(){
    const filters = collectFilters();
    if(window.productList && typeof productList.loadProducts === 'function'){
      try{ await productList.loadProducts(filters); return; }catch(e){ console.warn('productList.loadProducts error', e); }
    }
    try{
      const params = new URLSearchParams(filters);
      const resp = await fetch('/mehanik/api/products.php?'+params.toString(), { credentials:'same-origin' });
      if(resp.ok){
        const json = await resp.json();
        const items = json.products ?? json.items ?? json;
        renderProducts(Array.isArray(items)?items:[]);
      }
    }catch(e){ console.warn(e); }
  }

  function renderProducts(items){
    container.innerHTML = '';
    if(!items||!items.length){
      container.innerHTML = '<div class="no-products"><p style="font-weight:700;margin:0 0 8px;">По вашему запросу ничего не найдено</p><p style="margin:0;color:#6b7280">Попробуйте изменить фильтры или добавить товар.</p></div>';
      return;
    }

    const frag = document.createDocumentFragment();

    for(const it of items){
      const card = document.createElement('article');
      card.className = 'prod-card';
      card.setAttribute('data-id', String(it.id || ''));

      // thumb
      const thumb = document.createElement('div');
      thumb.className = 'thumb';
      const a = document.createElement('a');
      a.href = '/mehanik/public/product.php?id='+encodeURIComponent(it.id);
      a.style.display='block'; a.style.width='100%'; a.style.height='100%';
      const img = document.createElement('img');
      img.alt = it.name || 'Товар';
      img.src = (it.photo && (it.photo.indexOf('/')===0 || /^https?:\/\//i.test(it.photo))) ? it.photo : (it.photo ? (window.uploadsPrefix + it.photo) : window.noPhoto);
      a.appendChild(img);
      thumb.appendChild(a);

      // body
      const body = document.createElement('div'); body.className='card-body';

      // top row: left(title/meta) + right(price)
      const topRow = document.createElement('div'); topRow.className='price-row';
      const left = document.createElement('div'); left.style.flex='1'; left.style.minWidth='0';

      // title and manufacturer (manufacturer shown to the right of title)
      const title = document.createElement('div'); title.className='title'; title.textContent = it.name || 'Без названия';
      title.title = it.name || '';

      const titleRow = document.createElement('div'); titleRow.className = 'title-row';
      const manufacturerVal = it.manufacturer || it.manufacturer_name || it.brand_name || it.brand || it.producer || '';
      const manu = document.createElement('div'); manu.className = 'product-manufacturer';
      if (manufacturerVal) {
        manu.textContent = String(manufacturerVal);
      } else {
        manu.style.display = 'none';
      }
      titleRow.appendChild(title);
      titleRow.appendChild(manu);

      // product-sub: brand / model / complex part / component
      const brandVal = it.brand_name || it.brand || it.manufacturer || '';
      const modelVal = it.model_name || it.model || '';
      const complexVal = it.complex_part_name || it.complex_part || it.complex || '';
      const componentVal = it.component_name || it.component || '';
      const productSub = document.createElement('div'); productSub.className='product-sub';
      const parts = [];
      if(brandVal) parts.push(String(brandVal));
      if(modelVal) parts.push(String(modelVal));
      if(complexVal) parts.push(String(complexVal));
      if(componentVal) parts.push(String(componentVal));
      productSub.textContent = parts.length ? parts.join(' · ') : '-';

      left.appendChild(titleRow);
      left.appendChild(productSub);

      // SKU
      const rawSku = (it.sku || it.article || it.code || '').toString();
      const displaySku = rawSku.replace(/^SKU-/i, '').trim();
      if (displaySku) {
        const skuWrap = document.createElement('div'); skuWrap.className = 'sku-wrap';
        const skuLink = document.createElement('a'); skuLink.className = 'sku-link';
        skuLink.href = it.url || ('/mehanik/public/product.php?id='+encodeURIComponent(it.id));
        skuLink.textContent = displaySku; skuLink.title = 'Перейти к товару';
        skuWrap.appendChild(skuLink);
        const copyBtn = document.createElement('button'); copyBtn.type='button'; copyBtn.className='btn-copy-sku'; copyBtn.textContent='📋';
        copyBtn.title = 'Копировать артикул';
        copyBtn.addEventListener('click', function(e){
          e.preventDefault();
          const text = displaySku;
          if (!text) return;
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(()=> {
              const prev = copyBtn.textContent;
              copyBtn.textContent = '✓';
              setTimeout(()=> copyBtn.textContent = prev, 1200);
            }).catch(()=> fallbackCopy(text, copyBtn));
          } else fallbackCopy(text, copyBtn);
        });
        skuWrap.appendChild(copyBtn);
        left.appendChild(skuWrap);
      }

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
          if (ok) { const prev = btn.textContent; btn.textContent = '✓'; setTimeout(()=> btn.textContent = prev, 1200); }
          else alert('Не удалось скопировать артикул');
        } catch(e) { alert('Копирование не поддерживается в этом браузере'); }
      }

      const right = document.createElement('div'); right.style.textAlign='right'; right.style.minWidth='110px';
      const price = document.createElement('div'); price.className='price';
      price.textContent = it.price ? (Number(it.price).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + ' TMT') : '-';
      right.appendChild(price);

      topRow.appendChild(left); topRow.appendChild(right);
      body.appendChild(topRow);

      // badges: убираем статус, добавляем наличие, дату, и строку со состоянием/качество
      const badges = document.createElement('div'); badges.className='badges';

      const avail = document.createElement('div'); avail.className='meta'; avail.style.background='#f3f5f8'; avail.style.padding='6px 8px'; avail.style.borderRadius='8px'; avail.style.color='#334155'; avail.textContent = 'Наличие: ' + (it.availability ? String(it.availability) : '0');
      const added = document.createElement('div'); added.className='meta'; added.style.background='#f3f5f8'; added.style.padding='6px 8px'; added.style.borderRadius='8px'; added.style.color='#334155'; added.textContent = 'Добавлен: ' + (it.created_at ? new Date(it.created_at).toLocaleDateString() : '-');

      // состояние и качество
      const conditionText = it.quality || it.condition || it.state || '';
      const ratingText = (typeof it.rating !== 'undefined' && it.rating !== null && it.rating !== '') ? (Number(it.rating).toFixed(1)) : '';
      if(conditionText){
        const cond = document.createElement('div'); cond.className='meta'; cond.style.background='#f3f5f8'; cond.style.padding='6px 8px'; cond.style.borderRadius='8px'; cond.style.color='#334155'; cond.textContent = 'Состояние: ' + String(conditionText);
        badges.appendChild(cond);
      }
      if(ratingText){
        const rq = document.createElement('div'); rq.className='meta'; rq.style.background='#f3f5f8'; rq.style.padding='6px 8px'; rq.style.borderRadius='8px'; rq.style.color='#334155'; rq.textContent = 'Качество: ' + ratingText;
        badges.appendChild(rq);
      }

      badges.appendChild(avail); badges.appendChild(added);
      body.appendChild(badges);

      // footer / actions (only "Просмотр")
      const footer = document.createElement('div'); footer.className='card-footer';
      const actions = document.createElement('div'); actions.className='actions';
      const view = document.createElement('a'); view.className='btn-view'; view.href = '/mehanik/public/product.php?id='+encodeURIComponent(it.id); view.textContent = '👁 Просмотр';
      actions.appendChild(view);
      footer.appendChild(actions);

      card.appendChild(thumb); card.appendChild(body); card.appendChild(footer);
      frag.appendChild(card);
    }

    container.appendChild(frag);
  }

  // events
  brandEl.addEventListener('change', function(){ updateModelOptions(this.value); applyFilters(); });
  modelEl.addEventListener('change', applyFilters);
  complexEl.addEventListener('change', function(){ updateComponentOptions(this.value); applyFilters(); });
  componentEl.addEventListener('change', applyFilters);
  [partQualityEl, priceFromEl, priceToEl].forEach(el=>{ if(!el) return; el.addEventListener('change', applyFilters); });
  searchEl.addEventListener('input', (function(){ let t; return function(){ clearTimeout(t); t=setTimeout(()=>applyFilters(),300); }; })());
  clearBtn.addEventListener('click', function(e){ e.preventDefault(); [brandEl,modelEl,complexEl,componentEl,partQualityEl,priceFromEl,priceToEl,searchEl].forEach(el=>{ if(!el) return; if(el.tagName && el.tagName.toLowerCase()==='select') el.selectedIndex=0; else el.value=''; }); updateModelOptions(''); updateComponentOptions(''); applyFilters(); });

  (async function init(){ await loadLookups(); applyFilters(); })();

})();
</script>

</body>
</html>
