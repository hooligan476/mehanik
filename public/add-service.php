<?php
// public/add-service.php — улучшённая версия с управлением ценами на услуги
session_start();
require_once __DIR__ . '/../db.php';

// redirect not-logged users
if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$message = '';
$errors = [];

// handle POST: save service + photos + prices (if table exists)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');

    $lat = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? floatval($_POST['latitude']) : 0.0;
    $lng = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? floatval($_POST['longitude']) : 0.0;

    $uploadDir = __DIR__ . "/uploads/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    // логотип
    $logoName = '';
    if (!empty($_FILES['logo']['name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
        $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $logoName = uniqid('logo_') . "." . $ext;
        move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $logoName);
    }

    // простая валидация
    if ($name === '' || $description === '' || $phone === '') {
        $errors[] = "Заполните обязательные поля: Название, Описание, Контактное лицо.";
    }

    if (empty($errors)) {
        $sql = "
            INSERT INTO services 
            (name, description, phone, email, address, latitude, longitude, logo, status, created_at, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), ?)
        ";
        if ($stmt = $mysqli->prepare($sql)) {
            $types = "sssssddsi"; // name,desc,phone,email,address,lat,lng,logo,user_id
            $userId = (int)($_SESSION['user']['id'] ?? 0);
            $stmt->bind_param(
                $types,
                $name,
                $description,
                $phone,
                $email,
                $address,
                $lat,
                $lng,
                $logoName,
                $userId
            );

            if ($stmt->execute()) {
                $serviceId = $mysqli->insert_id;

                // дополнительные фото
                if (!empty($_FILES['photos']['name'][0])) {
                    foreach ($_FILES['photos']['name'] as $i => $pname) {
                        if (!is_uploaded_file($_FILES['photos']['tmp_name'][$i])) continue;
                        $ext = pathinfo($pname, PATHINFO_EXTENSION);
                        $fileName = uniqid('photo_') . "." . $ext;
                        move_uploaded_file($_FILES['photos']['tmp_name'][$i], $uploadDir . $fileName);

                        $stmt2 = $mysqli->prepare("INSERT INTO service_photos (service_id, photo) VALUES (?, ?)");
                        if ($stmt2) {
                            $stmt2->bind_param("is", $serviceId, $fileName);
                            $stmt2->execute();
                            $stmt2->close();
                        }
                    }
                }

                // prices: attempt to insert into service_prices if the table exists
                if (!empty($_POST['prices']['name']) && is_array($_POST['prices']['name'])) {
                    // check table exists
                    $havePricesTable = false;
                    $check = $mysqli->query("SHOW TABLES LIKE 'service_prices'");
                    if ($check && $check->num_rows > 0) $havePricesTable = true;
                    if ($havePricesTable) {
                        $stmtP = $mysqli->prepare("INSERT INTO service_prices (service_id, name, price) VALUES (?, ?, ?)");
                        if ($stmtP) {
                            foreach ($_POST['prices']['name'] as $idx => $pName) {
                                $pName = trim($pName);
                                $pPrice = isset($_POST['prices']['price'][$idx]) ? trim($_POST['prices']['price'][$idx]) : '';
                                if ($pName === '') continue;
                                // normalize price number
                                $pPrice = str_replace(',', '.', $pPrice);
                                $pPriceFloat = is_numeric($pPrice) ? floatval($pPrice) : 0.0;
                                $stmtP->bind_param("isd", $serviceId, $pName, $pPriceFloat);
                                $stmtP->execute();
                            }
                            $stmtP->close();
                        }
                    } else {
                        // table not present — skipping insert, could store into meta column if desired
                        // optionally: save JSON into services.meta if column exists (not implemented)
                    }
                }

                $message = "Сервис добавлен и ожидает модерации.";
            } else {
                $errors[] = "Ошибка сохранения: " . htmlspecialchars($stmt->error);
            }
            $stmt->close();
        } else {
            $errors[] = "Ошибка подготовки запроса: " . htmlspecialchars($mysqli->error);
        }
    }
}

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
    /* Page styles for add-service */
    .page { max-width:920px; margin:20px auto; padding:16px; }
    .card { background:#fff; border-radius:12px; padding:18px; box-shadow:0 8px 30px rgba(12,20,30,0.04); border:1px solid #f0f3f7; }
    h1 { margin:0 0 12px 0; font-size:1.4rem; }
    label.block { display:block; font-weight:600; margin-top:10px; color:#333; }
    .input, textarea, .file { width:100%; padding:10px 12px; border-radius:8px; border:1px solid #e6e9ef; box-sizing:border-box; font-size:1rem; }
    textarea { min-height:120px; resize:vertical; }
    .row { display:flex; gap:12px; }
    .row .col { flex:1; }
    .muted { color:#666; padding:12px 0; }
    .btn { display:inline-block; background:#0b57a4; color:#fff; padding:9px 14px; border-radius:10px; text-decoration:none; border:0; cursor:pointer; font-weight:700; }
    .btn.ghost { background:transparent; color:#0b57a4; border:1px solid #e6eefc; }
    .notice { background:#f7fdfc; border:1px solid #e6f8f3; padding:10px; border-radius:8px; color:#064c3b; margin-bottom:12px; }
    .error { background:#fff5f5; border:1px solid #ffd6d6; padding:10px; border-radius:8px; color:#8a1f1f; margin-bottom:12px; }

    /* Prices widget */
    .prices { margin-top:12px; border:1px dashed #e6e9ef; padding:12px; border-radius:8px; background:#fbfcfe; }
    .prices-rows { display:flex; flex-direction:column; gap:8px; margin-top:8px; }
    .price-row { display:flex; gap:8px; align-items:center; }
    .price-row .p-name { flex:1; padding:8px 10px; border-radius:8px; border:1px solid #e6e9ef; background:#fff; }
    .price-row .p-price { width:140px; padding:8px 10px; border-radius:8px; border:1px solid #e6e9ef; background:#fff; text-align:right; }
    .price-row .p-actions { display:flex; gap:6px; }
    .small-btn { padding:6px 8px; border-radius:8px; border:0; cursor:pointer; font-weight:600; }
    .small-btn.edit { background:#f0f7ff; color:#0b57a4; border:1px solid #dbeeff; }
    .small-btn.delete { background:#fff5f5; color:#b02a2a; border:1px solid #ffd6d6; }

    .form-foot { margin-top:16px; display:flex; gap:8px; align-items:center; justify-content:flex-end; }

    @media(max-width:760px){
      .row { flex-direction:column; }
      .price-row { flex-direction:column; align-items:stretch; }
      .price-row .p-price { width:100%; text-align:left; }
      .form-foot { flex-direction:column; align-items:stretch; }
    }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<main class="page">
  <div class="card">
    <h1>Добавить сервис / услугу</h1>

    <?php if (!empty($message)): ?>
      <div class="notice"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
      <div class="error">
        <?php foreach ($errors as $er): ?><div><?= htmlspecialchars($er) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form id="serviceForm" method="post" enctype="multipart/form-data">
      <label class="block">Название*:
        <input class="input" type="text" name="name" required>
      </label>

      <label class="block">Описание*:
        <textarea class="input" name="description" required></textarea>
      </label>

      <div class="row">
        <div class="col">
          <label class="block">Контактное лицо*:
            <input class="input" type="text" name="phone" required placeholder="+7 777 777 77 77">
          </label>
        </div>
        <div class="col">
          <label class="block">Email:
            <input class="input" type="email" name="email" placeholder="example@mail.com">
          </label>
        </div>
      </div>

      <label class="block">Адрес:
        <input class="input" type="text" name="address" placeholder="Город, улица, дом">
      </label>

      <label class="block">Местоположение (щелкните по карте чтобы поставить метку):</label>
      <div id="map" style="height:320px;border:1px solid #ddd;border-radius:8px;"></div>
      <input type="hidden" name="latitude" id="latitude">
      <input type="hidden" name="longitude" id="longitude">

      <div style="display:flex; gap:12px; margin-top:12px; flex-wrap:wrap;">
        <label class="block" style="flex:1;">
          Логотип:
          <input class="file" type="file" name="logo" accept="image/*">
        </label>

        <label class="block" style="flex:1;">
          Фотографии (можно несколько):
          <input class="file" type="file" name="photos[]" accept="image/*" multiple>
        </label>
      </div>

      <!-- Prices widget -->
      <div class="prices" aria-live="polite">
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <div style="font-weight:700;">Цены на услуги</div>
          <div>
            <button type="button" id="addPriceBtn" class="btn ghost">+ Добавить услугу</button>
          </div>
        </div>

        <div class="prices-rows" id="pricesRows">
          <!-- dynamic rows will be here -->
        </div>

        <!-- Hidden template for submission: we will create inputs named prices[name][] and prices[price][] -->
      </div>

      <div class="form-foot">
        <a href="services.php" class="btn ghost" style="text-decoration:none; padding:9px 12px;">Отмена</a>
        <button type="submit" class="btn">Сохранить и отправить на модерацию</button>
      </div>
    </form>
  </div>
</main>

<footer style="padding:20px;text-align:center;color:#777;font-size:.9rem;">
  &copy; <?= date('Y') ?> Mehanik
</footer>

<!-- Leaflet -->
<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<script>
/* Map: click to set marker and fill hidden inputs */
const map = L.map('map').setView([37.95, 58.38], 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
let marker;
map.on('click', function(e){
    if (marker) marker.setLatLng(e.latlng);
    else marker = L.marker(e.latlng).addTo(map);
    document.getElementById('latitude').value = e.latlng.lat;
    document.getElementById('longitude').value = e.latlng.lng;
});
</script>

<script>
/* Prices widget: add / edit / delete rows and keep inputs for form submission */
(function(){
  const rowsContainer = document.getElementById('pricesRows');
  const addPriceBtn = document.getElementById('addPriceBtn');

  // create a single row DOM; mode: 'view' or 'edit'
  function createRowElement(name = '', price = '') {
    const row = document.createElement('div');
    row.className = 'price-row';

    const nameEl = document.createElement('div');
    nameEl.className = 'p-name';
    nameEl.textContent = name || '—';

    const priceEl = document.createElement('div');
    priceEl.className = 'p-price';
    priceEl.textContent = price !== '' ? price : '—';

    const actions = document.createElement('div');
    actions.className = 'p-actions';

    const editBtn = document.createElement('button');
    editBtn.type = 'button';
    editBtn.className = 'small-btn edit';
    editBtn.textContent = 'Исправить';

    const delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.className = 'small-btn delete';
    delBtn.textContent = 'Удалить';

    actions.appendChild(editBtn);
    actions.appendChild(delBtn);

    row.appendChild(nameEl);
    row.appendChild(priceEl);
    row.appendChild(actions);

    // hidden inputs for form submission
    const hiddenName = document.createElement('input');
    hiddenName.type = 'hidden';
    hiddenName.name = 'prices[name][]';
    hiddenName.value = name;

    const hiddenPrice = document.createElement('input');
    hiddenPrice.type = 'hidden';
    hiddenPrice.name = 'prices[price][]';
    hiddenPrice.value = price;

    row.appendChild(hiddenName);
    row.appendChild(hiddenPrice);

    // edit handler: replace view with inputs inline
    editBtn.addEventListener('click', function(){
      // prevent multiple editors
      if (row.dataset.editing === '1') return;
      row.dataset.editing = '1';

      const nameInput = document.createElement('input');
      nameInput.type = 'text';
      nameInput.className = 'p-name';
      nameInput.style.padding = '8px 10px';
      nameInput.style.borderRadius = '8px';
      nameInput.value = hiddenName.value;

      const priceInput = document.createElement('input');
      priceInput.type = 'text';
      priceInput.className = 'p-price';
      priceInput.style.padding = '8px 10px';
      priceInput.style.borderRadius = '8px';
      priceInput.style.textAlign = 'right';
      priceInput.value = hiddenPrice.value;

      // replace nodes
      row.replaceChild(nameInput, nameEl);
      row.replaceChild(priceInput, priceEl);

      // change buttons
      editBtn.textContent = 'Сохранить';
      editBtn.classList.remove('edit');
      editBtn.classList.add('edit'); // keep style
      delBtn.textContent = 'Отмена';
      delBtn.classList.remove('delete');

      // Save handler
      const saveHandler = function(){
        const newName = nameInput.value.trim();
        const newPrice = priceInput.value.trim();
        if (newName === '') {
          alert('Введите название услуги');
          nameInput.focus();
          return;
        }
        // update text nodes
        nameEl.textContent = newName;
        priceEl.textContent = newPrice !== '' ? newPrice : '—';
        hiddenName.value = newName;
        hiddenPrice.value = newPrice;

        // restore view
        row.replaceChild(nameEl, nameInput);
        row.replaceChild(priceEl, priceInput);
        editBtn.textContent = 'Исправить';
        delBtn.textContent = 'Удалить';
        delBtn.classList.add('delete');
        row.dataset.editing = '0';

        // rebind del handler below (we re-create listeners when needed)
      };

      // Cancel handler (restore previous values)
      const cancelHandler = function(){
        row.replaceChild(nameEl, nameInput);
        row.replaceChild(priceEl, priceInput);
        editBtn.textContent = 'Исправить';
        delBtn.textContent = 'Удалить';
        delBtn.classList.add('delete');
        row.dataset.editing = '0';
      };

      // temporarily change listeners
      const onEditClick = function(){ saveHandler(); };
      const onDelClick = function(){ cancelHandler(); };

      // replace event listeners by reassigning
      editBtn.onclick = onEditClick;
      delBtn.onclick = onDelClick;
    });

    // delete handler
    delBtn.addEventListener('click', function(){
      if (row.dataset.editing === '1') {
        // if currently editing, treat as cancel - handled by edit flow
        return;
      }
      if (!confirm('Удалить услугу?')) return;
      rowsContainer.removeChild(row);
    });

    return row;
  }

  // add new empty editable row (immediately editable)
  function addEditableRow(name = '', price = '') {
    const row = createRowElement(name, price);
    // trigger edit immediately
    rowsContainer.appendChild(row);
    // simulate click on edit to open inputs
    const editBtn = row.querySelector('.small-btn.edit');
    if (editBtn) editBtn.click();
  }

  addPriceBtn.addEventListener('click', function(){
    addEditableRow('', '');
  });

  // on form submit — ensure no rows are left in edit mode and hidden inputs exist
  const form = document.getElementById('serviceForm');
  form.addEventListener('submit', function(e){
    // find any rows being edited and attempt to save
    const editingRow = rowsContainer.querySelector('[data-editing="1"]');
    if (editingRow) {
      // try to find save button and invoke click
      const saveBtn = editingRow.querySelector('.small-btn.edit');
      if (saveBtn) {
        saveBtn.click();
        // small delay to allow save; in case inputs invalid - prevent submit
        if (editingRow.dataset.editing === '1') {
          e.preventDefault();
          return;
        }
      }
    }
    // ensure at least one hidden input exists per row (they are created in createRowElement)
    // nothing else to do — hidden inputs already appended to rows
  });

})();
</script>

<script src="/mehanik/assets/js/main.js"></script>
</body>
</html>
