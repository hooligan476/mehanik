<?php
// public/add-car.php
// Добавить авто — форма + серверная обработка (с брендами/моделями, тип ТС, VIN, скрытый номер, выбор главного фото)
require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php';

$basePublic = '/mehanik/public';
$currentYear = (int)date('Y');
$minYear = $currentYear - 25;
$user_id = $_SESSION['user']['id'] ?? 0;
$user_phone = $_SESSION['user']['phone'] ?? '';

// load brands from DB (id,name)
$brands = [];
if (isset($mysqli) && $mysqli instanceof mysqli) {
    try {
        $r = $mysqli->query("SELECT id, name FROM brands ORDER BY name");
        while ($row = $r->fetch_assoc()) $brands[] = $row;
    } catch (Throwable $e) { /* ignore */ }
} elseif (isset($pdo) && $pdo instanceof PDO) {
    try {
        $st = $pdo->query("SELECT id, name FROM brands ORDER BY name");
        $brands = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* ignore */ }
}

// available vehicle types (displayed). bodies are loaded by API or fallback.
$vehicle_types = [
    'passenger' => 'Легковые',
    'cargo' => 'Грузовые',
    'agro' => 'Агро техника',
    'construction' => 'Строй техника',
    'motorcycle' => 'Мото/скутеры',
    'other' => 'Другое'
];

// POST handling
$errors = [];
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Accept both id-based selects (brand_id/model_id) and legacy text fields
    $brand_id = isset($_POST['brand_id']) && $_POST['brand_id'] !== '' ? (int)$_POST['brand_id'] : null;
    $model_id = isset($_POST['model_id']) && $_POST['model_id'] !== '' ? (int)$_POST['model_id'] : null;
    $brand_text = trim($_POST['brand'] ?? '');
    $model_text = trim($_POST['model'] ?? '');

    // Strings to save into cars.brand and cars.model (table may expect strings)
    $brand_save = null;
    $model_save = null;

    // If ID provided, try to lookup names
    if ($brand_id && isset($mysqli) && $mysqli instanceof mysqli) {
        $st = $mysqli->prepare("SELECT name FROM brands WHERE id = ? LIMIT 1");
        if ($st) { $st->bind_param('i', $brand_id); $st->execute(); $res = $st->get_result(); $r = $res->fetch_assoc(); if ($r) $brand_save = $r['name']; $st->close(); }
    } elseif ($brand_id && isset($pdo) && $pdo instanceof PDO) {
        try {
            $st = $pdo->prepare("SELECT name FROM brands WHERE id = :id LIMIT 1");
            $st->execute([':id'=>$brand_id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) $brand_save = $r['name'];
        } catch (Throwable $_) {}
    }
    if (!$brand_save) $brand_save = $brand_text;

    if ($model_id && isset($mysqli) && $mysqli instanceof mysqli) {
        $st = $mysqli->prepare("SELECT name FROM models WHERE id = ? LIMIT 1");
        if ($st) { $st->bind_param('i', $model_id); $st->execute(); $res = $st->get_result(); $r = $res->fetch_assoc(); if ($r) $model_save = $r['name']; $st->close(); }
    } elseif ($model_id && isset($pdo) && $pdo instanceof PDO) {
        try {
            $st = $pdo->prepare("SELECT name FROM models WHERE id = :id LIMIT 1");
            $st->execute([':id'=>$model_id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) $model_save = $r['name'];
        } catch (Throwable $_) {}
    }
    if (!$model_save) $model_save = $model_text;

    $year = (int)($_POST['year'] ?? 0);
    $vehicle_type = trim($_POST['vehicle_type'] ?? '');
    $body = trim($_POST['body'] ?? '');
    // body_type submitted by JS is name or id; keep as string
    $mileage = (int)($_POST['mileage'] ?? 0);
    $transmission = trim($_POST['transmission'] ?? '');
    $fuel = trim($_POST['fuel'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    // contact_phone is filled from session via hidden input (user can't change)
    $contact_phone = trim($_POST['contact_phone'] ?? $user_phone);

    $vin = trim($_POST['vin'] ?? '');

    // validation
    if ($brand_save === '') $errors[] = 'Бренд обязателен';
    if ($model_save === '') $errors[] = 'Модель обязателна';
    if ($year < $minYear || $year > $currentYear) $errors[] = "Год должен быть в диапазоне {$minYear}—{$currentYear}";
    if ($price < 0) $errors[] = 'Цена некорректна';

    // Handle uploads: accept 'photo' (main) and 'photos[]' (others), up to 6 total.
    $uploadDir = __DIR__ . '/../uploads/cars/';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
    $allowed_exts = ['jpg','jpeg','png','webp'];
    $max_files = 6;
    $savedMain = null;
    $savedExtras = [];

    // If user sent single main file as 'photo'
    if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
        $orig = basename($_FILES['photo']['name'] ?? '');
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed_exts, true) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $name = time() . '_' . bin2hex(random_bytes(6)) . '_main.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $name)) {
                $savedMain = 'uploads/cars/' . $name;
            }
        }
    }

    // Then process photos[] (others)
    if (!empty($_FILES['photos']) && is_array($_FILES['photos']['tmp_name'])) {
        $cnt = count($_FILES['photos']['tmp_name']);
        for ($i=0; $i<$cnt && count($savedExtras) < $max_files; $i++) {
            if (!is_uploaded_file($_FILES['photos']['tmp_name'][$i])) continue;
            $orig = basename($_FILES['photos']['name'][$i] ?? '');
            $err = $_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_exts, true)) continue;
            $name = time() . '_' . bin2hex(random_bytes(6)) . "_{$i}." . $ext;
            if (move_uploaded_file($_FILES['photos']['tmp_name'][$i], $uploadDir . $name)) {
                $savedExtras[] = 'uploads/cars/' . $name;
            }
        }
    }

    // If no main was provided but we have extras, pick first as main
    if ($savedMain === null && count($savedExtras) > 0) {
        $savedMain = array_shift($savedExtras);
    }

    // If VIN provided but DB has no vin column, append to description
    $useVinColumn = false;
    try {
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            $res = $mysqli->query("SHOW COLUMNS FROM cars LIKE 'vin'");
            if ($res && $res->num_rows > 0) $useVinColumn = true;
        } elseif (isset($pdo) && $pdo instanceof PDO) {
            $st = $pdo->query("SHOW COLUMNS FROM cars LIKE 'vin'");
            if ($st && $st->fetchColumn() !== false) $useVinColumn = true;
        }
    } catch (Throwable $_) { $useVinColumn = false; }

    if (!$useVinColumn && $vin !== '') {
        // prepend VIN to description so it's not lost
        $description = "VIN: " . $vin . "\n\n" . $description;
    }

    // Similarly check for vehicle_type column
    $useVehicleTypeColumn = false;
    try {
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            $res = $mysqli->query("SHOW COLUMNS FROM cars LIKE 'vehicle_type'");
            if ($res && $res->num_rows > 0) $useVehicleTypeColumn = true;
        } elseif (isset($pdo) && $pdo instanceof PDO) {
            $st = $pdo->query("SHOW COLUMNS FROM cars LIKE 'vehicle_type'");
            if ($st && $st->fetchColumn() !== false) $useVehicleTypeColumn = true;
        }
    } catch (Throwable $_) { $useVehicleTypeColumn = false; }

    if (empty($errors)) {
        try {
            // Build INSERT dynamically to include optional columns if they exist
            $cols = [
                'user_id','brand','model','year','body','mileage','transmission','fuel','price','photo','description','contact_phone'
            ];
            $placeholders = array_fill(0, count($cols), '?');
            $types = 'isssisisdsss'; // tentative: i (user_id), s, s, i, s, i, s, s, d, s, s, s
            $values = [
                $user_id,
                $brand_save,
                $model_save,
                $year,
                $body,
                $mileage,
                $transmission,
                $fuel,
                $price,
                $savedMain,
                $description,
                $contact_phone
            ];

            if ($useVinColumn) {
                $cols[] = 'vin';
                $placeholders[] = '?';
                $types .= 's';
                $values[] = $vin;
            }
            if ($useVehicleTypeColumn) {
                $cols[] = 'vehicle_type';
                $placeholders[] = '?';
                $types .= 's';
                $values[] = $vehicle_type;
            }

            // always set status and created_at
            $cols[] = 'status';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = 'pending';

            $cols[] = 'created_at';
            $placeholders[] = 'NOW()'; // literal NOW(), not bound
            // remove last '?' placeholder because we put NOW() literal
            // actually placeholders array must be strings; handle NOW() specially
            // rebuild placeholders properly:
            $phs = array_fill(0, count($cols), '?');
            // we will replace last placeholder with NOW()
            $phs[count($phs)-1] = 'NOW()';

            // Prepare SQL
            $sql = "INSERT INTO cars (" . implode(',', $cols) . ") VALUES (" . implode(',', $phs) . ")";
            // For binding we need to remove the NOW() parameter from types/values if present
            // types currently corresponds to all except created_at (we didn't append type for it)
            // So types and values are correct.

            if (isset($mysqli) && $mysqli instanceof mysqli) {
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) throw new Exception('Prepare failed: ' . $mysqli->error);

                // bind dynamically
                $bind_names[] = $types;
                for ($i = 0; $i < count($values); $i++) {
                    $bind_name = 'bind' . $i;
                    $$bind_name = $values[$i];
                    $bind_names[] = &$$bind_name;
                }
                // call_user_func_array expects array of references
                if (!call_user_func_array([$stmt, 'bind_param'], $bind_names)) {
                    throw new Exception('Bind failed: ' . $stmt->error);
                }
                $ok = $stmt->execute();
                if (!$ok) throw new Exception('Execute failed: ' . $stmt->error);
                $stmt->close();
                $success = 'Автомобиль успешно добавлен и отправлен на модерацию.';
                header('Location: ' . $basePublic . '/my-cars.php?msg=' . urlencode($success));
                exit;
            } elseif (isset($pdo) && $pdo instanceof PDO) {
                // PDO branch - build named placeholders but we used NOW() literal already
                $named = [];
                $params = [];
                foreach ($cols as $k => $col) {
                    if ($col === 'created_at') continue;
                    $ph = ":p{$k}";
                    $named[] = $ph;
                    $params[$ph] = $values[$k];
                }
                // build SQL with NOW()
                $insertCols = array_filter($cols, function($c){ return $c !== 'created_at'; });
                $sql2 = "INSERT INTO cars (" . implode(',', $insertCols) . ", created_at) VALUES (" . implode(',', array_keys($params)) . ", NOW())";
                $st = $pdo->prepare($sql2);
                if (!$st->execute(array_values($params))) throw new Exception('Execute failed (PDO)');
                $success = 'Автомобиль успешно добавлен и отправлен на модерацию.';
                header('Location: ' . $basePublic . '/my-cars.php?msg=' . urlencode($success));
                exit;
            } else {
                $errors[] = 'Нет подключения к базе данных.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Ошибка при сохранении: ' . $e->getMessage();
        }
    }
}

?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Добавить авто — Mehanik</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/mehanik/assets/css/header.css">
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    /* Локальные стили формы (стройно и похоже на остальной UI) */
    .page { max-width:1100px; margin:18px auto; padding:14px; box-sizing:border-box; }
    .card { background:#fff; border-radius:10px; box-shadow:0 8px 24px rgba(2,6,23,0.06); overflow:hidden; }
    .card-body { padding:18px; }
    .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    @media (max-width:760px){ .form-grid { grid-template-columns:1fr } }
    label.block{ display:block; font-weight:700; margin-bottom:6px; }
    input[type=text], input[type=number], select, textarea { width:100%; padding:10px 12px; border:1px solid #e6e9ef; border-radius:8px; box-sizing:border-box; }
    textarea { min-height:120px; }
    .muted { color:#6b7280; font-size:0.95rem; }
    .actions { display:flex; gap:10px; justify-content:flex-end; margin-top:12px; }
    .btn { background:#0b57a4; color:#fff; padding:10px 14px; border-radius:8px; border:0; cursor:pointer; font-weight:700; }
    .btn-ghost { background:transparent; border:1px solid #dbeafe; color:#0b57a4; }
    .error { background:#fff6f6; border:1px solid #f5c2c2; color:#8a1f1f; padding:10px; border-radius:8px; margin-bottom:12px; }
    .ok { background:#eafaf0; border:1px solid #cfead1; color:#116530; padding:10px; border-radius:8px; margin-bottom:12px; }
    .photo-preview { display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
    .preview-item { position:relative; width:120px; height:80px; border-radius:8px; overflow:hidden; border:1px solid #e6eef7; display:flex; align-items:center; justify-content:center; background:#fafafa; }
    .preview-item img { width:100%; height:100%; object-fit:cover; display:block; }
    .preview-item .actions { position:absolute; left:6px; top:6px; display:flex; flex-direction:column; gap:6px; }
    .preview-item button { font-size:11px; padding:5px 7px; border-radius:6px; border:0; cursor:pointer; background:rgba(0,0,0,0.6); color:#fff; }
    .preview-item .main-badge { position:absolute; right:6px; top:6px; background:#0b57a4;color:#fff;padding:4px 6px;border-radius:6px;font-size:11px; }
    .small { font-size:.9rem; color:#6b7280; }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<div class="page">
  <div class="card">
    <div class="card-body">
      <h2 style="margin:0 0 10px;">Добавить автомобиль на продажу</h2>
      <p class="muted" style="margin:0 0 12px;">Поля с * обязательны. Бренд и модель подтянутся из каталога (если есть).</p>

      <?php if (!empty($errors)): ?>
        <div class="error"><?= htmlspecialchars(implode(' · ', $errors)) ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="ok"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>

      <form id="addCarForm" method="post" enctype="multipart/form-data" novalidate>
        <div class="form-grid">
          <div>
            <label class="block">Тип ТС *</label>
            <select id="vehicle_type" name="vehicle_type" required>
              <option value="">— выберите тип —</option>
              <?php foreach ($vehicle_types as $k => $v): ?>
                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block">Кузов *</label>
            <select id="body_type" name="body" required>
              <option value="">— выберите кузов —</option>
            </select>
          </div>

          <div>
            <label class="block">Бренд *</label>
            <select id="brand" name="brand_id" required>
              <option value="">— выберите бренд —</option>
              <?php foreach ($brands as $b): ?>
                <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block">Модель *</label>
            <select id="model" name="model_id" required><option value="">— выберите модель —</option></select>
          </div>

          <div>
            <label class="block">Год выпуска *</label>
            <select id="year" name="year" required>
              <option value="">— год —</option>
              <?php for ($y = $currentYear; $y >= $minYear; $y--): ?>
                <option value="<?= $y ?>"><?= $y ?></option>
              <?php endfor; ?>
            </select>
            <div class="small">Допустимый диапазон: <?= $minYear ?> — <?= $currentYear ?></div>
          </div>

          <div>
            <label class="block">VIN</label>
            <input id="vin" type="text" name="vin" placeholder="VIN (если есть)">
          </div>

          <div>
            <label class="block">Пробег (км)</label>
            <input type="number" name="mileage" min="0" placeholder="например 120000">
          </div>

          <div>
            <label class="block">Коробка передач</label>
            <select name="transmission">
              <option value="Механика">Механика</option>
              <option value="Автомат">Автомат</option>
              <option value="Вариатор">Вариатор</option>
              <option value="Робот">Робот</option>
            </select>
          </div>

          <div>
            <label class="block">Тип топлива</label>
            <select name="fuel">
              <option value="Бензин">Бензин</option>
              <option value="Дизель">Дизель</option>
              <option value="Гибрид">Гибрид</option>
              <option value="Электро">Электро</option>
            </select>
          </div>

          <div>
            <label class="block">Цена (TMT)</label>
            <input type="number" name="price" step="0.01" min="0" placeholder="например 350000">
          </div>

          <div style="grid-column:1 / -1">
            <label class="block">Описание</label>
            <textarea name="description" placeholder="Дополнительная информация, комплектация..."></textarea>
          </div>

          <div>
            <label class="block">Фотографии (макс.6)</label>
            <div id="dropzone" class="dropzone" style="padding:10px;border:1px dashed #e6e9ef;border-radius:8px;cursor:pointer;text-align:center;">Перетащите фото сюда или нажмите для выбора</div>
            <input id="p_photos" type="file" name="photos[]" accept="image/*" multiple style="display:none">
            <div class="small">Рекомендуется не больше 6 фото. Выберите главное фото звёздочкой (★).</div>
            <div id="previews" class="photo-preview" aria-hidden="true"></div>
          </div>

          <div>
            <!-- hidden contact phone to prevent tampering -->
            <input type="hidden" name="contact_phone" value="<?= htmlspecialchars($user_phone) ?>">
            <label class="block">Контактный телефон</label>
            <div class="small"><?= htmlspecialchars($user_phone ?: 'Не указан') ?></div>
            <div class="small">Номер берётся из вашего профиля и не может быть изменён в форме.</div>
          </div>

        </div>

        <div class="actions">
          <a href="<?= $basePublic ?>/my-cars.php" class="btn btn-ghost" style="background:transparent;border:1px solid #e6eef7;color:#0b57a4;padding:8px 12px;">Отмена</a>
          <button type="submit" class="btn">Опубликовать</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  const brandEl = document.getElementById('brand');
  const modelEl = document.getElementById('model');
  const vehicleTypeEl = document.getElementById('vehicle_type');
  const bodyEl = document.getElementById('body_type');

  async function loadModels(brandId) {
    modelEl.innerHTML = '<option value="">Загрузка...</option>';
    if (!brandId) { modelEl.innerHTML = '<option value="">— выберите модель —</option>'; return; }
    try {
      const res = await fetch(`/mehanik/api/get-models.php?brand_id=${encodeURIComponent(brandId)}`);
      if (!res.ok) throw new Error('network');
      const data = await res.json();
      modelEl.innerHTML = '<option value="">— выберите модель —</option>';
      (Array.isArray(data) ? data : []).forEach(m => {
        const o = document.createElement('option'); o.value = m.id; o.textContent = m.name;
        modelEl.appendChild(o);
      });
    } catch (e) {
      console.error('Ошибка загрузки моделей', e);
      modelEl.innerHTML = '<option value="">— выберите модель —</option>';
    }
  }

  async function loadBodies(vtype) {
    bodyEl.innerHTML = '<option value="">Загрузка...</option>';
    if (!vtype) { bodyEl.innerHTML = '<option value="">— выберите кузов —</option>'; return; }
    try {
      const res = await fetch(`/mehanik/api/get-bodies.php?vehicle_type=${encodeURIComponent(vtype)}`);
      if (!res.ok) throw new Error('network');
      const data = await res.json();
      bodyEl.innerHTML = '<option value="">— выберите кузов —</option>';
      (Array.isArray(data) ? data : []).forEach(b => {
        const o = document.createElement('option'); o.value = b.id ?? b.key ?? b.value ?? b.name; o.textContent = b.name ?? b.label ?? b.value;
        bodyEl.appendChild(o);
      });
    } catch (e) {
      // fallback mapping
      console.warn('get-bodies failed, using fallback', e);
      const fallback = {
        passenger: [{id:'sedan',name:'Седан'},{id:'hatch',name:'Хэтчбек'},{id:'wagon',name:'Универсал'},{id:'suv',name:'SUV'}],
        cargo: [{id:'box',name:'Фургон'},{id:'flat',name:'Платформа'},{id:'tanker',name:'Цистерна'}],
        agro: [{id:'tractor',name:'Трактор'},{id:'combine',name:'Комбайн'}],
        construction: [{id:'bulldozer',name:'Бульдозер'},{id:'excavator',name:'Экскаватор'}],
        motorcycle: [{id:'bike',name:'Мотоцикл'},{id:'scooter',name:'Скутер'}],
        other: [{id:'other',name:'Другое'}]
      };
      const items = fallback[vtype] || [];
      bodyEl.innerHTML = '<option value="">— выберите кузов —</option>';
      items.forEach(b => { const o = document.createElement('option'); o.value = b.id; o.textContent = b.name; bodyEl.appendChild(o); });
    }
  }

  brandEl && brandEl.addEventListener('change', () => loadModels(brandEl.value));
  vehicleTypeEl && vehicleTypeEl.addEventListener('change', () => loadBodies(vehicleTypeEl.value));

  // photos with main selection
  const dropzone = document.getElementById('dropzone');
  const photosInput = document.getElementById('p_photos');
  const previews = document.getElementById('previews');
  const MAX = 6;
  const ALLOWED = ['image/jpeg','image/png','image/webp'];
  let files = [];
  let mainIndex = null;

  function render() {
    previews.innerHTML = '';
    files.forEach((f, idx) => {
      const w = document.createElement('div'); w.className = 'preview-item';
      const img = document.createElement('img'); w.appendChild(img);
      const fr = new FileReader();
      fr.onload = e => img.src = e.target.result;
      fr.readAsDataURL(f);

      const actions = document.createElement('div'); actions.className = 'actions';
      const btnMain = document.createElement('button'); btnMain.type='button'; btnMain.textContent='★'; btnMain.title='Сделать главным';
      const btnDel = document.createElement('button'); btnDel.type='button'; btnDel.textContent='✕'; btnDel.title='Удалить';
      actions.appendChild(btnMain); actions.appendChild(btnDel);
      w.appendChild(actions);

      if (idx === mainIndex) {
        const badge = document.createElement('div'); badge.className = 'main-badge'; badge.textContent = 'Главное'; w.appendChild(badge);
      }

      btnMain.addEventListener('click', () => { mainIndex = idx; render(); });
      btnDel.addEventListener('click', () => {
        files.splice(idx,1);
        if (mainIndex !== null) {
          if (idx === mainIndex) mainIndex = null;
          else if (idx < mainIndex) mainIndex--;
        }
        render();
      });

      previews.appendChild(w);
    });
  }

  function addIncoming(list) {
    const inc = Array.from(list || []);
    if (files.length + inc.length > MAX) { alert('Максимум ' + MAX + ' фото'); return; }
    for (let f of inc) {
      if (!ALLOWED.includes(f.type)) { alert('Неподдерживаемый формат: ' + f.name); continue; }
      files.push(f);
    }
    if (mainIndex === null && files.length) mainIndex = 0;
    render();
  }

  dropzone.addEventListener('click', () => photosInput.click());
  dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.classList.add('dragover'); });
  dropzone.addEventListener('dragleave', e => { e.preventDefault(); dropzone.classList.remove('dragover'); });
  dropzone.addEventListener('drop', e => { e.preventDefault(); dropzone.classList.remove('dragover'); addIncoming(e.dataTransfer.files); });

  photosInput.addEventListener('change', (e) => { addIncoming(e.target.files); photosInput.value=''; });

  // on submit, build FormData: append rest of inputs, and append main as 'photo' and others as 'photos[]'
  const form = document.getElementById('addCarForm');
  form.addEventListener('submit', function(e){
    // front validation: brand/model/year/price
    const brand = document.getElementById('brand').value;
    const model = document.getElementById('model').value;
    const year = parseInt(document.getElementById('year').value || '0', 10);
    const minY = <?= json_encode($minYear) ?>;
    const maxY = <?= json_encode($currentYear) ?>;
    if (!brand || !model) { e.preventDefault(); alert('Пожалуйста выберите бренд и модель'); return false; }
    if (!year || year < minY || year > maxY) { e.preventDefault(); alert('Выберите корректный год'); return false; }

    // build FormData and send via XHR so we can attach files properly (main + extras)
    e.preventDefault();

    const fd = new FormData();
    Array.from(form.elements).forEach(el => {
      if (!el.name) return;
      if (el.type === 'file') return; // files handled below
      if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
      fd.append(el.name, el.value);
    });

    // append files: main -> photo, others -> photos[]
    files.forEach((f, idx) => {
      if (idx === mainIndex) fd.append('photo', f, f.name);
      else fd.append('photos[]', f, f.name);
    });

    // send via XHR
    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.action, true);
    xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');

    xhr.onreadystatechange = function() {
      if (xhr.readyState !== 4) return;
      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          const j = JSON.parse(xhr.responseText || '{}');
          if (j && j.ok && j.id) {
            window.location.href = '/mehanik/public/product.php?id=' + encodeURIComponent(j.id);
            return;
          } else if (j && j.error) {
            alert('Ошибка: ' + j.error);
            return;
          }
        } catch (err) {
          // fallback: reload page (server may have redirected)
          location.reload();
        }
      } else {
        alert('Ошибка сервера при сохранении');
      }
    };
    xhr.send(fd);
  });
})();
</script>

</body>
</html>
