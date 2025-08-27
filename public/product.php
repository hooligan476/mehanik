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
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$product = $res->fetch_assoc();
$stmt->close();

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

// Фото
$photoRaw = $product['photo'] ?? '';
if ($photoRaw) {
    if (preg_match('~^https?://~i', $photoRaw) || str_starts_with($photoRaw, '/')) {
        $photoUrl = $photoRaw;
    } else {
        $photoUrl = rtrim($config['base_url'], '/') . '/uploads/products/' . $photoRaw;
    }
} else {
    $photoUrl = null;
}

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
    <div class="photo">
      <?php if ($photoUrl): ?>
        <img src="<?= htmlspecialchars($photoUrl) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
      <?php else: ?>
        <img src="/mehanik/assets/no-photo.png" alt="Нет фото">
      <?php endif; ?>
    </div>
  </div>

  <!-- Описание -->
  <div class="card">
    <div class="card-body">
      <div style="margin-bottom:8px;">
        <?php foreach (['brand_name','model_name','complex_part_name','component_name'] as $field): ?>
          <?php if (!empty($product[$field])): ?>
            <span class="pill"><?= htmlspecialchars($product[$field]) ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <div class="details" style="margin-top:8px;">
        <div class="row"><strong>SKU:</strong> <?= htmlspecialchars($product['sku'] ?? '') ?></div>
        <div class="row"><strong>Производитель:</strong> <?= htmlspecialchars($product['manufacturer'] ?? '-') ?></div>
        <div class="row"><strong>Состояние:</strong> <?= htmlspecialchars($product['quality'] ?? '-') ?></div>
        <div class="row"><strong>Качество:</strong> <?= number_format((float)($product['rating'] ?? 0),1) ?>/10</div>
        <div class="row"><strong>Годы выпуска:</strong> <?= ($product['year_from'] ?: '—') ?> — <?= ($product['year_to'] ?: '—') ?></div>
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
</body>
</html>
