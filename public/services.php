<?php
// mehanik/public/services.php
session_start();
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../db.php';

$user = $_SESSION['user'] ?? null;
$userId = (int)($user['id'] ?? 0);
$isAdmin = ($user['role'] ?? '') === 'admin';

// === NOTE ===
// Server-side deletion handling removed as requested.
// If deletion should be available, implement it on service.php with proper checks.
// ============

// search / sort
$search = trim($_GET['q'] ?? '');
$sort = trim($_GET['sort'] ?? 'created_desc');
$allowedSort = ['created_desc','created_asc','rating_desc','rating_asc'];
if (!in_array($sort, $allowedSort, true)) $sort = 'created_desc';
switch ($sort) {
    case 'rating_desc': $order = "ORDER BY COALESCE(avg_rating,0) DESC, s.created_at DESC"; break;
    case 'rating_asc':  $order = "ORDER BY COALESCE(avg_rating,0) ASC, s.created_at DESC"; break;
    case 'created_asc': $order = "ORDER BY s.created_at ASC"; break;
    default:            $order = "ORDER BY s.created_at DESC"; break;
}

if ($isAdmin) {
    $sql = "SELECT s.id, s.user_id, s.name, s.description, s.logo, s.contact_name, s.address, s.status, s.created_at,
             (SELECT AVG(r.rating) FROM service_ratings r WHERE r.service_id = s.id) AS avg_rating,
             (SELECT COUNT(*) FROM service_reviews r WHERE r.service_id = s.id) AS reviews_count
            FROM services s
            WHERE (s.name LIKE ? OR s.description LIKE ?)
            {$order}
            LIMIT 200";
} else {
    $sql = "SELECT s.id, s.user_id, s.name, s.description, s.logo, s.contact_name, s.address, s.status, s.created_at,
             (SELECT AVG(r.rating) FROM service_ratings r WHERE r.service_id = s.id) AS avg_rating,
             (SELECT COUNT(*) FROM service_reviews r WHERE r.service_id = s.id) AS reviews_count
            FROM services s
            WHERE (s.status = 'approved' OR s.status = 'active')
              AND (s.name LIKE ? OR s.description LIKE ?)
            {$order}
            LIMIT 200";
}

$services = [];
if ($st = $mysqli->prepare($sql)) {
    $like = '%' . $search . '%';
    $st->bind_param('ss', $like, $like);
    $st->execute();
    $services = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}

// helper to produce public URL
function toPublicUrl($rel) {
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
  <title>Автосервисы / Услуги — Mehanik</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/mehanik/assets/css/header.css">
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    body{font-family:Inter,system-ui,Arial;background:#f6f8fb;color:#222}
    .container{max-width:1200px;margin:18px auto;padding:0 16px}
    .card{background:#fff;border-radius:10px;padding:12px;border:1px solid #eef3f7;box-shadow:0 8px 24px rgba(12,20,30,.04);display:flex;gap:12px}
    .service-logo-wrap{width:110px;height:110px;border-radius:10px;overflow:hidden;border:1px solid #eef3f7;background:#fff;display:flex;align-items:center;justify-content:center}
    .service-logo{width:100%;height:100%;object-fit:cover;display:block}
    .service-noimg{color:#98a2b3;font-weight:700}
    .service-title{font-weight:800;color:#0b57a4;text-decoration:none;font-size:1.05rem}
    .status-badge{padding:6px 10px;border-radius:999px;font-weight:700;font-size:.78rem}
    .stars{position:relative;display:inline-block;font-size:16px;line-height:1;letter-spacing:2px}
    .stars::before{content:'★★★★★';color:#e5e7eb}
    .stars::after{content:'★★★★★';color:#fbbf24;position:absolute;left:0;top:0;white-space:nowrap;overflow:hidden;width:var(--percent,0%)}
    .svc-actions{display:flex;gap:8px;align-items:center}
    .btn{background:#0b57a4;color:#fff;padding:8px 12px;border-radius:8px;text-decoration:none;font-weight:700}
    .btn-ghost{background:transparent;color:#0b57a4;border:1px solid #dbeeff;padding:8px 12px;border-radius:8px}
    .service-desc{margin-top:8px;color:#374151;font-size:.95rem;line-height:1.3}
    @media(max-width:760px){ .card{flex-direction:column;align-items:stretch} .service-logo-wrap{width:100%;height:180px} .service-logo{height:100%}}
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<div class="container">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
    <h1 style="margin:0;color:#0b57a4;">Автосервисы / Услуги</h1>
    <?php if (!empty($_SESSION['user'])): ?>
      <a href="add-service.php" class="btn">+ Добавить Сервис/Услуги</a>
    <?php else: ?>
      <a href="login.php" class="btn">Войти</a>
    <?php endif; ?>
  </div>

  <div style="display:flex;gap:12px;align-items:center;margin-bottom:14px;flex-wrap:wrap;">
    <div style="flex:1;min-width:220px;">
      <input id="svc-search" name="q" type="search" placeholder="Поиск (название / описание / адрес)" value="<?= htmlspecialchars($search) ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #e6eef7;">
    </div>
    <div>
      <select id="svc-sort" name="sort" style="padding:10px;border-radius:8px;border:1px solid #e6eef7;">
        <option value="created_desc" <?= $sort === 'created_desc' ? 'selected' : '' ?>>По дате (новые)</option>
        <option value="created_asc" <?= $sort === 'created_asc' ? 'selected' : '' ?>>По дате (старые)</option>
        <option value="rating_desc" <?= $sort === 'rating_desc' ? 'selected' : '' ?>>По рейтингу (убыв.)</option>
        <option value="rating_asc" <?= $sort === 'rating_asc' ? 'selected' : '' ?>>По рейтингу (возр.)</option>
      </select>
    </div>
  </div>

  <?php if (!empty($_SESSION['flash'])): ?><div style="background:#ecfdf5;border:1px solid #d1fae5;padding:10px;border-radius:8px;margin-bottom:12px;color:#065f46;"><?= htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?></div><?php endif; ?>
  <?php if (!empty($_SESSION['flash_error'])): ?><div style="background:#fff1f2;border:1px solid #ffd6de;padding:10px;border-radius:8px;margin-bottom:12px;color:#7f1d1d;"><?= htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div><?php endif; ?>

  <?php if (empty($services)): ?>
    <div class="card">Сервисов не найдено.</div>
  <?php else: foreach ($services as $s):
    $avg = isset($s['avg_rating']) && $s['avg_rating']!==null ? round((float)$s['avg_rating'],1) : 0.0;
    $cnt = (int)($s['reviews_count'] ?? 0);
    $percent = max(0, min(100, ($avg/5)*100));
    $status = htmlspecialchars(strtolower($s['status'] ?? 'pending'));
    $logoUrl = !empty($s['logo']) ? toPublicUrl($s['logo']) : '';
    $shortDesc = isset($s['description']) ? htmlspecialchars(mb_strimwidth($s['description'], 0, 180, '...')) : '';
  ?>
    <div class="card" style="margin-bottom:12px;">
      <div class="service-logo-wrap">
        <?php if ($logoUrl): ?>
          <img src="<?= htmlspecialchars($logoUrl) ?>" alt="logo" class="service-logo">
        <?php else: ?>
          <div class="service-noimg">No img</div>
        <?php endif; ?>
      </div>

      <div style="flex:1;min-width:0;">
        <div style="display:flex;align-items:center;gap:10px;justify-content:space-between">
          <div style="min-width:0;">
            <a href="service.php?id=<?= (int)$s['id'] ?>" class="service-title"><?= htmlspecialchars($s['name']) ?></a>
            <?php if ($shortDesc !== ''): ?>
              <div class="service-desc"><?= $shortDesc ?></div>
            <?php endif; ?>
          </div>
          <div style="display:flex;gap:8px;align-items:center">
            <div class="status-badge <?= $status ?>"><?= htmlspecialchars(mb_strtoupper($s['status'] ?? 'PENDING')) ?></div>
          </div>
        </div>

        <div style="margin-top:10px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
          <div class="rating" title="Рейтинг: <?= number_format($avg,1) ?>">
            <span class="stars" style="--percent:<?= $percent ?>%"></span>
            <span style="margin-left:8px;font-weight:700"><?= number_format($avg,1) ?> <small style="color:#6b7280">(<?= $cnt ?>)</small></span>
          </div>
          <?php if (!empty($s['address'])): ?><div style="color:#6b7280">• <?= htmlspecialchars($s['address']) ?></div><?php endif; ?>
        </div>

        <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
          <div class="svc-actions">
            <a href="service.php?id=<?= (int)$s['id'] ?>" class="btn">Открыть</a>
            <a href="appointment.php?id=<?= (int)$s['id'] ?>" class="btn btn-ghost">Записаться</a>
          </div>

          <!-- Редактирование/Удаление убраны из списка; если нужно, реализовать на service.php -->
        </div>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>

<script>
(function(){
  const search = document.getElementById('svc-search');
  const sort = document.getElementById('svc-sort');
  function reload(){
    const q = encodeURIComponent(search.value || '');
    const s = encodeURIComponent(sort.value || '');
    window.location.href = '/mehanik/public/services.php?q=' + q + '&sort=' + s;
  }
  search.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ e.preventDefault(); reload(); }});
  sort.addEventListener('change', reload);
})();
</script>
</body>
</html>
