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

// Обработка сохранения
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Входные данные (защищённо)
    $name = trim($_POST['name'] ?? '');
    $manufacturer = trim($_POST['manufacturer'] ?? '');
    $quality = trim($_POST['quality'] ?? '');
    $rating = isset($_POST['rating']) ? (float)$_POST['rating'] : 0.0;
    $availability = isset($_POST['availability']) ? (int)$_POST['availability'] : 0;
    $price = isset($_POST['price']) ? (float)$_POST['price'] : 0.0;
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
        $errors[] = 'Название не может быть пустым.';
    }

    // Обновление фото (если загружено новое)
    $photoPath = $product['photo'];
    if (isset($_FILES['photo']) && !empty($_FILES['photo']['name'])) {
        // Настройки
        $uploadDir = __DIR__ . '/../uploads/products/';
        $publicPrefix = '/mehanik/uploads/products/';
        $allowedMimes = ['image/jpeg','image/png','image/webp'];
        $maxSize = 5 * 1024 * 1024; // 5 MB

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $file = $_FILES['photo'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Ошибка загрузки файла.';
        } elseif ($file['size'] > $maxSize) {
            $errors[] = 'Файл слишком большой (макс 5MB).';
        } else {
            // MIME sniffing
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, $allowedMimes)) {
                $errors[] = 'Недопустимый тип файла. Используйте JPG, PNG или WEBP.';
            } else {
                // расширение
                $ext = '.jpg';
                if ($mime === 'image/png') $ext = '.png';
                if ($mime === 'image/webp') $ext = '.webp';

                // уникальное имя
                $baseName = time() . '_' . bin2hex(random_bytes(6));
                $fileName = $baseName . $ext;
                $targetFile = $uploadDir . $fileName;

                if (move_uploaded_file($file['tmp_name'], $targetFile)) {
                    // удаляем старый файл
                    if (!empty($photoPath)) {
                        $old = $photoPath;
                        if (strpos($old, $publicPrefix) === 0) {
                            $oldRel = substr($old, strlen($publicPrefix));
                            $oldAbs = $uploadDir . $oldRel;
                        } elseif (strpos($old, '/') === 0) {
                            $oldAbs = __DIR__ . '/..' . $old;
                        } else {
                            $oldAbs = $uploadDir . $old;
                        }
                        if (!empty($oldAbs) && is_file($oldAbs)) {
                            @unlink($oldAbs);
                        }
                    }
                    $photoPath = $publicPrefix . $fileName;
                } else {
                    $errors[] = 'Не удалось сохранить файл на сервере.';
                }
            }
        }
    }

    // Если нет ошибок — обновляем запись + сбрасываем статус на pending
    if (empty($errors)) {
        $update = $mysqli->prepare("
            UPDATE products 
            SET name = ?, manufacturer = ?, quality = ?, rating = ?, availability = ?, price = ?, description = ?, photo = ?, status = 'pending'
            WHERE id = ?
        ");
        if (!$update) {
            $errors[] = 'Ошибка подготовки запроса: ' . $mysqli->error;
        } else {
            $update->bind_param(
                "sssdidssi",
                $name,
                $manufacturer,
                $quality,
                $rating,
                $availability,
                $price,
                $description,
                $photoPath,
                $id
            );
            if (!$update->execute()) {
                $errors[] = 'Ошибка сохранения: ' . $update->error;
            }
            $update->close();
        }
    }

    if (empty($errors)) {
        header("Location: /mehanik/public/product.php?id=" . $id);
        exit;
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
    .container { max-width:980px; margin:24px auto; padding:12px; }
    .card { background:#fff; border-radius:12px; padding:18px; box-shadow:0 8px 24px rgba(2,6,23,.06); }
    label{ display:block; margin-top:10px; font-weight:600; }
    input, select, textarea { width:100%; padding:10px 12px; border:1px solid #e6e9ef; border-radius:8px; box-sizing:border-box; }
    .thumb { margin-top:8px; }
    .actions { margin-top:14px; display:flex; gap:10px; }
    .btn-primary { background:linear-gradient(180deg,#0b57a4,#074b82); color:#fff; padding:10px 12px; border-radius:8px; border:0; cursor:pointer; font-weight:700; }
    .btn-secondary { padding:10px 12px; border-radius:8px; border:1px solid #e6e9ef; background:#fff; cursor:pointer; }
    .errors { background:#fff6f6; color:#9b1c1c; padding:10px; border-radius:8px; margin-bottom:10px; }
  </style>
</head>
<body>
<div class="container">
  <h2>Редактировать товар</h2>

  <?php if (!empty($errors)): ?>
    <div class="errors">
      <ul style="margin:0 0 0 18px;padding:0;">
        <?php foreach ($errors as $err): ?>
          <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card">
    <label>Название</label>
    <input type="text" name="name" value="<?= htmlspecialchars($product['name']) ?>" required>

    <label>Производитель</label>
    <input type="text" name="manufacturer" value="<?= htmlspecialchars($product['manufacturer']) ?>">

    <label>Качество</label>
    <select name="quality">
      <option value="New" <?= ($product['quality'] === 'New' || $product['quality'] === 'Новый') ? 'selected' : '' ?>>New</option>
      <option value="Used" <?= ($product['quality'] === 'Used' || $product['quality'] === 'Б/У') ? 'selected' : '' ?>>Used</option>
    </select>

    <label>Рейтинг</label>
    <input type="number" step="0.1" min="0" max="10" name="rating" value="<?= htmlspecialchars($product['rating']) ?>">

    <label>Наличие (шт)</label>
    <input type="number" name="availability" value="<?= htmlspecialchars($product['availability']) ?>">

    <label>Цена (TMT)</label>
    <input type="number" step="0.01" name="price" value="<?= htmlspecialchars($product['price']) ?>">

    <label>Описание</label>
    <textarea name="description" rows="5"><?= htmlspecialchars($product['description']) ?></textarea>

    <label>Фото</label>
    <?php if (!empty($product['photo'])): ?>
      <div class="thumb">
        <img src="<?= htmlspecialchars($product['photo']) ?>" alt="" style="max-height:150px;border-radius:8px;box-shadow:0 6px 18px rgba(2,6,23,.06);">
      </div>
    <?php endif; ?>
    <input type="file" name="photo" accept="image/*">

    <div class="actions">
      <button type="submit" class="btn-primary">💾 Сохранить</button>
      <a href="/mehanik/public/product.php?id=<?= (int)$id ?>" class="btn-secondary">Отмена</a>
    </div>
  </form>
</div>
</body>
</html>
