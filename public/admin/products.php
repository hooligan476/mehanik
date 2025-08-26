<?php
// htdocs/mehanik/public/admin/products.php
session_start();

// доступ только админам
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'admin') {
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
table { border-collapse: collapse; width:100%; }
th, td { border:1px solid #ddd; padding:8px; text-align:left; }
th { background:#f4f4f4; }
.actions a { margin-right:8px; }
.status-approved { color:green; font-weight:700; }
.status-rejected { color:#c00; font-weight:700; }
.status-pending  { color:#d98200; font-weight:700; }
.pagination { margin-top:15px; }
.pagination a { padding:6px 10px; border:1px solid #ddd; margin:2px; text-decoration:none; }
.pagination .active { background:#4CAF50; color:#fff; }
</style>
</head>
<body>
<?php require_once __DIR__.'/header.php'; ?>
<h2>Админ — Товары<?= $user_id ? ' пользователя #'.htmlspecialchars($user_id) : '' ?></h2>

<?php if ($msg): ?><div style="color:green;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div style="color:red;"><?= htmlspecialchars($err) ?></div><?php endif; ?>

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
    <tr><td colspan="12" style="text-align:center;">Товары не найдены.</td></tr>
  <?php else: ?>
    <?php foreach ($products as $p): ?>
      <tr>
        <td><?= htmlspecialchars($p['id']) ?></td>
        <td><?= htmlspecialchars($p['sku'] ?? '') ?></td>
        <td><?= htmlspecialchars($p['name'] ?? '') ?></td>
        <td><?= htmlspecialchars($p['brand_id'] ?? '') ?></td>
        <td><?= htmlspecialchars($p['model_id'] ?? '') ?></td>
        <td><?= htmlspecialchars(($p['year_from'] ?? '') . ' — ' . ($p['year_to'] ?? '')) ?></td>
        <td><?= htmlspecialchars($p['price'] ?? '') ?></td>
        <td><?= htmlspecialchars($p['availability'] ?? '') ?></td>
        <td><?= htmlspecialchars($p['owner_name'] ?? '') ?> (ID: <?= htmlspecialchars($p['user_id'] ?? '') ?>)</td>
        <td><?= htmlspecialchars($p['created_at'] ?? '') ?></td>
        <td>
          <?php if ($p['status'] === 'approved'): ?>
            <span class="status-approved">Подтверждён</span>
          <?php elseif ($p['status'] === 'rejected'): ?>
            <span class="status-rejected">Отклонён</span>
          <?php else: ?>
            <span class="status-pending">На модерации</span>
          <?php endif; ?>
        </td>
        <td class="actions">
          <a href="<?= htmlspecialchars($basePublic . '/product.php?id=' . $p['id']) ?>" target="_blank">Просмотр</a>
          <a href="<?= htmlspecialchars($basePublic . '/admin/edit_product.php?id=' . $p['id']) ?>">Ред.</a>

          <?php if ($p['status'] !== 'approved'): ?>
            <a href="<?= htmlspecialchars($basePublic . '/admin/products.php?action=approve&id=' . $p['id'] . backQS($page,$user_id)) ?>">Подтвердить</a>
          <?php endif; ?>
          <?php if ($p['status'] !== 'rejected'): ?>
            <a href="<?= htmlspecialchars($basePublic . '/admin/products.php?action=reject&id=' . $p['id'] . backQS($page,$user_id)) ?>">Отклонить</a>
          <?php endif; ?>

          <a href="<?= htmlspecialchars($basePublic . '/admin/products.php?action=delete&id=' . $p['id'] . backQS($page,$user_id)) ?>"
             onclick="return confirm('Удалить товар #<?= htmlspecialchars($p['id']) ?>?')">Удал.</a>
        </td>
      </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>

<!-- пагинация, как в users.php -->
<?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <?php
        $link = $basePublic . '/admin/products.php?page=' . $i . ($user_id ? '&user_id=' . (int)$user_id : '');
        $cls  = $i === $page ? 'active' : '';
      ?>
      <a class="<?= $cls ?>" href="<?= htmlspecialchars($link) ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
<?php endif; ?>

</body>
</html>
