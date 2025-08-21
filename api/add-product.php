<?php
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');

require_auth();
$user_id = $_SESSION['user']['id'];

// Получаем данные из формы
$name         = trim($_POST['name'] ?? '');
$sku          = trim($_POST['sku'] ?? '');
$manufacturer = trim($_POST['manufacturer'] ?? '');
$quality      = $_POST['quality'] ?? 'New';
$availability = isset($_POST['availability']) ? (int)$_POST['availability'] : 0;
$price        = isset($_POST['price']) ? (float)$_POST['price'] : 0.0;
$brand_id     = !empty($_POST['brand_id']) ? (int)$_POST['brand_id'] : null;
$model_id     = !empty($_POST['model_id']) ? (int)$_POST['model_id'] : null;
$year_from    = $_POST['year_from'] !== '' ? (int)$_POST['year_from'] : null;
$year_to      = $_POST['year_to'] !== '' ? (int)$_POST['year_to'] : null;
$cpart        = !empty($_POST['complex_part_id']) ? (int)$_POST['complex_part_id'] : null;
$comp         = !empty($_POST['component_id']) ? (int)$_POST['component_id'] : null;
$desc         = trim($_POST['description'] ?? '');

// Проверки
if (!$name || $price <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Название и цена обязательны']);
  exit;
}

// Работа с фото
$photoPath = null;
if (!empty($_FILES['photo']['name'])) {
  $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
  $fname = uniqid('p_') . '.' . $ext;
  $dest = __DIR__ . '/../uploads/products/' . $fname;
  if (!is_dir(dirname($dest))) {
    @mkdir(dirname($dest), 0755, true);
  }
  if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
    $photoPath = '/mehanik/uploads/products/' . $fname;
  }
}

// Вставка
$stmt = $mysqli->prepare("
  INSERT INTO products(
    user_id, brand_id, model_id, year_from, year_to,
    complex_part_id, component_id, sku, name, manufacturer,
    quality, rating, availability, price, description, photo, created_at
  ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
");

if (!$stmt) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Prepare failed: ' . $mysqli->error]);
  exit;
}

// rating по умолчанию = 0.0
$rating = 0.0;

$types = 'iiiiiiissssiidss'; 
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
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Bind failed: ' . $stmt->error]);
  exit;
}

if ($stmt->execute()) {
  echo json_encode(['ok' => true, 'id' => $stmt->insert_id]);
} else {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $stmt->error]);
}
