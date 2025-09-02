<?php
// public/service.php
session_start();
require_once __DIR__ . '/../db.php';
$config = file_exists(__DIR__ . '/../config.php') ? require __DIR__ . '/../config.php' : ['base_url'=>'/mehanik'];

// id сервиса
$id = (int)($_GET['id'] ?? 0);
$service = null;
$photos = [];
$avgRating = 0.0;
$reviewsCount = 0;
$reviews = [];
$owner = null; // ['id'=>..,'name'=>..,'phone'=>..]

// Обработка POST — добавление отзыва
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_review' && $id > 0) {
    $userName = trim($_POST['user_name'] ?? '');
    // если пользователь залогинен, используем его имя из сессии
    if (!empty($_SESSION['user']['name'])) {
        $userName = $_SESSION['user']['name'];
    }
    $comment = trim($_POST['comment'] ?? '');
    // рейтинг: приводим к float, ограничиваем диапазон 0.1 - 5.0
    $rating = isset($_POST['rating']) ? floatval($_POST['rating']) : 0.0;
    if ($rating < 0.1) $rating = 0.1;
    if ($rating > 5.0) $rating = 5.0;

    // Валидация: нужно имя/комментарий/рейтинг в разумных пределах
    if ($id > 0 && $userName !== '' && $comment !== '') {
        if ($stmt = $mysqli->prepare("INSERT INTO service_reviews (service_id, user_name, rating, comment, created_at) VALUES (?, ?, ?, ?, NOW())")) {
            $stmt->bind_param("isds", $id, $userName, $rating, $comment);
            $stmt->execute();
            $stmt->close();
        }
    }
    // Перенаправляем на ту же страницу (чтобы избежать повторной отправки формы)
    header("Location: service.php?id=" . $id . "#reviews");
    exit;
}

// Получаем сервис (видимый только approved/active)
if ($id > 0) {
    if ($stmt = $mysqli->prepare("
        SELECT id, user_id, name, description, logo, phone, email, address, latitude, longitude
        FROM services
        WHERE id = ? AND (status = 'approved' OR status = 'active')
    ")) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $service = $res->fetch_assoc();
        $stmt->close();
    }

    if ($service) {
        // получить владельца по user_id (если есть)
        $uid = isset($service['user_id']) ? (int)$service['user_id'] : 0;
        if ($uid > 0) {
            if ($st = $mysqli->prepare("SELECT id, name, phone FROM users WHERE id = ? LIMIT 1")) {
                $st->bind_param("i", $uid);
                $st->execute();
                $r = $st->get_result();
                $owner = $r ? $r->fetch_assoc() : null;
                $st->close();
            }
        }

        // фото
        if ($stmt = $mysqli->prepare("SELECT photo FROM service_photos WHERE service_id = ? ORDER BY id ASC")) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $photos = $res->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        // средний рейтинг и количество отзывов
        if ($stmt = $mysqli->prepare("SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt FROM service_reviews WHERE service_id = ?")) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            if ($row) {
                $avgRating = $row['avg_rating'] !== null ? round(floatval($row['avg_rating']), 1) : 0.0;
                $reviewsCount = (int)$row['cnt'];
            }
            $stmt->close();
        }

        // список отзывов (последние сверху)
        if ($stmt = $mysqli->prepare("SELECT id, user_name, rating, comment, created_at FROM service_reviews WHERE service_id = ? ORDER BY created_at DESC")) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $reviews = $res->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
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
    /* Локальные стили страницы сервиса */
    .svc-wrap { max-width:900px; margin:18px auto; padding:0 16px; color:#222; }
    .svc-top { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:12px; }
    .svc-logo { width:150px; height:150px; object-fit:cover; border-radius:10px; border:1px solid #ddd; }
    .svc-info { flex:1; }
    .stars-outer{ color:#ddd; font-size:1.15rem; line-height:1; position:relative; display:inline-block; }
    .stars-inner{ color:gold; position:absolute; left:0; top:0; white-space:nowrap; overflow:hidden; }
    .stars-outer span { letter-spacing:2px; } /* 10 stars if string contains 10 stars */
    .rating-inline { display:flex; align-items:center; gap:8px; margin-left:8px; }
    .photos-grid { display:grid; grid-template-columns: repeat(auto-fill,minmax(200px,1fr)); gap:12px; margin-bottom:18px; }
    .reviews { margin-top:18px; }
    .review { border-top:1px solid #eee; padding:12px 0; }
    .review:first-child { border-top:0; }
    .review .meta { font-weight:700; font-size:.95rem; }
    .review .time { color:#888; font-size:.85rem; margin-left:8px; }
    .review .comment { margin-top:6px; color:#333; }
    .add-review { margin-top:18px; border:1px solid #eee; padding:12px; border-radius:8px; background:#fafafa; }
    .add-review label { display:block; margin-top:8px; font-weight:600; }
    .add-review textarea { width:100%; min-height:100px; padding:8px; border-radius:6px; border:1px solid #ddd; }
    .add-review input[type="text"] { width:100%; padding:8px; border-radius:6px; border:1px solid #ddd; }
    .add-review .submit { margin-top:10px; padding:10px 14px; background:#0b57a4;color:#fff;border-radius:8px;border:0; cursor:pointer; font-weight:700; }
    .rating-slider { display:flex; align-items:center; gap:10px; margin-top:8px; }
    .rating-value { min-width:40px; font-weight:700; }
    .owner-block { margin-top:8px; padding:10px; border-radius:8px; background:#f7fbff; border:1px solid #e6f4ff; color:#073763; }
    @media(max-width:760px){ .svc-top{flex-direction:column; align-items:flex-start;} .svc-logo{width:100%;height:220px;} }
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<main class="svc-wrap">
  <?php if (!$service): ?>
    <p class="muted">Сервис не найден или ещё не одобрен.</p>
  <?php else: ?>
    <div class="svc-top">
      <?php if (!empty($service['logo']) && file_exists(__DIR__ . '/uploads/' . $service['logo'])): ?>
        <img src="uploads/<?= htmlspecialchars($service['logo']) ?>" alt="Логотип" class="svc-logo">
      <?php endif; ?>

      <div class="svc-info">
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
          <h1 style="margin:0; font-size:1.25rem;"><?= htmlspecialchars($service['name']) ?></h1>

          <?php
            // prepare 10-star string and percent fill
            $stars10 = str_repeat('★', 10);
            $percent = ($avgRating / 5) * 100;
            if ($percent < 0) $percent = 0;
            if ($percent > 100) $percent = 100;
          ?>
          <div class="rating-inline" aria-label="Средний рейтинг <?= number_format($avgRating,1) ?> из 5">
            <div style="position:relative;">
              <div class="stars-outer"><span><?= $stars10 ?></span></div>
              <div class="stars-inner" style="width:<?= $percent ?>%"><span><?= $stars10 ?></span></div>
            </div>
            <div style="font-size:.95rem;color:#333;"><?= number_format($avgRating,1) ?> (<?= $reviewsCount ?>)</div>
          </div>
        </div>

        <div style="margin-top:8px; color:#444;">
          <p style="margin:0 0 6px 0;"><strong>Описание:</strong><br><?= nl2br(htmlspecialchars($service['description'])) ?></p>

          <!-- Контактное лицо (переименованный телефон сервиса) -->
          <?php if (!empty($service['phone'])): ?>
            <div><strong>Контактное лицо:</strong> <?= htmlspecialchars($service['phone']) ?></div>
          <?php endif; ?>

          <?php if (!empty($service['email'])): ?><div><strong>Email:</strong> <?= htmlspecialchars($service['email']) ?></div><?php endif; ?>
          <?php if (!empty($service['address'])): ?><div><strong>Адрес:</strong> <?= htmlspecialchars($service['address']) ?></div><?php endif; ?>
        </div>

        <!-- Владелец -->
        <?php if (!empty($owner)): ?>
          <div class="owner-block" aria-label="Информация о владельце">
            <div><strong>Владелец:</strong> <?= htmlspecialchars($owner['name'] ?: '—') ?></div>
            <div style="margin-top:6px;"><strong>Номер владельца:</strong> <?= htmlspecialchars($owner['phone'] ?? '—') ?></div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Карта -->
    <h2 style="margin-top:18px;">Местоположение</h2>
    <div id="map" style="height:320px; border:1px solid #ddd; border-radius:6px; margin-bottom:18px;"></div>

    <!-- Галерея -->
    <?php if (!empty($photos)): ?>
      <h2>Фотографии</h2>
      <div class="photos-grid">
        <?php foreach ($photos as $p): if (!empty($p['photo']) && file_exists(__DIR__ . '/uploads/' . $p['photo'])): ?>
          <img src="uploads/<?= htmlspecialchars($p['photo']) ?>" alt="Фото сервиса" style="width:100%; height:180px; object-fit:cover; border-radius:8px; border:1px solid #eee;">
        <?php endif; endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Отзывы -->
    <section class="reviews" id="reviews">
      <h2>Отзывы (<?= $reviewsCount ?>)</h2>

      <?php if (empty($reviews)): ?>
        <p class="muted">Пока нет отзывов — будьте первым!</p>
      <?php else: ?>
        <?php foreach ($reviews as $r):
          $rRating = isset($r['rating']) ? round(floatval($r['rating']), 1) : 0.0;
          $rPercent = ($rRating / 5) * 100;
        ?>
          <div class="review" id="review-<?= (int)$r['id'] ?>">
            <div>
              <span class="meta"><?= htmlspecialchars($r['user_name']) ?></span>
              <span class="time"><?= htmlspecialchars(date('d.m.Y H:i', strtotime($r['created_at']))) ?></span>
              <div style="display:inline-block; margin-left:10px; position:relative; vertical-align:middle;">
                <div class="stars-outer" style="font-size:0.9rem;"><span><?= $stars10 ?></span></div>
                <div class="stars-inner" style="width:<?= $rPercent ?>%; font-size:0.9rem;"><span><?= $stars10 ?></span></div>
              </div>
              <span style="margin-left:8px;color:#333; font-weight:600;"><?= number_format($rRating,1) ?></span>
            </div>
            <div class="comment"><?= nl2br(htmlspecialchars($r['comment'])) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <!-- Форма добавления отзыва -->
      <div class="add-review" aria-labelledby="add-review-title">
        <h3 id="add-review-title" style="margin:0 0 8px 0;">Оставить отзыв</h3>

        <form method="post" action="service.php?id=<?= $id ?>#reviews">
          <?php if (empty($_SESSION['user'])): ?>
            <label for="user_name">Ваше имя</label>
            <input id="user_name" type="text" name="user_name" placeholder="Как вас зовут?" required>
          <?php else: ?>
            <div style="font-size:.95rem;margin-bottom:8px;">Вы авторизованы как <strong><?= htmlspecialchars($_SESSION['user']['name']) ?></strong></div>
          <?php endif; ?>

          <label for="rating">Оценка (0.1 — 5.0)</label>
          <div class="rating-slider">
            <input id="rating" type="range" name="rating" min="0.1" max="5.0" step="0.1" value="5.0" oninput="document.getElementById('ratingVal').textContent=this.value">
            <div class="rating-value" id="ratingVal">5.0</div>
          </div>

          <label for="comment">Комментарий</label>
          <textarea id="comment" name="comment" required placeholder="Ваш отзыв..."></textarea>

          <input type="hidden" name="action" value="add_review">
          <button type="submit" class="submit">Отправить отзыв</button>
        </form>
      </div>
    </section>
  <?php endif; ?>
</main>

<footer style="padding:20px;text-align:center;color:#777;font-size:.9rem;">
  &copy; <?= date('Y') ?> Mehanik
</footer>

<!-- Leaflet -->
<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<script>
<?php if ($service && !empty($service['latitude']) && !empty($service['longitude'])): ?>
    const map = L.map('map').setView([<?= $service['latitude'] ?>, <?= $service['longitude'] ?>], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
    L.marker([<?= $service['latitude'] ?>, <?= $service['longitude'] ?>]).addTo(map)
        .bindPopup("<?= addslashes(htmlspecialchars($service['name'])) ?>").openPopup();
<?php else: ?>
    const map = L.map('map').setView([37.95, 58.38], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
<?php endif; ?>
</script>

<script src="/mehanik/assets/js/main.js"></script>
</body>
</html>
