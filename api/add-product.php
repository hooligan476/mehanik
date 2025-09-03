<?php
// mehanik/api/add-product.php
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../db.php';

require_auth();
$user_id = $_SESSION['user']['id'] ?? 0;

$isAjax = (
  (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
  || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
);

// ---------- inputs ----------
$name         = trim($_POST['name'] ?? '');
$manufacturer = trim($_POST['manufacturer'] ?? '');
$quality = $_POST['quality'] ?? 'New';
$quality = in_array($quality, ['New','Used'], true) ? $quality : 'New';
$rating = isset($_POST['rating']) ? (float)$_POST['rating'] : 5.0;
$rating = max(0.1, min(9.9, round($rating,1)));
$availability = isset($_POST['availability']) ? (int)$_POST['availability'] : 0;
$price        = isset($_POST['price']) ? (float)$_POST['price'] : 0.0;

$brand_id     = ($_POST['brand_id']        ?? '') !== '' ? (int)$_POST['brand_id']        : null;
$model_id     = ($_POST['model_id']        ?? '') !== '' ? (int)$_POST['model_id']        : null;
$year_from    = ($_POST['year_from']       ?? '') !== '' ? (int)$_POST['year_from']       : null;
$year_to      = ($_POST['year_to']         ?? '') !== '' ? (int)$_POST['year_to']         : null;
$cpart        = ($_POST['complex_part_id'] ?? '') !== '' ? (int)$_POST['complex_part_id'] : null;
$comp         = ($_POST['component_id']    ?? '') !== '' ? (int)$_POST['component_id']    : null;

$desc         = trim($_POST['description'] ?? '');

// validate
if (!$name || $price <= 0 || !$brand_id || !$model_id) {
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8'); http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Название, бренд, модель и положительная цена обязательны']); 
  } else {
    header('Location: /mehanik/public/add-product.php?err=validation');
  }
  exit;
}

// sku
try {
  $sku = 'SKU-' . strtoupper(bin2hex(random_bytes(4)));
} catch (Throwable $e) {
  $sku = 'SKU-' . strtoupper(dechex(mt_rand(0, 0x7FFFFFFF)));
}

// upload dir
$uploadDir = __DIR__ . '/../uploads/products';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$allowed = ['image/jpeg','image/png','image/webp'];
$maxFileSize = 3 * 1024 * 1024; // 3MB

// determine next AUTO_INCREMENT for naming
$nextId = null;
if (isset($mysqli) && $mysqli instanceof mysqli) {
  if ($resAI = $mysqli->query("SELECT AUTO_INCREMENT AS ai FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'")) {
    if ($rowAI = $resAI->fetch_assoc()) $nextId = (int)$rowAI['ai'];
    $resAI->free();
  }
  if (!$nextId) {
    if ($resMax = $mysqli->query("SELECT MAX(id) AS max_id FROM products")) {
      $rowMax = $resMax->fetch_assoc();
      $nextId = (int)($rowMax['max_id'] ?? 0) + 1;
    }
  }
} else {
  // fallback
  $nextId = time();
}
$pad = str_pad($nextId, 9, '0', STR_PAD_LEFT);

// prepare arrays for file names
$logoName = '';
$mainPhotoName = '';
$extraFiles = []; // filenames (not full path), we'll insert into product_photos

// handle logo (optional)
if (!empty($_FILES['logo']['name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
  if ($_FILES['logo']['size'] > $maxFileSize) {
    echo json_encode(['ok'=>false,'error'=>'Логотип слишком большой']); exit;
  }
  $fType = mime_content_type($_FILES['logo']['tmp_name']);
  if (!in_array($fType, $allowed, true)) {
    echo json_encode(['ok'=>false,'error'=>'Недопустимый формат логотипа']); exit;
  }
  $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION)) ?: 'jpg';
  $logoName = 'logo_' . $pad . '.' . preg_replace('/[^a-z0-9]+/i','', $ext);
  if (!move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . '/' . $logoName)) {
    echo json_encode(['ok'=>false,'error'=>'Ошибка сохранения логотипа']); exit;
  }
}

// handle main photo (optional) - named as padded id
if (!empty($_FILES['photo']['name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
  if ($_FILES['photo']['size'] > $maxFileSize) {
    echo json_encode(['ok'=>false,'error'=>'Основное фото слишком большое']); exit;
  }
  $fType = mime_content_type($_FILES['photo']['tmp_name']);
  if (!in_array($fType, $allowed, true)) {
    echo json_encode(['ok'=>false,'error'=>'Недопустимый формат основного фото']); exit;
  }
  $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION)) ?: 'jpg';
  $mainPhotoName = $pad . '.' . preg_replace('/[^a-z0-9]+/i','', $ext);
  if (!move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . '/' . $mainPhotoName)) {
    echo json_encode(['ok'=>false,'error'=>'Ошибка сохранения основного фото']); exit;
  }
}

// handle additional photos (photos[] up to 10)
if (!empty($_FILES['photos']['name']) && is_array($_FILES['photos']['name'])) {
  $count = count($_FILES['photos']['name']);
  if ($count > 10) {
    echo json_encode(['ok'=>false,'error'=>'Максимум 10 файлов для фото']); exit;
  }
  for ($i=0;$i<$count;$i++) {
    if (!is_uploaded_file($_FILES['photos']['tmp_name'][$i])) continue;
    if ($_FILES['photos']['size'][$i] > $maxFileSize) { echo json_encode(['ok'=>false,'error'=>'Один из файлов слишком большой']); exit; }
    $tmp = $_FILES['photos']['tmp_name'][$i];
    $t = mime_content_type($tmp);
    if (!in_array($t, $allowed, true)) { echo json_encode(['ok'=>false,'error'=>'Неподдерживаемый формат одного из фото']); exit; }
    $ext = strtolower(pathinfo($_FILES['photos']['name'][$i], PATHINFO_EXTENSION)) ?: 'jpg';
    $fileName = $pad . '_' . ($i+1) . '.' . preg_replace('/[^a-z0-9]+/i','', $ext);
    if (!move_uploaded_file($tmp, $uploadDir . '/' . $fileName)) {
      echo json_encode(['ok'=>false,'error'=>'Ошибка сохранения одного из фото']); exit;
    }
    $extraFiles[] = $fileName;
  }
}

// Now insert into DB (mysqli preferred)
try {
  if (isset($mysqli) && $mysqli instanceof mysqli) {
    $mysqli->begin_transaction();

    // Insert product - include logo and main photo columns
    $sql = "
      INSERT INTO products(
        user_id, brand_id, model_id, year_from, year_to,
        complex_part_id, component_id, sku, name, manufacturer,
        quality, rating, availability, price, description, logo, photo, created_at
      ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
    ";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) throw new Exception('Prepare failed: ' . $mysqli->error);

    // types: 7 ints(i) + sku s + name s + manuf s + quality s + rating d + availability i + price d + desc s + logo s + photo s
    $types = 'iiiiiiissssdidsss'; // matches 17 columns before created_at
    // ensure variables exist for bind (use nulls when needed)
    $brand_id_b = $brand_id === null ? null : $brand_id;
    $model_id_b = $model_id === null ? null : $model_id;
    $year_from_b = $year_from === null ? null : $year_from;
    $year_to_b = $year_to === null ? null : $year_to;
    $cpart_b = $cpart === null ? null : $cpart;
    $comp_b = $comp === null ? null : $comp;

    $logoDb = $logoName ? '/mehanik/uploads/products/' . $logoName : null;
    $photoDb = $mainPhotoName ? '/mehanik/uploads/products/' . $mainPhotoName : null;

    // bind params
    $bind_ok = $stmt->bind_param(
      $types,
      $user_id,
      $brand_id_b,
      $model_id_b,
      $year_from_b,
      $year_to_b,
      $cpart_b,
      $comp_b,
      $sku,
      $name,
      $manufacturer,
      $quality,
      $rating,
      $availability,
      $price,
      $desc,
      $logoDb,
      $photoDb
    );
    if (!$bind_ok) throw new Exception('Bind failed: ' . $stmt->error);

    if (!$stmt->execute()) throw new Exception('Execute failed: ' . $stmt->error);
    $newId = $stmt->insert_id;
    $stmt->close();

    // insert extra photos into product_photos if any
    if (!empty($extraFiles)) {
      // ensure product_photos table exists (if not, try to create)
      $check = $mysqli->query("SHOW TABLES LIKE 'product_photos'");
      if ($check && $check->num_rows === 0) {
        $mysqli->query("
          CREATE TABLE IF NOT EXISTS product_photos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            filename VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (product_id),
            CONSTRAINT fk_product_photos_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
      }
      $stmt2 = $mysqli->prepare("INSERT INTO product_photos (product_id, filename) VALUES (?, ?)");
      if (!$stmt2) throw new Exception('Prepare product_photos failed: ' . $mysqli->error);
      foreach ($extraFiles as $fn) {
        $stmt2->bind_param('is', $newId, $fn);
        if (!$stmt2->execute()) throw new Exception('Insert photo failed: ' . $stmt2->error);
      }
      $stmt2->close();
    }

    $mysqli->commit();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'id'=>$newId,'sku'=>$sku]);
    exit;

  } elseif (isset($pdo) && $pdo instanceof PDO) {
    // PDO branch (similar logic)
    $pdo->beginTransaction();
    $sql = "INSERT INTO products(
      user_id, brand_id, model_id, year_from, year_to,
      complex_part_id, component_id, sku, name, manufacturer,
      quality, rating, availability, price, description, logo, photo, created_at
    ) VALUES (:user_id,:brand_id,:model_id,:year_from,:year_to,:complex_part_id,:component_id,:sku,:name,:manufacturer,:quality,:rating,:availability,:price,:description,:logo,:photo,NOW())";
    $st = $pdo->prepare($sql);
    $st->execute([
      ':user_id'=>$user_id,
      ':brand_id'=>$brand_id,
      ':model_id'=>$model_id,
      ':year_from'=>$year_from,
      ':year_to'=>$year_to,
      ':complex_part_id'=>$cpart,
      ':component_id'=>$comp,
      ':sku'=>$sku,
      ':name'=>$name,
      ':manufacturer'=>$manufacturer,
      ':quality'=>$quality,
      ':rating'=>$rating,
      ':availability'=>$availability,
      ':price'=>$price,
      ':description'=>$desc,
      ':logo'=>$logoDb ?? null,
      ':photo'=>$photoDb ?? null
    ]);
    $newId = $pdo->lastInsertId();

    if (!empty($extraFiles)) {
      $pdo->exec("CREATE TABLE IF NOT EXISTS product_photos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            filename VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (product_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      ");
      $st2 = $pdo->prepare("INSERT INTO product_photos (product_id, filename) VALUES (:pid, :fn)");
      foreach ($extraFiles as $fn) $st2->execute([':pid'=>$newId, ':fn'=>$fn]);
    }

    $pdo->commit();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'id'=>$newId,'sku'=>$sku]);
    exit;
  } else {
    throw new Exception('No DB connection available');
  }
} catch (Throwable $e) {
  // cleanup files on error
  foreach (array_merge([$logoName,$mainPhotoName], $extraFiles) as $f) if ($f) @unlink($uploadDir . '/' . $f);
  if (isset($mysqli) && $mysqli instanceof mysqli) { @$mysqli->rollback(); }
  if (isset($pdo) && $pdo instanceof PDO) { @$pdo->rollBack(); }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>'Ошибка сервера: ' . $e->getMessage()]);
  exit;
}
