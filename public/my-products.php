<?php
// public/my-products.php
require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php';

// ID текущего пользователя
$user_id = $_SESSION['user']['id'] ?? 0;
if (!$user_id) {
    http_response_code(403);
    echo "Пользователь не найден в сессии.";
    exit;
}

// Получаем все товары пользователя (сортировка по дате)
$stmt = $mysqli->prepare("SELECT * FROM products WHERE user_id = ? ORDER BY id DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$products = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// Путь к placeholder-изображению и базовый префикс к public uploads
$noPhoto = '/mehanik/assets/no-photo.png';
$uploadsPrefix = '/mehanik/uploads/products/';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Мои товары — Mehanik</title>

  <!-- Основные стили проекта -->
  <link rel="stylesheet" href="/mehanik/assets/css/header.css">
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">

  <!-- Небольшие локальные стили для страницы "Мои товары" -->
  <style>
    :root{
      --card-bg: #ffffff;
      --muted: #6b7280;
      --accent: #0b57a4;
      --danger: #ef4444;
      --ok: #15803d;
      --pending: #b45309;
    }
    .page-wrap{ max-width:1200px; margin:18px auto; padding:12px; }
    h2.page-title{ margin:6px 0 18px; font-size:1.5rem; display:flex;align-items:center;gap:12px; }
    .grid { display:grid; grid-template-columns: repeat(3, 1fr); gap:18px; }
    @media (max-width:992px){ .grid { grid-template-columns: repeat(2,1fr); } }
    @media (max-width:600px){ .grid { grid-template-columns: 1fr; } }

    .prod-card {
      background: var(--card-bg);
      border-radius:12px;
      overflow:hidden;
      display:flex;
      flex-direction:column;
      box-shadow: 0 8px 20px rgba(2,6,23,0.06);
      transition: transform .14s ease, box-shadow .14s ease;
      min-height: 320px;
    }
    .prod-card:hover { transform: translateY(-6px); box-shadow: 0 14px 30px rgba(2,6,23,0.10); }

    .thumb {
      height:180px;
      background:#f5f7fb;
      display:flex; align-items:center; justify-content:center;
    }
    .thumb img { max-width:100%; max-height:100%; object-fit:contain; display:block; }

    .card-body { padding:14px; flex:1; display:flex; flex-direction:column; gap:8px; }
    .title { font-weight:700; font-size:1.05rem; margin:0 0 4px; color:#0f172a; }
    .meta { color:var(--muted); font-size:0.95rem; }
    .price { font-weight:800; font-size:1.05rem; color:var(--accent); }

    .badges { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:6px; }
    .badge { padding:6px 10px; border-radius:999px; font-weight:700; font-size:.82rem; color:#fff; display:inline-block; }
    .badge.ok{ background:var(--ok); }
    .badge.rej{ background:var(--danger); }
    .badge.pending{ background:var(--pending); }

    .card-footer { padding:12px; border-top:1px solid #f1f3f6; display:flex; justify-content:space-between; align-items:center; gap:8px; }
    .actions a, .actions button { text-decoration:none; display:inline-flex; align-items:center; gap:6px; padding:8px 10px; border-radius:8px; font-size:.92rem; border:0; cursor:pointer; }
    .btn-view { background:#eef6ff; color:var(--accent); border:1px solid rgba(11,87,164,0.08); }
    .btn-edit { background:#fff7ed; color:#a16207; border:1px solid rgba(161,98,7,0.08); }
    .btn-delete { background:#fff6f6; color:var(--danger); border:1px solid rgba(239,68,68,0.06); }

    .no-products { text-align:center; padding:40px 10px; color:var(--muted); background:#fff; border-radius:10px; box-shadow:0 6px 18px rgba(2,6,23,0.04); }
    .tools { display:flex; gap:10px; align-items:center; margin-left:auto; }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/header.php'; ?>

<div class="page-wrap">
  <h2 class="page-title">Мои товары</h2>

  <?php if (!empty($products)): ?>
    <div class="grid" role="list">
      <?php foreach ($products as $p): 
        // аккуратно формируем URL картинки (если в БД указан относительный путь или имя файла)
        $photoRaw = trim((string)($p['photo'] ?? ''));
        if ($photoRaw === '') {
          $photoUrl = $noPhoto;
        } elseif (strpos($photoRaw, '/') === 0 || preg_match('~^https?://~i', $photoRaw)) {
          $photoUrl = $photoRaw;
        } else {
          $photoUrl = $uploadsPrefix . $photoRaw;
        }

        // статус — делаем читаемый бейдж
        $status = trim((string)($p['status'] ?? 'pending'));
        $statusLower = mb_strtolower($status, 'UTF-8');
        if (strpos($statusLower, 'approved') !== false || strpos($statusLower, 'подтверж') !== false || strpos($statusLower, 'active') !== false) {
          $sclass = 'ok'; $slabel = 'Подтвержён';
        } elseif (strpos($statusLower, 'reject') !== false || strpos($statusLower, 'отклон') !== false) {
          $sclass = 'rej'; $slabel = 'Отклонён';
        } else {
          $sclass = 'pending'; $slabel = 'На модерации';
        }
      ?>
        <article class="prod-card" role="listitem">
          <div class="thumb">
            <a href="/mehanik/public/product.php?id=<?= (int)$p['id'] ?>" style="display:block;width:100%;height:100%;">
              <img src="<?= htmlspecialchars($photoUrl) ?>" alt="<?= htmlspecialchars($p['name'] ?? 'Товар') ?>">
            </a>
          </div>

          <div class="card-body">
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
              <div style="flex:1;">
                <div class="title"><?= htmlspecialchars($p['name'] ?? 'Без названия') ?></div>
                <div class="meta"><?= htmlspecialchars($p['manufacturer'] ?? '-') ?></div>
              </div>
              <div style="text-align:right;">
                <div class="price"><?= number_format((float)($p['price'] ?? 0), 2) ?> TMT</div>
                <div class="meta" style="margin-top:6px;font-size:.9rem;color:var(--muted)">ID: <?= (int)$p['id'] ?></div>
              </div>
            </div>

            <div class="badges" style="margin-top:auto;">
              <div class="badge <?= $sclass ?>"><?= $slabel ?></div>
              <div class="meta" style="background:#f3f5f8;padding:6px 8px;border-radius:8px;font-weight:600;color:#334155;">Наличие: <?= (int)$p['availability'] ?></div>
              <div class="meta" style="background:#f3f5f8;padding:6px 8px;border-radius:8px;color:#334155;">Добавлен: <?= !empty($p['created_at']) ? date('d.m.Y', strtotime($p['created_at'])) : '-' ?></div>
            </div>
          </div>

          <div class="card-footer">
            <div class="actions">
              <a class="btn-view" href="/mehanik/public/product.php?id=<?= (int)$p['id'] ?>">👁 Просмотр</a>
              <a class="btn-edit" href="/mehanik/public/edit-product.php?id=<?= (int)$p['id'] ?>">✏ Редактировать</a>
            </div>

            <div>
              <form method="post" action="/mehanik/api/delete-product.php" onsubmit="return confirm('Удалить товар «<?= htmlspecialchars(addslashes($p['name'] ?? ''))?>»?');" style="display:inline;">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="btn-delete">🗑 Удалить</button>
              </form>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="no-products">
      <p style="font-weight:700;margin:0 0 8px;">У вас пока нет товаров</p>
      <p style="margin:0 0 12px;color:var(--muted)">Нажмите кнопку «Добавить товар», чтобы создать первое объявление.</p>
      <a href="/mehanik/public/add-product.php" class="btn" style="background:linear-gradient(180deg,var(--accent), #074b82);color:#fff;padding:10px 14px;border-radius:8px;text-decoration:none;font-weight:700;">➕ Добавить товар</a>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
