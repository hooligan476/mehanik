<?php
// mehanik/public/add-service.php (Google Maps version — replace YOUR_GOOGLE_API_KEY with your key)
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
    :root{--bg:#f7fafc;--card:#fff;--accent:#0b57a4;--muted:#e6e9ef}
    body{font-family:Inter,system-ui,Arial;background:var(--bg);color:#0f1724;margin:0}
    .page{max-width:980px;margin:20px auto;padding:16px}
    .card{background:var(--card);border-radius:12px;padding:20px;box-shadow:0 8px 30px rgba(12,20,30,0.04);border:1px solid #eef3f7}
    label.block{display:block;font-weight:600;margin-top:10px;color:#202733}
    .input,textarea,.file{width:100%;padding:12px;border-radius:10px;border:1px solid var(--muted);box-sizing:border-box;font-size:1rem}
    textarea{min-height:140px;resize:vertical}
    .row{display:flex;gap:12px}
    .row .col{flex:1}
    .btn{display:inline-block;background:var(--accent);color:#fff;padding:10px 14px;border-radius:10px;text-decoration:none;border:0;cursor:pointer;font-weight:700}
    .btn.ghost{background:transparent;color:var(--accent);border:1px solid #e6eefc}
    .notice{background:#f0fdfa;border:1px solid #d1fae5;padding:10px;border-radius:8px;color:#065f46;margin-bottom:12px}
    .error{background:#fff5f5;border:1px solid #ffd6d6;padding:10px;border-radius:8px;color:#8a1f1f;margin-bottom:12px}
    .prices{margin-top:12px;border:1px dashed var(--muted);padding:14px;border-radius:10px;background:#fbfcfe}
    .prices-rows{display:flex;flex-direction:column;gap:10px;margin-top:12px}
    .price-row{display:flex;gap:10px;align-items:center}
    .price-row .p-name{flex:1;padding:10px;border-radius:10px;border:1px solid var(--muted);background:#fff;min-height:40px;display:flex;align-items:center}
    .price-row .p-price{width:160px;padding:10px;border-radius:10px;border:1px solid var(--muted);background:#fff;text-align:right;min-height:40px;display:flex;align-items:center;justify-content:flex-end}
    .p-actions{display:flex;gap:8px}
    .small-btn{padding:8px 10px;border-radius:10px;border:0;cursor:pointer;font-weight:700;background:#eef6ff;color:var(--accent)}
    .small-btn.del{background:#fff5f5;color:#b91c1c;border:1px solid #ffd6d6}
    .form-foot{margin-top:18px;display:flex;gap:8px;align-items:center;justify-content:flex-end}
    .staff{margin-top:18px;border:1px dashed var(--muted);padding:14px;border-radius:10px;background:#fbfcfe}
    .staff-rows{display:flex;flex-direction:column;gap:10px;margin-top:12px}
    .staff-row{display:flex;gap:10px;align-items:center}
    .staff-row .s-photo{width:86px}
    .staff-row .s-photo input{width:86px}
    .staff-row .s-name{flex:1}
    .staff-row .s-pos{width:220px}
    .staff-actions{display:flex;gap:8px}
    .small-btn.remove{background:#fff5f5;color:#b91c1c;border:1px solid #ffd6d6;padding:8px 10px;border-radius:10px}
    @media(max-width:760px){.row{flex-direction:column}.price-row{flex-direction:column;align-items:stretch}.price-row .p-price{width:100%;text-align:left}.form-foot{flex-direction:column;align-items:stretch}.staff-row{flex-direction:column;align-items:stretch}.staff-row .s-pos{width:100%}}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<main class="page">
  <div class="card">
    <h1>Добавить сервис / услугу</h1>

    <?php if ($message): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form id="serviceForm" method="post" enctype="multipart/form-data" action="/mehanik/api/add-service.php" novalidate>
      <label class="block">Название*:
        <input class="input" type="text" name="name" required>
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

      <label class="block">Местоположение (щелкните по карте чтобы поставить метку)*:</label>
      <div id="map" style="height:320px;border:1px solid #ddd;border-radius:8px;"></div>
      <input type="hidden" name="latitude" id="latitude" required>
      <input type="hidden" name="longitude" id="longitude" required>

      <div style="display:flex; gap:12px; margin-top:12px; flex-wrap:wrap;">
        <label class="block" style="flex:1;">Логотип*:
          <input class="file" type="file" name="logo" accept="image/*" required>
        </label>

        <label class="block" style="flex:1;">Фотографии (до 10)*:
          <input class="file" type="file" name="photos[]" accept="image/*" multiple required>
        </label>
      </div>

      <div class="staff" aria-live="polite">
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <div style="font-weight:700;">Сотрудники (обязательно — добавьте хотя бы одного)</div>
          <div><button type="button" id="addStaffBtn" class="btn ghost">+ Добавить сотрудника</button></div>
        </div>
        <div class="staff-rows" id="staffRows"></div>
        <div style="margin-top:8px;color:#6b7280;font-size:.95rem;">Для каждого сотрудника обязательно укажите фото, имя и должность.</div>
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
// Google Maps initializer and manual fallback
function showManualCoordsFallback() {
  var mapEl = document.getElementById('map');
  if (mapEl) {
    mapEl.innerHTML = '<div style="padding:18px;color:#444">Карта недоступна. Укажите координаты вручную ниже.</div>';
  }
  if (!document.getElementById('manualCoords')) {
    var wrapper = document.createElement('div');
    wrapper.id = 'manualCoords';
    wrapper.style.marginTop = '12px';
    wrapper.innerHTML = '\n      <label class="block">Широта: <input class="input" id="latitude_manual" placeholder="например 37.95"></label>\n      <label class="block">Долгота: <input class="input" id="longitude_manual" placeholder="например 58.38"></label>\n    ';
    mapEl.parentNode.insertBefore(wrapper, mapEl.nextSibling);
    var latH = document.getElementById('latitude');
    var lngH = document.getElementById('longitude');
    var latM = document.getElementById('latitude_manual');
    var lngM = document.getElementById('longitude_manual');
    if (latH && latH.value) latM.value = latH.value;
    if (lngH && lngH.value) lngM.value = lngH.value;
    latM.addEventListener('input', function(){ if (latH) latH.value = this.value; });
    lngM.addEventListener('input', function(){ if (lngH) lngH.value = this.value; });
  }
}

function initMap() {
  try {
    var center = { lat: 37.95, lng: 58.38 };
    var map = new google.maps.Map(document.getElementById('map'), {
      center: center,
      zoom: 13,
      streetViewControl: false
    });

    var latHidden = document.getElementById('latitude');
    var lngHidden = document.getElementById('longitude');
    var marker = null;

    if (latHidden && lngHidden && latHidden.value && lngHidden.value) {
      var lat0 = parseFloat(latHidden.value);
      var lng0 = parseFloat(lngHidden.value);
      if (!isNaN(lat0) && !isNaN(lng0)) {
        marker = new google.maps.Marker({ position: { lat: lat0, lng: lng0 }, map: map });
        map.setCenter({ lat: lat0, lng: lng0 });
      }
    }

    map.addListener('click', function(e) {
      var lat = e.latLng.lat();
      var lng = e.latLng.lng();
      if (marker) marker.setPosition(e.latLng); else marker = new google.maps.Marker({ position: e.latLng, map: map });
      if (latHidden) latHidden.value = lat;
      if (lngHidden) lngHidden.value = lng;
    });

    console.info('Google Maps initialized');
  } catch (err) {
    console.warn('Google Maps init error:', err);
    showManualCoordsFallback();
  }
}

// If Google Maps doesn't load within 6s, show fallback
setTimeout(function(){ if (typeof google === 'undefined' || typeof google.maps === 'undefined') { console.warn('Google Maps not available, showing manual fallback'); showManualCoordsFallback(); } }, 6000);
</script>

<!-- Insert your API key below. Replace YOUR_GOOGLE_API_KEY with the real key when ready. -->
<script async defer src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_API_KEY&callback=initMap"></script>

<!-- Prices widget -->
<script>
(function(){
  const rows = document.getElementById('pricesRows');
  const addBtn = document.getElementById('addPriceBtn');

  function createRow(n = '', p = '', editable = false) {
    const r = document.createElement('div');
    r.className = 'price-row';

    const nameWrap = document.createElement('div');
    nameWrap.className = 'p-name';
    const priceWrap = document.createElement('div');
    priceWrap.className = 'p-price';

    const hin = document.createElement('input'); hin.type = 'hidden'; hin.name = 'prices[name][]'; hin.value = n;
    const hip = document.createElement('input'); hip.type = 'hidden'; hip.name = 'prices[price][]'; hip.value = p;

    const actions = document.createElement('div'); actions.className = 'p-actions';
    const btnEdit = document.createElement('button'); btnEdit.type = 'button'; btnEdit.className = 'small-btn'; btnEdit.textContent = 'Исправить';
    const btnDel = document.createElement('button'); btnDel.type = 'button'; btnDel.className = 'small-btn del'; btnDel.textContent = 'Удалить';
    actions.appendChild(btnEdit); actions.appendChild(btnDel);

    function makeInputs(initialName, initialPrice) {
      const nameInput = document.createElement('input'); nameInput.type = 'text'; nameInput.className = 'p-name'; nameInput.placeholder = 'Услуга'; nameInput.value = initialName || '';
      const priceInput = document.createElement('input'); priceInput.type = 'text'; priceInput.className = 'p-price'; priceInput.placeholder = 'Цена'; priceInput.value = initialPrice || '';
      return { nameInput, priceInput };
    }

    const nameText = document.createElement('div'); nameText.textContent = n || '—';
    const priceText = document.createElement('div'); priceText.textContent = p || '—';

    nameWrap.appendChild(nameText); priceWrap.appendChild(priceText);
    r.appendChild(nameWrap); r.appendChild(priceWrap); r.appendChild(actions); r.appendChild(hin); r.appendChild(hip);

    function startEdit() {
      if (r.dataset.editing === '1') return; r.dataset.editing = '1';
      const currentName = hin.value || ''; const currentPrice = hip.value || '';
      const { nameInput, priceInput } = makeInputs(currentName, currentPrice);
      r.replaceChild(nameInput, nameWrap); r.replaceChild(priceInput, priceWrap);
      btnEdit.textContent = 'Сохранить'; btnDel.textContent = 'Отмена'; btnDel.classList.remove('del');
      const save = function() { const newName = nameInput.value.trim(); const newPrice = priceInput.value.trim(); if (newName === '') { alert('Введите название услуги'); nameInput.focus(); return; } nameText.textContent = newName; priceText.textContent = newPrice !== '' ? newPrice : '—'; hin.value = newName; hip.value = newPrice; r.replaceChild(nameWrap, nameInput); r.replaceChild(priceWrap, priceInput); btnEdit.textContent = 'Исправить'; btnDel.textContent = 'Удалить'; btnDel.classList.add('del'); r.dataset.editing = '0'; btnEdit.onclick = startEdit; btnDel.onclick = delOrCancel; };
      const cancel = function() { r.replaceChild(nameWrap, nameInput); r.replaceChild(priceWrap, priceInput); btnEdit.textContent = 'Исправить'; btnDel.textContent = 'Удалить'; btnDel.classList.add('del'); r.dataset.editing = '0'; btnEdit.onclick = startEdit; btnDel.onclick = delOrCancel; };
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
    const lat = document.getElementById('latitude').value; const lng = document.getElementById('longitude').value;
    if (!lat || !lng) { alert('Пожалуйста, укажите местоположение (щелкните по карте или укажите координаты вручную).'); e.preventDefault(); return; }
    const requiredFields = ['name','contact_name','phone','email','description','address'];
    for (let fieldName of requiredFields) { const el = document.querySelector('[name="'+fieldName+'"]'); if (!el || el.value.trim() === '') { alert('Заполните поле: ' + fieldName.replace('_',' ')); e.preventDefault(); return; } }
    const logoInput = document.querySelector('input[name="logo"]'); if (!logoInput || logoInput.files.length === 0) { alert('Загрузите логотип (обязательно).'); e.preventDefault(); return; }
    const photosInput = document.querySelector('input[name="photos[]"]'); if (!photosInput || photosInput.files.length === 0) { alert('Загрузите хотя бы одну фотографию (обязательно).'); e.preventDefault(); return; }
    const staffRows = document.querySelectorAll('.staff-row'); if (staffRows.length === 0) { alert('Добавьте хотя бы одного сотрудника.'); e.preventDefault(); return; }
    for (let i = 0; i < staffRows.length; i++) { const row = staffRows[i]; const nameInput = row.querySelector('input[name="staff[name][]"]'); const posInput = row.querySelector('input[name="staff[position][]"]'); const photoInput = row.querySelector('input[name="staff_photo[]"]'); if (!nameInput || nameInput.value.trim() === '') { alert('Укажите имя для каждого сотрудника.'); e.preventDefault(); return; } if (!posInput || posInput.value.trim() === '') { alert('Укажите должность для каждого сотрудника.'); e.preventDefault(); return; } if (!photoInput || photoInput.files.length === 0) { alert('Загрузите фото для каждого сотрудника.'); e.preventDefault(); return; } }
  });
})();
</script>

<!-- Staff widget -->
<script>
(function(){
  const rows = document.getElementById('staffRows');
  const addBtn = document.getElementById('addStaffBtn');
  function createStaffRow(name = '', position = '') {
    const r = document.createElement('div'); r.className = 'staff-row';
    const photoWrap = document.createElement('div'); photoWrap.className = 's-photo'; const photoInput = document.createElement('input'); photoInput.type = 'file'; photoInput.name = 'staff_photo[]'; photoInput.accept = 'image/*'; photoInput.required = true; photoWrap.appendChild(photoInput);
    const nameWrap = document.createElement('div'); nameWrap.className = 's-name'; const nameInput = document.createElement('input'); nameInput.type = 'text'; nameInput.name = 'staff[name][]'; nameInput.placeholder = 'Имя сотрудника'; nameInput.className = 'input'; nameInput.value = name; nameInput.required = true; nameWrap.appendChild(nameInput);
    const posWrap = document.createElement('div'); posWrap.className = 's-pos'; const posInput = document.createElement('input'); posInput.type = 'text'; posInput.name = 'staff[position][]'; posInput.placeholder = 'Должность'; posInput.className = 'input'; posInput.value = position; posInput.required = true; posWrap.appendChild(posInput);
    const actions = document.createElement('div'); actions.className = 'staff-actions'; const btnRemove = document.createElement('button'); btnRemove.type = 'button'; btnRemove.className = 'small-btn remove'; btnRemove.textContent = 'Удалить'; btnRemove.onclick = function(){ if (!confirm('Удалить сотрудника?')) return; r.remove(); }; actions.appendChild(btnRemove);
    r.appendChild(photoWrap); r.appendChild(nameWrap); r.appendChild(posWrap); r.appendChild(actions);
    return r;
  }
  addBtn.addEventListener('click', function(){ rows.appendChild(createStaffRow('','')); const last = rows.lastElementChild; if (last) last.scrollIntoView({behavior:'smooth', block:'center'}); });
  document.addEventListener('DOMContentLoaded', function(){ if (rows.children.length === 0) rows.appendChild(createStaffRow('','')); });
})();
</script>

<script src="/mehanik/assets/js/main.js"></script>
</body>
</html>
