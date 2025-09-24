<?php
// public/edit-car.php
require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

// Префикс публичной части приложения — скорректируйте, если ваше приложение в другом подкаталоге.
$APP_PREFIX = '/mehanik'; // <- при необходимости поменяйте

function pub_url(string $p) : string {
    global $APP_PREFIX;
    $p = (string)$p;
    if ($p === '') return $APP_PREFIX . '/assets/no-photo.png';
    if (strpos($p, $APP_PREFIX . '/') === 0) return $p;
    if ($p[0] === '/') {
        return rtrim($APP_PREFIX, '/') . $p;
    }
    return rtrim($APP_PREFIX, '/') . '/' . ltrim($p, '/');
}

$currentUser = $_SESSION['user'] ?? null;
$uid = (int)($currentUser['id'] ?? 0);
$isAdmin = in_array($currentUser['role'] ?? '', ['admin','superadmin'], true);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location:/mehanik/public/my-cars.php'); exit; }

// load car
$car = null;
try {
    $st = $mysqli->prepare("SELECT * FROM cars WHERE id = ? LIMIT 1");
    $st->bind_param('i',$id); $st->execute(); $res = $st->get_result(); $car = $res ? $res->fetch_assoc() : null; $st->close();
} catch (Throwable $e) { error_log("edit-car load: ".$e->getMessage()); }

if (!$car) { http_response_code(404); echo "Объявление не найдено"; exit; }

// permission
$ownerId = (int)($car['user_id'] ?? 0);
if (!$isAdmin && $uid !== $ownerId) { http_response_code(403); echo "Нет прав"; exit; }

// load extra photos
$extraPhotos = [];
try {
    $stp = $mysqli->prepare("SELECT id, file_path FROM car_photos WHERE car_id = ? ORDER BY id ASC");
    $stp->bind_param('i',$id); $stp->execute(); $res = $stp->get_result();
    if ($res) $extraPhotos = $res->fetch_all(MYSQLI_ASSOC);
    $stp->close();
} catch (Throwable $_) { /* ignore */ }

$errors = [];
$success = '';

$uploadsBaseRel = 'uploads/cars';
$uploadsBase = __DIR__ . '/../' . $uploadsBaseRel;
$prodDir = $uploadsBase . '/' . intval($id);
$webProdPrefix = rtrim($APP_PREFIX, '/') . '/' . trim($uploadsBaseRel, '/') . '/' . intval($id) . '/';
if (!is_dir($prodDir)) @mkdir($prodDir, 0755, true);

$acceptedExt = ['jpg','jpeg','png','webp'];
$maxFileSize = 6 * 1024 * 1024;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vin = trim($_POST['vin'] ?? '');
    $mileage = isset($_POST['mileage']) ? (int)$_POST['mileage'] : 0;
    $price = isset($_POST['price']) ? (float)$_POST['price'] : 0.0;
    $description = trim($_POST['description'] ?? '');
    // delete extras
    $delete_photos = [];
    if (!empty($_POST['delete_photos']) && is_array($_POST['delete_photos'])) {
        foreach ($_POST['delete_photos'] as $v) { $v=(int)$v; if ($v>0) $delete_photos[] = $v; }
    }
    // set_main_existing (id of extra photo to become main)
    $set_main_existing = trim((string)($_POST['set_main_existing'] ?? ''));

    // handle files
    $uploadedMainTmp = null; $uploadedMainExt = null;
    if (!empty($_FILES['main_photo']['tmp_name']) && is_uploaded_file($_FILES['main_photo']['tmp_name']) && ($_FILES['main_photo']['error'] ?? 1) === UPLOAD_ERR_OK) {
        if ($_FILES['main_photo']['size'] > $maxFileSize) $errors[] = 'Основной файл слишком большой';
        else {
            $ext = strtolower(pathinfo($_FILES['main_photo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $acceptedExt, true)) $errors[] = 'Неподдерживаемый формат основного фото';
            else { $uploadedMainTmp = $_FILES['main_photo']['tmp_name']; $uploadedMainExt = $ext; }
        }
    }

    $pendingExtras = [];
    if (!empty($_FILES['photos']) && is_array($_FILES['photos']['tmp_name'])) {
        $cnt = count($_FILES['photos']['tmp_name']);
        for ($i=0;$i<$cnt;$i++) {
            $tmp = $_FILES['photos']['tmp_name'][$i] ?? null;
            $errf = $_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if (!$tmp || $errf !== UPLOAD_ERR_OK) continue;
            if ($_FILES['photos']['size'][$i] > $maxFileSize) { $errors[] = 'Один из дополнительных файлов слишком большой'; continue; }
            $ext = strtolower(pathinfo($_FILES['photos']['name'][$i] ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, $acceptedExt, true)) { $errors[] = 'Неподдерживаемый формат доп. фото'; continue; }
            $pendingExtras[] = ['tmp'=>$tmp,'ext'=>$ext,'orig'=>$_FILES['photos']['name'][$i]??'file'];
        }
    }

    // basic validation
    if ($vin === '') $errors[] = 'VIN обязателен';
    if ($price < 0) $errors[] = 'Цена должна быть >= 0';
    if ($mileage < 0) $errors[] = 'Пробег должен быть >= 0';

    if (empty($errors)) {
        try {
            $mysqli->begin_transaction();

            // delete selected extra photos (files + DB rows)
            if (!empty($delete_photos)) {
                $toDel = [];
                $stSel = $mysqli->prepare("SELECT id,file_path FROM car_photos WHERE id=? AND car_id=? LIMIT 1");
                foreach ($delete_photos as $delId) {
                    $stSel->bind_param('ii',$delId,$id); $stSel->execute(); $r = $stSel->get_result()->fetch_assoc();
                    if ($r) $toDel[] = $r;
                }
                $stSel->close();
                if (!empty($toDel)) {
                    $delSt = $mysqli->prepare("DELETE FROM car_photos WHERE id=? AND car_id=?");
                    foreach ($toDel as $row) {
                        $fp = $row['file_path'];
                        $abs = __DIR__ . '/../' . ltrim($fp,'/');
                        if (is_file($abs)) @unlink($abs);
                        $iid=(int)$row['id'];
                        $delSt->bind_param('ii',$iid,$id); $delSt->execute();
                    }
                    $delSt->close();
                }
            }

            // move pending extras to folder and collect web paths
            $newExtraWeb = [];
            foreach ($pendingExtras as $item) {
                $uniq = preg_replace('/[^a-z0-9]+/i','', uniqid('ph', true));
                $fname = 'photo_' . $uniq . '.' . $item['ext'];
                $abs = $prodDir . '/' . $fname;
                if (!@move_uploaded_file($item['tmp'],$abs)) throw new Exception('Не удалось сохранить дополнительное фото: '.$item['orig']);
                $newExtraWeb[] = $webProdPrefix . $fname;
            }

            // handle main photo upload
            $newMainWeb = null;
            if ($uploadedMainTmp) {
                $fname = 'main_' . $id . '.' . $uploadedMainExt;
                $abs = $prodDir . '/' . $fname;
                if (!@move_uploaded_file($uploadedMainTmp,$abs)) throw new Exception('Не удалось сохранить основное фото');
                $newMainWeb = $webProdPrefix . $fname;
                if (!empty($car['photo'])) {
                    $oldAbs = __DIR__ . '/../' . ltrim($car['photo'],'/');
                    if (is_file($oldAbs)) @unlink($oldAbs);
                }
            } elseif ($set_main_existing !== '') {
                $asId = is_numeric($set_main_existing) ? (int)$set_main_existing : 0;
                if ($asId > 0) {
                    $stc = $mysqli->prepare("SELECT file_path FROM car_photos WHERE car_id = ? AND id = ? LIMIT 1");
                    $stc->bind_param('ii', $id, $asId);
                    $stc->execute();
                    $cres = $stc->get_result()->fetch_assoc();
                    $stc->close();
                    if ($cres && !empty($cres['file_path'])) {
                        $newMainWeb = $cres['file_path'];
                    }
                }
            }

            if (!empty($newExtraWeb)) {
                $chk = $mysqli->query("SHOW TABLES LIKE 'car_photos'");
                if (!$chk || $chk->num_rows === 0) {
                    $mysqli->query("
                        CREATE TABLE IF NOT EXISTS car_photos (
                            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            car_id INT NOT NULL,
                            file_path VARCHAR(255) NOT NULL,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            INDEX (car_id),
                            CONSTRAINT fk_car_photos_car FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                }
                $ins = $mysqli->prepare("INSERT INTO car_photos (car_id, file_path) VALUES (?, ?)");
                foreach ($newExtraWeb as $p) { $ins->bind_param('is',$id,$p); $ins->execute(); }
                $ins->close();
            }

            // Update cars row (save chosen main photo path to cars.photo)
            $photoToSave = $newMainWeb ?? $car['photo'] ?? null;
            if ($photoToSave !== null) {
                $upd = $mysqli->prepare("UPDATE cars SET vin=?, mileage=?, price=?, description=?, photo=?, status='pending' WHERE id=?");
                $upd->bind_param('sidssi', $vin, $mileage, $price, $description, $photoToSave, $id);
            } else {
                $upd = $mysqli->prepare("UPDATE cars SET vin=?, mileage=?, price=?, description=?, status='pending' WHERE id=?");
                $upd->bind_param('sidsi', $vin, $mileage, $price, $description, $id);
            }
            $upd->execute();
            $upd->close();

            $mysqli->commit();

            $success = 'Изменения сохранены';
            // reload car and extras
            $st = $mysqli->prepare("SELECT * FROM cars WHERE id=? LIMIT 1"); $st->bind_param('i',$id); $st->execute(); $res = $st->get_result(); if ($res) $car = $res->fetch_assoc(); $st->close();
            $extraPhotos = [];
            $stp = $mysqli->prepare("SELECT id, file_path FROM car_photos WHERE car_id=? ORDER BY id ASC"); $stp->bind_param('i',$id); $stp->execute(); $r = $stp->get_result(); while ($row = $r->fetch_assoc()) $extraPhotos[] = $row; $stp->close();

        } catch (Throwable $e) {
            @$mysqli->rollback();
            $errors[] = 'Ошибка при сохранении: ' . $e->getMessage();
            error_log('edit-car save error: '.$e->getMessage());
        }
    }
}

require_once __DIR__ . '/header.php';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Редактировать авто — Mehanik</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/mehanik/assets/css/header.css">
<link rel="stylesheet" href="/mehanik/assets/css/style.css">
<style>
  :root{
    --bg:#f6f8fb; --card:#fff; --muted:#6b7280; --accent:#0b57a4; --radius:12px;
  }
  body{background:var(--bg);font-family:system-ui,Arial,sans-serif;color:#0f172a}
  .container{max-width:1100px;margin:28px auto;padding:12px}
  .card{background:var(--card);padding:16px;border-radius:var(--radius);box-shadow:0 10px 30px rgba(2,6,23,0.06)}
  .layout{display:grid;grid-template-columns:420px 1fr;gap:18px;align-items:start}
  @media(max-width:900px){ .layout{ grid-template-columns: 1fr } }
  label{display:block;font-weight:700;margin-top:10px;color:#0f172a}
  input[type="text"], input[type="number"], textarea { width:100%; padding:10px 12px; border-radius:8px; border:1px solid #e6e9ef; box-sizing:border-box; font-size:14px; }
  textarea{min-height:120px}
  /* main photo — now fills the frame (cover) */
  /* .main-photo — контейнер превью */
.main-photo {
  width: 100%;
  height: 440px;             /* можно поменять высоту */
  background: #f2f4f8;
  border-radius: 10px;
  overflow: hidden;
  position: relative;       /* обязательно для абсолютного img */
  display: block;
  box-shadow: inset 0 0 0 1px rgba(0,0,0,0.02);
}

/* img внутри .main-photo заполняет контейнер и режется по месту (cover) */
.main-photo img {
  position: absolute;
  inset: 0;                 /* top:0; right:0; bottom:0; left:0; */
  width: 100% !important;
  height: 100% !important;
  object-fit: cover;
  object-position: center;
  display: block;
  max-width: none !important; /* перекрывает глобальные правила */
}

  .thumbs{display:flex;gap:8px;overflow-x:auto;padding-bottom:6px;margin-top:8px}
  .thumb{width:120px;height:86px;flex:0 0 auto;border-radius:8px;overflow:hidden;position:relative;background:#fff;border:1px solid #e9eef6;box-shadow:0 6px 18px rgba(2,6,23,0.03);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:transform .12s ease,opacity .12s ease;}
  .thumb img{width:100%;height:100%;object-fit:cover;display:block}
  .thumb .controls{position:absolute;left:6px;bottom:6px;display:flex;gap:6px;z-index:2}
  .thumb .icon{background:rgba(0,0,0,0.55);color:#fff;padding:6px;border-radius:6px;font-size:12px;display:inline-flex;align-items:center;justify-content:center;user-select:none}
  .thumb .del{background:rgba(255,50,50,0.9)}
  .thumb.is-main{box-shadow:0 12px 32px rgba(11,87,164,0.14);outline:3px solid rgba(11,87,164,0.12)}
  .thumb .main-badge{position:absolute;right:6px;top:6px;background:var(--accent);color:#fff;padding:6px 8px;border-radius:8px;font-size:12px;z-index:3}
  .thumb.marked-delete{opacity:.54}
  .thumb.marked-delete::after{content:"Удалено";position:absolute;right:6px;top:6px;background:rgba(255,255,255,0.9);color:#b91c1c;padding:4px 6px;border-radius:6px;font-size:11px}
  .actions{margin-top:14px;display:flex;gap:10px;justify-content:flex-end}
  .btn-primary{background:linear-gradient(180deg,var(--accent),#074b82);color:#fff;padding:10px 14px;border-radius:10px;border:0;cursor:pointer;font-weight:700}
  .btn-ghost{background:#fff;border:1px solid #e6e9ef;padding:10px 12px;border-radius:10px;cursor:pointer}
  .muted{color:var(--muted);font-size:13px;margin-top:6px}
  .lightbox{position:fixed;left:0;top:0;right:0;bottom:0;display:flex;align-items:center;justify-content:center;background:rgba(2,6,23,0.75);z-index:9999;padding:24px;opacity:0;pointer-events:none;transition:opacity .18s ease;}
  .lightbox.open{opacity:1;pointer-events:auto}
  .lightbox-inner{background:transparent;border-radius:10px;padding:12px;max-width:98vw;max-height:98vh;display:flex;align-items:center;justify-content:center}
  .lightbox-inner img{max-width:95vw;max-height:95vh;width:auto;height:auto;display:block;border-radius:8px;object-fit:contain;cursor:zoom-in}
  .lightbox-close{position:absolute;right:18px;top:18px;background:rgba(255,255,255,0.95);border-radius:8px;padding:6px 8px;cursor:pointer;font-weight:700;border:0;z-index:10000}
</style>
</head>
<body>
<div class="container">
  <h2 style="margin:0 0 12px">Редактировать авто №<?= (int)$car['id'] ?></h2>

  <div class="card">
    <?php if (!empty($errors)): ?>
      <div style="background:#fff6f6;color:#9b1c1c;padding:10px;border-radius:8px;margin-bottom:10px"><?= h(implode('<br>',$errors)) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div style="background:#f0fdf4;color:#065f46;padding:10px;border-radius:8px;margin-bottom:10px"><?= h($success) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="editCarForm" class="layout">
      <!-- left: gallery -->
      <div>
        <label>Галерея</label>
        <div class="main-photo" id="mainPhotoContainer" title="Клик — увеличить">
          <?php $mainUrl = !empty($car['photo']) ? pub_url($car['photo']) : pub_url(''); ?>
          <img id="mainPhotoImg" src="<?= h($mainUrl) ?>" alt="Главное фото">
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:8px;">
          <div class="muted">Миниатюры — ★ сделать главным • ✕ пометить на удаление</div>
          <label class="btn-ghost" style="padding:6px 8px;font-size:13px;cursor:pointer;">
            Добавить фото
            <input id="uploadExtrasInput" type="file" name="photos[]" accept="image/*" multiple style="display:none">
          </label>
        </div>

        <div class="thumbs" id="thumbs">
          <?php foreach ($extraPhotos as $ep):
            $path = pub_url($ep['file_path']);
            $isMain = (!empty($car['photo']) && (rtrim($car['photo'],'/') === ltrim($ep['file_path'],'/')));
          ?>
            <div class="thumb <?= $isMain ? 'is-main' : '' ?>" data-id="<?= (int)$ep['id'] ?>" data-path="<?= h($path) ?>">
              <img src="<?= h($path) ?>" alt="Фото">
              <div class="controls">
                <span class="icon set-main" title="Сделать главным" role="button">★</span>
                <span class="icon del" title="Удалить" role="button">✕</span>
              </div>
              <?php if ($isMain): ?><div class="main-badge">Главное</div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <div style="margin-top:10px;">
          <label>Новые фото (предпросмотр)</label>
          <div id="newPreview" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;"></div>
        </div>
      </div>

      <!-- right: fields -->
      <div>
        <label>VIN</label>
        <input type="text" name="vin" value="<?= h($car['vin'] ?? '') ?>" required>

        <label>Пробег (км)</label>
        <input type="number" name="mileage" value="<?= h($car['mileage'] ?? '') ?>">

        <label>Цена (TMT)</label>
        <input type="number" step="0.01" name="price" value="<?= h($car['price'] ?? '') ?>">

        <label>Описание</label>
        <textarea name="description"><?= h($car['description'] ?? '') ?></textarea>

        <label style="margin-top:10px">Заменить основное фото (опционально)</label>
        <input type="file" name="main_photo" accept="image/*">

        <input type="hidden" name="set_main_existing" id="set_main_existing" value="">
        <div id="deleteInputsContainer"></div>

        <div class="actions">
          <a href="/mehanik/public/my-cars.php" class="btn-ghost" style="background:#fff">Отмена</a>
          <button type="submit" class="btn-primary">💾 Сохранить</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Lightbox -->
<div id="lightbox" class="lightbox" aria-hidden="true">
  <button id="lightboxClose" class="lightbox-close">✕</button>
  <div class="lightbox-inner" role="dialog" aria-modal="true">
    <img id="lightboxImg" src="" alt="Просмотр фото">
  </div>
</div>

<script>
(function(){
  const thumbs = document.getElementById('thumbs');
  const mainImg = document.getElementById('mainPhotoImg');
  const setMainInput = document.getElementById('set_main_existing');
  const deleteInputsContainer = document.getElementById('deleteInputsContainer');
  const newPreview = document.getElementById('newPreview');
  const uploadExtrasInput = document.getElementById('uploadExtrasInput');
  const noPhoto = '/mehanik/assets/no-photo.png';

  // Lightbox
  const lightbox = document.getElementById('lightbox');
  const lightboxImg = document.getElementById('lightboxImg');
  const lightboxClose = document.getElementById('lightboxClose');
  function openLightbox(src) {
    if (!src) return;
    lightboxImg.src = src;
    lightbox.classList.add('open');
    lightbox.setAttribute('aria-hidden','false');
  }
  function closeLightbox() {
    lightbox.classList.remove('open');
    lightbox.setAttribute('aria-hidden','true');
    lightboxImg.src = '';
    if (document.fullscreenElement) document.exitFullscreen().catch(()=>{});
  }
  lightboxClose.addEventListener('click', closeLightbox);
  lightbox.addEventListener('click', function(e){ if (e.target === lightbox) closeLightbox(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeLightbox(); });

  // helper: add main badge to thumb
  function ensureMainBadge(thumbEl) {
    if (!thumbEl) return;
    // remove existing main-badges elsewhere
    thumbs.querySelectorAll('.thumb .main-badge').forEach(n => n.remove());
    let badge = thumbEl.querySelector('.main-badge');
    if (!badge) {
      badge = document.createElement('div');
      badge.className = 'main-badge';
      badge.textContent = 'Главное';
      thumbEl.appendChild(badge);
    }
  }

  // move thumb to front of thumbs container
  function moveThumbToFront(thumbEl) {
    if (!thumbEl || !thumbs) return;
    thumbs.insertBefore(thumbEl, thumbs.firstChild);
  }

  // thumbnail interactions
  function removeDeleteInputForId(id) {
    if (!id) return;
    const inp = deleteInputsContainer.querySelector('input[value="'+id+'"]');
    if (inp) inp.remove();
    const thumb = thumbs.querySelector('.thumb[data-id="'+id+'"]');
    if (thumb) {
      thumb.classList.remove('marked-delete');
      const b = thumb.querySelector('.main-badge'); if (b) b.remove();
    }
  }

  function markThumbAsMain(thumbEl) {
    if (!thumbEl) return;
    const id = thumbEl.getAttribute('data-id');
    if (id) removeDeleteInputForId(id);

    // visual
    thumbs.querySelectorAll('.thumb').forEach(t => {
      t.classList.remove('is-main');
      const b = t.querySelector('.main-badge'); if (b) b.remove();
    });
    thumbEl.classList.add('is-main');
    ensureMainBadge(thumbEl);
    // move to front so thumbnails show it first
    moveThumbToFront(thumbEl);

    // set main preview
    const path = thumbEl.getAttribute('data-path');
    if (path) {
      mainImg.src = path;
      if (id) setMainInput.value = id; else setMainInput.value = path;
    } else {
      const img = thumbEl.querySelector('img');
      if (img && img.src) {
        mainImg.src = img.src;
        setMainInput.value = '';
      }
    }
  }

  function toggleDeleteForThumb(thumbEl) {
    const id = thumbEl.getAttribute('data-id');
    if (!id) return;
    const existing = deleteInputsContainer.querySelector('input[value="'+id+'"]');
    if (existing) {
      existing.remove();
      thumbEl.classList.remove('marked-delete');
    } else {
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'delete_photos[]';
      hidden.value = id;
      deleteInputsContainer.appendChild(hidden);
      thumbEl.classList.add('marked-delete');
      if (thumbEl.classList.contains('is-main')) {
        mainImg.src = noPhoto;
        setMainInput.value = '';
        thumbEl.classList.remove('is-main');
        const b = thumbEl.querySelector('.main-badge'); if (b) b.remove();
      }
    }
  }

  if (thumbs) {
    thumbs.addEventListener('click', function(e){
      const t = e.target;
      const thumb = t.closest('.thumb');
      if (!thumb) return;
      if (t.classList.contains('del') || t.closest('.del')) { toggleDeleteForThumb(thumb); return; }
      if (t.classList.contains('set-main') || t.closest('.set-main')) { markThumbAsMain(thumb); return; }
      // clicking thumb area sets main preview (but does not open lightbox)
      const path = thumb.getAttribute('data-path');
      if (path) {
        if (thumb.classList.contains('marked-delete')) {
          const id = thumb.getAttribute('data-id');
          const inp = deleteInputsContainer.querySelector('input[value="'+id+'"]');
          if (inp) inp.remove();
          thumb.classList.remove('marked-delete');
        }
        markThumbAsMain(thumb);
      }
    });

    // init: highlight current main if present and move to front
    const normalized = '<?= addslashes(pub_url($car['photo'] ?? '')) ?>';
    if (normalized) {
      // find by data-path
      const found = thumbs.querySelector('.thumb[data-path="'+normalized+'"]');
      if (found) {
        found.classList.add('is-main');
        ensureMainBadge(found);
        moveThumbToFront(found);
      }
    }
  }

  // Upload new extras preview
  if (uploadExtrasInput && newPreview) {
    uploadExtrasInput.addEventListener('change', function(){
      const files = Array.from(this.files || []);
      files.forEach((f) => {
        if (!f.type.startsWith('image/')) return;
        const fr = new FileReader();
        fr.onload = function(ev){
          const wrapper = document.createElement('div');
          wrapper.style.width = '120px';
          wrapper.style.height = '86px';
          wrapper.style.borderRadius = '8px';
          wrapper.style.overflow = 'hidden';
          wrapper.style.background = '#fff';
          wrapper.style.position = 'relative';
          wrapper.style.display = 'flex';
          wrapper.style.alignItems = 'center';
          wrapper.style.justifyContent = 'center';

          const img = document.createElement('img');
          img.src = ev.target.result;
          img.style.width = '100%';
          img.style.height = '100%';
          img.style.objectFit = 'cover';
          wrapper.appendChild(img);

          const ctr = document.createElement('div');
          ctr.style.position = 'absolute';
          ctr.style.left = '6px';
          ctr.style.bottom = '6px';
          ctr.style.display = 'flex';
          ctr.style.gap = '6px';

          const btnMain = document.createElement('button');
          btnMain.type = 'button';
          btnMain.textContent = '★';
          btnMain.title = 'Сделать главным (временно)';
          btnMain.style.padding='6px'; btnMain.style.borderRadius='6px'; btnMain.style.background='rgba(0,0,0,0.55)'; btnMain.style.color='#fff'; btnMain.style.border='0';
          const btnDel = document.createElement('button');
          btnDel.type = 'button';
          btnDel.textContent = '✕';
          btnDel.title = 'Удалить';
          btnDel.style.padding='6px'; btnDel.style.borderRadius='6px'; btnDel.style.background='rgba(255,50,50,0.9)'; btnDel.style.color='#fff'; btnDel.style.border='0';

          ctr.appendChild(btnMain); ctr.appendChild(btnDel);
          wrapper.appendChild(ctr);

          btnDel.addEventListener('click', ()=> wrapper.remove());
          btnMain.addEventListener('click', ()=> {
            mainImg.src = img.src;
            setMainInput.value = ''; // choosing new-uploaded as main — server will see new file in photos[]; we don't currently map index -> server. If you want to support choosing a new uploaded file as main on server, we can add main_new_index logic.
          });

          newPreview.appendChild(wrapper);
        };
        fr.readAsDataURL(f);
      });
    });
  }

  // Clicking main image opens lightbox
  if (mainImg) {
    mainImg.addEventListener('click', function(){
      const src = mainImg.src || noPhoto;
      if (src) openLightbox(src);
    });
  }

  // Lightbox image click toggles fullscreen
  const lbImg = document.getElementById('lightboxImg');
  lbImg.addEventListener('click', function(e){
    e.stopPropagation();
    const el = lbImg;
    if (document.fullscreenElement) { document.exitFullscreen().catch(()=>{}); }
    else if (el.requestFullscreen) { el.requestFullscreen().catch(()=>{}); }
  });

  // Ensure when form submits: if a thumb is marked main, pass its id as set_main_existing; if it's marked for deletion, clear
  const form = document.getElementById('editCarForm');
  form.addEventListener('submit', function(){
    const mainThumb = thumbs ? thumbs.querySelector('.thumb.is-main') : null;
    if (mainThumb) {
      const del = mainThumb.classList.contains('marked-delete');
      if (del) setMainInput.value = '';
      else {
        const id = mainThumb.getAttribute('data-id');
        if (id) setMainInput.value = id;
      }
    }
  });

})();
</script>
</body>
</html>
