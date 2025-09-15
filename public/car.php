<?php
// mehanik/public/car.php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
session_start();

// get id
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(404);
    echo "Автомобиль не найден.";
    exit;
}

// fetch car + owner
$sql = "SELECT c.*, u.name AS owner_name, u.phone AS owner_phone
        FROM cars c
        LEFT JOIN users u ON u.id = c.user_id
        WHERE c.id = ?";
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    http_response_code(500);
    echo "Нет соединения с БД.";
    exit;
}
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo "Ошибка подготовки запроса.";
    exit;
}
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$car = $res->fetch_assoc();
$stmt->close();

if (!$car) {
    http_response_code(404);
    echo "Автомобиль не найден.";
    exit;
}

// normalize status
$statusRaw = (string)($car['status'] ?? '');
$statusClean = mb_strtolower(trim($statusRaw), 'UTF-8');
if (preg_match('/(подтвержд|approved|active|yes|ok|одобрен)/iu', $statusClean)) {
    $statusNormalized = 'approved';
} elseif (preg_match('/(отклон|reject|declin|ban|block)/iu', $statusClean)) {
    $statusNormalized = 'rejected';
} elseif (preg_match('/(pend|moder|ожидан|на модерац|wait)/iu', $statusClean)) {
    $statusNormalized = 'pending';
} else {
    $statusNormalized = 'pending';
}

// current user and permissions
$current_user_id   = $_SESSION['user']['id'] ?? null;
$current_user_role = $_SESSION['user']['role'] ?? null;
$owner_id = (int)($car['user_id'] ?? 0);
$is_owner = $current_user_id !== null && (int)$current_user_id === $owner_id;
$is_admin = $current_user_role === 'admin' || $current_user_role === 'superadmin';

// if not approved and not owner/admin -> 404
if ($statusNormalized !== 'approved' && !$is_owner && !$is_admin) {
    http_response_code(404);
    echo "Автомобиль не найден.";
    exit;
}

// assemble photo URLs
$baseUrl = rtrim($config['base_url'] ?? '/mehanik', '/');
$mainPhoto = null;
if (!empty($car['photo'])) {
    $p = $car['photo'];
    if (preg_match('~^https?://~i', $p) || str_starts_with($p, '/')) {
        $mainPhoto = $p;
    } else {
        // saved like 'uploads/cars/....'
        $mainPhoto = $baseUrl . '/' . ltrim($p, '/');
    }
}

// load extra photos from car_photos table if exists
$gallery = [];
try {
    $check = $mysqli->query("SHOW TABLES LIKE 'car_photos'");
    if ($check && $check->num_rows > 0) {
        // try to detect column name for path
        $colRes = $mysqli->query("SHOW COLUMNS FROM car_photos");
        $cols = [];
        while ($cr = $colRes->fetch_assoc()) $cols[] = $cr['Field'];
        // prefer these names if present:
        $prefer = ['file_path','filepath','filename','path','url','file'];
        $useCol = null;
        foreach ($prefer as $cname) {
            if (in_array($cname, $cols, true)) { $useCol = $cname; break; }
        }
        if ($useCol === null) {
            // fallback: pick second column (not id/product/car_id/created_at)
            foreach ($cols as $cname) {
                if (!in_array($cname, ['id','car_id','created_at','created','updated','updated_at','user_id'], true)) {
                    $useCol = $cname;
                    break;
                }
            }
        }
        if ($useCol) {
            $st = $mysqli->prepare("SELECT {$useCol} as path FROM car_photos WHERE car_id = ? ORDER BY id ASC");
            if ($st) {
                $st->bind_param('i', $id);
                $st->execute();
                $rr = $st->get_result();
                while ($row = $rr->fetch_assoc()) {
                    $p = $row['path'];
                    if (!$p) continue;
                    if (preg_match('~^https?://~i', $p) || str_starts_with($p, '/')) $gallery[] = $p;
                    else $gallery[] = $baseUrl . '/' . ltrim($p, '/');
                }
                $st->close();
            }
        }
    }
} catch (Throwable $e) {
    // ignore gallery problems
}

// ensure main photo is first in gallery
if ($mainPhoto) {
    array_unshift($gallery, $mainPhoto);
    // remove duplicate occurrences of mainPhoto later in array
    $gallery = array_values(array_unique($gallery));
} else {
    // if no main photo and gallery present, take first gallery as main
    if (!empty($gallery)) {
        $mainPhoto = $gallery[0];
    }
}

// helper to format value
function esc($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$rejectReason = $car['reject_reason'] ?? '';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title><?= esc($car['brand'] ?? $car['name'] ?? 'Автомобиль') ?> — <?= esc($config['site_name'] ?? 'Mehanik') ?></title>
<link rel="stylesheet" href="/mehanik/assets/css/style.css">
<style>
.container { max-width:1100px; margin:22px auto; padding:18px; }
.top { display:flex; gap:20px; align-items:flex-start; }
@media (max-width:900px){ .top { flex-direction:column } }
.gallery { width:52%; min-width:320px; }
.main-photo { background:#f7f7f9; border-radius:12px; padding:10px; display:flex; align-items:center; justify-content:center; min-height:360px; }
.main-photo img { max-width:100%; max-height:620px; object-fit:contain; border-radius:8px; }
.thumbs { display:flex; gap:8px; margin-top:10px; flex-wrap:wrap; }
.thumb { width:80px; height:60px; overflow:hidden; border-radius:8px; border:1px solid #e6eef7; cursor:pointer; display:inline-block; }
.thumb img { width:100%; height:100%; object-fit:cover; display:block; }
.info { flex:1; }
.badge { display:inline-block; padding:6px 10px; border-radius:999px; background:#f2f4f7; margin-right:8px; font-weight:700; }
.status-approved { background:#e7f8ea; color:#116b1d; border:1px solid #bfe9c6; padding:10px; border-radius:8px; margin-bottom:10px; }
.status-pending { background:#fff6e6; color:#8a5600; border:1px solid #ffe1a6; padding:10px; border-radius:8px; margin-bottom:10px; }
.status-rejected { background:#ffeaea; color:#8f1a1a; border:1px solid #ffbcbc; padding:10px; border-radius:8px; margin-bottom:10px; }
.card { background:#fff; border-radius:12px; box-shadow:0 8px 20px rgba(2,6,23,0.06); padding:16px; }
.rows { display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:12px; }
.row-item { background:#fbfdff; padding:10px; border-radius:8px; border:1px solid #eef3f7; }
.price { font-size:1.4rem; font-weight:800; color:#0b57a4; }
.actions { margin-top:14px; display:flex; gap:8px; }
.btn { display:inline-block; padding:8px 12px; border-radius:8px; background:#0b57a4; color:#fff; text-decoration:none; }
.btn.ghost { background:transparent; color:#0b57a4; border:1px solid #dbeafe; }
.muted { color:#6b7280; }
.desc { margin-top:12px; padding:12px; background:#fafbff; border-radius:8px; border:1px dashed #e7e9f3; white-space:pre-wrap; }
.contact { margin-top:12px; }
.small { font-size:.95rem; color:#6b7280; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<div class="container">
  <h1 style="margin:0 0 8px;"><?= esc($car['brand'] ?: $car['model'] ?: $car['id']) ?> <?= esc($car['model'] ?: '') ?> <?= $car['year'] ? '(' . (int)$car['year'] . ')' : '' ?></h1>

  <?php if ($statusNormalized === 'approved'): ?>
    <div class="status-approved">✅ Объявление подтверждено</div>
  <?php elseif ($statusNormalized === 'rejected'): ?>
    <div class="status-rejected">❌ Объявление отклонено
      <?php if ($rejectReason): ?><div class="small" style="margin-top:6px;"><strong>Причина:</strong> <?= nl2br(esc($rejectReason)) ?></div><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="status-pending">⏳ На модерации</div>
  <?php endif; ?>

  <div class="top">
    <div class="gallery card">
      <div class="main-photo" id="mainPhotoWrap">
        <?php if ($mainPhoto): ?>
          <img id="mainPhotoImg" src="<?= esc($mainPhoto) ?>" alt="<?= esc($car['brand'].' '.$car['model']) ?>">
        <?php else: ?>
          <img id="mainPhotoImg" src="/mehanik/assets/no-photo.png" alt="Нет фото">
        <?php endif; ?>
      </div>

      <?php if (!empty($gallery)): ?>
        <div class="thumbs" id="thumbs">
          <?php foreach ($gallery as $idx => $g): ?>
            <div class="thumb" data-src="<?= esc($g) ?>">
              <img src="<?= esc($g) ?>" alt="Фото <?= $idx+1 ?>">
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="info card">
      <div style="display:flex; justify-content:space-between; align-items:flex-start;">
        <div>
          <?php if (!empty($car['brand'])): ?><span class="badge"><?= esc($car['brand']) ?></span><?php endif; ?>
          <?php if (!empty($car['model'])): ?><span class="badge"><?= esc($car['model']) ?></span><?php endif; ?>
          <?php if (!empty($car['body'])): ?><span class="badge"><?= esc($car['body']) ?></span><?php endif; ?>
        </div>
        <div style="text-align:right;">
          <div class="price"><?= number_format((float)($car['price'] ?? 0), 2) ?> TMT</div>
          <div class="small muted">Добавлено: <?= $car['created_at'] ? date('d.m.Y H:i', strtotime($car['created_at'])) : '-' ?></div>
        </div>
      </div>

      <div class="rows">
        <div class="row-item"><strong>VIN:</strong> <?= esc($car['vin'] ?? '-') ?></div>
        <div class="row-item"><strong>Пробег:</strong> <?= $car['mileage'] ? number_format((int)$car['mileage']) . ' км' : '—' ?></div>
        <div class="row-item"><strong>Коробка:</strong> <?= esc($car['transmission'] ?? '-') ?></div>
        <div class="row-item"><strong>Топливо:</strong> <?= esc($car['fuel'] ?? '-') ?></div>
      </div>

      <?php if (!empty($car['description'])): ?>
        <div class="section-title" style="margin-top:12px;font-weight:700;">Описание</div>
        <div class="desc"><?= nl2br(esc($car['description'])) ?></div>
      <?php endif; ?>

      <div class="section-title" style="margin-top:12px;font-weight:700;">Контакты продавца</div>
      <div class="contact">
        <div><strong>Имя:</strong> <?= esc($car['owner_name'] ?? '-') ?></div>
        <div style="margin-top:6px;">
          <?php $phone = trim((string)($car['owner_phone'] ?? '')); ?>
          <?php if ($phone): ?>
            <strong>Телефон:</strong> <a href="tel:<?= esc(preg_replace('~\D+~', '', $phone)) ?>"><?= esc($phone) ?></a>
          <?php else: ?>
            <span class="muted">Контакты не указаны</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="actions">
        <a class="btn ghost" href="/mehanik/public/index.php">⬅ Назад</a>
        <?php if ($is_owner || $is_admin): ?>
          <a class="btn" href="/mehanik/public/edit-car.php?id=<?= (int)$car['id'] ?>">Редактировать</a>
          <a class="btn ghost" href="/mehanik/public/delete-car.php?id=<?= (int)$car['id'] ?>" onclick="return confirm('Удалить объявление?')">Удалить</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const mainImg = document.getElementById('mainPhotoImg');
  const thumbs = document.getElementById('thumbs');
  if (!thumbs) return;
  thumbs.addEventListener('click', function(e){
    const t = e.target.closest('.thumb');
    if (!t) return;
    const src = t.getAttribute('data-src');
    if (src && mainImg) mainImg.src = src;
    // optional: scroll to main photo
    mainImg.scrollIntoView({behavior:'smooth', block:'center'});
  });
})();
</script>
</body>
</html>
