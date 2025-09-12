<?php
// public/my-cars.php
require_once __DIR__ . '/../middleware.php';
require_auth();
require_once __DIR__ . '/../db.php';

$user_id = $_SESSION['user']['id'] ?? 0;
if (!$user_id) {
    http_response_code(403); echo "Пользователь не в сессии."; exit;
}

$stmt = $mysqli->prepare("SELECT * FROM cars WHERE user_id = ? ORDER BY id DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$cars = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$noPhoto = '/mehanik/assets/no-photo.png';
$uploadsPrefix = '/mehanik/uploads/cars/';
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Мои авто — Mehanik</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/mehanik/assets/css/header.css">
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    .page { max-width:1200px; margin:18px auto; padding:14px; }
    .topbar-row { display:flex; gap:12px; align-items:center; margin-bottom:14px; flex-wrap:wrap; }
    .title { font-size:1.4rem; font-weight:800; margin:0; }
    .tools { margin-left:auto; display:flex; gap:8px; align-items:center; }
    .btn { background:#0b57a4;color:#fff;padding:8px 12px;border-radius:8px;border:0;cursor:pointer;font-weight:700;text-decoration:none; }
    .btn-ghost { background:transparent;border:1px solid #e6eef7;color:#0b57a4;padding:8px 12px;border-radius:8px;text-decoration:none; }

    .grid { display:grid; grid-template-columns: repeat(3,1fr); gap:18px; }
    @media (max-width:992px){ .grid{grid-template-columns:repeat(2,1fr);} }
    @media (max-width:640px){ .grid{grid-template-columns:1fr;} }

    .card { background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 8px 20px rgba(2,6,23,0.06); display:flex;flex-direction:column; }
    .thumb { height:180px;background:#f5f7fb;display:flex;align-items:center;justify-content:center; }
    .thumb img { max-width:100%; max-height:100%; object-fit:cover; display:block; }
    .card-body{padding:12px;flex:1;display:flex;flex-direction:column;gap:8px;}
    .car-title{font-weight:800;margin:0;font-size:1.05rem;}
    .meta{color:#6b7280;font-size:0.95rem;}
    .price{font-weight:800;color:#0b57a4;font-size:1.05rem;}
    .badges{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:auto;}
    .badge{padding:6px 10px;border-radius:999px;color:#fff;font-weight:700}
    .badge.ok{background:#15803d} .badge.rej{background:#ef4444} .badge.pending{background:#b45309}
    .card-footer{padding:10px;border-top:1px solid #f1f3f6;display:flex;justify-content:space-between;align-items:center;gap:8px}
    .actions a { text-decoration:none;padding:8px 10px;border-radius:8px;background:#eef6ff;color:#0b57a4;font-weight:700;border:1px solid rgba(11,87,164,0.08); }
    .actions .edit{background:#fff7ed;color:#a16207;border:1px solid rgba(161,98,7,0.08);padding:8px 10px;border-radius:8px;}
    .actions .del{background:#fff6f6;color:#ef4444;border:1px solid rgba(239,68,68,0.06);padding:8px 10px;border-radius:8px;}
    .empty{background:#fff;padding:28px;border-radius:10px;text-align:center;box-shadow:0 8px 24px rgba(2,6,23,0.04)}
    .notice{padding:10px;border-radius:8px;margin-bottom:12px}
    .notice.ok{background:#eafaf0;border:1px solid #cfead1;color:#116530}
    .notice.err{background:#fff6f6;border:1px solid #f5c2c2;color:#8a1f1f}
  </style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<div class="page">
  <div class="topbar-row">
    <h1 class="title">Мои объявления — Авто</h1>
    <div class="tools">
      <a href="/mehanik/public/add-car.php" class="btn">➕ Добавить авто</a>
    </div>
  </div>

  <?php if ($msg): ?><div class="notice ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="notice err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <?php if (!empty($cars)): ?>
    <div class="grid" role="list">
      <?php foreach ($cars as $car):
        $photoRaw = trim((string)($car['photo'] ?? ''));
        if ($photoRaw === '') $photoUrl = $noPhoto;
        elseif (strpos($photoRaw, '/') === 0 || preg_match('~^https?://~i', $photoRaw)) $photoUrl = $photoRaw;
        else $photoUrl = $uploadsPrefix . $photoRaw;

        $status = $car['status'] ?? 'pending';
        if ($status === 'approved') { $sclass = 'ok'; $slabel = 'Подтверждён'; }
        elseif ($status === 'rejected') { $sclass = 'rej'; $slabel = 'Отклонён'; }
        else { $sclass = 'pending'; $slabel = 'На модерации'; }
      ?>
        <article class="card" role="listitem">
          <div class="thumb">
            <a href="/mehanik/public/car.php?id=<?= (int)$car['id'] ?>"><img src="<?= htmlspecialchars($photoUrl) ?>" alt="<?= htmlspecialchars($car['brand'].' '.$car['model']) ?>"></a>
          </div>
          <div class="card-body">
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start">
              <div style="flex:1">
                <div class="car-title"><?= htmlspecialchars($car['brand'].' '.$car['model']) ?></div>
                <div class="meta"><?= (int)$car['year'] ?> г. · <?= number_format((float)($car['mileage'] ?? 0), 0, '.', ' ') ?> км · <?= htmlspecialchars($car['body'] ?? '-') ?></div>
              </div>
              <div style="text-align:right">
                <div class="price"><?= number_format((float)($car['price'] ?? 0), 2) ?> TMT</div>
                <div class="meta" style="margin-top:8px;font-size:.9rem;color:#6b7280">ID: <?= (int)$car['id'] ?></div>
              </div>
            </div>

            <div class="badges">
              <div class="badge <?= $sclass ?>"><?= $slabel ?></div>
              <div class="meta" style="background:#f3f5f8;padding:6px 8px;border-radius:8px;color:#334155;">Добавлен: <?= !empty($car['created_at']) ? date('d.m.Y', strtotime($car['created_at'])) : '-' ?></div>
            </div>
          </div>

          <div class="card-footer">
            <div class="actions">
              <a href="/mehanik/public/car.php?id=<?= (int)$car['id'] ?>">👁 Просмотр</a>
              <a class="edit" href="/mehanik/public/edit-car.php?id=<?= (int)$car['id'] ?>">✏ Редактировать</a>
            </div>
            <div>
              <form method="post" action="/mehanik/api/delete-car.php" onsubmit="return confirm('Удалить авто «<?= htmlspecialchars(addslashes($car['brand'].' '.$car['model'])) ?>»?');" style="display:inline;">
                <input type="hidden" name="id" value="<?= (int)$car['id'] ?>">
                <button type="submit" class="del">🗑 Удалить</button>
              </form>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty">
      <h3 style="margin:0 0 8px">У вас пока нет объявлений</h3>
      <p style="margin:0 0 12px;color:#6b7280">Создайте первое объявление — нажмите кнопку «Добавить авто».</p>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
