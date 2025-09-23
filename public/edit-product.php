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
                // guard: skip empty paths
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

    // если пользователь выбрал существующую дополнительную фото как главное
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
            // допустим, пользователь прислал прямой web path или basename; ищем в product_photos
            $candidate = null;
            if ($hasProductPhotosTable) {
                $stc = $mysqli->prepare("SELECT file_path FROM product_photos WHERE product_id = ? AND (file_path = ? OR id = ? ) LIMIT 1");
                if ($stc) {
                    // попытка интерпретировать set_main_existing как id или как path
                    $asId = is_numeric($set_main_existing) ? (int)$set_main_existing : 0;
                    $stc->bind_param('isi', $id, $set_main_existing, $asId);
                    $stc->execute();
                    $cres = $stc->get_result()->fetch_assoc();
                    $stc->close();
                    if ($cres && !empty($cres['file_path'])) $candidate = $cres['file_path'];
                }
            }
            // если нашли - используем как главный
            if ($candidate) {
                // if candidate is same as current main no-op
                $newMainWebPath = $candidate;
                // Note: do not delete candidate in later delete step
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
                // если удалили файл, и он был главным — сбросим главный (потом будет установлен newMainWebPath или null)
                if (!empty($product['photo']) && $fp && $product['photo'] === $fp) {
                    $newMainWebPath = null;
                }
                // также если пользователь выбрал в set_main_existing именно файл который удаляется — ignore: ensure not set
                if ($set_main_existing && ($set_main_existing === $fp || $set_main_existing == $delId)) {
                    $set_main_existing = '';
                }
            }
            $delStmt->close();
        }

        // 3) Добавляем дополнительные фотографии (photos[])
        // Но прежде — соберём хэши уже существующих файлов в папке продукта чтобы избежать дубликатов по содержимому
        $existingHashes = [];
        $existingFiles = [];
        if (is_dir($prodDir)) {
            $dir = scandir($prodDir);
            foreach ($dir as $f) {
                if ($f === '.' || $f === '..') continue;
                $abs = $prodDir . '/' . $f;
                if (!is_file($abs)) continue;
                // считываем md5 (быстро для небольших кол-ва)
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
                $mysqli->query("
                  CREATE TABLE IF NOT EXISTS product_photos (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    product_id INT NOT NULL,
                    file_path VARCHAR(255) NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX (product_id),
                    CONSTRAINT fk_product_photos_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
                  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
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
                    // remove file to keep folder clean
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
        // Если главный указан как существующий доп. файл (и не был удалён) — удостоверимся, что файл существует
        if ($set_main_existing !== '') {
            // try fetch by id or by path again to be safe
            $candidate = null;
            if ($hasProductPhotosTable) {
                $stc = $mysqli->prepare("SELECT file_path FROM product_photos WHERE product_id = ? AND (file_path = ? OR id = ?) LIMIT 1");
                if ($stc) {
                    $asId = is_numeric($set_main_existing) ? (int)$set_main_existing : 0;
                    $stc->bind_param('isi', $id, $set_main_existing, $asId);
                    $stc->execute();
                    $cres = $stc->get_result()->fetch_assoc();
                    $stc->close();
                    if ($cres && !empty($cres['file_path'])) $candidate = $cres['file_path'];
                }
            }
            if ($candidate) $newMainWebPath = $candidate;
        }

        // If newMainWebPath is still set and points to file inside product folder but doesn't exist (deleted earlier), set to null
        if (!empty($newMainWebPath) && strpos($newMainWebPath, $publicPrefix) === 0) {
            $rel = substr($newMainWebPath, strlen($publicPrefix));
            $abs = $uploadBaseDir . $rel;
            if (!is_file($abs)) {
                $newMainWebPath = null;
            }
        }

        // finally update products row
        $upd = $mysqli->prepare("
            UPDATE products
            SET name = ?, manufacturer = ?, quality = ?, rating = ?, availability = ?, price = ?, description = ?, photo = ?, logo = ?, delivery = ?, delivery_price = ?, status = 'pending'
            WHERE id = ?
        ");
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

    .layout { display:grid; grid-template-columns: 420px 1fr; gap:18px; align-items:start; }
    @media(max-width:980px){ .layout{ grid-template-columns: 1fr; } }

    .card { background:var(--card); padding:16px; border-radius:var(--radius); box-shadow:0 10px 30px rgba(2,6,23,0.06); }

    /* Gallery */
    .gallery { display:flex; flex-direction:column; gap:12px; }
    .main-photo { width:100%; height:320px; background:#f2f4f8; border-radius:10px; overflow:hidden; display:flex; align-items:center; justify-content:center; position:relative; }
    .main-photo img { width:100%; height:100%; object-fit:cover; display:block; }
    .main-actions { position:absolute; right:10px; top:10px; display:flex; gap:8px; }
    .action-btn { background:rgba(255,255,255,0.9); border-radius:8px; padding:6px 8px; cursor:pointer; border:1px solid rgba(15,23,42,0.06); font-weight:700; color:#0f172a; }
    .thumbs { display:flex; gap:8px; overflow-x:auto; padding-bottom:6px; }
    .thumb { width:96px; height:72px; flex:0 0 auto; border-radius:8px; overflow:hidden; position:relative; background:#fff; border:1px solid #e9eef6; box-shadow:0 6px 18px rgba(2,6,23,0.03); cursor:pointer; }
    .thumb img { width:100%; height:100%; object-fit:cover; display:block; }
    .thumb .controls { position:absolute; left:6px; bottom:6px; display:flex; gap:6px; }
    .thumb .icon { background:rgba(0,0,0,0.55); color:#fff; padding:6px; border-radius:6px; font-size:12px; display:inline-flex; align-items:center; justify-content:center; }
    .thumb .del { background:rgba(255,50,50,0.9); }

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

    .extras-grid { display:grid; grid-template-columns: repeat(3,1fr); gap:8px; margin-top:8px; }
    @media(max-width:600px){ .extras-grid{ grid-template-columns: repeat(2,1fr); } }

    .extra-card { background:#fff; border-radius:8px; overflow:hidden; border:1px solid #f0f3f8; position:relative; }
    .extra-card img { width:100%; height:120px; object-fit:cover; display:block; }
    .extra-card .meta { padding:8px; display:flex; justify-content:space-between; align-items:center; gap:8px; font-size:13px; color:#334155; }
    .checkbox-del { display:flex; gap:6px; align-items:center; }

    .errors { background:#fff6f6; color:#9b1c1c; padding:10px; border-radius:8px; margin-bottom:10px; }
    .okmsg { background:#f0fdf4; color:#065f46; padding:10px; border-radius:8px; margin-bottom:10px; }
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

  <form method="post" enctype="multipart/form-data" class="layout card">
    <!-- LEFT: gallery -->
    <div>
      <div class="gallery">
        <label>Галерея</label>
        <div class="main-photo" id="mainPhotoContainer">
          <?php if (!empty($product['photo'])): ?>
            <img id="mainPhotoImg" src="<?= htmlspecialchars($product['photo']) ?>" alt="Главное фото">
          <?php else: ?>
            <img id="mainPhotoImg" src="/mehanik/assets/no-photo.png" alt="Нет фото">
          <?php endif; ?>
          <div class="main-actions">
            <label class="action-btn" title="Загрузить новое главное фото">
              Загрузить
              <input type="file" name="photo" accept="image/*" style="display:none" id="uploadMainInput">
            </label>
            <button type="button" class="action-btn" id="clearMainBtn" title="Сбросить главное фото">Сброс</button>
          </div>
        </div>

        <div style="display:flex; align-items:center; justify-content:space-between;">
          <div class="muted">Миниатюры — клик: сделать главным • корзина: удалить</div>
          <label class="btn-ghost" style="padding:6px 8px; font-size:13px;">
            Добавить фото
            <input type="file" name="photos[]" accept="image/*" multiple style="display:none" id="uploadExtrasInput">
          </label>
        </div>

        <div class="thumbs" id="thumbs">
          <?php foreach ($extraPhotos as $ep): ?>
            <div class="thumb" data-id="<?= (int)$ep['id'] ?>" data-path="<?= htmlspecialchars($ep['file_path']) ?>">
              <img src="<?= htmlspecialchars($ep['file_path']) ?>" alt="Фото">
              <div class="controls">
                <span class="icon set-main" title="Сделать главным" role="button">★</span>
                <span class="icon del" title="Удалить" role="button">✕</span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if (!empty($extraPhotos)): ?>
          <div class="muted" style="margin-top:6px;">Вы можете кликнуть по миниатюре, чтобы сделать её главным. Кнопка корзины удалит изображение.</div>
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
          <label>Качество</label>
          <select name="quality">
            <option value="New" <?= ($product['quality'] === 'New' || $product['quality'] === 'Новый') ? 'selected' : '' ?>>New</option>
            <option value="Used" <?= ($product['quality'] === 'Used' || $product['quality'] === 'Б/У') ? 'selected' : '' ?>>Used</option>
          </select>
        </div>
        <div>
          <label>Рейтинг</label>
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
          <input type="checkbox" name="delivery" value="1" <?= (!empty($product['delivery']) && $product['delivery']) ? 'checked' : '' ?>> Есть доставка
        </label>
        <input type="number" step="0.01" name="delivery_price" placeholder="Цена доставки" value="<?= htmlspecialchars($product['delivery_price'] ?? 0) ?>">
      </div>

      <div style="margin-top:12px;">
        <label>Логотип (опционально)</label>
        <?php if (!empty($product['logo'])): ?>
          <div style="max-width:160px;margin-top:8px;">
            <img src="<?= htmlspecialchars($product['logo']) ?>" alt="Логотип" style="height:64px; object-fit:contain; border-radius:6px; border:1px solid #eef3fb;">
          </div>
        <?php endif; ?>
        <input type="file" name="logo" accept="image/*">
        <div class="muted">Загрузите логотип, он заменит текущий.</div>
      </div>

      <!-- скрытые поля: какие дополнительные фото удалить (ids), и которую сделать главным -->
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

<script>
// UI helpers
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

  // clicks on thumbs
  const thumbs = document.getElementById('thumbs');
  const mainImg = document.getElementById('mainPhotoImg');
  const setMainInput = document.getElementById('set_main_existing');
  const deleteInputsContainer = document.getElementById('deleteInputsContainer');
  if (thumbs) {
    thumbs.addEventListener('click', function(e){
      const t = e.target;
      // find .thumb
      let el = t;
      while (el && !el.classList.contains('thumb')) el = el.parentElement;
      if (!el) return;
      const id = el.getAttribute('data-id');
      const path = el.getAttribute('data-path');

      // if clicked control icons
      if (t.classList.contains('del') || (t.parentElement && t.parentElement.classList.contains('del'))) {
        // mark for deletion: add hidden input delete_photos[]
        if (!id) return;
        // add checkbox/ hidden input; toggle visual state
        if (el.classList.contains('marked-delete')) {
          el.classList.remove('marked-delete');
          // remove hidden input
          const inp = deleteInputsContainer.querySelector('input[value="'+id+'"]');
          if (inp) deleteInputsContainer.removeChild(inp);
        } else {
          el.classList.add('marked-delete');
          const hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = 'delete_photos[]';
          hidden.value = id;
          deleteInputsContainer.appendChild(hidden);
        }
        return;
      }

      if (t.classList.contains('set-main') || (t.parentElement && t.parentElement.classList.contains('set-main'))) {
        // set this existing photo as main (set hidden input to id or path)
        if (path) {
          setMainInput.value = path;
          // visually update main image preview
          mainImg.src = path;
          // optionally highlight selected thumb
          thumbs.querySelectorAll('.thumb').forEach(x=> x.style.boxShadow='none');
          el.style.boxShadow = '0 10px 30px rgba(11,87,164,0.14)';
        }
        return;
      }

      // otherwise clicking thumbnail itself -> make main preview (but doesn't update server until submit)
      if (path) {
        mainImg.src = path;
        // clear set_main_existing? we'll set it to path as well for convenience
        setMainInput.value = path;
        thumbs.querySelectorAll('.thumb').forEach(x=> x.style.boxShadow='none');
        el.style.boxShadow = '0 10px 30px rgba(11,87,164,0.14)';
      }
    });
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
          const img = document.createElement('img');
          img.src = ev.target.result;
          img.style.width = '96px';
          img.style.height = '72px';
          img.style.objectFit = 'cover';
          img.style.borderRadius = '6px';
          newPreview.appendChild(img);
        };
        fr.readAsDataURL(f);
      });
    });
    // clicking "Добавить фото" label triggers input
    const addBtnLabel = document.querySelector('label.btn-ghost input[name="photos[]"]');
  }

  // upload main input preview
  const uploadMainInput = document.getElementById('uploadMainInput');
  if (uploadMainInput && mainImg) {
    uploadMainInput.addEventListener('change', function(){
      if (!this.files || !this.files[0]) return;
      const f = this.files[0];
      if (!f.type.startsWith('image/')) return;
      const fr = new FileReader();
      fr.onload = function(ev){
        mainImg.src = ev.target.result;
        // clear set_main_existing because explicit new file chosen
        setMainInput.value = '';
      };
      fr.readAsDataURL(f);
    });
  }

  // clear main button
  const clearMainBtn = document.getElementById('clearMainBtn');
  if (clearMainBtn && mainImg) {
    clearMainBtn.addEventListener('click', function(){
      mainImg.src = '/mehanik/assets/no-photo.png';
      // set hidden input to empty so server will clear main if needed
      setMainInput.value = '';
      // also remove uploadMainInput value
      if (uploadMainInput) uploadMainInput.value = '';
    });
  }

  // clicking the "Добавить фото" ghost label should open file dialog:
  const addPhotosLabel = document.querySelector('label.btn-ghost input[name="photos[]"]');
  if (addPhotosLabel) {
    // the input is already inside the label -> no extra handler required
  }

})();
</script>
</body>
</html>
