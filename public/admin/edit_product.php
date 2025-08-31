<?php
require_once __DIR__.'/../../middleware.php';
require_admin();
require_once __DIR__.'/../../db.php';
require_once __DIR__.'/../../config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die("Неверный ID товара");
}

// Загружаем товар
$stmt = $mysqli->prepare("SELECT * FROM products WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$product = $res->fetch_assoc();
$stmt->close();

if (!$product) {
    die("Товар не найден");
}

// Обработка сохранения
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $manufacturer = trim($_POST['manufacturer'] ?? '');
    $quality = $_POST['quality'] ?? 'New';
    $availability = (int)($_POST['availability'] ?? 0);
    $price = (float)($_POST['price'] ?? 0);
    $desc = trim($_POST['description'] ?? '');

    $brand_id = !empty($_POST['brand_id']) ? (int)$_POST['brand_id'] : null;
    $model_id = !empty($_POST['model_id']) ? (int)$_POST['model_id'] : null;
    $year_from = $_POST['year_from'] !== '' ? (int)$_POST['year_from'] : null;
    $year_to = $_POST['year_to'] !== '' ? (int)$_POST['year_to'] : null;
    $cpart = !empty($_POST['complex_part_id']) ? (int)$_POST['complex_part_id'] : null;
    $comp = !empty($_POST['component_id']) ? (int)$_POST['component_id'] : null;

    $photoPath = $product['photo']; // старое фото
    if (!empty($_FILES['photo']['name'])) {
        $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $fname = uniqid('p_') . '.' . $ext;
        $dest = __DIR__ . '/../../uploads/products/' . $fname;
        if (!is_dir(dirname($dest))) {
            @mkdir(dirname($dest), 0755, true);
        }
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
            $photoPath = '/mehanik/uploads/products/' . $fname;
        }
    }

    $stmt = $mysqli->prepare("
        UPDATE products
        SET brand_id=?, model_id=?, year_from=?, year_to=?, complex_part_id=?, component_id=?,
            name=?, manufacturer=?, quality=?, availability=?, price=?, description=?, photo=?
        WHERE id=?
    ");
    $stmt->bind_param(
        'iiiiisssidssi',
        $brand_id, $model_id, $year_from, $year_to, $cpart, $comp,
        $name, $manufacturer, $quality, $availability, $price, $desc, $photoPath,
        $id
    );

    if ($stmt->execute()) {
        header("Location: ".$config['base_url']."/admin/products.php");
        exit;
    } else {
        $error = $stmt->error;
    }
    $stmt->close();
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Редактировать товар #<?= htmlspecialchars($id) ?></title>
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    body { font-family: Arial, sans-serif; background:#f4f6f9; margin:0; }
    .container { max-width:900px; margin:30px auto; background:#fff; padding:25px; border-radius:8px; box-shadow:0 2px 6px rgba(0,0,0,.1);}
    h2 { margin-top:0; }
    form { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
    label { display:flex; flex-direction:column; font-weight:bold; font-size:14px; color:#333; }
    input[type="text"], input[type="number"], select, textarea, input[type="file"] {
        padding:8px; border:1px solid #ccc; border-radius:5px; font-size:14px; margin-top:5px;
    }
    textarea { min-height:80px; resize:vertical; }
    .full { grid-column:1 / -1; }
    .photo-preview { margin-top:10px; }
    .photo-preview img { max-height:120px; border:1px solid #ddd; padding:3px; border-radius:5px; }
    button { padding:10px 20px; border:none; border-radius:5px; background:#007bff; color:#fff; font-size:15px; cursor:pointer; transition:.2s; }
    button:hover { background:#0056b3; }
    .alert { background:#fdd; border:1px solid #f99; padding:10px; border-radius:5px; color:#900; margin-bottom:15px; }
    a.back { display:inline-block; margin-bottom:15px; color:#007bff; text-decoration:none; }
    a.back:hover { text-decoration:underline; }
  </style>
</head>
<body>
  <?php require_once __DIR__.'/header.php'; ?>
  <div class="container">
    <h2>Редактировать товар #<?= htmlspecialchars($id) ?></h2>
    <a href="<?= htmlspecialchars($config['base_url'].'/admin/products.php') ?>" class="back">← Назад к товарам</a>

    <?php if (!empty($error)): ?>
      <div class="alert">Ошибка: <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
      <label class="full">Название
        <input type="text" name="name" value="<?= htmlspecialchars($product['name']) ?>">
      </label>

      <label>Артикул (SKU)
        <input type="text" value="<?= htmlspecialchars($product['sku']) ?>" readonly>
      </label>

      <label>Производитель
        <input type="text" name="manufacturer" value="<?= htmlspecialchars($product['manufacturer']) ?>">
      </label>

      <label>Качество
        <select name="quality">
          <option value="New" <?= $product['quality']=='New'?'selected':'' ?>>New</option>
          <option value="Used" <?= $product['quality']=='Used'?'selected':'' ?>>Used</option>
        </select>
      </label>

      <label>Доступно
        <input type="number" name="availability" value="<?= htmlspecialchars($product['availability']) ?>">
      </label>

      <label>Цена (TMT)
        <input type="number" step="0.01" name="price" value="<?= htmlspecialchars($product['price']) ?>">
      </label>

      <label class="full">Описание
        <textarea name="description"><?= htmlspecialchars($product['description']) ?></textarea>
      </label>

      <label>Brand ID
        <input type="number" name="brand_id" value="<?= htmlspecialchars($product['brand_id']) ?>">
      </label>
      <label>Model ID
        <input type="number" name="model_id" value="<?= htmlspecialchars($product['model_id']) ?>">
      </label>
      <label>Год от
        <input type="number" name="year_from" value="<?= htmlspecialchars($product['year_from']) ?>">
      </label>
      <label>Год до
        <input type="number" name="year_to" value="<?= htmlspecialchars($product['year_to']) ?>">
      </label>
      <label>Complex Part ID
        <input type="number" name="complex_part_id" value="<?= htmlspecialchars($product['complex_part_id']) ?>">
      </label>
      <label>Component ID
        <input type="number" name="component_id" value="<?= htmlspecialchars($product['component_id']) ?>">
      </label>

      <div class="full">
        <p><strong>Текущее фото:</strong></p>
        <div class="photo-preview">
          <?php if ($product['photo']): ?>
            <img src="<?= htmlspecialchars($product['photo']) ?>" alt="Фото">
          <?php else: ?>
            (нет)
          <?php endif; ?>
        </div>
      </div>

      <label class="full">Новое фото
        <input type="file" name="photo">
      </label>

      <div class="full" style="text-align:right;">
        <button type="submit">💾 Сохранить</button>
      </div>
    </form>
  </div>
</body>
</html>
