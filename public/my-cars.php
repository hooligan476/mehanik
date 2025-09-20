<?php
// public/my-cars.php — серверный рендер + клиентский фильтр (устойчивый)
// Исправлено: нормализация uploads, не посылать mine=0, проверка no-photo

require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php';

$user_id = (int)($_SESSION['user']['id'] ?? 0);
if (!$user_id) {
    http_response_code(403); echo "Пользователь не в сессии."; exit;
}

// Попробуем корректно найти no-photo (путь для отдачи через веб)
$noPhotoCandidates = [
    '/mehanik/assets/no-photo.png',
    '/mehanik/public/assets/no-photo.png',
    '/assets/no-photo.png',
];
$noPhoto = $noPhotoCandidates[0];
// Проверяем, есть ли файл в файловой системе и если да — используем соответствующий путь.
// __DIR__ указывает на public/; пробуем варианты.
if (file_exists(__DIR__ . '/../assets/no-photo.png')) {
    $noPhoto = '/mehanik/assets/no-photo.png';
} elseif (file_exists(__DIR__ . '/assets/no-photo.png')) {
    $noPhoto = '/mehanik/public/assets/no-photo.png';
} else {
    // Оставляем дефолт — положи файл в один из ожидаемых мест, если 404 остаётся.
    $noPhoto = '/mehanik/assets/no-photo.png';
}

// Универсальная папка uploads — передаём в JS и используем аккуратно
$uploadsPrefix = '/mehanik/uploads/';
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';
$debug = isset($_GET['debug']) && $_GET['debug'] == '1';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// --- server-side fetch: получить "мои" объявления на случай, если JS не сработает ---
$serverItems = [];
$fetchError = '';
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $sql = "SELECT id, user_id, brand, model, year, mileage, body, photo, price, status, sku, created_at, fuel, transmission
                FROM cars
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT 200";
        if ($st = $mysqli->prepare($sql)) {
            $st->bind_param('i', $user_id);
            $st->execute();
            $res = $st->get_result();
            while ($r = $res->fetch_assoc()) $serverItems[] = $r;
            $st->close();
        } else {
            $fetchError = 'Prepare failed: ' . $mysqli->error;
        }
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $st = $pdo->prepare("SELECT id, user_id, brand, model, year, mileage, body, photo, price, status, sku, created_at, fuel, transmission
                             FROM cars WHERE user_id = :uid ORDER BY created_at DESC LIMIT 200");
        if ($st->execute([':uid' => $user_id])) {
            $serverItems = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $info = $st->errorInfo();
            $fetchError = implode(' | ', $info);
        }
    } else {
        $fetchError = 'Нет подключения к БД';
    }
} catch (Throwable $e) {
    $fetchError = $e->getMessage();
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Мои авто — Mehanik</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <!-- явная ссылка на header.css, чтобы не было относительных 404 -->
  <link rel="stylesheet" href="/mehanik/assets/css/header.css">
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    /* CSS (как в оригинале) */
    .page { max-width:1200px; margin:18px auto; padding:14px; }
    .layout { display:grid; grid-template-columns: 320px 1fr; gap:18px; }
    @media (max-width:1100px){ .layout{grid-template-columns:1fr;} }
    .topbar-row { display:flex; gap:12px; align-items:center; margin-bottom:14px; flex-wrap:wrap; }
    .title { font-size:1.4rem; font-weight:800;margin:0; }
    .tools { margin-left:auto; display:flex; gap:8px; align-items:center; }
    .btn { background:#0b57a4;color:#fff;padding:8px 12px;border-radius:8px;border:0;cursor:pointer;font-weight:700;text-decoration:none; }
    .sidebar { background:#fff;padding:14px;border-radius:12px;box-shadow:0 8px 24px rgba(2,6,23,0.04); }
    .form-row{margin-top:10px;display:flex;flex-direction:column;gap:6px}
    .form-row label{font-weight:700}
    .form-row select,.form-row input{padding:8px;border-radius:8px;border:1px solid #eef3f8}
    .controls-row{display:flex;gap:8px;align-items:center;margin-top:12px}
    .products { display:grid; grid-template-columns: repeat(3,1fr); gap:18px; }
    @media (max-width:992px){ .products{grid-template-columns:repeat(2,1fr);} }
    @media (max-width:640px){ .products{grid-template-columns:1fr;} }
    .card { background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 8px 20px rgba(2,6,23,0.06); display:flex;flex-direction:column; }
    .thumb { height:180px;background:#f5f7fb;display:flex;align-items:center;justify-content:center; }
    .thumb img { max-width:100%; max-height:100%; object-fit:cover; display:block; }
    .card-body{padding:12px;flex:1;display:flex;flex-direction:column;gap:8px}
    .car-title{font-weight:800;margin:0;font-size:1.05rem}
    .meta{color:#6b7280;font-size:0.95rem}
    .price{font-weight:800;color:#0b57a4;font-size:1.05rem}
    .badges{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:auto}
    .badge{padding:6px 10px;border-radius:999px;color:#fff;font-weight:700}
    .badge.ok{background:#15803d} .badge.rej{background:#ef4444} .badge.pending{background:#b45309}
    .card-footer{padding:10px;border-top:1px solid #f1f3f6;display:flex;justify-content:space-between;align-items:center;gap:8px}
    .actions a,.actions button { text-decoration:none;padding:8px 10px;border-radius:8px;background:#eef6ff;color:#0b57a4;font-weight:700;border:1px solid rgba(11,87,164,0.08); cursor:pointer }
    .empty{background:#fff;padding:28px;border-radius:10px;text-align:center;box-shadow:0 8px 24px rgba(2,6,23,0.04)}
    .notice{padding:10px;border-radius:8px;margin-bottom:12px}
    .notice.ok{background:#eafaf0;border:1px solid #cfead1;color:#116530}
    .notice.err{background:#fff6f6;border:1px solid #f5c2c2;color:#8a1f1f}
    .sku-row { display:flex; gap:8px; align-items:center; margin-top:6px; }
    .sku-text { font-weight:700; color:#0b57a4; text-decoration:underline; font-size:.95rem; }
    .sku-copy { padding:6px 8px; border-radius:6px; border:1px solid #e6e9ef; background:#fff; cursor:pointer; font-weight:700; }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<div class="page">
  <div class="topbar-row">
    <h1 class="title">Мои объявления — Авто</h1>
    <div class="tools">
      <a href="/mehanik/public/add-car.php" class="btn">➕ Добавить авто</a>
    </div>
  </div>

  <?php if ($msg): ?><div class="notice ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="notice err"><?= h($err) ?></div><?php endif; ?>
  <?php if ($debug && $fetchError): ?><div class="notice err">Fetch error: <?= h($fetchError) ?></div><?php endif; ?>

  <div class="layout">
    <aside class="sidebar" aria-label="Фильтр автомобилей">
      <div style="display:flex;gap:8px;align-items:center;justify-content:space-between">
        <strong>Фильтр</strong>
        <label style="font-weight:700;font-size:.95rem"><input id="onlyMine" type="checkbox" checked> Только мои</label>
      </div>

      <div class="form-row">
        <label for="vehicle_type">Тип ТС</label>
        <select id="vehicle_type"><option value="">Все типы</option></select>
      </div>

      <div class="form-row">
        <label for="vehicle_body">Кузов</label>
        <select id="vehicle_body" disabled><option value="">Сначала выберите тип</option></select>
      </div>

      <div class="form-row">
        <label for="brand">Бренд</label>
        <select id="brand"><option value="">Все бренды</option></select>
      </div>

      <div class="form-row">
        <label for="model">Модель</label>
        <select id="model" disabled><option value="">Сначала выберите бренд</option></select>
      </div>

      <div class="form-row" style="flex-direction:row;gap:8px;">
        <div style="flex:1">
          <label for="year_from">Год (от)</label>
          <input type="number" id="year_from" min="1900" max="2050">
        </div>
        <div style="flex:1">
          <label for="year_to">Год (до)</label>
          <input type="number" id="year_to" min="1900" max="2050">
        </div>
      </div>

      <div class="form-row">
        <label for="fuel_type">Тип топлива</label>
        <select id="fuel_type"><option value="">Любое</option></select>
      </div>

      <div class="form-row">
        <label for="gearbox">Коробка передач</label>
        <select id="gearbox"><option value="">Любая</option></select>
      </div>

      <div class="form-row" style="flex-direction:row;gap:8px;">
        <div style="flex:1">
          <label for="price_from">Цена (от)</label>
          <input type="number" id="price_from" min="0">
        </div>
        <div style="flex:1">
          <label for="price_to">Цена (до)</label>
          <input type="number" id="price_to" min="0">
        </div>
      </div>

      <div class="form-row">
        <label for="search">Поиск (название / ID)</label>
        <input id="search" placeholder="например: Тойота или 123">
      </div>

      <div class="controls-row">
        <button id="clearFilters" class="btn-ghost">Сбросить</button>
        <div style="flex:1;color:#6b7280">Фильтры применяются автоматически.</div>
      </div>
    </aside>

    <section aria-live="polite">
      <div id="products" class="products">
        <?php if (!empty($serverItems)): ?>
          <?php foreach ($serverItems as $it):
              // аккуратно формируем URL фото: если абсолютный — оставляем, иначе добавляем uploadsPrefix + имя файла,
              // но не дублируем 'uploads/' если он уже в поле photo.
              $photoUrl = '';
              if (!empty($it['photo'])) {
                  $p = $it['photo'];
                  if (preg_match('#^https?://#i', $p) || strpos($p, '/') === 0) {
                      $photoUrl = $p;
                  } else {
                      if (strpos($p, 'uploads/') === 0) {
                          $photoUrl = '/' . ltrim($p, '/');
                      } else {
                          $photoUrl = rtrim($uploadsPrefix, '/') . '/' . ltrim($p, '/');
                      }
                  }
              } else {
                  $photoUrl = $noPhoto;
              }
          ?>
            <article class="card">
              <div class="thumb"><a href="/mehanik/public/car.php?id=<?= (int)$it['id'] ?>"><img src="<?= h($photoUrl) ?>" alt="<?= h(($it['brand'] ?? '') . ' ' . ($it['model'] ?? '')) ?>"></a></div>
              <div class="card-body">
                <div style="display:flex;justify-content:space-between;gap:12px">
                  <div style="flex:1">
                    <div class="car-title"><?= h(($it['brand'] ?? '') . ' ' . ($it['model'] ?? '')) ?></div>
                    <div class="meta"><?= ($it['year'] ? (int)$it['year'] . ' г. · ' : '') . ($it['mileage'] ? number_format((int)$it['mileage'],0,'.',' ') . ' км · ' : '') . h($it['body'] ?? '-') ?></div>
                  </div>
                  <div style="text-align:right">
                    <div class="price"><?= $it['price'] ? number_format((float)$it['price'], 2, '.', ' ') . ' TMT' : '-' ?></div>
                    <div class="meta" style="margin-top:8px;font-size:.9rem">ID: <?= (int)$it['id'] ?></div>
                  </div>
                </div>

                <div style="margin-top:6px">
                  <?php $sku = $it['sku'] ?? ''; if ($sku !== ''):
                        $displaySku = preg_replace('/^SKU-/i', '', (string)$sku);
                  ?>
                    <div class="sku-row">
                      <a class="sku-text" href="/mehanik/public/car.php?id=<?= (int)$it['id'] ?>"><?= h($displaySku) ?></a>
                      <button type="button" class="sku-copy" onclick="(function(t){ try{ navigator.clipboard.writeText(t); alert('Скопировано'); }catch(e){ alert('Копирование не доступно'); } })(<?= json_encode($sku) ?>)">📋</button>
                    </div>
                  <?php else: ?>
                    <div class="meta">Артикул: —</div>
                  <?php endif; ?>
                </div>

                <div class="badges" style="margin-top:12px">
                  <div class="badge <?= ($it['status']==='approved') ? 'ok' : (($it['status']==='rejected') ? 'rej' : 'pending') ?>">
                    <?= ($it['status']==='approved') ? 'Подтверждён' : (($it['status']==='rejected') ? 'Отклонён' : 'На модерации') ?>
                  </div>
                  <div class="meta" style="background:#f3f5f8;padding:6px 8px;border-radius:8px;color:#334155">
                    Добавлен: <?= h($it['created_at'] ?? '-') ?>
                  </div>
                </div>
              </div>
              <div class="card-footer">
                <div class="actions">
                  <a href="/mehanik/public/car.php?id=<?= (int)$it['id'] ?>">👁 Просмотр</a>
                </div>
                <div style="text-align:right;color:#6b7280;font-size:.85rem"></div>
              </div>
            </article>
          <?php endforeach; ?>
        <?php else: ?>
          <!-- Если серверных записей нет — контейнер будет пуст и JS попытается загрузить динамически -->
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>

<script>
window.currentUserId = <?= json_encode($user_id) ?>;
window.uploadsPrefix = <?= json_encode($uploadsPrefix) ?>;
window.noPhoto = <?= json_encode($noPhoto) ?>;
// Серверные записи доступны как запасной источник для JS
window.serverItems = <?= json_encode(array_values($serverItems), JSON_UNESCAPED_UNICODE) ?>;
</script>

<script src="/mehanik/assets/js/carList.js"></script>
<script>
(function(){
  // элементы
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
  const container = document.getElementById('products');

  // локальное хранилище lookup'ов
  let lookups = { brands: [], modelsByBrand: {}, vehicle_types: [], vehicle_bodies: {}, fuel_types: [], gearboxes: [] };

  function setSelectOptions(sel, items, placeholderText=''){
    if (!sel) return;
    const prev = sel.value;
    sel.innerHTML = '';
    const o0 = document.createElement('option'); o0.value = ''; o0.textContent = placeholderText || '—'; sel.appendChild(o0);
    if (!items || !items.length) return;
    for (const it of items) {
      const val = (typeof it === 'object') ? (it.id ?? it.value ?? it.key ?? it.name) : it;
      const label = (typeof it === 'object') ? (it.name ?? it.label ?? it.value) : it;
      const opt = document.createElement('option'); opt.value = String(val); opt.textContent = String(label); sel.appendChild(opt);
    }
    if (prev && Array.from(sel.options).some(o=>o.value===prev)) sel.value = prev; else sel.selectedIndex = 0;
  }

  function updateModelOptions(brandKey){
    if (!modelEl) return;
    if (!brandKey) { modelEl.innerHTML = '<option value="">Сначала выберите бренд</option>'; modelEl.disabled = true; return; }
    const models = lookups.modelsByBrand[brandKey] || [];
    setSelectOptions(modelEl, models, 'Все модели'); modelEl.disabled = false;
  }

  function updateBodyOptions(typeKey){
    if (!vehicleBodyEl) return;
    if (!typeKey) {
      vehicleBodyEl.innerHTML = '<option value="">Сначала выберите тип</option>';
      vehicleBodyEl.disabled = true;
      return;
    }
    let items = [];
    if (Array.isArray(lookups.vehicle_bodies)) {
      items = lookups.vehicle_bodies;
    } else if (lookups.vehicle_bodies && typeof lookups.vehicle_bodies === 'object') {
      items = lookups.vehicle_bodies[typeKey] || [];
    }
    setSelectOptions(vehicleBodyEl, items, 'Все кузова');
    vehicleBodyEl.disabled = false;
  }

  function mergeLookups(data){
    if (!data) return;
    if (Array.isArray(data.brands)) lookups.brands = data.brands;
    // models -> modelsByBrand
    lookups.modelsByBrand = {};
    const models = data.models ?? data.model_list ?? [];
    if (Array.isArray(models)){
      for (const m of models){
        const b = String(m.brand_id ?? m.brand ?? '');
        const name = m.name ?? m.model ?? '';
        if (!b || !name) continue;
        if (!lookups.modelsByBrand[b]) lookups.modelsByBrand[b] = [];
        lookups.modelsByBrand[b].push({ id: m.id ?? name, name: name });
      }
      for (const k in lookups.modelsByBrand){
        const seen = new Set(); lookups.modelsByBrand[k] = lookups.modelsByBrand[k].filter(x=>{ if (seen.has(x.name)) return false; seen.add(x.name); return true; });
      }
    }
    if (Array.isArray(data.vehicle_types)) lookups.vehicle_types = data.vehicle_types;
    if (data.vehicle_bodies) lookups.vehicle_bodies = data.vehicle_bodies;
    if (Array.isArray(data.fuel_types)) lookups.fuel_types = data.fuel_types;
    if (Array.isArray(data.gearboxes)) lookups.gearboxes = data.gearboxes;
  }

  // fallback lookups from serverItems (если нет API/нет productList.lookups)
  function buildLookupsFromServerItems(){
    if (!window.serverItems || !window.serverItems.length) return;
    const brandsMap = new Map();
    const modelsByBrand = {};
    const bodiesSet = new Set();
    const fuelSet = new Set();
    const gearboxSet = new Set();

    for (const it of window.serverItems){
      const brand = (it.brand || '').trim();
      const model = (it.model || '').trim();
      const body = (it.body || '').trim();
      if (brand) {
        if (!brandsMap.has(brand)) brandsMap.set(brand, { id: brand, name: brand });
        if (model) {
          if (!modelsByBrand[brand]) modelsByBrand[brand] = [];
          modelsByBrand[brand].push({ id: model, name: model });
        }
      }
      if (body) bodiesSet.add(body);
      if (it.fuel) fuelSet.add(it.fuel);
      if (it.transmission) gearboxSet.add(it.transmission);
    }

    if (!lookups.brands.length) lookups.brands = Array.from(brandsMap.values());
    for (const b in modelsByBrand){
      const seen = new Set();
      lookups.modelsByBrand[b] = modelsByBrand[b].filter(x => { if (seen.has(x.name)) return false; seen.add(x.name); return true; });
    }
    if (!lookups.vehicle_bodies || (typeof lookups.vehicle_bodies === 'object' && Object.keys(lookups.vehicle_bodies).length === 0)) {
      lookups.vehicle_bodies = Array.from(bodiesSet).map(v => ({ id: v, name: v }));
    }
    if ((!lookups.fuel_types || !lookups.fuel_types.length) && fuelSet.size) lookups.fuel_types = Array.from(fuelSet).map(v=>({ id: v, name: v }));
    if ((!lookups.gearboxes || !lookups.gearboxes.length) && gearboxSet.size) lookups.gearboxes = Array.from(gearboxSet).map(v=>({ id: v, name: v }));
  }

  async function loadLookups(){
    if (window.productList && productList.lookups) mergeLookups(productList.lookups);
    if (!lookups.brands.length){
      try{
        const resp = await fetch('/mehanik/api/products.php?type=auto', { credentials:'same-origin' });
        if (resp.ok){ const data = await resp.json(); mergeLookups(data.lookups ?? data); if (window.productList && typeof productList.fillLookups === 'function') productList.fillLookups(data.lookups ?? data); }
      }catch(e){}
    }

    buildLookupsFromServerItems();

    setSelectOptions(brandEl, lookups.brands, 'Все бренды');
    setSelectOptions(vehicleTypeEl, lookups.vehicle_types, 'Все типы');

    vehicleBodyEl.innerHTML = '<option value="">Сначала выберите тип</option>';
    vehicleBodyEl.disabled = true;

    setSelectOptions(fuelTypeEl, lookups.fuel_types, 'Любое');
    setSelectOptions(gearboxEl, lookups.gearboxes, 'Любая');

    updateModelOptions(brandEl ? brandEl.value : '');
    updateBodyOptions(vehicleTypeEl ? vehicleTypeEl.value : '');
  }

  function collectFilters(){
    const getVal=v=>v?String(v.value).trim():'';
    const filters = {
      type: 'auto',
      brand: getVal(brandEl), model: getVal(modelEl), vehicle_type: getVal(vehicleTypeEl), vehicle_body: getVal(vehicleBodyEl),
      fuel_type: getVal(fuelTypeEl), gearbox: getVal(gearboxEl), year_from: getVal(yearFromEl), year_to: getVal(yearToEl),
      price_from: getVal(priceFromEl), price_to: getVal(priceToEl), q: getVal(searchEl)
    };
    // только добавляем mine, если чекбокс включён
    if (onlyMineEl && onlyMineEl.checked) {
      filters.mine = '1';
    }
    Object.keys(filters).forEach(k=>{ if (filters[k]==='') delete filters[k]; });
    if (!filters.vehicle_type && filters.vehicle_body) delete filters.vehicle_body;
    return filters;
  }

  async function applyFilters(){
    const filters = collectFilters();
    if (window.productList && typeof productList.loadProducts === 'function'){
      try{ await productList.loadProducts(filters); return; }catch(e){ console.warn('productList.loadProducts error', e); }
    }

    try{
      const params = new URLSearchParams(filters);
      const resp = await fetch('/mehanik/api/products.php?'+params.toString(), { credentials:'same-origin' });
      if (resp.ok){
        const json = await resp.json();
        const items = json.products ?? json.items ?? json;
        renderProducts(Array.isArray(items)?items:[]);
      } else {
        renderProducts([]);
      }
    }catch(e){ console.warn(e); renderProducts([]); }
  }

  function renderProducts(items){
    container.innerHTML = '';
    if (!items || !items.length) { container.innerHTML = '<div class="empty"><h3 style="margin:0">По вашему запросу ничего не найдено</h3></div>'; return; }

    const frag = document.createDocumentFragment();

    for (const it of items){
      const card = document.createElement('article'); card.className = 'card';
      const thumb = document.createElement('div'); thumb.className = 'thumb';
      const a = document.createElement('a'); a.href = '/mehanik/public/car.php?id='+encodeURIComponent(it.id);
      const img = document.createElement('img'); img.alt = (it.brand || '') + ' ' + (it.model||'');
      // нормализация пути фото: избегаем uploads/uploads и корректно обрабатываем абсолютные ссылки
      let photo = it.photo || '';
      if (!photo) {
        photo = window.noPhoto;
      } else if (photo.startsWith('/') || /^https?:\/\//i.test(photo)) {
        photo = photo;
      } else if (photo.indexOf('uploads/') === 0) {
        photo = '/' + photo.replace(/^\/+/, '');
      } else {
        photo = (window.uploadsPrefix || '/mehanik/uploads/').replace(/\/$/,'') + '/' + photo.replace(/^\/+/,'');
      }
      img.src = photo;
      a.appendChild(img); thumb.appendChild(a);

      const body = document.createElement('div'); body.className = 'card-body';
      const row = document.createElement('div'); row.style.display='flex'; row.style.justifyContent='space-between'; row.style.gap='12px';
      const left = document.createElement('div'); left.style.flex='1';
      const title = document.createElement('div'); title.className='car-title'; title.textContent = (it.brand||'') + ' ' + (it.model||'');
      const meta = document.createElement('div'); meta.className='meta';
      meta.textContent = ((it.year)?(it.year+' г. · '):'') + ((it.mileage)?(Number(it.mileage).toLocaleString()+' км · '):'') + (it.body||'-');
      left.appendChild(title); left.appendChild(meta);
      const right = document.createElement('div'); right.style.textAlign='right';
      const price = document.createElement('div'); price.className = 'price'; price.textContent = (it.price ? (Number(it.price).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits:2}) + ' TMT') : '-');
      const idMeta = document.createElement('div'); idMeta.className='meta'; idMeta.style.marginTop='8px'; idMeta.style.fontSize='.9rem'; idMeta.textContent = 'ID: ' + (it.id||'-');
      right.appendChild(price); right.appendChild(idMeta);
      row.appendChild(left); row.appendChild(right);

      body.appendChild(row);

      // SKU row (trim SKU- префикс для отображения)
      const skuRaw = (it.sku || it.article || it.code || '') + '';
      const displaySku = skuRaw.replace(/^SKU-/i, '').trim();
      const skuWrap = document.createElement('div'); skuWrap.style.marginTop = '6px';
      if (skuRaw && skuRaw.trim() !== '') {
        const skuRow = document.createElement('div'); skuRow.className = 'sku-row';
        const skuLink = document.createElement('a'); skuLink.className = 'sku-text'; skuLink.href = '/mehanik/public/car.php?id='+encodeURIComponent(it.id); skuLink.textContent = displaySku || skuRaw;
        skuLink.title = 'Перейти к объявлению';
        const copyBtn = document.createElement('button'); copyBtn.type='button'; copyBtn.className='sku-copy'; copyBtn.textContent='📋'; copyBtn.title='Копировать артикул';
        copyBtn.addEventListener('click', function(ev){
          ev.preventDefault();
          const text = skuRaw;
          if (!text) return;
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(()=> {
              const prev = copyBtn.textContent;
              copyBtn.textContent = '✓';
              setTimeout(()=> copyBtn.textContent = prev, 1200);
            }).catch(()=> fallbackCopy(text, copyBtn));
          } else {
            fallbackCopy(text, copyBtn);
          }
        });
        skuRow.appendChild(skuLink);
        skuRow.appendChild(copyBtn);
        skuWrap.appendChild(skuRow);
      } else {
        const emptySku = document.createElement('div'); emptySku.className='meta'; emptySku.textContent = 'Артикул: —';
        skuWrap.appendChild(emptySku);
      }
      body.appendChild(skuWrap);

      const badges = document.createElement('div'); badges.className='badges';
      const status = document.createElement('div'); status.className = 'badge ' + ((it.status==='approved')? 'ok' : (it.status==='rejected'? 'rej':'pending'));
      status.textContent = (it.status==='approved')? 'Подтверждён' : (it.status==='rejected'? 'Отклонён' : 'На модерации');
      const added = document.createElement('div'); added.className='meta'; added.style.background='#f3f5f8'; added.style.padding='6px 8px'; added.style.borderRadius='8px'; added.style.color='#334155'; added.textContent = 'Добавлен: ' + (it.created_at? new Date(it.created_at).toLocaleDateString() : '-');
      badges.appendChild(status); badges.appendChild(added);
      body.appendChild(badges);

      const footer = document.createElement('div'); footer.className='card-footer';
      const actions = document.createElement('div'); actions.className='actions';
      const view = document.createElement('a'); view.href = '/mehanik/public/car.php?id='+encodeURIComponent(it.id); view.textContent = '👁 Просмотр'; actions.appendChild(view);

      footer.appendChild(actions);
      const ownerWrap = document.createElement('div'); ownerWrap.style.textAlign='right'; ownerWrap.style.fontSize='.85rem'; ownerWrap.style.color='#6b7280'; ownerWrap.textContent=''; footer.appendChild(ownerWrap);

      card.appendChild(thumb); card.appendChild(body); card.appendChild(footer);
      frag.appendChild(card);
    }

    container.appendChild(frag);
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

  // события
  if (brandEl) brandEl.addEventListener('change', function(){ updateModelOptions(this.value); applyFilters(); });
  if (modelEl) modelEl.addEventListener('change', applyFilters);

  if (vehicleTypeEl) {
    vehicleTypeEl.addEventListener('change', function(){
      updateBodyOptions(this.value);
      applyFilters();
    });
  }

  [vehicleBodyEl, fuelTypeEl, gearboxEl, yearFromEl, yearToEl, priceFromEl, priceToEl].forEach(el=>{ if(!el) return; el.addEventListener('change', applyFilters); });
  if (searchEl) searchEl.addEventListener('input', (function(){ let t; return function(){ clearTimeout(t); t=setTimeout(()=>applyFilters(),300); }; })());
  if (onlyMineEl) onlyMineEl.addEventListener('change', applyFilters);
  if (clearBtn) clearBtn.addEventListener('click', function(e){ e.preventDefault();
    [brandEl,modelEl,vehicleTypeEl,vehicleBodyEl,fuelTypeEl,gearboxEl,yearFromEl,yearToEl,priceFromEl,priceToEl,searchEl].forEach(el=>{ if(!el) return; if(el.tagName && el.tagName.toLowerCase()==='select') el.selectedIndex=0; else el.value=''; });
    if (onlyMineEl) onlyMineEl.checked=true;
    updateModelOptions('');
    updateBodyOptions('');
    applyFilters();
  });

  // инициализация: загружаем lookup'ы, заполняем селекты.
  (async function init(){
    await loadLookups();
    // Если нет serverItems — грузим через API. Если serverItems есть (отрисовано сервером), не затираем их сразу.
    if (!window.serverItems || !window.serverItems.length) {
      applyFilters();
    } else {
      // сервер уже отрисовал карточки — селекты заполнены, ждём действий пользователя
    }
  })();

})();
</script>

</body>
</html>
