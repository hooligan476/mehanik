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
    .price-row .p-name{flex:1;padding:10px;border-radius:10px;border:1px solid var(--muted);background:#fff}
    .price-row .p-price{width:160px;padding:10px;border-radius:10px;border:1px solid var(--muted);background:#fff;text-align:right}
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

/* Prices widget (как раньше) */
(function(){
  const rows = document.getElementById('pricesRows');
  const addBtn = document.getElementById('addPriceBtn');

  function createRow(n='', p=''){
    const r=document.createElement('div'); r.className='price-row';
    const name=document.createElement('div'); name.className='p-name'; name.textContent=n||'—';
    const price=document.createElement('div'); price.className='p-price'; price.textContent=p||'—';
    const actions=document.createElement('div'); actions.className='p-actions';
    const e=document.createElement('button'); e.type='button'; e.className='small-btn edit'; e.textContent='Исправить';
    const d=document.createElement('button'); d.type='button'; d.className='small-btn delete'; d.textContent='Удалить';
    actions.appendChild(e); actions.appendChild(d);
    r.appendChild(name); r.appendChild(price); r.appendChild(actions);
    const hin=document.createElement('input'); hin.type='hidden'; hin.name='prices[name][]'; hin.value=n;
    const hip=document.createElement('input'); hip.type='hidden'; hip.name='prices[price][]'; hip.value=p;
    r.appendChild(hin); r.appendChild(hip);

    e.onclick = function(){
      if(r.dataset.editing==='1') return;
      r.dataset.editing='1';
      const ni=document.createElement('input'); ni.type='text'; ni.className='p-name'; ni.value=hin.value; ni.style.padding='10px';
      const pi=document.createElement('input'); pi.type='text'; pi.className='p-price'; pi.value=hip.value; pi.style.padding='10px';
      r.replaceChild(ni, name); r.replaceChild(pi, price);
      e.textContent='Сохранить'; d.textContent='Отмена';
      e.onclick = function(){
        if(ni.value.trim()===''){ alert('Введите название услуги'); ni.focus(); return; }
        name.textContent = ni.value; price.textContent = pi.value || '—';
        hin.value = ni.value; hip.value = pi.value;
        r.replaceChild(name, ni); r.replaceChild(price, pi);
        e.textContent='Исправить'; d.textContent='Удалить'; r.dataset.editing='0';
        e.onclick = null; d.onclick = null;
      };
      d.onclick = function(){ r.replaceChild(name, ni); r.replaceChild(price, pi); e.textContent='Исправить'; d.textContent='Удалить'; r.dataset.editing='0'; e.onclick=null; d.onclick=null; };
    };
    d.onclick = function(){ if(confirm('Удалить услугу?')) r.remove(); };
    return r;
  }

  addBtn.addEventListener('click', ()=> rows.appendChild(createRow('','')));
})();
</script>

<script src="/mehanik/assets/js/main.js"></script>
</body>
</html>
