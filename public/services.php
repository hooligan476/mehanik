<?php
// public/services.php — единый файл: HTML + API (format=json)
// Запросы:
//  - HTML: /services.php
//  - AJAX JSON: /services.php?format=json&q=...&sort=...

session_start();
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../db.php';
$config = file_exists(__DIR__ . '/../config.php') ? require __DIR__ . '/../config.php' : ['base_url' => '/mehanik'];

$format = strtolower(trim((string)($_GET['format'] ?? 'html'))); // 'html' or 'json'
$q = trim((string)($_GET['q'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? 'created_desc')); // created_desc | created_asc | rating_desc | rating_asc
$user = $_SESSION['user'] ?? null;
$isAdmin = isset($user['role']) && $user['role'] === 'admin';

// validate sort
$allowedSort = ['created_desc','created_asc','rating_desc','rating_asc'];
if (!in_array($sort, $allowedSort, true)) $sort = 'created_desc';

switch ($sort) {
    case 'rating_desc':
        $orderClause = "ORDER BY COALESCE(avg_rating,0) DESC, s.created_at DESC";
        break;
    case 'rating_asc':
        $orderClause = "ORDER BY COALESCE(avg_rating,0) ASC, s.created_at DESC";
        break;
    case 'created_asc':
        $orderClause = "ORDER BY s.created_at ASC";
        break;
    case 'created_desc':
    default:
        $orderClause = "ORDER BY s.created_at DESC";
        break;
}

// Build SQL (same for JSON and HTML fallback)
if ($isAdmin) {
    $sql = "
      SELECT s.id, s.name, s.description, s.logo, s.phone, s.status,
        (SELECT AVG(r.rating) FROM service_reviews r WHERE r.service_id = s.id) AS avg_rating,
        (SELECT COUNT(*) FROM service_reviews r WHERE r.service_id = s.id) AS reviews_count
      FROM services s
      WHERE (s.name LIKE ? OR s.description LIKE ?)
      {$orderClause}
      LIMIT 200
    ";
} else {
    $sql = "
      SELECT s.id, s.name, s.description, s.logo, s.phone,
        (SELECT AVG(r.rating) FROM service_reviews r WHERE r.service_id = s.id) AS avg_rating,
        (SELECT COUNT(*) FROM service_reviews r WHERE r.service_id = s.id) AS reviews_count
      FROM services s
      WHERE (s.status = 'approved' OR s.status = 'active')
        AND (s.name LIKE ? OR s.description LIKE ?)
      {$orderClause}
      LIMIT 200
    ";
}

$services = [];
if ($stmt = $mysqli->prepare($sql)) {
    $like = '%' . $q . '%';
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    $services = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// If JSON/API requested -> return JSON and exit
if ($format === 'json' || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
    header('Content-Type: application/json; charset=utf-8');

    $out = ['success' => true, 'data' => []];
    foreach ($services as $r) {
        $logo = null;
        if (!empty($r['logo']) && file_exists(__DIR__ . '/uploads/' . $r['logo'])) {
            // Return path relative to this page
            $logo = 'uploads/' . $r['logo'];
        }
        $avg = isset($r['avg_rating']) && $r['avg_rating'] !== null ? round(floatval($r['avg_rating']), 1) : 0.0;
        $cnt = isset($r['reviews_count']) ? (int)$r['reviews_count'] : 0;
        $out['data'][] = [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'description' => $r['description'],
            'logo' => $logo,
            'phone' => $r['phone'],
            'status' => $r['status'] ?? null,
            'avg_rating' => $avg,
            'reviews_count' => $cnt,
        ];
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// --------------------------------------------------
// HTML output (normal page)
// --------------------------------------------------
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Автосервисы / Услуги — Mehanik</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/mehanik/assets/css/header.css">
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    /* Локальные стили для сервисов + 10-звёздный рейтинг + красивая сортировка */
    .services-wrap { max-width:1200px; margin: 18px auto; padding: 0 16px; color:#222; }
    .controls { display:flex; gap:12px; align-items:center; margin-bottom:12px; flex-wrap:wrap; }
    .controls .search { flex:1; min-width:220px; }
    .controls input[type="search"] { width:100%; padding:10px 12px; border:1px solid #ddd; border-radius:8px; }
    .controls .sort { min-width:220px; }
    .btn-add { background:#0b57a4; color:#fff; padding:9px 14px; border-radius:10px; text-decoration:none; font-weight:700; }

    .services-list { display:grid; gap:14px; }
    .service-card { border:1px solid #e8e8e8; border-radius:10px; padding:12px; display:flex; gap:12px; align-items:center; background:#fff; box-shadow:0 4px 12px rgba(12,20,30,0.03); }
    .service-card img { width:96px; height:96px; object-fit:cover; border-radius:8px; border:1px solid #ddd; }
    .service-card .meta { color:#555; font-size:.95rem; margin-top:6px; }
    .status-badge { font-size:.78rem; padding:4px 8px; border-radius:999px; background:#f0f0f0; color:#333; margin-left:8px; }
    .rating { display:flex; align-items:center; gap:8px; margin-top:6px; }
    .stars-outer{ color:#ddd; font-size:0.9rem; line-height:1; position:relative; display:inline-block; }
    .stars-inner{ color:gold; position:absolute; left:0; top:0; white-space:nowrap; overflow:hidden; width:0; }
    .stars-outer span { letter-spacing:2px; }

    /* ---------- Стильная сортировка ---------- */
    .sort-wrap { display:flex; align-items:center; gap:8px; font-family: inherit; }
    .sort-badge {
      display:inline-flex; align-items:center; justify-content:center;
      width:40px; height:40px; border-radius:10px;
      background: linear-gradient(180deg,#ffffff11,#ffffff06);
      border:1px solid rgba(255,255,255,0.04);
      box-shadow: 0 2px 8px rgba(13,20,30,0.04);
      color:#0b57a4; font-weight:700; font-size:0.95rem;
    }
    .sort-label { font-size:0.85rem; color:#6b7280; display:block; margin-bottom:6px; }
    .sort {
      -webkit-appearance: none; -moz-appearance: none; appearance: none;
      background: linear-gradient(180deg, #fff, #fbfbfb);
      border: 1px solid #e6e9ef;
      padding: 10px 36px 10px 12px;
      border-radius: 10px;
      font-size: 0.95rem;
      min-width: 220px;
      box-shadow: 0 4px 14px rgba(12,20,30,0.04);
      cursor: pointer; position: relative; transition: transform .08s ease, box-shadow .12s ease;
    }
    .sort {
      background-image:
        linear-gradient(180deg, rgba(255,255,255,0.0), rgba(255,255,255,0.0)),
        url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24'><path fill='%236b7280' d='M7 10l5 5 5-5z'/></svg>");
      background-repeat: no-repeat, no-repeat;
      background-position: calc(100% - 12px) center, right;
      background-size: 18px, auto;
      padding-right: 44px;
    }
    .sort:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(12,20,30,0.06); }
    .sort:focus { outline: none; box-shadow: 0 10px 30px rgba(11,87,164,0.12); border-color:#0b57a4; }
    .sort.flash { animation: sortFlash .45s ease; }
    @keyframes sortFlash {
      0% { transform: scale(1); box-shadow: 0 4px 14px rgba(12,20,30,0.04); }
      30% { transform: scale(1.02); box-shadow: 0 12px 28px rgba(11,87,164,0.12); }
      100% { transform: scale(1); box-shadow: 0 4px 14px rgba(12,20,30,0.04); }
    }

    @media (max-width:760px) {
      .service-card { flex-direction:column; align-items:flex-start; }
      .service-card img { width:100%; height:180px; }
      .controls { flex-direction:column; align-items:stretch; }
      .btn-add { width:100%; text-align:center; }
      .sort { min-width: 100%; }
      .sort-wrap { width:100%; }
    }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/header.php'; ?>

<main class="services-wrap">
  <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px; flex-wrap:wrap;">
    <h1 style="margin:0; font-size:1.3rem;">Автосервисы / Услуги</h1>
    <?php if (!empty($_SESSION['user'])): ?>
      <a class="btn-add" href="add-service.php">ДОБАВИТЬ СЕРВИС/УСЛУГИ</a>
    <?php endif; ?>
  </div>

  <div class="controls" role="region" aria-label="Фильтр и сортировка">
    <div class="search">
      <label for="svc-search" style="display:block;margin-bottom:6px;color:#666;font-size:.9rem;">Поиск (название / описание)</label>
      <input id="svc-search" type="search" name="q" placeholder="Начните вводить для поиска..." value="<?= htmlspecialchars($q) ?>" autocomplete="off">
    </div>

    <div class="sort-wrap" style="align-items:flex-start;">
      <div style="display:flex;flex-direction:column;">
        <span class="sort-label">Сортировка</span>
        <div style="display:flex; gap:8px; align-items:center;">
          <div class="sort-badge" aria-hidden="true">🆕</div>

          <select id="sort" class="sort" name="sort" aria-label="Сортировка">
            <option value="created_desc" <?= $sort === 'created_desc' ? 'selected' : '' ?>>По дате (новые)</option>
            <option value="created_asc" <?= $sort === 'created_asc' ? 'selected' : '' ?>>По дате (старые)</option>
            <option value="rating_desc" <?= $sort === 'rating_desc' ? 'selected' : '' ?>>По рейтингу (убыв.)</option>
            <option value="rating_asc" <?= $sort === 'rating_asc' ? 'selected' : '' ?>>По рейтингу (возр.)</option>
          </select>
        </div>
      </div>
    </div>

    <div style="flex:0 0 auto;">
      <?php if ($isAdmin): ?><div style="font-size:.9rem;color:#666;">(Вы — админ: отображаются все записи)</div><?php endif; ?>
    </div>
  </div>

  <!-- services-list: первоначально отрисованы сервером (фолбэк), затем JS обновит список -->
  <div class="services-list" id="services-list">
    <?php if (empty($services)): ?>
      <p class="muted">Сервисов не найдено. <?php if (!$isAdmin) echo 'Возможно сервисы ожидают модерации (status = pending).'; ?></p>
    <?php else: ?>
      <?php foreach ($services as $s):
        $avg = isset($s['avg_rating']) && $s['avg_rating'] !== null ? round(floatval($s['avg_rating']), 1) : 0.0;
        $reviews = (int)($s['reviews_count'] ?? 0);
        $percent = ($avg / 5) * 100;
        $stars = str_repeat('★', 10);
      ?>
        <article class="service-card" role="article" aria-labelledby="svc-<?= (int)$s['id'] ?>">
          <?php if (!empty($s['logo']) && file_exists(__DIR__ . '/uploads/' . $s['logo'])): ?>
            <img src="uploads/<?= htmlspecialchars($s['logo']) ?>" alt="Логотип <?= htmlspecialchars($s['name']) ?>">
          <?php else: ?>
            <div style="width:96px;height:96px;border-radius:8px;background:#f7f7f7;display:flex;align-items:center;justify-content:center;border:1px dashed #e1e1e1;color:#999;">No img</div>
          <?php endif; ?>

          <div style="flex:1;">
            <h3 id="svc-<?= (int)$s['id'] ?>" style="margin:0 0 6px 0; font-size:1.05rem;">
              <a href="service.php?id=<?= (int)$s['id'] ?>" style="color:#0b57a4; text-decoration:none;"><?= htmlspecialchars($s['name']) ?></a>
              <?php if ($isAdmin && isset($s['status'])): ?>
                <span class="status-badge"><?= htmlspecialchars($s['status']) ?></span>
              <?php endif; ?>
            </h3>

            <div class="meta"><?= nl2br(htmlspecialchars(mb_strimwidth($s['description'], 0, 320, '...'))) ?></div>

            <div class="rating" aria-label="Средний рейтинг: <?= $avg ?> из 5">
              <div style="position:relative;">
                <div class="stars-outer"><span><?= $stars ?></span></div>
                <div class="stars-inner" style="width:<?= $percent ?>%"><span><?= $stars ?></span></div>
              </div>
              <div class="rating-num"><?= number_format($avg, 1) ?> (<?= $reviews ?>)</div>
            </div>

            <div style="margin-top:8px; color:#444; font-size:.95rem;">
              <?php if (!empty($s['phone'])): ?><span>Тел: <?= htmlspecialchars($s['phone']) ?></span><?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</main>

<footer style="padding:20px;text-align:center;color:#777;font-size:.9rem;">
  &copy; <?= date('Y') ?> Mehanik
</footer>

<script>
// Клиентская логика: автопоиск, сортировка, загрузка через тот же файл (format=json)
(function(){
  const input = document.getElementById('svc-search');
  const sortEl = document.getElementById('sort');
  const badge = document.querySelector('.sort-badge');
  const list = document.getElementById('services-list');
  if (!input || !sortEl || !list) return;

  let t;
  const deb = 350;

  function updateBadge(val) {
    switch(val) {
      case 'rating_desc': badge.textContent = '★↓'; break;
      case 'rating_asc': badge.textContent = '★↑'; break;
      case 'created_asc': badge.textContent = '⌛'; break;
      case 'created_desc':
      default: badge.textContent = '🆕'; break;
    }
  }
  updateBadge(sortEl.value);

  function createServiceCard(s) {
    const article = document.createElement('article');
    article.className = 'service-card';
    article.setAttribute('role','article');
    article.setAttribute('aria-labelledby','svc-' + s.id);

    const imgWrap = document.createElement('div');
    if (s.logo) {
      const img = document.createElement('img');
      img.src = s.logo;
      img.alt = 'Логотип ' + s.name;
      img.loading = 'lazy';
      imgWrap.appendChild(img);
    } else {
      imgWrap.style.width = '96px';
      imgWrap.style.height = '96px';
      imgWrap.style.borderRadius = '8px';
      imgWrap.style.background = '#f7f7f7';
      imgWrap.style.display = 'flex';
      imgWrap.style.alignItems = 'center';
      imgWrap.style.justifyContent = 'center';
      imgWrap.style.border = '1px dashed #e1e1e1';
      imgWrap.style.color = '#999';
      imgWrap.textContent = 'No img';
    }
    article.appendChild(imgWrap);

    const main = document.createElement('div');
    main.style.flex = '1';

    const h3 = document.createElement('h3');
    h3.id = 'svc-' + s.id;
    h3.style.margin = '0 0 6px 0';
    h3.style.fontSize = '1.05rem';

    const a = document.createElement('a');
    a.href = 'service.php?id=' + encodeURIComponent(s.id);
    a.style.color = '#0b57a4';
    a.style.textDecoration = 'none';
    a.textContent = s.name;
    h3.appendChild(a);

    if (s.status) {
      const span = document.createElement('span');
      span.className = 'status-badge';
      span.style.marginLeft = '8px';
      span.textContent = s.status;
      h3.appendChild(span);
    }
    main.appendChild(h3);

    const meta = document.createElement('div');
    meta.className = 'meta';
    meta.style.color = '#555';
    meta.style.fontSize = '.95rem';
    meta.style.marginTop = '6px';
    meta.textContent = s.description.length > 320 ? s.description.slice(0,320) + '...' : s.description;
    main.appendChild(meta);

    const avg = Number(s.avg_rating) || 0.0;
    const reviews = Number(s.reviews_count) || 0;
    const percent = Math.max(0, Math.min(100, (avg / 5) * 100));

    const ratingDiv = document.createElement('div');
    ratingDiv.className = 'rating';
    ratingDiv.style.marginTop = '6px';
    ratingDiv.style.display = 'flex';
    ratingDiv.style.alignItems = 'center';
    ratingDiv.style.gap = '8px';

    const starsWrap = document.createElement('div');
    starsWrap.style.position = 'relative';

    const outer = document.createElement('div');
    outer.className = 'stars-outer';
    const starsStr = '★★★★★★★★★★';
    const spanOuter = document.createElement('span');
    spanOuter.textContent = starsStr;
    outer.appendChild(spanOuter);
    outer.style.color = '#ddd';
    outer.style.fontSize = '0.9rem';
    outer.style.lineHeight = '1';
    outer.style.display = 'inline-block';
    outer.style.position = 'relative';

    const inner = document.createElement('div');
    inner.className = 'stars-inner';
    inner.style.position = 'absolute';
    inner.style.left = '0';
    inner.style.top = '0';
    inner.style.overflow = 'hidden';
    inner.style.whiteSpace = 'nowrap';
    inner.style.width = percent + '%';
    inner.style.color = 'gold';
    inner.style.fontSize = '0.9rem';
    const spanInner = document.createElement('span');
    spanInner.textContent = starsStr;
    inner.appendChild(spanInner);

    starsWrap.appendChild(outer);
    starsWrap.appendChild(inner);
    ratingDiv.appendChild(starsWrap);

    const rn = document.createElement('div');
    rn.className = 'rating-num';
    rn.style.fontSize = '.9rem';
    rn.style.color = '#333';
    rn.textContent = avg.toFixed(1) + ' (' + reviews + ')';
    ratingDiv.appendChild(rn);

    main.appendChild(ratingDiv);

    if (s.phone) {
      const phoneDiv = document.createElement('div');
      phoneDiv.style.marginTop = '8px';
      phoneDiv.style.color = '#444';
      phoneDiv.style.fontSize = '.95rem';
      phoneDiv.textContent = 'Тел: ' + s.phone;
      main.appendChild(phoneDiv);
    }

    article.appendChild(main);
    return article;
  }

  async function loadAndRender(q, sort) {
    try {
      const params = new URLSearchParams();
      if (q) params.set('q', q);
      if (sort) params.set('sort', sort);
      params.set('format','json');
      const url = 'services.php?' + params.toString();
      const res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) throw new Error('Network error');
      const json = await res.json();
      if (!json.success) throw new Error(json.error || 'Ошибка API');
      // render
      list.innerHTML = '';
      if (!json.data || json.data.length === 0) {
        const p = document.createElement('p');
        p.className = 'muted';
        p.textContent = 'Сервисов не найдено.';
        list.appendChild(p);
        return;
      }
      json.data.forEach(s => {
        const card = createServiceCard(s);
        list.appendChild(card);
      });
    } catch (e) {
      console.warn('Ошибка загрузки сервисов:', e);
      // fallback: не очищаем список, чтобы пользователь не потерял текущий фолбэк
    }
  }

  function navigateAndLoad() {
    const q = input.value.trim();
    const sort = sortEl.value;
    const url = new URL(window.location.href);
    if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
    if (sort) url.searchParams.set('sort', sort); else url.searchParams.delete('sort');
    history.replaceState(null, '', url.toString());
    updateBadge(sort);
    sortEl.classList.remove('flash');
    void sortEl.offsetWidth; // trigger reflow
    sortEl.classList.add('flash');
    loadAndRender(q, sort);
  }

  // init
  loadAndRender(input.value.trim(), sortEl.value);

  input.addEventListener('input', function(){
    clearTimeout(t);
    t = setTimeout(navigateAndLoad, deb);
  });
  input.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); clearTimeout(t); navigateAndLoad(); } });
  sortEl.addEventListener('change', function(){ clearTimeout(t); navigateAndLoad(); });

})();
</script>

<script src="/mehanik/assets/js/main.js"></script>
</body>
</html>
