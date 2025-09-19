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

// Validation
if (!$name || $price <= 0 || !$brand_id || !$model_id) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8', true, 422);
        echo json_encode(['ok' => false, 'error' => 'Название, бренд, модель и положительная цена обязательны']);
    } else {
        header('Location: /mehanik/public/add-product.php?err=validation');
    }
    exit;
}

// -------------------------------------------------
// SKU generation — store *without* "SKU-" prefix
// -------------------------------------------------
try {
    // create 8-символьный HEX код (e.g. A1B2C3D4)
    $sku = strtoupper(bin2hex(random_bytes(4)));
} catch (Throwable $e) {
    // fallback: generate pseudo-random hex and pad to 8 chars
    $sku = strtoupper(str_pad(dechex(mt_rand(0, 0x7FFFFFFF)), 8, '0', STR_PAD_LEFT));
}

// upload dir (web-accessible path stored as prefix, filesystem path for saving)
$webPrefix = '/mehanik/uploads/products/';
$uploadDir = __DIR__ . '/../uploads/products';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$allowed = ['image/jpeg','image/png','image/webp'];
$maxFileSize = 3 * 1024 * 1024; // 3MB

// determine next AUTO_INCREMENT for naming (best-effort)
$nextId = null;
if (isset($mysqli) && $mysqli instanceof mysqli) {
    $resAI = $mysqli->query("SELECT AUTO_INCREMENT AS ai FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'");
    if ($resAI && $rowAI = $resAI->fetch_assoc()) $nextId = (int)$rowAI['ai'];
    if ($resAI) $resAI->free();
    if (!$nextId) {
      $resMax = $mysqli->query("SELECT MAX(id) AS max_id FROM products");
      if ($resMax && ($rowMax = $resMax->fetch_assoc())) $nextId = (int)($rowMax['max_id'] ?? 0) + 1;
      if ($resMax) $resMax->free();
    }
} elseif (isset($pdo) && $pdo instanceof PDO) {
    try {
        $st = $pdo->query("SELECT AUTO_INCREMENT AS ai FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'");
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r && !empty($r['ai'])) $nextId = (int)$r['ai'];
        if (!$nextId) {
            $st2 = $pdo->query("SELECT MAX(id) AS max_id FROM products");
            $r2 = $st2->fetch(PDO::FETCH_ASSOC);
            $nextId = (int)($r2['max_id'] ?? 0) + 1;
        }
    } catch (Throwable $e) {
        $nextId = time();
    }
} else {
    $nextId = time();
}

$pad = str_pad((string)$nextId, 9, '0', STR_PAD_LEFT);

// file name arrays
$logoName = null;
$mainPhotoName = null;
$extraFiles = []; // store full web paths to insert into product_photos

// helper to sanitize extension
$cleanExt = function($ext){
    $ext = strtolower($ext);
    $ext = preg_replace('/[^a-z0-9]+/i','', $ext);
    return $ext ?: 'jpg';
};

// helper to respond with error (declared early for use)
function respondWithError($msg) {
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8', true, 422);
        echo json_encode(['ok'=>false,'error'=>$msg]);
    } else {
        header('Location: /mehanik/public/add-product.php?err=' . urlencode($msg));
    }
    exit;
}

// handle logo (optional)
if (!empty($_FILES['logo']['name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
    if ($_FILES['logo']['size'] > $maxFileSize) {
        respondWithError('Логотип слишком большой');
    }
    $fType = mime_content_type($_FILES['logo']['tmp_name']);
    if (!in_array($fType, $allowed, true)) respondWithError('Недопустимый формат логотипа');
    $ext = $cleanExt(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
    $logoName = 'logo_' . $pad . '.' . $ext;
    if (!move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . '/' . $logoName)) {
        respondWithError('Ошибка сохранения логотипа');
    }
    $logoDb = $webPrefix . $logoName;
} else {
    $logoDb = null;
}

// handle main photo (optional)
if (!empty($_FILES['photo']['name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
    if ($_FILES['photo']['size'] > $maxFileSize) respondWithError('Основное фото слишком большое');
    $fType = mime_content_type($_FILES['photo']['tmp_name']);
    if (!in_array($fType, $allowed, true)) respondWithError('Недопустимый формат основного фото');
    $ext = $cleanExt(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    $mainPhotoName = $pad . '.' . $ext;
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . '/' . $mainPhotoName)) respondWithError('Ошибка сохранения основного фото');
    $photoDb = $webPrefix . $mainPhotoName;
} else {
    $photoDb = null;
}

// handle additional photos (photos[] up to 10)
if (!empty($_FILES['photos']['name']) && is_array($_FILES['photos']['name'])) {
    $count = count($_FILES['photos']['name']);
    if ($count > 10) respondWithError('Максимум 10 файлов для фото');
    for ($i=0;$i<$count;$i++) {
        if (!is_uploaded_file($_FILES['photos']['tmp_name'][$i])) continue;
        if ($_FILES['photos']['size'][$i] > $maxFileSize) respondWithError('Один из файлов слишком большой');
        $tmp = $_FILES['photos']['tmp_name'][$i];
        $t = mime_content_type($tmp);
        if (!in_array($t, $allowed, true)) respondWithError('Неподдерживаемый формат одного из фото');
        $ext = $cleanExt(pathinfo($_FILES['photos']['name'][$i], PATHINFO_EXTENSION));
        $fileName = $pad . '_' . ($i+1) . '.' . $ext;
        if (!move_uploaded_file($tmp, $uploadDir . '/' . $fileName)) respondWithError('Ошибка сохранения одного из фото');
        $extraFiles[] = $webPrefix . $fileName;
    }
}

// Insert into DB
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $mysqli->begin_transaction();

        $sql = "
          INSERT INTO products (
            user_id, brand_id, model_id, year_from, year_to,
            complex_part_id, component_id, sku, name, manufacturer,
            quality, rating, availability, price, description, logo, photo, created_at, status
          ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),'active')
        ";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) throw new Exception('Prepare failed: ' . $mysqli->error);

        // prepare values (use nulls where appropriate)
        $brand_id_b = $brand_id === null ? null : $brand_id;
        $model_id_b = $model_id === null ? null : $model_id;
        $year_from_b = $year_from === null ? null : $year_from;
        $year_to_b = $year_to === null ? null : $year_to;
        $cpart_b = $cpart === null ? null : $cpart;
        $comp_b = $comp === null ? null : $comp;

        // types: i i i i i i i s s s s d i d s s (17)
        // 'i' for integers, 'd' for double, 's' for string
        $types = 'iiiiiiissssdidsss';

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

        // create product_photos table if missing (uses file_path column and stores web path)
        if (!empty($extraFiles)) {
            $check = $mysqli->query("SHOW TABLES LIKE 'product_photos'");
            if ($check && $check->num_rows === 0) {
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
            $stmt2 = $mysqli->prepare("INSERT INTO product_photos (product_id, file_path) VALUES (?, ?)");
            if (!$stmt2) throw new Exception('Prepare product_photos failed: ' . $mysqli->error);
            foreach ($extraFiles as $fn) {
                $stmt2->bind_param('is', $newId, $fn);
                if (!$stmt2->execute()) throw new Exception('Insert photo failed: ' . $stmt2->error);
            }
            $stmt2->close();
        }

        $mysqli->commit();

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>true,'id'=>$newId,'sku'=>$sku]); // sku without SKU- prefix
            exit;
        } else {
            header('Location: /mehanik/public/product.php?id=' . (int)$newId);
            exit;
        }

    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $pdo->beginTransaction();

        $sql = "INSERT INTO products (
            user_id, brand_id, model_id, year_from, year_to,
            complex_part_id, component_id, sku, name, manufacturer,
            quality, rating, availability, price, description, logo, photo, created_at, status
          ) VALUES (
            :user_id, :brand_id, :model_id, :year_from, :year_to,
            :complex_part_id, :component_id, :sku, :name, :manufacturer,
            :quality, :rating, :availability, :price, :description, :logo, :photo, NOW(), 'active'
          )";
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
                product_id INT NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX (product_id)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $st2 = $pdo->prepare("INSERT INTO product_photos (product_id, file_path) VALUES (:pid, :fn)");
            foreach ($extraFiles as $fn) $st2->execute([':pid'=>$newId, ':fn'=>$fn]);
        }

        $pdo->commit();

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>true,'id'=>$newId,'sku'=>$sku]); // sku without SKU- prefix
            exit;
        } else {
            header('Location: /mehanik/public/product.php?id=' . (int)$newId);
            exit;
        }

    } else {
        throw new Exception('No DB connection available');
    }

} catch (Throwable $e) {
    // cleanup files on error
    foreach (array_filter([$logoName,$mainPhotoName]) as $f) {
        if ($f && file_exists($uploadDir . '/' . $f)) @unlink($uploadDir . '/' . $f);
    }
    // cleanup extraFiles (they are web paths — strip prefix to unlink)
    foreach ($extraFiles as $web) {
        $fn = basename($web);
        if ($fn && file_exists($uploadDir . '/' . $fn)) @unlink($uploadDir . '/' . $fn);
    }

    if (isset($mysqli) && $mysqli instanceof mysqli) {
        @$mysqli->rollback();
    }
    if (isset($pdo) && $pdo instanceof PDO) {
        try { @$pdo->rollBack(); } catch (Throwable $_) {}
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8', true, 500);
        echo json_encode(['ok'=>false,'error'=>'Ошибка сервера: ' . $e->getMessage()]);
    } else {
        // simple redirect with error (you can change to better UX)
        header('Location: /mehanik/public/add-product.php?err=server');
    }
    exit;
}
