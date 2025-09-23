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

// prepare product url for linking (used for SKU link)
$productUrl = '/mehanik/public/product.php?id=' . urlencode($id);

// prepare display SKU (remove leading SKU- if present)
$rawSku = trim((string)($product['sku'] ?? ''));
$displaySku = $rawSku === '' ? '' : preg_replace('/^SKU-/i', '', $rawSku);

?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($product['name']) ?> — <?= htmlspecialchars($config['site_name'] ?? 'Mehanik') ?></title>
<link rel="stylesheet" href="/mehanik/assets/css/style.css">
<style>
/* Layout: фото сверху, контент снизу */
.product-wrap { display:grid; grid-template-columns: 1fr; gap:18px; align-items:start; }
@media (min-width: 900px){
  /* на широких экранах оставляем фото крупным сверху, контент снизу */
  .product-wrap { grid-template-columns: 1fr; }
}
.card { background:#fff; border-radius:14px; box-shadow:0 6px 18px rgba(0,0,0,.08); overflow:hidden; }
.card-body { padding:20px; }

/* Photo block */
.photo { background:#f7f7f9; display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:420px; }
.photo-inner { width:100% !important; max-width:100% !important; position:relative; }
.photo-card { width:100% !important; }
.photo-display { width:100% !important; height:520px; border-radius:12px; overflow:hidden; background:#eaeef6; display:flex; align-items:center; justify-content:center; }
.photo-display img { width:100% !important; height:100% !important; object-fit:cover !important; display:block !important; }

.thumbs { display:flex; gap:8px; margin-top:12px; flex-wrap:wrap; justify-content:flex-start; }
.thumb { width:78px; height:78px; border-radius:8px; overflow:hidden; border:1px solid #eee; cursor:pointer; background:#fafafa; display:flex; align-items:center; justify-content:center; }
.thumb img { width:100%; height:100%; object-fit:cover; display:block; }
.thumb.active { box-shadow:0 6px 18px rgba(11,87,164,0.12); border:2px solid rgba(11,87,164,0.12); }

/* Details */
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
.btn { display:inline-block; padding:8px 16px; background:#116b1d; color:#fff; border-radius:6px; text-decoration:none; margin-top:12px; border: none; cursor: pointer; }

/* SKU row styles */
.sku-row { display:flex; gap:10px; align-items:center; }
.sku-text { font-weight:700; color:#0b57a4; text-decoration:underline; }
.sku-copy { padding:6px 8px; border-radius:6px; border:1px solid #e6e9ef; background:#fff; cursor:pointer; }

/* Responsive tweaks */
@media (max-width: 600px) {
  .sku-row { flex-direction: column; align-items:flex-start; gap:6px; }
  .btn { display:block; width:100%; text-align:center; }
  .btn + .btn { margin-top:8px; }
  .photo-display { height:320px; }
}
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
  <!-- Фото (вверху) -->
  <div class="card photo-card">
    <div class="photo" id="photoWrapper">
      <div class="photo-inner">
        <div class="photo-display">
          <?php if ($hasAnyPhoto): ?>
            <img id="mainPhoto" src="<?= htmlspecialchars($galleryUrls[0]) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
          <?php else: ?>
            <img id="mainPhoto" src="/mehanik/assets/no-photo.png" alt="Нет фото">
          <?php endif; ?>
        </div>

        <?php if ($hasAnyPhoto && count($galleryUrls) > 1): ?>
          <div class="thumbs" id="thumbs">
            <?php foreach ($galleryUrls as $idx => $g): ?>
              <div class="thumb<?= $idx === 0 ? ' active' : '' ?>" data-idx="<?= $idx ?>">
                <img src="<?= htmlspecialchars($g) ?>" alt="Фото <?= $idx+1 ?>">
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <!-- Описание (под фото) -->
  <div class="card info-card">
    <div class="card-body">
      

      <div style="margin-bottom:8px;">
        <?php foreach (['brand_name','model_name','complex_part_name','component_name'] as $field): ?>
          <?php if (!empty($product[$field])): ?>
            <span class="pill"><?= htmlspecialchars($product[$field]) ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <div class="details" style="margin-top:8px;">
        <div class="row">
          <strong>Артикул:</strong>
          <?php if ($displaySku !== ''): ?>
            <div class="sku-row" style="margin-top:6px;">
              <a class="sku-text" id="skuLink" href="<?= htmlspecialchars($productUrl) ?>" title="Перейти к товару"><?= htmlspecialchars($displaySku) ?></a>
              <button type="button" id="copySkuBtn" class="sku-copy" aria-label="Копировать артикул">📋</button>
            </div>
          <?php else: ?>
            <div style="margin-top:6px;" class="muted">—</div>
          <?php endif; ?>
        </div>

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
        <!-- Кнопки Super / Premium / Редактировать убраны по запросу -->
      </div>
    </div>
  </div>
</div>

<script>
// gallery thumbnail click -> swap main image + active state
(function(){
  const thumbs = document.getElementById('thumbs');
  const main = document.getElementById('mainPhoto');
  if (!thumbs || !main) return;

  thumbs.addEventListener('click', function(e){
    let t = e.target;
    while (t && !t.classList.contains('thumb')) t = t.parentElement;
    if (!t) return;
    const idx = parseInt(t.getAttribute('data-idx'), 10);
    if (Number.isNaN(idx)) return;
    const imgs = thumbs.querySelectorAll('img');
    const src = imgs[idx] ? imgs[idx].src : null;
    if (src) {
      // плавная подмена
      main.style.opacity = 0;
      setTimeout(()=> {
        main.src = src;
        main.style.opacity = 1;
      }, 120);

      // active thumb
      thumbs.querySelectorAll('.thumb').forEach(th => th.classList.remove('active'));
      t.classList.add('active');
    }
  });
})();

// SKU copy button (uses Clipboard API with fallback)
(function(){
  const copyBtn = document.getElementById('copySkuBtn');
  const skuTextEl = document.getElementById('skuLink');
  if (!copyBtn || !skuTextEl) return;
  copyBtn.addEventListener('click', function(){
    const text = skuTextEl.textContent.trim();
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(()=> {
        const prev = copyBtn.textContent;
        copyBtn.textContent = '✓';
        setTimeout(()=> copyBtn.textContent = prev, 1200);
      }).catch(()=> fallbackCopy(text));
    } else {
      fallbackCopy(text);
    }
  });

  function fallbackCopy(text) {
    try {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'absolute';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      const ok = document.execCommand('copy');
      document.body.removeChild(ta);
      if (ok) {
        copyBtn.textContent = '✓';
        setTimeout(()=> copyBtn.textContent = '📋', 1200);
      } else {
        alert('Не удалось скопировать артикул');
      }
    } catch(e) {
      alert('Копирование не поддерживается в этом браузере');
    }
  }
})();

</script>
</body>
</html>
