<?php
// public/service.php — фиксы звёзд, логотипа/фото и убрано описание под названием
session_start();
require_once __DIR__ . '/../db.php';
$config = file_exists(__DIR__ . '/../config.php') ? require __DIR__ . '/../config.php' : ['base_url'=>'/mehanik'];

$id = (int)($_GET['id'] ?? 0);
$service = null;
$photos = [];
$prices = [];
$avgRating = 0.0;
$reviewsCount = 0;
$reviews = [];

$user = $_SESSION['user'] ?? null;
$userId = (int)($user['id'] ?? 0);
$isAdmin = isset($user['role']) && $user['role'] === 'admin';

/** FS/URL helpers */
$uploadsFsRoot  = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
$uploadsUrlRoot = '/mehanik/uploads';

/**
 * Возвращает корректный [URL, FS-path] для файла...
 * (функция оставлена без изменений)
 */
function find_upload_url(string $value, string $preferredSubdir = 'services', string $uploadsFsRoot = '', string $uploadsUrlRoot = ''): array {
    $fname = trim($value);
    if ($fname === '') return ['', ''];

    if (is_file($fname)) {
        $pos = mb_stripos($fname, DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR);
        if ($pos !== false) {
            $sub = substr($fname, $pos + 1);
            $url = rtrim($uploadsUrlRoot, '/') . '/' . str_replace(DIRECTORY_SEPARATOR, '/', rawurlencode(basename($sub)));
            if (mb_stripos($sub, $preferredSubdir) !== false) {
                $afterUploads = substr($sub, mb_stripos($sub, 'uploads/') + strlen('uploads/'));
                $url = rtrim($uploadsUrlRoot, '/') . '/'. str_replace('%2F','/', rawurlencode($afterUploads));
            }
            return [$url, $fname];
        }
        return ['', $fname];
    }

    $pathOnly = $fname;
    if (preg_match('#^https?://#i', $fname)) {
        $p = parse_url($fname, PHP_URL_PATH);
        if ($p !== null) $pathOnly = $p;
    }

    $pathOnly = str_replace('\\', '/', $pathOnly);
    $pathOnly = ltrim($pathOnly, '/');

    $uploadsPos = stripos($pathOnly, 'uploads/');
    $candidatesFs = [];

    if ($uploadsPos !== false) {
        $fromUploads = substr($pathOnly, $uploadsPos + strlen('uploads/'));
        $candidatesFs[] = rtrim($uploadsFsRoot, '/') . '/' . $fromUploads;
        $candidatesFs[] = rtrim($uploadsFsRoot, '/') . '/' . trim($preferredSubdir, '/') . '/' . basename($fromUploads);
    }

    $candidatesFs[] = rtrim($uploadsFsRoot, '/') . '/' . trim($preferredSubdir, '/') . '/' . basename($pathOnly);
    $candidatesFs[] = rtrim($uploadsFsRoot, '/') . '/' . basename($pathOnly);
    $candidatesFs[] = $pathOnly;

    $checked = [];
    foreach ($candidatesFs as $c) {
        $cNorm = str_replace(['//','\\\\'], ['/','/'], $c);
        if (!in_array($cNorm, $checked, true)) $checked[] = $cNorm;
    }

    foreach ($checked as $fs) {
        if (is_file($fs)) {
            $normalizedFs = str_replace('\\', '/', $fs);
            $uploadsRootNorm = str_replace('\\', '/', rtrim($uploadsFsRoot, '/'));
            if (stripos($normalizedFs, $uploadsRootNorm) !== false) {
                $rel = ltrim(substr($normalizedFs, strlen($uploadsRootNorm)), '/');
                $url = rtrim($uploadsUrlRoot, '/') . '/' . str_replace('%2F','/', rawurlencode($rel));
            } else {
                if (stripos($normalizedFs, '/'.$preferredSubdir.'/') !== false) {
                    $url = rtrim($uploadsUrlRoot, '/') . '/' . $preferredSubdir . '/' . rawurlencode(basename($normalizedFs));
                } else {
                    $url = rtrim($uploadsUrlRoot, '/') . '/' . $preferredSubdir . '/' . rawurlencode(basename($normalizedFs));
                }
            }
            return [$url, $fs];
        }
    }

    if (stripos($pathOnly, 'uploads/') !== false) {
        $after = substr($pathOnly, stripos($pathOnly, 'uploads/') + strlen('uploads/'));
        $url = rtrim($uploadsUrlRoot, '/') . '/' . str_replace('%2F','/', rawurlencode($after));
        $fallbackFs = rtrim($uploadsFsRoot, '/') . '/' . $after;
        return [$url, $fallbackFs];
    }

    $fallbackUrl = rtrim($uploadsUrlRoot, '/') . '/' . trim($preferredSubdir, '/') . '/' . rawurlencode(basename($pathOnly));
    $fallbackFs = rtrim($uploadsFsRoot, '/') . '/' . trim($preferredSubdir, '/') . '/' . basename($pathOnly);
    return [$fallbackUrl, $fallbackFs];
}

// --------- утилиты БД (наличие колонок/таблиц) ----------
function column_exists($mysqli, $table, $col) {
    $table_q = $mysqli->real_escape_string($table);
    $col_q = $mysqli->real_escape_string($col);
    $res = $mysqli->query("SHOW COLUMNS FROM `{$table_q}` LIKE '{$col_q}'");
    return ($res && $res->num_rows > 0);
}

$haveRatingsTable = ($mysqli->query("SHOW TABLES LIKE 'service_ratings'")->num_rows > 0);
if (!$haveRatingsTable) {
    @ $mysqli->query("
        CREATE TABLE IF NOT EXISTS service_ratings (
          id INT AUTO_INCREMENT PRIMARY KEY,
          service_id INT NOT NULL,
          user_id INT NOT NULL,
          rating DECIMAL(3,1) NOT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_service_user (service_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $haveRatingsTable = true;
}
$reviewsHasUpdatedAt = column_exists($mysqli, 'service_reviews', 'updated_at');
$reviewsHasUserId    = column_exists($mysqli, 'service_reviews', 'user_id');
if (!$reviewsHasUserId) {
    @ $mysqli->query("ALTER TABLE service_reviews ADD COLUMN user_id INT NULL");
    $reviewsHasUserId = true;
}

// ---------------- Handlers ----------------

// поставить/обновить оценку
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rate' && $id > 0) {
    if ($userId > 0 && $haveRatingsTable) {
        $rating = isset($_POST['rating']) ? (float)$_POST['rating'] : null;
        if ($rating !== null) {
            $rating = max(0.1, min(5.0, round($rating,1)));
            if ($st = $mysqli->prepare("INSERT INTO service_ratings (service_id, user_id, rating, created_at, updated_at)
                                        VALUES (?, ?, ?, NOW(), NOW())
                                        ON DUPLICATE KEY UPDATE rating=VALUES(rating), updated_at=NOW()")) {
                $st->bind_param("iid", $id, $userId, $rating);
                $st->execute();
                $st->close();
            }
        }
    }
    header("Location: service.php?id={$id}#reviews"); exit;
}

// добавить/обновить отзыв (текст)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upsert_review' && $id > 0) {
    $comment  = trim($_POST['comment'] ?? '');
    $userName = trim($_POST['user_name'] ?? '');
    if ($userId > 0 && !empty($user['name'])) $userName = $user['name'];

    if ($comment !== '' && $userName !== '') {
        if ($userId > 0 && $reviewsHasUserId) {
            $rid = 0;
            if ($st = $mysqli->prepare("SELECT id FROM service_reviews WHERE service_id = ? AND user_id = ? LIMIT 1")) {
                $st->bind_param("ii", $id, $userId);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                if ($row) $rid = (int)$row['id'];
            }
            if ($rid > 0) {
                $sql = $reviewsHasUpdatedAt
                    ? "UPDATE service_reviews SET comment=?, user_name=?, updated_at=NOW() WHERE id=? AND service_id=? LIMIT 1"
                    : "UPDATE service_reviews SET comment=?, user_name=? WHERE id=? AND service_id=? LIMIT 1";
                if ($u = $mysqli->prepare($sql)) {
                    $u->bind_param("ssii", $comment, $userName, $rid, $id);
                    $u->execute();
                    $u->close();
                }
            } else {
                if ($ins = $mysqli->prepare("INSERT INTO service_reviews (service_id, user_id, user_name, comment, created_at) VALUES (?,?,?,?,NOW())")) {
                    $ins->bind_param("iiss", $id, $userId, $userName, $comment);
                    $ins->execute();
                    $ins->close();
                }
            }
        } else {
            if ($ins = $mysqli->prepare("INSERT INTO service_reviews (service_id, user_name, comment, created_at) VALUES (?,?,?,NOW())")) {
                $ins->bind_param("iss", $id, $userName, $comment);
                $ins->execute();
                $ins->close();
            }
        }
    }
    header("Location: service.php?id={$id}#reviews"); exit;
}

// удалить отзыв
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_review' && $id > 0) {
    $rid = (int)($_POST['review_id'] ?? 0);
    if ($rid > 0) {
        $canDelete = $isAdmin;
        if (!$canDelete) {
            if ($st = $mysqli->prepare("SELECT user_id, user_name FROM service_reviews WHERE id=? AND service_id=? LIMIT 1")) {
                $st->bind_param("ii", $rid, $id);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                if ($row) {
                    $rvUserId = (int)($row['user_id'] ?? 0);
                    $rvName   = $row['user_name'] ?? '';
                    if ($rvUserId>0 && $userId>0 && $rvUserId===$userId) $canDelete = true;
                    elseif ($rvUserId===0 && $userId>0 && !empty($user['name']) && $rvName===$user['name']) $canDelete = true;
                }
            }
        }
        if ($canDelete) {
            if ($del = $mysqli->prepare("DELETE FROM service_reviews WHERE id=? AND service_id=? LIMIT 1")) {
                $del->bind_param("ii", $rid, $id);
                $del->execute();
                $del->close();
            }
        }
    }
    header("Location: service.php?id={$id}#reviews"); exit;
}

// ---------------- Fetch service and related data ----------------
if ($id > 0) {
    if ($st = $mysqli->prepare("SELECT id, user_id, name, description, logo, contact_name, phone, email, address, latitude, longitude
                                FROM services WHERE id=? AND (status='approved' OR status='active')")) {
        $st->bind_param("i", $id);
        $st->execute();
        $service = $st->get_result()->fetch_assoc();
        $st->close();
    }

    if ($service) {
        if ($st = $mysqli->prepare("SELECT id, photo FROM service_photos WHERE service_id=? ORDER BY id ASC")) {
            $st->bind_param("i", $id);
            $st->execute();
            $photos = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();
        }
        if ($st = $mysqli->prepare("SELECT id, name, price FROM service_prices WHERE service_id=? ORDER BY id ASC")) {
            $st->bind_param("i", $id);
            $st->execute();
            $prices = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();
        }
        if ($haveRatingsTable) {
            if ($st = $mysqli->prepare("SELECT AVG(rating) AS avg_rating FROM service_ratings WHERE service_id=?")) {
                $st->bind_param("i", $id);
                $st->execute();
                $r = $st->get_result()->fetch_assoc();
                if ($r) $avgRating = $r['avg_rating'] !== null ? round((float)$r['avg_rating'], 1) : 0.0;
                $st->close();
            }
        }
        $cols = "id, service_id, user_id, user_name, rating, comment, created_at";
        if ($reviewsHasUpdatedAt) $cols .= ", updated_at";
        if ($st = $mysqli->prepare("SELECT $cols FROM service_reviews WHERE service_id=? ORDER BY created_at DESC")) {
            $st->bind_param("i", $id);
            $st->execute();
            $reviews = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();
        }
        $reviewsCount = count($reviews);
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title><?= $service ? htmlspecialchars($service['name']) . ' — Mehanik' : 'Сервис — Mehanik' ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/mehanik/assets/css/header.css">
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css"/>
  <style>
    :root{ --accent:#0b57a4; --muted:#6b7280; --card:#fff; --radius:12px; }
    body{ background:#f6f8fb; color:#222; }
    .container{ max-width:1100px; margin:20px auto; padding:16px; }
    .svc-grid{ display:grid; grid-template-columns:320px 1fr; gap:20px; align-items:start; }
    .card{ background:var(--card); border-radius:var(--radius); padding:16px; box-shadow:0 8px 30px rgba(12,20,30,.04); border:1px solid #eef3f8; }

    .logo{ width:100%; height:180px; object-fit:cover; border-radius:10px; border:1px solid #e6eef7; background:#fff; }
    h1.title{ margin:12px 0 0; font-size:1.35rem; color:var(--accent); }

    .contact-list{ margin-top:12px; display:flex; flex-direction:column; gap:8px; font-size:.95rem; }
    .prices{ margin-top:12px; border-top:1px dashed #eef3f8; padding-top:10px; }
    .price-row{ display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px dashed #f5f8fb; }

    .map-card{ height:260px; border-radius:10px; overflow:hidden; border:1px solid #e6eef7; }

    .photos-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px; margin-top:12px; }
    .thumb{ width:100%; height:110px; overflow:hidden; border-radius:8px; border:1px solid #eee; background:#fff; display:flex; align-items:center; justify-content:center; cursor:pointer; }
    .thumb img{ width:100%; height:100%; object-fit:cover; display:block; }

    /* Рейтинг: ровные звёзды */
    .stars{ position:relative; display:inline-block; font-size:20px; line-height:1; letter-spacing:2px; }
    .stars::before{ content:'★★★★★'; color:#e5e7eb; }
    .stars::after{ content:'★★★★★'; color:#fbbf24; position:absolute; left:0; top:0; white-space:nowrap; overflow:hidden; width:var(--percent,0%); }
    .avg-num{ font-size:1.6rem; font-weight:800; color:var(--accent); }
    .avg-meta{ color:var(--muted); font-size:.95rem; }

    .review-card{ background:#fff; border-radius:10px; padding:12px; border:1px solid #eef3f8; margin-bottom:10px; }
    .review-meta{ display:flex; align-items:center; gap:8px; }
    .review-name{ font-weight:700; }
    .review-time{ color:var(--muted); font-size:.88rem; margin-left:6px; }
    .review-comment{ margin-top:8px; color:#333; white-space:pre-wrap; }
    .btn{ background:var(--accent); color:#fff; padding:10px 14px; border-radius:10px; border:0; cursor:pointer; font-weight:700; }
    .btn-ghost{ background:transparent; color:var(--accent); border:1px solid #dbeeff; padding:10px 14px; border-radius:10px; font-weight:700; }
    .btn-small{ padding:6px 8px; border-radius:8px; background:#fff; border:1px solid #eef3f8; cursor:pointer; }

    .inline-editor{ margin-top:8px; display:flex; flex-direction:column; gap:8px; }
    .inline-editor textarea{ width:100%; min-height:80px; padding:8px; border-radius:8px; border:1px solid #e6eef7; box-sizing:border-box; }

    .lb-overlay{ position:fixed; inset:0; background:rgba(0,0,0,.8); display:none; align-items:center; justify-content:center; z-index:1200; padding:20px; }
    .lb-overlay.active{ display:flex; }
    .lb-img{ max-width:calc(100% - 40px); max-height:calc(100% - 40px); box-shadow:0 10px 40px rgba(0,0,0,.6); border-radius:8px; }
    .lb-close{ position:absolute; top:18px; right:18px; background:#fff; border-radius:6px; padding:6px 8px; cursor:pointer; font-weight:700; }

    @media(max-width:1000px){ .svc-grid{ grid-template-columns:1fr; } .logo{ height:220px; } }
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<div class="container">
  <?php if (!$service): ?>
    <div class="card">Сервис не найден или ещё не одобрен.</div>
  <?php else:
      // логотип — попробуем найти корректный URL и FS
      [$logoUrl,$logoFs] = !empty($service['logo'])
        ? find_upload_url($service['logo'], 'services', $uploadsFsRoot, $uploadsUrlRoot)
        : ['', ''];
  ?>
    <div class="svc-grid">
      <!-- LEFT: логотип, название, контакты, цены -->
      <aside class="card">
        <?php if ($logoUrl): ?>
          <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Логотип" class="logo">
        <?php else: ?>
          <div class="logo" style="display:flex;align-items:center;justify-content:center;color:#999;font-weight:700;">Нет логотипа</div>
        <?php endif; ?>

        <h1 class="title"><?= htmlspecialchars($service['name']) ?></h1>

        <div class="contact-list">
          <?php if (!empty($service['contact_name'])): ?><div><strong>Контакт:</strong> <?= htmlspecialchars($service['contact_name']) ?></div><?php endif; ?>
          <?php if (!empty($service['phone'])): ?><div><strong>Телефон:</strong> <a href="tel:<?= rawurlencode($service['phone']) ?>"><?= htmlspecialchars($service['phone']) ?></a></div><?php endif; ?>
          <?php if (!empty($service['email'])): ?><div><strong>Email:</strong> <a href="mailto:<?= htmlspecialchars($service['email']) ?>"><?= htmlspecialchars($service['email']) ?></a></div><?php endif; ?>
          <?php if (!empty($service['address'])): ?><div><strong>Адрес:</strong> <?= htmlspecialchars($service['address']) ?></div><?php endif; ?>
        </div>

        <?php if (!empty($prices)): ?>
          <div class="prices card" style="margin-top:12px; padding:12px;">
            <div style="font-weight:800; margin-bottom:8px;">Цены на услуги</div>
            <?php foreach ($prices as $p): ?>
              <div class="price-row">
                <div><?= htmlspecialchars($p['name']) ?></div>
                <div style="font-weight:700; color:var(--accent);">
                  <?= is_numeric($p['price']) ? number_format($p['price'], 2, '.', ' ') : htmlspecialchars($p['price']) ?> тмт
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </aside>

      <!-- RIGHT: описание, карта, фото, отзывы/рейтинг -->
      <main>
        <div class="card">
          <h2 style="margin:0 0 8px 0;">Описание</h2>
          <p style="margin:0; color:#333;"><?= nl2br(htmlspecialchars($service['description'])) ?></p>

          <div style="margin-top:14px;">
            <h3 style="margin:0 0 8px 0;">Местоположение</h3>
            <div id="map" class="map-card"></div>
          </div>

          <?php if (!empty($photos)): ?>
            <div style="margin-top:14px;">
              <h3 style="margin:0 0 8px 0;">Фотографии</h3>
              <div class="photos-grid">
                <?php foreach ($photos as $p):
                    $val = $p['photo'] ?? '';
                    [$url,$fs] = $val ? find_upload_url($val, 'services', $uploadsFsRoot, $uploadsUrlRoot) : ['',''];
                    if ($url): ?>
                      <div class="thumb" role="button" tabindex="0" onclick="openLightbox('<?= htmlspecialchars($url) ?>')">
                        <img src="<?= htmlspecialchars($url) ?>" alt="Фото">
                      </div>
                    <?php endif;
                endforeach; ?>
              </div>
            </div>

            <div id="lb" class="lb-overlay" onclick="closeLightbox(event)">
              <button class="lb-close" onclick="closeLightbox(event)">×</button>
              <img id="lbImg" class="lb-img" src="" alt="Фото">
            </div>
          <?php endif; ?>
        </div>

        <!-- Рейтинг и отзывы -->
        <section class="card" id="reviews" style="margin-top:18px;">
          <div style="display:flex; gap:16px; align-items:center; justify-content:space-between;">
            <div style="display:flex; align-items:center; gap:12px;">
              <div class="avg-num"><?= number_format($avgRating,1) ?></div>
              <div>
                <div style="font-weight:800; color:#222;">Средний рейтинг</div>
                <div class="avg-meta"><?= $reviewsCount ?> отзыв<?= ($reviewsCount%10==1 && $reviewsCount%100!=11)?'':'ов' ?></div>
              </div>
            </div>

            <div style="min-width:240px; text-align:right;">
              <?php $percent = max(0,min(100, ($avgRating/5)*100 )); ?>
              <span class="stars" style="--percent:<?= $percent ?>%;" aria-hidden="true" title="Рейтинг: <?= number_format($avgRating,1) ?>"></span>
              <?php if ($userId>0): ?>
                <form method="post" style="margin-top:8px; display:flex; justify-content:flex-end; gap:8px; align-items:center;">
                  <input type="hidden" name="action" value="rate">
                  <div style="display:flex; gap:8px; align-items:center;">
                    <input id="rateInput" type="range" name="rating" min="0.1" max="5.0" step="0.1" value="<?= htmlspecialchars($avgRating ?: 5.0) ?>" oninput="document.getElementById('rateVal').textContent=this.value">
                    <div id="rateVal" style="min-width:40px; font-weight:700;"><?= number_format($avgRating ?: 5.0,1) ?></div>
                  </div>
                  <button class="btn" type="submit">Поставить оценку</button>
                </form>
                <div style="font-size:.88rem;color:var(--muted); margin-top:6px; text-align:right;">Оценку можно изменить в любой момент</div>
              <?php else: ?>
                <div style="margin-top:8px;"><a class="btn-ghost" href="login.php" style="text-decoration:none;">Войдите, чтобы оценить</a></div>
              <?php endif; ?>
            </div>
          </div>

          <h3 style="margin:14px 0 8px 0;">Отзывы</h3>

          <?php if (empty($reviews)): ?>
            <div class="review-card">Пока нет отзывов — будьте первым!</div>
          <?php else: foreach ($reviews as $r):
              $rId = (int)$r['id'];
              $rUserId = (int)($r['user_id'] ?? 0);
              $rUserName = $r['user_name'] ?? 'Гость';
              $rRating = (isset($r['rating']) && is_numeric($r['rating']) && $r['rating']>0) ? round((float)$r['rating'],1) : null;
              $isOwner = $isAdmin || ($userId>0 && (($rUserId>0 && $userId===$rUserId) || ($rUserId===0 && !empty($user['name']) && $user['name']===$rUserName)));
              $rPercent = $rRating!==null ? max(0,min(100, ($rRating/5)*100 )) : 0;
          ?>
            <div class="review-card" id="review-<?= $rId ?>">
              <div class="review-meta">
                <div>
                  <span class="review-name"><?= htmlspecialchars($rUserName) ?></span>
                  <span class="review-time"><?= htmlspecialchars(date('d.m.Y H:i', strtotime($r['created_at']))) ?></span>
                </div>

                <?php if ($rRating !== null): ?>
                  <span class="stars" style="--percent:<?= $rPercent ?>%; font-size:16px; margin-left:12px;" aria-hidden="true"></span>
                  <div style="font-weight:700; margin-left:8px;"><?= number_format($rRating,1) ?></div>
                <?php endif; ?>

                <div style="margin-left:auto; display:flex; gap:8px;">
                  <?php if ($userId>0): ?>
                    <!-- Ответить доступен всем залогиненным -->
                    <button class="btn-small" onclick="startReply(<?= $rId ?>)">Ответить</button>
                  <?php endif; ?>

                  <?php if ($isOwner): ?>
                    <!-- Изменить доступен только владельцу/админу (фронтенд/инлайн) -->
                    <button class="btn-small" onclick="startEditInline(<?= $rId ?>)">Изменить</button>
                    <form method="post" style="display:inline-block;margin:0;">
                      <input type="hidden" name="action" value="delete_review">
                      <input type="hidden" name="review_id" value="<?= $rId ?>">
                      <button type="submit" class="btn-small" onclick="return confirm('Удалить отзыв?')">Удалить</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>

              <div class="review-comment"><?= nl2br(htmlspecialchars($r['comment'])) ?></div>

              <!-- контейнер для inline-редактора (вставляем сюда textarea при редактировании) -->
              <div class="inline-editor" data-review-id="<?= $rId ?>" style="display:none;"></div>
            </div>
          <?php endforeach; endif; ?>

          <div class="review-card" style="margin-top:12px;" id="mainReviewFormCard">
            <h3 style="margin:0 0 8px 0;">Оставить отзыв</h3>
            <form id="reviewForm" method="post" action="service.php?id=<?= $id ?>#reviews">
              <?php if ($userId <= 0): ?>
                <div style="margin-bottom:8px;">
                  <label>Ваше имя</label>
                  <input id="user_name" name="user_name" class="input" type="text" placeholder="Как вас зовут?" required>
                </div>
              <?php else: ?>
                <div style="font-size:.95rem;margin-bottom:8px;">Вы: <strong><?= htmlspecialchars($user['name']) ?></strong></div>
              <?php endif; ?>

              <div>
                <label>Комментарий</label>
                <textarea id="comment" name="comment" class="input" rows="5" required placeholder="Поделитесь впечатлением..."></textarea>
              </div>

              <!-- вспомогательное поле: если захотите редактировать конкретный отзыв по id -->
              <input type="hidden" id="editing_review_id" name="editing_review_id" value="">
              <!-- reply target (frontend only for now) -->
              <input type="hidden" id="reply_to" name="reply_to" value="">

              <div style="margin-top:8px; display:flex; gap:8px; justify-content:flex-end;">
                <input type="hidden" name="action" value="upsert_review">
                <button type="submit" class="btn">Сохранить отзыв</button>
                <button type="button" class="btn-ghost" onclick="resetReviewForm()">Очистить</button>
              </div>
            </form>
          </div>
        </section>
      </main>
    </div>
  <?php endif; ?>
</div>

<footer style="padding:20px;text-align:center;color:#777;font-size:.9rem;">
  &copy; <?= date('Y') ?> Mehanik
</footer>

<!-- Leaflet + helpers -->
<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<script>
<?php if ($service && !empty($service['latitude']) && !empty($service['longitude'])): ?>
  const map = L.map('map').setView([<?= $service['latitude'] ?>, <?= $service['longitude'] ?>], 15);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
  L.marker([<?= $service['latitude'] ?>, <?= $service['longitude'] ?>]).addTo(map).bindPopup("<?= addslashes(htmlspecialchars($service['name'])) ?>").openPopup();
<?php else: ?>
  const map = L.map('map').setView([37.95, 58.38], 13);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
<?php endif; ?> 

// Lightbox
function openLightbox(src){ const lb=document.getElementById('lb'); const img=document.getElementById('lbImg'); img.src=src; lb.classList.add('active'); }
function closeLightbox(e){ if (!e || e.target.id==='lb' || (e.target.classList && e.target.classList.contains('lb-close'))) { const lb=document.getElementById('lb'); lb.classList.remove('active'); document.getElementById('lbImg').src=''; } }

// Inline edit: открывает textarea в карточке отзыва, можно сохранить или отменить.
// Сохранение выполняется стандартным POST с action=upsert_review и редиректом на страницу.
// (Сервер по текущей реализации обновляет отзывы залогиненного пользователя.)
function startEditInline(id){
  try {
    const card = document.getElementById('review-' + id);
    if (!card) return;
    const container = card.querySelector('.inline-editor');
    if (!container) return;

    // если редактор уже открыт — ничего не делаем
    if (container.dataset.mode === 'edit') return;

    // достаём текущие значения
    const commentNode = card.querySelector('.review-comment');
    const nameNode = card.querySelector('.review-name');
    const currentComment = commentNode ? commentNode.innerText.trim() : '';
    const currentName = nameNode ? nameNode.innerText.trim() : '';

    // очистим контейнер и покажем
    container.style.display = 'block';
    container.dataset.mode = 'edit';
    container.innerHTML = '';

    // textarea
    const ta = document.createElement('textarea');
    ta.className = 'input';
    ta.rows = 5;
    ta.value = currentComment;
    container.appendChild(ta);

    // кнопки
    const btns = document.createElement('div');
    btns.style.display = 'flex';
    btns.style.justifyContent = 'flex-end';
    btns.style.gap = '8px';
    btns.style.marginTop = '6px';

    const saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'btn';
    saveBtn.textContent = 'Сохранить';
    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'btn-ghost';
    cancelBtn.textContent = 'Отмена';

    btns.appendChild(cancelBtn);
    btns.appendChild(saveBtn);
    container.appendChild(btns);

    // focus
    ta.focus();

    // Save handler: создаём временную форму и отправляем
    saveBtn.addEventListener('click', function(){
      const commentVal = ta.value.trim();
      if (commentVal === '') { alert('Комментарий не может быть пустым'); ta.focus(); return; }

      // build form
      const f = document.createElement('form');
      f.method = 'post';
      f.action = window.location.pathname + window.location.search + '#reviews';
      // action
      const a = document.createElement('input'); a.type='hidden'; a.name='action'; a.value='upsert_review'; f.appendChild(a);
      // comment
      const c = document.createElement('input'); c.type='hidden'; c.name='comment'; c.value = commentVal; f.appendChild(c);
      // user_name (if exists in DOM main form or nameNode)
      let userName = '';
      const mainNameInput = document.getElementById('user_name');
      if (mainNameInput && mainNameInput.value) userName = mainNameInput.value;
      else if (currentName) userName = currentName;
      const un = document.createElement('input'); un.type='hidden'; un.name='user_name'; un.value = userName; f.appendChild(un);

      // optional: include editing_review_id (server currently ignores, but left for future)
      const eid = document.createElement('input'); eid.type='hidden'; eid.name='editing_review_id'; eid.value = id; f.appendChild(eid);

      document.body.appendChild(f);
      f.submit();
    });

    cancelBtn.addEventListener('click', function(){
      container.innerHTML = '';
      container.style.display = 'none';
      container.dataset.mode = '';
    });

  } catch (e) {
    console.error('startEditInline error', e);
  }
}

// Reply: заполняет основную форму префиксом @UserName и скроллит к форме.
function startReply(id){
  try {
    const card = document.getElementById('review-' + id);
    if (!card) return;
    const nameNode = card.querySelector('.review-name');
    const userNameText = nameNode ? nameNode.innerText.trim() : '';
    const mainForm = document.getElementById('reviewForm');
    const commentEl = document.getElementById('comment');
    const nameEl = document.getElementById('user_name');
    const replyToEl = document.getElementById('reply_to');

    if (nameEl && userNameText && !nameEl.value) {
      // если имя не заполнено — проставляем ( у залогиненных оно обычно скрыто )
      nameEl.value = userNameText;
    }

    if (commentEl) {
      const prefix = userNameText ? ('@' + userNameText + ' ') : '';
      // если уже есть префикс, не дублируем
      if (!commentEl.value.startsWith(prefix)) commentEl.value = prefix + commentEl.value;
    }

    if (replyToEl) replyToEl.value = id;

    // скроллим к форме и фокусируем
    if (mainForm) mainForm.scrollIntoView({behavior:'smooth', block:'center'});
    if (commentEl) commentEl.focus();
  } catch (e) {
    console.error('startReply error', e);
  }
}

function resetReviewForm(){
  const f=document.getElementById('reviewForm');
  if (!f) return;
  f.reset();
  const editIdEl = document.getElementById('editing_review_id');
  if (editIdEl) editIdEl.value = '';
  const replyToEl = document.getElementById('reply_to');
  if (replyToEl) replyToEl.value = '';
}
</script>

<script src="/mehanik/assets/js/main.js"></script>
</body>
</html>
