<?php
require_once __DIR__.'/../db.php';
require_once __DIR__.'/../config.php';
session_start();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(404);
    echo "Товар не найден.";
    exit;
}

// Получаем товар и связки
$sql = "
  SELECT
    p.*,
    u.name  AS owner_name,
    u.phone AS owner_phone,
    b.name  AS brand_name,
    m.name  AS model_name,
    cp.name AS complex_part_name,
    c.name  AS component_name
  FROM products p
  LEFT JOIN users         u  ON u.id  = p.user_id
  LEFT JOIN brands        b  ON b.id  = p.brand_id
  LEFT JOIN models        m  ON m.id  = p.model_id
  LEFT JOIN complex_parts cp ON cp.id  = p.complex_part_id
  LEFT JOIN components    c  ON c.id  = p.component_id
  WHERE p.id = ?
";
if (isset($mysqli) && $mysqli instanceof mysqli) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $product = $res->fetch_assoc();
    $stmt->close();
} elseif (isset($pdo) && $pdo instanceof PDO) {
    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
} else {
    http_response_code(500);
    echo "DB connection error.";
    exit;
}

if (!$product) {
    http_response_code(404);
    echo "Товар не найден.";
    exit;
}

/* === нормализация статуса === */
$statusRaw   = (string)($product['status'] ?? '');
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

// Текущий пользователь
$current_user_id   = $_SESSION['user']['id'] ?? null;
$current_user_role = $_SESSION['user']['role'] ?? null;

$owner_id = (int)($product['user_id'] ?? 0);
$is_owner = $current_user_id !== null && (int)$current_user_id === $owner_id;
$is_admin = $current_user_role === 'admin';

// Если товар не approved — доступ только владельцу или админу
if ($statusNormalized !== 'approved' && !$is_owner && !$is_admin) {
    http_response_code(404);
    echo "Товар не найден.";
    exit;
}

// Главная фотография (product.photo) — вычисляем URL
$photoRaw = $product['photo'] ?? '';
$baseUrl = rtrim($config['base_url'] ?? '', '/');

function buildPublicPath($raw, $baseUrl) {
    $raw = (string)$raw;
    if ($raw === '') return null;
    if (preg_match('~^https?://~i', $raw) || strpos($raw, '/') === 0) {
        return $raw;
    }
    return ($baseUrl !== '' ? $baseUrl : '') . '/uploads/products/' . ltrim($raw, '/');
}

$photoUrl = buildPublicPath($photoRaw, $baseUrl);

// Logo
$logoRaw = $product['logo'] ?? '';
$logoUrl = buildPublicPath($logoRaw, $baseUrl);

// Подгружаем дополнительные фото из product_photos (если таблица есть)
$galleryUrls = [];
if (isset($mysqli) && $mysqli instanceof mysqli) {
    // check table exists
    $res = $mysqli->query("SHOW TABLES LIKE 'product_photos'");
    if ($res && $res->num_rows > 0) {
        $stmt2 = $mysqli->prepare("SELECT file_path FROM product_photos WHERE product_id = ? ORDER BY id ASC");
        if ($stmt2) {
            $stmt2->bind_param('i', $id);
            $stmt2->execute();
            $r2 = $stmt2->get_result();
            while ($row = $r2->fetch_assoc()) {
                $fp = $row['file_path'] ?? '';
                if ($fp === '') continue;
                $galleryUrls[] = buildPublicPath($fp, $baseUrl);
            }
            $stmt2->close();
        }
    }
} elseif (isset($pdo) && $pdo instanceof PDO) {
    try {
        $st = $pdo->query("SHOW TABLES LIKE 'product_photos'");
        $exists = (bool)$st->fetchColumn();
        if ($exists) {
            $st2 = $pdo->prepare("SELECT file_path FROM product_photos WHERE product_id = :pid ORDER BY id ASC");
            $st2->execute([':pid' => $id]);
            $rows = $st2->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $fp = $row['file_path'] ?? '';
                if ($fp === '') continue;
                $galleryUrls[] = buildPublicPath($fp, $baseUrl);
            }
        }
    } catch (Throwable $e) {
        // ignore, gallery remains empty
    }
}

// Убедимся, что главный фото URL представлен в галерее первым (если есть)
if ($photoUrl) {
    // если photoUrl не в массиве, добавим в начало
    if (!in_array($photoUrl, $galleryUrls, true)) {
        array_unshift($galleryUrls, $photoUrl);
    } else {
        // если есть в массиве — переместим его в начало
        $idx = array_search($photoUrl, $galleryUrls, true);
        if ($idx !== false && $idx !== 0) {
            array_splice($galleryUrls, $idx, 1);
            array_unshift($galleryUrls, $photoUrl);
        }
    }
}

// Если нет ни одного фото — покажем placeholder
$hasAnyPhoto = !empty($galleryUrls);

// reject reason
$rejectReason = $product['reject_reason'] ?? '';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($product['name']) ?> — <?= htmlspecialchars($config['site_name'] ?? 'Mehanik') ?></title>
<link rel="stylesheet" href="/mehanik/assets/css/style.css">
<style>
.product-wrap { display:grid; grid-template-columns: 1fr 1.2fr; gap:24px; align-items:start; }
@media (max-width: 900px){ .product-wrap { grid-template-columns: 1fr; } }
.card { background:#fff; border-radius:14px; box-shadow:0 6px 18px rgba(0,0,0,.08); overflow:hidden; }
.card-body { padding:20px; }
.photo { background:#f7f7f9; display:flex; align-items:center; justify-content:center; min-height:320px; }
.photo img { max-width:100%; max-height:520px; object-fit:contain; }
.thumbs { display:flex; gap:8px; margin-top:10px; flex-wrap:wrap; }
.thumb { width:78px; height:78px; border-radius:8px; overflow:hidden; border:1px solid #eee; cursor:pointer; background:#fafafa; display:flex; align-items:center; justify-content:center; }
.thumb img { width:100%; height:100%; object-fit:cover; display:block; }
.status-msg { padding:12px 14px; border-radius:10px; font-weight:600; margin: 0 0 14px 0; }
.status-approved { background:#e7f8ea; color:#116b1d; border:1px solid #bfe9c6; }
.status-rejected { background:#ffeaea; color:#8f1a1a; border:1px solid #ffbcbc; }
.status-pending  { background:#fff6e6; color:#8a5600; border:1px solid #ffe1a6; }
.pill { display:inline-block; padding:4px 10px; border-radius:999px; background:#f2f3f7; font-size:.9rem; margin-right:8px; }
.details { display:grid; grid-template-columns: 1fr 1fr; gap:14px; }
.details .row { background:#f8f9fb; border-radius:10px; padding:10px 12px; }
.muted { color:#6b7280; }
.price { font-weight:700; font-size:1.4rem; }
.section-title { margin:18px 0 8px; font-size:1.05rem; font-weight:700; }
.desc { background:#fafbff; border:1px dashed #e7e9f3; border-radius:12px; padding:14px; }
.logo { display:block; margin-bottom:12px; }
.btn { display:inline-block; padding:8px 16px; background:#116b1d; color:#fff; border-radius:6px; text-decoration:none; margin-top:12px; }
</style>
</head>
<body>
<?php require_once __DIR__.'/header.php'; ?>

<div class="container" style="padding:22px;">
<h1 style="margin-bottom:10px;"><?= htmlspecialchars($product['name']) ?></h1>

<!-- Статус -->
<?php if ($statusNormalized === 'approved'): ?>
<div class="status-msg status-approved">✅ Товар подтверждён администратором</div>
<?php elseif ($statusNormalized === 'rejected'): ?>
<div class="status-msg status-rejected">
❌ Товар отклонён администратором
<?php if ($rejectReason): ?>
<div class="muted" style="margin-top:6px;"><strong>Причина:</strong> <?= nl2br(htmlspecialchars($rejectReason)) ?></div>
<?php endif; ?>
</div>
<?php else: ?>
<div class="status-msg status-pending">⏳ Товар находится на модерации</div>
<?php endif; ?>

<div class="product-wrap">
  <!-- Фото -->
  <div class="card">
    <div class="photo" id="photoWrapper">
      <?php if ($hasAnyPhoto): ?>
        <img id="mainPhoto" src="<?= htmlspecialchars($galleryUrls[0]) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
      <?php else: ?>
        <img id="mainPhoto" src="/mehanik/assets/no-photo.png" alt="Нет фото">
      <?php endif; ?>
    </div>

    <?php if ($hasAnyPhoto && count($galleryUrls) > 1): ?>
      <div style="padding:12px;">
        <div class="thumbs" id="thumbs">
          <?php foreach ($galleryUrls as $idx => $g): ?>
            <div class="thumb" data-idx="<?= $idx ?>">
              <img src="<?= htmlspecialchars($g) ?>" alt="Фото <?= $idx+1 ?>">
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Описание -->
  <div class="card">
    <div class="card-body">
      <?php if ($logoUrl): ?>
        <img class="logo" src="<?= htmlspecialchars($logoUrl) ?>" alt="Логотип" style="max-width:140px; max-height:80px; object-fit:contain;">
      <?php endif; ?>

      <div style="margin-bottom:8px;">
        <?php foreach (['brand_name','model_name','complex_part_name','component_name'] as $field): ?>
          <?php if (!empty($product[$field])): ?>
            <span class="pill"><?= htmlspecialchars($product[$field]) ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <div class="details" style="margin-top:8px;">
        <div class="row"><strong>Артикул:</strong> <?= htmlspecialchars($product['sku'] ?? '') ?></div>
        <div class="row"><strong>Производитель:</strong> <?= htmlspecialchars($product['manufacturer'] ?? '-') ?></div>
        <div class="row"><strong>Состояние:</strong> <?= htmlspecialchars($product['quality'] ?? '-') ?></div>
        <div class="row"><strong>Качество:</strong> <?= number_format((float)($product['rating'] ?? 0),1) ?></div>
        <div class="row"><strong>Годы выпуска:</strong> <?= ($product['year_from'] ? htmlspecialchars($product['year_from']) : '—') ?> — <?= ($product['year_to'] ? htmlspecialchars($product['year_to']) : '—') ?></div>
        <div class="row"><strong>Наличие:</strong> <?= (int)($product['availability'] ?? 0) ?> шт.</div>
        <div class="row price"><strong>Цена:</strong> <?= number_format((float)($product['price'] ?? 0), 2) ?> TMT</div>
        <div class="row"><strong>Добавлено:</strong> <?= $product['created_at'] ? date('d.m.Y H:i', strtotime($product['created_at'])) : '-' ?></div>
      </div>

      <?php if (!empty($product['description'])): ?>
      <div class="section-title">Описание</div>
      <div class="desc"><?= nl2br(htmlspecialchars($product['description'])) ?></div>
      <?php endif; ?>

      <div class="section-title">Контакты продавца</div>
      <div class="details">
        <div class="row"><strong>Имя:</strong> <?= htmlspecialchars($product['owner_name'] ?? '-') ?></div>
        <div class="row">
          <?php $phone = trim((string)($product['owner_phone'] ?? '')); ?>
          <?php if ($phone): ?>
            <strong>Телефон:</strong> <a href="tel:<?= htmlspecialchars(preg_replace('~\D+~', '', $phone)) ?>"><?= htmlspecialchars($phone) ?></a>
          <?php else: ?>
            <span class="muted">Контакты не указаны</span>
          <?php endif; ?>
        </div>
      </div>

      <div style="margin-top:16px;">
        <a class="btn" href="/mehanik/public/index.php">⬅ Назад к каталогу</a>
      </div>
    </div>
  </div>
</div>

<script>
// gallery thumbnail click -> swap main image
(function(){
  const thumbs = document.getElementById('thumbs');
  const main = document.getElementById('mainPhoto');
  if (!thumbs || !main) return;
  thumbs.addEventListener('click', function(e){
    let t = e.target;
    // find .thumb
    while (t && !t.classList.contains('thumb')) t = t.parentElement;
    if (!t) return;
    const idx = t.getAttribute('data-idx');
    if (idx === null) return;
    const imgs = thumbs.querySelectorAll('img');
    const src = imgs[idx] ? imgs[idx].src : null;
    if (src) {
      main.src = src;
      // optionally update active thumb styles
      thumbs.querySelectorAll('.thumb').forEach(th => th.style.boxShadow = '');
      t.style.boxShadow = '0 4px 14px rgba(11,87,164,0.14)';
    }
  });
})();
</script>
</body>
</html>
