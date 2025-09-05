<?php
// htdocs/mehanik/public/admin/products.php
session_start();
// доступ только admin или superadmin
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['admin','superadmin'], true)) {
    header('Location: /mehanik/public/login.php');
    exit;
}

// DB настройки (как в users.php)
$dbHost = '127.0.0.1';
$dbName = 'mehanik';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("DB error: " . $e->getMessage());
}

// базовый путь до public
$basePublic = '/mehanik/public';

// параметры
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

$user_id = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;

// ПАГИНАЦИЯ (как в users.php)
$perPage = 10;                       // товаров на страницу
$page    = max(1, intval($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// helper: собрать query string для возврата (с учетом user_id и page)
function backQS($page, $user_id) {
    $q = [];
    if ($page > 1)   $q[] = 'page=' . (int)$page;
    if ($user_id)    $q[] = 'user_id=' . (int)$user_id;
    return $q ? ('?' . implode('&', $q)) : '';
}

// обработка действий approve / reject / delete (GET — как было)
if (isset($_GET['action'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    $act = $_GET['action'];

    try {
        if ($act === 'approve') {
            $st = $pdo->prepare("UPDATE products SET status='approved' WHERE id=?");
            $st->execute([$id]);
            header("Location: {$basePublic}/admin/products.php" . backQS($page,$user_id));
            exit;
        } elseif ($act === 'reject') {
            $st = $pdo->prepare("UPDATE products SET status='rejected' WHERE id=?");
            $st->execute([$id]);
            header("Location: {$basePublic}/admin/products.php" . backQS($page,$user_id));
            exit;
        } elseif ($act === 'delete') {
            // удаляем товар (как раньше), без изменения остальной логики
            // (при желании могу добавить удаление файла из uploads/products/)
            $st = $pdo->prepare("DELETE FROM products WHERE id=?");
            $st->execute([$id]);
            header("Location: {$basePublic}/admin/products.php" . backQS($page,$user_id));
            exit;
        }
    } catch (PDOException $e) {
        header("Location: {$basePublic}/admin/products.php?err=db_error" . ($user_id ? "&user_id={$user_id}" : ''));
        exit;
    }
}

// всего товаров (с учетом фильтра по user_id)
if ($user_id) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM products WHERE user_id = ?");
    $st->execute([$user_id]);
} else {
    $st = $pdo->query("SELECT COUNT(*) FROM products");
}
$totalProducts = (int)$st->fetchColumn();
$totalPages = max(1, (int)ceil($totalProducts / $perPage));

// список товаров на текущей странице
if ($user_id) {
    $sql = "SELECT p.id, p.sku, p.name, p.brand_id, p.model_id, p.year_from, p.year_to,
                   p.price, p.availability, p.user_id, p.status, p.created_at,
                   u.name AS owner_name
            FROM products p
            LEFT JOIN users u ON u.id = p.user_id
            WHERE p.user_id = :uid
            ORDER BY p.id DESC
            LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':uid', $user_id, PDO::PARAM_INT);
} else {
    $sql = "SELECT p.id, p.sku, p.name, p.brand_id, p.model_id, p.year_from, p.year_to,
                   p.price, p.availability, p.user_id, p.status, p.created_at,
                   u.name AS owner_name
            FROM products p
            LEFT JOIN users u ON u.id = p.user_id
            ORDER BY p.id DESC
            LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
}
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Админ — Товары</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/mehanik/assets/css/style.css">
<style>
/* Общая обёртка */
.page-wrap {
  max-width:1200px;
  margin:18px auto;
  padding:16px;
  font-family: Arial, sans-serif;
}
/* Верхняя строка */
.header-row {
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:12px;
  margin-bottom:14px;
}
.header-row h2 { margin:0; font-size:20px; color:#1f2d3d; }

/* Сообщения */
.msg { padding:10px 12px; border-radius:8px; margin-bottom:12px; font-size:14px; }
.msg-success { background:#eaf7ef; color:#116530; border:1px solid #cfead1; }
.msg-error   { background:#fff0f0; color:#9b1a1a; border:1px solid #f2c6c6; }

/* Таблица */
.table-wrap { overflow-x:auto; background:transparent; border-radius:8px; }
table { border-collapse:collapse; width:100%; min-width:1020px; background:#fff; box-shadow:0 2px 8px rgba(15,20,30,0.04); border-radius:6px; overflow:hidden; }
th, td { padding:10px 12px; border-bottom:1px solid #f0f2f5; text-align:left; vertical-align:middle; font-size:14px; color:#24303a; }
th { background:#fbfcfd; font-weight:600; color:#556171; font-size:13px; text-transform:uppercase; letter-spacing:0.02em; }
tr:hover td { background:#fbfbfd; }

/* Действия (ссылки выглядят как кнопки) */
.actions a { margin:0 6px 6px 0; text-decoration:none; display:inline-block; padding:6px 9px; border-radius:6px; font-size:13px; color:#fff; }
.actions a.view { background:#6c757d; }
.actions a.edit { background:#2b7ae4; }
.actions a.approve { background:#2ecc71; }
.actions a.reject { background:#f04336; }
.actions a.delete { background:#6f42c1; }
.actions a.small { padding:5px 8px; font-size:12px; }

/* Пагинация */
.pagination { margin-top:14px; display:flex; gap:6px; flex-wrap:wrap; }
.pagination a { padding:8px 10px; border-radius:6px; border:1px solid #e6e9ee; text-decoration:none; color:#2b2f36; }
.pagination a.active { background:#2b7ae4; color:#fff; border-color:#2b7ae4; }

/* Мелкие правки */
.table-note { font-size:13px; color:#697580; margin-top:8px; }
@media (max-width:800px) {
  th, td { padding:8px; font-size:13px; }
  .header-row { flex-direction:column; align-items:flex-start; gap:8px; }
}
</style>
</head>
<body>
<?php require_once __DIR__.'/header.php'; ?>

<div class="page-wrap">
  <div class="header-row">
    <h2>Админ — Товары<?= $user_id ? ' пользователя #'.htmlspecialchars($user_id) : '' ?></h2>
    <div class="table-note">Всего товаров: <strong><?= $totalProducts ?></strong></div>
  </div>

  <?php if ($msg): ?>
    <div class="msg msg-success"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="msg msg-error"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>SKU</th>
          <th>Название</th>
          <th>Бренд ID</th>
          <th>Модель ID</th>
          <th>Годы</th>
          <th>Цена</th>
          <th>Доступно</th>
          <th>Владелец</th>
          <th>Создано</th>
          <th>Статус</th>
          <th>Действия</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$products): ?>
        <tr><td colspan="12" style="text-align:center; padding:18px 12px;">Товары не найдены.</td></tr>
      <?php else: ?>
        <?php foreach ($products as $p): ?>
          <?php
            // status and label
            $statusRaw = $p['status'] ?? '';
            $status = strtolower(trim((string)$statusRaw));

            if ($status === 'approved') {
                // лёгкий зелёный фон для строки
                $rowStyle = 'background:#f6fff6;';
                $badgeStyle = 'display:inline-block;padding:6px 9px;border-radius:999px;font-weight:700;font-size:12px;background:#e6f6ea;color:#1b7a31;border:1px solid #cfead1;';
                $label = 'Подтверждён';
            } elseif ($status === 'rejected') {
                $rowStyle = 'background:#fff6f6;';
                $badgeStyle = 'display:inline-block;padding:6px 9px;border-radius:999px;font-weight:700;font-size:12px;background:#fff0f0;color:#b22222;border:1px solid #f2c6c6;';
                $label = 'Отклонён';
            } else {
                // pending or unknown
                $rowStyle = 'background:#fffdf7;';
                $badgeStyle = 'display:inline-block;padding:6px 9px;border-radius:999px;font-weight:700;font-size:12px;background:#fff7ea;color:#b26a00;border:1px solid #f0dcc1;';
                $label = $statusRaw !== '' ? htmlspecialchars($statusRaw) : 'На модерации';
                if ($label === 'На модерации') {
                    // already okay
                }
            }

            // price formatting — TMT
            $price = $p['price'] ?? '';
            if ($price !== '' && is_numeric($price)) {
                $priceOut = number_format((float)$price, 2, '.', ' ') . ' TMT';
            } else {
                $priceOut = htmlspecialchars($price);
            }

            // created_at formatting
            $dt = $p['created_at'] ?? '';
            $createdOut = $dt ? htmlspecialchars(date('d.m.Y H:i', strtotime($dt))) : '';
          ?>
          <tr style="<?= $rowStyle ?>">
            <td><?= htmlspecialchars($p['id']) ?></td>
            <td><?= htmlspecialchars($p['sku'] ?? '') ?></td>
            <td style="max-width:260px;"><?= htmlspecialchars($p['name'] ?? '') ?></td>
            <td><?= htmlspecialchars($p['brand_id'] ?? '') ?></td>
            <td><?= htmlspecialchars($p['model_id'] ?? '') ?></td>
            <td><?= htmlspecialchars(($p['year_from'] ?? '') . ' — ' . ($p['year_to'] ?? '')) ?></td>
            <td><?= $priceOut ?></td>
            <td><?= htmlspecialchars($p['availability'] ?? '') ?></td>
            <td><?= htmlspecialchars($p['owner_name'] ?? '') ?> (ID: <?= htmlspecialchars($p['user_id'] ?? '') ?>)</td>
            <td><?= $createdOut ?></td>
            <td>
              <span style="<?= $badgeStyle ?>"><?= $label ?></span>
            </td>
            <td class="actions">
              <a class="view" href="<?= htmlspecialchars($basePublic . '/product.php?id=' . $p['id']) ?>" target="_blank">Просмотр</a>
              <a class="edit" href="<?= htmlspecialchars($basePublic . '/admin/edit_product.php?id=' . $p['id']) ?>">Ред.</a>

              <?php if ($p['status'] !== 'approved'): ?>
                <a class="approve small" href="<?= htmlspecialchars($basePublic . '/admin/products.php?action=approve&id=' . $p['id'] . backQS($page,$user_id)) ?>">Подтвердить</a>
              <?php endif; ?>
              <?php if ($p['status'] !== 'rejected'): ?>
                <a class="reject small" href="<?= htmlspecialchars($basePublic . '/admin/products.php?action=reject&id=' . $p['id'] . backQS($page,$user_id)) ?>">Отклонить</a>
              <?php endif; ?>

              <a class="delete" href="<?= htmlspecialchars($basePublic . '/admin/products.php?action=delete&id=' . $p['id'] . backQS($page,$user_id)) ?>"
                 onclick="return confirm('Удалить товар #<?= htmlspecialchars($p['id']) ?>?')">Удал.</a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- пагинация -->
  <?php if ($totalPages > 1): ?>
    <div class="pagination" aria-label="Пагинация">
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <?php
          $link = $basePublic . '/admin/products.php?page=' . $i . ($user_id ? '&user_id=' . (int)$user_id : '');
          $cls  = $i === $page ? 'active' : '';
        ?>
        <a class="<?= $cls ?>" href="<?= htmlspecialchars($link) ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>

</div>
</body>
</html>
