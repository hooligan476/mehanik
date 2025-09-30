<?php
// mehanik/public/edit-service.php
session_start();
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../db.php';

if (empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}
$user = $_SESSION['user'];
$userId = (int)$user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: services.php');
    exit;
}

/* ------------------ Load service ------------------ */
$service = null;
if ($st = $mysqli->prepare("SELECT id,user_id,name,description,logo,contact_name,phone,email,address,latitude,longitude FROM services WHERE id = ? LIMIT 1")) {
    $st->bind_param('i', $id);
    $st->execute();
    $service = $st->get_result()->fetch_assoc();
    $st->close();
}
if (!$service) {
    $_SESSION['flash_error'] = 'Сервис не найден';
    header('Location: services.php'); exit;
}
if (!$isAdmin && (int)$service['user_id'] !== $userId) {
    $_SESSION['flash_error'] = 'Нет доступа к редактированию';
    header('Location: services.php'); exit;
}

/* ------------------ Config & FS roots ------------------ */
$uploadsFsRoot  = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
$uploadsUrlRoot = '/mehanik/uploads';

$allowedVideoMime = ['video/mp4','video/webm','video/ogg','video/quicktime'];
$maxVideoSize = 150 * 1024 * 1024; // 150 MB
$maxPhotoSize = 8 * 1024 * 1024; // 8 MB
$allowedPhotoExt = ['jpg','jpeg','png','webp'];
$allowedVideoExt = ['mp4','webm','ogg','mov'];

$maxVideosPerService = 10;

/* prepare dirs */
$serviceBaseDir = rtrim($uploadsFsRoot, '/') . '/services/' . $id;
$servicePhotosDir = $serviceBaseDir . '/photos';
$serviceVideosDir = $serviceBaseDir . '/videos';
$serviceLogoDir = $serviceBaseDir . '/logo';
@mkdir($servicePhotosDir, 0755, true);
@mkdir($serviceVideosDir, 0755, true);
@mkdir($serviceLogoDir, 0755, true);

/* Ensure is_main column exists (best-effort) */
$res = $mysqli->query("SHOW TABLES LIKE 'service_videos'");
if ($res && $res->num_rows > 0) {
    $colRes = $mysqli->query("SHOW COLUMNS FROM `service_videos` LIKE 'is_main'");
    if (!$colRes || $colRes->num_rows === 0) {
        @ $mysqli->query("ALTER TABLE service_videos ADD COLUMN is_main TINYINT(1) NOT NULL DEFAULT 0, ADD INDEX (is_main)");
    }
    if ($colRes) $colRes->free();
}
if ($res) $res->free();

/* small helper */
function toPublicUrl($rel){ if(!$rel) return ''; if(preg_match('#^https?://#i',$rel)) return $rel; if (strpos($rel,'/')===0) return $rel; return '/mehanik/' . ltrim($rel,'/'); }
function find_upload_url_simple($val) {
    global $uploadsFsRoot, $uploadsUrlRoot;
    if (!$val) return ['', ''];
    if (preg_match('#^https?://#i', $val)) return [$val, ''];
    $path = ltrim($val, '/');
    $url = rtrim($uploadsUrlRoot, '/') . '/' . str_replace('%2F','/', rawurlencode($path));
    $fs = rtrim($uploadsFsRoot, '/') . '/' . $path;
    return [$url, $fs];
}
function count_service_videos($mysqli, $serviceId) {
    if ($st = $mysqli->prepare("SELECT COUNT(*) AS cnt FROM service_videos WHERE service_id = ?")) {
        $st->bind_param('i', $serviceId);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        return isset($r['cnt']) ? (int)$r['cnt'] : 0;
    }
    return 0;
}
function return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1] ?? 'b');
    $num = (int)$val;
    switch($last) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }
    return $num;
}

/* ------------------ POST handlers ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Detect silent POST wipe when upload > post_max_size (PHP clears $_POST/$_FILES)
    $postMax = return_bytes(ini_get('post_max_size') ?: '8M');
    $uploadMax = return_bytes(ini_get('upload_max_filesize') ?: '2M');
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;

    if (empty($_POST) && empty($_FILES) && $contentLength > 0 && $contentLength > $postMax) {
        $_SESSION['flash_error'] = "Форма слишком большая для сервера (Content-Length={$contentLength}). Параметр post_max_size в php.ini = " . ini_get('post_max_size') . ". Увеличьте post_max_size/upload_max_filesize или загружайте меньшие файлы.";
        header('Location: edit-service.php?id=' . $id); exit;
    }

    // allow empty action detection: if action missing but files present, also handle
    $action = $_POST['action'] ?? '';

    /* ===== update_service ===== */
    if ($action === 'update_service') {
        // Gather inputs
        $name = trim((string)($_POST['name'] ?? ''));
        $contact_name = trim((string)($_POST['contact_name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $latitude = trim((string)($_POST['latitude'] ?? ''));
        $longitude = trim((string)($_POST['longitude'] ?? ''));

        if ($name === '') {
            $_SESSION['flash_error'] = 'Название сервиса обязательно.';
            header('Location: edit-service.php?id=' . $id);
            exit;
        }

        // Update services WITHOUT updated_at to be compatible with schemas lacking that column
        $sqlUpd = "UPDATE services SET name = ?, contact_name = ?, phone = ?, email = ?, address = ?, description = ?, latitude = NULLIF(?,''), longitude = NULLIF(?,'') WHERE id = ? LIMIT 1";
        if ($stmt = $mysqli->prepare($sqlUpd)) {
            // placeholders: name, contact_name, phone, email, address, description, latitude, longitude, id => 9 params
            $stmt->bind_param('ssssssssi', $name, $contact_name, $phone, $email, $address, $description, $latitude, $longitude, $id);
            $stmt->execute();
            $stmt->close();
        } else {
            error_log('Prepare failed update services: ' . $mysqli->error);
        }

        /* ===== prices: simple approach - delete & insert new ===== */
        // Delete existing
        if ($del = $mysqli->prepare("DELETE FROM service_prices WHERE service_id = ?")) {
            $del->bind_param('i', $id);
            $del->execute();
            $del->close();
        }
        // Insert provided
        $priceNames = $_POST['prices']['name'] ?? [];
        $priceValues = $_POST['prices']['price'] ?? [];
        if (is_array($priceNames) && is_array($priceValues)) {
            if ($insP = $mysqli->prepare("INSERT INTO service_prices (service_id, name, price) VALUES (?, ?, ?)")) {
                for ($i = 0; $i < count($priceNames); $i++) {
                    $pname = trim((string)($priceNames[$i] ?? ''));
                    $pprice = trim((string)($priceValues[$i] ?? ''));
                    if ($pname === '' && $pprice === '') continue;
                    $insP->bind_param('iss', $id, $pname, $pprice);
                    $insP->execute();
                }
                $insP->close();
            }
        }

        /* ===== staff: simple rebuild ===== */
        // fetch existing staff photos to remove them (cleanup)
        $existingStaffPhotos = [];
        if ($st = $mysqli->prepare("SELECT id, photo FROM service_staff WHERE service_id = ?")) {
            $st->bind_param('i', $id);
            $st->execute();
            $resStaff = $st->get_result();
            while ($r = $resStaff->fetch_assoc()) {
                if (!empty($r['photo'])) $existingStaffPhotos[] = $r['photo'];
            }
            $st->close();
        }
        // delete existing staff rows
        if ($delS = $mysqli->prepare("DELETE FROM service_staff WHERE service_id = ?")) {
            $delS->bind_param('i', $id);
            $delS->execute();
            $delS->close();
        }
        // remove old staff photo files (best-effort)
        foreach ($existingStaffPhotos as $rel) {
            $fs = rtrim($uploadsFsRoot, '/') . '/' . ltrim($rel, '/');
            if ($fs && is_file($fs)) @unlink($fs);
        }

        $staffNames = $_POST['staff']['name'] ?? [];
        $staffPos = $_POST['staff']['position'] ?? [];
        $staffFiles = $_FILES['staff_photo'] ?? null;
        if (is_array($staffNames) && is_array($staffPos)) {
            if ($insS = $mysqli->prepare("INSERT INTO service_staff (service_id, name, position, photo, created_at) VALUES (?, ?, ?, ?, NOW())")) {
                $total = max(count($staffNames), count($staffPos));
                for ($si = 0; $si < $total; $si++) {
                    $sname = trim((string)($staffNames[$si] ?? ''));
                    $spos = trim((string)($staffPos[$si] ?? ''));
                    if ($sname === '' && $spos === '') continue;
                    $photoRel = null;
                    // process uploaded file at same index
                    if ($staffFiles && isset($staffFiles['tmp_name'][$si]) && is_uploaded_file($staffFiles['tmp_name'][$si])) {
                        $tmp = $staffFiles['tmp_name'][$si];
                        $orig = $staffFiles['name'][$si] ?? 'photo';
                        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION)) ?: 'jpg';
                        $ext = preg_replace('/[^a-z0-9]+/i','',$ext);
                        if (!in_array($ext, $allowedPhotoExt, true)) $ext = 'jpg';
                        $uniq = uniqid('st', true);
                        $dstName = 'staff_' . $uniq . '.' . $ext;
                        $dstPath = $serviceBaseDir . '/' . $dstName;
                        if (move_uploaded_file($tmp, $dstPath)) {
                            @chmod($dstPath, 0644);
                            $photoRel = 'uploads/services/' . $id . '/' . $dstName;
                        }
                    }
                    $insS->bind_param('isss', $id, $sname, $spos, $photoRel);
                    $insS->execute();
                }
                $insS->close();
            }
        }

        $_SESSION['flash'] = 'Данные сервиса сохранены.';
        header('Location: edit-service.php?id=' . $id);
        exit;
    } // end update_service

    /* ===== replace_logo ===== */
    if ($action === 'replace_logo') {
        if (!isset($_FILES['logo']) || !is_uploaded_file($_FILES['logo']['tmp_name'])) {
            $_SESSION['flash_error'] = 'Файл логотипа не выбран.';
            header('Location: edit-service.php?id=' . $id); exit;
        }
        $f = $_FILES['logo'];
        if ($f['size'] > 8*1024*1024) {
            $_SESSION['flash_error'] = 'Логотип слишком большой (max 8MB).';
            header('Location: edit-service.php?id=' . $id); exit;
        }
        $tmp = $f['tmp_name'];
        $orig = $f['name'] ?? 'logo';
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION)) ?: 'png';
        $ext = preg_replace('/[^a-z0-9]+/i','',$ext);
        if (!in_array($ext, $allowedPhotoExt, true)) $ext = 'png';
        $uniq = uniqid('logo', true);
        $dstName = 'logo_' . $uniq . '.' . $ext;
        $dstPath = $serviceLogoDir . '/' . $dstName;
        if (!move_uploaded_file($tmp, $dstPath)) {
            $_SESSION['flash_error'] = 'Не удалось сохранить логотип.';
            header('Location: edit-service.php?id=' . $id); exit;
        }
        @chmod($dstPath, 0644);
        $relPath = 'uploads/services/' . $id . '/logo/' . $dstName;

        // delete old logo file
        if (!empty($service['logo'])) {
            $oldFs = rtrim($uploadsFsRoot, '/') . '/' . ltrim($service['logo'], '/');
            if (is_file($oldFs)) @unlink($oldFs);
        }

        if ($u = $mysqli->prepare("UPDATE services SET logo = ? WHERE id = ? LIMIT 1")) {
            $u->bind_param('si', $relPath, $id);
            $u->execute();
            $u->close();
        }
        $_SESSION['flash'] = 'Логотип обновлён.';
        header('Location: edit-service.php?id=' . $id); exit;
    }

    /* ===== add_photos ===== */
    if ($action === 'add_photos') {
        if (!isset($_FILES['photos'])) {
            $_SESSION['flash_error'] = 'Файлы не отправлены.';
            header('Location: edit-service.php?id=' . $id); exit;
        }
        $files = $_FILES['photos'];
        $count = is_array($files['name']) ? count($files['name']) : 0;

        // count existing photos
        $existing = 0;
        if ($stc = $mysqli->prepare("SELECT COUNT(*) AS cnt FROM service_photos WHERE service_id = ?")) {
            $stc->bind_param('i', $id);
            $stc->execute();
            $rr = $stc->get_result()->fetch_assoc();
            $stc->close();
            $existing = isset($rr['cnt']) ? (int)$rr['cnt'] : 0;
        }
        $available = max(0, 50 - $existing); // cap 50 photos
        if ($available <= 0) {
            $_SESSION['flash_error'] = 'Достигнут лимит фотографий.';
            header('Location: edit-service.php?id=' . $id); exit;
        }

        $saved = []; $errors = [];
        if ($ins = $mysqli->prepare("INSERT INTO service_photos (service_id, photo, created_at) VALUES (?, ?, NOW())")) {
            for ($i = 0; $i < $count && $available > 0; $i++) {
                if (empty($files['name'][$i]) || !is_uploaded_file($files['tmp_name'][$i])) continue;
                $tmp = $files['tmp_name'][$i];
                $name = $files['name'][$i];
                $size = (int)$files['size'][$i];
                if ($size <= 0) { $errors[] = "Файл {$name} пустой."; continue; }
                if ($size > $maxPhotoSize) { $errors[] = "Файл {$name} превышает лимит."; continue; }
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'jpg';
                if (!in_array($ext, $allowedPhotoExt, true)) { $errors[] = "Неподдерживаемый формат: {$name}"; continue; }
                $uniq = uniqid('ph', true);
                $dstName = 'photo_' . $uniq . '.' . $ext;
                $dstPath = $servicePhotosDir . '/' . $dstName;
                if (!move_uploaded_file($tmp, $dstPath)) { $errors[] = "Не удалось сохранить {$name}"; continue; }
                @chmod($dstPath, 0644);
                $rel = 'uploads/services/' . $id . '/photos/' . $dstName;
                $ins->bind_param('is', $id, $rel);
                $ins->execute();
                $saved[] = $rel;
                $available--;
            }
            $ins->close();
        } else {
            $_SESSION['flash_error'] = 'DB error: ' . $mysqli->error;
            header('Location: edit-service.php?id=' . $id); exit;
        }

        if (!empty($errors)) $_SESSION['flash_error'] = implode(' ', $errors);
        else $_SESSION['flash'] = 'Фотографии добавлены.';
        header('Location: edit-service.php?id=' . $id); exit;
    }

    /* ===== delete_photo ===== */
    if ($action === 'delete_photo') {
        $pid = isset($_POST['photo_id']) ? (int)$_POST['photo_id'] : 0;
        if ($pid <= 0) {
            $_SESSION['flash_error'] = 'Неверный идентификатор фото.';
            header('Location: edit-service.php?id=' . $id); exit;
        }
        if ($st = $mysqli->prepare("SELECT photo FROM service_photos WHERE id = ? AND service_id = ? LIMIT 1")) {
            $st->bind_param('ii', $pid, $id);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if (!$row) {
                $_SESSION['flash_error'] = 'Фото не найдено.';
                header('Location: edit-service.php?id=' . $id); exit;
            }
            $rel = $row['photo'];
            $fs = rtrim($uploadsFsRoot, '/') . '/' . ltrim($rel, '/');
            if ($del = $mysqli->prepare("DELETE FROM service_photos WHERE id = ? AND service_id = ? LIMIT 1")) {
                $del->bind_param('ii', $pid, $id);
                $del->execute();
                $del->close();
            }
            if ($fs && is_file($fs)) @unlink($fs);
            $_SESSION['flash'] = 'Фото удалено.';
            header('Location: edit-service.php?id=' . $id); exit;
        } else {
            $_SESSION['flash_error'] = 'Ошибка удаления фото.';
            header('Location: edit-service.php?id=' . $id); exit;
        }
    }

    /* ===== set_main_video ===== */
    if ($action === 'set_main_video') {
        $vid = isset($_POST['video_id']) ? (int)$_POST['video_id'] : 0;
        if ($vid <= 0) {
            $_SESSION['flash_error'] = 'Неверный идентификатор видео.';
            header('Location: edit-service.php?id=' . $id); exit;
        }
        if ($st = $mysqli->prepare("SELECT id FROM service_videos WHERE id = ? AND service_id = ? LIMIT 1")) {
            $st->bind_param('ii', $vid, $id);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if (!$row) {
                $_SESSION['flash_error'] = 'Видео не найдено.';
                header('Location: edit-service.php?id=' . $id); exit;
            }
            if ($u = $mysqli->prepare("UPDATE service_videos SET is_main = 0 WHERE service_id = ?")) {
                $u->bind_param('i', $id);
                $u->execute();
                $u->close();
            }
            if ($u2 = $mysqli->prepare("UPDATE service_videos SET is_main = 1 WHERE id = ? AND service_id = ?")) {
                $u2->bind_param('ii', $vid, $id);
                $u2->execute();
                $u2->close();
            }
            $_SESSION['flash'] = 'Видео отмечено как основное.';
            header('Location: edit-service.php?id=' . $id); exit;
        } else {
            $_SESSION['flash_error'] = 'Ошибка при установке основного видео.';
            header('Location: edit-service.php?id=' . $id); exit;
        }
    }

    /* ===== add_videos ===== */
    if ($action === 'add_videos') {
        // If $_FILES empty but content length not, handle error earlier; here assume $_FILES exists
        if (!isset($_FILES['videos'])) {
            // Check if there were upload errors hidden by PHP - try to detect common causes
            if ($contentLength > $postMax) {
                $_SESSION['flash_error'] = "Отправленный объём данных ({$contentLength} байт) превышает post_max_size ({$_SERVER['CONTENT_LENGTH']}). Увеличьте post_max_size/upload_max_filesize в php.ini.";
            } else {
                $_SESSION['flash_error'] = 'Файлы не отправлены или превышен лимит upload_max_filesize.';
            }
            header('Location: edit-service.php?id=' . $id); exit;
        }
        $files = $_FILES['videos'];
        $count = is_array($files['name']) ? count($files['name']) : 0;

        $existingCount = count_service_videos($mysqli, $id);
        $available = max(0, $maxVideosPerService - $existingCount);
        if ($available <= 0) {
            $_SESSION['flash_error'] = "Достигнут лимит видео ({$maxVideosPerService}). Удалите лишние видео или измените основной.";
            header('Location: edit-service.php?id=' . $id); exit;
        }

        $processed = 0;
        $saved = []; $errors = [];

        for ($i = 0; $i < $count; $i++) {
            if ($processed >= $available) break;
            // handle individual file upload errors
            $err = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err !== UPLOAD_ERR_OK) {
                switch ($err) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $errors[] = "Файл {$files['name'][$i]} превышает максимально разрешённый размер на сервере.";
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $errors[] = "Файл {$files['name'][$i]} загружен частично.";
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        // skip silently
                        break;
                    default:
                        $errors[] = "Ошибка загрузки файла {$files['name'][$i]} (код {$err}).";
                }
                continue;
            }

            if (empty($files['name'][$i]) || !is_uploaded_file($files['tmp_name'][$i])) continue;
            $tmp = $files['tmp_name'][$i];
            $name = $files['name'][$i];
            $size = (int)$files['size'][$i];
            if ($size <= 0) { $errors[] = "Файл {$name} пустой."; continue; }
            if ($size > $maxVideoSize) { $errors[] = "Файл {$name} превышает лимит {$maxVideoSize} байт."; continue; }

            $mime = @mime_content_type($tmp) ?: '';
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'mp4';
            if (!in_array($mime, $allowedVideoMime, true) && !in_array($ext, $allowedVideoExt, true)) {
                $errors[] = "Неподдерживаемый формат видео: {$name} ({$mime})"; continue;
            }

            $ext = preg_replace('/[^a-z0-9]+/i', '', $ext);
            $uniq = uniqid('v', true);
            $dstName = 'video_' . '_' . $uniq . '.' . $ext;
            $dstPath = $serviceVideosDir . '/' . $dstName;
            if (!move_uploaded_file($tmp, $dstPath)) {
                $errors[] = "Не удалось сохранить файл {$name}.";
                continue;
            }
            @chmod($dstPath, 0644);
            $relPath = 'uploads/services/' . $id . '/videos/' . $dstName;

            if ($ins = $mysqli->prepare("INSERT INTO service_videos (service_id, video, mime, size, created_at) VALUES (?, ?, ?, ?, NOW())")) {
                $ins->bind_param('issi', $id, $relPath, $mime, $size);
                $ins->execute();
                $ins->close();
                $saved[] = $relPath;
                $processed++;
            } else {
                @unlink($dstPath);
                $errors[] = "DB error при сохранении записи для {$name}: " . $mysqli->error;
            }
        }

        // set main if none exists
        $hasMain = false;
        if ($st = $mysqli->prepare("SELECT COUNT(*) AS cnt FROM service_videos WHERE service_id = ? AND is_main = 1")) {
            $st->bind_param('i', $id);
            $st->execute();
            $rr = $st->get_result()->fetch_assoc();
            $st->close();
            $hasMain = isset($rr['cnt']) && (int)$rr['cnt'] > 0;
        }
        if (!$hasMain) {
            if ($u = $mysqli->prepare("UPDATE service_videos SET is_main = 1 WHERE id = (SELECT id FROM (SELECT id FROM service_videos WHERE service_id = ? ORDER BY id ASC LIMIT 1) x)")) {
                $u->bind_param('i', $id);
                $u->execute();
                $u->close();
            }
        }

        if (!empty($errors)) $_SESSION['flash_error'] = implode(' ', $errors);
        else $_SESSION['flash'] = 'Видео успешно добавлены.';
        header('Location: edit-service.php?id=' . $id); exit;
    }

    /* ===== delete_video ===== */
    if ($action === 'delete_video') {
        $vid = isset($_POST['video_id']) ? (int)$_POST['video_id'] : 0;
        if ($vid <= 0) {
            $_SESSION['flash_error'] = 'Неверный идентификатор видео.';
            header('Location: edit-service.php?id=' . $id); exit;
        }
        if ($st = $mysqli->prepare("SELECT video, is_main FROM service_videos WHERE id = ? AND service_id = ? LIMIT 1")) {
            $st->bind_param('ii', $vid, $id);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if (!$row) {
                $_SESSION['flash_error'] = 'Видео не найдено.';
                header('Location: edit-service.php?id=' . $id); exit;
            }
            $videoRel = $row['video'] ?? '';
            $wasMain = !empty($row['is_main']);
            $videoFs = rtrim($uploadsFsRoot, '/') . '/' . ltrim($videoRel, '/');
            if ($del = $mysqli->prepare("DELETE FROM service_videos WHERE id = ? AND service_id = ? LIMIT 1")) {
                $del->bind_param('ii', $vid, $id);
                $del->execute();
                $del->close();
            }
            if ($videoFs && is_file($videoFs)) @unlink($videoFs);
            if ($wasMain) {
                if ($u = $mysqli->prepare("UPDATE service_videos SET is_main = 1 WHERE id = (SELECT id FROM (SELECT id FROM service_videos WHERE service_id = ? ORDER BY id ASC LIMIT 1) x)")) {
                    $u->bind_param('i', $id);
                    $u->execute();
                    $u->close();
                }
            }
            $_SESSION['flash'] = 'Видео удалено.';
            header('Location: edit-service.php?id=' . $id); exit;
        } else {
            $_SESSION['flash_error'] = 'Ошибка при удалении видео.'; 
            header('Location: edit-service.php?id=' . $id); exit;
        }
    }

    // Unknown action fallback:
    $_SESSION['flash_error'] = 'Неизвестное действие.';
    header('Location: edit-service.php?id=' . $id);
    exit;
}

/* ------------------ Load related data for UI ------------------ */
/* Photos */
$photos = [];
if ($st = $mysqli->prepare("SELECT id, photo FROM service_photos WHERE service_id = ? ORDER BY id ASC")) {
    $st->bind_param('i', $id);
    $st->execute();
    $photos = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}
/* Prices */
$prices = [];
if ($st = $mysqli->prepare("SELECT id, name, price FROM service_prices WHERE service_id = ? ORDER BY id ASC")) {
    $st->bind_param('i', $id);
    $st->execute();
    $prices = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}
/* Staff */
$staff = [];
try {
    $res = $mysqli->query("SHOW TABLES LIKE 'service_staff'");
    if ($res && $res->num_rows > 0) {
        $st = $mysqli->prepare("SELECT id, name, position, photo FROM service_staff WHERE service_id = ? ORDER BY id ASC");
        if ($st) {
            $st->bind_param('i', $id);
            $st->execute();
            $staff = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();
        }
    }
    if ($res) $res->free();
} catch (Throwable $_) {
    // ignore
}
/* Videos */
$videos = [];
$res = $mysqli->query("SHOW TABLES LIKE 'service_videos'");
if ($res && $res->num_rows > 0) {
    if ($st = $mysqli->prepare("SELECT id, video, mime, size, created_at, IFNULL(is_main,0) AS is_main FROM service_videos WHERE service_id = ? ORDER BY is_main DESC, id ASC")) {
        $st->bind_param('i', $id);
        $st->execute();
        $videos = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    }
}
$existingCountUi = count($videos);

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Редактировать сервис — Mehanik</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    /* (стили оставил без изменений — они в оригинале) */
    :root{--bg:#f7fafc;--card:#ffffff;--accent:#0b57a4;--muted:#6b7280;--border:#e9f1fb;--radius:12px;--danger:#dc2626;}
    *{box-sizing:border-box} body{font-family:Inter,system-ui,Arial;background:var(--bg);color:#0f1724;margin:0}
    .page{max-width:1100px;margin:20px auto;padding:16px}
    .top-actions{ display:flex; align-items:center; justify-content:space-between; gap:12px; margin:0 auto 16px; max-width:1100px; padding:0 16px; }
    .top-actions h1{ margin:0; font-size:1.3rem; font-weight:800; color:#0f1724; }
    .controls { display:flex; gap:8px; align-items:center; }
    .btn { display:inline-flex; align-items:center; gap:8px; background:linear-gradient(180deg,var(--accent),#0f74d6); color:#fff; padding:10px 14px; border-radius:10px; border:0; cursor:pointer; font-weight:700; text-decoration:none; }
    .btn.ghost { background:transparent; color:var(--accent); border:1px solid rgba(11,87,164,0.08); padding:9px 12px; border-radius:10px; text-decoration:none; }
    .card { background:var(--card); border-radius:14px; padding:20px; box-shadow:0 12px 40px rgba(12,20,30,0.06); border:1px solid var(--border); }
    .form-grid { display:flex; flex-direction:column; gap:18px; align-items:stretch; }
    label.block { display:block; font-weight:700; margin-top:10px; color:#12202a; }
    .input, textarea, select { width:100%; padding:10px; border-radius:10px; border:1px solid #e6eef6; box-sizing:border-box; font-size:14px; margin-top:8px; background:#fff; }
    textarea{ min-height:110px; resize:vertical; }
    .row { display:flex; gap:10px; }
    .row .col{ flex:1; }
    .note { color:var(--muted); font-size:13px; margin-top:8px; }
    .map { height:260px; border-radius:10px; overflow:hidden; border:1px solid #e6eef7; margin-top:8px; }
    .file-picker { position:relative; display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px; border-radius:10px; border:1px dashed #e6eef6; background:linear-gradient(180deg,#fff,#fbfeff); cursor:pointer; margin-top:10px; min-height:64px; }
    .file-picker input[type=file]{ position:absolute; left:0; top:0; opacity:0; width:100%; height:100%; cursor:pointer; }
    .fp-left { display:flex; gap:10px; align-items:center; }
    .fp-title { font-weight:700; color:#0b3b60; }
    .fp-sub { color:var(--muted); font-size:.92rem; }
    .logo-preview { width:100%; height:160px; border-radius:10px; overflow:hidden; border:1px solid #eef6ff; display:flex; align-items:center; justify-content:center; background:#fff; margin-top:10px; }
    .logo-preview img { max-width:100%; max-height:100%; object-fit:contain; }
    .photos-grid { display:flex; flex-wrap:wrap; gap:10px; margin-top:12px; }
    .thumb { width:120px; height:90px; border-radius:8px; border:1px solid #eee; overflow:hidden; position:relative; background:#fff; display:flex; align-items:center; justify-content:center; }
    .thumb img { width:100%; height:100%; object-fit:cover; display:block; }
    .del-photo { position:absolute; top:6px; right:6px; background:rgba(255,255,255,0.95); border-radius:6px; padding:4px 6px; border:1px solid #f1f1f1; cursor:pointer; font-weight:700; }
    .prices-rows { display:flex; flex-direction:column; gap:8px; margin-top:10px; }
    .price-row { display:flex; gap:8px; align-items:center; }
    .price-row .p-name { flex:1; }
    .price-row .p-price { width:140px; }
    .staff { margin-top:14px; border-top:1px dashed #eef6f9; padding-top:12px; }
    .staff-rows { display:flex; flex-direction:column; gap:10px; margin-top:10px; }
    .staff-row { display:flex; gap:10px; align-items:center; }
    .s-photo { width:86px; height:86px; border-radius:8px; overflow:hidden; border:1px solid #eef6ff; display:flex; align-items:center; justify-content:center; background:#fff; }
    .s-photo img { width:100%; height:100%; object-fit:cover; }
    .s-name { flex:1; }
    .s-pos { width:220px; }
    .small-btn { padding:8px 10px; border-radius:10px; border:0; cursor:pointer; font-weight:700; background:#eef6ff; color:var(--accent); }
    .small-btn.del { background:#fff5f5; color:#b91c1c; border:1px solid #ffd6d6; }
    .video-list { display:flex; gap:12px; flex-wrap:wrap; margin-top:12px; }
    .video-item { width:240px; border-radius:10px; overflow:hidden; border:1px solid #eef6f7; background:#000; position:relative; }
    .video-item video { width:100%; height:140px; display:block; object-fit:cover; background:#000; }
    .video-meta { padding:8px; display:flex; align-items:center; justify-content:space-between; gap:8px; }
    .video-name { color:#fff; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:140px; }
    .video-actions form { display:inline-block; margin:0; }
    .video-actions button { background:#fff; color:#111; border-radius:8px; padding:6px 8px; border:0; cursor:pointer; font-weight:700; }
    .main-badge { position:absolute; left:10px; top:10px; background:#fff;color:#111;padding:6px 8px;border-radius:8px;font-weight:700;font-size:12px; box-shadow:0 6px 18px rgba(2,6,23,0.18); }
    .set-main-btn { background:linear-gradient(180deg,#fff,#f3f4f6); color:#111; border-radius:8px; padding:6px 8px; border:0; cursor:pointer; font-weight:700; }
    footer { padding:20px; text-align:center; color:#777; font-size:.9rem; }
    @media(max-width:980px){ .row{flex-direction:column} .video-item{width:100%} }
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<main class="page">
  <div class="top-actions">
    <h1>Редактировать сервис</h1>
    <div class="controls">
      <a href="service.php?id=<?= $id ?>" class="btn ghost">Просмотреть</a>
      <a href="services.php" class="btn">К списку</a>
    </div>
  </div>

  <div class="card">
    <?php if (!empty($_SESSION['flash'])): ?>
      <div style="margin-bottom:12px;color:#065f46;background:#f0fdfa;border:1px solid #d1fae5;padding:10px;border-radius:8px;"><?= htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
      <div style="margin-bottom:12px;color:#7f1d1d;background:#fff5f5;border:1px solid #ffd6d6;padding:10px;border-radius:8px;"><?= htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
    <?php endif; ?>

    <div class="form-grid">
      <div>
        <form method="post" action="edit-service.php?id=<?= $id ?>" enctype="multipart/form-data" id="serviceForm">
          <input type="hidden" name="action" value="update_service">
          <label class="block">Название*:</label>
          <input class="input" type="text" name="name" required value="<?= htmlspecialchars($service['name']) ?>">

          <div class="row" style="margin-top:8px;">
            <div class="col">
              <label class="block">Контактное имя</label>
              <input class="input" type="text" name="contact_name" value="<?= htmlspecialchars($service['contact_name']) ?>">
            </div>
            <div class="col">
              <label class="block">Телефон*</label>
              <input class="input" type="text" name="phone" required value="<?= htmlspecialchars($service['phone']) ?>" placeholder="+99371234567">
            </div>
          </div>

          <div class="row" style="margin-top:8px;">
            <div class="col">
              <label class="block">Email</label>
              <input class="input" type="email" name="email" value="<?= htmlspecialchars($service['email']) ?>">
            </div>
            <div class="col">
              <label class="block">Адрес</label>
              <input class="input" type="text" name="address" value="<?= htmlspecialchars($service['address']) ?>">
            </div>
          </div>

          <label class="block">Описание</label>
          <textarea class="input" name="description"><?= htmlspecialchars($service['description']) ?></textarea>

          <label class="block" style="margin-top:12px">Местоположение (щелчок по карте — поставить метку или ввести вручную)</label>
          <div id="map" class="map"></div>
          <input type="hidden" name="latitude" id="latitude" value="<?= htmlspecialchars($service['latitude']) ?>">
          <input type="hidden" name="longitude" id="longitude" value="<?= htmlspecialchars($service['longitude']) ?>">
          <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;align-items:center;">
            <label style="flex:1;min-width:160px;">Широта:
              <input class="input" type="text" id="latitude_manual" placeholder="например 37.9500" value="<?= htmlspecialchars($service['latitude']) ?>" />
            </label>
            <label style="flex:1;min-width:160px;">Долгота:
              <input class="input" type="text" id="longitude_manual" placeholder="например 58.3800" value="<?= htmlspecialchars($service['longitude']) ?>" />
            </label>
            <div class="note">Можно щёлкнуть по карте или ввести координаты вручную.</div>
          </div>

          <label class="block" style="margin-top:12px">Цены</label>
          <div class="prices-rows" id="pricesRows">
            <?php if (empty($prices)): ?>
              <div class="price-row">
                <input class="input p-name" type="text" name="prices[name][]" placeholder="Услуга">
                <input class="input p-price" type="text" name="prices[price][]" placeholder="Цена">
              </div>
            <?php else: foreach ($prices as $p): ?>
              <div class="price-row">
                <input class="input p-name" type="text" name="prices[name][]" value="<?= htmlspecialchars($p['name']) ?>" placeholder="Услуга">
                <input class="input p-price" type="text" name="prices[price][]" value="<?= htmlspecialchars($p['price']) ?>" placeholder="Цена">
              </div>
            <?php endforeach; endif; ?>
          </div>
          <div style="margin-top:10px;display:flex;gap:8px;align-items:center">
            <button type="button" id="addPrice" class="btn ghost" style="padding:8px 10px">+ Добавить позицию</button>
            <div class="note">Позиции будут сохранены как отдельные тарифы.</div>
          </div>

          <div class="staff" id="staffSection">
            <div style="display:flex;justify-content:space-between;align-items:center">
              <div style="font-weight:800">Сотрудники</div>
              <div><button type="button" id="addStaffBtn" class="small-btn">+ Добавить сотрудника</button></div>
            </div>
            <div class="staff-rows" id="staffRows">
              <?php if (!empty($staff)): foreach ($staff as $s): ?>
                <div class="staff-row">
                  <div class="s-photo">
                    <?php if (!empty($s['photo'])): ?>
                      <img src="<?= htmlspecialchars(toPublicUrl($s['photo'])) ?>" alt="">
                    <?php else: ?>
                      <div style="color:var(--muted);padding:6px;font-weight:700">Нет фото</div>
                    <?php endif; ?>
                  </div>
                  <div class="s-name">
                    <input class="input" type="text" name="staff[name][]" value="<?= htmlspecialchars($s['name']) ?>" placeholder="Имя сотрудника" required>
                  </div>
                  <div class="s-pos">
                    <input class="input" type="text" name="staff[position][]" value="<?= htmlspecialchars($s['position']) ?>" placeholder="Должность" required>
                  </div>
                  <div style="display:flex;gap:8px;">
                    <input type="file" name="staff_photo[]" accept="image/*" style="display:none">
                    <button type="button" class="small-btn" onclick="(function(btn){ const f = btn.previousElementSibling; f.click(); })(this);">Изменить фото</button>
                    <button type="button" class="small-btn del" onclick="if(confirm('Удалить сотрудника?')) this.closest('.staff-row').remove();">Удалить</button>
                  </div>
                </div>
              <?php endforeach; else: ?>
              <?php endif; ?>
            </div>
            <div class="note" style="margin-top:8px">Для каждого сотрудника укажите фото, имя и должность. Фото загружаются при сохранении формы.</div>
          </div>

          <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:18px">
            <a href="service.php?id=<?= $id ?>" class="btn ghost">Отмена</a>
            <button type="submit" class="btn">Сохранить изменения</button>
          </div>
        </form>
      </div>

      <!-- LOGO & PHOTOS & VIDEOS -->
      <div>
        <div style="margin-bottom:12px">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div style="font-weight:800">Логотип</div>
            <div class="note">Рекомендуемый ~500×300, max 5MB</div>
          </div>
          <div class="logo-preview" id="logoCurrentWrap">
            <?php if (!empty($service['logo'])): ?>
              <img id="currentLogo" src="<?= htmlspecialchars(toPublicUrl($service['logo'])) ?>" alt="logo">
            <?php else: ?>
              <div style="color:var(--muted);font-weight:700">Нет логотипа</div>
            <?php endif; ?>
          </div>

          <form method="post" enctype="multipart/form-data" style="margin-top:12px">
            <input type="hidden" name="action" value="replace_logo">
            <label class="file-picker" for="logoReplaceInput">
              <div class="fp-left">
                <div class="fp-title">Выбрать логотип</div>
                <div class="fp-sub">PNG, JPG, WEBP — заменит текущий</div>
              </div>
              <div style="display:flex;align-items:center;gap:10px">
                <div id="logoReplacePreview" style="width:84px;height:56px;border-radius:8px;border:1px solid #eef6ff;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden">
                  <span style="color:var(--muted);font-size:.85rem">Нет</span>
                </div>
                <input id="logoReplaceInput" type="file" name="logo" accept="image/*" required>
              </div>
            </label>
            <div style="display:flex;justify-content:flex-end;margin-top:10px;gap:8px">
              <button type="submit" class="btn">Заменить логотип</button>
            </div>
          </form>
        </div>

        <div style="margin-bottom:18px">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div style="font-weight:800">Фотографии</div>
            <div class="note">Добавьте до 50 новых</div>
          </div>

          <div class="photos-grid" id="existingPhotos" style="margin-top:12px">
            <?php if (empty($photos)): ?>
              <div class="note">Фото пока нет</div>
            <?php endif; ?>
            <?php foreach ($photos as $ph):
              $pid=(int)$ph['id'];
              $purl = toPublicUrl($ph['photo']);
            ?>
              <div class="thumb" title="Клик — удалить">
                <img src="<?= htmlspecialchars($purl) ?>" alt="">
                <form method="post" style="display:inline;">
                  <input type="hidden" name="action" value="delete_photo">
                  <input type="hidden" name="photo_id" value="<?= $pid ?>">
                  <button class="del-photo" type="submit" onclick="return confirm('Удалить фото?')">×</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>

          <form method="post" enctype="multipart/form-data" style="margin-top:12px">
            <input type="hidden" name="action" value="add_photos">
            <label class="file-picker" for="photosInput">
              <div class="fp-left">
                <div class="fp-title">Добавить фотографии</div>
                <div class="fp-sub">До 50 файлов, JPG/PNG/WEBP</div>
              </div>
              <div style="display:flex;align-items:center;gap:10px">
                <div id="photosPreviewSmall" style="display:flex;gap:8px;flex-wrap:wrap;max-width:220px"></div>
                <input id="photosInput" type="file" name="photos[]" accept="image/*" multiple>
              </div>
            </label>
            <div style="display:flex;justify-content:flex-end;margin-top:10px;gap:8px">
              <button type="submit" class="btn">Добавить фото</button>
            </div>
          </form>
        </div>

        <!-- VIDEO SECTION -->
        <div>
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div style="font-weight:800">Видео</div>
            <div class="note">MP4/WebM/OGG, max <?= intval($maxVideoSize/1024/1024) ?> MB — всего <?= $maxVideosPerService ?> видео</div>
          </div>

          <!-- existing videos -->
          <div class="video-list" id="videoList">
            <?php if (empty($videos)): ?>
              <div class="note" style="margin-top:10px">Видео не добавлено.</div>
            <?php else: foreach ($videos as $v):
              $vid = (int)$v['id'];
              [$vurl, $vfs] = find_upload_url_simple($v['video']);
              $isMain = !empty($v['is_main']);
            ?>
              <div class="video-item" title="<?= htmlspecialchars($v['video']) ?>">
                <?php if ($isMain): ?>
                  <div class="main-badge">Основное</div>
                <?php endif; ?>
                <video muted preload="metadata" controls playsinline poster="">
                  <source src="<?= htmlspecialchars($vurl) ?>" type="<?= htmlspecialchars($v['mime'] ?: 'video/mp4') ?>">
                  Ваш браузер не поддерживает видео.
                </video>
                <div class="video-meta">
                  <div class="video-name"><?= htmlspecialchars(basename($v['video'])) ?></div>
                  <div class="video-actions">
                    <?php if (!$isMain): ?>
                      <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="set_main_video">
                        <input type="hidden" name="video_id" value="<?= $vid ?>">
                        <button type="submit" class="set-main-btn" onclick="return confirm('Сделать это видео основным/постером?')">Сделать основным</button>
                      </form>
                    <?php else: ?>
                      <!-- already main -->
                    <?php endif; ?>

                    <form method="post" onsubmit="return confirm('Удалить видео?');" style="display:inline;margin-left:6px;">
                      <input type="hidden" name="action" value="delete_video">
                      <input type="hidden" name="video_id" value="<?= $vid ?>">
                      <button type="submit">Удалить</button>
                    </form>
                  </div>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>

          <!-- add videos -->
          <?php $remainingSlots = max(0, $maxVideosPerService - $existingCountUi); ?>
          <form method="post" enctype="multipart/form-data" style="margin-top:12px">
            <input type="hidden" name="action" value="add_videos">
            <label class="file-picker" for="videosInput" id="videosPickerLabel">
              <div class="fp-left">
                <div class="fp-title">Добавить видео</div>
                <div class="fp-sub">Можно выбрать несколько файлов — осталось слотов: <strong id="slotsLeft"><?= $remainingSlots ?></strong></div>
              </div>
              <div style="display:flex;align-items:center;gap:10px">
                <div id="videosPreview" style="display:flex;gap:8px;flex-wrap:wrap;max-width:300px"></div>
                <input id="videosInput" type="file" name="videos[]" accept="video/*" multiple <?= $remainingSlots <= 0 ? 'disabled' : '' ?>>
              </div>
            </label>

            <div style="display:flex;justify-content:flex-end;margin-top:10px;gap:8px">
              <button type="submit" class="btn" <?= $remainingSlots <= 0 ? 'disabled' : '' ?>>Загрузить видео</button>
            </div>
          </form>
        </div>

      </div>

    </div>
  </div>
</main>

<footer>&copy; <?= date('Y') ?> Mehanik</footer>

<!-- Google Maps init -->
<script>
function showManualCoordsFallback() {
  var mapEl = document.getElementById('map');
  if (mapEl) {
    mapEl.innerHTML = '<div style="padding:18px;color:#444">Карта недоступна. Введите координаты вручную ниже.</div>';
  }
}

function initMap() {
  try {
    var center = { lat: <?= ($service['latitude'] !== null && $service['latitude'] !== '') ? floatval($service['latitude']) : 37.95 ?>, lng: <?= ($service['longitude'] !== null && $service['longitude'] !== '') ? floatval($service['longitude']) : 58.38 ?> };
    var map = new google.maps.Map(document.getElementById('map'), {
      center: center,
      zoom: 13,
      streetViewControl: false
    });

    var latHidden = document.getElementById('latitude');
    var lngHidden = document.getElementById('longitude');
    var latManual = document.getElementById('latitude_manual');
    var lngManual = document.getElementById('longitude_manual');
    var marker = null;

    if (latHidden && lngHidden && latHidden.value && lngHidden.value) {
      var lat0 = parseFloat(latHidden.value);
      var lng0 = parseFloat(lngHidden.value);
      if (!isNaN(lat0) && !isNaN(lng0)) {
        marker = new google.maps.Marker({ position: { lat: lat0, lng: lng0 }, map: map });
        map.setCenter({ lat: lat0, lng: lng0 });
        if (latManual) latManual.value = lat0;
        if (lngManual) lngManual.value = lng0;
      }
    } else {
      if (latManual && lngManual && latManual.value && lngManual.value) {
        var latm = parseFloat(latManual.value);
        var lngm = parseFloat(lngManual.value);
        if (!isNaN(latm) && !isNaN(lngm)) {
          marker = new google.maps.Marker({ position: { lat: latm, lng: lngm }, map: map });
          map.setCenter({ lat: latm, lng: lngm });
          if (latHidden) latHidden.value = latm;
          if (lngHidden) lngHidden.value = lngm;
        }
      }
    }

    map.addListener('click', function(e) {
      var lat = e.latLng.lat();
      var lng = e.latLng.lng();
      if (marker) marker.setPosition(e.latLng); else marker = new google.maps.Marker({ position: e.latLng, map: map });
      if (latHidden) latHidden.value = lat;
      if (lngHidden) lngHidden.value = lng;
      if (latManual) latManual.value = lat;
      if (lngManual) lngManual.value = lng;
    });

    console.info('Google Maps initialized');
  } catch (err) {
    console.warn('Google Maps init error:', err);
    showManualCoordsFallback();
  }
}

setTimeout(function(){ if (typeof google === 'undefined' || typeof google.maps === 'undefined') { console.warn('Google Maps not available, showing manual fallback'); showManualCoordsFallback(); } }, 6000);

// Sync manual inputs <-> hidden inputs
(function(){
  var latHidden = document.getElementById('latitude');
  var lngHidden = document.getElementById('longitude');
  var latManual = document.getElementById('latitude_manual');
  var lngManual = document.getElementById('longitude_manual');

  function setHiddenFromManual() {
    if (!latHidden || !lngHidden || !latManual || !lngManual) return;
    latHidden.value = latManual.value.trim();
    lngHidden.value = lngManual.value.trim();
  }
  if (latManual) latManual.addEventListener('input', setHiddenFromManual);
  if (lngManual) lngManual.addEventListener('input', setHiddenFromManual);
  document.addEventListener('DOMContentLoaded', function(){
    if (latHidden && latHidden.value) latManual.value = latHidden.value;
    if (lngHidden && lngHidden.value) lngManual.value = lngHidden.value;
  });
})();
</script>

<!-- small widgets: prices, logo/photo/video previews, staff -->
<script>
(function(){
  // prices
  const addPriceBtn = document.getElementById('addPrice');
  const pricesRows = document.getElementById('pricesRows');
  if (addPriceBtn) {
    addPriceBtn.addEventListener('click', function(){
      const div = document.createElement('div'); div.className = 'price-row';
      const in1 = document.createElement('input'); in1.type='text'; in1.name='prices[name][]'; in1.className='input p-name'; in1.placeholder='Услуга';
      const in2 = document.createElement('input'); in2.type='text'; in2.name='prices[price][]'; in2.className='input p-price'; in2.placeholder='Цена';
      div.appendChild(in1); div.appendChild(in2);
      pricesRows.appendChild(div);
      in1.focus();
    });
  }

  // logo preview
  const logoInput = document.getElementById('logoReplaceInput');
  const logoPreview = document.getElementById('logoReplacePreview');
  if (logoInput) {
    logoInput.addEventListener('change', function(){
      const f = this.files && this.files[0];
      if (!f) { logoPreview.innerHTML = '<span style="color:var(--muted)">Нет</span>'; return; }
      if (!f.type.startsWith('image/')) { alert('Только изображения допустимы'); this.value=''; return; }
      const fr = new FileReader();
      fr.onload = function(ev){
        logoPreview.innerHTML = '';
        const img = document.createElement('img');
        img.src = ev.target.result;
        img.style.width='100%'; img.style.height='100%'; img.style.objectFit='contain';
        logoPreview.appendChild(img);
      };
      fr.readAsDataURL(f);
    });
  }

  // photos preview
  const photosInput = document.getElementById('photosInput');
  const photosPreviewSmall = document.getElementById('photosPreviewSmall');
  if (photosInput) {
    photosInput.addEventListener('change', function(){
      const files = Array.from(this.files || []);
      photosPreviewSmall.innerHTML = '';
      if (files.length === 0) { photosPreviewSmall.innerHTML = '<span style="color:var(--muted)">Нет</span>'; return; }
      if (files.length > 50) { alert('Максимум 50 фотографий'); this.value=''; photosPreviewSmall.innerHTML=''; return; }
      files.slice(0,50).forEach(f => {
        if (!f.type.startsWith('image/')) return;
        const fr = new FileReader();
        fr.onload = function(ev){
          const div = document.createElement('div'); div.className='thumb';
          const img = document.createElement('img'); img.src = ev.target.result;
          div.appendChild(img);
          photosPreviewSmall.appendChild(div);
        };
        fr.readAsDataURL(f);
      });
    });
  }

  // videos preview
  const videosInput = document.getElementById('videosInput');
  const videosPreview = document.getElementById('videosPreview');
  const slotsLeftEl = document.getElementById('slotsLeft');
  var remainingSlots = parseInt(slotsLeftEl ? slotsLeftEl.textContent : '0', 10);
  if (videosInput) {
    videosInput.addEventListener('change', function(){
      const files = Array.from(this.files || []);
      videosPreview.innerHTML = '';
      if (files.length === 0) { videosPreview.innerHTML = '<span style="color:var(--muted)">Нет</span>'; return; }
      if (remainingSlots <= 0) { alert('Достигнут лимит видео. Удалите существующие видео.'); this.value=''; return; }
      const take = Math.min(remainingSlots, files.length, 10);
      files.slice(0,take).forEach(f => {
        if (!f.type.startsWith('video/')) {
          const d = document.createElement('div'); d.style.color='var(--muted)'; d.textContent = 'Неподдерживаемый файл: ' + f.name; videosPreview.appendChild(d); return;
        }
        const url = URL.createObjectURL(f);
        const box = document.createElement('div'); box.style.width='120px'; box.style.height='80px'; box.style.background='#000'; box.style.display='flex'; box.style.alignItems='center'; box.style.justifyContent='center'; box.style.borderRadius='6px'; box.style.overflow='hidden';
        const vid = document.createElement('video'); vid.src=url; vid.muted=true; vid.preload='metadata'; vid.style.width='100%'; vid.style.height='100%'; vid.style.objectFit='cover';
        box.appendChild(vid);
        videosPreview.appendChild(box);
      });
      if (files.length > take) {
        const warn = document.createElement('div'); warn.style.color='var(--muted)'; warn.textContent = 'Будут загружены только первые ' + take + ' файла(ов) (лимит).';
        videosPreview.appendChild(warn);
      }
    });
  }
})();
</script>

<!-- Staff widget -->
<script>
(function(){
  const staffRows = document.getElementById('staffRows');
  const addStaffBtn = document.getElementById('addStaffBtn');

  function createStaffRow(name = '', position = '') {
    const r = document.createElement('div'); r.className = 'staff-row';

    const photoWrap = document.createElement('div'); photoWrap.className = 's-photo';
    const photoPlaceholder = document.createElement('div'); photoPlaceholder.style.color = 'var(--muted)'; photoPlaceholder.style.fontWeight = '700'; photoPlaceholder.style.padding = '6px'; photoPlaceholder.textContent = 'Нет фото';
    photoWrap.appendChild(photoPlaceholder);

    const photoInput = document.createElement('input'); photoInput.type='file'; photoInput.name='staff_photo[]'; photoInput.accept='image/*'; photoInput.style.display='none';

    photoInput.addEventListener('change', function(){
      const f = this.files && this.files[0];
      if (!f || !f.type.startsWith('image/')) return;
      const fr = new FileReader();
      fr.onload = function(ev){
        photoWrap.innerHTML = '';
        const img = document.createElement('img'); img.src = ev.target.result; img.style.width='100%'; img.style.height='100%'; img.style.objectFit='cover';
        photoWrap.appendChild(img);
      };
      fr.readAsDataURL(f);
    });

    const nameWrap = document.createElement('div'); nameWrap.className = 's-name';
    const nameInput = document.createElement('input'); nameInput.type='text'; nameInput.name='staff[name][]'; nameInput.className='input'; nameInput.placeholder='Имя сотрудника'; nameInput.value = name;
    nameWrap.appendChild(nameInput);

    const posWrap = document.createElement('div'); posWrap.className = 's-pos';
    const posInput = document.createElement('input'); posInput.type='text'; posInput.name='staff[position][]'; posInput.className='input'; posInput.placeholder='Должность'; posInput.value = position;
    posWrap.appendChild(posInput);

    const actions = document.createElement('div'); actions.style.display='flex'; actions.style.gap='8px';
    const btnPhoto = document.createElement('button'); btnPhoto.type='button'; btnPhoto.className='small-btn'; btnPhoto.textContent='Выбрать фото';
    btnPhoto.addEventListener('click', function(){ photoInput.click(); });
    const btnRemove = document.createElement('button'); btnRemove.type='button'; btnRemove.className='small-btn del'; btnRemove.textContent='Удалить';
    btnRemove.addEventListener('click', function(){ if(confirm('Удалить сотрудника?')) r.remove(); });

    actions.appendChild(btnPhoto); actions.appendChild(btnRemove);

    r.appendChild(photoWrap);
    r.appendChild(photoInput);
    r.appendChild(nameWrap);
    r.appendChild(posWrap);
    r.appendChild(actions);

    return r;
  }

  if (addStaffBtn) {
    addStaffBtn.addEventListener('click', function(){
      const row = createStaffRow('','');
      staffRows.appendChild(row);
      row.scrollIntoView({behavior:'smooth', block:'center'});
    });
  }
})();
</script>

<!-- Replace YOUR_GOOGLE_API_KEY -->
<script async defer src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_API_KEY&callback=initMap"></script>
</body>
</html>
