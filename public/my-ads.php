<?php
// public/my-ads.php — "Мои объявления" (устойчиво к отсутствию колонок recommended/premium)
require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php';

$user_id = (int)($_SESSION['user']['id'] ?? 0);
if (!$user_id) {
    http_response_code(403);
    echo "Пользователь не найден в сессии.";
    exit;
}
$isAdmin = (isset($_SESSION['user']['role']) && strtolower($_SESSION['user']['role']) === 'admin');

$noPhoto = '/mehanik/assets/no-photo.png';

// helper: check if table has column
function hasColumn(mysqli $mysqli, $table, $col) {
    $table = $mysqli->real_escape_string($table);
    $col = $mysqli->real_escape_string($col);
    $q = "SHOW COLUMNS FROM `{$table}` LIKE '{$col}'";
    $r = $mysqli->query($q);
    if (!$r) return false;
    $ok = $r->num_rows > 0;
    $r->free();
    return $ok;
}

// prepare availability flags per table
$services_has_recommended = hasColumn($mysqli, 'services', 'recommended');
$services_has_premium     = hasColumn($mysqli, 'services', 'premium');

$cars_has_recommended = hasColumn($mysqli, 'cars', 'recommended');
$cars_has_premium     = hasColumn($mysqli, 'cars', 'premium');

$products_has_recommended = hasColumn($mysqli, 'products', 'recommended');
$products_has_premium     = hasColumn($mysqli, 'products', 'premium');

$flash = null;
if (!empty($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }
$flash_error = null;
if (!empty($_SESSION['flash_error'])) { $flash_error = $_SESSION['flash_error']; unset($_SESSION['flash_error']); }

// redirect helper
function redirectBackWith($msg = null, $err = null) {
    if ($msg) $_SESSION['flash'] = $msg;
    if ($err) $_SESSION['flash_error'] = $err;
    header('Location: my-ads.php');
    exit;
}

// --- handle POST actions: delete, toggle_recommended, toggle_premium ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $action = $_POST['action'];
    $type = $_POST['type'] ?? '';
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if (!in_array($type, ['service','car','product'], true) || $id <= 0) {
        redirectBackWith(null, 'Неверные параметры запроса.');
    }

    // check owner
    $tbl = $type === 'service' ? 'services' : ($type === 'car' ? 'cars' : 'products');
    $ownerId = 0;
    if ($st = $mysqli->prepare("SELECT user_id FROM `{$tbl}` WHERE id = ? LIMIT 1")) {
        $st->bind_param('i', $id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
        $ownerId = $row ? (int)$row['user_id'] : 0;
    }
    $isOwner = ($ownerId === $user_id);
    if (!$isAdmin && !$isOwner) redirectBackWith(null, 'Нет прав на выполнение действия.');

    try {
        if ($action === 'delete') {
            $mysqli->begin_transaction();

            if ($type === 'service') {
                // delete service photos
                if ($st = $mysqli->prepare("SELECT photo FROM service_photos WHERE service_id = ?")) {
                    $st->bind_param('i', $id);
                    $st->execute();
                    $photos = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                    $st->close();
                    foreach ($photos as $p) {
                        $f = $p['photo'] ?? '';
                        if ($f) {
                            $fs = __DIR__ . '/../' . ltrim($f, '/');
                            if (is_file($fs)) @unlink($fs);
                        }
                    }
                }
                $mysqli->query("DELETE FROM service_photos WHERE service_id = " . intval($id));
                $mysqli->query("DELETE FROM service_prices WHERE service_id = " . intval($id));
                $mysqli->query("DELETE FROM service_reviews WHERE service_id = " . intval($id));
                $mysqli->query("DELETE FROM service_ratings WHERE service_id = " . intval($id));
                // delete logo file
                if ($st = $mysqli->prepare("SELECT logo FROM services WHERE id = ? LIMIT 1")) {
                    $st->bind_param('i', $id);
                    $st->execute();
                    $row = $st->get_result()->fetch_assoc() ?: [];
                    $st->close();
                    if (!empty($row['logo'])) { $fs = __DIR__ . '/../' . ltrim($row['logo'],'/'); if (is_file($fs)) @unlink($fs); }
                }
                if ($st = $mysqli->prepare("DELETE FROM services WHERE id = ? LIMIT 1")) { $st->bind_param('i', $id); $st->execute(); $st->close(); }

                // RECURSIVE DIRECTORY REMOVAL (удаляет вложенные папки, например uploads/services/{id}/staff)
                $dir = realpath(__DIR__ . '/../uploads/services/' . $id);
                $base = realpath(__DIR__ . '/../uploads/services');

                // безопасность: убедимся, что $dir реально существует и находится внутри каталога uploads/services
                if ($dir && $base && strpos($dir, $base) === 0 && is_dir($dir)) {
                    try {
                        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
                        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
                        foreach ($files as $fileinfo) {
                            $path = $fileinfo->getRealPath();
                            if ($fileinfo->isFile() || $fileinfo->isLink()) {
                                @unlink($path);
                            } elseif ($fileinfo->isDir()) {
                                @rmdir($path);
                            }
                        }
                        // удалить сам каталог
                        @rmdir($dir);
                    } catch (UnexpectedValueException $e) {
                        // оставим лог в сессии, чтобы понять причину
                        $_SESSION['flash_error'] = 'Не удалось рекурсивно удалить папку с файлами: ' . $e->getMessage();
                    }
                }

            } elseif ($type === 'car') {
                if ($st = $mysqli->prepare("SELECT photo FROM cars WHERE id = ? LIMIT 1")) {
                    $st->bind_param('i', $id); $st->execute();
                    $row = $st->get_result()->fetch_assoc() ?: []; $st->close();
                    if (!empty($row['photo'])) { $fs = __DIR__ . '/../' . ltrim($row['photo'],'/'); if (is_file($fs)) @unlink($fs); }
                }
                if ($st = $mysqli->prepare("DELETE FROM cars WHERE id = ? LIMIT 1")) { $st->bind_param('i', $id); $st->execute(); $st->close(); }
                $dir = __DIR__ . '/../uploads/cars/' . $id;
                if (is_dir($dir)) { $files = glob($dir . '/*'); foreach ($files as $f) if (is_file($f)) @unlink($f); @rmdir($dir); }

            } elseif ($type === 'product') {
                if ($st = $mysqli->prepare("SELECT photo FROM products WHERE id = ? LIMIT 1")) {
                    $st->bind_param('i', $id); $st->execute();
                    $row = $st->get_result()->fetch_assoc() ?: []; $st->close();
                    if (!empty($row['photo'])) { $fs = __DIR__ . '/../' . ltrim($row['photo'],'/'); if (is_file($fs)) @unlink($fs); }
                }
                if ($st = $mysqli->prepare("DELETE FROM products WHERE id = ? LIMIT 1")) { $st->bind_param('i', $id); $st->execute(); $st->close(); }
                $dir = __DIR__ . '/../uploads/products/' . $id;
                if (is_dir($dir)) { $files = glob($dir . '/*'); foreach ($files as $f) if (is_file($f)) @unlink($f); @rmdir($dir); }
            }

            $mysqli->commit();
            redirectBackWith('Объявление удалено.');
        }

        // toggles
        if ($action === 'toggle_recommended' || $action === 'toggle_premium') {
            $field = ($action === 'toggle_recommended') ? 'recommended' : 'premium';

            // check column existence for this table
            $colExists = hasColumn($mysqli, $tbl, $field);
            if (!$colExists) {
                redirectBackWith(null, "Поле '{$field}' не найдено в таблице '{$tbl}'. Действие невозможно.");
            }

            // read current
            if ($st = $mysqli->prepare("SELECT COALESCE(`{$field}`,0) AS cur FROM `{$tbl}` WHERE id = ? LIMIT 1")) {
                $st->bind_param('i', $id);
                $st->execute();
                $row = $st->get_result()->fetch_assoc() ?: [];
                $st->close();
                $cur = (int)($row['cur'] ?? 0);
                $new = $cur ? 0 : 1;
                if ($st = $mysqli->prepare("UPDATE `{$tbl}` SET `{$field}` = ? WHERE id = ? LIMIT 1")) {
                    $st->bind_param('ii', $new, $id);
                    $st->execute();
                    $st->close();
                    redirectBackWith( ($new ? 'Включен' : 'Отключен') . " режим " . ($field === 'recommended' ? 'Super' : 'Premium') . " для объявления.");
                } else {
                    redirectBackWith(null, 'Ошибка обновления в БД.');
                }
            } else {
                redirectBackWith(null, 'Ошибка чтения из БД.');
            }
        }

        redirectBackWith(null, 'Неизвестное действие.');
    } catch (Throwable $e) {
        if ($mysqli->in_transaction) $mysqli->rollback();
        redirectBackWith(null, 'Ошибка: ' . $e->getMessage());
    }
}

// --- fetch user items (services, cars, products) with safe column expressions ---
$items = [];

// services select
$svc_rec = $services_has_recommended ? "COALESCE(recommended,0) AS recommended" : "0 AS recommended";
$svc_prem = $services_has_premium ? "COALESCE(premium,0) AS premium" : "0 AS premium";

if ($st = $mysqli->prepare("SELECT id, name AS title, description, logo AS photo, status, created_at, {$svc_rec}, {$svc_prem} FROM services WHERE user_id = ? ORDER BY id DESC")) {
    $st->bind_param('i',$user_id); $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    foreach ($rows as $r) { $r['type']='service'; $items[] = $r; }
    $st->close();
}

// cars select
$car_rec = $cars_has_recommended ? "COALESCE(recommended,0) AS recommended" : "0 AS recommended";
$car_prem = $cars_has_premium ? "COALESCE(premium,0) AS premium" : "0 AS premium";

if ($st = $mysqli->prepare("SELECT id, CONCAT(COALESCE(brand,''), ' ', COALESCE(model,'')) AS title, description, photo, status, created_at, {$car_rec}, {$car_prem} FROM cars WHERE user_id = ? ORDER BY id DESC")) {
    $st->bind_param('i',$user_id); $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    foreach ($rows as $r) { $r['type']='car'; $items[] = $r; }
    $st->close();
}

// products select
$prod_rec = $products_has_recommended ? "COALESCE(recommended,0) AS recommended" : "0 AS recommended";
$prod_prem = $products_has_premium ? "COALESCE(premium,0) AS premium" : "0 AS premium";

if ($st = $mysqli->prepare("SELECT id, COALESCE(name, sku, '') AS title, description, photo, status, created_at, {$prod_rec}, {$prod_prem} FROM products WHERE user_id = ? ORDER BY id DESC")) {
    $st->bind_param('i',$user_id); $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    foreach ($rows as $r) { $r['type']='product'; $items[] = $r; }
    $st->close();
}

// sort items by created_at desc overall
usort($items, function($a,$b){
    $ta = strtotime($a['created_at'] ?? '1970-01-01'); $tb = strtotime($b['created_at'] ?? '1970-01-01');
    return $tb <=> $ta;
});

// helper to convert relative photo path to public URL
function pubUrl($rel) {
    global $noPhoto;
    if (!$rel) return $noPhoto;
    if (preg_match('#^https?://#i', $rel)) return $rel;
    if ($rel[0] === '/') return $rel;
    return '/mehanik/' . ltrim($rel, '/');
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Мои объявления — Mehanik</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    body{font-family:Inter,system-ui,Arial;background:#f6f8fb;color:#111}
    .wrap{max-width:1100px;margin:18px auto;padding:12px}
    .row{display:flex;gap:12px;background:#fff;padding:12px;border-radius:10px;border:1px solid #eef3f7;align-items:center;margin-bottom:10px}
    .thumb{width:120px;height:84px;border-radius:8px;overflow:hidden;background:#f7f9fc;display:flex;align-items:center;justify-content:center}
    .thumb img{width:100%;height:100%;object-fit:cover}
    .body{flex:1;min-width:0}
    .title{font-weight:800;color:#0b57a4;text-decoration:none}
    .meta{color:#6b7280;font-size:.95rem}
    .actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .btn{padding:8px 10px;border-radius:8px;border:0;cursor:pointer;font-weight:700}
    .btn-view{background:#eef6ff;color:#0b57a4}
    .btn-edit{background:#fff7ed;color:#a16207;border:1px solid rgba(161,98,7,0.06)}
    .btn-delete{background:#fff6f6;color:#ef4444;border:0}
    .btn-super{background:linear-gradient(180deg,#fff8ed,#fff3df);border:1px solid #ffd28a}
    .btn-prem{background:linear-gradient(180deg,#fbf7ff,#f6f1ff);border:1px solid #d7b8ff}
    .tag{font-size:12px;padding:4px 8px;border-radius:999px;background:#f3f5f8;color:#334155;font-weight:700}
    @media(max-width:760px){ .row{flex-direction:column;align-items:stretch} .thumb{width:100%;height:200px} .actions{justify-content:flex-start} }
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<div class="wrap">
  <h1>Мои объявления</h1>

  <?php if ($flash): ?><div style="background:#ecfdf5;padding:10px;border-radius:8px;margin-bottom:12px;color:#065f46;"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
  <?php if ($flash_error): ?><div style="background:#fff1f2;padding:10px;border-radius:8px;margin-bottom:12px;color:#7f1d1d;"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>

  <?php if (empty($items)): ?>
    <div class="row">У вас пока нет объявлений.</div>
  <?php else: foreach ($items as $it):
      $type = $it['type'];
      $typeLabel = $type === 'service' ? 'Сервис/Услуга' : ($type === 'car' ? 'Авто' : 'Запчасть');
      $viewUrl = $type === 'service' ? "service.php?id=" . (int)$it['id'] : ($type === 'car' ? "car.php?id=" . (int)$it['id'] : "product.php?id=" . (int)$it['id']);
      $editUrl = $type === 'service' ? "edit-service.php?id=" . (int)$it['id'] : ($type === 'car' ? "edit-car.php?id=" . (int)$it['id'] : "edit-product.php?id=" . (int)$it['id']);
  ?>
    <div class="row">
      <div class="thumb">
        <a href="/mehanik/public/<?= htmlspecialchars($viewUrl) ?>"><img src="<?= htmlspecialchars(pubUrl($it['photo'])) ?>" alt=""></a>
      </div>

      <div class="body">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
          <div style="min-width:0">
            <a class="title" href="/mehanik/public/<?= htmlspecialchars($viewUrl) ?>"><?= htmlspecialchars($it['title'] ?: ("Объявление #" . (int)$it['id'])) ?></a>
            <div class="meta"><?= htmlspecialchars($typeLabel) ?> • <?= htmlspecialchars($it['status'] ?? '') ?> • <?= htmlspecialchars(date('Y-m-d', strtotime($it['created_at'] ?? '')) ) ?></div>
          </div>
          <div style="text-align:right">
            <?php if (!empty($it['recommended'])): ?><div class="tag">★ Super</div><?php endif; ?>
            <?php if (!empty($it['premium'])): ?><div class="tag" style="margin-top:6px">✨ Premium</div><?php endif; ?>
          </div>
        </div>

        <?php if (!empty($it['description'])): ?>
          <div style="margin-top:8px;color:#374151"><?= htmlspecialchars(mb_strimwidth($it['description'], 0, 240, '...')) ?></div>
        <?php endif; ?>

        <div style="margin-top:10px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
          <div class="actions">
            <a class="btn btn-view" href="/mehanik/public/<?= htmlspecialchars($viewUrl) ?>">👁 Просмотр</a>
            <a class="btn btn-edit" href="/mehanik/public/<?= htmlspecialchars($editUrl) ?>">✏ Редактировать</a>

            <form method="post" style="display:inline" onsubmit="return confirm('Удалить объявление?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
              <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
              <button type="submit" class="btn btn-delete">🗑 Удалить</button>
            </form>

            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="toggle_recommended">
              <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
              <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
              <button type="submit" class="btn btn-super"><?= empty($it['recommended']) ? '★ Super' : '★ Super ☑' ?></button>
            </form>

            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="toggle_premium">
              <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
              <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
              <button type="submit" class="btn btn-prem"><?= empty($it['premium']) ? '✨ Premium' : '✨ Premium ☑' ?></button>
            </form>
          </div>

          <div style="color:#6b7280;font-size:.9rem">ID: <?= (int)$it['id'] ?></div>
        </div>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>
</body>
</html>
