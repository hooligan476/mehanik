<?php
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../db.php';

require_auth();
$user_id = $_SESSION['user']['id'] ?? 0;

// Определим AJAX vs обычный POST
$isAjax = (
  (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
  || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
);

// ---------- Получаем данные ----------
$name         = trim($_POST['name'] ?? '');
$manufacturer = trim($_POST['manufacturer'] ?? '');

// quality — строка (New/Used)
$quality = $_POST['quality'] ?? 'New';
$quality = in_array($quality, ['New','Used'], true) ? $quality : 'New';

// rating — число 0.1–9.9
$rating = isset($_POST['rating']) ? (float)$_POST['rating'] : 5.0;
if ($rating < 0.1) $rating = 0.1;
if ($rating > 9.9) $rating = 9.9;
$rating = round($rating, 1);

$availability = isset($_POST['availability']) ? (int)$_POST['availability'] : 0;
$price        = isset($_POST['price']) ? (float)$_POST['price'] : 0.0;

$brand_id     = ($_POST['brand_id']        ?? '') !== '' ? (int)$_POST['brand_id']        : null;
$model_id     = ($_POST['model_id']        ?? '') !== '' ? (int)$_POST['model_id']        : null;
$year_from    = ($_POST['year_from']       ?? '') !== '' ? (int)$_POST['year_from']       : null;
$year_to      = ($_POST['year_to']         ?? '') !== '' ? (int)$_POST['year_to']         : null;
$cpart        = ($_POST['complex_part_id'] ?? '') !== '' ? (int)$_POST['complex_part_id'] : null;
$comp         = ($_POST['component_id']    ?? '') !== '' ? (int)$_POST['component_id']    : null;

$desc         = trim($_POST['description'] ?? '');

// Валидация
if (!$name || $price <= 0) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Название и положительная цена обязательны']);
  } else {
    header('Location: /mehanik/public/add-product.php?err=validation');
  }
  exit;
}

// ---------- SKU ----------
try {
  $sku = 'SKU-' . strtoupper(bin2hex(random_bytes(4)));
} catch (Throwable $e) {
  $sku = 'SKU-' . strtoupper(dechex(mt_rand(0, 0x7FFFFFFF)));
}

// ---------- Фото: 000000001.jpg ----------
$photoPath = null;
if (!empty($_FILES['photo']['name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
  // Попытка взять AUTO_INCREMENT для products
  $nextId = null;
  if ($resAI = $mysqli->query("SELECT AUTO_INCREMENT AS ai FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'")) {
    if ($rowAI = $resAI->fetch_assoc()) {
      $nextId = (int)$rowAI['ai'];
    }
    $resAI->free();
  }
  if (!$nextId) {
    $resMax = $mysqli->query("SELECT MAX(id) AS max_id FROM products");
    $rowMax = $resMax ? $resMax->fetch_assoc() : ['max_id' => 0];
    $nextId = (int)($rowMax['max_id'] ?? 0) + 1;
  }

  $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
  if ($ext === '') $ext = 'jpg';
  $fname = str_pad($nextId, 9, '0', STR_PAD_LEFT) . '.' . $ext;

  $destDir = __DIR__ . '/../uploads/products';
  $dest = $destDir . '/' . $fname;

  if (!is_dir($destDir)) {
    @mkdir($destDir, 0755, true);
  }

  if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
    $photoPath = '/mehanik/uploads/products/' . $fname;
  }
}

// ---------- INSERT ----------
$sql = "
  INSERT INTO products(
    user_id, brand_id, model_id, year_from, year_to,
    complex_part_id, component_id, sku, name, manufacturer,
    quality, rating, availability, price, description, photo, created_at
  ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
";
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Prepare failed: ' . $mysqli->error]);
  } else {
    header('Location: /mehanik/public/add-product.php?err=prepare');
  }
  exit;
}

$types = 'iiiiiiissssdidss';
$bind_ok = $stmt->bind_param(
  $types,
  $user_id,
  $brand_id,
  $model_id,
  $year_from,
  $year_to,
  $cpart,
  $comp,
  $sku,
  $name,
  $manufacturer,
  $quality,
  $rating,
  $availability,
  $price,
  $desc,
  $photoPath
);

if (!$bind_ok) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Bind failed: ' . $stmt->error]);
  } else {
    header('Location: /mehanik/public/add-product.php?err=bind');
  }
  exit;
}

if ($stmt->execute()) {
  $newId = $stmt->insert_id;
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'id' => $newId, 'sku' => $sku]);
  } else {
    // PRG: обычная отправка формы -> редирект на страницу товара
    header('Location: /mehanik/public/product.php?id=' . $newId);
  }
  exit;
} else {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $stmt->error]);
  } else {
    header('Location: /mehanik/public/add-product.php?err=execute');
  }
  exit;
}
