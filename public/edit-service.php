<?php
// mehanik/public/edit-service.php 
session_start();
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../db.php';

if (empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}
$user = $_SESSION['user'];
$userId = (int)$user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: services.php');
    exit;
}

// load service
$service = null;
if ($st = $mysqli->prepare("SELECT id,user_id,name,description,logo,contact_name,phone,email,address,latitude,longitude FROM services WHERE id = ? LIMIT 1")) {
    $st->bind_param('i', $id);
    $st->execute();
    $service = $st->get_result()->fetch_assoc();
    $st->close();
}
if (!$service) {
    $_SESSION['flash_error'] = 'Сервис не найден';
    header('Location: services.php'); exit;
}
if (!$isAdmin && (int)$service['user_id'] !== $userId) {
    $_SESSION['flash_error'] = 'Нет доступа к редактированию';
    header('Location: services.php'); exit;
}

// helper
function toPublicUrl($rel){ if(!$rel) return ''; if(preg_match('#^https?://#i',$rel)) return $rel; if (strpos($rel,'/')===0) return $rel; return '/mehanik/' . ltrim($rel,'/'); }

// handlers kept as before (update_service / replace_logo / add_photos / delete_photo)
// ... (the same handlers as you already have in the file) ...
// For brevity in this snippet I re-use the server-side handlers from your existing file.
// (In your copy ensure the handlers block above remains exactly like previously.)

// --- ensure we have photos and prices loaded (existing logic) ---
$photos = [];
if ($st = $mysqli->prepare("SELECT id, photo FROM service_photos WHERE service_id = ? ORDER BY id ASC")) {
    $st->bind_param('i', $id);
    $st->execute();
    $photos = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}
$prices = [];
if ($st = $mysqli->prepare("SELECT id, name, price FROM service_prices WHERE service_id = ? ORDER BY id ASC")) {
    $st->bind_param('i', $id);
    $st->execute();
    $prices = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}

// try load staff if table exists: service_staff (id, service_id, name, position, photo)
$staff = [];
try {
    $res = $mysqli->query("SHOW TABLES LIKE 'service_staff'");
    if ($res && $res->num_rows > 0) {
        $st = $mysqli->prepare("SELECT id, name, position, photo FROM service_staff WHERE service_id = ? ORDER BY id ASC");
        if ($st) {
            $st->bind_param('i', $id);
            $st->execute();
            $staff = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();
        }
    }
    if ($res) $res->free();
} catch (Throwable $_) {
    // ignore
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Редактировать сервис — Mehanik</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <style>
    :root{
      --bg:#f7fafc;
      --card:#ffffff;
      --accent:#0b57a4;
      --muted:#6b7280;
      --border:#e9f1fb;
      --radius:12px;
    }
    *{box-sizing:border-box}
    body{font-family:Inter,system-ui,Arial;background:var(--bg);color:#0f1724;margin:0}
    .page{max-width:1100px;margin:20px auto;padding:16px}
    /* top-actions (outside card, horizontal) */
    .top-actions{ display:flex; align-items:center; justify-content:space-between; gap:12px; margin:0 auto 16px; max-width:1100px; padding:0 16px; }
    .top-actions h1{ margin:0; font-size:1.3rem; font-weight:800; color:#0f1724; }
    .controls { display:flex; gap:8px; align-items:center; }
    .btn { display:inline-flex; align-items:center; gap:8px; background:linear-gradient(180deg,var(--accent),#0f74d6); color:#fff; padding:10px 14px; border-radius:10px; border:0; cursor:pointer; font-weight:700; text-decoration:none; }
    .btn.ghost { background:transparent; color:var(--accent); border:1px solid rgba(11,87,164,0.08); padding:9px 12px; border-radius:10px; text-decoration:none; }

    /* single unified card */
    .card { background:var(--card); border-radius:14px; padding:20px; box-shadow:0 12px 40px rgba(12,20,30,0.06); border:1px solid var(--border); }
    /* stack vertically: make form-grid single-column */
    .form-grid { display:flex; flex-direction:column; gap:18px; align-items:stretch; }
    @media(max-width:980px){ .form-grid{ } }
    label.block { display:block; font-weight:700; margin-top:10px; color:#12202a; }
    .input, textarea, select { width:100%; padding:10px; border-radius:10px; border:1px solid #e6eef6; box-sizing:border-box; font-size:14px; margin-top:8px; background:#fff; }
    textarea{ min-height:110px; resize:vertical; }
    .row { display:flex; gap:10px; }
    .row .col{ flex:1; }
    .note { color:var(--muted); font-size:13px; margin-top:8px; }
    .map { height:260px; border-radius:10px; overflow:hidden; border:1px solid #e6eef7; margin-top:8px; }
    /* file pickers (fixed: position relative so invisible input won't cover page) */
    .file-picker { position:relative; display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px; border-radius:10px; border:1px dashed #e6eef6; background:linear-gradient(180deg,#fff,#fbfeff); cursor:pointer; margin-top:10px; min-height:64px; }
    .file-picker input[type=file]{ position:absolute; left:0; top:0; opacity:0; width:100%; height:100%; cursor:pointer; }
    .fp-left { display:flex; gap:10px; align-items:center; }
    .fp-title { font-weight:700; color:#0b3b60; }
    .fp-sub { color:var(--muted); font-size:.92rem; }
    .logo-preview { width:100%; height:160px; border-radius:10px; overflow:hidden; border:1px solid #eef6ff; display:flex; align-items:center; justify-content:center; background:#fff; margin-top:10px; }
    .logo-preview img { max-width:100%; max-height:100%; object-fit:contain; }
    .photos-grid { display:flex; flex-wrap:wrap; gap:10px; margin-top:12px; }
    .thumb { width:120px; height:90px; border-radius:8px; border:1px solid #eee; overflow:hidden; position:relative; background:#fff; display:flex; align-items:center; justify-content:center; }
    .thumb img { width:100%; height:100%; object-fit:cover; display:block; }
    .del-photo { position:absolute; top:6px; right:6px; background:rgba(255,255,255,0.95); border-radius:6px; padding:4px 6px; border:1px solid #f1f1f1; cursor:pointer; font-weight:700; }
    /* prices */
    .prices-rows { display:flex; flex-direction:column; gap:8px; margin-top:10px; }
    .price-row { display:flex; gap:8px; align-items:center; }
    .price-row .p-name { flex:1; }
    .price-row .p-price { width:140px; }
    /* staff */
    .staff { margin-top:14px; border-top:1px dashed #eef6f9; padding-top:12px; }
    .staff-rows { display:flex; flex-direction:column; gap:10px; margin-top:10px; }
    .staff-row { display:flex; gap:10px; align-items:center; }
    .s-photo { width:86px; height:86px; border-radius:8px; overflow:hidden; border:1px solid #eef6ff; display:flex; align-items:center; justify-content:center; background:#fff; }
    .s-photo img { width:100%; height:100%; object-fit:cover; }
    .s-name { flex:1; }
    .s-pos { width:220px; }
    .small-btn { padding:8px 10px; border-radius:10px; border:0; cursor:pointer; font-weight:700; background:#eef6ff; color:var(--accent); }
    .small-btn.del { background:#fff5f5; color:#b91c1c; border:1px solid #ffd6d6; }
    footer { padding:20px; text-align:center; color:#777; font-size:.9rem; }
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<main class="page">
  <!-- top actions OUTSIDE main card, horizontally aligned -->
  <div class="top-actions">
    <h1>Редактировать сервис</h1>
    <div class="controls">
      <a href="service.php?id=<?= $id ?>" class="btn ghost">Просмотреть</a>
      <a href="services.php" class="btn">К списку</a>
    </div>
  </div>

  <div class="card">
    <?php if (!empty($_SESSION['flash'])): ?>
      <div style="margin-bottom:12px;color:#065f46;background:#f0fdfa;border:1px solid #d1fae5;padding:10px;border-radius:8px;"><?= htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
      <div style="margin-bottom:12px;color:#7f1d1d;background:#fff5f5;border:1px solid #ffd6d6;padding:10px;border-radius:8px;"><?= htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
    <?php endif; ?>

    <div class="form-grid">
      <!-- FORM (stacked vertically) -->
      <div>
        <form method="post" action="edit-service.php?id=<?= $id ?>" enctype="multipart/form-data" id="serviceForm">
          <input type="hidden" name="action" value="update_service">
          <label class="block">Название*:</label>
          <input class="input" type="text" name="name" required value="<?= htmlspecialchars($service['name']) ?>">

          <div class="row" style="margin-top:8px;">
            <div class="col">
              <label class="block">Контактное имя</label>
              <input class="input" type="text" name="contact_name" value="<?= htmlspecialchars($service['contact_name']) ?>">
            </div>
            <div class="col">
              <label class="block">Телефон*</label>
              <input class="input" type="text" name="phone" required value="<?= htmlspecialchars($service['phone']) ?>" placeholder="+99371234567">
            </div>
          </div>

          <div class="row" style="margin-top:8px;">
            <div class="col">
              <label class="block">Email</label>
              <input class="input" type="email" name="email" value="<?= htmlspecialchars($service['email']) ?>">
            </div>
            <div class="col">
              <label class="block">Адрес</label>
              <input class="input" type="text" name="address" value="<?= htmlspecialchars($service['address']) ?>">
            </div>
          </div>

          <label class="block">Описание</label>
          <textarea class="input" name="description"><?= htmlspecialchars($service['description']) ?></textarea>

          <label class="block" style="margin-top:12px">Местоположение (щелчок по карте — поставить метку или ввести вручную)</label>
          <div id="map" class="map"></div>
          <input type="hidden" name="latitude" id="latitude" value="<?= htmlspecialchars($service['latitude']) ?>">
          <input type="hidden" name="longitude" id="longitude" value="<?= htmlspecialchars($service['longitude']) ?>">
          <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;align-items:center;">
            <label style="flex:1;min-width:160px;">Широта:
              <input class="input" type="text" id="latitude_manual" placeholder="например 37.9500" value="<?= htmlspecialchars($service['latitude']) ?>" />
            </label>
            <label style="flex:1;min-width:160px;">Долгота:
              <input class="input" type="text" id="longitude_manual" placeholder="например 58.3800" value="<?= htmlspecialchars($service['longitude']) ?>" />
            </label>
            <div class="note">Можно щёлкнуть по карте или ввести координаты вручную.</div>
          </div>

          <label class="block" style="margin-top:12px">Цены</label>
          <div class="prices-rows" id="pricesRows">
            <?php if (empty($prices)): ?>
              <div class="price-row">
                <input class="input p-name" type="text" name="prices[name][]" placeholder="Услуга">
                <input class="input p-price" type="text" name="prices[price][]" placeholder="Цена">
              </div>
            <?php else: foreach ($prices as $p): ?>
              <div class="price-row">
                <input class="input p-name" type="text" name="prices[name][]" value="<?= htmlspecialchars($p['name']) ?>" placeholder="Услуга">
                <input class="input p-price" type="text" name="prices[price][]" value="<?= htmlspecialchars($p['price']) ?>" placeholder="Цена">
              </div>
            <?php endforeach; endif; ?>
          </div>
          <div style="margin-top:10px;display:flex;gap:8px;align-items:center">
            <button type="button" id="addPrice" class="btn ghost" style="padding:8px 10px">+ Добавить позицию</button>
            <div class="note">Позиции будут сохранены как отдельные тарифы.</div>
          </div>

          <!-- STAFF section -->
          <div class="staff" id="staffSection">
            <div style="display:flex;justify-content:space-between;align-items:center">
              <div style="font-weight:800">Сотрудники</div>
              <div><button type="button" id="addStaffBtn" class="small-btn">+ Добавить сотрудника</button></div>
            </div>
            <div class="staff-rows" id="staffRows">
              <?php if (!empty($staff)): foreach ($staff as $s): ?>
                <div class="staff-row">
                  <div class="s-photo">
                    <?php if (!empty($s['photo'])): ?>
                      <img src="<?= htmlspecialchars(toPublicUrl($s['photo'])) ?>" alt="">
                    <?php else: ?>
                      <div style="color:var(--muted);padding:6px;font-weight:700">Нет фото</div>
                    <?php endif; ?>
                  </div>
                  <div class="s-name">
                    <input class="input" type="text" name="staff[name][]" value="<?= htmlspecialchars($s['name']) ?>" placeholder="Имя сотрудника" required>
                  </div>
                  <div class="s-pos">
                    <input class="input" type="text" name="staff[position][]" value="<?= htmlspecialchars($s['position']) ?>" placeholder="Должность" required>
                  </div>
                  <div style="display:flex;gap:8px;">
                    <input type="file" name="staff_photo[]" accept="image/*" style="display:none">
                    <button type="button" class="small-btn" onclick="(function(btn){ const f = btn.previousElementSibling; f.click(); })(this);">Изменить фото</button>
                    <button type="button" class="small-btn del" onclick="if(confirm('Удалить сотрудника?')) this.closest('.staff-row').remove();">Удалить</button>
                  </div>
                </div>
              <?php endforeach; else: ?>
                <!-- initially empty row not required; user can add -->
              <?php endif; ?>
            </div>
            <div class="note" style="margin-top:8px">Для каждого сотрудника укажите фото, имя и должность. Фото загружаются при сохранении формы.</div>
          </div>

          <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:18px">
            <a href="service.php?id=<?= $id ?>" class="btn ghost">Отмена</a>
            <button type="submit" class="btn">Сохранить изменения</button>
          </div>
        </form>
      </div>

      <!-- LOGO & PHOTOS (will appear under the form because grid is vertical) -->
      <div>
        <div style="margin-bottom:12px">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div style="font-weight:800">Логотип</div>
            <div class="note">Рекомендуемый ~500×300, max 5MB</div>
          </div>
          <div class="logo-preview" id="logoCurrentWrap">
            <?php if (!empty($service['logo'])): ?>
              <img id="currentLogo" src="<?= htmlspecialchars(toPublicUrl($service['logo'])) ?>" alt="logo">
            <?php else: ?>
              <div style="color:var(--muted);font-weight:700">Нет логотипа</div>
            <?php endif; ?>
          </div>

          <form method="post" enctype="multipart/form-data" style="margin-top:12px">
            <input type="hidden" name="action" value="replace_logo">
            <label class="file-picker" for="logoReplaceInput">
              <div class="fp-left">
                <div class="fp-title">Выбрать логотип</div>
                <div class="fp-sub">PNG, JPG, WEBP — заменит текущий</div>
              </div>
              <div style="display:flex;align-items:center;gap:10px">
                <div id="logoReplacePreview" style="width:84px;height:56px;border-radius:8px;border:1px solid #eef6ff;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden">
                  <span style="color:var(--muted);font-size:.85rem">Нет</span>
                </div>
                <input id="logoReplaceInput" type="file" name="logo" accept="image/*" required>
              </div>
            </label>
            <div style="display:flex;justify-content:flex-end;margin-top:10px;gap:8px">
              <button type="submit" class="btn">Заменить логотип</button>
            </div>
          </form>
        </div>

        <div>
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div style="font-weight:800">Фотографии</div>
            <div class="note">Добавьте до 10 новых</div>
          </div>

          <div class="photos-grid" id="existingPhotos" style="margin-top:12px">
            <?php if (empty($photos)): ?>
              <div class="note">Фото пока нет</div>
            <?php endif; ?>
            <?php foreach ($photos as $ph):
              $pid=(int)$ph['id'];
              $purl = toPublicUrl($ph['photo']);
            ?>
              <div class="thumb" title="Клик — удалить">
                <img src="<?= htmlspecialchars($purl) ?>" alt="">
                <form method="post" style="display:inline;">
                  <input type="hidden" name="action" value="delete_photo">
                  <input type="hidden" name="photo_id" value="<?= $pid ?>">
                  <button class="del-photo" type="submit" onclick="return confirm('Удалить фото?')">×</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>

          <form method="post" enctype="multipart/form-data" style="margin-top:12px">
            <input type="hidden" name="action" value="add_photos">
            <label class="file-picker" for="photosInput">
              <div class="fp-left">
                <div class="fp-title">Добавить фотографии</div>
                <div class="fp-sub">До 10 файлов, JPG/PNG/WEBP</div>
              </div>
              <div style="display:flex;align-items:center;gap:10px">
                <div id="photosPreviewSmall" style="display:flex;gap:8px;flex-wrap:wrap;max-width:220px"></div>
                <input id="photosInput" type="file" name="photos[]" accept="image/*" multiple>
              </div>
            </label>
            <div style="display:flex;justify-content:flex-end;margin-top:10px;gap:8px">
              <button type="submit" class="btn">Добавить фото</button>
            </div>
          </form>
        </div>
      </div>

    </div>
  </div>
</main>

<footer>&copy; <?= date('Y') ?> Mehanik</footer>

<!-- Google Maps like in add-service.php -->
<script>
function showManualCoordsFallback() {
  var mapEl = document.getElementById('map');
  if (mapEl) {
    mapEl.innerHTML = '<div style="padding:18px;color:#444">Карта недоступна. Введите координаты вручную ниже.</div>';
  }
}

function initMap() {
  try {
    var center = { lat: <?= ($service['latitude'] !== null && $service['latitude'] !== '') ? floatval($service['latitude']) : 37.95 ?>, lng: <?= ($service['longitude'] !== null && $service['longitude'] !== '') ? floatval($service['longitude']) : 58.38 ?> };
    var map = new google.maps.Map(document.getElementById('map'), {
      center: center,
      zoom: 13,
      streetViewControl: false
    });

    var latHidden = document.getElementById('latitude');
    var lngHidden = document.getElementById('longitude');
    var latManual = document.getElementById('latitude_manual');
    var lngManual = document.getElementById('longitude_manual');
    var marker = null;

    if (latHidden && lngHidden && latHidden.value && lngHidden.value) {
      var lat0 = parseFloat(latHidden.value);
      var lng0 = parseFloat(lngHidden.value);
      if (!isNaN(lat0) && !isNaN(lng0)) {
        marker = new google.maps.Marker({ position: { lat: lat0, lng: lng0 }, map: map });
        map.setCenter({ lat: lat0, lng: lng0 });
        if (latManual) latManual.value = lat0;
        if (lngManual) lngManual.value = lng0;
      }
    } else {
      if (latManual && lngManual && latManual.value && lngManual.value) {
        var latm = parseFloat(latManual.value);
        var lngm = parseFloat(lngManual.value);
        if (!isNaN(latm) && !isNaN(lngm)) {
          marker = new google.maps.Marker({ position: { lat: latm, lng: lngm }, map: map });
          map.setCenter({ lat: latm, lng: lngm });
          if (latHidden) latHidden.value = latm;
          if (lngHidden) lngHidden.value = lngm;
        }
      }
    }

    map.addListener('click', function(e) {
      var lat = e.latLng.lat();
      var lng = e.latLng.lng();
      if (marker) marker.setPosition(e.latLng); else marker = new google.maps.Marker({ position: e.latLng, map: map });
      if (latHidden) latHidden.value = lat;
      if (lngHidden) lngHidden.value = lng;
      if (latManual) latManual.value = lat;
      if (lngManual) lngManual.value = lng;
    });

    console.info('Google Maps initialized');
  } catch (err) {
    console.warn('Google Maps init error:', err);
    showManualCoordsFallback();
  }
}

// Fallback if google not loaded within 6s
setTimeout(function(){ if (typeof google === 'undefined' || typeof google.maps === 'undefined') { console.warn('Google Maps not available, showing manual fallback'); showManualCoordsFallback(); } }, 6000);

// Sync manual inputs <-> hidden inputs
(function(){
  var latHidden = document.getElementById('latitude');
  var lngHidden = document.getElementById('longitude');
  var latManual = document.getElementById('latitude_manual');
  var lngManual = document.getElementById('longitude_manual');

  function setHiddenFromManual() {
    if (!latHidden || !lngHidden || !latManual || !lngManual) return;
    latHidden.value = latManual.value.trim();
    lngHidden.value = lngManual.value.trim();
  }
  if (latManual) latManual.addEventListener('input', setHiddenFromManual);
  if (lngManual) lngManual.addEventListener('input', setHiddenFromManual);
  document.addEventListener('DOMContentLoaded', function(){
    if (latHidden && latHidden.value) latManual.value = latHidden.value;
    if (lngHidden && lngHidden.value) lngManual.value = lngHidden.value;
  });
})();
</script>

<!-- Add-service-like widgets: prices, photos preview, logo preview, staff -->
<script>
(function(){
  // prices
  const addPriceBtn = document.getElementById('addPrice');
  const pricesRows = document.getElementById('pricesRows');
  addPriceBtn.addEventListener('click', function(){
    const div = document.createElement('div'); div.className = 'price-row';
    const in1 = document.createElement('input'); in1.type='text'; in1.name='prices[name][]'; in1.className='input p-name'; in1.placeholder='Услуга';
    const in2 = document.createElement('input'); in2.type='text'; in2.name='prices[price][]'; in2.className='input p-price'; in2.placeholder='Цена';
    div.appendChild(in1); div.appendChild(in2);
    pricesRows.appendChild(div);
    in1.focus();
  });

  // logo preview
  const logoInput = document.getElementById('logoReplaceInput');
  const logoPreview = document.getElementById('logoReplacePreview');
  if (logoInput) {
    logoInput.addEventListener('change', function(){
      const f = this.files && this.files[0];
      if (!f) { logoPreview.innerHTML = '<span style="color:var(--muted)">Нет</span>'; return; }
      if (!f.type.startsWith('image/')) { alert('Только изображения допустимы'); this.value=''; return; }
      const fr = new FileReader();
      fr.onload = function(ev){
        logoPreview.innerHTML = '';
        const img = document.createElement('img');
        img.src = ev.target.result;
        img.style.width='100%'; img.style.height='100%'; img.style.objectFit='contain';
        logoPreview.appendChild(img);
      };
      fr.readAsDataURL(f);
    });
  }

  // photos preview
  const photosInput = document.getElementById('photosInput');
  const photosPreviewSmall = document.getElementById('photosPreviewSmall');
  if (photosInput) {
    photosInput.addEventListener('change', function(){
      const files = Array.from(this.files || []);
      photosPreviewSmall.innerHTML = '';
      if (files.length === 0) { photosPreviewSmall.innerHTML = '<span style="color:var(--muted)">Нет</span>'; return; }
      if (files.length > 10) { alert('Максимум 10 фотографий'); this.value=''; photosPreviewSmall.innerHTML=''; return; }
      files.slice(0,10).forEach(f => {
        if (!f.type.startsWith('image/')) return;
        const fr = new FileReader();
        fr.onload = function(ev){
          const div = document.createElement('div'); div.className='thumb';
          const img = document.createElement('img'); img.src = ev.target.result;
          div.appendChild(img);
          photosPreviewSmall.appendChild(div);
        };
        fr.readAsDataURL(f);
      });
    });
  }
})();
</script>

<!-- Staff widget: dynamic add rows and file inputs -->
<script>
(function(){
  const staffRows = document.getElementById('staffRows');
  const addStaffBtn = document.getElementById('addStaffBtn');

  function createStaffRow(name = '', position = '') {
    const r = document.createElement('div'); r.className = 'staff-row';

    const photoWrap = document.createElement('div'); photoWrap.className = 's-photo';
    const photoImg = document.createElement('img'); photoImg.style.display = 'none';
    const photoPlaceholder = document.createElement('div'); photoPlaceholder.style.color = 'var(--muted)'; photoPlaceholder.style.fontWeight = '700'; photoPlaceholder.style.padding = '6px'; photoPlaceholder.textContent = 'Нет фото';
    photoWrap.appendChild(photoPlaceholder);

    const photoInput = document.createElement('input'); photoInput.type='file'; photoInput.name='staff_photo[]'; photoInput.accept='image/*'; photoInput.style.display='none';

    photoInput.addEventListener('change', function(){
      const f = this.files && this.files[0];
      if (!f || !f.type.startsWith('image/')) return;
      const fr = new FileReader();
      fr.onload = function(ev){
        photoImg.src = ev.target.result;
        photoImg.style.display = 'block';
        if (photoPlaceholder.parentNode) photoPlaceholder.remove();
        photoWrap.appendChild(photoImg);
      };
      fr.readAsDataURL(f);
    });

    const nameWrap = document.createElement('div'); nameWrap.className = 's-name';
    const nameInput = document.createElement('input'); nameInput.type='text'; nameInput.name='staff[name][]'; nameInput.className='input'; nameInput.placeholder='Имя сотрудника'; nameInput.value = name;
    nameWrap.appendChild(nameInput);

    const posWrap = document.createElement('div'); posWrap.className = 's-pos';
    const posInput = document.createElement('input'); posInput.type='text'; posInput.name='staff[position][]'; posInput.className='input'; posInput.placeholder='Должность'; posInput.value = position;
    posWrap.appendChild(posInput);

    const actions = document.createElement('div'); actions.style.display='flex'; actions.style.gap='8px';
    const btnPhoto = document.createElement('button'); btnPhoto.type='button'; btnPhoto.className='small-btn'; btnPhoto.textContent='Выбрать фото';
    btnPhoto.addEventListener('click', function(){ photoInput.click(); });
    const btnRemove = document.createElement('button'); btnRemove.type='button'; btnRemove.className='small-btn del'; btnRemove.textContent='Удалить';
    btnRemove.addEventListener('click', function(){ if(confirm('Удалить сотрудника?')) r.remove(); });

    actions.appendChild(btnPhoto); actions.appendChild(btnRemove);

    r.appendChild(photoWrap);
    r.appendChild(photoInput);
    r.appendChild(nameWrap);
    r.appendChild(posWrap);
    r.appendChild(actions);

    return r;
  }

  addStaffBtn.addEventListener('click', function(){
    const row = createStaffRow('','');
    staffRows.appendChild(row);
    row.scrollIntoView({behavior:'smooth', block:'center'});
  });

  document.addEventListener('DOMContentLoaded', function(){
    // leave empty if no staff — user can add via button
  });
})();
</script>

<!-- Replace YOUR_GOOGLE_API_KEY with a real key -->
<script async defer src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_API_KEY&callback=initMap"></script>
</body>
</html>
