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

// base URL from config (may be like '/mehanik' or '/mehanik/public' - normalize to site root)
$cfgBase = rtrim($config['base_url'] ?? '/mehanik', '/');

// remove trailing '/public' if present to get site root (this fixes URLs like /mehanik/public/uploads/... -> /mehanik/uploads/...)
$siteRoot = preg_replace('#/public$#i', '', $cfgBase);
if ($siteRoot === '') $siteRoot = '/';

// make sure it begins with slash and has no trailing slash (except single '/')
if ($siteRoot !== '/') $siteRoot = '/' . ltrim($siteRoot, '/');
$siteRoot = rtrim($siteRoot, '/');

// Debug flag (включите/выключите)
$DEBUG_IMG = $config['debug_images'] ?? true;

// helper to normalize/resolve image path -> absolute URL path (starting with '/') or full absolute URL
function resolve_image_path($p, $siteRoot) {
    $p = trim((string)$p);
    if ($p === '') return null;

    // already absolute URL
    if (preg_match('~^https?://~i', $p)) return $p;

    // ensure siteRoot normalized: starts with slash, no trailing slash (except '/')
    if ($siteRoot !== '/') $siteRoot = '/' . ltrim($siteRoot, '/');
    $siteRoot = rtrim($siteRoot, '/');

    // if path already starts with slash -> treat as site-root path
    if (strpos($p, '/') === 0) {
        return preg_replace('#/+#','/',$p);
    }

    // if path starts with 'mehanik/uploads/...' or '<project>/uploads/...' -> remove possible project prefix and prefix with siteRoot
    if (preg_match('~^[^/]+/uploads/~i', $p)) {
        // remove leading folder (like mehanik/)
        $after = preg_replace('~^[^/]+/~', '', $p);
        return preg_replace('#/+#','/',$siteRoot . '/' . $after);
    }

    // if path starts with 'uploads/' -> prefix with siteRoot
    if (stripos($p, 'uploads/') === 0) {
        return preg_replace('#/+#','/',$siteRoot . '/' . $p);
    }

    // if uploads appears later inside string, take tail
    $marker = 'uploads/';
    $pos = stripos($p, $marker);
    if ($pos !== false) {
        $tail = substr($p, $pos);
        return preg_replace('#/+#','/',$siteRoot . '/' . $tail);
    }

    // fallback: prefix with siteRoot
    return preg_replace('#/+#','/',$siteRoot . '/' . $p);
}

// assemble main photo
$mainPhoto = null;
if (!empty($car['photo'])) {
    $mainPhoto = resolve_image_path($car['photo'], $siteRoot);
}

// load extra photos from car_photos.file_path (robust detection)
$gallery = [];
try {
    $check = $mysqli->query("SHOW TABLES LIKE 'car_photos'");
    if ($check && $check->num_rows > 0) {
        $colRes = $mysqli->query("SHOW COLUMNS FROM car_photos");
        $cols = [];
        while ($cr = $colRes->fetch_assoc()) $cols[] = $cr['Field'];

        $useCol = null;
        $prefer = ['file_path','file','filepath','filename','path','photo','url'];
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

        if ($useCol) {
            $st = $mysqli->prepare("SELECT {$useCol} AS path FROM car_photos WHERE car_id = ? ORDER BY id ASC");
            if ($st) {
                $st->bind_param('i', $id);
                $st->execute();
                $rr = $st->get_result();
                while ($row = $rr->fetch_assoc()) {
                    $p = trim((string)($row['path'] ?? ''));
                    if ($p === '') continue;

                    $resolved = resolve_image_path($p, $siteRoot);

                    // server-side existence checks & fallbacks
                    if (!preg_match('~^https?://~i', $resolved)) {
                        $urlPath = parse_url($resolved, PHP_URL_PATH) ?: $resolved;
                        if (strpos($urlPath, '/') !== 0) $urlPath = '/' . $urlPath;

                        // Candidate 1: project root + urlPath
                        $candidateFs1 = realpath(__DIR__ . '/..' . $urlPath);
                        // Candidate 2: project root + original DB value
                        $candidateFs2 = realpath(__DIR__ . '/../' . ltrim($p, '/'));

                        if (!empty($GLOBALS['DEBUG_IMG'])) {
                            error_log("car.php: resolve debug for car_id={$id} p='{$p}' resolved='{$resolved}' urlPath='{$urlPath}' candidateFs1=" . var_export($candidateFs1, true) . " candidateFs2=" . var_export($candidateFs2, true));
                        }

                        if ($candidateFs1 && file_exists($candidateFs1)) {
                            // use URL path (like '/mehanik/uploads/...')
                            $resolved = $urlPath;
                        } elseif ($candidateFs2 && file_exists($candidateFs2)) {
                            // build URL using tail inside project root
                            $projectRoot = realpath(__DIR__ . '/..');
                            if ($projectRoot !== false && strpos($candidateFs2, $projectRoot) === 0) {
                                $tail = substr($candidateFs2, strlen($projectRoot));
                                $resolved = preg_replace('#/+#','/',$siteRoot . '/' . ltrim($tail, '/'));
                            } else {
                                $posUploads = stripos($p, 'uploads/');
                                if ($posUploads !== false) {
                                    $tail = substr($p, $posUploads);
                                    $resolved = preg_replace('#/+#','/',$siteRoot . '/' . ltrim($tail, '/'));
                                } else {
                                    if (!empty($GLOBALS['DEBUG_IMG'])) {
                                        error_log("car.php: candidateFs2 found but not under project root for car_id={$id}");
                                    }
                                }
                            }
                        } else {
                            if (!empty($GLOBALS['DEBUG_IMG'])) {
                                error_log("car.php: image not found on disk for car_id={$id}, tried: {$candidateFs1}, {$candidateFs2}, resolved='{$resolved}'");
                            }
                            // keep resolved as-is; browser may still attempt to load it
                        }
                    }

                    // normalize repeated slashes
                    $resolved = preg_replace('#/+#','/',$resolved);
                    $gallery[] = $resolved;
                }
                $st->close();
            }
        }
    }
} catch (Throwable $e) {
    error_log("car.php: gallery read failed: " . $e->getMessage());
}

// ensure main photo is first and unique
if ($mainPhoto) {
    $mainPhoto = preg_replace('#/+#','/',$mainPhoto);
    array_unshift($gallery, $mainPhoto);
}
if (!empty($gallery)) {
    $seen = [];
    $uniq = [];
    foreach ($gallery as $g) {
        if ($g === null) continue;
        if (!isset($seen[$g])) { $seen[$g] = true; $uniq[] = $g; }
    }
    $gallery = $uniq;
    if (empty($mainPhoto)) {
        $mainPhoto = $gallery[0] ?? null;
    }
} else {
    $gallery = [];
    if (!$mainPhoto) $mainPhoto = null;
}

// helper to format value
function esc($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$rejectReason = $car['reject_reason'] ?? '';
$car_sku = trim((string)($car['sku'] ?? ''));

// fallback no-photo URL
$noPhoto = preg_replace('#/+#','/', $siteRoot . '/assets/no-photo.png');

?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title><?= esc($car['brand'] ?? $car['name'] ?? 'Автомобиль') ?> — <?= esc($config['site_name'] ?? 'Mehanik') ?></title>

<!-- стили: используем siteRoot чтобы не ссылаться на /mehanik/public/... -->
<link rel="stylesheet" href="<?= esc($siteRoot) ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= esc($siteRoot) ?>/assets/css/header.css">

<style>
/* Container */
.container { max-width:1200px; margin:22px auto; padding:18px; font-family:Inter, system-ui, Arial, sans-serif; color:#0f172a; }
.header-row { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:12px; }
.title { font-size:1.5rem; font-weight:800; margin:0; }

/* Two-column layout: gallery / info */
.content-grid { display:grid; grid-template-columns: 1fr 420px; gap:18px; align-items:start; }
@media (max-width:1000px){ .content-grid { grid-template-columns: 1fr; } }

/* gallery */
.gallery { background:#fff; border-radius:12px; padding:12px; box-shadow:0 8px 24px rgba(2,6,23,0.06); }
.main-photo { display:flex; align-items:center; justify-content:center; background:#f7f8fa; border-radius:8px; min-height:360px; max-height:720px; overflow:hidden; }
.main-photo img { max-width:100%; max-height:720px; object-fit:contain; display:block; cursor:zoom-in; border-radius:6px; }
.thumbs { display:flex; gap:8px; margin-top:12px; flex-wrap:wrap; }
.thumb { width:92px; height:68px; flex:0 0 auto; border-radius:8px; overflow:hidden; border:1px solid #eef3f7; background:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; }
.thumb img { width:100%; height:100%; object-fit:cover; display:block; }

/* info card */
.info { background:#fff; border-radius:12px; padding:16px; box-shadow:0 8px 24px rgba(2,6,23,0.06); }
.badges { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:8px; }
.badge { padding:6px 10px; border-radius:999px; background:#f2f4f7; font-weight:700; font-size:.95rem; }

/* price and meta */
.price { font-size:1.6rem; color:#0b57a4; font-weight:800; margin-bottom:6px; white-space:nowrap; }
.meta-small { color:#6b7280; font-size:.95rem; }

/* specs grid */
.specs { display:grid; grid-template-columns: repeat(2, 1fr); gap:10px; margin-top:12px; }
@media (max-width:600px){ .specs { grid-template-columns: 1fr; } }
.spec { background:#fbfdff; padding:10px; border-radius:8px; border:1px solid #eef3f7; }

/* description */
.desc { margin-top:12px; padding:12px; background:#fafbff; border-radius:8px; border:1px dashed #e7e9f3; white-space:pre-wrap; word-break:break-word; }

/* contact + actions */
.contact { margin-top:12px; }
.actions { display:flex; gap:8px; margin-top:12px; flex-wrap:wrap; }
.btn { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:8px; text-decoration:none; font-weight:700; cursor:pointer; }
.btn.primary { background:#0b57a4; color:#fff; border:0; }
.btn.ghost { background:transparent; color:#0b57a4; border:1px solid rgba(11,87,164,0.08); }
.btn.warn { background:#fff7ed; color:#a16207; border:1px solid rgba(161,98,7,0.08); }
.btn.danger { background:#fff6f6; color:#ef4444; border:1px solid rgba(239,68,68,0.06); }

/* status */
.status { margin-bottom:12px; padding:10px; border-radius:8px; font-weight:700; }
.status.approved { background:#e7f8ea; color:#116b1d; border:1px solid #bfe9c6; }
.status.pending { background:#fff6e6; color:#8a5600; border:1px solid #ffe1a6; }
.status.rejected { background:#ffeaea; color:#8f1a1a; border:1px solid #ffbcbc; }

/* SKU */
.sku { margin-top:10px; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.sku .code { font-weight:800; color:#0b57a4; word-break:break-all; }

/* lightbox */
#lightbox { position:fixed; inset:0; background:rgba(2,6,23,0.85); display:none; align-items:center; justify-content:center; z-index:9999; }
#lightbox img { max-width:92%; max-height:92%; object-fit:contain; border-radius:8px; }
.lightbox-close { position:fixed; right:18px; top:18px; background:transparent; color:#fff; border:0; font-size:22px; cursor:pointer; }

/* small helpers */
.muted { color:#6b7280; font-size:.95rem; }
</style>
</head>
<body>
<?php
// Если в header.php есть абсолютные ссылки, возможно их тоже надо править.
// Но мы всё равно включаем header, чтобы навигация была как раньше.
require_once __DIR__ . '/header.php';
?>

<div class="container">
  <div class="header-row">
    <h1 class="title"><?= esc($car['brand'] ?: $car['model'] ?: 'Автомобиль') ?> <?= esc($car['model'] ?: '') ?> <?php if ($car['year']): ?><small style="font-weight:600;color:#374151"> (<?= (int)$car['year'] ?>)</small><?php endif; ?></h1>
    <div>
      <a href="<?= esc($siteRoot) ?>/public/my-cars.php" class="btn ghost">← К списку</a>
    </div>
  </div>

  <?php if ($statusNormalized === 'approved'): ?>
    <div class="status approved">✅ Объявление подтверждено</div>
  <?php elseif ($statusNormalized === 'rejected'): ?>
    <div class="status rejected">❌ Объявление отклонено
      <?php if ($rejectReason): ?><div class="muted" style="margin-top:8px;"><strong>Причина:</strong> <?= nl2br(esc($rejectReason)) ?></div><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="status pending">⏳ На модерации</div>
  <?php endif; ?>

  <div class="content-grid">
    <div class="gallery">
      <div class="main-photo" id="mainPhotoWrap">
        <?php if ($mainPhoto): ?>
          <img id="mainPhotoImg" src="<?= esc($mainPhoto) ?>" alt="<?= esc($car['brand'].' '.$car['model']) ?>">
        <?php else: ?>
          <img id="mainPhotoImg" src="<?= esc($noPhoto) ?>" alt="Нет фото">
        <?php endif; ?>
      </div>

      <?php if (!empty($gallery)): ?>
        <div class="thumbs" id="thumbs" aria-label="Галерея">
          <?php foreach ($gallery as $idx => $g): ?>
            <div class="thumb" data-src="<?= esc($g) ?>" title="Фото <?= $idx + 1 ?>">
              <img src="<?= esc($g) ?>" alt="Фото <?= $idx + 1 ?>">
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="muted" style="margin-top:12px;">Фотографии не добавлены.</div>
      <?php endif; ?>
    </div>

    <div class="info">
      <!-- (информация о машине — без изменений) -->
      <div class="badges">
        <?php if (!empty($car['brand'])): ?><div class="badge"><?= esc($car['brand']) ?></div><?php endif; ?>
        <?php if (!empty($car['model'])): ?><div class="badge"><?= esc($car['model']) ?></div><?php endif; ?>
        <?php if (!empty($car['body'])): ?><div class="badge"><?= esc($car['body']) ?></div><?php endif; ?>
      </div>

      <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
        <div>
          <div class="price"><?= number_format((float)($car['price'] ?? 0), 2) ?> TMT</div>
          <div class="muted">Добавлено: <?= $car['created_at'] ? date('d.m.Y H:i', strtotime($car['created_at'])) : '-' ?></div>
        </div>
      </div>

      <div class="specs" role="list">
        <div class="spec"><strong>VIN</strong><div class="muted"><?= esc($car['vin'] ?: '—') ?></div></div>
        <div class="spec"><strong>Пробег</strong><div class="muted"><?= $car['mileage'] ? number_format((int)$car['mileage']) . ' км' : '—' ?></div></div>

        <div class="spec"><strong>Коробка</strong><div class="muted"><?= esc($car['transmission'] ?: '—') ?></div></div>
        <div class="spec"><strong>Топливо</strong><div class="muted"><?= esc($car['fuel'] ?: '—') ?></div></div>

        <div class="spec"><strong>Цвет</strong><div class="muted"><?= esc($car['color'] ?: '—') ?></div></div>
        <div class="spec"><strong>Объём двигателя</strong><div class="muted"><?= esc($car['engine_volume'] ?: '—') ?></div></div>

        <div class="spec"><strong>Пассажиры</strong><div class="muted"><?= (!empty($car['passengers']) ? (int)$car['passengers'] . ' мест' : '—') ?></div></div>
        <div class="spec"><strong>Цвет салона</strong><div class="muted"><?= esc($car['interior_color'] ?: '—') ?></div></div>

        <div class="spec"><strong>Обшивка</strong><div class="muted"><?= esc($car['upholstery'] ?: '—') ?></div></div>
        <div class="spec"><strong>Тип зажигания</strong><div class="muted"><?= esc($car['ignition_type'] ?: '—') ?></div></div>

        <div class="spec"><strong>Города/Велаяты</strong><div class="muted"><?= esc($car['region'] ?: '—') ?></div></div>
        <div class="spec"><strong>Этрапы/Города</strong><div class="muted"><?= esc($car['district'] ?: '—') ?></div></div>
      </div>

      <div class="sku">
        <strong>Артикул:</strong>
        <?php if ($car_sku !== ''): ?>
          <div class="code" id="skuText"><?= esc($car_sku) ?></div>
          <button id="copySkuBtn" class="btn ghost">📋 Скопировать</button>
        <?php else: ?>
          <div class="muted">—</div>
        <?php endif; ?>
      </div>

      <?php if (!empty($car['description'])): ?>
        <strong>Описание:</strong>
        <div class="desc"><?= nl2br(esc($car['description'])) ?></div>
      <?php endif; ?>

      <div class="contact">
        <h4 style="margin:12px 0 6px;">Контакты продавца</h4>
        <div><strong>Имя:</strong> <?= esc($car['owner_name'] ?? '-') ?></div>
        <div style="margin-top:6px;">
          <?php $phone = trim((string)($car['owner_phone'] ?? '')); ?>
          <?php if ($phone): ?>
            <strong>Телефон:</strong> <a href="tel:<?= esc(preg_replace('~\D+~', '', $phone)) ?>"><?= esc($phone) ?></a>
          <?php else: ?>
            <div class="muted">Контакты не указаны</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="actions">
        <!-- Кнопки редактирования/удаления скрыты по требованию -->
      </div>
    </div>
  </div>
</div>

<!-- Simple lightbox -->
<div id="lightbox" role="dialog" aria-hidden="true">
  <button class="lightbox-close" id="lightboxClose" aria-label="Закрыть">✕</button>
  <img id="lightboxImg" src="" alt="Большое фото">
</div>

<script>
(function(){
  const mainImg = document.getElementById('mainPhotoImg');
  const thumbs = document.getElementById('thumbs');

  if (thumbs && mainImg) {
    thumbs.addEventListener('click', function(e){
      const t = e.target.closest('.thumb');
      if (!t) return;
      const src = t.getAttribute('data-src');
      if (src) {
        const pre = new Image();
        pre.onload = () => { mainImg.src = src; };
        pre.onerror = () => { mainImg.src = src; };
        pre.src = src;
      }
    });
  }

  const lb = document.getElementById('lightbox');
  const lbImg = document.getElementById('lightboxImg');
  const lbClose = document.getElementById('lightboxClose');

  if (mainImg && lb && lbImg && lbClose) {
    mainImg.addEventListener('click', () => {
      lbImg.src = mainImg.src;
      lb.style.display = 'flex';
      lb.setAttribute('aria-hidden','false');
    });
    lbClose.addEventListener('click', () => {
      lb.style.display = 'none';
      lb.setAttribute('aria-hidden','true');
      lbImg.src = '';
    });
    lb.addEventListener('click', (e) => {
      if (e.target === lb) lbClose.click();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && lb.style.display === 'flex') lbClose.click();
    });
  }

  (function(){
    const copyBtn = document.getElementById('copySkuBtn');
    const skuText = document.getElementById('skuText');
    if (!copyBtn || !skuText) return;
    copyBtn.addEventListener('click', function(){
      const text = skuText.textContent.trim();
      if (!text) return;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(()=> {
          const prev = copyBtn.textContent;
          copyBtn.textContent = '✓ Скопировано';
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
          setTimeout(()=> copyBtn.textContent = '📋 Скопировать', 1200);
        } else {
          alert('Не удалось скопировать артикул');
        }
      } catch(e) {
        alert('Копирование не поддерживается в этом браузере');
      }
    }
  })();

})();
</script>

</body>
</html>
