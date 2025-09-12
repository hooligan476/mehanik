<?php
// mehanik/public/edit-car.php
require_once __DIR__ . '/middleware.php';
require_auth();
require_once __DIR__ . '/db.php'; // ожидаем $mysqli и/или $pdo

$currentUser = $_SESSION['user'] ?? null;
$uid = (int)($currentUser['id'] ?? 0);
$isAdmin = in_array($currentUser['role'] ?? '', ['admin','superadmin'], true);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: /mehanik/public/my-cars.php'); exit; }

// получаем объявление через mysqli (если у тебя $mysqli)
if (isset($mysqli) && $mysqli instanceof mysqli) {
    $st = $mysqli->prepare("SELECT * FROM cars WHERE id = ? LIMIT 1");
    $st->bind_param('i', $id);
    $st->execute();
    $res = $st->get_result();
    $car = $res ? $res->fetch_assoc() : null;
    $st->close();
} else {
    // fallback на PDO
    $st = $pdo->prepare("SELECT * FROM cars WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $car = $st->fetch(PDO::FETCH_ASSOC);
}

if (!$car) { http_response_code(404); echo "Объявление не найдено."; exit; }

// проверка прав: владелец или админ
$ownerId = (int)($car['user_id'] ?? 0);
if (!$isAdmin && $uid !== $ownerId) {
    http_response_code(403); echo "Нет прав на редактирование."; exit;
}

$errors = [];
$success = '';
$currentYear = (int)date('Y');
$minYear = $currentYear - 25;

// обработка POST (обновление)
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
    // only admin can set status
    $status = $isAdmin ? (in_array($_POST['status'] ?? '', ['pending','approved','rejected']) ? $_POST['status'] : 'pending') : $car['status'];

    if ($brand === '') $errors[] = 'Бренд обязателен';
    if ($model === '') $errors[] = 'Модель обязателна';
    if ($year < $minYear || $year > $currentYear) $errors[] = "Год должен быть в диапазоне {$minYear}—{$currentYear}";

    // обработка замены основного фото (опционально)
    $newMain = null;
    if (!empty($_FILES['main_photo']['tmp_name']) && $_FILES['main_photo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['main_photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'])) $errors[] = 'Неподдерживаемый формат основного фото';
        else {
            $uploadDir = __DIR__ . '/uploads/cars/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $newName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $dest = $uploadDir . $newName;
            if (!move_uploaded_file($_FILES['main_photo']['tmp_name'], $dest)) $errors[] = 'Ошибка сохранения основного фото';
            else $newMain = 'uploads/cars/' . $newName;
        }
    }

    // дополнительные фото (append)
    $addedPhotos = [];
    if (!empty($_FILES['photos']) && is_array($_FILES['photos']['tmp_name'])) {
        $uploadDir = __DIR__ . '/uploads/cars/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $count = count($_FILES['photos']['tmp_name']);
        for ($i=0;$i<$count;$i++) {
            $tmp = $_FILES['photos']['tmp_name'][$i] ?? null;
            $name = $_FILES['photos']['name'][$i] ?? '';
            $errf = $_FILES['photos']['error'][$i] ?? 1;
            if ($errf !== UPLOAD_ERR_OK || !$tmp) continue;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'])) continue;
            $newName = time() . '_' . bin2hex(random_bytes(6)) . "_{$i}." . $ext;
            $dest = $uploadDir . $newName;
            if (move_uploaded_file($tmp, $dest)) $addedPhotos[] = 'uploads/cars/' . $newName;
        }
    }

    if (empty($errors)) {
        // обновление через mysqli или PDO
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            // начало транзакции
            $mysqli->begin_transaction();
            try {
                // если есть новое основное фото — удалить старое файл
                if ($newMain && !empty($car['photo'])) {
                    $old = $car['photo'];
                    $cands = [];
                    if (strpos($old, 'uploads/') === 0) $cands[] = __DIR__ . '/' . $old;
                    else $cands[] = __DIR__ . '/uploads/cars/' . ltrim($old,'/');
                    foreach ($cands as $f) if (file_exists($f) && is_file($f)) @unlink($f);
                }

                // обновляем запись
                if ($newMain) {
                    $stmt = $mysqli->prepare("UPDATE cars SET brand=?,model=?,year=?,body=?,mileage=?,transmission=?,fuel=?,price=?,description=?,photo=?,status=? WHERE id=?");
                    $stmt->bind_param('ssissssdsisi', $brand,$model,$year,$body,$mileage,$transmission,$fuel,$price,$description,$newMain,$status,$id);
                } else {
                    $stmt = $mysqli->prepare("UPDATE cars SET brand=?,model=?,year=?,body=?,mileage=?,transmission=?,fuel=?,price=?,description=?,status=? WHERE id=?");
                    $stmt->bind_param('ssissssdsii', $brand,$model,$year,$body,$mileage,$transmission,$fuel,$price,$description,$status,$id);
                }
                $stmt->execute();

                // добавим доп. фото в car_photos (если таблица есть)
                if ($addedPhotos && isset($pdo)) {
                    $ins = $pdo->prepare("INSERT INTO car_photos (car_id, file_path, created_at) VALUES (:car, :path, NOW())");
                    foreach ($addedPhotos as $p) $ins->execute([':car'=>$id, ':path'=>$p]);
                } elseif ($addedPhotos && isset($mysqli)) {
                    $ins = $mysqli->prepare("INSERT INTO car_photos (car_id, file_path, created_at) VALUES (?, ?, NOW())");
                    foreach ($addedPhotos as $p) { $ins->bind_param('is', $id, $p); $ins->execute(); }
                }

                $mysqli->commit();
                $success = 'Изменения сохранены';
            } catch (Throwable $e) {
                $mysqli->rollback();
                $errors[] = 'Ошибка при сохранении: ' . $e->getMessage();
            }
        } else {
            // PDO path
            try {
                $pdo->beginTransaction();
                if ($newMain && !empty($car['photo'])) {
                    $old = $car['photo'];
                    $cand = __DIR__ . '/' . ltrim($old, '/');
                    if (file_exists($cand) && is_file($cand)) @unlink($cand);
                }
                if ($newMain) {
                    $upd = $pdo->prepare("UPDATE cars SET brand=:brand,model=:model,year=:year,body=:body,mileage=:mileage,transmission=:trans,fuel=:fuel,price=:price,description=:desc,photo=:photo,status=:status WHERE id=:id");
                    $upd->execute([
                        ':brand'=>$brand,':model'=>$model,':year'=>$year,':body'=>$body,':mileage'=>$mileage,
                        ':trans'=>$transmission,':fuel'=>$fuel,':price'=>$price,':desc'=>$description,':photo'=>$newMain,':status'=>$status,':id'=>$id
                    ]);
                } else {
                    $upd = $pdo->prepare("UPDATE cars SET brand=:brand,model=:model,year=:year,body=:body,mileage=:mileage,transmission=:trans,fuel=:fuel,price=:price,description=:desc,status=:status WHERE id=:id");
                    $upd->execute([
                        ':brand'=>$brand,':model'=>$model,':year'=>$year,':body'=>$body,':mileage'=>$mileage,
                        ':trans'=>$transmission,':fuel'=>$fuel,':price'=>$price,':desc'=>$description,':status'=>$status,':id'=>$id
                    ]);
                }

                if ($addedPhotos) {
                    $ins = $pdo->prepare("INSERT INTO car_photos (car_id, file_path, created_at) VALUES (:car, :path, NOW())");
                    foreach ($addedPhotos as $p) $ins->execute([':car'=>$id, ':path'=>$p]);
                }

                $pdo->commit();
                $success = 'Изменения сохранены';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Ошибка при сохранении: ' . $e->getMessage();
            }
        }

        // обновим $car с новыми данными
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            $st = $mysqli->prepare("SELECT * FROM cars WHERE id = ? LIMIT 1");
            $st->bind_param('i', $id);
            $st->execute();
            $res = $st->get_result();
            $car = $res ? $res->fetch_assoc() : $car;
            $st->close();
        } else {
            $st = $pdo->prepare("SELECT * FROM cars WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $car = $st->fetch(PDO::FETCH_ASSOC);
        }
    }
}

// загрузим дополнительные фото
$extraPhotos = [];
if (isset($mysqli) && $mysqli instanceof mysqli) {
    $stp = $mysqli->prepare("SELECT id, file_path FROM car_photos WHERE car_id = ? ORDER BY id ASC");
    $stp->bind_param('i', $id);
    $stp->execute();
    $res = $stp->get_result();
    if ($res) $extraPhotos = $res->fetch_all(MYSQLI_ASSOC);
} else {
    $stp = $pdo->prepare("SELECT id, file_path FROM car_photos WHERE car_id = ? ORDER BY id ASC");
    $stp->execute([$id]);
    $extraPhotos = $stp->fetchAll(PDO::FETCH_ASSOC);
}
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
.page{max-width:1100px;margin:18px auto;padding:14px;}
.card{background:#fff;border-radius:10px;box-shadow:0 8px 24px rgba(2,6,23,0.06);}
.card-body{padding:16px;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
@media(max-width:760px){.form-grid{grid-template-columns:1fr}}
label.block{display:block;font-weight:700;margin-bottom:6px;}
input,select,textarea{width:100%;padding:8px;border:1px solid #e6e9ef;border-radius:8px;box-sizing:border-box}
textarea{min-height:120px}
.photo-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.photo-row img{width:130px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #e6eef7}
.btn{background:#0b57a4;color:#fff;padding:8px 12px;border-radius:8px;border:0;cursor:pointer}
.del-photo{background:#fff6f6;color:#b91c1c;border:1px solid #f5c2c2;padding:6px 8px;border-radius:6px;text-decoration:none;display:inline-block;margin-top:6px}
.notice{margin-bottom:12px;padding:10px;border-radius:8px}
.notice.ok{background:#eafaf0;border:1px solid #cfead1;color:#116530}
.notice.err{background:#fff6f6;border:1px solid #f5c2c2;color:#8a1f1f}
</style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<div class="page">
  <div class="card">
    <div class="card-body">
      <h2 style="margin:0 0 10px;">Редактирование объявления #<?= (int)$car['id'] ?></h2>

      <?php if ($errors): ?><div class="notice err"><?= htmlspecialchars(implode(' · ', $errors)) ?></div><?php endif; ?>
      <?php if ($success): ?><div class="notice ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

      <form method="post" enctype="multipart/form-data">
        <div class="form-grid">
          <div>
            <label class="block">Бренд *</label>
            <input name="brand" value="<?= htmlspecialchars($car['brand'] ?? '') ?>" required>
          </div>
          <div>
            <label class="block">Модель *</label>
            <input name="model" value="<?= htmlspecialchars($car['model'] ?? '') ?>" required>
          </div>

          <div>
            <label class="block">Год *</label>
            <select name="year" required>
              <?php for ($y = $currentYear; $y >= $minYear; $y--): ?>
                <option value="<?= $y ?>" <?= ((int)$car['year'] === $y) ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>

          <div>
            <label class="block">Кузов</label>
            <input name="body" value="<?= htmlspecialchars($car['body'] ?? '') ?>">
          </div>

          <div>
            <label class="block">Пробег (км)</label>
            <input name="mileage" type="number" value="<?= htmlspecialchars($car['mileage'] ?? '') ?>">
          </div>
          <div>
            <label class="block">Коробка</label>
            <input name="transmission" value="<?= htmlspecialchars($car['transmission'] ?? '') ?>">
          </div>

          <div>
            <label class="block">Топливо</label>
            <input name="fuel" value="<?= htmlspecialchars($car['fuel'] ?? '') ?>">
          </div>
          <div>
            <label class="block">Цена (TMT)</label>
            <input name="price" type="number" step="0.01" value="<?= htmlspecialchars($car['price'] ?? '') ?>">
          </div>

          <?php if ($isAdmin): ?>
          <div>
            <label class="block">Статус</label>
            <select name="status">
              <option value="pending" <?= ($car['status'] ?? '') === 'pending' ? 'selected' : '' ?>>На модерации</option>
              <option value="approved" <?= ($car['status'] ?? '') === 'approved' ? 'selected' : '' ?>>Подтверждён</option>
              <option value="rejected" <?= ($car['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>Отклонён</option>
            </select>
          </div>
          <?php endif; ?>

          <div style="grid-column:1 / -1">
            <label class="block">Описание</label>
            <textarea name="description"><?= htmlspecialchars($car['description'] ?? '') ?></textarea>
          </div>

          <div>
            <label class="block">Основное фото (заменит текущее)</label>
            <input type="file" name="main_photo" accept="image/*">
            <?php if (!empty($car['photo'])): ?>
              <div class="small">Текущее:</div>
              <div class="photo-row"><img src="<?= htmlspecialchars((strpos($car['photo'],'/')===0) ? $car['photo'] : '/' . ltrim($car['photo'],'/')) ?>" alt="main"></div>
            <?php endif; ?>
          </div>

          <div>
            <label class="block">Доп. фото (добавить)</label>
            <input type="file" name="photos[]" multiple accept="image/*">
            <?php if (!empty($extraPhotos)): ?>
              <div class="small">Существующие дополнительные фото:</div>
              <div class="photo-row">
                <?php foreach ($extraPhotos as $ep): ?>
                  <div style="text-align:center">
                    <img src="<?= htmlspecialchars((strpos($ep['file_path'],'/')===0)? $ep['file_path'] : '/' . ltrim($ep['file_path'],'/')) ?>" alt="">
                    <div>
                      <a class="del-photo" href="/mehanik/public/delete_car_photo.php?id=<?= (int)$ep['id'] ?>&car_id=<?= (int)$car['id'] ?>" onclick="return confirm('Удалить фото?')">Удалить фото</a>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end">
          <a class="btn" href="/mehanik/public/my-cars.php" style="background:transparent;border:1px solid #e6eef7;color:#0b57a4;">Отмена</a>
          <button class="btn" type="submit">Сохранить</button>
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html>
