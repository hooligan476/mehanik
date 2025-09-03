<?php
// mehanik/public/edit-service.php
session_start();
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../db.php';

if (empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}
$user = $_SESSION['user'];
$userId = (int)$user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: services.php');
    exit;
}

// check ownership or admin
$service = null;
if ($st = $mysqli->prepare("SELECT id,user_id,name,description,logo,contact_name,phone,email,address,latitude,longitude FROM services WHERE id = ? LIMIT 1")) {
    $st->bind_param('i', $id);
    $st->execute();
    $service = $st->get_result()->fetch_assoc();
    $st->close();
}
if (!$service) {
    $_SESSION['flash_error'] = 'Сервис не найден';
    header('Location: services.php'); exit;
}
if (!$isAdmin && (int)$service['user_id'] !== $userId) {
    $_SESSION['flash_error'] = 'Нет доступа к редактированию';
    header('Location: services.php'); exit;
}

// helper
function toPublicUrl($rel){ if(!$rel) return ''; if(preg_match('#^https?://#i',$rel)) return $rel; if (strpos($rel,'/')===0) return $rel; return '/mehanik/' . ltrim($rel,'/'); }

// handlers:
// 1) update basic info or prices
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_service') {
    $name = trim($_POST['name'] ?? '');
    $contact_name = trim($_POST['contact_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $lat = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? floatval($_POST['latitude']) : null;
    $lng = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? floatval($_POST['longitude']) : null;

    if ($name === '' || $phone === '') {
        $_SESSION['flash_error'] = 'Название и телефон обязательны';
        header("Location: edit-service.php?id={$id}"); exit;
    }

    $latVal = $lat === null ? 0.0 : $lat;
    $lngVal = $lng === null ? 0.0 : $lng;

    if ($st = $mysqli->prepare("UPDATE services SET name=?, contact_name=?, description=?, phone=?, email=?, address=?, latitude=?, longitude=? WHERE id=? LIMIT 1")) {
        $st->bind_param('sssssddsi', $name, $contact_name, $description, $phone, $email, $address, $latVal, $lngVal, $id);
        $st->execute();
        $st->close();
    }

    // process prices: delete all and reinsert
    if (isset($_POST['prices']['name']) && is_array($_POST['prices']['name'])) {
        $mysqli->query("DELETE FROM service_prices WHERE service_id = " . intval($id));
        if ($stmtP = $mysqli->prepare("INSERT INTO service_prices (service_id, name, price) VALUES (?, ?, ?)")) {
            foreach ($_POST['prices']['name'] as $pi => $pn) {
                $pn = trim($pn);
                $pp = trim($_POST['prices']['price'][$pi] ?? '');
                if ($pn === '') continue;
                $pp = str_replace(',', '.', $pp);
                $ppFloat = is_numeric($pp) ? floatval($pp) : 0.0;
                $stmtP->bind_param('isd', $id, $pn, $ppFloat);
                $stmtP->execute();
            }
            $stmtP->close();
        }
    }

    $_SESSION['flash'] = 'Сервис обновлён';
    header("Location: edit-service.php?id={$id}"); exit;
}

// 2) replace logo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'replace_logo') {
    if (!empty($_FILES['logo']['name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
        $allowedMime = ['image/jpeg','image/png','image/webp'];
        $maxSize = 5*1024*1024;
        if ($_FILES['logo']['size'] > $maxSize) { $_SESSION['flash_error']='Логотип слишком большой'; header("Location: edit-service.php?id={$id}"); exit; }
        $mime = mime_content_type($_FILES['logo']['tmp_name']);
        if (!in_array($mime, $allowedMime, true)) { $_SESSION['flash_error']='Неподдерживаемый формат логотипа'; header("Location: edit-service.php?id={$id}"); exit; }
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION)) ?: 'jpg';
        $ext = preg_replace('/[^a-z0-9]+/i','', $ext);
        $uploadsBase = __DIR__ . '/../uploads/services/' . $id;
        if (!is_dir($uploadsBase)) @mkdir($uploadsBase, 0755, true);
        $logoFile = 'logo.' . $ext;
        $dst = $uploadsBase . '/' . $logoFile;
        if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dst)) { $_SESSION['flash_error']='Не удалось сохранить логотип'; header("Location: edit-service.php?id={$id}"); exit; }
        $logoRel = 'uploads/services/' . $id . '/' . $logoFile;
        if ($st = $mysqli->prepare("UPDATE services SET logo = ? WHERE id = ? LIMIT 1")) {
            $st->bind_param('si', $logoRel, $id);
            $st->execute(); $st->close();
        }
        $_SESSION['flash'] = 'Логотип обновлён';
    }
    header("Location: edit-service.php?id={$id}"); exit;
}

// 3) add new photos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_photos') {
    if (!empty($_FILES['photos']['name']) && is_array($_FILES['photos']['name'])) {
        $uploadsBase = __DIR__ . '/../uploads/services/' . $id;
        if (!is_dir($uploadsBase)) @mkdir($uploadsBase, 0755, true);
        // count existing photos in folder or DB to continue numbering
        $maxExisting = 0;
        $r = $mysqli->query("SELECT photo FROM service_photos WHERE service_id = " . intval($id));
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $b = basename($row['photo']);
                if (preg_match('/photo(\d+)\./', $b, $m)) {
                    $n = (int)$m[1];
                    if ($n > $maxExisting) $maxExisting = $n;
                }
            }
            $r->free();
        }
        $index = $maxExisting;
        $allowedMime = ['image/jpeg','image/png','image/webp'];
        $maxSize = 5*1024*1024;
        $inserted = 0;
        $total = count($_FILES['photos']['name']);
        for ($i=0; $i<$total && $inserted < 10; $i++) {
            if (!is_uploaded_file($_FILES['photos']['tmp_name'][$i])) continue;
            if ($_FILES['photos']['size'][$i] > $maxSize) continue;
            $mime = mime_content_type($_FILES['photos']['tmp_name'][$i]);
            if (!in_array($mime, $allowedMime, true)) continue;
            $index++;
            $ext = strtolower(pathinfo($_FILES['photos']['name'][$i], PATHINFO_EXTENSION)) ?: 'jpg';
            $ext = preg_replace('/[^a-z0-9]+/i','', $ext);
            $photoFile = 'photo' . $index . '.' . $ext;
            $dst = $uploadsBase . '/' . $photoFile;
            if (move_uploaded_file($_FILES['photos']['tmp_name'][$i], $dst)) {
                $rel = 'uploads/services/' . $id . '/' . $photoFile;
                if ($ins = $mysqli->prepare("INSERT INTO service_photos (service_id, photo) VALUES (?, ?)")) {
                    $ins->bind_param('is', $id, $rel); $ins->execute(); $ins->close();
                }
                $inserted++;
            }
        }
        $_SESSION['flash'] = "Добавлено {$inserted} фото";
    }
    header("Location: edit-service.php?id={$id}"); exit;
}

// 4) delete photo (by id)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_photo') {
    $photoId = (int)($_POST['photo_id'] ?? 0);
    if ($photoId > 0) {
        if ($st = $mysqli->prepare("SELECT photo FROM service_photos WHERE id = ? AND service_id = ? LIMIT 1")) {
            $st->bind_param('ii', $photoId, $id);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($row) {
                $f = $row['photo'] ?? '';
                if ($f) {
                    $fs = __DIR__ . '/../' . ltrim($f, '/');
                    if (is_file($fs)) @unlink($fs);
                }
                if ($del = $mysqli->prepare("DELETE FROM service_photos WHERE id = ? AND service_id = ? LIMIT 1")) {
                    $del->bind_param('ii', $photoId, $id);
                    $del->execute(); $del->close();
                }
                $_SESSION['flash'] = 'Фото удалено';
            } else {
                $_SESSION['flash_error'] = 'Фото не найдено';
            }
        }
    }
    header("Location: edit-service.php?id={$id}"); exit;
}

// load latest photos and prices
$photos = [];
if ($st = $mysqli->prepare("SELECT id, photo FROM service_photos WHERE service_id = ? ORDER BY id ASC")) {
    $st->bind_param('i', $id);
    $st->execute();
    $photos = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}
$prices = [];
if ($st = $mysqli->prepare("SELECT id, name, price FROM service_prices WHERE service_id = ? ORDER BY id ASC")) {
    $st->bind_param('i', $id);
    $st->execute();
    $prices = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Редактировать сервис — Mehanik</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/mehanik/assets/css/header.css">
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    :root{
      --accent: #0b57a4;
      --muted: #6b7280;
      --card-bg: #fff;
      --border: #eef3f8;
      --radius: 12px;
    }
    body{background:#f6f8fb;color:#111;font-family:Inter,system-ui,Arial;margin:0}
    .container{max-width:1100px;margin:20px auto;padding:16px}
    .grid{display:grid;grid-template-columns:1fr 380px;gap:20px;align-items:start}
    .card{background:var(--card-bg);border-radius:var(--radius);padding:18px;border:1px solid var(--border);box-shadow:0 8px 30px rgba(12,20,30,0.04)}
    h1{margin:0 0 12px 0;font-size:1.4rem;color:var(--accent)}
    label{display:block;font-weight:600;margin-top:10px;color:#26323a}
    .input, textarea, select{width:100%;padding:10px;border-radius:10px;border:1px solid #e6e9ef;box-sizing:border-box;font-size:14px;margin-top:6px}
    textarea{min-height:110px;resize:vertical}
    .small{font-size:13px;color:var(--muted);margin-top:6px}
    .row{display:flex;gap:10px}
    .row .col{flex:1}
    .actions{display:flex;gap:8px;justify-content:flex-end;margin-top:14px}
    .btn{background:var(--accent);color:#fff;padding:10px 14px;border-radius:10px;border:0;cursor:pointer;font-weight:700}
    .btn.ghost{background:transparent;color:var(--accent);border:1px solid #dbeeff;padding:10px 12px}
    .btn-danger{background:#ef4444;color:#fff;padding:8px 12px;border-radius:8px;border:0}
    .muted-box{background:#fbfdff;border:1px solid #eef6ff;padding:10px;border-radius:10px;color:#274155}
    .map{height:240px;border-radius:8px;overflow:hidden;border:1px solid #e6eef7;margin-top:8px}
    .section-title{font-weight:800;margin:10px 0}
    /* photos */
    .photos-grid{display:flex;flex-wrap:wrap;gap:10px}
    .thumb{width:120px;height:90px;border-radius:8px;border:1px solid #eee;overflow:hidden;position:relative;background:#fff;display:flex;align-items:center;justify-content:center}
    .thumb img{width:100%;height:100%;object-fit:cover;display:block}
    .del-photo{position:absolute;top:6px;right:6px;background:rgba(255,255,255,0.95);border-radius:6px;padding:4px 6px;border:1px solid #f1f1f1;cursor:pointer;font-weight:700}
    .logo-preview{width:100%;height:200px;border-radius:8px;overflow:hidden;border:1px solid #eee;display:flex;align-items:center;justify-content:center;background:#fff}
    .logo-preview img{max-width:100%;max-height:100%;display:block;object-fit:contain}
    .note{font-size:13px;color:var(--muted);margin-top:6px}
    @media(max-width:980px){ .grid{grid-template-columns:1fr; } .logo-preview{height:180px} }
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<div class="container">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h1>Редактирование сервиса</h1>
    <div>
      <a href="service.php?id=<?= $id ?>" class="btn ghost" style="text-decoration:none">Просмотреть</a>
      <a href="services.php" class="btn" style="text-decoration:none;margin-left:8px">К списку</a>
    </div>
  </div>

  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="muted-box" style="margin-bottom:12px"><?= htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="muted-box" style="margin-bottom:12px;background:#fff5f5;border-color:#ffd6d6;color:#7f1d1d"><?= htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
  <?php endif; ?>

  <div class="grid">
    <!-- LEFT: form -->
    <div class="card">
      <form method="post" action="edit-service.php?id=<?= $id ?>">
        <input type="hidden" name="action" value="update_service">
        <label>Название*:
          <input class="input" type="text" name="name" required value="<?= htmlspecialchars($service['name']) ?>">
        </label>

        <div class="row">
          <div class="col">
            <label>Контактное имя:
              <input class="input" type="text" name="contact_name" value="<?= htmlspecialchars($service['contact_name']) ?>">
            </label>
          </div>
          <div class="col">
            <label>Телефон*:
              <input class="input" type="text" name="phone" required value="<?= htmlspecialchars($service['phone']) ?>" placeholder="+99371234567">
            </label>
          </div>
        </div>

        <div class="row">
          <div class="col">
            <label>Email:
              <input class="input" type="email" name="email" value="<?= htmlspecialchars($service['email']) ?>">
            </label>
          </div>
          <div class="col">
            <label>Адрес:
              <input class="input" type="text" name="address" value="<?= htmlspecialchars($service['address']) ?>">
            </label>
          </div>
        </div>

        <label>Описание:
          <textarea class="input" name="description"><?= htmlspecialchars($service['description']) ?></textarea>
        </label>

        <label class="section-title">Местоположение</label>
        <div class="map" id="map"></div>
        <input type="hidden" name="latitude" id="latitude" value="<?= htmlspecialchars($service['latitude']) ?>">
        <input type="hidden" name="longitude" id="longitude" value="<?= htmlspecialchars($service['longitude']) ?>">

        <label class="section-title" style="margin-top:12px">Цены</label>
        <div id="pricesRows">
          <?php if (empty($prices)): ?>
            <div style="display:flex;gap:8px;margin-bottom:8px">
              <input class="input" type="text" name="prices[name][]" placeholder="Услуга">
              <input class="input" type="text" name="prices[price][]" style="width:120px" placeholder="Цена">
            </div>
          <?php else: foreach ($prices as $p): ?>
            <div style="display:flex;gap:8px;margin-bottom:8px;">
              <input type="text" name="prices[name][]" class="input" style="flex:1" value="<?= htmlspecialchars($p['name']) ?>" placeholder="Услуга">
              <input type="text" name="prices[price][]" class="input" style="width:120px" value="<?= htmlspecialchars($p['price']) ?>" placeholder="Цена">
            </div>
          <?php endforeach; endif; ?>
        </div>
        <div style="margin-top:8px">
          <button type="button" id="addPrice" class="btn ghost" style="padding:8px 10px">+ Добавить позицию</button>
          <div class="note">Каждая позиция будет сохранена как отдельный тариф.</div>
        </div>

        <div class="actions" style="margin-top:16px">
          <a class="btn ghost" href="service.php?id=<?= $id ?>">Отмена</a>
          <button type="submit" class="btn">Сохранить изменения</button>
        </div>
      </form>
    </div>

    <!-- RIGHT: logo / photos -->
    <aside>
      <div class="card" style="margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <div class="section-title">Логотип</div>
          <div class="small">Рекомендуемый размер 500×300, max 5MB</div>
        </div>

        <div class="logo-preview" style="margin-top:10px">
          <?php if (!empty($service['logo'])): ?>
            <img src="<?= htmlspecialchars(toPublicUrl($service['logo'])) ?>" alt="logo">
          <?php else: ?>
            <div style="color:#9aa3ad;font-weight:700">Нет логотипа</div>
          <?php endif; ?>
        </div>

        <form method="post" enctype="multipart/form-data" style="margin-top:12px">
          <input type="hidden" name="action" value="replace_logo">
          <label style="display:flex;gap:8px;align-items:center">
            <input type="file" name="logo" accept="image/*" required>
            <button type="submit" class="btn" style="margin-left:6px">Заменить</button>
          </label>
        </form>
      </div>

      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <div class="section-title">Фотографии</div>
          <div class="small">Можно добавить до 10 новых</div>
        </div>

        <div style="margin-top:10px" class="photos-grid">
          <?php if (empty($photos)): ?>
            <div class="note">Фото пока нет</div>
          <?php endif; ?>
          <?php foreach ($photos as $ph):
            $pid=(int)$ph['id'];
            $purl = toPublicUrl($ph['photo']);
          ?>
            <div class="thumb" title="Клик — удалить">
              <img src="<?= htmlspecialchars($purl) ?>" alt="">
              <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="delete_photo">
                <input type="hidden" name="photo_id" value="<?= $pid ?>">
                <button class="del-photo" type="submit" onclick="return confirm('Удалить фото?')">×</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>

        <form method="post" enctype="multipart/form-data" style="margin-top:12px">
          <input type="hidden" name="action" value="add_photos">
          <label style="display:block">
            <input type="file" name="photos[]" accept="image/*" multiple>
          </label>
          <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">
            <button type="submit" class="btn">Добавить фото</button>
          </div>
          <div class="note" style="margin-top:8px">Файлы будут сохранены в папке <code>/uploads/services/<?= $id ?>/</code> и пронумерованы как <code>photo1.jpg</code>, <code>photo2.jpg</code>…</div>
        </form>
      </div>
    </aside>
  </div>
</div>

<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<script>
const latVal = <?= ($service['latitude'] !== null && $service['latitude'] !== '') ? floatval($service['latitude']) : 37.95 ?>;
const lngVal = <?= ($service['longitude'] !== null && $service['longitude'] !== '') ? floatval($service['longitude']) : 58.38 ?>;
const map = L.map('map').setView([latVal, lngVal], 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
let marker = null;
if (<?= ($service['latitude'] !== null && $service['latitude'] !== '') ? 'true' : 'false' ?>) {
  marker = L.marker([latVal,lngVal]).addTo(map);
}
map.on('click', function(e){
  if(marker) marker.setLatLng(e.latlng); else marker = L.marker(e.latlng).addTo(map);
  document.getElementById('latitude').value = e.latlng.lat;
  document.getElementById('longitude').value = e.latlng.lng;
});

// add price row
document.getElementById('addPrice').addEventListener('click', function(){
  const rows = document.getElementById('pricesRows');
  const div = document.createElement('div');
  div.style.display='flex'; div.style.gap='8px'; div.style.marginTop='8px';
  const in1 = document.createElement('input'); in1.type='text'; in1.name='prices[name][]'; in1.className='input'; in1.placeholder = 'Услуга';
  const in2 = document.createElement('input'); in2.type='text'; in2.name='prices[price][]'; in2.className='input'; in2.style.width='120px'; in2.placeholder = 'Цена';
  div.appendChild(in1); div.appendChild(in2);
  rows.appendChild(div);
});
</script>

</body>
</html>
