<?php
// public/my-cars.php
// Single-file cars listing + API for filters (lookups come from admin tables ONLY)
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$noPhoto = '/mehanik/assets/no-photo.png';
$uploadsPrefix = '/mehanik/uploads/cars/';
$user_id = (int)($_SESSION['user']['id'] ?? 0);

// ---------------- API ----------------
if (isset($_GET['__api'])) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
            echo json_encode(['ok' => false, 'error' => 'DB missing']);
            exit;
        }

        // helpers
        $getStr = function($k) { return isset($_GET[$k]) ? trim((string)$_GET[$k]) : ''; };
        $getInt = function($k) { return (isset($_GET[$k]) && $_GET[$k] !== '') ? (int)$_GET[$k] : null; };
        $getFloat = function($k) { return (isset($_GET[$k]) && $_GET[$k] !== '') ? (float)$_GET[$k] : null; };

        // If value is numeric id from lookup table, convert to textual name for filtering.
        $resolveLookupName = function($table, $id, $nameCol = 'name', $idCol = 'id') use ($mysqli) {
            $id = (int)$id;
            if ($id <= 0) return null;
            $sql = "SELECT {$nameCol} FROM {$table} WHERE {$idCol} = ? LIMIT 1";
            if ($st = $mysqli->prepare($sql)) {
                $st->bind_param('i', $id);
                $st->execute();
                $res = $st->get_result();
                $row = $res ? $res->fetch_row() : null;
                $st->close();
                return $row ? trim((string)$row[0]) : null;
            }
            return null;
        };

        // inputs (we expect select values to be ids if provided)
        $brand_in = $getStr('brand');         // may be id or text
        $model_in = $getStr('model');
        $type_in  = $getStr('vehicle_type');
        $body_in  = $getStr('vehicle_body');
        $year_from = $getInt('year_from');
        $year_to   = $getInt('year_to');
        $price_from = $getFloat('price_from');
        $price_to   = $getFloat('price_to');
        $fuel_in = $getStr('fuel');
        $trans_in = $getStr('transmission');
        $q = $getStr('q');
        $limit = 200;

        // If numeric -> resolve to name via admin tables
        if ($brand_in !== '' && ctype_digit($brand_in)) {
            $tmp = $resolveLookupName('brands', (int)$brand_in, 'name', 'id');
            if ($tmp !== null) $brand_in = $tmp;
        }
        if ($model_in !== '' && ctype_digit($model_in)) {
            $tmp = $resolveLookupName('models', (int)$model_in, 'name', 'id');
            if ($tmp !== null) $model_in = $tmp;
        }
        if ($type_in !== '' && ctype_digit($type_in)) {
            $tmp = $resolveLookupName('vehicle_types', (int)$type_in, 'name', 'id');
            if ($tmp !== null) $type_in = $tmp;
        }
        if ($body_in !== '' && ctype_digit($body_in)) {
            $tmp = $resolveLookupName('vehicle_bodies', (int)$body_in, 'name', 'id');
            if ($tmp !== null) $body_in = $tmp;
        }
        if ($fuel_in !== '' && ctype_digit($fuel_in)) {
            $tmp = $resolveLookupName('fuel_types', (int)$fuel_in, 'name', 'id');
            if ($tmp !== null) $fuel_in = $tmp;
        }
        if ($trans_in !== '' && ctype_digit($trans_in)) {
            $tmp = $resolveLookupName('gearboxes', (int)$trans_in, 'name', 'id');
            if ($tmp !== null) $trans_in = $tmp;
        }

        // Build main SQL (cars table stores textual fields)
        $sql = "SELECT id, sku, user_id, vehicle_type, body, brand, model, year, mileage, transmission, fuel, price, photo, description, contact_phone, status, created_at, vin
                FROM cars
                WHERE (status = 'approved' OR status = 'active')";

        $params = []; $types = '';

        if ($brand_in !== '') { $sql .= " AND LOWER(IFNULL(brand,'')) = LOWER(?)"; $params[] = $brand_in; $types .= 's'; }
        if ($model_in !== '') { $sql .= " AND LOWER(IFNULL(model,'')) = LOWER(?)"; $params[] = $model_in; $types .= 's'; }
        if ($type_in  !== '') { $sql .= " AND LOWER(IFNULL(vehicle_type,'')) = LOWER(?)"; $params[] = $type_in; $types .= 's'; }
        if ($body_in  !== '') { $sql .= " AND LOWER(IFNULL(body,'')) = LOWER(?)"; $params[] = $body_in; $types .= 's'; }

        if ($year_from !== null) { $sql .= " AND (year IS NULL OR year >= ?)"; $params[] = $year_from; $types .= 'i'; }
        if ($year_to !== null)   { $sql .= " AND (year IS NULL OR year <= ?)"; $params[] = $year_to; $types .= 'i'; }

        if ($price_from !== null) { $sql .= " AND (price IS NULL OR price >= ?)"; $params[] = $price_from; $types .= 'd'; }
        if ($price_to   !== null) { $sql .= " AND (price IS NULL OR price <= ?)"; $params[] = $price_to; $types .= 'd'; }

        if ($fuel_in !== '') { $sql .= " AND LOWER(IFNULL(fuel,'')) = LOWER(?)"; $params[] = $fuel_in; $types .= 's'; }
        if ($trans_in !== ''){ $sql .= " AND LOWER(IFNULL(transmission,'')) = LOWER(?)"; $params[] = $trans_in; $types .= 's'; }

        // q: numeric => id match or sku contains, otherwise search brand/model/sku/description
        if ($q !== '') {
            if (ctype_digit($q)) {
                $sql .= " AND (id = ? OR sku LIKE CONCAT('%', ?, '%') OR model LIKE CONCAT('%', ?, '%') OR brand LIKE CONCAT('%', ?, '%'))";
                $params[] = (int)$q; $params[] = $q; $params[] = $q; $params[] = $q; $types .= 'isss';
            } else {
                $sql .= " AND (brand LIKE CONCAT('%', ?, '%') OR model LIKE CONCAT('%', ?, '%') OR sku LIKE CONCAT('%', ?, '%') OR description LIKE CONCAT('%', ?, '%') OR vin LIKE CONCAT('%', ?, '%'))";
                $params[] = $q; $params[] = $q; $params[] = $q; $params[] = $q; $params[] = $q; $types .= 'sssss';
            }
        }

        $sql .= " ORDER BY id DESC LIMIT " . (int)$limit;

        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            echo json_encode(['ok'=>false,'error'=>'DB prepare failed: '.$mysqli->error,'sql'=>$sql], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!empty($params)) {
            $bind = []; $bind[] = $types;
            for ($i=0;$i<count($params);$i++){ $name='p'.$i; $$name = $params[$i]; $bind[] = &$$name; }
            call_user_func_array([$stmt,'bind_param'], $bind);
        }

        if (!$stmt->execute()) {
            echo json_encode(['ok'=>false,'error'=>'Execute failed: '.$stmt->error], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $rows = [];
        if (method_exists($stmt,'get_result')) {
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $rows[] = $r;
            if ($res) $res->free();
        } else {
            $meta = $stmt->result_metadata();
            if ($meta) {
                $fields=[]; $out=[]; $bindParams=[];
                while ($f=$meta->fetch_field()) { $fields[]=$f->name; $out[$f->name]=null; $bindParams[]=&$out[$f->name]; }
                if (!empty($bindParams)) {
                    call_user_func_array([$stmt,'bind_result'],$bindParams);
                    while ($stmt->fetch()) { $row=[]; foreach($fields as $fn) $row[$fn]=$out[$fn]; $rows[]=$row; }
                }
                $meta->free();
            }
        }
        $stmt->close();

        // ---------------- lookups: ONLY from master tables ----------------
        $lookups = [
            'brands'=>[], 'models'=>[], 'vehicle_types'=>[], 'vehicle_bodies'=>[],
            'fuel_types'=>[], 'gearboxes'=>[], 'vehicle_years'=>[]
        ];

        $qmap = function($sql) use ($mysqli) {
            $out = [];
            $r = $mysqli->query($sql);
            if ($r) {
                while ($row = $r->fetch_assoc()) $out[] = $row;
                $r->free();
            }
            return $out;
        };

        // brands
        $lookups['brands'] = $qmap("SELECT id, name FROM brands ORDER BY name ASC");

        // models (include brand_id)
        $lookups['models'] = $qmap("SELECT id, name, brand_id FROM models ORDER BY name ASC");

        // vehicle types
        $lookups['vehicle_types'] = $qmap("SELECT id, `key`, name FROM vehicle_types ORDER BY `order` ASC, name ASC");

        // vehicle bodies (include vehicle_type_id)
        $lookups['vehicle_bodies'] = $qmap("SELECT id, vehicle_type_id, `key`, name FROM vehicle_bodies ORDER BY vehicle_type_id ASC, `order` ASC, name ASC");

        // fuel types
        $lookups['fuel_types'] = $qmap("SELECT id, `key`, name FROM fuel_types WHERE COALESCE(active,1)=1 ORDER BY `order` ASC, name ASC");

        // gearboxes
        $lookups['gearboxes'] = $qmap("SELECT id, `key`, name FROM gearboxes WHERE COALESCE(active,1)=1 ORDER BY `order` ASC, name ASC");

        // years
        $lookups['vehicle_years'] = $qmap("SELECT id, `year` FROM vehicle_years WHERE COALESCE(active,1)=1 ORDER BY `year` DESC");

        echo json_encode(['ok'=>true, 'cars'=>$rows, 'lookups'=>$lookups], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>'Unhandled: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ---------------- HTML page ----------------
?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Автомобили — Mehanik</title>
  <style>
:root{--bg:#f6f8fb;--card-bg:#fff;--muted:#6b7280;--accent:#0b57a4;--radius:10px}
*{box-sizing:border-box}
html,body{height:100%;margin:0;background:var(--bg);font-family:system-ui,Arial,sans-serif;color:#0f172a}
.page-wrap{max-width:1200px;margin:18px auto;padding:12px}
.topbar-row{display:flex;gap:12px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
.page-title{margin:0;font-size:1.25rem;font-weight:700}
.tools{margin-left:auto}
.layout{display:grid;grid-template-columns:320px 1fr;gap:18px}
@media(max-width:1000px){.layout{grid-template-columns:1fr}}
.sidebar{background:var(--card-bg);padding:14px;border-radius:12px;box-shadow:0 8px 24px rgba(2,6,23,0.04)}
.form-row{margin-top:10px;display:flex;flex-direction:column;gap:6px}
.form-row label{font-weight:700;color:#334155}
.form-row select,.form-row input{padding:8px;border-radius:8px;border:1px solid #e6eef7;background:linear-gradient(#fff,#fbfdff)}
.controls-row{display:flex;gap:8px;align-items:center;margin-top:12px}
#cars{display:flex;flex-direction:column;gap:10px;padding:6px 0}
.car-card{display:flex;gap:12px;padding:10px;border-radius:12px;background:var(--card-bg);box-shadow:0 6px 18px rgba(2,6,23,0.06);border:1px solid rgba(15,23,42,0.04);align-items:center}
.thumb{flex:0 0 140px;width:140px;height:84px;border-radius:8px;overflow:hidden;background:#f7f9fc;display:flex;align-items:center;justify-content:center}
.thumb img{width:auto;height:100%;object-fit:contain}
.card-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:6px}
.title{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.meta{color:var(--muted);font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.price-row{display:flex;justify-content:space-between;align-items:center}
.price{font-weight:800;color:var(--accent)}
.card-footer{display:flex;gap:8px;align-items:center;min-width:150px;flex:0 0 auto}
.btn-view{background:#eef6ff;color:var(--accent);border-radius:8px;padding:6px 10px;text-decoration:none;display:inline-block}
.no-cars{text-align:center;padding:28px;border-radius:10px;background:var(--card-bg);color:var(--muted)}
.muted{color:var(--muted);padding:12px}
  </style>
</head>
<body>

<?php require_once __DIR__ . '/header.php'; ?>

<div class="page-wrap">
  <div class="topbar-row">
    <h2 class="page-title">Автомобили</h2>
    <div class="tools">
      <a href="/mehanik/public/add-car.php" class="btn">➕ Добавить авто</a>
    </div>
  </div>

  <div class="layout">
    <aside class="sidebar" aria-label="Фильтр автомобилей">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <strong>Фильтр</strong>
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
        <label for="brand_car">Бренд</label>
        <select id="brand_car"><option value="">Все бренды</option></select>
      </div>

      <div class="form-row">
        <label for="model_car">Модель</label>
        <select id="model_car" disabled><option value="">Сначала выберите бренд</option></select>
      </div>

      <div class="form-row" style="flex-direction:row;gap:8px;">
        <div style="flex:1">
          <label for="year_from">Год (от)</label>
          <input id="year_from" type="number" min="1900" max="2050" placeholder="например 2005">
        </div>
        <div style="flex:1">
          <label for="year_to">Год (до)</label>
          <input id="year_to" type="number" min="1900" max="2050" placeholder="например 2020">
        </div>
      </div>

      <div class="form-row">
        <label for="fuel">Тип топлива</label>
        <select id="fuel"><option value="">Любое</option></select>
      </div>

      <div class="form-row">
        <label for="transmission">Коробка передач</label>
        <select id="transmission"><option value="">Любая</option></select>
      </div>

      <div class="form-row" style="flex-direction:row;gap:8px;">
        <div style="flex:1"><label for="price_from">Цена (от)</label><input id="price_from" type="number" min="0"></div>
        <div style="flex:1"><label for="price_to">Цена (до)</label><input id="price_to" type="number" min="0"></div>
      </div>

      <div class="form-row">
        <label for="search">Поиск (название / ID / VIN)</label>
        <input id="search" placeholder="например: Тойота или 123">
      </div>

      <div class="controls-row">
        <button id="clearFilters" class="btn-ghost">Сбросить</button>
        <div style="flex:1;color:#6b7280">Фильтры применяются автоматически.</div>
      </div>
    </aside>

    <section aria-live="polite">
      <div id="cars" class="grid">
        <div class="muted">Загрузка…</div>
      </div>
    </section>
  </div>
</div>

<script>
window.currentUserId = <?= json_encode($user_id) ?>;
window.uploadsPrefix = <?= json_encode($uploadsPrefix) ?>;
window.noPhoto = <?= json_encode($noPhoto) ?>;
</script>

<script>
(function(){
  const API = location.pathname.replace(/\/?$/, '') + '?__api=1';
  const carsContainer = document.getElementById('cars');

  const typeEl = document.getElementById('vehicle_type');
  const bodyEl = document.getElementById('vehicle_body');
  const brandEl = document.getElementById('brand_car');
  const modelEl = document.getElementById('model_car');
  const yearFromEl = document.getElementById('year_from');
  const yearToEl = document.getElementById('year_to');
  const fuelEl = document.getElementById('fuel');
  const transEl = document.getElementById('transmission');
  const priceFromEl = document.getElementById('price_from');
  const priceToEl = document.getElementById('price_to');
  const searchEl = document.getElementById('search');
  const clearBtn = document.getElementById('clearFilters');

  let modelsByBrand = {};
  let bodiesByType = {};
  let debounce = null;

  function safeText(v){ return v === null || v === undefined ? '' : String(v).trim(); }

  function setOptions(sel, items, placeholder){
    if(!sel) return;
    const prev = sel.value;
    sel.innerHTML = '';
    const o0 = document.createElement('option'); o0.value=''; o0.textContent = placeholder || '—'; sel.appendChild(o0);
    if (!Array.isArray(items) || !items.length) { sel.selectedIndex = 0; return; }
    for(const it of items){
      const label = it.name ?? (it.label??'');
      const val = (it.id !== undefined && it.id !== null && String(it.id) !== '') ? String(it.id) : String(label);
      const opt = document.createElement('option');
      opt.value = val;
      opt.textContent = label;
      sel.appendChild(opt);
    }
    if (prev && Array.from(sel.options).some(o=>o.value===prev)) sel.value = prev;
    else sel.selectedIndex = 0;
  }

  async function loadLookups(){
    try {
      const resp = await fetch(API, { credentials: 'same-origin' });
      if (!resp.ok) throw new Error('network');
      const json = await resp.json();
      if (!json.ok) throw new Error('api error');

      const lk = json.lookups || {};
      // brands: [{id,name}]
      const brands = lk.brands || [];
      // models: [{id,name,brand_id}]
      const models = lk.models || [];
      modelsByBrand = {};
      for(const m of models){
        const bid = String(m.brand_id ?? '');
        if (!bid) continue;
        if (!modelsByBrand[bid]) modelsByBrand[bid] = [];
        modelsByBrand[bid].push({ id: m.id, name: m.name });
      }
      // vehicle types
      const types = lk.vehicle_types || [];
      // bodies
      const bodies = lk.vehicle_bodies || [];
      bodiesByType = {};
      for(const b of bodies){
        const tid = String(b.vehicle_type_id ?? '');
        if (!tid) continue;
        if (!bodiesByType[tid]) bodiesByType[tid] = [];
        bodiesByType[tid].push({ id: b.id, name: b.name });
      }
      // fuel and gearboxes
      const fuels = lk.fuel_types || [];
      const gears = lk.gearboxes || [];
      // years (we'll show years in selects if desired; for now year inputs are free)
      const years = lk.vehicle_years || [];

      // populate selects (use id for values)
      setOptions(typeEl, types, 'Все типы');
      if (bodyEl) { bodyEl.innerHTML = '<option value="">Сначала выберите тип</option>'; bodyEl.disabled = true; }
      setOptions(brandEl, brands, 'Все бренды');
      if (modelEl) { modelEl.innerHTML = '<option value="">Сначала выберите бренд</option>'; modelEl.disabled = true; }
      setOptions(fuelEl, fuels, 'Любое');
      setOptions(transEl, gears, 'Любая');

    } catch(e) {
      console.warn('loadLookups failed', e);
    }
  }

  function updateModelSelect(brandVal){
    if (!modelEl) return;
    if (!brandVal) {
      modelEl.innerHTML = '<option value="">Сначала выберите бренд</option>';
      modelEl.disabled = true;
      return;
    }
    const list = modelsByBrand[brandVal] || [];
    setOptions(modelEl, list, 'Все модели');
    modelEl.disabled = false;
  }

  function updateBodySelect(typeVal){
    if (!bodyEl) return;
    if (!typeVal) {
      bodyEl.innerHTML = '<option value="">Сначала выберите тип</option>';
      bodyEl.disabled = true;
      return;
    }
    const list = bodiesByType[typeVal] || [];
    setOptions(bodyEl, list, 'Все кузова');
    bodyEl.disabled = false;
  }

  function collectFilters(){
    const f = {};
    const setIf = (k, el) => { if(!el) return; const v = safeText(el.value); if (v !== '') f[k] = v; };
    setIf('vehicle_type', typeEl);
    setIf('vehicle_body', bodyEl);
    setIf('brand', brandEl);
    setIf('model', modelEl);
    setIf('year_from', yearFromEl);
    setIf('year_to', yearToEl);
    setIf('transmission', transEl);
    setIf('fuel', fuelEl);
    setIf('price_from', priceFromEl);
    setIf('price_to', priceToEl);
    const qv = safeText(searchEl && searchEl.value);
    if (qv) f.q = qv;
    return f;
  }

  // Robust image URL builder to correctly handle different formats stored in DB.
  function buildPhotoUrl(photo) {
    if (!photo) return window.noPhoto;
    photo = String(photo).trim();
    // absolute URL -> return as-is
    if (/^https?:\/\//i.test(photo)) return photo;
    // absolute path -> return as-is
    if (photo.charAt(0) === '/') return photo;

    // ensure uploadsPrefix ends with single slash
    const upPrefix = (window.uploadsPrefix || '/uploads/cars/').replace(/\/+$/, '/') ;

    // prefer last occurrence of 'uploads/cars/' to avoid doubled prefixes
    const marker = 'uploads/cars/';
    const lastIdx = photo.lastIndexOf(marker);
    if (lastIdx !== -1) {
      const tail = photo.substring(lastIdx + marker.length);
      if (tail === '') return upPrefix;
      return upPrefix + tail;
    }

    // if contains 'uploads/' fallback to absolute path from that
    const uploadsIdx = photo.lastIndexOf('uploads/');
    if (uploadsIdx !== -1) {
      const tail = photo.substring(uploadsIdx);
      return '/' + tail.replace(/^\/+/, '');
    }

    // otherwise treat as filename relative to uploadsPrefix
    return upPrefix + photo.replace(/^\/+/, '');
  }

  async function fetchCars(){
    carsContainer.innerHTML = '<div class="muted">Загрузка...</div>';
    const filters = collectFilters();
    const params = new URLSearchParams(filters);
    try {
      const resp = await fetch(API + '&' + params.toString(), { credentials: 'same-origin' });
      if (!resp.ok) throw new Error('network');
      const json = await resp.json();
      if (!json.ok) {
        console.warn('api error', json);
        carsContainer.innerHTML = '<div class="muted">Ошибка API — см. консоль</div>';
        return;
      }
      const items = json.cars || [];
      renderCars(items);
    } catch (e) {
      console.warn('fetchCars err', e);
      carsContainer.innerHTML = '<div class="muted">Ошибка загрузки</div>';
    }
  }

  function renderCars(items){
    carsContainer.innerHTML = '';
    if (!Array.isArray(items) || items.length === 0) {
      carsContainer.innerHTML = '<div class="no-cars"><p style="font-weight:700;margin:0 0 8px;">По вашему запросу ничего не найдено</p><p style="margin:0;color:#6b7280">Попробуйте изменить фильтры.</p></div>';
      return;
    }
    const frag = document.createDocumentFragment();
    for(const it of items){
      const card = document.createElement('article'); card.className = 'car-card';
      const thumb = document.createElement('div'); thumb.className = 'thumb';
      const a = document.createElement('a'); a.href = '/mehanik/public/car.php?id=' + encodeURIComponent(it.id);
      const img = document.createElement('img');
      img.alt = ((it.brand||'') + ' ' + (it.model||'')).trim();
      // Use robust builder for photo path
      img.src = buildPhotoUrl(it.photo);
      a.appendChild(img); thumb.appendChild(a);

      const body = document.createElement('div'); body.className = 'card-body';
      const top = document.createElement('div'); top.className = 'price-row';
      const left = document.createElement('div'); left.style.flex = '1'; left.style.minWidth = '0';
      const title = document.createElement('div'); title.className = 'title';
      title.textContent = (((it.brand||'') + ' ' + (it.model||'')).trim() + (it.year ? ' '+it.year : ''));
      const meta = document.createElement('div'); meta.className = 'meta';
      meta.textContent = ((it.transmission ? it.transmission + ' • ' : '') + (it.fuel ? it.fuel : '')).trim() || (it.vin ? 'VIN: '+it.vin : '-');
      left.appendChild(title); left.appendChild(meta);

      const right = document.createElement('div'); right.style.textAlign = 'right'; right.style.minWidth = '140px';
      const price = document.createElement('div'); price.className = 'price';
      price.textContent = it.price ? (Number(it.price).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2}) + ' TMT') : '-';
      const idMeta = document.createElement('div'); idMeta.className = 'meta'; idMeta.style.marginTop='6px'; idMeta.style.fontSize='.85rem'; idMeta.textContent = 'ID: ' + (it.id||'-');
      right.appendChild(price); right.appendChild(idMeta);

      top.appendChild(left); top.appendChild(right);
      body.appendChild(top);

      const footer = document.createElement('div'); footer.className = 'card-footer';
      const actions = document.createElement('div'); actions.className = 'actions';
      const view = document.createElement('a'); view.className = 'btn-view'; view.href = '/mehanik/public/car.php?id=' + encodeURIComponent(it.id); view.textContent = '👁 Просмотр';
      actions.appendChild(view);
      footer.appendChild(actions);

      card.appendChild(thumb); card.appendChild(body); card.appendChild(footer);
      frag.appendChild(card);
    }
    carsContainer.appendChild(frag);
  }

  function scheduleFetch(){
    if (debounce) clearTimeout(debounce);
    debounce = setTimeout(()=>{ fetchCars(); debounce=null; }, 220);
  }

  // wiring
  if (typeEl) typeEl.addEventListener('change', function(){ updateBodySelect(this.value); scheduleFetch(); });
  if (brandEl) brandEl.addEventListener('change', function(){ updateModelSelect(this.value); scheduleFetch(); });
  if (modelEl) modelEl.addEventListener('change', scheduleFetch);
  if (bodyEl) bodyEl.addEventListener('change', scheduleFetch);
  [yearFromEl, yearToEl, fuelEl, transEl, priceFromEl, priceToEl].forEach(el => { if (el) el.addEventListener('change', scheduleFetch); });
  if (searchEl) searchEl.addEventListener('input', function(){ if (debounce) clearTimeout(debounce); debounce = setTimeout(()=>{ fetchCars(); debounce=null; }, 300); });

  if (clearBtn) clearBtn.addEventListener('click', function(e){
    e.preventDefault();
    [brandEl, modelEl, typeEl, bodyEl, yearFromEl, yearToEl, fuelEl, transEl, priceFromEl, priceToEl, searchEl].forEach(el=>{
      if(!el) return;
      if (el.tagName && el.tagName.toLowerCase()==='select') el.selectedIndex = 0;
      else el.value = '';
    });
    updateModelSelect('');
    updateBodySelect('');
    scheduleFetch();
  });

  (async function init(){
    await loadLookups();
    // small delay to let selects settle
    setTimeout(()=>{ scheduleFetch(); }, 40);
  })();

})();
</script>

</body>
</html>
