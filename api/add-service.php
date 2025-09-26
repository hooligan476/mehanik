<?php
// mehanik/api/add-service.php
// Исправлена привязка типов (address — string)
// Обработка: multipart/form-data (файлы + поля формы)
// Автор: ChatGPT (патч для проекта mehanik)

session_start();
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../db.php';

// require logged user
if (empty($_SESSION['user'])) {
    header('Location: /mehanik/public/login.php');
    exit;
}
$userId = (int)($_SESSION['user']['id'] ?? 0);

$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
       || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// collect and sanitize
$name = trim($_POST['name'] ?? '');
$contact_name = trim($_POST['contact_name'] ?? '');
$description = trim($_POST['description'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');
$lat = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? floatval($_POST['latitude']) : null;
$lng = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? floatval($_POST['longitude']) : null;
$prices_names = $_POST['prices']['name'] ?? [];
$prices_prices = $_POST['prices']['price'] ?? [];

// staff arrays (optional)
$staff_names = $_POST['staff']['name'] ?? [];
$staff_positions = $_POST['staff']['position'] ?? [];
$staff_ratings = $_POST['staff']['rating'] ?? [];

// basic validation
if ($name === '' || $description === '' || $phone === '') {
    $msg = 'Заполните обязательные поля: Название, Описание, Контактный телефон.';
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8', true, 422);
        echo json_encode(['ok'=>false,'error'=>$msg]);
    } else {
        header('Location: /mehanik/public/add-service.php?err=' . urlencode($msg));
    }
    exit;
}

// uploads config
$uploadsRootBase = __DIR__ . '/../uploads';
$uploadsRoot = $uploadsRootBase . '/services';
if (!is_dir($uploadsRoot)) {
    @mkdir($uploadsRoot, 0755, true);
}
$allowedMime = ['image/jpeg','image/png','image/webp'];
$maxSize = 5 * 1024 * 1024;
$maxPhotos = 10;

// check optional tables
$haveServicePhotos = ($mysqli->query("SHOW TABLES LIKE 'service_photos'")->num_rows > 0);
$haveServicePrices = ($mysqli->query("SHOW TABLES LIKE 'service_prices'")->num_rows > 0);
$haveStaffTable = ($mysqli->query("SHOW TABLES LIKE 'service_staff'")->num_rows > 0);

// files saved (for cleanup on error)
$savedFiles = [];

$mysqli->begin_transaction();
try {
    // Insert service with empty logo (update after)
    $sql = "INSERT INTO services (name, contact_name, description, phone, email, address, latitude, longitude, logo, status, created_at, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, '', 'pending', NOW(), ?)";
    if (!$stmt = $mysqli->prepare($sql)) throw new Exception('DB prepare failed: ' . $mysqli->error);

    // bind lat/lng as doubles; if null set to 0.0 (DB numeric columns)
    $latVal = $lat === null ? 0.0 : $lat;
    $lngVal = $lng === null ? 0.0 : $lng;

    // CORRECTED types:
    // name (s), contact_name (s), description (s), phone (s), email (s), address (s),
    // latitude (d), longitude (d), user_id (i)
    $stmt->bind_param('ssssssddi', $name, $contact_name, $description, $phone, $email, $address, $latVal, $lngVal, $userId);
    if (!$stmt->execute()) throw new Exception('DB execute failed: ' . $stmt->error);
    $serviceId = $mysqli->insert_id;
    $stmt->close();

    // create service folder
    $serviceDir = $uploadsRoot . '/' . $serviceId;
    if (!is_dir($serviceDir) && !mkdir($serviceDir, 0755, true)) throw new Exception('Не удалось создать папку: ' . $serviceDir);

    // process logo
    $logoRel = '';
    if (!empty($_FILES['logo']['name']) && isset($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
        if ($_FILES['logo']['size'] > $maxSize) throw new Exception('Логотип слишком большой');
        $mime = mime_content_type($_FILES['logo']['tmp_name']);
        if (!in_array($mime, $allowedMime, true)) throw new Exception('Неподдерживаемый формат логотипа');
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION)) ?: 'jpg';
        $ext = preg_replace('/[^a-z0-9]+/i', '', $ext);
        $logoFile = 'logo.' . $ext;
        $dst = $serviceDir . '/' . $logoFile;
        if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dst)) throw new Exception('Ошибка сохранения логотипа');
        $savedFiles[] = $dst;
        $logoRel = 'uploads/services/' . $serviceId . '/' . $logoFile;
        // update services.logo
        if ($u = $mysqli->prepare("UPDATE services SET logo = ? WHERE id = ? LIMIT 1")) {
            $u->bind_param('si', $logoRel, $serviceId);
            $u->execute();
            $u->close();
        }
    }

    // process photos (max 10)
    $insertedPhotos = [];
    if (isset($_FILES['photos']) && !empty($_FILES['photos']['name']) && is_array($_FILES['photos']['name']) && $haveServicePhotos) {
        $count = count($_FILES['photos']['name']);
        $photoIndex = 0;
        for ($i=0; $i<$count && $photoIndex < $maxPhotos; $i++) {
            if (!isset($_FILES['photos']['tmp_name'][$i]) || !is_uploaded_file($_FILES['photos']['tmp_name'][$i])) continue;
            $photoIndex++;
            if ($_FILES['photos']['size'][$i] > $maxSize) throw new Exception('Одно из фото слишком большое');
            $mime = mime_content_type($_FILES['photos']['tmp_name'][$i]);
            if (!in_array($mime, $allowedMime, true)) throw new Exception('Неподдерживаемый формат фото');
            $ext = strtolower(pathinfo($_FILES['photos']['name'][$i], PATHINFO_EXTENSION)) ?: 'jpg';
            $ext = preg_replace('/[^a-z0-9]+/i', '', $ext);
            $photoFile = 'photo' . $photoIndex . '.' . $ext;
            $dst = $serviceDir . '/' . $photoFile;
            if (!move_uploaded_file($_FILES['photos']['tmp_name'][$i], $dst)) throw new Exception('Не удалось сохранить фото');
            $savedFiles[] = $dst;
            $photoRel = 'uploads/services/' . $serviceId . '/' . $photoFile;
            if ($ins = $mysqli->prepare("INSERT INTO service_photos (service_id, photo) VALUES (?, ?)")) {
                $ins->bind_param('is', $serviceId, $photoRel);
                $ins->execute();
                $ins->close();
            }
            $insertedPhotos[] = $photoRel;
        }
    }

    // prices
    if (!empty($prices_names) && is_array($prices_names) && $haveServicePrices) {
        if ($stmtP = $mysqli->prepare("INSERT INTO service_prices (service_id, name, price) VALUES (?, ?, ?)")) {
            foreach ($prices_names as $idx => $pn) {
                $pn = trim($pn);
                $pp = trim($prices_prices[$idx] ?? '');
                if ($pn === '') continue;
                $pp = str_replace(',', '.', $pp);
                $ppFloat = is_numeric($pp) ? floatval($pp) : 0.0;
                $stmtP->bind_param('isd', $serviceId, $pn, $ppFloat);
                $stmtP->execute();
            }
            $stmtP->close();
        }
    }

    // STAFF (optional)
    $insertedStaff = [];
    if ($haveStaffTable && !empty($staff_names) && is_array($staff_names)) {
        // create staff subfolder
        $staffDir = $serviceDir . '/staff';
        if (!is_dir($staffDir) && !mkdir($staffDir, 0755, true)) throw new Exception('Не удалось создать папку для сотрудников: ' . $staffDir);

        // prepare insert
        if ($stmtS = $mysqli->prepare("INSERT INTO service_staff (service_id, photo, name, position, rating, created_at) VALUES (?, ?, ?, ?, ?, NOW())")) {
            // iterate staff entries by index
            $countStaff = count($staff_names);
            for ($i = 0; $i < $countStaff; $i++) {
                $sname = trim($staff_names[$i] ?? '');
                if ($sname === '') continue; // skip empty name rows
                $spos = trim($staff_positions[$i] ?? '');
                $srateRaw = trim($staff_ratings[$i] ?? '');
                $srate = is_numeric(str_replace(',', '.', $srateRaw)) ? floatval(str_replace(',', '.', $srateRaw)) : 0.0;
                if ($srate < 0) $srate = 0.0;
                if ($srate > 5) $srate = 5.0;

                $photoRel = null;
                // check file for this index in staff_photo[]
                if (isset($_FILES['staff_photo']) && !empty($_FILES['staff_photo']['name']) && isset($_FILES['staff_photo']['tmp_name'][$i]) && is_uploaded_file($_FILES['staff_photo']['tmp_name'][$i])) {
                    if ($_FILES['staff_photo']['size'][$i] > $maxSize) throw new Exception("Фото сотрудника '{$sname}' слишком большое");
                    $mime = mime_content_type($_FILES['staff_photo']['tmp_name'][$i]);
                    if (!in_array($mime, $allowedMime, true)) throw new Exception("Неподдерживаемый формат фото сотрудника '{$sname}'");
                    $ext = strtolower(pathinfo($_FILES['staff_photo']['name'][$i], PATHINFO_EXTENSION)) ?: 'jpg';
                    $ext = preg_replace('/[^a-z0-9]+/i', '', $ext);
                    // unique filename to avoid collisions
                    $uniq = uniqid('s', true);
                    $photoFile = 'staff_' . ($i+1) . '_' . $uniq . '.' . $ext;
                    $dst = $staffDir . '/' . $photoFile;
                    if (!move_uploaded_file($_FILES['staff_photo']['tmp_name'][$i], $dst)) throw new Exception("Не удалось сохранить фото сотрудника '{$sname}'");
                    $savedFiles[] = $dst;
                    $photoRel = 'uploads/services/' . $serviceId . '/staff/' . $photoFile;
                }

                // insert staff row (photo can be empty string)
                $photoToInsert = $photoRel ?? '';
                $stmtS->bind_param('isssd', $serviceId, $photoToInsert, $sname, $spos, $srate);
                $stmtS->execute();
                $newStaffId = $mysqli->insert_id;
                $insertedStaff[] = ['id' => $newStaffId, 'photo' => $photoToInsert, 'name' => $sname];
            } // foreach staff
            $stmtS->close();
        } // prepared stmt
    } // have staff

    // commit
    $mysqli->commit();

    // success response
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'id' => $serviceId,
            'logo' => $logoRel,
            'photos' => $insertedPhotos,
            'staff' => $insertedStaff
        ]);
        exit;
    } else {
        header('Location: /mehanik/public/service.php?id=' . $serviceId . '&m=' . rawurlencode('Сервис добавлен и ожидает модерации.'));
        exit;
    }

} catch (Throwable $e) {
    // rollback db
    $mysqli->rollback();
    // remove any saved files
    foreach ($savedFiles as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    $err = 'Ошибка: ' . $e->getMessage();
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8', true, 500);
        echo json_encode(['ok'=>false,'error'=>$err]);
    } else {
        header('Location: /mehanik/public/add-service.php?err=' . rawurlencode($err));
    }
    exit;
}
