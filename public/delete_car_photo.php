<?php
// mehanik/public/delete_car_photo.php
require_once __DIR__ . '/middleware.php';
require_auth();
require_once __DIR__ . '/db.php';

$currentUser = $_SESSION['user'] ?? null;
$uid = (int)($currentUser['id'] ?? 0);
$isAdmin = in_array($currentUser['role'] ?? '', ['admin','superadmin'], true);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$car_id = isset($_GET['car_id']) ? (int)$_GET['car_id'] : 0;
if (!$id) { header('Location: /mehanik/public/'); exit; }

// получим запись photo и car_id (если car_id не передан)
if (isset($mysqli) && $mysqli instanceof mysqli) {
    $st = $mysqli->prepare("SELECT car_id, file_path FROM car_photos WHERE id = ?");
    $st->bind_param('i', $id);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
} else {
    $st = $pdo->prepare("SELECT car_id, file_path FROM car_photos WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
}

if (!$row) { header('Location: /mehanik/public/'); exit; }
$car_id = (int)$row['car_id'];
$file_path = $row['file_path'] ?? '';

// проверим права: если не админ — должен быть владельцем объявления
$ownerOk = false;
if ($isAdmin) $ownerOk = true;
else {
    // получим car.user_id
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $st2 = $mysqli->prepare("SELECT user_id FROM cars WHERE id = ? LIMIT 1");
        $st2->bind_param('i', $car_id);
        $st2->execute();
        $r2 = $st2->get_result()->fetch_assoc();
        $ownerOk = $r2 && ((int)$r2['user_id'] === $uid);
    } else {
        $st2 = $pdo->prepare("SELECT user_id FROM cars WHERE id = ? LIMIT 1");
        $st2->execute([$car_id]);
        $r2 = $st2->fetch(PDO::FETCH_ASSOC);
        $ownerOk = $r2 && ((int)$r2['user_id'] === $uid);
    }
}

if (!$ownerOk) { http_response_code(403); echo "Нет прав"; exit; }

// удаляем запись и файл
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $stmt = $mysqli->prepare("DELETE FROM car_photos WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("DELETE FROM car_photos WHERE id = ?");
        $stmt->execute([$id]);
    }
} catch (Throwable $e) {
    header('Location: /mehanik/public/edit-car.php?id=' . $car_id . '&err=db');
    exit;
}

// удаляем файл безопасно
if ($file_path) {
    $candidates = [];
    if (strpos($file_path, 'uploads/') === 0) $candidates[] = __DIR__ . '/' . $file_path;
    else $candidates[] = __DIR__ . '/uploads/cars/' . ltrim($file_path, '/');

    foreach ($candidates as $f) {
        if (file_exists($f) && is_file($f)) { @unlink($f); break; }
    }
}

header('Location: /mehanik/public/edit-car.php?id=' . $car_id . '&msg=' . urlencode('Фото удалено'));
exit;
