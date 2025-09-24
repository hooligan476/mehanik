<?php
// mehanik/public/add-service.php (Google Maps + manual coords) - visually improved
session_start();
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../db.php';

// only logged-in users
if (empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$message = $_GET['m'] ?? '';
$error = $_GET['err'] ?? '';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Добавить сервис — Mehanik</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/mehanik/assets/css/header.css">
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    :root{
      --bg:#f6f9fc;
      --card:#fff;
      --accent:#0b57a4;
      --accent-2:#0f74d6;
      --muted:#9aa6b2;
      --soft:#eef6ff;
      --danger:#ef4444;
      --radius:12px;
      --glass: rgba(255,255,255,0.6);
    }
    *{box-sizing:border-box}
    body{font-family:Inter,system-ui,Arial;background:var(--bg);color:#0f1724;margin:0;-webkit-font-smoothing:antialiased}
    .page{max-width:1100px;margin:26px auto;padding:18px}
    .card{background:linear-gradient(180deg,var(--card),#fbfdff);border-radius:14px;padding:22px;box-shadow:0 12px 40px rgba(12,20,30,0.06);border:1px solid rgba(12,20,30,0.04)}
    h1{margin:0 0 12px;font-size:1.4rem}
    .muted-small{color:var(--muted);font-size:.95rem}

    /* Form controls */
    label.block{display:block;font-weight:700;margin-top:12px;color:#12202a}
    .input, textarea, .file, .coord-input {
      width:100%;
      padding:12px 14px;
      border-radius:10px;
      border:1px solid #e6eef6;
      box-sizing:border-box;
      font-size:1rem;
      background: #fff;
      transition:box-shadow .12s ease, transform .06s ease;
    }
    .input:focus, textarea:focus, .coord-input:focus { outline:0; box-shadow: 0 6px 18px rgba(11,87,164,0.06); transform: translateY(-1px); border-color: rgba(11,87,164,0.12); }
    textarea{min-height:140px;resize:vertical}
    .row{display:flex;gap:12px}
    .row .col{flex:1}

    /* Buttons */
    .btn{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(180deg,var(--accent),var(--accent-2));color:#fff;padding:10px 14px;border-radius:10px;text-decoration:none;border:0;cursor:pointer;font-weight:700;box-shadow:0 8px 22px rgba(11,87,164,0.12)}
    .btn.ghost{background:transparent;color:var(--accent);border:1px solid rgba(11,87,164,0.09);box-shadow:none}
    .btn.small{padding:8px 10px;font-size:.95rem;border-radius:8px}
    .hint{color:var(--muted);font-size:.94rem;margin-top:6px}

    /* Notifications */
    .notice{background:#f0fdfa;border:1px solid #d1fae5;padding:10px;border-radius:10px;color:#065f46;margin-bottom:12px}
    .error{background:#fff5f5;border:1px solid #ffd6d6;padding:10px;border-radius:10px;color:#8a1f1f;margin-bottom:12px}

    /* Map */
    #map{height:360px;border-radius:12px;border:1px solid rgba(12,20,30,0.04);overflow:hidden;box-shadow:0 10px 30px rgba(2,6,23,0.04)}
    .coords-wrap{display:flex;gap:12px;flex-wrap:wrap;margin-top:12px;align-items:center}
    .coord-input{max-width:220px}

    /* Files UI */
    .files-grid{display:flex;gap:12px;flex-wrap:wrap;margin-top:10px}
    .file-picker {
      display:block;
      width:100%;
      padding:12px 14px;
      border-radius:12px;
      border:1px dashed #e6eef6;
      background:linear-gradient(180deg,#fff,#fbfeff);
      cursor:pointer;
      transition:transform .12s ease, box-shadow .12s ease;
      position:relative;
      min-height:68px;
      display:flex;
      align-items:center;
      gap:12px;
    }
    .file-picker:hover{transform:translateY(-4px);box-shadow:0 10px 30px rgba(11,87,164,0.06)}
    .file-picker input[type=file]{position:absolute;left:0;top:0;opacity:0;width:100%;height:100%;cursor:pointer}
    .file-picker .fp-left{display:flex;flex-direction:column;gap:6px;flex:1}
    .file-picker .fp-title{font-weight:700;color:#0b3b60}
    .file-picker .fp-sub{color:var(--muted);font-size:.92rem}
    .file-picker .fp-preview{display:flex;gap:8px;align-items:center;flex-wrap:wrap}

    .logo-preview{
      width:84px;height:56px;border-radius:8px;border:1px solid #eef6ff;background:#fff;display:inline-flex;align-items:center;justify-content:center;overflow:hidden;
    }
    .logo-preview img{width:100%;height:100%;object-fit:contain;display:block}

    .photos-preview{display:flex;gap:8px;flex-wrap:wrap}
    .photo-thumb{width:96px;height:64px;border-radius:8px;overflow:hidden;border:1px solid #e9f1fb;background:#fff;display:inline-flex;align-items:center;justify-content:center}
    .photo-thumb img{width:100%;height:100%;object-fit:cover;display:block}

    /* Staff / prices */
    .prices, .staff{
      margin-top:14px;padding:14px;border-radius:12px;background:linear-gradient(180deg,#fff,#fbfdff);border:1px solid rgba(11,87,164,0.03)
    }
    .prices-rows, .staff-rows{display:flex;flex-direction:column;gap:12px;margin-top:12px}
    .price-row, .staff-row{display:flex;gap:12px;align-items:center;padding:8px;border-radius:10px;background:linear-gradient(180deg,#fff,#fcfeff);border:1px solid #f0f6fb}
    .price-row .p-name{flex:1;font-weight:600}
    .price-row .p-price{width:160px;text-align:right;color:#0b57a4;font-weight:700}
    .staff-row{align-items:center}
    .s-photo{width:86px;height:86px;border-radius:10px;border:1px dashed #e6eef6;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#fff}
    .s-photo img{width:100%;height:100%;object-fit:cover}
    .s-name{flex:1}
    .s-pos{width:220px}
    .staff-actions, .p-actions{display:flex;gap:8px}

    .small-btn{padding:8px 10px;border-radius:10px;border:0;cursor:pointer;font-weight:700;background:#eef6ff;color:var(--accent)}
    .small-btn.del{background:#fff5f5;color:var(--danger);border:1px solid #ffe5e5}

    .form-foot{margin-top:18px;display:flex;gap:8px;align-items:center;justify-content:flex-end}
    @media(max-width:900px){.row{flex-direction:column}.price-row{flex-direction:column;align-items:stretch}.price-row .p-price{width:100%;text-align:left}.form-foot{flex-direction:column;align-items:stretch}.staff-row{flex-direction:column;align-items:stretch}.s-pos{width:100%}}
    /* subtle animation for add */
    .fade-in{animation:fadeIn .18s ease both}
    @keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<main class="page">
  <h1>Добавить сервис / услугу</h1>
  <div class="card">

    <?php if ($message): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form id="serviceForm" method="post" enctype="multipart/form-data" action="/mehanik/api/add-service.php" novalidate>
      <label class="block">Название*:
        <input class="input" type="text" name="name" required placeholder="Название сервиса">
      </label>

      <div class="row">
        <div class="col">
          <label class="block">Контактное имя*:
            <input class="input" type="text" name="contact_name" placeholder="Иван Иванов" required>
          </label>
        </div>
        <div class="col">
          <label class="block">Контактный телефон*:
            <input class="input" type="text" name="phone" required placeholder="+99371234567">
          </label>
        </div>
      </div>

      <label class="block">Email*:
        <input class="input" type="email" name="email" placeholder="example@mail.com" required>
      </label>

      <label class="block">Описание*:
        <textarea class="input" name="description" required placeholder="Краткое описание..."></textarea>
      </label>

      <label class="block">Адрес*:
        <input class="input" type="text" name="address" placeholder="Город, улица, дом" required>
      </label>

      <label class="block">Местоположение (клик по карте — поставить метку или введите вручную):</label>
      <div id="map"></div>

      <!-- Hidden fields that will actually be submitted. NOT required - coordinates optional -->
      <input type="hidden" name="latitude" id="latitude">
      <input type="hidden" name="longitude" id="longitude">

      <!-- Visible manual inputs (user-visible, NOT submitted directly) -->
      <div class="coords-wrap">
        <label style="flex:1;min-width:160px;">Широта:
          <input class="coord-input" type="text" id="latitude_manual" placeholder="например 37.9500" />
        </label>
        <label style="flex:1;min-width:160px;">Долгота:
          <input class="coord-input" type="text" id="longitude_manual" placeholder="например 58.3800" />
        </label>
        <div style="min-width:220px;color:var(--muted);font-size:.95rem;">Можно кликнуть по карте или ввести координаты вручную.</div>
      </div>

      <div style="display:flex; gap:12px; margin-top:12px; flex-wrap:wrap;">
        <!-- LOGO: styled picker with preview -->
        <label class="file-picker" for="logoInput" title="Загрузить логотип">
          <div class="fp-left">
            <div class="fp-title">Логотип*</div>
            <div class="fp-sub">PNG, JPG или WEBP — лучше квадратный</div>
            <div class="hint">Логотип обязателен — будет показываться в карточке сервиса</div>
          </div>
          <div class="fp-preview">
            <div class="logo-preview" id="logoPreviewArea" aria-hidden="true">
              <span style="color:var(--muted);font-size:.85rem">Нет</span>
            </div>
          </div>
          <input type="file" id="logoInput" name="logo" accept="image/*" required>
        </label>

        <!-- PHOTOS: multi files with small previews -->
        <label class="file-picker" for="photosInput" title="Загрузить фотографии">
          <div class="fp-left">
            <div class="fp-title">Фотографии (до 10)*</div>
            <div class="fp-sub">Покажите ваш сервис — до 10 фото</div>
            <div class="hint">Рекомендуется загружать качественные фотографии</div>
          </div>
          <div class="fp-preview photos-preview" id="photosPreviewArea" aria-hidden="true"></div>
          <input type="file" id="photosInput" name="photos[]" accept="image/*" multiple required>
        </label>
      </div>

      <div class="staff" aria-live="polite">
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <div style="font-weight:700;">Сотрудники (обязательно — добавьте хотя бы одного)</div>
          <div><button type="button" id="addStaffBtn" class="btn ghost">+ Добавить сотрудника</button></div>
        </div>
        <div class="staff-rows" id="staffRows"></div>
        <div class="hint">Для каждого сотрудника обязательно укажите фото, имя и должность.</div>
      </div>

      <div class="prices" aria-live="polite">
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <div style="font-weight:700;">Цены на услуги (обязательно — минимум одна)</div>
          <div><button type="button" id="addPriceBtn" class="btn ghost">+ Добавить услугу</button></div>
        </div>
        <div class="prices-rows" id="pricesRows"></div>
      </div>

      <div class="form-foot">
        <a href="services.php" class="btn ghost" style="text-decoration:none; padding:9px 12px;">Отмена</a>
        <button type="submit" class="btn">Сохранить и отправить на модерацию</button>
      </div>
    </form>
  </div>
</main>

<footer style="padding:20px;text-align:center;color:#777;font-size:.9rem;">&copy; <?= date('Y') ?> Mehanik</footer>

<script>
/* --- Map (same logic, but kept robust) --- */
function showManualCoordsFallback() {
  var mapEl = document.getElementById('map');
  if (mapEl) {
    mapEl.innerHTML = '<div style="padding:18px;color:#444">Карта недоступна. Введите координаты вручную ниже.</div>';
  }
}

function initMap() {
  try {
    var center = { lat: 37.95, lng: 58.38 };
    var map = new google.maps.Map(document.getElementById('map'), {
      center: center,
      zoom: 13,
      streetViewControl: false,
      mapTypeControl: false
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

setTimeout(function(){ if (typeof google === 'undefined' || typeof google.maps === 'undefined') { console.warn('Google Maps not available, showing manual fallback'); showManualCoordsFallback(); } }, 6000);

/* --- Sync manual coords <-> hidden fields --- */
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
  function setManualFromHidden() {
    if (!latHidden || !lngHidden || !latManual || !lngManual) return;
    if (latHidden.value) latManual.value = latHidden.value;
    if (lngHidden.value) lngManual.value = lngHidden.value;
  }
  if (latManual) latManual.addEventListener('input', setHiddenFromManual);
  if (lngManual) lngManual.addEventListener('input', setHiddenFromManual);
  document.addEventListener('DOMContentLoaded', setManualFromHidden);
})();

/* --- File pickers: logo + photos preview --- */
(function(){
  var logoInput = document.getElementById('logoInput');
  var logoPreview = document.getElementById('logoPreviewArea');
  var photosInput = document.getElementById('photosInput');
  var photosPreview = document.getElementById('photosPreviewArea');

  function clearLogoPreview() {
    logoPreview.innerHTML = '<span style="color:var(--muted);font-size:.85rem">Нет</span>';
  }
  function setLogoPreview(src) {
    logoPreview.innerHTML = '';
    var imgWrap = document.createElement('div');
    imgWrap.style.width = '100%';
    imgWrap.style.height = '100%';
    imgWrap.style.overflow = 'hidden';
    var img = document.createElement('img');
    img.src = src;
    img.alt = 'Logo';
    img.style.width = '100%';
    img.style.height = '100%';
    img.style.objectFit = 'contain';
    imgWrap.appendChild(img);
    logoPreview.appendChild(imgWrap);
  }

  function updatePhotosPreview(files) {
    photosPreview.innerHTML = '';
    var max = Math.min(files.length, 10);
    for (var i=0;i<max;i++){
      (function(f){
        var fr = new FileReader();
        fr.onload = function(ev){
          var t = document.createElement('div');
          t.className = 'photo-thumb fade-in';
          var im = document.createElement('img');
          im.src = ev.target.result;
          im.alt = f.name;
          t.appendChild(im);
          photosPreview.appendChild(t);
        };
        fr.readAsDataURL(f);
      })(files[i]);
    }
    if (files.length === 0) {
      photosPreview.innerHTML = '<span style="color:var(--muted);font-size:.9rem">Нет фото</span>';
    }
  }

  if (logoInput) {
    logoInput.addEventListener('change', function(){
      var f = this.files && this.files[0];
      if (!f) { clearLogoPreview(); return; }
      if (!f.type.startsWith('image/')) { alert('Только изображения допустимы для логотипа'); this.value=''; clearLogoPreview(); return; }
      var fr = new FileReader();
      fr.onload = function(ev){ setLogoPreview(ev.target.result); };
      fr.readAsDataURL(f);
    });
    // initialize preview placeholder
    document.addEventListener('DOMContentLoaded', function(){ clearLogoPreview(); });
  }
  if (photosInput) {
    photosInput.addEventListener('change', function(){
      var files = Array.from(this.files || []);
      if (files.length === 0) { photosPreview.innerHTML = '<span style="color:var(--muted);font-size:.9rem">Нет фото</span>'; return; }
      if (files.length > 10) { alert('Максимум 10 фотографий'); this.value = ''; photosPreview.innerHTML = ''; return; }
      updatePhotosPreview(files);
    });
    document.addEventListener('DOMContentLoaded', function(){ photosPreview.innerHTML = '<span style="color:var(--muted);font-size:.9rem">Нет фото</span>'; });
  }
})();

/* --- Prices widget (improved visuals, same behavior) --- */
(function(){
  const rows = document.getElementById('pricesRows');
  const addBtn = document.getElementById('addPriceBtn');

  function createRow(n = '', p = '', editable = false) {
    const r = document.createElement('div');
    r.className = 'price-row fade-in';

    const nameWrap = document.createElement('div'); nameWrap.className = 'p-name';
    const priceWrap = document.createElement('div'); priceWrap.className = 'p-price';

    const hin = document.createElement('input'); hin.type = 'hidden'; hin.name = 'prices[name][]'; hin.value = n;
    const hip = document.createElement('input'); hip.type = 'hidden'; hip.name = 'prices[price][]'; hip.value = p;

    const actions = document.createElement('div'); actions.className = 'p-actions';
    const btnEdit = document.createElement('button'); btnEdit.type = 'button'; btnEdit.className = 'small-btn'; btnEdit.textContent = 'Исправить';
    const btnDel = document.createElement('button'); btnDel.type = 'button'; btnDel.className = 'small-btn del'; btnDel.textContent = 'Удалить';
    actions.appendChild(btnEdit); actions.appendChild(btnDel);

    const nameText = document.createElement('div'); nameText.textContent = n || '—';
    const priceText = document.createElement('div'); priceText.textContent = p || '—';

    nameWrap.appendChild(nameText); priceWrap.appendChild(priceText);
    r.appendChild(nameWrap); r.appendChild(priceWrap); r.appendChild(actions); r.appendChild(hin); r.appendChild(hip);

    function makeInputs(initialName, initialPrice) {
      const nameInput = document.createElement('input'); nameInput.type = 'text'; nameInput.className = 'input'; nameInput.placeholder = 'Услуга'; nameInput.value = initialName || '';
      const priceInput = document.createElement('input'); priceInput.type = 'text'; priceInput.className = 'input'; priceInput.placeholder = 'Цена'; priceInput.value = initialPrice || '';
      // style smaller inside row
      nameInput.style.padding = '8px 10px';
      priceInput.style.padding = '8px 10px';
      priceInput.style.maxWidth = '220px';
      return { nameInput, priceInput };
    }

    function startEdit() {
      if (r.dataset.editing === '1') return; r.dataset.editing = '1';
      const currentName = hin.value || ''; const currentPrice = hip.value || '';
      const { nameInput, priceInput } = makeInputs(currentName, currentPrice);
      r.replaceChild(nameInput, nameWrap); r.replaceChild(priceInput, priceWrap);
      btnEdit.textContent = 'Сохранить'; btnDel.textContent = 'Отмена'; btnDel.classList.remove('del');
      const save = function() {
        const newName = nameInput.value.trim();
        const newPrice = priceInput.value.trim();
        if (newName === '') { alert('Введите название услуги'); nameInput.focus(); return; }
        nameText.textContent = newName;
        priceText.textContent = newPrice !== '' ? newPrice : '—';
        hin.value = newName; hip.value = newPrice;
        r.replaceChild(nameWrap, nameInput); r.replaceChild(priceWrap, priceInput);
        btnEdit.textContent = 'Исправить'; btnDel.textContent = 'Удалить'; btnDel.classList.add('del'); r.dataset.editing = '0';
        btnEdit.onclick = startEdit; btnDel.onclick = delOrCancel;
      };
      const cancel = function() {
        r.replaceChild(nameWrap, nameInput); r.replaceChild(priceWrap, priceInput);
        btnEdit.textContent = 'Исправить'; btnDel.textContent = 'Удалить'; btnDel.classList.add('del'); r.dataset.editing = '0';
        btnEdit.onclick = startEdit; btnDel.onclick = delOrCancel;
      };
      btnEdit.onclick = save; btnDel.onclick = cancel;
    }

    function delOrCancel() { if (r.dataset.editing === '1') return; if (!confirm('Удалить услугу?')) return; r.remove(); }
    btnEdit.onclick = startEdit; btnDel.onclick = delOrCancel;
    if (editable || (!n && !p)) { setTimeout(startEdit, 10); }
    return r;
  }

  addBtn.addEventListener('click', function(){ rows.appendChild(createRow('', '', true)); const last = rows.lastElementChild; if (last) last.scrollIntoView({behavior:'smooth', block:'center'}); });
  document.addEventListener('DOMContentLoaded', function(){ if (rows.children.length === 0) rows.appendChild(createRow('', '', true)); });

  const form = document.getElementById('serviceForm');
  form.addEventListener('submit', function(e){
    const editing = rows.querySelector('[data-editing="1"]');
    if (editing) { const saveBtn = editing.querySelector('.p-actions .small-btn'); if (saveBtn) { saveBtn.click(); if (editing.dataset.editing === '1') { e.preventDefault(); return; } } }
    const priceNames = Array.from(document.querySelectorAll('input[name="prices[name][]"]')).map(n => n.value.trim()).filter(v => v !== '');
    if (priceNames.length === 0) { alert('Добавьте хотя бы одну услугу с названием и сохраните её.'); e.preventDefault(); return; }

    // required field checks
    const requiredFields = ['name','contact_name','phone','email','description','address'];
    for (let fieldName of requiredFields) { const el = document.querySelector('[name="'+fieldName+'"]'); if (!el || el.value.trim() === '') { alert('Заполните поле: ' + fieldName.replace('_',' ')); e.preventDefault(); return; } }
    const logoInput = document.querySelector('input[name="logo"]'); if (!logoInput || logoInput.files.length === 0) { alert('Загрузите логотип (обязательно).'); e.preventDefault(); return; }
    const photosInput = document.querySelector('input[name="photos[]"]'); if (!photosInput || photosInput.files.length === 0) { alert('Загрузите хотя бы одну фотографию (обязательно).'); e.preventDefault(); return; }
    const staffRows = document.querySelectorAll('.staff-row'); if (staffRows.length === 0) { alert('Добавьте хотя бы одного сотрудника.'); e.preventDefault(); return; }
    for (let i = 0; i < staffRows.length; i++) { const row = staffRows[i]; const nameInput = row.querySelector('input[name="staff[name][]"]'); const posInput = row.querySelector('input[name="staff[position][]"]'); const photoInput = row.querySelector('input[name="staff_photo[]"]'); if (!nameInput || nameInput.value.trim() === '') { alert('Укажите имя для каждого сотрудника.'); e.preventDefault(); return; } if (!posInput || posInput.value.trim() === '') { alert('Укажите должность для каждого сотрудника.'); e.preventDefault(); return; } if (!photoInput || photoInput.files.length === 0) { alert('Загрузите фото для каждого сотрудника.'); e.preventDefault(); return; } }
  });
})();

/* --- Staff widget (file preview for staff photos) --- */
(function(){
  const rows = document.getElementById('staffRows');
  const addBtn = document.getElementById('addStaffBtn');

  function createStaffRow(name = '', position = '') {
    const r = document.createElement('div'); r.className = 'staff-row fade-in';

    const photoWrap = document.createElement('div'); photoWrap.className = 's-photo';
    const photoInput = document.createElement('input'); photoInput.type = 'file'; photoInput.name = 'staff_photo[]'; photoInput.accept = 'image/*'; photoInput.required = true;
    photoInput.style.opacity = 0; photoInput.style.width = '100%'; photoInput.style.height = '100%'; photoInput.style.cursor = 'pointer';
    photoWrap.appendChild(photoInput);
    const photoPlace = document.createElement('div'); photoPlace.style.width='100%'; photoPlace.style.height='100%'; photoPlace.style.display='flex'; photoPlace.style.alignItems='center'; photoPlace.style.justifyContent='center'; photoPlace.style.color = 'var(--muted)'; photoPlace.style.fontSize='.9rem'; photoPlace.textContent = 'Фото';
    photoWrap.appendChild(photoPlace);

    photoInput.addEventListener('change', function(){
      const f = this.files && this.files[0];
      if (!f) { photoPlace.textContent = 'Фото'; photoPlace.style.background = ''; photoPlace.style.color = 'var(--muted)'; return; }
      if (!f.type.startsWith('image/')) { alert('Только изображения допустимы'); this.value=''; return; }
      const fr = new FileReader();
      fr.onload = function(ev){
        photoPlace.innerHTML = '';
        const img = document.createElement('img');
        img.src = ev.target.result;
        img.style.width='100%'; img.style.height='100%'; img.style.objectFit='cover';
        photoPlace.appendChild(img);
      };
      fr.readAsDataURL(f);
    });

    const nameWrap = document.createElement('div'); nameWrap.className = 's-name';
    const nameInput = document.createElement('input'); nameInput.type = 'text'; nameInput.name = 'staff[name][]'; nameInput.placeholder = 'Имя сотрудника'; nameInput.className = 'input'; nameInput.value = name; nameInput.required = true;
    nameWrap.appendChild(nameInput);

    const posWrap = document.createElement('div'); posWrap.className = 's-pos';
    const posInput = document.createElement('input'); posInput.type = 'text'; posInput.name = 'staff[position][]'; posInput.placeholder = 'Должность'; posInput.className = 'input'; posInput.value = position; posInput.required = true;
    posWrap.appendChild(posInput);

    const actions = document.createElement('div'); actions.className = 'staff-actions';
    const btnRemove = document.createElement('button'); btnRemove.type = 'button'; btnRemove.className = 'small-btn del'; btnRemove.textContent = 'Удалить';
    btnRemove.onclick = function(){ if (!confirm('Удалить сотрудника?')) return; r.remove(); };
    actions.appendChild(btnRemove);

    r.appendChild(photoWrap); r.appendChild(nameWrap); r.appendChild(posWrap); r.appendChild(actions);
    return r;
  }

  addBtn.addEventListener('click', function(){ rows.appendChild(createStaffRow('','')); const last = rows.lastElementChild; if (last) last.scrollIntoView({behavior:'smooth', block:'center'}); });
  document.addEventListener('DOMContentLoaded', function(){ if (rows.children.length === 0) rows.appendChild(createStaffRow('','')); });
})();
</script>

<!-- Insert your API key below. Replace YOUR_GOOGLE_API_KEY with the real key when ready. -->
<script async defer src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_API_KEY&callback=initMap"></script>

<script src="/mehanik/assets/js/main.js"></script>
</body>
</html>
