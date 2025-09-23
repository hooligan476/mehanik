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

// contact phone (hidden input filled by the form or fallback to session)
$contact_phone = trim($_POST['contact_phone'] ?? $_SESSION['user']['phone'] ?? '');

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

// SKU generation — store *without* "SKU-" prefix
try {
    $sku = strtoupper(bin2hex(random_bytes(4)));
} catch (Throwable $e) {
    $sku = strtoupper(str_pad(dechex(mt_rand(0, 0x7FFFFFFF)), 8, '0', STR_PAD_LEFT));
}

// upload dir (web-accessible path stored as prefix, filesystem path for saving)
$webPrefix = '/mehanik/uploads/products/';
$uploadDir = __DIR__ . '/../uploads/products';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$allowed = ['image/jpeg','image/png','image/webp'];
$maxFileSize = 3 * 1024 * 1024; // 3MB

// helper to sanitize extension
$cleanExt = function($ext){
    $ext = strtolower($ext);
    $ext = preg_replace('/[^a-z0-9]+/i','', $ext);
    return $ext ?: 'jpg';
};

// helper to respond with error
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

// We'll save uploaded files first to uploads/products/ with temporary unique names,
// then AFTER successful DB insert we'll create uploads/products/{id}/ and move files there,
// rename them to consistent names (logo_{id}.ext, photo_{id}.ext, photo_{id}_1.ext, ...)
// and finally update products.logo / products.photo and insert product_photos with final paths.

// Track temporary saved files (basename in uploads/products)
$tmpLogo = null;        // basename or null
$tmpMain = null;        // basename or null
$tmpExtras = [];        // array of basenames

// handle logo (optional)
if (!empty($_FILES['logo']['name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
    if ($_FILES['logo']['size'] > $maxFileSize) {
        respondWithError('Логотип слишком большой');
    }
    $fType = @mime_content_type($_FILES['logo']['tmp_name']);
    if (!in_array($fType, $allowed, true)) respondWithError('Недопустимый формат логотипа');
    $ext = $cleanExt(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
    $tmpName = uniqid('tmp_logo_', true) . '.' . $ext;
    if (!move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . '/' . $tmpName)) {
        respondWithError('Ошибка сохранения логотипа');
    }
    $tmpLogo = $tmpName;
}

// handle main photo (optional)
if (!empty($_FILES['photo']['name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
    if ($_FILES['photo']['size'] > $maxFileSize) respondWithError('Основное фото слишком большое');
    $fType = @mime_content_type($_FILES['photo']['tmp_name']);
    if (!in_array($fType, $allowed, true)) respondWithError('Недопустимый формат основного фото');
    $ext = $cleanExt(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    $tmpName = uniqid('tmp_photo_', true) . '.' . $ext;
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . '/' . $tmpName)) respondWithError('Ошибка сохранения основного фото');
    $tmpMain = $tmpName;
}

// handle additional photos (photos[] up to 10)
if (!empty($_FILES['photos']['name']) && is_array($_FILES['photos']['name'])) {
    $count = count($_FILES['photos']['name']);
    if ($count > 10) respondWithError('Максимум 10 файлов для фото');
    for ($i=0;$i<$count;$i++) {
        if (!is_uploaded_file($_FILES['photos']['tmp_name'][$i])) continue;
        if ($_FILES['photos']['size'][$i] > $maxFileSize) respondWithError('Один из файлов слишком большой');
        $tmp = $_FILES['photos']['tmp_name'][$i];
        $t = @mime_content_type($tmp);
        if (!in_array($t, $allowed, true)) respondWithError('Неподдерживаемый формат одного из фото');
        $ext = $cleanExt(pathinfo($_FILES['photos']['name'][$i], PATHINFO_EXTENSION));
        $tmpName = uniqid('tmp_photo_extra_', true) . '.' . $ext;
        if (!move_uploaded_file($tmp, $uploadDir . '/' . $tmpName)) respondWithError('Ошибка сохранения одного из фото');
        $tmpExtras[] = $tmpName;
    }
}

// detect whether products table has contact_phone column
$hasContactPhone = false;
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $r = $mysqli->query("SHOW COLUMNS FROM products LIKE 'contact_phone'");
        if ($r && $r->num_rows > 0) $hasContactPhone = true;
        if ($r) $r->free();
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $st = $pdo->query("SHOW COLUMNS FROM products LIKE 'contact_phone'");
        $row = $st->fetch(PDO::FETCH_NUM);
        if ($row) $hasContactPhone = true;
    }
} catch (Throwable $_) { $hasContactPhone = false; }

try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $mysqli->begin_transaction();

        // Build dynamic columns and values (NOTE: do NOT include logo/photo here — we'll update them after moving files)
        $cols = [
            'user_id','brand_id','model_id','year_from','year_to',
            'complex_part_id','component_id','sku','name','manufacturer',
            'quality','rating','availability','price','description'
        ];
        $values = [
            $user_id,
            $brand_id === null ? null : $brand_id,
            $model_id === null ? null : $model_id,
            $year_from === null ? null : $year_from,
            $year_to === null ? null : $year_to,
            $cpart === null ? null : $cpart,
            $comp === null ? null : $comp,
            $sku,
            $name,
            $manufacturer,
            $quality,
            $rating,
            $availability,
            $price,
            $desc
        ];

        if ($hasContactPhone) {
            $cols[] = 'contact_phone';
            $values[] = $contact_phone !== '' ? $contact_phone : null;
        }

        // status
        $cols[] = 'status';
        $values[] = 'active';

        // placeholders
        $placeholders = array_fill(0, count($values), '?');
        $sql = "INSERT INTO products (" . implode(',', $cols) . ", created_at) VALUES (" . implode(',', $placeholders) . ", NOW())";

        // build types string dynamically
        $types = '';
        foreach ($values as $v) {
            if (is_int($v)) $types .= 'i';
            elseif (is_float($v) || is_double($v)) $types .= 'd';
            else $types .= 's';
        }

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) throw new Exception('Prepare failed: ' . $mysqli->error);

        // build references for bind_param
        $bindParams = [];
        $bindParams[] = & $types;
        for ($i = 0; $i < count($values); $i++) {
            ${"p".$i} = $values[$i];
            $bindParams[] = &${"p".$i};
        }

        if (!call_user_func_array([$stmt, 'bind_param'], $bindParams)) {
            throw new Exception('Bind failed: ' . $stmt->error);
        }

        if (!$stmt->execute()) throw new Exception('Execute failed: ' . $stmt->error);
        $newId = $stmt->insert_id;
        $stmt->close();

        // Create product folder and move files into it, rename consistently
        $prodDir = $uploadDir . '/' . (int)$newId;
        if (!is_dir($prodDir)) {
            if (!mkdir($prodDir, 0755, true)) throw new Exception('Не удалось создать папку для продукта');
        }

        // final web paths to write to DB
        $finalLogoPath = null;
        $finalPhotoPath = null;
        $finalExtrasWeb = [];

        // move logo
        if ($tmpLogo) {
            $ext = pathinfo($tmpLogo, PATHINFO_EXTENSION);
            $finalName = 'logo_' . (int)$newId . '.' . $ext;
            if (!@rename($uploadDir . '/' . $tmpLogo, $prodDir . '/' . $finalName)) throw new Exception('Не удалось переместить логотип');
            $finalLogoPath = $webPrefix . $newId . '/' . $finalName;
        }

        // move main photo
        if ($tmpMain) {
            $ext = pathinfo($tmpMain, PATHINFO_EXTENSION);
            $finalName = 'photo_' . (int)$newId . '.' . $ext;
            if (!@rename($uploadDir . '/' . $tmpMain, $prodDir . '/' . $finalName)) throw new Exception('Не удалось переместить основное фото');
            $finalPhotoPath = $webPrefix . $newId . '/' . $finalName;
        }

        // move extras
        $i = 1;
        foreach ($tmpExtras as $tmp) {
            $ext = pathinfo($tmp, PATHINFO_EXTENSION);
            $finalName = 'photo_' . (int)$newId . '_' . $i . '.' . $ext;
            if (!@rename($uploadDir . '/' . $tmp, $prodDir . '/' . $finalName)) throw new Exception('Не удалось переместить одно из фото');
            $finalExtrasWeb[] = $webPrefix . $newId . '/' . $finalName;
            $i++;
        }

        // update products table with logo/photo if present
        if ($finalLogoPath !== null || $finalPhotoPath !== null) {
            $updParts = [];
            $updVals = [];
            if ($finalLogoPath !== null) { $updParts[] = 'logo = ?'; $updVals[] = $finalLogoPath; }
            if ($finalPhotoPath !== null) { $updParts[] = 'photo = ?'; $updVals[] = $finalPhotoPath; }
            $updVals[] = $newId;
            $typesUpd = str_repeat('s', count($updVals)-1) . 'i'; // final param is id
            $sqlUpd = "UPDATE products SET " . implode(',', $updParts) . " WHERE id = ?";
            $stUpd = $mysqli->prepare($sqlUpd);
            if (!$stUpd) throw new Exception('Prepare update product failed: ' . $mysqli->error);
            $bind = [];
            $bind[] = & $typesUpd;
            for ($k=0;$k<count($updVals);$k++){
                ${"u".$k} = $updVals[$k];
                $bind[] = &${"u".$k};
            }
            if (!call_user_func_array([$stUpd, 'bind_param'], $bind)) throw new Exception('Bind update failed: ' . $stUpd->error);
            if (!$stUpd->execute()) throw new Exception('Execute update failed: ' . $stUpd->error);
            $stUpd->close();
        }

        // insert product_photos if any
        if (!empty($finalExtrasWeb)) {
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
            foreach ($finalExtrasWeb as $fn) {
                $stmt2->bind_param('is', $newId, $fn);
                if (!$stmt2->execute()) throw new Exception('Insert photo failed: ' . $stmt2->error);
            }
            $stmt2->close();
        }

        $mysqli->commit();

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>true,'id'=>$newId,'sku'=>$sku]);
            exit;
        } else {
            header('Location: /mehanik/public/product.php?id=' . (int)$newId);
            exit;
        }

    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $pdo->beginTransaction();

        // Build dynamic columns and params for PDO (omit logo/photo for now)
        $cols = [
            'user_id','brand_id','model_id','year_from','year_to',
            'complex_part_id','component_id','sku','name','manufacturer',
            'quality','rating','availability','price','description'
        ];
        $params = [
            ':user_id' => $user_id,
            ':brand_id' => $brand_id,
            ':model_id' => $model_id,
            ':year_from' => $year_from,
            ':year_to' => $year_to,
            ':complex_part_id' => $cpart,
            ':component_id' => $comp,
            ':sku' => $sku,
            ':name' => $name,
            ':manufacturer' => $manufacturer,
            ':quality' => $quality,
            ':rating' => $rating,
            ':availability' => $availability,
            ':price' => $price,
            ':description' => $desc
        ];

        if ($hasContactPhone) {
            $cols[] = 'contact_phone';
            $params[':contact_phone'] = $contact_phone !== '' ? $contact_phone : null;
        }

        // status
        $cols[] = 'status';
        $params[':status'] = 'active';

        $sql = "INSERT INTO products (" . implode(',', $cols) . ", created_at) VALUES (" . implode(',', array_keys($params)) . ", NOW())";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $newId = $pdo->lastInsertId();

        // Create product folder and move files into it, rename consistently
        $prodDir = $uploadDir . '/' . (int)$newId;
        if (!is_dir($prodDir)) {
            if (!mkdir($prodDir, 0755, true)) throw new Exception('Не удалось создать папку для продукта');
        }

        // final web paths to write to DB
        $finalLogoPath = null;
        $finalPhotoPath = null;
        $finalExtrasWeb = [];

        // move logo
        if ($tmpLogo) {
            $ext = pathinfo($tmpLogo, PATHINFO_EXTENSION);
            $finalName = 'logo_' . (int)$newId . '.' . $ext;
            if (!@rename($uploadDir . '/' . $tmpLogo, $prodDir . '/' . $finalName)) throw new Exception('Не удалось переместить логотип');
            $finalLogoPath = $webPrefix . $newId . '/' . $finalName;
        }

        // move main photo
        if ($tmpMain) {
            $ext = pathinfo($tmpMain, PATHINFO_EXTENSION);
            $finalName = 'photo_' . (int)$newId . '.' . $ext;
            if (!@rename($uploadDir . '/' . $tmpMain, $prodDir . '/' . $finalName)) throw new Exception('Не удалось переместить основное фото');
            $finalPhotoPath = $webPrefix . $newId . '/' . $finalName;
        }

        // move extras
        $i = 1;
        foreach ($tmpExtras as $tmp) {
            $ext = pathinfo($tmp, PATHINFO_EXTENSION);
            $finalName = 'photo_' . (int)$newId . '_' . $i . '.' . $ext;
            if (!@rename($uploadDir . '/' . $tmp, $prodDir . '/' . $finalName)) throw new Exception('Не удалось переместить одно из фото');
            $finalExtrasWeb[] = $webPrefix . $newId . '/' . $finalName;
            $i++;
        }

        // update products table with logo/photo if present
        if ($finalLogoPath !== null || $finalPhotoPath !== null) {
            $updParts = [];
            $paramsUpd = [':id' => $newId];
            if ($finalLogoPath !== null) { $updParts[] = 'logo = :logo'; $paramsUpd[':logo'] = $finalLogoPath; }
            if ($finalPhotoPath !== null) { $updParts[] = 'photo = :photo'; $paramsUpd[':photo'] = $finalPhotoPath; }
            $sqlUpd = "UPDATE products SET " . implode(',', $updParts) . " WHERE id = :id";
            $stUpd = $pdo->prepare($sqlUpd);
            $stUpd->execute($paramsUpd);
        }

        // insert product_photos if any
        if (!empty($finalExtrasWeb)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS product_photos (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX (product_id)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $st2 = $pdo->prepare("INSERT INTO product_photos (product_id, file_path) VALUES (:pid, :fn)");
            foreach ($finalExtrasWeb as $fn) $st2->execute([':pid'=>$newId, ':fn'=>$fn]);
        }

        $pdo->commit();

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>true,'id'=>$newId,'sku'=>$sku]);
            exit;
        } else {
            header('Location: /mehanik/public/product.php?id=' . (int)$newId);
            exit;
        }

    } else {
        throw new Exception('No DB connection available');
    }

} catch (Throwable $e) {
    // cleanup files on error (tmp files)
    $toRemove = [];
    if (!empty($tmpLogo)) $toRemove[] = $uploadDir . '/' . $tmpLogo;
    if (!empty($tmpMain)) $toRemove[] = $uploadDir . '/' . $tmpMain;
    foreach ($tmpExtras as $t) $toRemove[] = $uploadDir . '/' . $t;
    foreach ($toRemove as $f) if ($f && file_exists($f)) @unlink($f);

    // In case we partially moved files into a product folder, try to remove that folder
    // (best-effort cleanup — don't stop error handling)
    if (!empty($newId)) {
        $prodDir = $uploadDir . '/' . (int)$newId;
        if (is_dir($prodDir)) {
            $files = glob($prodDir . '/*');
            if (is_array($files)) foreach ($files as $ff) @unlink($ff);
            @rmdir($prodDir);
        }
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
        header('Location: /mehanik/public/add-product.php?err=server');
    }
    exit;
}
