<?php
// mehanik/public/add-product.php
require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php';

// load brands and complex parts (mysqli or pdo support)
$brands = [];
$cparts = [];

if (isset($mysqli) && $mysqli instanceof mysqli) {
    try {
        $r = $mysqli->query("SELECT id,name FROM brands ORDER BY name");
        while ($row = $r->fetch_assoc()) $brands[] = $row;
    } catch (Throwable $e) {}
    try {
        $r = $mysqli->query("SELECT id,name FROM complex_parts ORDER BY name");
        while ($row = $r->fetch_assoc()) $cparts[] = $row;
    } catch (Throwable $e) {}
} elseif (isset($pdo) && $pdo instanceof PDO) {
    try {
        $st = $pdo->query("SELECT id,name FROM brands ORDER BY name");
        $brands = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    try {
        $st = $pdo->query("SELECT id,name FROM complex_parts ORDER BY name");
        $cparts = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Добавить товар — Mehanik</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/mehanik/assets/css/header.css">
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    .container { max-width:980px; margin:26px auto; padding:18px; }
    .card { background:#fff; border-radius:12px; padding:18px; box-shadow:0 8px 28px rgba(2,6,23,0.08); }
    h2 { margin-top:0; }
    label { display:block; margin-top:12px; font-weight:600; }
    input[type="text"], input[type="number"], select, textarea { width:100%; padding:10px 12px; border:1px solid #e6e9ef; border-radius:8px; box-sizing:border-box; font-size:14px; }
    textarea { min-height:120px; resize:vertical; }
    .row { display:flex; gap:10px; }
    .row input { flex:1; }
    .actions { margin-top:14px; display:flex; gap:10px; align-items:center; }
    button#submitBtn { background: linear-gradient(180deg,#0b57a4,#074b82); color:#fff; border:0; padding:10px 14px; border-radius:8px; cursor:pointer; font-weight:700; }
    .hint { font-size:13px; color:#6b7280; margin-top:6px; }
    .preview { display:flex; gap:8px; margin-top:8px; flex-wrap:wrap; }
    .preview img { width:100px; height:100px; object-fit:cover; border-radius:8px; border:1px solid #eee; }
    .logo-preview img { width:120px; height:80px; object-fit:cover; border-radius:8px; border:1px solid #eee; }
    @media (max-width:800px){ .row { flex-direction:column; } }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<div class="container">
  <div class="card">
    <h2>Добавление товара</h2>

    <form id="addProductForm" enctype="multipart/form-data" method="post" action="/mehanik/api/add-product.php"
          autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" novalidate>
      <input style="display:none" type="text" name="fakeuser" autocomplete="off">

      <label for="p_name">Название *</label>
      <input id="p_name" type="text" name="name" placeholder="Название" required>

      <label for="p_manuf">Производитель</label>
      <input id="p_manuf" type="text" name="manufacturer" placeholder="Производитель">

      <div class="row">
        <div style="flex:1">
          <label for="p_quality">Состояние *</label>
          <select id="p_quality" name="quality" required>
            <option value="New">New</option>
            <option value="Used">Used</option>
          </select>
        </div>
        <div style="width:140px">
          <label for="p_rating">Качество (0.1–9.9) *</label>
          <input id="p_rating" type="number" name="rating" step="0.1" min="0.1" max="9.9" value="5.0" required>
        </div>
      </div>

      <div class="row">
        <div style="width:160px">
          <label for="p_avail">Наличие *</label>
          <input id="p_avail" type="number" name="availability" placeholder="Наличие" value="1" required min="0">
        </div>
        <div style="flex:1">
          <label for="p_price">Цена *</label>
          <input id="p_price" type="number" step="0.01" name="price" placeholder="Цена" required min="0">
        </div>
      </div>

      <label for="ap_brand">Бренд *</label>
      <select id="ap_brand" name="brand_id" required>
        <option value="">-- выберите бренд --</option>
        <?php foreach ($brands as $b): ?>
          <option value="<?= htmlspecialchars($b['id']) ?>"><?= htmlspecialchars($b['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <label for="ap_model">Модель *</label>
      <select id="ap_model" name="model_id" required>
        <option value="">-- выберите модель --</option>
      </select>

      <label>Годы выпуска</label>
      <div class="row">
        <input type="number" name="year_from" placeholder="от">
        <input type="number" name="year_to" placeholder="до">
      </div>

      <label for="ap_cpart">Комплексная часть *</label>
      <select id="ap_cpart" name="complex_part_id" required>
        <option value="">-- выберите часть --</option>
        <?php foreach ($cparts as $c): ?>
          <option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <label for="ap_comp">Компонент *</label>
      <select id="ap_comp" name="component_id" required>
        <option value="">-- выберите компонент --</option>
      </select>

      <label for="p_description">Описание</label>
      <textarea id="p_description" name="description" placeholder="Описание товара (кратко)"></textarea>

      <label for="p_logo">Логотип (основной)</label>
      <input id="p_logo" type="file" name="logo" accept="image/*">
      <div class="logo-preview" id="logoPreview"></div>

      <label for="p_photos">Фотографии (до 10 штук)</label>
      <input id="p_photos" type="file" name="photos[]" accept="image/*" multiple>
      <div id="photosPreview" class="preview"></div>
      <div class="hint">Максимум 10 фото. Форматы: jpg, png, webp. Рекомендуемый размер ≤ 3MB на файл.</div>

      <div class="actions">
        <button id="submitBtn" type="submit">Сохранить</button>
        <span class="hint">Поля отмеченные * обязательны к заполнению.</span>
      </div>
    </form>
  </div>
</div>

<script>
  // динамическая подгрузка моделей и компонентов (используем ваши API)
  (function () {
    const brandSel = document.getElementById('ap_brand');
    const modelSel = document.getElementById('ap_model');
    const cpartSel = document.getElementById('ap_cpart');
    const compSel = document.getElementById('ap_comp');

    async function loadModels(brandId) {
      modelSel.innerHTML = '<option value="">-- выберите модель --</option>';
      if (!brandId) return;
      try {
        const res = await fetch(`/mehanik/api/get-models.php?brand_id=${encodeURIComponent(brandId)}`);
        if (!res.ok) throw new Error('network');
        const data = await res.json();
        (Array.isArray(data) ? data : []).forEach(m => {
          const o = document.createElement('option');
          o.value = m.id; o.textContent = m.name;
          modelSel.appendChild(o);
        });
      } catch (e) { console.error('Ошибка загрузки моделей', e); }
    }

    async function loadComponents(cpartId) {
      compSel.innerHTML = '<option value="">-- выберите компонент --</option>';
      if (!cpartId) return;
      try {
        const res = await fetch(`/mehanik/api/get-components.php?complex_part_id=${encodeURIComponent(cpartId)}`);
        if (!res.ok) throw new Error('network');
        const data = await res.json();
        (Array.isArray(data) ? data : []).forEach(c => {
          const o = document.createElement('option');
          o.value = c.id; o.textContent = c.name;
          compSel.appendChild(o);
        });
      } catch (e) { console.error('Ошибка загрузки компонентов', e); }
    }

    if (brandSel) {
      brandSel.addEventListener('change', () => loadModels(brandSel.value));
      if (brandSel.value) loadModels(brandSel.value);
    }
    if (cpartSel) {
      cpartSel.addEventListener('change', () => loadComponents(cpartSel.value));
      if (cpartSel.value) loadComponents(cpartSel.value);
    }
  })();
</script>

<script>
  // preview + limit for photos and logo preview
  (function(){
    const photosInput = document.getElementById('p_photos');
    const photosPreview = document.getElementById('photosPreview');
    const logoInput = document.getElementById('p_logo');
    const logoPreview = document.getElementById('logoPreview');

    const MAX_PHOTOS = 10;
    const ALLOWED = ['image/jpeg','image/png','image/webp'];
    const MAX_SIZE = 3 * 1024 * 1024; // 3MB

    function clearPreview() { photosPreview.innerHTML = ''; }
    photosInput && photosInput.addEventListener('change', function(){
      const files = Array.from(this.files || []);
      if (files.length > MAX_PHOTOS) {
        alert('Можно загрузить не более ' + MAX_PHOTOS + ' фото.');
        this.value = ''; clearPreview();
        return;
      }
      clearPreview();
      files.forEach(f => {
        if (!ALLOWED.includes(f.type)) return;
        if (f.size > MAX_SIZE) { alert('Один из файлов слишком большой: ' + f.name); this.value = ''; clearPreview(); return; }
        const r = new FileReader();
        r.onload = e => {
          const img = document.createElement('img');
          img.src = e.target.result;
          photosPreview.appendChild(img);
        };
        r.readAsDataURL(f);
      });
    });

    logoInput && logoInput.addEventListener('change', function(){
      logoPreview.innerHTML = '';
      const f = (this.files && this.files[0]) || null;
      if (!f) return;
      if (!ALLOWED.includes(f.type)) { alert('Неподдерживаемый формат логотипа'); this.value = ''; return; }
      if (f.size > MAX_SIZE) { alert('Логотип слишком большой'); this.value = ''; return; }
      const r = new FileReader();
      r.onload = e => {
        const img = document.createElement('img');
        img.src = e.target.result;
        logoPreview.appendChild(img);
      };
      r.readAsDataURL(f);
    });
  })();
</script>

<script>
  // AJAX submit with FormData and client validation (reuse your previous logic)
  (function () {
    const form = document.getElementById('addProductForm');
    const btn = document.getElementById('submitBtn');
    form.addEventListener('submit', async function (e) {
      e.preventDefault();

      // client-side required fields
      const requiredSelectors = [
        '#p_name','#p_quality','#p_rating','#p_avail','#p_price','#ap_brand','#ap_model','#ap_cpart','#ap_comp'
      ];
      for (let sel of requiredSelectors) {
        const el = document.querySelector(sel);
        if (!el || String(el.value).trim() === '') {
          alert('Пожалуйста, заполните все обязательные поля.');
          if (el) el.focus();
          return;
        }
      }

      // photos count check
      const photosInput = document.getElementById('p_photos');
      if (photosInput && photosInput.files.length > 10) {
        alert('Максимум 10 фото.');
        return;
      }

      btn.disabled = true;
      try {
        const fd = new FormData(form);
        const res = await fetch(form.action, {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (data && data.ok && data.id) {
          window.location.href = `/mehanik/public/product.php?id=${encodeURIComponent(data.id)}`;
        } else {
          alert('Ошибка: ' + (data && data.error ? data.error : 'Неизвестная ошибка при сохранении'));
        }
      } catch (err) {
        console.error(err);
        alert('Ошибка сети при сохранении товара');
      } finally {
        btn.disabled = false;
      }
    });
  })();
</script>

</body>
</html>
