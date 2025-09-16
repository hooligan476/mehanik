<?php
// public/add-car.php
require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php';

$basePublic = '/mehanik/public';
$currentYear = (int)date('Y');
$minYear = $currentYear - 25;
$user_id = $_SESSION['user']['id'] ?? 0;
$user_phone = $_SESSION['user']['phone'] ?? '';

// detect AJAX (like in other handlers)
$isAjax = (
  (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
  || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
);

// load brands (id,name)
$brands = [];
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $r = $mysqli->query("SELECT id, name FROM brands ORDER BY name");
        while ($row = $r->fetch_assoc()) $brands[] = $row;
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $st = $pdo->query("SELECT id, name FROM brands ORDER BY name");
        $brands = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $_) {
    $brands = [];
}

// Try to load vehicle types/bodies from DB, otherwise fallback
$types_from_db = [];
$bodies_by_type_id = [];
$use_db_types = false;
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $res = $mysqli->query("SHOW TABLES LIKE 'vehicle_types'");
        if ($res && $res->num_rows > 0) {
            $use_db_types = true;
            $r = $mysqli->query("SELECT id, `key`, name, `order` FROM vehicle_types ORDER BY `order` ASC, name ASC");
            while ($row = $r->fetch_assoc()) $types_from_db[(int)$row['id']] = $row;
            $r2 = $mysqli->query("SELECT * FROM vehicle_bodies ORDER BY vehicle_type_id ASC, `order` ASC, name ASC");
            while ($b = $r2->fetch_assoc()) $bodies_by_type_id[(int)$b['vehicle_type_id']][] = $b;
        }
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $st = $pdo->query("SHOW TABLES LIKE 'vehicle_types'");
        $has = $st->fetch(PDO::FETCH_NUM);
        if ($has) {
            $use_db_types = true;
            $st2 = $pdo->query("SELECT id, `key`, name, `order` FROM vehicle_types ORDER BY `order` ASC, name ASC");
            $rows = $st2->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) $types_from_db[(int)$r['id']] = $r;
            $st3 = $pdo->query("SELECT * FROM vehicle_bodies ORDER BY vehicle_type_id ASC, `order` ASC, name ASC");
            $rows2 = $st3->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows2 as $b) $bodies_by_type_id[(int)$b['vehicle_type_id']][] = $b;
        }
    }
} catch (Throwable $_) {
    $use_db_types = false;
}

// Fallback (if DB missing)
$vehicle_types_fallback = [
    'passenger' => 'Легковые',
    'cargo' => 'Грузовые',
    'agro' => 'Агро техника',
    'construction' => 'Строй техника',
    'motorcycle' => 'Мото/скутеры',
    'other' => 'Другое'
];
$vehicle_bodies_fallback = [
    'passenger' => [
        ['id'=>'sedan','name'=>'Седан'],
        ['id'=>'hatch','name'=>'Хэтчбек'],
        ['id'=>'wagon','name'=>'Универсал'],
        ['id'=>'suv','name'=>'SUV / Внедорожник'],
        ['id'=>'coupe','name'=>'Купе'],
        ['id'=>'minivan','name'=>'Минивэн'],
        ['id'=>'pickup','name'=>'Пикап'],
    ],
    'cargo' => [
        ['id'=>'box','name'=>'Фургон'],
        ['id'=>'flat','name'=>'Платформа'],
        ['id'=>'tanker','name'=>'Цистерна'],
    ],
    'agro' => [
        ['id'=>'tractor','name'=>'Трактор'],
        ['id'=>'combine','name'=>'Комбайн'],
    ],
    'construction' => [
        ['id'=>'bulldozer','name'=>'Бульдозер'],
        ['id'=>'excavator','name'=>'Экскаватор'],
    ],
    'motorcycle' => [
        ['id'=>'bike','name'=>'Мотоцикл'],
        ['id'=>'scooter','name'=>'Скутер'],
    ],
    'other' => [
        ['id'=>'other','name'=>'Другое'],
    ],
];

// Build select data for server-render and JS
$vehicle_types_select = [];
$vehicle_bodies_js = [];

if ($use_db_types && count($types_from_db) > 0) {
    foreach ($types_from_db as $tid => $t) {
        $vehicle_types_select[$tid] = $t['name'];
        $vehicle_bodies_js[$tid] = [];
        foreach ($bodies_by_type_id[$tid] ?? [] as $b) {
            $vehicle_bodies_js[$tid][] = ['id' => (int)$b['id'], 'name' => $b['name'], 'key' => $b['key'] ?? null];
        }
    }
} else {
    foreach ($vehicle_types_fallback as $k => $v) {
        $vehicle_types_select[$k] = $v;
        $vehicle_bodies_js[$k] = $vehicle_bodies_fallback[$k] ?? [];
    }
}

// Helper for sending JSON responses
function jsonOk($data=[]) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok'=>true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}
function jsonError($msg='Ошибка') {
    header('Content-Type: application/json; charset=utf-8', true, 500);
    echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// POST handling (saving)
$errors = [];
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // accept id selects or legacy text
    $brand_id = isset($_POST['brand_id']) && $_POST['brand_id'] !== '' ? (int)$_POST['brand_id'] : null;
    $model_id = isset($_POST['model_id']) && $_POST['model_id'] !== '' ? (int)$_POST['model_id'] : null;
    $brand_text = trim($_POST['brand'] ?? '');
    $model_text = trim($_POST['model'] ?? '');

    // resolve brand/model names (prefer loaded $brands to avoid extra query)
    $brand_save = '';
    if ($brand_id) {
        foreach ($brands as $b) {
            if ((int)$b['id'] === (int)$brand_id) { $brand_save = $b['name']; break; }
        }
    }
    if (!$brand_save) $brand_save = $brand_text;

    $model_save = '';
    if ($model_id) {
        // try to resolve via DB
        try {
            if (isset($mysqli) && $mysqli instanceof mysqli) {
                $st = $mysqli->prepare("SELECT name FROM models WHERE id = ? LIMIT 1");
                if ($st) { $st->bind_param('i', $model_id); $st->execute(); $res = $st->get_result(); $r = $res->fetch_assoc(); if ($r) $model_save = $r['name']; $st->close(); }
            } elseif (isset($pdo) && $pdo instanceof PDO) {
                $st = $pdo->prepare("SELECT name FROM models WHERE id = :id LIMIT 1");
                $st->execute([':id'=>$model_id]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if ($r) $model_save = $r['name'];
            }
        } catch (Throwable $_) { /* ignore */ }
    }
    if (!$model_save) $model_save = $model_text;

    // other fields
    $year = (int)($_POST['year'] ?? 0);
    $vehicle_type_raw = trim($_POST['vehicle_type'] ?? '');
    $body_raw = trim($_POST['body'] ?? '');
    $mileage = (int)($_POST['mileage'] ?? 0);
    $transmission = trim($_POST['transmission'] ?? '');
    $fuel = trim($_POST['fuel'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? $user_phone);
    $vin = trim($_POST['vin'] ?? '');

    // Convert vehicle_type_raw and body_raw to values to store
    $vehicle_type_save = '';
    $body_save = '';
    if ($use_db_types) {
        $vt_id = is_numeric($vehicle_type_raw) ? (int)$vehicle_type_raw : null;
        if ($vt_id && isset($types_from_db[$vt_id])) {
            $trow = $types_from_db[$vt_id];
            $vehicle_type_save = ($trow['key'] && trim($trow['key']) !== '') ? $trow['key'] : $trow['name'];
            // body - may be numeric id from DB or string
            if (is_numeric($body_raw)) {
                $b_id = (int)$body_raw;
                $found = null;
                foreach ($bodies_by_type_id[$vt_id] ?? [] as $b) {
                    if ((int)$b['id'] === $b_id) { $found = $b; break; }
                }
                if ($found) $body_save = $found['name'];
            } else {
                $body_save = $body_raw;
            }
        } else {
            // fallback to raw
            $vehicle_type_save = $vehicle_type_raw;
            $body_save = $body_raw;
        }
    } else {
        $vehicle_type_save = $vehicle_type_raw;
        $body_save = $body_raw;
    }

    // validation
    if ($brand_save === '') $errors[] = 'Бренд обязателен';
    if ($model_save === '') $errors[] = 'Модель обязателна';
    if ($year < $minYear || $year > $currentYear) $errors[] = "Год должен быть в диапазоне {$minYear}—{$currentYear}";
    if ($price < 0) $errors[] = 'Цена некорректна';

    // process files (photo main as 'photo', extras as 'photos[]')
    $uploadDir = __DIR__ . '/../uploads/cars/';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
    $allowed_exts = ['jpg','jpeg','png','webp'];
    $max_files = 6;
    $savedMain = null;
    $savedExtras = [];

    // single main 'photo'
    if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
        $orig = basename($_FILES['photo']['name'] ?? '');
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed_exts, true) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_OK) {
            $name = time() . '_' . bin2hex(random_bytes(6)) . '_main.' . $ext;
            if (@move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $name)) {
                $savedMain = 'uploads/cars/' . $name;
            }
        }
    }

    // photos[] extras
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
            if (@move_uploaded_file($_FILES['photos']['tmp_name'][$i], $uploadDir . $name)) {
                $savedExtras[] = 'uploads/cars/' . $name;
            }
        }
    }

    if ($savedMain === null && count($savedExtras) > 0) {
        $savedMain = array_shift($savedExtras);
    }

    // VIN: if DB lacks column, prepend to description
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
        $description = "VIN: " . $vin . "\n\n" . $description;
    }

    // vehicle_type column check
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

    // If no errors - insert
    if (empty($errors)) {
        try {
            // columns and values arrays
            $cols = [
                'user_id','brand','model','year','body','mileage','transmission','fuel','price','photo','description','contact_phone'
            ];
            $values = [
                $user_id,
                $brand_save,
                $model_save,
                $year,
                $body_save,
                $mileage,
                $transmission,
                $fuel,
                $price,
                $savedMain,
                $description,
                $contact_phone
            ];
            $types = 'isssisisdsss'; // corresponds to values above

            if ($useVinColumn) {
                $cols[] = 'vin';
                $values[] = $vin;
                $types .= 's';
            }
            if ($useVehicleTypeColumn) {
                $cols[] = 'vehicle_type';
                $values[] = $vehicle_type_save;
                $types .= 's';
            }

            // status + created_at
            $cols[] = 'status';
            $values[] = 'pending';
            $types .= 's';

            $cols[] = 'created_at'; // literal NOW()

            // placeholders: last is NOW()
            $placeholders = array_fill(0, count($cols), '?');
            $placeholders[count($placeholders)-1] = 'NOW()';

            $sql = "INSERT INTO cars (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";

            if (isset($mysqli) && $mysqli instanceof mysqli) {
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) throw new Exception('Prepare failed: ' . $mysqli->error);

                // bind params dynamically (exclude last created_at)
                $bindParams = [];
                $bindParams[] = $types;
                for ($i=0; $i < count($values); $i++) {
                    $varname = 'p' . $i;
                    $$varname = $values[$i];
                    $bindParams[] = &$$varname;
                }
                if (!call_user_func_array([$stmt, 'bind_param'], $bindParams)) {
                    throw new Exception('Bind failed: ' . $stmt->error);
                }
                $ok = $stmt->execute();
                if (!$ok) throw new Exception('Execute failed: ' . $stmt->error);
                $newId = $stmt->insert_id;
                $stmt->close();

                if ($isAjax) jsonOk(['id'=>$newId]);
                header('Location: ' . $basePublic . '/my-cars.php?msg=' . urlencode('Автомобиль добавлен и отправлен на модерацию.'));
                exit;
            } elseif (isset($pdo) && $pdo instanceof PDO) {
                // build named placeholders excluding created_at
                $insertCols = array_slice($cols, 0, -1); // exclude created_at
                $named = [];
                $params = [];
                foreach ($insertCols as $k => $col) {
                    $ph = ':p' . $k;
                    $named[] = $ph;
                    $params[$ph] = $values[$k];
                }
                $sql2 = "INSERT INTO cars (" . implode(',', $insertCols) . ", created_at) VALUES (" . implode(',', array_keys($params)) . ", NOW())";
                $st = $pdo->prepare($sql2);
                if (!$st->execute(array_values($params))) throw new Exception('Execute failed (PDO)');
                $newId = (int)$pdo->lastInsertId();
                if ($isAjax) jsonOk(['id'=>$newId]);
                header('Location: ' . $basePublic . '/my-cars.php?msg=' . urlencode('Автомобиль добавлен и отправлен на модерацию.'));
                exit;
            } else {
                throw new Exception('Нет подключения к БД');
            }

        } catch (Throwable $e) {
            $errors[] = 'Ошибка при сохранении: ' . $e->getMessage();
            if ($isAjax) jsonError($errors[count($errors)-1]);
        }
    } else {
        // validation errors
        if ($isAjax) jsonError(implode('; ', $errors));
    }
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Добавить авто — Mehanik</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/mehanik/assets/css/header.css">
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    /* локальные стили */
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
    .dropzone { padding:10px; border:1px dashed #e6e9ef; border-radius:8px; cursor:pointer; text-align:center; color:#6b7280; background:#fff; }
    .dropzone.dragover { background:#f0f8ff; border-color:#b6e0ff; color:#044a75; }
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
        <div class="error"><?= h(implode(' · ', $errors)) ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="ok"><?= h($success) ?></div>
      <?php endif; ?>

      <form id="addCarForm" method="post" enctype="multipart/form-data" novalidate>
        <div class="form-grid">
          <div>
            <label class="block">Тип ТС *</label>
            <select id="vehicle_type" name="vehicle_type" required>
              <option value="">— выберите тип —</option>
              <?php foreach ($vehicle_types_select as $val => $label): ?>
                <option value="<?= h((string)$val) ?>"><?= h($label) ?></option>
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
                <option value="<?= (int)$b['id'] ?>"><?= h($b['name']) ?></option>
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
            <div id="dropzone" class="dropzone">Перетащите фото сюда или нажмите для выбора</div>
            <input id="p_photos" type="file" name="photos[]" accept="image/*" multiple style="display:none">
            <div class="small">Рекомендуется не больше 6 фото. Выберите главное фото звёздочкой (★).</div>
            <div id="previews" class="photo-preview" aria-hidden="true"></div>
          </div>

          <div>
            <!-- hidden contact phone to prevent tampering -->
            <input type="hidden" name="contact_phone" value="<?= h($user_phone) ?>">
            <label class="block">Контактный телефон</label>
            <div class="small"><?= h($user_phone ?: 'Не указан') ?></div>
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
  // Preloaded data from server: map vehicle_type_value => bodies[]
  window.VEHICLE_BODIES_BY_TYPE = <?= json_encode($vehicle_bodies_js, JSON_UNESCAPED_UNICODE) ?>;

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

  function populateBodiesFor(typeValue) {
    bodyEl.innerHTML = '<option value="">— выберите кузов —</option>';
    if (!typeValue) return;
    const items = window.VEHICLE_BODIES_BY_TYPE[typeValue] || [];
    if (items.length === 0) {
      // try API fallback
      fetch(`/mehanik/api/get-bodies.php?vehicle_type=${encodeURIComponent(typeValue)}`)
        .then(r => r.ok ? r.json() : Promise.reject('no') )
        .then(data => {
          (Array.isArray(data) ? data : []).forEach(b => {
            const o = document.createElement('option'); o.value = b.id ?? b.key ?? b.name; o.textContent = b.name ?? b.label ?? b.value;
            bodyEl.appendChild(o);
          });
        })
        .catch(()=>{ /* ignore */ });
      return;
    }
    items.forEach(b => {
      const o = document.createElement('option'); o.value = b.id ?? b.key ?? b.name; o.textContent = b.name;
      bodyEl.appendChild(o);
    });
  }

  if (brandEl) brandEl.addEventListener('change', () => loadModels(brandEl.value));
  if (vehicleTypeEl) vehicleTypeEl.addEventListener('change', () => populateBodiesFor(vehicleTypeEl.value));

  // photos + main selection
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
      const fr = new FileReader(); fr.onload = e => img.src = e.target.result; fr.readAsDataURL(f);

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

  // on submit -> build FormData and send (XHR) so photos included properly
  const form = document.getElementById('addCarForm');
  form.addEventListener('submit', function(e){
    // front validation
    const brand = document.getElementById('brand').value;
    const model = document.getElementById('model').value;
    const year = parseInt(document.getElementById('year').value || '0', 10);
    const minY = <?= json_encode($minYear) ?>;
    const maxY = <?= json_encode($currentYear) ?>;
    if (!brand || !model) { e.preventDefault(); alert('Пожалуйста выберите бренд и модель'); return false; }
    if (!year || year < minY || year > maxY) { e.preventDefault(); alert('Выберите корректный год'); return false; }

    e.preventDefault();
    const fd = new FormData();
    Array.from(form.elements).forEach(el => {
      if (!el.name) return;
      if (el.type === 'file') return;
      if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
      fd.append(el.name, el.value);
    });

    // append files: main -> photo, others -> photos[]
    files.forEach((f, idx) => {
      if (idx === mainIndex) fd.append('photo', f, f.name);
      else fd.append('photos[]', f, f.name);
    });

    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.action, true);
    xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');

    xhr.onreadystatechange = function() {
      if (xhr.readyState !== 4) return;
      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          const j = JSON.parse(xhr.responseText || '{}');
          if (j && j.ok) {
            // success — go to my-cars
            window.location.href = '<?= $basePublic ?>/my-cars.php';
            return;
          } else if (j && j.error) {
            alert('Ошибка: ' + j.error);
            return;
          }
        } catch (err) {
          // fallback - reload
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
