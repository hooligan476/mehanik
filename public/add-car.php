<?php
// public/add-car.php
require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php';

$basePublic = '/mehanik/public';
$currentYear = (int)date('Y');
$minYear = $currentYear - 25;
$user_id = $_SESSION['user']['id'] ?? 0;
$user_phone = $_SESSION['user']['phone'] ?? '';

// detect AJAX
$isAjax = (
  (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
  || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
);

// helper
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function jsonOk($data=[]){ header('Content-Type: application/json; charset=utf-8'); echo json_encode(array_merge(['ok'=>true], $data), JSON_UNESCAPED_UNICODE); exit; }
function jsonError($msg='Ошибка'){ header('Content-Type: application/json; charset=utf-8', true, 500); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

// ---------- load brands ----------
$brands = [];
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $r = $mysqli->query("SELECT id, name FROM brands ORDER BY name");
        if ($r) while ($row = $r->fetch_assoc()) $brands[] = $row;
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $st = $pdo->query("SELECT id, name FROM brands ORDER BY name");
        $brands = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log("add-car: load brands error: " . $e->getMessage());
    $brands = [];
}

// ---------- load new lookups ----------
$car_colors = [];
$engine_volumes = [];
$passenger_counts = [];
$interior_colors = [];
$upholstery_types = [];
$ignition_types = [];
$regions = [];
$districts_by_region = [];

// NEW: lookups for years, gearboxes, fuel types
$vehicle_years = [];
$gearboxes = [];
$fuel_types = [];

try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $r = $mysqli->query("SELECT id, name, `key`, `order`, active FROM car_colors ORDER BY `order` ASC, name ASC");
        if ($r) while ($row = $r->fetch_assoc()) $car_colors[] = $row;

        $r = $mysqli->query("SELECT id, label, `order`, active FROM engine_volumes ORDER BY `order` ASC, label ASC");
        if ($r) while ($row = $r->fetch_assoc()) $engine_volumes[] = $row;

        $r = $mysqli->query("SELECT id, cnt, label, `order`, active FROM passenger_counts ORDER BY `order` ASC, cnt ASC");
        if ($r) while ($row = $r->fetch_assoc()) $passenger_counts[] = $row;

        $r = $mysqli->query("SELECT id, name, `order`, active FROM interior_colors ORDER BY `order` ASC, name ASC");
        if ($r) while ($row = $r->fetch_assoc()) $interior_colors[] = $row;

        $r = $mysqli->query("SELECT id, name, `order`, active FROM upholstery_types ORDER BY `order` ASC, name ASC");
        if ($r) while ($row = $r->fetch_assoc()) $upholstery_types[] = $row;

        $r = $mysqli->query("SELECT id, name, `key`, `order`, active FROM ignition_types ORDER BY `order` ASC, name ASC");
        if ($r) while ($row = $r->fetch_assoc()) $ignition_types[] = $row;

        // regions + districts
        $r = $mysqli->query("SELECT id, name, `order`, active FROM regions ORDER BY `order` ASC, name ASC");
        if ($r) while ($row = $r->fetch_assoc()) $regions[] = $row;

        $r = $mysqli->query("SELECT id, region_id, name FROM districts ORDER BY region_id ASC, `order` ASC, name ASC");
        if ($r) while ($row = $r->fetch_assoc()) {
            $districts_by_region[(int)$row['region_id']][] = $row;
        }

        // vehicle_years
        $r = $mysqli->query("SELECT `year`, `active` FROM vehicle_years ORDER BY `year` DESC");
        if ($r) while ($row = $r->fetch_assoc()) $vehicle_years[] = $row;

        // gearboxes (transmissions)
        $r = $mysqli->query("SELECT id, name, `order`, active FROM gearboxes ORDER BY `order` ASC, name ASC");
        if ($r) while ($row = $r->fetch_assoc()) $gearboxes[] = $row;

        // fuel types
        $r = $mysqli->query("SELECT id, name, `order`, active FROM fuel_types ORDER BY `order` ASC, name ASC");
        if ($r) while ($row = $r->fetch_assoc()) $fuel_types[] = $row;

    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $car_colors = $pdo->query("SELECT id, name, `key`, `order`, active FROM car_colors ORDER BY `order` ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $engine_volumes = $pdo->query("SELECT id, label, `order`, active FROM engine_volumes ORDER BY `order` ASC, label ASC")->fetchAll(PDO::FETCH_ASSOC);
        $passenger_counts = $pdo->query("SELECT id, cnt, label, `order`, active FROM passenger_counts ORDER BY `order` ASC, cnt ASC")->fetchAll(PDO::FETCH_ASSOC);
        $interior_colors = $pdo->query("SELECT id, name, `order`, active FROM interior_colors ORDER BY `order` ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $upholstery_types = $pdo->query("SELECT id, name, `order`, active FROM upholstery_types ORDER BY `order` ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $ignition_types = $pdo->query("SELECT id, name, `key`, `order`, active FROM ignition_types ORDER BY `order` ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $regions = $pdo->query("SELECT id, name, `order`, active FROM regions ORDER BY `order` ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $rows = $pdo->query("SELECT id, region_id, name FROM districts ORDER BY region_id ASC, `order` ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $districts_by_region[(int)$r['region_id']][] = $r;

        $vehicle_years = $pdo->query("SELECT `year`, `active` FROM vehicle_years ORDER BY `year` DESC")->fetchAll(PDO::FETCH_ASSOC);
        $gearboxes = $pdo->query("SELECT id, name, `order`, active FROM gearboxes ORDER BY `order` ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $fuel_types = $pdo->query("SELECT id, name, `order`, active FROM fuel_types ORDER BY `order` ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log("add-car: load new lookups error: " . $e->getMessage());
}

// ---------- vehicle types & bodies (existing code) ----------
$types_from_db = [];
$bodies_by_type_id = [];
$use_db_types = false;
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $res = $mysqli->query("SHOW TABLES LIKE 'vehicle_types'");
        if ($res && $res->num_rows > 0) {
            $use_db_types = true;
            $r = $mysqli->query("SELECT id, `key`, name, `order` FROM vehicle_types ORDER BY `order` ASC, name ASC");
            while ($row = $r->fetch_assoc()) $types_from_db[(int)$row['id']] = $row;
            $r2 = $mysqli->query("SELECT * FROM vehicle_bodies ORDER BY vehicle_type_id ASC, `order` ASC, name ASC");
            while ($b = $r2->fetch_assoc()) $bodies_by_type_id[(int)$b['vehicle_type_id']][] = $b;
        }
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $st = $pdo->query("SHOW TABLES LIKE 'vehicle_types'");
        $has = $st->fetch(PDO::FETCH_NUM);
        if ($has) {
            $use_db_types = true;
            $st2 = $pdo->query("SELECT id, `key`, name, `order` FROM vehicle_types ORDER BY `order` ASC, name ASC");
            $rows = $st2->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) $types_from_db[(int)$r['id']] = $r;
            $st3 = $pdo->query("SELECT * FROM vehicle_bodies ORDER BY vehicle_type_id ASC, `order` ASC, name ASC");
            $rows2 = $st3->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows2 as $b) $bodies_by_type_id[(int)$b['vehicle_type_id']][] = $b;
        }
    }
} catch (Throwable $_) {
    $use_db_types = false;
}

$vehicle_types_fallback = [
    'passenger' => 'Легковые',
    'cargo' => 'Грузовые',
    'agro' => 'Агро техника',
    'construction' => 'Строй техника',
    'motorcycle' => 'Мото/скутеры',
    'other' => 'Другое'
];
$vehicle_bodies_fallback = [
    'passenger' => [
        ['id'=>'sedan','name'=>'Седан'],
        ['id'=>'hatch','name'=>'Хэтчбек'],
        ['id'=>'wagon','name'=>'Универсал'],
        ['id'=>'suv','name'=>'SUV / Внедорожник'],
        ['id'=>'coupe','name'=>'Купе'],
        ['id'=>'minivan','name'=>'Минивэн'],
        ['id'=>'pickup','name'=>'Пикап'],
    ],
    'cargo' => [
        ['id'=>'box','name'=>'Фургон'],
        ['id'=>'flat','name'=>'Платформа'],
        ['id'=>'tanker','name'=>'Цистерна'],
    ],
    'agro' => [
        ['id'=>'tractor','name'=>'Трактор'],
        ['id'=>'combine','name'=>'Комбайн'],
    ],
    'construction' => [
        ['id'=>'bulldozer','name'=>'Бульдозер'],
        ['id'=>'excavator','name'=>'Экскаватор'],
    ],
    'motorcycle' => [
        ['id'=>'bike','name'=>'Мотоцикл'],
        ['id'=>'scooter','name'=>'Скутер'],
    ],
    'other' => [
        ['id'=>'other','name'=>'Другое'],
    ],
];

$vehicle_types_select = [];
$vehicle_bodies_js = [];

if ($use_db_types && count($types_from_db) > 0) {
    foreach ($types_from_db as $tid => $t) {
        $vehicle_types_select[$tid] = $t['name'];
        $vehicle_bodies_js[$tid] = [];
        foreach ($bodies_by_type_id[$tid] ?? [] as $b) {
            $vehicle_bodies_js[$tid][] = ['id' => (int)$b['id'], 'name' => $b['name'], 'key' => $b['key'] ?? null];
        }
    }
} else {
    foreach ($vehicle_types_fallback as $k => $v) {
        $vehicle_types_select[$k] = $v;
        $vehicle_bodies_js[$k] = $vehicle_bodies_fallback[$k] ?? [];
    }
}

// ---------- POST handling (saving) ----------
$errors = [];
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // collect fields
    $brand_id = isset($_POST['brand_id']) && $_POST['brand_id'] !== '' ? (int)$_POST['brand_id'] : null;
    $model_id = isset($_POST['model_id']) && $_POST['model_id'] !== '' ? (int)$_POST['model_id'] : null;
    $brand_text = trim($_POST['brand'] ?? '');
    $model_text = trim($_POST['model'] ?? '');

    // resolve brand/model names
    $brand_save = '';
    if ($brand_id) {
        foreach ($brands as $b) {
            if ((int)$b['id'] === (int)$brand_id) { $brand_save = $b['name']; break; }
        }
    }
    if (!$brand_save) $brand_save = $brand_text;

    $model_save = '';
    if ($model_id) {
        try {
            if (isset($mysqli) && $mysqli instanceof mysqli) {
                $st = $mysqli->prepare("SELECT name FROM models WHERE id = ? LIMIT 1");
                if ($st) { $st->bind_param('i', $model_id); $st->execute(); $res = $st->get_result(); $r = $res->fetch_assoc(); if ($r) $model_save = $r['name']; $st->close(); }
            } elseif (isset($pdo) && $pdo instanceof PDO) {
                $st = $pdo->prepare("SELECT name FROM models WHERE id = :id LIMIT 1");
                $st->execute([':id'=>$model_id]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if ($r) $model_save = $r['name'];
            }
        } catch (Throwable $_) { /* ignore */ }
    }
    if (!$model_save) $model_save = $model_text;

    // other fields
    $year = (int)($_POST['year'] ?? 0);
    $vehicle_type_raw = trim($_POST['vehicle_type'] ?? '');
    $body_raw = trim($_POST['body'] ?? '');
    $mileage = (int)($_POST['mileage'] ?? 0);
    $transmission = trim($_POST['transmission'] ?? '');
    $fuel = trim($_POST['fuel'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? $user_phone);
    $vin = trim($_POST['vin'] ?? '');
    // new fields
    $color = trim($_POST['color'] ?? '');
    $engine_volume = trim($_POST['engine_volume'] ?? '');
    $passengers = isset($_POST['passengers']) && $_POST['passengers'] !== '' ? (int)$_POST['passengers'] : null;
    $interior_color = trim($_POST['interior_color'] ?? '');
    $upholstery = trim($_POST['upholstery'] ?? '');
    $ignition_type = trim($_POST['ignition_type'] ?? '');
    $region_id = isset($_POST['region_id']) && $_POST['region_id'] !== '' ? (int)$_POST['region_id'] : null;
    $district_id = isset($_POST['district_id']) && $_POST['district_id'] !== '' ? (int)$_POST['district_id'] : null;

    // optionally resolve region/district names to save
    $region_save = '';
    $district_save = '';
    if ($region_id) {
        foreach ($regions as $r) if ((int)$r['id'] === (int)$region_id) { $region_save = $r['name']; break; }
    }
    if ($district_id && is_array($districts_by_region[$region_id] ?? [])) {
        foreach ($districts_by_region[$region_id] as $d) if ((int)$d['id'] === (int)$district_id) { $district_save = $d['name']; break; }
    }

    // convert vehicle type/body
    $vehicle_type_save = '';
    $body_save = '';
    if ($use_db_types) {
        $vt_id = is_numeric($vehicle_type_raw) ? (int)$vehicle_type_raw : null;
        if ($vt_id && isset($types_from_db[$vt_id])) {
            $trow = $types_from_db[$vt_id];
            $vehicle_type_save = ($trow['key'] && trim($trow['key']) !== '') ? $trow['key'] : $trow['name'];
            if (is_numeric($body_raw)) {
                $b_id = (int)$body_raw;
                $found = null;
                foreach ($bodies_by_type_id[$vt_id] ?? [] as $b) {
                    if ((int)$b['id'] === $b_id) { $found = $b; break; }
                }
                if ($found) $body_save = $found['name'];
            } else {
                $body_save = $body_raw;
            }
        } else {
            $vehicle_type_save = $vehicle_type_raw;
            $body_save = $body_raw;
        }
    } else {
        $vehicle_type_save = $vehicle_type_raw;
        $body_save = $body_raw;
    }

    // validation
    if ($brand_save === '') $errors[] = 'Бренд обязателен';
    if ($model_save === '') $errors[] = 'Модель обязательна';
    if ($year < $minYear || $year > $currentYear) $errors[] = "Год должен быть в диапазоне {$minYear}—{$currentYear}";
    if ($price < 0) $errors[] = 'Цена некорректна';
    // require fuel and transmission
    if (trim($transmission) === '') $errors[] = 'Коробка передач обязательна';
    if (trim($fuel) === '') $errors[] = 'Тип топлива обязателен';

    // ---------- FILES: collect uploaded file info (do NOT move yet) ----------
    $accepted_exts = ['jpg','jpeg','png','webp'];
    $max_files = 10; // increased from 6 to 10
    $uploadPending = []; // each: ['tmp'=>..., 'orig'=>..., 'ext'=>...]
    $savedMainIndex = null;

    try {
        // main photo field
        if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
            $orig = basename($_FILES['photo']['name'] ?? '');
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (in_array($ext, $accepted_exts, true) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_OK) {
                $uploadPending[] = ['tmp' => $_FILES['photo']['tmp_name'], 'orig' => $orig, 'ext' => $ext];
                $savedMainIndex = count($uploadPending) - 1;
            }
        }

        // other photos
        if (!empty($_FILES['photos']) && is_array($_FILES['photos']['tmp_name'])) {
            $cnt = count($_FILES['photos']['tmp_name']);
            for ($i=0; $i<$cnt && count($uploadPending) < $max_files; $i++) {
                if (!is_uploaded_file($_FILES['photos']['tmp_name'][$i])) continue;
                $err = $_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                if ($err !== UPLOAD_ERR_OK) continue;
                $orig = basename($_FILES['photos']['name'][$i] ?? '');
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                if (!in_array($ext, $accepted_exts, true)) continue;
                $uploadPending[] = ['tmp' => $_FILES['photos']['tmp_name'][$i], 'orig' => $orig, 'ext' => $ext];
            }
        }
    } catch (Throwable $e) {
        error_log("add-car: file collect error: " . $e->getMessage());
        $errors[] = 'Ошибка при обработке загружаемых файлов';
    }

    if ($savedMainIndex === null && count($uploadPending) > 0) $savedMainIndex = 0;

    // VIN handling as before
    $useVinColumn = false;
    try {
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            $res = $mysqli->query("SHOW COLUMNS FROM cars LIKE 'vin'");
            if ($res && $res->num_rows > 0) $useVinColumn = true;
        } elseif (isset($pdo) && $pdo instanceof PDO) {
            $st = $pdo->query("SHOW COLUMNS FROM cars LIKE 'vin'");
            if ($st && $st->fetchColumn() !== false) $useVinColumn = true;
        }
    } catch (Throwable $_) { $useVinColumn = false; }

    if (!$useVinColumn && $vin !== '') {
        $description = "VIN: " . $vin . "\n\n" . $description;
    }

    // vehicle_type column check
    $useVehicleTypeColumn = false;
    try {
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            $res = $mysqli->query("SHOW COLUMNS FROM cars LIKE 'vehicle_type'");
            if ($res && $res->num_rows > 0) $useVehicleTypeColumn = true;
        } elseif (isset($pdo) && $pdo instanceof PDO) {
            $st = $pdo->query("SHOW COLUMNS FROM cars LIKE 'vehicle_type'");
            if ($st && $st->fetchColumn() !== false) $useVehicleTypeColumn = true;
        }
    } catch (Throwable $_) { $useVehicleTypeColumn = false; }

    // If no errors - insert
    if (empty($errors)) {
        try {
            // --- build columns/values dynamically (exclude created_at, we'll use NOW()) ---
            // NOTE: new fields (color, engine_volume, passengers, interior_color, upholstery,
            // ignition_type, region, district) are included here so they are saved.
            $cols = [
                'user_id','brand','model','year','body','mileage','transmission','fuel','price','photo','description','contact_phone',
                'color','engine_volume','passengers','interior_color','upholstery','ignition_type','region','district'
            ];

            // note: photo will be empty now; we'll update after moving files
            $savedMain = ''; // placeholder, will update after moving
            $values = [
                $user_id,
                $brand_save,
                $model_save,
                $year,
                $body_save,
                $mileage,
                $transmission,
                $fuel,
                $price,
                $savedMain,
                $description,
                $contact_phone,
                $color,
                $engine_volume,
                $passengers,
                $interior_color,
                $upholstery,
                $ignition_type,
                $region_save,
                $district_save
            ];

            // optional vin / vehicle_type columns
            if ($useVinColumn) {
                $cols[] = 'vin';
                $values[] = $vin;
            }
            if ($useVehicleTypeColumn) {
                $cols[] = 'vehicle_type';
                $values[] = $vehicle_type_save;
            }

            // status
            $cols[] = 'status';
            $values[] = 'pending';

            // --- mysqli path (dynamic types and binding) ---
            if (isset($mysqli) && $mysqli instanceof mysqli) {
                // Build placeholders for bound params (we won't bind created_at)
                $placeholders = array_fill(0, count($values), '?');
                $sql = "INSERT INTO cars (" . implode(',', $cols) . ", created_at) VALUES (" . implode(',', $placeholders) . ", NOW())";

                // determine types string
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

                // bind
                if (!call_user_func_array([$stmt, 'bind_param'], $bindParams)) {
                    throw new Exception('Bind failed: ' . $stmt->error);
                }

                if (!$stmt->execute()) {
                    throw new Exception('Execute failed: ' . $stmt->error);
                }

                $newId = $stmt->insert_id;
                $stmt->close();

                // Now move uploaded files into folder uploads/cars/{id} and rename
                $movedMain = null;
                $savedFiles = []; // all moved files (rel paths)
                if (!empty($uploadPending) && !empty($newId)) {
                    $destDirRel = 'uploads/cars/' . intval($newId);
                    $destDir = __DIR__ . '/../' . $destDirRel;
                    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);

                    $counter = 1;
                    $zeroPad = function($num, $len=10){ return str_pad((string)$num, $len, '0', STR_PAD_LEFT); };

                    foreach ($uploadPending as $idx => $item) {
                        $ext = $item['ext'] ?? 'jpg';
                        if ($idx === $savedMainIndex) {
                            $fileName = 'car' . intval($newId) . '_' . $zeroPad($counter) . '_main.' . $ext;
                        } else {
                            $fileName = 'car' . intval($newId) . '_' . $zeroPad($counter) . '.' . $ext;
                        }
                        $counter++;
                        $destPath = $destDir . '/' . $fileName;
                        if (@move_uploaded_file($item['tmp'], $destPath)) {
                            $relPath = $destDirRel . '/' . $fileName;
                            $savedFiles[] = $relPath;
                            if ($idx === $savedMainIndex) $movedMain = $relPath;
                        } else {
                            error_log("add-car: move_uploaded_file failed for tmp={$item['tmp']} -> dest={$destPath}");
                        }
                    }

                    // Update cars.photo with main image if moved
                    if (!empty($movedMain)) {
                        try {
                            $up = $mysqli->prepare("UPDATE cars SET photo = ? WHERE id = ? LIMIT 1");
                            if ($up) { $up->bind_param('si', $movedMain, $newId); $up->execute(); $up->close(); }
                        } catch (Throwable $e) {
                            error_log("add-car: failed to update photo path in DB for id={$newId} : " . $e->getMessage());
                        }
                    }

                    // Insert all saved files into car_photos if table exists
                    try {
                        $tblCheck = $mysqli->query("SHOW TABLES LIKE 'car_photos'");
                        if ($tblCheck && $tblCheck->num_rows > 0 && !empty($savedFiles)) {
                            // find best column to insert path into
                            $colRes = $mysqli->query("SHOW COLUMNS FROM car_photos");
                            $cols = [];
                            while ($cr = $colRes->fetch_assoc()) $cols[] = $cr['Field'];
                            $prefer = ['file_path','file','filepath','filename','path','photo','url'];
                            $useCol = null;
                            foreach ($prefer as $cname) {
                                if (in_array($cname, $cols, true)) { $useCol = $cname; break; }
                            }
                            if ($useCol === null) {
                                // fallback: pick first non-meta column
                                foreach ($cols as $cname) {
                                    if (!in_array($cname, ['id','car_id','created_at','created','updated','updated_at','user_id'], true)) {
                                        $useCol = $cname;
                                        break;
                                    }
                                }
                            }
                            if ($useCol === null) {
                                // fallback to 'photo' or 'path' if present
                                $useCol = (in_array('photo', $cols, true) ? 'photo' : (in_array('path', $cols, true) ? 'path' : null));
                            }

                            if ($useCol) {
                                $insSql = "INSERT INTO car_photos (car_id, {$useCol}, created_at) VALUES (?, ?, NOW())";
                                $insStmt = $mysqli->prepare($insSql);
                                if ($insStmt) {
                                    foreach ($savedFiles as $p) {
                                        $insStmt->bind_param('is', $newId, $p);
                                        $insStmt->execute();
                                    }
                                    $insStmt->close();
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        error_log("add-car: failed to insert into car_photos: " . $e->getMessage());
                    }
                }

                // generate SKU
                $skuGenerated = 'CAR-' . str_pad((string)$newId, 6, '0', STR_PAD_LEFT);
                $up = $mysqli->prepare("UPDATE cars SET sku = ? WHERE id = ? LIMIT 1");
                if ($up) { $up->bind_param('si', $skuGenerated, $newId); $up->execute(); $up->close(); }

                if ($isAjax) jsonOk(['id'=>$newId, 'sku'=>$skuGenerated]);
                header('Location: ' . $basePublic . '/my-cars.php?msg=' . urlencode('Автомобиль добавлен и отправлен на модерацию.'));
                exit;

            } elseif (isset($pdo) && $pdo instanceof PDO) {
                // PDO path: named placeholders
                $insertCols = $cols;
                $namedPlaceholders = [];
                $params = [];
                foreach ($values as $k => $v) {
                    $ph = ':p' . $k;
                    $namedPlaceholders[] = $ph;
                    $params[$ph] = $v;
                }
                $sql2 = "INSERT INTO cars (" . implode(',', $insertCols) . ", created_at) VALUES (" . implode(',', array_keys($params)) . ", NOW())";
                $st = $pdo->prepare($sql2);
                if (!$st->execute($params)) {
                    $info = $st->errorInfo();
                    throw new Exception('Execute failed (PDO): ' . implode(' | ', $info));
                }
                $newId = (int)$pdo->lastInsertId();

                // Now move uploaded files into folder uploads/cars/{id} and rename (PDO path)
                $movedMain = null;
                $savedFiles = [];
                if (!empty($uploadPending) && !empty($newId)) {
                    $destDirRel = 'uploads/cars/' . intval($newId);
                    $destDir = __DIR__ . '/../' . $destDirRel;
                    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);

                    $counter = 1;
                    $zeroPad = function($num, $len=10){ return str_pad((string)$num, $len, '0', STR_PAD_LEFT); };

                    foreach ($uploadPending as $idx => $item) {
                        $ext = $item['ext'] ?? 'jpg';
                        if ($idx === $savedMainIndex) {
                            $fileName = 'car' . intval($newId) . '_' . $zeroPad($counter) . '_main.' . $ext;
                        } else {
                            $fileName = 'car' . intval($newId) . '_' . $zeroPad($counter) . '.' . $ext;
                        }
                        $counter++;
                        $destPath = $destDir . '/' . $fileName;
                        if (@move_uploaded_file($item['tmp'], $destPath)) {
                            $relPath = $destDirRel . '/' . $fileName;
                            $savedFiles[] = $relPath;
                            if ($idx === $savedMainIndex) $movedMain = $relPath;
                        } else {
                            error_log("add-car: move_uploaded_file failed for tmp={$item['tmp']} -> dest={$destPath}");
                        }
                    }

                    // Update cars.photo with main image if moved
                    if (!empty($movedMain)) {
                        try {
                            $up = $pdo->prepare("UPDATE cars SET photo = :p WHERE id = :id");
                            $up->execute([':p' => $movedMain, ':id' => $newId]);
                        } catch (Throwable $e) {
                            error_log("add-car: failed to update photo path in DB (PDO) for id={$newId} : " . $e->getMessage());
                        }
                    }

                    // insert into car_photos if exists (PDO)
                    try {
                        $tblCheck = $pdo->query("SHOW TABLES LIKE 'car_photos'");
                        $ok = $tblCheck && $tblCheck->fetchColumn() !== false;
                        if ($ok && !empty($savedFiles)) {
                            $colRes = $pdo->query("SHOW COLUMNS FROM car_photos");
                            $cols = $colRes->fetchAll(PDO::FETCH_COLUMN);
                            $prefer = ['file_path','file','filepath','filename','path','photo','url'];
                            $useCol = null;
                            foreach ($prefer as $cname) {
                                if (in_array($cname, $cols, true)) { $useCol = $cname; break; }
                            }
                            if ($useCol === null) {
                                foreach ($cols as $cname) {
                                    if (!in_array($cname, ['id','car_id','created_at','created','updated','updated_at','user_id'], true)) {
                                        $useCol = $cname;
                                        break;
                                    }
                                }
                            }
                            if ($useCol === null) {
                                $useCol = (in_array('photo', $cols, true) ? 'photo' : (in_array('path', $cols, true) ? 'path' : null));
                            }

                            if ($useCol) {
                                $ins = $pdo->prepare("INSERT INTO car_photos (car_id, {$useCol}, created_at) VALUES (:id, :p, NOW())");
                                foreach ($savedFiles as $p) {
                                    $ins->execute([':id'=>$newId, ':p'=>$p]);
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        error_log("add-car: failed to insert into car_photos (PDO): " . $e->getMessage());
                    }
                }

                $skuGenerated = 'CAR-' . str_pad((string)$newId, 6, '0', STR_PAD_LEFT);
                $upd = $pdo->prepare("UPDATE cars SET sku = :sku WHERE id = :id");
                $upd->execute([':sku' => $skuGenerated, ':id' => $newId]);

                if ($isAjax) jsonOk(['id'=>$newId, 'sku'=>$skuGenerated]);
                header('Location: ' . $basePublic . '/my-cars.php?msg=' . urlencode('Автомобиль добавлен и отправлен на модерацию.'));
                exit;

            } else {
                throw new Exception('Нет подключения к БД');
            }

        } catch (Throwable $e) {
            error_log("add-car: save error: " . $e->getMessage() . " | SQL: " . ($sql ?? ($sql2 ?? 'n/a')));
            $errors[] = 'Ошибка при сохранении: ' . $e->getMessage();
            if ($isAjax) jsonError($errors[count($errors)-1]);
        }
    } else {
        if ($isAjax) jsonError(implode('; ', $errors));
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Добавить авто — Mehanik</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/mehanik/assets/css/header.css">
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    /* (стили как у вас) */
    .page { max-width:1100px; margin:18px auto; padding:14px; box-sizing:border-box; }
    .card { background:#fff; border-radius:10px; box-shadow:0 8px 24px rgba(2,6,23,0.06); overflow:hidden; }
    .card-body { padding:18px; }
    .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    @media (max-width:760px){ .form-grid { grid-template-columns:1fr } }
    label.block{ display:block; font-weight:700; margin-bottom:6px; }
    input[type=text], input[type=number], select, textarea { width:100%; padding:10px 12px; border:1px solid #e6e9ef; border-radius:8px; box-sizing:border-box; }
    textarea { min-height:120px; }
    .muted { color:#6b7280; font-size:0.95rem; }
    .actions { display:flex; gap:10px; justify-content:flex-end; margin-top:12px; }
    .btn { background:#0b57a4; color:#fff; padding:10px 14px; border-radius:8px; border:0; cursor:pointer; font-weight:700; }
    .btn-ghost { background:transparent; border:1px solid #dbeafe; color:#0b57a4; }
    .error { background:#fff6f6; border:1px solid #f5c2c2; color:#8a1f1f; padding:10px; border-radius:8px; margin-bottom:12px; }
    .ok { background:#eafaf0; border:1px solid #cfead1; color:#116530; padding:10px; border-radius:8px; margin-bottom:12px; }
    .photo-preview { display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
    .preview-item { position:relative; width:120px; height:80px; border-radius:8px; overflow:hidden; border:1px solid #e6eef7; display:flex; align-items:center; justify-content:center; background:#fafafa; }
    .preview-item img { width:100%; height:100%; object-fit:cover; display:block; }
    .preview-item .actions { position:absolute; left:6px; top:6px; display:flex; flex-direction:column; gap:6px; }
    .preview-item button { font-size:11px; padding:5px 7px; border-radius:6px; border:0; cursor:pointer; background:rgba(0,0,0,0.6); color:#fff; }
    .preview-item .main-badge { position:absolute; right:6px; top:6px; background:#0b57a4;color:#fff;padding:4px 6px;border-radius:6px;font-size:11px; }
    .small { font-size:.9rem; color:#6b7280; }
    .dropzone { padding:10px; border:1px dashed #e6e9ef; border-radius:8px; cursor:pointer; text-align:center; color:#6b7280; background:#fff; }
    .dropzone.dragover { background:#f0f8ff; border-color:#b6e0ff; color:#044a75; }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<div class="page">
  <div class="card">
    <div class="card-body">
      <h2 style="margin:0 0 10px;">Добавить автомобиль на продажу</h2>
      <p class="muted" style="margin:0 0 12px;">Поля с * обязательны. Бренд и модель подтянутся из каталога (если есть).</p>

      <?php if (!empty($errors)): ?>
        <div class="error"><?= h(implode(' · ', $errors)) ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="ok"><?= h($success) ?></div>
      <?php endif; ?>

      <form id="addCarForm" method="post" enctype="multipart/form-data" novalidate>
        <div class="form-grid">
          <div>
            <label class="block">Тип ТС *</label>
            <select id="vehicle_type" name="vehicle_type" required>
              <option value="">— выберите тип —</option>
              <?php foreach ($vehicle_types_select as $val => $label): ?>
                <option value="<?= h((string)$val) ?>"><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block">Кузов *</label>
            <select id="body_type" name="body" required>
              <option value="">— выберите кузов —</option>
            </select>
          </div>

          <div>
            <label class="block">Бренд *</label>
            <select id="brand" name="brand_id" required>
              <option value="">— выберите бренд —</option>
              <?php foreach ($brands as $b): ?>
                <option value="<?= (int)$b['id'] ?>"><?= h($b['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block">Модель *</label>
            <select id="model" name="model_id" required><option value="">— выберите модель —</option></select>
          </div>

          <div>
            <label class="block">Год выпуска *</label>
            <select id="year" name="year" required>
              <option value="">— год —</option>
              <?php if (!empty($vehicle_years)): ?>
                <?php foreach ($vehicle_years as $y): ?>
                  <?php if ((int)($y['active'] ?? 1) === 1): ?>
                    <option value="<?= (int)$y['year'] ?>"><?= (int)$y['year'] ?></option>
                  <?php endif; ?>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
            <div class="small">Допустимый диапазон: <?= $minYear ?> — <?= $currentYear ?></div>
          </div>

          <div>
            <label class="block">VIN</label>
            <input id="vin" type="text" name="vin" placeholder="VIN (если есть)">
          </div>

          <div>
            <label class="block">Пробег (км)</label>
            <input type="number" name="mileage" min="0" placeholder="например 120000">
          </div>

          <div>
            <label class="block">Коробка передач *</label>
            <select id="transmission" name="transmission" required>
              <option value="">— выберите —</option>
              <?php if (!empty($gearboxes)): ?>
                <?php foreach ($gearboxes as $g): if ((int)($g['active'] ?? 1) !== 1) continue; ?>
                  <option value="<?= h($g['name']) ?>"><?= h($g['name']) ?></option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>

          <div>
            <label class="block">Тип топлива *</label>
            <select id="fuel" name="fuel" required>
              <option value="">— выберите —</option>
              <?php if (!empty($fuel_types)): ?>
                <?php foreach ($fuel_types as $f): if ((int)($f['active'] ?? 1) !== 1) continue; ?>
                  <option value="<?= h($f['name']) ?>"><?= h($f['name']) ?></option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>

          <!-- Цвет авто -->
<div>
  <label class="block">Цвет авто</label>
  <select name="color" id="color">
    <option value="">— выберите цвет —</option>
    <?php foreach ($car_colors as $c): if ((int)($c['active'] ?? 1) !== 1) continue; ?>
      <option value="<?= h($c['name']) ?>"><?= h($c['name']) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<!-- Объем двигателя -->
<div>
  <label class="block">Объём двигателя</label>
  <select name="engine_volume" id="engine_volume">
    <option value="">— выберите объём —</option>
    <?php foreach ($engine_volumes as $ev): if ((int)($ev['active'] ?? 1) !== 1) continue; ?>
      <option value="<?= h($ev['label']) ?>"><?= h($ev['label']) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<!-- Кол-во пассажиров -->
<div>
  <label class="block">Количество пассажиров</label>
  <select name="passengers" id="passengers">
    <option value="">— выберите —</option>
    <?php foreach ($passenger_counts as $pc): if ((int)($pc['active'] ?? 1) !== 1) continue; ?>
      <option value="<?= (int)$pc['cnt'] ?>"><?= h($pc['label'] ?: ($pc['cnt'].' мест')) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<!-- Салон: цвет и обшивка -->
<div>
  <label class="block">Цвет салона</label>
  <select name="interior_color" id="interior_color">
    <option value="">— выберите —</option>
    <?php foreach ($interior_colors as $ic): if ((int)($ic['active'] ?? 1) !== 1) continue; ?>
      <option value="<?= h($ic['name']) ?>"><?= h($ic['name']) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<div>
  <label class="block">Обшивка салона</label>
  <select name="upholstery" id="upholstery">
    <option value="">— выберите —</option>
    <?php foreach ($upholstery_types as $up): if ((int)($up['active'] ?? 1) !== 1) continue; ?>
      <option value="<?= h($up['name']) ?>"><?= h($up['name']) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<!-- Тип зажигания -->
<div>
  <label class="block">Тип зажигания</label>
  <select name="ignition_type" id="ignition_type">
    <option value="">— выберите —</option>
    <?php foreach ($ignition_types as $it): if ((int)($it['active'] ?? 1) !== 1) continue; ?>
      <option value="<?= h($it['name']) ?>"><?= h($it['name']) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<!-- Адрес: Велаят / Этрапы -->
<div>
  <label class="block">Местоположение</label>
  <select name="region_id" id="region_id">
    <option value="">— выберите город/велаят —</option>
    <?php foreach ($regions as $r): if ((int)($r['active'] ?? 1) !== 1) continue; ?>
      <option value="<?= (int)$r['id'] ?>"><?= h($r['name']) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<div>
  <label class="block">Этрапы/Город</label>
  <select name="district_id" id="district_id">
    <option value="">— выберите этрап —</option>
  </select>
</div>

          <div>
            <label class="block">Цена (TMT)</label>
            <input type="number" name="price" step="0.01" min="0" placeholder="например 350000">
          </div>

          <div style="grid-column:1 / -1">
            <label class="block">Описание</label>
            <textarea name="description" placeholder="Дополнительная информация, комплектация..."></textarea>
          </div>

          <div>
            <label class="block">Фотографии (макс.10)</label>
            <div id="dropzone" class="dropzone">Перетащите фото сюда или нажмите для выбора</div>
            <input id="p_photos" type="file" name="photos[]" accept="image/*" multiple style="display:none">
            <div class="small">Рекомендуется не больше 10 фото. Выберите главное фото звёздочкой (★).</div>
            <div id="previews" class="photo-preview" aria-hidden="true"></div>
          </div>

          <div>
            <input type="hidden" name="contact_phone" value="<?= h($user_phone) ?>">
            <label class="block">Контактный телефон</label>
            <div class="small"><?= h($user_phone ?: 'Не указан') ?></div>
            <div class="small">Номер берётся из вашего профиля и не может быть изменён в форме.</div>
          </div>

        </div>

        <div class="actions">
          <a href="<?= $basePublic ?>/my-cars.php" class="btn btn-ghost" style="background:transparent;border:1px solid #e6eef7;color:#0b57a4;padding:8px 12px;">← К списку</a>
          <button type="submit" class="btn">Опубликовать</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- конфиг для внешнего скрипта; обязательно перед подключением add-car.js -->
<script>
window.ADD_CAR_CONFIG = {
  VEHICLE_BODIES_BY_TYPE: <?= json_encode($vehicle_bodies_js, JSON_UNESCAPED_UNICODE) ?>,
  DISTRICTS_BY_REGION: <?= json_encode($districts_by_region, JSON_UNESCAPED_UNICODE) ?>,
  MIN_YEAR: <?= json_encode($minYear) ?>,
  MAX_YEAR: <?= json_encode($currentYear) ?>,
  BASE_PUBLIC: <?= json_encode($basePublic) ?>
};
</script>
<script defer src="/mehanik/assets/js/add-car.js"></script>

</body>
</html>
