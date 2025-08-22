<?php
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');

require_auth();
$user_id = $_SESSION['user']['id'];

// Получаем данные из формы
$name         = trim($_POST['name'] ?? '');
$manufacturer = trim($_POST['manufacturer'] ?? '');
$quality      = isset($_POST['quality']) ? (float)$_POST['quality'] : 5.0; // от 0.1 до 9.9
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

// === SKU (генерация, неменяемый) ===
$sku = "SKU-" . strtoupper(bin2hex(random_bytes(4)));

// === Фото (номер по порядку) ===
$photoPath = null;
if (!empty($_FILES['photo']['name'])) {
  // Узнаем последний ID
  $res = $mysqli->query("SELECT MAX(id) as max_id FROM products");
  $row = $res->fetch_assoc();
  $nextId = ($row['max_id'] ?? 0) + 1;

  $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
  $fname = str_pad($nextId, 9, "0", STR_PAD_LEFT) . '.' . strtolower($ext);

  $dest = __DIR__ . '/../uploads/products/' . $fname;
  if (!is_dir(dirname($dest))) {
    @mkdir(dirname($dest), 0755, true);
  }
  if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
    $photoPath = '/mehanik/uploads/products/' . $fname;
  }
}

// === Вставка ===
$stmt = $mysqli->prepare("
  INSERT INTO products(
    user_id, brand_id, model_id, year_from, year_to,
    complex_part_id, component_id, sku, name, manufacturer,
    quality, availability, price, description, photo, created_at
  ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
");

if (!$stmt) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Prepare failed: ' . $mysqli->error]);
  exit;
}

$types = 'iiiiiiisssdi dss'; // поправлено под новые типы
$bind_ok = $stmt->bind_param(
  "iiiiiiisssdidss",
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
  echo json_encode(['ok' => true, 'id' => $stmt->insert_id, 'sku' => $sku]);
} else {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $stmt->error]);
}
