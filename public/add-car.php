<?php
// public/add-car.php
// Добавить авто — форма + серверная обработка (простая)
require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php';

$basePublic = '/mehanik/public';
$currentYear = (int)date('Y');
$minYear = $currentYear - 25;
$user_id = $_SESSION['user']['id'] ?? 0;

// Обработка POST
$errors = [];
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $year = (int)($_POST['year'] ?? 0);
    $body = trim($_POST['body'] ?? '');
    $mileage = (int)($_POST['mileage'] ?? 0);
    $transmission = trim($_POST['transmission'] ?? '');
    $fuel = trim($_POST['fuel'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? ($_SESSION['user']['phone'] ?? ''));

    if ($brand === '') $errors[] = 'Бренд обязателен';
    if ($model === '') $errors[] = 'Модель обязателна';
    if ($year < $minYear || $year > $currentYear) $errors[] = "Год должен быть в диапазоне {$minYear}—{$currentYear}";
    if ($price < 0) $errors[] = 'Цена некорректна';

    // загрузка фото (опционально, до 6)
    $photoFilename = null;
    if (!empty($_FILES['photos']) && is_array($_FILES['photos']['tmp_name'])) {
        $uploadDir = __DIR__ . '/../uploads/cars/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $saved = 0;
        $filesCount = count($_FILES['photos']['tmp_name']);
        $photoList = [];
        for ($i = 0; $i < $filesCount && $saved < 6; $i++) {
            $tmp = $_FILES['photos']['tmp_name'][$i];
            $orig = basename($_FILES['photos']['name'][$i] ?? '');
            $err = $_FILES['photos']['error'][$i] ?? 1;
            if ($err !== UPLOAD_ERR_OK || !$tmp) continue;
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'])) continue;
            $newName = time() . '_' . bin2hex(random_bytes(6)) . "_{$i}." . $ext;
            $dest = $uploadDir . $newName;
            if (move_uploaded_file($tmp, $dest)) {
                $photoList[] = 'uploads/cars/' . $newName;
                $saved++;
            }
        }
        if (count($photoList)) {
            // сохраняем первый как основное (можно расширить, чтобы хранить несколько)
            $photoFilename = $photoList[0];
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $mysqli->prepare("INSERT INTO cars (user_id, brand, model, year, body, mileage, transmission, fuel, price, photo, description, contact_phone, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->bind_param("isssisisdssss",
                $user_id,
                $brand,
                $model,
                $year,
                $body,
                $mileage,
                $transmission,
                $fuel,
                $price,
                $photoFilename,
                $description,
                $contact_phone
            );
            $ok = $stmt->execute();
            if ($ok) {
                $success = 'Автомобиль успешно добавлен и отправлен на модерацию.';
                // Перенаправляем на мои авто
                header('Location: ' . $basePublic . '/my-cars.php?msg=' . urlencode($success));
                exit;
            } else {
                $errors[] = 'Ошибка при сохранении в базу данных.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Исключение: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
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
    .photo-preview img { width:120px; height:80px; object-fit:cover; border-radius:8px; border:1px solid #e6eef7; }
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
            <label class="block">Бренд *</label>
            <select id="brand" name="brand" required><option value="">— выберите бренд —</option></select>
          </div>

          <div>
            <label class="block">Модель *</label>
            <select id="model" name="model" required><option value="">— выберите модель —</option></select>
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
            <label class="block">Кузов</label>
            <select name="body">
              <option value="">— выбрать —</option>
              <option value="Седан">Седан</option>
              <option value="Хэтчбек">Хэтчбек</option>
              <option value="Универсал">Универсал</option>
              <option value="SUV">SUV / Внедорожник</option>
              <option value="Купе">Купе</option>
              <option value="Минивэн">Минивэн</option>
              <option value="Пикап">Пикап</option>
              <option value="Другое">Другое</option>
            </select>
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
            <input id="photos" type="file" name="photos[]" accept="image/*" multiple>
            <div class="small">Рекомендуется не больше 6 фото. Первое фото будет основным.</div>
            <div id="previews" class="photo-preview" aria-hidden="true"></div>
          </div>

          <div>
            <label class="block">Контактный телефон</label>
            <input type="text" name="contact_phone" value="<?= htmlspecialchars($_SESSION['user']['phone'] ?? '') ?>">
            <div class="small">Этот телефон будет показан покупателям.</div>
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
/* JS: подгрузка брендов/моделей + превью изображений + валидация года перед отправкой */
(function(){
  const brandEl = document.getElementById('brand');
  const modelEl = document.getElementById('model');
  const previews = document.getElementById('previews');
  const photosInput = document.getElementById('photos');
  const minYear = <?= json_encode($minYear) ?>;
  const currentYear = <?= json_encode($currentYear) ?>;

  // Попытка воспользоваться глобальными функциями, иначе fetch /api/brands_models.php
  async function loadLookupsSafe(){
    if (typeof loadLookups === 'function') {
      try { const r = await loadLookups(); if (r && r.brands) return r; } catch(e){ console.warn(e); }
    }
    if (window.productList && typeof productList.getLookups === 'function') {
      try { const r = await productList.getLookups(); if (r && r.brands) return r; } catch(e){ console.warn(e); }
    }
    // fallback fetch
    try {
      const resp = await fetch('/mehanik/api/brands_models.php', {credentials:'same-origin'});
      if (resp.ok) return await resp.json();
    } catch(e){ /* ignore */ }
    return {brands: []};
  }

  function fillBrands(data) {
    brandEl.innerHTML = '<option value=\"\">— выберите бренд —</option>';
    (data.brands || []).forEach(b => {
      const opt = document.createElement('option');
      opt.value = b.name ?? b.id ?? '';
      opt.textContent = b.name ?? b.id ?? '';
      opt.dataset.id = b.id ?? '';
      brandEl.appendChild(opt);
    });
  }
  function fillModels(brandName, data) {
    modelEl.innerHTML = '<option value=\"\">— выберите модель —</option>';
    if (!brandName) return;
    const found = (data.brands || []).find(b => (b.name == brandName) || (String(b.id) === String(brandName)));
    if (!found || !Array.isArray(found.models)) return;
    found.models.forEach(m => {
      const opt = document.createElement('option');
      opt.value = m.name ?? m.id ?? '';
      opt.textContent = m.name ?? m.id ?? '';
      modelEl.appendChild(opt);
    });
  }

  document.addEventListener('DOMContentLoaded', async () => {
    const lookups = await loadLookupsSafe();
    fillBrands(lookups);

    brandEl.addEventListener('change', (e) => fillModels(e.target.value, lookups));

    // превью фото
    photosInput.addEventListener('change', () => {
      previews.innerHTML = '';
      const files = Array.from(photosInput.files || []).slice(0,6);
      files.forEach(file => {
        const fr = new FileReader();
        fr.onload = (ev) => {
          const img = document.createElement('img');
          img.src = ev.target.result;
          previews.appendChild(img);
        };
        fr.readAsDataURL(file);
      });
      previews.setAttribute('aria-hidden', files.length === 0 ? 'true' : 'false');
    });

    // проверка при сабмите
    const form = document.getElementById('addCarForm');
    form.addEventListener('submit', (e) => {
      const year = parseInt(document.getElementById('year').value || '0', 10);
      if (!year || year < minYear || year > currentYear) {
        e.preventDefault();
        alert('Выберите корректный год. Допустимый диапазон: ' + minYear + ' — ' + currentYear);
        return false;
      }
      if (!brandEl.value || !modelEl.value) {
        e.preventDefault();
        alert('Пожалуйста, выберите бренд и модель.');
        return false;
      }
      return true;
    });
  });
})();
</script>

</body>
</html>
