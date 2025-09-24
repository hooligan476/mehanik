<?php
// mehanik/public/add-product.php
require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php';

// current user's phone for read-only contact display
$user_phone = $_SESSION['user']['phone'] ?? '';

// small helper
if (!function_exists('h')) {
    function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
}

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
  <style>
    /* Inline styles matched visually to add-car.php */
    :root { --accent: #0b57a4; --muted: #6b7280; --bg:#fff; --card-shadow: 0 8px 24px rgba(2,6,23,0.06); }
    body { font-family: Inter, system-ui, Arial, sans-serif; background:#f6f8fb; margin:0; color:#0f172a; -webkit-font-smoothing:antialiased; }
    .page { max-width:1100px; margin:18px auto; padding:14px; box-sizing:border-box; }
    .card { background:var(--bg); border-radius:10px; box-shadow:var(--card-shadow); overflow:hidden; }
    .card-body { padding:18px; }
    h2 { margin:0 0 10px; font-size:1.4rem; font-weight:800; }
    .muted { color:var(--muted); font-size:.95rem; }
    .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    @media (max-width:760px){ .form-grid { grid-template-columns:1fr } }
    label.block{ display:block; font-weight:700; margin-bottom:6px; }
    input[type=text], input[type=number], select, textarea { width:100%; padding:10px 12px; border:1px solid #e6e9ef; border-radius:8px; box-sizing:border-box; font-size:14px; background:#fff; color:#0f172a; }
    textarea { min-height:120px; resize:vertical; }
    .row { display:flex; gap:10px; }
    .row input { flex:1; }
    .actions { margin-top:14px; display:flex; gap:10px; align-items:center; justify-content:flex-end; }
    .btn { background:var(--accent); color:#fff; padding:10px 14px; border-radius:8px; border:0; cursor:pointer; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:8px; }
    .btn-ghost { background:transparent; border:1px solid #e6eef7; color:var(--accent); padding:8px 12px; border-radius:8px; text-decoration:none; display:inline-block; }
    .hint { font-size:13px; color:var(--muted); margin-top:6px; }
    .preview { display:flex; gap:8px; margin-top:8px; flex-wrap:wrap; }
    .preview-item { position:relative; width:120px; height:80px; border-radius:8px; overflow:hidden; border:1px solid #e6eef7; display:flex; align-items:center; justify-content:center; background:#fafafa; }
    .preview-item img { width:100%; height:100%; object-fit:cover; display:block; }
    .preview-item .actions { position:absolute; left:6px; top:6px; display:flex; flex-direction:column; gap:6px; }
    .preview-item button { font-size:11px; padding:5px 7px; border-radius:6px; border:0; cursor:pointer; background:rgba(0,0,0,0.6); color:#fff; }
    .preview-item .main-badge { position:absolute; right:6px; top:6px; background:var(--accent); color:#fff; padding:4px 6px; border-radius:6px; font-size:11px; }
    .logo-preview img { width:120px; height:80px; object-fit:cover; border-radius:8px; border:1px solid #eef3f7; display:block; }
    .dropzone { margin-top:8px; padding:12px; border:1px dashed #e6e9ef; border-radius:8px; text-align:center; color:var(--muted); cursor:pointer; background:#fff; }
    .dropzone.dragover { background:#f0f8ff; border-color:#b6e0ff; color:#044a75; }
    .error-text { color:#b91c1c; margin-top:6px; font-size:0.95rem; display:none; }
    .progress-wrap { margin-top:10px; display:none; align-items:center; gap:8px; }
    .progress-bar { height:10px; background:#e6eefc; border-radius:6px; overflow:hidden; flex:1; }
    .progress-bar > i { display:block; height:100%; width:0%; background:var(--accent); }
    .progress-text { min-width:60px; text-align:right; font-size:13px; color:#374151; }
    @media (max-width:800px){ .row { flex-direction:column; } .preview-item{ width:80px;height:80px } }
    .delivery-row { display:flex; gap:10px; align-items:center; margin-top:8px; }
    .delivery-price { width:180px; }
    .error-block { background:#fff6f6; border:1px solid #f5c2c2; color:#8a1f1f; padding:10px; border-radius:8px; margin-top:12px; display:none; }
    .small { font-size:.95rem; color:var(--muted); }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<div class="page">
  <div class="card">
    <div class="card-body">
      <h2>Добавление товара</h2>
      <p class="muted" style="margin:0 0 12px;">Поля с * обязательны. Интерфейс повторяет добавление авто: drag & drop, превью, выбор главного фото.</p>

      <form id="addProductForm" enctype="multipart/form-data" method="post" action="/mehanik/api/add-product.php"
            autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" novalidate>
        <input style="display:none" type="text" name="fakeuser" autocomplete="off">

        <div class="form-grid">
          <div>
            <label class="block" for="p_name">Название *</label>
            <input id="p_name" type="text" name="name" placeholder="Название" required>
            <div class="error-text" id="err_name">Введите название</div>
          </div>

          <div>
            <label class="block" for="p_manuf">Производитель</label>
            <input id="p_manuf" type="text" name="manufacturer" placeholder="Производитель">
          </div>

          <div>
            <label class="block" for="p_quality">Состояние *</label>
            <select id="p_quality" name="quality" required>
              <option value="New">New</option>
              <option value="Used">Used</option>
            </select>
          </div>

          <div>
            <label class="block" for="p_rating">Качество (0.1–9.9) *</label>
            <input id="p_rating" type="number" name="rating" step="0.1" min="0.1" max="9.9" value="5.0" required>
            <div class="error-text" id="err_rating">Введите корректное значение</div>
          </div>

          <div>
            <label class="block" for="p_avail">Наличие *</label>
            <input id="p_avail" type="number" name="availability" placeholder="Наличие" value="1" required min="0">
          </div>

          <div>
            <label class="block" for="p_price">Цена *</label>
            <input id="p_price" type="number" step="0.01" name="price" placeholder="Цена" required min="0">
            <div class="error-text" id="err_price">Введите положительную цену</div>
          </div>

          <div>
            <label class="block" for="p_delivery">Доставка</label>
            <div class="delivery-row">
              <select id="p_delivery" name="delivery">
                <option value="0">Нет</option>
                <option value="1">Да</option>
              </select>
              <input id="p_delivery_price" class="delivery-price" type="number" step="0.01" name="delivery_price" placeholder="Цена доставки" min="0" style="display:none">
              <div id="err_delivery_price" class="error-text" style="display:none">Введите цену доставки</div>
            </div>
          </div>

          <div>
            <label class="block" for="ap_brand">Бренд *</label>
            <select id="ap_brand" name="brand_id" required>
              <option value="">-- выберите бренд --</option>
              <?php foreach ($brands as $b): ?>
                <option value="<?= (int)$b['id'] ?>"><?= h($b['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="error-text" id="err_brand">Выберите бренд</div>
          </div>

          <div>
            <label class="block" for="ap_model">Модель *</label>
            <select id="ap_model" name="model_id" required>
              <option value="">-- выберите модель --</option>
            </select>
            <div class="error-text" id="err_model">Выберите модель</div>
          </div>

          <div>
            <label class="block">Годы выпуска</label>
            <div class="row">
              <input type="number" name="year_from" placeholder="от">
              <input type="number" name="year_to" placeholder="до">
            </div>
          </div>

          <div>
            <label class="block" for="ap_cpart">Комплексная часть *</label>
            <select id="ap_cpart" name="complex_part_id" required>
              <option value="">-- выберите часть --</option>
              <?php foreach ($cparts as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block" for="ap_comp">Компонент *</label>
            <select id="ap_comp" name="component_id" required>
              <option value="">-- выберите компонент --</option>
            </select>
          </div>

          <div style="grid-column:1 / -1">
            <label class="block" for="p_description">Описание</label>
            <textarea id="p_description" name="description" placeholder="Описание товара (кратко)"></textarea>
          </div>

          <div>
            <label class="block" for="p_logo">Логотип (основной)</label>
            <input id="p_logo" type="file" name="logo" accept="image/*">
            <div id="logoPreview" style="margin-top:8px;"></div>
          </div>

          <div>
            <label class="block">Фотографии (до 10 штук)</label>
            <div id="dropzone" class="dropzone">Перетащите фото сюда или нажмите, чтобы выбрать</div>
            <input id="p_photos" type="file" name="photos[]" accept="image/*" multiple style="display:none">
            <div id="photosPreview" class="preview" aria-live="polite"></div>
            <div class="hint">Максимум 10 фото. Форматы: jpg, png, webp. Рекомендуемый размер ≤ 3MB на файл.</div>
            <div class="error-text" id="err_photos">Ошибка с фотографиями</div>
          </div>

          <!-- Contact phone (readonly) -->
          <div>
            <!-- hidden input will be sent with form -->
            <input type="hidden" name="contact_phone" value="<?= h($user_phone) ?>">
            <label class="block">Контактный телефон</label>
            <div class="small" style="margin-bottom:6px;"><?= h($user_phone ?: 'Не указан') ?></div>
            <div class="hint">Номер берётся из вашего профиля и не может быть изменён в форме.</div>
          </div>

        </div>

        <div class="actions">
          <a href="/mehanik/public/my-products.php" class="btn-ghost">← К списку</a>
          <button id="submitBtn" type="submit" class="btn">Сохранить</button>
        </div>

        <div class="progress-wrap" id="progressWrap">
          <div class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"><i id="progressBarFill"></i></div>
          <div class="progress-text" id="progressText">0%</div>
        </div>

        <div id="serverError" class="error-block"></div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  // Elements
  const brandSel = document.getElementById('ap_brand');
  const modelSel = document.getElementById('ap_model');
  const cpartSel = document.getElementById('ap_cpart');
  const compSel = document.getElementById('ap_comp');
  const deliverySel = document.getElementById('p_delivery');
  const deliveryPriceInput = document.getElementById('p_delivery_price');
  const errDeliveryPrice = document.getElementById('err_delivery_price');

  async function loadModels(brandId) {
    modelSel.innerHTML = '<option value="">Загрузка...</option>';
    if (!brandId) { modelSel.innerHTML = '<option value="">-- выберите модель --</option>'; return; }
    try {
      const res = await fetch(`/mehanik/api/get-models.php?brand_id=${encodeURIComponent(brandId)}`);
      if (!res.ok) throw new Error('network');
      const data = await res.json();
      modelSel.innerHTML = '<option value="">-- выберите модель --</option>';
      (Array.isArray(data) ? data : []).forEach(m => {
        const o = document.createElement('option'); o.value = m.id; o.textContent = m.name;
        modelSel.appendChild(o);
      });
    } catch (e) {
      console.error('Ошибка загрузки моделей', e);
      modelSel.innerHTML = '<option value="">-- выберите модель --</option>';
    }
  }

  async function loadComponents(cpartId) {
    compSel.innerHTML = '<option value="">Загрузка...</option>';
    if (!cpartId) { compSel.innerHTML = '<option value="">-- выберите компонент --</option>'; return; }
    try {
      const res = await fetch(`/mehanik/api/get-components.php?complex_part_id=${encodeURIComponent(cpartId)}`);
      if (!res.ok) throw new Error('network');
      const data = await res.json();
      compSel.innerHTML = '<option value="">-- выберите компонент --</option>';
      (Array.isArray(data) ? data : []).forEach(c => {
        const o = document.createElement('option'); o.value = c.id; o.textContent = c.name;
        compSel.appendChild(o);
      });
    } catch (e) {
      console.error('Ошибка загрузки компонентов', e);
      compSel.innerHTML = '<option value="">-- выберите компонент --</option>';
    }
  }

  if (brandSel) {
    brandSel.addEventListener('change', () => loadModels(brandSel.value));
    if (brandSel.value) loadModels(brandSel.value);
  }
  if (cpartSel) {
    cpartSel.addEventListener('change', () => loadComponents(cpartSel.value));
    if (cpartSel.value) loadComponents(cpartSel.value);
  }

  // Toggle delivery price visibility
  function onDeliveryChange() {
    if (!deliverySel) return;
    if (deliverySel.value === '1') {
      deliveryPriceInput.style.display = '';
    } else {
      deliveryPriceInput.style.display = 'none';
      deliveryPriceInput.value = '';
      if (errDeliveryPrice) errDeliveryPrice.style.display = 'none';
    }
  }
  deliverySel && deliverySel.addEventListener('change', onDeliveryChange);
  onDeliveryChange();

  // Photos handling
  const dropzone = document.getElementById('dropzone');
  const photosInput = document.getElementById('p_photos');
  const photosPreview = document.getElementById('photosPreview');
  const MAX_PHOTOS = 10;
  const ALLOWED = ['image/jpeg','image/png','image/webp'];
  const MAX_SIZE = 3 * 1024 * 1024; // 3MB

  let photosFiles = []; // array of File
  let mainIndex = null; // index in photosFiles for main photo

  function renderPreviews() {
    photosPreview.innerHTML = '';
    photosFiles.forEach((file, idx) => {
      const wrap = document.createElement('div');
      wrap.className = 'preview-item';
      const img = document.createElement('img');
      wrap.appendChild(img);
      const reader = new FileReader();
      reader.onload = (e) => img.src = e.target.result;
      reader.readAsDataURL(file);

      const actions = document.createElement('div');
      actions.className = 'actions';
      const btnMain = document.createElement('button'); btnMain.type = 'button'; btnMain.title = 'Сделать главным'; btnMain.innerText = '★';
      const btnDel = document.createElement('button'); btnDel.type = 'button'; btnDel.title = 'Удалить'; btnDel.innerText = '✕';
      actions.appendChild(btnMain); actions.appendChild(btnDel);
      wrap.appendChild(actions);

      if (idx === mainIndex) {
        const badge = document.createElement('div');
        badge.className = 'main-badge';
        badge.textContent = 'Главное';
        wrap.appendChild(badge);
      }

      btnMain.addEventListener('click', () => {
        mainIndex = idx;
        renderPreviews();
      });
      btnDel.addEventListener('click', () => {
        photosFiles.splice(idx,1);
        if (mainIndex !== null) {
          if (idx === mainIndex) mainIndex = null;
          else if (idx < mainIndex) mainIndex--;
        }
        renderPreviews();
      });

      photosPreview.appendChild(wrap);
    });
  }

  function addFiles(filesList) {
    const incoming = Array.from(filesList || []);
    if (photosFiles.length + incoming.length > MAX_PHOTOS) {
      alert('Можно загрузить не более ' + MAX_PHOTOS + ' фото.');
      return;
    }
    for (let f of incoming) {
      if (!ALLOWED.includes(f.type)) {
        alert('Неподдерживаемый формат: ' + f.name);
        continue;
      }
      if (f.size > MAX_SIZE) {
        alert('Файл слишком большой: ' + f.name);
        continue;
      }
      photosFiles.push(f);
    }
    if (mainIndex === null && photosFiles.length > 0) mainIndex = 0;
    renderPreviews();
  }

  // dropzone interactions
  dropzone.addEventListener('click', () => photosInput.click());
  dropzone.addEventListener('dragover', (e) => { e.preventDefault(); dropzone.classList.add('dragover'); });
  dropzone.addEventListener('dragleave', (e) => { e.preventDefault(); dropzone.classList.remove('dragover'); });
  dropzone.addEventListener('drop', (e) => {
    e.preventDefault(); dropzone.classList.remove('dragover');
    addFiles(e.dataTransfer.files);
  });

  photosInput.addEventListener('change', (e) => {
    addFiles(e.target.files);
    photosInput.value = '';
  });

  // logo preview
  const logoInput = document.getElementById('p_logo');
  const logoPreview = document.getElementById('logoPreview');
  logoInput && logoInput.addEventListener('change', function(){
    logoPreview.innerHTML = '';
    const f = (this.files && this.files[0]) || null;
    if (!f) return;
    if (!ALLOWED.includes(f.type)) { alert('Неподдерживаемый формат логотипа'); this.value = ''; return; }
    if (f.size > MAX_SIZE) { alert('Логотип слишком большой'); this.value = ''; return; }
    const r = new FileReader();
    r.onload = e => {
      const img = document.createElement('img'); img.src = e.target.result;
      img.style.width = '120px'; img.style.height = '80px'; img.style.objectFit = 'cover'; img.style.borderRadius='8px';
      logoPreview.appendChild(img);
    };
    r.readAsDataURL(f);
  });

  // validation helpers
  function showError(id, show, text) {
    const el = document.getElementById(id);
    if (!el) return;
    if (show) { el.style.display = 'block'; if (text) el.textContent = text; }
    else el.style.display = 'none';
  }

  // progress elements
  const progressWrap = document.getElementById('progressWrap');
  const progressBarFill = document.getElementById('progressBarFill');
  const progressText = document.getElementById('progressText');
  const serverError = document.getElementById('serverError');

  // submit handling
  const form = document.getElementById('addProductForm');
  const submitBtn = document.getElementById('submitBtn');

  form.addEventListener('submit', function(e){
    e.preventDefault();
    serverError.style.display = 'none';

    // client-side validation
    const name = document.getElementById('p_name').value.trim();
    const price = parseFloat(document.getElementById('p_price').value);
    const brand = document.getElementById('ap_brand').value;
    const model = document.getElementById('ap_model').value;
    const rating = parseFloat(document.getElementById('p_rating').value);
    const delivery = document.getElementById('p_delivery').value;
    const deliveryPrice = parseFloat(document.getElementById('p_delivery_price').value);

    let ok = true;
    showError('err_name', false);
    showError('err_price', false);
    showError('err_brand', false);
    showError('err_model', false);
    showError('err_rating', false);
    showError('err_photos', false);
    showError('err_delivery_price', false);

    if (!name) { showError('err_name', true); ok = false; }
    if (!brand) { showError('err_brand', true); ok = false; }
    if (!model) { showError('err_model', true); ok = false; }
    if (isNaN(price) || price <= 0) { showError('err_price', true); ok = false; }
    if (isNaN(rating) || rating < 0.1 || rating > 9.9) { showError('err_rating', true); ok = false; }
    if (delivery === '1') {
      if (isNaN(deliveryPrice) || deliveryPrice < 0) { showError('err_delivery_price', true); ok = false; }
    }
    if (!ok) return;

    // Build FormData
    const fd = new FormData();
    Array.from(form.elements).forEach(el => {
      if (!el.name) return;
      // skip photos input (we append manually)
      if (el.name === 'photos[]' || (el.type === 'file' && el.name === 'photos[]')) return;
      if (el.name === 'delivery_price' && delivery !== '1') return;
      if (el.type === 'file') {
        // append logo if present
        if (el.name === 'logo' && el.files && el.files.length) fd.append(el.name, el.files[0]);
        return;
      }
      if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
      if (el.tagName.toLowerCase() === 'select' || el.type === 'text' || el.type === 'number' || el.tagName.toLowerCase() === 'textarea' || el.type === 'hidden') {
        fd.append(el.name, el.value);
      }
    });

    // append photos
    if (photosFiles.length > 0) {
      photosFiles.forEach((file, idx) => {
        if (idx === mainIndex) fd.append('photo', file, file.name);
        else fd.append('photos[]', file, file.name);
      });
    }

    // show progress UI
    progressWrap.style.display = 'flex';
    progressBarFill.style.width = '0%';
    progressText.textContent = '0%';
    submitBtn.disabled = true;

    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.action, true);
    xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');

    xhr.upload.addEventListener('progress', (e) => {
      if (!e.lengthComputable) return;
      const perc = Math.round((e.loaded / e.total) * 100);
      progressBarFill.style.width = perc + '%';
      progressText.textContent = perc + '%';
    });

    xhr.onreadystatechange = function() {
      if (xhr.readyState !== 4) return;
      submitBtn.disabled = false;
      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          const json = JSON.parse(xhr.responseText);
          if (json && json.ok && json.id) {
            window.location.href = `/mehanik/public/product.php?id=${encodeURIComponent(json.id)}`;
            return;
          } else {
            const msg = (json && json.error) ? json.error : 'Неизвестная ошибка при сохранении';
            serverError.textContent = msg;
            serverError.style.display = 'block';
            progressWrap.style.display = 'none';
          }
        } catch (err) {
          serverError.textContent = 'Неверный ответ сервера';
          serverError.style.display = 'block';
          progressWrap.style.display = 'none';
        }
      } else {
        let errorText = 'Ошибка сети при сохранении';
        try { const j = JSON.parse(xhr.responseText); if (j && j.error) errorText = j.error; } catch (_) {}
        serverError.textContent = errorText;
        serverError.style.display = 'block';
        progressWrap.style.display = 'none';
      }
    };

    xhr.onerror = function() {
      submitBtn.disabled = false;
      serverError.textContent = 'Ошибка сети при сохранении';
      serverError.style.display = 'block';
      progressWrap.style.display = 'none';
    };

    xhr.send(fd);
  });
})();
</script>

</body>
</html>
