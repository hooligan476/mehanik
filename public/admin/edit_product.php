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
    $sku = trim($_POST['sku'] ?? '');
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
            sku=?, name=?, manufacturer=?, quality=?, availability=?, price=?, description=?, photo=?
        WHERE id=?
    ");
    $stmt->bind_param(
        'iiiiiiisssidssi',
        $brand_id, $model_id, $year_from, $year_to, $cpart, $comp,
        $sku, $name, $manufacturer, $quality, $availability, $price, $desc, $photoPath,
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
</head>
<body>
  <?php require_once __DIR__.'/header.php'; ?>
  <h2>Редактировать товар #<?= htmlspecialchars($id) ?></h2>
  <p><a href="<?= htmlspecialchars($config['base_url'].'/admin/products.php') ?>">← Назад к товарам</a></p>

  <?php if (!empty($error)): ?>
    <div class="alert">Ошибка: <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <label>Название: <input type="text" name="name" value="<?= htmlspecialchars($product['name']) ?>"></label><br>
    <label>SKU: <input type="text" name="sku" value="<?= htmlspecialchars($product['sku']) ?>"></label><br>
    <label>Производитель: <input type="text" name="manufacturer" value="<?= htmlspecialchars($product['manufacturer']) ?>"></label><br>
    <label>Качество:
      <select name="quality">
        <option value="New" <?= $product['quality']=='New'?'selected':'' ?>>New</option>
        <option value="Used" <?= $product['quality']=='Used'?'selected':'' ?>>Used</option>
      </select>
    </label><br>
    <label>Доступно: <input type="number" name="availability" value="<?= htmlspecialchars($product['availability']) ?>"></label><br>
    <label>Цена: <input type="number" step="0.01" name="price" value="<?= htmlspecialchars($product['price']) ?>"></label><br>
    <label>Описание:<br><textarea name="description"><?= htmlspecialchars($product['description']) ?></textarea></label><br>

    <label>Brand ID: <input type="number" name="brand_id" value="<?= htmlspecialchars($product['brand_id']) ?>"></label><br>
    <label>Model ID: <input type="number" name="model_id" value="<?= htmlspecialchars($product['model_id']) ?>"></label><br>
    <label>Год от: <input type="number" name="year_from" value="<?= htmlspecialchars($product['year_from']) ?>"></label><br>
    <label>Год до: <input type="number" name="year_to" value="<?= htmlspecialchars($product['year_to']) ?>"></label><br>
    <label>Complex Part ID: <input type="number" name="complex_part_id" value="<?= htmlspecialchars($product['complex_part_id']) ?>"></label><br>
    <label>Component ID: <input type="number" name="component_id" value="<?= htmlspecialchars($product['component_id']) ?>"></label><br>

    <p>Текущее фото: 
      <?php if ($product['photo']): ?>
        <img src="<?= htmlspecialchars($product['photo']) ?>" alt="Фото" style="max-height:100px;">
      <?php else: ?>
        (нет)
      <?php endif; ?>
    </p>
    <label>Новое фото: <input type="file" name="photo"></label><br><br>

    <button type="submit">Сохранить</button>
  </form>
</body>
</html>
