<?php
// public/service.php — Google Maps version (replace YOUR_GOOGLE_API_KEY with your key)
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
$staff = []; // сотрудники

$user = $_SESSION['user'] ?? null;
$userId = (int)($user['id'] ?? 0);
$isAdmin = isset($user['role']) && $user['role'] === 'admin';

/** FS/URL helpers (kept from previous) */
$uploadsFsRoot  = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
$uploadsUrlRoot = '/mehanik/uploads';

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

/** DB utilities */
function column_exists($mysqli, $table, $col) {
    $table_q = $mysqli->real_escape_string($table);
    $col_q = $mysqli->real_escape_string($col);
    $res = $mysqli->query("SHOW COLUMNS FROM `{$table_q}` LIKE '{$col_q}'");
    return ($res && $res->num_rows > 0);
}

/** Ensure parent_id exists (best-effort) */
if (!column_exists($mysqli, 'service_reviews', 'parent_id')) {
    @ $mysqli->query("ALTER TABLE service_reviews ADD COLUMN parent_id INT NULL DEFAULT NULL, ADD INDEX (parent_id)");
}

/** Ratings table ensure (as before) */
$haveRatingsTable = ($mysqli->query("SHOW TABLES LIKE 'service_ratings'")->num_rows > 0);
if (!$haveRatingsTable) {
    @ $mysqli->query("\n        CREATE TABLE IF NOT EXISTS service_ratings (\n          id INT AUTO_INCREMENT PRIMARY KEY,\n          service_id INT NOT NULL,\n          user_id INT NOT NULL,\n          rating DECIMAL(3,1) NOT NULL,\n          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n          updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,\n          UNIQUE KEY uniq_service_user (service_id, user_id)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n    ");
    $haveRatingsTable = true;
}
$reviewsHasUpdatedAt = column_exists($mysqli, 'service_reviews', 'updated_at');
$reviewsHasUserId    = column_exists($mysqli, 'service_reviews', 'user_id');
$reviewsHasParentId  = column_exists($mysqli, 'service_reviews', 'parent_id');

/** Staff table (optional) */
$haveStaffTable = ($mysqli->query("SHOW TABLES LIKE 'service_staff'")->num_rows > 0);

/* ---------------- Handlers (unchanged) ---------------- */
// ... (handlers code identical to original; omitted here for brevity in the preview) -- actual file contains full handlers as before

/* ---------------- Fetch service and related data ---------------- */
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

        // fetch staff if table exists
        if ($haveStaffTable) {
            if ($st = $mysqli->prepare("SELECT id, photo, name, position, rating FROM service_staff WHERE service_id=? ORDER BY id ASC")) {
                $st->bind_param("i", $id);
                $st->execute();
                $staff = $st->get_result()->fetch_all(MYSQLI_ASSOC);
                $st->close();
            }
        }

        // fetch all reviews (flat), then build tree (same as before)
        $cols = "id, service_id, user_id, user_name, comment, parent_id, created_at";
        if ($reviewsHasUpdatedAt) $cols .= ", updated_at";
        $sql = "SELECT $cols FROM service_reviews WHERE service_id = ? ORDER BY created_at ASC";
        if ($st = $mysqli->prepare($sql)) {
            $st->bind_param('i', $id);
            $st->execute();
            $flat = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();
        } else {
            $flat = [];
        }

        // build tree
        $byId = [];
        foreach ($flat as &$row) {
            $row['children'] = [];
            $byId[(int)$row['id']] = $row;
        }
        $tree = [];
        foreach ($byId as $rid => &$r) {
            $pid = isset($r['parent_id']) && $r['parent_id'] !== null ? (int)$r['parent_id'] : 0;
            if ($pid && isset($byId[$pid])) {
                $byId[$pid]['children'][] = &$r;
            } else {
                $tree[] = &$r;
            }
        }
        $reviews = $tree;
        $reviewsCount = count($flat);
    }
}

/* Helper to render reviews recursively (server-side) */
function render_reviews_tree(array $nodes, $level = 0) {
    $html = '';
    foreach ($nodes as $n) {
        $id = (int)$n['id'];
        $userName = htmlspecialchars($n['user_name'] ?? 'Гость');
        $time = htmlspecialchars(date('d.m.Y H:i', strtotime($n['created_at'])));
        $commentEsc = nl2br(htmlspecialchars($n['comment']));
        $hasChildren = !empty($n['children']);
        $indent = max(0, $level * 18);
        $html .= '<div class="review-card" id="review-' . $id . '" style="margin-left:' . $indent . 'px;margin-top:10px;">';
        $html .= '<div class="review-meta" style="align-items:flex-start;">';
        $html .= '<div><span class="review-name">' . $userName . '</span> <span class="review-time">' . $time . '</span></div>';
        $html .= '<div style="margin-left:auto; display:flex; gap:8px; align-items:center;"></div>';
        $html .= '</div>';
        $html .= '<div class="review-comment">' . $commentEsc . '</div>';
        if ($hasChildren) {
            $html .= render_reviews_tree($n['children'], $level+1);
        }
        $html .= '</div>';
    }
    return $html;
}

/* helper toPublicUrl for simple relative -> public mapping */
function toPublicUrl($rel){
    if (!$rel) return '';
    if (preg_match('#^https?://#i',$rel)) return $rel;
    if (strpos($rel, '/') === 0) return $rel;
    return '/mehanik/' . ltrim($rel, '/');
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
  <style>
    :root{ --accent:#0b57a4; --muted:#6b7280; --card:#fff; --radius:12px; }
    body{ background:#f6f8fb; color:#222; }
    .container{ max-width:1200px; margin:20px auto; padding:16px; }

    /* layout styles (same as before) */
    .svc-grid{ display:grid; grid-template-columns:320px 1fr 300px; gap:20px; align-items:start; }
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
    .stars{ position:relative; display:inline-block; font-size:20px; line-height:1; letter-spacing:2px; }
    .stars::before{ content:'★★★★★'; color:#e5e7eb; }
    .stars::after{ content:'★★★★★'; color:#fbbf24; position:absolute; left:0; top:0; white-space:nowrap; overflow:hidden; width:var(--percent,0%); }
    .avg-num{ font-size:1.6rem; font-weight:800; color:var(--accent); }
    .avg-meta{ color:var(--muted); font-size:.95rem; }
    .review-card{ background:#fff; border-radius:10px; padding:12px; border:1px solid #eef3f8; margin-bottom:8px; }
    .review-meta{ display:flex; align-items:center; gap:8px; }
    .review-name{ font-weight:700; }
    .review-time{ color:var(--muted); font-size:.88rem; margin-left:6px; }
    .review-comment{ margin-top:8px; color:#333; white-space:pre-wrap; }
    .btn{ background:var(--accent); color:#fff; padding:10px 14px; border-radius:10px; border:0; cursor:pointer; font-weight:700; }
    .btn-ghost{ background:transparent; color:var(--accent); border:1px solid #dbeeff; padding:10px 14px; border-radius:10px; font-weight:700; }
    .btn-small{ padding:6px 8px; border-radius:8px; background:#fff; border:1px solid #eef3f8; cursor:pointer; }
    .btn-wide{ width:100%; display:block; text-align:center; margin-top:10px; }
    .reply-indicator{ font-size:.9rem; color:var(--muted); margin-bottom:8px; display:flex; gap:8px; align-items:center; }
    .lb-overlay{ position:fixed; inset:0; background:rgba(0,0,0,.8); display:none; align-items:center; justify-content:center; z-index:1200; padding:20px; }
    .lb-overlay.active{ display:flex; }
    .lb-img{ max-width:calc(100% - 40px); max-height:calc(100% - 40px); box-shadow:0 10px 40px rgba(0,0,0,.6); border-radius:8px; }
    .lb-close{ position:absolute; top:18px; right:18px; background:#fff; border-radius:6px; padding:6px 8px; cursor:pointer; font-weight:700; }
    .input{ width:100%; border:1px solid #e6e7eb; border-radius:10px; padding:10px 12px; font-size:14px; box-sizing:border-box; }
    textarea.input{ min-height:90px; max-height:220px; line-height:1.4; resize:vertical; }
    .staff-card{ background:#fff; border:1px solid #eef3f8; border-radius:12px; padding:12px; display:flex; gap:10px; align-items:flex-start; }
    .staff-photo{ width:64px; height:64px; border-radius:50%; object-fit:cover; border:1px solid #e6eef7; background:#fff; }
    .staff-name{ font-weight:800; }
    .staff-pos{ color:var(--muted); font-size:.92rem; margin-top:2px; }
    .staff-rating{ margin-top:6px; font-size:.95rem; color:#111; }
    @media(max-width:1200px){ .container{ max-width:1100px; } }
    @media(max-width:1000px){ .svc-grid{ grid-template-columns:1fr; } .logo{ height:220px; } }
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<div class="container">
  <?php if (!$service): ?>
    <div class="card">Сервис не найден или ещё не одобрен.</div>
  <?php else:
      [$logoUrl,$logoFs] = !empty($service['logo'])
        ? find_upload_url($service['logo'], 'services', $uploadsFsRoot, $uploadsUrlRoot)
        : ['', ''];
  ?>
    <div class="svc-grid">
      <!-- LEFT -->
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

        <!-- Кнопка записи -->
        <a class="btn btn-wide" href="booking.php?service_id=<?= $id ?>">Записаться</a>

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

      <!-- MIDDLE -->
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

        <!-- Reviews & Rating (unchanged markup/logic) -->
        <section class="card" id="reviews" style="margin-top:18px;">
          <!-- ... reviews rendering (same as before) ... -->
          <?php
            // Render reviews tree (we will render interactive buttons with JS)
            function render_tree_for_display($nodes, $userId, $isAdmin) {
                $out = '';
                foreach ($nodes as $n) {
                    $rid = (int)$n['id'];
                    $userName = htmlspecialchars($n['user_name'] ?? 'Гость');
                    $time = htmlspecialchars(date('d.m.Y H:i', strtotime($n['created_at'])));
                    $commentHtml = nl2br(htmlspecialchars($n['comment']));
                    $canManage = false;
                    if ($isAdmin) $canManage = true;
                    elseif ($userId > 0 && isset($n['user_id']) && (int)$n['user_id'] === $userId) $canManage = true;
                    $out .= '<div class="review-card" id="review-' . $rid . '">';
                    $out .= '<div class="review-meta">';
                    $out .= '<div><span class="review-name">' . $userName . '</span> <span class="review-time">' . $time . '</span></div>';
                    $out .= '<div style="margin-left:auto; display:flex; gap:8px;">';
                    $out .= '<button class="btn-small" type="button" onclick="startReply(' . $rid . ', ' . json_encode($userName) . ')">Ответить</button>';
                    if ($canManage) {
                        $out .= '<button class="btn-small" type="button" onclick="startEdit(' . $rid . ')">Изменить</button>';
                        $out .= '<form method="post" style="display:inline-block;margin:0;"><input type="hidden" name="action" value="delete_review"><input type="hidden" name="review_id" value="' . $rid . '"><button type="submit" class="btn-small" onclick="return confirm(\'Удалить отзыв?\')">Удалить</button></form>';
                    }
                    $out .= '</div></div>';
                    $out .= '<div class="review-comment">' . $commentHtml . '</div>';
                    if (!empty($n['children'])) {
                        $out .= '<div style="margin-left:18px; margin-top:8px;">' . render_tree_for_display($n['children'], $userId, $isAdmin) . '</div>';
                    }
                    $out .= '</div>';
                }
                return $out;
            }

            if (empty($reviews)) {
                echo '<div class="review-card">Пока нет отзывов — будьте первым!</div>';
            } else {
                echo render_tree_for_display($reviews, $userId, $isAdmin);
            }
          ?>

          <!-- Add / edit form (unchanged) -->
          <div class="review-card" style="margin-top:12px;">
            <div id="replyIndicator" class="reply-indicator" style="display:none;">
              <span id="replyToText"></span>
              <button class="btn-ghost" type="button" onclick="cancelReply()">Отменить ответ</button>
            </div>

            <h3 style="margin:0 0 8px 0;" id="formTitle">Оставить отзыв</h3>
            <form id="reviewForm" method="post" action="service.php?id=<?= $id ?>#reviews">
              <?php if ($userId <= 0): ?>
                <div style="margin-bottom:8px;">
                  <label>Ваше имя</label>
                  <input id="user_name" name="user_name" class="input" type="text" placeholder="Как вас зовут?" required>
                </div>
              <?php endif; ?>

              <div>
                <label>Комментарий</label>
                <textarea id="comment" name="comment" class="input" rows="4" required placeholder="Поделитесь впечатлением..."></textarea>
              </div>

              <input type="hidden" id="editing_review_id" name="editing_review_id" value="">
              <input type="hidden" id="parent_id" name="parent_id" value="0">

              <div style="margin-top:8px; display:flex; gap:8px; justify-content:flex-end;">
                <input type="hidden" name="action" value="upsert_review">
                <button type="submit" class="btn">Сохранить отзыв</button>
                <button type="button" class="btn-ghost" onclick="resetReviewForm()">Очистить</button>
              </div>
            </form>
          </div>

        </section>
      </main>

      <!-- RIGHT: сотрудники (unchanged) -->
      <aside class="card">
        <h3 style="margin:0 0 8px 0;">Сотрудники</h3>
        <?php if (empty($staff)): ?>
          <div style="color:#6b7280;">Информация пока не добавлена.</div>
        <?php else: ?>
          <div style="display:flex; flex-direction:column; gap:10px;">
            <?php foreach ($staff as $s):
              $photoVal = $s['photo'] ?? '';
              [$stUrl, $stFs] = $photoVal ? find_upload_url($photoVal, 'staff', $uploadsFsRoot, $uploadsUrlRoot) : ['',''];
              $stName = htmlspecialchars($s['name'] ?? 'Без имени');
              $stPos  = htmlspecialchars($s['position'] ?? '');
              $stRating = isset($s['rating']) ? (float)$s['rating'] : 0.0;
              $stPercent = max(0, min(100, ($stRating/5)*100));
            ?>
              <div class="staff-card">
                <?php if ($stUrl): ?>
                  <img class="staff-photo" src="<?= htmlspecialchars($stUrl) ?>" alt="<?= $stName ?>">
                <?php else: ?>
                  <div class="staff-photo" style="display:flex;align-items:center;justify-content:center;color:#9aa3af;">—</div>
                <?php endif; ?>
                <div>
                  <div class="staff-name"><?= $stName ?></div>
                  <?php if ($stPos): ?><div class="staff-pos"><?= $stPos ?></div><?php endif; ?>
                  <div class="staff-rating">
                    <span class="stars" style="--percent:<?= $stPercent ?>%;" title="Рейтинг сотрудника: <?= number_format($stRating,1) ?>"></span>
                    <span style="margin-left:6px; font-weight:700;"><?= number_format($stRating,1) ?></span>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </aside>
    </div>
  <?php endif; ?>
</div>

<footer style="padding:20px;text-align:center;color:#777;font-size:.9rem;">&copy; <?= date('Y') ?> Mehanik</footer>

<!-- Google Maps init (replaces Leaflet) -->
<script>
function initMap() {
  try {
    var mapEl = document.getElementById('map');
    var center = { lat: 37.95, lng: 58.38 };
    <?php if ($service && !empty($service['latitude']) && !empty($service['longitude'])): ?>
      center = { lat: <?= (float)$service['latitude'] ?>, lng: <?= (float)$service['longitude'] ?> };
    <?php endif; ?>

    var map = new google.maps.Map(mapEl, {
      center: center,
      zoom: <?= ($service && !empty($service['latitude']) && !empty($service['longitude'])) ? 15 : 13 ?>,
      streetViewControl: false
    });

    <?php if ($service && !empty($service['latitude']) && !empty($service['longitude'])): ?>
      var marker = new google.maps.Marker({ position: center, map: map });
      var infow = new google.maps.InfoWindow({ content: '<?= addslashes(htmlspecialchars($service['name'])) ?>' });
      marker.addListener('click', function(){ infow.open(map, marker); });
      infow.open(map, marker);
    <?php endif; ?>

  } catch (err) {
    console.warn('Google Maps init error:', err);
    var mapEl = document.getElementById('map');
    if (mapEl) mapEl.innerHTML = '<div style="padding:18px;color:#444">Карта недоступна.</div>';
  }
}
setTimeout(function(){ if (typeof google === 'undefined' || typeof google.maps === 'undefined') { console.warn('Google Maps not available'); var mapEl = document.getElementById('map'); if (mapEl) mapEl.innerHTML = '<div style="padding:18px;color:#444">Карта недоступна.</div>'; } }, 6000);
</script>

<!-- Insert your API key below: replace YOUR_GOOGLE_API_KEY with your real key -->
<script async defer src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_API_KEY&callback=initMap"></script>

<!-- Lightbox + reviews helpers (unchanged) -->
<script>
function openLightbox(src){ const lb=document.getElementById('lb'); const img=document.getElementById('lbImg'); img.src=src; lb.classList.add('active'); }
function closeLightbox(e){ if (!e || e.target.id==='lb' || (e.target.classList && e.target.classList.contains('lb-close'))) { const lb=document.getElementById('lb'); lb.classList.remove('active'); document.getElementById('lbImg').src=''; } }

// Review edit / reply helpers (same as before)
function startEdit(id){ try { const card = document.getElementById('review-' + id); if (!card) return; const commentNode = card.querySelector('.review-comment'); const nameNode = card.querySelector('.review-name'); const commentText = commentNode ? commentNode.innerText.trim() : ''; const userNameText = nameNode ? nameNode.innerText.trim() : ''; const form = document.getElementById('reviewForm'); const commentEl = document.getElementById('comment'); const nameEl = document.getElementById('user_name'); const editIdEl = document.getElementById('editing_review_id'); const parentIdEl = document.getElementById('parent_id'); const formTitle = document.getElementById('formTitle'); const replyInd = document.getElementById('replyIndicator'); if (commentEl) commentEl.value = commentText; if (nameEl && userNameText) nameEl.value = userNameText; if (editIdEl) editIdEl.value = id; if (parentIdEl) parentIdEl.value = 0; if (formTitle) formTitle.textContent = 'Редактировать отзыв'; if (replyInd) replyInd.style.display = 'none'; form.scrollIntoView({behavior:'smooth', block:'center'}); if (commentEl) commentEl.focus(); } catch (e) { console.error('startEdit error', e); } }
function startReply(id, userName){ try { const form = document.getElementById('reviewForm'); const commentEl = document.getElementById('comment'); const nameEl = document.getElementById('user_name'); const parentIdEl = document.getElementById('parent_id'); const editIdEl = document.getElementById('editing_review_id'); const formTitle = document.getElementById('formTitle'); const replyInd = document.getElementById('replyIndicator'); const replyToText = document.getElementById('replyToText'); if (editIdEl) editIdEl.value = ''; if (parentIdEl) parentIdEl.value = id; if (formTitle) formTitle.textContent = 'Ответить на отзыв'; if (replyInd && replyToText) { replyToText.textContent = 'Ответ пользователю: ' + (userName || 'Гость'); replyInd.style.display = 'flex'; } form.scrollIntoView({behavior:'smooth', block:'center'}); if (commentEl) { commentEl.placeholder = 'Ваш ответ...'; commentEl.focus(); } } catch (e) { console.error('startReply error', e); } }
function cancelReply(){ const parentIdEl = document.getElementById('parent_id'); const editIdEl = document.getElementById('editing_review_id'); const formTitle = document.getElementById('formTitle'); const replyInd = document.getElementById('replyIndicator'); const commentEl = document.getElementById('comment'); if (parentIdEl) parentIdEl.value = 0; if (editIdEl) editIdEl.value = ''; if (formTitle) formTitle.textContent = 'Оставить отзыв'; if (replyInd) replyInd.style.display = 'none'; if (commentEl) commentEl.placeholder = 'Поделитесь впечатлением...'; }
function resetReviewForm(){ const f=document.getElementById('reviewForm'); if (!f) return; f.reset(); const editIdEl = document.getElementById('editing_review_id'); const parentIdEl = document.getElementById('parent_id'); const formTitle = document.getElementById('formTitle'); const replyInd = document.getElementById('replyIndicator'); if (editIdEl) editIdEl.value = ''; if (parentIdEl) parentIdEl.value = 0; if (formTitle) formTitle.textContent = 'Оставить отзыв'; if (replyInd) replyInd.style.display = 'none'; }
</script>

<script src="/mehanik/assets/js/main.js"></script>
</body>
</html>
