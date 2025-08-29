<?php
// public/add-product.php
require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php';

// Попробуем взять $mysqli (MySQLi) или $pdo (PDO) — поддерживаем оба варианта
$brands = [];
$cparts = [];

if (isset($mysqli) && $mysqli instanceof mysqli) {
    try {
        $r = $mysqli->query("SELECT id,name FROM brands ORDER BY name");
        while ($row = $r->fetch_assoc()) $brands[] = $row;
    } catch (Exception $e) {}
    try {
        $r = $mysqli->query("SELECT id,name FROM complex_parts ORDER BY name");
        while ($row = $r->fetch_assoc()) $cparts[] = $row;
    } catch (Exception $e) {}
} elseif (isset($pdo) && $pdo instanceof PDO) {
    try {
        $st = $pdo->query("SELECT id,name FROM brands ORDER BY name");
        $brands = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    try {
        $st = $pdo->query("SELECT id,name FROM complex_parts ORDER BY name");
        $cparts = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
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
    /* небольшие локальные стили */
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
    @media (max-width:800px){ .row { flex-direction:column; } }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<div class="container">
  <div class="card">
    <h2>Добавление товара</h2>

    <!-- Форма с отключённым автозаполнением и ловушкой -->
    <form id="addProductForm" enctype="multipart/form-data" method="post" action="/mehanik/api/add-product.php"
          autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" novalidate>
      <!-- ловушка для автозаполнения -->
      <input style="display:none" type="text" name="fakeuser" autocomplete="off">

      <label for="p_name">Название *</label>
      <input id="p_name" type="text" name="name" placeholder="Название" required>

      <label for="p_manuf">Производитель</label>
      <input id="p_manuf" type="text" name="manufacturer" placeholder="Производитель">

      <label for="p_quality">Состояние *</label>
      <select id="p_quality" name="quality" required>
        <option value="New">New</option>
        <option value="Used">Used</option>
      </select>

      <label for="p_rating">Качество (0.1–9.9) *</label>
      <input id="p_rating" type="number" name="rating" step="0.1" min="0.1" max="9.9" value="5.0" required>

      <label for="p_avail">Наличие *</label>
      <input id="p_avail" type="number" name="availability" placeholder="Наличие" value="1" required min="0">

      <label for="p_price">Цена *</label>
      <input id="p_price" type="number" step="0.01" name="price" placeholder="Цена" required min="0">

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
      <textarea id="p_description" name="description" placeholder="Описание"></textarea>

      <label for="p_photo">Фото</label>
      <input id="p_photo" type="file" name="photo" accept="image/*">

      <div class="actions">
        <button id="submitBtn" type="submit">Сохранить</button>
        <span class="hint">Поля отмеченные * обязательны к заполнению.</span>
      </div>
    </form>
  </div>
</div>

<script>
  // Автозаполнение: очистка и отключение «подстановки»
  (function () {
    // очистим возможные автозаполненные значения
    window.addEventListener('load', function () {
      setTimeout(() => {
        const f = document.getElementById('addProductForm');
        try { f.reset(); } catch (e) {}
        // дополнительная чистка для полей text/number
        ['p_name','p_manuf','p_rating','p_avail','p_price','p_description'].forEach(id=>{
          const el = document.getElementById(id);
          if (el) el.value = el.value ? el.value.trim() : '';
        });
      }, 40);
    });

    window.addEventListener('pagehide', () => {
      try {
        const f = document.getElementById('addProductForm');
        ['p_name','p_manuf','p_rating','p_avail','p_price','p_description','p_photo'].forEach(id=>{
          const el = document.getElementById(id);
          if (el) el.value = '';
        });
        try { f.reset(); } catch(e){}
      } catch(e){}
    });
  })();
</script>

<script>
  // динамическая подгрузка моделей и компонентов (через существующие API)
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
      } catch (e) {
        console.error('Ошибка загрузки моделей', e);
      }
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
      } catch (e) {
        console.error('Ошибка загрузки компонентов', e);
      }
    }

    if (brandSel) {
      brandSel.addEventListener('change', () => loadModels(brandSel.value));
      // если бренд уже выбран серверно — подгружаем модели
      if (brandSel.value) loadModels(brandSel.value);
    }

    if (cpartSel) {
      cpartSel.addEventListener('change', () => loadComponents(cpartSel.value));
      if (cpartSel.value) loadComponents(cpartSel.value);
    }
  })();
</script>

<script>
  // Отправка формы — поведение AJAX как у тебя было. Мы оставляем действие формы на /mehanik/api/add-product.php
  (function () {
    const form = document.getElementById('addProductForm');
    const btn = document.getElementById('submitBtn');
    form.addEventListener('submit', async function (e) {
      e.preventDefault();

      // простая клиентская валидация
      const required = [
        'p_name','p_quality','p_rating','p_avail','p_price','ap_brand','ap_model','ap_cpart','ap_comp'
      ];
      for (let id of required) {
        const el = document.getElementById(id) || document.querySelector(`[name="${id}"]`);
        if (el && String(el.value).trim() === '') {
          alert('Пожалуйста, заполните все обязательные поля.');
          el.focus();
          return;
        }
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
          // переход на карточку товара
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
