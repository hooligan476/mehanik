<?php
// mehanik/api/add-service.php
// Обработка добавления сервиса: multipart/form-data (файлы + поля формы)
// Поддержка: логотип, фотографии, до 10 видео, прайсы, сотрудники
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
$uploadsRootBase = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
$uploadsRoot = $uploadsRootBase . '/services';
if (!is_dir($uploadsRoot)) {
    @mkdir($uploadsRoot, 0755, true);
}
$allowedImageMime = ['image/jpeg','image/png','image/webp'];
$allowedVideoMime = ['video/mp4','video/webm','video/ogg','video/quicktime'];
$maxImageSize = 8 * 1024 * 1024;     // 8 MB per image/logo/staff photo (align with edit-service)
$maxPhotos = 10;
$maxVideos = 10;
$maxVideoSize = 200 * 1024 * 1024;   // 200 MB for video (adjust if needed)

// check optional tables/columns
$haveServicePhotos = ($mysqli->query("SHOW TABLES LIKE 'service_photos'")->num_rows > 0);
$haveServicePrices = ($mysqli->query("SHOW TABLES LIKE 'service_prices'")->num_rows > 0);
$haveStaffTable = ($mysqli->query("SHOW TABLES LIKE 'service_staff'")->num_rows > 0);
$haveServiceVideosTable = ($mysqli->query("SHOW TABLES LIKE 'service_videos'")->num_rows > 0);
$servicesHasVideoCol = ($mysqli->query("SHOW COLUMNS FROM `services` LIKE 'video'")->num_rows > 0);

// detect columns in service_videos (mime/size)
$serviceVideosHasMime = false;
$serviceVideosHasSize = false;
if ($haveServiceVideosTable) {
    $res = $mysqli->query("SHOW COLUMNS FROM `service_videos` LIKE 'mime'");
    $serviceVideosHasMime = $res && $res->num_rows > 0;
    if ($res) $res->free();
    $res2 = $mysqli->query("SHOW COLUMNS FROM `service_videos` LIKE 'size'");
    $serviceVideosHasSize = $res2 && $res2->num_rows > 0;
    if ($res2) $res2->free();
}

// files saved (for cleanup on error)
$savedFiles = [];

$mysqli->begin_transaction();
try {
    // Insert service with empty logo (update after)
    $sql = "INSERT INTO services (name, contact_name, description, phone, email, address, latitude, longitude, logo, status, created_at, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, '', 'pending', NOW(), ?)";
    if (!$stmt = $mysqli->prepare($sql)) throw new Exception('DB prepare failed: ' . $mysqli->error);

    // bind lat/lng as doubles; if null set to NULL via 0.0 (or adapt if column accepts NULL)
    $latVal = $lat === null ? 0.0 : $lat;
    $lngVal = $lng === null ? 0.0 : $lng;

    if (!$stmt->bind_param('ssssssddi', $name, $contact_name, $description, $phone, $email, $address, $latVal, $lngVal, $userId)) {
        throw new Exception('Bind failed: ' . $stmt->error);
    }
    if (!$stmt->execute()) throw new Exception('DB execute failed: ' . $stmt->error);
    $serviceId = (int)$mysqli->insert_id;
    $stmt->close();

    if ($serviceId <= 0) throw new Exception('Не удалось создать сервис в БД.');

    // create service folder and subfolders (consistent with edit-service)
    $serviceDir = rtrim($uploadsRoot, '/') . '/' . $serviceId;
    $servicePhotosDir = $serviceDir . '/photos';
    $serviceVideosDir = $serviceDir . '/videos';
    $serviceLogoDir = $serviceDir . '/logo';
    $serviceStaffDir = $serviceDir . '/staff';
    @mkdir($servicePhotosDir, 0755, true);
    @mkdir($serviceVideosDir, 0755, true);
    @mkdir($serviceLogoDir, 0755, true);
    @mkdir($serviceStaffDir, 0755, true);

    // process logo
    $logoRel = '';
    if (!empty($_FILES['logo']['name']) && isset($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
        if ((int)$_FILES['logo']['size'] > $maxImageSize) throw new Exception('Логотип слишком большой');
        $mime = mime_content_type($_FILES['logo']['tmp_name']);
        if (!in_array($mime, $allowedImageMime, true)) throw new Exception('Неподдерживаемый формат логотипа');
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION)) ?: 'png';
        $ext = preg_replace('/[^a-z0-9]+/i', '', $ext);
        $uniq = uniqid('logo', true);
        $logoFile = $uniq . '.' . $ext;
        $dst = $serviceLogoDir . '/' . $logoFile;
        if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dst)) throw new Exception('Ошибка сохранения логотипа');
        @chmod($dst, 0644);
        $savedFiles[] = $dst;
        $logoRel = 'uploads/services/' . $serviceId . '/logo/' . $logoFile;
        // update services.logo
        if ($u = $mysqli->prepare("UPDATE services SET logo = ? WHERE id = ? LIMIT 1")) {
            $u->bind_param('si', $logoRel, $serviceId);
            $u->execute();
            $u->close();
        }
    }

    // process photos (max $maxPhotos)
    $insertedPhotos = [];
    if (isset($_FILES['photos']) && is_array($_FILES['photos']['name']) && $haveServicePhotos) {
        $count = count($_FILES['photos']['name']);
        $photoIndex = 0;
        if ($ins = $mysqli->prepare("INSERT INTO service_photos (service_id, photo, created_at) VALUES (?, ?, NOW())")) {
            for ($i=0; $i<$count && $photoIndex < $maxPhotos; $i++) {
                if (!isset($_FILES['photos']['tmp_name'][$i]) || !is_uploaded_file($_FILES['photos']['tmp_name'][$i])) continue;
                if ((int)$_FILES['photos']['size'][$i] <= 0) continue;
                if ((int)$_FILES['photos']['size'][$i] > $maxImageSize) throw new Exception('Одно из фото слишком большое');
                $pmime = mime_content_type($_FILES['photos']['tmp_name'][$i]);
                if (!in_array($pmime, $allowedImageMime, true)) throw new Exception('Неподдерживаемый формат фото');
                $photoIndex++;
                $ext = strtolower(pathinfo($_FILES['photos']['name'][$i], PATHINFO_EXTENSION)) ?: 'jpg';
                $ext = preg_replace('/[^a-z0-9]+/i', '', $ext);
                $uniq = uniqid('ph', true);
                $photoFile = $uniq . '.' . $ext;
                $dst = $servicePhotosDir . '/' . $photoFile;
                if (!move_uploaded_file($_FILES['photos']['tmp_name'][$i], $dst)) throw new Exception('Не удалось сохранить фото');
                @chmod($dst, 0644);
                $savedFiles[] = $dst;
                $photoRel = 'uploads/services/' . $serviceId . '/photos/' . $photoFile;
                $ins->bind_param('is', $serviceId, $photoRel);
                $ins->execute();
                $insertedPhotos[] = $photoRel;
            }
            $ins->close();
        }
    }

    // process videos (support multiple files from input name "videos[]")
    $insertedVideos = [];
    if (isset($_FILES['videos']) && is_array($_FILES['videos']['name']) && count($_FILES['videos']['name']) > 0) {
        $count = count($_FILES['videos']['name']);
        $processed = 0;
        for ($i = 0; $i < $count && $processed < $maxVideos; $i++) {
            if (!isset($_FILES['videos']['tmp_name'][$i]) || !is_uploaded_file($_FILES['videos']['tmp_name'][$i])) continue;
            $size = (int)($_FILES['videos']['size'][$i] ?? 0);
            if ($size <= 0) continue;
            if ($size > $maxVideoSize) throw new Exception('Одно из видео слишком большое (превышен лимит).');
            $vmime = mime_content_type($_FILES['videos']['tmp_name'][$i]) ?: '';
            $vext = strtolower(pathinfo($_FILES['videos']['name'][$i], PATHINFO_EXTENSION)) ?: 'mp4';
            if (!in_array($vmime, $allowedVideoMime, true) && !in_array($vext, ['mp4','webm','ogg','mov'], true)) {
                throw new Exception('Неподдерживаемый формат видео. Разрешены MP4/WebM/OGG/MOV.');
            }
            $vext = preg_replace('/[^a-z0-9]+/i', '', $vext);
            $uniq = uniqid('v', true);
            $videoFile = $uniq . '.' . $vext;
            $dstVideo = $serviceVideosDir . '/' . $videoFile;
            if (!move_uploaded_file($_FILES['videos']['tmp_name'][$i], $dstVideo)) throw new Exception('Не удалось сохранить видео');
            @chmod($dstVideo, 0644);
            $savedFiles[] = $dstVideo;
            $videoRel = 'uploads/services/' . $serviceId . '/videos/' . $videoFile;

            // insert into service_videos if table exists
            if ($haveServiceVideosTable) {
                if ($serviceVideosHasMime && $serviceVideosHasSize) {
                    if ($insV = $mysqli->prepare("INSERT INTO service_videos (service_id, video, mime, size, created_at) VALUES (?, ?, ?, ?, NOW())")) {
                        $insV->bind_param('issi', $serviceId, $videoRel, $vmime, $size);
                        $insV->execute();
                        $insV->close();
                    } else {
                        throw new Exception('Ошибка записи видео в БД: ' . $mysqli->error);
                    }
                } else {
                    // fallback to minimal insert (only video path)
                    if ($insV = $mysqli->prepare("INSERT INTO service_videos (service_id, video, created_at) VALUES (?, ?, NOW())")) {
                        $insV->bind_param('is', $serviceId, $videoRel);
                        $insV->execute();
                        $insV->close();
                    } else {
                        throw new Exception('Ошибка записи видео в БД: ' . $mysqli->error);
                    }
                }
            } elseif ($servicesHasVideoCol) {
                // if services table has 'video' column and no separate table, save first video into services.video
                // only update services.video for the first video
                if (empty($insertedVideos)) {
                    if ($upV = $mysqli->prepare("UPDATE services SET video = ? WHERE id = ? LIMIT 1")) {
                        $upV->bind_param('si', $videoRel, $serviceId);
                        $upV->execute();
                        $upV->close();
                    } // otherwise ignore silently
                }
            } else {
                // no place in DB for videos — file saved, but not recorded
            }

            $insertedVideos[] = $videoRel;
            $processed++;
        }

        // make sure there's a main video if service_videos has is_main column (set first)
        if ($haveServiceVideosTable && count($insertedVideos) > 0) {
            $colRes = $mysqli->query("SHOW COLUMNS FROM `service_videos` LIKE 'is_main'");
            $hasIsMain = $colRes && $colRes->num_rows > 0;
            if ($colRes) $colRes->free();
            if ($hasIsMain) {
                if ($u = $mysqli->prepare("UPDATE service_videos SET is_main = 1 WHERE id = (SELECT id FROM (SELECT id FROM service_videos WHERE service_id = ? ORDER BY id ASC LIMIT 1) x)")) {
                    $u->bind_param('i', $serviceId);
                    $u->execute();
                    $u->close();
                }
            }
        }
    }

    // prices
    if (!empty($prices_names) && is_array($prices_names) && $haveServicePrices) {
        if ($stmtP = $mysqli->prepare("INSERT INTO service_prices (service_id, name, price) VALUES (?, ?, ?)")) {
            foreach ($prices_names as $idx => $pn) {
                $pn = trim((string)$pn);
                $pp = trim((string)($prices_prices[$idx] ?? ''));
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
        if ($stmtS = $mysqli->prepare("INSERT INTO service_staff (service_id, photo, name, position, rating, created_at) VALUES (?, ?, ?, ?, ?, NOW())")) {
            $countStaff = count($staff_names);
            for ($i = 0; $i < $countStaff; $i++) {
                $sname = trim((string)($staff_names[$i] ?? ''));
                if ($sname === '') continue;
                $spos = trim((string)($staff_positions[$i] ?? ''));
                $srateRaw = trim((string)($staff_ratings[$i] ?? ''));
                $srate = is_numeric(str_replace(',', '.', $srateRaw)) ? floatval(str_replace(',', '.', $srateRaw)) : 0.0;
                if ($srate < 0) $srate = 0.0;
                if ($srate > 10) $srate = 10.0;

                $photoRel = '';
                if (isset($_FILES['staff_photo']) && isset($_FILES['staff_photo']['tmp_name'][$i]) && is_uploaded_file($_FILES['staff_photo']['tmp_name'][$i])) {
                    if ((int)$_FILES['staff_photo']['size'][$i] > $maxImageSize) throw new Exception("Фото сотрудника '{$sname}' слишком большое");
                    $smime = mime_content_type($_FILES['staff_photo']['tmp_name'][$i]);
                    if (!in_array($smime, $allowedImageMime, true)) throw new Exception("Неподдерживаемый формат фото сотрудника '{$sname}'");
                    $ext = strtolower(pathinfo($_FILES['staff_photo']['name'][$i], PATHINFO_EXTENSION)) ?: 'jpg';
                    $ext = preg_replace('/[^a-z0-9]+/i', '', $ext);
                    $uniq = uniqid('st', true);
                    $photoFile = $uniq . '.' . $ext;
                    $dst = $serviceStaffDir . '/' . $photoFile;
                    if (!move_uploaded_file($_FILES['staff_photo']['tmp_name'][$i], $dst)) throw new Exception("Не удалось сохранить фото сотрудника '{$sname}'");
                    @chmod($dst, 0644);
                    $savedFiles[] = $dst;
                    $photoRel = 'uploads/services/' . $serviceId . '/staff/' . $photoFile;
                }

                $photoToInsert = $photoRel ?? '';
                $stmtS->bind_param('isssd', $serviceId, $photoToInsert, $sname, $spos, $srate);
                $stmtS->execute();
                $newStaffId = $mysqli->insert_id;
                $insertedStaff[] = ['id' => $newStaffId, 'photo' => $photoToInsert, 'name' => $sname];
            }
            $stmtS->close();
        }
    }

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
            'videos' => $insertedVideos,
            'staff' => $insertedStaff
        ]);
        exit;
    } else {
        header('Location: /mehanik/public/service.php?id=' . $serviceId . '&m=' . rawurlencode('Сервис добавлен и ожидает модерации.'));
        exit;
    }

} catch (Throwable $e) {
    // rollback db
    if ($mysqli->in_transaction) $mysqli->rollback();
    // remove any saved files
    foreach ($savedFiles as $f) {
        if (is_file($f)) @unlink($f);
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
