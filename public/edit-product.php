<?php
// public/edit-product.php
require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php';

// Получаем ID продукта
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die("Неверный ID продукта");
}

// Загружаем товар (только если существует)
$stmt = $mysqli->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    die("Продукт не найден");
}

// Доп. проверка: только владелец или админ может редактировать
$currentUserId = $_SESSION['user']['id'] ?? null;
$currentRole = $_SESSION['user']['role'] ?? null;
if (!($currentRole === 'admin' || ($currentUserId !== null && (int)$currentUserId === (int)$product['user_id']))) {
    http_response_code(403);
    die("У вас нет прав для редактирования этого товара.");
}

/*
 * Получаем дополнительные фото
 */
$extraPhotos = [];
$hasProductPhotosTable = false;
try {
    $res = $mysqli->query("SHOW TABLES LIKE 'product_photos'");
    if ($res && $res->num_rows > 0) {
        $hasProductPhotosTable = true;
        $stmtp = $mysqli->prepare("SELECT id, file_path FROM product_photos WHERE product_id = ? ORDER BY id ASC");
        if ($stmtp) {
            $stmtp->bind_param('i', $id);
            $stmtp->execute();
            $r = $stmtp->get_result();
            while ($row = $r->fetch_assoc()) {
                if (!empty($row['file_path'])) $extraPhotos[] = $row;
            }
            $stmtp->close();
        }
    }
    if ($res) $res->free();
} catch (Throwable $_) {
    // ignore
}

// Deduplicate extraPhotos by file_path (just in case)
$seen = [];
$extraPhotosDedup = [];
foreach ($extraPhotos as $p) {
    if (empty($p['file_path'])) continue;
    if (isset($seen[$p['file_path']])) continue;
    $seen[$p['file_path']] = true;
    $extraPhotosDedup[] = $p;
}
$extraPhotos = $extraPhotosDedup;

// Подготовим отображаемый SKU (без префикса SKU-)
$rawSku = trim((string)($product['sku'] ?? ''));
$displaySku = $rawSku === '' ? '' : preg_replace('/^SKU-/i', '', $rawSku);
$productUrl = '/mehanik/public/product.php?id=' . urlencode($id);

// Настройки загрузки / пути
$uploadBaseDir = __DIR__ . '/../uploads/products/';
$publicPrefix = '/mehanik/uploads/products/';
$allowedMimes = ['image/jpeg','image/png','image/webp'];
$maxSize = 6 * 1024 * 1024; // 6 MB per file
if (!is_dir($uploadBaseDir)) @mkdir($uploadBaseDir, 0755, true);

// Обработка сохранения
$errors = [];
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Входные данные (защищённо)
    $name = trim($_POST['name'] ?? '');
    $manufacturer = trim($_POST['manufacturer'] ?? '');
    $quality = trim($_POST['quality'] ?? '');
    $rating = isset($_POST['rating']) ? (float)$_POST['rating'] : 0.0;
    $availability = isset($_POST['availability']) ? (int)$_POST['availability'] : 0;
    $price = isset($_POST['price']) ? (float)$_POST['price'] : 0.0;
    $description = trim($_POST['description'] ?? '');

    // delivery fields
    $delivery = isset($_POST['delivery']) && ($_POST['delivery'] === '1' || $_POST['delivery'] === 'on') ? 1 : 0;
    $delivery_price = isset($_POST['delivery_price']) ? (float)$_POST['delivery_price'] : 0.0;
    if ($delivery && $delivery_price < 0) $delivery_price = 0.0;

    if ($name === '') {
        $errors[] = 'Название не может быть пустым.';
    }

    // массив id дополнительных фото на удаление
    $delete_photos = [];
    if (!empty($_POST['delete_photos']) && is_array($_POST['delete_photos'])) {
        foreach ($_POST['delete_photos'] as $dp) {
            $dp = (int)$dp;
            if ($dp > 0) $delete_photos[] = $dp;
        }
    }

    // если пользователь выбрал существующую дополнительную фото как главное (передаём id)
    $set_main_existing = trim((string)($_POST['set_main_existing'] ?? ''));

    // Начинаем транзакцию
    $mysqli->begin_transaction();
    try {
        // ensure product folder exists
        $prodDir = $uploadBaseDir . $id;
        if (!is_dir($prodDir)) {
            if (!mkdir($prodDir, 0755, true)) throw new Exception('Не удалось создать папку для фотографий продукта.');
        }

        // 1) Замена/загрузка основного фото (приоритет)
        $newMainWebPath = $product['photo']; // по умолчанию
        if (isset($_FILES['photo']) && !empty($_FILES['photo']['name'])) {
            $file = $_FILES['photo'];
            if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('Ошибка загрузки основного файла.');
            if ($file['size'] > $maxSize) throw new Exception('Основной файл слишком большой (макс 6MB).');
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, $allowedMimes, true)) throw new Exception('Недопустимый формат основного файла.');

            $ext = 'jpg';
            if ($mime === 'image/png') $ext = 'png';
            if ($mime === 'image/webp') $ext = 'webp';

            $finalName = 'main_' . $id . '.' . $ext;
            $finalAbs = $prodDir . '/' . $finalName;
            if (!move_uploaded_file($file['tmp_name'], $finalAbs)) throw new Exception('Не удалось сохранить основное фото.');

            // удаляем старый главный (если он внутри нашей папки)
            if (!empty($product['photo'])) {
                $old = $product['photo'];
                $oldAbs = null;
                if (strpos($old, $publicPrefix) === 0) {
                    $oldRel = substr($old, strlen($publicPrefix));
                    $oldAbs = $uploadBaseDir . $oldRel;
                } elseif (strpos($old, '/') === 0) {
                    $oldAbs = __DIR__ . '/..' . $old;
                } else {
                    $oldAbs = $uploadBaseDir . $old;
                }
                if ($oldAbs && is_file($oldAbs)) @unlink($oldAbs);
            }

            $newMainWebPath = $publicPrefix . $id . '/' . $finalName;
        } elseif ($set_main_existing !== '') {
            // если выбрана существующая доп. фотография как главный — проверим, что она действительно принадлежит продукту
            $candidate = null;
            if ($hasProductPhotosTable) {
                $asId = is_numeric($set_main_existing) ? (int)$set_main_existing : 0;
                if ($asId > 0) {
                    $stc = $mysqli->prepare("SELECT file_path FROM product_photos WHERE product_id = ? AND id = ? LIMIT 1");
                    if ($stc) {
                        $stc->bind_param('ii', $id, $asId);
                        $stc->execute();
                        $cres = $stc->get_result()->fetch_assoc();
                        $stc->close();
                        if ($cres && !empty($cres['file_path'])) $candidate = $cres['file_path'];
                    }
                }
            }
            if ($candidate) {
                $newMainWebPath = $candidate;
            }
        }

        // 2) Удаление выбранных дополнительных фото (файлы + записи)
        if (!empty($delete_photos)) {
            $delStmt = $mysqli->prepare("DELETE FROM product_photos WHERE id = ? AND product_id = ?");
            if (!$delStmt) throw new Exception('Prepare delete failed: ' . $mysqli->error);
            foreach ($delete_photos as $delId) {
                // получим file_path для удаления файла
                $stg = $mysqli->prepare("SELECT file_path FROM product_photos WHERE id = ? AND product_id = ? LIMIT 1");
                if (!$stg) continue;
                $stg->bind_param('ii', $delId, $id);
                $stg->execute();
                $row = $stg->get_result()->fetch_assoc();
                $stg->close();
                if (!$row) continue;
                $fp = $row['file_path'];
                // убираем файл физически
                if ($fp) {
                    $abs = null;
                    if (strpos($fp, $publicPrefix) === 0) {
                        $rel = substr($fp, strlen($publicPrefix));
                        $abs = $uploadBaseDir . $rel;
                    } elseif (strpos($fp, '/') === 0) {
                        $abs = __DIR__ . '/..' . $fp;
                    } else {
                        $abs = $uploadBaseDir . $fp;
                    }
                    if ($abs && is_file($abs)) @unlink($abs);
                }
                // удаление записи
                $delStmt->bind_param('ii', $delId, $id);
                $delStmt->execute();
                // если удалили файл, и он был главным — сбросим главный
                if (!empty($product['photo']) && $fp && $product['photo'] === $fp) {
                    $newMainWebPath = null;
                }
                // если пользователь выбрал в set_main_existing именно файл который удаляется — ignore
                if ($set_main_existing && ($set_main_existing == $delId)) {
                    $set_main_existing = '';
                }
            }
            $delStmt->close();
        }

        // 3) Добавляем дополнительные фотографии (photos[])
        $existingHashes = [];
        $existingFiles = [];
        if (is_dir($prodDir)) {
            $dir = scandir($prodDir);
            foreach ($dir as $f) {
                if ($f === '.' || $f === '..') continue;
                $abs = $prodDir . '/' . $f;
                if (!is_file($abs)) continue;
                $h = @md5_file($abs);
                if ($h) {
                    $existingHashes[$h] = $f;
                    $existingFiles[$f] = $abs;
                }
            }
        }

        if (!empty($_FILES['photos']['name']) && is_array($_FILES['photos']['name'])) {
            // ensure product_photos table exists
            $check = $mysqli->query("SHOW TABLES LIKE 'product_photos'");
            if (!$check || $check->num_rows === 0) {
                $mysqli->query("\
                  CREATE TABLE IF NOT EXISTS product_photos (\n                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n                    product_id INT NOT NULL,\n                    file_path VARCHAR(255) NOT NULL,\n                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,\n                    INDEX (product_id),\n                    CONSTRAINT fk_product_photos_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE\n                  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n                ");
            }
            $count = count($_FILES['photos']['name']);
            for ($i=0;$i<$count;$i++){
                if (!is_uploaded_file($_FILES['photos']['tmp_name'][$i])) continue;
                if ($_FILES['photos']['size'][$i] > $maxSize) throw new Exception('Один из дополнительных файлов слишком большой');
                $tmp = $_FILES['photos']['tmp_name'][$i];
                $mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmp);
                if (!in_array($mime, $allowedMimes, true)) throw new Exception('Неподдерживаемый формат одного из дополнительных фото');
                $ext = 'jpg';
                if ($mime === 'image/png') $ext = 'png';
                if ($mime === 'image/webp') $ext = 'webp';

                // compute md5 of uploaded tmp to check duplicates
                $md5tmp = @md5_file($tmp);
                if ($md5tmp && isset($existingHashes[$md5tmp])) {
                    // duplicate content — skip
                    continue;
                }

                // uniq name
                $uniq = preg_replace('/[^a-z0-9]+/i','', uniqid('p', true));
                $finalName = 'extra_' . $id . '_' . $uniq . '.' . $ext;
                $finalAbs = $prodDir . '/' . $finalName;
                if (!move_uploaded_file($tmp, $finalAbs)) throw new Exception('Не удалось сохранить одно из дополнительных фото');
                // compute md5 and add to existingHashes
                $md5new = @md5_file($finalAbs);
                if ($md5new) $existingHashes[$md5new] = $finalName;

                $webPath = $publicPrefix . $id . '/' . $finalName;

                // avoid inserting duplicate file_path in DB (in case)
                $stdup = $mysqli->prepare("SELECT id FROM product_photos WHERE product_id = ? AND file_path = ? LIMIT 1");
                $skip = false;
                if ($stdup) {
                    $stdup->bind_param('is', $id, $webPath);
                    $stdup->execute();
                    $rr = $stdup->get_result()->fetch_assoc();
                    $stdup->close();
                    if ($rr) $skip = true;
                }
                if ($skip) {
                    if (is_file($finalAbs)) @unlink($finalAbs);
                    continue;
                }

                $stins = $mysqli->prepare("INSERT INTO product_photos (product_id, file_path) VALUES (?, ?)");
                if (!$stins) throw new Exception('Prepare insert extra photo failed: ' . $mysqli->error);
                $stins->bind_param('is', $id, $webPath);
                if (!$stins->execute()) throw new Exception('Insert extra photo failed: ' . $stins->error);
                $stins->close();
            }
        }

        // 4) Обновление логотипа, если загружен
        $logoWeb = $product['logo'] ?? null;
        if (isset($_FILES['logo']) && !empty($_FILES['logo']['name'])) {
            $file = $_FILES['logo'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                if ($file['size'] <= $maxSize) {
                    $mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']);
                    if (in_array($mime, $allowedMimes, true)) {
                        $ext = 'jpg';
                        if ($mime === 'image/png') $ext = 'png';
                        if ($mime === 'image/webp') $ext = 'webp';
                        $finalLogoName = 'logo_' . $id . '.' . $ext;
                        $finalLogoAbs = $prodDir . '/' . $finalLogoName;
                        if (move_uploaded_file($file['tmp_name'], $finalLogoAbs)) {
                            // remove old logo file if inside folder
                            if (!empty($product['logo'])) {
                                $old = $product['logo'];
                                $oldAbs = null;
                                if (strpos($old, $publicPrefix) === 0) {
                                    $oldRel = substr($old, strlen($publicPrefix));
                                    $oldAbs = $uploadBaseDir . $oldRel;
                                } elseif (strpos($old, '/') === 0) {
                                    $oldAbs = __DIR__ . '/..' . $old;
                                } else {
                                    $oldAbs = $uploadBaseDir . $old;
                                }
                                if ($oldAbs && is_file($oldAbs)) @unlink($oldAbs);
                            }
                            $logoWeb = $publicPrefix . $id . '/' . $finalLogoName;
                        }
                    }
                }
            }
        }

        // 5) Обновление основной записи products
        if ($set_main_existing !== '') {
            // try fetch by id again to be safe
            $candidate = null;
            $asId = is_numeric($set_main_existing) ? (int)$set_main_existing : 0;
            if ($asId > 0 && $hasProductPhotosTable) {
                $stc = $mysqli->prepare("SELECT file_path FROM product_photos WHERE product_id = ? AND id = ? LIMIT 1");
                if ($stc) {
                    $stc->bind_param('ii', $id, $asId);
                    $stc->execute();
                    $cres = $stc->get_result()->fetch_assoc();
                    $stc->close();
                    if ($cres && !empty($cres['file_path'])) $candidate = $cres['file_path'];
                }
            }
            if ($candidate) $newMainWebPath = $candidate;
        }

        // If newMainWebPath points to file inside product folder but doesn't exist (deleted earlier), set to null
        if (!empty($newMainWebPath) && strpos($newMainWebPath, $publicPrefix) === 0) {
            $rel = substr($newMainWebPath, strlen($publicPrefix));
            $abs = $uploadBaseDir . $rel;
            if (!is_file($abs)) {
                $newMainWebPath = null;
            }
        }

        // finally update products row
        $upd = $mysqli->prepare("\n            UPDATE products\n            SET name = ?, manufacturer = ?, quality = ?, rating = ?, availability = ?, price = ?, description = ?, photo = ?, logo = ?, delivery = ?, delivery_price = ?, status = 'pending'\n            WHERE id = ?\n        ");
        if (!$upd) throw new Exception('Ошибка подготовки запроса: ' . $mysqli->error);

        $upd->bind_param(
            "sssdidsssidi",
            $name,
            $manufacturer,
            $quality,
            $rating,
            $availability,
            $price,
            $description,
            $newMainWebPath,
            $logoWeb,
            $delivery,
            $delivery_price,
            $id
        );
        if (!$upd->execute()) throw new Exception('Ошибка сохранения: ' . $upd->error);
        $upd->close();

        $mysqli->commit();
        $success = true;

        // reload product & extras
        $stmt = $mysqli->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $extraPhotos = [];
        if ($hasProductPhotosTable) {
            $stmtp = $mysqli->prepare("SELECT id, file_path FROM product_photos WHERE product_id = ? ORDER BY id ASC");
            if ($stmtp) {
                $stmtp->bind_param('i', $id);
                $stmtp->execute();
                $r = $stmtp->get_result();
                while ($row = $r->fetch_assoc()) {
                    if (!empty($row['file_path'])) $extraPhotos[] = $row;
                }
                $stmtp->close();
            }
        }

    } catch (Throwable $e) {
        $mysqli->rollback();
        $errors[] = 'Ошибка при сохранении: ' . $e->getMessage();
    }
}

// Подключаем header (публичный)
require_once __DIR__ . '/header.php';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Редактировать товар — <?= htmlspecialchars($product['name'] ?? '') ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/mehanik/assets/css/header.css">
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    :root{
      --bg: #f6f8fb;
      --card: #fff;
      --muted: #6b7280;
      --accent: #0b57a4;
      --radius: 12px;
    }
    body { background: var(--bg); }
    .container { max-width:1100px; margin:28px auto; padding:12px; font-family:system-ui,Arial,sans-serif; color:#0f172a; }
    .top { display:flex; align-items:center; gap:12px; justify-content:space-between; margin-bottom:14px; }
    h2 { margin:0; font-size:1.25rem; }

    .layout { display:grid; grid-template-columns: 480px 1fr; gap:18px; align-items:start; }
    @media(max-width:980px){ .layout{ grid-template-columns: 1fr; } }

    .card { background:var(--card); padding:16px; border-radius:var(--radius); box-shadow:0 10px 30px rgba(2,6,23,0.06); }

    /* Gallery */
    .gallery { display:flex; flex-direction:column; gap:12px; }
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

    /* main image fills the frame (cover) */
    .thumbs { display:flex; gap:8px; overflow-x:auto; padding-bottom:6px; }
    .thumb { width:120px; height:86px; flex:0 0 auto; border-radius:8px; overflow:hidden; position:relative; background:#fff; border:1px solid #e9eef6; box-shadow:0 6px 18px rgba(2,6,23,0.03); cursor:pointer; display:flex; align-items:center; justify-content:center; transition:transform .12s ease, opacity .12s ease; }
    .thumb:hover { transform:translateY(-4px); }
    .thumb img { width:100%; height:100%; object-fit:cover; display:block; }
    .thumb .controls { position:absolute; left:6px; bottom:6px; display:flex; gap:6px; z-index:2; }
    .thumb .icon { background:rgba(0,0,0,0.55); color:#fff; padding:6px; border-radius:6px; font-size:12px; display:inline-flex; align-items:center; justify-content:center; user-select:none; }
    /* order badge */
    .thumb .order-badge {
      background: rgba(0,0,0,0.65);
      color: #fff;
      min-width:26px;
      height:26px;
      line-height:18px;
      padding:0 6px;
      border-radius:14px;
      font-size:13px;
      font-weight:700;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      box-shadow: 0 6px 18px rgba(2,6,23,0.12);
    }
    .thumb .del { background:rgba(255,50,50,0.9); }
    .thumb.is-main { box-shadow:0 12px 32px rgba(11,87,164,0.14); outline: 3px solid rgba(11,87,164,0.12); }
    .thumb.is-main .order-badge { background: var(--accent); }

    /* marked delete visual */
    .thumb.marked-delete { opacity: .54; }
    .thumb.marked-delete .del { background:#ef4444 !important; box-shadow:0 6px 18px rgba(239,68,68,0.14); }
    .thumb.marked-delete::after { content: "Удалено"; position:absolute; right:6px; top:6px; background:rgba(255,255,255,0.9); color:#b91c1c; padding:4px 6px; border-radius:6px; font-size:11px; }

    /* form */
    label { display:block; font-weight:700; margin-top:10px; color:#0f172a; }
    input[type="text"], input[type="number"], select, textarea { width:100%; padding:10px 12px; border-radius:8px; border:1px solid #e6e9ef; box-sizing:border-box; font-size:14px; }
    textarea { min-height:120px; }

    .row { display:flex; gap:12px; align-items:center; margin-top:8px; }
    .col-2 { display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
    .muted { color:var(--muted); font-size:13px; margin-top:6px; }

    .actions { margin-top:14px; display:flex; gap:10px; flex-wrap:wrap; }
    .btn-primary { background:linear-gradient(180deg,var(--accent),#074b82); color:#fff; padding:10px 14px; border-radius:10px; border:0; cursor:pointer; font-weight:700; }
    .btn-ghost { background:#fff; border:1px solid #e6e9ef; padding:10px 12px; border-radius:10px; cursor:pointer; }

    .extra-card { background:#fff; border-radius:8px; overflow:hidden; border:1px solid #f0f3f8; position:relative; }
    .extra-card img { width:100%; height:120px; object-fit:cover; display:block; }
    .checkbox-del { display:flex; gap:6px; align-items:center; }

    .errors { background:#fff6f6; color:#9b1c1c; padding:10px; border-radius:8px; margin-bottom:10px; }
    .okmsg { background:#f0fdf4; color:#065f46; padding:10px; border-radius:8px; margin-bottom:10px; }

    /* Lightbox - увеличенный просмотр */
    .lightbox { position:fixed; left:0; top:0; right:0; bottom:0; display:flex; align-items:center; justify-content:center; background:rgba(2,6,23,0.75); z-index:9999; padding:24px; opacity:0; pointer-events:none; transition:opacity .18s ease; }
    .lightbox.open { opacity:1; pointer-events:auto; }
    .lightbox-inner { background:transparent; border-radius:10px; padding:12px; max-width:98vw; max-height:98vh; box-shadow:0 20px 60px rgba(2,6,23,0.6); display:flex; align-items:center; justify-content:center; }
    .lightbox-inner img { max-width:95vw; max-height:95vh; width:auto; height:auto; display:block; border-radius:8px; object-fit:contain; cursor:zoom-in; }
    .lightbox-close { position:absolute; right:18px; top:18px; background:rgba(255,255,255,0.95); border-radius:8px; padding:6px 8px; cursor:pointer; font-weight:700; border:0; z-index:10000; }
  </style>
</head>
<body>
<div class="container">
  <div class="top">
    <h2>Редактировать товар</h2>
    <div>
      <a href="/mehanik/public/product.php?id=<?= (int)$id ?>" class="btn-ghost">Просмотр</a>
      <a href="/mehanik/public/index.php" class="btn-ghost">Каталог</a>
    </div>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="errors">
      <ul style="margin:0 0 0 18px;padding:0;">
        <?php foreach ($errors as $err): ?>
          <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="okmsg">Изменения успешно сохранены. Статус товара поставлен на модерацию.</div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="layout card" id="editProductForm">
    <!-- LEFT: gallery -->
    <div>
      <div class="gallery">
        <label>Галерея</label>
        <div class="main-photo" id="mainPhotoContainer" title="Клик — увеличить">
          <?php if (!empty($product['photo'])): ?>
            <img id="mainPhotoImg" src="<?= htmlspecialchars($product['photo']) ?>" alt="Главное фото">
          <?php else: ?>
            <img id="mainPhotoImg" src="/mehanik/assets/no-photo.png" alt="Нет фото" style="opacity:.7">
          <?php endif; ?>
        </div>

        <div style="display:flex; align-items:center; justify-content:space-between;">
          <div class="muted">Миниатюры — № поставить главным • ✕ пометить на удаление</div>
          <label class="btn-ghost" style="padding:6px 8px; font-size:13px;">
            Добавить фото
            <input type="file" name="photos[]" accept="image/*" multiple style="display:none" id="uploadExtrasInput">
          </label>
        </div>

        <div class="thumbs" id="thumbs">
          <?php
            // determine which of extraPhotos is currently the main (if main located in extras)
            $currentMainPath = $product['photo'] ?? '';
          ?>
          <?php $idx = 1; ?>
          <?php foreach ($extraPhotos as $ep):
              $isMain = ($currentMainPath !== '' && $currentMainPath === $ep['file_path']);
          ?>
            <div class="thumb <?= $isMain ? 'is-main' : '' ?>" data-id="<?= (int)$ep['id'] ?>" data-path="<?= htmlspecialchars($ep['file_path']) ?>">
              <img src="<?= htmlspecialchars($ep['file_path']) ?>" alt="Фото">
              <div class="controls">
                <span class="icon order-badge" title="Сделать главным" role="button" data-order="<?= $idx ?>"><?= $idx ?></span>
                <span class="icon del" title="Удалить" role="button">✕</span>
              </div>
            </div>
          <?php $idx++; endforeach; ?>
        </div>

        <?php if (!empty($extraPhotos)): ?>
          <div class="muted" style="margin-top:6px;">Вы можете кликнуть по номеру, чтобы сделать миниатюру главным. Кнопка корзины пометит изображение на удаление.</div>
        <?php else: ?>
          <div class="muted" style="margin-top:6px;">Дополнительных фото пока нет.</div>
        <?php endif; ?>

        <div style="margin-top:8px;">
          <label>Новые фото (предпросмотр)</label>
          <div id="newPreview" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;"></div>
        </div>
      </div>
    </div>

    <!-- RIGHT: form -->
    <div>
      <label>Артикул</label>
      <?php if ($displaySku !== ''): ?>
        <div style="display:flex;gap:8px;align-items:center;">
          <a class="sku-text" id="skuLink" href="<?= htmlspecialchars($productUrl) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($displaySku) ?></a>
          <button type="button" id="copySkuBtn" class="btn-ghost">📋</button>
        </div>
      <?php else: ?>
        <div class="muted">Артикул отсутствует</div>
      <?php endif; ?>

      <label>Название</label>
      <input type="text" name="name" value="<?= htmlspecialchars($product['name']) ?>" required>

      <label>Производитель</label>
      <input type="text" name="manufacturer" value="<?= htmlspecialchars($product['manufacturer']) ?>">

      <div class="col-2">
        <div>
          <label>Состояние</label>
          <select name="quality">
            <option value="New" <?= ($product['quality'] === 'New' || $product['quality'] === 'Новый') ? 'selected' : '' ?>>Новый</option>
            <option value="Used" <?= ($product['quality'] === 'Used' || $product['quality'] === 'Б/У') ? 'selected' : '' ?>>Б/У</option>
          </select>
        </div>
        <div>
          <label>Качество</label>
          <input type="number" step="0.1" min="0" max="10" name="rating" value="<?= htmlspecialchars($product['rating']) ?>">
        </div>
      </div>

      <div class="col-2">
        <div>
          <label>Наличие (шт)</label>
          <input type="number" name="availability" value="<?= htmlspecialchars($product['availability']) ?>">
        </div>
        <div>
          <label>Цена (TMT)</label>
          <input type="number" step="0.01" name="price" value="<?= htmlspecialchars($product['price']) ?>">
        </div>
      </div>

      <label>Описание</label>
      <textarea name="description"><?= htmlspecialchars($product['description']) ?></textarea>

      <label style="margin-top:10px;">Доставка</label>
      <div style="display:flex;gap:12px;align-items:center;">
        <label style="display:flex;gap:8px;align-items:center;">
          <input type="checkbox" name="delivery" value="1" <?= (!empty($product['delivery']) && $product['delivery']) ? 'checked' : '' ?> id="deliveryCheckbox"> Есть доставка
        </label>
        <input type="number" step="0.01" name="delivery_price" id="deliveryPriceInput" placeholder="Цена доставки" value="<?= htmlspecialchars($product['delivery_price'] ?? '') ?>">
      </div>

      <div style="margin-top:12px;">
        <label>Логотип (опционально)</label>
        <?php if (!empty($product['logo'])): ?>
          <div style="max-width:160px;margin-top:8px;">
            <img id="logoImg" src="<?= htmlspecialchars($product['logo']) ?>" alt="Логотип" style="height:64px; object-fit:contain; border-radius:6px; border:1px solid #eef3fb; cursor:pointer;">
          </div>
        <?php endif; ?>
        <input type="file" name="logo" accept="image/*">
        <div class="muted">Загрузите логотип, он заменит текущий.</div>
      </div>

      <!-- скрытые поля: какую дополнительную фото сделать главной (id), и скрытые поля для удаления -->
      <input type="hidden" name="set_main_existing" id="set_main_existing" value="">
      <div id="deleteInputsContainer"></div>

      <div class="actions">
        <button type="submit" class="btn-primary">💾 Сохранить</button>
        <a href="/mehanik/public/product.php?id=<?= (int)$id ?>" class="btn-ghost">Отмена</a>
      </div>

      <div class="muted" style="margin-top:10px;">После изменения статус товара будет установлен в <strong>На модерации</strong>.</div>
    </div>
  </form>
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
  // copy SKU
  const copyBtn = document.getElementById('copySkuBtn');
  const skuLink = document.getElementById('skuLink');
  if (copyBtn && skuLink) {
    copyBtn.addEventListener('click', function(){
      const text = skuLink.textContent.trim();
      if (!text) return;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(()=> {
          const prev = copyBtn.textContent;
          copyBtn.textContent = '✓';
          setTimeout(()=> copyBtn.textContent = prev, 1200);
        }).catch(()=> alert('Копирование не поддерживается'));
      } else {
        alert('Копирование не поддерживается');
      }
    });
  }

  const thumbs = document.getElementById('thumbs');
  const mainImg = document.getElementById('mainPhotoImg');
  const setMainInput = document.getElementById('set_main_existing');
  const deleteInputsContainer = document.getElementById('deleteInputsContainer');
  const noPhoto = '/mehanik/assets/no-photo.png';

  // Lightbox elements
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
    // exit fullscreen if still in it
    if (document.fullscreenElement) {
      document.exitFullscreen().catch(()=>{});
    }
  }
  lightboxClose.addEventListener('click', closeLightbox);
  // close only when click on overlay (not on image)
  lightbox.addEventListener('click', function(e){
    if (e.target === lightbox) closeLightbox();
  });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeLightbox();
  });

  // mark thumb as main (visual + hidden input)
  function removeDeleteInputForId(id) {
    if (!id) return;
    const inp = deleteInputsContainer.querySelector('input[value="'+id+'"]');
    if (inp) inp.remove();
    const thumb = thumbs.querySelector('.thumb[data-id="'+id+'"]');
    if (thumb) thumb.classList.remove('marked-delete');
  }

  // helper: обновить цифры бейджей — текущему main присвоить 1, остальные 2,3,...
  function updateOrderBadges() {
    if (!thumbs) return;
    const all = Array.from(thumbs.querySelectorAll('.thumb'));
    const main = thumbs.querySelector('.thumb.is-main');
    let order = 2;
    all.forEach(t => {
      const badge = t.querySelector('.order-badge');
      if (!badge) return;
      if (t === main) {
        badge.textContent = '1';
        badge.setAttribute('data-order', '1');
      } else {
        badge.textContent = String(order);
        badge.setAttribute('data-order', String(order));
        order++;
      }
    });
  }

  function markThumbAsMain(thumbEl) {
    if (!thumbEl) return;
    // unmark delete if present for this thumb
    const id = thumbEl.getAttribute('data-id');
    if (id) removeDeleteInputForId(id);

    // remove main from others
    thumbs.querySelectorAll('.thumb').forEach(t => t.classList.remove('is-main'));
    thumbEl.classList.add('is-main');

    const path = thumbEl.getAttribute('data-path');
    if (path) {
      mainImg.src = path;
      // set hidden input to id (server expects id)
      const idVal = thumbEl.getAttribute('data-id');
      if (idVal) setMainInput.value = idVal;
      else setMainInput.value = path;
    } else {
      // fallback to img src
      const img = thumbEl.querySelector('img');
      if (img && img.src) {
        mainImg.src = img.src;
        setMainInput.value = '';
      }
    }

    // после установки main — обновить бейджи
    updateOrderBadges();
  }

  // toggle delete mark
  function toggleDeleteForThumb(thumbEl) {
    const id = thumbEl.getAttribute('data-id');
    if (!id) return;
    const existing = deleteInputsContainer.querySelector('input[value="'+id+'"]');
    if (existing) {
      // unmark deletion
      existing.remove();
      thumbEl.classList.remove('marked-delete');
    } else {
      // add hidden input
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'delete_photos[]';
      hidden.value = id;
      deleteInputsContainer.appendChild(hidden);
      thumbEl.classList.add('marked-delete');

      // if this thumb was marked as main, clear main preview & hidden main input
      if (thumbEl.classList.contains('is-main')) {
        mainImg.src = noPhoto;
        setMainInput.value = '';
        thumbEl.classList.remove('is-main');
      }
    }
  }

  if (thumbs) {
    thumbs.addEventListener('click', function(e){
      const t = e.target;
      const thumb = t.closest('.thumb');
      if (!thumb) return;

      // delete icon clicked
      if (t.classList.contains('del') || t.closest('.del')) {
        toggleDeleteForThumb(thumb);
        // после пометки удаления — обновим бейджы
        updateOrderBadges();
        return;
      }

      // order-badge clicked -> делаем главным и переставляем номера
      if (t.classList.contains('order-badge') || t.closest('.order-badge')) {
        markThumbAsMain(thumb);
        return;
      }

      // clicking on thumb area -> insert image into main preview (NOT open lightbox)
      const path = thumb.getAttribute('data-path');
      if (path) {
        // if thumb is currently marked-delete, unmark deletion automatically when user wants to view/set it
        if (thumb.classList.contains('marked-delete')) {
          // remove delete input and class
          const id = thumb.getAttribute('data-id');
          const inp = deleteInputsContainer.querySelector('input[value="'+id+'"]');
          if (inp) inp.remove();
          thumb.classList.remove('marked-delete');
        }
        markThumbAsMain(thumb);
      } else {
        // fallback: use img src
        const img = thumb.querySelector('img');
        if (img && img.src) {
          // create a temporary thumb-like behaviour: set main image
          mainImg.src = img.src;
          setMainInput.value = '';
          thumbs.querySelectorAll('.thumb').forEach(tn => tn.classList.remove('is-main'));
        }
      }
    });

    // initialize highlight for current main if present (server-side indicated)
    const currentMainPath = '<?= addslashes($product['photo'] ?? '') ?>';
    if (currentMainPath) {
      const found = thumbs.querySelector('.thumb[data-path="'+currentMainPath+'"]');
      if (found) found.classList.add('is-main');
    }

    // set initial badges correctly on load
    updateOrderBadges();
  }

  // upload new extras preview
  const uploadExtrasInput = document.getElementById('uploadExtrasInput');
  const newPreview = document.getElementById('newPreview');
  if (uploadExtrasInput && newPreview) {
    uploadExtrasInput.addEventListener('change', function(){
      newPreview.innerHTML = '';
      const files = Array.from(this.files || []);
      files.forEach(f => {
        if (!f.type.startsWith('image/')) return;
        const fr = new FileReader();
        fr.onload = function(ev){
          const wrapper = document.createElement('div');
          wrapper.style.width = '120px';
          wrapper.style.height = '86px';
          wrapper.style.borderRadius = '8px';
          wrapper.style.overflow = 'hidden';
          wrapper.style.background = '#fff';
          const img = document.createElement('img');
          img.src = ev.target.result;
          img.style.width = '100%';
          img.style.height = '100%';
          img.style.objectFit = 'cover';
          wrapper.appendChild(img);
          newPreview.appendChild(wrapper);
        };
        fr.readAsDataURL(f);
      });
    });
  }

  // clicking main image -> open lightbox
  if (mainImg) {
    mainImg.addEventListener('click', function(){
      const src = mainImg.src || noPhoto;
      if (src) openLightbox(src);
    });
  }

  // Clicking logo should open lightbox as requested
  const logoImg = document.getElementById('logoImg');
  if (logoImg) {
    logoImg.addEventListener('click', function(e){
      const src = logoImg.src || null;
      if (src) openLightbox(src);
    });
  }

  // in lightbox, clicking the image -> toggle full screen (use browser fullscreen API if available)
  lightboxImg.addEventListener('click', function(e){
    // prevent overlay click from closing immediately
    e.stopPropagation();
    const el = lightboxImg;
    if (document.fullscreenElement) {
      document.exitFullscreen().catch(()=>{});
    } else if (el.requestFullscreen) {
      el.requestFullscreen().catch(()=>{});
    } else {
      // fallback: nothing
    }
  });

  // DELIVERY: toggle price input enabled/disabled and clear value when unchecked
  const deliveryCheckbox = document.getElementById('deliveryCheckbox');
  const deliveryPriceInput = document.getElementById('deliveryPriceInput');
  function toggleDeliveryInput() {
    if (!deliveryPriceInput) return;
    const enabled = deliveryCheckbox && deliveryCheckbox.checked;
    deliveryPriceInput.disabled = !enabled;
    deliveryPriceInput.style.opacity = enabled ? '1' : '0.6';
    if (!enabled) {
      // clear and remove required when disabled
      deliveryPriceInput.value = '';
      deliveryPriceInput.removeAttribute('required');
    } else {
      // if you want price required when delivery checked, uncomment:
      // deliveryPriceInput.setAttribute('required', 'required');
    }
  }
  if (deliveryCheckbox && deliveryPriceInput) {
    // initialize on load
    toggleDeliveryInput();
    deliveryCheckbox.addEventListener('change', toggleDeliveryInput);
  }

  // when user submits, ensure set_main_existing contains id if a thumb is highlighted as main and that id hasn't been marked for deletion
  const form = document.getElementById('editProductForm');
  form.addEventListener('submit', function(e){
    const mainThumb = thumbs ? thumbs.querySelector('.thumb.is-main') : null;
    if (mainThumb) {
      const del = mainThumb.classList.contains('marked-delete');
      if (del) {
        setMainInput.value = '';
      } else {
        const id = mainThumb.getAttribute('data-id');
        if (id) setMainInput.value = id;
      }
    }
  });

})();
</script>
</body>
</html>
