<?php
// mehanik/public/edit-car.php
require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php'; // ожидаем $mysqli и/или $pdo

$currentUser = $_SESSION['user'] ?? null;
$uid = (int)($currentUser['id'] ?? 0);
$isAdmin = in_array($currentUser['role'] ?? '', ['admin','superadmin'], true);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: /mehanik/public/my-cars.php'); exit; }

// load car
$car = null;
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $st = $mysqli->prepare("SELECT * FROM cars WHERE id = ? LIMIT 1");
        $st->bind_param('i', $id);
        $st->execute();
        $res = $st->get_result();
        $car = $res ? $res->fetch_assoc() : null;
        $st->close();
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $st = $pdo->prepare("SELECT * FROM cars WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $car = $st->fetch(PDO::FETCH_ASSOC);
    } else {
        throw new Exception('DB connection missing');
    }
} catch (Throwable $e) {
    error_log("edit-car: load failed: " . $e->getMessage());
    http_response_code(500); echo "Ошибка сервера"; exit;
}
if (!$car) { http_response_code(404); echo "Объявление не найдено."; exit; }

// permission
$ownerId = (int)($car['user_id'] ?? 0);
if (!$isAdmin && $uid !== $ownerId) { http_response_code(403); echo "Нет прав на редактирование."; exit; }

// load existing extra photos
$extraPhotos = [];
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $stp = $mysqli->prepare("SELECT id, file_path FROM car_photos WHERE car_id = ? ORDER BY id ASC");
        $stp->bind_param('i', $id);
        $stp->execute();
        $res = $stp->get_result();
        if ($res) $extraPhotos = $res->fetch_all(MYSQLI_ASSOC);
        $stp->close();
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $stp = $pdo->prepare("SELECT id, file_path FROM car_photos WHERE car_id = ? ORDER BY id ASC");
        $stp->execute([$id]);
        $extraPhotos = $stp->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $_) {
    $extraPhotos = [];
}

$errors = [];
$success = '';
$currentYear = (int)date('Y');
$minYear = $currentYear - 25;

// helpers
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function webPathFor($rel){ // normalize web path to starting with '/'
    $p = '/' . ltrim($rel, '/');
    return $p;
}

// POST handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // collect
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $year = (int)($_POST['year'] ?? 0);
    $body = trim($_POST['body'] ?? '');
    $mileage = (int)($_POST['mileage'] ?? 0);
    $transmission = trim($_POST['transmission'] ?? '');
    $fuel = trim($_POST['fuel'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $status = $isAdmin ? (in_array($_POST['status'] ?? '', ['pending','approved','rejected']) ? $_POST['status'] : 'pending') : $car['status'];

    // which existing extra photos to delete (ids)
    $delete_photos = [];
    if (!empty($_POST['delete_photos']) && is_array($_POST['delete_photos'])) {
        foreach ($_POST['delete_photos'] as $v) {
            $v = (int)$v;
            if ($v > 0) $delete_photos[] = $v;
        }
    }

    // client may indicate which of the NEW uploaded files should be main
    $main_new_index = isset($_POST['main_new_index']) ? ((int)$_POST['main_new_index']) : -1;

    // validation
    if ($brand === '') $errors[] = 'Бренд обязателен';
    if ($model === '') $errors[] = 'Модель обязательна';
    if ($year < $minYear || $year > $currentYear) $errors[] = "Год должен быть в диапазоне {$minYear}—{$currentYear}";

    // prepare upload dirs
    $uploadsBaseRel = 'uploads/cars';
    $uploadsBase = __DIR__ . '/../' . $uploadsBaseRel;
    $prodDir = $uploadsBase . '/' . intval($id);
    $webProdPrefix = '/' . trim($uploadsBaseRel, '/') . '/' . intval($id) . '/';

    if (!is_dir($prodDir)) {
        if (!@mkdir($prodDir, 0755, true)) {
            // attempt later — but not fatal yet
        }
    }

    // Allowed mime extensions
    $acceptedExt = ['jpg','jpeg','png','webp'];
    $maxFileSize = 6 * 1024 * 1024; // 6MB

    // 1) handle deletion of existing extra photos (we'll remove DB rows and unlink files inside transaction)
    // 2) handle uploaded files: main_photo and photos[] — save temporarily and then insert rows
    $newMainWeb = null; // web path for new main photo (if replaced)
    $newExtraWeb = [];  // array of web paths to insert into car_photos

    // collect uploaded files info first
    $uploadedMainTmp = null;
    $uploadedMainExt = null;
    if (!empty($_FILES['main_photo']['tmp_name']) && is_uploaded_file($_FILES['main_photo']['tmp_name']) && ($_FILES['main_photo']['error'] ?? 1) === UPLOAD_ERR_OK) {
        if ($_FILES['main_photo']['size'] > $maxFileSize) $errors[] = 'Основной файл слишком большой';
        else {
            $ext = strtolower(pathinfo($_FILES['main_photo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $acceptedExt, true)) $errors[] = 'Неподдерживаемый формат основного фото';
            else { $uploadedMainTmp = $_FILES['main_photo']['tmp_name']; $uploadedMainExt = $ext; }
        }
    }

    // multiple new extras
    $pendingExtras = []; // each ['tmp','ext','orig']
    if (!empty($_FILES['photos']) && is_array($_FILES['photos']['tmp_name'])) {
        $cnt = count($_FILES['photos']['tmp_name']);
        for ($i=0;$i<$cnt;$i++) {
            $tmp = $_FILES['photos']['tmp_name'][$i] ?? null;
            $errf = $_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if (!$tmp || $errf !== UPLOAD_ERR_OK) continue;
            if ($_FILES['photos']['size'][$i] > $maxFileSize) { $errors[] = 'Один из дополнительных файлов слишком большой'; continue; }
            $ext = strtolower(pathinfo($_FILES['photos']['name'][$i] ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, $acceptedExt, true)) { $errors[] = 'Неподдерживаемый формат в доп. фото: ' . ($_FILES['photos']['name'][$i] ?? ''); continue; }
            $pendingExtras[] = ['tmp'=>$tmp, 'ext'=>$ext, 'orig'=>$_FILES['photos']['name'][$i] ?? 'file'];
        }
    }

    if (empty($errors)) {
        // Start transaction
        try {
            if (isset($mysqli) && $mysqli instanceof mysqli) $mysqli->begin_transaction();
            elseif (isset($pdo) && $pdo instanceof PDO) $pdo->beginTransaction();

            // 1) delete selected existing extra photos
            if (!empty($delete_photos)) {
                // select rows to delete to know file paths
                $toDeleteRows = [];
                foreach ($delete_photos as $delId) {
                    if (isset($mysqli) && $mysqli instanceof mysqli) {
                        $st = $mysqli->prepare("SELECT id, file_path FROM car_photos WHERE id = ? AND car_id = ? LIMIT 1");
                        $st->bind_param('ii', $delId, $id);
                        $st->execute();
                        $r = $st->get_result()->fetch_assoc();
                        $st->close();
                        if ($r) $toDeleteRows[] = $r;
                    } else {
                        $st = $pdo->prepare("SELECT id, file_path FROM car_photos WHERE id = ? AND car_id = ? LIMIT 1");
                        $st->execute([$delId, $id]);
                        $r = $st->fetch(PDO::FETCH_ASSOC);
                        if ($r) $toDeleteRows[] = $r;
                    }
                }
                // delete files and DB rows
                if (!empty($toDeleteRows)) {
                    if (isset($mysqli) && $mysqli instanceof mysqli) {
                        $delSt = $mysqli->prepare("DELETE FROM car_photos WHERE id = ? AND car_id = ?");
                        foreach ($toDeleteRows as $row) {
                            $fp = $row['file_path'];
                            // compute absolute path if it lives under uploads/cars/{id}/
                            $abs = null;
                            if (strpos($fp, $webProdPrefix) === 0) {
                                $rel = substr($fp, strlen($webProdPrefix));
                                $abs = $prodDir . '/' . $rel;
                            } elseif (strpos($fp, '/uploads/cars/' . $id . '/') !== false) {
                                // fallback if web path includes /uploads/cars/{id}/
                                $abs = __DIR__ . '/../' . ltrim($fp, '/');
                            } else {
                                // relative to upload dir
                                $abs = $prodDir . '/' . basename($fp);
                            }
                            if ($abs && is_file($abs)) @unlink($abs);
                            $iid = (int)$row['id'];
                            $delSt->bind_param('ii', $iid, $id);
                            $delSt->execute();
                        }
                        $delSt->close();
                    } else {
                        $delSt = $pdo->prepare("DELETE FROM car_photos WHERE id = :id AND car_id = :car");
                        foreach ($toDeleteRows as $row) {
                            $fp = $row['file_path'];
                            $abs = __DIR__ . '/../' . ltrim($fp, '/');
                            if ($abs && is_file($abs)) @unlink($abs);
                            $delSt->execute([':id'=>$row['id'], ':car'=>$id]);
                        }
                    }
                }
            }

            // ensure product directory exists
            if (!is_dir($prodDir)) { @mkdir($prodDir, 0755, true); }

            // 2) process uploaded extras -> move files and collect web paths
            foreach ($pendingExtras as $idx => $item) {
                $uniq = preg_replace('/[^a-z0-9]+/i','', uniqid('ph', true));
                $fname = 'photo_' . $uniq . '.' . $item['ext'];
                $abs = $prodDir . '/' . $fname;
                if (!@move_uploaded_file($item['tmp'], $abs)) {
                    throw new Exception('Не удалось сохранить дополнительное фото: ' . $item['orig']);
                }
                $web = $webProdPrefix . $fname;
                $newExtraWeb[] = $web;
            }

            // 3) process uploaded main photo (if any). If main_new_index was set and corresponds to one of the new extras,
            // we should set that file as main (move already done above). But our logic: if there is uploaded main_photo field -> use it.
            // We'll prefer explicit main_photo input. If not provided and main_new_index >=0 and within pendingExtras -> we have to identify corresponding file.
            if ($uploadedMainTmp) {
                // save uploaded main to prodDir with deterministic name main_{id}.{ext}
                $fname = 'main_' . $id . '.' . $uploadedMainExt;
                $abs = $prodDir . '/' . $fname;
                if (!@move_uploaded_file($uploadedMainTmp, $abs)) {
                    throw new Exception('Не удалось сохранить главное фото');
                }
                $newMainWeb = $webProdPrefix . $fname;

                // remove previous main file if it existed inside this prodDir
                if (!empty($car['photo'])) {
                    $old = $car['photo'];
                    $oldAbs = __DIR__ . '/../' . ltrim($old, '/');
                    if (is_file($oldAbs)) @unlink($oldAbs);
                }
            } else {
                // no explicit main file; if client set main_new_index and that corresponds to an element of newExtraWeb,
                // we move that existing extra file to main filename and remove it from extras list.
                if ($main_new_index >= 0 && isset($newExtraWeb[$main_new_index])) {
                    // move that extra file to main_{id}.ext
                    $srcWeb = $newExtraWeb[$main_new_index];
                    $srcAbs = __DIR__ . '/../' . ltrim($srcWeb, '/');
                    $ext = pathinfo($srcAbs, PATHINFO_EXTENSION) ?: 'jpg';
                    $fname = 'main_' . $id . '.' . $ext;
                    $dstAbs = $prodDir . '/' . $fname;
                    if (@rename($srcAbs, $dstAbs)) {
                        // update web path
                        $newMainWeb = $webProdPrefix . $fname;
                        // remove from extra list (we'll insert remaining extras later)
                        array_splice($newExtraWeb, $main_new_index, 1);
                    } else {
                        // fallback: keep it as extra and do not set main
                    }
                    // remove previous main if inside our dir
                    if (!empty($car['photo'])) {
                        $old = $car['photo'];
                        $oldAbs = __DIR__ . '/../' . ltrim($old, '/');
                        if (is_file($oldAbs)) @unlink($oldAbs);
                    }
                }
            }

            // 4) insert newExtraWeb into car_photos table
            if (!empty($newExtraWeb)) {
                // ensure table exists (best-effort)
                if (isset($mysqli) && $mysqli instanceof mysqli) {
                    $check = $mysqli->query("SHOW TABLES LIKE 'car_photos'");
                    if (!$check || $check->num_rows === 0) {
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
                    foreach ($newExtraWeb as $p) {
                        $ins->bind_param('is', $id, $p);
                        $ins->execute();
                    }
                    $ins->close();
                } else {
                    // PDO
                    $stt = $pdo->prepare("INSERT INTO car_photos (car_id, file_path) VALUES (:car, :path)");
                    foreach ($newExtraWeb as $p) $stt->execute([':car'=>$id, ':path'=>$p]);
                }
            }

            // 5) update cars table (set photo to newMainWeb if set, or keep old)
            $photoToSave = $newMainWeb ?? $car['photo'] ?? null;
            if (isset($mysqli) && $mysqli instanceof mysqli) {
                if ($photoToSave !== null) {
                    $upd = $mysqli->prepare("UPDATE cars SET brand=?, model=?, year=?, body=?, mileage=?, transmission=?, fuel=?, price=?, description=?, photo=?, status=? WHERE id=?");
                    $upd->bind_param('ssissssdsisi', $brand, $model, $year, $body, $mileage, $transmission, $fuel, $price, $description, $photoToSave, $status, $id);
                } else {
                    $upd = $mysqli->prepare("UPDATE cars SET brand=?, model=?, year=?, body=?, mileage=?, transmission=?, fuel=?, price=?, description=?, status=? WHERE id=?");
                    $upd->bind_param('ssissssdsii', $brand, $model, $year, $body, $mileage, $transmission, $fuel, $price, $description, $status, $id);
                }
                $upd->execute();
                $upd->close();
                $mysqli->commit();
            } else {
                if ($photoToSave !== null) {
                    $stupd = $pdo->prepare("UPDATE cars SET brand=:brand, model=:model, year=:year, body=:body, mileage=:mileage, transmission=:trans, fuel=:fuel, price=:price, description=:desc, photo=:photo, status=:status WHERE id=:id");
                    $stupd->execute([
                        ':brand'=>$brand, ':model'=>$model, ':year'=>$year, ':body'=>$body, ':mileage'=>$mileage,
                        ':trans'=>$transmission, ':fuel'=>$fuel, ':price'=>$price, ':desc'=>$description, ':photo'=>$photoToSave, ':status'=>$status, ':id'=>$id
                    ]);
                } else {
                    $stupd = $pdo->prepare("UPDATE cars SET brand=:brand, model=:model, year=:year, body=:body, mileage=:mileage, transmission=:trans, fuel=:fuel, price=:price, description=:desc, status=:status WHERE id=:id");
                    $stupd->execute([
                        ':brand'=>$brand, ':model'=>$model, ':year'=>$year, ':body'=>$body, ':mileage'=>$mileage,
                        ':trans'=>$transmission, ':fuel'=>$fuel, ':price'=>$price, ':desc'=>$description, ':status'=>$status, ':id'=>$id
                    ]);
                }
                $pdo->commit();
            }

            $success = 'Изменения сохранены. Статус: ' . h($status);
            // reload $car and $extraPhotos
            if (isset($mysqli) && $mysqli instanceof mysqli) {
                $st = $mysqli->prepare("SELECT * FROM cars WHERE id = ? LIMIT 1");
                $st->bind_param('i', $id);
                $st->execute();
                $res = $st->get_result();
                if ($res) $car = $res->fetch_assoc();
                $st->close();
                // reload extra photos
                $extraPhotos = [];
                $stp = $mysqli->prepare("SELECT id, file_path FROM car_photos WHERE car_id = ? ORDER BY id ASC");
                $stp->bind_param('i', $id); $stp->execute();
                $res2 = $stp->get_result();
                if ($res2) $extraPhotos = $res2->fetch_all(MYSQLI_ASSOC);
            } else {
                $st = $pdo->prepare("SELECT * FROM cars WHERE id = ? LIMIT 1"); $st->execute([$id]); $car = $st->fetch(PDO::FETCH_ASSOC);
                $stp = $pdo->prepare("SELECT id, file_path FROM car_photos WHERE car_id = ? ORDER BY id ASC"); $stp->execute([$id]); $extraPhotos = $stp->fetchAll(PDO::FETCH_ASSOC);
            }

        } catch (Throwable $e) {
            // rollback
            if (isset($mysqli) && $mysqli instanceof mysqli) { @$mysqli->rollback(); }
            if (isset($pdo) && $pdo instanceof PDO) { try { @$pdo->rollBack(); } catch (Throwable $_) {} }
            $errors[] = 'Ошибка при сохранении: ' . $e->getMessage();
            error_log('edit-car save error: ' . $e->getMessage());
        }
    }
} // end POST

// render page
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
:root{--accent:#0b57a4;--muted:#6b7280}
.container{max-width:1100px;margin:18px auto;padding:14px}
.card{background:#fff;border-radius:12px;padding:18px;box-shadow:0 8px 24px rgba(2,6,23,.06)}
.grid{display:grid;grid-template-columns:360px 1fr;gap:18px}
@media(max-width:900px){.grid{grid-template-columns:1fr}}
label{display:block;font-weight:700;margin-bottom:6px;color:#0f172a}
.input, input[type="text"], input[type="number"], select, textarea {width:100%;padding:10px;border:1px solid #e6e9ef;border-radius:8px;box-sizing:border-box}
textarea{min-height:120px}
.section{background:#fbfdff;padding:12px;border-radius:10px;border:1px solid #eef4fb}
.preview-main{width:100%;height:220px;border-radius:10px;overflow:hidden;background:#f3f6fb;display:flex;align-items:center;justify-content:center}
.preview-main img{width:100%;height:100%;object-fit:cover;display:block}
.extras-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-top:10px}
.extra-item{position:relative;border-radius:8px;overflow:hidden;border:1px solid #eef4fb;background:#fff;padding:6px;text-align:center}
.extra-item img{width:100%;height:110px;object-fit:cover;border-radius:6px}
.extra-controls{display:flex;gap:6px;justify-content:center;margin-top:8px}
.btn{background:var(--accent);color:#fff;padding:8px 12px;border-radius:8px;border:0;cursor:pointer}
.btn-ghost{background:#fff;border:1px solid #e6eef7;color:var(--accent);padding:8px 12px;border-radius:8px;cursor:pointer}
.small{font-size:.9rem;color:var(--muted)}
.notice{padding:10px;border-radius:8px;margin-bottom:12px}
.notice.ok{background:#eafaf0;border:1px solid #cfead1;color:#116530}
.notice.err{background:#fff6f6;border:1px solid #f5c2c2;color:#8a1f1f}
.file-preview{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.preview-item{width:110px;height:80px;border-radius:8px;overflow:hidden;border:1px solid #e6eef7;position:relative;background:#fafafa;display:flex;align-items:center;justify-content:center}
.preview-item img{width:100%;height:100%;object-fit:cover}
.preview-controls{position:absolute;left:6px;top:6px;display:flex;flex-direction:column;gap:6px}
.star-badge{position:absolute;right:6px;top:6px;background:var(--accent);color:#fff;padding:4px 6px;border-radius:6px;font-size:11px}
.checkbox-delete{margin-top:6px}
</style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<div class="container">
  <div class="card">
    <h2 style="margin:0 0 12px">Редактирование объявления #<?= (int)$car['id'] ?></h2>

    <?php if ($errors): ?>
      <div class="notice err"><?= h(implode(' · ', $errors)) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="notice ok"><?= h($success) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="editCarForm">
      <div class="grid">
        <!-- left: photos -->
        <div class="section">
          <label>Основное фото</label>
          <div class="preview-main" id="mainPreview">
            <?php if (!empty($car['photo'])): ?>
              <img id="currentMainImg" src="<?= h( (strpos($car['photo'],'/')===0) ? $car['photo'] : '/' . ltrim($car['photo'],'/') ) ?>" alt="main">
            <?php else: ?>
              <img id="currentMainImg" src="/mehanik/assets/no-photo.png" alt="no photo">
            <?php endif; ?>
          </div>
          <div style="margin-top:8px">
            <label class="small">Загрузить новое основное фото (опционально)</label>
            <input type="file" name="main_photo" accept="image/*" id="mainFileInput">
            <div class="small" style="margin-top:6px">Если загрузить — текущее основное фото будет заменено.</div>
          </div>

          <hr style="margin:12px 0">

          <label>Дополнительные фото</label>
          <div class="small">Добавьте новые фото или удалите уже существующие.</div>
          <input type="file" name="photos[]" id="extrasInput" accept="image/*" multiple style="margin-top:8px">
          <div class="file-preview" id="newPreview"></div>

          <?php if (!empty($extraPhotos)): ?>
            <div style="margin-top:12px"><strong>Существующие дополнительные фото</strong></div>
            <div class="extras-grid" id="existingExtras">
              <?php foreach ($extraPhotos as $ep): ?>
                <div class="extra-item">
                  <img src="<?= h((strpos($ep['file_path'],'/')===0) ? $ep['file_path'] : '/' . ltrim($ep['file_path'],'/')) ?>" alt="">
                  <div class="extra-controls">
                    <label class="checkbox-delete"><input type="checkbox" name="delete_photos[]" value="<?= (int)$ep['id'] ?>"> Удалить</label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="small" style="margin-top:8px">Пока нет дополнительных фото</div>
          <?php endif; ?>

          <input type="hidden" name="main_new_index" id="main_new_index" value="-1">
          <div class="small" style="margin-top:10px">У выбранного на превью нового фото можно отметить звёздочкой главное фото (★).</div>
        </div>

        <!-- right: fields -->
        <div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <label>Бренд *</label>
              <input class="input" type="text" name="brand" value="<?= h($car['brand'] ?? '') ?>" required>
            </div>
            <div>
              <label>Модель *</label>
              <input class="input" type="text" name="model" value="<?= h($car['model'] ?? '') ?>" required>
            </div>
            <div>
              <label>Год *</label>
              <select name="year" class="input" required>
                <?php for ($y=$currentYear;$y>=$minYear;$y--): ?>
                  <option value="<?= $y ?>" <?= ((int)$car['year']=== $y)?'selected':'' ?>><?= $y ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div>
              <label>Кузов</label>
              <input class="input" type="text" name="body" value="<?= h($car['body'] ?? '') ?>">
            </div>
            <div>
              <label>Пробег (км)</label>
              <input class="input" type="number" name="mileage" min="0" value="<?= h($car['mileage'] ?? '') ?>">
            </div>
            <div>
              <label>Коробка</label>
              <input class="input" type="text" name="transmission" value="<?= h($car['transmission'] ?? '') ?>">
            </div>

            <div>
              <label>Топливо</label>
              <input class="input" type="text" name="fuel" value="<?= h($car['fuel'] ?? '') ?>">
            </div>
            <div>
              <label>Цена (TMT)</label>
              <input class="input" type="number" step="0.01" name="price" min="0" value="<?= h($car['price'] ?? '') ?>">
            </div>

            <?php if ($isAdmin): ?>
            <div style="grid-column:1 / -1">
              <label>Статус</label>
              <select name="status" class="input">
                <option value="pending" <?= ($car['status'] ?? '') === 'pending' ? 'selected' : '' ?>>На модерации</option>
                <option value="approved" <?= ($car['status'] ?? '') === 'approved' ? 'selected' : '' ?>>Подтверждён</option>
                <option value="rejected" <?= ($car['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>Отклонён</option>
              </select>
            </div>
            <?php endif; ?>
          </div>

          <div style="margin-top:12px">
            <label>Описание</label>
            <textarea name="description"><?= h($car['description'] ?? '') ?></textarea>
          </div>

          <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end">
            <a class="btn-ghost" href="/mehanik/public/my-cars.php">Отмена</a>
            <button class="btn" type="submit">Сохранить</button>
          </div>

          <div class="small" style="margin-top:10px">После сохранения статус станет "На модерации" (если вы не админ).</div>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  // preview new extras and allow mark main
  const extrasInput = document.getElementById('extrasInput');
  const newPreview = document.getElementById('newPreview');
  const mainIndexInput = document.getElementById('main_new_index');
  const mainFileInput = document.getElementById('mainFileInput');
  const mainPreviewImg = document.getElementById('currentMainImg');

  let files = []; // File objects for new extras
  let mainIdx = -1; // index among files for "make main" (if chosen)

  function renderPreviews(){
    newPreview.innerHTML = '';
    files.forEach((f, idx) => {
      const w = document.createElement('div');
      w.className = 'preview-item';
      const img = document.createElement('img');
      w.appendChild(img);

      const controls = document.createElement('div');
      controls.className = 'preview-controls';

      const btnStar = document.createElement('button');
      btnStar.type='button'; btnStar.title='Сделать главным'; btnStar.textContent='★';
      btnStar.style.padding='6px'; btnStar.style.borderRadius='6px'; btnStar.style.border='0'; btnStar.style.background='rgba(11,87,164,0.9)'; btnStar.style.color='#fff';
      controls.appendChild(btnStar);

      const btnDel = document.createElement('button');
      btnDel.type='button'; btnDel.title='Удалить'; btnDel.textContent='✕';
      btnDel.style.padding='6px'; btnDel.style.marginTop='6px'; btnDel.style.borderRadius='6px'; btnDel.style.border='0'; btnDel.style.background='rgba(0,0,0,0.6)'; btnDel.style.color='#fff';
      controls.appendChild(btnDel);

      w.appendChild(controls);

      if (idx === mainIdx) {
        const badge = document.createElement('div');
        badge.className = 'star-badge';
        badge.textContent = 'Главное';
        w.appendChild(badge);
      }

      const reader = new FileReader();
      reader.onload = function(e){ img.src = e.target.result; };
      reader.readAsDataURL(f);

      btnStar.addEventListener('click', function(){
        if (mainIdx === idx) mainIdx = -1;
        else mainIdx = idx;
        // reflect to main_new_index input
        mainIndexInput.value = mainIdx;
        // also if user marks a new file as main, update main preview to show it
        if (mainIdx >= 0) {
          const fr = new FileReader();
          fr.onload = function(e){ mainPreviewImg.src = e.target.result; };
          fr.readAsDataURL(files[mainIdx]);
        } else {
          // reset to original main (server-provided)
          mainPreviewImg.src = mainPreviewImg.dataset.orig || mainPreviewImg.src;
        }
        renderPreviews();
      });

      btnDel.addEventListener('click', function(){
        files.splice(idx,1);
        if (mainIdx !== -1) {
          if (idx === mainIdx) mainIdx = -1;
          else if (idx < mainIdx) mainIdx--;
        }
        mainIndexInput.value = mainIdx;
        renderPreviews();
      });

      newPreview.appendChild(w);
    });
  }

  extrasInput && extrasInput.addEventListener('change', function(){
    const list = Array.from(this.files || []);
    for (const f of list) {
      if (!f.type.startsWith('image/')) continue;
      files.push(f);
    }
    if (mainIdx === -1 && files.length) mainIdx = 0;
    mainIndexInput.value = mainIdx;
    renderPreviews();
    // Clear input to allow re-adding same files if needed
    extrasInput.value = '';
    // If main marked, update main preview to selected file
    if (mainIdx >= 0 && files[mainIdx]) {
      const fr = new FileReader();
      fr.onload = e => mainPreviewImg.src = e.target.result;
      fr.readAsDataURL(files[mainIdx]);
    }
  });

  // When user uploads a main photo via mainFileInput, update main preview immediately
  mainFileInput && mainFileInput.addEventListener('change', function(){
    const f = this.files && this.files[0];
    if (!f) return;
    const fr = new FileReader();
    fr.onload = function(e){ mainPreviewImg.src = e.target.result; };
    fr.readAsDataURL(f);
    // mark main_new_index to -1 since explicit main file uploaded
    mainIdx = -1;
    mainIndexInput.value = -1;
  });

  // Enhance drag & drop on preview area
  const dropzone = newPreview;
  if (dropzone) {
    dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.style.background = '#f0f8ff'; });
    dropzone.addEventListener('dragleave', e => { e.preventDefault(); dropzone.style.background = ''; });
    dropzone.addEventListener('drop', e => {
      e.preventDefault(); dropzone.style.background='';
      const dt = e.dataTransfer;
      if (!dt) return;
      const added = Array.from(dt.files || []).filter(f=>f.type && f.type.startsWith('image/'));
      for (const f of added) files.push(f);
      if (mainIdx === -1 && files.length) mainIdx = 0;
      mainIndexInput.value = mainIdx;
      renderPreviews();
    });
  }

  // before submit: we need to append the extras files and if user set main_new_index we pass it in hidden input
  const form = document.getElementById('editCarForm');
  form.addEventListener('submit', function(e){
    // if there are new files, we want to send them as real file inputs so server can process them.
    // Because form already has <input type="file" name="photos[]">, HTML will handle them only if user selected via that input.
    // We used JS-managed files array, so we need to construct FormData and submit via XHR, or create temporary file inputs (not possible).
    // Simpler: allow normal POST if user used native inputs; but because we replaced extrasInput with our handling,
    // we should submit via FormData (XHR) to include files. We'll do XHR submission to same URL.
    e.preventDefault();

    // basic front validation as convenience
    const brand = (form.querySelector('[name=brand]') || {}).value || '';
    const model = (form.querySelector('[name=model]') || {}).value || '';
    const year = parseInt((form.querySelector('[name=year]') || {}).value || '0', 10);
    if (!brand || !model) { alert('Пожалуйста заполните бренд и модель'); return; }
    const minY = <?= json_encode($minYear) ?>;
    const maxY = <?= json_encode($currentYear) ?>;
    if (!year || year < minY || year > maxY) { alert('Пожалуйста выберите корректный год'); return; }

    const fd = new FormData();

    // append regular form fields
    Array.from(form.elements).forEach(el => {
      if (!el.name) return;
      if (el.type === 'file') return; // skip file inputs (we'll append files manually)
      if ((el.type === 'checkbox' || el.type === 'radio')) {
        if (!el.checked) return;
      }
      fd.append(el.name, el.value);
    });

    // append main file if uploaded via native mainFileInput
    if (mainFileInput && mainFileInput.files && mainFileInput.files[0]) {
      fd.append('main_photo', mainFileInput.files[0], mainFileInput.files[0].name);
    }

    // append extras: our files[] array
    files.forEach((f, idx) => {
      // default: append as photos[]
      fd.append('photos[]', f, f.name);
    });

    // append delete_photos checkboxes (they are in form as checked checkboxes so were already appended above)
    // XHR
    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.action, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function(){
      if (xhr.readyState !== 4) return;
      if (xhr.status >= 200 && xhr.status < 300) {
        // if server redirected HTML, we might simply reload
        try {
          // try parse json
          const j = JSON.parse(xhr.responseText || '{}');
          if (j && j.ok) {
            location.reload();
            return;
          }
        } catch (e) {
          // fallback reload
          location.reload();
        }
      } else {
        alert('Ошибка при сохранении. Попробуйте ещё раз.');
      }
    };
    xhr.send(fd);
  });

})();
</script>

</body>
</html>
