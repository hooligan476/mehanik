<?php
// mehanik/public/add-service.php
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
  <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css"/>
  <style>
    /* Сжато — ваши стили */
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
    @media(max-width:760px){.row{flex-direction:column}.price-row{flex-direction:column;align-items:stretch}.price-row .p-price{width:100%;text-align:left}.form-foot{flex-direction:column;align-items:stretch}}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<main class="page">
  <div class="card">
    <h1>Добавить сервис / услугу</h1>

    <?php if ($message): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form id="serviceForm" method="post" enctype="multipart/form-data" action="/mehanik/api/add-service.php">
      <label class="block">Название*:
        <input class="input" type="text" name="name" required>
      </label>

      <div class="row">
        <div class="col">
          <label class="block">Контактное имя:
            <input class="input" type="text" name="contact_name" placeholder="Иван Иванов">
          </label>
        </div>
        <div class="col">
          <label class="block">Контактный телефон*:
            <!-- placeholder ONLY, не value -->
            <input class="input" type="text" name="phone" required placeholder="+99371234567">
          </label>
        </div>
      </div>

      <label class="block">Email:
        <input class="input" type="email" name="email" placeholder="example@mail.com">
      </label>

      <label class="block">Описание*:
        <textarea class="input" name="description" required placeholder="Краткое описание..."></textarea>
      </label>

      <label class="block">Адрес:
        <input class="input" type="text" name="address" placeholder="Город, улица, дом">
      </label>

      <label class="block">Местоположение (щелкните по карте чтобы поставить метку):</label>
      <div id="map" style="height:320px;border:1px solid #ddd;border-radius:8px;"></div>
      <input type="hidden" name="latitude" id="latitude">
      <input type="hidden" name="longitude" id="longitude">

      <div style="display:flex; gap:12px; margin-top:12px; flex-wrap:wrap;">
        <label class="block" style="flex:1;">Логотип:
          <input class="file" type="file" name="logo" accept="image/*">
        </label>

        <label class="block" style="flex:1;">Фотографии (до 10):
          <input class="file" type="file" name="photos[]" accept="image/*" multiple>
        </label>
      </div>

      <div class="prices" aria-live="polite">
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <div style="font-weight:700;">Цены на услуги</div>
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

<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<script>
/* Map */
const map = L.map('map').setView([37.95,58.38],13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
let marker;
map.on('click', function(e){
  if(marker) marker.setLatLng(e.latlng); else marker = L.marker(e.latlng).addTo(map);
  document.getElementById('latitude').value = e.latlng.lat;
  document.getElementById('longitude').value = e.latlng.lng;
});

/* Prices widget */
(function(){
  const rows = document.getElementById('pricesRows');
  const addBtn = document.getElementById('addPriceBtn');

  // Create DOM for a row. If editable===true, show inputs immediately.
  function createRow(n = '', p = '', editable = false) {
    const r = document.createElement('div');
    r.className = 'price-row';

    // containers for display (either divs or inputs)
    const nameWrap = document.createElement('div');
    nameWrap.className = 'p-name';
    const priceWrap = document.createElement('div');
    priceWrap.className = 'p-price';

    // hidden inputs to be submitted
    const hin = document.createElement('input');
    hin.type = 'hidden';
    hin.name = 'prices[name][]';
    hin.value = n;

    const hip = document.createElement('input');
    hip.type = 'hidden';
    hip.name = 'prices[price][]';
    hip.value = p;

    // action buttons
    const actions = document.createElement('div');
    actions.className = 'p-actions';
    const btnEdit = document.createElement('button');
    btnEdit.type = 'button';
    btnEdit.className = 'small-btn';
    btnEdit.textContent = 'Исправить';
    const btnDel = document.createElement('button');
    btnDel.type = 'button';
    btnDel.className = 'small-btn del';
    btnDel.textContent = 'Удалить';
    actions.appendChild(btnEdit);
    actions.appendChild(btnDel);

    // helper to make inputs
    function makeInputs(initialName, initialPrice) {
      const nameInput = document.createElement('input');
      nameInput.type = 'text';
      nameInput.className = 'p-name';
      nameInput.placeholder = 'Услуга';
      nameInput.value = initialName || '';

      const priceInput = document.createElement('input');
      priceInput.type = 'text';
      priceInput.className = 'p-price';
      priceInput.placeholder = 'Цена';
      priceInput.value = initialPrice || '';

      return { nameInput, priceInput };
    }

    // default non-edit view
    const nameText = document.createElement('div');
    nameText.textContent = n || '—';
    const priceText = document.createElement('div');
    priceText.textContent = p || '—';

    // append initial nodes (we'll replace if editable)
    nameWrap.appendChild(nameText);
    priceWrap.appendChild(priceText);
    r.appendChild(nameWrap);
    r.appendChild(priceWrap);
    r.appendChild(actions);
    r.appendChild(hin);
    r.appendChild(hip);

    // edit flow
    function startEdit() {
      if (r.dataset.editing === '1') return;
      r.dataset.editing = '1';
      const currentName = hin.value || '';
      const currentPrice = hip.value || '';
      const { nameInput, priceInput } = makeInputs(currentName, currentPrice);

      // replace display with inputs
      r.replaceChild(nameInput, nameWrap);
      r.replaceChild(priceInput, priceWrap);

      btnEdit.textContent = 'Сохранить';
      btnDel.textContent = 'Отмена';
      btnDel.classList.remove('del');

      // save handler
      const save = function() {
        const newName = nameInput.value.trim();
        const newPrice = priceInput.value.trim();
        if (newName === '') { alert('Введите название услуги'); nameInput.focus(); return; }
        // restore text nodes
        nameText.textContent = newName;
        priceText.textContent = newPrice !== '' ? newPrice : '—';
        hin.value = newName;
        hip.value = newPrice;
        r.replaceChild(nameWrap, nameInput);
        r.replaceChild(priceWrap, priceInput);
        btnEdit.textContent = 'Исправить';
        btnDel.textContent = 'Удалить';
        btnDel.classList.add('del');
        r.dataset.editing = '0';
        // rebind handlers to original functions
        btnEdit.onclick = startEdit;
        btnDel.onclick = delOrCancel;
      };

      // cancel handler
      const cancel = function() {
        r.replaceChild(nameWrap, nameInput);
        r.replaceChild(priceWrap, priceInput);
        btnEdit.textContent = 'Исправить';
        btnDel.textContent = 'Удалить';
        btnDel.classList.add('del');
        r.dataset.editing = '0';
        btnEdit.onclick = startEdit;
        btnDel.onclick = delOrCancel;
      };

      // temporarily override handlers
      btnEdit.onclick = save;
      btnDel.onclick = cancel;
    }

    // delete or cancel depending on state
    function delOrCancel() {
      if (r.dataset.editing === '1') return; // should be handled by cancel above
      if (!confirm('Удалить услугу?')) return;
      r.remove();
    }

    // initial binding
    btnEdit.onclick = startEdit;
    btnDel.onclick = delOrCancel;

    // if editable requested at creation - start editing immediately
    if (editable || (!n && !p)) {
      // replace after a tick so the element exists in DOM if caller wants to focus
      setTimeout(startEdit, 10);
    }

    return r;
  }

  addBtn.addEventListener('click', function(){
    rows.appendChild(createRow('', '', true));
    // scroll last into view
    const last = rows.lastElementChild;
    if (last) last.scrollIntoView({behavior:'smooth', block:'center'});
  });

  // on load - add one empty editable row to prompt user
  document.addEventListener('DOMContentLoaded', function(){
    if (rows.children.length === 0) rows.appendChild(createRow('', '', true));
  });

  // ensure editing rows are saved before submit
  const form = document.getElementById('serviceForm');
  form.addEventListener('submit', function(e){
    // find any row still in editing mode -> try to save by triggering Save button
    const editing = rows.querySelector('[data-editing="1"]');
    if (editing) {
      const saveBtn = editing.querySelector('.p-actions .small-btn');
      if (saveBtn) {
        // try to trigger its click (which when editing is bound to 'Сохранить' logic)
        saveBtn.click();
        // if still editing (because validation failed), prevent submit
        if (editing.dataset.editing === '1') {
          e.preventDefault();
          return;
        }
      }
    }
    // ok to submit - hidden inputs already present
  });
})();
</script>

<script src="/mehanik/assets/js/main.js"></script>
</body>
</html>
